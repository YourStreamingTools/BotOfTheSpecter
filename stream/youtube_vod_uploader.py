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
import time
import inspect
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
CHUNK_SIZE = 64 * 1024 * 1024
YOUTUBE_MAX_DURATION_S = 12 * 3600
YOUTUBE_MAX_BYTES = 256 * 1024 * 1024 * 1024
TWITCH_WEB_CLIENT_ID = os.getenv("TWITCH_WEB_CLIENT_ID", "kimne78kx3ncx6brgo4mv6wki5h1ko")
TWITCH_GQL = "https://gql.twitch.tv/gql"
TWITCH_USHER = "https://usher.ttvnw.net/vod/{vod_id}.m3u8"
PLAYBACK_QUERY = """
query PlaybackAccessToken($id: ID!, $playerType: String!) {
  video(id: $id) {
    playbackAccessToken(params: {platform: "web", playerBackend: "mediaplayer", playerType: $playerType}) {
      value
      signature
    }
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


STREAM_STORAGE_QUOTA_BYTES = int(os.getenv("STREAM_STORAGE_QUOTA_BYTES") or str(100 * 1024 * 1024 * 1024))


def _directory_size_bytes(path):
    total = 0
    if not os.path.isdir(path):
        return 0
    for root, _dirs, files in os.walk(path):
        for name in files:
            file_path = os.path.join(root, name)
            try:
                total += os.path.getsize(file_path)
            except OSError:
                continue
    return total


async def get_storage_slot(pool, user_id):
    async with pool.acquire() as conn:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(
                "SELECT quota_bytes, bonus_bytes FROM stream_storage_slots WHERE user_id = %s LIMIT 1",
                (user_id,),
            )
            row = await cur.fetchone()
    if not row:
        return STREAM_STORAGE_QUOTA_BYTES
    raw_quota = row.get("quota_bytes")
    quota = STREAM_STORAGE_QUOTA_BYTES if raw_quota is None else int(raw_quota)
    bonus = int(row.get("bonus_bytes") or 0)
    if quota == 0:
        return 0
    return quota + bonus


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


async def set_progress(pool, job_id, sent, total, percent=None):
    sent = int(sent or 0)
    total = int(total or 0)
    if percent is not None:
        try:
            pct = round(max(0.0, min(100.0, float(percent))), 1)
        except (TypeError, ValueError):
            pct = round((100.0 * sent / total), 1) if total > 0 else 0.0
    else:
        pct = round((100.0 * sent / total), 1) if total > 0 else 0.0
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "UPDATE youtube_vod_uploads SET bytes_sent = %s, bytes_total = %s, progress_percent = %s WHERE id = %s",
                    (sent, total, pct, job_id),
                )
                await conn.commit()
    except Exception as e:
        logger.warning(f"Could not store upload progress for job {job_id}: {e}")
    return pct


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


def youtube_file_over_limit(path, duration_s=None):
    try:
        size = os.path.getsize(path)
    except OSError:
        size = 0
    if size > YOUTUBE_MAX_BYTES:
        return "too_large"
    if duration_s is not None and duration_s > YOUTUBE_MAX_DURATION_S:
        return "too_long"
    return None


async def probe_duration_seconds(path):
    try:
        proc = await asyncio.create_subprocess_exec(
            "ffprobe",
            "-v",
            "error",
            "-show_entries",
            "format=duration",
            "-of",
            "default=noprint_wrappers=1:nokey=1",
            path,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.DEVNULL,
        )
        out, _ = await proc.communicate()
        raw = (out or b"").decode("utf-8", "replace").strip()
        if not raw:
            return None
        value = float(raw)
        if value < 0:
            return None
        return value
    except Exception:
        return None


async def resumable_upload(session, access_token, path, title, privacy, on_progress=None):
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

    logger.info(f"⬆️  Uploading {path} ({size} bytes) as {title!r}")
    if on_progress:
        await on_progress(0, size)
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
                    if on_progress:
                        await on_progress(size, size)
                    result = await read_json(resp)
                    video_id = result.get("id") if isinstance(result, dict) else None
                    if not video_id:
                        return None, "missing_video_id", result
                    return video_id, None, result
                if resp.status == 308:
                    sent = end + 1
                    if on_progress:
                        await on_progress(sent, size)
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


def _gql_playback_headers(twitch_oauth):
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
    return headers


def _playback_token_from_gql(body):
    data = body.get("data") if isinstance(body, dict) else None
    if not isinstance(data, dict):
        return None, None
    token_row = data.get("videoPlaybackAccessToken")
    if not isinstance(token_row, dict):
        video = data.get("video") if isinstance(data.get("video"), dict) else {}
        token_row = video.get("playbackAccessToken") if isinstance(video.get("playbackAccessToken"), dict) else {}
    return token_row.get("value"), token_row.get("signature")


async def twitch_vod_hls_url(session, vod_id, twitch_oauth):
    payload = {
        "operationName": "PlaybackAccessToken",
        "query": PLAYBACK_QUERY,
        "variables": {
            "id": str(vod_id),
            "playerType": "site",
        },
    }
    attempts = [twitch_oauth, ""] if (twitch_oauth or "").strip() else [""]
    body = {}
    last_status = None
    for oauth in attempts:
        headers = _gql_playback_headers(oauth)
        async with session.post(TWITCH_GQL, headers=headers, json=payload) as resp:
            last_status = resp.status
            body = await read_json(resp)
        if last_status != 200:
            continue
        value, signature = _playback_token_from_gql(body)
        if value and signature:
            break
    else:
        value, signature = None, None
    if last_status != 200 and not value:
        return None, f"gql_http_{last_status}"
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


DURATION_RE = re.compile(rb"Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)")
TIME_RE = re.compile(rb"time=\s*(\d+):(\d+):(\d+(?:\.\d+)?)")
SIZE_RE = re.compile(rb"size=\s*(\d+)kB", re.I)


def _hms_to_s(h, m, s):
    return int(h) * 3600 + int(m) * 60 + float(s)


async def ffmpeg_pull_twitch_vod(hls_url, dest_path, on_progress=None, on_pid=None):
    from ffmpeg_jobs import find_ffmpeg_pid_for_path

    os.makedirs(os.path.dirname(dest_path), exist_ok=True)
    part_path = dest_path + ".part"
    if find_ffmpeg_pid_for_path(dest_path):
        return False, "already_running"
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
        "info",
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
        "-f",
        "mp4",
        part_path,
    ]
    proc = await asyncio.create_subprocess_exec(
        *cmd,
        stdout=asyncio.subprocess.DEVNULL,
        stderr=asyncio.subprocess.PIPE,
        start_new_session=True,
    )
    if on_pid:
        on_pid(proc.pid)
    buf = b""
    duration_s = None
    tail = []
    try:
        while True:
            chunk = await proc.stderr.read(256)
            if not chunk:
                break
            buf += chunk
            parts = re.split(rb"[\r\n]", buf)
            buf = parts[-1]
            for raw in parts[:-1]:
                if not raw:
                    continue
                line = raw.decode("utf-8", "replace").rstrip()
                if line:
                    tail.append(line)
                    if len(tail) > 40:
                        del tail[: len(tail) - 40]
                if duration_s is None:
                    dm = DURATION_RE.search(raw)
                    if dm:
                        duration_s = _hms_to_s(*dm.groups())
                tm = TIME_RE.search(raw)
                if tm and on_progress:
                    cur = _hms_to_s(*tm.groups())
                    pct = max(0.0, min(100.0, cur / duration_s * 100.0)) if duration_s else None
                    sm = SIZE_RE.search(raw)
                    nbytes = int(sm.group(1)) * 1024 if sm else None
                    maybe = on_progress(pct, cur, duration_s, nbytes)
                    if inspect.isawaitable(maybe):
                        await maybe
        rc = await proc.wait()
    except asyncio.CancelledError:
        # Leave ffmpeg running across a Python restart.
        raise
    if rc != 0 or not os.path.isfile(part_path):
        try:
            if os.path.isfile(part_path) and not find_ffmpeg_pid_for_path(dest_path):
                os.remove(part_path)
        except OSError:
            pass
        return False, "\n".join(tail[-8:]) or f"ffmpeg_exit_{rc}"
    os.replace(part_path, dest_path)
    if on_progress:
        maybe = on_progress(100.0, duration_s or 0.0, duration_s, os.path.getsize(dest_path))
        if inspect.isawaitable(maybe):
            await maybe
    return True, None


STALE_JOB_SECONDS = 30 * 60


async def fail_stale_jobs(pool):
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """
                UPDATE youtube_vod_uploads
                SET status = 'failed', error_message = 'stale_progress'
                WHERE status IN ('pulling', 'uploading')
                  AND updated_at < (NOW() - INTERVAL %s SECOND)
                """,
                (STALE_JOB_SECONDS,),
            )
            n = cur.rowcount
            await conn.commit()
    if n:
        logger.warning(f"Marked {n} stale YouTube job(s) as failed")


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
        return None

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
        return True

    path = os.path.join(VOD_ROOT, username, filename)
    if is_twitch_vod:
        quota = await get_storage_slot(pool, user_id)
        if quota is None:
            await set_job(pool, job_id, "failed", error="no_stream_storage_slot")
            logger.error(
                f"❌ {username} has no stream storage slot "
                f"(max 5 users due to limited storage space)"
            )
            return True
        used = _directory_size_bytes(os.path.join(VOD_ROOT, username))
        if quota > 0 and used >= quota:
            await set_job(pool, job_id, "failed", error="stream_storage_full")
            logger.error(f"❌ {username} stream storage is full ({used} / {quota} bytes)")
            return True
        if not await claim_job(pool, job_id, "pulling"):
            logger.info(f"⏭️  Job {job_id} already claimed")
            return True
        await set_progress(pool, job_id, 0, 0, percent=0)
        if not os.path.isfile(path):
            if not twitch_video_id:
                await set_job(pool, job_id, "failed", error="missing_twitch_video_id")
                return True
            logger.info(f"⬇️  Pulling Twitch VOD {twitch_video_id} via ffmpeg")
            hls_url, hls_err = await twitch_vod_hls_url(session, twitch_video_id, job.get("twitch_oauth"))
            if hls_err:
                await set_job(pool, job_id, "failed", error=f"twitch_hls:{hls_err}")
                logger.error(f"❌ Could not resolve Twitch HLS for {twitch_video_id}: {hls_err}")
                return True
            last_pull_at = 0.0

            async def on_pull_progress(pct, cur, duration_s, nbytes):
                nonlocal last_pull_at
                now = time.monotonic()
                if now - last_pull_at < 2:
                    return
                last_pull_at = now
                await set_progress(pool, job_id, int(nbytes or 0), 0, percent=pct)
                if pct is not None:
                    logger.info(f"⬇️  Job {job_id} download {pct:.1f}%")

            ok, ffmpeg_err = await ffmpeg_pull_twitch_vod(hls_url, path, on_progress=on_pull_progress)
            if not ok:
                await set_job(pool, job_id, "failed", error=f"ffmpeg:{ffmpeg_err}")
                logger.error(f"❌ ffmpeg pull failed for {twitch_video_id}: {ffmpeg_err}")
                return True
            logger.info(f"✅ Twitch VOD saved to {path}")
    else:
        if not os.path.isfile(path):
            logger.info(
                f"⏭️  {username}/{filename} is not on this stream host ({VOD_ROOT}); leaving queued"
            )
            return "skipped"
        if not await claim_job(pool, job_id, "uploading"):
            logger.info(f"⏭️  Job {job_id} already claimed")
            return True

    if not os.path.isfile(path):
        await set_job(pool, job_id, "failed", error="missing_file")
        logger.error(f"❌ Missing file for job {job_id}: {path}")
        return True

    duration_s = await probe_duration_seconds(path)
    limit = youtube_file_over_limit(path, duration_s)
    if limit:
        await set_job(pool, job_id, "failed", error=f"youtube_limit:{limit}")
        logger.warning(
            f"⏭️  Job {job_id} for {username} exceeds YouTube limits ({limit}); file kept"
        )
        return True

    try:
        file_size = os.path.getsize(path)
    except OSError:
        file_size = 0
    await set_progress(pool, job_id, 0, file_size, percent=0)
    await set_job(pool, job_id, "uploading")
    access = job["access_token"]
    refresh = job["refresh_token"]

    token_body, token_err = await refresh_access(session, refresh)
    if token_err is not None:
        if (token_err or {}).get("error") == "invalid_grant":
            await mark_reauth(pool, user_id)
            await set_job(pool, job_id, "failed", error="youtube_reauth_required")
            logger.warning(f"🚫 YouTube re-auth required for {username}")
            return True
        await set_job(pool, job_id, "failed", error="token_refresh_failed")
        logger.error(f"❌ Token refresh failed for {username}")
        return True
    access, refresh = await persist_access(pool, user_id, token_body, refresh)

    last_progress_at = 0.0

    async def report_progress(sent, total):
        nonlocal last_progress_at
        now = time.monotonic()
        if sent < total and now - last_progress_at < 2:
            return
        last_progress_at = now
        pct = await set_progress(pool, job_id, sent, total)
        logger.info(f"⬆️  Job {job_id} upload {pct}% ({sent}/{total})")

    video_id, err, body = await resumable_upload(
        session, access, path, job["title"], job["privacy_status"], on_progress=report_progress
    )
    if err == "unauthorized":
        token_body, token_err = await refresh_access(session, refresh)
        if token_err is None:
            access, refresh = await persist_access(pool, user_id, token_body, refresh)
            video_id, err, body = await resumable_upload(
                session, access, path, job["title"], job["privacy_status"], on_progress=report_progress
            )

    if video_id:
        await set_progress(pool, job_id, os.path.getsize(path), os.path.getsize(path))
        await set_job(pool, job_id, "done", video_id=video_id)
        logger.info(f"✅ Uploaded job {job_id} for {username} as {video_id}")
        return True

    reason = _error_reason(body) if body else err or "upload_failed"
    if reason in ("quotaExceeded", "uploadLimitExceeded"):
        await set_job(pool, job_id, "failed", error="youtube_daily_upload_quota_reached")
        logger.warning(f"⏳ YouTube quota hit; job {job_id} marked failed for retry later")
        return True
    await set_job(pool, job_id, "failed", error=str(err or reason)[:500])
    logger.error(f"❌ Upload failed for job {job_id}: {err}")
    return True


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
            await fail_stale_jobs(pool)
            while True:
                result = await process_one(pool, session)
                if result is None or result == "skipped":
                    break;
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
