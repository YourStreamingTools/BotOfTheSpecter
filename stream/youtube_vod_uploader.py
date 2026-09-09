#!/usr/bin/env python3
"""
Upload queued stream recordings to the streamer's YouTube channel.

Runs on the **stream server** (same host as stream.py), reading MP4s from
the same directory stream.py writes after FLV→MP4 conversion:

  {STREAM_FILES_ROOT}/{username}/{filename}

That root matches stream.py:
  us-west / us-east / eu-central → /mnt/s3/bots-stream
  sydney → the directory that contains stream.py
Override with STREAM_FILES_ROOT if needed. STREAM_SERVER should match
stream.py's -server value.

Not the bot host. Not twitch-recorder /mnt/blockstorage.
"""
import os
import re
import sys
import json
import asyncio
from urllib.parse import urljoin
import aiohttp
import aiomysql
import logging
from logging.handlers import RotatingFileHandler
from dotenv import load_dotenv

try:
    import fcntl
except ImportError:
    fcntl = None

load_dotenv()

YOUTUBE_CLIENT_ID = os.getenv("YOUTUBE_CLIENT_ID") or os.getenv("GOOGLE_CLIENT_ID")
YOUTUBE_CLIENT_SECRET = os.getenv("YOUTUBE_CLIENT_SECRET") or os.getenv("GOOGLE_CLIENT_SECRET")
DB_HOST = os.getenv("SQL_HOST")
DB_USER = os.getenv("SQL_USER")
DB_PASS = os.getenv("SQL_PASSWORD")
DB_NAME = "website"
TOKEN_URL = "https://oauth2.googleapis.com/token"
UPLOAD_INIT = "https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status"
CHUNK_SIZE = 8 * 1024 * 1024
TWITCH_WEB_CLIENT_ID = os.getenv("TWITCH_WEB_CLIENT_ID", "kimne78kx3ncx6brgo4mv6wki5h1ko")
TWITCH_GQL = "https://gql.twitch.tv/gql"
TWITCH_USHER = "https://usher.ttvnw.net/vod/{vod_id}.m3u8"
PLAYBACK_QUERY = """
query PlaybackAccessToken_Template($isLive: Boolean!, $isVod: Boolean!, $login: String!, $playerType: String!, $vodID: ID!) {
  videoPlaybackAccessToken(id: $vodID, params: {platform: "web", playerBackend: "mediaplayer", playerType: $playerType}) @include(if: $isVod) {
    value
    signature
  }
}
"""

STREAM_DIR = os.path.dirname(os.path.abspath(__file__))


def stream_files_root():
    override = os.getenv("STREAM_FILES_ROOT")
    if override:
        return override
    server = (os.getenv("STREAM_SERVER") or "").lower().strip()
    if server in ("us-west", "us-east", "eu-central"):
        return "/mnt/s3/bots-stream"
    if server == "sydney":
        return STREAM_DIR
    if os.path.isdir("/mnt/s3/bots-stream"):
        return "/mnt/s3/bots-stream"
    return STREAM_DIR


VOD_ROOT = stream_files_root()

log_dir = os.path.join(STREAM_DIR, "logs")
os.makedirs(log_dir, exist_ok=True)
log_file = os.path.join(log_dir, "youtube_vod_uploader.log")
logger = logging.getLogger("youtube_vod_uploader")
logger.setLevel(logging.INFO)

file_handler = RotatingFileHandler(log_file, maxBytes=200 * 1024, backupCount=5)
file_handler.setFormatter(logging.Formatter("%(asctime)s %(message)s", "%Y-%m-%d %H:%M:%S"))
logger.addHandler(file_handler)
console_handler = logging.StreamHandler()
console_handler.setFormatter(logging.Formatter("%(message)s"))
logger.addHandler(console_handler)

LOCK_PATH = os.path.join(log_dir, "youtube_vod_uploader.lock")


def _safe_filename(name):
    if not isinstance(name, str) or not name:
        return False
    if "/" in name or "\\" in name or "\x00" in name:
        return False
    if name in (".", "..") or os.path.basename(name) != name:
        return False
    return name.lower().endswith(".mp4")


def _safe_username(name):
    if not isinstance(name, str):
        return False
    return bool(re.match(r"^[a-zA-Z0-9_]{1,64}$", name))


async def read_json(resp):
    text = await resp.text()
    if not text:
        return {}
    try:
        decoded = json.loads(text)
        return decoded if isinstance(decoded, dict) else {"raw": text[:300]}
    except Exception:
        return {"raw": text[:300]}


async def refresh_access(session, refresh_token):
    data = {
        "grant_type": "refresh_token",
        "refresh_token": refresh_token,
        "client_id": YOUTUBE_CLIENT_ID,
        "client_secret": YOUTUBE_CLIENT_SECRET,
    }
    async with session.post(TOKEN_URL, data=data) as resp:
        body = await read_json(resp)
        if resp.status != 200 or "access_token" not in body:
            return None, body
        return body, None


async def persist_access(pool, user_id, token_body, fallback_refresh):
    access = token_body["access_token"]
    refresh = token_body.get("refresh_token") or fallback_refresh
    expires_in = int(token_body.get("expires_in") or 3600)
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "UPDATE youtube_tokens SET access_token = %s, refresh_token = %s, "
                "token_expiry = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %s SECOND), needs_reauth = 0 "
                "WHERE user_id = %s",
                (access, refresh, expires_in, user_id),
            )
            await conn.commit()
    return access, refresh


async def mark_reauth(pool, user_id):
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "UPDATE youtube_tokens SET access_token = '', refresh_token = '', needs_reauth = 1 WHERE user_id = %s",
                (user_id,),
            )
            await conn.commit()


async def set_job(pool, job_id, status, video_id=None, error=None):
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "UPDATE youtube_vod_uploads SET status = %s, youtube_video_id = %s, error_message = %s WHERE id = %s",
                (status, video_id, error, job_id),
            )
            await conn.commit()


def _error_reason(body):
    if not isinstance(body, dict):
        return ""
    errors = body.get("error")
    if isinstance(errors, dict):
        nested = errors.get("errors")
        if isinstance(nested, list) and nested:
            return str(nested[0].get("reason") or nested[0].get("message") or "")
        return str(errors.get("status") or errors.get("message") or "")
    if isinstance(errors, str):
        return errors
    return ""


async def resumable_upload(session, access_token, path, title, privacy):
    size = os.path.getsize(path)
    headers = {
        "Authorization": f"Bearer {access_token}",
        "Content-Type": "application/json; charset=UTF-8",
        "X-Upload-Content-Type": "video/mp4",
        "X-Upload-Content-Length": str(size),
    }
    payload = {
        "snippet": {
            "title": (title or "Specter stream")[:100],
            "description": "Uploaded from BotOfTheSpecter stream recordings.",
            "categoryId": "20",
        },
        "status": {
            "privacyStatus": privacy if privacy in ("private", "unlisted", "public") else "private",
            "selfDeclaredMadeForKids": False,
        },
    }
    async with session.post(UPLOAD_INIT, headers=headers, json=payload) as resp:
        if resp.status == 401:
            return None, "unauthorized", await read_json(resp)
        if resp.status not in (200, 201):
            body = await read_json(resp)
            return None, f"init_failed:{resp.status}:{_error_reason(body)}", body
        location = resp.headers.get("Location")
    if not location:
        return None, "no_session_url", None

    sent = 0
    with open(path, "rb") as handle:
        while sent < size:
            data = handle.read(CHUNK_SIZE)
            if not data:
                break
            end = sent + len(data) - 1
            put_headers = {
                "Authorization": f"Bearer {access_token}",
                "Content-Length": str(len(data)),
                "Content-Range": f"bytes {sent}-{end}/{size}",
            }
            async with session.put(location, headers=put_headers, data=data) as resp:
                if resp.status in (200, 201):
                    result = await read_json(resp)
                    video_id = result.get("id") if isinstance(result, dict) else None
                    if not video_id:
                        return None, "missing_video_id", result
                    return video_id, None, result
                if resp.status == 308:
                    sent = end + 1
                    continue
                if resp.status == 401:
                    return None, "unauthorized", await read_json(resp)
                body = await read_json(resp)
                return None, f"put_failed:{resp.status}:{_error_reason(body)}", body
    return None, "incomplete", None


def _pick_hls_variant(master_text):
    best_url = None
    best_bw = -1
    chunked_url = None
    lines = master_text.splitlines()
    for i, line in enumerate(lines):
        if not line.startswith("#EXT-X-STREAM-INF"):
            continue
        url = lines[i + 1].strip() if i + 1 < len(lines) else ""
        if not url or url.startswith("#"):
            continue
        bw = -1
        m = re.search(r"BANDWIDTH=(\d+)", line)
        if m:
            bw = int(m.group(1))
        if 'VIDEO="chunked"' in line or 'NAME="chunked"' in line:
            chunked_url = url
        if bw > best_bw:
            best_bw = bw
            best_url = url
    return chunked_url or best_url


async def twitch_vod_hls_url(session, vod_id, twitch_oauth):
    headers = {
        "Client-ID": TWITCH_WEB_CLIENT_ID,
        "Content-Type": "application/json",
        "User-Agent": "Mozilla/5.0",
    }
    oauth = (twitch_oauth or "").strip()
    if oauth.lower().startswith("oauth:"):
        oauth = oauth.split(":", 1)[1]
    if oauth:
        headers["Authorization"] = f"OAuth {oauth}"
    payload = {
        "operationName": "PlaybackAccessToken_Template",
        "query": PLAYBACK_QUERY,
        "variables": {
            "isLive": False,
            "login": "",
            "isVod": True,
            "vodID": str(vod_id),
            "playerType": "site",
        },
    }
    async with session.post(TWITCH_GQL, headers=headers, json=payload) as resp:
        body = await read_json(resp)
        if resp.status != 200:
            return None, f"gql_http_{resp.status}"
    token_row = ((body.get("data") or {}).get("videoPlaybackAccessToken") or {})
    value = token_row.get("value")
    signature = token_row.get("signature")
    if not value or not signature:
        return None, "gql_no_token"
    params = {
        "player": "twitchweb",
        "allow_source": "true",
        "allow_audio_only": "false",
        "allow_spectre": "false",
        "playlist_include_framerate": "true",
        "sig": signature,
        "token": value,
    }
    usher = TWITCH_USHER.format(vod_id=vod_id)
    async with session.get(usher, params=params, headers={"User-Agent": "Mozilla/5.0"}) as resp:
        playlist = await resp.text()
        playlist_url = str(resp.url)
        if resp.status != 200 or not playlist:
            return None, f"usher_http_{resp.status}"
    if "#EXT-X-STREAM-INF" in playlist:
        variant = _pick_hls_variant(playlist)
        if not variant:
            return None, "no_hls_variant"
        if not variant.startswith("http"):
            variant = urljoin(playlist_url, variant)
        return variant, None
    if "#EXTINF" in playlist:
        return playlist_url, None
    return None, "unrecognised_playlist"


async def ffmpeg_pull_twitch_vod(hls_url, dest_path):
    os.makedirs(os.path.dirname(dest_path), exist_ok=True)
    part_path = dest_path + ".part"
    if os.path.isfile(part_path):
        try:
            os.remove(part_path)
        except OSError:
            pass
    cmd = [
        "ffmpeg",
        "-y",
        "-hide_banner",
        "-loglevel",
        "error",
        "-stats",
        "-user_agent",
        "Mozilla/5.0",
        "-i",
        hls_url,
        "-c",
        "copy",
        "-bsf:a",
        "aac_adtstoasc",
        "-movflags",
        "+faststart",
        part_path,
    ]
    proc = await asyncio.create_subprocess_exec(
        *cmd,
        stdout=asyncio.subprocess.DEVNULL,
        stderr=asyncio.subprocess.PIPE,
    )
    _out, err = await proc.communicate()
    if proc.returncode != 0 or not os.path.isfile(part_path):
        try:
            if os.path.isfile(part_path):
                os.remove(part_path)
        except OSError:
            pass
        tail = (err or b"").decode("utf-8", "replace")[-500:]
        return False, tail or f"ffmpeg_exit_{proc.returncode}"
    os.replace(part_path, dest_path)
    return True, None


async def claim_job(pool, job_id, status):
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "UPDATE youtube_vod_uploads SET status = %s, error_message = NULL WHERE id = %s AND status = 'queued'",
                (status, job_id),
            )
            await conn.commit()
            return cur.rowcount == 1


async def process_one(pool, session):
    async with pool.acquire() as conn:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(
                """
                SELECT u.id AS job_id, u.user_id, u.filename, u.title, u.privacy_status,
                       u.source, u.twitch_video_id,
                       t.access_token, t.refresh_token, t.can_upload, t.needs_reauth,
                       usr.username, usr.access_token AS twitch_oauth
                FROM youtube_vod_uploads u
                JOIN youtube_tokens t ON t.user_id = u.user_id
                JOIN users usr ON usr.id = u.user_id
                WHERE u.status = 'queued'
                  AND t.refresh_token IS NOT NULL AND t.refresh_token != ''
                  AND t.needs_reauth = 0
                  AND t.can_upload = 1
                  AND usr.is_admin = 1
                ORDER BY u.id ASC
                LIMIT 1
                """
            )
            job = await cur.fetchone()
    if not job:
        logger.info("ℹ️  No queued YouTube VOD uploads")
        return

    job_id = job["job_id"]
    user_id = job["user_id"]
    username = job["username"]
    filename = job["filename"]
    source = (job.get("source") or "stream")
    twitch_video_id = (job.get("twitch_video_id") or "").strip()
    is_twitch_vod = source == "twitch_vod" or twitch_video_id != ""
    logger.info(f"📤 Starting YouTube upload job {job_id} for {username} ({filename})")
    if not _safe_username(username) or not _safe_filename(filename):
        await set_job(pool, job_id, "failed", error="unsafe_path")
        logger.error(f"❌ Unsafe username or filename for job {job_id}")
        return

    path = os.path.join(VOD_ROOT, username, filename)
    if is_twitch_vod:
        if not await claim_job(pool, job_id, "pulling"):
            logger.info(f"⏭️  Job {job_id} already claimed")
            return
        if not os.path.isfile(path):
            if not twitch_video_id:
                await set_job(pool, job_id, "failed", error="missing_twitch_video_id")
                return
            logger.info(f"⬇️  Pulling Twitch VOD {twitch_video_id} via ffmpeg")
            hls_url, hls_err = await twitch_vod_hls_url(session, twitch_video_id, job.get("twitch_oauth"))
            if hls_err:
                await set_job(pool, job_id, "failed", error=f"twitch_hls:{hls_err}")
                logger.error(f"❌ Could not resolve Twitch HLS for {twitch_video_id}: {hls_err}")
                return
            ok, ffmpeg_err = await ffmpeg_pull_twitch_vod(hls_url, path)
            if not ok:
                await set_job(pool, job_id, "failed", error=f"ffmpeg:{ffmpeg_err}")
                logger.error(f"❌ ffmpeg pull failed for {twitch_video_id}: {ffmpeg_err}")
                return
            logger.info(f"✅ Twitch VOD saved to {path}")
    else:
        if not os.path.isfile(path):
            logger.info(
                f"⏭️  {username}/{filename} is not on this stream host ({VOD_ROOT}); leaving queued"
            )
            return
        if not await claim_job(pool, job_id, "uploading"):
            logger.info(f"⏭️  Job {job_id} already claimed")
            return

    await set_job(pool, job_id, "uploading")
    access = job["access_token"]
    refresh = job["refresh_token"]

    token_body, token_err = await refresh_access(session, refresh)
    if token_err is not None:
        if (token_err or {}).get("error") == "invalid_grant":
            await mark_reauth(pool, user_id)
            await set_job(pool, job_id, "failed", error="youtube_reauth_required")
            logger.warning(f"🚫 YouTube re-auth required for {username}")
            return
        await set_job(pool, job_id, "failed", error="token_refresh_failed")
        logger.error(f"❌ Token refresh failed for {username}")
        return
    access, refresh = await persist_access(pool, user_id, token_body, refresh)

    video_id, err, body = await resumable_upload(
        session, access, path, job["title"], job["privacy_status"]
    )
    if err == "unauthorized":
        token_body, token_err = await refresh_access(session, refresh)
        if token_err is None:
            access, refresh = await persist_access(pool, user_id, token_body, refresh)
            video_id, err, body = await resumable_upload(
                session, access, path, job["title"], job["privacy_status"]
            )

    if video_id:
        await set_job(pool, job_id, "done", video_id=video_id)
        logger.info(f"✅ Uploaded job {job_id} for {username} as {video_id}")
        return

    reason = _error_reason(body) if body else err or "upload_failed"
    if reason in ("quotaExceeded", "uploadLimitExceeded"):
        await set_job(pool, job_id, "failed", error="youtube_daily_upload_quota_reached")
        logger.warning(f"⏳ YouTube quota hit; job {job_id} marked failed for retry later")
        return
    await set_job(pool, job_id, "failed", error=str(err or reason)[:500])
    logger.error(f"❌ Upload failed for job {job_id}: {err}")


async def main():
    lock_fh = open(LOCK_PATH, "a+")
    if fcntl is not None:
        try:
            fcntl.flock(lock_fh.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            logger.info("⏭️  Another YouTube uploader is already running")
            lock_fh.close()
            return

    logger.info(f"Stream files root: {VOD_ROOT}")
    if not YOUTUBE_CLIENT_ID or not YOUTUBE_CLIENT_SECRET:
        logger.error("❌ Missing YOUTUBE_CLIENT_ID / YOUTUBE_CLIENT_SECRET")
        return
    if not DB_HOST or not DB_USER or not DB_PASS:
        logger.error("❌ Missing SQL_HOST / SQL_USER / SQL_PASSWORD")
        return
    try:
        pool = await aiomysql.create_pool(
            host=DB_HOST,
            user=DB_USER,
            password=DB_PASS,
            db=DB_NAME,
            autocommit=True,
        )
    except Exception as e:
        logger.error(f"❌ Failed to connect to database: {e}")
        return
    try:
        timeout = aiohttp.ClientTimeout(total=None, sock_connect=30, sock_read=300)
        async with aiohttp.ClientSession(timeout=timeout) as session:
            await process_one(pool, session)
    except Exception as e:
        logger.error(f"❌ Uploader error: {e}")
    finally:
        pool.close()
        await pool.wait_closed()
        if fcntl is not None:
            try:
                fcntl.flock(lock_fh.fileno(), fcntl.LOCK_UN)
            except OSError:
                pass
        lock_fh.close()


if __name__ == "__main__":
    asyncio.run(main())
