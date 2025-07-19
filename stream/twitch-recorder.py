"""
Twitch Auto-Recorder Script

This script provides an automated Twitch stream recording system with two main components:

1. RecordChecker: An async coordinator that checks the database for users who have 
   enabled auto-recording and manages the recording threads accordingly.

2. TwitchRecorderThread: The actual recording implementation that handles individual
   stream recording using streamlink, including live status checking and file management.

Architecture:
- RecordChecker runs asynchronously and efficiently monitors multiple users
- Each active recorder runs in its own TwitchRecorderThread
- Database-driven configuration allows real-time enabling/disabling of recording
- Supports OAuth token management with system fallback for API calls
- Uses modern aiohttp for all HTTP/API requests

Status: NEW SCRIPT - UNDER DEVELOPMENT
Note: This is a newly created script that is still under active development.
Testing has not yet begun, and functionality may change during implementation.
"""

import os
import aiohttp
import time
import subprocess
import datetime
import random
import urllib3
import socket
import logging
import threading
import asyncio
import argparse
from typing import Tuple, Optional, Dict, Any, List
from config import conf
from json import JSONDecodeError
import aiomysql
from dotenv import load_dotenv

# Load environment variables from .env file
load_dotenv()

# Environment variables
SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER')
SQL_PASSWORD = os.getenv('SQL_PASSWORD')
CLIENT_ID = os.getenv('CLIENT_ID')
CLIENT_SECRET = os.getenv('CLIENT_SECRET')
TWITCH_GQL = os.getenv('TWITCH_GQL')
CHECK_INTERVAL_MIN = 15
RETRY_BASE = 2
RETRY_JITTER = 0.01

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s %(levelname)s: %(message)s',
    datefmt='%Hh%Mm%Ss'
)

def sanitize_filename(filename: str) -> str:
    return "".join(x for x in filename if x.isalnum() or x in [" ", "-", "_", "."])

class MySQLManager:
    def __init__(self, server_location=None, logger=None):
        self.server_location = server_location
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
            self.logger.error(f"Failed to connect to database '{database_name}': {e}")
            return None

    async def get_users_with_auto_record(self) -> List[str]:
        if not self.server_location:
            self.logger.error("No server location specified")
            return []
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
            self.logger.info(f"Found {len(usernames)} total users in website database")
            # Now check each user's individual database for auto_record settings
            users_to_record = []
            for username in usernames:
                try:
                    user_conn = await self.get_connection(username)
                    if not user_conn:
                        self.logger.debug(f"Could not connect to database for user: {username}")
                        continue
                    async with user_conn.cursor(aiomysql.DictCursor) as cursor:
                        # Check if auto_record_settings table exists and has matching server + enabled
                        query = """
                        SELECT enabled, server_location 
                        FROM auto_record_settings 
                        WHERE enabled = 1 AND server_location = %s
                        LIMIT 1
                        """
                        await cursor.execute(query, (self.server_location,))
                        result = await cursor.fetchone()
                        if result:
                            users_to_record.append(username)
                            self.logger.debug(f"User {username} has auto_record enabled for server {self.server_location}")
                    user_conn.close()
                except Exception as e:
                    self.logger.debug(f"Error checking auto_record for user {username}: {e}")
                    continue
            self.logger.info(f"Found {len(users_to_record)} users with auto_record enabled for server {self.server_location}")
            return users_to_record
        except Exception as e:
            self.logger.error(f"Error getting users with auto_record: {e}")
            return []

    async def get_user_twitch_access_token(self, username: str) -> Optional[str]:
        try:
            # Connect to website database to get twitch_user_id
            conn = await self.get_connection('website')
            if not conn:
                self.logger.error(f"Could not connect to website database for user {username}")
                return None
            async with conn.cursor(aiomysql.DictCursor) as cursor:
                # Get twitch_user_id from users table
                await cursor.execute("SELECT twitch_user_id FROM users WHERE username = %s", (username,))
                user_result = await cursor.fetchone()
                if not user_result or not user_result.get('twitch_user_id'):
                    self.logger.debug(f"No twitch_user_id found for user {username}")
                    conn.close()
                    return None
                twitch_user_id = user_result['twitch_user_id']
                # Get access token from twitch_bot_access table
                await cursor.execute("SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = %s", (twitch_user_id,))
                token_result = await cursor.fetchone()
                if not token_result or not token_result.get('twitch_access_token'):
                    self.logger.debug(f"No twitch_access_token found for twitch_user_id {twitch_user_id}")
                    conn.close()
                    return None
                access_token = token_result['twitch_access_token']
                self.logger.debug(f"Retrieved access token for user {username}")
                conn.close()
                return access_token
        except Exception as e:
            self.logger.error(f"Error getting Twitch access token for {username}: {e}")
            if 'conn' in locals() and conn:
                conn.close()
            return None

    async def get_system_twitch_access_token(self) -> Optional[str]:
        try:
            conn = await self.get_connection('website')
            if not conn:
                self.logger.error("Could not connect to website database for system token")
                return None
            async with conn.cursor(aiomysql.DictCursor) as cursor:
                # Get system access token from twitch_bot_access table using system twitch_user_id
                await cursor.execute("SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = %s", ("971436498",))
                token_result = await cursor.fetchone()
                if not token_result or not token_result.get('twitch_access_token'):
                    self.logger.error("No system twitch_access_token found for twitch_user_id 971436498")
                    conn.close()
                    return None
                access_token = token_result['twitch_access_token']
                self.logger.debug("Retrieved system fallback access token")
                conn.close()
                return access_token
        except Exception as e:
            self.logger.error(f"Error getting system Twitch access token: {e}")
            if 'conn' in locals() and conn:
                conn.close()
            return None

    async def should_auto_record(self, channel_name: str) -> bool:
        try:
            # Connect to the user's specific database
            conn = await self.get_connection(channel_name)
            if not conn:
                return False
            async with conn.cursor(aiomysql.DictCursor) as cursor:
                query = """
                SELECT enabled, server_location 
                FROM auto_record_settings 
                WHERE enabled = 1 AND server_location = %s
                LIMIT 1
                """
                await cursor.execute(query, (self.server_location,))
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
        self.ffmpeg_path = "ffmpeg"
        self.recorded_path = os.path.join(self.root_path, "recorded", self.username, self.quality)
        self.processed_path = os.path.join(self.root_path, "processed", self.username, self.quality)
        os.makedirs(self.recorded_path, exist_ok=True)
        os.makedirs(self.processed_path, exist_ok=True)
        self._stop_event = threading.Event()
        self.access_token = None
        self.system_access_token = None

    def stop(self):
        self._stop_event.set()

    def stopped(self):
        return self._stop_event.is_set()

    async def check_user(self) -> Tuple[int, Optional[Dict[str, Any]]]:
        status = 3
        info = None
        # Get user's access token if we don't have it yet
        if not self.access_token:
            try:
                self.access_token = await self.mysql_manager.get_user_twitch_access_token(self.username)
            except Exception as e:
                logging.error(f"Error getting access token for {self.username}: {e}")
                return status, info
        # If no user token, try to get system token
        if not self.access_token:
            if not self.system_access_token:
                try:
                    self.system_access_token = await self.mysql_manager.get_system_twitch_access_token()
                except Exception as e:
                    logging.error(f"Error getting system access token: {e}")
                    return status, info
            if not self.system_access_token:
                logging.error(f"No valid access token found for {self.username} and no system fallback")
                return status, info
        # Try with user token first, fallback to system token on 401
        current_token = self.access_token if self.access_token else self.system_access_token
        token_type = "user" if self.access_token else "system"
        async def getinfo(try_number=1, use_system_fallback=False):
            # Use system token if fallback is requested or if we don't have user token
            token_to_use = self.system_access_token if use_system_fallback else current_token
            token_desc = "system" if use_system_fallback else token_type
            url = f'https://api.twitch.tv/helix/streams?user_login={self.username}'
            headers = {
                'client-id': CLIENT_ID,
                'Authorization': f'Bearer {token_to_use}',
                'Accept': 'application/json'
            }
            try:
                async with aiohttp.ClientSession() as session:
                    async with session.get(url, headers=headers) as response:
                        if response.status == 401 and not use_system_fallback and self.system_access_token:
                            logging.warning(f"User token invalid for {self.username}, trying system fallback token")
                            self.access_token = None  # Reset user token
                            return await getinfo(try_number, use_system_fallback=True)
                        
                        response.raise_for_status()
                        return await response.json()
            except aiohttp.ClientResponseError as e:
                logging.error(f"HTTP error with {token_desc} token: {e}")
                return None
            except (aiohttp.ClientConnectorError, aiohttp.ClientError, JSONDecodeError, 
                    urllib3.exceptions.MaxRetryError, urllib3.exceptions.NewConnectionError, socket.gaierror) as e:
                await asyncio.sleep(RETRY_BASE ** try_number + random.random() * RETRY_JITTER)
                return await getinfo(try_number=try_number + 1, use_system_fallback=use_system_fallback)
        info = await getinfo()
        if not info:
            return status, info
        if 'data' in info and info['data'] == []:
            status = 1
        elif 'data' in info and info['data'][0]['user_name'].lower() == self.username and info['data'][0]['type'] == "live":
            status = 0
        else:
            status = 2
        return status, info

    def fix_video(self, input_file: str, output_file: str):
        try:
            subprocess.call([self.ffmpeg_path, '-err_detect', 'ignore_err', '-i', input_file, '-c', 'copy', output_file])
            os.remove(input_file)
            logging.info(f"Fixed and moved: {output_file}")
        except Exception as e:
            logging.error(f"Error fixing video {input_file}: {e}")

    def process_previous_recordings(self):
        try:
            video_list = [f for f in os.listdir(self.recorded_path) if os.path.isfile(os.path.join(self.recorded_path, f))]
            if video_list:
                logging.info('Fixing previously recorded files.')
            for f in video_list:
                recorded_filename = os.path.join(self.recorded_path, f)
                processed_filename = os.path.join(self.processed_path, f)
                logging.info(f'Fixing {recorded_filename}.')
                self.fix_video(recorded_filename, processed_filename)
        except Exception as e:
            logging.error(f"Error processing previous recordings: {e}")

    def record_stream(self, info: Dict[str, Any]):
        title = info['data'][0]['title'] if 'data' in info and info['data'] else "Untitled"
        filename = f"{self.username} - {datetime.datetime.now().strftime('%Y-%m-%d %Hh%Mm%Ss')} - {title}.mp4"
        filename = sanitize_filename(filename)
        recorded_filename = os.path.join(self.recorded_path, filename)
        processed_filename = os.path.join(self.processed_path, filename)
        try:
            subprocess.call([
                "streamlink", "--twitch-disable-hosting", "--twitch-disable-ads",
                f"twitch.tv/{self.username}", self.quality, "-o", recorded_filename
            ])
            logging.info("Recording stream is done. Fixing video file.")
            if os.path.exists(recorded_filename):
                self.fix_video(recorded_filename, processed_filename)
            else:
                logging.warning("Skip fixing. File not found.")
        except Exception as e:
            logging.error(f"Error recording stream: {e}")

    def run(self):
        self.process_previous_recordings()
        logging.info(f"Checking for {self.username} every {self.refresh} seconds. Record with {self.quality} quality.")
        while not self.stopped():
            try:
                logging.info("Validating OAuth_Token ...")
                valid = conf.validate()
                if 'status' in valid and valid['status'] == 401 and valid['message'] == "invalid access token":
                    logging.info("OAuth_Token Invalid, Refreshing Token ...")
                    conf.refresh()
                    logging.info("OAuth_Token Refreshed!")
                status, info = asyncio.run(self.check_user())
                if status == 2:
                    logging.warning("Username not found. Invalid username or typo.")
                elif status == 3:
                    logging.warning(f"Unexpected error. Will try again in {self.refresh} seconds.")
                elif status == 1:
                    logging.info(f"{self.username} currently offline, checking again in {self.refresh} seconds.")
                elif status == 0:
                    logging.info(f"{self.username} online. Stream recording in session, using OAuth_Token: {conf.oauthtoken}")
                    self.record_stream(info)
                    logging.info("Fixing is done. Going back to checking..")
                time.sleep(self.refresh)
            except Exception as e:
                logging.error(f"Error in loopcheck: {e}")
                time.sleep(self.refresh)

class RecordChecker:
    def __init__(self, server_location, root_path=""):
        self.server_location = server_location
        self.root_path = root_path or os.path.join(os.getcwd(), "stream")
        self.mysql_manager = MySQLManager(server_location=server_location, logger=logging.getLogger("RecordChecker"))
        self.active_recordings = {}  # username: subprocess or recording info
        self.logger = logging.getLogger("RecordChecker")
        self.running = False

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
        self.logger.info(f"Server location: {self.server_location}")
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
                total_users = result['count'] if result else 0
                self.logger.info(f"Found {total_users} total users in website database")
            conn.close()
            # Test getting users with auto_record enabled for this server
            users_with_auto_record = await self.mysql_manager.get_users_with_auto_record()
            self.logger.info(f"Found {len(users_with_auto_record)} users with auto_record enabled for {self.server_location}")
            # Check system requirements
            await self.check_system_requirements()
            return True
        except Exception as e:
            self.logger.error(f"Error during initial checks: {e}")
            return False

    async def check_system_requirements(self):
        self.logger.info("Checking system requirements...")
        # Check if streamlink is installed
        try:
            result = subprocess.run(['streamlink', '--version'], capture_output=True, text=True, timeout=5)
            if result.returncode == 0:
                self.logger.info(f"Streamlink found: {result.stdout.strip()}")
            else:
                self.logger.warning("Streamlink not found or not working")
        except (subprocess.TimeoutExpired, FileNotFoundError):
            self.logger.warning("Streamlink not found in PATH")
        # Check if ffmpeg is installed
        try:
            result = subprocess.run(['ffmpeg', '-version'], capture_output=True, text=True, timeout=5)
            if result.returncode == 0:
                version_line = result.stdout.split('\n')[0]
                self.logger.info(f"FFmpeg found: {version_line}")
            else:
                self.logger.warning("FFmpeg not found or not working")
        except (subprocess.TimeoutExpired, FileNotFoundError):
            self.logger.warning("FFmpeg not found in PATH")

    async def check_channels_loop(self):
        self.logger.info("Starting channel checking loop...")
        
        while self.running:
            try:
                # Get all users with auto_record enabled for this server
                users_with_auto_record = await self.mysql_manager.get_users_with_auto_record()
                self.logger.debug(f"Found {len(users_with_auto_record)} users with auto_record enabled")
                # Stop recordings for users who disabled auto_record
                await self.stop_disabled_recordings(users_with_auto_record)
                # For each enabled user, check if they're live and start/continue recording
                for username in users_with_auto_record:
                    await self.check_and_record_user(username)
                # Clean up finished recordings
                await self.cleanup_finished_recordings()
            except Exception as e:
                self.logger.error(f"Error in check_channels_loop: {e}")
            # Wait before next check
            await asyncio.sleep(60)  # Check every 60 seconds

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
            # Get fresh token from database each time
            user_token = await self.mysql_manager.get_user_twitch_access_token(username)
            # Check if user is live
            is_live, stream_info = await self.check_user_live_status(username, user_token)
            if is_live:
                # Start recording if not already recording
                if username not in self.active_recordings:
                    await self.start_recording_for_user(username, stream_info)
                else:
                    self.logger.debug(f"Already recording {username}")
            else:
                # Stop recording if currently recording
                if username in self.active_recordings:
                    self.logger.info(f"User {username} went offline, stopping recording")
                    await self.stop_recording_for_user(username)
        except Exception as e:
            self.logger.error(f"Error checking user {username}: {e}")

    async def check_user_live_status(self, username, user_token):
        try:
            # Try with user token first
            if user_token:
                is_live, stream_info = await self.api_check_live(username, user_token, "user")
                if is_live is not None:  # API call succeeded (live or not live)
                    return is_live, stream_info
            # Fallback to system token - get fresh token from database each time
            system_token = await self.mysql_manager.get_system_twitch_access_token()
            if system_token:
                self.logger.debug(f"Using fresh system token for {username}")
                return await self.api_check_live(username, system_token, "system")
            else:
                self.logger.error(f"No valid system token available for {username}")
                return False, None
        except Exception as e:
            self.logger.error(f"Error checking live status for {username}: {e}")
            return False, None

    async def api_check_live(self, username, access_token, token_type):
        try:
            url = f'https://api.twitch.tv/helix/streams?user_login={username}'
            headers = {
                'client-id': CLIENT_ID,
                'Authorization': f'Bearer {access_token}',
                'Accept': 'application/json'
            }
            async with aiohttp.ClientSession() as session:
                async with session.get(url, headers=headers) as response:
                    if response.status == 401:
                        self.logger.warning(f"{token_type.capitalize()} token invalid for {username}")
                        return None, None  # Indicate token failure - will get fresh token on next check
                    response.raise_for_status()
                    data = await response.json()
                    if 'data' in data and data['data']:
                        stream_data = data['data'][0]
                        if stream_data['user_name'].lower() == username.lower() and stream_data['type'] == "live":
                            return True, stream_data
                    return False, None
        except aiohttp.ClientResponseError as e:
            self.logger.error(f"HTTP error checking {username} with {token_type} token: {e}")
            return None, None
        except Exception as e:
            self.logger.error(f"Error in API call for {username}: {e}")
            return None, None

    async def start_recording_for_user(self, username, stream_info):
        try:
            title = stream_info.get('title', 'Untitled') if stream_info else 'Untitled'
            filename = f"{username} - {datetime.datetime.now().strftime('%Y-%m-%d %Hh%Mm%Ss')} - {title}.mp4"
            filename = sanitize_filename(filename)
            # Create directories
            recorded_path = os.path.join(self.root_path, "recorded", username, "best")
            processed_path = os.path.join(self.root_path, "processed", username, "best")
            os.makedirs(recorded_path, exist_ok=True)
            os.makedirs(processed_path, exist_ok=True)
            recorded_filename = os.path.join(recorded_path, filename)
            # Start recording process
            cmd = [
                "streamlink", "--twitch-disable-hosting", "--twitch-disable-ads",
                f"twitch.tv/{username}", "best", "-o", recorded_filename
            ]
            process = subprocess.Popen(cmd)
            self.active_recordings[username] = {
                'process': process,
                'filename': recorded_filename,
                'processed_path': processed_path,
                'start_time': datetime.datetime.now()
            }
            self.logger.info(f"Started recording for {username}: {filename}")
        except Exception as e:
            self.logger.error(f"Error starting recording for {username}: {e}")

    async def stop_recording_for_user(self, username):
        try:
            if username in self.active_recordings:
                recording_info = self.active_recordings[username]
                process = recording_info['process']
                # Terminate the process
                process.terminate()
                # Wait a bit for graceful termination
                try:
                    process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()
                self.logger.info(f"Stopped recording for {username}")
                # Move to cleanup (will be processed in cleanup_finished_recordings)
                del self.active_recordings[username]
        except Exception as e:
            self.logger.error(f"Error stopping recording for {username}: {e}")

    async def cleanup_finished_recordings(self):
        finished_users = []
        for username, recording_info in self.active_recordings.items():
            process = recording_info['process']
            if process.poll() is not None:  # Process has finished
                finished_users.append(username)
        for username in finished_users:
            self.logger.info(f"Recording finished for {username}")
            recording_info = self.active_recordings[username]
            # Process the video file (fix it)
            await self.fix_video_file(recording_info)
            del self.active_recordings[username]

    async def fix_video_file(self, recording_info):
        try:
            recorded_filename = recording_info['filename']
            if os.path.exists(recorded_filename):
                processed_filename = os.path.join(
                    recording_info['processed_path'], 
                    os.path.basename(recorded_filename)
                )
                # Use ffmpeg to fix the file
                cmd = ['ffmpeg', '-err_detect', 'ignore_err', '-i', recorded_filename, '-c', 'copy', processed_filename]
                result = subprocess.run(cmd, capture_output=True)
                if result.returncode == 0:
                    os.remove(recorded_filename)
                    self.logger.info(f"Fixed and moved: {processed_filename}")
                else:
                    self.logger.error(f"Failed to fix video file: {recorded_filename}")
            else:
                self.logger.warning(f"Recorded file not found: {recorded_filename}")
        except Exception as e:
            self.logger.error(f"Error fixing video file: {e}")

    def stop_checker(self):
        self.logger.info("Stopping RecordChecker...")
        self.running = False
        # Stop all active recordings
        for username in list(self.active_recordings.keys()):
            self.logger.info(f"Stopping recording for {username}")
            try:
                recording_info = self.active_recordings[username]
                process = recording_info['process']
                process.terminate()
                try:
                    process.wait(timeout=5.0)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()
                    
            except Exception as e:
                self.logger.error(f"Error stopping recording for {username}: {e}")
        self.active_recordings.clear()
        self.logger.info("RecordChecker stopped")

# Function to run the recorder
async def run_record_checker():
    # Parse command line arguments
    parser = argparse.ArgumentParser(description='Twitch Stream Auto-Recorder')
    parser.add_argument('-server', type=str, required=True, choices=['au-east-1', 'us-west-1', 'us-east-1', 'eu-central-1'], help='Server location for auto-recording')
    args = parser.parse_args()
    root_path = os.getenv('STREAM_ROOT_PATH', os.path.join(os.getcwd(), "stream"))
    checker = RecordChecker(server_location=args.server, root_path=root_path)
    try:
        await checker.start_checker()
    except KeyboardInterrupt:
        logging.info("Received interrupt signal")
    finally:
        checker.stop_checker()

if __name__ == "__main__":
    asyncio.run(run_record_checker())