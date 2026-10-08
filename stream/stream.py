import os
import re
import sys
import socket
import secrets
import datetime
import logging
import ssl
import asyncio
import argparse
import time
from asyncio import subprocess
from functools import wraps
from urllib.parse import quote
import aiomysql
from dotenv import load_dotenv
from pyrtmp import StreamClosedException
from pyrtmp.flv import FLVFileWriter, FLVMediaType
from pyrtmp.session_manager import SessionManager
from pyrtmp.rtmp import SimpleRTMPController, RTMPProtocol, SimpleRTMPServer
from quart import Quart, render_template_string, request, jsonify, redirect, session, send_file, send_from_directory
from ffmpeg_jobs import (
    download_mp4_name,
    ffmpeg_is_writing_path,
    find_ffmpeg_pid_for_path,
    live_ffmpeg_cmdlines,
    read_media_meta,
    remove_media_and_sidecars,
    update_media_meta,
)
from twitch_vod_downloader import (
    PULL_LIVE_SECONDS,
    cache_duration,
    probe_duration_seconds,
    pull_state as twitch_pull_state,
    pulls_for_user as twitch_pulls_for_user,
    request_pull as request_twitch_pull,
)

# Patch SessionManager.peername to avoid unpacking None
def safe_peername(self):
    info = self.writer.get_extra_info("peername")
    if not info or not isinstance(info, tuple) or len(info) != 2:
        return ("unknown", 0)
    return info

SessionManager.peername = property(safe_peername)

# Define Twitch ingest servers
TWITCH_INGEST_SERVERS = {
    "sydney": "rtmps://syd03.contribute.live-video.net/app/",
    "us-west": "rtmps://sea02.contribute.live-video.net/app/",
    "us-east": "rtmps://atl.contribute.live-video.net/app/",
    "eu-central": "rtmps://fra05.contribute.live-video.net/app/",
    # Add more regions as needed
}

# Define SSL domain mappings for Let's Encrypt certificates
SSL_DOMAIN_MAPPING = {
    "sydney": "syd1.stream.botofthespecter.com",
    "us-west": "usw1.stream.botofthespecter.com",
    "us-east": "use1.stream.botofthespecter.com",
    "eu-central": "euc1.stream.botofthespecter.com",
}

DEFAULT_INGEST_SERVER = "sydney"

# Display titles for the operator web UI (one UI per server / region)
SERVER_DISPLAY_NAMES = {
    "sydney": "RTMP Server - Sydney, Australia (syd1.stream)",
    "us-east": "RTMP Server - Ashburn, Virginia, USA (us-east-1)",
    "us-west": "RTMP Server - Hillsboro, Oregon, USA (us-west-1)",
    "eu-central": "RTMP Server - Nuremberg, Germany (eu-central-1)",
}

DEFAULT_WEB_HOST = "0.0.0.0"
DEFAULT_WEB_PORT = 80
DEFAULT_HTTPS_PORT = 443
# Where twitch-recorder.py writes its per-user recordings (matches its STREAM_ROOT_PATH default)
DEFAULT_RECORDER_STORAGE_PATH = os.getenv('STREAM_ROOT_PATH', '/mnt/blockstorage')

# SSO: home/sso.php mints a handoff token; we verify it on /sso/login.
# Sydney lives on .botofthespecter.com; other regions still use .video.
SSO_AUTHORITY_URL = "https://botofthespecter.com/sso.php"
SSO_TARGET_BY_REGION = {
    "sydney":     "rtmp-sydney",
    "us-east":    "rtmp-us-east",
    "us-west":    "rtmp-us-west",
    "eu-central": "rtmp-eu-central",
}
WEB_SESSION_COOKIE_NAME = "bots_video_session"
WEB_SESSION_COOKIE_DOMAIN_BY_REGION = {
    "sydney": None,
    "us-east": None,
    "us-west": None,
    "eu-central": None,
}
WEB_SESSION_COOKIE_DOMAIN = WEB_SESSION_COOKIE_DOMAIN_BY_REGION.get(
    os.getenv("STREAM_SERVER") or "sydney"
)
WEB_SESSION_LIFETIME_SECONDS = 14400  # 4h, matches the .com side
# Signed-cookie key. Set the SAME value across every regional .env so a single
# login cookie can be reused where the cookie domain matches.
# Falls back to an ephemeral key if missing - sessions then survive only until
# this process restarts.
WEB_SECRET_KEY = os.getenv('WEB_SECRET_KEY')

# Parse command line arguments
def parse_args():
    parser = argparse.ArgumentParser(description='RTMP Server with Twitch forwarding')
    parser.add_argument('-server', type=str, default=DEFAULT_INGEST_SERVER,
                       help='Twitch ingest server location (sydney, us-west, us-east, eu-central)')
    parser.add_argument('--web-host', type=str, default=DEFAULT_WEB_HOST,
                       help='Bind address for the operator web UI')
    parser.add_argument('--web-port', type=int, default=DEFAULT_WEB_PORT,
                       help='Port for HTTP redirect to HTTPS (default: 80)')
    parser.add_argument('--https-port', type=int, default=DEFAULT_HTTPS_PORT,
                       help='Port for HTTPS operator web UI (default: 443)')
    parser.add_argument('--recorder-path', type=str, default=DEFAULT_RECORDER_STORAGE_PATH,
                       help='Filesystem root where twitch-recorder.py stores per-user recordings')
    return parser.parse_args()

# Load environment variables
load_dotenv()

# Parse and validate command line arguments
args = parse_args()
if args.server in TWITCH_INGEST_SERVERS:
    server_location = args.server
    _server_warning = None
else:
    _server_warning = f"Invalid server location: {args.server}. Using default: {DEFAULT_INGEST_SERVER}"
    server_location = DEFAULT_INGEST_SERVER

log_dir = "/home/botofthespecter/logs"
log_file = os.path.join(log_dir, f"{server_location}.txt")

os.makedirs(log_dir, exist_ok=True)
if not os.path.exists(log_file):
    with open(log_file, 'w') as f:
        pass

formatter = logging.Formatter('%(asctime)s - %(levelname)s - %(message)s', datefmt='%Y-%m-%d %H:%M:%S')
file_handler = logging.FileHandler(log_file)
file_handler.setFormatter(formatter)
logging.basicConfig(level=logging.INFO, handlers=[file_handler])
logger = logging.getLogger()
# Hypercorn/hpack DEBUG logs request headers, including X-API-KEY.
logging.getLogger("hpack").setLevel(logging.WARNING)
logging.getLogger("hypercorn").setLevel(logging.INFO)

if _server_warning:
    logger.warning(_server_warning)

# RTMP(S) Server Settings
RTMPS_PORT = 1935
RTMPS_HOST = "0.0.0.0"
SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER')
SQL_PASSWORD = os.getenv('SQL_PASSWORD')
ADMIN_KEY_SERVICE = "rtmp-server"
SERVER_START_TIME = datetime.datetime.now()
FFMPEG_VERSION: str = "unknown"
STREAM_STORAGE_MAX_SLOTS = int(os.getenv("STREAM_STORAGE_MAX_SLOTS") or "5")
STREAM_STORAGE_QUOTA_BYTES = int(os.getenv("STREAM_STORAGE_QUOTA_BYTES") or str(100 * 1024 * 1024 * 1024))
VODS_CDN_BASE = (os.getenv("VODS_CDN_BASE") or "https://vods.botofthespecter.com").rstrip("/")
DASHBOARD_HOME_URL = "https://dashboard.botofthespecter.com"
FAVICON_URL = "https://cdn.botofthespecter.com/favicon.ico"
LOGO_URL = "https://cdn.botofthespecter.com/logo.png"
RECORDING_RETENTION_SECONDS = int(os.getenv("RECORDING_RETENTION_SECONDS") or "86400")
# An expired extended (S4) VOD is only kept for a rerun that starts within this many days of its expiry, so rescheduling a rerun can't keep it in S4 forever.
RERUN_S4_HOLD_DAYS = 7
DURATION_BACKFILL_PER_REQUEST = 3
DURATION_PROBE_RETRY_SECONDS = 600
_duration_probes: set[str] = set()


def _duration_probe_due(meta: dict) -> bool:
    stamped = meta.get("duration_unavailable_at")
    if not isinstance(stamped, (int, float)):
        return True
    return time.time() - float(stamped) >= DURATION_PROBE_RETRY_SECONDS


async def _run_duration_probe(path: str) -> None:
    try:
        await asyncio.to_thread(cache_duration, path)
    finally:
        _duration_probes.discard(path)


def _schedule_duration_probe(path: str, meta: dict) -> bool:
    # One probe per file. The library response does not wait for it.
    if path in _duration_probes or not _duration_probe_due(meta):
        return False
    _duration_probes.add(path)
    asyncio.create_task(_run_duration_probe(path))
    return True


# In-progress copies from a user's S3 bucket back onto this server. Keyed by username + filename.
_s3_restores: dict[str, dict] = {}
_s3_restore_tasks: dict[str, asyncio.Task] = {}
STREAM_DIR = os.path.dirname(os.path.abspath(__file__))
DOCS_UI_DIR = os.path.join(STREAM_DIR, "docs_ui")
_PULL_RESUME_MAX_AGE_SECONDS = 24 * 3600


def _vod_title_from_sidecar(user_dir: str, vod_id: str) -> str:
    meta = read_media_meta(os.path.join(user_dir, f"twitch-{vod_id}.mp4"))
    return str(meta.get("title") or "").strip()


def _write_vod_title_sidecar(user_dir: str, vod_id: str, title: str) -> None:
    title = (title or "").strip()
    if not user_dir or not vod_id or not title:
        return
    update_media_meta(os.path.join(user_dir, f"twitch-{vod_id}.mp4"), vod_id=vod_id, title=title)


async def _request_twitch_pull(user_id: int, username: str, vod_id: str, title: str, dest: str) -> str:
    """Start (or join) the shared pull for this VOD. Returns stored / pulling / started / failed."""
    sqldb = None
    try:
        sqldb = await access_website_database()
        state = await twitch_pull_state(sqldb, user_id, vod_id)
        live = bool(state and state["live"])
        if not live and os.path.isfile(dest) and not os.path.isdir(dest + ".part.d"):
            return "stored"
        _write_vod_title_sidecar(os.path.dirname(dest), vod_id, title)
        result = await request_twitch_pull(sqldb, user_id, username, vod_id, title, dest)
        logger.info(f"Twitch VOD {vod_id} pull for {username}: {result}")
        return result
    except Exception as e:
        logger.error(f"Twitch VOD {vod_id} pull request failed for {username}: {e}")
        return "failed"
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()


async def _twitch_pulls_for_listing(username: str) -> list:
    user_id = await lookup_user_id(username)
    if not user_id:
        return []
    sqldb = None
    try:
        sqldb = await access_website_database()
        rows = await twitch_pulls_for_user(sqldb, user_id)
    except Exception as e:
        logger.warning(f"Could not load Twitch VOD pulls for {username}: {e}")
        return []
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()
    out = []
    for row in rows:
        vod_id = str(row.get("twitch_video_id") or "")
        filename = str(row.get("filename") or f"twitch-{vod_id}.mp4")
        status = str(row.get("status") or "")
        if status in ("queued", "pulling"):
            # A worker that stopped heartbeating died; let the user retry.
            status = "pulling" if row.get("live") else "failed"
        out.append({
            "vod_id": vod_id,
            "filename": filename,
            "title": row.get("title") or "",
            "status": status,
            "percent": float(row["progress_percent"]) if row.get("progress_percent") is not None else None,
            "bytes": int(row.get("bytes_sent") or 0),
            "bytes_total": int(row.get("bytes_total") or 0),
            "error": row.get("error_message") if status == "failed" else None,
        })
    return out


async def _resume_twitch_pulls(root: str) -> None:
    """After a reboot, restart workers for pulls on this host that stopped heartbeating."""
    await asyncio.sleep(5)
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute(
                """
                UPDATE twitch_vod_pulls
                SET status = 'failed', error_message = 'interrupted'
                WHERE status IN ('queued', 'pulling') AND owner_host = %s
                  AND updated_at < NOW() - INTERVAL %s SECOND
                """,
                (socket.gethostname(), _PULL_RESUME_MAX_AGE_SECONDS),
            )
            await cursor.execute(
                """
                SELECT user_id, twitch_video_id, username, filename, title
                FROM twitch_vod_pulls
                WHERE status IN ('queued', 'pulling') AND owner_host = %s
                  AND updated_at < NOW() - INTERVAL %s SECOND
                """,
                (socket.gethostname(), PULL_LIVE_SECONDS),
            )
            rows = await cursor.fetchall() or []
        await sqldb.commit()
        for row in rows:
            username = str(row.get("username") or "")
            vod_id = str(row.get("twitch_video_id") or "")
            if not re.match(r"^[a-zA-Z0-9_]{1,64}$", username) or not re.match(r"^[0-9]{1,20}$", vod_id):
                continue
            dest = os.path.join(root, username, f"twitch-{vod_id}.mp4")
            result = await request_twitch_pull(sqldb, int(row["user_id"]), username, vod_id, row.get("title") or "", dest)
            logger.info(f"Resumed Twitch VOD pull {vod_id} for {username}: {result}")
    except Exception as e:
        logger.error(f"Twitch VOD pull resume failed: {e}")
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()


async def access_website_database():
    # Connect to your MySQL database
    return await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db="website",
    )

async def userdb_connect(username):
    # Connect to the user's database
    return await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db=username,
    )

async def get_username_from_api_key(api_key):
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute("SELECT username FROM users WHERE api_key = %s", (api_key,))
            row = await cursor.fetchone()
            return row['username'] if row else None
    except Exception as e:
        logger.error(f"Error in get_username_from_api_key: {e}")
        return None
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()

async def validate_api_key(api_key):
    return await get_username_from_api_key(api_key)

async def maybe_queue_youtube_vod(username, filename):
    # If the streamer opted in, queue this finished MP4 for YouTube upload.
    if not username or not filename:
        return
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute(
                """
                SELECT u.id AS user_id, t.auto_upload, t.can_upload, t.privacy_status,
                       t.refresh_token, t.needs_reauth
                FROM users u
                JOIN youtube_tokens t ON t.user_id = u.id
                WHERE u.username = %s AND u.is_admin = 1
                LIMIT 1
                """,
                (username,),
            )
            row = await cursor.fetchone()
            if not row or not row.get("auto_upload") or not row.get("can_upload"):
                return
            if row.get("needs_reauth") or not (row.get("refresh_token") or "").strip():
                return
            await cursor.execute(
                """
                SELECT id FROM youtube_vod_uploads
                WHERE user_id = %s AND filename = %s AND status IN ('queued','pulling','uploading','done')
                ORDER BY id DESC LIMIT 1
                """,
                (row["user_id"], filename),
            )
            if await cursor.fetchone():
                return
            title = os.path.splitext(filename)[0].replace("_", " ").replace("-", " ").strip()[:100]
            privacy = row.get("privacy_status") or "private"
            await cursor.execute(
                """
                INSERT INTO youtube_vod_uploads (user_id, filename, title, privacy_status, status)
                VALUES (%s, %s, %s, %s, 'queued')
                """,
                (row["user_id"], filename, title, privacy),
            )
            await sqldb.commit()
            logger.info(f"Queued YouTube VOD upload for {username}: {filename}")
    except Exception as e:
        logger.error(f"YouTube auto-queue failed for {username}: {e}")
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()


async def maybe_queue_s3_vod(username, filename):
    if not username or not filename:
        return
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute(
                """
                SELECT u.id AS user_id, s.auto_copy
                FROM users u
                JOIN user_s3_settings s ON s.user_id = u.id
                WHERE u.username = %s
                  AND s.endpoint IS NOT NULL AND s.endpoint != ''
                  AND s.bucket IS NOT NULL AND s.bucket != ''
                  AND s.access_key IS NOT NULL AND s.access_key != ''
                  AND s.secret_key IS NOT NULL AND s.secret_key != ''
                LIMIT 1
                """,
                (username,),
            )
            row = await cursor.fetchone()
            if not row or not row.get("auto_copy"):
                return
            await cursor.execute(
                """
                SELECT id FROM user_s3_uploads
                WHERE user_id = %s AND filename = %s AND status IN ('queued','pulling','uploading','done')
                ORDER BY id DESC LIMIT 1
                """,
                (row["user_id"], filename),
            )
            if await cursor.fetchone():
                return
            title = os.path.splitext(filename)[0].replace("_", " ").replace("-", " ").strip()[:100]
            await cursor.execute(
                """
                INSERT INTO user_s3_uploads (user_id, filename, title, status)
                VALUES (%s, %s, %s, 'queued')
                """,
                (row["user_id"], filename, title),
            )
            await sqldb.commit()
            logger.info(f"Queued S3 VOD copy for {username}: {filename}")
    except Exception as e:
        logger.error(f"S3 auto-queue failed for {username}: {e}")
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()


def user_storage_dir(output_directory: str, username: str) -> str:
    return os.path.join(output_directory, username)


def directory_size_bytes(path: str) -> int:
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


async def get_storage_slot(username):
    """Return quota info for an opted-in ingest user, or None if they have no slot."""
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute(
                """
                SELECT u.id AS user_id, s.quota_bytes, s.bonus_bytes
                FROM users u
                JOIN stream_storage_slots s ON s.user_id = u.id
                WHERE u.username = %s
                LIMIT 1
                """,
                (username,),
            )
            row = await cursor.fetchone()
            if not row:
                return None
            raw_quota = row.get("quota_bytes")
            quota = STREAM_STORAGE_QUOTA_BYTES if raw_quota is None else int(raw_quota)
            bonus = int(row.get("bonus_bytes") or 0)
            return {
                "user_id": row["user_id"],
                "quota_bytes": 0 if quota == 0 else quota + bonus,
            }
    except Exception as e:
        logger.error(f"Error in get_storage_slot for {username}: {e}")
        return None
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()


async def get_streaming_settings(username):
    userdb = None
    try:
        userdb = await userdb_connect(username)
        async with userdb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute("SELECT twitch_key, forward_to_twitch FROM streaming_settings WHERE id = 1")
            row = await cursor.fetchone()
            if row:
                return row['twitch_key'], row['forward_to_twitch']
            return None, False
    except Exception as e:
        logger.error(f"Error in get_streaming_settings for {username}: {e}")
        return None, False
    finally:
        if userdb is not None:
            await userdb.ensure_closed()

class SessionRegistry:
    def __init__(self):
        self._sessions: dict[int, dict] = {}

    def register_connection(self, session_id: int, peer: str, disconnect_callback=None) -> None:
        self._sessions[session_id] = {
            "id": session_id,
            "peer": peer,
            "connected_at": datetime.datetime.now(),
            "publishing_name": None,
            "username": None,
            "flv_file_path": None,
            "publish_started_at": None,
            "forwarding": None,
            "_disconnect_callback": disconnect_callback,
        }

    def get(self, session_id: int) -> dict | None:
        return self._sessions.get(session_id)

    def force_disconnect(self, session_id: int) -> bool:
        s = self._sessions.get(session_id)
        if s is None:
            return False
        cb = s.get("_disconnect_callback")
        if cb is None:
            return False
        try:
            cb()
        except Exception as e:
            logger.warning(f"force_disconnect callback raised: {e}")
        return True

    def attach_publish(self, session_id: int, *, publishing_name: str, username: str, flv_file_path: str) -> None:
        s = self._sessions.get(session_id)
        if s is None:
            return
        s["publishing_name"] = publishing_name
        s["username"] = username
        s["flv_file_path"] = flv_file_path
        s["publish_started_at"] = datetime.datetime.now()

    def attach_forwarding(self, session_id: int, *, target_url: str, ffmpeg_pid: int) -> None:
        s = self._sessions.get(session_id)
        if s is None:
            return
        # Mask the stream key portion of the Twitch ingest URL
        masked = target_url
        if "/app/" in target_url:
            head, _, _ = target_url.partition("/app/")
            masked = f"{head}/app/****"
        s["forwarding"] = {
            "target_url": masked,
            "ffmpeg_pid": ffmpeg_pid,
            "started_at": datetime.datetime.now(),
        }

    def deregister(self, session_id: int) -> None:
        self._sessions.pop(session_id, None)

    def snapshot(self) -> list[dict]:
        return list(self._sessions.values())

class _StreamPipeSink:
    def __init__(self, real_file, ffmpeg_stdin):
        self._real = real_file
        self._stdin = ffmpeg_stdin
        self._broken = False

    def write(self, data):
        result = self._real.write(data)
        if not self._broken and self._stdin is not None and not self._stdin.is_closing():
            try:
                self._stdin.write(data)
            except Exception as e:
                logger.warning(f"FFmpeg stdin write failed; tee disabled: {e}")
                self._broken = True
        return result

    def flush(self):
        try:
            self._real.flush()
        except Exception:
            pass

    def close(self):
        try:
            self._real.close()
        finally:
            if self._stdin is not None and not self._stdin.is_closing():
                try:
                    self._stdin.close()
                except Exception:
                    pass

    def __getattr__(self, name):
        return getattr(self._real, name)

class TeeFLVFileWriter(FLVFileWriter):
    def __init__(self, output, sink_stdin):
        super().__init__(output=output)
        try:
            self.buffer.flush()
        except Exception:
            pass
        try:
            with open(output, "rb") as fh:
                header_bytes = fh.read()
            if header_bytes and sink_stdin is not None and not sink_stdin.is_closing():
                sink_stdin.write(header_bytes)
        except Exception as e:
            logger.warning(f"Could not replay FLV header to ffmpeg: {e}")
        self.buffer = _StreamPipeSink(self.buffer, sink_stdin)

class RTMP2FLVController(SimpleRTMPController):
    def __init__(self, output_directory: str, twitch_server: str, session_registry: SessionRegistry):
        self.output_directory = output_directory
        self.twitch_server = twitch_server
        self.session_registry = session_registry
        super().__init__()

    async def on_connect(self, session, message):
        # Record the connection start time
        session.connection_start_time = datetime.datetime.now()
        session.closed = False
        # Register with the operator web UI registry, with a callback the API can use to kick the session
        try:
            host, port = session.peername
            peer_str = f"{host}:{port}"
        except Exception:
            peer_str = "unknown"
        def _force_close():
            try:
                if session.writer is not None and not session.writer.is_closing():
                    session.writer.close()
            except Exception:
                pass
        self.session_registry.register_connection(id(session), peer_str, disconnect_callback=_force_close)
        # Schedule monitoring to disconnect after 48 hours; store so we can cancel on stream close
        session.duration_monitor_task = asyncio.create_task(self.monitor_connection_duration(session))
        await super().on_connect(session, message)

    async def monitor_connection_duration(self, session):
        max_duration = 48 * 3600  # 48 hours in seconds
        try:
            await asyncio.sleep(max_duration)
        except asyncio.CancelledError:
            return
        if session.writer and not session.writer.is_closing():
            logger.info(f"Disconnecting session {getattr(session, 'publishing_name', 'unknown')} after 48 hours.")
            session.writer.close()
            try:
                await session.writer.wait_closed()
            except Exception as e:
                logger.warning(f"Error during disconnection: {e}")

    async def on_ns_publish(self, session, message) -> None:
        # Validate API Key
        publishing_name = message.publishing_name
        username = await validate_api_key(publishing_name)
        if not username:
            logger.warning(f"Unauthorized API key: {publishing_name}")
            session.writer.close()
            try:
                await session.writer.wait_closed()
            except ssl.SSLError as e:
                logger.warning(f"Ignored SSL error after close notify: {e}")
            return
        slot = await get_storage_slot(username)
        if not slot:
            logger.warning(
                f"Refusing {username}: no stream storage slot "
                f"(max {STREAM_STORAGE_MAX_SLOTS} users due to limited storage space)"
            )
            session.writer.close()
            try:
                await session.writer.wait_closed()
            except ssl.SSLError as e:
                logger.warning(f"Ignored SSL error after close notify: {e}")
            return
        user_dir = user_storage_dir(self.output_directory, username)
        os.makedirs(user_dir, exist_ok=True)
        used = directory_size_bytes(user_dir)
        quota = slot["quota_bytes"]
        if quota > 0 and used >= quota:
            logger.warning(
                f"Refusing {username}: stream storage full "
                f"({used} bytes used of {quota} byte quota)"
            )
            session.writer.close()
            try:
                await session.writer.wait_closed()
            except ssl.SSLError as e:
                logger.warning(f"Ignored SSL error after close notify: {e}")
            return
        # Fetch streaming settings
        twitch_key, forward_to_twitch = await get_streaming_settings(username)
        session.publishing_name = publishing_name
        start_date = datetime.datetime.now().strftime("%d-%m-%Y_%H-%M-%S")
        file_path = os.path.join(user_dir, f"{start_date}.flv")
        session.flv_file_path = file_path
        session.twitch_key = twitch_key
        session.ffmpeg_process = None
        self.session_registry.attach_publish(
            id(session), publishing_name=publishing_name, username=username, flv_file_path=file_path
        )
        # Set up FLV recording, optionally tee'd to ffmpeg for live Twitch forwarding
        if forward_to_twitch and twitch_key:
            twitch_server_url = TWITCH_INGEST_SERVERS.get(self.twitch_server, TWITCH_INGEST_SERVERS[DEFAULT_INGEST_SERVER])
            twitch_url = f"{twitch_server_url}{twitch_key}"
            logger.info(f"Using Twitch ingest server: {self.twitch_server} ({twitch_server_url})")
            await self._start_twitch_forwarding(session, file_path, twitch_url)
        else:
            if forward_to_twitch and not twitch_key:
                logger.warning(f"forward_to_twitch enabled for {username} but twitch_key is missing; recording only.")
            session.state = FLVFileWriter(output=file_path)
        logger.info(f"Started recording stream {publishing_name} to {file_path}")
        await super().on_ns_publish(session, message)

    async def _spawn_ffmpeg_forwarder(self, twitch_url):
        command = [
            "ffmpeg",
            "-hide_banner",
            "-loglevel", "warning",
            "-f", "flv",
            "-i", "pipe:0",
            "-c", "copy",
            "-f", "flv",
            twitch_url,
        ]
        return await asyncio.create_subprocess_exec(
            *command,
            stdin=subprocess.PIPE,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
        )

    async def _start_twitch_forwarding(self, session, file_path, twitch_url):
        proc = None
        try:
            proc = await self._spawn_ffmpeg_forwarder(twitch_url)
            writer = TeeFLVFileWriter(output=file_path, sink_stdin=proc.stdin)
        except Exception as e:
            logger.error(f"Failed to start Twitch forwarding: {e}", exc_info=True)
            if proc is not None:
                try:
                    proc.kill()
                    await proc.wait()
                except Exception:
                    pass
            session.state = FLVFileWriter(output=file_path)
            return
        session.state = writer
        session.ffmpeg_process = proc
        session.twitch_forward_start_time = datetime.datetime.now()
        session.last_health_check = time.time()
        session.drain_task = asyncio.create_task(self._drain_ffmpeg_stdin(session))
        session.health_monitor_task = asyncio.create_task(self.monitor_ffmpeg_health(session))
        session.ffmpeg_watcher_task = asyncio.create_task(self._watch_ffmpeg(session))
        self.session_registry.attach_forwarding(id(session), target_url=twitch_url, ffmpeg_pid=proc.pid)
        logger.info(f"Forwarding stream to Twitch via ffmpeg PID {proc.pid}: {twitch_url}")

    async def _drain_ffmpeg_stdin(self, session):
        proc = session.ffmpeg_process
        if proc is None or proc.stdin is None:
            return
        try:
            while not proc.stdin.is_closing() and proc.returncode is None:
                await proc.stdin.drain()
                await asyncio.sleep(0.1)
        except (BrokenPipeError, ConnectionResetError):
            return
        except asyncio.CancelledError:
            return
        except Exception as e:
            logger.warning(f"Drainer for ffmpeg stdin exited: {e}")

    async def _read_ffmpeg_stderr(self, session):
        proc = session.ffmpeg_process
        if proc is None or proc.stderr is None:
            return
        try:
            async for line in proc.stderr:
                decoded = line.decode(errors="replace").rstrip()
                if decoded:
                    logger.info(f"[ffmpeg] {decoded}")
        except asyncio.CancelledError:
            return
        except Exception:
            return

    async def _watch_ffmpeg(self, session):
        proc = session.ffmpeg_process
        if proc is None:
            return
        stderr_task = asyncio.create_task(self._read_ffmpeg_stderr(session))
        try:
            rc = await proc.wait()
        except asyncio.CancelledError:
            stderr_task.cancel()
            return
        try:
            await asyncio.wait_for(stderr_task, timeout=2.0)
        except (asyncio.TimeoutError, asyncio.CancelledError):
            stderr_task.cancel()
        if hasattr(session, "twitch_forward_start_time"):
            duration = datetime.datetime.now() - session.twitch_forward_start_time
            hours, remainder = divmod(duration.seconds, 3600)
            minutes, seconds = divmod(remainder, 60)
            tail = f" after {hours}h {minutes}m {seconds}s"
        else:
            tail = ""
        if rc == 0:
            logger.info(f"FFmpeg exited cleanly{tail}.")
        else:
            logger.error(f"FFmpeg exited with code {rc}{tail}; Twitch forwarding stopped.")

    async def monitor_ffmpeg_health(self, session):
        try:
            check_interval = 300  # Check every 5 minutes
            while True:
                await asyncio.sleep(check_interval)
                current_time = time.time()
                if not hasattr(session, 'ffmpeg_process') or session.ffmpeg_process is None:
                    logger.error("FFmpeg process reference lost, monitoring stopping")
                    return
                if session.ffmpeg_process.returncode is not None:
                    logger.error(f"FFmpeg process terminated unexpectedly with code {session.ffmpeg_process.returncode}")
                    return
                # Calculate uptime
                uptime = datetime.datetime.now() - session.twitch_forward_start_time
                hours, remainder = divmod(uptime.seconds + uptime.days * 86400, 3600)
                minutes, seconds = divmod(remainder, 60)
                # Log process status
                logger.info(f"FFmpeg health check: Process is running (PID: {session.ffmpeg_process.pid}, Uptime: {hours}h {minutes}m {seconds}s)")
                # Check if the stream file is still growing
                if hasattr(session, 'flv_file_path') and os.path.exists(session.flv_file_path):
                    current_size = os.path.getsize(session.flv_file_path)
                    logger.info(f"Current FLV file size: {current_size / (1024*1024):.2f} MB")
                # If approaching 8 hours, log more frequently
                if 7 <= hours < 8:
                    logger.warning(f"Stream approaching 8 hour mark: {hours}h {minutes}m {seconds}s - monitoring closely")
                    check_interval = 60  # Check every minute when approaching the 8-hour mark
                session.last_health_check = current_time
        except asyncio.CancelledError:
            logger.info("FFmpeg health monitoring cancelled")
        except Exception as e:
            logger.error(f"Error in FFmpeg health monitoring: {e}", exc_info=True)

    async def terminate_ffmpeg(self, session):
        if not hasattr(session, "ffmpeg_process") or session.ffmpeg_process is None:
            return
        proc = session.ffmpeg_process
        try:
            # Cancel auxiliary tasks attached to this session
            for attr in ("drain_task", "health_monitor_task", "ffmpeg_watcher_task"):
                t = getattr(session, attr, None)
                if t is not None and not t.done():
                    t.cancel()
                    try:
                        await asyncio.wait_for(t, timeout=1.0)
                    except (asyncio.CancelledError, asyncio.TimeoutError):
                        pass
            if hasattr(session, "twitch_forward_start_time"):
                duration = datetime.datetime.now() - session.twitch_forward_start_time
                hours, remainder = divmod(duration.seconds, 3600)
                minutes, seconds = divmod(remainder, 60)
                duration_str = f" (ran for {hours}h {minutes}m {seconds}s)"
            else:
                duration_str = ""
            if proc.returncode is not None:
                logger.info(f"FFmpeg already exited with code {proc.returncode}{duration_str}")
                return
            # Graceful shutdown: closing stdin makes ffmpeg flush its output and exit.
            if proc.stdin is not None and not proc.stdin.is_closing():
                try:
                    proc.stdin.close()
                except Exception:
                    pass
            try:
                await asyncio.wait_for(proc.wait(), timeout=5.0)
                logger.info(f"FFmpeg exited cleanly after stdin close{duration_str}")
                return
            except asyncio.TimeoutError:
                logger.warning("FFmpeg didn't exit after stdin close; sending SIGTERM")
            proc.terminate()
            try:
                await asyncio.wait_for(proc.wait(), timeout=5.0)
                logger.info(f"FFmpeg terminated{duration_str}")
            except asyncio.TimeoutError:
                logger.warning("FFmpeg didn't respond to SIGTERM; killing")
                proc.kill()
                await proc.wait()
                logger.info(f"FFmpeg killed{duration_str}")
        except Exception as e:
            logger.error(f"Error while terminating FFmpeg: {e}", exc_info=True)

    async def on_metadata(self, session, message) -> None:
        session.state.write(0, message.to_raw_meta(), FLVMediaType.OBJECT)
        await super().on_metadata(session, message)

    async def on_video_message(self, session, message) -> None:
        session.state.write(message.timestamp, message.payload, FLVMediaType.VIDEO)
        await super().on_video_message(session, message)

    async def on_audio_message(self, session, message) -> None:
        session.state.write(message.timestamp, message.payload, FLVMediaType.AUDIO)
        await super().on_audio_message(session, message)

    async def on_stream_closed(self, session: SessionManager, exception: StreamClosedException) -> None:
        # Mark closed so any forwarder loop exits instead of restarting ffmpeg
        session.closed = True
        # Cancel the 48-hour duration monitor if still pending
        monitor_task = getattr(session, "duration_monitor_task", None)
        if monitor_task is not None and not monitor_task.done():
            monitor_task.cancel()
        # Terminate FFmpeg process if it exists
        await self.terminate_ffmpeg(session)
        # Close the FLV file after the stream ends
        session.state.close()
        # Remove from the operator web UI registry
        self.session_registry.deregister(id(session))
        # Convert FLV to MP4 using FFmpeg
        flv_file_path = session.flv_file_path
        asyncio.create_task(self.convert_flv_to_mp4_background(session, flv_file_path))
        logger.info(f"Started background conversion for {session.publishing_name}.")
        await super().on_stream_closed(session, exception)

    async def convert_flv_to_mp4_background(self, session, flv_file_path: str):
        # Get username and set user folder as destination
        username = await get_username_from_api_key(session.publishing_name)
        if not username:
            logger.error(
                f"Could not resolve username for stream key on {flv_file_path}; "
                f"skipping MP4 conversion and keeping FLV."
            )
            return
        user_dir = user_storage_dir(self.output_directory, username)
        os.makedirs(user_dir, exist_ok=True)
        date_part = os.path.splitext(os.path.basename(flv_file_path))[0]
        final_mp4_path = os.path.join(user_dir, f"{date_part}.mp4")
        # Check if file exists inside user folder and append a part number if needed
        if os.path.exists(final_mp4_path):
            base, ext = os.path.splitext(final_mp4_path)
            part = 2
            while os.path.exists(f"{base}-p{part}{ext}"):
                part += 1
            final_mp4_path = f"{base}-p{part}{ext}"
        # Run FFmpeg to convert the FLV file to MP4
        command = ["ffmpeg", "-i", flv_file_path, "-c", "copy", final_mp4_path]
        logger.info(f"Running FFmpeg process in async for {flv_file_path}...")
        process = await asyncio.create_subprocess_exec(*command)
        await process.communicate()
        if process.returncode == 0:
            os.remove(flv_file_path)
            logger.info(f"Converted file saved to {final_mp4_path}; removed source {flv_file_path}")
            await asyncio.to_thread(cache_duration, final_mp4_path)
            await maybe_queue_youtube_vod(username, os.path.basename(final_mp4_path))
            await maybe_queue_s3_vod(username, os.path.basename(final_mp4_path))
        else:
            logger.error(
                f"FFmpeg conversion failed (exit code {process.returncode}). "
                f"Keeping FLV file: {flv_file_path}"
            )

    async def on_command_message(self, session, message):
        if message.command in ["releaseStream", "FCPublish", "FCUnpublish"]:
            logger.info(f"Ignored command message: {message.command}")
            return
        await super().on_command_message(session, message)

class SimpleServer(SimpleRTMPServer):
    def __init__(self, output_directory: str, twitch_server: str, session_registry: SessionRegistry):
        self.output_directory = output_directory
        self.twitch_server = twitch_server
        self.session_registry = session_registry
        super().__init__()

    async def create(self, host: str, port: int, ssl_context=None):
        loop = asyncio.get_event_loop()
        self.server = await loop.create_server(
            lambda: RTMPProtocol(controller=RTMP2FLVController(self.output_directory, self.twitch_server, self.session_registry)),
            host=host,
            port=port,
            ssl=ssl_context
        )

def resolve_cert_paths(server_location):
    domain = (os.getenv("STREAM_SSL_DOMAIN") or "").strip() or SSL_DOMAIN_MAPPING.get(
        server_location, SSL_DOMAIN_MAPPING[DEFAULT_INGEST_SERVER]
    )
    cert_path = f"/etc/letsencrypt/live/{domain}/fullchain.pem"
    key_path = f"/etc/letsencrypt/live/{domain}/privkey.pem"
    if not os.path.exists(cert_path) or not os.path.exists(key_path):
        current_dir = os.path.dirname(os.path.abspath(__file__))
        cert_path = f"{current_dir}/ssl/fullchain.pem"
        key_path = f"{current_dir}/ssl/privkey.pem"
        logger.warning(f"Let's Encrypt certificates not found for {domain}, falling back to local SSL directory")
    return domain, cert_path, key_path

def create_ssl_context(server_location):
    context = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
    domain, cert_path, key_path = resolve_cert_paths(server_location)
    context.load_cert_chain(certfile=cert_path, keyfile=key_path)
    logger.info(f"SSL context created for domain: {domain}")
    return context

_BASE_CSS = """
  body { font-family: ui-sans-serif, system-ui, sans-serif; background: #0d0d0f; color: #e8e8f0; margin: 0; padding: 24px; }
  h1 { margin: 0 0 4px 0; font-size: 20px; display: flex; align-items: center; gap: 10px; }
  h1 img { width: 28px; height: 28px; }
  h2 { margin: 24px 0 6px 0; font-size: 15px; color: #e8e8f0; }
  .meta { color: #a8a8bc; font-size: 12px; margin-bottom: 14px; }
  nav { display: flex; gap: 18px; margin: 4px 0 18px 0; border-bottom: 1px solid rgba(255,255,255,0.07); align-items: center; flex-wrap: wrap; }
  nav a { color: #a8a8bc; text-decoration: none; padding: 6px 0 8px 0; font-size: 13px; }
  nav a:hover { color: #e8e8f0; }
  nav a.active { color: #e8e8f0; border-bottom: 2px solid #7c5cbf; }
  nav a.docs { color: #9070d8; font-weight: 600; }
  .nav-right { margin-left: auto; display: flex; gap: 18px; align-items: center; }
  .who { color: #6c6c84; font-size: 12px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid rgba(255,255,255,0.07); vertical-align: top; }
  th { font-weight: 600; color: #a8a8bc; text-transform: uppercase; font-size: 11px; letter-spacing: 0.04em; }
  tr:hover td { background: #1a1a20; }
  code { font-family: ui-monospace, SFMono-Regular, monospace; background: #16161c; padding: 1px 6px; border-radius: 3px; font-size: 12px; }
  .empty { color: #a8a8bc; padding: 32px; text-align: center; border: 1px dashed rgba(255,255,255,0.07); border-radius: 6px; }
  .yes { color: #3ecf8e; }
  .no  { color: #6c6c84; }
  section { margin-bottom: 24px; }
  a.dl { color: #7c5cbf; text-decoration: none; }
  a.dl:hover { color: #9070d8; }
"""

_NAV_HTML = """  <nav>
    <a href="/" class="{{ 'active' if page == 'dashboard' else '' }}">Live sessions</a>
    <a href="/recordings" class="{{ 'active' if page == 'recordings' else '' }}">Recordings</a>
    <a href="/docs" class="docs">API docs</a>
    <span class="nav-right">
      {% if viewer_name %}<span class="who">{{ viewer_name }}</span>{% endif %}
      <a href="https://dashboard.botofthespecter.com">Dashboard</a>
      <a href="/logout">Sign out</a>
    </span>
  </nav>
"""

_HEAD_ICONS = """<link rel="icon" href="https://cdn.botofthespecter.com/favicon.ico">
<link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
"""

DASHBOARD_TEMPLATE = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="5">
<title>{{ server_title }}</title>
""" + _HEAD_ICONS + """<style>""" + _BASE_CSS + """</style>
</head>
<body>
  <h1><img src="https://cdn.botofthespecter.com/logo.png" alt=""> {{ server_title }}</h1>
""" + _NAV_HTML + """  <div class="meta">
    {% if is_admin %}Operator view · {% endif %}
    {{ sessions|length }} active session{{ '' if sessions|length == 1 else 's' }} ·
    refreshed {{ generated_at }} (auto-refresh 5s)
    · <a href="/docs">API docs</a>
  </div>
  {% if sessions %}
  <table>
    <thead>
      <tr>
        {% if is_admin %}<th>Stream key</th>{% endif %}
        <th>User</th>
        {% if is_admin %}<th>Incoming peer</th>{% endif %}
        <th>Connected</th>
        <th>FLV size</th>
        <th>Outgoing target</th>
        {% if is_admin %}<th>FFmpeg PID</th>{% endif %}
        <th>Forwarding for</th>
      </tr>
    </thead>
    <tbody>
      {% for s in sessions %}
      <tr>
        {% if is_admin %}<td><code>{{ s.publishing_name }}</code></td>{% endif %}
        <td>{{ s.username }}</td>
        {% if is_admin %}<td><code>{{ s.peer }}</code></td>{% endif %}
        <td>{{ s.connected_for }}</td>
        <td>{{ s.flv_size }}</td>
        {% if s.forwarding_target %}
          <td class="yes"><code>{{ s.forwarding_target }}</code></td>
          {% if is_admin %}<td>{{ s.forwarding_pid }}</td>{% endif %}
          <td>{{ s.forwarding_duration }}</td>
        {% else %}
          <td class="no">not forwarding</td>
          {% if is_admin %}<td class="no">&mdash;</td>{% endif %}
          <td class="no">&mdash;</td>
        {% endif %}
      </tr>
      {% endfor %}
    </tbody>
  </table>
  {% else %}
    <div class="empty">{% if is_admin %}No active sessions on this server.{% else %}You are not publishing to this Specter ingest right now.{% endif %}</div>
  {% endif %}
</body>
</html>
"""

RECORDINGS_TEMPLATE = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="30">
<title>{{ server_title }} &mdash; Recordings</title>
""" + _HEAD_ICONS + """<style>""" + _BASE_CSS + """</style>
</head>
<body>
  <h1><img src="https://cdn.botofthespecter.com/logo.png" alt=""> {{ server_title }}</h1>
""" + _NAV_HTML + """  <div class="meta">
    {% if is_admin %}Recorder storage: <code>{{ root_path }}</code> ·
    {{ users|length }} user folder{{ '' if users|length == 1 else 's' }} ·
    {% endif %}
    refreshed {{ generated_at }} (auto-refresh 30s)
    · <a href="/docs">API docs</a>
  </div>
  {% if users %}
    {% for u in users %}
    <section>
      <h2>{{ u.username }}</h2>
      <div class="meta">{{ u.file_count }} file{{ '' if u.file_count == 1 else 's' }} &middot; {{ u.total_size_str }}</div>
      <table>
        <thead><tr><th>File</th><th>Size</th><th>Modified</th><th></th></tr></thead>
        <tbody>
          {% for f in u.files %}
          <tr>
            <td>{{ f.display_name }}</td>
            <td>{{ f.size_str }}</td>
            <td>{{ f.mtime_str }}</td>
            <td>
              {% if f.download_url %}
                <a class="dl" href="{{ f.download_url }}">Download</a>
              {% elif f.is_partial %}
                in progress
              {% endif %}
            </td>
          </tr>
          {% endfor %}
        </tbody>
      </table>
    </section>
    {% endfor %}
  {% else %}
    <div class="empty">{% if is_admin %}No recordings found at <code>{{ root_path }}</code>.{% else %}No recordings stored for your channel on this server yet.{% endif %}</div>
  {% endif %}
</body>
</html>
"""

def _humanize_bytes(n: float) -> str:
    for unit in ("B", "KB", "MB", "GB", "TB"):
        if n < 1024.0:
            return f"{n:.1f} {unit}"
        n /= 1024.0
    return f"{n:.1f} PB"

def _humanize_duration(delta: datetime.timedelta) -> str:
    total = max(0, int(delta.total_seconds()))
    h, rem = divmod(total, 3600)
    m, s = divmod(rem, 60)
    if h > 0:
        return f"{h}h {m}m {s}s"
    if m > 0:
        return f"{m}m {s}s"
    return f"{s}s"

def list_recorder_files(root_path: str) -> list[dict]:
    if not root_path or not os.path.isdir(root_path):
        return []
    try:
        entries = sorted(os.listdir(root_path))
    except OSError as e:
        logger.warning(f"Could not list recorder storage at {root_path}: {e}")
        return []
    users = []
    for entry in entries:
        if entry.startswith("_") or not re.match(r"^[a-zA-Z0-9_]{1,64}$", entry):
            continue
        user_dir = os.path.join(root_path, entry)
        if not os.path.isdir(user_dir):
            continue
        try:
            file_entries = os.listdir(user_dir)
        except OSError:
            continue
        files = []
        total_size = 0
        for fname in file_entries:
            fpath = os.path.join(user_dir, fname)
            if not os.path.isfile(fpath):
                continue
            try:
                stat = os.stat(fpath)
            except OSError:
                continue
            files.append({"name": fname, "size": stat.st_size, "mtime": stat.st_mtime})
            total_size += stat.st_size
        files.sort(key=lambda f: f["mtime"], reverse=True)
        users.append({
            "username": entry,
            "files": files,
            "total_size": total_size,
            "file_count": len(files),
        })
    return users


_RECORDING_SKIP_SUFFIXES = (".ffmpeg.log", ".ytdlp.log", ".fwd.log", ".json")


def _safe_recording_name(name: str) -> bool:
    if not isinstance(name, str) or not name:
        return False
    if "/" in name or "\\" in name or "\x00" in name:
        return False
    if name in (".", "..") or os.path.basename(name) != name:
        return False
    return True


def vod_cdn_url(username: str, filename: str, title: str = "") -> str:
    from ffmpeg_jobs import sanitize_download_basename

    disk = os.path.basename(filename or "")
    pretty = sanitize_download_basename(title or os.path.splitext(disk)[0])
    if not pretty.lower().endswith(".mp4"):
        pretty += ".mp4"
    return (
        f"{VODS_CDN_BASE}/{quote(username, safe='')}/"
        f"{quote(disk, safe='')}/"
        f"{quote(pretty, safe='')}"
    )


async def list_extended_vods(username: str, keep_names: set[str] | None = None) -> list[dict]:
    # keep_names: expired extensions still listed because a rerun is holding them.
    keep = sorted(keep_names or ())
    keep_sql = (" OR filename IN (" + ", ".join(["%s"] * len(keep)) + ")") if keep else ""
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            try:
                await cursor.execute(
                    """
                    SELECT filename, s4_key, expires_at, duration_seconds
                    FROM vod_extensions
                    WHERE username = %s AND (expires_at > NOW()""" + keep_sql + """)
                    """,
                    (username, *keep),
                )
            except aiomysql.OperationalError:
                # duration_seconds column not migrated yet
                await cursor.execute(
                    "SELECT filename, s4_key, expires_at FROM vod_extensions WHERE username = %s AND (expires_at > NOW()" + keep_sql + ")",
                    (username, *keep),
                )
            return await cursor.fetchall() or []
    except Exception as e:
        logger.error(f"list_extended_vods failed for {username}: {e}")
        return []
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()


async def save_extended_vod(user_id: int, username: str, filename: str, s4_key: str, expires_at, duration_seconds=None) -> None:
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor() as cursor:
            await cursor.execute(
                """
                INSERT INTO vod_extensions (user_id, username, filename, s4_key, expires_at, duration_seconds)
                VALUES (%s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE s4_key = VALUES(s4_key), expires_at = VALUES(expires_at),
                    duration_seconds = COALESCE(VALUES(duration_seconds), duration_seconds)
                """,
                (user_id, username, filename, s4_key, expires_at, duration_seconds),
            )
            await sqldb.commit()
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()


async def get_extended_vod(username: str, filename: str) -> dict | None:
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute(
                """
                SELECT s4_key FROM vod_extensions
                WHERE username = %s AND filename = %s
                LIMIT 1
                """,
                (username, filename),
            )
            return await cursor.fetchone()
    except Exception as e:
        logger.error(f"get_extended_vod failed for {username}: {e}")
        return None
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()


async def delete_extended_vod_row(username: str, filename: str) -> None:
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor() as cursor:
            await cursor.execute(
                "DELETE FROM vod_extensions WHERE username = %s AND filename = %s",
                (username, filename),
            )
            await sqldb.commit()
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()


async def recording_youtube_busy(user_id: int, filename: str) -> bool:
    # YouTube or S3 still has this file queued or in flight, or a rerun is still due to play it.
    if user_id <= 0 or not filename:
        return False
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor() as cursor:
            await cursor.execute(
                """
                SELECT id FROM youtube_vod_uploads
                WHERE user_id = %s AND filename = %s
                  AND status IN ('queued', 'pulling', 'uploading')
                LIMIT 1
                """,
                (user_id, filename),
            )
            if await cursor.fetchone() is not None:
                return True
            await cursor.execute(
                """
                SELECT id FROM user_s3_uploads
                WHERE user_id = %s AND filename = %s
                  AND status IN ('queued', 'pulling', 'uploading')
                LIMIT 1
                """,
                (user_id, filename),
            )
            if await cursor.fetchone() is not None:
                return True
            await cursor.execute(
                """
                SELECT i.id FROM vod_rerun_items i
                JOIN vod_reruns r ON r.id = i.rerun_id
                WHERE i.user_id = %s AND i.filename = %s AND i.storage = 'local'
                  AND r.status IN ('scheduled', 'live')
                LIMIT 1
                """,
                (user_id, filename),
            )
            return await cursor.fetchone() is not None
    except Exception:
        return False
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()


async def filenames_held_for_user(user_id: int) -> set[str]:
    if user_id <= 0:
        return set()
    sqldb = None
    try:
        sqldb = await access_website_database()
        names = set()
        for table in ("youtube_vod_uploads", "user_s3_uploads"):
            try:
                async with sqldb.cursor() as cursor:
                    await cursor.execute(
                        f"""
                        SELECT filename FROM {table}
                        WHERE user_id = %s AND status IN ('queued', 'pulling', 'uploading')
                        """,
                        (user_id,),
                    )
                    rows = await cursor.fetchall()
            except Exception:
                continue
            for row in rows or []:
                if row and row[0]:
                    names.add(str(row[0]))
        return names
    except Exception as e:
        logger.warning(f"Could not load in-progress uploads for user {user_id}: {e}")
        return set()
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()


async def filenames_in_reruns(user_id: int) -> set[str]:
    # Files a scheduled or live rerun still needs, with when that rerun ends (unix time)
    if user_id <= 0:
        return set()
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor() as cursor:
            await cursor.execute(
                """
                SELECT DISTINCT i.filename FROM vod_rerun_items i
                JOIN vod_reruns r ON r.id = i.rerun_id
                WHERE i.user_id = %s AND r.status IN ('scheduled', 'live')
                  AND (
                    i.storage <> 's4'
                    OR EXISTS (
                      SELECT 1 FROM vod_extensions e
                      WHERE e.username = r.username AND e.filename = i.filename
                        AND r.scheduled_at <= e.expires_at + INTERVAL %s DAY
                    )
                  )
                """,
                (user_id, RERUN_S4_HOLD_DAYS),
            )
            return {str(row[0]) for row in await cursor.fetchall() or [] if row and row[0]}
    except Exception:
        return set()
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()


def _restore_state_key(username: str, filename: str) -> str:
    return username + "\0" + filename


def _restores_for_user(username: str) -> list[dict]:
    prefix = username + "\0"
    out = []
    for key, state in list(_s3_restores.items()):
        if not key.startswith(prefix):
            continue
        if state.get("status") == "done" and os.path.isfile(state.get("dest") or ""):
            _s3_restores.pop(key, None)
            continue
        out.append({
            "object_key": state.get("object_key") or "",
            "filename": state.get("filename") or "",
            "status": state.get("status") or "",
            "percent": state.get("percent") or 0,
            "bytes": int(state.get("bytes") or 0),
            "bytes_total": int(state.get("bytes_total") or 0),
        })
    return out


async def cleanup_expired_local_vods(root: str) -> None:
    # Drop local MP4s past the retention window. Leave a file alone while YouTube or S3 is still using it.
    if not root or not os.path.isdir(root):
        return
    from upload_hold import connect_website, filenames_held_for_upload, names_kept_with

    try:
        conn = await connect_website()
    except Exception as e:
        logger.error(f"Skipping local VOD retention; database unavailable: {e}")
        return
    try:
        held = await filenames_held_for_upload(conn)
    except Exception as e:
        logger.error(f"Skipping local VOD retention; could not read in-progress uploads: {e}")
        held = None
    finally:
        await conn.ensure_closed()
    if held is None:
        return
    kept = {}
    for username, filename in held:
        kept.setdefault(username, set()).update(names_kept_with(filename))
    cutoff = time.time() - RECORDING_RETENTION_SECONDS
    removed = 0
    try:
        user_dirs = os.listdir(root)
    except OSError as e:
        logger.error(f"Local VOD retention could not list {root}: {e}")
        return
    for username in user_dirs:
        if not re.match(r"^[a-zA-Z0-9_]{1,64}$", username):
            continue
        user_dir = os.path.join(root, username)
        if not os.path.isdir(user_dir):
            continue
        protect = kept.get(username, set())
        try:
            names = os.listdir(user_dir)
        except OSError:
            continue
        for name in names:
            path = os.path.join(user_dir, name)
            if name == "_restore":
                if os.path.isdir(path):
                    _cleanup_restore_parts(path, cutoff)
                continue
            if name in protect or not os.path.isfile(path):
                continue
            try:
                if os.path.getmtime(path) >= cutoff:
                    continue
                lower = name.lower()
                if lower.endswith(".mp4") or lower.endswith(".flv"):
                    if find_ffmpeg_pid_for_path(path):
                        continue
                    if lower.endswith(".mp4"):
                        remove_media_and_sidecars(path)
                    else:
                        os.remove(path)
                    removed += 1
                    continue
                # Leave a sidecar alone while its video is still on disk. The video's own pass removes it.
                media = _sidecar_media_path(path, name)
                if not media or os.path.isfile(media):
                    continue
                os.remove(path)
                removed += 1
            except FileNotFoundError:
                continue
            except OSError as e:
                logger.warning(f"Could not remove expired recording {path}: {e}")
    if removed:
        logger.info(f"Removed {removed} recording file(s) past retention")


def _safe_object_key(key: str) -> bool:
    if not key or len(key) > 512 or ".." in key or "\\" in key or "\x00" in key:
        return False
    if key.startswith("/") or not key.lower().endswith(".mp4"):
        return False
    return True


async def _s3_done_job(user_id: int, object_key: str) -> dict | None:
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute(
                """
                SELECT filename, title, object_key, twitch_video_id
                FROM user_s3_uploads
                WHERE user_id = %s AND object_key = %s AND status = 'done'
                ORDER BY id DESC LIMIT 1
                """,
                (user_id, object_key),
            )
            return await cursor.fetchone()
    except Exception as e:
        logger.error(f"S3 restore lookup failed: {e}")
        return None
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()


async def _s3_settings_for_user(user_id: int) -> dict | None:
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute(
                """
                SELECT endpoint, region, bucket, prefix, access_key, secret_key, path_style
                FROM user_s3_settings WHERE user_id = %s LIMIT 1
                """,
                (user_id,),
            )
            row = await cursor.fetchone()
        if not row or not (row.get("access_key") or "") or not (row.get("bucket") or "") or not (row.get("endpoint") or ""):
            return None
        return row
    except Exception as e:
        logger.error(f"S3 settings lookup failed: {e}")
        return None
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()


def _s3_object_size(settings: dict, object_key: str) -> tuple[int, bool]:
    from botocore.exceptions import ClientError
    from s3_vod_uploader import make_client

    client = make_client(settings)
    try:
        meta = client.head_object(Bucket=settings["bucket"], Key=object_key)
    except ClientError as e:
        status = int((e.response or {}).get("ResponseMetadata", {}).get("HTTPStatusCode") or 0)
        code = str((e.response or {}).get("Error", {}).get("Code") or "")
        if status in (403, 404) or code in ("404", "NoSuchKey", "NotFound", "403", "Forbidden"):
            return 0, True
        raise
    return int(meta.get("ContentLength") or 0), False


def _link_restored_file(dest: str, username: str, filename: str) -> None:
    # YouTube and S3 workers read stream_files_root(), which can differ from the library folder.
    try:
        from s3_vod_uploader import stream_files_root
        other_dir = os.path.join(stream_files_root(), username)
        other = os.path.join(other_dir, filename)
    except Exception:
        return
    if os.path.abspath(other) == os.path.abspath(dest) or os.path.isfile(other):
        return
    try:
        os.makedirs(other_dir, exist_ok=True)
        os.link(dest, other)
    except OSError:
        try:
            os.symlink(dest, other)
        except OSError as e:
            logger.warning(f"Could not link restored VOD into the uploader folder: {e}")


async def _restore_s3_file(state_key, username, filename, object_key, settings, dest, title, vod_id):
    state = _s3_restores.get(state_key) or {}
    part_dir = os.path.join(os.path.dirname(dest), "_restore")
    part = os.path.join(part_dir, filename + ".part")

    def download():
        from s3_vod_uploader import make_client
        os.makedirs(part_dir, exist_ok=True)
        client = make_client(settings)

        def cb(n):
            state["bytes"] = int(state.get("bytes") or 0) + int(n or 0)
            total = int(state.get("bytes_total") or 0)
            if total > 0:
                state["percent"] = round(min(100.0, 100.0 * state["bytes"] / total), 1)

        client.download_file(settings["bucket"], object_key, part, Callback=cb)

    try:
        await asyncio.to_thread(download)
        os.replace(part, dest)
        await asyncio.to_thread(cache_duration, dest, vod_id, title)
        _link_restored_file(dest, username, filename)
        state["status"] = "done"
        state["percent"] = 100
        logger.info(f"Restored {username}/{filename} from user S3")
    except Exception as e:
        state["status"] = "failed"
        logger.error(f"S3 restore failed for {username}/{filename}: {type(e).__name__}")
        try:
            if os.path.isfile(part):
                os.remove(part)
        except OSError:
            pass
    finally:
        _s3_restore_tasks.pop(state_key, None)


def _sidecar_media_path(path: str, name: str) -> str:
    lower = name.lower()
    if lower.endswith(".mp4.part"):
        return path[:-5]
    for suffix in (".ffmpeg.log", ".ytdlp.log", ".fwd.log", ".json"):
        if lower.endswith(suffix):
            media = path[: -len(suffix)]
            if media.lower().endswith(".mp4"):
                return media
            return media + ".mp4"
    return ""


def _cleanup_restore_parts(part_dir: str, cutoff: float) -> None:
    try:
        names = os.listdir(part_dir)
    except OSError:
        return
    for name in names:
        path = os.path.join(part_dir, name)
        try:
            if os.path.isfile(path) and os.path.getmtime(path) < cutoff:
                os.remove(path)
        except OSError:
            continue


async def _expired_vod_loop(root: str) -> None:
    while True:
        await cleanup_expired_s4_vods()
        await cleanup_expired_local_vods(root)
        try:
            from s3_vod_uploader import stream_files_root
            other = stream_files_root()
            if other and os.path.abspath(other) != os.path.abspath(root or ""):
                await cleanup_expired_local_vods(other)
        except Exception as e:
            logger.warning(f"Uploader-folder retention skipped: {e}")
        await asyncio.sleep(60)


async def lookup_user_id(username: str) -> int | None:
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute("SELECT id FROM users WHERE username = %s LIMIT 1", (username,))
            row = await cursor.fetchone()
            return int(row["id"]) if row else None
    except Exception as e:
        logger.error(f"lookup_user_id failed for {username}: {e}")
        return None
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()


async def cleanup_expired_s4_vods() -> None:
    from vod_s4 import delete_vod

    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            try:
                await cursor.execute(
                    """
                    SELECT e.id, e.s4_key FROM vod_extensions e
                    WHERE e.expires_at <= NOW()
                      AND NOT EXISTS (
                        SELECT 1 FROM vod_rerun_items i
                        JOIN vod_reruns r ON r.id = i.rerun_id
                        WHERE r.status IN ('scheduled', 'live') AND i.storage = 's4'
                          AND r.username = e.username AND i.filename = e.filename
                          AND r.scheduled_at <= e.expires_at + INTERVAL %s DAY
                      )
                    """,
                    (RERUN_S4_HOLD_DAYS,),
                )
            except aiomysql.ProgrammingError:
                # vod_reruns not migrated yet
                await cursor.execute(
                    "SELECT id, s4_key FROM vod_extensions WHERE expires_at <= NOW()"
                )
            rows = await cursor.fetchall() or []
            for row in rows:
                key = row.get("s4_key") or ""
                try:
                    await asyncio.to_thread(delete_vod, key)
                except Exception as e:
                    logger.warning(f"S4 VOD delete failed for {key}: {e}")
                    continue
                await cursor.execute("DELETE FROM vod_extensions WHERE id = %s", (row["id"],))
            if rows:
                await sqldb.commit()
                logger.info(f"Removed {len(rows)} expired S4 VODs")
    except Exception as e:
        logger.error(f"cleanup_expired_s4_vods failed: {e}")
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()


def list_user_recording_files(root_path: str, username: str) -> list[dict]:
    if not root_path or not username or not re.match(r"^[a-zA-Z0-9_]{1,64}$", username):
        return []
    user_dir = os.path.join(root_path, username)
    if not os.path.isdir(user_dir):
        return []
    files = []
    try:
        names = os.listdir(user_dir)
    except OSError:
        return []
    ffmpeg_cmds = live_ffmpeg_cmdlines()
    for fname in names:
        if not _safe_recording_name(fname):
            continue
        if fname.endswith(_RECORDING_SKIP_SUFFIXES):
            continue
        fpath = os.path.join(user_dir, fname)
        if not os.path.isfile(fpath):
            continue
        try:
            stat = os.stat(fpath)
        except OSError:
            continue
        files.append({
            "name": fname,
            "size": stat.st_size,
            "mtime": stat.st_mtime,
            "is_partial": fname.endswith(".part") or ffmpeg_is_writing_path(fpath, ffmpeg_cmds),
        })
    files.sort(key=lambda f: f["mtime"], reverse=True)
    return files


def _recording_display_name(username: str, filename: str, root_path: str) -> str:
    name = filename or ""
    match = re.match(r"^twitch-([0-9]{1,20})\.mp4(?:\.part)?$", name, re.I)
    if match:
        title = _vod_title_from_sidecar(os.path.join(root_path, username), match.group(1))
        if title:
            return title
    base = name
    if base.lower().endswith(".part"):
        base = base[:-5]
    if base.lower().endswith(".mp4"):
        base = base[:-4]
    return base or name


def _ui_recording_users(root_path: str, only_username: str | None = None) -> list[dict]:
    if only_username:
        raw_files = list_user_recording_files(root_path, only_username)
        groups = [{
            "username": only_username,
            "files": raw_files,
            "total_size": sum(int(f.get("size") or 0) for f in raw_files),
            "file_count": len(raw_files),
        }]
    else:
        groups = list_recorder_files(root_path)
    out = []
    for group in groups:
        username = group["username"]
        files = []
        total = 0
        for item in group["files"]:
            name = item["name"]
            if name.endswith(_RECORDING_SKIP_SUFFIXES):
                continue
            is_partial = bool(item.get("is_partial")) or name.endswith(".part")
            size = int(item.get("size") or 0)
            total += size
            display = _recording_display_name(username, name, root_path)
            files.append({
                "name": name,
                "display_name": display,
                "size": size,
                "size_str": _humanize_bytes(float(size)),
                "mtime": item.get("mtime"),
                "mtime_str": datetime.datetime.fromtimestamp(item["mtime"]).strftime("%Y-%m-%d %H:%M:%S") if item.get("mtime") else "—",
                "is_partial": is_partial,
                "download_url": None if is_partial else vod_cdn_url(username, name, display),
            })
        files.sort(key=lambda f: f.get("mtime") or 0, reverse=True)
        if only_username and not files:
            continue
        out.append({
            "username": username,
            "files": files,
            "total_size": total,
            "total_size_str": _humanize_bytes(float(total)),
            "file_count": len(files),
        })
    return out


async def _detect_ffmpeg_version() -> str:
    try:
        proc = await asyncio.create_subprocess_exec(
            "ffmpeg", "-version",
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        stdout, _ = await asyncio.wait_for(proc.communicate(), timeout=5.0)
        first_line = stdout.decode(errors="replace").splitlines()[0].strip() if stdout else ""
        return first_line or "unknown"
    except Exception as e:
        logger.warning(f"Could not detect ffmpeg version: {e}")
        return "unknown"

async def _verify_admin_key(api_key: str) -> bool:
    if not api_key:
        return False
    sqldb = None
    try:
        sqldb = await access_website_database()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute(
                "SELECT service FROM admin_api_keys WHERE api_key = %s",
                (api_key,),
            )
            row = await cursor.fetchone()
            if not row:
                return False
            service = (row.get("service") or "").lower()
            if service == "admin":
                return True
            if service == ADMIN_KEY_SERVICE.lower():
                return True
            logger.warning(f"Admin key for service '{service}' tried to access {ADMIN_KEY_SERVICE}; denied.")
            return False
    except Exception as e:
        logger.error(f"Error verifying admin key: {e}")
        return False
    finally:
        if sqldb is not None:
            await sqldb.ensure_closed()

def _require_api_key(view):
    @wraps(view)
    async def wrapper(*args, **kwargs):
        provided = request.headers.get("X-API-Key", "")
        if not provided:
            return jsonify({"error": "missing X-API-Key header"}), 401
        if not await _verify_admin_key(provided):
            return jsonify({"error": "incorrect API key"}), 401
        return await view(*args, **kwargs)
    return wrapper

def _session_to_json(s: dict, now: datetime.datetime) -> dict:
    flv_path = s.get("flv_file_path")
    flv_size = None
    if flv_path and os.path.exists(flv_path):
        try:
            flv_size = os.path.getsize(flv_path)
        except OSError:
            flv_size = None
    forwarding = s.get("forwarding")
    fwd_json = None
    if forwarding:
        fwd_json = {
            "target_url": forwarding["target_url"],
            "ffmpeg_pid": forwarding["ffmpeg_pid"],
            "started_at": forwarding["started_at"].isoformat(),
            "duration_seconds": int((now - forwarding["started_at"]).total_seconds()),
        }
    publish_started = s.get("publish_started_at")
    return {
        "id": s["id"],
        "peer": s["peer"],
        "publishing_name": s.get("publishing_name"),
        "username": s.get("username"),
        "connected_at": s["connected_at"].isoformat(),
        "connected_seconds": int((now - s["connected_at"]).total_seconds()),
        "publish_started_at": publish_started.isoformat() if publish_started else None,
        "flv_file_path": flv_path,
        "flv_size_bytes": flv_size,
        "forwarding": fwd_json,
    }

def stream_openapi_spec() -> dict:
    return {
        "openapi": "3.0.3",
        "info": {
            "title": "Sydney Stream API",
            "version": "1.0.0",
            "description": (
                "Recordings, Twitch VOD store, and extended VOD storage on syd1. "
                "User routes take the streamer's API key in X-API-KEY. "
                "Operator routes also accept an admin key with service rtmp-server or admin."
            ),
        },
        "servers": [{"url": "https://syd1.stream.botofthespecter.com"}],
        "components": {
            "securitySchemes": {
                "ApiKey": {"type": "apiKey", "in": "header", "name": "X-API-KEY"},
            }
        },
        "security": [{"ApiKey": []}],
        "paths": {
            "/api/me/recordings": {
                "get": {
                    "tags": ["Recordings"],
                    "summary": "List stored files and in-progress Twitch VOD downloads",
                    "responses": {"200": {"description": "files, pulls, used_bytes, quota_bytes"}},
                }
            },
            "/api/me/recordings/file": {
                "get": {
                    "tags": ["Recordings"],
                    "summary": "Download a stored MP4",
                    "parameters": [{"name": "name", "in": "query", "required": True, "schema": {"type": "string"}}],
                    "responses": {"200": {"description": "video/mp4"}},
                }
            },
            "/api/me/recordings/extend": {
                "post": {
                    "tags": ["Recordings"],
                    "summary": "Move a local MP4 to extended storage for 3 extra days",
                    "requestBody": {
                        "required": True,
                        "content": {"application/json": {"schema": {"type": "object", "properties": {"name": {"type": "string"}}}}},
                    },
                    "responses": {"200": {"description": "Extended"}},
                }
            },
            "/api/me/recordings/delete": {
                "post": {
                    "tags": ["Recordings"],
                    "summary": "Delete a stored MP4 from local disk and extended storage",
                    "requestBody": {
                        "required": True,
                        "content": {"application/json": {"schema": {"type": "object", "properties": {"name": {"type": "string"}}}}},
                    },
                    "responses": {
                        "200": {"description": "Deleted"},
                        "409": {"description": "Still recording or uploading"},
                    },
                }
            },
            "/api/me/recordings/pull-twitch": {
                "post": {
                    "tags": ["Recordings"],
                    "summary": "Download a Twitch VOD into the channel folder",
                    "requestBody": {
                        "required": True,
                        "content": {
                            "application/json": {
                                "schema": {
                                    "type": "object",
                                    "properties": {
                                        "vod_id": {"type": "string"},
                                        "title": {"type": "string"},
                                    },
                                    "required": ["vod_id"],
                                }
                            }
                        },
                    },
                    "responses": {"202": {"description": "Download started"}},
                }
            },
            "/api/me/recordings/restore-s3": {
                "post": {
                    "tags": ["Recordings"],
                    "summary": "Copy an S3 archive back into local storage",
                    "responses": {"202": {"description": "Copy started"}},
                }
            },
            "/api/server": {
                "get": {
                    "tags": ["Operator"],
                    "summary": "Stream host health (admin key)",
                    "responses": {"200": {"description": "Region, ffmpeg, sessions"}},
                }
            },
            "/api/recordings": {
                "get": {
                    "tags": ["Operator"],
                    "summary": "All local recording folders (admin key)",
                    "responses": {"200": {"description": "Per-user file inventory"}},
                }
            },
        },
    }


def create_web_app(server_title: str, region: str, session_registry: SessionRegistry, recorder_storage_path: str) -> Quart:
    app = Quart(__name__)

    # Quart session cookie is host-only on {syd1|use1|usw1|euc1}.stream.botofthespecter.com.
    if WEB_SECRET_KEY:
        app.config["SECRET_KEY"] = WEB_SECRET_KEY
    else:
        app.config["SECRET_KEY"] = secrets.token_hex(32)
        logger.warning(
            "WEB_SECRET_KEY env var not set; using ephemeral key. "
            "Sessions will be lost on restart and won't share across regions."
        )
    app.config["SESSION_COOKIE_NAME"]        = WEB_SESSION_COOKIE_NAME
    app.config["SESSION_COOKIE_DOMAIN"]      = WEB_SESSION_COOKIE_DOMAIN_BY_REGION.get(region)
    app.config["SESSION_COOKIE_SECURE"]      = True
    app.config["SESSION_COOKIE_HTTPONLY"]    = True
    app.config["SESSION_COOKIE_SAMESITE"]    = "Lax"
    app.config["PERMANENT_SESSION_LIFETIME"] = WEB_SESSION_LIFETIME_SECONDS

    ACME_CHALLENGE_DIR = "/var/lib/letsencrypt/http_challenges"

    @app.before_request
    async def enforce_https():
        if request.path.startswith("/.well-known/acme-challenge/"):
            return None
        if request.scheme == "http":
            host = request.headers.get("Host", "").split(":")[0] or request.host.split(":")[0]
            qs = request.query_string.decode("utf-8") if request.query_string else ""
            target = f"https://{host}{request.path}" + (f"?{qs}" if qs else "")
            return redirect(target, 301)

    @app.get("/.well-known/acme-challenge/<path:filename>")
    async def acme_challenge(filename: str):
        if os.path.isdir(ACME_CHALLENGE_DIR):
            return await send_from_directory(ACME_CHALLENGE_DIR, filename)
        return "Not found", 404

    sso_target = SSO_TARGET_BY_REGION.get(region, f"rtmp-{region}")

    def _require_sso_session(view):
        @wraps(view)
        async def wrapper(*args, **kwargs):
            if not session.get("twitchUserId"):
                # Stash the path the user was trying to reach so SSO can drop them back.
                qs = request.query_string.decode() if request.query_string else ""
                back = request.path + (("?" + qs) if qs else "")
                return redirect(
                    f"{SSO_AUTHORITY_URL}?target={sso_target}&return={quote(back, safe='')}"
                )
            return await view(*args, **kwargs)
        return wrapper

    async def _verify_and_consume_handoff(token: str) -> dict | None:
        """Atomically claim a handoff token. Returns the token's row dict on
        success, None if the token is missing / expired / wrong target /
        already used. Marks the row used so it can't be replayed."""
        if not token:
            return None
        sqldb = None
        try:
            sqldb = await access_website_database()
            async with sqldb.cursor(aiomysql.DictCursor) as cur:
                await cur.execute(
                    "SELECT twitch_user_id, username, display_name, profile_image, "
                    "       is_admin, used, expires_at, target "
                    "FROM handoff_tokens WHERE token = %s LIMIT 1",
                    (token,),
                )
                row = await cur.fetchone()
                if not row:
                    return None
                if row["used"] or row["expires_at"] < datetime.datetime.now():
                    return None
                if (row.get("target") or "") != sso_target:
                    logger.warning(
                        f"Handoff token target='{row.get('target')}' presented "
                        f"to region expecting '{sso_target}'; rejecting."
                    )
                    return None
                # Race-safe consume: only succeed if `used` was still 0 at UPDATE time.
                await cur.execute(
                    "UPDATE handoff_tokens SET used = 1 WHERE token = %s AND used = 0",
                    (token,),
                )
                if cur.rowcount != 1:
                    return None
                await sqldb.commit()
                return row
        except Exception as e:
            logger.error(f"Handoff token verification failed: {e}")
            return None
        finally:
            if sqldb is not None:
                await sqldb.ensure_closed()

    @app.get("/openapi.json")
    async def openapi_json():
        return jsonify(stream_openapi_spec())

    @app.get("/docs")
    async def themed_docs():
        return await send_from_directory(DOCS_UI_DIR, "index.html")

    @app.get("/docs-static/<path:filename>")
    async def docs_static(filename):
        return await send_from_directory(DOCS_UI_DIR, filename)

    @app.get("/favicon.ico")
    async def favicon():
        return redirect(FAVICON_URL)

    def _viewer_context():
        username = (session.get("username") or "").strip()
        display = (session.get("display_name") or username).strip()
        return {
            "is_admin": bool(session.get("is_admin")),
            "username": username,
            "viewer_name": display or username,
        }

    @app.get("/")
    @_require_sso_session
    async def dashboard():
        now = datetime.datetime.now()
        viewer = _viewer_context()
        rows = []
        for s in session_registry.snapshot():
            session_user = (s.get("username") or "").strip()
            if not viewer["is_admin"] and session_user.lower() != viewer["username"].lower():
                continue
            connected_for = _humanize_duration(now - s["connected_at"])
            publishing = s["publishing_name"] or "(handshake)"
            flv_size_str = "-"
            flv = s["flv_file_path"]
            if flv and os.path.exists(flv):
                flv_size_str = _humanize_bytes(float(os.path.getsize(flv)))
            forwarding = s["forwarding"]
            if forwarding:
                fwd_target = forwarding["target_url"]
                fwd_pid = forwarding["ffmpeg_pid"]
                fwd_duration = _humanize_duration(now - forwarding["started_at"])
            else:
                fwd_target = None
                fwd_pid = None
                fwd_duration = None
            rows.append({
                "publishing_name": publishing,
                "username": session_user or "-",
                "peer": s["peer"],
                "connected_for": connected_for,
                "flv_size": flv_size_str,
                "forwarding_target": fwd_target,
                "forwarding_pid": fwd_pid,
                "forwarding_duration": fwd_duration,
            })
        rows.sort(key=lambda r: r["publishing_name"])
        return await render_template_string(
            DASHBOARD_TEMPLATE,
            server_title=server_title,
            sessions=rows,
            generated_at=now.strftime("%Y-%m-%d %H:%M:%S"),
            page="dashboard",
            is_admin=viewer["is_admin"],
            viewer_name=viewer["viewer_name"],
        )

    @app.get("/recordings")
    @_require_sso_session
    async def recordings():
        now = datetime.datetime.now()
        viewer = _viewer_context()
        only = None if viewer["is_admin"] else viewer["username"]
        users = _ui_recording_users(recorder_storage_path, only)
        return await render_template_string(
            RECORDINGS_TEMPLATE,
            server_title=server_title,
            users=users,
            root_path=recorder_storage_path,
            generated_at=now.strftime("%Y-%m-%d %H:%M:%S"),
            page="recordings",
            is_admin=viewer["is_admin"],
            viewer_name=viewer["viewer_name"],
        )

    @app.get("/api/sessions")
    @_require_api_key
    async def api_sessions():
        now = datetime.datetime.now()
        sessions = [_session_to_json(s, now) for s in session_registry.snapshot()]
        return jsonify({
            "sessions": sessions,
            "count": len(sessions),
            "generated_at": now.isoformat(),
        })

    @app.get("/api/sessions/<int:session_id>")
    @_require_api_key
    async def api_session_detail(session_id: int):
        s = session_registry.get(session_id)
        if s is None:
            return jsonify({"error": "session not found", "session_id": session_id}), 404
        return jsonify({"session": _session_to_json(s, datetime.datetime.now())})

    @app.post("/api/sessions/<int:session_id>/disconnect")
    @_require_api_key
    async def api_session_disconnect(session_id: int):
        ok = session_registry.force_disconnect(session_id)
        if not ok:
            return jsonify({"error": "session not found", "session_id": session_id}), 404
        logger.info(f"Admin API force-disconnected session {session_id}")
        return jsonify({"disconnected": True, "session_id": session_id})

    @app.get("/api/recordings")
    @_require_api_key
    async def api_recordings():
        now = datetime.datetime.now()
        users = list_recorder_files(recorder_storage_path)
        out = []
        for u in users:
            out.append({
                "username": u["username"],
                "file_count": u["file_count"],
                "total_size_bytes": u["total_size"],
                "files": [
                    {
                        "name": f["name"],
                        "size_bytes": f["size"],
                        "modified_at": datetime.datetime.fromtimestamp(f["mtime"]).isoformat(),
                    }
                    for f in u["files"]
                ],
            })
        return jsonify({
            "users": out,
            "root_path": recorder_storage_path,
            "generated_at": now.isoformat(),
        })

    @app.get("/api/me/recordings")
    async def api_my_recordings():
        provided = request.headers.get("X-API-Key", "") or request.args.get("api_key", "")
        username = await get_username_from_api_key(provided)
        if not username:
            return jsonify({"error": "incorrect API key"}), 401
        files = list_user_recording_files(recorder_storage_path, username)
        used_bytes = sum(int(f.get("size") or 0) for f in files)
        local_names = {f["name"] for f in files}
        listing_user_id = await lookup_user_id(username) or 0
        held_names = await filenames_held_for_user(listing_user_id)
        rerun_names = await filenames_in_reruns(listing_user_id)
        payload_files = []
        probe_budget = DURATION_BACKFILL_PER_REQUEST
        for f in files:
            expires_unix = int(f["mtime"]) + RECORDING_RETENTION_SECONDS
            twitch_id = None
            fpath = os.path.join(recorder_storage_path, username, f["name"])
            meta = read_media_meta(fpath)
            title = str(meta.get("title") or "").strip() or None
            tm = re.match(r"^twitch-([0-9]{1,20})\.mp4(?:\.part)?$", f["name"], re.I)
            if tm:
                twitch_id = tm.group(1)
            duration = meta.get("duration_seconds")
            if duration is None and probe_budget > 0 and not f["is_partial"] and f["name"].lower().endswith(".mp4"):
                if _schedule_duration_probe(fpath, meta):
                    probe_budget -= 1
            payload_files.append({
                "name": f["name"],
                "title": title,
                "duration_seconds": int(duration) if isinstance(duration, (int, float)) else None,
                "size_bytes": f["size"],
                "modified_at": datetime.datetime.fromtimestamp(f["mtime"]).isoformat(),
                "is_partial": f["is_partial"],
                "storage": "local",
                "can_extend": (not f["is_partial"]) and f["name"].lower().endswith(".mp4"),
                "download_url": vod_cdn_url(username, f["name"], title or ""),
                "expires_at": datetime.datetime.fromtimestamp(expires_unix).isoformat(),
                "expires_at_unix": expires_unix,
                "twitch_video_id": twitch_id,
                "upload_hold": f["name"] in held_names,
                "rerun_hold": f["name"] in rerun_names,
            })
        for row in await list_extended_vods(username, rerun_names):
            name = str(row.get("filename") or "")
            if not name or name in local_names:
                continue
            expires = row.get("expires_at")
            if hasattr(expires, "timestamp"):
                expires_unix = int(expires.timestamp())
                expires_iso = expires.isoformat()
            else:
                expires_iso = str(expires or "")
                try:
                    expires_unix = int(datetime.datetime.fromisoformat(expires_iso).timestamp())
                except Exception:
                    expires_unix = 0
            twitch_id = None
            title = None
            tm = re.match(r"^twitch-([0-9]{1,20})\.mp4$", name, re.I)
            if tm:
                twitch_id = tm.group(1)
                title = _vod_title_from_sidecar(os.path.join(recorder_storage_path, username), twitch_id) or None
            ext_duration = row.get("duration_seconds")
            payload_files.append({
                "name": name,
                "title": title,
                "duration_seconds": int(ext_duration) if ext_duration is not None else None,
                "size_bytes": 0,
                "modified_at": expires_iso,
                "is_partial": False,
                "storage": "s4",
                "can_extend": False,
                "download_url": vod_cdn_url(username, name, title or ""),
                "expires_at": expires_iso,
                "expires_at_unix": expires_unix,
                "twitch_video_id": twitch_id,
                "rerun_hold": name in rerun_names,
            })
        slot = await get_storage_slot(username)
        quota_bytes = STREAM_STORAGE_QUOTA_BYTES if slot is None else int(slot.get("quota_bytes") or 0)
        unlimited = quota_bytes == 0
        pulls = [
            job for job in await _twitch_pulls_for_listing(username)
            if job["status"] == "pulling" or job["filename"] not in local_names
        ]
        return jsonify({
            "username": username,
            "used_bytes": used_bytes,
            "quota_bytes": quota_bytes,
            "quota_unlimited": unlimited,
            "files": payload_files,
            "pulls": pulls,
            "restores": _restores_for_user(username),
        })

    @app.post("/api/me/recordings/titles")
    async def api_save_vod_titles():
        provided = request.headers.get("X-API-Key", "") or request.args.get("api_key", "")
        username = await get_username_from_api_key(provided)
        if not username:
            return jsonify({"error": "incorrect API key"}), 401
        body = await request.get_json(silent=True) or {}
        items = body.get("items") if isinstance(body, dict) else None
        if not isinstance(items, list):
            return jsonify({"error": "invalid items"}), 400
        user_dir = os.path.join(recorder_storage_path, username)
        os.makedirs(user_dir, exist_ok=True)
        saved = 0
        for item in items:
            if not isinstance(item, dict):
                continue
            vod_id = str(item.get("vod_id") or "").strip()
            title = str(item.get("title") or "").strip()
            if not re.match(r"^[0-9]{1,20}$", vod_id) or not title:
                continue
            update_media_meta(os.path.join(user_dir, f"twitch-{vod_id}.mp4"), vod_id=vod_id, title=title)
            saved += 1
        return jsonify({"ok": True, "saved": saved})

    @app.post("/api/me/recordings/extend")
    async def api_extend_recording():
        provided = request.headers.get("X-API-Key", "") or request.args.get("api_key", "")
        username = await get_username_from_api_key(provided)
        if not username:
            return jsonify({"error": "incorrect API key"}), 401
        body = await request.get_json(silent=True) or {}
        fname = (body.get("name") or request.args.get("name") or "").strip()
        if not _safe_recording_name(fname) or not fname.lower().endswith(".mp4") or fname.endswith(".part"):
            return jsonify({"error": "invalid file name"}), 400
        path = os.path.join(recorder_storage_path, username, fname)
        if not os.path.isfile(path):
            return jsonify({"error": "file not found"}), 404
        age = time.time() - os.path.getmtime(path)
        if age < 15 or find_ffmpeg_pid_for_path(path):
            return jsonify({"error": "recording still in progress"}), 409
        user_id = await lookup_user_id(username)
        if not user_id:
            return jsonify({"error": "user_not_found"}), 404
        from vod_s4 import extend_until, upload_vod, delete_vod

        try:
            s4_key = await asyncio.to_thread(upload_vod, path, username, fname)
        except Exception as e:
            logger.error(f"VOD extend upload failed for {username}/{fname}: {e}")
            return jsonify({"error": "upload_failed"}), 502
        expires = extend_until()
        # The local sidecar goes away with the file, so keep the length on the extension row.
        ext_duration = read_media_meta(path).get("duration_seconds")
        if ext_duration is None:
            ext_duration = await asyncio.to_thread(probe_duration_seconds, path)
        try:
            await save_extended_vod(user_id, username, fname, s4_key, expires, ext_duration)
        except Exception as e:
            logger.error(f"VOD extend DB failed for {username}: {e}")
            try:
                await asyncio.to_thread(delete_vod, s4_key)
            except Exception:
                pass
            return jsonify({"error": "db_failed"}), 500
        tm = re.match(r"^twitch-([0-9]{1,20})", fname, re.I)
        title = _vod_title_from_sidecar(os.path.dirname(path), tm.group(1)) if tm else ""
        try:
            remove_media_and_sidecars(path)
        except OSError as e:
            logger.warning(f"Could not remove local VOD after extend {path}: {e}")
        return jsonify({
            "ok": True,
            "filename": fname,
            "storage": "s4",
            "expires_at": expires.isoformat(),
            "download_url": vod_cdn_url(username, fname, title),
        })

    @app.post("/api/me/recordings/delete")
    async def api_delete_recording():
        provided = request.headers.get("X-API-Key", "") or request.args.get("api_key", "")
        username = await get_username_from_api_key(provided)
        if not username:
            return jsonify({"error": "incorrect API key"}), 401
        body = await request.get_json(silent=True) or {}
        fname = (body.get("name") or request.args.get("name") or "").strip()
        if not _safe_recording_name(fname) or not fname.lower().endswith(".mp4") or fname.endswith(".part"):
            return jsonify({"error": "invalid file name"}), 400
        path = os.path.join(recorder_storage_path, username, fname)
        local = os.path.isfile(path)
        if local and find_ffmpeg_pid_for_path(path):
            return jsonify({"error": "recording still in progress"}), 409
        if local and (time.time() - os.path.getmtime(path) < 15):
            return jsonify({"error": "recording still in progress"}), 409
        user_id = await lookup_user_id(username)
        if user_id and await recording_youtube_busy(user_id, fname):
            return jsonify({"error": "youtube upload in progress"}), 409
        extended = await get_extended_vod(username, fname)
        if not local and not extended:
            return jsonify({"error": "file not found"}), 404
        if local:
            try:
                remove_media_and_sidecars(path)
            except OSError as e:
                logger.error(f"Could not delete local VOD {path}: {e}")
                return jsonify({"error": "delete_failed"}), 500
        if extended and extended.get("s4_key"):
            from vod_s4 import delete_vod

            try:
                await asyncio.to_thread(delete_vod, extended["s4_key"])
            except Exception as e:
                logger.warning(f"S4 VOD delete failed for {fname}: {e}")
            try:
                await delete_extended_vod_row(username, fname)
            except Exception as e:
                logger.warning(f"vod_extensions delete failed for {fname}: {e}")
        logger.info(f"Deleted recording {username}/{fname}")
        return jsonify({"ok": True, "filename": fname})

    @app.post("/api/me/recordings/pull-twitch")
    async def api_pull_twitch_vod():
        provided = request.headers.get("X-API-Key", "") or request.args.get("api_key", "")
        username = await get_username_from_api_key(provided)
        if not username:
            return jsonify({"error": "incorrect API key"}), 401
        body = await request.get_json(silent=True) or {}
        vod_id = str(body.get("vod_id") or request.args.get("vod_id") or "").strip()
        if not re.match(r"^[0-9]{1,20}$", vod_id):
            return jsonify({"error": "invalid_vod_id"}), 400
        slot = await get_storage_slot(username)
        quota = STREAM_STORAGE_QUOTA_BYTES if slot is None else int(slot.get("quota_bytes") or 0)
        user_dir = os.path.join(recorder_storage_path, username)
        used = directory_size_bytes(user_dir)
        if quota > 0 and used >= quota:
            return jsonify({"error": "stream_storage_full"}), 507
        dest = os.path.join(user_dir, f"twitch-{vod_id}.mp4")
        title = str(body.get("title") or "").strip()
        user_id = await lookup_user_id(username)
        if not user_id:
            return jsonify({"error": "user_not_found"}), 404
        result = await _request_twitch_pull(user_id, username, vod_id, title, dest)
        if result == "stored":
            return jsonify({"ok": True, "already": True, "status": "stored", "filename": os.path.basename(dest), "vod_id": vod_id}), 200
        if result == "failed":
            return jsonify({"error": "could_not_start", "vod_id": vod_id}), 502
        return jsonify({
            "ok": True,
            "status": "pulling",
            "filename": os.path.basename(dest),
            "vod_id": vod_id,
        }), 202

    @app.post("/api/me/recordings/restore-s3")
    async def api_restore_s3_vod():
        # Copy one object from the user's bucket back into the local library so it can be sent to YouTube.
        provided = request.headers.get("X-API-Key", "") or request.args.get("api_key", "")
        username = await get_username_from_api_key(provided)
        if not username:
            return jsonify({"error": "incorrect API key"}), 401
        body = await request.get_json(silent=True) or {}
        object_key = str(body.get("object_key") or "").strip()
        if not _safe_object_key(object_key):
            return jsonify({"error": "invalid_object_key"}), 400
        user_id = await lookup_user_id(username)
        if not user_id:
            return jsonify({"error": "user_not_found"}), 404
        job = await _s3_done_job(user_id, object_key)
        if not job:
            return jsonify({"error": "not_found"}), 404
        filename = str(job.get("filename") or "")
        if not _safe_recording_name(filename) or not filename.lower().endswith(".mp4"):
            return jsonify({"error": "invalid file name"}), 400
        settings = await _s3_settings_for_user(user_id)
        if not settings:
            return jsonify({"error": "s3_not_connected"}), 400
        user_dir = os.path.join(recorder_storage_path, username)
        dest = os.path.join(user_dir, filename)
        if os.path.isfile(dest):
            if find_ffmpeg_pid_for_path(dest):
                return jsonify({"error": "recording still in progress"}), 409
            return jsonify({"ok": True, "already": True, "status": "stored", "filename": filename})
        state_key = _restore_state_key(username, filename)
        existing = _s3_restore_tasks.get(state_key)
        if existing and not existing.done():
            state = _s3_restores.get(state_key) or {}
            return jsonify({
                "ok": True,
                "status": "copying",
                "filename": filename,
                "percent": state.get("percent") or 0,
            }), 202
        try:
            size, missing = await asyncio.to_thread(_s3_object_size, settings, object_key)
        except Exception as e:
            logger.error(f"S3 restore head failed for {username}: {type(e).__name__}")
            return jsonify({"error": "s3_unavailable"}), 502
        if missing:
            return jsonify({"error": "not_found"}), 404
        slot = await get_storage_slot(username)
        quota = STREAM_STORAGE_QUOTA_BYTES if slot is None else int(slot.get("quota_bytes") or 0)
        used = directory_size_bytes(user_dir)
        if quota > 0 and size > 0 and used + size > quota:
            return jsonify({"error": "stream_storage_full"}), 507
        os.makedirs(user_dir, exist_ok=True)
        state = {
            "object_key": object_key,
            "filename": filename,
            "status": "copying",
            "percent": 0,
            "bytes": 0,
            "bytes_total": size,
            "dest": dest,
        }
        _s3_restores[state_key] = state
        task = asyncio.create_task(_restore_s3_file(
            state_key, username, filename, object_key, settings, dest,
            str(job.get("title") or ""), str(job.get("twitch_video_id") or ""),
        ))
        _s3_restore_tasks[state_key] = task
        return jsonify({"ok": True, "status": "copying", "filename": filename, "percent": 0}), 202

    @app.get("/api/me/recordings/file")
    async def api_my_recording_file():
        provided = request.headers.get("X-API-Key", "") or request.args.get("api_key", "")
        username = await get_username_from_api_key(provided)
        if not username:
            return jsonify({"error": "incorrect API key"}), 401
        fname = (request.args.get("name") or "").strip()
        if not _safe_recording_name(fname) or not fname.lower().endswith(".mp4"):
            return jsonify({"error": "invalid file name"}), 400
        if fname.endswith(".part"):
            return jsonify({"error": "file not available"}), 400
        path = os.path.join(recorder_storage_path, username, fname)
        if not os.path.isfile(path):
            return jsonify({"error": "file not found"}), 404
        return await send_file(
            path,
            as_attachment=True,
            download_name=download_mp4_name(path, fname),
            mimetype="video/mp4",
        )

    @app.get("/api/server")
    @_require_api_key
    async def api_server():
        now = datetime.datetime.now()
        uptime = now - SERVER_START_TIME
        return jsonify({
            "region": region,
            "region_display_name": server_title,
            "hostname": socket.gethostname(),
            "rtmps_port": RTMPS_PORT,
            "active_sessions": len(session_registry.snapshot()),
            "started_at": SERVER_START_TIME.isoformat(),
            "uptime_seconds": int(uptime.total_seconds()),
            "ffmpeg_version": FFMPEG_VERSION,
            "python_version": sys.version.split()[0],
            "generated_at": now.isoformat(),
        })

    # SSO consumer: verify a handoff token minted by home/sso.php and
    # create the Quart session cookie for this region's host.
    @app.get("/sso/login")
    async def sso_login():
        token = request.args.get("handoff", "")
        return_path = request.args.get("return", "/")
        row = await _verify_and_consume_handoff(token)
        if row is None:
            # Invalid / expired / wrong target / replayed. Send the user back
            # through SSO to mint a fresh token.
            return redirect(
                f"{SSO_AUTHORITY_URL}?target={sso_target}&return={quote(return_path, safe='')}"
            )
        session.clear()
        session.permanent = True
        session["twitchUserId"]  = row["twitch_user_id"]
        session["username"]      = row["username"]
        session["display_name"]  = row["display_name"]
        session["profile_image"] = row["profile_image"]
        session["is_admin"]      = bool(row["is_admin"])
        session["signed_in_at"]  = datetime.datetime.now().isoformat()
        # Drop the user back where they were trying to go. Only honour purely
        # local paths - anything else falls back to "/".
        safe_return = "/"
        if isinstance(return_path, str) and return_path.startswith("/") and not return_path.startswith("//"):
            safe_return = return_path
        return redirect(safe_return)

    @app.get("/logout")
    async def logout():
        session.clear()
        return redirect(DASHBOARD_HOME_URL)

    return app

async def _serve_rtmp(server: SimpleServer) -> None:
    await server.start()
    await server.wait_closed()

async def _serve_web(
    app: Quart,
    host: str,
    port: int,
    certfile: str,
    keyfile: str,
    https_port: int = DEFAULT_HTTPS_PORT,
) -> None:
    from hypercorn.asyncio import serve
    from hypercorn.config import Config

    cfg = Config()
    cfg.certfile = certfile
    cfg.keyfile = keyfile

    # HTTPS is served on https_port (default 443)
    cfg.bind = [f"{host}:{int(https_port)}"]

    # HTTP redirect on port (default 80)
    insecure = []
    if int(port) != int(https_port):
        insecure.append(f"{host}:{int(port)}")

    # Also bind legacy 8080 to redirect callers to HTTPS, if free
    if 8080 not in (int(port), int(https_port)):
        probe = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        try:
            probe.bind((host if host not in ("0.0.0.0", "") else "0.0.0.0", 8080))
            probe.close()
            insecure.append(f"{host}:8080")
        except OSError:
            probe.close()

    cfg.insecure_bind = insecure
    logger.info(f"HTTPS bind(s): {', '.join(cfg.bind)}")
    if cfg.insecure_bind:
        logger.info(f"HTTP redirect bind(s): {', '.join(cfg.insecure_bind)}")
    await serve(app, cfg)

async def start_rtmp_server(
    twitch_server: str,
    web_host: str,
    web_port: int,
    recorder_storage_path: str,
    https_port: int = DEFAULT_HTTPS_PORT,
) -> None:
    global FFMPEG_VERSION
    # Determine output directory based on server location
    files_root = (os.getenv("STREAM_FILES_ROOT") or "").strip()
    if files_root:
        output_directory = files_root
    elif twitch_server in ("us-west", "us-east", "eu-central"):
        output_directory = "/mnt/s3/bots-stream"
    else:
        output_directory = os.path.dirname(os.path.abspath(__file__))
    FFMPEG_VERSION = await _detect_ffmpeg_version()
    logger.info(f"Detected ffmpeg: {FFMPEG_VERSION}")
    logger.info(
        f"Stream storage: root={output_directory} "
        f"max_slots={STREAM_STORAGE_MAX_SLOTS} "
        f"default_quota_bytes={STREAM_STORAGE_QUOTA_BYTES}"
    )
    ssl_context = create_ssl_context(twitch_server)
    domain, cert_path, key_path = resolve_cert_paths(twitch_server)
    session_registry = SessionRegistry()
    server = SimpleServer(
        output_directory=output_directory,
        twitch_server=twitch_server,
        session_registry=session_registry,
    )
    await server.create(host=RTMPS_HOST, port=RTMPS_PORT, ssl_context=ssl_context)
    logger.info(f"RTMPS server started on {RTMPS_HOST}:{RTMPS_PORT} with SSL for domain: {domain}")
    logger.info(f"Using Twitch ingest server location: {twitch_server}")
    server_title = SERVER_DISPLAY_NAMES.get(twitch_server, f"RTMP Server - {twitch_server}")
    web_app = create_web_app(server_title, twitch_server, session_registry, recorder_storage_path)
    ui_url = f"https://{domain}/" if https_port == 443 else f"https://{domain}:{https_port}/"
    logger.info(f"Operator web UI: {ui_url} (HTTP port {web_port} redirects to HTTPS)")
    logger.info(f"API docs: https://{domain}/docs")
    logger.info(f"Recordings page reading from: {recorder_storage_path}")
    await asyncio.gather(
        _serve_rtmp(server),
        _serve_web(web_app, web_host, web_port, cert_path, key_path, https_port),
        _expired_vod_loop(recorder_storage_path),
        _resume_twitch_pulls(recorder_storage_path),
    )

if __name__ == "__main__":
    try:
        asyncio.run(
            start_rtmp_server(
                server_location,
                args.web_host,
                args.web_port,
                args.recorder_path,
                getattr(args, "https_port", DEFAULT_HTTPS_PORT),
            )
        )
    except KeyboardInterrupt:
        logger.info("Server shutdown gracefully due to CTRL+C")