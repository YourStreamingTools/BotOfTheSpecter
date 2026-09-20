#!/usr/bin/env python3
"""Copy queued VODs to each streamer's own S3-compatible bucket."""
import os
import re
import socket
import ipaddress
import time
import asyncio
from urllib.parse import urlparse
import aiomysql
import logging
from logging.handlers import RotatingFileHandler
from dotenv import load_dotenv

try:
    import fcntl
except ImportError:
    fcntl = None

load_dotenv()

DB_HOST = os.getenv("SQL_HOST")
DB_USER = os.getenv("SQL_USER")
DB_PASS = os.getenv("SQL_PASSWORD")
DB_NAME = "website"
CHUNK = 64 * 1024 * 1024
SAFE_USER = re.compile(r"^[a-zA-Z0-9_]{1,64}$")
SAFE_FILE = re.compile(r"^[^/\\]+\.mp4$", re.I)
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
log_file = os.path.join(log_dir, "s3_vod_uploader.log")
logger = logging.getLogger("s3_vod_uploader")
logger.setLevel(logging.INFO)
file_handler = RotatingFileHandler(log_file, maxBytes=200 * 1024, backupCount=5)
file_handler.setFormatter(logging.Formatter("%(asctime)s %(message)s", "%Y-%m-%d %H:%M:%S"))
logger.addHandler(file_handler)
console_handler = logging.StreamHandler()
console_handler.setFormatter(logging.Formatter("%(message)s"))
logger.addHandler(console_handler)
LOCK_PATH = os.path.join(log_dir, "s3_vod_uploader.lock")


def _safe_filename(name):
    if not isinstance(name, str) or not name or "/" in name or "\\" in name or "\x00" in name:
        return False
    if name in (".", "..") or os.path.basename(name) != name:
        return False
    return bool(SAFE_FILE.match(name))


def _normalise_endpoint(raw):
    raw = (raw or "").strip()
    if not raw:
        return ""
    if "://" not in raw:
        raw = "https://" + raw.lstrip("/")
    return raw.rstrip("/")


def _host_is_public(host):
    host = (host or "").strip().lower()
    if host.startswith("[") and host.endswith("]"):
        host = host[1:-1]
    if not host or host == "localhost" or host.endswith(".localhost") or host.endswith(".local"):
        return False
    try:
        infos = socket.getaddrinfo(host, None)
    except socket.gaierror:
        return False
    if not infos:
        return False
    for info in infos:
        ip = ipaddress.ip_address(info[4][0])
        if (
            ip.is_private
            or ip.is_loopback
            or ip.is_link_local
            or ip.is_reserved
            or ip.is_multicast
            or ip.is_unspecified
        ):
            return False
    return True


def endpoint_ok(raw):
    parsed = urlparse(_normalise_endpoint(raw))
    if parsed.scheme not in ("http", "https") or not parsed.hostname:
        return False
    if parsed.username or parsed.password:
        return False
    return _host_is_public(parsed.hostname)


def object_key(prefix, username, filename):
    if not SAFE_USER.match(username or "") or not _safe_filename(filename):
        return None
    prefix = (prefix or "").strip("/")
    if prefix and (".." in prefix or not re.match(r"^[A-Za-z0-9._/-]+$", prefix)):
        return None
    base = f"{username}/{filename}"
    return f"{prefix}/{base}" if prefix else base


def make_client(row):
    import boto3
    from botocore.config import Config as BotoConfig

    endpoint = _normalise_endpoint(row.get("endpoint") or "")
    region = (row.get("region") or "").strip() or "us-east-1"
    return boto3.client(
        "s3",
        endpoint_url=endpoint,
        region_name=region,
        aws_access_key_id=row.get("access_key") or "",
        aws_secret_access_key=row.get("secret_key") or "",
        config=BotoConfig(
            s3={"addressing_style": "path" if int(1 if row.get("path_style") is None else row.get("path_style")) else "virtual"}
        ),
    )


async def set_job(pool, job_id, status, object_key=None, error=None):
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "UPDATE user_s3_uploads SET status = %s, object_key = %s, error_message = %s WHERE id = %s",
                (status, object_key, error, job_id),
            )
            await conn.commit()


async def set_progress(pool, job_id, sent, total):
    sent = int(sent or 0)
    total = int(total or 0)
    pct = round((100.0 * sent / total), 1) if total > 0 else 0.0
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "UPDATE user_s3_uploads SET bytes_sent = %s, bytes_total = %s, progress_percent = %s WHERE id = %s",
                    (sent, total, pct, job_id),
                )
                await conn.commit()
    except Exception as e:
        logger.warning(f"Could not store S3 progress for job {job_id}: {e}")
    return pct


async def claim_job(pool, job_id):
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "UPDATE user_s3_uploads SET status = 'uploading', error_message = NULL WHERE id = %s AND status = 'queued'",
                (job_id,),
            )
            await conn.commit()
            return cur.rowcount == 1


async def fail_stale_jobs(pool):
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """
                UPDATE user_s3_uploads
                SET status = 'failed', error_message = 'stale_progress'
                WHERE status = 'uploading'
                  AND updated_at < (NOW() - INTERVAL 1800 SECOND)
                """
            )
            n = cur.rowcount
            await conn.commit()
    if n:
        logger.warning(f"Marked {n} stale S3 job(s) as failed")


def upload_file_sync(client, path, bucket, key, size, progress_state):
    from boto3.s3.transfer import TransferConfig

    def cb(n):
        progress_state["sent"] = progress_state.get("sent", 0) + n

    extra = {"ContentType": "video/mp4"}
    client.upload_file(
        path,
        bucket,
        key,
        ExtraArgs=extra,
        Config=TransferConfig(
            multipart_threshold=CHUNK,
            multipart_chunksize=CHUNK,
            max_concurrency=4,
        ),
        Callback=cb,
    )


async def process_one(pool):
    async with pool.acquire() as conn:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(
                """
                SELECT u.id AS job_id, u.user_id, u.filename, u.title,
                       s.endpoint, s.region, s.bucket, s.prefix, s.access_key, s.secret_key, s.path_style,
                       usr.username
                FROM user_s3_uploads u
                JOIN user_s3_settings s ON s.user_id = u.user_id
                JOIN users usr ON usr.id = u.user_id
                WHERE u.status = 'queued'
                  AND s.endpoint IS NOT NULL AND s.endpoint != ''
                  AND s.bucket IS NOT NULL AND s.bucket != ''
                  AND s.access_key IS NOT NULL AND s.access_key != ''
                  AND s.secret_key IS NOT NULL AND s.secret_key != ''
                ORDER BY u.id ASC
                LIMIT 1
                """
            )
            job = await cur.fetchone()
    if not job:
        logger.info("No queued user S3 VOD copies")
        return None

    job_id = job["job_id"]
    username = job["username"]
    filename = job["filename"]
    logger.info(f"Starting S3 copy job {job_id} for {username} ({filename})")
    if not SAFE_USER.match(username or "") or not _safe_filename(filename):
        await set_job(pool, job_id, "failed", error="unsafe_path")
        return True
    if not endpoint_ok(job.get("endpoint") or ""):
        await set_job(pool, job_id, "failed", error="bad_endpoint")
        return True
    key = object_key(job.get("prefix") or "", username, filename)
    if not key:
        await set_job(pool, job_id, "failed", error="bad_key")
        return True
    path = os.path.join(VOD_ROOT, username, filename)
    if not os.path.isfile(path):
        logger.info(f"{username}/{filename} is not on this stream host; leaving queued")
        return "skipped"
    if not await claim_job(pool, job_id):
        return True
    size = os.path.getsize(path)
    await set_progress(pool, job_id, 0, size)
    progress_state = {"sent": 0}
    stop = asyncio.Event()

    async def ticker():
        last = -1
        while not stop.is_set():
            sent = int(progress_state.get("sent") or 0)
            if sent != last:
                last = sent
                await set_progress(pool, job_id, sent, size)
                logger.info(f"Job {job_id} S3 upload {round(100.0 * sent / size, 1) if size else 0}% ({sent}/{size})")
            try:
                await asyncio.wait_for(stop.wait(), timeout=2)
            except asyncio.TimeoutError:
                pass

    tick = asyncio.create_task(ticker())
    try:
        client = make_client(job)
        await asyncio.to_thread(upload_file_sync, client, path, job["bucket"], key, size, progress_state)
    except Exception as e:
        stop.set()
        await tick
        err = str(e)[:500]
        logger.error(f"S3 copy failed for job {job_id}: {err}")
        await set_job(pool, job_id, "failed", error=err)
        return True
    stop.set()
    await tick
    await set_progress(pool, job_id, size, size)
    await set_job(pool, job_id, "done", object_key=key)
    logger.info(f"Copied job {job_id} for {username} to {key}")
    return True


async def main():
    lock_fh = open(LOCK_PATH, "a+")
    if fcntl is not None:
        try:
            fcntl.flock(lock_fh.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            logger.info("Another S3 VOD copier is already running")
            lock_fh.close()
            return
    if not DB_HOST or not DB_USER or not DB_PASS:
        logger.error("Missing SQL_HOST / SQL_USER / SQL_PASSWORD")
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
        logger.error(f"Failed to connect to database: {e}")
        return
    try:
        await fail_stale_jobs(pool)
        while True:
            result = await process_one(pool)
            if result is None or result == "skipped":
                break
    except Exception as e:
        logger.error(f"S3 copier error: {e}")
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
