#!/usr/bin/env python3
# Plays scheduled VOD reruns to Twitch (rerun-scheduler.service on the stream server)
import asyncio
import datetime
import json
import os
import re
import shutil
import signal
import subprocess
import time
import logging
from logging.handlers import RotatingFileHandler

import aiohttp
import aiomysql
from dotenv import load_dotenv

from ffmpeg_jobs import (
    AttachedProcess, cmdline_has, ffmpeg_returncode, find_ffmpeg_pid_for_path, pid_alive,
    remove_media_and_sidecars, spawn_detached_ffmpeg,
)
from upload_hold import filenames_held_for_upload

try:
    import fcntl
except ImportError:
    fcntl = None

load_dotenv()

DB_HOST = os.getenv("SQL_HOST")
DB_USER = os.getenv("SQL_USER")
DB_PASS = os.getenv("SQL_PASSWORD")
DB_NAME = "website"
CLIENT_ID = os.getenv("CLIENT_ID")
CLIENT_SECRET = os.getenv("CLIENT_SECRET")
MIN_DISK_FREE_BYTES = int(os.getenv("STREAM_MIN_DISK_FREE_BYTES") or str(20 * 1024 * 1024 * 1024))
RECORDING_RETENTION_SECONDS = int(os.getenv("RECORDING_RETENTION_SECONDS") or "86400")

POLL_SECONDS = 10
WATCH_SECONDS = 5
# A rerun the worker didn't start within this long of its time (service down) is dropped, not started late.
MISSED_GRACE_SECONDS = 15 * 60
# VODs that aren't on local disk are copied this far ahead of the start.
STAGE_AHEAD_SECONDS = 6 * 3600
TITLE_MAX = 140
RERUN_PREFIX = "RERUN - "

TWITCH_INGEST_SERVERS = {
    "sydney": "rtmps://syd03.contribute.live-video.net/app/",
    "us-west": "rtmps://sea02.contribute.live-video.net/app/",
    "us-east": "rtmps://atl.contribute.live-video.net/app/",
    "eu-central": "rtmps://fra05.contribute.live-video.net/app/",
}

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
STAGE_ROOT = os.path.join(VOD_ROOT, "_reruns")
INGEST_URL = os.getenv("RERUN_INGEST_URL") or TWITCH_INGEST_SERVERS.get(
    (os.getenv("STREAM_SERVER") or "").lower().strip(), TWITCH_INGEST_SERVERS["sydney"]
)

log_dir = os.path.join(STREAM_DIR, "logs")
os.makedirs(log_dir, exist_ok=True)
log_file = os.path.join(log_dir, "rerun_scheduler.log")
logger = logging.getLogger("rerun_scheduler")
logger.setLevel(logging.INFO)

file_handler = RotatingFileHandler(log_file, maxBytes=200 * 1024, backupCount=5)
file_handler.setFormatter(logging.Formatter("%(asctime)s %(message)s", "%Y-%m-%d %H:%M:%S"))
logger.addHandler(file_handler)
console_handler = logging.StreamHandler()
console_handler.setFormatter(logging.Formatter("%(message)s"))
logger.addHandler(console_handler)

LOCK_PATH = os.path.join(log_dir, "rerun_scheduler.lock")


def _has_control_chars(text):
    return any(ord(ch) < 0x20 or ord(ch) == 0x7F for ch in text)


def _safe_filename(name):
    if not isinstance(name, str) or not name:
        return False
    # Control characters (newlines especially) could add lines to the ffmpeg concat list.
    if "/" in name or "\\" in name or _has_control_chars(name):
        return False
    if name in (".", "..") or os.path.basename(name) != name:
        return False
    return name.lower().endswith(".mp4")


def _safe_username(name):
    if not isinstance(name, str):
        return False
    return bool(re.match(r"^[a-zA-Z0-9_]{1,64}$", name))


def _discard_file(path):
    if path and os.path.isfile(path):
        try:
            os.remove(path)
        except OSError:
            pass


def rerun_title(title):
    text = re.sub(r"\s+", " ", str(title or "")).strip() or "Rerun"
    return (RERUN_PREFIX + text)[:TITLE_MAX].rstrip()


def stage_dir(rerun_id):
    return os.path.join(STAGE_ROOT, str(int(rerun_id)))


def scrub(text, stream_key=""):
    text = str(text or "")
    if stream_key:
        text = text.replace(stream_key, "***")
    text = re.sub(r"live_[A-Za-z0-9_]+", "***", text)
    return text.replace(INGEST_URL, "twitch-ingest/")


def log_tail(path, limit=300):
    try:
        size = os.path.getsize(path)
        with open(path, "rb") as handle:
            if size > 4096:
                handle.seek(size - 4096)
            lines = [line.strip() for line in handle.read().decode("utf-8", "replace").splitlines() if line.strip()]
    except OSError:
        return ""
    return lines[-1][:limit] if lines else ""


# Database

NOW = object()
_RERUN_COLS = {"status", "cancel_requested", "current_position", "error_message", "started_at", "finished_at"}
_ITEM_COLS = {"status", "staged_path", "ffmpeg_pid", "error_message", "started_at", "finished_at"}


async def _update(pool, table, cols, row_id, fields, only_status=None):
    sets, args = [], []
    for key, value in fields.items():
        if key not in cols:
            raise ValueError(f"unknown column {key}")
        if value is NOW:
            sets.append(f"{key} = UTC_TIMESTAMP()")
            continue
        if key == "error_message" and value is not None:
            value = str(value)[:255]
        sets.append(f"{key} = %s")
        args.append(value)
    sql = f"UPDATE {table} SET {', '.join(sets)} WHERE id = %s"
    args.append(row_id)
    if only_status:
        sql += " AND status IN (" + ", ".join(["%s"] * len(only_status)) + ")"
        args.extend(only_status)
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(sql, args)
            return cur.rowcount


async def set_rerun(pool, rerun_id, only_status=None, **fields):
    return await _update(pool, "vod_reruns", _RERUN_COLS, rerun_id, fields, only_status)


async def set_item(pool, item_id, only_status=None, **fields):
    return await _update(pool, "vod_rerun_items", _ITEM_COLS, item_id, fields, only_status)


async def fetch_all(pool, sql, args=()):
    async with pool.acquire() as conn:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(sql, args)
            return list(await cur.fetchall() or [])


async def fetch_one(pool, sql, args=()):
    rows = await fetch_all(pool, sql, args)
    return rows[0] if rows else None


async def cancel_requested(pool, rerun_id):
    row = await fetch_one(pool, "SELECT status, cancel_requested FROM vod_reruns WHERE id = %s", (rerun_id,))
    return not row or int(row.get("cancel_requested") or 0) == 1 or row.get("status") == "cancelled"


async def stream_key_for(username):
    conn = None
    try:
        conn = await aiomysql.connect(host=DB_HOST, user=DB_USER, password=DB_PASS, db=username)
        async with conn.cursor() as cur:
            await cur.execute("SELECT twitch_key FROM streaming_settings ORDER BY id ASC LIMIT 1")
            row = await cur.fetchone()
        return (row[0] or "").strip() if row else ""
    except Exception as exc:
        logger.error(f"Could not read the stream key for {username}: {type(exc).__name__}")
        return ""
    finally:
        if conn is not None:
            conn.close()


# Twitch

class TwitchChannel:

    def __init__(self, pool, session, user_id):
        self.pool = pool
        self.session = session
        self.user_id = int(user_id)
        self.twitch_user_id = ""
        self.access = ""
        self.refresh = ""
        self.client_id = CLIENT_ID or ""
        self.scopes = set()
        self.last_title = None

    async def load(self):
        row = await fetch_one(
            self.pool,
            "SELECT twitch_user_id, access_token, refresh_token FROM users WHERE id = %s LIMIT 1",
            (self.user_id,),
        )
        if not row:
            return False
        self.twitch_user_id = str(row.get("twitch_user_id") or "").strip()
        self.access = (row.get("access_token") or "").strip()
        self.refresh = (row.get("refresh_token") or "").strip()
        return bool(self.twitch_user_id)

    async def _validate(self):
        if not self.access:
            return False
        try:
            async with self.session.get(
                "https://id.twitch.tv/oauth2/validate",
                headers={"Authorization": f"OAuth {self.access}"},
                timeout=aiohttp.ClientTimeout(total=15),
            ) as resp:
                if resp.status != 200:
                    return False
                body = await resp.json(content_type=None)
        except Exception:
            return False
        self.client_id = str(body.get("client_id") or self.client_id)
        self.scopes = set(body.get("scopes") or [])
        return True

    async def _refresh(self):
        if not (CLIENT_ID and CLIENT_SECRET and self.refresh):
            return False
        try:
            async with self.session.post(
                "https://id.twitch.tv/oauth2/token",
                data={
                    "client_id": CLIENT_ID,
                    "client_secret": CLIENT_SECRET,
                    "grant_type": "refresh_token",
                    "refresh_token": self.refresh,
                },
                timeout=aiohttp.ClientTimeout(total=15),
            ) as resp:
                if resp.status != 200:
                    logger.warning(f"Twitch token refresh failed for user {self.user_id}: HTTP {resp.status}")
                    return False
                body = await resp.json(content_type=None)
        except Exception as exc:
            logger.warning(f"Twitch token refresh failed for user {self.user_id}: {type(exc).__name__}")
            return False
        new_access = body.get("access_token")
        if not new_access:
            return False
        self.access = new_access
        self.refresh = body.get("refresh_token") or self.refresh
        async with self.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "UPDATE users SET access_token = %s, refresh_token = %s WHERE id = %s",
                    (self.access, self.refresh, self.user_id),
                )
        return await self._validate()

    async def ensure(self):
        if not await self.load():
            return False
        if await self._validate():
            return True
        return await self._refresh()

    async def helix(self, method, path, params=None, payload=None):
        for attempt in range(2):
            try:
                async with self.session.request(
                    method,
                    "https://api.twitch.tv/helix/" + path,
                    params=params,
                    json=payload,
                    headers={"Client-Id": self.client_id, "Authorization": f"Bearer {self.access}"},
                    timeout=aiohttp.ClientTimeout(total=20),
                ) as resp:
                    status = resp.status
                    text = await resp.text()
            except Exception as exc:
                return 0, type(exc).__name__
            if status == 401 and attempt == 0 and await self._refresh():
                continue
            return status, text
        return 401, "unauthorized"

    async def is_live(self):
        status, text = await self.helix("GET", "streams", params={"user_id": self.twitch_user_id})
        if status != 200:
            return None
        try:
            return bool((json.loads(text) or {}).get("data"))
        except ValueError:
            return None

    async def set_channel(self, title, game_id=None):
        # game_id None leaves the category alone; "" clears it
        payload = {"title": title}
        if game_id is not None:
            payload["game_id"] = game_id
        status, text = await self.helix(
            "PATCH",
            "channels",
            params={"broadcaster_id": self.twitch_user_id},
            payload=payload,
        )
        if status == 204:
            self.last_title = title
            return True, ""
        return False, f"twitch_channel_update_{status}"


# Staging (VODs that aren't on local disk)

def _disk_has_room(expected_bytes=0):
    try:
        free = shutil.disk_usage(VOD_ROOT).free
    except OSError:
        return False
    return free - int(expected_bytes or 0) >= MIN_DISK_FREE_BYTES


async def _download_user_s3(pool, user_id, source_key, part):
    from s3_vod_uploader import endpoint_ok, make_client

    row = await fetch_one(
        pool,
        """
        SELECT endpoint, region, bucket, prefix, access_key, secret_key, path_style
        FROM user_s3_settings WHERE user_id = %s LIMIT 1
        """,
        (user_id,),
    )
    if not row or not (row.get("access_key") or "") or not (row.get("bucket") or ""):
        raise RuntimeError("s3_not_connected")
    if not endpoint_ok(row.get("endpoint") or ""):
        raise RuntimeError("s3_endpoint_rejected")
    key = str(source_key or "")
    if not key or ".." in key.split("/") or not key.lower().endswith(".mp4"):
        raise RuntimeError("s3_bad_key")

    def fetch():
        client = make_client(row)
        head = client.head_object(Bucket=row["bucket"], Key=key)
        if not _disk_has_room(int(head.get("ContentLength") or 0)):
            raise RuntimeError("low_disk")
        client.download_file(row["bucket"], key, part)

    await asyncio.to_thread(fetch)


async def stage_item(pool, session, item, username):
    if not await set_item(pool, item["id"], only_status=("pending",), status="staging", error_message=None):
        return None
    folder = stage_dir(item["rerun_id"])
    os.makedirs(folder, exist_ok=True)
    dest = os.path.join(folder, f"{int(item['position']):03d}.mp4")
    part = dest + ".part"
    try:
        await _download_user_s3(pool, item["user_id"], item.get("source_key"), part)
        os.replace(part, dest)
    except asyncio.CancelledError:
        _discard_file(part)
        await set_item(pool, item["id"], status="pending")
        raise
    except Exception as exc:
        _discard_file(part)
        reason = str(exc) if isinstance(exc, RuntimeError) else f"copy_failed:{type(exc).__name__}"
        await set_item(pool, item["id"], status="failed", error_message=reason)
        logger.error(f"Could not copy {username}/{item['filename']} for rerun {item['rerun_id']}: {reason}")
        return None
    await set_item(pool, item["id"], status="staged", staged_path=dest)
    logger.info(f"Copied {username}/{item['filename']} for rerun {item['rerun_id']}")
    return dest


async def stage_pending(pool, session):
    item = await fetch_one(
        pool,
        """
        SELECT i.*, r.username
        FROM vod_rerun_items i
        JOIN vod_reruns r ON r.id = i.rerun_id
        WHERE i.status = 'pending' AND i.storage = 'user_s3'
          AND r.status = 'scheduled'
          AND r.scheduled_at <= UTC_TIMESTAMP() + INTERVAL %s SECOND
        ORDER BY r.scheduled_at ASC, i.position ASC
        LIMIT 1
        """,
        (STAGE_AHEAD_SECONDS,),
    )
    if not item:
        return False
    if not _safe_username(item["username"]) or not _safe_filename(item["filename"]):
        await set_item(pool, item["id"], status="failed", error_message="unsafe_path")
        return True
    await stage_item(pool, session, item, item["username"])
    return True


async def stage_pending_safe(pool, session):
    try:
        await stage_pending(pool, session)
    except Exception as exc:
        logger.error(f"Staging error: {exc!r}")


def cleanup_stage_dirs(active_ids):
    if not os.path.isdir(STAGE_ROOT):
        return
    for name in os.listdir(STAGE_ROOT):
        if not name.isdigit() or int(name) in active_ids:
            continue
        shutil.rmtree(os.path.join(STAGE_ROOT, name), ignore_errors=True)


# Playing

def ffmpeg_command(list_path, stream_key):
    return [
        "ffmpeg",
        "-hide_banner",
        "-nostdin",
        "-loglevel", "warning",
        "-re",
        "-f", "concat",
        "-safe", "0",
        "-i", list_path,
        "-map", "0:v:0?",
        "-map", "0:a:0?",
        "-c", "copy",
        "-f", "flv",
        "-flvflags", "no_duration_filesize",
        INGEST_URL + stream_key,
    ]


def group_list_path(rerun_id, first_position):
    return os.path.join(stage_dir(rerun_id), f"group-{int(first_position):03d}.txt")


def write_concat_list(list_path, paths):
    # One "file" line per VOD and nothing else: only plain absolute paths inside our own folders.
    allowed = (os.path.realpath(VOD_ROOT) + os.sep, os.path.realpath(STAGE_ROOT) + os.sep)
    for path in paths:
        if not os.path.isabs(path) or _has_control_chars(path) or not os.path.realpath(path).startswith(allowed):
            raise ValueError("unsafe path for concat list")
    os.makedirs(os.path.dirname(list_path), exist_ok=True)
    with open(list_path, "w", encoding="utf-8") as handle:
        for path in paths:
            handle.write("file '" + path.replace("'", "'\\''") + "'\n")


def probe_media(path):
    # Files with the same settings key can share one stream copy
    try:
        res = subprocess.run(
            [
                "ffprobe", "-v", "error",
                "-show_entries",
                "stream=codec_type,codec_name,profile,width,height,r_frame_rate,pix_fmt,sample_rate,channels:format=duration",
                "-of", "json", path,
            ],
            capture_output=True, timeout=120,
        )
        data = json.loads(res.stdout or b"{}")
    except Exception:
        return None, None
    video = next((st for st in data.get("streams") or [] if st.get("codec_type") == "video"), {})
    audio = next((st for st in data.get("streams") or [] if st.get("codec_type") == "audio"), {})
    try:
        duration = float((data.get("format") or {}).get("duration") or 0) or None
    except (TypeError, ValueError):
        duration = None
    if not video:
        return None, duration
    key = (
        video.get("codec_name"), video.get("profile"), video.get("width"), video.get("height"),
        video.get("r_frame_rate"), video.get("pix_fmt"),
        audio.get("codec_name"), audio.get("sample_rate"), audio.get("channels"),
    )
    return key, duration


async def resolve_item_path(pool, session, item, username):
    if item["storage"] == "local":
        path = os.path.join(VOD_ROOT, username, item["filename"])
        return path if os.path.isfile(path) else None
    if item["storage"] != "user_s3":
        return None
    # Wait out a copy the staging loop is already making, then make it here if it never started.
    for _ in range(6 * 3600 // WATCH_SECONDS):
        row = await fetch_one(pool, "SELECT status, staged_path FROM vod_rerun_items WHERE id = %s", (item["id"],))
        status = (row or {}).get("status")
        if status == "staged":
            path = (row or {}).get("staged_path") or ""
            return path if path.startswith(STAGE_ROOT + os.sep) and os.path.isfile(path) else None
        if status == "pending":
            path = await stage_item(pool, session, dict(item, status="pending"), username)
            if path:
                return path
            continue
        if status != "staging":
            return None
        if await cancel_requested(pool, item["rerun_id"]):
            return None
        await asyncio.sleep(WATCH_SECONDS)
    return None


async def stop_ffmpeg(proc):
    proc.send_signal(signal.SIGINT)
    for _ in range(20):
        await asyncio.sleep(0.5)
        if proc.poll() is not None:
            return proc.poll()
    proc.kill()
    return proc.poll()


async def run_group(pool, channel, rerun_id, group, proc, started_epoch, current, log_path, stream_key):
    # Returns ("done" | "cancelled" | "failed", index of the VOD reached)
    offsets = []
    total = 0.0
    for item in group:
        offsets.append(total)
        total += float(item["duration_seconds"] or 0)
    while True:
        rc = proc.poll()
        if rc is not None:
            break
        if await cancel_requested(pool, rerun_id):
            await stop_ffmpeg(proc)
            await set_item(pool, group[current]["id"], status="cancelled", finished_at=NOW)
            return "cancelled", current
        elapsed = time.time() - started_epoch
        while current + 1 < len(group) and elapsed >= offsets[current + 1]:
            await set_item(pool, group[current]["id"], status="done", finished_at=NOW)
            current += 1
            nxt = group[current]
            await set_item(pool, nxt["id"], status="playing", started_at=NOW, error_message=None)
            await set_rerun(pool, rerun_id, current_position=nxt["position"])
            ok, err = True, ""
            if rerun_title(nxt["title"]) != channel.last_title:
                ok, err = await channel.set_channel(rerun_title(nxt["title"]))
            if not ok:
                logger.warning(f"Rerun {rerun_id}: title update for item {nxt['position']} failed ({err}); still playing")
            logger.info(f"Rerun {rerun_id} now playing item {nxt['position']}")
        wait = WATCH_SECONDS
        if current + 1 < len(group):
            wait = max(0.5, min(WATCH_SECONDS, offsets[current + 1] - elapsed))
        await asyncio.sleep(wait)
    if rc == 0:
        for item in group[current:]:
            await set_item(pool, item["id"], status="done", finished_at=NOW)
        return "done", len(group) - 1
    reason = scrub(log_tail(log_path), stream_key) or f"ffmpeg_exit_{rc}"
    await set_item(pool, group[current]["id"], status="failed", error_message=reason, finished_at=NOW)
    logger.error(f"Rerun {rerun_id} item {group[current]['position']} ffmpeg exited {rc}: {reason}")
    return "failed", current


class _DetachedHandle:
    def __init__(self, pid):
        self.pid = pid

    def poll(self):
        rc = ffmpeg_returncode(self.pid)
        if rc is None and not pid_alive(self.pid):
            return 0
        return rc

    def send_signal(self, sig):
        if pid_alive(self.pid):
            os.kill(self.pid, sig)

    def kill(self):
        self.send_signal(signal.SIGKILL)


async def remove_expired_after_rerun(pool, rerun_id):
    rerun = await fetch_one(pool, "SELECT username FROM vod_reruns WHERE id = %s", (rerun_id,))
    username = (rerun or {}).get("username") or ""
    if not _safe_username(username):
        return
    items = await fetch_all(
        pool, "SELECT DISTINCT filename FROM vod_rerun_items WHERE rerun_id = %s AND storage = 'local'", (rerun_id,)
    )
    if not items:
        return
    # Uploads and other reruns still waiting on a file keep it.
    async with pool.acquire() as conn:
        try:
            held = await filenames_held_for_upload(conn)
        except Exception as exc:
            logger.warning(f"Rerun {rerun_id}: skipped expiry cleanup, could not read holds: {exc!r}")
            return
        async with conn.cursor() as cur:
            await cur.execute(
                """
                SELECT DISTINCT i.filename FROM vod_rerun_items i
                JOIN vod_reruns r ON r.id = i.rerun_id
                WHERE r.username = %s AND r.status IN ('scheduled', 'live') AND r.id != %s
                """,
                (username, rerun_id),
            )
            other_reruns = {str(row[0]) for row in await cur.fetchall() or []}
    cutoff = time.time() - RECORDING_RETENTION_SECONDS
    for item in items:
        name = item["filename"]
        if not _safe_filename(name) or (username, name) in held or name in other_reruns:
            continue
        path = os.path.join(VOD_ROOT, username, name)
        try:
            expired = os.path.isfile(path) and os.path.getmtime(path) < cutoff
        except OSError:
            expired = False
        if expired and not find_ffmpeg_pid_for_path(path):
            remove_media_and_sidecars(path)
            logger.info(f"Removed expired {username}/{name} after rerun {rerun_id}")


async def release_rerun(pool, rerun_id):
    shutil.rmtree(stage_dir(rerun_id), ignore_errors=True)
    try:
        await remove_expired_after_rerun(pool, rerun_id)
    except Exception as exc:
        logger.warning(f"Rerun {rerun_id}: expiry cleanup failed: {exc!r}")


async def finish_rerun(pool, rerun_id, status, error=None):
    await set_rerun(pool, rerun_id, status=status, error_message=error, finished_at=NOW, current_position=None)
    logger.info(f"Rerun {rerun_id} finished: {status}{(' (' + error + ')') if error else ''}")
    await release_rerun(pool, rerun_id)


async def release_cancelled(pool, released):
    # Dashboard cancels never reach this worker, so release them here
    rows = await fetch_all(
        pool,
        """
        SELECT id FROM vod_reruns
        WHERE status = 'cancelled' AND started_at IS NULL
          AND finished_at >= UTC_TIMESTAMP() - INTERVAL 10 MINUTE
        """,
    )
    for row in rows:
        rerun_id = int(row["id"])
        if rerun_id in released:
            continue
        released.add(rerun_id)
        logger.info(f"Rerun {rerun_id} was cancelled before it started")
        await release_rerun(pool, rerun_id)


async def cancel_remaining(pool, rerun_id):
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "UPDATE vod_rerun_items SET status = 'cancelled' WHERE rerun_id = %s AND status IN ('pending', 'staging', 'staged', 'playing')",
                (rerun_id,),
            )


async def run_rerun(pool, session, rerun_id, resume=False):
    rerun = await fetch_one(pool, "SELECT * FROM vod_reruns WHERE id = %s", (rerun_id,))
    if not rerun:
        return
    username = rerun["username"]
    if not _safe_username(username):
        await finish_rerun(pool, rerun_id, "failed", "unsafe_username")
        return
    stream_key = await stream_key_for(username)
    if not stream_key:
        await cancel_remaining(pool, rerun_id)
        await finish_rerun(pool, rerun_id, "failed", "no_stream_key")
        return
    channel = TwitchChannel(pool, session, rerun["user_id"])
    if not await channel.ensure():
        await cancel_remaining(pool, rerun_id)
        await finish_rerun(pool, rerun_id, "failed", "twitch_token_invalid")
        return
    if "channel:manage:broadcast" not in channel.scopes:
        await cancel_remaining(pool, rerun_id)
        await finish_rerun(pool, rerun_id, "failed", "twitch_missing_scope")
        return
    items = await fetch_all(pool, "SELECT * FROM vod_rerun_items WHERE rerun_id = %s ORDER BY position ASC", (rerun_id,))
    played = any(item["status"] == "done" for item in items)
    if not resume:
        live = await channel.is_live()
        if live is None:
            await cancel_remaining(pool, rerun_id)
            await finish_rerun(pool, rerun_id, "failed", "twitch_check_failed")
            return
        if live:
            await cancel_remaining(pool, rerun_id)
            await finish_rerun(pool, rerun_id, "skipped", "channel_live")
            return
        logger.info(f"▶️  Starting rerun {rerun_id} for {username} ({len(items)} VOD(s))")
    else:
        logger.info(f"↩️  Resuming rerun {rerun_id} for {username}")

    first_error = None
    title_set = played

    # A worker restart mid-rerun: pick the running push back up where it is.
    playing = next((item for item in items if item["status"] == "playing"), None)
    if playing is not None:
        pid = int(playing.get("ffmpeg_pid") or 0)
        group = [item for item in items if int(item.get("ffmpeg_pid") or 0) == pid and pid]
        list_path = group_list_path(rerun_id, group[0]["position"]) if group else ""
        if pid and pid_alive(pid) and list_path and cmdline_has(pid, list_path) and all(item["duration_seconds"] for item in group):
            logger.info(f"Reattached to ffmpeg {pid} for rerun {rerun_id}")
            started = group[0].get("started_at")
            started_epoch = started.replace(tzinfo=datetime.timezone.utc).timestamp() if started else time.time()
            current = group.index(playing)
            outcome, reached = await run_group(
                pool, channel, rerun_id, group, AttachedProcess(pid), started_epoch, current,
                os.path.join(stage_dir(rerun_id), f"group-{int(group[0]['position']):03d}.ffmpeg.log"), stream_key,
            )
            if outcome == "cancelled":
                await cancel_remaining(pool, rerun_id)
                await finish_rerun(pool, rerun_id, "cancelled")
                return
            played = played or reached > 0 or outcome == "done"
            title_set = True
        else:
            await set_item(pool, playing["id"], status="failed", error_message="interrupted", finished_at=NOW)
            first_error = "interrupted"
        items = await fetch_all(pool, "SELECT * FROM vod_rerun_items WHERE rerun_id = %s ORDER BY position ASC", (rerun_id,))

    # Every VOD still to play: find it on disk and read its settings and length.
    queue = []
    for item in items:
        if item["status"] in ("done", "failed", "skipped", "cancelled", "playing"):
            continue
        if await cancel_requested(pool, rerun_id):
            await cancel_remaining(pool, rerun_id)
            await finish_rerun(pool, rerun_id, "cancelled")
            return
        path = None
        if _safe_filename(item["filename"]):
            path = await resolve_item_path(pool, session, item, username)
        if not path:
            row = await fetch_one(pool, "SELECT status, error_message FROM vod_rerun_items WHERE id = %s", (item["id"],))
            reason = ((row or {}).get("error_message") or "missing_file") if (row or {}).get("status") == "failed" else "missing_file"
            await set_item(pool, item["id"], status="failed", error_message=reason, finished_at=NOW)
            first_error = first_error or reason
            logger.warning(f"Rerun {rerun_id} item {item['position']} unavailable: {reason}")
            continue
        key, duration = await asyncio.to_thread(probe_media, path)
        item = dict(item, duration_seconds=int(round(duration)) if duration else None)
        queue.append((item, path, key))
    # The category is chosen once for the whole rerun (the dashboard stores it on every VOD).
    game_id = (items[0].get("game_id") or "") if items else ""

    index = 0
    while index < len(queue):
        if await cancel_requested(pool, rerun_id):
            await cancel_remaining(pool, rerun_id)
            await finish_rerun(pool, rerun_id, "cancelled")
            return
        # Consecutive VODs with identical settings and known lengths share one continuous push.
        first_item, _, first_key = queue[index]
        end = index + 1
        if first_key is not None and first_item["duration_seconds"]:
            while end < len(queue) and queue[end][2] == first_key and queue[end][0]["duration_seconds"]:
                end += 1
        group = [entry[0] for entry in queue[index:end]]
        paths = [entry[1] for entry in queue[index:end]]
        await set_rerun(pool, rerun_id, current_position=first_item["position"])
        ok, err = True, ""
        if not title_set or rerun_title(first_item["title"]) != channel.last_title:
            ok, err = await channel.set_channel(rerun_title(first_item["title"]), None if title_set else game_id)
        if not ok and not title_set:
            # Never go live without the RERUN title on the channel.
            await set_item(pool, first_item["id"], status="failed", error_message=err, finished_at=NOW)
            await cancel_remaining(pool, rerun_id)
            await finish_rerun(pool, rerun_id, "failed", err)
            return
        if not ok:
            logger.warning(f"Rerun {rerun_id}: title update before item {first_item['position']} failed ({err}); still playing")
        title_set = True
        list_path = group_list_path(rerun_id, first_item["position"])
        log_path = os.path.join(stage_dir(rerun_id), f"group-{int(first_item['position']):03d}.ffmpeg.log")
        try:
            write_concat_list(list_path, paths)
        except ValueError:
            for item in group:
                await set_item(pool, item["id"], status="failed", error_message="unsafe_path", finished_at=NOW)
            first_error = first_error or "unsafe_path"
            index = end
            continue
        try:
            pid = spawn_detached_ffmpeg(ffmpeg_command(list_path, stream_key), log_path)
        except OSError as exc:
            await set_item(pool, first_item["id"], status="failed", error_message=f"ffmpeg_start:{type(exc).__name__}", finished_at=NOW)
            first_error = first_error or "ffmpeg_start"
            index += 1
            continue
        try:
            os.chmod(log_path, 0o600)
        except OSError:
            pass
        started_epoch = time.time()
        for item in group:
            await set_item(pool, item["id"], ffmpeg_pid=pid)
            await _update(pool, "vod_rerun_items", {"duration_seconds"}, item["id"], {"duration_seconds": item["duration_seconds"]})
        await set_item(pool, first_item["id"], status="playing", started_at=NOW, error_message=None)
        logger.info(
            f"📡 Rerun {rerun_id} live via ffmpeg {pid}: {len(group)} VOD(s) in one stream, from item {first_item['position']}"
        )
        outcome, reached = await run_group(pool, channel, rerun_id, group, _DetachedHandle(pid), started_epoch, 0, log_path, stream_key)
        if outcome == "cancelled":
            await cancel_remaining(pool, rerun_id)
            await finish_rerun(pool, rerun_id, "cancelled")
            return
        if outcome == "done":
            played = True
            index = end
            continue
        # The push died part-way: that VOD failed; carry on from the next one in a fresh push.
        played = played or reached > 0
        first_error = first_error or "stream_failed"
        index = index + reached + 1

    if played:
        await finish_rerun(pool, rerun_id, "done")
    else:
        await finish_rerun(pool, rerun_id, "failed", first_error or "nothing_played")

async def start_due(pool, session, running):
    rows = await fetch_all(
        pool,
        """
        SELECT id, user_id, username,
               TIMESTAMPDIFF(SECOND, scheduled_at, UTC_TIMESTAMP()) AS late_seconds
        FROM vod_reruns
        WHERE status = 'scheduled' AND scheduled_at <= UTC_TIMESTAMP()
        ORDER BY scheduled_at ASC
        """,
    )
    for row in rows:
        rerun_id = int(row["id"])
        if rerun_id in running:
            continue
        if int(row.get("late_seconds") or 0) > MISSED_GRACE_SECONDS:
            if await set_rerun(pool, rerun_id, only_status=("scheduled",), status="failed", error_message="missed_start", finished_at=NOW):
                await cancel_remaining(pool, rerun_id)
                logger.warning(f"Rerun {rerun_id} for {row['username']} missed its start time")
                await release_rerun(pool, rerun_id)
            continue
        busy = await fetch_one(
            pool,
            "SELECT id FROM vod_reruns WHERE user_id = %s AND status = 'live' AND id != %s LIMIT 1",
            (row["user_id"], rerun_id),
        )
        if busy:
            if await set_rerun(pool, rerun_id, only_status=("scheduled",), status="skipped", error_message="another_rerun_live", finished_at=NOW):
                await cancel_remaining(pool, rerun_id)
                await release_rerun(pool, rerun_id)
            continue
        if not await set_rerun(pool, rerun_id, only_status=("scheduled",), status="live", started_at=NOW, error_message=None):
            continue
        running[rerun_id] = asyncio.create_task(run_rerun(pool, session, rerun_id))


async def main():
    lock_fh = open(LOCK_PATH, "a+")
    if fcntl is not None:
        try:
            fcntl.flock(lock_fh.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            logger.info("⏭️  Another rerun scheduler is already running")
            lock_fh.close()
            return
    if not DB_HOST or not DB_USER or not DB_PASS:
        logger.error("❌ Missing SQL_HOST / SQL_USER / SQL_PASSWORD")
        return
    logger.info(f"Rerun scheduler started. Stream files root: {VOD_ROOT}")
    pool = await aiomysql.create_pool(
        host=DB_HOST, user=DB_USER, password=DB_PASS, db=DB_NAME, autocommit=True, minsize=1, maxsize=6,
    )
    running = {}
    released = set()
    staging_task = None
    try:
        timeout = aiohttp.ClientTimeout(total=None, sock_connect=30, sock_read=300)
        async with aiohttp.ClientSession(timeout=timeout) as session:
            # Copies cut short by a restart start over; reruns that were live carry on.
            async with pool.acquire() as conn:
                async with conn.cursor() as cur:
                    await cur.execute("UPDATE vod_rerun_items SET status = 'pending' WHERE status = 'staging'")
            for row in await fetch_all(pool, "SELECT id FROM vod_reruns WHERE status = 'live'"):
                running[int(row["id"])] = asyncio.create_task(run_rerun(pool, session, int(row["id"]), resume=True))
            ticks = 0
            while True:
                for rerun_id, task in list(running.items()):
                    if task.done():
                        running.pop(rerun_id, None)
                        if not task.cancelled() and task.exception():
                            logger.error(f"Rerun {rerun_id} crashed: {task.exception()!r}")
                            if await set_rerun(pool, rerun_id, only_status=("live",), status="failed", error_message="worker_error", finished_at=NOW):
                                await release_rerun(pool, rerun_id)
                try:
                    await start_due(pool, session, running)
                    await release_cancelled(pool, released)
                    if staging_task is None or staging_task.done():
                        staging_task = asyncio.create_task(stage_pending_safe(pool, session))
                    if ticks % 6 == 0:
                        active = await fetch_all(pool, "SELECT id FROM vod_reruns WHERE status IN ('scheduled', 'live')")
                        cleanup_stage_dirs({int(r["id"]) for r in active})
                except Exception as exc:
                    logger.error(f"Scheduler loop error: {exc!r}")
                ticks += 1
                await asyncio.sleep(POLL_SECONDS)
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
