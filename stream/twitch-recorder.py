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
import aiohttp
import time
import subprocess
import datetime
import random
import logging
from logging.handlers import RotatingFileHandler
import threading
import asyncio
import argparse
from typing import Tuple, Optional, Dict, Any, List
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
STORAGE_ROOT_PATH = os.getenv('STREAM_ROOT_PATH', '/mnt/blockstorage')
FILE_RETENTION_SECONDS = int(os.getenv('RECORDING_RETENTION_SECONDS', '86400'))
CHECK_INTERVAL_MIN = 15
RETRY_BASE = 2
RETRY_JITTER = 0.01
LIVE_FROM_START_UNSUPPORTED_TEXT = "no formats that can be downloaded from the start"

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
    return "".join(x for x in filename if x.isalnum() or x in [" ", "-", "_", "."])

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
        start_time = datetime.datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')
        base_filename = f"{self.username} - {start_time} UTC - {game}"
        base_filename = sanitize_filename(base_filename)
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
        self.logger = logging.getLogger("RecordChecker")
        self.running = False
        self.last_enabled_users = set()

    async def start_checker(self):
        self.logger.info("RecordChecker starting up...")
        # Perform initial database checks
        checks_passed = await self.perform_initial_checks()
        if not checks_passed:
            self.logger.error("Initial checks failed, cannot start recorder")
            return False
        self.running = True
        # Start the continuous checking loop
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

    async def check_channels_loop(self):
        self.logger.info("Starting channel checking loop...")
        while self.running:
            try:
                # Get all users with auto_record enabled for this server
                users_with_auto_record = await self.mysql_manager.get_users_with_auto_record()
                current_enabled_users = set(users_with_auto_record)
                newly_enabled = sorted(current_enabled_users - self.last_enabled_users)
                newly_disabled = sorted(self.last_enabled_users - current_enabled_users)
                if newly_enabled:
                    self.logger.info(f"Auto-record enabled: {', '.join(newly_enabled)}")
                if newly_disabled:
                    self.logger.info(f"Auto-record disabled: {', '.join(newly_disabled)}")
                self.last_enabled_users = current_enabled_users
                # Stop recordings for users who disabled auto_record
                await self.stop_disabled_recordings(users_with_auto_record)
                # For each enabled user, check if they're live and start/continue recording
                for username in users_with_auto_record:
                    await self.check_and_record_user(username)
                # Clean up finished recordings
                await self.cleanup_finished_recordings()
                # Delete old files from server storage
                await self.cleanup_old_files()
            except Exception as e:
                self.logger.error(f"Error in check_channels_loop: {e}")
            # Wait before next check
            await asyncio.sleep(60)  # Check every 60 seconds

    async def cleanup_old_files(self):
        cutoff_timestamp = time.time() - self.file_retention_seconds
        removed_count = 0
        if os.path.isdir(self.root_path):
            for current_root, _, filenames in os.walk(self.root_path):
                for filename in filenames:
                    file_path = os.path.join(current_root, filename)
                    try:
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
        for username in users_to_stop:
            self.logger.info(f"User {username} disabled auto_record, stopping recording")
            await self.stop_recording_for_user(username)

    async def check_and_record_user(self, username):
        try:
            self.logger.debug(f"Checking stream status for {username}")
            is_live, stream_info = await get_internal_stream_status(username, self.logger)
            if is_live is None:
                self.logger.warning(f"Could not determine stream status for {username}")
                return
            if is_live:
                # Start recording if not already recording
                if username not in self.active_recordings:
                    self.logger.info(f"Starting new recording for {username}")
                    await self.start_recording_for_user(username, stream_info)
                else:
                    self.logger.debug(f"Already recording {username}")
            else:
                self.logger.debug(f"{username} is offline")
                # Stop recording if currently recording
                if username in self.active_recordings:
                    self.logger.info(f"User {username} went offline, stopping recording")
                    await self.stop_recording_for_user(username)
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

    async def start_recording_for_user(self, username, stream_info):
        try:
            game = stream_info.get('game_name') if stream_info else None
            if not game:
                game = 'Unknown Game'
            start_time = datetime.datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')
            base_filename = f"{username} - {start_time} UTC - {game}"
            base_filename = sanitize_filename(base_filename)
            # Create directories
            user_storage_path = os.path.join(self.root_path, username)
            os.makedirs(user_storage_path, exist_ok=True)
            self.logger.info(f"Created/verified storage path: {user_storage_path}")
            output_prefix = os.path.join(user_storage_path, base_filename)
            output_template = f"{output_prefix}.%(ext)s"
            # Start recording process
            url = f"https://www.twitch.tv/{username}"
            cmd = self.select_recording_command(username, url, output_template)
            self.logger.info(f"Recording command for {username}: {' '.join(cmd)}")
            # Redirect yt-dlp output to a per-recording log file to prevent pipe buffer
            # deadlock (stdout/stderr=PIPE with no reader blocks yt-dlp after ~64KB)
            ytdlp_log_path = os.path.join(user_storage_path, f"{base_filename}.ytdlp.log")
            ytdlp_log_file = open(ytdlp_log_path, 'w', encoding='utf-8')
            process = subprocess.Popen(cmd, stdout=ytdlp_log_file, stderr=ytdlp_log_file)
            self.active_recordings[username] = {
                'process': process,
                'log_file': ytdlp_log_file,
                'filename': None,
                'output_prefix': output_prefix,
                'output_template': output_template,
                'user_storage_path': user_storage_path,
                'start_time': datetime.datetime.now()
            }
            self.logger.info(f"Started recording for {username}: {base_filename}")
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
                # Give yt-dlp time to finish naturally before sending SIGTERM — it may
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

    def stop_checker(self):
        self.logger.info("Stopping RecordChecker...")
        self.running = False
        # Stop all active recordings
        for username in list(self.active_recordings.keys()):
            self.logger.info(f"Stopping recording for {username}")
            try:
                recording_info = self.active_recordings[username]
                process = recording_info['process']
                log_file = recording_info.get('log_file')
                output_prefix = recording_info.get('output_prefix', '')
                process.terminate()
                try:
                    process.wait(timeout=30)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()
                if log_file:
                    try:
                        log_file.close()
                    except Exception:
                        pass
                if output_prefix:
                    self._rename_part_files(username, output_prefix)
            except Exception as e:
                self.logger.error(f"Error stopping recording for {username}: {e}")
        self.active_recordings.clear()
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