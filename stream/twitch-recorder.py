"""
Twitch Auto-Recorder Script

This script provides an automated Twitch stream recording system with two main components:

1. RecordChecker: An async coordinator that checks the database for users who have
   enabled auto-recording and manages the recording processes accordingly.

2. TwitchRecorderThread: The actual recording implementation that handles individual
   stream recording using yt-dlp, including live status checking and file management.

Architecture:
- RecordChecker runs asynchronously and monitors multiple users on a configurable interval
- Each active recorder is managed as a subprocess via yt-dlp
- Database-driven configuration allows real-time enabling/disabling of recording
  (per-user auto_record_settings table in each user's individual database)
- Live status is determined via the internal BotOfTheSpecter stream API
- Uses aiohttp for all HTTP/API requests and aiomysql for async database access

Recording:
- Output files are named: [channel] - [YYYY-MM-DD HH:MM:SS] UTC - [game name]
- Files are stored under STREAM_ROOT_PATH/<username>/
- yt-dlp is used with --hls-use-mpegts and --live-from-start (with automatic fallback)
- Old recordings are automatically cleaned up after FILE_RETENTION_SECONDS

Logging:
- Logs are written to a fixed file: logs/twitch-recorder.log
- Log file is rotated at 10MB with 5 backup files retained

Status: UNDER DEVELOPMENT
Note: This script is under active development. Testing has not yet begun on the
production server and functionality may change during implementation.
"""

import os
import re
import shutil
import unicodedata
import aiohttp
import time
import subprocess
import datetime
from zoneinfo import ZoneInfo
from ffmpeg_jobs import (
    AttachedProcess,
    atomic_write_json,
    cmdline_has,
    is_sidecar_name,
    load_json,
    pid_alive,
    remove_media_and_sidecars,
    sidecar_paths_for_media,
)
import random
import logging
from logging.handlers import RotatingFileHandler
import threading
import asyncio
import argparse
from typing import Tuple, Optional, Dict, Any, List
from urllib.parse import urljoin
import aiomysql
from dotenv import load_dotenv

# Load environment variables from .env file
load_dotenv()

# Environment variables
SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER')
SQL_PASSWORD = os.getenv('SQL_PASSWORD')
ADMIN_KEY = os.getenv('ADMIN_KEY')
INTERNAL_STREAM_API_URL = os.getenv('INTERNAL_STREAM_API_URL', 'https://api.botofthespecter.com/v2/streamonline')
YT_DLP_COOKIES_FILE = os.getenv('YT_DLP_COOKIES_FILE', 'twitch-cookies.txt')
YT_DLP_LIVE_FROM_START = os.getenv('YT_DLP_LIVE_FROM_START', 'true').lower() in ('1', 'true', 'yes', 'on')
STORAGE_ROOT_PATH = os.getenv('STREAM_FILES_ROOT') or os.getenv('STREAM_ROOT_PATH') or '/var/lib/specter-stream'
FILE_RETENTION_SECONDS = int(os.getenv('RECORDING_RETENTION_SECONDS', '86400'))
STREAM_STORAGE_QUOTA_BYTES = int(os.getenv('STREAM_STORAGE_QUOTA_BYTES') or str(100 * 1024 * 1024 * 1024))
MIN_DISK_FREE_BYTES = int(os.getenv('STREAM_MIN_DISK_FREE_BYTES') or str(20 * 1024 * 1024 * 1024))
TWITCH_WEB_CLIENT_ID = os.getenv('TWITCH_WEB_CLIENT_ID', 'kimne78kx3ncx6brgo4mv6wki5h1ko')
TWITCH_GQL = 'https://gql.twitch.tv/gql'
TWITCH_LIVE_USHER = 'https://usher.ttvnw.net/api/channel/hls/{login}.m3u8'
LIVE_PLAYBACK_QUERY = """
query StreamAccess($login: String!, $playerType: String!) {
  user(login: $login) {
    stream {
      id
      title
      game {
        name
      }
      playbackAccessToken(params: {platform: "web", playerBackend: "mediaplayer", playerType: $playerType}) {
        value
        signature
      }
    }
  }
}
"""
WINDOWS_RESERVED = {
    "CON", "PRN", "AUX", "NUL",
    *(f"COM{i}" for i in range(1, 10)),
    *(f"LPT{i}" for i in range(1, 10)),
}
CHECK_INTERVAL_MIN = 15
CHECK_INTERVAL = max(2, int(os.getenv("RECORDING_CHECK_INTERVAL", "5")))
LIVE_CACHE_TTL = max(5, int(os.getenv("RECORDING_LIVE_CACHE_SECONDS", "20")))
RETRY_BASE = 2
RETRY_JITTER = 0.01
LIVE_FROM_START_UNSUPPORTED_TEXT = "no formats that can be downloaded from the start"

# RTMP ingest endpoints for supported forwarding services.
# {stream_key} is replaced at runtime with the user's configured key.
RTMP_ENDPOINTS: Dict[str, str] = {
    'youtube': 'rtmp://a.rtmp.youtube.com/live2/{stream_key}',
    'kick':    'rtmps://fa723fc1b171.global-contribute.live-video.net/app/{stream_key}',
    'trovo':   'rtmp://livepush.trovo.live/live/{stream_key}',
}


def resolve_forward_url(service: str, stream_key: str) -> Optional[str]:
    key = (stream_key or "").strip()
    if not key:
        return None
    if key.lower().startswith(("rtmp://", "rtmps://")):
        return key
    template = RTMP_ENDPOINTS.get(service)
    if not template:
        return None
    return template.format(stream_key=key)

# Custom formatter that stamps log entries in Sydney, Australia time
class SydneyFormatter(logging.Formatter):
    _TZ = datetime.timezone(datetime.timedelta(hours=10))  # AEST base offset

    def _sydney_offset(self, dt: datetime.datetime) -> datetime.timezone:
        # AEDT (UTC+11) last Sun Oct -> first Sun Apr; AEST (UTC+10) otherwise
        year = dt.year
        # Last Sunday in October (DST start)
        oct_last_sun = max(
            d for d in (datetime.date(year, 10, d) for d in range(25, 32))
            if d.weekday() == 6
        )
        # First Sunday in April (DST end)
        apr_first_sun = min(
            d for d in (datetime.date(year, 4, d) for d in range(1, 8))
            if d.weekday() == 6
        )
        dt_date = dt.date()
        if oct_last_sun <= dt_date or dt_date < apr_first_sun:
            return datetime.timezone(datetime.timedelta(hours=11))  # AEDT
        return datetime.timezone(datetime.timedelta(hours=10))  # AEST

    def formatTime(self, record, datefmt=None):
        utc_dt = datetime.datetime.fromtimestamp(record.created, tz=datetime.timezone.utc)
        tz = self._sydney_offset(utc_dt)
        local_dt = utc_dt.astimezone(tz)
        if datefmt:
            return local_dt.strftime(datefmt)
        return local_dt.strftime('%Y-%m-%d %H:%M:%S')

# Setup logging with both file and console output
def setup_logging():
    # Create logs directory if it doesn't exist
    log_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'logs')
    os.makedirs(log_dir, exist_ok=True)
    # Fixed log filename (no date) so admin log viewer always finds the same file
    log_filename = os.path.join(log_dir, 'twitch-recorder.log')
    # Create formatter (timestamps in Sydney, Australia time)
    formatter = SydneyFormatter(
        '%(asctime)s [%(levelname)s] %(name)s: %(message)s',
        datefmt='%Y-%m-%d %H:%M:%S'
    )
    # Setup root logger
    root_logger = logging.getLogger()
    root_logger.setLevel(logging.INFO)
    # Remove any existing handlers
    root_logger.handlers.clear()
    # Console handler
    console_handler = logging.StreamHandler()
    console_handler.setLevel(logging.INFO)
    console_handler.setFormatter(formatter)
    root_logger.addHandler(console_handler)
    # File handler with rotation (max 10MB per file, keep 5 backup files)
    file_handler = RotatingFileHandler(
        log_filename,
        maxBytes=10*1024*1024,  # 10MB
        backupCount=5,
        encoding='utf-8'
    )
    file_handler.setLevel(logging.INFO)
    file_handler.setFormatter(formatter)
    root_logger.addHandler(file_handler)
    logging.info(f"Logging initialized. Log file: {log_filename}")
    return log_filename

# Initialize logging
setup_logging()

def sanitize_filename(filename: str) -> str:
    name = unicodedata.normalize("NFC", filename or "")
    name = re.sub(r'[\x00-\x1f\x7f<>:"/\\|?*]', "-", name)
    name = name.replace("\n", " ").replace("\r", " ").replace("\t", " ")
    name = re.sub(r"\s+", " ", name).strip().strip(".")
    if name.upper() in WINDOWS_RESERVED:
        name = name + "-stream"
    if len(name) > 180:
        name = name[:180].rstrip(" .")
    return name or "stream"


def unique_recording_basename(directory: str, base: str) -> str:
    candidate = base
    n = 2
    while True:
        mp4 = os.path.join(directory, candidate + ".mp4")
        part = mp4 + ".part"
        if not os.path.exists(mp4) and not os.path.exists(part):
            return candidate
        candidate = f"{base} ({n})"
        n += 1


def stream_meta_from_gql(stream: dict) -> dict:
    title = (stream.get("title") or "").strip() or "Untitled"
    game = stream.get("game") if isinstance(stream.get("game"), dict) else {}
    game_name = (game.get("name") or "").strip() or "Unknown Game"
    return {"title": title, "game_name": game_name}


def directory_size_bytes(path: str) -> int:
    total = 0
    if not os.path.isdir(path):
        return 0
    for root, _dirs, files in os.walk(path):
        for name in files:
            if name.endswith((".ffmpeg.log", ".ytdlp.log", ".fwd.log")):
                continue
            try:
                total += os.path.getsize(os.path.join(root, name))
            except OSError:
                continue
    return total


def _pick_hls_variant(master_text: str) -> Optional[str]:
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
        match = re.search(r"BANDWIDTH=(\d+)", line)
        if match:
            bw = int(match.group(1))
        if 'VIDEO="chunked"' in line or 'NAME="chunked"' in line:
            chunked_url = url
        if bw > best_bw:
            best_bw = bw
            best_url = url
    return chunked_url or best_url


async def twitch_live_hls(session: aiohttp.ClientSession, login: str):
    """Anonymous GQL playback token + Usher HLS. No website auth-token cookie."""
    headers = {
        "Client-ID": TWITCH_WEB_CLIENT_ID,
        "Content-Type": "application/json",
        "User-Agent": "Mozilla/5.0",
    }
    payload = {
        "query": LIVE_PLAYBACK_QUERY,
        "variables": {"login": login, "playerType": "site"},
    }
    async with session.post(TWITCH_GQL, headers=headers, json=payload) as resp:
        try:
            body = await resp.json(content_type=None)
        except Exception:
            return None, None, f"gql_http_{resp.status}", None
        if resp.status != 200:
            return None, None, f"gql_http_{resp.status}", None
    data = body.get("data") if isinstance(body, dict) else None
    user = data.get("user") if isinstance(data, dict) else None
    stream = user.get("stream") if isinstance(user, dict) else None
    if not stream:
        return False, None, None, None
    meta = stream_meta_from_gql(stream)
    token = stream.get("playbackAccessToken") if isinstance(stream, dict) else None
    value = token.get("value") if isinstance(token, dict) else None
    signature = token.get("signature") if isinstance(token, dict) else None
    if not value or not signature:
        return True, None, "gql_no_token", meta
    params = {
        "player": "twitchweb",
        "allow_source": "true",
        "allow_audio_only": "false",
        "allow_spectre": "false",
        "playlist_include_framerate": "true",
        "sig": signature,
        "token": value,
    }
    usher = TWITCH_LIVE_USHER.format(login=login)
    async with session.get(usher, params=params, headers={"User-Agent": "Mozilla/5.0"}) as resp:
        playlist = await resp.text()
        playlist_url = str(resp.url)
        if resp.status != 200 or not playlist:
            return True, None, f"usher_http_{resp.status}", meta
    if "#EXT-X-STREAM-INF" in playlist:
        variant = _pick_hls_variant(playlist)
        if not variant:
            return True, None, "no_hls_variant", meta
        if not variant.startswith("http"):
            variant = urljoin(playlist_url, variant)
        return True, variant, None, meta
    if "#EXTINF" in playlist:
        return True, playlist_url, None, meta
    return True, None, "unrecognised_playlist", meta

def resolve_cookies_path(cookies_path: str) -> Optional[str]:
    if not cookies_path:
        return None
    if os.path.isabs(cookies_path):
        return cookies_path if os.path.exists(cookies_path) else None
    candidate_paths = [
        os.path.join(os.getcwd(), cookies_path),
        os.path.join(os.path.dirname(os.path.abspath(__file__)), cookies_path),
        os.path.join('/home/botofthespecter', cookies_path),
    ]
    for candidate in candidate_paths:
        if os.path.exists(candidate):
            return candidate
    return None

def build_yt_dlp_command(url: str, output_template: str, live_from_start: Optional[bool] = None) -> List[str]:
    command = [
        "yt-dlp",
        "--hls-use-mpegts",
        "--retries", "infinite",
        "--fragment-retries", "infinite",
    ]
    use_live_from_start = YT_DLP_LIVE_FROM_START if live_from_start is None else live_from_start
    if use_live_from_start:
        command.append("--live-from-start")
    else:
        command.append("--no-live-from-start")
    cookies_path = YT_DLP_COOKIES_FILE
    resolved_cookies_path = resolve_cookies_path(cookies_path)
    if resolved_cookies_path:
        command.extend(["--cookies", resolved_cookies_path])
    command.extend(["-o", output_template, url])
    return command

async def get_internal_stream_status(channel_name: str, logger: logging.Logger) -> Tuple[Optional[bool], Optional[Dict[str, Any]]]:
    if not ADMIN_KEY:
        logger.error("ADMIN_KEY is missing; cannot call internal stream status API")
        return None, None
    headers = {
        'accept': 'application/json',
        'X-API-KEY': ADMIN_KEY,
    }
    params = {
        'channel': channel_name,
    }
    for attempt in range(1, 4):
        try:
            timeout = aiohttp.ClientTimeout(total=15)
            async with aiohttp.ClientSession(timeout=timeout) as session:
                async with session.get(INTERNAL_STREAM_API_URL, headers=headers, params=params) as response:
                    if response.status in (401, 403):
                        logger.error(f"Internal API authentication failed for channel {channel_name}")
                        return None, None
                    if response.status == 404:
                        logger.warning(f"Internal API could not find channel {channel_name}")
                        return False, None
                    response.raise_for_status()
                    payload = await response.json(content_type=None)
                    return bool(payload.get('online', False)), payload
        except aiohttp.ClientResponseError as e:
            logger.error(f"Internal API HTTP error for {channel_name}: {e}")
            return None, None
        except (aiohttp.ClientConnectorError, aiohttp.ClientError, asyncio.TimeoutError):
            await asyncio.sleep(RETRY_BASE ** attempt + random.random() * RETRY_JITTER)
    logger.error(f"Internal API retries exhausted for channel {channel_name}")
    return None, None

class MySQLManager:
    def __init__(self, server_location=None, logger=None):
        self.logger = logger or logging.getLogger("MySQLManager")

    async def get_connection(self, database_name='website'):
        try:
            db_host = SQL_HOST
            db_user = SQL_USER
            db_password = SQL_PASSWORD
            conn = await aiomysql.connect(
                host=db_host,
                user=db_user,
                password=db_password,
                db=database_name,
                port=3306,
                autocommit=True
            )
            return conn
        except Exception as e:
            return None

    async def get_users_with_auto_record(self) -> List[str]:
        try:
            # First, get all usernames from the website database
            conn = await self.get_connection('website')
            if not conn:
                return []
            async with conn.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT username FROM users WHERE username IS NOT NULL AND username != ''")
                user_rows = await cursor.fetchall()
                usernames = [row['username'] for row in user_rows]
            conn.close()
            # Now check each user's individual database for auto_record settings
            users_to_record = []
            for username in usernames:
                try:
                    user_conn = await self.get_connection(username)
                    if not user_conn:
                        self.logger.debug(f"Could not connect to database for user: {username}")
                        continue
                    async with user_conn.cursor(aiomysql.DictCursor) as cursor:
                        # Check if auto_record_settings table has enabled recording
                        query = """
                        SELECT enabled
                        FROM auto_record_settings 
                        WHERE enabled = 1
                        LIMIT 1
                        """
                        await cursor.execute(query)
                        result = await cursor.fetchone()
                        if result:
                            users_to_record.append(username)
                            self.logger.debug(f"User {username} has auto_record enabled")
                    user_conn.close()
                except Exception as e:
                    self.logger.debug(f"Error checking auto_record for user {username}: {e}")
                    continue
            return users_to_record
        except Exception as e:
            self.logger.error(f"Error getting users with auto_record: {e}")
            return []

    async def get_profile_timezone(self, channel_name: str) -> str:
        conn = None
        try:
            conn = await self.get_connection(channel_name)
            if not conn:
                return "UTC"
            async with conn.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT timezone FROM profile LIMIT 1")
                row = await cursor.fetchone()
            tz = (row.get("timezone") if row else None) or ""
            tz = str(tz).strip()
            return tz or "UTC"
        except Exception:
            return "UTC"
        finally:
            if conn:
                conn.close()

    async def should_auto_record(self, channel_name: str) -> bool:
        try:
            # Connect to the user's specific database
            conn = await self.get_connection(channel_name)
            if not conn:
                return False
            async with conn.cursor(aiomysql.DictCursor) as cursor:
                query = """
                SELECT enabled
                FROM auto_record_settings 
                WHERE enabled = 1
                LIMIT 1
                """
                await cursor.execute(query)
                result = await cursor.fetchone()
                return bool(result)
        except Exception as e:
            self.logger.error(f"Error checking auto_record for {channel_name}: {e}")
            return False
        finally:
            if 'conn' in locals() and conn:
                conn.close()

    async def get_forwarding_settings(self, channel_name: str) -> List[Dict[str, Any]]:
        try:
            conn = await self.get_connection(channel_name)
            if not conn:
                return []
            async with conn.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    "SELECT service, stream_key FROM stream_forward_settings "
                    "WHERE enabled = 1 AND stream_key IS NOT NULL AND stream_key != ''"
                )
                results = await cursor.fetchall()
                # Only return services whose keys are present in our known endpoints
                return [r for r in results if r['service'] in RTMP_ENDPOINTS]
        except Exception as e:
            self.logger.debug(f"Error getting forwarding settings for {channel_name}: {e}")
            return []
        finally:
            if 'conn' in locals() and conn:
                conn.close()

    async def get_storage_quota(self, username: str) -> Optional[int]:
        conn = await self.get_connection('website')
        if not conn:
            return None
        try:
            async with conn.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    """
                    SELECT s.quota_bytes, s.bonus_bytes
                    FROM users u
                    JOIN stream_storage_slots s ON s.user_id = u.id
                    WHERE u.username = %s
                    LIMIT 1
                    """,
                    (username,),
                )
                row = await cursor.fetchone()
            if not row:
                return STREAM_STORAGE_QUOTA_BYTES
            raw_quota = row.get("quota_bytes")
            quota = STREAM_STORAGE_QUOTA_BYTES if raw_quota is None else int(raw_quota)
            bonus = int(row.get("bonus_bytes") or 0)
            if quota == 0:
                return 0
            return quota + bonus
        except Exception as e:
            self.logger.error(f"Error getting storage quota for {username}: {e}")
            return None
        finally:
            conn.close()

    async def get_users_to_monitor(self) -> List[str]:
        try:
            conn = await self.get_connection('website')
            if not conn:
                return []
            async with conn.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    "SELECT username FROM users WHERE username IS NOT NULL AND username != ''"
                )
                user_rows = await cursor.fetchall()
                usernames = [row['username'] for row in user_rows]
            conn.close()
            users_to_monitor = []
            for username in usernames:
                try:
                    user_conn = await self.get_connection(username)
                    if not user_conn:
                        continue
                    async with user_conn.cursor(aiomysql.DictCursor) as cursor:
                        await cursor.execute(
                            "SELECT enabled FROM auto_record_settings WHERE enabled = 1 LIMIT 1"
                        )
                        record_result = await cursor.fetchone()
                        await cursor.execute(
                            "SELECT 1 FROM stream_forward_settings "
                            "WHERE enabled = 1 AND stream_key IS NOT NULL AND stream_key != '' LIMIT 1"
                        )
                        forward_result = await cursor.fetchone()
                        if record_result or forward_result:
                            users_to_monitor.append(username)
                    user_conn.close()
                except Exception:
                    continue
            return users_to_monitor
        except Exception as e:
            self.logger.error(f"Error getting users to monitor: {e}")
            return []

class TwitchRecorderThread(threading.Thread):
    def __init__(self, username, mysql_manager, root_path="", refresh=10.0):
        super().__init__()
        self.username = username
        self.mysql_manager = mysql_manager
        self.root_path = root_path
        self.refresh = max(refresh, CHECK_INTERVAL_MIN)
        self.quality = "best"
        self.user_storage_path = os.path.join(self.root_path, self.username)
        os.makedirs(self.user_storage_path, exist_ok=True)
        self._stop_event = threading.Event()
        self.access_token = None
        self.system_access_token = None

    def stop(self):
        self._stop_event.set()

    def stopped(self):
        return self._stop_event.is_set()

    async def check_user(self) -> Tuple[int, Optional[Dict[str, Any]]]:
        is_live, info = await get_internal_stream_status(self.username, logging.getLogger("TwitchRecorderThread"))
        if is_live is None:
            return 3, None
        if is_live:
            return 0, info
        return 1, info

    def record_stream(self, info: Dict[str, Any]):
        game = info.get('game_name') if info else None
        if not game:
            game = "Unknown Game"
        start_time = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H-%M")
        base_filename = sanitize_filename(f"{start_time} - Untitled - {game}")
        output_prefix = os.path.join(self.user_storage_path, base_filename)
        output_template = f"{output_prefix}.%(ext)s"
        try:
            url = f"https://www.twitch.tv/{self.username}"
            command = build_yt_dlp_command(url, output_template, live_from_start=True)
            logging.info(f"Starting recording for {self.username}")
            logging.info(f"Command: {' '.join(command)}")
            logging.info(f"Output file: {output_template}")
            result = subprocess.run(command, capture_output=True, text=True)
            if result.returncode != 0:
                combined_output = f"{result.stdout}\n{result.stderr}".lower()
                logging.error(f"yt-dlp failed with return code {result.returncode}")
                logging.error(f"STDOUT: {result.stdout}")
                logging.error(f"STDERR: {result.stderr}")
                if "no formats that can be downloaded from the start" in combined_output:
                    logging.warning(
                        f"{self.username} does not support live-from-start; retrying from current live edge"
                    )
                    fallback_command = build_yt_dlp_command(url, output_template, live_from_start=False)
                    logging.info(f"Fallback command: {' '.join(fallback_command)}")
                    fallback_result = subprocess.run(fallback_command, capture_output=True, text=True)
                    if fallback_result.returncode != 0:
                        error_text = (fallback_result.stderr or fallback_result.stdout or "Unknown yt-dlp error").strip()
                        logging.error(f"yt-dlp fallback failed for {self.username}: {error_text}")
                        return
                    else:
                        logging.info(f"Fallback recording successful for {self.username}")
                else:
                    error_text = (result.stderr or result.stdout or "Unknown yt-dlp error").strip()
                    logging.error(f"yt-dlp failed for {self.username}: {error_text}")
                    return
            else:
                logging.info(f"Recording completed successfully for {self.username}")
                logging.info(f"STDOUT: {result.stdout}")
            logging.info("Recording stream is done.")
        except Exception as e:
            logging.error(f"Error recording stream for {self.username}: {e}", exc_info=True)

    def run(self):
        logging.info(f"Checking for {self.username} every {self.refresh} seconds. Record with {self.quality} quality.")
        while not self.stopped():
            try:
                status, info = asyncio.run(self.check_user())
                if status == 2:
                    logging.warning("Username not found. Invalid username or typo.")
                elif status == 3:
                    logging.warning(f"Unexpected error. Will try again in {self.refresh} seconds.")
                elif status == 1:
                    logging.info(f"{self.username} currently offline, checking again in {self.refresh} seconds.")
                elif status == 0:
                    logging.info(f"{self.username} online. Stream recording in session.")
                    self.record_stream(info)
                    logging.info("Recording ended. Going back to checking..")
                time.sleep(self.refresh)
            except Exception as e:
                logging.error(f"Error in loopcheck: {e}")
                time.sleep(self.refresh)

class RecordChecker:
    def __init__(self, root_path=""):
        self.root_path = root_path or STORAGE_ROOT_PATH
        self.file_retention_seconds = FILE_RETENTION_SECONDS
        self.mysql_manager = MySQLManager(logger=logging.getLogger("RecordChecker"))
        self.active_recordings = {}  # username: subprocess or recording info
        self.forwarding_processes: Dict[str, Dict[str, Any]] = {}  # username -> {service: {procs, log}}
        self.logger = logging.getLogger("RecordChecker")
        self.running = False
        self.last_enabled_users = set()
        self._live_cache = {}
        self._last_live = {}
        self._cleanup_tick = 0
        self._jobs_path = os.path.join(self.root_path, "_jobs", "recordings.json")

    def _disk_has_room(self) -> bool:
        try:
            return shutil.disk_usage(self.root_path).free >= MIN_DISK_FREE_BYTES
        except OSError:
            return False

    async def _user_has_quota_room(self, username: str) -> bool:
        quota = await self.mysql_manager.get_storage_quota(username)
        if quota == 0:
            return True
        used = directory_size_bytes(os.path.join(self.root_path, username))
        return used < quota

    async def start_checker(self):
        self.logger.info("RecordChecker starting up...")
        # Perform initial database checks
        checks_passed = await self.perform_initial_checks()
        if not checks_passed:
            self.logger.error("Initial checks failed, cannot start recorder")
            return False
        self.running = True
        self._recover_ffmpeg_jobs()
        await self.check_channels_loop()
        return True

    async def perform_initial_checks(self):
        self.logger.info("Performing initial database checks...")
        try:
            # Test database connection to website database
            conn = await self.mysql_manager.get_connection('website')
            if not conn:
                self.logger.error("Failed to connect to website database during startup")
                return False
            self.logger.info("Website database connection successful")
            # Check if required tables exist and have expected columns in website database
            async with conn.cursor(aiomysql.DictCursor) as cursor:
                # Check users table structure
                await cursor.execute("DESCRIBE users")
                columns = await cursor.fetchall()
                column_names = [col['Field'] for col in columns]
                required_columns = ['username']
                missing_columns = [col for col in required_columns if col not in column_names]
                if missing_columns:
                    self.logger.warning(f"Missing required columns in users table: {missing_columns}")
                else:
                    self.logger.info("Website users table structure verified")
                # Check how many users exist
                await cursor.execute("SELECT COUNT(*) as count FROM users WHERE username IS NOT NULL AND username != ''")
                result = await cursor.fetchone()
            conn.close()
            # Test getting users with auto_record enabled for this server
            await self.mysql_manager.get_users_with_auto_record()
            # Check system requirements
            await self.check_system_requirements()
            return True
        except Exception as e:
            self.logger.error(f"Error during initial checks: {e}")
            return False

    async def check_system_requirements(self):
        self.logger.info("Checking system requirements...")
        # Check if yt-dlp is installed
        try:
            result = subprocess.run(['yt-dlp', '--version'], capture_output=True, text=True, timeout=5)
            if result.returncode == 0:
                self.logger.info(f"yt-dlp found: {result.stdout.strip()}")
            else:
                self.logger.warning("yt-dlp not found or not working")
        except (subprocess.TimeoutExpired, FileNotFoundError):
            self.logger.warning("yt-dlp not found in PATH")
        # Check if ffmpeg is installed (required for stream forwarding)
        try:
            result = subprocess.run(['ffmpeg', '-version'], capture_output=True, text=True, timeout=5)
            if result.returncode == 0:
                self.logger.info(f"ffmpeg found: {result.stdout.splitlines()[0].strip()}")
            else:
                self.logger.warning("ffmpeg not found or not working - stream forwarding will be unavailable")
        except (subprocess.TimeoutExpired, FileNotFoundError):
            self.logger.warning("ffmpeg not found in PATH - stream forwarding will be unavailable")

    async def check_channels_loop(self):
        self.logger.info(f"Starting channel checking loop (every {CHECK_INTERVAL}s)...")
        while self.running:
            try:
                # Get all users with recording or forwarding enabled
                users_to_monitor = await self.mysql_manager.get_users_to_monitor()
                current_enabled_users = set(users_to_monitor)
                newly_enabled = sorted(current_enabled_users - self.last_enabled_users)
                newly_disabled = sorted(self.last_enabled_users - current_enabled_users)
                if newly_enabled:
                    self.logger.info(f"Now monitoring: {', '.join(newly_enabled)}")
                if newly_disabled:
                    self.logger.info(f"No longer monitoring: {', '.join(newly_disabled)}")
                self.last_enabled_users = current_enabled_users
                # Stop recordings/forwarding for users who disabled everything
                await self.stop_disabled_recordings(users_to_monitor)
                for username in users_to_monitor:
                    force_live = username in newly_enabled
                    await self.check_and_record_user(username, force_live=force_live)
                await self.cleanup_finished_recordings()
                await self.cleanup_finished_forwarders()
                self._cleanup_tick += 1
                cleanup_every = max(1, 60 // CHECK_INTERVAL)
                if FILE_RETENTION_SECONDS > 0 and self._cleanup_tick % cleanup_every == 0:
                    await self.cleanup_old_files()
            except Exception as e:
                self.logger.error(f"Error in check_channels_loop: {e}")
            await asyncio.sleep(CHECK_INTERVAL)

    async def cleanup_old_files(self):
        cutoff_timestamp = time.time() - self.file_retention_seconds
        removed_count = 0
        active_paths = {
            rec.get("filename")
            for rec in self.active_recordings.values()
            if rec.get("filename")
        }
        active_sidecars = set()
        for rec_path in list(active_paths):
            active_sidecars.update(sidecar_paths_for_media(rec_path))
        if os.path.isdir(self.root_path):
            for current_root, dirnames, filenames in os.walk(self.root_path):
                dirnames[:] = [d for d in dirnames if d != "_jobs"]
                if os.path.basename(current_root) == "_jobs":
                    continue
                for filename in filenames:
                    file_path = os.path.join(current_root, filename)
                    if file_path in active_paths or file_path in active_sidecars:
                        continue
                    try:
                        if filename.lower().endswith(".mp4"):
                            if os.path.getmtime(file_path) < cutoff_timestamp:
                                removed_count += remove_media_and_sidecars(file_path)
                            continue
                        if filename.endswith(".fwd.log"):
                            if os.path.getmtime(file_path) < cutoff_timestamp:
                                os.remove(file_path)
                                removed_count += 1
                            continue
                        if is_sidecar_name(filename):
                            if filename.endswith(".ffmpeg.log"):
                                media = file_path[: -len(".ffmpeg.log")] + ".mp4"
                            elif filename.endswith(".ytdlp.log"):
                                media = file_path[: -len(".ytdlp.log")] + ".mp4"
                            elif filename.endswith(".json"):
                                media = file_path[: -len(".json")] + ".mp4"
                            else:
                                media = None
                            if media and (os.path.isfile(media) or media in active_paths):
                                continue
                            os.remove(file_path)
                            removed_count += 1
                            continue
                        if os.path.getmtime(file_path) < cutoff_timestamp:
                            os.remove(file_path)
                            removed_count += 1
                    except FileNotFoundError:
                        continue
                    except Exception as e:
                        self.logger.error(f"Failed to remove old file {file_path}: {e}")
        if removed_count > 0:
            self.logger.info(f"Removed {removed_count} recording file(s) older than 24 hours")

    async def stop_disabled_recordings(self, enabled_users):
        users_to_stop = []
        for username in list(self.active_recordings.keys()):
            if username not in enabled_users:
                users_to_stop.append(username)
            elif not await self.mysql_manager.should_auto_record(username):
                users_to_stop.append(username)
        for username in users_to_stop:
            self.logger.info(f"User {username} disabled auto_record, stopping recording")
            await self.stop_recording_for_user(username)
        # Also stop forwarding for users that are no longer being monitored
        for username in list(self.forwarding_processes.keys()):
            if username not in enabled_users:
                self.logger.info(f"User {username} disabled, stopping all forwarding")
                await self.stop_forwarding_for_user(username)

    async def _cached_live(self, username, force=False):
        now = time.time()
        cached = self._live_cache.get(username)
        if not force and cached and (now - cached[0]) < LIVE_CACHE_TTL:
            return cached[1], cached[2], cached[3], cached[4] if len(cached) > 4 else {}
        timeout = aiohttp.ClientTimeout(total=30)
        async with aiohttp.ClientSession(timeout=timeout) as session:
            is_live, hls_url, live_err, meta = await twitch_live_hls(session, username)
        self._live_cache[username] = (now, is_live, hls_url, live_err, meta or {})
        return is_live, hls_url, live_err, meta or {}

    async def check_and_record_user(self, username, force_live=False):
        try:
            self.logger.debug(f"Checking stream status for {username}")
            is_live, hls_url, live_err, meta = await self._cached_live(username, force=force_live)
            stream_info = dict(meta or {})
            if hls_url:
                stream_info['hls_url'] = hls_url
            if is_live is None:
                self.logger.warning(f"Could not determine stream status for {username}: {live_err}")
                return
            if is_live and live_err:
                self.logger.warning(f"{username} is live but HLS failed: {live_err}")
            if is_live:
                # Recording — only start on offline→online so we never join mid-stream
                if username not in self.active_recordings:
                    should_record = await self.mysql_manager.should_auto_record(username)
                    went_live = self._last_live.get(username) is False
                    if should_record and went_live:
                        if not self._disk_has_room():
                            self.logger.warning(
                                f"Skipping {username}: host disk has less than "
                                f"{MIN_DISK_FREE_BYTES} bytes free"
                            )
                        elif not await self._user_has_quota_room(username):
                            self.logger.warning(
                                f"Skipping {username}: stream storage is at the 100GB cap"
                            )
                        else:
                            self.logger.info(f"Starting new recording for {username} (stream just went live)")
                            await self.start_recording_for_user(username, stream_info)
                    elif should_record and not went_live:
                        if self._last_live.get(username) is not True:
                            self.logger.info(
                                f"Not starting {username}: already live when recording was enabled; "
                                f"waiting for the next full stream"
                            )
                else:
                    if not await self._user_has_quota_room(username):
                        self.logger.warning(
                            f"Stopping {username}: recording hit the 100GB cap "
                            f"(a long 1080p60 session can be ~60GB on its own)"
                        )
                        await self.stop_recording_for_user(username)
                    else:
                        self.logger.debug(f"Already recording {username}")
                self._last_live[username] = True
                # Forwarding
                forward_settings = await self.mysql_manager.get_forwarding_settings(username)
                enabled_services = {fwd['service'] for fwd in forward_settings}
                # Stop services that have been disabled since last check
                user_forwarders = self.forwarding_processes.get(username, {})
                for running_service in list(user_forwarders.keys()):
                    if running_service not in enabled_services:
                        self.logger.info(f"Stopping {running_service} forwarding for {username} (disabled)")
                        await self.stop_forwarding_for_service(username, running_service)
                # Start services that aren't running yet
                for fwd in forward_settings:
                    service = fwd['service']
                    stream_key = fwd['stream_key']
                    if service not in self.forwarding_processes.get(username, {}):
                        await self.start_forwarding_for_service(
                            username, service, stream_key, hls_url=hls_url
                        )
            else:
                self.logger.debug(f"{username} is offline")
                self._last_live[username] = False
                # Stop recording if currently recording
                if username in self.active_recordings:
                    self.logger.info(f"User {username} went offline, stopping recording")
                    await self.stop_recording_for_user(username)
                # Stop all forwarding
                if username in self.forwarding_processes:
                    self.logger.info(f"User {username} went offline, stopping all forwarding")
                    await self.stop_forwarding_for_user(username)
        except Exception as e:
            self.logger.error(f"Error checking user {username}: {e}", exc_info=True)

    def select_recording_command(self, username: str, url: str, output_template: str) -> List[str]:
        if not YT_DLP_LIVE_FROM_START:
            return build_yt_dlp_command(url, output_template, live_from_start=False)
        preferred_command = build_yt_dlp_command(url, output_template, live_from_start=True)
        probe_command = preferred_command + ["--simulate"]
        try:
            probe_result = subprocess.run(probe_command, capture_output=True, text=True)
            if probe_result.returncode != 0:
                probe_output = f"{probe_result.stdout}\n{probe_result.stderr}".lower()
                if LIVE_FROM_START_UNSUPPORTED_TEXT in probe_output:
                    self.logger.warning(
                        f"{username} does not support live-from-start; using current live edge"
                    )
                    return build_yt_dlp_command(url, output_template, live_from_start=False)
        except Exception as e:
            self.logger.warning(f"Could not pre-check live-from-start for {username}: {e}")
        return preferred_command

    def _persist_ffmpeg_jobs(self) -> None:
        recordings = {}
        for username, info in self.active_recordings.items():
            process = info.get("process")
            pid = getattr(process, "pid", None)
            if not pid:
                continue
            recordings[username] = {
                "pid": int(pid),
                "filename": info.get("filename") or "",
                "output_prefix": info.get("output_prefix") or "",
                "user_storage_path": info.get("user_storage_path") or "",
            }
        forwarding = {}
        for username, services in self.forwarding_processes.items():
            row = {}
            for service, info in services.items():
                proc = info.get("ffmpeg_proc")
                pid = getattr(proc, "pid", None)
                if pid:
                    row[service] = {"pid": int(pid)}
            if row:
                forwarding[username] = row
        atomic_write_json(self._jobs_path, {"recordings": recordings, "forwarding": forwarding})

    def _recover_ffmpeg_jobs(self) -> None:
        data = load_json(self._jobs_path, {})
        recordings = data.get("recordings") if isinstance(data, dict) else {}
        if isinstance(recordings, dict):
            for username, info in recordings.items():
                if not isinstance(info, dict):
                    continue
                pid = int(info.get("pid") or 0)
                filename = info.get("filename") or ""
                if not pid_alive(pid) or not cmdline_has(pid, filename or os.path.basename(filename)):
                    self.logger.info(f"Dropping stale recording job for {username} (pid {pid})")
                    continue
                prefix = info.get("output_prefix") or os.path.splitext(filename)[0]
                storage = info.get("user_storage_path") or os.path.dirname(filename)
                log_path = prefix + ".ffmpeg.log"
                log_file = open(log_path, "a", encoding="utf-8") if os.path.isdir(os.path.dirname(log_path) or ".") else None
                self.active_recordings[username] = {
                    "process": AttachedProcess(pid),
                    "log_file": log_file,
                    "filename": filename,
                    "output_prefix": prefix,
                    "output_template": filename,
                    "user_storage_path": storage,
                    "start_time": datetime.datetime.now(),
                }
                self._last_live[username] = True
                self.logger.info(f"Reattached recording for {username} pid={pid}")
        forwarding = data.get("forwarding") if isinstance(data, dict) else {}
        if isinstance(forwarding, dict):
            for username, services in forwarding.items():
                if not isinstance(services, dict):
                    continue
                for service, info in services.items():
                    if not isinstance(info, dict):
                        continue
                    pid = int(info.get("pid") or 0)
                    if not pid_alive(pid) or not cmdline_has(pid, "ffmpeg"):
                        continue
                    if username not in self.forwarding_processes:
                        self.forwarding_processes[username] = {}
                    self.forwarding_processes[username][service] = {
                        "ffmpeg_proc": AttachedProcess(pid),
                        "log_file": None,
                    }
                    self._last_live[username] = True
                    self.logger.info(f"Reattached {service} forwarding for {username} pid={pid}")
        self._persist_ffmpeg_jobs()

    async def start_recording_for_user(self, username, stream_info):
        try:
            game = ((stream_info or {}).get("game_name") or "").strip() or "Unknown Game"
            title = ((stream_info or {}).get("title") or "").strip() or "Untitled"
            tz_name = await self.mysql_manager.get_profile_timezone(username)
            try:
                tz = ZoneInfo(tz_name)
            except Exception:
                tz = datetime.timezone.utc
                tz_name = "UTC"
            start_time = datetime.datetime.now(tz).strftime("%Y-%m-%d %H-%M")
            base_filename = sanitize_filename(f"{start_time} - {title} - {game}")
            user_storage_path = os.path.join(self.root_path, username)
            os.makedirs(user_storage_path, exist_ok=True)
            base_filename = unique_recording_basename(user_storage_path, base_filename)
            self.logger.info(f"Created/verified storage path: {user_storage_path} tz={tz_name}")
            output_prefix = os.path.join(user_storage_path, base_filename)
            output_path = f"{output_prefix}.mp4"
            hls_url = (stream_info or {}).get('hls_url')
            if not hls_url:
                timeout = aiohttp.ClientTimeout(total=30)
                async with aiohttp.ClientSession(timeout=timeout) as session:
                    _live, hls_url, hls_err, extra = await twitch_live_hls(session, username)
                if extra:
                    stream_info.update(extra)
                if not hls_url:
                    self.logger.error(f"No live HLS for {username}: {hls_err}")
                    return
            cmd = [
                "ffmpeg",
                "-hide_banner",
                "-loglevel", "warning",
                "-stats",
                "-user_agent", "Mozilla/5.0",
                "-reconnect", "1",
                "-reconnect_streamed", "1",
                "-reconnect_delay_max", "5",
                "-i", hls_url,
                "-c", "copy",
                "-bsf:a", "aac_adtstoasc",
                "-movflags", "frag_keyframe+empty_moov+default_base_moof",
                "-f", "mp4",
                output_path,
            ]
            rec_log_path = os.path.join(user_storage_path, f"{base_filename}.ffmpeg.log")
            rec_log_file = open(rec_log_path, 'a', encoding='utf-8')
            process = subprocess.Popen(
                cmd,
                stdout=rec_log_file,
                stderr=rec_log_file,
                start_new_session=True,
            )
            self.active_recordings[username] = {
                'process': process,
                'log_file': rec_log_file,
                'filename': output_path,
                'output_prefix': output_prefix,
                'output_template': output_path,
                'user_storage_path': user_storage_path,
                'start_time': datetime.datetime.now()
            }
            self._persist_ffmpeg_jobs()
            self.logger.info(f"Started recording for {username}: {base_filename}.mp4")
            self.logger.info(f"Process PID: {process.pid}")
        except Exception as e:
            self.logger.error(f"Error starting recording for {username}: {e}", exc_info=True)

    def _rename_part_files(self, username: str, output_prefix: str):
        directory = os.path.dirname(output_prefix)
        basename = os.path.basename(output_prefix)
        try:
            if not os.path.isdir(directory):
                return
            for filename in os.listdir(directory):
                if filename.startswith(basename) and filename.endswith('.part'):
                    part_path = os.path.join(directory, filename)
                    final_path = part_path[:-5]  # Strip .part suffix
                    if not os.path.exists(final_path):
                        os.rename(part_path, final_path)
                        self.logger.info(f"Renamed partial recording: {filename} -> {os.path.basename(final_path)}")
                    else:
                        self.logger.warning(f"Cannot rename {filename}: target file already exists")
        except Exception as e:
            self.logger.warning(f"Could not rename .part files for {username}: {e}")

    async def stop_recording_for_user(self, username):
        try:
            if username in self.active_recordings:
                recording_info = self.active_recordings[username]
                process = recording_info['process']
                log_file = recording_info.get('log_file')
                output_prefix = recording_info.get('output_prefix', '')
                # Give yt-dlp time to finish naturally before sending SIGTERM - it may
                # already be finalizing after detecting the HLS playlist ended
                if process.poll() is None:
                    process.terminate()
                    try:
                        process.wait(timeout=30)
                    except subprocess.TimeoutExpired:
                        self.logger.warning(f"yt-dlp did not exit gracefully for {username}, forcing kill")
                        process.kill()
                        process.wait()
                if log_file:
                    try:
                        log_file.close()
                    except Exception:
                        pass
                # Rename any .part files yt-dlp left behind
                if output_prefix:
                    self._rename_part_files(username, output_prefix)
                self.logger.info(f"Stopped recording for {username}")
                del self.active_recordings[username]
                self._persist_ffmpeg_jobs()
        except Exception as e:
            self.logger.error(f"Error stopping recording for {username}: {e}")

    async def cleanup_finished_recordings(self):
        finished_users = []
        for username, recording_info in self.active_recordings.items():
            process = recording_info['process']
            if process.poll() is not None:  # Process has finished
                return_code = process.returncode
                finished_users.append(username)
                self.logger.info(f"Recording process finished for {username} with return code {return_code}")
                log_file = recording_info.get('log_file')
                if log_file:
                    try:
                        log_file.close()
                    except Exception:
                        pass
                output_prefix = recording_info.get('output_prefix', '')
                if output_prefix:
                    self._rename_part_files(username, output_prefix)
        for username in finished_users:
            self.logger.info(f"Cleaning up recording for {username}")
            del self.active_recordings[username]
        if finished_users:
            self._persist_ffmpeg_jobs()

    async def start_forwarding_for_service(
        self, username: str, service: str, stream_key: str, hls_url: Optional[str] = None
    ):
        """Live restream: Twitch HLS → YouTube / Kick / Trovo. Does not write the 100GB recording."""
        log_file = None
        try:
            rtmp_url = resolve_forward_url(service, stream_key)
            if not rtmp_url:
                self.logger.error(f"No RTMP URL for {username} ({service})")
                return
            user_storage_path = os.path.join(self.root_path, username)
            os.makedirs(user_storage_path, exist_ok=True)
            log_path = os.path.join(user_storage_path, f"{username}-fwd-{service}.fwd.log")
            log_file = open(log_path, 'a', encoding='utf-8')
            if not hls_url:
                timeout = aiohttp.ClientTimeout(total=30)
                async with aiohttp.ClientSession(timeout=timeout) as session:
                    _live, hls_url, hls_err, _meta = await twitch_live_hls(session, username)
                if not hls_url:
                    self.logger.error(f"Could not resolve live HLS for {username} ({service}): {hls_err}")
                    log_file.close()
                    return
            ffmpeg_cmd = [
                "ffmpeg",
                "-hide_banner",
                "-loglevel", "warning",
                "-user_agent", "Mozilla/5.0",
                "-reconnect", "1",
                "-reconnect_streamed", "1",
                "-reconnect_delay_max", "5",
                "-i", hls_url,
                "-c", "copy",
                "-bsf:a", "aac_adtstoasc",
                "-f", "flv",
                rtmp_url,
            ]
            self.logger.info(f"Starting {service} forwarding for {username}")
            ffmpeg_proc = subprocess.Popen(
                ffmpeg_cmd,
                stdout=log_file,
                stderr=log_file,
                start_new_session=True,
            )
            if username not in self.forwarding_processes:
                self.forwarding_processes[username] = {}
            self.forwarding_processes[username][service] = {
                'ffmpeg_proc': ffmpeg_proc,
                'log_file': log_file,
            }
            self.logger.info(
                f"Started {service} forwarding for {username} [ffmpeg PID={ffmpeg_proc.pid}]"
            )
            self._persist_ffmpeg_jobs()
        except Exception as e:
            if log_file:
                try:
                    log_file.close()
                except Exception:
                    pass
            self.logger.error(f"Error starting {service} forwarding for {username}: {e}", exc_info=True)

    async def stop_forwarding_for_service(self, username: str, service: str):
        if username not in self.forwarding_processes:
            return
        if service not in self.forwarding_processes[username]:
            return
        fwd_info = self.forwarding_processes[username][service]
        self.logger.info(f"Stopping {service} forwarding for {username}")
        try:
            ffmpeg_proc = fwd_info.get('ffmpeg_proc')
            log_file = fwd_info.get('log_file')
            if ffmpeg_proc and ffmpeg_proc.poll() is None:
                ffmpeg_proc.terminate()
                try:
                    ffmpeg_proc.wait(timeout=15)
                except subprocess.TimeoutExpired:
                    ffmpeg_proc.kill()
                    ffmpeg_proc.wait()
            if log_file:
                try:
                    log_file.close()
                except Exception:
                    pass
        except Exception as e:
            self.logger.error(f"Error stopping {service} forwarding for {username}: {e}")
        finally:
            del self.forwarding_processes[username][service]
            if not self.forwarding_processes[username]:
                del self.forwarding_processes[username]
            self._persist_ffmpeg_jobs()

    async def stop_forwarding_for_user(self, username: str):
        if username not in self.forwarding_processes:
            return
        for service in list(self.forwarding_processes[username].keys()):
            await self.stop_forwarding_for_service(username, service)

    async def cleanup_finished_forwarders(self):
        for username in list(self.forwarding_processes.keys()):
            for service in list(self.forwarding_processes[username].keys()):
                fwd_info = self.forwarding_processes[username][service]
                ffmpeg_proc = fwd_info.get('ffmpeg_proc')
                if ffmpeg_proc and ffmpeg_proc.poll() is not None:
                    rc = ffmpeg_proc.returncode
                    self.logger.info(
                        f"{service} forwarding ended for {username} (exit code {rc})"
                    )
                    log_file = fwd_info.get('log_file')
                    if log_file:
                        try:
                            log_file.close()
                        except Exception:
                            pass
                    del self.forwarding_processes[username][service]
            if username in self.forwarding_processes and not self.forwarding_processes[username]:
                del self.forwarding_processes[username]
        self._persist_ffmpeg_jobs()

    def stop_checker(self):
        self.logger.info("Stopping RecordChecker (leaving ffmpeg recordings/downloads running)")
        self.running = False
        self._persist_ffmpeg_jobs()
        for username in list(self.active_recordings.keys()):
            log_file = self.active_recordings[username].get("log_file")
            if log_file:
                try:
                    log_file.close()
                except Exception:
                    pass
        self.active_recordings.clear()
        for username in list(self.forwarding_processes.keys()):
            for fwd_info in self.forwarding_processes[username].values():
                log_file = fwd_info.get("log_file")
                if log_file:
                    try:
                        log_file.close()
                    except Exception:
                        pass
        self.forwarding_processes.clear()
        self.logger.info("RecordChecker stopped")

# Function to run the recorder
async def run_record_checker():
    # Parse command line arguments
    parser = argparse.ArgumentParser(description='Twitch Stream Auto-Recorder')
    parser.parse_args()
    checker = RecordChecker(root_path=STORAGE_ROOT_PATH)
    try:
        await checker.start_checker()
    except asyncio.CancelledError:
        logging.info("Recorder shutdown requested")
    except KeyboardInterrupt:
        logging.info("Recorder interrupted")
    finally:
        checker.stop_checker()

if __name__ == "__main__":
    try:
        asyncio.run(run_record_checker())
    except KeyboardInterrupt:
        logging.info("Recorder process stopped")