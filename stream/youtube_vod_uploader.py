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
import json
import time
import asyncio
import tempfile
import aiohttp
import aiomysql
import logging
from logging.handlers import RotatingFileHandler
from dotenv import load_dotenv
from twitch_vod_downloader import pull_gate

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


STALE_JOB_SECONDS = 30 * 60


async def fail_stale_jobs(pool):
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """
                UPDATE youtube_vod_uploads
                SET status = 'failed', error_message = 'stale_progress'
                WHERE status IN ('pulling', 'uploading')
                  AND NOT (status = 'pulling' AND source = 'twitch_vod')
                  AND updated_at < (NOW() - INTERVAL %s SECOND)
                """,
                (STALE_JOB_SECONDS,),
            )
            n = cur.rowcount
            await conn.commit()
    if n:
        logger.warning(f"Marked {n} stale YouTube job(s) as failed")


async def claim_job(pool, job_id, status, from_statuses=("queued",)):
    placeholders = ", ".join(["%s"] * len(from_statuses))
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                f"UPDATE youtube_vod_uploads SET status = %s, error_message = NULL WHERE id = %s AND status IN ({placeholders})",
                (status, job_id, *from_statuses),
            )
            await conn.commit()
            return cur.rowcount == 1


async def mark_waiting_on_pull(pool, job_id, pull):
    """Job stays 'pulling' while the shared Twitch pull runs; mirror its progress."""
    pull = pull or {}
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """
                UPDATE youtube_vod_uploads
                SET status = 'pulling', error_message = NULL, bytes_sent = %s, bytes_total = %s,
                    progress_percent = %s, updated_at = NOW()
                WHERE id = %s AND status IN ('queued', 'pulling')
                """,
                (int(pull.get("bytes_sent") or 0), int(pull.get("bytes_total") or 0),
                 float(pull.get("progress_percent") or 0), job_id),
            )
            await conn.commit()


def _discard_file(path):
    if path and os.path.isfile(path):
        try:
            os.remove(path)
        except OSError:
            pass


async def pull_user_s3_vod(pool, user_id, filename, job_id):
    async with pool.acquire() as conn:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(
                """
                SELECT endpoint, region, bucket, prefix, access_key, secret_key, path_style
                FROM user_s3_settings WHERE user_id = %s LIMIT 1
                """,
                (user_id,),
            )
            row = await cur.fetchone()
    if not row or not (row.get("access_key") or "") or not (row.get("bucket") or ""):
        return None
    from s3_vod_uploader import _object_is_absent, make_client, object_key

    key = object_key(row.get("prefix") or "", filename)
    if not key:
        return None
    dest = os.path.join(tempfile.gettempdir(), f"yt-s3-{int(job_id)}.mp4")

    def fetch():
        client = make_client(row)
        state = _object_is_absent(client, row["bucket"], key)
        if state is not False:
            return state
        client.download_file(row["bucket"], key, dest)
        return False

    try:
        state = await asyncio.to_thread(fetch)
    except Exception as exc:
        _discard_file(dest)
        await set_job(pool, job_id, "failed", error="s3_download_failed")
        logger.error(f"S3 download failed for job {job_id}: {type(exc).__name__}")
        return ""
    if state is not False:
        _discard_file(dest)
        return None
    if not os.path.isfile(dest):
        await set_job(pool, job_id, "failed", error="s3_download_failed")
        return ""
    logger.info(f"Downloaded user S3 object for YouTube job {job_id}")
    return dest


async def process_one(pool, session, skip_ids):
    skip_sql = ""
    params = []
    if skip_ids:
        skip_sql = "AND u.id NOT IN (" + ", ".join(["%s"] * len(skip_ids)) + ")"
        params = list(skip_ids)
    async with pool.acquire() as conn:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(
                f"""
                SELECT u.id AS job_id, u.user_id, u.filename, u.title, u.privacy_status,
                       u.status AS job_status, u.source, u.twitch_video_id,
                       t.access_token, t.refresh_token, t.can_upload, t.needs_reauth,
                       usr.username
                FROM youtube_vod_uploads u
                JOIN youtube_tokens t ON t.user_id = u.user_id
                JOIN users usr ON usr.id = u.user_id
                WHERE (u.status = 'queued' OR (u.status = 'pulling' AND u.source = 'twitch_vod'))
                  AND t.refresh_token IS NOT NULL AND t.refresh_token != ''
                  AND t.needs_reauth = 0
                  AND t.can_upload = 1
                  AND usr.is_admin = 1
                  {skip_sql}
                ORDER BY u.id ASC
                LIMIT 1
                """,
                params,
            )
            job = await cur.fetchone()
    if not job:
        if not skip_ids:
            logger.info("ℹ️  No queued YouTube VOD uploads")
        return None

    job_id = job["job_id"]
    user_id = job["user_id"]
    username = job["username"]
    filename = job["filename"]
    source = (job.get("source") or "stream")
    twitch_video_id = (job.get("twitch_video_id") or "").strip()
    is_twitch_vod = source == "twitch_vod" or twitch_video_id != ""
    if not _safe_username(username) or not _safe_filename(filename):
        await set_job(pool, job_id, "failed", error="unsafe_path")
        logger.error(f"❌ Unsafe username or filename for job {job_id}")
        return True

    path = os.path.join(VOD_ROOT, username, filename)
    s3_temp = None
    if is_twitch_vod:
        if not twitch_video_id:
            await set_job(pool, job_id, "failed", error="missing_twitch_video_id")
            return True
        gate, info = await pull_gate(
            pool, user_id, username, twitch_video_id, job.get("title") or "", path,
            waiting=job.get("job_status") == "pulling",
        )
        if gate == "failed":
            await set_job(pool, job_id, "failed", error=f"twitch_pull_failed:{info}"[:500])
            logger.error(f"❌ Twitch pull for job {job_id} ({twitch_video_id}) failed: {info}")
            return True
        if gate == "waiting":
            await mark_waiting_on_pull(pool, job_id, info)
            logger.info(f"⏳ Job {job_id} waiting on Twitch VOD {twitch_video_id} download")
            skip_ids.add(job_id)
            return "waiting"
        if not await claim_job(pool, job_id, "uploading", ("queued", "pulling")):
            logger.info(f"⏭️  Job {job_id} already claimed")
            return True
        logger.info(f"📤 Starting YouTube upload job {job_id} for {username} ({filename})")
    else:
        logger.info(f"📤 Starting YouTube upload job {job_id} for {username} ({filename})")
        s3_temp = None
        if not os.path.isfile(path):
            if not await claim_job(pool, job_id, "pulling"):
                logger.info(f"⏭️  Job {job_id} already claimed")
                return True
            s3_temp = await pull_user_s3_vod(pool, user_id, filename, job_id)
            if s3_temp is None:
                await set_job(pool, job_id, "queued")
                logger.info(
                    f"⏭️  {username}/{filename} is not on this stream host ({VOD_ROOT}); leaving queued"
                )
                skip_ids.add(job_id)
                return "skipped"
            if s3_temp == "":
                return True
            path = s3_temp
        elif not await claim_job(pool, job_id, "uploading"):
            logger.info(f"⏭️  Job {job_id} already claimed")
            return True

    if not os.path.isfile(path):
        _discard_file(s3_temp)
        await set_job(pool, job_id, "failed", error="missing_file")
        logger.error(f"❌ Missing file for job {job_id}: {path}")
        return True

    duration_s = await probe_duration_seconds(path)
    limit = youtube_file_over_limit(path, duration_s)
    if limit:
        _discard_file(s3_temp)
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
            _discard_file(s3_temp)
            await set_job(pool, job_id, "failed", error="youtube_reauth_required")
            logger.warning(f"🚫 YouTube re-auth required for {username}")
            return True
        _discard_file(s3_temp)
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
        _discard_file(s3_temp)
        await set_job(pool, job_id, "done", video_id=video_id)
        logger.info(f"✅ Uploaded job {job_id} for {username} as {video_id}")
        return True

    reason = _error_reason(body) if body else err or "upload_failed"
    if reason in ("quotaExceeded", "uploadLimitExceeded"):
        _discard_file(s3_temp)
        await set_job(pool, job_id, "failed", error="youtube_daily_upload_quota_reached")
        logger.warning(f"⏳ YouTube quota hit; job {job_id} marked failed for retry later")
        return True
    _discard_file(s3_temp)
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
            # Jobs waiting on a Twitch download (or on another host's file) are skipped
            # for this run so they don't block the rest of the queue.
            skip_ids = set()
            while True:
                result = await process_one(pool, session, skip_ids)
                if result is None:
                    break
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
