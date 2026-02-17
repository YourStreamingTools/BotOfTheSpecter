# Standard library imports
import os, re, sys, ast, signal, argparse, traceback, math
import json, time, random, base64, operator, threading
from asyncio import Queue, subprocess, Lock
from asyncio import CancelledError as asyncioCancelledError
from asyncio import TimeoutError as asyncioTimeoutError
from asyncio import wait_for as asyncio_wait_for
from asyncio import sleep, gather, create_task, get_event_loop, create_subprocess_exec
from datetime import datetime, timezone, timedelta
from urllib.parse import urlencode
from logging import getLogger
from logging.handlers import RotatingFileHandler as LoggerFileHandler
from logging import Formatter as loggingFormatter
from logging import INFO as LoggingLevel
from pathlib import Path

# Third-party imports
from websockets import connect as WebSocketConnect
from websockets import ConnectionClosed as WebSocketConnectionClosed
from websockets import ConnectionClosedError as WebSocketConnectionClosedError
from aiohttp import ClientSession as httpClientSession
from aiohttp import ClientError as aiohttpClientError
from aiohttp import ClientTimeout
from socketio import AsyncClient
from aiomysql import connect as sql_connect, create_pool
from aiomysql import IntegrityError as MySQLIntegrityError
from socketio.exceptions import ConnectionError as ConnectionExecptionError
from aiomysql import DictCursor, MySQLError
from aiomysql import Error as MySQLOtherErrors
from deep_translator import GoogleTranslator as translator
from twitchio.ext.commands import Context
from twitchio.ext import commands, routines
from streamlink import Streamlink
import pytz as set_timezone
from pytz import timezone as pytz_timezone
from geopy.geocoders import Nominatim
from jokeapi import Jokes
from pint import UnitRegistry as ureg
from paramiko import SSHClient, AutoAddPolicy
import yt_dlp
from openai import AsyncOpenAI

# Load environment variables from .env file
from dotenv import load_dotenv
load_dotenv()

# Parse command-line arguments
parser = argparse.ArgumentParser(description="BotOfTheSpecter Chat Bot")
parser.add_argument("-channel", dest="target_channel", required=True, help="Target Twitch channel name")
parser.add_argument("-channelid", dest="channel_id", required=True, help="Twitch user ID")
parser.add_argument("-token", dest="channel_auth_token", required=True, help="Auth Token for authentication")
parser.add_argument("-refresh", dest="refresh_token", required=True, help="Refresh Token for authentication")
parser.add_argument("-apitoken", dest="api_token", required=False, help="API Token for Websocket Server")
parser.add_argument("-custom", dest="custom_mode", action="store_true", help="Enable custom bot mode")
parser.add_argument("-botusername", dest="bot_username", required=False, help="Bot's Twitch username (required when -custom is used)")
parser.add_argument("-self", dest="self_mode", action="store_true", help="Enable self mode (use broadcaster account)")
args = parser.parse_args()

# Twitch bot settings
CHANNEL_NAME = args.target_channel
CHANNEL_ID = args.channel_id
CHANNEL_AUTH = args.channel_auth_token
REFRESH_TOKEN = args.refresh_token
API_TOKEN = args.api_token
SELF_MODE = args.self_mode
CUSTOM_MODE = args.custom_mode or SELF_MODE
if args.custom_mode:
    BOT_USERNAME = args.bot_username
elif SELF_MODE:
    BOT_USERNAME = args.target_channel
else:
    BOT_USERNAME = "botofthespecter"
VERSION = "5.8"
if CUSTOM_MODE:
    SYSTEM = "CUSTOM"
else:
    SYSTEM = "BETA"
SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER')
SQL_PASSWORD = os.getenv('SQL_PASSWORD')
ADMIN_API_KEY = os.getenv('ADMIN_KEY')
OAUTH_TOKEN = os.getenv('OAUTH_TOKEN')
CLIENT_ID = os.getenv('CLIENT_ID')
CLIENT_SECRET = os.getenv('CLIENT_SECRET')
TWITCH_OAUTH_API_TOKEN = os.getenv('TWITCH_OAUTH_API_TOKEN')
TWITCH_OAUTH_API_CLIENT_ID = os.getenv('TWITCH_OAUTH_API_CLIENT_ID')
TWITCH_GQL = os.getenv('TWITCH_GQL')
SHAZAM_API = os.getenv('SHAZAM_API')
STEAM_API = os.getenv('STEAM_API')
EXCHANGE_RATE_API_KEY = os.getenv('EXCHANGE_RATE_API')
HYPERATE_API_KEY = os.getenv('HYPERATE_API_KEY')
OPENAI_API_KEY = os.getenv('OPENAI_KEY')
OPENAI_INSTRUCTIONS_ENDPOINT = 'https://api.botofthespecter.com/chat-instructions'
_cached_instructions = None
_cached_instructions_time = 0
INSTRUCTIONS_CACHE_TTL = int('300') # seconds
HISTORY_DIR = '/home/botofthespecter/ai/chat-history'
AD_BREAK_CHAT_DIR = '/home/botofthespecter/ai/ad_break_chat'
# Max allowed characters per chat message; reserve room for possible prefixes like @username
MAX_CHAT_MESSAGE_LENGTH = 240
SSH_USERNAME = os.getenv('SSH_USERNAME')
SSH_PASSWORD = os.getenv('SSH_PASSWORD')
SSH_HOSTS = {
    'API': os.getenv('API-HOST'),
    'WEBSOCKET': os.getenv('WEBSOCKET-HOST'),
    'BOT-SRV': os.getenv('BOT-SRV-HOST'),
    'SQL': os.getenv('SQL-HOST'),
    'STREAM-US-EAST-1': os.getenv('STREAM-US-EAST-1-HOST'),
    'STREAM-US-WEST-1': os.getenv('STREAM-US-WEST-1-HOST'),
    'STREAM-AU-EAST-1': os.getenv('STREAM-AU-EAST-1-HOST'),
    'WEB': os.getenv('WEB-HOST'),
    'BILLING': os.getenv('BILLING-HOST')
}
builtin_commands = {
    "commands", "bot", "roadmap", "quote", "rps", "story", "roulette", "songrequest", "songqueue", "watchtime", "stoptimer",
    "checktimer", "version", "convert", "subathon", "todo", "kill", "points", "slots", "timer", "game", "joke", "ping",
    "weather", "time", "song", "translate", "cheerleader", "steam", "schedule", "mybits", "lurk", "unlurk", "lurking",
    "lurklead", "userslurking", "clip", "subscription", "hug", "highfive", "kiss", "uptime", "typo", "typos", "followage",
    "deaths", "heartrate", "gamble", "joinraffle", "leaveraffle"
}
mod_commands = {
    "addcommand", "removecommand", "editcommand", "removetypos", "addpoints", "removepoints", "permit", "removequote", "quoteadd",
    "settitle", "setgame", "edittypos", "deathadd", "deathremove", "shoutout", "marker", "checkupdate", "startlotto", "drawlotto",
    "skipsong", "wsstatus", "dbstatus", "obs", "startraffle", "stopraffle", "drawraffle"
}
builtin_aliases = {
    "cmds", "back", "so", "typocount", "edittypo", "removetypo", "death+", "death-", "mysub", "sr", "lurkleader", "skip",
    "rafflejoin", "raffle"
}

# Logs
logs_root = "/home/botofthespecter/logs"
logs_directory = os.path.join(logs_root, "logs")
log_types = ["bot", "chat", "twitch", "api", "chat_history", "event_log", "websocket", "system", "integrations"]

# Ensure directories exist
for log_type in log_types:
    directory_path = os.path.join(logs_directory, log_type)
    os.makedirs(directory_path, mode=0o755, exist_ok=True)

# Create a function to setup individual loggers for clarity
def setup_logger(name, log_file, level=LoggingLevel):
    logger = getLogger(name)
    logger.setLevel(level)
    # Clear any existing handlers to prevent duplicates
    if logger.hasHandlers():
        logger.handlers.clear()
    # Setup rotating file handler
    handler = LoggerFileHandler(
        log_file,
        maxBytes=10485760, # 10MB
        backupCount=5,
        encoding='utf-8'
    )
    formatter = loggingFormatter('%(asctime)s - %(levelname)s - %(message)s', datefmt='%Y-%m-%d %H:%M:%S')
    handler.setFormatter(formatter)
    logger.addHandler(handler)
    return logger

# Setup loggers
loggers = {}
for log_type in log_types:
    log_file = os.path.join(logs_directory, log_type, f"{CHANNEL_NAME}.txt")
    loggers[log_type] = setup_logger(f"bot.{log_type}", log_file)

# Access individual loggers
bot_logger = loggers['bot']
chat_logger = loggers['chat']
twitch_logger = loggers['twitch']
api_logger = loggers['api']
chat_history_logger = loggers['chat_history']
event_logger = loggers['event_log']
websocket_logger = loggers['websocket']
system_logger = loggers['system']
integrations_logger = loggers['integrations']

# Log startup messages
startup_msg = f"Logger initialized for channel: {CHANNEL_NAME} (Bot Version: {VERSION} {SYSTEM})"
for logger in loggers.values():
    logger.info(startup_msg)

# Function to get the current time
def time_right_now(tz=None):
    if tz:
        return datetime.now(tz)
    return datetime.now()

# Initialize instances for the translator, shoutout queue, websockets, and permitted users for protection
scheduled_tasks = set()                                 # Set for scheduled tasks
shoutout_queue = Queue()                                # Queue for shoutouts
recent_shoutouts = {}                                   # Dictionary for recent shoutouts
permitted_users = {}                                    # Dictionary for permitted users
connected = set()                                       # Set for connected users
pending_removals = {}                                   # Dictionary for pending removals
shoutout_tracker = {}                                   # Dictionary for tracking shoutouts
shoutout_user = {}                                      # Dictionary for temporary shoutout user data
command_usage = {}                                      # Dictionary for tracking command usage with timestamps
last_poll_progress_update = 0                           # Variable for last poll progress update
last_message_time = 0                                   # Variable for last message time
chat_line_count = 0                                     # Tracks the number of chat messages
chat_trigger_tasks = {}                                 # Maps message IDs to chat line counts
song_requests = {}                                      # Tracks song request from users
looped_tasks = {}                                       # Set for looped tasks
active_timed_messages = {}                              # Dictionary to track active timed message IDs and their details
message_tasks = {}                                      # Dictionary to track individual message tasks by ID
gift_sub_recipients = {}                                # Tracks users who received gift subs to prevent duplicate notifications
GIFT_SUB_TRACKING_DURATION = 30                         # Seconds to track gift recipients

# Initialize global variables
specterSocket = AsyncClient()                           # Specter Socket Client instance
streamelements_socket = AsyncClient()                   # StreamElements Socket Client instance
openai_client = AsyncOpenAI(api_key=OPENAI_API_KEY)     # OpenAI client for AI responses
_shared_http_session = None                              # Shared aiohttp session (lazy-created)
bot_started = time_right_now()                          # Time the bot started
stream_online = False                                   # Whether the stream is currently online 
next_spotify_refresh_time = None                        # Time for the next Spotify token refresh 
HEARTRATE = None                                        # Current heart rate value 
hyperate_task = None                                    # HypeRate WebSocket task
TWITCH_SHOUTOUT_GLOBAL_COOLDOWN = timedelta(minutes=2)  # Global cooldown for shoutouts
TWITCH_SHOUTOUT_USER_COOLDOWN = timedelta(minutes=60)   # User-specific cooldown for shoutouts
last_shoutout_time = datetime.min                       # Last time a shoutout was performed
websocket_connected = False                             # Whether the websocket is currently connected
bot_owner = "gfaundead"                                 # Bot owner's username
streamelements_token = None                             # StreamElements OAuth2 access token
streamlabs_token = None                                 # StreamLabs access token
ad_settings_cache = None                                # Global cache for ad settings
ad_settings_cache_time = 0                              # Last time the ad settings were cached
CACHE_DURATION = 60                                     # 1 minute (matches ad check interval)
ad_upcoming_notified = False                            # Flag to prevent duplicate ad upcoming notifications
AD_DEDUPE_COOLDOWN_SECONDS = 45                         # Minimum seconds between ad messages per process
last_ad_message_ts = 0.0                                # Timestamp of last ad message sent by this process
pending_outgoing_raid = None                            # Dictionary to hold pending outgoing raid data until stream goes offline for accurate viewer count persistence
outgoing_raid_task = None                               # asyncio.Task that waits for stream end to persist outgoing raid

SPOTIFY_ERROR_MESSAGES = {
    400: "It looks like something went wrong with the request. Please try again.",
    401: "I couldn't connect to Spotify. Looks like the authentication failed. Please check the bot's credentials.",
    403: "Spotify says I don't have permission to do that. Check your Spotify account settings.",
    404: "I couldn't find what you were looking for. Please double-check the song or playlist.",
    429: "Spotify is saying we're sending too many requests. Let's wait a moment and try again.",
    500: "Spotify is having server issues. Please try again in a bit.",
    502: "Spotify is having a temporary issue. Please try again in a bit.",
    503: "Spotify's service is currently down. We'll need to wait until it's back online.",
}

allowed_ops = {
    '+': operator.add,
    '-': operator.sub,
    '*': operator.mul,
    '/': operator.floordiv
}

# Custom cooldown functions
async def check_cooldown(command, user_id, bucket_type, rate, time_window, send_message=True):
    global command_usage
    current_time = time.time()
    # Create key based on bucket type to properly separate cooldowns
    key = (command, bucket_type, user_id)
    if key not in command_usage:
        command_usage[key] = []
    # Clean old timestamps outside the time window
    command_usage[key] = [t for t in command_usage[key] if current_time - t < time_window]
    # Check if under the rate limit
    if len(command_usage[key]) < rate:
        return True  # Command can be used
    else:
        # Calculate remaining cooldown time
        if send_message:
            oldest_usage = min(command_usage[key])
            remaining_time = int(time_window - (current_time - oldest_usage))
            await send_chat_message(f"{command} is on cooldown. Please wait {remaining_time} seconds.")
        return False  # Command on cooldown

def add_usage(command, user_id, bucket_type='default'):
    global command_usage
    current_time = time.time()
    # Create key based on bucket type to properly separate cooldowns
    key = (command, bucket_type, user_id)
    if key not in command_usage:
        command_usage[key] = []
    command_usage[key].append(current_time)

# Function to handle termination signals
def signal_handler(sig, frame):
    bot_logger.info("Received termination signal. Shutting down gracefully...")
    # Schedule the async cleanup tasks
    loop = get_event_loop()
    if loop.is_running():
        loop.create_task(async_signal_cleanup())
    else:
        sys.exit(0)  # Exit the program

# Async cleanup function
async def async_signal_cleanup():
    await specterSocket.disconnect()     # Disconnect the SocketClient
    ssh_manager.close_all_connections()  # Close all SSH connections
    await mysql_handler.close_all()      # Close all MySQL connections
    # Close shared HTTP session if created
    try:
        global _shared_http_session
        if '_shared_http_session' in globals() and _shared_http_session:
            await _shared_http_session.close()
    except Exception as e:
        bot_logger.error(f"Error closing shared HTTP session: {e}")
    for task in scheduled_tasks:
        task.cancel()
    for task in looped_tasks:
        task.cancel()
    for task in shoutout_queue:
        task.cancel()
    sys.exit(0)  # Exit the program

# Register the signal handler
signal.signal(signal.SIGTERM, signal_handler)
signal.signal(signal.SIGINT, signal_handler)  # Handle Ctrl+C as well

# MySQL Connection Wrapper compatible with existing usage (`async with mysql_handler.get_connection()`)
class PoolConnectionWrapper:
    def __init__(self, connection, pool=None):
        self._connection = connection
        self._pool = pool
        self._released = False

    def cursor(self, *args, **kwargs):
        if self._released:
            raise RuntimeError("Cannot use cursor on released connection")
        return self._connection.cursor(*args, **kwargs)

    async def commit(self):
        if self._connection and not self._released:
            await self._connection.commit()

    async def rollback(self):
        if self._connection and not self._released:
            await self._connection.rollback()

    async def release(self):
        if self._connection and not self._released:
            try:
                # If using a pool, release back to pool; otherwise close the single connection
                if self._pool is not None:
                    try:
                        await self._pool.release(self._connection)
                    except Exception:
                        # Some pool implementations may expect synchronous release
                        try:
                            self._pool.release(self._connection)
                        except Exception:
                            pass
                else:
                    try:
                        self._connection.close()
                    except Exception:
                        pass
                self._released = True
            except Exception as e:
                bot_logger.error(f"Error releasing/closing connection: {e}")

    async def __aenter__(self):
        return self

    async def __aexit__(self, exc_type, exc_val, exc_tb):
        await self.release()
        return False

    def __del__(self):
        if self._connection and not self._released:
            try:
                loop = get_event_loop()
                if loop.is_running():
                    loop.create_task(self.release())
            except Exception:
                pass

# Improved MySQL handler with connection pooling, health checks and cleanup
class MySQLHandler:
    def __init__(self):
        # db_name -> aiomysql Pool
        self.pools = {}
        # db_name -> metadata (last_used)
        self.pool_meta = {}
        self.lock = Lock()

    async def _create_pool(self, db_name):
        global SQL_HOST, SQL_USER, SQL_PASSWORD
        pool = await create_pool(
            host=SQL_HOST,
            user=SQL_USER,
            password=SQL_PASSWORD,
            db=db_name,
            minsize=1,
            maxsize=8,
            autocommit=True,
            connect_timeout=5
        )
        self.pools[db_name] = pool
        self.pool_meta[db_name] = {'last_used': time.time()}
        return pool

    async def _close_pool(self, db_name):
        pool = self.pools.pop(db_name, None)
        self.pool_meta.pop(db_name, None)
        if pool:
            try:
                pool.close()
                await pool.wait_closed()
            except Exception as e:
                bot_logger.error(f"Error closing pool {db_name}: {e}")

    async def get_connection(self, db_name=None):
        if db_name is None:
            db_name = CHANNEL_NAME
        for attempt in range(3):
            try:
                async with self.lock:
                    pool = self.pools.get(db_name)
                    if pool is None:
                        pool = await self._create_pool(db_name)
                # Acquire a connection from the pool
                conn = await pool.acquire()
                try:
                    # Ensure the connection is alive; try ping, recreate pool on failure
                    try:
                        await conn.ping()
                    except Exception:
                        # Connection is dead - release and recreate pool, then retry acquisition
                        try:
                            pool.release(conn)
                        except Exception:
                            pass
                        async with self.lock:
                            await self._close_pool(db_name)
                            pool = await self._create_pool(db_name)
                        conn = await pool.acquire()
                    # Update last used timestamp
                    self.pool_meta.setdefault(db_name, {})['last_used'] = time.time()
                    return PoolConnectionWrapper(conn, pool=pool)
                except Exception:
                    try:
                        pool.release(conn)
                    except Exception:
                        pass
                    raise
            except Exception as e:
                bot_logger.warning(f"MySQL get_connection attempt {attempt+1} failed for {db_name}: {e}")
                await sleep(1 + attempt)
        raise MySQLError(f"Unable to get MySQL connection for {db_name} after retries")

    async def close_all(self):
        # Close all pools and clear metadata
        async with self.lock:
            for db_name in list(self.pools.keys()):
                await self._close_pool(db_name)

    async def cleanup_idle_pools(self, max_idle_time=1800):
        now = time.time()
        to_close = []
        for db_name, meta in list(self.pool_meta.items()):
            if now - meta.get('last_used', 0) > max_idle_time:
                to_close.append(db_name)

        for db_name in to_close:
            bot_logger.info(f"Closing idle DB pool: {db_name}")
            await self._close_pool(db_name)

    def get_pool_stats(self):
        stats = {}
        for db_name, pool in self.pools.items():
            try:
                stats[db_name] = {
                    'minsize': getattr(pool, 'minsize', None),
                    'maxsize': getattr(pool, 'maxsize', None),
                    'free_size': getattr(pool, 'free_size', None)
                }
            except Exception:
                stats[db_name] = {'info': 'unavailable'}
        return stats

    def get_pool_status(self, db_name=None):
        if db_name is None:
            return {'pools': list(self.pools.keys()), 'total': len(self.pools)}
        return {'connected': db_name in self.pools}

# Initialize global MySQL handler for backward compatibility
mysql_handler = MySQLHandler()

# Function to handle MySQL connections (returns a PoolConnectionWrapper compatible with existing usage)
async def mysql_connection(db_name=None):
    if db_name is None:
        db_name = CHANNEL_NAME
    return await mysql_handler.get_connection(db_name=db_name)

# Connect to database spam_pattern and fetch patterns
async def get_spam_patterns():
    async with await mysql_handler.get_connection(db_name="spam_pattern") as connection:
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute("SELECT spam_pattern FROM spam_patterns")
            results = await cursor.fetchall()
    compiled_patterns = [re.compile(re.escape(row["spam_pattern"]), re.IGNORECASE) for row in results if row["spam_pattern"]]
    return compiled_patterns

# Setup Token Refresh
async def twitch_token_refresh():
    global REFRESH_TOKEN
    # Wait for 5 minutes before the first token refresh
    await sleep(300)
    next_refresh_time = await refresh_twitch_token(REFRESH_TOKEN)
    while True:
        current_time = time.time()
        time_until_expiration = next_refresh_time - current_time
        if current_time >= next_refresh_time:
            next_refresh_time = await refresh_twitch_token(REFRESH_TOKEN)
        else:
            # Adjust sleep intervals based on time remaining until next refresh
            if time_until_expiration > 3600:
                sleep_time = 3600  # Sleep for 1 hour
            elif time_until_expiration > 300:
                sleep_time = 300  # Sleep for 5 minutes
            else:
                sleep_time = 60  # Sleep for 1 minute
            await sleep(sleep_time)

# Function to refresh Twitch token
async def refresh_twitch_token(current_refresh_token):
    global CHANNEL_AUTH, OAUTH_TOKEN, CLIENT_ID, CLIENT_SECRET
    url = 'https://id.twitch.tv/oauth2/token'
    body = {
        'grant_type': 'refresh_token',
        'refresh_token': current_refresh_token,
        'client_id': CLIENT_ID,
        'client_secret': CLIENT_SECRET,
    }
    try:
        async with httpClientSession() as session:
            async with session.post(url, data=body) as response:
                if response.status == 200:
                    response_json = await response.json()
                    new_access_token = response_json.get('access_token')
                    expires_in = response_json.get('expires_in', 14400)  # Default to 4 hours if not provided
                    next_refresh_time = time.time() + expires_in - 300  # Refresh 5 minutes before expiration
                    if new_access_token:
                        # Update the global access token
                        CHANNEL_AUTH = new_access_token
                        twitch_logger.info(f"Refreshed token. New Access Token: {CHANNEL_AUTH}.")
                        async with await mysql_handler.get_connection(db_name="website") as connection:
                            try:
                                async with connection.cursor(DictCursor) as cursor:
                                    # Insert or update the access token for the given twitch_user_id
                                    query = "INSERT INTO twitch_bot_access (twitch_user_id, twitch_access_token) VALUES (%s, %s) ON DUPLICATE KEY UPDATE twitch_access_token = %s;"
                                    await cursor.execute(query, (CHANNEL_ID, CHANNEL_AUTH, CHANNEL_AUTH))
                                    await connection.commit()
                            except Exception as e:
                                twitch_logger.error(f"Database update failed: {e}")
                        return next_refresh_time
                    else:
                        twitch_logger.error("Token refresh failed: 'access_token' not found in response.")
                else:
                    error_response = await response.json()
                    twitch_logger.error(f"Twitch token refresh failed: HTTP {response.status} - {error_response}")
                    # Additional error logging for better insights
                    twitch_logger.error(f"Error details: {error_response}")
    except Exception as e:
        twitch_logger.error(f"Twitch token refresh error: {e}")
    return time.time() + 3600  # Default retry time of 1 hour

# Save EventSub session ID to database
async def save_eventsub_session_id(session_id):
    try:
        session_name = f"{SYSTEM} Bot"
        async with await mysql_handler.get_connection() as conn:
            async with conn.cursor() as cursor:
                await cursor.execute(
                    "INSERT INTO eventsub_sessions (session_id, session_name) VALUES (%s, %s) "
                    "ON DUPLICATE KEY UPDATE session_name = %s, last_updated = CURRENT_TIMESTAMP",
                    (session_id, session_name, session_name)
                )
        event_logger.info(f"Saved EventSub session ID to database: {session_id} as '{session_name}'")
    except Exception as e:
        event_logger.error(f"Failed to save EventSub session ID to database: {e}")

# Setup Twitch EventSub
async def twitch_eventsub():
    twitch_websocket_uri = "wss://eventsub.wss.twitch.tv/ws?keepalive_timeout_seconds=600"
    while True:
        try:
            async with WebSocketConnect(twitch_websocket_uri) as twitch_websocket:
                # Receive and parse the welcome message
                eventsub_welcome_message = await twitch_websocket.recv()
                eventsub_welcome_data = json.loads(eventsub_welcome_message)
                # Validate the message type
                if eventsub_welcome_data.get('metadata', {}).get('message_type') == 'session_welcome':
                    session_id = eventsub_welcome_data['payload']['session']['id']
                    keepalive_timeout = eventsub_welcome_data['payload']['session']['keepalive_timeout_seconds']
                    event_logger.info(f"Twitch WS Connected with session ID: {session_id}")
                    event_logger.info(f"Twitch WS Keepalive timeout: {keepalive_timeout} seconds")
                    # Save session ID to database
                    await save_eventsub_session_id(session_id)
                    # Subscribe to the events using the session ID and auth token
                    await subscribe_to_events(session_id)
                    # Manage keepalive and listen for messages concurrently
                    await gather(twitch_receive_messages(twitch_websocket, keepalive_timeout))
        except WebSocketConnectionClosedError as e:
            event_logger.error(f"WebSocket connection closed unexpectedly: {e}")
            await sleep(10)  # Wait before retrying
        except Exception as e:
            event_logger.error(f"An unexpected error occurred: {e}")
            await sleep(10)  # Wait before reconnecting

async def subscribe_to_events(session_id):
    global CHANNEL_ID, CHANNEL_AUTH, CLIENT_ID
    url = "https://api.twitch.tv/helix/eventsub/subscriptions"
    headers = {
        "Client-Id": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}",
        "Content-Type": "application/json"
    }
    # Define topics with their versions and conditions
    topics = [
        # v1 topics
        {"type": "stream.online", "version": "1", "condition": {"broadcaster_user_id": CHANNEL_ID}},
        {"type": "stream.offline", "version": "1", "condition": {"broadcaster_user_id": CHANNEL_ID}},
        {"type": "channel.chat.notification", "version": "1", "condition": {"broadcaster_user_id": CHANNEL_ID, "user_id": CHANNEL_ID}},
        {"type": "channel.bits.use", "version": "1", "condition": {"broadcaster_user_id": CHANNEL_ID}},
        {"type": "channel.raid", "version": "1", "condition": {"to_broadcaster_user_id": CHANNEL_ID}},
        {"type": "channel.ad_break.begin", "version": "1", "condition": {"broadcaster_user_id": CHANNEL_ID}},
        {"type": "channel.charity_campaign.donate", "version": "1", "condition": {"broadcaster_user_id": CHANNEL_ID}},
        {"type": "channel.channel_points_custom_reward_redemption.add", "version": "1", "condition": {"broadcaster_user_id": CHANNEL_ID}},
        {"type": "channel.poll.begin", "version": "1", "condition": {"broadcaster_user_id": CHANNEL_ID}},
        {"type": "channel.poll.end", "version": "1", "condition": {"broadcaster_user_id": CHANNEL_ID}},
        {"type": "channel.suspicious_user.message", "version": "1", "condition": {"broadcaster_user_id": CHANNEL_ID, "moderator_user_id": CHANNEL_ID}},
        {"type": "channel.chat.user_message_hold", "version": "1", "condition": {"broadcaster_user_id": CHANNEL_ID, "user_id": CHANNEL_ID}},
        {"type": "channel.shoutout.create", "version": "1", "condition": {"broadcaster_user_id": CHANNEL_ID, "moderator_user_id": CHANNEL_ID}},
        {"type": "channel.shoutout.receive", "version": "1", "condition": {"broadcaster_user_id": CHANNEL_ID, "moderator_user_id": CHANNEL_ID}},
        {"type": "channel.chat.message", "version": "1", "condition": {"broadcaster_user_id": CHANNEL_ID, "user_id": CHANNEL_ID}},
        # v2 topics
        {"type": "channel.channel_points_automatic_reward_redemption.add", "version": "2", "condition": {"broadcaster_user_id": CHANNEL_ID}},        
        {"type": "automod.message.hold", "version": "2", "condition": {"broadcaster_user_id": CHANNEL_ID, "moderator_user_id": CHANNEL_ID}},
        {"type": "channel.follow", "version": "2", "condition": {"broadcaster_user_id": CHANNEL_ID, "moderator_user_id": CHANNEL_ID}},
        {"type": "channel.update", "version": "2", "condition": {"broadcaster_user_id": CHANNEL_ID}},
        {"type": "channel.hype_train.begin", "version": "2", "condition": {"broadcaster_user_id": CHANNEL_ID}},
        {"type": "channel.hype_train.end", "version": "2", "condition": {"broadcaster_user_id": CHANNEL_ID}},
        {"type": "channel.moderate", "version": "2", "condition": {"broadcaster_user_id": CHANNEL_ID, "moderator_user_id": CHANNEL_ID}},
    ]
    # Prepare payloads
    payloads = []
    for topic in topics:
        payload = {
            "type": topic["type"],
            "version": topic["version"],
            "condition": topic["condition"],
            "transport": {
                "method": "websocket",
                "session_id": session_id
            }
        }
        payloads.append(payload)
    # Subscribe concurrently
    responses = []
    async with httpClientSession() as session:
        twitch_logger.info("===== Subscribing to Twitch EventSub Events =====")
        tasks = []
        for payload in payloads:
            task = session.post(url, headers=headers, json=payload)
            tasks.append(task)
        # Gather all responses
        results = await gather(*tasks, return_exceptions=True)
        subscribed_events = 0
        failed_events = 0
        events_subscribed_to = []
        events_failed_to_subscribe = []
        for i, result in enumerate(results):
            if isinstance(result, Exception):
                twitch_logger.error(f"Error subscribing to {payloads[i]['type']}: {result}")
            else:
                response = result
                if response.status in (200, 202):
                    responses.append(await response.json())
                    subscribed_events += 1
                    events_subscribed_to.append(payloads[i]['type'])
                else:
                    error_text = await response.text()
                    twitch_logger.error(f"Failed to subscribe to {payloads[i]['type']}: HTTP {response.status} - {error_text}")
                    failed_events += 1
                    events_failed_to_subscribe.append(payloads[i]['type'])
        twitch_logger.info(f"Subscribed to {subscribed_events} Twitch EventSub events. Events: {', '.join(events_subscribed_to)}")
        if events_failed_to_subscribe:
            twitch_logger.error(f"Failed to subscribe to {failed_events} events: {', '.join(events_failed_to_subscribe)}")
    twitch_logger.info("===== Twitch EventSub Subscription Complete =====")

async def twitch_receive_messages(twitch_websocket, keepalive_timeout):
    while True:
        try:
            message = await asyncio_wait_for(twitch_websocket.recv(), timeout=keepalive_timeout)
            message_data = json.loads(message)
            # event_logger.info(f"Received message: {message}")
            if 'metadata' in message_data:
                message_type = message_data['metadata'].get('message_type')
                if message_type == 'session_keepalive':
                    event_logger.info("Received session keepalive message from Twitch WebSocket")
                else:
                    # event_logger.info(f"Received message type: {message_type}")
                    event_logger.info(f"Info from Twitch EventSub: {message_data}")
                    await process_twitch_eventsub_message(message_data)
            else:
                event_logger.error("Received unrecognized message format")
        except asyncioTimeoutError:
            event_logger.error("Keepalive timeout exceeded, reconnecting...")
            await twitch_websocket.close()
            continue  # Continue the loop to allow reconnection logic
        except WebSocketConnectionClosedError as e:
            event_logger.error(f"WebSocket connection closed unexpectedly: {str(e)}")
            break  # Exit the loop for reconnection
        except Exception as e:
            event_logger.error(f"Error receiving message: {e}")
            break  # Exit the loop on critical error

async def connect_to_integrations():
    # Start Stream Bingo first
    looped_tasks["stream_bingo_websocket"] = create_task(stream_bingo_websocket())
    # Wait 2 seconds for Stream Bingo to connect and log
    await sleep(2)
    # Start Tanggle integration
    looped_tasks["tanggle_websocket"] = create_task(connect_to_tanggle())
    # Wait 2 seconds for Tanggle to connect and log
    await sleep(2)
    # Then start tipping services (StreamElements and StreamLabs)
    looped_tasks["connect_to_tipping_services"] = create_task(connect_to_tipping_services())


async def connect_to_tipping_services():
    global CHANNEL_ID, streamelements_token, streamlabs_token
    try:
        async with await mysql_handler.get_connection(db_name="website") as connection:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch StreamElements token
                await cursor.execute("SELECT access_token FROM streamelements_tokens WHERE twitch_user_id = %s", (CHANNEL_ID,))
                se_result = await cursor.fetchone()
                if se_result:
                    streamelements_token = se_result.get('access_token')
                    event_logger.info("StreamElements token retrieved from database")
                else:
                    event_logger.info("No StreamElements token found for this channel")
                # Fetch StreamLabs tokens (prefer socket token for websocket connection)
                await cursor.execute("SELECT socket_token, access_token FROM streamlabs_tokens WHERE twitch_user_id = %s", (CHANNEL_ID,))
                sl_result = await cursor.fetchone()
                if sl_result:
                    socket_tok = sl_result.get('socket_token')
                    access_tok = sl_result.get('access_token')
                    if socket_tok:
                        streamlabs_token = socket_tok
                        event_logger.info("StreamLabs socket token retrieved from database and will be used for websocket connection")
                    elif access_tok:
                        # fallback: use access token if socket token isn't available
                        streamlabs_token = access_tok
                        event_logger.info("StreamLabs access token retrieved from database; using it as fallback for websocket connection")
                    else:
                        event_logger.info("StreamLabs entry found but no usable token (socket_token/access_token) present")
                else:
                    event_logger.info("No StreamLabs token record found for this channel")
            # Start connection tasks with delays for cleaner logs
            if streamelements_token:
                create_task(streamelements_connection_manager())
                # Wait 2 seconds for StreamElements to connect and log before starting StreamLabs
                await sleep(2)
            if streamlabs_token:
                create_task(connect_to_streamlabs())
            if not streamelements_token and not streamlabs_token:
                event_logger.error("No valid tokens found for either StreamElements or StreamLabs. Aborting tipping service connection.")
                return
    except MySQLError as err:
        event_logger.error(f"Database error while fetching tipping service tokens: {err}")

async def streamelements_connection_manager():
    global streamelements_token
    max_retries = 5
    base_delay = 1  # Start with 1 second delay
    max_delay = 60  # Maximum delay of 60 seconds
    long_delay = 300  # 5 minutes for extended failures
    while True:  # Keep trying indefinitely
        for attempt in range(max_retries):
            try:
                event_logger.info(f"Attempting to connect to StreamElements (attempt {attempt + 1}/{max_retries})")
                await connect_to_streamelements()
                # If we get here, connection was successful and maintained
                event_logger.info("StreamElements connection maintained successfully")
                return  # Exit the function if connection is successful and stays connected
            except Exception as e:
                if attempt < max_retries - 1:
                    # Calculate delay with exponential backoff
                    delay = min(base_delay * (2 ** attempt), max_delay)
                    event_logger.error(f"StreamElements connection failed: {e}. Retrying in {delay} seconds...")
                    await sleep(delay)
                else:
                    event_logger.error(f"Failed to connect to StreamElements after {max_retries} attempts: {e}")
        # If we've exhausted all retries, wait longer before trying the whole cycle again
        event_logger.info(f"StreamElements connection cycle completed. Waiting {long_delay} seconds before retrying...")
        await sleep(long_delay)

async def connect_to_streamelements():
    global streamelements_token
    uri = "https://realtime.streamelements.com"
    # Check if we have a valid token
    if not streamelements_token:
        return
    integrations_logger.info("===== StreamElements =====")
    try:
        @streamelements_socket.event
        async def connect():
            integrations_logger.info("Successfully connected to StreamElements websocket")
            # Authenticate using OAuth2 token (following StreamElements example)
            await streamelements_socket.emit('authenticate', {'method': 'oauth2', 'token': streamelements_token})
        @streamelements_socket.event
        async def disconnect():
            integrations_logger.error("Disconnected from StreamElements websocket - will attempt reconnection with fresh token")
            # Disconnect detected - the connection manager will handle reconnection with token refresh
        @streamelements_socket.event
        async def authenticated(data):
            channel_id = data.get('channelId')
            integrations_logger.info(f"Successfully authenticated to StreamElements channel {channel_id}")
        @streamelements_socket.event
        async def unauthorized(data):
            integrations_logger.error(f"StreamElements authentication failed: {data}")
            # Token might be expired or invalid - trigger disconnection so reconnection manager can refresh token
            integrations_logger.error("Authentication failed, disconnecting to trigger token refresh and reconnection")
            await streamelements_socket.disconnect()
        @streamelements_socket.event
        async def event(*args):
            if args:
                data = args[0]
                # Main event handler for live events - only process tip events
                try:
                    # Only process tip events from StreamElements
                    if data.get('type') == 'tip':
                        sanitized_data = json.dumps(data).replace(streamelements_token, "[REDACTED]")
                        integrations_logger.info(f"StreamElements Tip Event: {sanitized_data}")
                        await process_tipping_message(data, "StreamElements")
                    else:
                        # Log other events for debugging but don't process them
                        event_type = data.get('type', 'unknown')
                        integrations_logger.debug(f"StreamElements event ignored (type: {event_type})")
                except Exception as e:
                    integrations_logger.error(f"Error processing StreamElements event: {e}")
        @streamelements_socket.event
        async def event_test(*args):
            if args:
                data = args[0]
                # Test event handler - only process tip tests
                try:
                    if data.get('type') == 'tip':
                        sanitized_data = json.dumps(data).replace(streamelements_token, "[REDACTED]")
                        integrations_logger.info(f"StreamElements Test Tip Event: {sanitized_data}")
                        # Note: Usually test events shouldn't trigger actual processing
                    else:
                        event_type = data.get('type', 'unknown')
                        integrations_logger.debug(f"StreamElements test event ignored (type: {event_type})")
                except Exception as e:
                    integrations_logger.error(f"Error processing StreamElements test event: {e}")
        @streamelements_socket.event
        async def event_update(*args):
            if args:
                data = args[0]
                # Session update events - not processing these since we only care about tips
                try:
                    integrations_logger.debug("StreamElements session update event received (ignored)")
                except Exception as e:
                    integrations_logger.error(f"Error handling StreamElements update event: {e}")
        @streamelements_socket.event
        async def event_reset(*args):
            if args:
                data = args[0]
                # Session reset events - not processing these since we only care about tips
                try:
                    integrations_logger.debug("StreamElements session reset event received (ignored)")
                except Exception as e:
                    integrations_logger.error(f"Error handling StreamElements reset event: {e}")
        # Connect to StreamElements with websocket transport only (as per example)
        await streamelements_socket.connect(uri, transports=['websocket'])
        await streamelements_socket.wait()
    except ConnectionExecptionError as e:
        integrations_logger.error(f"StreamElements WebSocket connection error: {e}")
        # Should attempt reconnection with backoff
        raise
    except Exception as e:
        integrations_logger.error(f"StreamElements WebSocket error: {e}")
        raise

async def connect_to_streamlabs():
    global streamlabs_token
    uri = f"wss://sockets.streamlabs.com/socket.io/?token={streamlabs_token}&EIO=3&transport=websocket"
    integrations_logger.info("===== StreamLabs =====")
    try:
        async with WebSocketConnect(uri, ping_interval=None) as streamlabs_websocket:
            integrations_logger.info(f"Connected to StreamLabs WebSocket")
            # Listen for messages
            while True:
                message = await streamlabs_websocket.recv()
                sanitized_message = message.replace(streamlabs_token, "[REDACTED]")
                # Handle Socket.IO ping (frame type 2) - respond with pong (frame type 3)
                if message == "2":
                    await streamlabs_websocket.send("3")
                    integrations_logger.debug("StreamLabs pong sent")
                    continue
                message_str = str(message)
                # Check if message is just protocol control (starts with 0, 40, 41, etc. and no JSON follows)
                if message_str.startswith(('0', '40', '41')):
                    # Check if it's purely digits (like "40") or starts with two digits followed by '{' or '[' indicating a handshake payload
                    if message_str.isdigit() or (len(message_str) > 2 and message_str[0].isdigit() and message_str[1].isdigit() and message_str[2] in '{['):
                        # If it's just digits (like "40") or a handshake message starting with "0{...}"
                        if message_str == "40" or (message_str.startswith('0') and '{' in message_str):
                            integrations_logger.debug(f"StreamLabs Socket.IO protocol message: {message_str[:50]}...")
                            continue
                sanitized_message = message.replace(streamlabs_token, "[REDACTED]")
                integrations_logger.info(f"StreamLabs Message: {sanitized_message}")
                await process_message(message, "StreamLabs")
    except WebSocketConnectionClosed as e:
        integrations_logger.error(f"StreamLabs WebSocket connection closed: {e}")
    except Exception as e:
        integrations_logger.error(f"StreamLabs WebSocket error: {e}")

async def process_message(message, source):
    global streamelements_token, streamlabs_token
    try:
        # For StreamLabs, strip Socket.IO frame type prefix (e.g., "0", "40", "42")
        if source == "StreamLabs":
            # Socket.IO messages start with a frame type number, strip it
            # Frame types: 0=open, 1=close, 2=ping, 3=pong, 4=message, 40=connect, 41=disconnect, 42=event
            message_str = str(message)
            # Find where the JSON starts (after the leading digits)
            json_start = 0
            for i, char in enumerate(message_str):
                if not char.isdigit():
                    json_start = i
                    break
            # If the entire message is just digits (like "40"), skip processing
            if json_start == 0 and message_str.isdigit():
                integrations_logger.debug(f"StreamLabs Socket.IO control frame: {message_str}")
                return
            # Extract JSON part
            json_message = message_str[json_start:]
            if not json_message:
                integrations_logger.debug(f"StreamLabs message has no JSON content: {message_str}")
                return
            message = json_message
        data = json.loads(message)
        if source == "StreamElements":
            if data.get('type') == 'response':
                # Handle the subscription response
                if 'error' in data:
                    sanitized_message = data['data']['message'].replace(streamelements_token, "[REDACTED]") if 'message' in data['data'] else None
                    handle_streamelements_error(data['error'], sanitized_message)
                else:
                    sanitized_message = data['data']['message'].replace(streamelements_token, "[REDACTED]") if 'message' in data['data'] else None
                    integrations_logger.info(f"StreamElements subscription success: {sanitized_message}")
            else:
                sanitized_message = json.dumps(data).replace(streamelements_token, "[REDACTED]")
                await process_tipping_message(json.loads(sanitized_message), source)
        elif source == "StreamLabs":
            sanitized_message = message.replace(streamlabs_token, "[REDACTED]")
            await process_tipping_message(json.loads(sanitized_message), source)
    except Exception as e:
        integrations_logger.error(f"Error processing message from {source}: {e}")

def handle_streamelements_error(error, message):
    global streamelements_token
    error_messages = {
        "err_internal_error": "An internal error occurred.",
        "err_bad_request": "The request was malformed or invalid.",
        "err_unauthorized": "The request lacked valid authentication credentials.",
        "rate_limit_exceeded": "The rate limit for the API has been exceeded.",
        "invalid_message": "The message was invalid or could not be processed."
    }
    sanitized_message = message.replace(streamelements_token, "[REDACTED]") if message else "N/A"
    error_message = error_messages.get(error, "Unknown error occurred.")
    integrations_logger.error(f"StreamElements error: {error_message} - {sanitized_message}")

async def process_tipping_message(data, source):
    try:
        send_message = None
        user = None
        amount = None
        tip_message = None
        tip_id = None
        currency = None
        created_at = None
        if source == "StreamElements" and data.get('type') == 'tip':
            # Use correct StreamElements API field names
            tip_data = data.get('data', {})
            tip_id = tip_data.get('tipId')
            user = tip_data.get('displayName')
            currency = tip_data.get('currency', '')
            amount = tip_data.get('amount')
            tip_message = tip_data.get('message', '')
            created_at = tip_data.get('createdAt')
            # Format the tip message with currency symbol
            amount_text = f"{currency}{amount}" if currency else str(amount)
            message_part = f" Message: {tip_message}" if tip_message else ""
            send_message = f"{user} just tipped {amount_text}!{message_part}"
            integrations_logger.info(f"StreamElements Tip: {send_message} (ID: {tip_id})")
        elif source == "StreamLabs" and 'event' in data and data['event'] == 'donation':
            for donation in data['data']['donations']:
                user = donation['name']
                amount = donation['amount']
                tip_message = donation['message']
                send_message = f"{user} just tipped {amount}! Message: {tip_message}"
                integrations_logger.info(f"StreamLabs Tip: {send_message}")
        if send_message and user and amount is not None:
            await send_chat_message(send_message)
            # Save tipping data to database
            connection = await mysql_handler.get_connection()
            try:
                async with connection.cursor(DictCursor) as cursor:
                    # For StreamElements, store additional data
                    if source == "StreamElements":
                        await cursor.execute(
                            "INSERT INTO tipping (username, amount, message, source, tip_id, currency, created_at) VALUES (%s, %s, %s, %s, %s, %s, %s)",
                            (user, amount, tip_message or '', source, tip_id, currency, created_at)
                        )
                    else:
                        # For other sources, use the basic format
                        await cursor.execute(
                            "INSERT INTO tipping (username, amount, message, source) VALUES (%s, %s, %s, %s)",
                            (user, amount, tip_message or '', source)
                        )
                    await connection.commit()
            except MySQLError as err:
                integrations_logger.error(f"Database error saving tip: {err}")
            finally:
                pass
    except Exception as e:
        integrations_logger.error(f"Error processing tipping message: {e}")

async def process_twitch_eventsub_message(message):
    global pending_outgoing_raid, outgoing_raid_task
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            event_type = message.get("payload", {}).get("subscription", {}).get("type")
            event_data = message.get("payload", {}).get("event")
            if event_type:
                # Tier mapping for all subscription-related events
                tier_mapping = {
                    "1000": "Tier 1",
                    "2000": "Tier 2", 
                    "3000": "Tier 3"
                }
                # Followers Event
                if event_type == "channel.follow":
                    create_task(process_followers_event(
                        event_data["user_id"],
                        event_data["user_name"]
                    ))
                # Chat Notification Event (handles sub, resub, sub_gift, etc.)
                elif event_type == "channel.chat.notification":
                    notice_type = event_data.get("notice_type")
                    if notice_type == "sub":
                        sub_data = event_data.get("sub", {})
                        tier = sub_data.get("sub_tier")
                        tier_name = tier_mapping.get(tier, tier)
                        user_id = event_data["chatter_user_id"]
                        # Check if this user recently received a gift sub
                        current_time = time.time()
                        should_skip = False
                        if user_id in gift_sub_recipients:
                            # Check if the gift was recent (within tracking duration)
                            if current_time - gift_sub_recipients[user_id] < GIFT_SUB_TRACKING_DURATION:
                                event_logger.info(f"Skipping duplicate sub notification for {event_data['chatter_user_name']} - already announced via gift event")
                                should_skip = True
                            # Clean up the entry (whether old or recent)
                            del gift_sub_recipients[user_id]
                        # Only process if not a duplicate from gift
                        if not should_skip:
                            create_task(process_subscription_event(
                                user_id,
                                event_data["chatter_user_name"],
                                tier_name,
                                1  # New sub is always month 1
                            ))
                    elif notice_type == "resub":
                        resub_data = event_data.get("resub", {})
                        tier = resub_data.get("sub_tier")
                        tier_name = tier_mapping.get(tier, tier)
                        cumulative_months = resub_data.get("cumulative_months", 1)
                        subscription_message = resub_data.get("sub_message", {}).get("text", "")
                        if subscription_message:
                            create_task(process_subscription_message_event(
                                event_data["chatter_user_id"],
                                event_data["chatter_user_name"],
                                tier_name,
                                cumulative_months
                            ))
                        else:
                            create_task(process_subscription_event(
                                event_data["chatter_user_id"],
                                event_data["chatter_user_name"],
                                tier_name,
                                cumulative_months
                            ))
                    elif notice_type == "sub_gift":
                        sub_gift_data = event_data.get("sub_gift", {})
                        tier = sub_gift_data.get("sub_tier")
                        tier_name = tier_mapping.get(tier, tier)
                        recipient_user_id = sub_gift_data.get("recipient_user_id")
                        # Track the recipient to prevent duplicate sub notification
                        if recipient_user_id:
                            gift_sub_recipients[recipient_user_id] = time.time()
                        create_task(process_giftsub_event(
                            event_data["chatter_user_name"],
                            tier_name,
                            1,  # Single gift
                            event_data.get("chatter_is_anonymous", False),
                            sub_gift_data.get("cumulative_total")
                        ))
                    elif notice_type == "community_sub_gift":
                        community_gift_data = event_data.get("community_sub_gift", {})
                        tier = community_gift_data.get("sub_tier")
                        tier_name = tier_mapping.get(tier, tier)
                        create_task(process_giftsub_event(
                            event_data["chatter_user_name"],
                            tier_name,
                            community_gift_data.get("count", 0),
                            event_data.get("chatter_is_anonymous", False),
                            community_gift_data.get("cumulative_total")
                        ))
                    elif notice_type == "pay_it_forward":
                        pay_it_forward_data = event_data.get("pay_it_forward", {})
                        tier = pay_it_forward_data.get("sub_tier")
                        tier_name = tier_mapping.get(tier, tier)
                        gifter_user_name = pay_it_forward_data.get("gifter_user_name")
                        # Fetch pay it forward message from database
                        await cursor.execute("SELECT alert_message FROM twitch_chat_alerts WHERE alert_type = %s", ("pay_it_forward",))
                        result = await cursor.fetchone()
                        if result and result.get("alert_message"):
                            pif_message = result.get("alert_message")
                        else:
                            if gifter_user_name:
                                pif_message = "Thank you (user) for paying it forward! They received a (tier) gift from (gifter) and gifted a (tier) subscription in return!"
                            else:
                                pif_message = "Thank you (user) for paying it forward with a (tier) subscription!"
                        # Replace placeholders
                        pif_message = pif_message.replace("(user)", event_data['chatter_user_name'])
                        pif_message = pif_message.replace("(tier)", tier_name)
                        if gifter_user_name:
                            pif_message = pif_message.replace("(gifter)", gifter_user_name)
                        await send_chat_message(pif_message)
                        # Process the gift subscription (skip alert since we already sent custom message)
                        create_task(process_giftsub_event(
                            event_data["chatter_user_name"],
                            tier_name,
                            1,  # Pay it forward is a single gift
                            event_data.get("chatter_is_anonymous", False),
                            None,
                            skip_alert=True  # Skip duplicate alert
                        ))
                        event_logger.info(f"Pay it forward: {event_data['chatter_user_name']} paid forward a {tier_name} subscription (received from {gifter_user_name})")
                    elif notice_type == "gift_paid_upgrade":
                        gift_paid_upgrade_data = event_data.get("gift_paid_upgrade", {})
                        tier = gift_paid_upgrade_data.get("sub_tier")
                        tier_name = tier_mapping.get(tier, tier)
                        # Fetch upgrade message from database
                        await cursor.execute("SELECT alert_message FROM twitch_chat_alerts WHERE alert_type = %s", ("gift_paid_upgrade",))
                        result = await cursor.fetchone()
                        if result and result.get("alert_message"):
                            upgrade_message = result.get("alert_message")
                        else:
                            upgrade_message = "Thank you (user) for upgrading from a Gifted Sub to a paid (tier) subscription!"
                        # Replace placeholders
                        upgrade_message = upgrade_message.replace("(user)", event_data['chatter_user_name'])
                        upgrade_message = upgrade_message.replace("(tier)", tier_name)
                        await send_chat_message(upgrade_message)
                        # Process the subscription data
                        create_task(process_subscription_event(
                            event_data["chatter_user_id"],
                            event_data["chatter_user_name"],
                            tier_name,
                            1,  # Gift paid upgrade starts their own subscription at month 1
                            is_upgrade=True  # Skip sending duplicate subscription alert
                        ))
                        event_logger.info(f"Gift paid upgrade: {event_data['chatter_user_name']} upgraded from Gifted Sub to paid {tier_name} subscription")
                    elif notice_type == "prime_paid_upgrade":
                        prime_paid_upgrade_data = event_data.get("prime_paid_upgrade", {})
                        tier = prime_paid_upgrade_data.get("sub_tier")
                        tier_name = tier_mapping.get(tier, tier)
                        # Fetch upgrade message from database
                        await cursor.execute("SELECT alert_message FROM twitch_chat_alerts WHERE alert_type = %s", ("prime_paid_upgrade",))
                        result = await cursor.fetchone()
                        if result and result.get("alert_message"):
                            upgrade_message = result.get("alert_message")
                        else:
                            upgrade_message = "Thank you (user) for upgrading from Prime Gaming to a paid (tier) subscription!"
                        # Replace placeholders
                        upgrade_message = upgrade_message.replace("(user)", event_data['chatter_user_name'])
                        upgrade_message = upgrade_message.replace("(tier)", tier_name)
                        await send_chat_message(upgrade_message)
                        # Process the subscription data
                        create_task(process_subscription_event(
                            event_data["chatter_user_id"],
                            event_data["chatter_user_name"],
                            tier_name,
                            1,  # Prime paid upgrade starts their own subscription at month 1
                            is_upgrade=True  # Skip sending duplicate subscription alert
                        ))
                        event_logger.info(f"Prime paid upgrade: {event_data['chatter_user_name']} upgraded from Prime Gaming to paid {tier_name} subscription")
                    elif notice_type == "raid":
                        # Detect outgoing raid initiated by broadcaster (sent)
                        chatter_user_id = event_data.get("chatter_user_id")
                        if chatter_user_id == CHANNEL_ID:
                            system_msg = event_data.get("system_message", "") or ""
                            target = None
                            m = re.search(r'raiding\s+@?([A-Za-z0-9_\-]+)', system_msg, re.IGNORECASE)
                            if m:
                                target = m.group(1)
                            else:
                                # try message fragments
                                fragments = event_data.get("message", {}).get("fragments", [])
                                text = "".join([f.get("text","") for f in fragments if f.get("type") == "text"])
                                m2 = re.search(r'raiding\s+@?([A-Za-z0-9_\-]+)', text, re.IGNORECASE)
                                if m2:
                                    target = m2.group(1)
                                else:
                                    # last resort, look for raid subobject
                                    raid_obj = event_data.get("raid") or {}
                                    target = raid_obj.get("user_name") or raid_obj.get("user_login") or "<unknown>"
                            viewers_sent = 0
                            try:
                                async with httpClientSession() as session:
                                    headers = {'Client-ID': CLIENT_ID, 'Authorization': f'Bearer {CHANNEL_AUTH}'}
                                    async with session.get(f'https://api.twitch.tv/helix/streams?user_login={CHANNEL_NAME}&type=live', headers=headers) as resp:
                                        data = await resp.json()
                                        if data.get('data'):
                                            viewers_sent = int(data['data'][0].get('viewer_count', 0))
                            except Exception as e:
                                event_logger.error(f"Failed to fetch viewer count for outgoing raid: {e}")
                            pending_outgoing_raid = {'target': target, 'viewers': viewers_sent, 'timestamp': time.time()}
                            event_logger.info(f"Held outgoing raid to {target} with {viewers_sent} viewers until stream offline.")
                            # Start (or restart) a background task that waits until the stream goes offline and persists the raid
                            try:
                                if outgoing_raid_task and not outgoing_raid_task.done():
                                    try:
                                        outgoing_raid_task.cancel()
                                    except Exception:
                                        pass
                                outgoing_raid_task = create_task(wait_and_persist_outgoing_raid())
                            except Exception as e:
                                event_logger.error(f"Failed to start outgoing raid persistence task: {e}")
                        else:
                            # an incoming raid that is also delivered through chat notification; handled by channel.raid
                            pass
                    elif notice_type == "unraid":
                        # If broadcaster canceled outgoing raid
                        chatter_user_id = event_data.get("chatter_user_id")
                        if chatter_user_id == CHANNEL_ID:
                            pending_outgoing_raid = None
                            if outgoing_raid_task and not outgoing_raid_task.done():
                                try:
                                    outgoing_raid_task.cancel()
                                except Exception:
                                    pass
                                outgoing_raid_task = None
                            event_logger.info("Outgoing raid canceled (unraid)")
                # Cheer Event
                elif event_type == "channel.bits.use":
                    create_task(process_cheer_event(
                        event_data["user_id"],
                        event_data["user_name"],
                        event_data["bits"]
                    ))
                # Raid Event
                elif event_type == "channel.raid":
                    create_task(process_raid_event(
                        event_data["from_broadcaster_user_id"],
                        event_data["from_broadcaster_user_name"],
                        event_data["viewers"]
                    ))
                # Hype Train Begin Event
                elif event_type == "channel.hype_train.begin":
                    event_logger.info(f"Hype Train Start Event Data: {event_data}")
                    level = event_data["level"]
                    await cursor.execute("SELECT alert_message FROM twitch_chat_alerts WHERE alert_type = %s", ("hype_train_start",))
                    result = await cursor.fetchone()
                    if result and result.get("alert_message"):
                        alert_message = result.get("alert_message")
                    else:
                        alert_message = "The Hype Train has started! Starting at level: (level)"
                    alert_message = alert_message.replace("(level)", str(level))
                    await send_chat_message(alert_message)
                    await cursor.execute("SELECT * FROM twitch_sound_alerts WHERE twitch_alert_id = %s", ("Hype Train Start",))
                    result = await cursor.fetchone()
                    if result and result.get("sound_mapping"):
                        sound_file = "twitch/" + result.get("sound_mapping")
                        create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
                # Hype Train End Event
                elif event_type == "channel.hype_train.end":
                    event_logger.info(f"Hype Train End Event Data: {event_data}")
                    level = event_data["level"]
                    await cursor.execute("SELECT alert_message FROM twitch_chat_alerts WHERE alert_type = %s", ("hype_train_end",))
                    result = await cursor.fetchone()
                    if result and result.get("alert_message"):
                        alert_message = result.get("alert_message")
                    else:
                        alert_message = "The Hype Train has ended at level: (level)!"
                    alert_message = alert_message.replace("(level)", str(level))
                    await send_chat_message(alert_message)
                    await cursor.execute("SELECT * FROM twitch_sound_alerts WHERE twitch_alert_id = %s", ("Hype Train End",))
                    result = await cursor.fetchone()
                    if result and result.get("sound_mapping"):
                        sound_file = "twitch/" + result.get("sound_mapping")
                        create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
                # Channel Update Event
                elif event_type == 'channel.update':
                    global current_game
                    global stream_title
                    title = event_data["title"]
                    category_name = event_data["category_name"]
                    stream_title = title
                    current_game = category_name
                    event_logger.info(f"Channel Updated with the following data: Title: {stream_title}. Category: {category_name}.")
                # Ad Break Begin Event
                elif event_type == 'channel.ad_break.begin':
                    create_task(handle_ad_break_start(event_data["duration_seconds"]))
                # Charity Campaign Donate Event
                elif event_type == 'channel.charity_campaign.donate':
                    user = event_data["user_name"]
                    charity = event_data["charity_name"]
                    value = event_data["amount"]["value"]
                    currency = event_data["amount"]["currency"]
                    value_formatted = "{:,.2f}".format(value)
                    message = f"Thank you so much {user} for your ${value_formatted}{currency} donation to {charity}. Your support means so much to us and to {charity}."
                    await send_chat_message(message)
                # Moderation Event
                elif event_type == 'channel.moderate':
                    moderator_user_name = event_data.get("moderator_user_name", "Unknown Moderator")
                    action = event_data.get("action")
                    # Skip logging raid actions as they are handled separately
                    if action == "raid":
                        return
                    # Log the moderation action
                    event_logger.info(f"Moderation action '{action}' performed by {moderator_user_name}")
                    # Handle different moderation actions
                    if action == "timeout":
                        timeout_info = event_data.get("timeout", {})
                        user_name = timeout_info.get("user_name", "Unknown User")
                        reason = timeout_info.get("reason", "No reason provided")
                        expires_at_str = timeout_info.get("expires_at")
                        if expires_at_str:
                            expires_at = datetime.strptime(expires_at_str, "%Y-%m-%dT%H:%M:%SZ")
                            expires_at_formatted = expires_at.strftime("%Y-%m-%d %H:%M:%S UTC")
                        else:
                            expires_at_formatted = "No expiration time provided"
                        event_logger.info(f"User {user_name} timed out by {moderator_user_name} for: {reason}. Expires at: {expires_at_formatted}")
                    elif action == "untimeout":
                        untimeout_info = event_data.get("untimeout", {})
                        user_name = untimeout_info.get("user_name", "Unknown User")
                        event_logger.info(f"User {user_name} untimed out by {moderator_user_name}")
                    elif action == "ban":
                        ban_info = event_data.get("ban", {})
                        user_name = ban_info.get("user_name", "Unknown User")
                        reason = ban_info.get("reason", "No reason provided")
                        event_logger.info(f"User {user_name} banned by {moderator_user_name} for: {reason}")
                    elif action == "unban":
                        unban_info = event_data.get("unban", {})
                        user_name = unban_info.get("user_name", "Unknown User")
                        event_logger.info(f"User {user_name} unbanned by {moderator_user_name}")
                    elif action == "warn":
                        warn_info = event_data.get("warn", {})
                        user_name = warn_info.get("user_name", "Unknown User")
                        reason = warn_info.get("reason", "No reason provided")
                        chat_rules_cited = warn_info.get("chat_rules_cited")
                        rules_text = f" (Chat rules cited: {chat_rules_cited})" if chat_rules_cited else ""
                        event_logger.info(f"User {user_name} warned by {moderator_user_name} for: {reason}{rules_text}")
                    elif action == "mod":
                        mod_info = event_data.get("mod", {})
                        user_name = mod_info.get("user_name", "Unknown User")
                        event_logger.info(f"User {user_name} added as moderator by {moderator_user_name}")
                    elif action == "unmod":
                        unmod_info = event_data.get("unmod", {})
                        user_name = unmod_info.get("user_name", "Unknown User")
                        event_logger.info(f"User {user_name} removed as moderator by {moderator_user_name}")
                    elif action == "vip":
                        vip_info = event_data.get("vip", {})
                        user_name = vip_info.get("user_name", "Unknown User")
                        event_logger.info(f"User {user_name} added as VIP by {moderator_user_name}")
                    elif action == "unvip":
                        unvip_info = event_data.get("unvip", {})
                        user_name = unvip_info.get("user_name", "Unknown User")
                        event_logger.info(f"User {user_name} removed as VIP by {moderator_user_name}")
                    elif action == "delete":
                        delete_info = event_data.get("delete", {})
                        user_name = delete_info.get("user_name", "Unknown User")
                        message_id = delete_info.get("message_id", "Unknown")
                        event_logger.info(f"Message deleted from user {user_name} by {moderator_user_name} (Message ID: {message_id})")
                    elif action == "automod_terms":
                        automod_info = event_data.get("automod_terms", {})
                        terms = automod_info.get("terms", [])
                        event_logger.info(f"AutoMod terms updated by {moderator_user_name}: {terms}")
                    elif action == "unban_request":
                        unban_request_info = event_data.get("unban_request", {})
                        event_logger.info(f"Unban request handled by {moderator_user_name}")
                    elif action == "shared_chat_ban":
                        shared_ban_info = event_data.get("shared_chat_ban", {})
                        user_name = shared_ban_info.get("user_name", "Unknown User")
                        reason = shared_ban_info.get("reason", "No reason provided")
                        source_broadcaster = event_data.get("source_broadcaster_user_name", "Unknown Channel")
                        event_logger.info(f"User {user_name} banned in shared chat by {moderator_user_name} from {source_broadcaster} for: {reason}")
                    elif action == "shared_chat_unban":
                        shared_unban_info = event_data.get("shared_chat_unban", {})
                        user_name = shared_unban_info.get("user_name", "Unknown User")
                        source_broadcaster = event_data.get("source_broadcaster_user_name", "Unknown Channel")
                        event_logger.info(f"User {user_name} unbanned in shared chat by {moderator_user_name} from {source_broadcaster}")
                    elif action == "shared_chat_timeout":
                        shared_timeout_info = event_data.get("shared_chat_timeout", {})
                        user_name = shared_timeout_info.get("user_name", "Unknown User")
                        reason = shared_timeout_info.get("reason", "No reason provided")
                        expires_at_str = shared_timeout_info.get("expires_at")
                        if expires_at_str:
                            expires_at = datetime.strptime(expires_at_str, "%Y-%m-%dT%H:%M:%SZ")
                            expires_at_formatted = expires_at.strftime("%Y-%m-%d %H:%M:%S UTC")
                        else:
                            expires_at_formatted = "No expiration time provided"
                        source_broadcaster = event_data.get("source_broadcaster_user_name", "Unknown Channel")
                        event_logger.info(f"User {user_name} timed out in shared chat by {moderator_user_name} from {source_broadcaster} for: {reason}. Expires at: {expires_at_formatted}")
                    elif action == "shared_chat_untimeout":
                        shared_untimeout_info = event_data.get("shared_chat_untimeout", {})
                        user_name = shared_untimeout_info.get("user_name", "Unknown User")
                        source_broadcaster = event_data.get("source_broadcaster_user_name", "Unknown Channel")
                        event_logger.info(f"User {user_name} untimed out in shared chat by {moderator_user_name} from {source_broadcaster}")
                    elif action == "shared_chat_delete":
                        shared_delete_info = event_data.get("shared_chat_delete", {})
                        user_name = shared_delete_info.get("user_name", "Unknown User")
                        message_id = shared_delete_info.get("message_id", "Unknown")
                        source_broadcaster = event_data.get("source_broadcaster_user_name", "Unknown Channel")
                        event_logger.info(f"Message deleted from user {user_name} in shared chat by {moderator_user_name} from {source_broadcaster} (Message ID: {message_id})")
                    # Handle mode changes (actions without specific user data)
                    elif action in ["emoteonly", "emoteonlyoff", "followers", "followersoff", "slow", "slowoff", "subscribers", "subscribersoff", "uniquechat", "uniquechatoff", "clear"]:
                        event_logger.info(f"Chat mode '{action}' activated by {moderator_user_name}")
                    else:
                        # Log unknown actions for debugging
                        event_logger.error(f"Unknown moderation action '{action}' received: {event_data}")
                    # Send moderation event to websocket for Discord logging
                    create_task(websocket_notice(event="MODERATION", additional_data=event_data))
                # Channel Point Rewards Event
                elif event_type in [
                    "channel.channel_points_automatic_reward_redemption.add", 
                    "channel.channel_points_custom_reward_redemption.add"
                    ]:
                    create_task(process_channel_point_rewards(event_data, event_type))
                # Poll Event
                elif event_type in ["channel.poll.begin", "channel.poll.end"]:
                    if event_type == "channel.poll.begin":
                        poll_id = event_data.get("id")
                        poll_title = event_data.get("title")
                        poll_ends_at = datetime.fromisoformat(event_data["ends_at"].replace("Z", "+00:00"))
                        utc_now = time_right_now(timezone.utc)
                        time_until_end = (poll_ends_at - utc_now).total_seconds()
                        half_time = int(time_until_end / 2)
                        minutes, seconds = divmod(time_until_end, 60)
                        if minutes and seconds:
                            message = f"Poll '{poll_title}' has started! Poll ending in {int(minutes)} minutes and {int(seconds)} seconds."
                        elif minutes:
                            message = f"Poll '{poll_title}' has started! Poll ending in {int(minutes)} minutes."
                        else:
                            message = f"Poll '{poll_title}' has started! Poll ending in {int(seconds)} seconds."
                        create_task(handel_twitch_poll(event="poll.begin", poll_title=poll_title, half_time=half_time, message=message))
                    elif event_type == "channel.poll.end":
                        poll_id = event_data.get("id")
                        poll_title = event_data.get("title")
                        choices_data = []
                        for choice in event_data.get("choices", []):
                            choices_data.append({
                                "title": choice.get("title"),
                                "bits_votes": choice.get("bits_votes") if event_data.get("bits_voting", {}).get("is_enabled") else 0,
                                "channel_points_votes": choice.get("channel_points_votes") if event_data.get("channel_points_voting", {}).get("is_enabled") else 0,
                                "total_votes": choice.get("votes", 0)
                            })
                        sorted_choices = sorted(choices_data, key=lambda x: x["total_votes"], reverse=True)
                        message = f"The poll '{poll_title}' has ended!"
                        await cursor.execute("INSERT INTO poll_results (poll_id, poll_name) VALUES (%s, %s)", (poll_id, poll_title))
                        await connection.commit()
                        sql_options = ["one", "two", "three", "four", "five"]
                        sql_query = "UPDATE poll_results SET " + ", ".join([f"poll_option_{i+1} = %s" for i in range(len(sql_options))]) + " WHERE poll_id = %s"
                        params = [sorted_choices[i]["title"] if i < len(sorted_choices) else None for i in range(len(sql_options))] + [poll_id]
                        await cursor.execute(sql_query, params)
                        await connection.commit()
                        create_task(handel_twitch_poll(event="poll.end", poll_title=poll_title, message=message))
                # Stream Online/Offline Event
                elif event_type in ["stream.online", "stream.offline"]:
                    # Reset ad break count in database
                    try:
                        await cursor.execute("INSERT INTO stream_session_stats (id, ad_break_count) VALUES (1, 0) ON DUPLICATE KEY UPDATE ad_break_count = 0")
                        await connection.commit()
                        event_logger.info(f"Reset ad break count for {event_type}")
                    except Exception as e:
                        event_logger.error(f"Error resetting ad break count: {e}")
                    if event_type == "stream.online":
                        bot_logger.info(f"Stream is now online!")
                        create_task(websocket_notice(event="STREAM_ONLINE"))
                    else:
                        bot_logger.info(f"Stream is now offline.")
                        create_task(websocket_notice(event="STREAM_OFFLINE"))
                # AutoMod Message Hold Event
                elif event_type == "automod.message.hold":
                    event_logger.info(f"Got an AutoMod Message Hold: {event_data}")
                    messageContent = event_data["message"]["text"]
                    messageAuthor = event_data["user_name"]
                    messageAuthorID = event_data["user_id"]
                    messageHoldID = event_data["message_id"]
                    spam_pattern = await get_spam_patterns()
                    for pattern in spam_pattern:
                        if pattern.search(messageContent):
                            twitch_logger.info(f"Banning user {messageAuthor} with ID {messageAuthorID} for spam pattern match.")
                            create_task(ban_user(messageAuthor, messageAuthorID))
                            # Deny the message via Twitch API
                            try:
                                # Determine which user ID to use for the API request
                                use_streamer = False  # Use bot token to make it appear as bot denied
                                api_user_id = CHANNEL_ID if use_streamer else "971436498"
                                # Fetch settings from the twitch_bot_access table
                                await cursor.execute("SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = %s LIMIT 1", (api_user_id,))
                                result = await cursor.fetchone()
                                # Use the token from the database if found, otherwise default to CHANNEL_AUTH
                                api_user_auth = result.get('twitch_access_token') if result else CHANNEL_AUTH
                                async with httpClientSession() as session:
                                    headers = {"Authorization": f"Bearer {api_user_auth}", "Client-Id": CLIENT_ID, "Content-Type": "application/json"}
                                    body = {"user_id": messageAuthorID, "msg_id": messageHoldID, "action": "DENY"}
                                    async with session.post("https://api.twitch.tv/helix/moderation/automod/message", headers=headers, json=body) as response:
                                        if response.status == 204:
                                            twitch_logger.info(f"Denied message with ID {messageHoldID} for spam pattern.")
                                        else:
                                            twitch_logger.error(f"Failed to deny message {messageHoldID}: {response.status}")
                            except Exception as e:
                                twitch_logger.error(f"Error denying message {messageHoldID}: {e}")
                # User Message Hold Event
                elif event_type == "channel.chat.user_message_hold":
                    event_logger.info(f"Got a User Message Hold in Chat: {event_data}")
                    messageContent = event_data["message"]["text"]
                    messageAuthor = event_data["user_name"]
                    messageAuthorID = event_data["user_id"]
                    messageHoldID = event_data["message_id"]
                    spam_pattern = await get_spam_patterns()
                    for pattern in spam_pattern:
                        if pattern.search(messageContent):
                            twitch_logger.info(f"Banning user {messageAuthor} with ID {messageAuthorID} for spam pattern match.")
                            create_task(ban_user(messageAuthor, messageAuthorID))
                            # Deny the message via Twitch API
                            try:
                                # Determine which user ID to use for the API request
                                use_streamer = False  # Use bot token to make it appear as bot denied
                                api_user_id = CHANNEL_ID if use_streamer else "971436498"
                                # Fetch settings from the twitch_bot_access table
                                await cursor.execute("SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = %s LIMIT 1", (api_user_id,))
                                result = await cursor.fetchone()
                                # Use the token from the database if found, otherwise default to CHANNEL_AUTH
                                api_user_auth = result.get('twitch_access_token') if result else CHANNEL_AUTH
                                async with httpClientSession() as session:
                                    headers = {"Authorization": f"Bearer {api_user_auth}", "Client-Id": CLIENT_ID, "Content-Type": "application/json"}
                                    body = {"user_id": messageAuthorID, "msg_id": messageHoldID, "action": "DENY"}
                                    async with session.post("https://api.twitch.tv/helix/moderation/automod/message", headers=headers, json=body) as response:
                                        if response.status == 204:
                                            twitch_logger.info(f"Denied message with ID {messageHoldID} for spam pattern.")
                                        else:
                                            twitch_logger.error(f"Failed to deny message {messageHoldID}: {response.status}")
                            except Exception as e:
                                twitch_logger.error(f"Error denying message {messageHoldID}: {e}")
                # Suspicious User Message Event
                elif event_type == "channel.suspicious_user.message":
                    spam_pattern = await get_spam_patterns()
                    event_logger.info(f"Got a Suspicious User Message: {event_data}")
                    messageContent = event_data["message"]["text"]
                    messageAuthor = event_data["user_name"]
                    messageAuthorID = event_data["user_id"]
                    lowTrustStatus = event_data["low_trust_status"]
                    banEvasionTypes = event_data["types"]
                    banEvasionEvaluation = event_data["ban_evasion_evaluation"]
                    if banEvasionEvaluation:
                        twitch_logger.info(f"Suspicious user {messageAuthor} has ban evasion evaluation: {banEvasionEvaluation}")
                        if banEvasionEvaluation == "likely":
                            bot_logger.info(f"Banning suspicious user {messageAuthor} with ID {messageAuthorID} due to likely ban evasion.")
                            create_task(ban_user(messageAuthor, messageAuthorID))
                    if banEvasionTypes:
                        twitch_logger.info(f"Suspicious user {messageAuthor} has the following types: {banEvasionTypes}")
                    if lowTrustStatus == "active_monitoring":
                        bot_logger.info(f"Banning suspicious user {messageAuthor} with ID {messageAuthorID} due to active monitoring status.")
                        create_task(ban_user(messageAuthor, messageAuthorID))
                    for pattern in spam_pattern:
                        if pattern.search(messageContent):
                            twitch_logger.info(f"Banning user {messageAuthor} with ID {messageAuthorID} for spam pattern match.")
                            create_task(ban_user(messageAuthor, messageAuthorID))
                elif event_type == "channel.shoutout.create" or event_type == "channel.shoutout.receive":
                    if event_type == "channel.shoutout.create":
                        global shoutout_user
                        user_id = event_data['to_broadcaster_user_id']
                        user_to_shoutout = event_data['to_broadcaster_user_name']
                        # Check if this shoutout was triggered by a command (stored in shoutout_user dict)
                        if user_to_shoutout in shoutout_user:
                            twitch_logger.info(f"Skipping EventSub shoutout message for {user_to_shoutout} - command-triggered shoutout already sent message.")
                            return
                        # This is a manual/UI-triggered shoutout, send a chat message
                        twitch_logger.info(f"Processing manual/UI-triggered shoutout for {user_to_shoutout}")
                        shoutout_message = await get_shoutout_message(user_id, user_to_shoutout, "eventsub")
                    elif event_type == "channel.shoutout.receive":
                        shoutout_message = f"@{event_data['from_broadcaster_user_name']} has given @{CHANNEL_NAME} a shoutout."
                    else:
                        shoutout_message = f"Sorry, @{CHANNEL_NAME}, I see a shoutout, however I was unable to get the correct information from twitch to process the request."
                    await send_chat_message(shoutout_message)
                    twitch_logger.info(f"Shoutout message sent: {shoutout_message}")
                elif event_type == "channel.chat.message":
                    if event_data.get("source_broadcaster_user_id") and event_data["source_broadcaster_user_id"] != CHANNEL_ID:
                        return
                    chatter_user_id = event_data["chatter_user_id"]
                    chatter_user_name = event_data["chatter_user_name"]
                    message_text = event_data["message"]["text"]
                    # Capture chat for AI Ad Breaks
                    try:
                        # Skip if it matches an auto-posted timed message
                        is_auto_message = False
                        auto_messages = [m.get('message') for m in active_timed_messages.values() if m.get('message')]
                        if message_text in auto_messages:
                            is_auto_message = True
                        if not is_auto_message:
                            Path(AD_BREAK_CHAT_DIR).mkdir(parents=True, exist_ok=True)
                            chat_file = Path(AD_BREAK_CHAT_DIR) / f"{CHANNEL_NAME}.json"
                            chat_entry = {
                                "user": chatter_user_name,
                                "message": message_text,
                                "timestamp": time.time()
                            }
                            current_chat = []
                            if chat_file.exists():
                                try:
                                    with chat_file.open('r', encoding='utf-8') as f:
                                        content = f.read()
                                        if content:
                                            current_chat = json.loads(content)
                                except json.JSONDecodeError:
                                    # If file is corrupted, start fresh
                                    current_chat = []
                                except Exception as e:
                                    event_logger.error(f"Error reading ad break chat log: {e}")
                            current_chat.append(chat_entry)
                            with chat_file.open('w', encoding='utf-8') as f:
                                json.dump(current_chat, f, ensure_ascii=False, indent=2)
                    except Exception as e:
                        event_logger.error(f"Error logging chat for ad break: {e}")
                    create_task(process_chat_message_event(chatter_user_id, chatter_user_name, message_text))
                else:
                    # Logging for unknown event types
                    twitch_logger.error(f"Received message with unknown event type: {event_type}")
    except Exception as e:
        event_logger.error(f"Error processing EventSub message: {e}")
    finally:
        pass

# Connect and manage reconnection for Internal Socket Server
async def specter_websocket():
    global websocket_connected, specterSocket
    specter_websocket_uri = "https://websocket.botofthespecter.com"
    # Reconnection parameters
    reconnect_delay = 60  # Fixed 60 second delay for each reconnection attempt
    consecutive_failures = 0
    while True:
        try:
            # Ensure clean state before connection attempt
            websocket_connected = False
            # Disconnect existing connection if any
            if specterSocket and specterSocket.connected:
                try:
                    await specterSocket.disconnect()
                    websocket_logger.info("Disconnected existing WebSocket connection before reconnection attempt")
                except Exception as disconnect_error:
                    websocket_logger.error(f"Error disconnecting existing connection: {disconnect_error}")
            # Wait 60 seconds before each reconnection attempt (server takes min 2 mins to reboot)
            if consecutive_failures > 0:
                # Add small jitter to prevent multiple instances from reconnecting simultaneously
                jitter = random.uniform(0, 5)  # 0-5 second jitter
                total_delay = reconnect_delay + jitter
                websocket_logger.info(f"Reconnection attempt {consecutive_failures}, waiting {total_delay:.1f} seconds (server reboot consideration)")
                await sleep(total_delay)
            # Attempt to connect to the WebSocket server using websocket transport directly
            bot_logger.info(f"Attempting to connect to Internal WebSocket Server (attempt {consecutive_failures + 1})")
            await specterSocket.connect(specter_websocket_uri, transports=['websocket'])
            # Wait for connection to be established and registered
            connection_timeout = 30  # 30 second timeout for connection + registration
            start_time = time_right_now()
            while not websocket_connected:
                if (time_right_now() - start_time).total_seconds() > connection_timeout:
                    raise asyncioTimeoutError("Connection establishment and registration timeout")
                await sleep(0.5)
            # Reset failure counter on successful connection
            consecutive_failures = 0
            websocket_logger.info("Successfully connected and registered with Internal WebSocket Server")
            # Keep the connection alive and handle messages
            await specterSocket.wait()
        except ConnectionExecptionError as e:
            consecutive_failures += 1
            websocket_connected = False
            websocket_logger.error(f"Internal WebSocket Connection Failed (attempt {consecutive_failures}): {e}")
        except asyncioTimeoutError as e:
            consecutive_failures += 1
            websocket_connected = False
            websocket_logger.error(f"Internal WebSocket Connection Timeout (attempt {consecutive_failures}): {e}")
        except Exception as e:
            consecutive_failures += 1
            websocket_connected = False
            websocket_logger.error(f"Unexpected error with Internal WebSocket (attempt {consecutive_failures}): {e}")
        # Connection lost or failed, prepare for reconnection
        websocket_connected = False
        websocket_logger.error(f"WebSocket connection lost, preparing for reconnection attempt {consecutive_failures + 1}")
        # Small delay before next iteration to prevent tight loop
        await sleep(1)

@specterSocket.event
async def connect():
    global websocket_connected
    websocket_logger.info("WebSocket connection established, attempting registration...")
    websocket_logger.info(f"Session ID: {specterSocket.sid}")
    websocket_logger.info(f"Transport: {specterSocket.transport()}")
    registration_data = {
        'code': API_TOKEN,
        'channel': CHANNEL_NAME,
        'name': f'Twitch Bot {SYSTEM} V{VERSION}'
    }
    try:
        await specterSocket.emit('REGISTER', registration_data)
        websocket_logger.info("Client registration sent successfully")
        websocket_connected = True  # Set flag to true only after successful registration
        websocket_logger.info("Successfully registered with internal websocket server")
    except Exception as e:
        websocket_logger.error(f"Failed to register client: {e}")
        websocket_connected = False  # Set flag to false if registration fails
        # Disconnect to trigger reconnection
        try:
            await specterSocket.disconnect()
        except Exception:
            pass

@specterSocket.event
async def connect_error(data):
    global websocket_connected
    websocket_connected = False  # Ensure flag is set to false on connection error
    websocket_logger.error(f"WebSocket connection error: {data}")
    websocket_logger.info("Connection will be retried automatically")

@specterSocket.event
async def disconnect():
    global websocket_connected
    websocket_connected = False  # Set flag to false when disconnected
    websocket_logger.error("Client disconnected from internal websocket server")
    websocket_logger.info("WebSocket will attempt to reconnect automatically")

@specterSocket.event
async def message(data):
    websocket_logger.info(f"Message received: {data}")

@specterSocket.event
async def STREAM_ONLINE(data):
    websocket_logger.info(f"Stream online event received: {data}")
    try:
        await process_stream_online_websocket()
    except Exception as e:
        websocket_logger.error(f"Failed to process stream online event: {e}")

@specterSocket.event
async def STREAM_OFFLINE(data):
    websocket_logger.info(f"Stream offline event received: {data}")
    try:
        await process_stream_offline_websocket()
    except Exception as e:
        websocket_logger.error(f"Failed to process stream offline event: {e}")

@specterSocket.event
async def WEATHER_DATA(data):
    websocket_logger.info(f"Weather data received: {data}")
    try:
        await process_weather_websocket(data)
    except Exception as e:
        websocket_logger.error(f"Failed to process weather data: {e}")

@specterSocket.event
async def FOURTHWALL(data):
    websocket_logger.info(f"FourthWall event received: {data}")
    try:
        await process_fourthwall_event(data)
    except Exception as e:
        websocket_logger.error(f"Failed to process FourthWall event: {e}")

@specterSocket.event
async def KOFI(data):
    websocket_logger.info(f"Ko-fi event received: {data}")
    try:
        await process_kofi_event(data)
    except Exception as e:
        websocket_logger.error(f"Failed to process Ko-fi event: {e}")

@specterSocket.event
async def PATREON(data):
    websocket_logger.info(f"Patreon event received: {data}")
    try:
        await process_patreon_event(data)
    except Exception as e:
        websocket_logger.error(f"Failed to process Patreon event: {e}")

@specterSocket.event
async def CUSTOM_COMMAND(data):
    websocket_logger.info(f"Custom command event received: {data}")
    try:
        command = data.get('command')
        response = data.get('response')
        if not command or not response:
            websocket_logger.error(f"Missing command or response in custom command event: {data}")
            return
        # Process and send the custom command
        await process_custom_command_variables(command, response, user="API", send_to_chat=True)
        websocket_logger.info(f"Custom command '{command}' executed successfully via API")
    except Exception as e:
        websocket_logger.error(f"Failed to process custom command event: {e}")

@specterSocket.event
async def RAFFLE_WINNER(data):
    websocket_logger.info(f"Raffle winner event received: {data}")
    try:
        raffle_name = data.get('raffle_name') or data.get('raffle')
        winner = data.get('winner')
        if not raffle_name or not winner:
            websocket_logger.error(f"Missing raffle_name or winner in RAFFLE_WINNER event: {data}")
            return
        # Announce in chat
        try:
            await send_chat_message(f"🎉 Congratulations @{winner}! You won the raffle: {raffle_name} 🎉")
        except Exception as e:
            websocket_logger.error(f"Failed to send raffle winner chat message: {e}")
    except Exception as e:
        websocket_logger.error(f"Failed to process RAFFLE_WINNER event: {e}")

@specterSocket.event
async def SYSTEM_UPDATE(data):
    websocket_logger.info(f"System update event received: {data}")
    try:
        # Fetch version information from API
        async with httpClientSession() as session:
            async with session.get("https://api.botofthespecter.com/versions") as response:
                if response.status == 200:
                    version_data = await response.json()
                    # Select appropriate version based on SYSTEM variable
                    if SYSTEM == "BETA":
                        latest_version = version_data.get("beta_version")
                    elif SYSTEM == "STABLE":
                        latest_version = version_data.get("stable_version")
                    else:
                        websocket_logger.error(f"Unknown SYSTEM value: {SYSTEM}")
                        return
                    # Only send message if versions differ
                    if latest_version and latest_version != VERSION:
                        message = f"I have a new update ready ({latest_version}), please restart me from the dashboard when you are ready."
                        await send_chat_message(message)
                        websocket_logger.info(f"Update notification sent for version {latest_version}")
                    else:
                        websocket_logger.info(f"No update needed. Current version: {VERSION}, Latest version: {latest_version}")
                else:
                    websocket_logger.error(f"Failed to fetch version data from API: HTTP {response.status}")
    except Exception as e:
        websocket_logger.error(f"Failed to process system update event: {e}")

@specterSocket.event
async def OBS_EVENT_RECEIVED(data):
    websocket_logger.info(f"OBS event received: {data}")
    try:
        # Extract action and scene information from the data
        action = data.get('action')
        scene = data.get('scene')
        # Handle set_current_program_scene action
        if action == 'set_current_program_scene':
            if scene:
                websocket_logger.info(f"OBS scene successfully changed to: {scene}")
                await send_chat_message(f"OBS scene changed to {scene}!")
            else:
                websocket_logger.error("Scene change action received but no scene name provided")
        # Handle other OBS actions as needed
        elif action:
            websocket_logger.info(f"OBS action executed: {action}")
            await send_chat_message(f"OBS action executed: {action}")
        else:
            websocket_logger.error(f"OBS event received but no action specified: {data}")
    except Exception as e:
        websocket_logger.error(f"Error processing OBS event: {e}", exc_info=True)

# Helper function for manual websocket reconnection (can be called from commands)
async def force_websocket_reconnect():
    global websocket_connected
    try:
        if specterSocket and specterSocket.connected:
            websocket_logger.info("Forcing websocket disconnection for reconnection")
            await specterSocket.disconnect()
        websocket_connected = False
        return True
    except Exception as e:
        websocket_logger.error(f"Error during forced reconnection: {e}")
        return False

# Helper function to check websocket connection status
def is_websocket_connected():
    global websocket_connected
    return websocket_connected

# Helper to safely redact sensitive values
def redact(s: str) -> str:
    return str(s).replace(HYPERATE_API_KEY, "[REDACTED]")

# Persistent WebSocket connection that stays open as long as heart rate data is received
async def hyperate_websocket_persistent():
    global HEARTRATE
    while True:
        try:
            # Check DB for heartrate code before attempting any websocket connection
            try:
                connection = await mysql_handler.get_connection()
                async with connection.cursor(DictCursor) as cursor:
                    await cursor.execute('SELECT heartrate_code FROM profile')
                    heartrate_code_data = await cursor.fetchone()
            finally:
                pass
            if not heartrate_code_data or not heartrate_code_data.get('heartrate_code'):
                bot_logger.info("HypeRate info: No Heart Rate Code found in database. Stopping websocket connection.")
                HEARTRATE = None
                return
            heartrate_code = heartrate_code_data['heartrate_code']
            bot_logger.info("HypeRate info: Attempting to connect to HypeRate Heart Rate WebSocket Server")
            hyperate_websocket_uri = f"wss://app.hyperate.io/socket/websocket?token={HYPERATE_API_KEY}"
            async with WebSocketConnect(hyperate_websocket_uri) as hyperate_websocket:
                bot_logger.info("HypeRate info: Successfully connected to the WebSocket")
                # Send 'phx_join' message to join the appropriate channel using the DB-provided code
                await join_channel(hyperate_websocket, heartrate_code)
                # Send the heartbeat every 10 seconds and keep a handle to cancel it later
                heartbeat_task = create_task(send_heartbeat(hyperate_websocket))
                try:
                    while True:
                        try:
                            raw = await hyperate_websocket.recv()
                        except WebSocketConnectionClosed:
                            bot_logger.error("HypeRate WebSocket connection closed, reconnecting...")
                            break
                        raw_sanitized = redact(raw)
                        try:
                            data = json.loads(raw)
                        except Exception as e:
                            bot_logger.error(
                                f"HypeRate warning: failed to parse incoming message: {redact(e)} - raw: {raw_sanitized[:200]}"
                            )
                            # Skip malformed messages without tearing down the connection
                            continue
                        payload = data.get("payload") if isinstance(data, dict) else None
                        event = data.get("event") if isinstance(data, dict) else None
                        # Only process hr_update events for heart rate data
                        if event == "hr_update" and isinstance(payload, dict):
                            hr = payload.get("hr")
                            if hr is None:
                                bot_logger.info("HypeRate info: Received None heart rate in hr_update event, closing persistent connection")
                                HEARTRATE = None
                                return  # Exit the function entirely, stopping the persistent connection
                            else:
                                # Update global with valid heart rate data
                                HEARTRATE = hr
                                bot_logger.debug(f"HypeRate info: Updated heart rate to {hr}")
                        # Ignore other message types (phx_reply, etc.) - they don't contain heart rate data
                finally:
                    try:
                        if 'heartbeat_task' in locals() and heartbeat_task and not heartbeat_task.done():
                            heartbeat_task.cancel()
                            try:
                                await heartbeat_task
                            except asyncioCancelledError:
                                pass
                    except Exception:
                        # Be defensive: nothing critical if cancelling fails
                        pass
        except Exception as e:
            bot_logger.error(f"HypeRate error: An unexpected error occurred with HypeRate Heart Rate WebSocket: {redact(e)}")
            await sleep(10)  # Retry connection after a brief wait

# Heartbeat sender for HypeRate Websocket
async def send_heartbeat(hyperate_websocket):
    while True:
        await sleep(10)  # Send heartbeat every 10 seconds
        heartbeat_payload = {
            "topic": "phoenix",
            "event": "heartbeat",
            "payload": {},
            "ref": 0
        }
        try:
            await hyperate_websocket.send(json.dumps(heartbeat_payload))
        except Exception as e:
            bot_logger.error(f"Error sending heartbeat: {redact(e)}")
            break

# Join HypeRate WebSocket channel
async def join_channel(hyperate_websocket, heartrate_code):
    try:
        if not heartrate_code:
            bot_logger.error("HypeRate error: No Heart Rate Code provided to join_channel, aborting join.")
            return
        # Construct the 'phx_join' event payload
        phx_join = {
            "topic": f"hr:{heartrate_code}",
            "event": "phx_join",
            "payload": {},
            "ref": 0
        }
        # Send the 'phx_join' event to join the channel
        await hyperate_websocket.send(json.dumps(phx_join))
    except Exception as e:
        bot_logger.error(f"HypeRate error: Error during 'join_channel' operation: {redact(e)}")

# Stream Bingo WebSocket integration
async def stream_bingo_websocket():
    global CHANNEL_ID
    integrations_logger.info("===== Stream Bingo =====")
    while True:
        try:
            # Retrieve Stream Bingo API key from database
            connection = await mysql_handler.get_connection()
            stream_bingo_api_key = None
            try:
                async with connection.cursor(DictCursor) as cursor:
                    await cursor.execute("SELECT stream_bounty_api_key FROM profile")
                    result = await cursor.fetchone()
                    if result:
                        stream_bingo_api_key = result.get('stream_bounty_api_key')
            finally:
                pass
            if not stream_bingo_api_key:
                await sleep(300)  # Wait 5 minutes before checking again
                continue
            # Construct WebSocket URL
            websocket_url = f"wss://api.stream-bingo.com/games/{CHANNEL_ID}/{stream_bingo_api_key}/notifications"
            integrations_logger.info("Attempting to connect to Stream Bingo WebSocket")
            async with WebSocketConnect(websocket_url) as stream_bingo_ws:
                integrations_logger.info("Successfully connected to Stream Bingo WebSocket")
                while True:
                    try:
                        message = await stream_bingo_ws.recv()
                        integrations_logger.info(f"Stream Bingo: Received message: {message}")
                        # Parse JSON message
                        try:
                            data = json.loads(message)
                            # Process bingo events here
                            await process_stream_bingo_message(data)
                        except json.JSONDecodeError as e:
                            integrations_logger.error(f"Stream Bingo: Failed to parse JSON message: {e}")
                        except Exception as e:
                            integrations_logger.error(f"Stream Bingo: Error processing message: {e}")
                            
                    except WebSocketConnectionClosed:
                        integrations_logger.error("Stream Bingo: WebSocket connection closed, reconnecting...")
                        break
                    except Exception as e:
                        integrations_logger.error(f"Stream Bingo: Error receiving message: {e}")
                        break
        except Exception as e:
            integrations_logger.error(f"Stream Bingo: WebSocket connection error: {e}")
            await sleep(10)  # Wait before retrying

async def connect_to_tanggle():
    global CHANNEL_NAME
    integrations_logger.info("===== Tanggle =====")
    while True:
        try:
            # Retrieve Tanggle credentials from database
            connection = await mysql_handler.get_connection(db_name=CHANNEL_NAME)
            tanggle_api_token = None
            tanggle_community_uuid = None
            try:
                async with connection.cursor(DictCursor) as cursor:
                    await cursor.execute("SELECT tanggle_api_token, tanggle_community_uuid FROM profile LIMIT 1")
                    result = await cursor.fetchone()
                    if result:
                        tanggle_api_token = result.get('tanggle_api_token')
                        tanggle_community_uuid = result.get('tanggle_community_uuid')
            finally:
                pass
            if not tanggle_api_token or not tanggle_community_uuid:
                integrations_logger.info("No Tanggle credentials found, skipping connection")
                await sleep(300)  # Wait 5 minutes before checking again
                continue
            # Construct WebSocket URL with events parameter
            websocket_url = f"wss://api.tanggle.io/ws/communities/{tanggle_community_uuid}?events=queue+rooms"
            headers = {"Authorization": f"Bearer {tanggle_api_token}"}
            integrations_logger.info("Attempting to connect to Tanggle WebSocket")
            async with WebSocketConnect(websocket_url, additional_headers=headers) as tanggle_ws:
                integrations_logger.info("Successfully connected to Tanggle WebSocket")
                while True:
                    try:
                        message = await tanggle_ws.recv()
                        integrations_logger.info(f"Tanggle: Received message: {message}")
                        # Parse JSON message
                        try:
                            data = json.loads(message)
                            # Log the event type and data
                            event_type = data.get('type', 'unknown')
                            if event_type == 'room.complete':
                                integrations_logger.info(f"Tanggle: A room has been completed. Raw: {data}")
                                return
                            integrations_logger.info(f"Tanggle Event Type: {event_type}, Data: {data}")
                        except json.JSONDecodeError as e:
                            integrations_logger.error(f"Tanggle: Failed to parse JSON message: {e}")
                        except Exception as e:
                            integrations_logger.error(f"Tanggle: Error processing message: {e}")
                    except WebSocketConnectionClosed:
                        integrations_logger.error("Tanggle: WebSocket connection closed, reconnecting...")
                        break
                    except Exception as e:
                        integrations_logger.error(f"Tanggle: Error receiving message: {e}")
                        break
        except Exception as e:
            integrations_logger.error(f"Tanggle: WebSocket connection error: {e}")
            await sleep(10)  # Wait before retrying


async def process_stream_bingo_message(data):
    try:
        event_type = data.get('type', 'unknown')
        integrations_logger.info(f"Stream Bingo: Processing event type: {event_type}")
        # Connect to user database for storing bingo data
        user_db = await mysql_handler.get_connection()
        try:
            # Handle different bingo event types
            if event_type in ['bingo_started', 'GAME_STARTED']:
                # Handle bingo game started
                game_id = data.get('game_id')
                events = data.get('events', [])
                is_sub_only = data.get('isSubOnly', False)
                random_call_only = data.get('randomCallOnly', True)
                bingo_patterns = data.get('bingoPatterns', [])
                # Save game data to database
                async with user_db.cursor() as cursor:
                    await cursor.execute("""
                        INSERT INTO bingo_games (game_id, events_count, is_sub_only, random_call_only, status)
                        VALUES (%s, %s, %s, %s, 'active')
                        ON DUPLICATE KEY UPDATE
                        events_count = VALUES(events_count),
                        is_sub_only = VALUES(is_sub_only),
                        random_call_only = VALUES(random_call_only),
                        status = 'active'
                    """, (game_id, len(events), is_sub_only, random_call_only))
                    await user_db.commit()
                integrations_logger.info(f"Stream Bingo: Bingo game started - Game ID: {game_id}, Events: {len(events)}, Sub-only: {is_sub_only}, Random-only: {random_call_only}")
                # You can add chat notifications or other actions here
            elif event_type in ['bingo_ended', 'GAME_ENDED']:
                # Handle bingo game ended
                game_id = data.get('game_id')
                # Update game data in database
                async with user_db.cursor() as cursor:
                    await cursor.execute("""
                        UPDATE bingo_games 
                        SET end_time = CURRENT_TIMESTAMP, status = 'completed' 
                        WHERE game_id = %s
                    """, (game_id,))
                    await user_db.commit()
                integrations_logger.info(f"Stream Bingo: Bingo game ended - Game ID: {game_id}")
                # You can add chat notifications or other actions here
            elif event_type in ['number_called', 'EVENT_CALLED']:
                # Handle number called
                number = data.get('number')
                display_number = data.get('displayNumber')
                event_id = data.get('eventId')
                event_name = data.get('eventName')
                game_id = data.get('game_id')
                if event_name:
                    integrations_logger.info(f"Stream Bingo: Event called - Event: {event_name} (ID: {event_id})")
                elif number:
                    integrations_logger.info(f"Stream Bingo: Number called - Game ID: {game_id}, Number: {number}")
                elif display_number:
                    integrations_logger.info(f"Stream Bingo: Display number called - {display_number}")
                # You can add chat notifications or other actions here
            elif event_type == 'PLAYER_JOINED':
                # Handle player joined
                player_name = data.get('playerName')
                player_id = data.get('playerId')
                integrations_logger.info(f"Stream Bingo: Player joined - {player_name} (ID: {player_id})")
                # You can add chat notifications or other actions here
            elif event_type == 'BINGO_REGISTERED':
                # Handle bingo registered (player got bingo)
                player_name = data.get('playerName')
                player_id = data.get('playerId')
                rank = data.get('rank')
                game_id = data.get('game_id')
                # Save winner data to database
                async with user_db.cursor() as cursor:
                    await cursor.execute("""
                        INSERT INTO bingo_winners (game_id, player_name, player_id, `rank`)
                        VALUES (%s, %s, %s, %s)
                    """, (game_id, player_name, player_id, rank))
                    await user_db.commit()
                integrations_logger.info(f"Stream Bingo: Bingo registered - {player_name} (ID: {player_id}) got bingo! Rank: {rank}")
                # You can add chat notifications or other actions here
            elif event_type == 'EXTRA_CARD_WITH_BITS':
                # Handle extra card purchased with bits
                player_name = data.get('playerName')
                player_id = data.get('playerId')
                bits = data.get('bits')
                integrations_logger.info(f"Stream Bingo: Extra card purchased - {player_name} (ID: {player_id}) bought extra card for {bits} bits")
                # You can add chat notifications or other actions here
            elif event_type == 'VOTE_STARTED':
                # Handle vote started
                integrations_logger.info("Stream Bingo: Voting has started")
                # You can add chat notifications or other actions here
            elif event_type == 'EXTRA_VOTE_WITH_BITS':
                # Handle extra vote purchased with bits
                player_name = data.get('playerName')
                player_id = data.get('playerId')
                bits = data.get('bits')
                integrations_logger.info(f"Stream Bingo: Extra vote purchased - {player_name} (ID: {player_id}) bought extra vote for {bits} bits")
                # You can add chat notifications or other actions here
            elif event_type == 'VOTE_ENDED':
                # Handle vote ended
                integrations_logger.info("Stream Bingo: Voting has ended")
                # You can add chat notifications or other actions here
            elif event_type == 'ALL_EVENTS_CALLED':
                # Handle all events called
                integrations_logger.info("Stream Bingo: All events have been called")
                # You can add chat notifications or other actions here
            else:
                integrations_logger.debug(f"Stream Bingo: Unhandled event type: {event_type}")
        finally:
            user_db.close()
    except Exception as e:
        integrations_logger.error(f"Stream Bingo: Error processing message: {e}")

# Bot classes
class GameNotFoundException(Exception):
    pass

class GameUpdateFailedException(Exception):
    pass

class SSHConnectionManager:
    def __init__(self, logger, timeout_minutes=5):
        self.logger = logger
        self.timeout_seconds = timeout_minutes * 60
        self.connections = {}  # server_name -> connection info
        self.lock = threading.Lock()
    async def get_connection(self, server_name):
        if not SSH_USERNAME or not SSH_PASSWORD:
            raise ValueError("SSH_USERNAME and SSH_PASSWORD must be set in environment")
        if server_name not in SSH_HOSTS or not SSH_HOSTS[server_name]:
            raise ValueError(f"Invalid server name '{server_name}' or host not configured")
        hostname = SSH_HOSTS[server_name]
        with self.lock:
            # Check if we have an active connection
            if server_name in self.connections:
                conn_info = self.connections[server_name]
                # Check if connection is still valid and not timed out
                if (time.time() - conn_info['last_used'] < self.timeout_seconds and 
                    self._is_connection_alive(conn_info['client'])):
                    conn_info['last_used'] = time.time()
                    self.logger.debug(f"Reusing SSH connection to {server_name} ({hostname})")
                    return conn_info['client']
                else:
                    # Connection expired or dead, clean it up
                    self._cleanup_connection(server_name)
            # Create new connection
            return await self._create_connection(server_name, hostname)
    def _is_connection_alive(self, ssh_client):
        try:
            transport = ssh_client.get_transport()
            return transport and transport.is_active()
        except:
            return False
    async def _create_connection(self, server_name, hostname):
        try:
            self.logger.info(f"Creating new SSH connection to {server_name} ({hostname})")
            ssh_client = SSHClient()
            ssh_client.set_missing_host_key_policy(AutoAddPolicy())
            # Connect with credentials
            connect_kwargs = {'hostname': hostname,'port': 22,'username': SSH_USERNAME,'password': SSH_PASSWORD,'timeout': 30}
            # Run connection in thread to avoid blocking
            await get_event_loop().run_in_executor(None, lambda: ssh_client.connect(**connect_kwargs))
            # Store connection info
            self.connections[server_name] = {'client': ssh_client,'last_used': time.time(),'hostname': hostname}
            return ssh_client
        except Exception as e:
            self.logger.error(f"Failed to create SSH connection to {server_name} ({hostname}): {e}")
            raise
    def _cleanup_connection(self, server_name):
        if server_name in self.connections:
            try:
                self.connections[server_name]['client'].close()
                self.logger.debug(f"Closed SSH connection to {server_name}")
            except:
                pass
            del self.connections[server_name]

    async def execute_command(self, server_name, command):
        ssh_client = await self.get_connection(server_name)
        try:
            # Execute command in thread to avoid blocking
            stdin, stdout, stderr = await get_event_loop().run_in_executor(None, ssh_client.exec_command, command)
            # Read output in thread
            stdout_data = await get_event_loop().run_in_executor(None, stdout.read)
            stderr_data = await get_event_loop().run_in_executor(None, stderr.read)
            return_code = stdout.channel.recv_exit_status()
            return {'stdout': stdout_data.decode('utf-8'),'stderr': stderr_data.decode('utf-8'),'return_code': return_code}
        except Exception as e:
            self.logger.error(f"Error executing command on {server_name}: {e}")
            raise
    async def file_exists(self, server_name, file_path):
        try:
            result = await self.execute_command(server_name, f'test -f "{file_path}" && echo "exists" || echo "not_exists"')
            return result['stdout'].strip() == 'exists'
        except Exception as e:
            self.logger.error(f"Error checking file existence on {server_name}: {e}")
            return False
    def close_all_connections(self):
        with self.lock:
            for server_name in list(self.connections.keys()):
                self._cleanup_connection(server_name)
            self.logger.info("All SSH connections closed")

# Database connection pool cleanup routine
async def cleanup_idle_db_pools():
    while True:
        try:
            # Wait 30 minutes between cleanup runs
            await sleep(1800)
            # Log current pool stats before cleanup
            stats = mysql_handler.get_pool_stats()
            if stats:
                bot_logger.info(f"DB Pool Stats before cleanup: {stats}")
            # Clean up pools idle for more than 30 minutes
            await mysql_handler.cleanup_idle_pools(max_idle_time=1800)
            # Log stats after cleanup
            stats_after = mysql_handler.get_pool_stats()
            if stats_after:
                bot_logger.info(f"DB Pool Stats after cleanup: {stats_after}")
            else:
                bot_logger.info("All idle database pools have been cleaned up")
        except Exception as e:
            bot_logger.error(f"Error in cleanup_idle_db_pools: {e}")
            await sleep(300)  # Wait 5 minutes before retrying on error

class TwitchBot(commands.Bot):
    # Event Message to get the bot ready
    def __init__(self, token, prefix, channel_name):
        super().__init__(token=token, prefix=prefix, initial_channels=[channel_name], case_insensitive=True)
        self.channel_name = channel_name
        self.running_commands = set()

    async def event_ready(self):
        bot_logger.info(f'Logged in as "{self.nick}"')
        await update_version_control()
        await builtin_commands_creation()
        await load_automated_shoutout_tracking()
        looped_tasks["check_stream_online"] = create_task(check_stream_online())
        create_task(known_users())
        create_task(channel_point_rewards())
        looped_tasks["twitch_token_refresh"] = create_task(twitch_token_refresh())
        looped_tasks["twitch_eventsub"] = create_task(twitch_eventsub())
        looped_tasks["specter_websocket"] = create_task(specter_websocket())
        looped_tasks["connect_to_integrations"] = create_task(connect_to_integrations())
        looped_tasks["midnight"] = create_task(midnight())
        looped_tasks["shoutout_worker"] = create_task(shoutout_worker())
        looped_tasks["periodic_watch_time_update"] = create_task(periodic_watch_time_update())
        looped_tasks["check_song_requests"] = create_task(check_song_requests())
        looped_tasks["cleanup_idle_db_pools"] = create_task(cleanup_idle_db_pools())
        looped_tasks["cleanup_gift_sub_tracking"] = create_task(cleanup_gift_sub_tracking())
        looped_tasks["cleanup_expired_shoutouts"] = create_task(cleanup_expired_shoutouts())
        await send_chat_message(f"SpecterSystems connected and ready! Running V{VERSION} {SYSTEM}")

    async def event_channel_joined(self, channel):
        self.target_channel = channel 
        bot_logger.info(f"Joined channel: {channel.name}")

    # Errors
    async def event_command_error(self, ctx, error: Exception) -> None:
        command = ctx.message.content.split()[0][1:]
        if isinstance(error, commands.CommandOnCooldown):
            retry_after = max(1, math.ceil(error.retry_after))
            bot_logger.info(f"[COOLDOWN] Command: '{command}' is on cooldown for {retry_after} seconds.")
            message = f"Command '{command}' is on cooldown. Try again in {retry_after} seconds."
            await send_chat_message(message)
        elif isinstance(error, commands.CommandNotFound):
            # Check if the command is a custom command
            try:
                async with await mysql_handler.get_connection() as connection:
                    async with connection.cursor(DictCursor) as cursor:
                        await cursor.execute('SELECT * FROM custom_commands WHERE command = %s', (command,))
                        result = await cursor.fetchone()
                        if result:
                            bot_logger.debug(f"[CUSTOM COMMAND] Command '{command}' exists in the database. Ignoring error.")
                            return
                        await cursor.execute('SELECT * FROM custom_user_commands WHERE command = %s', (command,))
                        result = await cursor.fetchone()
                        if result:
                            bot_logger.debug(f"[CUSTOM USER COMMAND] Command '{command}' exists in the database. Ignoring error.")
                            return
            except Exception as e:
                bot_logger.error(f"Error checking custom commands: {e}")
            bot_logger.error(f"Command '{command}' was not found in the bot or custom commands.")
        else:
            bot_logger.error(f"Command: '{command}', Error: {type(error).__name__}, Details: {error}")

    # Function to check all messages and push out a custom command.
    async def event_message(self, message):
        global CHANNEL_NAME, CHANNEL_ID
        # Verify source-room-id matches expected channel
        if hasattr(message, 'tags') and message.tags:
            source_room_id = message.tags.get('source-room-id')
            # source-room-id indicates the originating channel (where the user is from)
            # We only accept messages from users in the running bot channel
            if source_room_id and source_room_id != str(CHANNEL_ID):
                return
        chat_history_logger.info(f"Chat message from {message.author.name}: {message.content}")
        async with await mysql_handler.get_connection() as connection:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("INSERT INTO chat_history (author, message) VALUES (%s, %s)", (message.author.name, message.content))
                await connection.commit()
        messageAuthor = ""
        messageAuthorID = ""
        bannedUser = None
        messageContent = ""
        try:
            # Ignore messages from the bot itself
            if message.echo:
                return
            # Handle commands
            await self.handle_commands(message)
            messageContent = str(message.content).strip().lower() if message.content else ""
            messageAuthor = message.author.name if message.author else ""
            messageAuthorID = message.author.id if message.author else ""
            AuthorMessage = str(message.content) if message.content else ""
            # Check if the message matches the spam pattern (Has its own DB logic internally)
            spam_pattern = await get_spam_patterns()
            if spam_pattern:  # Check if spam_pattern is not empty
                for pattern in spam_pattern:
                    if pattern.search(messageContent):
                        bot_logger.info(f"Banning user {messageAuthor} with ID {messageAuthorID} for spam pattern match.")
                        create_task(ban_user(messageAuthor, messageAuthorID))
                        bannedUser = messageAuthor
                        return
            if messageContent.startswith('!'):
                command_parts = messageContent.split()
                command = command_parts[0][1:]  # Extract the command without '!'
                arg = command_parts[1] if len(command_parts) > 1 else None
                # Check if it's a built-in command/alias, but also check if it's disabled
                if command in builtin_commands or command in mod_commands or command in builtin_aliases:
                    # Check if the built-in command is disabled
                    builtin_disabled = False
                    async with await mysql_handler.get_connection() as connection:
                        async with connection.cursor(DictCursor) as cursor:
                            await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", (command,))
                            builtin_result = await cursor.fetchone()
                            if builtin_result and builtin_result.get("status") == 'Disabled':
                                builtin_disabled = True
                    # If built-in is enabled, process as built-in and return
                    if not builtin_disabled:
                        chat_logger.info(f"{messageAuthor} used a built-in command called: {command}")
                        return  # It's an enabled built-in command or alias, do nothing more
                    # If built-in is disabled, continue to check for custom command override
                    chat_logger.info(f"{messageAuthor} attempted to use disabled built-in command '{command}', checking for custom override")
                # Store results in variables to use outside the connection block
                command_data = None 
                async with await mysql_handler.get_connection() as connection:
                    async with connection.cursor(DictCursor) as cursor:
                        # Fetch timezone (used for logging only?)
                        await cursor.execute("SELECT timezone FROM profile")
                        tz_result = await cursor.fetchone()
                        if tz_result and tz_result.get("timezone"):
                            timezone = tz_result.get("timezone")
                            tz = pytz_timezone(timezone)
                            chat_logger.info(f"TZ: {tz} | Timezone: {timezone}")
                        else:
                            tz = set_timezone.UTC
                            chat_logger.info("Timezone not set, defaulting to UTC")
                        # Lookup Custom Command
                        await cursor.execute('SELECT response, status, cooldown, permission FROM custom_commands WHERE command = %s', (command,))
                        cc_result = await cursor.fetchone()
                        if cc_result:
                            command_data = {
                                'type': 'custom',
                                'response': cc_result.get("response"),
                                'status': cc_result.get("status"),
                                'cooldown': cc_result.get("cooldown"),
                                'permission': cc_result.get("permission")
                            }
                        else:
                            # Check Custom User Command
                            await cursor.execute('SELECT response, status, cooldown, user_id FROM custom_user_commands WHERE command = %s', (command,))
                            custom_user_command = await cursor.fetchone()
                            if custom_user_command:
                                command_data = {
                                    'type': 'user',
                                    'response': custom_user_command['response'],
                                    'status': custom_user_command['status'],
                                    'cooldown': custom_user_command['cooldown'],
                                    'user_id': custom_user_command['user_id']
                                }
                # Process Command Logic (Using captured data)
                if command_data:
                    # Protection: block custom commands when user hasn't sent a message this stream
                    try:
                        block_first_message_setting = False
                        async with await mysql_handler.get_connection() as connection:
                            async with connection.cursor(DictCursor) as cursor:
                                await cursor.execute("SELECT block_first_message_commands FROM protection LIMIT 1")
                                protection_row = await cursor.fetchone()
                                if protection_row and protection_row.get("block_first_message_commands") == 'True':
                                    block_first_message_setting = True
                        if block_first_message_setting and not await command_permissions("mod", message.author) and messageAuthor.lower() != CHANNEL_NAME.lower():
                            async with await mysql_handler.get_connection() as connection:
                                async with connection.cursor(DictCursor) as cursor:
                                    await cursor.execute('SELECT * FROM seen_today WHERE user_id = %s', (messageAuthorID,))
                                    seen_today_res = await cursor.fetchone()
                                    if not seen_today_res and stream_online:
                                        await send_chat_message("Sorry, you cannot use this command because: you haven't sent a chat message recently")
                                        command_data = None
                    except Exception as e:
                        bot_logger.error(f"Error checking first-message protection: {e}")
                    if command_data['type'] == 'custom':
                        response = command_data['response']
                        cc_status = command_data['status']
                        cooldown = command_data['cooldown']
                        cc_permission = command_data['permission']
                        if cc_status == 'Enabled':
                            # Check permissions
                            if not await command_permissions(cc_permission, message.author):
                                chat_logger.info(f"{messageAuthor} tried to use command {command} but doesn't have {cc_permission} permission.")
                                return
                            # Check cooldown
                            if not await check_cooldown(command, 'global', 'default', 1, int(cooldown)):
                                return
                            # Handle (call.)
                            if '(call.' in response:
                                calling_match = re.search(r'\(call\.(\w+)\)', response)
                                if calling_match:
                                    match_call = calling_match.group(1)
                                    response = response.replace(f"(call.{match_call})", "")
                                    await self.call_command(match_call, message)
                            # Extract user mention
                            user_mention = re.search(r'@(\w+)', messageContent)
                            user_name = user_mention.group(1) if user_mention else messageAuthor
                            # Process variables (SAFE now - connection released)
                            await process_custom_command_variables(command, response, user=user_name, arg=arg, send_to_chat=True)
                            # Record usage
                            add_usage(command, 'global', 'default')
                        else:
                            chat_logger.info(f"{command} not ran because it's disabled.")
                    elif command_data['type'] == 'user':
                        response = command_data['response']
                        cuc_status = command_data['status']
                        cooldown = command_data['cooldown']
                        user_id = command_data['user_id']
                        if cuc_status == 'Enabled':
                            # Check cooldown
                            if not await check_cooldown(command, 'global', 'default', 1, int(cooldown)):
                                return
                            if messageAuthor.lower() == user_id.lower() or await command_permissions("mod", message.author):
                                await send_chat_message(response)
                                # Record usage
                                add_usage(command, 'global', 'default')
                    else:
                        chat_logger.info(f"Custom command '{command}' not found.")
                else:
                    chat_logger.info(f"Custom command '{command}' not found.")
            # Handle AI responses
            if f'@{self.nick.lower()}' in str(message.content).lower():
                # Ignore messages from the bot itself to prevent self-responses
                if message.author.name.lower() == self.nick.lower():
                    chat_logger.info(f"Ignoring AI mention from bot itself")
                    return
                # If running in CUSTOM or SELF mode and the bot's nick equals the channel name, ignore mentions of the channel name
                if (CUSTOM_MODE or SELF_MODE) and self.nick.lower() == CHANNEL_NAME.lower():
                    chat_logger.info(f"Ignoring AI mention of channel name in CUSTOM/SELF mode")
                    return
                # Ignore messages from the channel owner in SELF mode (explicit owner messages)
                if message.author.name.lower() == CHANNEL_NAME.lower():
                    chat_logger.info(f"Ignoring AI mention from channel owner in SELF mode")
                    return
                user_message = str(message.content).lower().replace(f'@{self.nick.lower()}', '').strip()
                if not user_message:
                    await send_chat_message(f'Hello, {message.author.name}!')
                else:
                    await self.handle_ai_response(user_message, messageAuthorID, message.author.name)
            # Initialize link/term deletion flags
            should_delete = False
            alert_mods = False
            send_warning = False
            if 'http://' in AuthorMessage or 'https://' in AuthorMessage:
                # Block 3: Link Protection (Acquire -> Release immediately)
                async with await mysql_handler.get_connection() as connection:
                    async with connection.cursor(DictCursor) as cursor:
                        # ALWAYS check blacklist first - blacklisted URLs are blocked regardless of URL blocking setting
                        await cursor.execute('SELECT link FROM link_blacklisting')
                        blacklist_result = await cursor.fetchall()
                        blacklisted_links = [row['link'] for row in blacklist_result] if blacklist_result else []
                        contains_blacklisted_link = await match_domain_or_link(AuthorMessage, blacklisted_links, use_regex=False)
                        if contains_blacklisted_link:
                            should_delete = True
                            alert_mods = True
                            chat_logger.info(f"Blacklisted URL detected in message from {messageAuthor}")
                        else:
                            # Check URL blocking setting (only if not blacklisted)
                            await cursor.execute('SELECT url_blocking FROM protection')
                            result = await cursor.fetchone()
                            url_blocking = bool(result.get("url_blocking")) if result else False
                            if url_blocking:
                                # Check if user is mod or streamer (bypass URL blocking for privileged users)
                                is_privileged = False
                                if messageAuthor in permitted_users and time.time() < permitted_users[messageAuthor]:
                                    is_privileged = True
                                elif await command_permissions("mod", message.author):
                                    is_privileged = True
                                if is_privileged:
                                    chat_logger.info(f"URL found in message from {messageAuthor}, allowed due to mod/streamer privilege.")
                                else:
                                    # Fetch whitelist (regex patterns)
                                    await cursor.execute('SELECT link FROM link_whitelist')
                                    whitelist_result = await cursor.fetchall()
                                    whitelisted_patterns = [row['link'] for row in whitelist_result] if whitelist_result else []
                                    contains_whitelisted_link = await match_domain_or_link(AuthorMessage, whitelisted_patterns, use_regex=True)
                                    contains_twitch_clip_link = 'https://clips.twitch.tv/' in AuthorMessage or 'https://www.twitch.tv/' in AuthorMessage
                                    if not contains_whitelisted_link and not contains_twitch_clip_link:
                                        should_delete = True
                                        send_warning = True
                                        chat_logger.info(f"Non-whitelisted URL detected in message from {messageAuthor}")
                                    else:
                                        chat_logger.info(f"URL found in message from {messageAuthor}, allowed due to whitelist match or Twitch link.")
                            else:
                                chat_logger.info(f"URL found in message from {messageAuthor}, allowed because URL blocking is disabled.")
            # Check for blocked terms (independent of URL blocking)
            try:
                # Use a dedicated connection & cursor to avoid using a cursor that may have been closed
                async with await mysql_handler.get_connection() as term_conn:
                    async with term_conn.cursor(DictCursor) as term_cursor:
                        await term_cursor.execute('SELECT term_blocking FROM protection')
                        result = await term_cursor.fetchone()
                        term_blocking = result.get("term_blocking") == 'True' if result else False
                        if term_blocking:
                            # Fetch blocked terms
                            await term_cursor.execute('SELECT term FROM blocked_terms')
                            blocked_terms_result = await term_cursor.fetchall()
                            blocked_terms = [row['term'].lower() for row in blocked_terms_result] if blocked_terms_result else []
                            if blocked_terms:
                                message_lower = messageContent.lower()
                                for term in blocked_terms:
                                    if term in message_lower:
                                        should_delete = True
                                        chat_logger.info(f"Blocked term '{term}' detected in message from {messageAuthor}")
                                        try:
                                            await send_chat_message(f"{messageAuthor}, your message contained a blocked term and has been removed.")
                                        except Exception as send_err:
                                            chat_logger.error(f"Error sending blocked term notice: {send_err}")
                                        break
            except Exception as term_error:
                chat_logger.error(f"Error checking blocked terms: {term_error}")
            if should_delete:
                await message.delete()
                if alert_mods:
                    chat_logger.info(f"Deleted message from {messageAuthor} containing a blacklisted URL: {AuthorMessage}")
                    await send_chat_message(f"Code Red! Link escapee! Mods have been alerted and are on the hunt for the missing URL.")
                    return
                if send_warning:
                    chat_logger.info(f"Deleted message from {messageAuthor} containing a non-whitelisted URL: {AuthorMessage}")
                    await send_chat_message(f"{messageAuthor}, whoa there! We appreciate you sharing, but links aren't allowed in chat without a mod's okay.")
                    return
        except Exception as e:
            if isinstance(e, AttributeError) and "NoneType" in str(e):
                pass
            else:
                bot_logger.error(f"An error occurred in event_message: {e}")

    async def message_counting_and_welcome_messages(self, messageAuthor, messageAuthorID, bannedUser, messageContent=""):
        global stream_online
        if messageAuthor in [bannedUser, None, ""]:
            chat_logger.info(f"Blocked message from {messageAuthor} - banned or invalid.")
            return
        if messageAuthor.lower() == BOT_USERNAME.lower():
            # Skip message counting and welcome message for the bot itself
            return
        send_shoutout = False
        shoutout_message = None
        try:
            connection = await mysql_handler.get_connection()
            async with connection.cursor(DictCursor) as cursor:
                # Check user level
                is_vip = await is_user_vip(messageAuthorID)
                is_mod = await is_user_mod(messageAuthorID)
                is_broadcaster = messageAuthor.lower() == CHANNEL_NAME.lower()
                user_level = 'broadcaster' if is_broadcaster else 'mod' if is_mod else 'vip' if is_vip else 'normal'
                # Update message count
                await cursor.execute(
                    'INSERT INTO message_counts (username, message_count, user_level) VALUES (%s, 1, %s) '
                    'ON DUPLICATE KEY UPDATE message_count = message_count + 1, user_level = %s',
                    (messageAuthor, user_level, user_level)
                )
                await connection.commit()
                # Check if user is already in seen_today
                await cursor.execute('SELECT * FROM seen_today WHERE user_id = %s', (messageAuthorID,))
                seen_today_result = await cursor.fetchone()
                already_seen_today = seen_today_result is not None
                # Skip further handling for broadcaster
                if is_broadcaster:
                    return
                # Check if the user is new or returning
                await cursor.execute('SELECT * FROM seen_users WHERE username = %s', (messageAuthor,))
                user_data = await cursor.fetchone()
                if user_data:
                    has_welcome_message = user_data.get("welcome_message")
                    user_status_enabled = user_data.get("status", 'True') == 'True'
                else:
                    has_welcome_message = None
                    user_status_enabled = True
                    await cursor.execute(
                        'INSERT INTO seen_users (username, status) VALUES (%s, %s)',
                        (messageAuthor, "True")
                    )
                    await connection.commit()
                    chat_logger.info(f"Added new user to seen_users: {messageAuthor}")
                # Update last_seen and set first_seen if null
                await cursor.execute('UPDATE seen_users SET last_seen = NOW(), first_seen = COALESCE(first_seen, NOW()) WHERE username = %s', (messageAuthor,))
                await connection.commit()
                # Load streamer preferences
                await cursor.execute('SELECT * FROM streamer_preferences WHERE id = 1')
                preferences = await cursor.fetchone()
                if not preferences:
                    chat_logger.error(f"No streamer preferences found, using defaults")
                    # Set default values
                    send_welcome_messages = 1
                    new_default_welcome_message = "(user) is new to the community, let's give them a warm welcome!"
                    new_default_vip_welcome_message = "ATTENTION! A very important person has entered the chat, welcome (user)"
                    new_default_mod_welcome_message = "MOD ON DUTY! Welcome in (user), the power of the sword has increased!"
                    default_welcome_message = "Welcome back (user), glad to see you again!"
                    default_vip_welcome_message = "ATTENTION! A very important person has entered the chat, welcome (user)"
                    default_mod_welcome_message = "MOD ON DUTY! Welcome in (user), the power of the sword has increased!"
                else:
                    send_welcome_messages = int(preferences["send_welcome_messages"])
                    new_default_welcome_message = preferences["new_default_welcome_message"]
                    new_default_vip_welcome_message = preferences["new_default_vip_welcome_message"]
                    new_default_mod_welcome_message = preferences["new_default_mod_welcome_message"]
                    default_welcome_message = preferences["default_welcome_message"]
                    default_vip_welcome_message = preferences["default_vip_welcome_message"]
                    default_mod_welcome_message = preferences["default_mod_welcome_message"]
                def replace_user_placeholder(message, username):
                    return message.replace("(user)", username)
                # If user has not been seen today and stream is online, ONLY insert them when the message is NOT a command
                is_command_message = bool(messageContent and messageContent.strip().startswith('!'))
                if not already_seen_today and stream_online and not is_command_message:
                    await cursor.execute(
                        'INSERT INTO seen_today (user_id, username) VALUES (%s, %s)',
                        (messageAuthorID, messageAuthor)
                    )
                    await connection.commit()
                    chat_logger.info(f"Marked {messageAuthor} as seen today.")
                    # Only send welcome message if enabled
                    if user_status_enabled and send_welcome_messages:
                        if not user_data:
                            if is_vip:
                                message_to_send = replace_user_placeholder(new_default_vip_welcome_message, messageAuthor)
                            elif is_mod:
                                message_to_send = replace_user_placeholder(new_default_mod_welcome_message, messageAuthor)
                            else:
                                message_to_send = replace_user_placeholder(new_default_welcome_message, messageAuthor)
                        else:
                            if has_welcome_message:
                                message_to_send = has_welcome_message
                            else:
                                if is_vip:
                                    message_to_send = replace_user_placeholder(default_vip_welcome_message, messageAuthor)
                                elif is_mod:
                                    message_to_send = replace_user_placeholder(default_mod_welcome_message, messageAuthor)
                                else:
                                    message_to_send = replace_user_placeholder(default_welcome_message, messageAuthor)
                        if '(shoutout)' in message_to_send:
                            send_shoutout = True
                            message_to_send = message_to_send.replace('(shoutout)', '')
                            user_id = messageAuthorID
                            user_to_shoutout = messageAuthor
                            shoutout_message = await get_shoutout_message(user_id, user_to_shoutout, "welcome_message")
                        if message_to_send.strip():
                            await send_chat_message(message_to_send)
                        if send_shoutout and shoutout_message:
                            await add_shoutout(user_to_shoutout, user_id, is_automated=True)
                            await send_chat_message(shoutout_message)
                        chat_logger.info(f"Sent welcome message to {messageAuthor}")
                        create_task(self.safe_walkon(messageAuthor))
                elif not already_seen_today and stream_online and is_command_message:
                    # First message is a command — do not mark as seen yet. This ensures the user must send a non-command chat message to be considered "seen".
                    chat_logger.info(f"{messageAuthor} sent a command as their first message; deferring 'seen' until a non-command message is received.")
        except Exception as e:
            chat_logger.error(f"Error in message_counting for {messageAuthor}: {e}")
        finally:
            await self.user_points(messageAuthor, messageAuthorID)
            await self.user_grouping(messageAuthor, messageAuthorID)
            await handle_chat_message(messageAuthor, messageContent)

    async def safe_walkon(self, user):
        try:
            await websocket_notice(event="WALKON", user=user)
            chat_logger.info(f"Sent WALKON notice for {user}")
        except Exception as e:
            chat_logger.error(f"Failed to send WALKON for {user}: {e}")

    async def user_points(self, messageAuthor, messageAuthorID):
        try:
            settings = await get_point_settings()
            if not settings or 'chat_points' not in settings or 'excluded_users' not in settings:
                chat_logger.error("Error: Point settings are missing or incomplete.")
                return
            chat_points = settings['chat_points']
            excluded_users = [user.strip().lower() for user in settings['excluded_users'].split(',')]
            author_lower = messageAuthor.lower()
            if author_lower not in excluded_users:
                result = await manage_user_points(messageAuthorID, messageAuthor, "credit", chat_points)
                if result["success"]:
                    bot_logger.info(f"Awarded {chat_points} {settings['point_name']} to {messageAuthor}. Total: {result['points']}")
                else:
                    chat_logger.error(f"Failed to award points to {messageAuthor}: {result['error']}")
        except Exception as e:
            chat_logger.error(f"Error in user_points: {e}")

    async def user_grouping(self, messageAuthor, messageAuthorID):
        try:
            connection = await mysql_handler.get_connection()
            group_names = []
            # Check if the user is the broadcaster
            if messageAuthor == self.channel_name:
                return
            # Check if there was a user passed
            if messageAuthor == "None":
                return
            async with connection.cursor(DictCursor) as cursor:
                # Check if the user is a moderator
                if await is_user_mod(messageAuthorID):
                    group_names = ["MOD"]  # Override any other groups
                else:
                    # Check if the user is a VIP
                    if await is_user_vip(messageAuthorID):
                        group_names = ["VIP"]  # Override subscriber groups
                    # Check if the user is a subscriber, only if they are not a VIP or MOD
                    if not group_names:
                        subscription_tier = await is_user_subscribed(messageAuthorID)
                        if subscription_tier:
                            # Map subscription tier to group name
                            if subscription_tier == "Tier 1":
                                group_names.append("Subscriber T1")
                            elif subscription_tier == "Tier 2":
                                group_names.append("Subscriber T2")
                            elif subscription_tier == "Tier 3":
                                group_names.append("Subscriber T3")
                # If the user is not a MOD, VIP, or Subscriber, assign them the role "Normal"
                if not group_names:
                    group_names.append("Normal")
                # Assign user to groups
                for name in group_names:
                    await cursor.execute("SELECT * FROM `groups` WHERE name=%s", (name,))
                    group = await cursor.fetchone()
                    if group:
                        try:
                            await cursor.execute(
                                "INSERT INTO everyone (username, group_name) VALUES (%s, %s) "
                                "ON DUPLICATE KEY UPDATE group_name = %s", (messageAuthor, name, name)
                            )
                            await connection.commit()
                            #bot_logger.info(f"User '{messageAuthor}' assigned to group '{name}' successfully.")
                        except MySQLIntegrityError:
                            bot_logger.error(f"Failed to assign user '{messageAuthor}' to group '{name}'.")
                    else:
                        bot_logger.error(f"Group '{name}' does not exist.")
        except Exception as e:
            bot_logger.error(f"An error occurred in user_grouping: {e}")

    async def call_command(self, command_name, ctx):
        if command_name in self.running_commands:
            bot_logger.error(f"Command '{command_name}' is already running, skipping.")
            return
        # If ctx doesn't have 'view', it's a Message, create a Context
        if not hasattr(ctx, 'view'):
            ctx = Context(message=ctx, bot=self, prefix='!')
        command_method = getattr(self, f"{command_name}_command", None)
        if command_method:
            bot_logger.info(f"Calling command: {command_name}")
            self.running_commands.add(command_name)
            try:
                await command_method(ctx)
            except Exception as e:
                bot_logger.error(f"Error executing command '{command_name}': {e}")
            finally:
                self.running_commands.discard(command_name)
        else:
            bot_logger.error(f"Command '{command_name}' not found.")
            await send_chat_message(f"Command '{command_name}' not found.")

    async def handle_ai_response(self, user_message, user_id, message_author_name):
        ai_response = await self.get_ai_response(user_message, user_id, message_author_name)
        if not ai_response:
            return
        # Normalize duplicate mentions that may be produced by the AI itself
        try:
            name = message_author_name or ''
            if name:
                # Collapse repeated adjacent @mentions of the same user (e.g. "@name @name," -> "@name,")
                dup_pattern = re.compile(r'(@' + re.escape(name) + r'\b)(?:[\s,;:]+@' + re.escape(name) + r'\b)+', re.IGNORECASE)
                ai_response = dup_pattern.sub(r'\1', ai_response)
        except Exception as e:
            api_logger.debug(f"Failed to normalize duplicate mentions: {e}")
        # Split the response into message-sized chunks (max 255 chars)
        messages = []
        try:
            text = ai_response.strip()
            if not text:
                messages = [""]
            else:
                # First, try splitting on sentence boundaries using punctuation
                sentences = re.split(r'(?<=[\.\!\?])\s+', text)
                current = ''
                for sent in sentences:
                    if not sent:
                        continue
                    # If adding the sentence keeps us under limit, append it
                    if len(current) + (1 if current else 0) + len(sent) <= 255:
                        current = (current + ' ' + sent).strip() if current else sent
                    else:
                        # Flush current if present
                        if current:
                            messages.append(current)
                            current = ''
                        # If the sentence itself is longer than limit, split by words
                        if len(sent) > 255:
                            words = re.split(r'\s+', sent)
                            wcur = ''
                            for w in words:
                                if not w:
                                    continue
                                if len(wcur) + (1 if wcur else 0) + len(w) <= 255:
                                    wcur = (wcur + ' ' + w).strip() if wcur else w
                                else:
                                    if wcur:
                                        messages.append(wcur)
                                    # If single word longer than 255, hard-split it
                                    if len(w) > 255:
                                        for i in range(0, len(w), 255):
                                            messages.append(w[i:i+255])
                                        wcur = ''
                                    else:
                                        wcur = w
                            if wcur:
                                messages.append(wcur)
                        else:
                            # Sentence fits within a chunk by itself
                            messages.append(sent)
                if current:
                    messages.append(current)
        except Exception as e:
            api_logger.debug(f"Sentence-based chunking failed, falling back to simple splits: {e}")
            messages = [ai_response[i:i+255] for i in range(0, len(ai_response), 255)]
        # Send each part of the response as a separate message, addressing the user on the first message
        first = True
        # Precompute a full-response mention check to avoid double-prefixing
        try:
            full_lower = ai_response.lower()
            mention_token = f"@{message_author_name.lower()}"
            has_any_mention = mention_token in full_lower
        except Exception:
            has_any_mention = False
        for part in messages:
            part_to_send = part
            if first:
                # If any @username appears anywhere in the AI response, don't add another prefix
                if has_any_mention:
                    prefix = ''
                else:
                    # Fallback: check start-of-message patterns (in case of minor mismatches)
                    trimmed = part.lstrip()
                    lower_trim = trimmed.lower()
                    name_mention = f"@{message_author_name.lower()}"
                    name_plain = message_author_name.lower()
                    already_addressed = False
                    try:
                        if lower_trim.startswith(name_mention) or lower_trim.startswith(name_mention + ',') or lower_trim.startswith(name_mention + ':'):
                            already_addressed = True
                        elif lower_trim.startswith(name_plain + ',') or lower_trim.startswith(name_plain + ':') or lower_trim == name_plain:
                            already_addressed = True
                    except Exception:
                        already_addressed = False
                    prefix = '' if already_addressed else f"@{message_author_name} "
                # Calculate available space for this message after accounting for prefix
                try:
                    total_limit = 255
                    available = total_limit - len(prefix)
                    if available < 10:
                        # Fallback to conservative default
                        available = max(32, total_limit - len(prefix))
                except Exception:
                    available = 255
                # If part is too long, truncate at last space within available-3 and append ellipsis
                if len(part_to_send) > available:
                    try:
                        cut_at = part_to_send.rfind(' ', 0, max(0, available - 3))
                        if cut_at == -1:
                            # No space found; hard cut
                            truncated = part_to_send[:max(0, available - 3)]
                        else:
                            truncated = part_to_send[:cut_at]
                        part_to_send = (truncated.rstrip() + '...')
                    except Exception:
                        part_to_send = part_to_send[:max(0, available - 3)] + '...'
                # Send with prefix if needed
                if prefix:
                    await send_chat_message(prefix + part_to_send)
                else:
                    await send_chat_message(part_to_send)
                first = False
            else:
                # For subsequent parts, ensure they also respect the total limit
                try:
                    if len(part_to_send) > 255:
                        cut_at = part_to_send.rfind(' ', 0, 252)
                        if cut_at == -1:
                            part_to_send = part_to_send[:252] + '...'
                        else:
                            part_to_send = part_to_send[:cut_at].rstrip() + '...'
                except Exception:
                    part_to_send = part_to_send[:255]
                await send_chat_message(part_to_send)

    async def get_ai_response(self, user_message, user_id, message_author_name):
        global INSTRUCTIONS_CACHE_TTL, OPENAI_INSTRUCTIONS_ENDPOINT, bot_owner
        # Ensure history directory exists
        try:
            Path(HISTORY_DIR).mkdir(parents=True, exist_ok=True)
        except Exception as e:
            api_logger.debug(f"Could not create history directory {HISTORY_DIR}: {e}")
        premium_tier = await check_premium_feature(message_author_name)
        # Allow bot owner access even without premium subscription
        if premium_tier in (2000, 3000, 4000):
            # Chat-based behavior: read system instructions JSON if available and call the chat completions API
            messages = []
            # Fetch system instructions from the remote endpoint with a small local cache
            global _cached_instructions, _cached_instructions_time
            sys_instr = None
            try:
                now = time.time()
                if _cached_instructions and (now - _cached_instructions_time) < INSTRUCTIONS_CACHE_TTL:
                    sys_instr = _cached_instructions
                else:
                    api_logger.debug(f"Fetching system instructions from {OPENAI_INSTRUCTIONS_ENDPOINT}")
                    async with httpClientSession() as session:
                        try:
                            async with session.get(OPENAI_INSTRUCTIONS_ENDPOINT, timeout=10) as resp:
                                if resp.status == 200:
                                    sys_instr = await resp.json()
                                    _cached_instructions = sys_instr
                                    _cached_instructions_time = now
                                else:
                                    api_logger.error(f"Failed to fetch instructions: HTTP {resp.status}")
                        except Exception as e:
                            api_logger.error(f"HTTP error fetching instructions: {e}")
            except Exception as e:
                api_logger.error(f"Error while loading system instructions: {e}")
            # Accept several JSON shapes: list of messages, {system: '...'}, or {messages: [...]}
            try:
                if isinstance(sys_instr, list):
                    messages.extend(sys_instr)
                elif isinstance(sys_instr, dict):
                    if 'system' in sys_instr and isinstance(sys_instr['system'], str):
                        messages.append({'role': 'system', 'content': sys_instr['system']})
                    elif 'messages' in sys_instr and isinstance(sys_instr['messages'], list):
                        messages.extend(sys_instr['messages'])
            except Exception as e:
                api_logger.error(f"Failed to parse system instructions JSON: {e}")
            # Add a system message to tell the AI which Twitch user it's speaking to
            try:
                user_context = f"You are speaking to Twitch user '{message_author_name}' (id: {user_id}). Address them by their display name @{message_author_name} and tailor the response to them. Keep responses concise and suitable for Twitch chat."
                messages.append({'role': 'system', 'content': user_context})
                # Instruct the AI to keep replies within the chat length limit
                try:
                    limiter = f"Important: Keep your final reply under {MAX_CHAT_MESSAGE_LENGTH} characters total so it fits in one Twitch chat message. If you need to be concise, prefer short sentences and avoid long lists."
                    messages.append({'role': 'system', 'content': limiter})
                except Exception:
                    pass
            except Exception as e:
                api_logger.error(f"Failed to build user context for AI: {e}")
            # Load per-user chat history and insert as prior messages
            try:
                history_file = Path(HISTORY_DIR) / f"{user_id}.json"
                history = []
                if history_file.exists():
                    try:
                        with history_file.open('r', encoding='utf-8') as hf:
                            history = json.load(hf)
                    except Exception as e:
                        api_logger.debug(f"Failed to read history for {user_id}: {e}")
                # History is expected to be a list of {role: 'user'|'assistant', content: '...'}
                if isinstance(history, list) and len(history) > 0:
                    # Keep only the last 8 turns to avoid long prompts
                    recent = history[-8:]
                    for item in recent:
                        if isinstance(item, dict) and 'role' in item and 'content' in item:
                            messages.append({'role': item['role'], 'content': item['content']})
            except Exception as e:
                api_logger.debug(f"Error loading chat history for {user_id}: {e}")
            # Append the current user message as the latest user turn
            messages.append({'role': 'user', 'content': user_message})
            # Call OpenAI chat completion via AsyncOpenAI client
            try:
                api_logger.debug("Calling OpenAI chat completion from get_ai_response")
                chat_client = getattr(openai_client, 'chat', None)
                ai_text = None
                if chat_client and hasattr(chat_client, 'completions') and hasattr(chat_client.completions, 'create'):
                    resp = await chat_client.completions.create(model="gpt-5-nano", messages=messages)
                    if isinstance(resp, dict) and 'choices' in resp and len(resp['choices']) > 0:
                        choice = resp['choices'][0]
                        if 'message' in choice and 'content' in choice['message']:
                            ai_text = choice['message']['content']
                        elif 'text' in choice:
                            ai_text = choice['text']
                    else:
                        # Try attribute access
                        choices = getattr(resp, 'choices', None)
                        if choices and len(choices) > 0:
                            ai_text = getattr(choices[0].message, 'content', None)
                elif hasattr(openai_client, 'chat_completions') and hasattr(openai_client.chat_completions, 'create'):
                    resp = await openai_client.chat_completions.create(model="gpt-5-nano", messages=messages)
                    if isinstance(resp, dict) and 'choices' in resp and len(resp['choices']) > 0:
                        ai_text = resp['choices'][0].get('message', {}).get('content') or resp['choices'][0].get('text')
                    else:
                        choices = getattr(resp, 'choices', None)
                        if choices and len(choices) > 0:
                            ai_text = getattr(choices[0].message, 'content', None)
                else:
                    api_logger.error("No compatible chat completions method found on openai_client")
                    return "AI chat completions API is not available."
            except Exception as e:
                api_logger.error(f"Error calling chat completion API: {e}")
                return "An error occurred while contacting the AI chat service."
            if not ai_text:
                api_logger.error(f"Chat completion returned no usable text: {resp}")
                return "The AI chat service returned an unexpected response."
            # Filter out "Chaos Crew" hallucination
            if "Chaos Crew" in ai_text:
                ai_text = ai_text.replace("Chaos Crew", "Stream Team")
                api_logger.info("Filtered 'Chaos Crew' from AI response in get_ai_response")
            api_logger.info("AI response received from chat completion")
            # Persist the user message and AI response to per-user history
            try:
                history_file = Path(HISTORY_DIR) / f"{user_id}.json"
                history = []
                if history_file.exists():
                    try:
                        with history_file.open('r', encoding='utf-8') as hf:
                            history = json.load(hf)
                    except Exception as e:
                        api_logger.debug(f"Failed to read existing history for append {user_id}: {e}")
                # Append entries
                history.append({'role': 'user', 'content': user_message})
                history.append({'role': 'assistant', 'content': ai_text})
                # Trim history to last 200 entries
                if len(history) > 200:
                    history = history[-200:]
                try:
                    with history_file.open('w', encoding='utf-8') as hf:
                        json.dump(history, hf, ensure_ascii=False, indent=2)
                except Exception as e:
                    api_logger.debug(f"Failed to write history for {user_id}: {e}")
            except Exception as e:
                api_logger.debug(f"Error while persisting chat history for {user_id}: {e}")
            return ai_text
        else:
            api_logger.info("AI access denied due to lack of premium.")
            return False

    @commands.command(name='commands', aliases=['cmds'])
    async def commands_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("commands",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('commands', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        # If the user is a mod, include both mod_commands and builtin_commands
                        is_mod = await command_permissions("mod", ctx.author)
                        if is_mod:
                            mod_commands_list = ", ".join(sorted(f"!{command}" for command in mod_commands))
                            await send_chat_message(f"Moderator commands: {mod_commands_list}")
                        # Include builtin commands for both mod and normal users
                        builtin_commands_list = ", ".join(sorted(f"!{command}" for command in builtin_commands))
                        await send_chat_message(f"General commands: {builtin_commands_list}")
                        # Custom commands link
                        custom_response_message = f"Custom commands: https://members.botofthespecter.com/{CHANNEL_NAME}/"
                        await send_chat_message(custom_response_message)
                        # Record usage
                        add_usage('commands', bucket_key, cooldown_bucket)
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the commands command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred while executing the 'commands' command: {str(e)}")
            await send_chat_message("An error occurred while fetching the twitch_commands. Please try again later.")

    @commands.command(name='bot')
    async def bot_command(self, ctx):
        global bot_owner, CUSTOM_MODE, BOT_USERNAME
        connection = await mysql_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("bot",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('bot', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        chat_logger.info(f"{ctx.author.name} ran the Bot Command.")
                        # If running as a custom bot account, note it in chat and logs
                        is_custom_bot = (('CUSTOM_MODE' in globals() and CUSTOM_MODE) or BOT_USERNAME.lower() != "botofthespecter")
                        if is_custom_bot:
                            await send_chat_message(f"The system I'm using is from BotOfTheSpecter. I'm a custom bot account @{BOT_USERNAME} linked to the Echo system — check out more features by my owner {bot_owner} at https://botofthespecter.com")
                        else:
                            await send_chat_message(f"This amazing bot is built by the one and the only {bot_owner}. Check me out on my website: https://botofthespecter.com")
                        # Record usage
                        add_usage('bot', bucket_key, cooldown_bucket)
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the bot command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the bot command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")
        finally:
            await connection.ensure_closed()

    @commands.command(name='wsstatus')
    async def websocket_status_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("wsstatus",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('wsstatus', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        websocket_status = "Connected" if is_websocket_connected() else "Disconnected"
                        chat_logger.info(f"{ctx.author.name} checked WebSocket status: {websocket_status}")
                        await send_chat_message(f"Internal system WebSocket status: {websocket_status}")
                        # If disconnected, attempt reconnection
                        if not is_websocket_connected():
                            chat_logger.info(f"WebSocket disconnected, attempting reconnection...")
                            await send_chat_message("WebSocket is disconnected. Attempting to reconnect...")
                            try:
                                await force_websocket_reconnect()
                                # Check status after reconnection attempt
                                new_status = "Connected" if is_websocket_connected() else "Still Disconnected"
                                chat_logger.info(f"Reconnection attempt completed. Status: {new_status}")
                                await send_chat_message(f"Reconnection attempt completed. Status: {new_status}")
                            except Exception as reconnect_error:
                                chat_logger.error(f"Failed to reconnect WebSocket: {reconnect_error}")
                                await send_chat_message("Failed to reconnect. Please try again or contact support.")
                        # Record usage
                        add_usage('wsstatus', bucket_key, cooldown_bucket)
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to check WebSocket status but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in websocket_status_command: {e}")
            await send_chat_message("An error occurred while checking WebSocket status.")

    @commands.command(name='dbstatus')
    async def database_status_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("dbstatus",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('dbstatus', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        # Get status of main database connection
                        db_status = mysql_handler.get_connection_status()
                        if db_status['connected']:
                            connection_time = db_status['connection_time']
                            uptime = time.time() - connection_time
                            hours, remainder = divmod(int(uptime), 3600)
                            minutes, seconds = divmod(remainder, 60)
                            uptime_str = f"{hours}h {minutes}m {seconds}s" if hours > 0 else f"{minutes}m {seconds}s"
                            chat_logger.info(f"{ctx.author.name} checked database status: Connected (Uptime: {uptime_str})")
                            await send_chat_message(f"Database status: Connected to '{db_status['db_name']}' (Uptime: {uptime_str})")
                        else:
                            # Database is disconnected
                            last_attempt = db_status.get('last_attempt')
                            if last_attempt:
                                time_since = time.time() - last_attempt
                                hours, remainder = divmod(int(time_since), 3600)
                                minutes, seconds = divmod(remainder, 60)
                                time_str = f"{hours}h {minutes}m {seconds}s ago" if hours > 0 else f"{minutes}m {seconds}s ago"
                                chat_logger.info(f"{ctx.author.name} checked database status: Disconnected (Last attempt: {time_str})")
                                await send_chat_message(f"Database status: Disconnected from '{db_status['db_name']}' (Last connection attempt: {time_str})")
                            else:
                                chat_logger.info(f"{ctx.author.name} checked database status: Disconnected (Never connected)")
                                await send_chat_message(f"Database status: Disconnected from '{db_status['db_name']}' (No connection established yet)")
                        # Record usage
                        add_usage('dbstatus', bucket_key, cooldown_bucket)
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to check database status but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in database_status_command: {e}")
            await send_chat_message("An error occurred while checking database status.")

    @commands.command(name='forceonline')
    async def forceonline_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("forceonline",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('forceonline', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        chat_logger.info(f"Stream status forcibly set to online by {ctx.author.name}.")
                        bot_logger.info(f"Stream is now online!")
                        await send_chat_message("Stream status has been forcibly set to online.")
                        create_task(websocket_notice(event="STREAM_ONLINE"))
                        # Record usage
                        add_usage('forceonline', bucket_key, cooldown_bucket)
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to use the force online command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in forceonline_command: {e}")
            await send_chat_message(f"An error occurred while executing the command. {e}")

    @commands.command(name='forceoffline')
    async def forceoffline_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("forceoffline",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('forceoffline', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        chat_logger.info(f"Stream status forcibly set to offline by {ctx.author.name}.")
                        bot_logger.info(f"Stream is now offline.")
                        await send_chat_message("Stream status has been forcibly set to offline.")
                        create_task(websocket_notice(event="STREAM_OFFLINE"))
                        # Record usage
                        add_usage('forceoffline', bucket_key, cooldown_bucket)
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to use the force offline command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
                else:
                    await send_chat_message("Command not found.")
        except Exception as e:
            chat_logger.error(f"Error in forceoffline_command: {e}")
            await send_chat_message(f"An error occurred while executing the command. {e}")

    @commands.command(name='version')
    async def version_command(self, ctx):
        global bot_owner, bot_started
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("version",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('version', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        # Check premium feature status
                        message_user = ctx.author.name
                        premium_tier = await check_premium_feature(message_user)
                        uptime = time_right_now()- bot_started
                        uptime_days = uptime.days
                        uptime_hours, remainder = divmod(uptime.seconds, 3600)
                        uptime_minutes, _ = divmod(remainder, 60)
                        # Build the message
                        message = f"The version that I'm currently running is V{VERSION} {SYSTEM}. "
                        message += "I've been running for: "
                        if uptime_days == 1:
                            message += f"1 day, "
                        elif uptime_days > 1:
                            message += f"{uptime_days} days, "
                        if uptime_hours == 1:
                            message += f"1 hour, "
                        elif uptime_hours > 1:
                            message += f"{uptime_hours} hours, "
                        if uptime_minutes == 1:
                            message += f"1 minute, "
                        elif uptime_minutes > 1 or (uptime_days == 0 and uptime_hours == 0):
                            message += f"{uptime_minutes} minutes, "
                        # Add premium status information
                        if message_user == bot_owner:
                            premium_status = "Premium Features: Bot Owner Control"
                        elif premium_tier == 4000:
                            premium_status = "Premium Features: Beta User Access"
                        elif premium_tier == 3000:
                            premium_status = "Premium Features: Tier 3 Subscriber"
                        elif premium_tier == 2000:
                            premium_status = "Premium Features: Tier 2 Subscriber"
                        elif premium_tier == 1000:
                            premium_status = "Premium Features: Tier 1 Subscriber"
                        else:
                            premium_status = "Premium Features: None"
                        await send_chat_message(f"{message[:-2]}. {premium_status}")
                        # Record usage
                        add_usage('version', bucket_key, cooldown_bucket)
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the version command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the version command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='roadmap')
    async def roadmap_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("roadmap",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('roadmap', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        await send_chat_message("BotOfTheSpecter Roadmap can be found here: https://roadmap.botofthespecter.com/")
                        # Record usage
                        add_usage('roadmap', bucket_key, cooldown_bucket)
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the roadmap command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the roadmap command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='weather')
    async def weather_command(self, ctx, *, location: str = None) -> None:
        global bot_owner, CHANNEL_NAME
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("weather",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if websocket is connected - weather data comes via websocket
                    if not is_websocket_connected():
                        await send_chat_message(f"The bot is not connected to the weather data service. @{CHANNEL_NAME} please restart me to reconnect to the service.")
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('weather', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        if not location:
                            location = await get_streamer_weather()
                        if location:
                            async with httpClientSession() as session:
                                response = await session.get(f"https://api.botofthespecter.com/weather?api_key={API_TOKEN}&location={location}")
                                result = await response.json()
                                if "detail" in result and "404: Location" in result["detail"]:
                                    await send_chat_message(f"Error: The location '{location}' was not found.")
                                    api_logger.info(f"API - BotOfTheSpecter - WeatherCommand - {result}")
                                else:
                                    api_logger.info(f"API - BotOfTheSpecter - WeatherCommand - {result}")
                        else:
                            await send_chat_message("Unable to retrieve location.")
                        # Record usage
                        add_usage('weather', bucket_key, cooldown_bucket)
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the weather command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the weather command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='points')
    async def points_command(self, ctx):
        global bot_owner
        user_id = str(ctx.author.id)
        user_name = ctx.author.name
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("points",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('points', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        settings = await get_point_settings()
                        if settings and 'excluded_users' in settings:
                            excluded_users = [user.strip().lower() for user in settings['excluded_users'].split(',')]
                            if user_name.lower() in excluded_users:
                                await send_chat_message(f'@{user_name}, you have 0 points.')
                                add_usage('points', bucket_key, cooldown_bucket)
                                return
                        result = await manage_user_points(user_id, user_name, "get")
                        if result["success"]:
                            points = result["points"]
                            await send_chat_message(f'@{user_name}, you have {points} points.')
                            add_usage('points', bucket_key, cooldown_bucket)
                        else:
                            await send_chat_message(f"Error checking points: {result['error']}")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the points command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the points command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='addpoints')
    async def addpoints_command(self, ctx, user: str, points_to_add: int):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("addpoints",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('addpoints', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        user = user.lstrip('@')
                        user_info = await self.fetch_users(names=[user])
                        if not user_info:
                            await send_chat_message(f"User {user} not found.")
                            return
                        target_user_id = str(user_info[0].id)
                        target_user_name = user_info[0].name
                        result = await manage_user_points(target_user_id, target_user_name, "credit", points_to_add)
                        if result["success"]:
                            await send_chat_message(f"Added {points_to_add} points to {target_user_name}. They now have {result['points']} points.")
                            add_usage('addpoints', bucket_key, cooldown_bucket)
                        else:
                            await send_chat_message(f"Error adding points: {result['error']}")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of addpoints_command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='removepoints')
    async def removepoints_command(self, ctx, user: str, points_to_remove: int):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("removepoints",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('removepoints', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        user = user.lstrip('@')
                        user_info = await self.fetch_users(names=[user])
                        if not user_info:
                            await send_chat_message(f"User {user} not found.")
                            return
                        target_user_id = str(user_info[0].id)
                        target_user_name = user_info[0].name
                        result = await manage_user_points(target_user_id, target_user_name, "debit", points_to_remove)
                        if result["success"]:
                            await send_chat_message(f"Removed {result['amount_changed']} points from {target_user_name}. They now have {result['points']} points.")
                            add_usage('removepoints', bucket_key, cooldown_bucket)
                        else:
                            await send_chat_message(f"Error removing points: {result['error']}")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of removepoints_command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='time')
    async def time_command(self, ctx, *, timezone: str = None) -> None:
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("time",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('time', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        if timezone:
                            # Validate input format (should contain a comma for location,country)
                            if ',' not in timezone:
                                await send_chat_message(f"Please use the format: Location,Country (e.g., 'NewYork,US' or 'Sydney,AU')")
                                chat_logger.info(f"Invalid time format provided: '{timezone}' - missing country code")
                                return
                            geolocator = Nominatim(user_agent="BotOfTheSpecter")
                            location_data = geolocator.geocode(timezone, addressdetails=True)
                            if not location_data:
                                await send_chat_message(f"Could not find the location '{timezone}'. Please use the format: Location,Country (e.g., 'California,US' or 'Sydney,AU')")
                                chat_logger.info(f"Could not find the time location that you requested: '{timezone}'")
                                return
                            # Validate that we got a meaningful location (city, state, or country)
                            address = location_data.raw.get('address', {})
                            valid_location_types = ['city', 'town', 'village', 'state', 'country', 'county', 'municipality']
                            has_valid_location = any(key in address for key in valid_location_types)
                            if not has_valid_location:
                                await send_chat_message(f"Could not find a valid location for '{timezone}'. Please provide a valid city and country code.")
                                chat_logger.info(f"Invalid location type for '{timezone}': {address}")
                                return
                            timezone_api_key = os.getenv('TIMEZONE_API')
                            timezone_url = f"http://api.timezonedb.com/v2.1/get-time-zone?key={timezone_api_key}&format=json&by=position&lat={location_data.latitude}&lng={location_data.longitude}"
                            async with httpClientSession() as session:
                                async with session.get(timezone_url) as response:
                                    if response.status != 200:
                                        await send_chat_message(f"Could not retrieve time information from the API.")
                                        chat_logger.info(f"Failed to retrieve time information from the API, status code: {response.status}")
                                        return
                                    timezone_data = await response.json()
                            if timezone_data['status'] != "OK":
                                await send_chat_message(f"Could not find the time location that you requested.")
                                chat_logger.info(f"Could not find the time location that you requested.")
                                return
                            # Get a user-friendly location name from the geocoding result
                            display_location = address.get('city') or address.get('town') or address.get('village') or address.get('state') or address.get('country') or timezone.split(',')[0]
                            timezone_str = timezone_data["zoneName"]
                            tz = pytz_timezone(timezone_str)
                            chat_logger.info(f"TZ: {tz} | Timezone: {timezone_str} | Location: {display_location}")
                            current_time = time_right_now(tz)
                            time_format_date = current_time.strftime("%B %d, %Y")
                            time_format_time = current_time.strftime("%I:%M %p")
                            time_format_week = current_time.strftime("%A")
                            time_format = f"The time for {display_location} is {time_format_week}, {time_format_date} and the time is: {time_format_time}"
                        else:
                            await cursor.execute("SELECT timezone FROM profile")
                            result = await cursor.fetchone()
                            if result and result.get("timezone"):
                                timezone = result.get("timezone")
                                tz = pytz_timezone(timezone)
                                chat_logger.info(f"TZ: {tz} | Timezone: {timezone}")
                                current_time = time_right_now(tz)
                                time_format_date = current_time.strftime("%B %d, %Y")
                                time_format_time = current_time.strftime("%I:%M %p")
                                time_format_week = current_time.strftime("%A")
                                time_format = f"It is {time_format_week}, {time_format_date} and the time is: {time_format_time}"
                            else:
                                await send_chat_message("Streamer timezone is not set.")
                                return
                        await send_chat_message(time_format)
                        # Record usage
                        add_usage('time', bucket_key, cooldown_bucket)
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the time command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the time command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='joke')
    async def joke_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("joke",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('joke', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        # Retrieve the blacklist from the joke_settings table
                        await cursor.execute("SELECT blacklist FROM joke_settings WHERE id = 1")
                        blacklist_result = await cursor.fetchone()
                        # Parse the blacklist safely (default to empty list if missing or invalid)
                        blacklist_json = blacklist_result.get("blacklist") if blacklist_result else None
                        try:
                            blacklist = json.loads(blacklist_json) if blacklist_json else []
                        except Exception as e:
                            chat_logger.error(f"Error parsing joke blacklist: {e}")
                            blacklist = []
                        blacklist_lower = {cat.lower() for cat in blacklist}
                        # Instantiate the JokeAPI client (constructor is not awaitable)
                        joke = Jokes()
                        while True:
                            # Fetch a joke from the JokeAPI
                            # Fetch the joke synchronously in a thread to avoid blocking the event loop
                            get_joke = await get_event_loop().run_in_executor(None, joke.get_joke)
                            # Normalize response: some library versions return a list instead of a dict
                            if isinstance(get_joke, list):
                                if len(get_joke) == 0:
                                    continue
                                first = get_joke[0]
                                if isinstance(first, dict):
                                    get_joke = first
                                else:
                                    get_joke = {"type": "single", "category": "Unknown", "joke": str(first)}
                            elif not isinstance(get_joke, dict):
                                # Some unexpected shape (e.g., tuple); coerce to a safe dict
                                get_joke = {"type": "single", "category": "Unknown", "joke": str(get_joke)}
                            # Resolve the category and check against the blacklist
                            try:
                                category = (get_joke.get("category") or "").lower()
                            except Exception:
                                category = ""
                            if category not in blacklist_lower:
                                break
                        # Send the joke based on its type
                        if get_joke.get("type") == "single":
                            await send_chat_message(f"Here's a joke from {get_joke['category']}: {get_joke.get('joke')}")
                        else:
                            await send_chat_message(f"Here's a joke from {get_joke['category']}: {get_joke.get('setup')} | {get_joke.get('delivery')}")
                        # Record usage
                        add_usage('joke', bucket_key, cooldown_bucket)    
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the joke command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the joke command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='quote')
    async def quote_command(self, ctx, number: int = None):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("quote",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('quote', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        if number is None:  # If no number is provided, get a random quote
                            await cursor.execute("SELECT quote FROM quotes ORDER BY RAND() LIMIT 1")
                            quote = await cursor.fetchone()
                            if quote:
                                await send_chat_message("Random Quote: " + quote["quote"])
                            else:
                                await send_chat_message("No quotes available.")
                        else:  # If a number is provided, retrieve the quote by its ID
                            await cursor.execute("SELECT quote FROM quotes WHERE id = %s", (number,))
                            quote = await cursor.fetchone()
                            if quote:
                                await send_chat_message(f"Quote {number}: " + quote["quote"])
                            else:
                                await send_chat_message(f"No quote found with ID {number}.")
                        # Record usage
                        add_usage('quote', bucket_key, cooldown_bucket)
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the quote command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the quote command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='quoteadd')
    async def quoteadd_command(self, ctx, *, quote):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("quoteadd",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('quoteadd', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        await cursor.execute("INSERT INTO quotes (quote) VALUES (%s)", (quote,))
                        await connection.commit()
                        await send_chat_message("Quote added successfully: " + quote)
                        # Record usage
                        add_usage('quoteadd', bucket_key, cooldown_bucket)
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to add a quote but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the quoteadd command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='removequote')
    async def quoteremove_command(self, ctx, number: int = None):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("removequote",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('removequote', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        if number is None:
                            await send_chat_message("Please specify the ID to remove.")
                            return
                        await cursor.execute("DELETE FROM quotes WHERE id = %s", (number,))
                        await connection.commit()
                        if cursor.rowcount > 0:  # Check if a row was deleted
                            await send_chat_message(f"Quote {number} has been removed.")
                            # Record usage
                            add_usage('removequote', bucket_key, cooldown_bucket)
                        else:
                            await send_chat_message(f"No quote found with ID {number}.")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to remove a quote but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the removequote command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='permit')
    async def permit_command(self, ctx, permit_user: str = None):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the permit command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("permit",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('permit', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the required permissions
                    if await command_permissions(permissions, ctx.author):
                        permit_user = permit_user.lstrip('@')
                        if permit_user:
                            permitted_users[permit_user] = time.time() + 30
                            await send_chat_message(f"{permit_user} is now permitted to post links for the next 30 seconds.")
                            # Record usage
                            add_usage('permit', bucket_key, cooldown_bucket)
                        else:
                            await send_chat_message("Please specify a user to permit.")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to use the permit command but lacked permissions.")
                        await send_chat_message("You do not have the correct permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the permit command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='settitle')
    async def settitle_command(self, ctx, *, title: str = None) -> None:
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the settitle command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("settitle",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('settitle', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the required permissions
                    if await command_permissions(permissions, ctx.author):
                        if title is None:
                            await send_chat_message("Stream titles cannot be blank. You must provide a title for the stream.")
                            return
                        # Update the stream title
                        await trigger_twitch_title_update(title)
                        twitch_logger.info(f'Setting stream title to: {title}')
                        await send_chat_message(f'Stream title updated to: {title}')
                        # Record usage
                        add_usage('settitle', bucket_key, cooldown_bucket)
                    else:
                        await send_chat_message("You do not have the correct permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the settitle command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='setgame')
    async def setgame_command(self, ctx, *, game: str = None) -> None:
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the setgame command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("setgame",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Verify user permissions
                    if await command_permissions(permissions, ctx.author):
                        # Check cooldown
                        bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                        if not await check_cooldown('setgame', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                            return
                        if game is None:
                            await send_chat_message("You must provide a game for the stream.")
                            return
                        try:
                            game_name = await update_twitch_game(game)
                            await send_chat_message(f'Stream game updated to: {game_name}')
                            # Record usage
                            add_usage('setgame', bucket_key, cooldown_bucket)
                        except GameNotFoundException as e:
                            await send_chat_message(f"Game not found: {str(e)}")
                        except GameUpdateFailedException as e:
                            await send_chat_message(f"Failed to update game: {str(e)}")
                        except GeneratorExit:
                            raise
                        except Exception as e:
                            try:
                                await send_chat_message(f'An error occurred in setgame command: {str(e)}')
                            except:
                                pass
                    else:
                        await send_chat_message("You do not have the correct permissions to use this command.")
        except GeneratorExit:
            chat_logger.info("Setgame command cancelled due to shutdown")
            raise
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the setgame command: {e}")
            try:
                await send_chat_message("An unexpected error occurred. Please try again later.")
            except:
                pass

    @commands.command(name='song')
    async def song_command(self, ctx):
        global stream_online, song_requests, bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the song command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("song",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('song', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                # Get the current song and artist from Spotify
                song_name, artist_name, song_id, spotify_error = await get_spotify_current_song()
                # If Spotify succeeded and returned song data
                if song_name and artist_name:
                    # If the stream is offline, notify that the user that the streamer is listening to music while offline
                    if not stream_online:
                        await send_chat_message(f"{CHANNEL_NAME} is currently listening to \"{song_name} by {artist_name}\" while being offline.")
                        add_usage('song', bucket_key, cooldown_bucket)
                        return
                    # Check if the song is in the tracked list and if a user is associated
                    requested_by = None
                    if song_id in song_requests:
                        requested_by = song_requests[song_id].get("user")
                    if requested_by:
                        await send_chat_message(f"The current playing song is: {song_name} by {artist_name}, requested by {requested_by}")
                    else:
                        await send_chat_message(f"The current playing song is: {song_name} by {artist_name}")
                    add_usage('song', bucket_key, cooldown_bucket)
                    return
                # Spotify failed or returned no song, attempt failover to Shazam
                if not stream_online:
                    await send_chat_message("Sorry, I can only get the current playing song while the stream is online.")
                    return
                # Check if premium is available for Shazam failover
                message_user = ctx.author.name
                premium_tier = await check_premium_feature(message_user)
                if premium_tier in (1000, 2000, 3000, 4000):
                    # Premium feature access granted - use Shazam as failover
                    await send_chat_message("Please stand by, checking what song is currently playing...")
                    try:
                        song_info = await shazam_the_song()
                        await send_chat_message(song_info)
                        await delete_recorded_files()
                    except Exception as e:
                        chat_logger.error(f"An error occurred while getting current song via Shazam: {e}")
                        await send_chat_message("Sorry, there was an error retrieving the current song.")
                else:
                    # No premium access
                    await send_chat_message("Sorry, I couldn't determine the current song.")
                # Record usage
                add_usage('song', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the song command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='songrequest', aliases=['sr'])
    async def songrequest_command(self, ctx):
        global SPOTIFY_ERROR_MESSAGES, song_requests, bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the songrequest command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("songrequest",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        await send_chat_message(f"Requesting songs is currently disabled.")
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('songrequest', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
            access_token = await get_spotify_access_token()
            headers = {"Authorization": f"Bearer {access_token}"}
            message = ctx.message.content
            parts = message.split(" ", 1)
            if len(parts) < 2 or not parts[1].strip():
                await send_chat_message("Please provide a song title, artist, YouTube link, or a Spotify link. Examples: !songrequest [song title] by [artist] or !songrequest https://www.youtube.com/watch?v=... or !songrequest https://open.spotify.com/track/...")
                return
            message_content = parts[1].strip()
            # Spotify URL patterns - both track and album
            spotify_track_url_patterns = [
                re.compile(r'https?://open\.spotify\.com/track/([a-zA-Z0-9]+)'),
                re.compile(r'https?://open\.spotify\.com/intl-[a-z]{2}/track/([a-zA-Z0-9]+)'),
                re.compile(r'https?://spotify\.link/([a-zA-Z0-9]+)'),  # Short links
                re.compile(r'spotify:track:([a-zA-Z0-9]+)')
            ]
            spotify_album_url_patterns = [
                re.compile(r'https?://open\.spotify\.com/album/([a-zA-Z0-9]+)'),
                re.compile(r'https?://open\.spotify\.com/intl-[a-z]{2}/album/([a-zA-Z0-9]+)'),
                re.compile(r'spotify:album:([a-zA-Z0-9]+)')
            ]
            # Check for album links and prompt user to provide a track link instead
            album_match = None
            for pattern in spotify_album_url_patterns:
                album_match = pattern.search(message_content)
                if album_match:
                    break
            if album_match:
                await send_chat_message("That looks like a Spotify album link. Please provide a Spotify track link instead.")
                return
            # YouTube URL patterns
            youtube_url_patterns = [
                re.compile(r'https?://(www\.)?youtube\.com/watch\?v=([a-zA-Z0-9_-]+)'),
                re.compile(r'https?://(www\.)?youtube\.com/v/([a-zA-Z0-9_-]+)'),
                re.compile(r'https?://youtu\.be/([a-zA-Z0-9_-]+)'),
                re.compile(r'https?://(www\.)?youtube\.com/embed/([a-zA-Z0-9_-]+)'),
                re.compile(r'https?://m\.youtube\.com/watch\?v=([a-zA-Z0-9_-]+)')
            ]
            # Check if it's a YouTube link
            youtube_match = None
            for pattern in youtube_url_patterns:
                youtube_match = pattern.search(message_content)
                if youtube_match:
                    break
            if youtube_match:
                # Extract video title using yt-dlp
                try:
                    ydl_opts = {
                        'quiet': True,
                        'no_warnings': True,
                        'extractaudio': False,
                        'skip_download': True,
                        'cookiefile': '/home/botofthespecter/ytdl-cookies.txt',
                    }
                    with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                        info = ydl.extract_info(message_content, download=False)
                        video_title = info.get('title', '')
                        if not video_title:
                            await send_chat_message("Could not extract title from the YouTube video.")
                            return
                        # Clean up the title for better Spotify search results
                        # Remove common YouTube suffixes and prefixes
                        cleanup_patterns = [
                            r'\s*\[.*?\]\s*',  # Remove [Official Video], [Lyrics], etc.
                            r'\s*\(.*?\)\s*',  # Remove (Official Video), (Lyrics), etc.
                            r'\s*-\s*(Official|Music|Lyric|Audio).*$' ,  # Remove - Official Video, etc.
                            r'\s*\|\s*.*$',  # Remove everything after |
                            r'\s*(HD|4K|1080p|720p).*$' ,  # Remove quality indicators
                            r'\s*(feat\.|ft\.|featuring)',  # Normalize featuring
                        ]
                        cleaned_title = video_title
                        for pattern in cleanup_patterns:
                            cleaned_title = re.sub(pattern, '', cleaned_title, flags=re.IGNORECASE)
                        cleaned_title = cleaned_title.strip()
                        # Use the cleaned title for Spotify search
                        message_content = cleaned_title
                        api_logger.info(f"YouTube title extracted: '{video_title}' -> cleaned: '{cleaned_title}'")
                except Exception as e:
                    api_logger.error(f"Error extracting YouTube video info: {e}")
                    await send_chat_message("Sorry, I couldn't extract information from that YouTube link. Please try a different link or provide the song title manually.")
                    return
            # Check for Spotify track links
            track_match = None
            track_id = None
            for pattern in spotify_track_url_patterns:
                track_match = pattern.search(message_content)
                if track_match:
                    track_id = track_match.group(1)
                    break
            if track_match:
                track_url = f"https://api.spotify.com/v1/tracks/{track_id}"
                async with httpClientSession() as track_session:
                    async with track_session.get(track_url, headers=headers) as response:
                        if response.status == 200:
                            track_data = await response.json()
                            song_id = track_data["uri"]
                            song_name = track_data["name"]
                            artist_name = track_data["artists"][0]["name"]
                            unwanted_keywords = ["instrumental", "karaoke version"]
                            if any(keyword in song_name.lower() or keyword in artist_name.lower() for keyword in unwanted_keywords):
                                await send_chat_message(f"Sorry, I don't accept karaoke or instrumental versions.")
                                return
                            api_logger.info(f"Song Request from {ctx.message.author.name} for {song_name} by {artist_name} song id: {song_id}")
                            song_requests[song_id] = { "user": ctx.message.author.name, "song_name": song_name, "artist_name": artist_name, "timestamp": time_right_now()}
                        else:
                            api_logger.error(f"Spotify returned response code: {response.status}")
                            error_message = SPOTIFY_ERROR_MESSAGES.get(response.status, "Spotify gave me an unknown error. Try again in a moment.")
                            await send_chat_message(f"Sorry, I couldn't find that song. {error_message}")
                            return
            else:
                # Use search for non-Spotify URL requests (including YouTube-extracted titles)
                search = message_content.replace(" ", "%20")
                search_url = f"https://api.spotify.com/v1/search?q={search}&type=track&limit=1"
                async with httpClientSession() as search_session:
                    async with search_session.get(search_url, headers=headers) as response:
                        if response.status == 200:
                            data = await response.json()
                            tracks = data.get("tracks", {}).get("items", [])
                            if not tracks:
                                await send_chat_message(f"No song found: {message_content}")
                                return
                            track = tracks[0]
                            song_id = track["uri"]
                            song_name = track["name"]
                            artist_name = track["artists"][0]["name"]
                            unwanted_keywords = ["instrumental", "karaoke version"]
                            if any(keyword in song_name.lower() or keyword in artist_name.lower() for keyword in unwanted_keywords):
                                await send_chat_message(f"No song found: {message_content}")
                                return
                            api_logger.info(f"Song Request from {ctx.message.author.name} for {song_name} by {artist_name} song id: {song_id}")
                            song_requests[song_id] = { "user": ctx.message.author.name, "song_name": song_name, "artist_name": artist_name, "timestamp": time_right_now()}
                        else:
                            api_logger.error(f"Spotify returned response code: {response.status}")
                            error_message = SPOTIFY_ERROR_MESSAGES.get(response.status, "Spotify gave me an unknown error. Try again in a moment.")
                            await send_chat_message(f"Sorry, I couldn't add the song to the queue. {error_message}")
                            return
            # Add to Spotify queue
            request_url = f"https://api.spotify.com/v1/me/player/queue?uri={song_id}"
            async with httpClientSession() as queue_session:
                async with queue_session.post(request_url, headers=headers) as response:
                    if response.status == 200:
                        await send_chat_message(f"The song {song_name} by {artist_name} has been added to the queue.")
                        # Record usage
                        add_usage('songrequest', bucket_key, cooldown_bucket)
                    else:
                        api_logger.error(f"Spotify returned response code: {response.status}")
                        error_message = SPOTIFY_ERROR_MESSAGES.get(response.status, "Spotify gave me an unknown error. Try again in a moment.")
                        await send_chat_message(f"Sorry, I couldn't add the song to the queue. {error_message}")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the songrequest command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='skipsong', aliases=['skip'])
    async def skipsong_command(self, ctx):
        global SPOTIFY_ERROR_MESSAGES, song_requests, bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("skipsong",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        await send_chat_message(f"Skipping songs is currently disabled.")
                        return
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('skipsong', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
            access_token = await get_spotify_access_token()
            headers = {"Authorization": f"Bearer {access_token}"}
            device_url = "https://api.spotify.com/v1/me/player/devices"
            async with httpClientSession() as session:
                device_id = None
                async with session.get(device_url, headers=headers) as response:
                    if response.status != 200:
                        active_devices = await response.json()
                        current_active_devices = active_devices.get("devices", [])
                        if not current_active_devices:
                            await send_chat_message("No active Spotify devices found. Please make sure you have an active device playing Spotify.")
                            return
                        for device in current_active_devices:
                            if device.get("is_active"):
                                device_id = device["id"]
                                break
                        if device_id is None:
                            await send_chat_message("No active Spotify devices found. Please make sure you have an active device playing Spotify.")
                            return
                    else:
                        # If status is 200, still need to parse devices
                        active_devices = await response.json()
                        current_active_devices = active_devices.get("devices", [])
                        for device in current_active_devices:
                            if device.get("is_active"):
                                device_id = device["id"]
                                break
                        if device_id is None:
                            await send_chat_message("No active Spotify devices found. Please make sure you have an active device playing Spotify.")
                            return
                next_url = f"https://api.spotify.com/v1/me/player/next?device_id={device_id}"
                async with session.post(next_url, headers=headers) as response:
                    if response.status in (200, 204):
                        api_logger.info(f"Song skipped successfully by {ctx.message.author.name}")
                        await send_chat_message("Song skipped successfully.")
                        # Record usage
                        add_usage('skipsong', bucket_key, cooldown_bucket)
                    else:
                        api_logger.error(f"Spotify returned response code: {response.status}")
                        error_message = SPOTIFY_ERROR_MESSAGES.get(response.status, "Spotify gave me an unknown error. Try again in a moment.")
                        await send_chat_message(f"Sorry, I couldn't skip the song. {error_message}")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the skipsong command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='songqueue', aliases=['sq', 'queue'])
    async def songqueue_command(self, ctx):
        global SPOTIFY_ERROR_MESSAGES, song_requests, bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the songqueue command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("songqueue",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        await send_chat_message(f"Sorry, checking the song queue is currently disabled.")
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('songqueue', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
            # Request the queue information from Spotify
            access_token = await get_spotify_access_token()
            headers = {"Authorization": f"Bearer {access_token}"}
            queue_url = "https://api.spotify.com/v1/me/player/queue"
            async with httpClientSession() as queue_session:
                async with queue_session.get(queue_url, headers=headers) as response:
                    if response.status == 200:
                        data = await response.json()
                        if data and 'queue' in data:
                            queue = data['queue']
                            queue_length = len(queue)
                            # Get the currently playing song
                            song_name, artist_name, song_id, spotify_error = await get_spotify_current_song()
                            # Check if there was a Spotify error when getting current song
                            if spotify_error:
                                await send_chat_message(spotify_error)
                                return
                            current_song_requester = song_requests.get(song_id, {}).get("user") if song_id in song_requests else None
                            if song_name and artist_name:
                                if current_song_requester:
                                    await send_chat_message(f"🎵 Now Playing: {song_name} by {artist_name} (requested by {current_song_requester})")
                                else:
                                    await send_chat_message(f"🎵 Now Playing: {song_name} by {artist_name}")
                            # Format the song queue
                            song_list = []
                            for idx, song in enumerate(queue, start=1):
                                song_id = song['uri']
                                song_name = song['name']
                                artist_name = song['artists'][0]['name']
                                requester = song_requests.get(song_id, {}).get("user") if song_id in song_requests else None
                                if requester:
                                    song_list.append(f"{idx}. {song_name} by {artist_name} (requested by {requester})")
                                else:
                                    song_list.append(f"{idx}. {song_name} by {artist_name}")
                                if idx >= 3:
                                    break
                            # Add a note if there are more songs in the queue
                            if queue_length > 3:
                                song_list.append(f"...and {queue_length - 3} more songs in the queue.")
                            # Send the queue to chat
                            if song_list:
                                await send_chat_message(f"Upcoming Songs:\n" + "\n".join(song_list))
                            else:
                                await send_chat_message("The queue is empty right now. Add some songs!")
                        else:
                            await send_chat_message("It seems like nothing is playing on Spotify right now.")
                    else:
                        error_message = SPOTIFY_ERROR_MESSAGES.get(response.status, "Something went wrong with Spotify. Please try again soon.")
                        await send_chat_message(f"Sorry, I couldn't fetch the queue. {error_message}")
                        api_logger.error(f"Spotify returned response code: {response.status}")
            # Record usage
            add_usage('songqueue', bucket_key, cooldown_bucket)
        except GeneratorExit:
            api_logger.info("Songqueue command cancelled due to shutdown")
            raise
        except Exception as e:
            api_logger.error(f"Error in songqueue_command: {e}")
            try:
                await send_chat_message("Something went wrong while fetching the song queue. Please try again later.")
            except:
                pass

    @commands.command(name='timer')
    async def timer_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the timer command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("timer",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('timer', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                # Check if the user already has an active timer
                await cursor.execute("SELECT end_time FROM active_timers WHERE user_id=%s", (ctx.author.id,))
                active_timer = await cursor.fetchone()
                if active_timer:
                    await send_chat_message(f"@{ctx.author.name}, you already have an active timer.")
                    return
                content = ctx.message.content.strip()
                try:
                    _, minutes = content.split(' ')
                    minutes = int(minutes)
                except ValueError:
                    # Default to 5 minutes if the user didn't provide a valid value
                    minutes = 5
                end_time = time_right_now(timezone.utc) + timedelta(minutes=minutes)
                await cursor.execute("INSERT INTO active_timers (user_id, end_time) VALUES (%s, %s)", (ctx.author.id, end_time))
                await connection.commit()
                await send_chat_message(f"Timer started for {minutes} minute(s) @{ctx.author.name}.")
                await sleep(minutes * 60)
                await send_chat_message(f"The {minutes} minute timer has ended @{ctx.author.name}!")
                # Remove the timer from the active_timers table
                await cursor.execute("DELETE FROM active_timers WHERE user_id=%s", (ctx.author.id,))
                await connection.commit()
                # Record usage
                add_usage('timer', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the timer command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='stoptimer')
    async def stoptimer_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the stoptimer command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("stoptimer",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('stoptimer', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                await cursor.execute("SELECT end_time FROM active_timers WHERE user_id=%s", (ctx.author.id,))
                active_timer = await cursor.fetchone()
                if not active_timer:
                    await send_chat_message(f"@{ctx.author.name}, you don't have an active timer.")
                    return
                await cursor.execute("DELETE FROM active_timers WHERE user_id=%s", (ctx.author.id,))
                await connection.commit()
                await send_chat_message(f"Your timer has been stopped @{ctx.author.name}.")
                # Record usage
                add_usage('stoptimer', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the stoptimer command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='checktimer')
    async def checktimer_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the checktimer command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("checktimer",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('checktimer', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                await cursor.execute("SELECT end_time FROM active_timers WHERE user_id=%s", (ctx.author.id,))
                active_timer = await cursor.fetchone()
                if not active_timer:
                    await send_chat_message(f"@{ctx.author.name}, you don't have an active timer.")
                    return
                end_time = active_timer["end_time"]
                remaining_time = end_time - time_right_now(timezone.utc)
                minutes_left = remaining_time.total_seconds() // 60
                seconds_left = remaining_time.total_seconds() % 60
                await send_chat_message(f"@{ctx.author.name}, your timer has {int(minutes_left)} minute(s) and {int(seconds_left)} second(s) left.")
                # Record usage
                add_usage('checktimer', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the checktimer command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='hug')
    async def hug_command(self, ctx, mentioned_username: str = None):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the hug command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("hug",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('hug', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                # Remove any '@' symbol from the mentioned username if present
                if mentioned_username:
                    mentioned_username = mentioned_username.lstrip('@')
                else:
                    await send_chat_message("Usage: !hug @username")
                    return
                if mentioned_username == ctx.author.name:
                    await send_chat_message("You can't hug yourself.")
                    return
                # Check if the mentioned username is valid on Twitch
                is_valid_user = await is_valid_twitch_user(mentioned_username)
                if not is_valid_user:
                    chat_logger.error(f"User {mentioned_username} does not exist on Twitch. Instead, you hugged the air.")
                    await send_chat_message(f"The user @{mentioned_username} does not exist on Twitch.")
                    return
                # Increment hug count in the database
                await cursor.execute(
                    'INSERT INTO hug_counts (username, hug_count) VALUES (%s, 1) '
                    'ON DUPLICATE KEY UPDATE hug_count = hug_count + 1', 
                    (mentioned_username,)
                )
                await connection.commit()
                # Retrieve the updated count
                await cursor.execute('SELECT hug_count FROM hug_counts WHERE username = %s', (mentioned_username,))
                hug_count_result = await cursor.fetchone()
                if hug_count_result:
                    hug_count = hug_count_result.get("hug_count")
                    # Send the message
                    chat_logger.info(f"{mentioned_username} has been hugged by {ctx.author.name}. They have been hugged: {hug_count}")
                    await send_chat_message(f"@{mentioned_username} has been hugged by @{ctx.author.name}, they have been hugged {hug_count} times.")
                    if mentioned_username == BOT_USERNAME:
                        author = ctx.author.name
                        await return_the_action_back(ctx, author, "hug")
                    # Record usage
                    add_usage('hug', bucket_key, cooldown_bucket)
                else:
                    chat_logger.error(f"No hug count found for user: {mentioned_username}")
                    await send_chat_message(f"Sorry @{ctx.author.name}, you can't hug @{mentioned_username} right now, there's an issue in my system.")
        except Exception as e:
            chat_logger.error(f"Error in hug command: {e}")
            await send_chat_message("An error occurred while processing the command.")

    @commands.command(name='highfive')
    async def highfive_command(self, ctx, mentioned_username: str = None):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the hug command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("highfive",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('highfive', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                # Remove any '@' symbol from the mentioned username if present
                if mentioned_username:
                    mentioned_username = mentioned_username.lstrip('@')
                else:
                    await send_chat_message("Usage: !highfive @username")
                    return
                if mentioned_username == ctx.author.name:
                    await send_chat_message("You can't high-five yourself.")
                    return
                # Check if the mentioned username is valid on Twitch
                is_valid_user = await is_valid_twitch_user(mentioned_username)
                if not is_valid_user:
                    chat_logger.error(f"User {mentioned_username} does not exist on Twitch. You swung and hit only air.")
                    await send_chat_message(f"The user @{mentioned_username} does not exist on Twitch.")
                    return
                # Increment highfive count in the database
                await cursor.execute(
                    'INSERT INTO highfive_counts (username, highfive_count) VALUES (%s, 1) '
                    'ON DUPLICATE KEY UPDATE highfive_count = highfive_count + 1', 
                    (mentioned_username,)
                )
                await connection.commit()
                # Retrieve the updated count
                await cursor.execute('SELECT highfive_count FROM highfive_counts WHERE username = %s', (mentioned_username,))
                highfive_count_result = await cursor.fetchone()
                if highfive_count_result:
                    highfive_count = highfive_count_result.get("highfive_count")
                    # Send the message
                    chat_logger.info(f"{mentioned_username} has been high-fived by {ctx.author.name}. They have been high-fived: {highfive_count}")
                    await send_chat_message(f"@{mentioned_username} has been high-fived by @{ctx.author.name}, they have been high-fived {highfive_count} times.")
                    if mentioned_username == BOT_USERNAME:
                        author = ctx.author.name
                        await return_the_action_back(ctx, author, "highfive")
                    # Record usage
                    add_usage('highfive', bucket_key, cooldown_bucket)
                else:
                    chat_logger.error(f"No high-five count found for user: {mentioned_username}")
                    await send_chat_message(f"Sorry @{ctx.author.name}, you can't high-five @{mentioned_username} right now, there's an issue in my system.")
        except Exception as e:
            chat_logger.error(f"Error in highfive command: {e}")
            await send_chat_message("An error occurred while processing the command.")

    @commands.command(name='kiss')
    async def kiss_command(self, ctx, mentioned_username: str = None):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the kiss command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("kiss",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('kiss', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                # Remove any '@' symbol from the mentioned username if present
                if mentioned_username:
                    mentioned_username = mentioned_username.lstrip('@')
                else:
                    await send_chat_message("Usage: !kiss @username")
                    return
                if mentioned_username == ctx.author.name:
                    await send_chat_message("You can't kiss yourself.")
                    return
                # Check if the mentioned username is valid on Twitch
                is_valid_user = await is_valid_twitch_user(mentioned_username)
                if not is_valid_user:
                    chat_logger.error(f"User {mentioned_username} does not exist on Twitch. You kissed the air.")
                    await send_chat_message(f"The user @{mentioned_username} does not exist on Twitch.")
                    return
                # Increment kiss count in the database
                await cursor.execute(
                    'INSERT INTO kiss_counts (username, kiss_count) VALUES (%s, 1) '
                    'ON DUPLICATE KEY UPDATE kiss_count = kiss_count + 1', 
                    (mentioned_username,)
                )
                await connection.commit()
                # Retrieve the updated count
                await cursor.execute('SELECT kiss_count FROM kiss_counts WHERE username = %s', (mentioned_username,))
                kiss_count_result = await cursor.fetchone()
                if kiss_count_result:
                    kiss_count = kiss_count_result.get("kiss_count")
                    # Send the message
                    chat_logger.info(f"{mentioned_username} has been kissed by {ctx.author.name}. They have been kissed: {kiss_count}")
                    await send_chat_message(f"@{mentioned_username} has been given a peck on the cheek by @{ctx.author.name}, they have been kissed {kiss_count} times.")
                    if mentioned_username == BOT_USERNAME:
                        author = ctx.author.name
                        await return_the_action_back(ctx, author, "kiss")
                    # Record usage
                    add_usage('kiss', bucket_key, cooldown_bucket)
                else:
                    chat_logger.error(f"No kiss count found for user: {mentioned_username}")
                    await send_chat_message(f"Sorry @{ctx.author.name}, you can't kiss @{mentioned_username} right now, there's an issue in my system.")
        except Exception as e:
            chat_logger.error(f"Error in kiss command: {e}")
            await send_chat_message("An error occurred while processing the command.")

    @commands.command(name='ping')
    async def ping_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("ping",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        # Check cooldown
                        bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                        if not await check_cooldown('ping', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                            return
                        # Using asyncio subprocess to run the ping command
                        process = await create_subprocess_exec(
                            "ping", "-c", "1", "ping.botofthespecter.com",
                            stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE
                        )
                        stdout, stderr = await process.communicate()
                        # Decode the result from bytes to string and search for the time
                        output = stdout.decode('utf-8')
                        match = re.search(r"time=(\d+\.\d+) ms", output)
                        if match:
                            ping_time = match.group(1)
                            bot_logger.info(f"Pong: {ping_time} ms")
                            # Updated message to make it clear to the user
                            await send_chat_message(f'Pong: {ping_time} ms – Response time from the bot server to the internet.')
                            # Record usage
                            add_usage('ping', bucket_key, cooldown_bucket)
                        else:
                            bot_logger.error(f"Error Pinging. {output}")
                            await send_chat_message(f'Error pinging the internet from the bot server.')
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to use the ping command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in ping_command: {e}")
            await send_chat_message(f"An error occurred while executing the command. {e}")

    @commands.command(name='translate')
    async def translate_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("translate",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        # Check cooldown
                        bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                        if not await check_cooldown('translate', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                            return
                        # Get the message content after the command
                        message = ctx.message.content[len("!translate "):]
                        # Check if there is a message to translate
                        if not message:
                            await send_chat_message("Please provide a message to translate.")
                            return
                        try:
                            # Check if the input message is too short
                            if len(message.strip()) < 5:
                                await send_chat_message("The provided message is too short for reliable translation.")
                                return
                            translate_message = translator(source='auto', target='en').translate(text=message)
                            await send_chat_message(f"Translation: {translate_message}")
                            # Record usage
                            add_usage('translate', bucket_key, cooldown_bucket)
                        except AttributeError as ae:
                            chat_logger.error(f"AttributeError: {ae}")
                            await send_chat_message("An error occurred while detecting the language.")
                        except Exception as e:
                            chat_logger.error(f"Translating error: {e}")
                            await send_chat_message("An error occurred while translating the message.")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to use the translate command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in translate_command: {e}")
            await send_chat_message(f"An error occurred while executing the command. {e}")

    @commands.command(name='cheerleader', aliases=['bitsleader'])
    async def cheerleader_command(self, ctx):
        global bot_owner, CLIENT_ID, CHANNEL_AUTH
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("cheerleader",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        # Check cooldown
                        bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                        if not await check_cooldown('cheerleader', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                            return
                        headers = {
                            'Client-ID': CLIENT_ID,
                            'Authorization': f'Bearer {CHANNEL_AUTH}'
                        }
                        params = {
                            'count': 1
                        }
                        async with httpClientSession() as session:
                            async with session.get('https://api.twitch.tv/helix/bits/leaderboard', headers=headers, params=params) as response:
                                if response.status == 200:
                                    data = await response.json()
                                    if data['data']:
                                        top_cheerer = data['data'][0]
                                        score = "{:,}".format(top_cheerer['score'])
                                        await send_chat_message(f"The current top cheerleader is {top_cheerer['user_name']} with {score} bits!")
                                        # Record usage
                                        add_usage('cheerleader', bucket_key, cooldown_bucket)
                                    else:
                                        await send_chat_message("There is no one currently in the leaderboard for bits; cheer to take this spot.")
                                elif response.status == 401:
                                    await send_chat_message("Sorry, something went wrong while reaching the Twitch API.")
                                else:
                                    await send_chat_message("Sorry, I couldn't fetch the leaderboard.")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to use the cheerleader command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in cheerleader_command: {e}")
            await send_chat_message(f"An error occurred while executing the command. {e}")

    @commands.command(name='mybits')
    async def mybits_command(self, ctx):
        global bot_owner, CLIENT_ID, CHANNEL_AUTH
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("mybits",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        # Check cooldown
                        bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                        if not await check_cooldown('mybits', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                            return
                        user_id = ctx.author.id
                        await cursor.execute("SELECT bits FROM bits_data WHERE user_id = %s", (user_id,))
                        db_bits = await cursor.fetchone()
                        if db_bits:
                            db_bits = db_bits["bits"]
                        else:
                            db_bits = 0
                        headers = {
                            'Client-ID': CLIENT_ID,
                            'Authorization': f'Bearer {CHANNEL_AUTH}'
                        }
                        params = {
                            'user_id': user_id
                        }
                        async with httpClientSession() as session:
                            async with session.get('https://api.twitch.tv/helix/bits/leaderboard', headers=headers, params=params) as response:
                                if response.status == 200:
                                    data = await response.json()
                                    api_logger.info(f"Twitch Leaderboard: {data}")
                                    # Filter out only the data for the current user_id
                                    user_data = next((user for user in data['data'] if user['user_id'] == str(user_id)), None)
                                    if user_data:
                                        api_bits = user_data['score']
                                        # Compare API bits with the database bits and update if necessary
                                        if api_bits > db_bits:
                                            # Update the database with the higher bits from the API
                                            await cursor.execute('UPDATE bits_data SET bits = %s WHERE user_id = %s', (api_bits, user_id))
                                            await connection.commit()
                                            bits = "{:,}".format(api_bits)
                                            await send_chat_message(f"You have given {bits} bits in total.")
                                            # Record usage
                                            add_usage('mybits', bucket_key, cooldown_bucket)
                                        elif api_bits < db_bits:
                                            # Inform the user that the local database has a higher value
                                            bits = "{:,}".format(db_bits)
                                            await send_chat_message(f"Our records show you have given {bits} bits in total.")
                                            # Record usage
                                            add_usage('mybits', bucket_key, cooldown_bucket)
                                        else:
                                            bits = "{:,}".format(api_bits)
                                            await send_chat_message(f"You have given {bits} bits in total.")
                                            # Record usage
                                            add_usage('mybits', bucket_key, cooldown_bucket)
                                    else:
                                        await send_chat_message("You haven't given any bits yet.")
                                        # Record usage
                                        add_usage('mybits', bucket_key, cooldown_bucket)
                                elif response.status == 401:
                                    await send_chat_message("Sorry, something went wrong while reaching the Twitch API.")
                                else:
                                    await send_chat_message("Sorry, I couldn't fetch your bits information.")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to use the mybits command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in mybits_command: {e}")
            await send_chat_message(f"An error occurred while executing the command. {e}")

    @commands.command(name='lurk')
    async def lurk_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("lurk",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        chat_logger.info(f"{ctx.author.name} tried to use the lurk command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('lurk', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    user_id = str(ctx.author.id)
                    now = time_right_now()
                    if ctx.author.name.lower() == CHANNEL_NAME.lower():
                        await send_chat_message(f"You cannot lurk in your own channel, Streamer.")
                        chat_logger.info(f"{ctx.author.name} tried to lurk in their own channel.")
                        return
                    # Check if the user is already in the lurk table
                    await cursor.execute("SELECT options FROM command_options WHERE command=%s", ("lurk",))
                    command_options = await cursor.fetchone()
                    # Decode JSON options and check if timer is enabled
                    timer_enabled = False
                    if command_options and command_options.get("options"):
                        try:
                            options_json = json.loads(command_options.get("options"))
                            timer_enabled = options_json.get("timer", False)
                        except (json.JSONDecodeError, TypeError) as e:
                            chat_logger.error(f"Error parsing command options JSON: {e}")
                            timer_enabled = False
                    if timer_enabled:
                        await cursor.execute('SELECT start_time FROM lurk_times WHERE user_id = %s', (user_id,))
                        lurk_result = await cursor.fetchone()
                        if lurk_result:
                            previous_start_time = datetime.strptime(lurk_result["start_time"], "%Y-%m-%d %H:%M:%S")
                            lurk_duration = now - previous_start_time
                            time_string = format_lurk_time(lurk_duration)
                            lurk_message = (f"Continuing to lurk, {ctx.author.name}? No problem, you've been lurking for {time_string}. I've reset your lurk time.")
                            chat_logger.info(f"{ctx.author.name} refreshed their lurk time after {time_string}.")
                        else:
                            lurk_message = (f"Thanks for lurking, {ctx.author.name}! See you soon.")
                            chat_logger.info(f"{ctx.author.name} is now lurking.")
                    else:
                        lurk_message = (f"Thanks for lurking, {ctx.author.name}! See you soon.")
                    # Send message to chat
                    await send_chat_message(lurk_message)
                    # Update the start time in the database
                    formatted_datetime = now.strftime("%Y-%m-%d %H:%M:%S")
                    await cursor.execute(
                        'INSERT INTO lurk_times (user_id, start_time) VALUES (%s, %s) ON DUPLICATE KEY UPDATE start_time = %s', 
                        (user_id, formatted_datetime, formatted_datetime)
                    )
                    await connection.commit()
                    # Record usage
                    add_usage('lurk', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"Error in lurk_command: {e}")
            await send_chat_message(f"Thanks for lurking! See you soon.")

    @commands.command(name='lurking')
    async def lurking_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("lurking",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        chat_logger.info(f"{ctx.author.name} tried to use the lurking command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('lurking', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    user_id = ctx.author.id
                    if ctx.author.name.lower() == CHANNEL_NAME.lower():
                        await send_chat_message(f"Streamer, you're always present!")
                        chat_logger.info(f"{ctx.author.name} tried to check lurk time in their own channel.")
                        return
                    await cursor.execute('SELECT start_time FROM lurk_times WHERE user_id = %s', (user_id,))
                    result = await cursor.fetchone()
                    if result:
                        start_time = datetime.strptime(result["start_time"], "%Y-%m-%d %H:%M:%S")
                        elapsed_time = time_right_now() - start_time
                        time_string = format_lurk_time(elapsed_time)
                        # Send the lurk time message
                        await send_chat_message(f"{ctx.author.name}, you've been lurking for {time_string} so far.")
                        chat_logger.info(f"{ctx.author.name} checked their lurk time: {time_string}.")
                        # Record usage
                        add_usage('lurking', bucket_key, cooldown_bucket)
                    else:
                        await send_chat_message(f"{ctx.author.name}, you're not currently lurking.")
                        chat_logger.info(f"{ctx.author.name} tried to check lurk time but is not lurking.")
                        # Record usage
                        add_usage('lurking', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"Error in lurking_command: {e}")
            await send_chat_message(f"Oops, something went wrong while trying to check your lurk time.")

    @commands.command(name='lurklead', aliases=['lurkleader'])
    async def lurklead_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("lurklead",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        chat_logger.info(f"{ctx.author.name} tried to use the lurklead command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('lurklead', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    try:
                        await cursor.execute('SELECT user_id, start_time FROM lurk_times')
                        lurkers = await cursor.fetchall()
                        longest_lurk = None
                        longest_lurk_user_id = None
                        now = time_right_now()
                        for lurker in lurkers:
                            user_id = lurker['user_id']
                            start_time_str = lurker['start_time']
                            start_time = datetime.strptime(start_time_str, "%Y-%m-%d %H:%M:%S")
                            lurk_duration = now - start_time
                            if longest_lurk is None or lurk_duration.total_seconds() > longest_lurk.total_seconds():
                                longest_lurk = lurk_duration
                                longest_lurk_user_id = user_id
                        if longest_lurk_user_id:
                            display_name = await get_display_name(longest_lurk_user_id)
                            if display_name:
                                time_string = format_lurk_time(longest_lurk)
                                await send_chat_message(f"{display_name} is currently lurking the most with {time_string} on the clock.")
                                chat_logger.info(f"Lurklead command run. User {display_name} has the longest lurk time of {time_string}.")
                                # Record usage
                                add_usage('lurklead', bucket_key, cooldown_bucket)
                            else:
                                await send_chat_message("There was an issue retrieving the display name of the lurk leader.")
                        else:
                            await send_chat_message("No one is currently lurking.")
                            chat_logger.info("Lurklead command run but no lurkers found.")
                            # Record usage
                            add_usage('lurklead', bucket_key, cooldown_bucket)
                    except Exception as e:
                        chat_logger.error(f"Error in lurklead_command: {e}")
                        await send_chat_message("Oops, something went wrong while trying to find the lurk leader.")
        except Exception as e:
            chat_logger.error(f"Error in lurklead_command: {e}")
            await send_chat_message("Oops, something went wrong while trying to check the command status.")

    @commands.command(name='unlurk', aliases=('back',))
    async def unlurk_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("unlurk",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('unlurk', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    user_id = ctx.author.id
                    if ctx.author.name.lower() == CHANNEL_NAME.lower():
                        await send_chat_message(f"Streamer, you've been here all along!")
                        chat_logger.info(f"{ctx.author.name} tried to unlurk in their own channel.")
                        return
                    await cursor.execute("SELECT options FROM command_options WHERE command=%s", ("unlurk",))
                    command_options = await cursor.fetchone()
                    timer_enabled = False
                    if command_options and command_options.get("options"):
                        try:
                            options_json = json.loads(command_options.get("options"))
                            timer_enabled = options_json.get("timer", False)
                        except (json.JSONDecodeError, TypeError) as e:
                            chat_logger.error(f"Error parsing command options JSON for unlurk: {e}")
                            timer_enabled = False
                    await cursor.execute('SELECT start_time FROM lurk_times WHERE user_id = %s', (user_id,))
                    result = await cursor.fetchone()
                    if result:
                        if timer_enabled:
                            time_now = time_right_now()
                            # Convert start_time from string to datetime
                            start_time = datetime.strptime(result["start_time"], "%Y-%m-%d %H:%M:%S")
                            elapsed_time = time_now - start_time
                            time_string = format_lurk_time(elapsed_time)
                            # Log the unlurk command execution and send a response
                            chat_logger.info(f"{ctx.author.name} is no longer lurking. Time lurking: {time_string}")
                            await send_chat_message(f"{ctx.author.name} has returned from the shadows after {time_string}, welcome back!")
                            # Record usage
                            add_usage('unlurk', bucket_key, cooldown_bucket)
                        else:
                            chat_logger.info(f"{ctx.author.name} is no longer lurking.")
                            await send_chat_message(f"{ctx.author.name} has returned from lurking, welcome back!")
                            # Record usage
                            add_usage('unlurk', bucket_key, cooldown_bucket)
                        # Remove the user's start time from the database
                        await cursor.execute('DELETE FROM lurk_times WHERE user_id = %s', (user_id,))
                        await connection.commit()
                    else:
                        await send_chat_message(f"{ctx.author.name} has returned from lurking, welcome back!")
                        # Record usage
                        add_usage('unlurk', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"Error in unlurk_command: {e}... Time now: {time_right_now()}... User Time {start_time if 'start_time' in locals() else 'N/A'}")
            await send_chat_message("Oops, something went wrong with the unlurk command.")

    @commands.command(name='userslurking')
    async def userslurking_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("userslurking",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        chat_logger.info(f"{ctx.author.name} tried to use the userslurking command but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('userslurking', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                await cursor.execute('SELECT COUNT(*) as count FROM lurk_times')
                result = await cursor.fetchone()
                count = result.get("count", 0)
                if count == 0:
                    await send_chat_message("No one is currently lurking.")
                    # Record usage
                    add_usage('userslurking', bucket_key, cooldown_bucket)
                else:
                    await send_chat_message(f"There are currently {count} user{'s' if count != 1 else ''} lurking.")
                    # Record usage
                    add_usage('userslurking', bucket_key, cooldown_bucket)
                chat_logger.info(f"{ctx.author.name} checked the number of lurkers: {count}.")
        except Exception as e:
            chat_logger.error(f"Error in userslurking_command: {e}")
            await send_chat_message("Oops, something went wrong while trying to check the number of lurkers.")

    @commands.command(name='clip')
    async def clip_command(self, ctx):
        global stream_online, bot_owner, CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("clip",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('clip', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    if not stream_online:
                        await send_chat_message("Sorry, I can only create clips while the stream is online.")
                        return
                    headers = {
                        "Client-ID": CLIENT_ID,
                        "Authorization": f"Bearer {CHANNEL_AUTH}"
                    }
                    params = {
                        "broadcaster_id": CHANNEL_ID
                    }
                    async with httpClientSession() as session:
                        async with session.post('https://api.twitch.tv/helix/clips', headers=headers, params=params) as clip_response:
                            if clip_response.status == 202:
                                clip_data = await clip_response.json()
                                clip_id = clip_data['data'][0]['id']
                                clip_url = f"http://clips.twitch.tv/{clip_id}"
                                await send_chat_message(f"{ctx.author.name} created a clip: {clip_url}")
                                marker_description = f"Clip creation by {ctx.author.name}"
                                if await make_stream_marker(marker_description):
                                    twitch_logger.info(f"A stream marker was created for the clip: {marker_description}.")
                                else:
                                    twitch_logger.info("Failed to create a stream marker for the clip.")
                                # Record usage
                                add_usage('clip', bucket_key, cooldown_bucket)
                            else:
                                marker_description = f"Failed to create clip."
                                if await make_stream_marker(marker_description):
                                    twitch_logger.info(f"A stream marker was created for the clip: {marker_description}.")
                                else:
                                    twitch_logger.info("Failed to create a stream marker for the clip.")
                                await send_chat_message(marker_description)
                                twitch_logger.error(f"Clip Error Code: {clip_response.status}")
        except Exception as e:
            twitch_logger.error(f"Error in clip_command: {e}")
            await send_chat_message("An error occurred while executing the clip command.")

    @commands.command(name='marker')
    async def marker_command(self, ctx, *, description: str):
        global stream_online, bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("marker",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('marker', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    if not stream_online:
                        await send_chat_message("Sorry, I can only create stream markers while the stream is online.")
                        return
                    marker_description = description if description else f"Marker made by {ctx.author.name}"
                    if await make_stream_marker(marker_description):
                        await send_chat_message(f"{ctx.author.name} created a stream marker.")
                        twitch_logger.info(f"A stream marker was created: {marker_description}.")
                    else:
                        await send_chat_message("Failed to create a stream marker.")
                        twitch_logger.error("Failed to create a stream marker.")
                    # Record usage
                    add_usage('marker', bucket_key, cooldown_bucket)
        except Exception as e:
            twitch_logger.error(f"Error in marker_command: {e}")
            await send_chat_message("An error occurred while executing the marker command.")

    @commands.command(name='subscription', aliases=['mysub'])
    async def subscription_command(self, ctx):
        global bot_owner, CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("subscription",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('subscription', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    user_id = ctx.author.id
                    headers = {
                        "Client-ID": CLIENT_ID,
                        "Authorization": f"Bearer {CHANNEL_AUTH}"
                    }
                    params = {
                        "broadcaster_id": CHANNEL_ID,
                        "user_id": user_id
                    }
                    tier_mapping = {
                        "1000": "Tier 1",
                        "2000": "Tier 2",
                        "3000": "Tier 3"
                    }
                    async with httpClientSession() as session:
                        async with session.get('https://api.twitch.tv/helix/subscriptions', headers=headers, params=params) as subscription_response:
                            if subscription_response.status == 200:
                                subscription_data = await subscription_response.json()
                                subscriptions = subscription_data.get('data', [])
                                if subscriptions:
                                    for subscription in subscriptions:
                                        user_name = subscription['user_name']
                                        tier = subscription['tier']
                                        is_gift = subscription['is_gift']
                                        gifter_name = subscription.get('gifter_name') if is_gift else None
                                        tier_name = tier_mapping.get(tier, tier)
                                        if is_gift:
                                            await send_chat_message(f"{user_name}, your gift subscription from {gifter_name} is {tier_name}.")
                                        else:
                                            await send_chat_message(f"{user_name}, you are currently subscribed at {tier_name}.")
                                else:
                                    await send_chat_message(f"You are currently not subscribed to {CHANNEL_NAME}, you can subscribe here: https://subs.twitch.tv/{CHANNEL_NAME}")
                            else:
                                await send_chat_message("Failed to retrieve subscription information. Please try again later.")
                                twitch_logger.error(f"Failed to retrieve subscription information. Status code: {subscription_response.status}")
                    # Record usage
                    add_usage('subscription', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the subscription command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='uptime')
    async def uptime_command(self, ctx):
        global stream_online, bot_owner, CLIENT_ID, CHANNEL_AUTH, CHANNEL_NAME
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the uptime command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("uptime",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('uptime', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                if not stream_online:
                    await send_chat_message(f"{CHANNEL_NAME} is currently offline.")
                    return
                if await command_permissions(permissions, ctx.author):
                    headers = {
                        'Client-ID': CLIENT_ID,
                        'Authorization': f'Bearer {CHANNEL_AUTH}'
                    }
                    params = {
                        'user_login': CHANNEL_NAME,
                        'type': 'live'
                    }
                    try:
                        async with httpClientSession() as session:
                            async with session.get('https://api.twitch.tv/helix/streams', headers=headers, params=params) as response:
                                if response.status == 200:
                                    data = await response.json()
                                    if data['data']:  # If stream is live
                                        started_at_str = data['data'][0]['started_at']
                                        started_at = datetime.strptime(started_at_str.replace('Z', '+00:00'), "%Y-%m-%dT%H:%M:%S%z")
                                        uptime = time_right_now(timezone.utc) - started_at
                                        hours, remainder = divmod(uptime.seconds, 3600)
                                        minutes, seconds = divmod(remainder, 60)
                                        await send_chat_message(f"The stream has been live for {hours} hours, {minutes} minutes, and {seconds} seconds.")
                                        chat_logger.info(f"{CHANNEL_NAME} has been online for {uptime}.")
                                        # Record usage
                                        add_usage('uptime', bucket_key, cooldown_bucket)
                                    else:
                                        await send_chat_message(f"{CHANNEL_NAME} is currently offline.")
                                        api_logger.info(f"{CHANNEL_NAME} is currently offline.")
                                else:
                                    await send_chat_message(f"Failed to retrieve stream data. Status: {response.status}")
                                    chat_logger.error(f"Failed to retrieve stream data. Status: {response.status}")
                    except Exception as e:
                        chat_logger.error(f"Error retrieving stream data: {e}")
                        await send_chat_message("Oops, something went wrong while trying to check uptime.")
                else:
                    await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the uptime command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='typo')
    async def typo_command(self, ctx, mentioned_username: str = None):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the typo command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("typo",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('typo', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                chat_logger.info("Typo Command ran.")
                # Determine the target user: mentioned user or the command caller
                target_user = mentioned_username.lower().lstrip('@') if mentioned_username else ctx.author.name.lower()
                # Check if the target is the broadcaster
                if target_user == CHANNEL_NAME.lower():
                    if ctx.author.name.lower() == CHANNEL_NAME.lower():
                        await send_chat_message("Dear Streamer, you can never have a typo in your own channel.")
                    else:
                        await send_chat_message("The streamer cannot have a typo count.")
                    return
                # Increment typo count in the database
                await cursor.execute('INSERT INTO user_typos (username, typo_count) VALUES (%s, 1) ON DUPLICATE KEY UPDATE typo_count = typo_count + 1', (target_user,))
                await connection.commit()
                # Retrieve the updated count
                await cursor.execute('SELECT typo_count FROM user_typos WHERE username = %s', (target_user,))
                result = await cursor.fetchone()
                typo_count = result.get("typo_count") if result else 0
                # Send the message
                chat_logger.info(f"{target_user} has made a new typo in chat, their count is now at {typo_count}.")
                await send_chat_message(f"Congratulations {target_user}, you've made a typo! You've made a typo in chat {typo_count} times.")
                # Record usage
                add_usage('typo', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"Error in typo_command: {e}", exc_info=True)
            await send_chat_message(f"An error occurred while trying to add to your typo count.")

    @commands.command(name='typos', aliases=('typocount',))
    async def typos_command(self, ctx, mentioned_username: str = None):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the typos command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("typos",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    chat_logger.info(f"{ctx.author.name} tried to use the typos command but lacked permissions.")
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('typos', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                chat_logger.info("Typos Command ran.")
                if ctx.author.name.lower() == CHANNEL_NAME.lower():
                    await send_chat_message(f"Dear Streamer, you can never have a typo in your own channel.")
                    return
                mentioned_username_lower = mentioned_username.lower() if mentioned_username else ctx.author.name.lower()
                target_user = mentioned_username_lower.lstrip('@')
                await cursor.execute('SELECT typo_count FROM user_typos WHERE username = %s', (target_user,))
                result = await cursor.fetchone()
                typo_count = result.get("typo_count") if result else 0
                chat_logger.info(f"{target_user} has made {typo_count} typos in chat.")
                await send_chat_message(f"{target_user} has made {typo_count} typos in chat.")
                # Record usage
                add_usage('typos', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"Error in typos_command: {e}")
            await send_chat_message(f"An error occurred while trying to check typos.")

    @commands.command(name='edittypos', aliases=('edittypo',))
    async def edittypo_command(self, ctx, mentioned_username: str = None, new_count: int = None):
        global bot_owner
        async with await mysql_handler.get_connection() as connection:
            try:
                async with connection.cursor(DictCursor) as cursor:
                    await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("edittypos",))
                    result = await cursor.fetchone()
                    if result:
                        status = result.get("status")
                        permissions = result.get("permission")
                        cooldown_rate = result.get("cooldown_rate")
                        cooldown_time = result.get("cooldown_time")
                        cooldown_bucket = result.get("cooldown_bucket")
                        if status == 'Disabled' and ctx.author.name != bot_owner:
                            return
                        if not await command_permissions(permissions, ctx.author):
                            await send_chat_message(f"You do not have the required permissions to use this command.")
                            return
                        # Check cooldown
                        bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                        if not await check_cooldown('edittypos', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                            return
                        chat_logger.info("Edit Typos Command ran.")
                        try:
                            # Determine the target user: mentioned user or the command caller
                            mentioned_username_lower = mentioned_username.lower() if mentioned_username else ctx.author.name.lower()
                            target_user = mentioned_username_lower.lstrip('@')
                            chat_logger.info(f"Edit Typos Command ran with params: {target_user}, {new_count}")
                            # Check if mentioned_username is not provided
                            if mentioned_username is None:
                                chat_logger.error("There was no mentioned username for the command to run.")
                                await send_chat_message("Usage: !edittypos @username [amount]")
                                return
                            # Check if new_count is not provided
                            if new_count is None:
                                chat_logger.error("There was no count added to the command to edit.")
                                await send_chat_message(f"Usage: !edittypos @{target_user} [amount]")
                                return
                            # Check if new_count is non-negative
                            if new_count < 0:
                                chat_logger.error(f"Typo count for {target_user} tried to be set to {new_count}.")
                                await send_chat_message(f"Typo count cannot be negative.")
                                return
                            # Check if the user exists in the database
                            await cursor.execute('SELECT typo_count FROM user_typos WHERE username = %s', (target_user,))
                            result = await cursor.fetchone()
                            if result is not None:
                                # Update typo count in the database
                                await cursor.execute('UPDATE user_typos SET typo_count = %s WHERE username = %s', (new_count, target_user))
                                await connection.commit()
                                chat_logger.info(f"Typo count for {target_user} has been updated to {new_count}.")
                                await send_chat_message(f"Typo count for {target_user} has been updated to {new_count}.")
                            else:
                                # If user does not exist, add the user with the given typo count
                                await cursor.execute('INSERT INTO user_typos (username, typo_count) VALUES (%s, %s)', (target_user, new_count))
                                await connection.commit()
                                chat_logger.info(f"Typo count for {target_user} has been set to {new_count}.")
                                await send_chat_message(f"Typo count for {target_user} has been set to {new_count}.")
                        except Exception as e:
                            chat_logger.error(f"Error in edit_typo_command: {e}")
                            await send_chat_message(f"An error occurred while trying to edit typos. {e}")
                # Record usage
                add_usage('edittypos', bucket_key, cooldown_bucket)
            except Exception as e:
                chat_logger.error(f"An error occurred during the execution of the edittypos command: {e}")
                await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='removetypos', aliases=('removetypo',))
    async def removetypos_command(self, ctx, mentioned_username: str = None, decrease_amount: int = 1):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("removetypos",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message(f"You do not have the required permissions to use this command.")
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('removetypos', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    mentioned_username_lower = mentioned_username.lower() if mentioned_username else ctx.author.name.lower()
                    target_user = mentioned_username_lower.lstrip('@')
                    chat_logger.info(f"Remove Typos Command ran with params: {target_user}, decrease_amount: {decrease_amount}")
                    if decrease_amount < 0:
                        chat_logger.error(f"Invalid decrease amount {decrease_amount} for typo count of {target_user}.")
                        await send_chat_message(f"Remove amount cannot be negative.")
                        return
                    await cursor.execute('SELECT typo_count FROM user_typos WHERE username = %s', (target_user,))
                    result = await cursor.fetchone()
                    if result:
                        current_count = result.get("typo_count")
                        new_count = max(0, current_count - decrease_amount)
                        await cursor.execute('UPDATE user_typos SET typo_count = %s WHERE username = %s', (new_count, target_user))
                        await connection.commit()
                        await send_chat_message(f"Typo count for {target_user} decreased by {decrease_amount}. New count: {new_count}.")
                    else:
                        await send_chat_message(f"No typo record found for {target_user}.")
            # Record usage
            add_usage('removetypos', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"Error in remove_typos_command: {e}")
            await send_chat_message(f"An error occurred while trying to remove typos.")

    @commands.command(name='steam')
    async def steam_command(self, ctx):
        global current_game, bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("steam",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('steam', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
            # File path
            file_path = '/var/www/api/steamapplist.json'
            # Check if the file exists and if it's less than 1 hour old
            try:
                file_mtime = os.path.getmtime(file_path)
                if (time.time() - file_mtime) < 3600:
                    # Load from file if it's still fresh
                    with open(file_path, 'r') as file:
                        steam_app_list = json.load(file)
                else:
                    raise FileNotFoundError  # Force fetching fresh data
            except (FileNotFoundError, OSError):
                async with httpClientSession() as session:
                    response = await session.get("http://api.steampowered.com/ISteamApps/GetAppList/v2")
                    if response.status == 200:
                        data = await response.json()
                        steam_app_list = {app['name'].lower(): app['appid'] for app in data['applist']['apps']}
                        # Save to file
                        with open(file_path, 'w') as file:
                            json.dump(data, file)
                    else:
                        await send_chat_message("Failed to fetch Steam games list.")
                        return
            game_name_lower = current_game.lower()
            if game_name_lower.startswith('the '):
                game_name_without_the = game_name_lower[4:]
                if game_name_without_the in steam_app_list:
                    game_id = steam_app_list[game_name_without_the]
                    store_url = f"https://store.steampowered.com/app/{game_id}"
                    await send_chat_message(f"{current_game} is available on Steam, you can get it here: {store_url}")
                    return
            if game_name_lower in steam_app_list:
                game_id = steam_app_list[game_name_lower]
                store_url = f"https://store.steampowered.com/app/{game_id}"
                await send_chat_message(f"{current_game} is available on Steam, you can get it here: {store_url}")
            else:
                await send_chat_message("This game is not available on Steam.")
            # Record usage
            add_usage('steam', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"Error in steam_command: {e}")
            await send_chat_message("An error occurred while trying to check the Steam store.")

    @commands.command(name='deaths')
    async def deaths_command(self, ctx):
        global current_game, bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the deaths command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("deaths",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('deaths', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                if current_game is None:
                    await send_chat_message("Current game is not set. Can't see death count.")
                    return
                await cursor.execute("SELECT 1 FROM game_deaths_settings WHERE game_name = %s LIMIT 1", (current_game,))
                ignored_result = await cursor.fetchone()
                if ignored_result:
                    await send_chat_message("Deaths are not counted for this game.")
                    # Record usage
                    add_usage('deaths', bucket_key, cooldown_bucket)
                chat_logger.info("Deaths command ran.")
                await cursor.execute('SELECT death_count FROM game_deaths WHERE game_name = %s', (current_game,))
                game_death_count_result = await cursor.fetchone()
                game_death_count = game_death_count_result.get("death_count") if game_death_count_result else 0
                await cursor.execute('SELECT SUM(death_count) as total FROM game_deaths')
                total_death_count_result = await cursor.fetchone()
                total_death_count = total_death_count_result.get("total") if total_death_count_result and total_death_count_result.get("total") else 0
                await cursor.execute('SELECT death_count FROM per_stream_deaths WHERE game_name = %s', (current_game,))
                stream_death_count_result = await cursor.fetchone()
                stream_death_count = stream_death_count_result.get("death_count") if stream_death_count_result else 0
                chat_logger.info(f"{ctx.author.name} has reviewed the death count for {current_game}. Total deaths are: {total_death_count}. Stream deaths are: {stream_death_count}")
                await send_chat_message(f"We have died {game_death_count} times in {current_game}, with a total of {total_death_count} deaths in all games. This stream, we've died {stream_death_count} times.")
                if await command_permissions("mod", ctx.author):
                    chat_logger.info(f"Sending DEATHS event with game: {current_game}, death count: {stream_death_count}")
                    create_task(websocket_notice(event="DEATHS", death=stream_death_count, game=current_game))
                # Record usage
                add_usage('deaths', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"Error in deaths_command: {e}")
            await send_chat_message(f"An error occurred while executing the command. {e}")

    @commands.command(name='deathadd', aliases=['death+'])
    async def deathadd_command(self, ctx, deaths: int = 1):
        global current_game, bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("deathadd",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('deathadd', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                if current_game is None:
                    await send_chat_message("Current game is not set. Cannot add death to nothing.")
                    return
                await cursor.execute("SELECT 1 FROM game_deaths_settings WHERE game_name = %s LIMIT 1", (current_game,))
                ignored_result = await cursor.fetchone()
                if ignored_result:
                    await send_chat_message("Deaths are not counted for this game.")
                    return
                if deaths < 1:
                    deaths = 1  # Ensure at least 1 death is added
                try:
                    chat_logger.info(f"Death Add Command ran by a mod or broadcaster, adding {deaths} deaths.")
                    await cursor.execute(
                        'INSERT INTO game_deaths (game_name, death_count) VALUES (%s, %s) ON DUPLICATE KEY UPDATE death_count = death_count + %s',
                        (current_game, deaths, deaths))
                    # Update per_stream_deaths
                    await cursor.execute(
                        'INSERT INTO per_stream_deaths (game_name, death_count) VALUES (%s, %s) ON DUPLICATE KEY UPDATE death_count = death_count + %s',
                        (current_game, deaths, deaths))
                    await connection.commit()
                    await cursor.execute('SELECT death_count FROM game_deaths WHERE game_name = %s', (current_game,))
                    game_death_count_result = await cursor.fetchone()
                    game_death_count = game_death_count_result.get("death_count") if game_death_count_result else 0
                    # Calculate total death count by summing all game deaths
                    await cursor.execute('SELECT SUM(death_count) AS total FROM game_deaths')
                    total_death_count_result = await cursor.fetchone()
                    total_death_count = total_death_count_result.get("total") if total_death_count_result and total_death_count_result.get("total") else 0
                    await cursor.execute('SELECT death_count FROM per_stream_deaths WHERE game_name = %s', (current_game,))
                    stream_death_count_result = await cursor.fetchone()
                    stream_death_count = stream_death_count_result.get("death_count") if stream_death_count_result else 0
                    chat_logger.info(f"{current_game} now has {game_death_count} deaths.")
                    chat_logger.info(f"Total death count has been calculated as: {total_death_count}")
                    chat_logger.info(f"Stream death count for {current_game} is now: {stream_death_count}")
                    await send_chat_message(f"We have died {game_death_count} times in {current_game}, with a total of {total_death_count} deaths in all games. This stream, we've died {stream_death_count} times in {current_game}.")
                    create_task(websocket_notice(event="DEATHS", death=stream_death_count, game=current_game))
                except GeneratorExit:
                    raise
                except Exception as e:
                    chat_logger.error(f"Error in deathadd_command: {e}")
                    try:
                        await send_chat_message(f"An error occurred while executing the command. {e}")
                    except:
                        pass
            # Record usage
            add_usage('deathadd', bucket_key, cooldown_bucket)
        except GeneratorExit:
            chat_logger.info("Deathadd command cancelled due to shutdown")
            raise
        except Exception as e:
            chat_logger.error(f"Unexpected error in deathadd_command: {e}")
            try:
                await send_chat_message(f"An unexpected error occurred: {e}")
            except:
                pass

    @commands.command(name='deathremove', aliases=['death-'])
    async def deathremove_command(self, ctx, deaths: int = 1):
        global current_game, bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("deathremove",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('deathremove', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                if current_game is None:
                    await send_chat_message("Current game is not set. Can't remove from nothing.")
                    return
                if deaths < 1:
                    deaths = 1  # Ensure at least 1 death is removed
                try:
                    chat_logger.info(f"Death Remove Command Ran, removing {deaths} deaths")
                    await cursor.execute(
                        'UPDATE game_deaths SET death_count = CASE WHEN death_count >= %s THEN death_count - %s ELSE 0 END WHERE game_name = %s',
                        (deaths, deaths, current_game))
                    await cursor.execute(
                        'UPDATE per_stream_deaths SET death_count = CASE WHEN death_count >= %s THEN death_count - %s ELSE 0 END WHERE game_name = %s',
                        (deaths, deaths, current_game))
                    await connection.commit()
                    await cursor.execute('SELECT death_count FROM game_deaths WHERE game_name = %s', (current_game,))
                    game_death_count_result = await cursor.fetchone()
                    game_death_count = game_death_count_result.get("death_count") if game_death_count_result else 0
                    # Calculate total death count by summing all game deaths
                    await cursor.execute('SELECT SUM(death_count) AS total FROM game_deaths')
                    total_death_count_result = await cursor.fetchone()
                    total_death_count = total_death_count_result.get("total") if total_death_count_result and total_death_count_result.get("total") else 0
                    await cursor.execute('SELECT death_count FROM per_stream_deaths WHERE game_name = %s', (current_game,))
                    stream_death_count_result = await cursor.fetchone()
                    stream_death_count = stream_death_count_result.get("death_count") if stream_death_count_result else 0
                    chat_logger.info(f"{current_game} death has been removed, we now have {game_death_count} deaths.")
                    chat_logger.info(f"Total death count has been calculated as: {total_death_count}")
                    await send_chat_message(f"Death removed from {current_game}, count is now {game_death_count}. Total deaths in all games: {total_death_count}.")
                    create_task(websocket_notice(event="DEATHS", death=stream_death_count, game=current_game))
                except GeneratorExit:
                    raise
                except Exception as e:
                    chat_logger.error(f"Error in deathremove_command: {e}")
                    try:
                        await send_chat_message(f"An error occurred while executing the command. {e}")
                    except:
                        pass
            # Record usage
            add_usage('deathremove', bucket_key, cooldown_bucket)
        except GeneratorExit:
            chat_logger.info("Deathremove command cancelled due to shutdown")
            raise
        except Exception as e:
            chat_logger.error(f"Unexpected error in deathremove_command: {e}")
            try:
                await send_chat_message(f"An unexpected error occurred: {e}")
            except:
                pass

    @commands.command(name='game')
    async def game_command(self, ctx):
        global current_game, bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("game",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('game', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                if current_game is not None:
                    await send_chat_message(f"The current game we're playing is: {current_game}")
                else:
                    await send_chat_message("We're not currently streaming any specific game category.")
            # Record usage
            add_usage('game', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"Error in game_command: {e}")
            await send_chat_message("Oops, something went wrong while trying to retrieve the game information.")

    @commands.command(name='followage')
    async def followage_command(self, ctx, mentioned_username: str = None):
        global bot_owner, CLIENT_ID, CHANNEL_AUTH
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the followage command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("followage",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('followage', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                target_user = mentioned_username.lstrip('@') if mentioned_username else ctx.author.name
                headers = {
                    'Client-ID': CLIENT_ID,
                    'Authorization': f'Bearer {CHANNEL_AUTH}'
                }
                try:
                    if mentioned_username:
                        user_info = await self.fetch_users(names=[target_user])
                        if user_info:
                            user_id = user_info[0].id
                            params = {
                                'user_id': user_id,
                                'broadcaster_id': CHANNEL_ID
                            }
                        else:
                            await send_chat_message(f"The user {target_user} is not a user on Twitch.")
                            return
                    else:
                        params = {
                            'user_id': ctx.author.id,
                            'broadcaster_id': CHANNEL_ID
                        }
                    async with httpClientSession() as session:
                        async with session.get('https://api.twitch.tv/helix/channels/followers', headers=headers, params=params) as response:
                            if response.status == 200:
                                data = await response.json()
                                if data['total'] > 0:
                                    followed_at_str = data['data'][0]['followed_at']
                                    followed_at = datetime.strptime(followed_at_str.replace('Z', '+00:00'), "%Y-%m-%dT%H:%M:%S%z")
                                    followage = time_right_now(timezone.utc) - followed_at
                                    years, days = divmod(followage.days, 365)
                                    months, days = divmod(days, 30)
                                    hours, seconds = divmod(followage.seconds, 3600)
                                    minutes, seconds = divmod(seconds, 60)
                                    parts = []
                                    if years > 0:
                                        parts.append(f"{years} year{'s' if years > 1 else ''}")
                                    if months > 0:
                                        parts.append(f"{months} month{'s' if months > 1 else ''}")
                                    if days > 0:
                                        parts.append(f"{days} day{'s' if days > 1 else ''}")
                                    if hours > 0:
                                        parts.append(f"{hours} hour{'s' if hours > 1 else ''}")
                                    if minutes > 0:
                                        parts.append(f"{minutes} minute{'s' if minutes > 1 else ''}")
                                    if seconds > 0:
                                        parts.append(f"{seconds} second{'s' if seconds > 1 else ''}")
                                    followage_text = ", ".join(parts)
                                    await send_chat_message(f"{target_user} has been following for: {followage_text}.")
                                    chat_logger.info(f"{target_user} has been following for: {followage_text}.")
                                    # Record usage
                                    add_usage('followage', bucket_key, cooldown_bucket)
                                else:
                                    await send_chat_message(f"{target_user} does not follow {CHANNEL_NAME}.")
                                    chat_logger.info(f"{target_user} does not follow {CHANNEL_NAME}.")
                                    # Record usage
                                    add_usage('followage', bucket_key, cooldown_bucket)
                            else:
                                await send_chat_message(f"Failed to retrieve followage information for {target_user}.")
                                chat_logger.info(f"Failed to retrieve followage information for {target_user}.")
                except Exception as e:
                    chat_logger.error(f"Error retrieving followage: {e}")
                    await send_chat_message(f"Oops, something went wrong while trying to check followage.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the followage command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='schedule')
    async def schedule_command(self, ctx):
        global bot_owner, CLIENT_ID, CHANNEL_AUTH
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("schedule",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('schedule', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                await cursor.execute("SELECT timezone FROM profile")
                timezone_row = await cursor.fetchone()
                timezone = timezone_row["timezone"] if timezone_row else 'UTC'
                tz = pytz_timezone(timezone)
                current_time = time_right_now(tz)
                headers = {
                    'Client-ID': CLIENT_ID,
                    'Authorization': f'Bearer {CHANNEL_AUTH}'
                }
                params = {
                    'broadcaster_id': CHANNEL_ID,
                    'first': '3'
                }
                try:
                    async with httpClientSession() as session:
                        async with session.get('https://api.twitch.tv/helix/schedule', headers=headers, params=params) as response:
                            if response.status == 200:
                                data = await response.json()
                                segments = data['data']['segments']
                                vacation = data['data'].get('vacation')
                                # Check if vacation is ongoing
                                if vacation and 'start_time' in vacation and 'end_time' in vacation:
                                    vacation_start = datetime.strptime(vacation['start_time'][:-1], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=set_timezone.utc).astimezone(tz)
                                    vacation_end = datetime.strptime(vacation['end_time'][:-1], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=set_timezone.utc).astimezone(tz)
                                    if vacation_start <= current_time <= vacation_end:
                                        # Check if there is a stream within 2 days after the vacation ends
                                        for segment in segments:
                                            start_time_utc = datetime.strptime(segment['start_time'][:-1], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=set_timezone.utc)
                                            start_time = start_time_utc.astimezone(tz)
                                            if start_time >= vacation_end and (start_time - current_time).days <= 2:
                                                await send_chat_message(f"I'm on vacation until {vacation_end.strftime('%A, %d %B %Y')} ({vacation_end.strftime('%H:%M %Z')} UTC). My next stream is on {start_time.strftime('%A, %d %B %Y')} ({start_time.strftime('%H:%M %Z')} UTC).")
                                                return
                                        await send_chat_message(f"I'm on vacation until {vacation_end.strftime('%A, %d %B %Y')} ({vacation_end.strftime('%H:%M %Z')} UTC). No streams during this time!")
                                        return
                                next_stream = None
                                canceled_stream = None
                                for segment in segments:
                                    # Check if the segment is canceled
                                    if segment.get('canceled_until'):
                                        canceled_until = datetime.strptime(segment['canceled_until'][:-1], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=set_timezone.utc).astimezone(tz)
                                        start_time_utc = datetime.strptime(segment['start_time'][:-1], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=set_timezone.utc)
                                        canceled_stream = (start_time_utc.astimezone(tz), canceled_until)
                                        continue
                                    start_time_utc = datetime.strptime(segment['start_time'][:-1], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=set_timezone.utc)
                                    start_time = start_time_utc.astimezone(tz)
                                    if start_time > current_time:
                                        next_stream = segment
                                        break  # Exit the loop after finding the first upcoming stream
                                if canceled_stream:
                                    canceled_time, canceled_until = canceled_stream
                                    await send_chat_message(f"The next stream scheduled for {canceled_time.strftime('%A, %d %B %Y')} ({canceled_time.strftime('%H:%M %Z')} UTC) has been canceled.")
                                if next_stream:
                                    start_date_utc = next_stream['start_time'].split('T')[0]  # Extract date from start_time
                                    start_time_utc = datetime.strptime(next_stream['start_time'][:-1], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=set_timezone.utc)
                                    start_time = start_time_utc.astimezone(tz)
                                    time_until = start_time - current_time
                                    # Format time_until
                                    days, seconds = time_until.days, time_until.seconds
                                    hours = seconds // 3600
                                    minutes = (seconds % 3600) // 60
                                    seconds = (seconds % 60)
                                    time_str = f"{days} days, {hours} hours, {minutes} minutes, {seconds} seconds" if days else f"{hours} hours, {minutes} minutes, {seconds} seconds"
                                    await send_chat_message(f"The next stream will be on {start_date_utc} at {start_time.strftime('%H:%M %Z')} ({start_time_utc.strftime('%H:%M')} UTC), which is in {time_str}. Check out the full schedule here: https://www.twitch.tv/{CHANNEL_NAME}/schedule")
                                else:
                                    await send_chat_message(f"There are no upcoming streams in the next three days.")
                            else:
                                await send_chat_message(f"Something went wrong while trying to get the schedule from Twitch.")
                except Exception as e:
                    chat_logger.error(f"Error retrieving schedule: {e}")
                    await send_chat_message(f"Oops, something went wrong while trying to check the schedule.")
            # Record usage
            add_usage('schedule', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the schedule command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='checkupdate')
    async def checkupdate_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("checkupdate",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('checkupdate', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                API_URL = "https://api.botofthespecter.com/versions"
                async with httpClientSession() as session:
                    async with session.get(API_URL, headers={'accept': 'application/json'}) as response:
                        if response.status == 200:
                            data = await response.json()
                            version_key = f'{SYSTEM.lower()}_version'
                            remote_version = data.get(version_key, '').strip()
                            if remote_version and remote_version != f"{VERSION}":
                                remote_major, remote_minor, remote_patch = map(int, remote_version.split('.'))
                                local_major, local_minor, local_patch = map(int, VERSION.split('.'))
                                if remote_major > local_major or \
                                        (remote_major == local_major and remote_minor > local_minor) or \
                                        (remote_major == local_major and remote_minor == local_minor and remote_patch > local_patch):
                                    message = f"A new {SYSTEM.lower()} update (V{remote_version}) is available. Please head over to the website and restart the bot. You are currently running V{VERSION}."
                                else:
                                    message = f"There is no {SYSTEM.lower()} update pending. You are currently running V{VERSION}."
                                bot_logger.info(f"Bot {SYSTEM.lower()} update available. (V{remote_version})")
                                await send_chat_message(message)
                            else:
                                message = f"There is no {SYSTEM.lower()} update pending. You are currently running V{VERSION}."
                                bot_logger.info(f"{message}")
                                await send_chat_message(message)
                        else:
                            await send_chat_message("Failed to check for updates. Please try again later.")
            # Record usage
            add_usage('checkupdate', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"Error in checkupdate_command: {e}")
            await send_chat_message("Oops, something went wrong while trying to check for updates.")

    @commands.command(name='shoutout', aliases=('so',))
    async def shoutout_command(self, ctx, user_to_shoutout: str = None):
        global bot_owner, shoutout_user
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("shoutout",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the required permissions for this command
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
            # Check cooldown
            bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
            if not await check_cooldown('shoutout', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                return
            chat_logger.info(f"Shoutout command running from {ctx.author.name}")
            if not user_to_shoutout:
                chat_logger.error(f"Shoutout command missing username parameter.")
                await send_chat_message(f"Usage: !so @username")
                return
            chat_logger.info(f"Shoutout command trying to run.")
            user_to_shoutout = user_to_shoutout.lstrip('@')
            is_valid_user = await is_valid_twitch_user(user_to_shoutout)
            if not is_valid_user:
                chat_logger.error(f"User {user_to_shoutout} does not exist on Twitch.")
                await send_chat_message(f"The user @{user_to_shoutout} does not exist on Twitch.")
                return
            chat_logger.info(f"Shoutout for {user_to_shoutout} ran by {ctx.author.name}")
            user_info = await self.fetch_users(names=[user_to_shoutout])
            if not user_info:
                await send_chat_message("Failed to fetch user information.")
                return
            user_id = user_info[0].id
            shoutout_message = await get_shoutout_message(user_id, user_to_shoutout, "command")
            chat_logger.info(shoutout_message)
            await send_chat_message(shoutout_message)
            await add_shoutout(user_to_shoutout, user_id, is_automated=False)
            # Record usage
            add_usage('shoutout', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the shoutout command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='addcommand')
    async def addcommand_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("addcommand",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the required permissions for this command
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
            # Check cooldown
            bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
            if not await check_cooldown('addcommand', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                return
            # Parse the command and response from the message
            try:
                command, response = ctx.message.content.strip().split(' ', 1)[1].split(' ', 1)
            except ValueError:
                await send_chat_message(f"Invalid command format. Use: !addcommand [command] [response]")
                return
            # Check if command name conflicts with an enabled built-in command
            if command in builtin_commands or command in mod_commands or command in builtin_aliases:
                async with connection.cursor(DictCursor) as cursor:
                    await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", (command,))
                    builtin_result = await cursor.fetchone()
                    if builtin_result and builtin_result.get("status") != 'Disabled':
                        await send_chat_message(f"Cannot create custom command '!{command}' - this is an enabled built-in command. Disable the built-in command first to create a custom override.")
                        return
                    # If built-in is disabled, allow custom command creation
                    chat_logger.info(f"Creating custom command '{command}' as override for disabled built-in command")
            # Insert the command and response into the database
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute('INSERT INTO custom_commands (command, response, status) VALUES (%s, %s, %s)', (command, response, 'Enabled'))
                await connection.commit()
            chat_logger.info(f"{ctx.author.name} has added the command !{command} with the response: {response}")
            await send_chat_message(f'Custom command added: !{command}')
            # Record usage
            add_usage('addcommand', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the addcommand command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='editcommand')
    async def editcommand_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("editcommand",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the required permissions for this command
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
            # Check cooldown
            bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
            if not await check_cooldown('editcommand', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                return
            # Parse the command and new response from the message
            try:
                command, new_response = ctx.message.content.strip().split(' ', 1)[1].split(' ', 1)
            except ValueError:
                await send_chat_message(f"Invalid command format. Use: !editcommand [command] [new_response]")
                return
            # Update the command's response in the database
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute('UPDATE custom_commands SET response = %s WHERE command = %s', (new_response, command))
                await connection.commit()
            chat_logger.info(f"{ctx.author.name} has edited the command !{command} to have the new response: {new_response}")
            await send_chat_message(f'Custom command edited: !{command}')
            # Record usage
            add_usage('editcommand', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the editcommand command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='removecommand')
    async def removecommand_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("removecommand",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the required permissions for this command
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
            # Check cooldown
            bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
            if not await check_cooldown('removecommand', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                return
            # Parse the command from the message
            try:
                command = ctx.message.content.strip().split(' ')[1]
            except IndexError:
                await send_chat_message(f"Invalid command format. Use: !removecommand [command]")
                return
            # Delete the command from the database
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute('DELETE FROM custom_commands WHERE command = %s', (command,))
                await connection.commit()
            chat_logger.info(f"{ctx.author.name} has removed {command}")
            await send_chat_message(f'Custom command removed: !{command}')
            # Record usage
            add_usage('removecommand', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the removecommand command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='enablecommand')
    async def enablecommand_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("enablecommand",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the required permissions for this command
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
            # Check cooldown
            bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
            if not await check_cooldown('enablecommand', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                return
            # Parse the command from the message
            try:
                command = ctx.message.content.strip().split(' ')[1]
            except IndexError:
                await send_chat_message(f"Invalid command format. Use: !enablecommand [command]")
                return
            # First check if it's a built-in command
            await cursor.execute('SELECT command FROM builtin_commands WHERE command = %s', (command,))
            builtin_result = await cursor.fetchone()
            if builtin_result:
                # It's a built-in command, enable it
                await cursor.execute('UPDATE builtin_commands SET status = %s WHERE command = %s', ('Enabled', command))
                await connection.commit()
                chat_logger.info(f"{ctx.author.name} has enabled the built-in command: {command}")
                await send_chat_message(f'Built-in command enabled: !{command}')
            else:
                # Check if it's a custom command
                await cursor.execute('SELECT command FROM custom_commands WHERE command = %s', (command,))
                custom_result = await cursor.fetchone()
                if custom_result:
                    # It's a custom command, enable it
                    await cursor.execute('UPDATE custom_commands SET status = %s WHERE command = %s', ('Enabled', command))
                    await connection.commit()
                    chat_logger.info(f"{ctx.author.name} has enabled the custom command: {command}")
                    await send_chat_message(f'Custom command enabled: !{command}')
                else:
                    # Command doesn't exist in either table
                    await send_chat_message(f"Command !{command} not found.")
            # Record usage
            add_usage('enablecommand', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the enablecommand command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='disablecommand')
    async def disablecommand_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("disablecommand",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the required permissions for this command
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
            # Check cooldown
            bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
            if not await check_cooldown('disablecommand', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                return
            # Parse the command from the message
            try:
                command = ctx.message.content.strip().split(' ')[1]
            except IndexError:
                await send_chat_message(f"Invalid command format. Use: !disablecommand [command]")
                return
            # First check if it's a built-in command
            await cursor.execute('SELECT command FROM builtin_commands WHERE command = %s', (command,))
            builtin_result = await cursor.fetchone()
            if builtin_result:
                # It's a built-in command, disable it
                await cursor.execute('UPDATE builtin_commands SET status = %s WHERE command = %s', ('Disabled', command))
                await connection.commit()
                chat_logger.info(f"{ctx.author.name} has disabled the built-in command: {command}")
                await send_chat_message(f'Built-in command disabled: !{command}')
            else:
                # Check if it's a custom command
                await cursor.execute('SELECT command FROM custom_commands WHERE command = %s', (command,))
                custom_result = await cursor.fetchone()
                if custom_result:
                    # It's a custom command, disable it
                    await cursor.execute('UPDATE custom_commands SET status = %s WHERE command = %s', ('Disabled', command))
                    await connection.commit()
                    chat_logger.info(f"{ctx.author.name} has disabled the custom command: {command}")
                    await send_chat_message(f'Custom command disabled: !{command}')
                else:
                    # Command doesn't exist in either table
                    await send_chat_message(f"Command !{command} not found.")
            # Record usage
            add_usage('disablecommand', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the disablecommand command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='slots')
    async def slots_command(self, ctx):
        global bot_owner
        user_id = str(ctx.author.id)
        user_name = ctx.author.name
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("slots",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('slots', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                # Fetch user's points from the database
                await cursor.execute("SELECT points FROM bot_points WHERE user_id = %s", (user_id,))
                user_data = await cursor.fetchone()
                if not user_data:
                    await cursor.execute(
                        "INSERT INTO bot_points (user_id, user_name, points) VALUES (%s, %s, %s)",
                        (user_id, user_name, 0)
                    )
                    await connection.commit()
                    user_points = 0
                else:
                    user_points = user_data.get("points")
                # Define the payouts for each icon
                slot_payouts = {
                    "🍒": 10,
                    "🍋": 15,
                    "🍊": 20,
                    "🍉": 25,
                    "🍇": 30,
                    "🍓": 35,
                    "⭐": 50
                }
                slot_icons = list(slot_payouts.keys())
                # Determine if the user wins 70% of the time
                if random.random() < 0.7:  # 70% win chance
                    # Generate a winning result (all symbols the same)
                    winning_icon = random.choice(slot_icons)
                    result = [winning_icon] * 3
                    winnings = slot_payouts[winning_icon] * 3
                    user_points += winnings
                    message = f"{ctx.author.name}, {''.join(result)} You Win {winnings} points!"
                else:
                    result = [random.choice(slot_icons) for _ in range(3)]
                    loss_penalty = sum(slot_payouts[icon] for icon in result)
                    user_points = max(0, user_points - loss_penalty)
                    message = f"{ctx.author.name}, {''.join(result)} Better luck next time. You lost {loss_penalty} points."
                # Update user's points in the database
                await cursor.execute("UPDATE bot_points SET points = %s WHERE user_id = %s", (user_points, user_id))
                await connection.commit()
                await send_chat_message(message)
            # Record usage
            add_usage('slots', bucket_key, cooldown_bucket)
        except GeneratorExit:
            # Handle generator exit gracefully without attempting further async operations
            chat_logger.info("Slots command cancelled due to shutdown")
            raise
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the slots command: {e}")
            try:
                await send_chat_message("An unexpected error occurred. Please try again later.")
            except:
                pass

    @commands.command(name='kill')
    async def kill_command(self, ctx, mention: str = None):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("kill",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
            # Check cooldown
            bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
            if not await check_cooldown('kill', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                return
            async with httpClientSession() as session:
                async with session.get(f"https://api.botofthespecter.com/kill?api_key={API_TOKEN}") as response:
                    if response.status == 200:
                        data = await response.json()
                        kill_message = data.get("killcommand", {})
                        if not kill_message:
                            chat_logger.error("No 'killcommand' found in the API response.")
                            await send_chat_message("No kill messages found.")
                            return
                    else:
                        chat_logger.error(f"Failed to fetch kill messages from API. Status code: {response.status}")
                        await send_chat_message("Unable to retrieve kill messages.")
                        return
            if mention:
                mention = mention.lstrip('@')
                target = mention
                message_key = [key for key in kill_message if "other" in key]
                if message_key:
                    message = random.choice([kill_message[key] for key in message_key])
                    result = message.replace("$1", ctx.author.name).replace("$2", target)
                else:
                    result = f"{ctx.author.name} tried to kill {target}, but something went wrong."
                    chat_logger.error("No 'other' kill message found.")
                api_logger.info(f"API - BotOfTheSpecter - KillCommand - {result}")
            else:
                message_key = [key for key in kill_message if "self" in key]
                if message_key:
                    message = random.choice([kill_message[key] for key in message_key])
                    result = message.replace("$1", ctx.author.name)
                else:
                    result = f"{ctx.author.name} tried to kill themselves, but something went wrong."
                    chat_logger.error("No 'self' kill message found.")
                api_logger.info(f"API - BotOfTheSpecter - KillCommand - {result}")
            await send_chat_message(result)
            chat_logger.info(f"Kill command executed by {ctx.author.name}: {result}")
            # Record usage
            add_usage('kill', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the kill command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name="roulette")
    async def roulette_command(self, ctx):
        global bot_owner
        user_id = str(ctx.author.id)
        user_name = ctx.author.name
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the roulette command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("roulette",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('roulette', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                # Fetch user's points from the database
                await cursor.execute("SELECT points FROM bot_points WHERE user_id = %s", (user_id,))
                user_data = await cursor.fetchone()
                if not user_data:
                    await cursor.execute(
                        "INSERT INTO bot_points (user_id, user_name, points) VALUES (%s, %s, %s)",
                        (user_id, user_name, 0)
                    )
                    await connection.commit()
                    user_points = 0
                else:
                    user_points = user_data.get("points")
                outcomes = [
                    "and survives!",
                    "and gets shot!"
                ]
                result = random.choice(outcomes)
                message = f"{ctx.author.name} pulls the trigger... {result}"
                # If user gets shot, subtract 100 points for hospital bills
                if "gets shot" in result:
                    penalty = 100
                    user_points = max(0, user_points - penalty)
                    message += f" Lost {penalty} points for hospital bills. Current points: {user_points}"
                    # Update user's points in the database
                    await cursor.execute("UPDATE bot_points SET points = %s WHERE user_id = %s", (user_points, user_id))
                    await connection.commit()
                await send_chat_message(message)
                # Record usage
                add_usage('roulette', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the roulette command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name="rps")
    async def rps_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the rps command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("rps",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('rps', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                choices = ["rock", "paper", "scissors"]
                bot_choice = random.choice(choices)
                user_input = ctx.message.content.split(' ')[1].lower() if len(ctx.message.content.split(' ')) > 1 else None
                if user_input not in choices:
                    await send_chat_message(f'Please choose "Rock", "Paper" or "Scissors". Usage: !rps <choice>')
                    return
                user_choice = user_input
                if user_choice == bot_choice:
                    result = f"It's a tie! We both chose {bot_choice}."
                elif (user_choice == 'rock' and bot_choice == 'scissors') or \
                     (user_choice == 'paper' and bot_choice == 'rock') or \
                     (user_choice == 'scissors' and bot_choice == 'paper'):
                    result = f"You Win! You chose {user_choice} and I chose {bot_choice}."
                else:
                    result = f"You lose! You chose {user_choice} and I chose {bot_choice}."
                await send_chat_message(result)
                # Record usage
                add_usage('rps', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the RPS command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name="gamble")
    async def gamble_command(self, ctx):
        global bot_owner
        user_id = str(ctx.author.id)
        user_name = ctx.author.name
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the gamble command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("gamble",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('gamble', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                # Parse command arguments
                parts = ctx.message.content.split(' ')
                if len(parts) < 2:
                    await send_chat_message(f"{ctx.author.name}, please specify a game type. Try !gamble coinflip 100, !gamble blackjack 100, or !gamble roulette red 100")
                    return
                game_type = parts[1].lower()
                try:
                    bet_amount = int(parts[2]) if len(parts) > 2 else 100
                except ValueError:
                    bet_amount = 100
                if game_type == "roulette":
                    if len(parts) > 2:
                        if parts[2].lower() in ["red", "black"]:
                            choice = parts[2].lower()
                            try:
                                bet_amount = int(parts[3]) if len(parts) > 3 else 100
                            except ValueError:
                                bet_amount = 100
                        else:
                            try:
                                bet_amount = int(parts[2])
                                choice = parts[3].lower() if len(parts) > 3 else None
                            except ValueError:
                                choice = parts[2].lower() if parts[2].lower() in ["red", "black"] else None
                                bet_amount = 100
                    else:
                        choice = None
                    if not choice or choice not in ["red", "black"]:
                        await send_chat_message(f"{ctx.author.name}, please specify red or black for roulette. Usage: !gamble roulette red 100 or !gamble roulette 100 red")
                        return
                # Fetch user's points from the database
                await cursor.execute("SELECT points FROM bot_points WHERE user_id = %s", (user_id,))
                user_data = await cursor.fetchone()
                if not user_data:
                    await cursor.execute(
                        "INSERT INTO bot_points (user_id, user_name, points) VALUES (%s, %s, %s)",
                        (user_id, user_name, 0)
                    )
                    await connection.commit()
                    user_points = 0
                else:
                    user_points = user_data.get("points")
                # Check if user has enough points (broadcaster has unlimited points)
                is_broadcaster = ctx.author.is_broadcaster
                if not is_broadcaster and user_points < bet_amount:
                    await send_chat_message(f"{ctx.author.name}, you don't have enough points to gamble {bet_amount}. You have {user_points} points.")
                    return
                # Handle game types
                if game_type == "coinflip":
                    # Coin flip: 50% chance to win double, 50% to lose all
                    if random.random() < 0.5:  # Win
                        winnings = bet_amount * 2
                        user_points += winnings
                        message = f"{ctx.author.name}, you flipped heads and won {winnings} points! Total points: {user_points}"
                    else:  # Lose
                        user_points -= bet_amount
                        message = f"{ctx.author.name}, you flipped tails and lost {bet_amount} points. Total points: {user_points}"
                elif game_type == "blackjack":
                    # Blackjack
                    roll = random.randint(1, 21)
                    if roll == 21:
                        winnings = bet_amount * 2
                        user_points += winnings
                        message = f"{ctx.author.name}, you rolled 21 in blackjack and won {winnings} points! Total points: {user_points}"
                    else:
                        user_points -= bet_amount
                        message = f"{ctx.author.name}, you rolled {roll} in blackjack and lost {bet_amount} points. Total points: {user_points}"
                elif game_type == "roulette":
                    bot_choice = random.choice(["red", "black"])
                    if choice == bot_choice:
                        winnings = bet_amount * 2
                        user_points += winnings
                        message = f"{ctx.author.name}, roulette landed on {bot_choice}, you won {winnings} points! Total points: {user_points}"
                    else:
                        user_points -= bet_amount
                        message = f"{ctx.author.name}, roulette landed on {bot_choice}, you lost {bet_amount} points. Total points: {user_points}"
                else:
                    await send_chat_message(f"{ctx.author.name}, invalid game type. Try !gamble coinflip {bet_amount}, !gamble blackjack {bet_amount}, or !gamble roulette red {bet_amount}")
                    return
                # Update user's points in the database (skip for broadcaster)
                if not is_broadcaster:
                    await cursor.execute("UPDATE bot_points SET points = %s WHERE user_id = %s", (user_points, user_id))
                    await connection.commit()
                await send_chat_message(message)
                # Record usage
                add_usage('gamble', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the gamble command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name="story")
    async def story_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the story command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("story",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('story', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                words = ctx.message.content.split(' ')[1:]
                if len(words) < 5:
                    await send_chat_message(f"{ctx.author.name}, please provide 5 words. (noun, verb, adjective, adverb, action) Usage: !story <word1> <word2> <word3> <word4> <word5>")
                    return
                # Build a user-provided seed prompt and send to AI for creative generation
                seed_prompt = (
                    f"Create a short, creative story using these five words provided by the user: "
                    f"noun={words[0]}, verb={words[1]}, adjective={words[2]}, adverb={words[3]}, action={words[4]}. "
                    f"Make the story engaging, about 3-5 sentences, and keep it safe for a general audience. "
                    f"Do not include the words list in the final story output."
                )
                response = await self.handle_ai_response(seed_prompt, ctx.author.id, ctx.author.name)
                await send_chat_message(response)
                # Record usage
                add_usage('story', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the story command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name="convert")
    async def convert_command(self, ctx, *args):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the convert command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("convert",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('convert', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
                try:
                    startwitch = ["€", "$", "£", "¥", "₹", "₣", "₽", "₺", "₩", "₼", "₱", "₪", "₴", "₭", "₨", "฿", "₮", "₳", "₵", "ƒ", "៛", "﷼", "R$"]
                    if len(args) == 3 and any(args[0].startswith(symbol) for symbol in startwitch):
                        # Handle currency conversion
                        amount_str = args[0]
                        amount = float(amount_str[1:])
                        from_currency = args[1].upper()
                        to_currency = args[2].upper()
                        converted_amount = await convert_currency(amount, from_currency, to_currency)
                        formatted_converted_amount = f"{converted_amount:,.2f}"
                        await send_chat_message(f"The currency exchange for {amount_str} {from_currency} is {formatted_converted_amount} {to_currency}")
                        # Record usage
                        add_usage('convert', bucket_key, cooldown_bucket)
                    elif len(args) == 3:
                        # Handle unit conversion
                        amount_str = args[0]
                        amount = float(amount_str)
                        from_unit = args[1].lower()
                        to_unit = args[2].lower()
                        # Handle common temperature unit aliases
                        unit_aliases = {
                            'c': 'celsius',
                            'f': 'fahrenheit',
                            'k': 'kelvin',
                            'celsius': 'celsius',
                            'fahrenheit': 'fahrenheit',
                            'kelvin': 'kelvin'
                        }
                        # Convert unit aliases to proper pint units
                        if from_unit in unit_aliases:
                            from_unit = unit_aliases[from_unit]
                        if to_unit in unit_aliases:
                            to_unit = unit_aliases[to_unit]
                        quantity = amount * ureg(from_unit)
                        converted_quantity = quantity.to(to_unit)
                        formatted_converted_quantity = f"{converted_quantity.magnitude:,.2f}"
                        await send_chat_message(f"{amount_str} {args[1]} in {args[2]} is {formatted_converted_quantity} {converted_quantity.units}")
                        # Record usage
                        add_usage('convert', bucket_key, cooldown_bucket)
                    else:
                        await send_chat_message("Invalid format. Please use: !convert <amount> <unit> <to_unit> or !convert $<amount> <from_currency> <to_currency>")
                except GeneratorExit:
                    raise
                except Exception as e:
                    sanitized_error = str(e).replace(EXCHANGE_RATE_API_KEY, '[API_KEY]')
                    api_logger.error(f"An error occurred in convert command: {sanitized_error}")
                    try:
                        await send_chat_message("Failed to convert. Please ensure the format is correct: !convert <amount> <unit> <to_unit> or !convert $<amount> <from_currency> <to_currency.")
                    except:
                        pass
        except GeneratorExit:
            chat_logger.info("Convert command cancelled due to shutdown")
            raise
        except Exception as e:
            chat_logger.error(f"An unexpected error occurred during the execution of the convert command: {e}")
            try:
                await send_chat_message("An unexpected error occurred. Please try again later.")
            except:
                pass

    @commands.command(name='todo')
    async def todo_command(self, ctx: commands.Context):
        global bot_owner
        message_content = ctx.message.content.strip()
        user = ctx.author
        user_id = user.id
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch the status and permissions for the todo command
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("todo",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('todo', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
            if message_content.lower() == '!todo':
                await send_chat_message(f"{user.name}, check the todo list at https://members.botofthespecter.com/{CHANNEL_NAME}/")
                chat_logger.info(f"{user.name} viewed the todo list.")
                # Record usage
                add_usage('todo', bucket_key, cooldown_bucket)
                return
            action, *params = message_content[5:].strip().split(' ', 1)
            action = action.lower()
            bot_logger.info(f"Action: {action}, Params: {params}")
            actions = {
                'add': add_task,
                'edit': edit_task,
                'remove': remove_task,
                'complete': complete_task,
                'done': complete_task,
                'confirm': confirm_removal,
                'view': view_task,
            }
            if action in actions:
                if action in ['add', 'edit', 'remove', 'complete', 'done']:
                    if not await command_permissions("mod", user):
                        await send_chat_message(f"{user.name}, you do not have the required permissions for this action.")
                        chat_logger.error(f"{user.name} attempted to {action} without proper permissions.")
                        return
                await actions[action](ctx, params, user_id, connection)
                chat_logger.info(f"{user.name} executed the action {action} with params {params}.")
                # Record usage
                add_usage('todo', bucket_key, cooldown_bucket)
            else:
                await send_chat_message(f"{user.name}, unrecognized action. Please use Add, Edit, Remove, Complete, Confirm, or View.")
                chat_logger.error(f"{user.name} used an unrecognized action: {action}.")
        except Exception as e:
            bot_logger.error(f"An error occurred in todo_command: {e}")

    @commands.command(name="subathon")
    async def subathon_command(self, ctx, action: str = None, minutes: int = None):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("subathon",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await send_chat_message("You do not have the required permissions to use this command.")
                    return
                # Check cooldown
                bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                if not await check_cooldown('subathon', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                    return
            user = ctx.author
            # Check permissions for valid actions
            if action in ['start', 'stop', 'pause', 'resume', 'addtime']:
                if not await command_permissions("mod", user):
                    await send_chat_message(f"{user.name}, you do not have the required permissions for this action.")
                    return
            if action == "start":
                await start_subathon(ctx)
            elif action == "stop":
                await stop_subathon(ctx)
            elif action == "pause":
                await pause_subathon(ctx)
            elif action == "resume":
                await resume_subathon(ctx)
            elif action == "addtime":
                if minutes is not None:
                    await addtime_subathon(ctx, minutes)
                else:
                    await send_chat_message(f"{user.name}, please provide the number of minutes to add. Usage: !subathon addtime <minutes>")
            elif action == "status":
                await subathon_status(ctx)
            else:
                await send_chat_message(f"{user.name}, invalid action. Use !subathon start|stop|pause|resume|addtime|status")
            # Record usage
            add_usage('subathon', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the subathon command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='heartrate')
    async def heartrate_command(self, ctx):
        global bot_owner, HEARTRATE, hyperate_task
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Check if the 'heartrate' command is enabled
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("heartrate",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Verify user permissions
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('heartrate', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if heartrate code exists in database
                    await cursor.execute('SELECT heartrate_code FROM profile')
                    heartrate_code_data = await cursor.fetchone()
                    if not heartrate_code_data or not heartrate_code_data.get('heartrate_code'):
                        await send_chat_message("Heart rate monitoring is not setup.")
                        return
                    # Start the persistent websocket connection if not already running
                    if hyperate_task is None or hyperate_task.done():
                        hyperate_task = create_task(hyperate_websocket_persistent())
                        bot_logger.info("HypeRate info: Started persistent websocket connection")
                        # Wait a moment for connection to establish and get initial data
                        await send_chat_message(f"Just a moment, scanning the heart right now.")
                        await sleep(10)
                    if HEARTRATE is None:
                        await send_chat_message("The Heart Rate is not turned on right now.")
                    else:
                        await send_chat_message(f"The current Heart Rate is: {HEARTRATE}")
            # Record usage
            add_usage('heartrate', bucket_key, cooldown_bucket)
        except Exception as e:
            chat_logger.error(f"An error occurred in the heartrate command: {e}")
            await send_chat_message("An unexpected error occurred. Please try again later.")

    @commands.command(name='watchtime')
    async def watchtime_command(self, ctx):
        global bot_owner
        user_id = ctx.author.id
        username = ctx.author.name
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Check if the 'watchtime' command is enabled
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("watchtime",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('watchtime', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                # Query watch time for the user
                await cursor.execute("""
                    SELECT total_watch_time_live, total_watch_time_offline
                    FROM watch_time
                    WHERE user_id = %s
                """, (user_id,))
                watch_time = await cursor.fetchone()
                if watch_time:
                    total_live = watch_time["total_watch_time_live"]  # Total live watch time in seconds
                    total_offline = watch_time["total_watch_time_offline"]  # Total offline watch time in seconds
                    # Function to convert seconds into years, months, days, hours, minutes
                    def convert_seconds(seconds):
                        years, remainder = divmod(seconds, 31536000)
                        months, remainder = divmod(remainder, 2592000)
                        days, remainder = divmod(remainder, 86400)
                        hours, remainder = divmod(remainder, 3600)
                        minutes, _ = divmod(remainder, 60)
                        return years, months, days, hours, minutes
                    # Convert live and offline watch time
                    live_years, live_months, live_days, live_hours, live_minutes = convert_seconds(total_live)
                    offline_years, offline_months, offline_days, offline_hours, offline_minutes = convert_seconds(total_offline)
                    # Function to build time string excluding zero values
                    def format_time(years, months, days, hours, minutes):
                        time_parts = []
                        if years > 0: time_parts.append(f"{years} year{'s' if years > 1 else ''}")
                        if months > 0: time_parts.append(f"{months} month{'s' if months > 1 else ''}")
                        if days > 0: time_parts.append(f"{days} day{'s' if days > 1 else ''}")
                        if hours > 0: time_parts.append(f"{hours} hour{'s' if hours > 1 else ''}")
                        if minutes > 0: time_parts.append(f"{minutes} minute{'s' if minutes > 1 else ''}")
                        return ', '.join(time_parts) if time_parts else "0 minutes"
                    # Format both live and offline time
                    live_str = format_time(live_years, live_months, live_days, live_hours, live_minutes)
                    offline_str = format_time(offline_years, offline_months, offline_days, offline_hours, offline_minutes)
                    # Respond with the user's watch time
                    await send_chat_message(f"@{username}, you have watched for {live_str} live, and {offline_str} offline.")
                else:
                    # If no watch time data is found
                    await send_chat_message(f"@{username}, no watch time data recorded for you yet.")
            # Record usage
            add_usage('watchtime', bucket_key, cooldown_bucket)
        except Exception as e:
            bot_logger.error(f"Error fetching watch time for {username}: {e}")
            await send_chat_message(f"@{username}, an error occurred while fetching your watch time.")

    @commands.command(name='startlotto')
    async def startlotto_command(self, ctx):
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("startlotto",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('startlotto', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                done = await generate_winning_lotto_numbers()
                if done == True:
                    await send_chat_message("Lotto numbers have been generated. Good luck everyone!")
                elif done == "exists":
                    await send_chat_message("Lotto numbers have already been generated. Ready to draw the winners.")
                else:
                    await send_chat_message("There was an error generating the lotto numbers.")
            # Record usage
            add_usage('startlotto', bucket_key, cooldown_bucket)
        except Exception as e:
            bot_logger.error(f"Error in starting lotto game: {e}")
            await send_chat_message("There was an error generating the lotto numbers.")

    @commands.command(name='drawlotto')
    async def drawlotto_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("drawlotto",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('drawlotto', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                prize_pool = {
                    "Division 1 (Jackpot!)": 100000,
                    "Division 2": 50000,
                    "Division 3": 10000,
                    "Division 4": 5000,
                    "Division 5": 1000,
                    "Division 6": 500
                }
                # Retrieve all user lotto numbers and the winning lotto numbers
                await cursor.execute("SELECT username, winning_numbers, supplementary_numbers FROM stream_lotto")
                user_lotto_numbers = await cursor.fetchall()
                await cursor.execute("SELECT winning_numbers, supplementary_numbers FROM stream_lotto_winning_numbers")
                winning_lotto_numbers = await cursor.fetchone()
                if not winning_lotto_numbers:
                    done = await generate_winning_lotto_numbers()
                    if done == True:
                        await cursor.execute("SELECT winning_numbers, supplementary_numbers FROM stream_lotto_winning_numbers")
                        winning_lotto_numbers = await cursor.fetchone()
                    if not winning_lotto_numbers:
                        await send_chat_message("No winning numbers selected. The draw cannot proceed.")
                        return  # If there are no winning numbers, end the draw
                # Extract winning numbers and supplementary numbers
                winning_set = set(map(int, winning_lotto_numbers["winning_numbers"].split(', ')))
                supplementary_set = set(map(int, winning_lotto_numbers["supplementary_numbers"].split(', ')))
                if not user_lotto_numbers:
                    await send_chat_message(f"No users have played the lotto yet!")
                    return  # If no users have played, send a message and exit
                winners = 0
                for user in user_lotto_numbers:
                    user_name = user["username"]
                    user_winning_set = set(map(int, user["winning_numbers"].split(', ')))
                    user_supplementary_set = set(map(int, user["supplementary_numbers"].split(', ')))
                    # Compare user numbers to winning numbers
                    match_main = len(user_winning_set & winning_set)
                    match_supplementary = len(user_supplementary_set & supplementary_set)
                    # Determine division based on the match
                    if match_main == 6:
                        division = "Division 1 (Jackpot!)"
                    elif match_main == 5 and match_supplementary >= 1:
                        division = "Division 2"
                    elif match_main == 5:
                        division = "Division 3"
                    elif match_main == 4:
                        division = "Division 4"
                    elif match_main == 3 and match_supplementary >= 1:
                        division = "Division 5"
                    elif match_main == 3:
                        division = "Division 6"
                    else:
                        division = None
                    if division:
                        prize = prize_pool.get(division, 0)
                        await cursor.execute("SELECT points FROM bot_points WHERE user_name = %s", (user_name,))
                        user_points = await cursor.fetchone()
                        if user_points:
                            current_points = user_points["points"]
                            new_points = current_points + prize
                            await cursor.execute("UPDATE bot_points SET points = %s WHERE user_name = %s", (new_points, user_name))
                        else:
                            # If no points record exists, set to prize
                            await cursor.execute("INSERT INTO bot_points (user_name, points) VALUES (%s, %s)", (user_name, prize))
                        await connection.commit()
                        # Retrieve updated points
                        await cursor.execute("SELECT points FROM bot_points WHERE user_name = %s", (user_name,))
                        total_points_data = await cursor.fetchone()
                        total_points = total_points_data["points"] if total_points_data else prize
                        # Send message about the win
                        message = f"@{user_name} you've won {division} and received {prize} points! Total points: {total_points}"
                        await send_chat_message(message)
                        winners += 1
                    # Remove user lotto entry after the draw
                    await cursor.execute("DELETE FROM stream_lotto WHERE username = %s", (user_name,))
                    await connection.commit()
                if winners == 0 and user_lotto_numbers:
                    await send_chat_message(f"No winners this time! The winning numbers were: {winning_set} and Supplementary: {supplementary_set}")
                else:
                    await send_chat_message(f"The winning numbers were: {winning_set} and Supplementary: {supplementary_set}")
                # Clear winning numbers after the draw
                await cursor.execute("TRUNCATE TABLE stream_lotto_winning_numbers")
                await connection.commit()
            # Record usage
            add_usage('drawlotto', bucket_key, cooldown_bucket)
        except Exception as e:
            bot_logger.error(f"Error in Drawing Lotto Winners: {e}")
            await send_chat_message("Sorry, there is an error in drawing the lotto winners.")

    # Raffle commands
    @commands.command(name='startraffle')
    async def startraffle_command(self, ctx, *args):
        connection = await mysql_handler.get_connection()
        try:
            # Permission check: only mods/broadcaster or configured builtin permission
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("startraffle",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                # Parse args: !startraffle <name> <prize> <number_of_winners> [weighted]
                if len(args) < 3:
                    await send_chat_message("Usage: !startraffle <name> <prize> <number_of_winners> [weighted]")
                    return
                name = args[0]
                prize = args[1]
                try:
                    number_of_winners = int(args[2])
                    if number_of_winners <= 0:
                        raise ValueError
                except Exception:
                    await send_chat_message("Invalid number of winners. Please specify a positive number.")
                    return
                weighted = False
                if len(args) > 3 and args[3].lower() == 'weighted':
                    weighted = True
                await cursor.execute("INSERT INTO raffles (name, prize, number_of_winners, status, is_weighted) VALUES (%s, %s, %s, %s, %s)", (name, prize, number_of_winners, 'running', 1 if weighted else 0))
                await connection.commit()
                await send_chat_message(f"Raffle '{name}' started! Prize: {prize}. Winners: {number_of_winners}. Use !joinraffle to enter.")
        except Exception as e:
            bot_logger.error(f"Error starting raffle: {e}")
            await send_chat_message("There was an error starting the raffle.")

    @commands.command(name='joinraffle', aliases=['rafflejoin','raffle'])
    async def joinraffle_command(self, ctx):
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT id, name, is_weighted FROM raffles WHERE status=%s ORDER BY created_at DESC LIMIT 1", ("running",))
                raffle = await cursor.fetchone()
                if not raffle:
                    await send_chat_message("There is no active raffle right now.")
                    return
                raffle_id = raffle.get('id')
                raffle_name = raffle.get('name')
                is_weighted = raffle.get('is_weighted')
                username = ctx.author.name
                user_id = str(ctx.author.id)
                # Check existing entry
                await cursor.execute("SELECT id FROM raffle_entries WHERE raffle_id=%s AND username=%s", (raffle_id, username))
                exists = await cursor.fetchone()
                if exists:
                    await send_chat_message(f"@{username}, you are already entered in raffle '{raffle_name}'.")
                    return
                weight = 1
                if is_weighted:
                    try:
                        if await is_user_subscribed(user_id):
                            weight = 2
                    except Exception:
                        weight = 1
                await cursor.execute("INSERT INTO raffle_entries (raffle_id, user_id, username, weight) VALUES (%s, %s, %s, %s)", (raffle_id, user_id, username, weight))
                await connection.commit()
                await send_chat_message(f"@{username} has been entered into raffle '{raffle_name}'. Good luck!")
        except Exception as e:
            bot_logger.error(f"Error joining raffle: {e}")
            await send_chat_message("There was an error entering the raffle.")

    @commands.command(name='leaveraffle')
    async def leaveraffle_command(self, ctx):
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT id FROM raffles WHERE status=%s ORDER BY created_at DESC LIMIT 1", ("running",))
                raffle = await cursor.fetchone()
                if not raffle:
                    await send_chat_message("There is no active raffle to leave.")
                    return
                raffle_id = raffle.get('id')
                username = ctx.author.name
                await cursor.execute("DELETE FROM raffle_entries WHERE raffle_id=%s AND username=%s", (raffle_id, username))
                await connection.commit()
                await send_chat_message(f"@{username} has been removed from the current raffle.")
        except Exception as e:
            bot_logger.error(f"Error leaving raffle: {e}")
            await send_chat_message("There was an error removing you from the raffle.")

    @commands.command(name='stopraffle')
    async def stopraffle_command(self, ctx):
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Permission check
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("stopraffle",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                await cursor.execute("SELECT id, name FROM raffles WHERE status=%s ORDER BY created_at DESC LIMIT 1", ("running",))
                raffle = await cursor.fetchone()
                if not raffle:
                    await send_chat_message("There is no active raffle to stop.")
                    return
                raffle_id = raffle.get('id')
                raffle_name = raffle.get('name')
                await cursor.execute("UPDATE raffles SET status=%s WHERE id=%s", ('ended', raffle_id))
                await connection.commit()
                await send_chat_message(f"Raffle '{raffle_name}' has been stopped and ended without drawing winners.")
        except Exception as e:
            bot_logger.error(f"Error stopping raffle: {e}")
            await send_chat_message("There was an error stopping the raffle.")

    @commands.command(name='drawraffle')
    async def drawraffle_command(self, ctx, raffle_id: str = None):
        # moderator-only draw
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Permission check (reuse drawlotto pattern)
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("drawraffle",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await send_chat_message("You do not have the required permissions to use this command.")
                        return
                # Determine raffle to draw
                if raffle_id:
                    await cursor.execute("SELECT id, name, prize, number_of_winners, is_weighted FROM raffles WHERE id=%s", (raffle_id,))
                    raffle = await cursor.fetchone()
                else:
                    await cursor.execute("SELECT id, name, prize, number_of_winners, is_weighted FROM raffles WHERE status=%s ORDER BY created_at DESC LIMIT 1", ("running",))
                    raffle = await cursor.fetchone()
                if not raffle:
                    await send_chat_message("No raffle found to draw.")
                    return
                raffle_id = raffle.get('id')
                raffle_name = raffle.get('name')
                raffle_prize = raffle.get('prize')
                number_of_winners = raffle.get('number_of_winners', 1)
                is_weighted = raffle.get('is_weighted')
                await cursor.execute("SELECT username, user_id, weight FROM raffle_entries WHERE raffle_id=%s", (raffle_id,))
                entries = await cursor.fetchall()
                if not entries:
                    await send_chat_message(f"No entries in raffle '{raffle_name}'.")
                    return
                # Draw multiple winners
                winners = []
                available_entries = list(entries)
                for _ in range(min(number_of_winners, len(entries))):
                    total_weight = sum([e.get('weight',1) for e in available_entries])
                    pick = random.randint(1, total_weight)
                    running = 0
                    winner = None
                    winner_index = -1
                    for idx, e in enumerate(available_entries):
                        running += e.get('weight',1)
                        if running >= pick:
                            winner = e
                            winner_index = idx
                            break
                    if winner:
                        winners.append(winner)
                        # Remove winner from available entries for next draw
                        available_entries.pop(winner_index)
                if not winners:
                    await send_chat_message("There was an error selecting winners.")
                    return
                # Store winners as JSON
                winner_names = [w.get('username') for w in winners]
                winner_ids = [w.get('user_id') for w in winners]
                winner_names_json = json.dumps(winner_names)
                winner_ids_json = json.dumps(winner_ids)
                # Update raffle record
                await cursor.execute("UPDATE raffles SET winner_username=%s, winner_user_id=%s, status=%s WHERE id=%s", (winner_names_json, winner_ids_json, 'ended', raffle_id))
                await connection.commit()
                # Announce winners
                if len(winners) == 1:
                    winner_text = f"@{winner_names[0]}"
                else:
                    winner_text = ", ".join([f"@{w}" for w in winner_names])
                prize_text = f" - Prize: {raffle_prize}" if raffle_prize else ""
                await send_chat_message(f"🎉 Congratulations {winner_text}! You won the raffle '{raffle_name}'{prize_text} 🎉")
                # Notify websocket clients/overlays for each winner
                try:
                    for winner_name in winner_names:
                        create_task(websocket_notice(event="RAFFLE_WINNER", additional_data={"raffle_name": raffle_name, "winner": winner_name, "prize": raffle_prize}))
                except Exception as e:
                    websocket_logger.error(f"Failed to send RAFFLE_WINNER notify: {e}")
        except Exception as e:
            bot_logger.error(f"Error drawing raffle: {e}")
            await send_chat_message("There was an error drawing the raffle.")

    @commands.command(name='obs')
    async def obs_command(self, ctx):
        global bot_owner
        connection = await mysql_handler.get_connection()
        try:
            async with connection.cursor(DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command=%s", ("obs",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    cooldown_rate = result.get("cooldown_rate")
                    cooldown_time = result.get("cooldown_time")
                    cooldown_bucket = result.get("cooldown_bucket")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check cooldown
                    bucket_key = 'global' if cooldown_bucket == 'default' else ('mod' if cooldown_bucket == 'mods' and await command_permissions("mod", ctx.author) else str(ctx.author.id))
                    if not await check_cooldown('obs', bucket_key, cooldown_bucket, cooldown_rate, cooldown_time):
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        message_parts = ctx.message.content.split()
                        if len(message_parts) > 1:
                            subcommand = message_parts[1].lower()
                            if subcommand == "scene":
                                if len(message_parts) > 2:
                                    scene_name = " ".join(message_parts[2:])
                                    await websocket_notice(event="SEND_OBS_EVENT", additional_data={"command": "obs", "subcommand": "scene", "scene_name": scene_name})
                                    chat_logger.info(f"{ctx.author.name} triggered OBS scene change to {scene_name}")
                                else:
                                    await send_chat_message("Please specify a scene name: !obs scene <name>")
                            else:
                                await send_chat_message("Unknown subcommand.")
                        else:
                            await websocket_notice(event="SEND_OBS_EVENT", additional_data={"command": "obs_triggered"})
                            chat_logger.info(f"{ctx.author.name} triggered OBS event")
                        # Record usage
                        add_usage('obs', bucket_key, cooldown_bucket)
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to trigger OBS event but lacked permissions.")
                        await send_chat_message("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in obs_command: {e}")
            await send_chat_message("An error occurred while sending OBS event.")

# Functions for all the commands
##
# Function to format lurk time duration
def format_lurk_time(elapsed_time):
    total_seconds = int(elapsed_time.total_seconds())
    years = total_seconds // (365 * 24 * 3600)
    total_seconds %= (365 * 24 * 3600)
    months = total_seconds // (30 * 24 * 3600)
    total_seconds %= (30 * 24 * 3600)
    days = total_seconds // (24 * 3600)
    total_seconds %= (24 * 3600)
    hours = total_seconds // 3600
    total_seconds %= 3600
    minutes = total_seconds // 60
    seconds = total_seconds % 60
    periods = [("year", years), ("month", months), ("day", days), ("hour", hours), ("minute", minutes), ("second", seconds)]
    return ", ".join(f"{value} {name}{'s' if value != 1 else ''}" for name, value in periods if value)

# Function  to check if the user is a real user on Twitch
async def is_valid_twitch_user(user_name):
    global CLIENT_ID, CHANNEL_AUTH
    url = f"https://api.twitch.tv/helix/users?login={user_name}"
    headers = {
        "Client-ID": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}"
    }
    async with httpClientSession() as session:
        async with session.get(url, headers=headers) as response:
            if response.status == 200:
                data = await response.json()
                if data['data']:
                    return True  # User exists
                else:
                    return False  # User does not exist
            else:
                # If there's an error with the request or response, return False
                return False

# Function to get the diplay name of the user from their user id
async def get_display_name(user_id):
    global CLIENT_ID, CHANNEL_AUTH
    # Replace with actual method to get display name from Twitch API
    url = f"https://api.twitch.tv/helix/users?id={user_id}"
    headers = {
        "Client-ID": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}"
    }
    async with httpClientSession() as session:
        async with session.get(url, headers=headers) as response:
            if response.status == 200:
                data = await response.json()
                return data['data'][0]['display_name'] if data['data'] else None
            else:
                return None

# Function to check if the user running the task is a mod to the channel or the channel broadcaster.
async def command_permissions(setting, user):
    global bot_owner, CHANNEL_NAME, CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
    # Check if the setting allows everyone
    if setting == "everyone":
        chat_logger.info(f"Command Permission granted to {user.name}. (Everyone allowed)")
        return True
    # Check if the user is the bot owner
    if user.name == bot_owner:
        chat_logger.info(f"Command Permission checked, {user.name}. (Bot owner)")
        return True
    # Check if the user is the broadcaster (general broadcaster access)
    elif user.name == CHANNEL_NAME:
        chat_logger.info(f"Command Permission checked, {user.name} is the Broadcaster")
        return True
    # Check if the setting specifically requires broadcaster permission
    elif setting == "broadcaster":
        if user.name == CHANNEL_NAME:
            chat_logger.info(f"Command Permission checked, {user.name} is the Broadcaster (broadcaster-only command)")
            return True
        else:
            chat_logger.info(f"Command Permission denied to {user.name}. Command requires broadcaster permission.")
            return False
    # Check if the user is a moderator and the setting is "mod"
    elif setting == "mod" and user.is_mod:
        chat_logger.info(f"Command Permission checked, {user.name} is a Moderator")
        return True
    # Check if the user is a VIP and the setting is "vip"
    elif setting == "vip" and user.is_vip or user.is_mod:
        if user.is_mod:
            chat_logger.info(f"Command Permission checked, {user.name} is a Moderator")
        else:
            chat_logger.info(f"Command Permission checked, {user.name} is a VIP")
        return True
    # Check if the user is a subscriber for all-subs or t1-sub
    elif setting in ["all-subs", "t1-sub"]:
        if user.is_subscriber or user.is_mod:
            if user.is_mod:
                chat_logger.info(f"Command Permission checked, {user.name} is a Moderator")
            else:
                chat_logger.info(f"Command Permission checked, {user.name} is a Subscriber")
            return True
    # Check for Tier 2 or Tier 3 subscription using the Twitch API
    elif setting in ["t2-sub", "t3-sub"]:
        user_id = user.id
        headers = {
            "Client-ID": CLIENT_ID,
            "Authorization": f"Bearer {CHANNEL_AUTH}"
        }
        params = {
            "broadcaster_id": CHANNEL_ID,
            "user_id": user_id
        }
        async with httpClientSession() as session:
            async with session.get('https://api.twitch.tv/helix/subscriptions', headers=headers, params=params) as subscription_response:
                if subscription_response.status == 200:
                    subscription_data = await subscription_response.json()
                    subscriptions = subscription_data.get('data', [])
                    if subscriptions:
                        for subscription in subscriptions:
                            tier = subscription['tier']
                            if (setting == "t2-sub" and tier == "2000") or (setting == "t3-sub" and tier == "3000") or user.is_mod:
                                if user.is_mod:
                                    chat_logger.info(f"Command Permission checked, {user.name} is a Moderator")
                                else:
                                    chat_logger.info(f"Command Permission checked, {user.name} has the required subscription tier ({tier}).")
                                return True
    # If none of the above, the user does not have required permissions
    twitch_logger.info(f"User {user.name} does not have required permissions for the command that requires {setting} permission.")
    return False

# Function to check if a user is a mod of the channel using the Twitch API
async def is_user_mod(user_id):
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
    headers = {
        "Client-ID": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}"
    }
    params = {
        "broadcaster_id": CHANNEL_ID,
        "user_id": user_id
    }
    async with httpClientSession() as session:
        async with session.get('https://api.twitch.tv/helix/moderation/moderators', headers=headers, params=params) as response:
            if response.status == 200:
                data = await response.json()
                if data.get('data'):
                    return True  # User is a moderator
                else:
                    return False  # User is not a moderator
            else:
                twitch_logger.error(f"Failed to check mod status for user_id {user_id}. Status Code: {response.status}")
                return False

# Function to check if a user is a VIP of the channel using the Twitch API
async def is_user_vip(user_id):
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
    headers = {
        "Client-ID": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}"
    }
    params = {
        "broadcaster_id": CHANNEL_ID,
        "user_id": user_id
    }
    async with httpClientSession() as session:
        async with session.get('https://api.twitch.tv/helix/channels/vips', headers=headers, params=params) as response:
            if response.status == 200:
                data = await response.json()
                if data.get('data'):
                    return True  # User is a VIP
                else:
                    return False  # User is not a VIP
            else:
                twitch_logger.error(f"Failed to check VIP status for user_id {user_id}. Status Code: {response.status}")
                return False

# Function to check if a user is a subscriber of the channel
async def is_user_subscribed(user_id):
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
    headers = {
        "Client-ID": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}"
    }
    params = {
        "broadcaster_id": CHANNEL_ID,
        "user_id": user_id
    }
    tier_mapping = {
        "1000": "Tier 1",
        "2000": "Tier 2",
        "3000": "Tier 3"
    }
    async with httpClientSession() as session:
        async with session.get('https://api.twitch.tv/helix/subscriptions', headers=headers, params=params) as subscription_response:
            if subscription_response.status == 200:
                subscription_data = await subscription_response.json()
                subscriptions = subscription_data.get('data', [])
                if subscriptions:
                    for subscription in subscriptions:
                        tier = subscription['tier']
                        tier_name = tier_mapping.get(tier, tier)
                        return tier_name
    return None

# Function to add user to the table of known users
async def user_is_seen(username):
    connection = await mysql_handler.get_connection()
    try:
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute('INSERT INTO seen_users (username, status) VALUES (%s, %s)', (username, "True"))
            await connection.commit()
    except Exception as e:
        bot_logger.error(f"Error occurred while adding user '{username}' to seen_users table: {e}")
    finally:
        pass

# Function to fetch custom API responses
async def fetch_api_response(url, json_flag=False):
    try:
        async with httpClientSession() as session:
            async with session.get(url) as resp:
                if json_flag:
                    data = await resp.json()
                    return str(data)
                else:
                    return await resp.text()
    except Exception:
        return "Error"

def safe_math(expr: str):
    # Only allow digits, spaces, and + - * / operators
    if not re.match(r'^[0-9+\-*/\s]+$', expr):
        return "Error"
    tokens = re.findall(r'\d+|[+\-*/]', expr)
    result = int(tokens[0])
    i = 1
    while i < len(tokens) - 1:
        op = tokens[i]
        num = int(tokens[i+1])
        if op not in allowed_ops:
            return "Error"
        result = allowed_ops[op](result, num)
        i += 2
    return result

# Function to update custom counts
async def update_custom_count(command, count):
    count = int(count)
    connection = await mysql_handler.get_connection()
    try:
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute('SELECT count FROM custom_counts WHERE command = %s', (command,))
            result = await cursor.fetchone()
            if result:
                current_count = result.get("count")
                new_count = current_count + count
                await cursor.execute('UPDATE custom_counts SET count = %s WHERE command = %s', (new_count, command))
                chat_logger.info(f"Updated count for command '{command}' to {new_count}.")
            else:
                await cursor.execute('INSERT INTO custom_counts (command, count) VALUES (%s, %s)', (command, count))
                chat_logger.info(f"Inserted new command '{command}' with count {count}.")
        await connection.commit()
    except Exception as e:
        chat_logger.error(f"Error updating count for command '{command}': {e}")
        await connection.rollback()
    finally:
        pass

async def get_custom_count(command):
    connection = await mysql_handler.get_connection()
    try:
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute('SELECT count FROM custom_counts WHERE command = %s', (command,))
            result = await cursor.fetchone()
            if result:
                count = result.get("count")
                chat_logger.info(f"Retrieved count for command '{command}': {count}")
                return count
            else:
                chat_logger.info(f"No count found for command '{command}', returning 0.")
                return 0
    except Exception as e:
        chat_logger.error(f"Error retrieving count for command '{command}': {e}")
        return 0
    finally:
        pass

# Function to update user counts
async def update_user_count(command, user, count):
    count = int(count)
    connection = await mysql_handler.get_connection()
    try:
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute('SELECT count FROM user_counts WHERE command = %s AND user = %s', (command, user))
            result = await cursor.fetchone()
            if result:
                current_count = result.get("count")
                new_count = current_count + count
                await cursor.execute('UPDATE user_counts SET count = %s WHERE command = %s AND user = %s', (new_count, command, user))
                chat_logger.info(f"Updated user count for command '{command}' and user '{user}' to {new_count}.")
            else:
                await cursor.execute('INSERT INTO user_counts (command, user, count) VALUES (%s, %s, %s)', (command, user, count))
                chat_logger.info(f"Inserted new user count for command '{command}' and user '{user}' with count {count}.")
        await connection.commit()
    except Exception as e:
        chat_logger.error(f"Error updating user count for command '{command}' and user '{user}': {e}")
        await connection.rollback()
    finally:
        pass

async def get_user_count(command, user):
    connection = await mysql_handler.get_connection()
    try:
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute('SELECT count FROM user_counts WHERE command = %s AND user = %s', (command, user))
            result = await cursor.fetchone()
            if result:
                count = result.get("count")
                chat_logger.info(f"Retrieved user count for command '{command}' and user '{user}': {count}")
                return count
            else:
                chat_logger.info(f"No user count found for command '{command}' and user '{user}', returning 0.")
                return 0
    except Exception as e:
        chat_logger.error(f"Error retrieving user count for command '{command}' and user '{user}': {e}")
        return 0
    finally:
        pass

# Function to process custom command variables
async def process_custom_command_variables(command, response, user="API", arg=None, send_to_chat=False):
    connection = await mysql_handler.get_connection()
    try:
        async with connection.cursor(DictCursor) as cursor:
            # Get timezone
            await cursor.execute("SELECT timezone FROM profile")
            tz_result = await cursor.fetchone()
            if tz_result and tz_result.get("timezone"):
                timezone = tz_result.get("timezone")
                tz = pytz_timezone(timezone)
            else:
                tz = set_timezone.UTC
            # Define switches to check for
            switches = [
                '(customapi.', '(count)', '(daysuntil.',
                '(command.', '(user)', '(author)', 
                '(random.percent)', '(random.number)', '(random.percent.',
                '(random.number.', '(random.pick.', '(math.',
                '(usercount)', '(timeuntil.', '(game)'
            ]
            # Process variables in a loop until none remain
            responses_to_send = []
            while any(switch in response for switch in switches):
                # Handle (count)
                if '(count)' in response:
                    try:
                        if arg is None:
                            await update_custom_count(command, "1")
                        else:
                            await update_custom_count(command, arg)
                        get_count = await get_custom_count(command)
                        response = response.replace('(count)', str(get_count))
                    except Exception as e:
                        chat_logger.error(f"Error processing (count): {e}")
                # Handle (usercount)
                if '(usercount)' in response:
                    try:
                        if arg is None:
                            await update_user_count(command, user, "1")
                        else:
                            await update_user_count(command, user, arg)
                        get_count = await get_user_count(command, user)
                        response = response.replace('(usercount)', str(get_count))
                    except Exception as e:
                        chat_logger.error(f"Error processing (usercount): {e}")
                        response = response.replace('(usercount)', "Error")
                # Handle (daysuntil.)
                if '(daysuntil.' in response:
                    get_date = re.search(r'\(daysuntil\.(\d{4}-\d{2}-\d{2})\)', response)
                    if get_date:
                        date_str = get_date.group(1)
                        event_date = datetime.strptime(date_str, "%Y-%m-%d").date()
                        current_date = time_right_now(tz).date()
                        days_left = (event_date - current_date).days
                        if days_left < 0:
                            next_year_date = event_date.replace(year=event_date.year + 1)
                            days_left = (next_year_date - current_date).days
                        response = response.replace(f"(daysuntil.{date_str})", str(days_left))
                # Handle (timeuntil.)
                if '(timeuntil.' in response:
                    get_datetime = re.search(r'\(timeuntil\.(\d{4}-\d{2}-\d{2}(?:-\d{1,2}-\d{2})?)\)', response)
                    if get_datetime:
                        datetime_str = get_datetime.group(1)
                        if '-' in datetime_str[10:]:
                            event_datetime = datetime.strptime(datetime_str, "%Y-%m-%d-%H-%M").replace(tzinfo=tz)
                        else:
                            event_datetime = datetime.strptime(datetime_str + "-00-00", "%Y-%m-%d-%H-%M").replace(tzinfo=tz)
                        current_datetime = time_right_now(tz)
                        time_left = event_datetime - current_datetime
                        if time_left.days < 0:
                            event_datetime = event_datetime.replace(year=event_datetime.year + 1)
                            time_left = event_datetime - current_datetime
                        days_left = time_left.days
                        hours_left, remainder = divmod(time_left.seconds, 3600)
                        minutes_left, _ = divmod(remainder, 60)
                        time_left_str = f"{days_left} days, {hours_left} hours, and {minutes_left} minutes"
                        response = response.replace(f"(timeuntil.{datetime_str})", time_left_str)
                # Handle (user) and (author)
                if '(user)' in response:
                    response = response.replace('(user)', user)
                if '(author)' in response:
                    response = response.replace('(author)', user)
                # Handle (command.) - reference other commands
                if '(command.' in response:
                    command_match = re.search(r'\(command\.(\w+)\)', response)
                    if command_match:
                        sub_command = command_match.group(1)
                        await cursor.execute('SELECT response FROM custom_commands WHERE command = %s', (sub_command,))
                        sub_response = await cursor.fetchone()
                        if sub_response:
                            response = response.replace(f"(command.{sub_command})", "")
                            responses_to_send.append(sub_response["response"])
                        else:
                            chat_logger.warning(f"Command {sub_command} referenced but not found")
                            response = response.replace(f"(command.{sub_command})", "[Command Not Found]")
                # Handle random replacements
                if '(random.percent' in response or '(random.number' in response or '(random.pick.' in response:
                    pattern = r'\((random\.(percent|number|pick))(?:\.(.+?))?\)'
                    matches = re.finditer(pattern, response)
                    for match in matches:
                        category = match.group(1)
                        details = match.group(3)
                        replacement = ''
                        if 'percent' in category or 'number' in category:
                            lower_bound, upper_bound = 0, 100
                            if details:
                                range_match = re.match(r'(\d+)-(\d+)', details)
                                if range_match:
                                    lower_bound, upper_bound = int(range_match.group(1)), int(range_match.group(2))
                            random_value = random.randint(lower_bound, upper_bound)
                            replacement = f'{random_value}%' if 'percent' in category else str(random_value)
                        elif 'pick' in category:
                            items = details.split('.') if details else []
                            replacement = random.choice(items) if items else ''
                        response = response.replace(match.group(0), replacement)
                # Handle (math.x+y)
                if '(math.' in response:
                    math_match = re.search(r'\(math\.(.+?)\)', response)
                    if math_match:
                        math_expression = math_match.group(1)
                        try:
                            math_result = safe_math(math_expression)
                            response = response.replace(f'(math.{math_expression})', str(math_result))
                        except Exception as e:
                            chat_logger.error(f"Math expression error: {e}")
                            response = response.replace(f'(math.{math_expression})', "Error")
                # Handle (customapi.)
                if '(customapi.' in response:
                    pattern = r'\(customapi\.(\S+?)\)'
                    matches = re.finditer(pattern, response)
                    for match in matches:
                        full_placeholder = match.group(0)
                        url = match.group(1)
                        json_flag = False
                        if url.startswith('json.'):
                            json_flag = True
                            url = url[5:]
                        api_response = await fetch_api_response(url, json_flag=json_flag)
                        response = response.replace(full_placeholder, api_response)
                # Handle (game)
                if '(game)' in response:
                    try:
                        game_name = await get_current_game()
                        response = response.replace('(game)', game_name)
                    except Exception as e:
                        chat_logger.error(f"Error getting current game: {e}")
                        response = response.replace('(game)', "Error")
            # Send the main response to chat if requested
            if send_to_chat:
                await send_chat_message(response)
            # Send any additional responses from (command.) references
            for resp in responses_to_send:
                await send_chat_message(resp)
    except Exception as e:
        chat_logger.error(f"Error processing custom command variables: {e}")

# Functions for weather
async def get_streamer_weather():
    connection = await mysql_handler.get_connection()
    try:
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute("SELECT weather_location FROM profile")
            info = await cursor.fetchone()
            if info:
                location = info["weather_location"]
                chat_logger.info(f"Got {location} weather info.")
                return location
            else:
                return None
    finally:
        pass

# Function to udpate the stream title
async def trigger_twitch_title_update(new_title):
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
    # Twitch API
    url = "https://api.twitch.tv/helix/channels"
    headers = {
        "Authorization": f"Bearer {CHANNEL_AUTH}",
        "Client-ID": CLIENT_ID,
    }
    params = {
        "broadcaster_id": CHANNEL_ID,
        "title": new_title
    }
    async with httpClientSession() as session:
        async with session.patch(url, headers=headers, json=params) as response:
            if response.status == 200:
                twitch_logger.info(f'Stream title updated to: {new_title}')
            else:
                twitch_logger.error(f'Failed to update stream title: {await response.text()}')

# Function to update the current stream category
async def update_twitch_game(game_name: str):
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
    # Twitch Helix endpoints
    twitch_search_url = "https://api.twitch.tv/helix/search/categories"
    twitch_game_update_url = "https://api.twitch.tv/helix/channels"
    # Headers for Twitch API
    twitch_headers = {
        "Authorization": f"Bearer {CHANNEL_AUTH}",
        "Client-ID": CLIENT_ID,
        "Content-Type": "application/json",
    }
    # Use the new search/categories endpoint so partial and multi-word queries work reliably
    async with httpClientSession() as session:
        params = {"query": game_name, "first": 1}
        async with session.get(twitch_search_url, headers=twitch_headers, params=params) as response:
            if response.status == 200:
                data = await response.json()
                matches = data.get("data", [])
                if matches:
                    game_id = matches[0].get("id")
                    game_name = matches[0].get("name")
                else:
                    raise GameNotFoundException(f"Game '{game_name}' not found in Twitch search results.")
            else:
                error_message = await response.text()
                raise GameNotFoundException(f"Failed to search game on Twitch API: {error_message}")
        # Update the Twitch stream game/category
        payload = {
            "broadcaster_id": CHANNEL_ID,
            "game_id": game_id
        }
        async with session.patch(twitch_game_update_url, headers=twitch_headers, json=payload) as twitch_response:
            if twitch_response.status == 200:
                twitch_logger.info(f"Stream game updated to: {game_name}")
                return game_name
            else:
                error_message = await twitch_response.text()
                raise GameUpdateFailedException(f"Failed to update stream game: {error_message}")

# Helper function to check if user has received automated shoutout today
async def get_automated_shoutout_cooldown():
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute("SELECT cooldown_minutes FROM automated_shoutout_settings LIMIT 1")
            result = await cursor.fetchone()
            if result:
                return max(60, int(result['cooldown_minutes']))  # Enforce minimum of 60 minutes
    except Exception as e:
        twitch_logger.error(f"Error fetching automated shoutout cooldown: {e}")
    return 60  # Default to 60 minutes

# Helper function to check if user is within automated shoutout cooldown
async def has_automated_shoutout_cooldown(user_id):
    if user_id not in recent_shoutouts:
        return False
    last_shoutout_time = recent_shoutouts[user_id]
    cooldown_minutes = await get_automated_shoutout_cooldown()
    cooldown_duration = timedelta(minutes=cooldown_minutes)
    time_since_shoutout = time_right_now() - last_shoutout_time
    return time_since_shoutout < cooldown_duration

# Helper function to record automated shoutout
async def record_automated_shoutout(user_id, user_name):
    now = time_right_now()
    recent_shoutouts[user_id] = now
    twitch_logger.info(f"Recorded automated shoutout for {user_name} (user_id: {user_id}) at {now}")
    # Store in database
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute(
                """INSERT INTO automated_shoutout_tracking (user_id, user_name, shoutout_time) 
                   VALUES (%s, %s, %s) 
                   ON DUPLICATE KEY UPDATE user_name = %s, shoutout_time = %s""",
                (user_id, user_name, now, user_name, now)
            )
            await connection.commit()
    except Exception as e:
        twitch_logger.error(f"Error storing automated shoutout in database: {e}")

# Helper function to load automated shoutout tracking from database
async def load_automated_shoutout_tracking():
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute("SELECT user_id, shoutout_time FROM automated_shoutout_tracking")
            results = await cursor.fetchall()
            for row in results:
                recent_shoutouts[row['user_id']] = row['shoutout_time']
            twitch_logger.info(f"Loaded {len(results)} automated shoutout tracking entries from database")
    except Exception as e:
        twitch_logger.error(f"Error loading automated shoutout tracking: {e}")

# Helper function to clear automated shoutout tracking
async def clear_automated_shoutout_tracking():
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute("DELETE FROM automated_shoutout_tracking")
            await connection.commit()
        recent_shoutouts.clear()
        twitch_logger.info("Cleared automated shoutout tracking")
    except Exception as e:
        twitch_logger.error(f"Error clearing automated shoutout tracking: {e}")

# Background task to cleanup expired shoutout entries
async def cleanup_expired_shoutouts():
    while True:
        try:
            await sleep(300)  # Run every 5 minutes
            cooldown_minutes = await get_automated_shoutout_cooldown()
            cooldown_duration = timedelta(minutes=cooldown_minutes)
            cutoff_time = time_right_now() - cooldown_duration
            # Remove expired entries from in-memory dictionary
            expired_user_ids = [user_id for user_id, shoutout_time in recent_shoutouts.items() if shoutout_time < cutoff_time]
            for user_id in expired_user_ids:
                del recent_shoutouts[user_id]
            if expired_user_ids:
                twitch_logger.info(f"Removed {len(expired_user_ids)} expired shoutout entries from memory")
            # Remove expired entries from database
            connection = await mysql_handler.get_connection()
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute(
                    "DELETE FROM automated_shoutout_tracking WHERE shoutout_time < %s",
                    (cutoff_time,)
                )
                deleted_count = cursor.rowcount
                await connection.commit()
                if deleted_count > 0:
                    twitch_logger.info(f"Removed {deleted_count} expired shoutout entries from database")
        except Exception as e:
            twitch_logger.error(f"Error in cleanup_expired_shoutouts: {e}")
            await sleep(60)  # Wait a minute before retrying on error

# Enqueue shoutout requests
async def add_shoutout(user_to_shoutout, user_id, is_automated=True):
    if is_automated and await has_automated_shoutout_cooldown(user_id):
        cooldown_minutes = await get_automated_shoutout_cooldown()
        twitch_logger.info(f"Skipping automated shoutout for {user_to_shoutout} (user_id: {user_id}) - still within {cooldown_minutes} minute cooldown.")
        return
    await shoutout_queue.put((user_to_shoutout, user_id, is_automated))
    twitch_logger.info(f"Added shoutout request for {user_to_shoutout} to the queue.")

# Worker to process shoutout queue
async def shoutout_worker():
    global last_shoutout_time
    while True:
        user_to_shoutout, user_id, is_automated = await shoutout_queue.get()
        now = time_right_now()
        # Check user-specific cooldown FIRST (before waiting for global cooldown)
        if user_id in shoutout_tracker:
            last_user_shoutout_time = shoutout_tracker[user_id]
            if now - last_user_shoutout_time < TWITCH_SHOUTOUT_USER_COOLDOWN:
                twitch_logger.info(f"Skipping shoutout for {user_to_shoutout}. User-specific cooldown in effect.")
                shoutout_queue.task_done()
                continue
        # Check global cooldown (only if user passed per-user cooldown check)
        if last_shoutout_time and now - last_shoutout_time < TWITCH_SHOUTOUT_GLOBAL_COOLDOWN:
            wait_time = (TWITCH_SHOUTOUT_GLOBAL_COOLDOWN - (now - last_shoutout_time)).total_seconds()
            twitch_logger.info(f"Waiting {wait_time} seconds for global cooldown.")
            await sleep(wait_time)
        # Trigger the shoutout
        await trigger_twitch_shoutout(user_to_shoutout, user_id)
        twitch_logger.info(f"Shoutout sent for {user_to_shoutout}.")
        shoutout_user[user_to_shoutout] = {"timestamp": time.time()}
        create_task(remove_shoutout_user(user_to_shoutout, 60))
        # Record automated shoutout
        if is_automated:
            await record_automated_shoutout(user_id, user_to_shoutout)
        # Update cooldown trackers
        last_shoutout_time = time_right_now()
        shoutout_tracker[user_id] = last_shoutout_time
        # Mark the task as done
        shoutout_queue.task_done()

# Function to trigger a Twitch shoutout via Twitch API
async def trigger_twitch_shoutout(user_to_shoutout, user_id):
    try:
        connection = await mysql_handler.get_connection(db_name="website")
    except Exception as e:
        twitch_logger.error(f"Database connection error while fetching bot access token: {e}")
        return
    async with connection.cursor(DictCursor) as cursor:
        bot_id = "971436498"
        await cursor.execute(f"SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = {bot_id} LIMIT 1")
        result = await cursor.fetchone()
        bot_auth = result.get('twitch_access_token')
        url = 'https://api.twitch.tv/helix/chat/shoutouts'
        headers = {
            "Authorization": f"Bearer {bot_auth}",
            "Client-ID": CLIENT_ID,
        }
        payload = {
            "from_broadcaster_id": CHANNEL_ID,
            "to_broadcaster_id": user_id,
            "moderator_id": bot_id
        }
        try:
            async with httpClientSession() as session:
                async with session.post(url, headers=headers, json=payload) as response:
                    if response.status in (200, 204):
                        twitch_logger.info(f"Shoutout triggered successfully for {user_to_shoutout}.")
                    else:
                        twitch_logger.error(f"Failed to trigger shoutout. Status: {response.status}. Message: {await response.text()}")
        except aiohttpClientError as e:
            twitch_logger.error(f"Error triggering shoutout: {e}")

# Function to get the last stream category for a user to shoutout
async def get_latest_stream_game(broadcaster_id, user_to_shoutout):
    global CLIENT_ID, CHANNEL_AUTH
    headers = {
        'Client-ID': CLIENT_ID,
        'Authorization': f'Bearer {CHANNEL_AUTH}'
    }
    params = {
        'broadcaster_id': broadcaster_id
    }
    async with httpClientSession() as session:
        async with session.get('https://api.twitch.tv/helix/channels', headers=headers, params=params) as response:
            if response.status == 200:
                data = await response.json()
                if data.get("data"):
                    game_name = data["data"][0].get("game_name")
                    if game_name:
                        twitch_logger.info(f"Got game for {user_to_shoutout}: {game_name}.")
                        return game_name
                    else:
                        api_logger.error(f"Game name not found in Twitch API response for {user_to_shoutout}.")
                        return None
                else:
                    api_logger.error(f"Empty response data from Twitch API for {user_to_shoutout}.")
                    return None
            else:
                api_logger.error(f"Failed to get game for {user_to_shoutout}. Status code: {response.status}")
                return None

# Function to process JSON requests
async def fetch_json(url, headers=None):
    try:
        async with httpClientSession() as session:
            async with session.get(url, headers=headers) as response:
                if response.status == 200:
                    if "json" in response.headers.get("Content-Type", ""):
                        return await response.json()
                    else:
                        return await response.text()
    except Exception as e:
        api_logger.error(f"Error fetching data: {e}")
    return None

# Function to process fourthwall events
async def process_fourthwall_event(data):
    integrations_logger.info("===== Fourthwall =====")
    # Check if 'data' is a string and needs to be parsed
    if isinstance(data.get('data'), str):
        try:
            # Parse the string to convert it to a dictionary
            data['data'] = ast.literal_eval(data['data'])
        except (ValueError, SyntaxError) as e:
            integrations_logger.error(f"Failed to parse data: {e}")
            return
    # Extract the event type and the nested event data
    event_type = data.get('data', {}).get('type')
    event_data = data.get('data', {}).get('data', {})
    # Check the event type and process accordingly
    try:
        if event_type == 'ORDER_PLACED':
            purchaser_name = event_data['username']
            offer = event_data['offers'][0]
            item_name = offer['name']
            item_quantity = offer['variant']['quantity']
            total_price = event_data['amounts']['total']['value']
            currency = event_data['amounts']['total']['currency']
            # Log the order details
            integrations_logger.info(f"New Order: {purchaser_name} bought {item_quantity} x {item_name} for {total_price} {currency}")
            # Prepare the message to send
            message = f"🎉 {purchaser_name} just bought {item_quantity} x {item_name} for {total_price} {currency}!"
            await send_chat_message(message)
        elif event_type == 'DONATION':
            donor_username = event_data['username']
            donation_amount = event_data['amounts']['total']['value']
            currency = event_data['amounts']['total']['currency']
            message_from_supporter = event_data.get('message', '')
            # Log the donation details and prepare the message
            if message_from_supporter:
                integrations_logger.info(f"New Donation: {donor_username} donated {donation_amount} {currency} with message: {message_from_supporter}")
                message = f"💰 {donor_username} just donated {donation_amount} {currency}! Message: {message_from_supporter}"
            else:
                integrations_logger.info(f"New Donation: {donor_username} donated {donation_amount} {currency}")
                message = f"💰 {donor_username} just donated {donation_amount} {currency}! Thank you!"
            await send_chat_message(message)
        elif event_type == 'GIVEAWAY_PURCHASED':
            purchaser_username = event_data['username']
            item_name = event_data['offer']['name']
            total_price = event_data['amounts']['total']['value']
            currency = event_data['amounts']['total']['currency']
            # Log the giveaway purchase details
            integrations_logger.info(f"New Giveaway Purchase: {purchaser_username} purchased giveaway '{item_name}' for {total_price} {currency}")
            # Prepare and send the message
            message = f"🎁 {purchaser_username} just purchased a giveaway: {item_name} for {total_price} {currency}!"
            await send_chat_message(message)
            # Process each gift
            for idx, gift in enumerate(event_data.get('gifts', []), start=1):
                gift_status = gift['status']
                winner = gift.get('winner', {})
                winner_username = winner.get('username', "No winner yet")
                # Log each gift's status and winner details
                integrations_logger.info(f"Gift {idx} is {gift_status} with winner: {winner_username}")
                # Prepare and send the gift status message
                gift_message = f"🎁 Gift {idx}: Status - {gift_status}. Winner: {winner_username}."
                await send_chat_message(gift_message)
        elif event_type == 'SUBSCRIPTION_PURCHASED':
            subscriber_nickname = event_data['nickname']
            subscription_variant = event_data['subscription']['variant']
            interval = subscription_variant['interval']
            amount = subscription_variant['amount']['value']
            currency = subscription_variant['amount']['currency']
            # Log the subscription purchase details
            integrations_logger.info(f"New Subscription: {subscriber_nickname} subscribed {interval} for {amount} {currency}")
            # Prepare and send the message
            message = f"🎉 {subscriber_nickname} just subscribed for {interval}, paying {amount} {currency}!"
            await send_chat_message(message)
        else:
            integrations_logger.info(f"Unhandled Fourthwall event: {event_type}")
    except KeyError as e:
        integrations_logger.error(f"Error processing event '{event_type}': Missing key {e}")
    except Exception as e:
        integrations_logger.error(f"Unexpected error processing event '{event_type}': {e}")

# Function to process KOFI events
async def process_kofi_event(data):
    integrations_logger.info("===== Ko-fi =====")
    if isinstance(data.get('data'), str):
        try:
            data['data'] = ast.literal_eval(data['data'])
        except (ValueError, SyntaxError) as e:
            integrations_logger.error(f"Failed to parse data: {e}")
            return
    if not isinstance(data.get('data'), dict):
        integrations_logger.error(f"Unexpected data structure: {data}")
        return
    # Extract event type and data
    event_type = data.get('data', {}).get('type', None)
    event_data = data.get('data', {})
    message_to_send = None
    if event_type is None:
        integrations_logger.info(f"Unhandled KOFI event: {event_type}")
        return
    # Process the event based on type
    try:
        if event_type == 'Donation':
            donor_name = event_data.get('from_name', 'Unknown')
            amount = event_data.get('amount', 'Unknown')
            currency = event_data.get('currency', 'Unknown')
            message = event_data.get('message', None)
            # Log the donation details and build the message to send to chat
            if message:
                integrations_logger.info(f"Donation: {donor_name} donated {amount} {currency} with message: {message}")
                message_to_send = f"💰 {donor_name} donated {amount} {currency}. Message: {message}"
            else:
                integrations_logger.info(f"Donation: {donor_name} donated {amount} {currency}")
                message_to_send = f"💰 {donor_name} donated {amount} {currency}. Thank you!"
        elif event_type == 'Subscription':
            subscriber_name = event_data.get('from_name', 'Unknown')
            amount = event_data.get('amount', 'Unknown')
            currency = event_data.get('currency', 'Unknown')
            is_first_payment = event_data.get('is_first_subscription_payment', False)
            tier_name = event_data.get('tier_name', 'None')
            # Log the subscription details and build the message to send to chat
            if is_first_payment:
                integrations_logger.info(f"Subscription: {subscriber_name} subscribed to {tier_name} for {amount} {currency} (First payment)")
                message_to_send = f"🎉 {subscriber_name} subscribed to {tier_name} for {amount} {currency} (First payment)!"
            else:
                integrations_logger.info(f"Subscription: {subscriber_name} renewed {tier_name} for {amount} {currency}")
                message_to_send = f"🎉 {subscriber_name} renewed {tier_name} for {amount} {currency}!"
        elif event_type == 'Shop Order':
            purchaser_name = event_data.get('from_name', 'Unknown')
            amount = event_data.get('amount', 'Unknown')
            currency = event_data.get('currency', 'Unknown')
            shop_items = event_data.get('shop_items', [])
            item_summary = ", ".join([f"{item['quantity']} x {item['variation_name']}" for item in shop_items])
            message_to_send = f"🛒 {purchaser_name} purchased items for {amount} {currency}. Items: {item_summary}"
            # Log the shop order details
            integrations_logger.info(f"Shop Order: {purchaser_name} ordered items for {amount} {currency}. Items: {item_summary}")
        else:
            integrations_logger.info(f"Unhandled KOFI event: {event_type}")
            return
        # Only send a message if it was successfully created
        if message_to_send:
            await send_chat_message(message_to_send)
    except KeyError as e:
        integrations_logger.error(f"Error processing event '{event_type}': Missing key {e}")
    except Exception as e:
        integrations_logger.error(f"Unexpected error processing event '{event_type}': {e}")

async def process_patreon_event(data):
    # Extract the data from the event
    message = data.get("message", {})
    message_data = message.get("data", {})
    patreon_event = message_data.get("event", {})
    # Extract the event data and attributes
    event_data = patreon_event.get("data", {})
    event_data_attributes = event_data.get("attributes", {})
    is_follower = event_data_attributes.get("is_follower", False)
    is_free_trial = event_data_attributes.get("is_free_trial", False)
    is_gifted = event_data_attributes.get("is_gifted", False)
    # Extract the included data to get the pay_per_name
    included_data = patreon_event.get("included", [])
    pay_per_name = "month"
    for item in included_data:
        attributes = item.get("attributes", {})
        if "pay_per_name" in attributes:
            pay_per_name = attributes["pay_per_name"]
            break
    # Determine the correct phrasing based on the pay_per_name
    if pay_per_name == "month":
        subscription_type = "monthly"
    elif pay_per_name == "yearly":
        subscription_type = "yearly"
    else:
        subscription_type = "monthly"
    # Process the event based on the data we have received
    if is_follower and is_gifted:
        message = f"A patreon follower has been gifted a {subscription_type} subscription!"
    elif is_follower and is_free_trial:
        message = f"A patreon follower has started a free trial!"
    elif is_follower:
        message = f"A patreon follower has subscribed for a {subscription_type} subscription!"
    elif is_gifted:
        message = f"A patreon supporter has been gifted a {subscription_type} subscription!"
    elif is_free_trial:
        message = f"A patreon supporter has started a free trial!"
    else:
        message = f"A patreon supporter just subscribed for a {subscription_type} plan!"
    await send_chat_message(message)

async def process_weather_websocket(data):
    # Convert weather_data from string to dictionary
    try:
        weather_data = ast.literal_eval(data.get('weather_data', '{}'))
    except (ValueError, SyntaxError) as e:
        event_logger.error(f"Error parsing weather data: {e}")
        return
    # Extract weather information from the weather_data
    location = weather_data.get('location', 'Unknown location')
    status = weather_data.get('status', 'Unknown status')
    temperature_c = weather_data.get('temperature', 'Unknown').split('°C')[0].strip()
    temperature_f = weather_data.get('temperature', 'Unknown').split('°F')[0].split('|')[-1].strip()
    wind_speed_kph = weather_data.get('wind', 'Unknown').split('kph')[0].strip()
    wind_speed_mph = weather_data.get('wind', 'Unknown').split('mph')[0].split('|')[-1].strip()
    wind_direction = weather_data.get('wind', 'Unknown').split()[-1]
    humidity = weather_data.get('humidity', 'Unknown').split('%')[0].strip()
    # Get the current UTC time using timezone-aware datetime
    now = time_right_now(pytz_timezone("UTC"))
    minutes_ago = now.minute  # Get current minutes (0-59)
    # Format the message
    message = (f"The weather as of {minutes_ago} min ago in {location} is {status} with a temperature of "
               f"{temperature_c}°C ({temperature_f}°F). Wind is blowing from the {wind_direction} at "
               f"{wind_speed_kph} kph ({wind_speed_mph} mph) with {humidity}% humidity.")
    # Log and send message
    event_logger.info(f"Sending weather update: {message}")
    await send_chat_message(message)

# Function to process the stream being online
async def process_stream_online_websocket():
    global stream_online, current_game, CLIENT_ID, CHANNEL_AUTH, CHANNEL_NAME
    stream_online = True
    looped_tasks["timed_message"] = create_task(timed_message())
    looped_tasks["handle_upcoming_ads"] = create_task(handle_upcoming_ads())
    await generate_winning_lotto_numbers()
    # Reach out to the Twitch API to get stream data
    async with httpClientSession() as session:
        headers = {
            'Client-ID': CLIENT_ID,
            'Authorization': f'Bearer {CHANNEL_AUTH}'
        }
        params = {
            'user_login': CHANNEL_NAME,
            'type': 'live'
        }
        async with session.get('https://api.twitch.tv/helix/streams', headers=headers, params=params) as response:
            data = await response.json()
    # Extract necessary data from the API response
    if data.get('data'):
        current_game = data['data'][0].get('game_name')
    else:
        current_game = "Unknown"
    # Send a message to the chat announcing the stream is online
    message = f"Stream is now online! Streaming {current_game}" if current_game else "Stream is now online!"
    await send_chat_message(message)
    # Log the status to the file
    os.makedirs(f'/home/botofthespecter/logs/online', exist_ok=True)
    with open(f'/home/botofthespecter/logs/online/{CHANNEL_NAME}.txt', 'w') as file:
        file.write('True')
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            # Update the stream status in the database
            await cursor.execute("UPDATE stream_status SET status = %s", ("True",))
            await connection.commit()
    finally:
        pass

# Function to process the stream being offline
async def process_stream_offline_websocket():
    global stream_online, scheduled_clear_task
    stream_online = False  # Update the stream status
    # Cancel any previous scheduled task to avoid duplication
    if "hyperate_websocket" in looped_tasks:
        looped_tasks["hyperate_websocket"].cancel()
    if 'scheduled_clear_task' in globals() and scheduled_clear_task:
        scheduled_clear_task.cancel()
    # Clear automated shoutout tracking
    await clear_automated_shoutout_tracking()
    # Schedule the clearing task with a 5-minute delay
    scheduled_clear_task = create_task(delayed_clear_tables())
    bot_logger.info("Scheduled task to clear tables if stream remains offline for 5 minutes.")
    # Immediately remove any temporary VIPs granted with (vip.today)
    try:
        await clear_temporary_vips()
    except Exception as e:
        bot_logger.error(f"Error clearing temporary VIPs on stream offline: {e}")
    # Log the status to the file
    os.makedirs(f'/home/botofthespecter/logs/online', exist_ok=True)
    with open(f'/home/botofthespecter/logs/online/{CHANNEL_NAME}.txt', 'w') as file:
        file.write('False')
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            # Update the stream status in the database
            await cursor.execute("UPDATE stream_status SET status = %s", ("False",))
            await connection.commit()
    finally:
        pass

# Function to clear both tables if the stream remains offline after 5 minutes
async def delayed_clear_tables():
    global stream_online
    for _ in range(30):  # Check every 10 seconds for 5 minutes
        await sleep(10)
        if stream_online:
            bot_logger.info("Stream is back online. Skipping table clear.")
            return
    # If the stream is still offline after 5 minutes, clear the tables
    await clear_seen_today()
    await clear_credits_data()
    await clear_per_stream_deaths()
    await clear_lotto_numbers()
    await stop_all_timed_messages()
    # Ensure temporary VIPs are removed as part of end-of-stream cleanup
    try:
        await clear_temporary_vips()
    except Exception as e:
        bot_logger.error(f"Error clearing temporary VIPs during delayed cleanup: {e}")
    for task_name in ["timed_message", "handle_upcoming_ads"]:
        task = looped_tasks.get(task_name)
        if task and not task.done():
            bot_logger.info(f"Cancelling task: {task_name}")
            task.cancel()
            try:
                await task
            except asyncioCancelledError:
                bot_logger.info(f"Task {task_name} cancelled successfully.")
    bot_logger.info("Tables and lotto entries cleared after stream remained offline.")

# Function to clear the seen users table at the end of stream
async def clear_seen_today():
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute('TRUNCATE TABLE seen_today')
            await connection.commit()
            bot_logger.info('Seen today table cleared successfully.')
    except MySQLOtherErrors as err:
        bot_logger.error(f'Failed to clear seen today table: {err}')
    finally:
        pass


# Clear temporary VIPs granted with (vip.today) at end of stream
async def clear_temporary_vips():
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute("SELECT user_id, username FROM vip_today")
            rows = await cursor.fetchall()
            if not rows:
                return
            async with httpClientSession() as session:
                headers_vip = {
                    'Client-Id': CLIENT_ID,
                    'Authorization': f'Bearer {CHANNEL_AUTH}'
                }
                for row in rows:
                    uid = row.get('user_id')
                    uname = row.get('username') or uid
                    try:
                        params = {'broadcaster_id': CHANNEL_ID, 'user_id': uid}
                        url = 'https://api.twitch.tv/helix/channels/vips'
                        async with session.delete(url, headers=headers_vip, params=params) as resp:
                            if resp.status == 204:
                                bot_logger.info(f"Removed temporary VIP for {uname} ({uid})")
                            else:
                                text = await resp.text()
                                bot_logger.error(f"Failed to remove VIP for {uname} ({uid}): {resp.status} {text}")
                        # be conservative with rate limits
                        await sleep(0.25)
                    except Exception as e:
                        bot_logger.error(f"Exception removing VIP for {uname} ({uid}): {e}")
            # Remove all entries from vip_today regardless of success to avoid repeated attempts
            await cursor.execute('TRUNCATE TABLE vip_today')
            await connection.commit()
            bot_logger.info('Cleared vip_today table after attempting removals.')
    except MySQLOtherErrors as err:
        bot_logger.error(f'Failed to clear vip_today table: {err}')
    finally:
        pass

# Function to clear the ending credits table at the end of stream
async def clear_credits_data():
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute('TRUNCATE TABLE stream_credits')
            await connection.commit()
            bot_logger.info('Stream credits table cleared successfully.')
    except MySQLOtherErrors as err:
        bot_logger.error(f'Failed to clear stream credits table: {err}')
    finally:
        pass

# Function to clear the death count per stream
async def clear_per_stream_deaths():
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute('TRUNCATE TABLE per_stream_deaths')
            await connection.commit()
            bot_logger.info('Per Stream Death Count cleared successfully.')
    except MySQLOtherErrors as err:
        bot_logger.error(f'Failed to clear Per Stream Death Count: {err}')
    finally:
        pass

# Function to clear the lotto numbers at the end of stream
async def clear_lotto_numbers():
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute('TRUNCATE TABLE stream_lotto')
            await connection.commit()
            await cursor.execute('TRUNCATE TABLE stream_lotto_winning_numbers')
            await connection.commit()
            bot_logger.info('Lotto Numbers cleared successfully.')
    except MySQLOtherErrors as err:
        bot_logger.error(f'Failed to clear Lotto Numbers: {err}')
    finally:
        pass

# Function for timed messages
async def timed_message():
    global stream_online
    chat_logger.info(f"Timed message function called. Stream online status: {stream_online}")
    if stream_online:
        chat_logger.info("Starting dynamic timed message system...")
        try:
            await update_timed_messages()
            chat_logger.info("Successfully updated timed messages")
            # Start the periodic checker
            if "timed_message_checker" not in looped_tasks:
                looped_tasks["timed_message_checker"] = create_task(periodic_message_checker())
                chat_logger.info("Created periodic message checker task")
        except Exception as e:
            bot_logger.error(f"Error in timed_message initialization: {e}")
    else:
        chat_logger.info("Stream is offline, stopping timed messages")
        await stop_all_timed_messages()

async def periodic_message_checker():
    global stream_online
    try:
        while stream_online:
            await sleep(60)  # Check every minute
            if stream_online:
                await update_timed_messages()
            else:
                break
    except asyncioCancelledError:
        chat_logger.info("Periodic message checker cancelled")
    except Exception as e:
        bot_logger.error(f"Error in periodic_message_checker: {e}")

async def update_timed_messages():
    global active_timed_messages, message_tasks, chat_trigger_tasks, scheduled_tasks, stream_online
    if not stream_online:
        return
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            # Fetch all enabled messages
            await cursor.execute("SELECT id, interval_count, message, status, chat_line_trigger FROM timed_messages WHERE status = 1")
            current_messages = await cursor.fetchall()
            if not current_messages:
                return
            # Convert to dictionary for easy lookup
            current_message_dict = {row["id"]: row for row in current_messages}
            current_message_ids = set(current_message_dict.keys())
            active_message_ids = set(active_timed_messages.keys())
            # Find new messages to add
            new_message_ids = current_message_ids - active_message_ids
            # Find removed/disabled messages to stop
            removed_message_ids = active_message_ids - current_message_ids
            # Find existing messages that might have changed
            existing_message_ids = current_message_ids & active_message_ids
            # Stop removed/disabled messages
            for message_id in removed_message_ids:
                await stop_timed_message(message_id)
                chat_logger.info(f"Stopped timed message ID: {message_id} (removed or disabled)")
            # Add new messages
            for message_id in new_message_ids:
                row = current_message_dict[message_id]
                await start_timed_message(message_id, row)
                chat_logger.info(f"Started new timed message ID: {message_id}")
            # Check for changes in existing messages
            for message_id in existing_message_ids:
                current_row = current_message_dict[message_id]
                active_row = active_timed_messages[message_id]
                # Check if message content or settings have changed
                if (current_row["message"] != active_row["message"] or
                    current_row["interval_count"] != active_row["interval_count"] or
                    current_row["chat_line_trigger"] != active_row["chat_line_trigger"]):
                    # Restart the message with new settings
                    await stop_timed_message(message_id)
                    await start_timed_message(message_id, current_row)
                    chat_logger.info(f"Restarted timed message ID: {message_id} (settings changed)")
    except Exception as e:
        bot_logger.error(f"Error in update_timed_messages: {e}")
    finally:
        pass

async def start_timed_message(message_id, row):
    global active_timed_messages, message_tasks, chat_trigger_tasks, scheduled_tasks, chat_line_count
    message = row["message"]
    interval = row["interval_count"]
    chat_line_trigger = row["chat_line_trigger"]
    # Store message details in memory
    active_timed_messages[message_id] = dict(row)
    # If interval_count is set, this is an interval-based message
    if interval and int(interval) > 0:
        interval_mins = max(5, min(60, int(interval)))
        wait_time = interval_mins * 60
        task = create_task(send_interval_message(message_id, message, wait_time))
        message_tasks[message_id] = task
        scheduled_tasks.add(task)
        chat_logger.info(f"Started interval message ID: {message_id} every {interval_mins} minutes")
    # If chat_line_trigger is set, this is a chat-count based message
    elif chat_line_trigger and int(chat_line_trigger) > 0:
        chat_trigger_tasks[message_id] = {
            "chat_line_trigger": int(chat_line_trigger),
            "message": message,
            "last_trigger_count": chat_line_count
        }
        chat_logger.info(f"Started chat count message ID: {message_id} - trigger after {chat_line_trigger} messages")

async def stop_timed_message(message_id):
    global active_timed_messages, message_tasks, chat_trigger_tasks, scheduled_tasks
    # Remove from active messages
    if message_id in active_timed_messages:
        del active_timed_messages[message_id]
    # Cancel interval task if exists
    if message_id in message_tasks:
        task = message_tasks[message_id]
        task.cancel()
        if task in scheduled_tasks:
            scheduled_tasks.remove(task)
        del message_tasks[message_id]
    # Remove from chat trigger tasks
    if message_id in chat_trigger_tasks:
        del chat_trigger_tasks[message_id]

async def stop_all_timed_messages():
    global active_timed_messages, message_tasks, chat_trigger_tasks, scheduled_tasks, chat_line_count
    chat_logger.info("Stopping all timed messages...")
    # Cancel periodic checker
    if "timed_message_checker" in looped_tasks:
        looped_tasks["timed_message_checker"].cancel()
        del looped_tasks["timed_message_checker"]
    # Cancel all interval tasks
    for task in list(message_tasks.values()):
        task.cancel()
    # Cancel all scheduled tasks
    for task in scheduled_tasks:
        task.cancel()
    # Clear all tracking dictionaries
    active_timed_messages.clear()
    message_tasks.clear()
    chat_trigger_tasks.clear()
    scheduled_tasks.clear()
    chat_line_count = 0

async def handle_chat_message(messageAuthor, messageContent=""):
    global chat_trigger_tasks, chat_line_count, stream_online
    if not stream_online:
        return
    # Don't count bot messages
    if messageAuthor.lower() == BOT_USERNAME.lower():
        return
    # Don't count command messages (starting with !)
    if messageContent and messageContent.strip().startswith('!'):
        return
    # Increment the global chat message counter
    chat_line_count += 1
    # Check each tracked message for trigger conditions
    chat_logger.debug(f"Chat line count: {chat_line_count} (from {messageAuthor})")
    for message_id, trigger_info in chat_trigger_tasks.items():
        chat_line_trigger = trigger_info["chat_line_trigger"]
        last_trigger_count = trigger_info["last_trigger_count"]
        message = trigger_info["message"]
        # Check if enough new chat lines have occurred since the last trigger
        if chat_line_count - last_trigger_count >= chat_line_trigger:
            trigger_info["last_trigger_count"] = chat_line_count  # Update last trigger count
            create_task(send_timed_message(message_id, message, 0))
            chat_logger.info(f"Chat count trigger reached for message ID: {message_id}")

async def send_interval_message(message_id, message, interval_seconds):
    global stream_online, scheduled_tasks
    switchs = ["(game)",]
    if message and any(switch in message for switch in switchs):
        game_name = await get_current_game()
        message = message.replace("(game)", game_name)
    while stream_online:
        await sleep(interval_seconds)
        if stream_online:
            await send_timed_message(message_id, message, 0)
        else:
            break

async def send_timed_message(message_id, message, delay):
    global stream_online, last_message_time
    if delay > 0:
        await sleep(delay)
    if stream_online:
        # Ensure a delay between consecutive messages
        current_time = get_event_loop().time()
        safe_gap = 60
        if last_message_time != 0: # Check if last_message_time has been initialized
            elapsed = current_time - last_message_time
            if elapsed < safe_gap:
                wait_time = safe_gap - elapsed
                chat_logger.info(f"Waiting {wait_time} more seconds before sending Timed Message ID: {message_id}")
                await sleep(wait_time)
        chat_logger.info(f"Sending Timed Message ID: {message_id} - {message}")
        try:
            await send_chat_message(message)
            last_message_time = get_event_loop().time()
        except Exception as e:
            bot_logger.error(f"Error sending message: {e}")
            bot_logger.error(f"BOTS_TWITCH_BOT state: {BOTS_TWITCH_BOT._connection._status}")
    else:
        chat_logger.info(f'Stream is offline. Message ID: {message_id} not sent.')

# Function to get Spotify access token from database
async def get_spotify_access_token():
    try:
        connection = await mysql_handler.get_connection(db_name="website")
        async with connection.cursor(DictCursor) as cursor:
            # Get the user_id from the profile table based on CHANNEL_NAME
            await cursor.execute("SELECT id FROM users WHERE username = %s", (CHANNEL_NAME,))
            user_row = await cursor.fetchone()
            if user_row:
                user_id = user_row["id"]
                # Fetch the Spotify access token for this user
                await cursor.execute("SELECT access_token FROM spotify_tokens WHERE user_id = %s", (user_id,))
                token_row = await cursor.fetchone()
                if token_row and token_row.get("access_token"):
                    return token_row["access_token"]
                else:
                    api_logger.error(f"No Spotify access token found for user {CHANNEL_NAME}")
                    return None
            else:
                api_logger.error(f"No user found with username {CHANNEL_NAME}")
                return None
    except Exception as e:
        api_logger.error(f"Error retrieving Spotify access token: {e}")
        return None
    finally:
        pass

# Function to get the song via Spotify
async def get_spotify_current_song():
    global SPOTIFY_ERROR_MESSAGES, song_requests
    # Get the Spotify access token from the database
    access_token = await get_spotify_access_token()
    if not access_token:
        api_logger.error("Failed to retrieve Spotify access token from database")
        return None, None, None, "Failed to retrieve Spotify access token"
    headers = { "Authorization": f"Bearer {access_token}" }
    async with httpClientSession() as session:
        async with session.get("https://api.spotify.com/v1/me/player/currently-playing", headers=headers) as response:
            if response.status == 200:
                data = await response.json()
                # Extract song name, artist if Spotify is currently playing
                is_playing = data["is_playing"]
                if is_playing:
                    song_id = data["item"]["uri"]
                    song_name = data["item"]["name"]
                    artist_name = ", ".join([artist["name"] for artist in data["item"]["artists"]])
                    api_logger.info(f"The current song from Spotify is: {song_name} by {artist_name}")
                    return song_name, artist_name, song_id, None  # Return song name, artist name, song id and no error
                else:
                    return None, None, None, None  # No song playing
            elif response.status == 204:
                # 204 No Content means no song is currently playing
                return None, None, None, None
            else:
                # Handle potential Spotify API errors with proper error messages
                error_message = SPOTIFY_ERROR_MESSAGES.get(response.status, "Spotify gave me an unknown error. Try again in a moment.")
                api_logger.error(f"Spotify API error: {response.status} - {error_message}")
                return None, None, None, error_message

# Function to get the current playing song
async def shazam_the_song():
    try:
        song_info = await shazam_song_info()
        if "error" in song_info:
            error_message = song_info["error"]
            chat_logger.error(f"Trying to Shazam the audio I got an error: {error_message}")
            return error_message
        else:
            artist = song_info.get('artist', '')
            song = song_info.get('song', '')
            message = f"The current song is: {song} by {artist}"
            chat_logger.info(message)
            return message
    except Exception as e:
        api_logger.error(f"An error occurred while getting song info: {e}")
        return "Failed to get song information."

async def shazam_song_info():
    global stream_recording_file_global, raw_recording_file_global
    try:
        # Test validity of GQL OAuth token
        if not await twitch_gql_token_valid():
            return {"error": "Twitch GQL Token Expired"}
        # Record stream audio
        random_file_name = str(random.randint(10000000, 99999999))
        working_dir = "/home/botofthespecter/logs/songs"
        stream_recording_file = os.path.join(working_dir, f"{random_file_name}.acc")
        raw_recording_file = os.path.join(working_dir, f"{random_file_name}.raw")
        outfile = os.path.join(working_dir, f"{random_file_name}.acc")
        # Assign filenames to global variables
        stream_recording_file_global = stream_recording_file
        raw_recording_file_global = raw_recording_file
        if not await record_stream(outfile):
            return {"error": "Stream is not available"}
        # Convert Stream Audio into Raw Format for Shazam
        if not await convert_to_raw_audio(stream_recording_file, raw_recording_file):
            return {"error": "Error converting stream audio from ACC to raw PCM s16le"}
        # Encode raw audio to base64
        with open(raw_recording_file, "rb") as song:
            songBytes = song.read()
            songb64 = base64.b64encode(songBytes)
            # Detect the song
            matches = await shazam_detect_song(songb64)
            if "track" in matches.keys():
                artist = matches["track"].get("subtitle", "")
                song_title = matches["track"].get("title", "")
                api_logger.info(f"Identified song: {song_title} by {artist}.")
                return {"artist": artist, "song": song_title}
            else:
                return {"error": "The current song can not be identified."}
    except Exception as e:
        api_logger.error(f"An error occurred while getting song info: {e}")
        return {"error": "Failed to get song information."}

async def twitch_gql_token_valid():
    try:
        url = "https://gql.twitch.tv/gql"
        headers = {
            "Client-Id": CLIENT_ID,
            "Content-Type": "text/plain",
            "Authorization": f"OAuth {TWITCH_GQL}"
        }
        data = [
            {
                "operationName": "SyncedSettingsEmoteAnimations",
                    "variables": {},
                    "extensions": {
                    "persistedQuery": {
                        "version": 1,
                        "sha256Hash": "64ac5d385b316fd889f8c46942a7c7463a1429452ef20ffc5d0cd23fcc4ecf30"
                    }
                }
            }
        ]
        async with httpClientSession() as session:
            async with session.post(url, headers=headers, json=data, timeout=10) as response:
                # Log the status code received
                api_logger.info(f"Twitch GQL token validation response status code: {response.status}")
                if response.status == 200:
                    return True
                else:
                    api_logger.error(f"Twitch GQL token validation failed with status code: {response.status}")
                    return False
    except Exception as e:
        api_logger.error(f"An error occurred while checking Twitch GQL token validity: {e}")
        return False

async def shazam_detect_song(raw_audio_b64):
    try:
        url = "https://shazam.p.rapidapi.com/songs/v2/detect"
        querystring = {"timezone": "Australia/Sydney", "locale": "en-US"}
        headers = {
            "content-type": "text/plain",
            "X-RapidAPI-Key": SHAZAM_API,
            "X-RapidAPI-Host": "shazam.p.rapidapi.com"
        }
        # Convert base64 encoded audio to bytes
        audio_bytes = raw_audio_b64
        async with httpClientSession() as session:
            async with session.post(url, data=audio_bytes, headers=headers, params=querystring, timeout=15) as response:
                # Check requests remaining for the API
                if "x-ratelimit-requests-remaining" in response.headers:
                    requests_left = response.headers['x-ratelimit-requests-remaining']
                    file_path = "/var/www/api/shazam.txt"
                    with open(file_path, 'w') as file:
                        file.write(requests_left)
                    api_logger.info(f"There are {requests_left} requests lefts for the song command.")
                    connection = await mysql_handler.get_connection(db_name="website")
                    async with connection.cursor(DictCursor) as cursor:
                        await cursor.execute("UPDATE api_counts SET count=%s WHERE type=%s", (requests_left, "shazam"))
                        await connection.commit()
                    if int(requests_left) == 0:
                        return {"error": "Sorry, no more requests for song info are available for the rest of the month. Requests reset each month on the 23rd."}
                return await response.json()
    except Exception as e:
        api_logger.error(f"An error occurred while detecting song: {e}")
        return {}

async def convert_to_raw_audio(in_file, out_file):
    try:
        ffmpeg_path = "/usr/bin/ffmpeg"
        proc = await create_subprocess_exec(
            ffmpeg_path, '-i', in_file, "-vn", "-ar", "44100", "-ac", "1", "-c:a", "pcm_s16le", "-f", "s16le", out_file,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL)
        # Wait for the subprocess to finish
        returncode = await proc.wait()
        return returncode == 0
    except Exception as e:
        api_logger.error(f"An error occurred while converting audio: {e}")
        return False

async def record_stream(outfile):
    try:
        session = Streamlink()
        session.set_option("http-headers", {"Authorization": f"OAuth {TWITCH_GQL}"})
        streams = session.streams(f"https://twitch.tv/{CHANNEL_NAME}")
        if len(streams) == 0 or "worst" not in streams.keys():
            return False
        stream_obj = streams["worst"]
        fd = stream_obj.open()
        chunk = 1024
        num_bytes = 0
        data = b''
        max_bytes = 200
        while num_bytes <= max_bytes * 1024:
            data += fd.read(chunk)
            num_bytes += chunk
        fd.close()
        with open(outfile, "wb") as file:
            file.write(data)
        return os.path.exists(outfile)
    except Exception as e:
        api_logger.error(f"An error occurred while recording stream: {e}")
        return False

async def delete_recorded_files():
    global stream_recording_file_global, raw_recording_file_global
    try:
        if stream_recording_file_global:
            os.remove(stream_recording_file_global)
        if raw_recording_file_global:
            os.remove(raw_recording_file_global)
        stream_recording_file_global = None
        raw_recording_file_global = None
    except Exception as e:
        api_logger.error(f"An error occurred while deleting recorded files: {e}")

# Function for POLLS
async def handel_twitch_poll(event=None, poll_title=None, half_time=None, message=None):
    if event == "poll.start":
        await send_chat_message(message)
        half_time = int(half_time.total_seconds()), 60
        minutes, seconds = divmod(half_time)
        if minutes and seconds:
            time_left = f"{minutes} minutes and {seconds} seconds"
        elif minutes:
            time_left = f"{minutes} minutes"
        else:
            time_left = f"{seconds} seconds"
        half_way_message = f"The poll '{poll_title}' is halfway through! You have {time_left} left to cast your vote."
        @routines.routine(seconds=half_time, iterations=1, wait_first=True)
        async def handel_twitch_poll_half_message():
            await send_chat_message(half_way_message)
        handel_twitch_poll_half_message.start()
    elif event == "poll.end":
        await send_chat_message(message)
        handel_twitch_poll_half_message.cancel()

# Function for RAIDS
async def process_raid_event(from_broadcaster_id, from_broadcaster_name, viewer_count):
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            # Check existing raid data
            await cursor.execute('SELECT raid_count, viewers FROM raid_data WHERE raider_id = %s', (from_broadcaster_id,))
            existing_data = await cursor.fetchone()
            # Update or insert raid data
            if existing_data:
                existing_raid_count = existing_data["raid_count"]
                existing_viewer_count = existing_data["viewers"]
                raid_count = existing_raid_count + 1
                viewers = existing_viewer_count + viewer_count
                await cursor.execute('UPDATE raid_data SET raid_count = %s, viewers = %s WHERE raider_id = %s', (raid_count, viewers, from_broadcaster_id))
            else:
                await cursor.execute('INSERT INTO raid_data (raider_id, raider_name, raid_count, viewers) VALUES (%s, %s, %s, %s)', (from_broadcaster_id, from_broadcaster_name, 1, viewer_count))
            # Insert stream credits data
            await cursor.execute('INSERT INTO stream_credits (username, event, data) VALUES (%s, %s, %s)', (from_broadcaster_name, "raid", viewer_count))
            # Retrieve the bot settings to get the raid points amount and subscriber multiplier
            settings = await get_point_settings()
            raid_points = int(settings['raid_points'])
            subscriber_multiplier = int(settings.get('subscriber_multiplier', 1))
            # Check if the user is a subscriber and apply the multiplier
            if await is_user_subscribed(from_broadcaster_id) is not None:
                raid_points *= subscriber_multiplier
            # Calculate and award points based on the raid event
            total_awarded_points = raid_points * viewer_count
            # Fetch current points for the raider
            await cursor.execute("SELECT points FROM bot_points WHERE user_id = %s", (from_broadcaster_id,))
            result = await cursor.fetchone()
            current_points = result.get("points") if result else 0
            # Update the raider's points
            new_points = current_points + total_awarded_points
            if result:
                await cursor.execute("UPDATE bot_points SET points = %s WHERE user_id = %s", (new_points, from_broadcaster_id))
            else:
                await cursor.execute(
                    "INSERT INTO bot_points (user_id, user_name, points) VALUES (%s, %s, %s)",
                    (from_broadcaster_id, from_broadcaster_name, new_points)
                )
            await connection.commit()
            # Record raid in per-channel analytics DB (analytic_raids) if available
            try:
                user_conn = await mysql_handler.get_connection(db_name=CHANNEL_NAME)
                async with user_conn.cursor(DictCursor) as user_cursor:
                    await user_cursor.execute(
                        "INSERT INTO analytic_raids (raider_name, viewers, source, created_at) VALUES (%s, %s, %s, NOW())",
                        (from_broadcaster_name, viewer_count, 'received')
                    )
                    await user_conn.commit()
            except Exception as e:
                twitch_logger.error(f"Failed to write raid analytics to analytic_raids for channel {CHANNEL_NAME}: {e}")
            # Send raid notification to Twitch Chat, and Websocket
            create_task(websocket_notice(event="TWITCH_RAID", user=from_broadcaster_name, raid_viewers=viewer_count))
            # Send a message to the Twitch channel
            await cursor.execute("SELECT alert_message FROM twitch_chat_alerts WHERE alert_type = %s", ("raid_alert",))
            result = await cursor.fetchone()
            if result and result.get("alert_message"):
                alert_message = result.get("alert_message")
            else:
                alert_message = "Incredible! (user) and (viewers) viewers have joined the party! Let's give them a warm welcome!"
            # Check if shoutout trigger is in the message
            send_shoutout = False
            shoutout_message = None
            if "(shoutout)" in alert_message:
                send_shoutout = True
                alert_message = alert_message.replace("(shoutout)", "")
                user_id = from_broadcaster_id
                user_to_shoutout = from_broadcaster_name
                shoutout_message = await get_shoutout_message(user_id, user_to_shoutout, "raid")
            # Replace variables in the message
            alert_message = alert_message.replace("(user)", from_broadcaster_name).replace("(viewers)", str(viewer_count))
            if alert_message.strip():
                await send_chat_message(alert_message)
            if send_shoutout and shoutout_message:
                await add_shoutout(user_to_shoutout, user_id, is_automated=True)
                await send_chat_message(shoutout_message)
            marker_description = f"New Raid from {from_broadcaster_name}"
            if await make_stream_marker(marker_description):
                twitch_logger.info(f"A stream marker was created: {marker_description}.")
            else:
                twitch_logger.info("Failed to create a stream marker.")
            await cursor.execute("SELECT * FROM twitch_sound_alerts WHERE twitch_alert_id = %s", ("Raid",))
            result = await cursor.fetchone()
            if result and result.get("sound_mapping"):
                sound_file = "twitch/" + result.get("sound_mapping")
                create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
    finally:
        pass

# Function for BITS
async def process_cheer_event(user_id, user_name, bits):
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute("SELECT alert_message FROM twitch_chat_alerts WHERE alert_type = %s", ("cheer_alert",))
            result = await cursor.fetchone()
            if result and result.get("alert_message"):
                alert_message = result.get("alert_message")
            else:
                alert_message = "Thank you (user) for (bits) bits! You've given a total of (total-bits) bits."
            # Check existing bits data
            await cursor.execute('SELECT bits FROM bits_data WHERE user_id = %s OR user_name = %s', (user_id, user_name))
            existing_bits = await cursor.fetchone()
            # Update or insert bits data
            if existing_bits:
                total_bits = existing_bits["bits"] + bits
                await cursor.execute('UPDATE bits_data SET bits = %s WHERE user_id = %s OR user_name = %s', (total_bits, user_id, user_name))
                total_bits = "{:,}".format(total_bits)
            else:
                total_bits = bits
                total_bits = "{:,}".format(total_bits)
                await cursor.execute('INSERT INTO bits_data (user_id, user_name, bits) VALUES (%s, %s, %s)', (user_id, user_name, bits))
                if bits < 100:
                    image = "cheer.png"
                elif 100 <= bits < 1000:
                    image = "cheer100.png"
                else:
                    image = "cheer1000.png"
            # Check if shoutout trigger is in the message
            send_shoutout = False
            shoutout_message = None
            if "(shoutout)" in alert_message:
                send_shoutout = True
                alert_message = alert_message.replace("(shoutout)", "")
                shoutout_message = await get_shoutout_message(user_id, user_name, "cheer")
            alert_message = alert_message.replace("(user)", user_name).replace("(bits)", str(bits)).replace("(total-bits)", str(total_bits))
            if alert_message.strip():
                await send_chat_message(alert_message)
            if send_shoutout and shoutout_message:
                await add_shoutout(user_name, user_id, is_automated=True)
                await send_chat_message(shoutout_message)
            # Insert stream credits data
            await cursor.execute('INSERT INTO stream_credits (username, event, data) VALUES (%s, %s, %s)', (user_name, "bits", bits))
            # Retrieve the bot settings to get the cheer points amount and subscriber multiplier
            settings = await get_point_settings()
            cheer_points = int(settings['cheer_points'])
            subscriber_multiplier = int(settings['subscriber_multiplier'])
            # Check if the user is a subscriber and apply the multiplier
            if await is_user_subscribed(user_id) is not None:
                cheer_points *= subscriber_multiplier
            # Fetch current points for the user
            await cursor.execute("SELECT points FROM bot_points WHERE user_id = %s", (user_id,))
            result = await cursor.fetchone()
            current_points = result.get("points") if result else 0
            # Award points based on the cheer event
            new_points = current_points + cheer_points
            if result:
                await cursor.execute("UPDATE bot_points SET points = %s WHERE user_id = %s", (new_points, user_id))
            else:
                await cursor.execute(
                    "INSERT INTO bot_points (user_id, user_name, points) VALUES (%s, %s, %s)",
                    (user_id, user_name, new_points)
                )
            await connection.commit()
            # Add time to subathon if it's running
            subathon_state = await get_subathon_state()
            if subathon_state and not subathon_state[4]:  # If subathon is running
                cheer_add_time = int(settings['cheer_add'])  # Retrieve the time to add for cheers
                await addtime_subathon(CHANNEL_NAME, cheer_add_time)  # Call to add time based on cheers
            # Send cheer notification to Twitch Chat, and Websocket
            create_task(websocket_notice(event="TWITCH_CHEER", user=user_name, cheer_amount=bits))
            marker_description = f"New Cheer from {user_name}"
            if await make_stream_marker(marker_description):
                twitch_logger.info(f"A stream marker was created: {marker_description}.")
            else:
                twitch_logger.info("Failed to create a stream marker.")
            await cursor.execute("SELECT * FROM twitch_sound_alerts WHERE twitch_alert_id = %s", ("Cheer",))
            result = await cursor.fetchone()
            if result and result.get("sound_mapping"):
                sound_file = "twitch/" + result.get("sound_mapping")
                create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
    finally:
        pass

# Function for Subscriptions
async def process_subscription_event(user_id, user_name, sub_plan, event_months, is_upgrade=False):
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            event_logger.info(f"Processing subscription event for user_id: {user_id}, user_name: {user_name}")
            await cursor.execute('SELECT sub_plan, months FROM subscription_data WHERE user_id = %s', (user_id,))
            existing_subscription = await cursor.fetchone()
            event_logger.debug(f"Existing subscription: {existing_subscription}")
            if existing_subscription:
                existing_sub_plan, db_months = existing_subscription["sub_plan"], existing_subscription["months"]
                new_months = db_months + event_months
                if existing_sub_plan != sub_plan:
                    await cursor.execute('UPDATE subscription_data SET sub_plan = %s, months = %s WHERE user_id = %s', (sub_plan, new_months, user_id))
                    event_logger.info(f"Updated subscription plan for user_id: {user_id} to {sub_plan} with {new_months} months")
                else:
                    await cursor.execute('UPDATE subscription_data SET months = %s WHERE user_id = %s', (new_months, user_id))
                    event_logger.info(f"Updated subscription months for user_id: {user_id} to {new_months} months")
            else:
                await cursor.execute('INSERT INTO subscription_data (user_id, user_name, sub_plan, months) VALUES (%s, %s, %s, %s)', (user_id, user_name, sub_plan, event_months))
                event_logger.info(f"Inserted new subscription for user_id: {user_id}, sub_plan: {sub_plan}, months: {event_months}")
            # Insert stream credits data
            await cursor.execute('INSERT INTO stream_credits (username, event, data) VALUES (%s, %s, %s)', (user_name, "subscriptions", f"{sub_plan} - {event_months} months"))
            event_logger.debug(f"Inserted stream credits for user_name: {user_name}")
            # Retrieve bot settings
            settings = await get_point_settings()
            subscriber_points = int(settings.get('point_amount_subscriber', 0))
            subscriber_multiplier = int(settings.get('subscriber_multiplier', 1))
            subscriber_points *= subscriber_multiplier
            event_logger.debug(f"Subscriber points after multiplier: {subscriber_points}")
            # Fetch and update user points
            await cursor.execute("SELECT points FROM bot_points WHERE user_id = %s", (user_id,))
            result = await cursor.fetchone()
            current_points = result.get("points") if result else 0
            new_points = current_points + subscriber_points
            if result:
                await cursor.execute("UPDATE bot_points SET points = %s WHERE user_id = %s", (new_points, user_id))
                event_logger.info(f"Updated points for user_id: {user_id} to {new_points}")
            else:
                await cursor.execute("INSERT INTO bot_points (user_id, user_name, points) VALUES (%s, %s, %s)", (user_id, user_name, new_points))
                event_logger.info(f"Inserted new bot points for user_id: {user_id} with {new_points} points")
            await connection.commit()
            event_logger.info("Database changes committed successfully")
            # Add time to subathon based on sub_plan
            subathon_state = await get_subathon_state()
            if subathon_state and not subathon_state[4]:  # If subathon is running
                if sub_plan == 'Tier 1':
                    sub_add_time = int(settings['sub_add_1'])
                elif sub_plan == 'Tier 2':
                    sub_add_time = int(settings['sub_add_2'])
                elif sub_plan == 'Tier 3':
                    sub_add_time = int(settings['sub_add_3'])
                else:
                    sub_add_time = 0  # Default to 0 if no matching tier
                await addtime_subathon(CHANNEL_NAME, sub_add_time)  # Call to add time based on subscriptions
            # Send notification messages (skip for upgrades since they send their own message)
            if not is_upgrade:
                await cursor.execute("SELECT alert_message FROM twitch_chat_alerts WHERE alert_type = %s", ("subscription_alert",))
                result = await cursor.fetchone()
                if result and result.get("alert_message"):
                    alert_message = result.get("alert_message")
                else:
                    alert_message = "Thank you (user) for subscribing! You are now a (tier) subscriber for (months) months!"
                # Check if shoutout trigger is in the message
                send_shoutout = False
                shoutout_message = None
                if "(shoutout)" in alert_message:
                    send_shoutout = True
                    alert_message = alert_message.replace("(shoutout)", "")
                    shoutout_message = await get_shoutout_message(user_id, user_name, "subscription")
                alert_message = alert_message.replace("(user)", user_name).replace("(tier)", sub_plan).replace("(months)", str(event_months))
            try:
                create_task(websocket_notice(event="TWITCH_SUB", user=user_name, sub_tier=sub_plan, sub_months=event_months))
                event_logger.info("Sent WebSocket notice")
            except Exception as e:
                event_logger.error(f"Failed to send WebSocket notice: {e}")
            # Retrieve the channel object
            try:
                if alert_message.strip():
                    await send_chat_message(alert_message)
                if send_shoutout and shoutout_message:
                    await add_shoutout(user_name, user_id, is_automated=True)
                    await send_chat_message(shoutout_message)
                marker_description = f"New Subscription from {user_name}"
                if await make_stream_marker(marker_description):
                    twitch_logger.info(f"A stream marker was created: {marker_description}.")
                else:
                    twitch_logger.info("Failed to create a stream marker.")
            except Exception as e:
                event_logger.error(f"Failed to send message to channel {CHANNEL_NAME}: {e}")
            await cursor.execute("SELECT * FROM twitch_sound_alerts WHERE twitch_alert_id = %s", ("Subscription",))
            result = await cursor.fetchone()
            if result and result.get("sound_mapping"):
                sound_file = "twitch/" + result.get("sound_mapping")
                create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
    except Exception as e:
        event_logger.error(f"Error processing subscription event for user {user_name} ({user_id}): {e}")
    finally:
        pass

# Function for Resubscriptions with Messages
async def process_subscription_message_event(user_id, user_name, sub_plan, event_months, is_upgrade=False):
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            event_logger.info(f"Processing subscription message event for user_id: {user_id}, user_name: {user_name}")
            await cursor.execute('SELECT sub_plan, months FROM subscription_data WHERE user_id = %s', (user_id,))
            existing_subscription = await cursor.fetchone()
            event_logger.debug(f"Existing subscription: {existing_subscription}")
            if existing_subscription:
                existing_sub_plan, db_months = existing_subscription["sub_plan"], existing_subscription["months"]
                new_months = db_months + event_months
                if existing_sub_plan != sub_plan:
                    await cursor.execute('UPDATE subscription_data SET sub_plan = %s, months = %s WHERE user_id = %s', (sub_plan, new_months, user_id))
                    event_logger.info(f"Updated subscription plan for user_id: {user_id} to {sub_plan} with {new_months} months")
                else:
                    await cursor.execute('UPDATE subscription_data SET months = %s WHERE user_id = %s', (new_months, user_id))
                    event_logger.info(f"Updated subscription months for user_id: {user_id} to {new_months} months")
            else:
                await cursor.execute('INSERT INTO subscription_data (user_id, user_name, sub_plan, months) VALUES (%s, %s, %s, %s)', (user_id, user_name, sub_plan, event_months))
                event_logger.info(f"Inserted new subscription for user_id: {user_id}, sub_plan: {sub_plan}, months: {event_months}")
            # Insert stream credits data
            await cursor.execute('INSERT INTO stream_credits (username, event, data) VALUES (%s, %s, %s)', (user_name, "subscriptions", f"{sub_plan} - {event_months} months"))
            event_logger.debug(f"Inserted stream credits for user_name: {user_name}")
            # Retrieve bot settings
            settings = await get_point_settings()
            subscriber_points = int(settings.get('point_amount_subscriber', 0))
            subscriber_multiplier = int(settings.get('subscriber_multiplier', 1))
            subscriber_points *= subscriber_multiplier
            event_logger.debug(f"Subscriber points after multiplier: {subscriber_points}")
            # Fetch and update user points
            await cursor.execute("SELECT points FROM bot_points WHERE user_id = %s", (user_id,))
            result = await cursor.fetchone()
            current_points = result.get("points") if result else 0
            new_points = current_points + subscriber_points
            if result:
                await cursor.execute("UPDATE bot_points SET points = %s WHERE user_id = %s", (new_points, user_id))
                event_logger.info(f"Updated points for user_id: {user_id} to {new_points}")
            else:
                await cursor.execute("INSERT INTO bot_points (user_id, user_name, points) VALUES (%s, %s, %s)", (user_id, user_name, new_points))
                event_logger.info(f"Inserted new bot points for user_id: {user_id} with {new_points} points")
            await connection.commit()
            event_logger.info("Database changes committed successfully")
            # Add time to subathon based on sub_plan
            subathon_state = await get_subathon_state()
            if subathon_state and not subathon_state[4]:  # If subathon is running
                if sub_plan == 'Tier 1':
                    sub_add_time = int(settings['sub_add_1'])
                elif sub_plan == 'Tier 2':
                    sub_add_time = int(settings['sub_add_2'])
                elif sub_plan == 'Tier 3':
                    sub_add_time = int(settings['sub_add_3'])
                else:
                    sub_add_time = 0  # Default to 0 if no matching tier
                await addtime_subathon(CHANNEL_NAME, sub_add_time)  # Call to add time based on subscriptions
            # Send notification messages (skip for upgrades since they send their own message)
            if not is_upgrade:
                await cursor.execute("SELECT alert_message FROM twitch_chat_alerts WHERE alert_type = %s", ("subscription_alert",))
                result = await cursor.fetchone()
                if result and result.get("alert_message"):
                    alert_message = result.get("alert_message")
                else:
                    alert_message = "Thank you (user) for subscribing! You are now a (tier) subscriber for (months) months!"
                # Check if shoutout trigger is in the message
                send_shoutout = False
                shoutout_message = None
                if "(shoutout)" in alert_message:
                    send_shoutout = True
                    alert_message = alert_message.replace("(shoutout)", "")
                    shoutout_message = await get_shoutout_message(user_id, user_name, "subscription")
                alert_message = alert_message.replace("(user)", user_name).replace("(tier)", sub_plan).replace("(months)", str(event_months))
            try:
                create_task(websocket_notice(event="TWITCH_SUB", user=user_name, sub_tier=sub_plan, sub_months=event_months))
                event_logger.info("Sent WebSocket notice")
            except Exception as e:
                event_logger.error(f"Failed to send WebSocket notice: {e}")
            # Retrieve the channel object
            try:
                if alert_message.strip():
                    await send_chat_message(alert_message)
                if send_shoutout and shoutout_message:
                    await add_shoutout(user_name, user_id, is_automated=True)
                    await send_chat_message(shoutout_message)
                marker_description = f"New Subscription from {user_name}"
                if await make_stream_marker(marker_description):
                    twitch_logger.info(f"A stream marker was created: {marker_description}.")
                else:
                    twitch_logger.info("Failed to create a stream marker.")
            except Exception as e:
                event_logger.error(f"Failed to send message to channel {CHANNEL_NAME}: {e}")
            await cursor.execute("SELECT * FROM twitch_sound_alerts WHERE twitch_alert_id = %s", ("Subscription",))
            result = await cursor.fetchone()
            if result and result.get("sound_mapping"):
                sound_file = "twitch/" + result.get("sound_mapping")
                create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
    except Exception as e:
        event_logger.error(f"Error processing subscription message event for user {user_name} ({user_id}): {e}")
    finally:
        pass

# Function for Gift Subscriptions
async def process_giftsub_event(gifter_user_name, givent_sub_plan, number_gifts, anonymous, total_gifted, skip_alert=False):
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute('INSERT INTO stream_credits (username, event, data) VALUES (%s, %s, %s)', (gifter_user_name, "Gift Subscriptions", f"{number_gifts} - GIFT SUBSCRIPTIONS"))
            await connection.commit()
            # Skip alert message for special cases like pay_it_forward (which sends its own message)
            if not skip_alert:
                await cursor.execute("SELECT alert_message FROM twitch_chat_alerts WHERE alert_type = %s", ("gift_subscription_alert",))
                result = await cursor.fetchone()
                if result and result.get("alert_message"):
                    alert_message = result.get("alert_message")
                else:
                    alert_message = "Thank you (user) for gifting a (tier) subscription to (count) members! You have gifted a total of (total-gifted) to the community!"
                if anonymous:
                    giftsubfrom = "Anonymous"
                else:
                    giftsubfrom = gifter_user_name
                alert_message = alert_message.replace("(user)", giftsubfrom).replace("(count)", str(number_gifts)).replace("(tier)", givent_sub_plan).replace("(total-gifted)", str(total_gifted))
                await send_chat_message(alert_message)
                marker_description = f"New Gift Subs from {giftsubfrom}"
                if await make_stream_marker(marker_description):
                    twitch_logger.info(f"A stream marker was created: {marker_description}.")
                else:
                    twitch_logger.info("Failed to create a stream marker.")
                await cursor.execute("SELECT * FROM twitch_sound_alerts WHERE twitch_alert_id = %s", ("Gift Subscription",))
                result = await cursor.fetchone()
                if result and result.get("sound_mapping"):
                    sound_file = "twitch/" + result.get("sound_mapping")
                    create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
    finally:
        pass

# Function for FOLLOWERS
async def process_followers_event(user_id, user_name):
    try:
        connection = await mysql_handler.get_connection()
        time_now = time_right_now()
        followed_at = time_now.strftime("%Y-%m-%d %H:%M:%S")
        async with connection.cursor(DictCursor) as cursor:
            # Insert follower data
            await cursor.execute(
                'INSERT INTO followers_data (user_id, user_name, followed_at) VALUES (%s, %s, %s)',
                (user_id, user_name, followed_at)
            )
            await cursor.execute(
                'INSERT INTO stream_credits (username, event, data) VALUES (%s, %s, %s)',
                (user_name, "follow", 0)
            )
            # Retrieve the bot settings to get the follower points amount
            settings = await get_point_settings()
            follower_points = settings['follower_points']
            # Fetch current points for the user
            await cursor.execute("SELECT points FROM bot_points WHERE user_id = %s", (user_id,))
            result = await cursor.fetchone()
            current_points = result.get("points") if result else 0
            # Update the user's points based on the follow event
            new_points = current_points + follower_points
            if result:
                await cursor.execute("UPDATE bot_points SET points = %s WHERE user_id = %s", (new_points, user_id))
            else:
                await cursor.execute(
                    "INSERT INTO bot_points (user_id, user_name, points) VALUES (%s, %s, %s)",
                    (user_id, user_name, new_points)
                )
            await connection.commit()
            # Send follow notification to Twitch Chat and Websocket
            await cursor.execute("SELECT alert_message FROM twitch_chat_alerts WHERE alert_type = %s", ("follower_alert",))
            result = await cursor.fetchone()
            if result and result.get("alert_message"):
                alert_message = result.get("alert_message")
            else:
                alert_message = "Thank you (user) for following! Welcome to the channel!"
            # Check if shoutout trigger is in the message
            send_shoutout = False
            shoutout_message = None
            if "(shoutout)" in alert_message:
                send_shoutout = True
                alert_message = alert_message.replace("(shoutout)", "")
                shoutout_message = await get_shoutout_message(user_id, user_name, "follow")
            alert_message = alert_message.replace("(user)", user_name)
            if alert_message.strip():
                await send_chat_message(alert_message)
            if send_shoutout and shoutout_message:
                await add_shoutout(user_name, user_id, is_automated=True)
                await send_chat_message(shoutout_message)
            create_task(websocket_notice(event="TWITCH_FOLLOW", user=user_name))
            marker_description = f"New Twitch Follower: {user_name}"
            if await make_stream_marker(marker_description):
                twitch_logger.info(f"A stream marker was created: {marker_description}.")
            else:
                twitch_logger.info("Failed to create a stream marker.")
            await cursor.execute("SELECT * FROM twitch_sound_alerts WHERE twitch_alert_id = %s", ("Follow",))
            result = await cursor.fetchone()
            if result and result.get("sound_mapping"):
                sound_file = "twitch/" + result.get("sound_mapping")
                create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
    finally:
        pass

# Function to ban a user
async def ban_user(username, user_id, use_streamer=False):
    # Connect to the database
    try:
        connection = await mysql_handler.get_connection(db_name="website")
        async with connection.cursor(DictCursor) as cursor:
            # Determine which user ID to use for the API request
            api_user_id = CHANNEL_ID if use_streamer else "971436498"
            # Fetch settings from the twitch_bot_access table
            await cursor.execute("SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = %s LIMIT 1", (api_user_id,))
            result = await cursor.fetchone()
            # Use the token from the database if found, otherwise default to CHANNEL_AUTH
            api_user_auth = result.get('twitch_access_token') if result else CHANNEL_AUTH
            # Construct the ban URL using the selected API user
            ban_url = f"https://api.twitch.tv/helix/moderation/bans?broadcaster_id={CHANNEL_ID}&moderator_id={api_user_id}"
            headers = {
                "Client-ID": CLIENT_ID,
                "Authorization": f"Bearer {api_user_auth}",
                "Content-Type": "application/json",
            }
            data = {
                "data": {
                    "user_id": user_id,
                    "reason": "Spam/Bot Account",
                }
            }
            # Perform the ban request
            async with httpClientSession() as session:
                async with session.post(ban_url, headers=headers, json=data) as response:
                    if response.status == 200:
                        twitch_logger.info(f"{username} has been banned for sending a spam message in chat.")
                    else:
                        error_text = await response.text()
                        twitch_logger.error(f"Failed to ban user: {username}. Status Code: {response.status}, Response: {error_text}")
    finally:
        pass

# Unified function to connect to the websocket server and push notices
async def websocket_notice(
    event, user=None, death=None, game=None, weather=None, cheer_amount=None,
    sub_tier=None, sub_months=None, raid_viewers=None, text=None, sound=None,
    video=None, additional_data=None, rewards_data=None
):
    # Check if websocket is connected before sending notifications
    if not is_websocket_connected():
        websocket_logger.error(f"Cannot send event '{event}' - websocket is not connected to internal system")
        return
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            async with httpClientSession() as session:
                params = {
                    'code': API_TOKEN,
                    'event': event
                }
                # Event-specific parameter handling
                if event == "WALKON" and user:
                    found = False
                    # Check for supported walkon file types (audio and video) on WEB server via SSH
                    for ext in ['.mp3', '.mp4']:
                        walkon_file_path = f"/var/www/walkons/{CHANNEL_NAME}/{user}{ext}"
                        try:
                            if await ssh_manager.file_exists('WEB', walkon_file_path):
                                params['channel'] = CHANNEL_NAME
                                params['user'] = user
                                params['ext'] = ext
                                websocket_logger.info(f"WALKON triggered for {user}: found file {walkon_file_path} on WEB server")
                                found = True
                                break
                        except Exception as e:
                            websocket_logger.error(f"Error checking walkon file {walkon_file_path} on WEB server: {e}")
                    if not found:
                        websocket_logger.error(f"WALKON triggered for {user}, but no walk-on file found in /var/www/walkons/{CHANNEL_NAME}/ on WEB server")
                elif event == "DEATHS" and death and game:
                    params['death-text'] = death
                    params['game'] = game
                elif event in ["STREAM_ONLINE", "STREAM_OFFLINE"]:
                    pass  # No additional parameters needed
                elif event == "WEATHER" and weather:
                    params['location'] = weather
                elif event == "TWITCH_FOLLOW" and user:
                    params['twitch-username'] = user
                elif event == "TWITCH_CHEER" and user and cheer_amount:
                    params['twitch-username'] = user
                    params['twitch-cheer-amount'] = cheer_amount
                elif event == "TWITCH_SUB" and user and sub_tier and sub_months:
                    params['twitch-username'] = user
                    params['twitch-tier'] = sub_tier
                    params['twitch-sub-months'] = sub_months
                elif event == "TWITCH_RAID" and user and raid_viewers:
                    params['twitch-username'] = user
                    params['twitch-raid'] = raid_viewers
                elif event == "TWITCH_CHANNELPOINTS" and rewards_data:
                    params['rewards'] = json.dumps(rewards_data)
                elif event == "TTS" and text:
                    # Make a database query to fetch additional information for TTS
                    try:
                        query = "SELECT voice, language FROM tts_settings LIMIT 1"
                        await cursor.execute(query)
                        result = await cursor.fetchone()
                        if result:
                            params['voice'] = result.get('voice', 'default')
                            params['language'] = result.get('language', 'en')
                        else:
                            params['voice'] = 'default'
                            params['language'] = 'en'
                    except MySQLOtherErrors as e:
                        websocket_logger.error(f"Database error while fetching TTS settings for the channel: {e}")
                        params['voice'] = 'default'
                        params['language'] = 'en'
                    params['text'] = text
                elif event in ["SUBATHON_START", "SUBATHON_STOP", "SUBATHON_PAUSE", "SUBATHON_RESUME", "SUBATHON_ADD_TIME"]:
                    if additional_data:
                        params.update(additional_data)
                    else:
                        websocket_logger.error(f"Event '{event}' requires additional parameters.")
                        return
                elif event == "SEND_OBS_EVENT":
                    if additional_data:
                        params.update(additional_data)
                    else:
                        websocket_logger.error(f"Event '{event}' requires additional parameters.")
                        return
                elif event == "RAFFLE_WINNER" and additional_data:
                    # Expect additional_data to contain raffle_name and winner (or caller may pass via additional_data)
                    params['channel'] = CHANNEL_NAME
                    params['raffle_name'] = additional_data.get('raffle_name')
                    params['winner'] = additional_data.get('winner')
                elif event == "SOUND_ALERT" and sound:
                    params['sound'] = f"https://soundalerts.botofthespecter.com/{CHANNEL_NAME}/{sound}"
                elif event == "VIDEO_ALERT" and video:
                    params['video'] = f"https://videoalerts.botofthespecter.com/{CHANNEL_NAME}/{video}"
                elif event == "MODERATION":
                    if additional_data:
                        params.update(additional_data)
                    else:
                        websocket_logger.error(f"Event '{event}' requires additional parameters.")
                        return
                else:
                    websocket_logger.error(f"Event '{event}' requires additional parameters or is not recognized")
                    return
                # URL-encode the parameters
                encoded_params = urlencode(params)
                url = f'https://websocket.botofthespecter.com/notify?{encoded_params}'
                # Send the HTTP request
                async with session.get(url) as response:
                    if response.status == 200:
                        websocket_logger.info(f"HTTP event '{event}' sent successfully with params: {params}")
                    else:
                        websocket_logger.error(f"Failed to send HTTP event '{event}'. Status: {response.status}")
    except Exception as e:
        websocket_logger.error(f"Error while processing websocket notice: {e}")
    except asyncioCancelledError:
        bot_logger.info('check_stream_online task was cancelled')
        # attempt to clean up any spawned looped tasks related to streaming
        try:
            if looped_tasks.get("timed_message"):
                looped_tasks["timed_message"].cancel()
        except Exception:
            pass
        try:
            if looped_tasks.get("handle_upcoming_ads"):
                looped_tasks["handle_upcoming_ads"].cancel()
        except Exception:
            pass
        raise
    except GeneratorExit:
        bot_logger.info('check_stream_online received GeneratorExit during shutdown')
        raise
    except Exception as e:
        bot_logger.error(f"Error in check_stream_online: {e}")
    finally:
        pass

# Function to create the command in the database if it doesn't exist
async def builtin_commands_creation():
    all_commands = list(mod_commands) + list(builtin_commands)
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            # Create placeholders for the query
            placeholders = ', '.join(['%s'] * len(all_commands))
            # Construct the query string with the placeholders
            query = f"SELECT command, permission FROM builtin_commands WHERE command IN ({placeholders})"
            # Execute the query with the tuple of all commands
            await cursor.execute(query, tuple(all_commands))
            # Fetch the existing commands from the database
            existing_commands = await cursor.fetchall()
            existing_command_dict = {row["command"]: row["permission"] for row in existing_commands}
            # Filter out existing commands
            new_commands = [command for command in all_commands if command not in existing_command_dict]
            # Check for commands with NULL permission and update accordingly
            commands_to_update = []
            for command in existing_command_dict:
                permission = existing_command_dict[command]
                if permission is None:
                    new_permission = 'mod' if command in mod_commands else 'everyone'
                    commands_to_update.append((new_permission, command))
            # Update NULL permissions
            if commands_to_update:
                update_query = "UPDATE builtin_commands SET permission = %s WHERE command = %s"
                await cursor.executemany(update_query, commands_to_update)
                await connection.commit()
                for command in commands_to_update:
                    bot_logger.info(f"Command '{command[1]}' updated with permission '{command[0]}.'")
            # Insert new commands with their permissions
            if new_commands:
                values = []
                for command in new_commands:
                    # Determine permission type
                    permission = 'mod' if command in mod_commands else 'everyone'
                    values.append((command, 'Enabled', permission))
                # Insert query with placeholders for each command
                insert_query = "INSERT INTO builtin_commands (command, status, permission) VALUES (%s, %s, %s)"
                await cursor.executemany(insert_query, values)  # Use executemany here
                await connection.commit()
                for command in new_commands:
                    bot_logger.info(f"Command '{command}' added to database successfully.")
    except MySQLOtherErrors as e:
        bot_logger.error(f"builtin_commands_creation function error: {e}")
    finally:
        pass

# Function to tell the website what version of the bot is currently running
async def update_version_control():
    global SYSTEM, VERSION, CHANNEL_NAME
    try:
        # Define the directory path
        directory = "/home/botofthespecter/logs/version/"
        beta_directory = "/home/botofthespecter/logs/version/beta/"
        custom_directory = "/home/botofthespecter/logs/version/custom/"
        # Ensure the directory exists, create it if it doesn't
        if not os.path.exists(directory):
            os.makedirs(directory)
        if not os.path.exists(beta_directory):
            os.makedirs(beta_directory)
        if not os.path.exists(custom_directory):
            os.makedirs(custom_directory)
        # Determine file name based on SYSTEM value
        if SYSTEM == "STABLE":
            file_name = f"{CHANNEL_NAME}_version_control.txt"
            directory = "/home/botofthespecter/logs/version/"
            # Define the full file path
            file_path = os.path.join(directory, file_name)
        elif SYSTEM == "BETA":
            file_name = f"{CHANNEL_NAME}_beta_version_control.txt"
            directory = "/home/botofthespecter/logs/version/beta/"
            # Define the full file path
            file_path = os.path.join(directory, file_name)
        elif SYSTEM == "CUSTOM":
            file_name = f"{CHANNEL_NAME}_custom_version_control.txt"
            directory = "/home/botofthespecter/logs/version/custom/"
            # Define the full file path
            file_path = os.path.join(directory, file_name)
        else:
            raise ValueError("Invalid SYSTEM value. Expected STABLE, BETA, or CUSTOM.")
        # Delete the file if it exists
        if os.path.exists(file_path):
            os.remove(file_path)
        # Write the new version to the file
        with open(file_path, "w") as file:
            file.write(VERSION)
        bot_logger.info(f"Version control updated")
    except Exception as e:
        bot_logger.error(f"An error occurred in update_version_control: {e}")

async def wait_and_persist_outgoing_raid():
    global pending_outgoing_raid, outgoing_raid_task
    try:
        # If the stream is currently live, wait until it ends (polling stream_online)
        while stream_online:
            await sleep(5)
        # Stream is now offline (or was already); persist if we still have a pending outgoing raid
        if not pending_outgoing_raid:
            return
        target = pending_outgoing_raid.get('target')
        viewers_sent = pending_outgoing_raid.get('viewers', 0)
        try:
            user_conn = await mysql_handler.get_connection(db_name=CHANNEL_NAME)
            async with user_conn.cursor(DictCursor) as user_cursor:
                await user_cursor.execute("INSERT INTO analytic_raids (raider_name, viewers, source, created_at) VALUES (%s, %s, %s, NOW())", (target, viewers_sent, 'sent'))
                await user_conn.commit()
        except Exception as e:
            twitch_logger.error(f"Failed to save sent raid analytics for channel {CHANNEL_NAME}: {e}")
    except asyncioCancelledError:
        event_logger.info("Outgoing raid persistence task cancelled")
    finally:
        pending_outgoing_raid = None
        outgoing_raid_task = None

async def check_stream_online():
    global stream_online, current_game, stream_title, CLIENT_ID, CHANNEL_AUTH, CHANNEL_NAME, CHANNEL_ID
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            async with httpClientSession() as session:
                headers = {
                    'Client-ID': CLIENT_ID,
                    'Authorization': f'Bearer {CHANNEL_AUTH}'
                }
                async with session.get(f'https://api.twitch.tv/helix/streams?user_login={CHANNEL_NAME}&type=live', headers=headers) as response:
                    data = await response.json()
                    # Check if the stream is offline
                    if not data.get('data'):
                        stream_online = False
                        # Stop all timed messages when stream goes offline
                        await stop_all_timed_messages()
                        # Log the status to the file
                        os.makedirs(f'/home/botofthespecter/logs/online', exist_ok=True)
                        with open(f'/home/botofthespecter/logs/online/{CHANNEL_NAME}.txt', 'w') as file:
                            file.write('False')
                        await cursor.execute("UPDATE stream_status SET status = %s", ("False",))
                        bot_logger.info(f"Bot Starting, Stream is offline.")
                        # When offline, call channels to get the set game and title
                        async with session.get(f"https://api.twitch.tv/helix/channels?broadcaster_id={CHANNEL_ID}", headers=headers) as channel_response:
                            channel_data = await channel_response.json()
                            if channel_data.get('data'):
                                current_game = channel_data['data'][0].get('game_name', None)
                                stream_title = channel_data['data'][0].get('title', None)
                    else:
                        stream_online = True
                        # Extract game and title from streams data
                        stream_data = data['data'][0]
                        current_game = stream_data.get('game_name', None)
                        stream_title = stream_data.get('title', None)
                        looped_tasks["timed_message"] = create_task(timed_message())
                        looped_tasks["handle_upcoming_ads"] = create_task(handle_upcoming_ads())
                        # Log the status to the file
                        os.makedirs(f'/home/botofthespecter/logs/online', exist_ok=True)
                        with open(f'/home/botofthespecter/logs/online/{CHANNEL_NAME}.txt', 'w') as file:
                            file.write('True')
                        await cursor.execute("UPDATE stream_status SET status = %s", ("True",))
                        bot_logger.info(f"Bot Starting, Stream is online.")
                await connection.commit()
    finally:
        pass

async def get_current_game():
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID, current_game
    url = f"https://api.twitch.tv/helix/channels?broadcaster_id={CHANNEL_ID}"
    headers = {"Client-Id": CLIENT_ID, "Authorization": f"Bearer {CHANNEL_AUTH}"}
    try:
        async with httpClientSession() as session:
            async with session.get(url, headers=headers) as response:
                if response.status == 200:
                    data = await response.json()
                    if data['data']:
                        game_name = data['data'][0]['game_name']
                        current_game = game_name
                        return game_name
                    else:
                        api_logger.info("Stream is offline or no game data available")
                        return "Offline"
                else:
                    api_logger.error(f"Failed to fetch stream data: {response.status}")
                    return "Error"
    except Exception as e:
        api_logger.error(f"Error fetching current game: {e}")
        return "Error"

async def convert_currency(amount, from_currency, to_currency):
    global EXCHANGE_RATE_API_KEY
    url = f"https://v6.exchangerate-api.com/v6/{EXCHANGE_RATE_API_KEY}/pair/{from_currency}/{to_currency}/{amount}"
    try:
        async with httpClientSession() as session:
            async with session.get(url) as response:
                response.raise_for_status()
                data = await response.json()
                if data['result'] == "success":
                    converted_amount = data['conversion_result']
                    api_logger.info(f"Converted {amount} {from_currency} to {converted_amount:.2f} {to_currency}")
                    # Update API usage count in database
                    try:
                        connection = await mysql_handler.get_connection(db_name="website")
                        async with connection.cursor(DictCursor) as cursor:
                            # Get current count
                            await cursor.execute("SELECT count FROM api_counts WHERE type = %s", ("exchangerate",))
                            result = await cursor.fetchone()
                            if result:
                                remaining_requests = int(result['count']) - 1
                                # Update the count
                                await cursor.execute("UPDATE api_counts SET count = %s WHERE type = %s", (remaining_requests, "exchangerate"))
                                await connection.commit()
                                api_logger.info(f"Exchangerate API Requests Remaining: {remaining_requests}")
                            else:
                                api_logger.error("No exchangerate count found in api_counts table")
                    except Exception as e:
                        api_logger.error(f"Error updating API count: {e}")
                    finally:
                        pass
                    return converted_amount
                else:
                    error_message = data.get('error-type', 'Unknown error')
                    api_logger.error(f"convert_currency Error: {error_message}")
                    sanitized_error = str(error_message).replace(EXCHANGE_RATE_API_KEY, '[EXCHANGE_RATE_API_KEY]')
                    raise ValueError(f"Currency conversion failed: {sanitized_error}")
    except aiohttpClientError as e:
        sanitized_error = str(e).replace(EXCHANGE_RATE_API_KEY, '[EXCHANGE_RATE_API_KEY]')
        api_logger.error(f"Failed to convert {amount} {from_currency} to {to_currency}. Error: {sanitized_error}")
        raise

# Channel Point Rewards Proccessing
async def process_channel_point_rewards(event_data, event_type):
    connection = await mysql_handler.get_connection()
    async with connection.cursor(DictCursor) as cursor:
        try:
            user_name = event_data["user_name"]
            user_id = event_data["user_id"]
            reward_data = event_data.get("reward", {})
            reward_id = reward_data.get("id")
            reward_title = reward_data.get("title" if event_type.endswith(".add") else "type")
            create_task(websocket_notice(event="TWITCH_CHANNELPOINTS", rewards_data=event_data))
            # Custom message handling
            await cursor.execute("SELECT custom_message FROM channel_point_rewards WHERE reward_id = %s", (reward_id,))
            custom_message_result = await cursor.fetchone()
            if custom_message_result and custom_message_result["custom_message"]:
                custom_message = custom_message_result.get("custom_message")
                if custom_message:
                    # Apply all replacements in a loop until no more variables are found
                    max_iterations = 8
                    iteration = 0
                    vars_to_replace = ['(user)', '(usercount)', '(userstreak)', '(track)', '(tts)', '(lotto)', '(fortune)', '(vip)', '(vip.today)', '(customapi.']
                    while iteration < max_iterations:
                        iteration += 1
                        has_vars = any(var in custom_message for var in vars_to_replace if var != '(customapi.')
                        has_customapi = '(customapi.' in custom_message
                        if not (has_vars or has_customapi):
                            break
                        # Re-populate replacements for this iteration
                        replacements = {}
                        # Handle (user)
                        if '(user)' in custom_message:
                            replacements['(user)'] = user_name
                        # Handle (usercount)
                        if '(usercount)' in custom_message:
                            try:
                                await cursor.execute('SELECT count FROM reward_counts WHERE reward_id = %s AND user = %s', (reward_id, user_name))
                                result = await cursor.fetchone()
                                if result:
                                    user_count = result.get("count")
                                else:
                                    user_count = 0
                                    await cursor.execute('INSERT INTO reward_counts (reward_id, user, count) VALUES (%s, %s, %s)', (reward_id, user_name, user_count))
                                    await connection.commit()
                                user_count += 1
                                await cursor.execute('UPDATE reward_counts SET count = %s WHERE reward_id = %s AND user = %s', (user_count, reward_id, user_name))
                                await connection.commit()
                                replacements['(usercount)'] = str(user_count)
                            except Exception as e:
                                chat_logger.error(f"Error while handling (usercount): {e}")
                                replacements['(usercount)'] = "Error"
                        # Handle (userstreak)
                        if '(userstreak)' in custom_message:
                            try:
                                await cursor.execute("SELECT `current_user`, streak FROM reward_streaks WHERE reward_id = %s", (reward_id,))
                                streak_row = await cursor.fetchone()
                                if streak_row:
                                    current_user_from_db = streak_row['current_user']
                                    current_streak = streak_row['streak']
                                    if current_user_from_db and current_user_from_db.lower() == user_name.lower():
                                        current_user = user_name
                                        current_streak += 1
                                    else:
                                        current_user = user_name
                                        current_streak = 1
                                    await cursor.execute("UPDATE reward_streaks SET `current_user` = %s, streak = %s WHERE reward_id = %s", (current_user, current_streak, reward_id))
                                else:
                                    current_user = user_name
                                    current_streak = 1
                                    await cursor.execute("INSERT INTO reward_streaks (reward_id, `current_user`, streak) VALUES (%s, %s, %s)", (reward_id, current_user, current_streak))
                                await connection.commit()
                                replacements['(userstreak)'] = str(current_streak)
                            except Exception as e:
                                chat_logger.error(f"Error while handling (userstreak): {e}\n{traceback.format_exc()}")
                                replacements['(userstreak)'] = "Error"
                        # Handle (track)
                        if '(track)' in custom_message:
                            try:
                                await cursor.execute("UPDATE channel_point_rewards SET usage_count = COALESCE(usage_count, 0) + 1 WHERE reward_id = %s", (reward_id,))
                                await connection.commit()
                                replacements['(track)'] = ''
                            except Exception as e:
                                chat_logger.error(f"Error while handling (track): {e}")
                                replacements['(track)'] = ''
                        # Handle (customapi.)
                        if '(customapi.' in custom_message:
                            pattern = r'\(customapi\.(\S+?)\)'
                            matches = re.finditer(pattern, custom_message)
                            for match in matches:
                                full_placeholder = match.group(0)
                                url = match.group(1)
                                json_flag = False
                                if url.startswith('json.'):
                                    json_flag = True
                                    url = url[5:]
                                api_response = await fetch_api_response(url, json_flag=json_flag)
                                replacements[full_placeholder] = api_response
                        # Handle (tts)
                        if '(tts)' in custom_message:
                            tts_message = event_data.get("user_input", "")
                            create_task(websocket_notice(event="TTS", text=tts_message))
                            replacements['(tts)'] = ""
                        # Handle (lotto)
                        if '(lotto)' in custom_message:
                            winning_numbers_str = await generate_user_lotto_numbers(user_name)
                            if isinstance(winning_numbers_str, dict) and 'error' in winning_numbers_str:
                                replacements['(lotto)'] = f"Error: {winning_numbers_str['error']}"
                            else:
                                replacements['(lotto)'] = winning_numbers_str
                        # Handle (fortune)
                        if '(fortune)' in custom_message:
                            fortune_message = await tell_fortune()
                            fortune_message = fortune_message[0].lower() + fortune_message[1:]
                            replacements['(fortune)'] = fortune_message
                        # Handle (vip) and (vip.today) - grant VIP via Twitch Helix API
                        if '(vip)' in custom_message or '(vip.today)' in custom_message:
                            try:
                                # Make POST to add VIP
                                async with httpClientSession() as session:
                                    headers_vip = {
                                        'Client-Id': CLIENT_ID,
                                        'Authorization': f'Bearer {CHANNEL_AUTH}'
                                    }
                                    params_vip = {'broadcaster_id': CHANNEL_ID, 'user_id': user_id}
                                    add_vip_url = 'https://api.twitch.tv/helix/channels/vips'
                                    async with session.post(add_vip_url, headers=headers_vip, params=params_vip) as vip_resp:
                                        if vip_resp.status == 204:
                                            replacements['(vip)'] = ''
                                            replacements['(vip.today)'] = ''
                                            # If vip.today, record the user so we can remove at stream end
                                            if '(vip.today)' in custom_message:
                                                try:
                                                    await cursor.execute("INSERT INTO vip_today (user_id, username) VALUES (%s, %s) ON DUPLICATE KEY UPDATE username = VALUES(username)", (user_id, user_name))
                                                    await connection.commit()
                                                except Exception as _e:
                                                    chat_logger.error(f"Failed to record vip_today for {user_name}: {_e}")
                                            # Notify chat of success (only if the placeholder stands alone / developer can customize message)
                                            create_task(websocket_notice(event='VIP_ADDED', user=user_name))
                                        else:
                                            txt = await vip_resp.text()
                                            chat_logger.error(f"Failed to add VIP for {user_name}: {vip_resp.status} {txt}")
                                            replacements['(vip)'] = ''
                                            replacements['(vip.today)'] = ''
                                            # Inform chat of failure
                                            create_task(send_chat_message(f"@{user_name} I couldn't grant VIP (Twitch API returned {vip_resp.status})."))
                            except Exception as e:
                                chat_logger.error(f"Error while handling (vip)/(vip.today): {e}")
                                replacements['(vip)'] = ''
                                replacements['(vip.today)'] = ''
                        # Apply all replacements
                        for var, value in replacements.items():
                            if value is None:
                                value = ""
                            custom_message = custom_message.replace(var, str(value))
                    # Only send message if it's not empty after replacements
                    if custom_message.strip():
                        # Check for (tts.message) which sends the final message to both TTS and chat
                        if '(tts.message)' in custom_message:
                            custom_message = custom_message.replace('(tts.message)', '')
                            await send_chat_message(custom_message)
                            create_task(websocket_notice(event="TTS", text=custom_message))
                        else:
                            await send_chat_message(custom_message)
            # Sound alert logic
            await cursor.execute("SELECT sound_mapping FROM sound_alerts WHERE reward_id = %s", (reward_id,))
            sound_result = await cursor.fetchone()
            if sound_result and sound_result["sound_mapping"]:
                sound_file = sound_result.get("sound_mapping")
                event_logger.info(f"Got {event_type} - Found Sound Mapping - {reward_id} - {sound_file}")
                create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
            # Video alert logic
            await cursor.execute("SELECT video_mapping FROM video_alerts WHERE reward_id = %s", (reward_id,))
            video_result = await cursor.fetchone()
            if video_result and video_result["video_mapping"]:
                video_file = video_result.get("video_mapping")
                event_logger.info(f"Got {event_type} - Found Video Mapping - {reward_id} - {video_file}")
                create_task(websocket_notice(event="VIDEO_ALERT", video=video_file))
        except Exception as e:
            event_logger.error(f"An error occurred while processing the reward: {str(e)}")

async def channel_point_rewards():
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
    # Check the broadcaster's type
    rewards_processed = 0
    user_api_url = f"https://api.twitch.tv/helix/users?id={CHANNEL_ID}"
    headers = {"Client-Id": CLIENT_ID,"Authorization": f"Bearer {CHANNEL_AUTH}"}
    try:
        # Get MySQL connection
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            async with httpClientSession() as session:
                # Fetch broadcaster info
                async with session.get(user_api_url, headers=headers) as user_response:
                    if user_response.status == 200:
                        user_data = await user_response.json()
                        broadcaster_type = user_data["data"][0].get("broadcaster_type", "")
                        if broadcaster_type not in ["affiliate", "partner"]:
                            api_logger.info(f"Broadcaster type '{broadcaster_type}' does not support channel points. Exiting.")
                            return
                    else:
                        api_logger.error(f"Failed to fetch broadcaster info: {user_response.status} {user_response.reason}")
                        return
                # If the broadcaster is an affiliate or partner, proceed with fetching rewards
                api_url = f"https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={CHANNEL_ID}"
                async with session.get(api_url, headers=headers) as response:
                    if response.status == 200:
                        data = await response.json()
                        rewards = data.get("data", [])
                        for reward in rewards:
                            rewards_processed += 1
                            reward_id = reward.get("id")
                            reward_title = reward.get("title")
                            reward_cost = reward.get("cost")
                            # Insert or update the reward in the database
                            await cursor.execute(
                                "INSERT INTO channel_point_rewards (reward_id, reward_title, reward_cost) "
                                "VALUES (%s, %s, %s) AS new "
                                "ON DUPLICATE KEY UPDATE reward_title = new.reward_title, reward_cost = new.reward_cost",
                                (reward_id, reward_title, reward_cost)
                            )
                        api_logger.info(f"Rewards processed successfully. {rewards_processed} rewards processed.")
                    else:
                        api_logger.error(f"Failed to fetch rewards: {response.status} {response.reason}")
        if connection:
            await connection.commit()
    except Exception as e:
        api_logger.error(f"An error occurred in channel_point_rewards: {str(e)}")
    finally:
        pass

async def generate_winning_lotto_numbers():
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute("SELECT winning_numbers, supplementary_numbers FROM stream_lotto_winning_numbers")
            result = await cursor.fetchone()
            if result:
                winning_numbers = result.get("winning_numbers")
                supplementary_numbers = result.get("supplementary_numbers")
                return "exists"
            # Draw 7 winning numbers and 3 supplementary numbers from 1-47
            all_numbers = random.sample(range(1, 48), 9)
            winning_str = ', '.join(map(str, all_numbers[:6]))
            supplementary_str = ', '.join(map(str, all_numbers[6:]))
            winning_numbers = winning_str
            supplementary_numbers = supplementary_str
            await cursor.execute(
                "INSERT INTO stream_lotto_winning_numbers (winning_numbers, supplementary_numbers) VALUES (%s, %s)",
                (winning_numbers, supplementary_numbers)
                )
            await connection.commit()
        return True
    except MySQLOtherErrors as e:
        api_logger.error(f"An error occurred in generate_winning_lotto_numbers: {str(e)}")

# Function to generate random Lotto numbers
async def generate_user_lotto_numbers(user_name):
    user_name = user_name.lower()
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            # Check if there are winning numbers in the database
            await cursor.execute("SELECT winning_numbers, supplementary_numbers FROM stream_lotto_winning_numbers")
            game_running = await cursor.fetchone()
            # Check if the user has already played
            await cursor.execute("SELECT username FROM stream_lotto WHERE username = %s", (user_name,))
            user_exists = await cursor.fetchone()
            if user_exists:
                return {"error": "you've already played the lotto, please wait until the next round."}
            # If there are no winning numbers, generate them
            if not game_running:
                done = await generate_winning_lotto_numbers()
                if done == True:
                    await cursor.execute("SELECT winning_numbers, supplementary_numbers FROM stream_lotto_winning_numbers")
                    game_running = await cursor.fetchone()
                if not game_running:
                    return {"error": "you can't play lotto as the winning numbers haven't been selected yet."}
            # Draw the numbers if the game is running
            all_numbers = random.sample(range(1, 48), 9)
            # Combine both sets of numbers into one string
            winning_numbers = ', '.join(map(str, all_numbers[:6]))
            supplementary_numbers = ', '.join(map(str, all_numbers[6:]))
            all_numbers_str = f"Winning Numbers: {winning_numbers} Supplementary Numbers: {supplementary_numbers}"
            # Insert the user's numbers into the database
            await cursor.execute(
                "INSERT INTO stream_lotto (username, winning_numbers, supplementary_numbers) VALUES (%s, %s, %s)",
                (user_name, winning_numbers, supplementary_numbers)
            )
            await connection.commit()
            return all_numbers_str
    except MySQLOtherErrors as e:
        api_logger.error(f"An error occurred in generate_user_lotto_numbers: {str(e)}")
        return {"error": "An error occurred while generating your lotto numbers."}

# Function to fetch a random fortune
async def tell_fortune():
    url = f"https://api.botofthespecter.com/fortune?api_key={API_TOKEN}"
    async with httpClientSession() as session:
        async with session.get(url) as response:
            if response.status == 200:
                fortune_data = await response.json()
                if fortune_data and "fortune" in fortune_data:
                    # Return the fetched fortune
                    api_logger.info(f'API - BotOfTheSpecter - Fortune - {fortune_data["fortune"]}')
                    return fortune_data["fortune"]
            return "Unable to retrieve your fortune at this time."

# Functions for the ToDo List
# ToDo List Function - Add Task
async def add_task(ctx, params, user_id, connection):
    user = ctx.author
    async with connection.cursor(DictCursor) as cursor:
        if params:
            try:
                task_and_category = params[0].strip().split('"')
                task_description = task_and_category[1].strip()
                category_id = int(task_and_category[2].strip()) if len(task_and_category) > 2 and task_and_category[2].strip() else 1
                await cursor.execute("INSERT INTO todos (objective, category) VALUES (%s, %s)", (task_description, category_id))
                task_id = cursor.lastrowid
                await connection.commit()
                category_name = await fetch_category_name(cursor, category_id)
                await send_chat_message(f'{user.name}, your task "{task_description}" ID {task_id} has been added to category "{category_name or ("Unknown" if category_name is None else category_name)}".')
                chat_logger.info(f"{user.name} added a task: '{task_description}' in category: '{category_name or 'Unknown'}' with ID {task_id}.")
            except (ValueError, IndexError):
                await send_chat_message(f"{user.name}, please provide a valid task description and optional category ID.")
                chat_logger.error(f"{user.name} provided invalid task description or category ID for adding a task.")
        else:
            await send_chat_message(f"{user.name}, please provide a task to add.")
            chat_logger.error(f"{user.name} did not provide any task to add.")

# ToDo List Function - Edit Task
async def edit_task(ctx, params, user_id, connection):
    user = ctx.author
    async with connection.cursor(DictCursor) as cursor:
        if params:
            try:
                todo_id_str, new_task = params[0].split(',', 1)
                todo_id = int(todo_id_str.strip())
                new_task = new_task.strip()
                await cursor.execute("UPDATE todos SET objective = %s WHERE id = %s", (new_task, todo_id))
                if cursor.rowcount == 0:
                    await send_chat_message(f"{user.name}, task ID {todo_id} does not exist.")
                    chat_logger.error(f"{user.name} tried to edit non-existing task ID {todo_id}.")
                else:
                    await connection.commit()
                    await send_chat_message(f"{user.name}, task {todo_id} has been updated to \"{new_task}\".")
                    chat_logger.info(f"{user.name} edited task ID {todo_id} to new task: '{new_task}'.")
            except ValueError:
                await send_chat_message(f"{user.name}, please provide the task ID and new description separated by a comma.")
                chat_logger.error(f"{user.name} provided invalid format for editing a task.")
        else:
            await send_chat_message(f"{user.name}, please provide the task ID and new description.")
            chat_logger.error(f"{user.name} did not provide task ID and new description for editing.")

# ToDo List Function - Remove Task
async def remove_task(ctx, params, user_id, connection):
    user = ctx.author
    async with connection.cursor(DictCursor) as cursor:
        if params:
            try:
                todo_id = int(params[0].strip())
                await cursor.execute("SELECT id FROM todos WHERE id = %s", (todo_id,))
                if await cursor.fetchone():
                    pending_removals[user_id] = todo_id
                    await send_chat_message(f"{user.name}, please use `!todo confirm` to remove task ID {todo_id}.")
                    chat_logger.info(f"{user.name} initiated removal of task ID {todo_id}.")
                else:
                    await send_chat_message(f"{user.name}, task ID {todo_id} does not exist.")
                    chat_logger.error(f"{user.name} tried to remove non-existing task ID {todo_id}.")
            except ValueError:
                await send_chat_message(f"{user.name}, please provide a valid task ID to remove.")
                chat_logger.error(f"{user.name} provided invalid task ID for removal.")
        else:
            await send_chat_message(f"{user.name}, please provide the task ID to remove.")
            chat_logger.error(f"{user.name} did not provide task ID for removal.")

# ToDo List Function - Complete Task
async def complete_task(ctx, params, user_id, connection):
    user = ctx.author
    async with connection.cursor(DictCursor) as cursor:
        if params:
            try:
                todo_id = int(params[0].strip())
                await cursor.execute("UPDATE todos SET completed = 'Yes' WHERE id = %s", (todo_id,))
                if cursor.rowcount == 0:
                    await send_chat_message(f"{user.name}, task ID {todo_id} does not exist.")
                    chat_logger.error(f"{user.name} tried to complete non-existing task ID {todo_id}.")
                else:
                    await connection.commit()
                    await send_chat_message(f"{user.name}, task {todo_id} has been marked as complete.")
                    chat_logger.info(f"{user.name} marked task ID {todo_id} as complete.")
            except ValueError:
                await send_chat_message(f"{user.name}, please provide a valid task ID to mark as complete.")
                chat_logger.error(f"{user.name} provided invalid task ID for completion.")
        else:
            await send_chat_message(f"{user.name}, please provide the task ID to mark as complete.")
            chat_logger.error(f"{user.name} did not provide task ID for completion.")

# ToDo List Function - Confirm Removal
async def confirm_removal(ctx, params, user_id, connection):
    user = ctx.author
    async with connection.cursor(DictCursor) as cursor:
        if user_id in pending_removals:
            todo_id = pending_removals.pop(user_id)
            await cursor.execute("DELETE FROM todos WHERE id = %s", (todo_id,))
            await connection.commit()
            await send_chat_message(f"{user.name}, task ID {todo_id} has been removed.")
            chat_logger.info(f"{user.name} confirmed and removed task ID {todo_id}.")
        else:
            await send_chat_message(f"{user.name}, you have no pending task removal to confirm.")
            chat_logger.error(f"{user.name} tried to confirm removal without pending task.")

# ToDo List Function - View Task
async def view_task(ctx, params, user_id, connection):
    user = ctx.author
    async with connection.cursor(DictCursor) as cursor:
        if params:
            try:
                todo_id = int(params[0].strip())
                await cursor.execute("SELECT objective, category, completed FROM todos WHERE id = %s", (todo_id,))
                result = await cursor.fetchone()
                if result:
                    objective = result.get("objective")
                    category_id = result.get("category")
                    completed = result.get("completed")
                    category_name = await fetch_category_name(cursor, category_id)
                    await send_chat_message(f"Task ID {todo_id}: Description: {objective} Category: {category_name or 'Unknown'} Completed: {completed}")
                    chat_logger.info(f"{user.name} viewed task ID {todo_id}.")
                else:
                    await send_chat_message(f"{user.name}, task ID {todo_id} does not exist.")
                    chat_logger.error(f"{user.name} tried to view non-existing task ID {todo_id}.")
            except ValueError:
                await send_chat_message(f"{user.name}, please provide a valid task ID to view.")
                chat_logger.error(f"{user.name} provided invalid task ID for viewing.")
        else:
            await send_chat_message(f"{user.name}, please provide the task ID to view.")
            chat_logger.error(f"{user.name} did not provide task ID for viewing.")

# Function to get Category Names for the ToDo List
async def fetch_category_name(cursor, category_id):
    await cursor.execute("SELECT category FROM categories WHERE id = %s", (category_id,))
    result = await cursor.fetchone()
    return result.get("category") if result else None

# Function to start subathon timer
async def start_subathon(ctx):
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            subathon_state = await get_subathon_state()
            if subathon_state and not subathon_state["paused"]:
                await send_chat_message(f"A subathon is already running!")
                return
            if subathon_state and subathon_state["paused"]:
                await resume_subathon(ctx)
            else:
                await cursor.execute("SELECT * FROM subathon_settings LIMIT 1")
                settings = await cursor.fetchone()
                if settings:
                    starting_minutes = settings["starting_minutes"]
                    subathon_start_time = time_right_now()
                    subathon_end_time = subathon_start_time + timedelta(minutes=starting_minutes)
                    await cursor.execute("INSERT INTO subathon (start_time, end_time, starting_minutes, paused, remaining_minutes) VALUES (%s, %s, %s, %s, %s)", (subathon_start_time, subathon_end_time, starting_minutes, False, 0))
                    await connection.commit()
                    await send_chat_message(f"Subathon started!")
                    create_task(subathon_countdown())
                    # Send websocket notice
                    additional_data = {'starting_minutes': starting_minutes}
                    create_task(websocket_notice(event="SUBATHON_START", additional_data=additional_data))
                else:
                    await send_chat_message(f"Can't start subathon, please go to the dashboard and set up subathons.")
    finally:
        pass

# Function to stop subathon timer
async def stop_subathon(ctx):
    subathon_state = await get_subathon_state()
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            if subathon_state and not subathon_state["paused"]:
                await cursor.execute("UPDATE subathon SET paused = %s WHERE id = %s", (True, subathon_state["id"]))
                await connection.commit()
                await send_chat_message(f"Subathon ended!")
                # Send websocket notice
                create_task(websocket_notice(event="SUBATHON_STOP"))
            else:
                await send_chat_message(f"No subathon active.")
    finally:
        pass

# Function to pause subathon
async def pause_subathon(ctx):
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            subathon_state = await get_subathon_state()
            if subathon_state and not subathon_state["paused"]:
                remaining_minutes = (subathon_state["end_time"] - time_right_now()).total_seconds() // 60
                await cursor.execute("UPDATE subathon SET paused = %s, remaining_minutes = %s WHERE id = %s", (True, remaining_minutes, subathon_state["id"]))
                await connection.commit()
                await send_chat_message(f"Subathon paused with {int(remaining_minutes)} minutes remaining.")
                # Send websocket notice
                additional_data = {'remaining_minutes': remaining_minutes}
                create_task(websocket_notice(event="SUBATHON_PAUSE", additional_data=additional_data))
            else:
                await send_chat_message("No subathon is active or it's already paused!")
    finally:
        pass

# Function to resume subathon
async def resume_subathon(ctx):
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            subathon_state = await get_subathon_state()
            if subathon_state and subathon_state["paused"]:
                subathon_end_time = time_right_now()+ timedelta(minutes=subathon_state["remaining_minutes"])
                await cursor.execute("UPDATE subathon SET paused = %s, remaining_minutes = %s, end_time = %s WHERE id = %s", (False, 0, subathon_end_time, subathon_state["id"]))
                await connection.commit()
                await send_chat_message(f"Subathon resumed with {int(subathon_state['remaining_minutes'])} minutes remaining!")
                create_task(subathon_countdown())
                # Send websocket notice
                additional_data = {'remaining_minutes': subathon_state["remaining_minutes"]}
                create_task(websocket_notice(event="SUBATHON_RESUME", additional_data=additional_data))
    finally:
        pass

# Function to Add Time to subathon
async def addtime_subathon(ctx, minutes):
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            subathon_state = await get_subathon_state()
            if subathon_state and not subathon_state["paused"]:
                subathon_end_time = subathon_state["end_time"] + timedelta(minutes=minutes)
                await cursor.execute("UPDATE subathon SET end_time = %s WHERE id = %s", (subathon_end_time, subathon_state["id"]))
                await connection.commit()
                await send_chat_message(f"Added {minutes} minutes to the subathon timer!")
                # Send websocket notice
                additional_data = {'added_minutes': minutes}
                create_task(websocket_notice(event="SUBATHON_ADD_TIME", additional_data=additional_data))
            else:
                await send_chat_message("No subathon is active or it's paused!")
    finally:
        pass

# Function to get the current subathon status
async def subathon_status(ctx):
    subathon_state = await get_subathon_state()
    if subathon_state:
        if subathon_state["paused"]:
            await send_chat_message(f"Subathon is paused with {subathon_state['remaining_minutes']} minutes remaining.")
        else:
            remaining = subathon_state["end_time"] - time_right_now()
            await send_chat_message(f"Subathon time remaining: {remaining}.")
    else:
        await send_chat_message("No subathon is active!")

# Function to start the subathon countdown
async def subathon_countdown():
    while True:
        subathon_state = await get_subathon_state()
        if subathon_state and not subathon_state["paused"]:
            now = time_right_now()
            if now >= subathon_state["end_time"]:
                await send_chat_message(f"Subathon has ended!")
                try:
                    connection = await mysql_handler.get_connection()
                    async with connection.cursor(DictCursor) as cursor:
                        await cursor.execute("UPDATE subathon SET paused = %s WHERE id = %s", (True, subathon_state["id"]))
                        await connection.commit()
                finally:
                    pass
            break
        await sleep(60)  # Check every minute

# Function to get the current subathon state
async def get_subathon_state():
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute("SELECT * FROM subathon ORDER BY id DESC LIMIT 1")
            return await cursor.fetchone()
    finally:
        pass

# Function to run at midnight each night
async def midnight():
    # Get the timezone once outside the loop
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute("SELECT timezone FROM profile")
            result = await cursor.fetchone()
            if result and result.get("timezone"):
                timezone = result.get("timezone")
                tz = pytz_timezone(timezone)
            else:
                # Default to UTC if no timezone is set
                bot_logger.info("No timezone set for the user. Defaulting to UTC.")
                tz = set_timezone.UTC  # Set to UTC
        while True:
            # Get the current time in the user's timezone
            current_time = time_right_now(tz)
            # Check if it's exactly midnight (00:00:00)
            if current_time.hour == 0 and current_time.minute == 0:
                # Reload the .env file at midnight
                await reload_env_vars()
                # Send the midnight message to the channel
                cur_date = current_time.strftime("%d %B %Y")
                cur_time = current_time.strftime("%I %p")
                cur_day = current_time.strftime("%A")
                if stream_online:
                    message = f"Welcome to {cur_day}, {cur_date}. It's currently {cur_time}. Good morning everyone!"
                    await send_chat_message(message)
                # Sleep for 120 seconds to avoid sending the message multiple times
                await sleep(120)
            else:
                # Sleep for 10 seconds before checking again
                await sleep(10)
    except Exception as e:
        bot_logger.error(f"An error occurred in midnight function: {str(e)}")

async def reload_env_vars():
    # Load in all the globals
    global SQL_HOST, SQL_USER, SQL_PASSWORD, ADMIN_API_KEY
    global OAUTH_TOKEN, CLIENT_ID, CLIENT_SECRET, TWITCH_GQL
    global SHAZAM_API, STEAM_API, EXCHANGE_RATE_API_KEY, HYPERATE_API_KEY, CHANNEL_AUTH
    global TWITCH_OAUTH_API_TOKEN, TWITCH_OAUTH_API_CLIENT_ID
    global OPENAI_API_KEY
    global SSH_USERNAME, SSH_PASSWORD, SSH_HOSTS
    # Reload the .env file
    load_dotenv()
    SQL_HOST = os.getenv('SQL_HOST')
    SQL_USER = os.getenv('SQL_USER')
    SQL_PASSWORD = os.getenv('SQL_PASSWORD')
    ADMIN_API_KEY = os.getenv('ADMIN_KEY')
    OAUTH_TOKEN = os.getenv('OAUTH_TOKEN')
    CLIENT_ID = os.getenv('CLIENT_ID')
    CLIENT_SECRET = os.getenv('CLIENT_SECRET')
    TWITCH_OAUTH_API_TOKEN = os.getenv('TWITCH_OAUTH_API_TOKEN')
    TWITCH_OAUTH_API_CLIENT_ID = os.getenv('TWITCH_OAUTH_API_CLIENT_ID')
    TWITCH_GQL = os.getenv('TWITCH_GQL')
    SHAZAM_API = os.getenv('SHAZAM_API')
    STEAM_API = os.getenv('STEAM_API')
    EXCHANGE_RATE_API_KEY = os.getenv('EXCHANGE_RATE_API')
    HYPERATE_API_KEY = os.getenv('HYPERATE_API_KEY')
    OPENAI_API_KEY = os.getenv('OPENAI_KEY')
    SSH_USERNAME = os.getenv('SSH_USERNAME')
    SSH_PASSWORD = os.getenv('SSH_PASSWORD')
    SSH_HOSTS = {
        'API': os.getenv('API-HOST'),
        'WEBSOCKET': os.getenv('WEBSOCKET-HOST'),
        'BOT-SRV': os.getenv('BOT-SRV-HOST'),
        'SQL': os.getenv('SQL-HOST'),
        'STREAM-US-EAST-1': os.getenv('STREAM-US-EAST-1-HOST'),
        'STREAM-US-WEST-1': os.getenv('STREAM-US-WEST-1-HOST'),
        'STREAM-AU-EAST-1': os.getenv('STREAM-AU-EAST-1-HOST'),
        'WEB': os.getenv('WEB-HOST'),
        'BILLING': os.getenv('BILLING-HOST')
    }
    # Log or handle any environment variable updates
    bot_logger.info("Reloaded environment variables")

async def get_point_settings():
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute("""
                SELECT 
                    point_name, 
                    point_amount_chat, 
                    point_amount_follower,
                    point_amount_subscriber, 
                    point_amount_cheer, 
                    point_amount_raid,
                    subscriber_multiplier,
                    excluded_users
                FROM bot_settings 
                LIMIT 1
            """)
            result = await cursor.fetchone()
            if result:
                return {
                    'point_name': result['point_name'],
                    'chat_points': int(result['point_amount_chat']),
                    'follower_points': int(result['point_amount_follower']),
                    'subscriber_points': int(result['point_amount_subscriber']),
                    'cheer_points': int(result['point_amount_cheer']),
                    'raid_points': int(result['point_amount_raid']),
                    'subscriber_multiplier': int(result['subscriber_multiplier']),
                    'excluded_users': result['excluded_users']
                }
            else:
                return None
    except Exception as e:
        bot_logger.error(f"Error fetching bot settings: {e}")
        return None
    finally:
        pass

async def known_users():
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
    try:
        connection = await mysql_handler.get_connection()
        headers = {"Authorization": f"Bearer {CHANNEL_AUTH}","Client-Id": CLIENT_ID,"Content-Type": "application/json"}
        async with httpClientSession() as session:
            # Get all the mods and put them into the database
            url_mods = f'https://api.twitch.tv/helix/moderation/moderators?broadcaster_id={CHANNEL_ID}'
            async with session.get(url_mods, headers=headers) as response:
                if response.status == 200:
                    data = await response.json()
                    moderators = data.get('data', [])
                    mod_list = [(mod['user_name'], "MOD") for mod in moderators]
                else:
                    api_logger.error(f"Failed to fetch moderators: {response.status} - {await response.text()}")
                    mod_list = []
            # Get all the VIPs and put them into the database
            url_vips = f'https://api.twitch.tv/helix/channels/vips?broadcaster_id={CHANNEL_ID}'
            async with session.get(url_vips, headers=headers) as response:
                if response.status == 200:
                    data = await response.json()
                    vips = data.get('data', [])
                    vip_list = [(vip['user_name'], "VIP") for vip in vips]
                else:
                    api_logger.error(f"Failed to fetch VIPs: {response.status} - {await response.text()}")
                    vip_list = []
        # Combine lists, prioritizing MOD over VIP for users in both
        user_groups = {}
        for username, group in mod_list + vip_list:
            if username not in user_groups or group == "MOD":
                user_groups[username] = group
        if user_groups:
            async with connection.cursor(DictCursor) as cursor:
                values = [(username, group) for username, group in user_groups.items()]
                await cursor.executemany(
                    "INSERT INTO everyone (username, group_name) VALUES (%s, %s) AS new ON DUPLICATE KEY UPDATE group_name = new.group_name",
                    values
                )
                await connection.commit()
    except asyncioCancelledError:
        bot_logger.info("known_users task was cancelled")
        raise
    except Exception as e:
        bot_logger.error(f"An error occurred in known_users: {e}")
    finally:
        pass

async def check_premium_feature(user):
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID, CHANNEL_NAME, bot_owner
    api_logger.info("Starting premium feature check")
    if user == bot_owner:
        api_logger.info("User is bot owner, returning 4000")
        return 4000
    try:
        async with await mysql_handler.get_connection(db_name="website") as connection:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT beta_access FROM users WHERE username = %s", (CHANNEL_NAME.lower(),))
                result = await cursor.fetchone()
                if result and result['beta_access'] == 1:
                    api_logger.info("User has beta access, returning 4000")
                    return 4000
        api_logger.info("User does not have beta access, checking subscription")
        twitch_subscriptions_url = f"https://api.twitch.tv/helix/subscriptions/user?broadcaster_id=140296994&user_id={CHANNEL_ID}"
        headers = {"Client-ID": CLIENT_ID,"Authorization": f"Bearer {CHANNEL_AUTH}",}
        api_logger.info(f"Fetching subscription from {twitch_subscriptions_url}")
        async with httpClientSession() as session:
            async with session.get(twitch_subscriptions_url, headers=headers) as response:
                response.raise_for_status()
                data = await response.json()
                api_logger.info(f"Subscription data: {data}")
                if data.get("data"):
                    tier = int(data["data"][0]["tier"])
                    api_logger.info(f"User is subscribed with tier {tier}")
                    return tier
                else:
                    api_logger.info("User is not subscribed, returning 0")
                    return 0  # Return 0 if not subscribed
    except Exception as e:
        api_logger.error(f"Error in check_premium_feature: {e}")
        return 0

# Make a Stream Marker for events
async def make_stream_marker(description: str):
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
    # Validate description
    if not description or not description.strip():
        twitch_logger.error("Stream marker description cannot be empty")
        return False
    if len(description) > 140:
        twitch_logger.error(f"Stream marker description too long: {len(description)} characters (max 140)")
        return False
    payload = {"user_id": CHANNEL_ID,"description": description.strip()}
    headers = {"Client-ID": CLIENT_ID,"Authorization": f"Bearer {CHANNEL_AUTH}","Content-Type": "application/json"}
    timeout = ClientTimeout(total=10)  # 10 second timeout
    try:
        async with httpClientSession() as session:
            async with session.post('https://api.twitch.tv/helix/streams/markers', headers=headers, json=payload, timeout=timeout) as marker_response:
                if marker_response.status == 200:
                    try:
                        data = await marker_response.json()
                        marker_id = data.get("data", [{}])[0].get("id")
                        twitch_logger.info(f"Stream marker created successfully with ID: {marker_id}")
                        return True
                    except (aiohttpClientError, json.JSONDecodeError) as e:
                        twitch_logger.error(f"Failed to parse response JSON: {e}")
                        return False
                else:
                    response_text = await marker_response.text()
                    twitch_logger.error(f"Failed to create stream marker: HTTP {marker_response.status} - {response_text}")
                    return False
    except ClientTimeout as e:
        twitch_logger.error(f"Timeout creating stream marker: {e}")
        return False
    except aiohttpClientError as e:
        twitch_logger.error(f"Client error creating stream marker: {e}")
        return False
    except Exception as e:
        twitch_logger.error(f"Unexpected error creating stream marker: {e}")
        return False

# Function to check if a URL or domain matches whitelisted or blacklisted URLs
async def match_domain_or_link(message, domain_list, use_regex=False):
    """
    Check if a message contains URLs matching patterns in the domain list.
    
    Args:
        message: The message text to check
        domain_list: List of patterns to match against
        use_regex: If True, treat patterns as regex. If False, treat as literal domains.
    
    Returns:
        True if a match is found, False otherwise
    """
    for pattern in domain_list:
        if use_regex:
            # Use pattern as-is for regex matching
            try:
                if re.search(pattern, message, re.IGNORECASE):
                    return True
            except re.error as e:
                chat_logger.error(f"Invalid regex pattern '{pattern}': {e}")
                continue
        else:
            # Escape pattern for literal domain matching
            escaped_pattern = re.escape(pattern)
            if re.search(rf"(https?://)?(www\.)?{escaped_pattern}(\/|$)", message, re.IGNORECASE):
                return True
    return False

# Function(s) to track watch time for users in active channel
async def periodic_watch_time_update():
    while True:
        # Fetch active users from Twitch API
        active_users = await fetch_active_users()
        if not active_users:
            pass
        else:
            # Pass the active users (raw data) to the watch time tracker
            await track_watch_time(active_users)
        # Wait for 60 seconds before the next check
        await sleep(60)

# Function to get a list of users that are active in chat
async def fetch_active_users():
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
    headers = {
        "Client-ID": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}"
    }
    url = f"https://api.twitch.tv/helix/chat/chatters?broadcaster_id={CHANNEL_ID}&moderator_id={CHANNEL_ID}"
    async with httpClientSession() as session:
        try:
            async with session.get(url, headers=headers) as response:
                if response.status == 200:
                    data = await response.json()
                    return data.get("data", [])
                else:
                    bot_logger.error(f"Failed to fetch active users: {response.status} {await response.text()}")
                    return []
        except Exception as e:
            bot_logger.error(f"Error fetching active users: {e}")
            return []

# Function to add time in the database
async def track_watch_time(active_users):
    global stream_online
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            current_time = int(time.time())
            # Fetch the excluded_users list once
            await cursor.execute("SELECT excluded_users FROM watch_time_excluded_users LIMIT 1")
            excluded_users_data = await cursor.fetchone()
            excluded_users = excluded_users_data['excluded_users'] if excluded_users_data else ''
            excluded_users_list = excluded_users.split(',') if excluded_users else []
            # Filter active users to exclude those in the list
            non_excluded_users = [user for user in active_users if user['user_login'] not in excluded_users_list]
            if not non_excluded_users:
                return
            # Get all user_ids for batch query
            user_ids = [user['user_id'] for user in non_excluded_users]
            placeholders = ','.join(['%s'] * len(user_ids))
            await cursor.execute(f"SELECT user_id, total_watch_time_live, total_watch_time_offline, last_active FROM watch_time WHERE user_id IN ({placeholders})", user_ids)
            existing_data = {row['user_id']: row for row in await cursor.fetchall()}
            # Prepare updates and inserts
            updates = []
            inserts = []
            for user in non_excluded_users:
                user_id = user['user_id']
                user_login = user['user_login']
                if user_id in existing_data:
                    data = existing_data[user_id]
                    total_live = data['total_watch_time_live'] + (60 if stream_online else 0)
                    total_offline = data['total_watch_time_offline'] + (60 if not stream_online else 0)
                    updates.append((total_live, total_offline, current_time, user_id))
                else:
                    inserts.append((user_id, user_login, 60 if stream_online else 0, 60 if not stream_online else 0, current_time))
            # Execute batch updates
            if updates:
                await cursor.executemany("UPDATE watch_time SET total_watch_time_live = %s, total_watch_time_offline = %s, last_active = %s WHERE user_id = %s", updates)
            # Execute batch inserts
            if inserts:
                await cursor.executemany("INSERT INTO watch_time (user_id, username, total_watch_time_live, total_watch_time_offline, last_active) VALUES (%s, %s, %s, %s, %s) AS new_watch_time ON DUPLICATE KEY UPDATE total_watch_time_live = watch_time.total_watch_time_live + new_watch_time.total_watch_time_live, total_watch_time_offline = watch_time.total_watch_time_offline + new_watch_time.total_watch_time_offline, last_active = new_watch_time.last_active, username = new_watch_time.username", inserts)
            await connection.commit()
    except Exception as e:
        bot_logger.error(f"Error in track_watch_time: {e}", exc_info=True)
    finally:
        pass

# Function to periodically check the queue
async def check_song_requests():
    global song_requests
    while True:
        await sleep(180)
        if song_requests:
            # Get the Spotify access token from the database
            access_token = await get_spotify_access_token()
            headers = { "Authorization": f"Bearer {access_token}" }
            queue_url = "https://api.spotify.com/v1/me/player/queue"
            async with httpClientSession() as session:
                async with session.get(queue_url, headers=headers) as response:
                    if response.status == 200:
                        data = await response.json()
                        if data and 'queue' in data:
                            queue = data['queue']
                            queue_ids = [song['uri'] for song in queue]
                            for song_id in list(song_requests):
                                if song_id not in queue_ids:
                                    song_info = song_requests[song_id]
                                    del song_requests[song_id]
                                    api_logger.info(f"Song \"{song_info['song_name']} by {song_info['artist_name']}\" removed from tracking list.")
                    else:
                        api_logger.error(f"Failed to fetch queue from Spotify, status code: {response.status}")

# Function to return the action back to the user
async def return_the_action_back(ctx, author, action):
    action_config = {
        "kiss": ("kiss_counts", "kiss_count"),
        "hug": ("hug_counts", "hug_count"),
        "highfive": ("highfive_counts", "highfive_count")
    }
    if action not in action_config:
        return
    table, column = action_config[action]
    display_action = "high five" if action == "highfive" else action
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute(
                f'INSERT INTO {table} (username, {column}) VALUES (%s, 1) '
                f'ON DUPLICATE KEY UPDATE {column} = {column} + 1',
                (author,)
            )
            await connection.commit()
            await cursor.execute(f'SELECT {column} FROM {table} WHERE username = %s', (author,))
            result = await cursor.fetchone()
            if result:
                count = result[column]
                await send_chat_message(f"Thanks for the {display_action}, {author}! I've given you a {display_action} too, you have been {display_action} {count} times!")
    finally:
        pass

# Function to remove the temp user from the shoutout_user dict
async def remove_shoutout_user(username: str, delay: int):
    global shoutout_user
    await sleep(delay)
    if shoutout_user:
        chat_logger.info(f"Removed temporary shoutout data for {username}")
        shoutout_user = None

# Helper function to format duration
def format_duration(duration_seconds):
    minutes = duration_seconds // 60
    seconds = duration_seconds % 60
    if minutes == 0:
        return f"{seconds} second{'s' if seconds != 1 else ''}"
    elif seconds == 0:
        return f"{minutes} minute{'s' if minutes != 1 else ''}"
    else:
        return f"{minutes} minute{'s' if minutes != 1 else ''} and {seconds} second{'s' if seconds != 1 else ''}"

# Helper function to get ad settings with caching (refreshed every minute to ensure accuracy)
async def get_ad_settings():
    global ad_settings_cache, ad_settings_cache_time
    current_time = time.time()
    if ad_settings_cache and (current_time - ad_settings_cache_time) < CACHE_DURATION:
        return ad_settings_cache
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute("SELECT * FROM ad_notice_settings WHERE id = 1")
            settings = await cursor.fetchone()
            if settings:
                ad_settings_cache = {
                    'ad_start_message': settings.get("ad_start_message", "Ads are running for (duration). We'll be right back after these ads."),
                    'ad_end_message': settings.get("ad_end_message", "Thanks for sticking with us through the ads! Welcome back, everyone!"),
                    'ad_upcoming_message': settings.get("ad_upcoming_message", "Heads up! An ad break is coming up in (minutes) minutes and will last (duration)."),
                    'ad_snoozed_message': settings.get("ad_snoozed_message", "Ads have been snoozed."),
                    'enable_ad_notice': settings.get("enable_ad_notice", True),
                    'enable_upcoming_ad_message': settings.get("enable_upcoming_ad_message", True),
                    'enable_start_ad_message': settings.get("enable_start_ad_message", True),
                    'enable_end_ad_message': settings.get("enable_end_ad_message", True),
                    'enable_snoozed_ad_message': settings.get("enable_snoozed_ad_message", True),
                    'enable_ai_ad_breaks': settings.get("enable_ai_ad_breaks", 0)
                }
            else:
                ad_settings_cache = {
                    'ad_start_message': "Ads are running for (duration). We'll be right back after these ads.",
                    'ad_end_message': "Thanks for sticking with us through the ads! Welcome back, everyone!",
                    'ad_upcoming_message': "Heads up! An ad break is coming up in (minutes) minutes and will last (duration).",
                    'ad_snoozed_message': "Ads have been snoozed.",
                    'enable_ad_notice': True,
                    'enable_upcoming_ad_message': True,
                    'enable_start_ad_message': True,
                    'enable_end_ad_message': True,
                    'enable_snoozed_ad_message': True,
                    'enable_ai_ad_breaks': 0
                }
            # Ensure messages are distinct to avoid confusion
            if ad_settings_cache['ad_upcoming_message'] == ad_settings_cache['ad_snoozed_message']:
                ad_settings_cache['ad_upcoming_message'] = "Heads up! An ad break is coming up in (minutes) minutes and will last (duration)."
                ad_settings_cache['ad_snoozed_message'] = "Ads have been snoozed."
            ad_settings_cache_time = current_time
            return ad_settings_cache
    except Exception as e:
        # Log full exception and fall back to defaults so ad-notice paths can continue
        try:
            api_logger.error(f"Error fetching ad settings from DB: {e}")
        except Exception:
            # If api_logger is not available for some reason, fallback to bot_logger
            try:
                bot_logger.error(f"Error fetching ad settings from DB: {e}")
            except Exception:
                pass
        # Ensure we still return reasonable defaults so ad notifications continue
        ad_settings_cache = {
            'ad_start_message': "Ads are running for (duration). We'll be right back after these ads.",
            'ad_end_message': "Thanks for sticking with us through the ads! Welcome back, everyone!",
            'ad_upcoming_message': "Heads up! An ad break is coming up in (minutes) minutes and will last (duration).",
            'ad_snoozed_message': "Ads have been snoozed.",
            'enable_ad_notice': True,
            'enable_upcoming_ad_message': True,
            'enable_start_ad_message': True,
            'enable_end_ad_message': True,
            'enable_snoozed_ad_message': True,
            'enable_ai_ad_breaks': 0
        }
        ad_settings_cache_time = current_time
        return ad_settings_cache
    finally:
        pass

# Function for AD BREAK
def analyze_chat_vibe(chat_history):
    try:
        if not chat_history:
            return {"summary": "No recent chat.", "topics": [], "tone": "neutral"}
        # Keywords mapping to topics
        keywords = {
            'coding': ['code', 'coding', 'program', 'pair', 'pair-program', 'pairing', 'co-work', 'co-work', 'collab', 'debug', 'stack', 'merge'],
            'game': ['pvp', 'pve', 'coop', 'co-op', 'ranked', 'match', 'kill', 'gank', 'gg', 'clutch'],
            'music': ['song', 'music', 'track', 'banger'],
            'hype': ['pog', 'poggers', 'hype', ':fire:', ':tada:']
        }
        counts = {k: 0 for k in keywords}
        exclaim = 0
        emoji_like = 0
        caps = 0
        for entry in chat_history[-40:]:
            msg = (entry.get('message') or '').lower()
            if not msg:
                continue
            exclaim += msg.count('!')
            emoji_like += msg.count(':')  # rough proxy
            caps += sum(1 for c in msg if c.isupper())
            for topic, kwlist in keywords.items():
                for kw in kwlist:
                    if kw in msg:
                        counts[topic] += 1
        # Determine top topics
        topics = [t for t, c in sorted(counts.items(), key=lambda x: -x[1]) if c > 0]
        # Decide tone
        tone = 'neutral'
        if exclaim > 8 or emoji_like > 12:
            tone = 'hype'
        elif counts.get('coding', 0) > max(counts.get('game', 0), 0):
            tone = 'focused'
        elif counts.get('game', 0) > 0:
            tone = 'gamey'
        summary = f"Tone: {tone}. Detected topics: {', '.join(topics) if topics else 'none'}."
        return {"summary": summary, "topics": topics, "tone": tone}
    except Exception as e:
        event_logger.error(f"Error analyzing chat vibe: {e}")
        return {"summary": "No recent chat.", "topics": [], "tone": "neutral"}

def try_mark_ad_message_sent_after(success):
    global last_ad_message_ts
    try:
        if success:
            last_ad_message_ts = datetime.now(timezone.utc).timestamp()
            return True
    except Exception:
        pass
    return False

def can_send_ad_message():
    try:
        now_ts = datetime.now(timezone.utc).timestamp()
        if now_ts - last_ad_message_ts < AD_DEDUPE_COOLDOWN_SECONDS:
            return False
        return True
    except Exception:
        return True

async def handle_ad_break_start(duration_seconds):
    settings = await get_ad_settings()
    # Honor global ad-notice toggle — if disabled, do nothing (same behavior as main bot)
    if not settings.get('enable_ad_notice', True):
        return
    formatted_duration = format_duration(duration_seconds)
    # 1. Update Ad Break Count
    ad_break_count = 1
    try:
        connection = await mysql_handler.get_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute("UPDATE stream_session_stats SET ad_break_count = ad_break_count + 1 WHERE id = 1")
            await connection.commit()
            await cursor.execute("SELECT ad_break_count FROM stream_session_stats WHERE id = 1")
            result = await cursor.fetchone()
            if result:
                ad_break_count = result['ad_break_count']
    except Exception as e:
        api_logger.error(f"Error updating ad break count in handle_ad_break_start: {e}")
    # Pre-read ad-break chat file and clear it immediately so old messages don't pile up (even if AI is disabled/permission not granted)
    chat_file = Path(AD_BREAK_CHAT_DIR) / f"{CHANNEL_NAME}.json"
    chat_history = []
    if chat_file.exists():
        try:
            with chat_file.open('r', encoding='utf-8') as f:
                content = f.read()
                if content:
                    chat_history = json.loads(content)
                    # Filter out only timed messages for AI context (keep command responses)
                    timed_messages = [m.get('message') for m in active_timed_messages.values() if m.get('message')]
                    chat_history = [entry for entry in chat_history if entry.get('message', '') not in timed_messages]
        except Exception as e:
            event_logger.error(f"Error reading ad break chat history (pre-clear): {e}")
    # Clear the file IMMEDIATELY after reading so future ad-breaks don't process stale logs
    try:
        if chat_file.exists():
            with chat_file.open('w', encoding='utf-8') as f:
                json.dump([], f)
    except Exception as e:
        event_logger.error(f"Error clearing ad break chat history file (pre-clear): {e}")
    # 2. AI Logic
    enable_ai = settings.get('enable_ai_ad_breaks', 0)
    premium_tier = await check_premium_feature(CHANNEL_NAME)
    ai_message_sent = False
    if enable_ai and premium_tier >= 2000:
        try:
            # Check Ad Manager Status (to detect automated start-of-stream ads)
            uses_ad_manager = False
            try:
                # Need access to global creds
                global CHANNEL_ID, CLIENT_ID, CHANNEL_AUTH
                ads_api_url = f"https://api.twitch.tv/helix/channels/ads?broadcaster_id={CHANNEL_ID}"
                headers = { "Client-ID": CLIENT_ID, "Authorization": f"Bearer {CHANNEL_AUTH}" }
                async with httpClientSession() as session:
                    async with session.get(ads_api_url, headers=headers) as response:
                        if response.status == 200:
                            data = await response.json()
                            ads_data = data.get("data", [])
                            if ads_data:
                                # User indicates that duration > 0 implies Ad Manager is active
                                if int(ads_data[0].get("duration", 0)) > 0:
                                    uses_ad_manager = True
            except Exception as e:
                api_logger.error(f"Error checking ad manager status: {e}")
            system_prompt = (
                "You are the witty and entertaining assistant for a Twitch stream. "
                f"An ad break is STARTING RIGHT NOW (Duration: {formatted_duration}). "
                "Your goal is to write a message announcing the ad break is beginning. "
                "Let viewers know we're taking a quick break and will be back soon. "
                "IMPORTANT: "
                "1. Keep your response under 500 characters. "
                "2. DO NOT use the phrase 'Chaos Crew' ever."
            )
            if ad_break_count == 1 and uses_ad_manager:
                user_content = "This is the FIRST ad break (automated start-of-stream). Welcome everyone in! Mention we are getting these automated ads out of the way early so we can enjoy the stream. Keep the vibe high and welcoming."
            else:
                user_content = "An ad break is starting now. Let viewers know we're taking a break and will be back shortly. "
            # Add stream context (title and game) for better AI awareness
            try:
                recent_activity = ""
                if 'stream_title' in globals() and stream_title:
                    recent_activity += f"Stream title: {stream_title}. "
                if 'current_game' in globals() and current_game:
                    recent_activity += f"Current category: {current_game}. "
                if recent_activity:
                    user_content += f"Stream context: {recent_activity}"
            except Exception:
                pass
            user_content += "Here's what happened in chat recently that you can reference (but keep it brief and fun). Chat logs:\n"
            for entry in chat_history:
                user_content += f"{entry.get('user', 'User')}: {entry.get('message', '')}\n"
            # Analyze chat vibe so the AI can reference relevant topics/tone and avoid inventing unrelated activities
            try:
                vibe = analyze_chat_vibe(chat_history)
                user_content += f"\nVibe summary: {vibe.get('summary')}\n"
                if vibe.get('topics'):
                    user_content += f"Detected topics: {', '.join(vibe.get('topics'))}."
            except Exception as e:
                event_logger.debug(f"Could not analyze chat vibe for ad start: {e}")
            messages = [
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_content}
            ]
            try:
                api_logger.debug("Calling OpenAI chat completion for AI ad break")
                chat_client = getattr(openai_client, 'chat', None)
                ai_text = None
                if chat_client and hasattr(chat_client, 'completions') and hasattr(chat_client.completions, 'create'):
                    resp = await chat_client.completions.create(model="gpt-5-nano", messages=messages)
                    if isinstance(resp, dict) and 'choices' in resp and len(resp['choices']) > 0:
                        choice = resp['choices'][0]
                        if 'message' in choice and 'content' in choice['message']:
                            ai_text = choice['message']['content']
                        elif 'text' in choice:
                            ai_text = choice['text']
                    else:
                        # Try attribute access
                        choices = getattr(resp, 'choices', None)
                        if choices and len(choices) > 0:
                            ai_text = getattr(choices[0].message, 'content', None)
                elif hasattr(openai_client, 'chat_completions') and hasattr(openai_client.chat_completions, 'create'):
                    resp = await openai_client.chat_completions.create(model="gpt-5-nano", messages=messages)
                    if isinstance(resp, dict) and 'choices' in resp and len(resp['choices']) > 0:
                        ai_text = resp['choices'][0].get('message', {}).get('content') or resp['choices'][0].get('text')
                    else:
                        choices = getattr(resp, 'choices', None)
                        if choices and len(choices) > 0:
                            ai_text = getattr(choices[0].message, 'content', None)
                else:
                    api_logger.error("No compatible chat completions method found on openai_client for ad break")
                    ai_text = None
                if not ai_text:
                    api_logger.error(f"Chat completion returned no usable text for ad break: {resp if 'resp' in locals() else 'No response'}")
                else:
                    ai_text = ai_text.strip()
                    # Filter out "Chaos Crew" hallucination
                    if "Chaos Crew" in ai_text:
                        ai_text = ai_text.replace("Chaos Crew", "Stream Team")
                        api_logger.info("Filtered 'Chaos Crew' from AI response")
                    # Dedupe: ensure this process hasn't sent an ad message recently
                    try:
                        if can_send_ad_message():
                            sent_ok = await send_chat_message(f"/me {ai_text}")
                            if sent_ok:
                                try_mark_ad_message_sent_after(True)
                            else:
                                api_logger.error(f"AI Ad start message reported not sent: {ai_text}")
                        else:
                            api_logger.info("Skipped AI ad start message due to cooldown")
                    except Exception as e:
                        api_logger.error(f"Error sending AI ad start message: {e}")
                    ai_message_sent = True
                    api_logger.info(f"Sent AI Ad Break message: {ai_text}")
            except Exception as e:
                api_logger.error(f"Error calling chat completion API for ad break: {e}")
        except Exception as e:
            event_logger.error(f"Error in AI Ad Break logic in handle_ad_break_start: {e}")
    # 3. Standard Notice (Fallback or if AI disabled)
    if not ai_message_sent:
        if settings['enable_ad_notice'] and settings.get('enable_start_ad_message', True):
            ad_start_message = settings['ad_start_message'].replace("(duration)", formatted_duration)
            try:
                # Dedupe: avoid sending if this process sent a recent ad message
                if can_send_ad_message():
                    sent_ok = await send_chat_message(ad_start_message)
                    if sent_ok:
                        try_mark_ad_message_sent_after(True)
                    else:
                        api_logger.error(f"Ad start message failed to send: {ad_start_message}")
                else:
                    api_logger.info("Skipped ad start fallback due to cooldown")
            except Exception as e:
                api_logger.error(f"Exception while sending ad start message: {e}")
    @routines.routine(seconds=duration_seconds, iterations=1, wait_first=True)
    async def handle_ad_break_end():
        ai_message_sent = False
        enable_ai = settings.get('enable_ai_ad_breaks', 0)
        premium_tier = await check_premium_feature(CHANNEL_NAME)
        # Pre-read and clear ad-break chat history so old messages don't persist when AI is disabled/permission missing
        chat_file = Path(AD_BREAK_CHAT_DIR) / f"{CHANNEL_NAME}.json"
        chat_history = []
        try:
            if chat_file.exists():
                try:
                    with chat_file.open('r', encoding='utf-8') as f:
                        content = f.read()
                        if content:
                            chat_history = json.loads(content)
                            timed_messages = [m.get('message') for m in active_timed_messages.values() if m.get('message')]
                            chat_history = [entry for entry in chat_history if entry.get('message', '') not in timed_messages]
                except Exception as e:
                    event_logger.error(f"Error reading ad break chat history (pre-clear ad end): {e}")
            # Clear file after reading so future ad breaks start fresh
            try:
                if chat_file.exists():
                    with chat_file.open('w', encoding='utf-8') as f:
                        json.dump([], f)
            except Exception as e:
                event_logger.error(f"Error clearing ad break chat history file (pre-clear ad end): {e}")
        except Exception as e:
            event_logger.debug(f"No ad break chat file to pre-clear: {e}")
        if enable_ai and premium_tier >= 2000:
            try:
                system_prompt = (
                    "You are the witty and entertaining assistant for a Twitch stream. "
                    "The ad break has JUST FINISHED. "
                    "Your goal is to write a message welcoming viewers back to the stream. "
                    "IMPORTANT: "
                    "1. Keep your response under 500 characters. "
                    "2. DO NOT use the phrase 'Chaos Crew' ever."
                )
                try:
                    recent_activity = ""
                    if 'stream_title' in globals() and stream_title:
                        recent_activity += f"Stream title: {stream_title}. "
                    if 'current_game' in globals() and current_game:
                        recent_activity += f"Current category: {current_game}. "
                except Exception:
                    recent_activity = ""
                user_content = "Ads are done. Welcome everyone back to the stream! Get them hyped for more content."
                if recent_activity:
                    user_content += f" Recent activity: {recent_activity}"
                if chat_history:
                    user_content += " Recent chat (brief): "
                    # Include only a few recent chat lines to keep prompt concise
                    for entry in chat_history[-6:]:
                        user_content += f"{entry.get('user','User')}: {entry.get('message','')}\n"
                # Clear the ad-break chat file after reading so future ad-breaks start fresh
                try:
                    if chat_history and chat_file.exists():
                        with chat_file.open('w', encoding='utf-8') as f:
                            json.dump([], f)
                except Exception as e:
                    event_logger.error(f"Error clearing ad break chat history after ad end read: {e}")
                # Analyze chat vibe and include guidance so the model doesn't invent unrelated activities
                try:
                    vibe = analyze_chat_vibe(chat_history)
                    user_content += f"\nVibe summary: {vibe.get('summary')}\n"
                    if vibe.get('topics'):
                        user_content += f"Detected topics: {', '.join(vibe.get('topics'))}."
                    # Strong instruction: prefer detected topics and do not invent unrelated events
                    user_content += "\nInstruction: Prefer referencing detected topics/tone. Do not invent activities not present in the recent chat or stream title."
                except Exception as e:
                    event_logger.debug(f"Could not analyze chat vibe for ad end: {e}")
                messages = [
                    {"role": "system", "content": system_prompt},
                    {"role": "user", "content": user_content}
                ]
                try:
                    chat_client = getattr(openai_client, 'chat', None)
                    ai_text = None
                    if chat_client and hasattr(chat_client, 'completions') and hasattr(chat_client.completions, 'create'):
                        resp = await chat_client.completions.create(model="gpt-5-nano", messages=messages)
                        if isinstance(resp, dict) and 'choices' in resp and len(resp['choices']) > 0:
                            choice = resp['choices'][0]
                            if 'message' in choice and 'content' in choice['message']:
                                ai_text = choice['message']['content']
                            elif 'text' in choice:
                                ai_text = choice['text']
                        else:
                            choices = getattr(resp, 'choices', None)
                            if choices and len(choices) > 0:
                                ai_text = getattr(choices[0].message, 'content', None)
                    elif hasattr(openai_client, 'chat_completions') and hasattr(openai_client.chat_completions, 'create'):
                        resp = await openai_client.chat_completions.create(model="gpt-5-nano", messages=messages)
                        if isinstance(resp, dict) and 'choices' in resp and len(resp['choices']) > 0:
                            ai_text = resp['choices'][0].get('message', {}).get('content') or resp['choices'][0].get('text')
                        else:
                            choices = getattr(resp, 'choices', None)
                            if choices and len(choices) > 0:
                                ai_text = getattr(choices[0].message, 'content', None)
                    if ai_text:
                        ai_text = ai_text.strip()
                        if "Chaos Crew" in ai_text:
                            ai_text = ai_text.replace("Chaos Crew", "Stream Team")
                        # Dedupe check
                        try:
                            if can_send_ad_message():
                                sent_ok = await send_chat_message(f"/me {ai_text}")
                                if sent_ok:
                                    try_mark_ad_message_sent_after(True)
                                else:
                                    api_logger.error(f"AI Ad end message reported not sent: {ai_text}")
                            else:
                                api_logger.info("Skipped AI ad end message due to cooldown")
                        except Exception as e:
                            api_logger.error(f"Error sending AI ad end message: {e}")
                        ai_message_sent = True
                        api_logger.info(f"Sent AI Ad Break End message: {ai_text}")
                except Exception as e:
                    api_logger.error(f"Error calling chat completion API for ad break end: {e}")
            except Exception as e:
                api_logger.error(f"Error in AI Ad Break End logic: {e}")
        try:
            # Check individual setting for end message (Standard/Fallback)
            if not ai_message_sent and settings.get('enable_end_ad_message', True):
                try:
                    if can_send_ad_message():
                        sent_ok = await send_chat_message(settings['ad_end_message'])
                        if sent_ok:
                            try_mark_ad_message_sent_after(True)
                        else:
                            api_logger.error(f"Ad end message failed to send: {settings.get('ad_end_message')}")
                    else:
                        api_logger.info("Skipped ad end fallback due to cooldown")
                except Exception as e:
                    api_logger.error(f"Exception while sending ad end message: {e}")
        except Exception as e:
            api_logger.error(f"Exception while sending ad end message: {e}")
        # Check for the next ad after this one completes
        try:
            global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
            ads_api_url = f"https://api.twitch.tv/helix/channels/ads?broadcaster_id={CHANNEL_ID}"
            headers = { "Client-ID": CLIENT_ID, "Authorization": f"Bearer {CHANNEL_AUTH}" }
            create_task(check_next_ad_after_completion(ads_api_url, headers))
        except Exception as e:
            api_logger.error(f"Exception scheduling next-ad check after ad end: {e}")
    try:
        handle_ad_break_end.start()
    except Exception as e:
        api_logger.error(f"Failed to start ad-end routine: {e}")

# Handle upcoming Twitch Ads
async def handle_upcoming_ads():
    global CHANNEL_NAME, stream_online, ad_upcoming_notified
    last_notification_time = None
    last_ad_time = None
    last_snooze_count = None
    ad_upcoming_notified = False  # Initialize flag to prevent duplicate notifications
    while stream_online:
        try:
            last_notification_time, last_ad_time, last_snooze_count = await check_and_handle_ads(
                last_notification_time, last_ad_time, last_snooze_count
            )
            await sleep(10)  # Check every 10 seconds
        except Exception as e:
            api_logger.error(f"Error in handle_upcoming_ads loop: {e}")
            await sleep(10)

async def check_and_handle_ads(last_notification_time, last_ad_time, last_snooze_count=None):
    global stream_online, CHANNEL_ID, CLIENT_ID, CHANNEL_AUTH, ad_upcoming_notified
    ads_api_url = f"https://api.twitch.tv/helix/channels/ads?broadcaster_id={CHANNEL_ID}"
    headers = { "Client-ID": CLIENT_ID, "Authorization": f"Bearer {CHANNEL_AUTH}" }
    if not stream_online:
        return last_notification_time, last_ad_time, last_snooze_count
    try:
        async with httpClientSession() as session:
            async with session.get(ads_api_url, headers=headers) as response:
                if response.status != 200:
                    # Capture body for debugging
                    try:
                        body = await response.text()
                    except Exception:
                        body = '<could not read response body>'
                    api_logger.error(f"Failed to fetch ad data. Status: {response.status}, body: {body}")
                    return last_notification_time, last_ad_time, last_snooze_count
                data = await response.json()
                ads_data = data.get("data", [])
                if not ads_data:
                    api_logger.debug("No ad data available")
                    return last_notification_time, last_ad_time, last_snooze_count
                ad_info = ads_data[0]
                next_ad_at = ad_info.get("next_ad_at")
                duration = int(ad_info.get("duration"))
                preroll_free_time = int(ad_info.get("preroll_free_time", 0))
                snooze_count = int(ad_info.get("snooze_count", 0))
                last_ad_at = ad_info.get("last_ad_at")
                api_logger.debug(f"Ad info - next_ad_at: {next_ad_at}, duration: {duration}, preroll_free_time: {preroll_free_time}")
                skip_upcoming_check = False
                if last_snooze_count is not None and snooze_count < last_snooze_count:
                    settings = await get_ad_settings()
                    # Check global and individual settings for snoozed message
                    if settings and settings['enable_ad_notice'] and settings.get('enable_snoozed_ad_message', True):
                        snooze_message = settings['ad_snoozed_message'] if settings and settings['ad_snoozed_message'] else "Ads have been snoozed."
                        try:
                            sent_ok = await send_chat_message(snooze_message)
                            if not sent_ok:
                                api_logger.error(f"Failed to send snooze message: {snooze_message}")
                            else:
                                api_logger.info(f"Sent ad snoozed notification: {snooze_message}")
                        except Exception as e:
                            api_logger.error(f"Exception sending snooze message: {e}")
                    last_snooze_count = snooze_count
                    skip_upcoming_check = True
                    return last_notification_time, last_ad_time, last_snooze_count
                # Update the last snooze count
                last_snooze_count = snooze_count
                # Check if we have a scheduled ad
                if next_ad_at and not skip_upcoming_check:
                    try:
                        # Parse the next ad time
                        next_ad_datetime = datetime.fromtimestamp(int(next_ad_at), set_timezone.UTC)
                        current_time = time_right_now(set_timezone.UTC)
                        # Notify if ad is coming up in exactly 5 minutes and we haven't notified recently
                        time_until_ad = (next_ad_datetime - current_time).total_seconds()
                        if 270 <= time_until_ad <= 330:
                            if not ad_upcoming_notified and last_notification_time != next_ad_at:
                                minutes_until = 5
                                duration_text = format_duration(duration)
                                settings = await get_ad_settings()
                                # Check global and individual settings for upcoming message
                                if settings and settings['enable_ad_notice'] and settings.get('enable_upcoming_ad_message', True):
                                    if settings and settings['ad_upcoming_message']:
                                        message = settings['ad_upcoming_message']
                                        # Replace placeholders
                                        message = message.replace("(minutes)", str(minutes_until))
                                        message = message.replace("(duration)", duration_text)
                                    else:
                                        message = f"Heads up! An ad break is coming up in {minutes_until} minutes and will last {duration_text}."
                                    try:
                                        sent_ok = await send_chat_message(message)
                                        if not sent_ok:
                                            api_logger.error(f"Failed to send 5-minute ad notification: {message}")
                                        else:
                                            api_logger.info(f"Sent 5-minute ad notification: {message}")
                                    except Exception as e:
                                        api_logger.error(f"Exception while sending 5-minute ad notification: {e}")
                                else:
                                    api_logger.debug("Ad upcoming notification disabled by settings")
                                last_notification_time = next_ad_at
                                ad_upcoming_notified = True
                    except Exception as e:
                        api_logger.error(f"Error parsing ad time: {e}")
                if last_ad_at and last_ad_at != last_ad_time:
                    # A new ad just finished, reset notification time and schedule next ad check
                    api_logger.info("Ad break completed, checking for next scheduled ad")
                    last_notification_time = None
                    last_ad_time = last_ad_at
                    ad_upcoming_notified = False  # Reset flag for next ad
                    # Schedule a check for the next ad after a brief delay
                    create_task(check_next_ad_after_completion(ads_api_url, headers))
                # Log preroll free time for debugging
                if preroll_free_time > 0:
                    api_logger.debug(f"Preroll free time remaining: {preroll_free_time} seconds")
                return last_notification_time, last_ad_time, last_snooze_count
    except Exception as e:
        api_logger.error(f"Error in check_and_handle_ads: {e}")
        return last_notification_time, last_ad_time, last_snooze_count

async def check_next_ad_after_completion(ads_api_url, headers):
    global ad_upcoming_notified
    # Poll for up to 5 minutes (check every 10 seconds) to find the next ad and send timely notifications.
    timeout = 300
    interval = 10
    elapsed = 0
    while elapsed < timeout:
        try:
            async with httpClientSession() as session:
                async with session.get(ads_api_url, headers=headers) as response:
                    if response.status != 200:
                        try:
                            body = await response.text()
                        except Exception:
                            body = '<could not read response body>'
                        api_logger.error(f"Failed to fetch next ad data after completion. Status: {response.status}, body: {body}")
                        # Continue polling until timeout
                    else:
                        try:
                            data = await response.json()
                        except Exception as e:
                            # Log and continue polling
                            api_logger.error(f"Failed to parse JSON from next-ad response: {e}")
                        else:
                            ads_data = data.get("data", [])
                            if not ads_data:
                                api_logger.debug("No next ad data available after completion")
                            else:
                                ad_info = ads_data[0]
                                next_ad_at = ad_info.get("next_ad_at")
                                duration = ad_info.get("duration")
                                if next_ad_at:
                                    try:
                                        # Parse the next ad time
                                        next_ad_datetime = datetime.fromtimestamp(int(next_ad_at), set_timezone.UTC)
                                        current_time = time_right_now(set_timezone.UTC)
                                        time_until_ad = (next_ad_datetime - current_time).total_seconds()
                                        api_logger.info(f"Next ad scheduled in {time_until_ad} seconds ({time_until_ad/60:.1f} minutes)")
                                        if time_until_ad <= 300:  # 5 minutes or less
                                            if not ad_upcoming_notified:
                                                minutes_until = max(1, int(time_until_ad / 60))
                                                duration_text = format_duration(duration)
                                                settings = await get_ad_settings()
                                                if settings and settings['ad_upcoming_message']:
                                                    message = settings['ad_upcoming_message']
                                                    # Replace placeholders
                                                    message = message.replace("(minutes)", str(minutes_until))
                                                    message = message.replace("(duration)", duration_text)
                                                else:
                                                    message = f"Heads up! Another ad break is coming up in {minutes_until} minute{'s' if minutes_until != 1 else ''} and will last {duration_text}."
                                                try:
                                                    sent_ok = await send_chat_message(message)
                                                    if not sent_ok:
                                                        api_logger.error(f"Failed to send immediate next-ad notification: {message}")
                                                    else:
                                                        api_logger.info(f"Sent immediate next-ad notification: {message}")
                                                except Exception as e:
                                                    api_logger.error(f"Exception while sending immediate next-ad notification: {e}")
                                                ad_upcoming_notified = True
                                                return
                                    except Exception as e:
                                        api_logger.error(f"Error parsing next ad time after completion: {e}")
        except Exception as e:
            api_logger.error(f"Error checking next ad after completion: {e}")
        await sleep(interval)
        elapsed += interval

# Function to track chat messages for the bot counter
async def track_chat_message():
    # Construct bot_system identifier using SYSTEM variable (e.g., 'twitch_beta', 'twitch_stable')
    bot_system = f"twitch_{SYSTEM.lower()}"
    try:
        async with await mysql_handler.get_connection('website') as connection:
            async with connection.cursor(DictCursor) as cursor:
                # Get current record
                await cursor.execute(
                    "SELECT messages_sent, counted_since FROM bot_messages WHERE bot_system = %s",
                    (bot_system,)
                )
                record = await cursor.fetchone()
                if record is None:
                    # First entry for this bot system
                    await cursor.execute(
                        """INSERT INTO bot_messages (bot_system, counted_since, messages_sent, last_updated)
                           VALUES (%s, NOW(), 1, NOW())""",
                        (bot_system,)
                    )
                    chat_logger.info(f"Created initial tracking record for {bot_system}")
                elif record['messages_sent'] == 0 or record['counted_since'] is None:
                    # First message being counted
                    await cursor.execute(
                        """UPDATE bot_messages 
                           SET counted_since = NOW(), messages_sent = 1, last_updated = NOW()
                           WHERE bot_system = %s""",
                        (bot_system,)
                    )
                    chat_logger.debug(f"Initialized message counting for {bot_system}")
                else:
                    # Subsequent messages
                    await cursor.execute(
                        """UPDATE bot_messages 
                           SET messages_sent = messages_sent + 1, last_updated = NOW()
                           WHERE bot_system = %s""",
                        (bot_system,)
                    )
                await connection.commit()
    except Exception as e:
        chat_logger.error(f"Error tracking message for {bot_system}: {e}")

# Function to get custom bot credentials from database
async def get_custom_bot_credentials():
    try:
        async with await mysql_handler.get_connection('website') as connection:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute(
                    "SELECT bot_channel_id, access_token, token_expires FROM custom_bots WHERE bot_username = %s AND is_verified = 1 LIMIT 1",
                    (BOT_USERNAME,)
                )
                result = await cursor.fetchone()
                if result:
                    system_logger.debug(f"Fetched custom bot creds from DB for {BOT_USERNAME}")
                    # Parse token_expires to aware UTC datetime if present (DB uses Australia/Sydney timezone)
                    token_expires = None
                    if result.get('token_expires'):
                        try:
                            raw = result.get('token_expires')
                            # Accept either datetime objects or strings
                            if isinstance(raw, datetime):
                                naive = raw
                            else:
                                naive = datetime.strptime(str(raw), '%Y-%m-%d %H:%M:%S')
                            # Localize using DB timezone and convert to UTC
                            try:
                                local_tz = pytz_timezone('Australia/Sydney')
                                local_dt = local_tz.localize(naive) if naive.tzinfo is None else naive.astimezone(local_tz)
                                token_expires = local_dt.astimezone(timezone.utc)
                            except Exception:
                                # Fallback: treat naive as UTC
                                token_expires = naive.replace(tzinfo=timezone.utc)
                        except Exception:
                            token_expires = None
                    return {
                        'bot_channel_id': result.get('bot_channel_id'),
                        'access_token': result.get('access_token'),
                        'token_expires': token_expires
                    }
                else:
                    system_logger.error(f"No verified custom bot found for username: {BOT_USERNAME}")
                    return None
    except Exception as e:
        system_logger.error(f"Error fetching custom bot credentials: {e}")
        return None

# Lightweight expiry-aware in-memory cache keyed by bot username
_custom_creds_cache = {}
# Simple counter for retries after 401/403
_custom_token_retry_count = 0

async def get_current_custom_credentials():
    global _custom_creds_cache
    cache_entry = _custom_creds_cache.get(BOT_USERNAME)
    now = datetime.now(timezone.utc)
    # If cache present and not expired, return it
    if cache_entry:
        expires_at = cache_entry.get('expires_at')
        if expires_at and expires_at > now + timedelta(seconds=2):
            system_logger.debug(f"Using cached custom token for {BOT_USERNAME}, expires at {expires_at}")
            return cache_entry
    # Otherwise read DB for fresh creds
    creds = await get_custom_bot_credentials()
    if not creds:
        system_logger.debug(f"No custom creds available from DB for {BOT_USERNAME}")
        return None
    access_token = creds.get('access_token')
    expires_at = creds.get('token_expires')
    if expires_at and isinstance(expires_at, datetime):
        # Store in cache if expiry set
        _custom_creds_cache[BOT_USERNAME] = {
            'bot_channel_id': creds.get('bot_channel_id'),
            'access_token': access_token,
            'expires_at': expires_at
        }
        system_logger.debug(f"Cached custom token for {BOT_USERNAME} until {expires_at}")
    else:
        # Do not cache if no expiry
        _custom_creds_cache.pop(BOT_USERNAME, None)
        system_logger.debug(f"Not caching custom token for {BOT_USERNAME} (no expiry set)")
    return {
        'bot_channel_id': creds.get('bot_channel_id'),
        'access_token': access_token,
        'token_expires': expires_at
    }

# Function to send chat message via Twitch API
async def send_chat_message(message, for_source_only=True, reply_parent_message_id=None):
    global CLIENT_ID, CHANNEL_ID, CHANNEL_AUTH, TWITCH_OAUTH_API_TOKEN, TWITCH_OAUTH_API_CLIENT_ID
    if len(message) > 500:
        chat_logger.error(f"Message too long: {len(message)} characters (max 500)")
        return False
    # Determine credentials based on mode
    if SELF_MODE:
        sender_id = CHANNEL_ID
        access_token = CHANNEL_AUTH
        client_id = CLIENT_ID
    elif CUSTOM_MODE:
        # Use expiry-aware cached credentials when present, otherwise fetch from DB
        creds = await get_current_custom_credentials()
        if not creds:
            chat_logger.error(f"Failed to get custom bot credentials for {BOT_USERNAME}")
            return False
        sender_id = creds['bot_channel_id']
        access_token = creds['access_token']
        client_id = CLIENT_ID  # Use the same client ID
    else:
        # Use main bot credentials from environment
        sender_id = "971436498"
        access_token = TWITCH_OAUTH_API_TOKEN
        client_id = TWITCH_OAUTH_API_CLIENT_ID
    url = "https://api.twitch.tv/helix/chat/messages"
    headers = {
        "Authorization": f"Bearer {access_token}",
        "Client-Id": client_id,
        "Content-Type": "application/json"
    }
    data = {
        "broadcaster_id": CHANNEL_ID,
        "sender_id": sender_id,
        "message": message
    }
    if reply_parent_message_id:
        data["reply_parent_message_id"] = reply_parent_message_id
    # Use a shared aiohttp session to reduce transient connection issues
    async def _get_shared_session():
        global _shared_http_session
        try:
            if _shared_http_session is None:
                _shared_http_session = httpClientSession()
        except Exception:
            _shared_http_session = httpClientSession()
        return _shared_http_session
    async def _do_post(token_to_use):
        hdrs = headers.copy()
        hdrs['Authorization'] = f"Bearer {token_to_use}"
        session = await _get_shared_session()
        try:
            async with session.post(url, headers=hdrs, json=data, timeout=10) as resp:
                status = resp.status
                try:
                    text = await resp.text()
                except Exception:
                    text = None
                try:
                    j = await resp.json()
                except Exception:
                    j = None
                return status, text, j
        except Exception as e:
            # Surface the exception to caller for retry handling
            raise
    # Retry loop for transient network/server issues
    max_attempts = 3
    retryable_statuses = (502, 503, 504)
    for attempt in range(1, max_attempts + 1):
        try:
            status, resp_text, resp_json = await _do_post(access_token)
            if status == 200:
                response_data = resp_json
                if response_data and response_data.get("data"):
                    msg_data = response_data["data"][0]
                    message_id = msg_data.get("message_id")
                    is_sent = msg_data.get("is_sent", False)
                    drop_reason = msg_data.get("drop_reason")
                    if is_sent:
                        chat_logger.info(f"Successfully sent chat message: {message} (ID: {message_id})")
                        await track_chat_message()
                        return True
                    else:
                        chat_logger.error(f"Message not sent. Drop reason: {drop_reason}")
                        return False
                else:
                    chat_logger.error(f"No data in response; text={resp_text}")
                    return False
            # Handle token expiry for custom mode
            if status in (401, 403) and CUSTOM_MODE:
                chat_logger.warning(f"Custom bot token invalid/expired for {BOT_USERNAME}. Re-fetching credentials from DB and retrying.")
                fresh = await get_custom_bot_credentials()
                if not fresh or fresh.get('access_token') == access_token:
                    text = resp_text
                    chat_logger.error(f"Failed to send chat message after DB re-fetch: {status} - {text}")
                    _custom_creds_cache.pop(BOT_USERNAME, None)
                    return False
                try:
                    global _custom_token_retry_count
                    _custom_token_retry_count += 1
                    system_logger.info(f"Custom token retry for {BOT_USERNAME} count={_custom_token_retry_count}")
                except Exception:
                    pass
                if fresh.get('token_expires'):
                    _custom_creds_cache[BOT_USERNAME] = {
                        'bot_channel_id': fresh.get('bot_channel_id'),
                        'access_token': fresh.get('access_token'),
                        'expires_at': fresh.get('token_expires')
                    }
                status2, resp_text2, resp_json2 = await _do_post(fresh.get('access_token'))
                if status2 == 200:
                    response_data = resp_json2
                    if response_data and response_data.get("data") and response_data["data"][0].get("is_sent"):
                        chat_logger.info(f"Sent chat message after DB refresh: {message}")
                        await track_chat_message()
                        return True
                    else:
                        text = resp_text2
                        chat_logger.error(f"Failed on retry after DB refresh: {status2} - {text}")
                        return False
                else:
                    text = resp_text2
                    chat_logger.error(f"Retry failed after DB refresh: {status2} - {text}")
                    return False
            # Retry on server errors
            if status in retryable_statuses:
                text = resp_text
                chat_logger.warning(f"Transient error sending chat message (status {status}): {text}. Attempt {attempt}/{max_attempts}")
                if attempt < max_attempts:
                    await sleep(0.5 * attempt + (random.random() * 0.2))
                    continue
                else:
                    return False
            # Unretryable failure
            error_text = resp_text
            chat_logger.error(f"Failed to send chat message: {status} - {error_text}")
            return False
        except aiohttpClientError as net_err:
            chat_logger.warning(f"Network error sending chat message (attempt {attempt}/{max_attempts}): {net_err}")
            if attempt < max_attempts:
                await sleep(0.5 * attempt + (random.random() * 0.2))
                continue
            else:
                chat_logger.error(f"Error sending chat message: {net_err}")
                return False
        except Exception as e:
            chat_logger.error(f"Error sending chat message: {e}")
            return False
    # All retries failed
    chat_logger.error("Exhausted retries sending chat message")
    return False

# Function to generate shoutout message with game info
async def get_shoutout_message(user_id, user_name, action="command"):
    game = await get_latest_stream_game(user_id, user_name)
    # For raids, we know the user was just streaming, so always include game info
    if action == "raid":
        if game:
            shoutout_message = (
                f"Hey, huge shoutout to @{user_name}! "
                f"You should go give them a follow over at "
                f"https://www.twitch.tv/{user_name} where they were playing: {game}"
            )
        else:
            # Fallback if game fetch fails for raid
            shoutout_message = (
                f"Hey, huge shoutout to @{user_name}! "
                f"You should go give them a follow over at "
                f"https://www.twitch.tv/{user_name}"
            )
    else:
        # For other actions, check if game exists before including it
        if game:
            shoutout_message = (
                f"Hey, huge shoutout to @{user_name}! "
                f"You should go give them a follow over at "
                f"https://www.twitch.tv/{user_name} where they were playing: {game}"
            )
        else:
            shoutout_message = (
                f"Hey, huge shoutout to @{user_name}! "
                f"You should go give them a follow over at "
                f"https://www.twitch.tv/{user_name}"
            )
    return shoutout_message

# Function to manage user points
async def manage_user_points(user_id: str, user_name: str, action: str, amount: int = 0) -> dict:
    try:
        async with await mysql_handler.get_connection() as connection:
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT points FROM bot_points WHERE user_id = %s", (user_id,))
                result = await cursor.fetchone()
                if result:
                    current_points = result.get("points", 0)
                else:
                    await cursor.execute(
                        "INSERT INTO bot_points (user_id, user_name, points) VALUES (%s, %s, 0)",
                        (user_id, user_name)
                    )
                    await connection.commit()
                    current_points = 0
                previous_points = current_points
                if action == "get":
                    return {
                        "success": True,
                        "points": current_points,
                        "previous_points": current_points,
                        "amount_changed": 0,
                        "error": None
                    }
                elif action == "credit":
                    new_points = current_points + amount
                    await cursor.execute(
                        "UPDATE bot_points SET points = %s WHERE user_id = %s",
                        (new_points, user_id)
                    )
                    await connection.commit()
                    return {
                        "success": True,
                        "points": new_points,
                        "previous_points": previous_points,
                        "amount_changed": amount,
                        "error": None
                    }
                elif action == "debit":
                    actual_debit = min(amount, current_points)
                    new_points = max(0, current_points - amount)
                    await cursor.execute(
                        "UPDATE bot_points SET points = %s WHERE user_id = %s",
                        (new_points, user_id)
                    )
                    await connection.commit()
                    return {
                        "success": True,
                        "points": new_points,
                        "previous_points": previous_points,
                        "amount_changed": actual_debit,
                        "error": None
                    }
                else:
                    return {
                        "success": False,
                        "points": current_points,
                        "previous_points": current_points,
                        "amount_changed": 0,
                        "error": f"Invalid action: {action}. Use 'get', 'credit', or 'debit'."
                    }
    except Exception as e:
        bot_logger.error(f"Error in manage_user_points (action={action}, user={user_name}): {e}")
        return {
            "success": False,
            "points": 0,
            "previous_points": 0,
            "amount_changed": 0,
            "error": str(e)
        }

async def process_chat_message_event(user_id: str, user_name: str, message: str = ""):
    try:
        get_function_from = BOTS_TWITCH_BOT
        await get_function_from.message_counting_and_welcome_messages(user_name, user_id, False, message)
        await get_function_from.user_points(user_name, user_id)
        event_logger.info(f"Processed chat message from {user_name}: welcome check + points awarded")
    except Exception as e:
        event_logger.error(f"Error processing chat message event for {user_name}: {e}")

async def cleanup_gift_sub_tracking():
    global gift_sub_recipients
    while True:
        try:
            await sleep(60)  # Run cleanup every minute
            current_time = time.time()
            # Remove entries older than tracking duration
            expired_ids = [
                user_id for user_id, timestamp in gift_sub_recipients.items()
                if current_time - timestamp > GIFT_SUB_TRACKING_DURATION
            ]
            for user_id in expired_ids:
                del gift_sub_recipients[user_id]
            if expired_ids:
                event_logger.debug(f"Cleaned up {len(expired_ids)} expired gift sub tracking entries")
        except Exception as e:
            event_logger.error(f"Error in gift sub tracking cleanup: {e}")

# Determine the correct OAuth token based on mode
if CUSTOM_MODE:
    # Use the broadcaster/channel auth token for TwitchIO connection when running in CUSTOM mode.
    # The chat-sending functions will always read the most recent custom bot token from the DB
    # when issuing Helix chat messages so we rely on the external token refresh system.
    BOT_OAUTH_TOKEN = CHANNEL_AUTH
    if SELF_MODE and not args.custom_mode:
        bot_logger.info(f"Running in CUSTOM mode (self) using broadcaster account: {BOT_USERNAME}")
    else:
        bot_logger.info(f"Running in CUSTOM mode with bot username: {BOT_USERNAME}")
else:
    # In standard mode, use the main bot OAuth token from environment
    BOT_OAUTH_TOKEN = OAUTH_TOKEN
    bot_logger.info(f"Running in BETA mode with bot username: {BOT_USERNAME}")

# Here is the TwitchBot
BOTS_TWITCH_BOT = TwitchBot(
    token=BOT_OAUTH_TOKEN,
    prefix='!',
    channel_name=CHANNEL_NAME
)

# Initialize SSH Connection Manager
ssh_manager = SSHConnectionManager(system_logger)

# Run the bot
def start_bot():
    # Validate configuration before starting
    if CUSTOM_MODE and not BOT_USERNAME:
        system_logger.error("Custom mode requires -botusername argument")
        raise ValueError("Custom mode enabled but no bot username provided. Use -botusername flag.")
    if not BOT_OAUTH_TOKEN:
        system_logger.error(f"No OAuth token available for bot mode: {'CUSTOM' if CUSTOM_MODE else 'BETA'}")
        raise ValueError("OAuth token is missing. Check configuration.")
    system_logger.info("===== Initializing Twitch Bot =====")
    system_logger.info(f"Starting bot for channel: {CHANNEL_NAME}")
    system_logger.info(f"Bot username: {BOT_USERNAME}")
    system_logger.info(f"System: {'Custom Bot Name' if SYSTEM == 'CUSTOM' else SYSTEM}")
    system_logger.info(f"Version: {VERSION}")
    system_logger.info(f"Start Time: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    system_logger.info("===== Initialization Complete =====")
    # Start the bot
    BOTS_TWITCH_BOT.run()

if __name__ == '__main__':
    start_bot()