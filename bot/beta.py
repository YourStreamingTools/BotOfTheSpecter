# Standard library imports
import os
import re
import asyncio
from asyncio import Queue
from asyncio import subprocess
import argparse
import datetime
from datetime import datetime, timezone, timedelta
import logging
from logging.handlers import RotatingFileHandler
import json
import time
import random
import base64
import uuid
from urllib.parse import urlencode
import ast
import signal
import sys

# Third-party imports
import aiohttp
from aiohttp import ClientSession
import socketio
from socketio import AsyncClient as SocketClient
import aiomysql
from deep_translator import GoogleTranslator
from twitchio.ext import commands, routines
import streamlink
import pytz
from geopy.geocoders import Nominatim
from jokeapi import Jokes
import websockets
from pint import UnitRegistry

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
args = parser.parse_args()

# Twitch bot settings
CHANNEL_NAME = args.target_channel
CHANNEL_ID = args.channel_id
CHANNEL_AUTH = args.channel_auth_token
REFRESH_TOKEN = args.refresh_token
API_TOKEN = args.api_token
BOT_USERNAME = "botofthespecter"
VERSION = "5.4"
SYSTEM = "BETA"
SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER')
SQL_PASSWORD = os.getenv('SQL_PASSWORD')
ADMIN_API_KEY = os.getenv('ADMIN_KEY')
USE_BACKUP_SYSTEM = os.getenv('USE_BACKUP_SYSTEM', 'False').lower() == 'true'
if USE_BACKUP_SYSTEM:
    BACKUP_SYSTEM = True
    OAUTH_TOKEN = f"oauth:{CHANNEL_AUTH}"
    CLIENT_ID = os.getenv('BACKUP_CLIENT_ID')
    CLIENT_SECRET = os.getenv('BACKUP_SECRET_KEY')
else:
    BACKUP_SYSTEM = False
    OAUTH_TOKEN = os.getenv('OAUTH_TOKEN')
    CLIENT_ID = os.getenv('CLIENT_ID')
    CLIENT_SECRET = os.getenv('CLIENT_SECRET')
TWITCH_GQL = os.getenv('TWITCH_GQL')
SHAZAM_API = os.getenv('SHAZAM_API')
STEAM_API = os.getenv('STEAM_API')
SPOTIFY_CLIENT_ID = os.getenv('SPOTIFY_CLIENT_ID')
SPOTIFY_CLIENT_SECRET = os.getenv('SPOTIFY_CLIENT_SECRET')
EXCHANGE_RATE_API_KEY = os.getenv('EXCHANGE_RATE_API')
HYPERATE_API_KEY = os.getenv('HYPERATE_API_KEY')
builtin_commands = {
    "commands", "bot", "roadmap", "quote", "rps", "story", "roulette", "songrequest", "songqueue", "watchtime", "stoptimer",
    "checktimer", "version", "convert", "subathon", "todo", "kill", "points", "slots", "timer", "game", "joke", "ping",
    "weather", "time", "song", "translate", "cheerleader", "steam", "schedule", "mybits", "lurk", "unlurk", "lurking",
    "lurklead", "clip", "subscription", "hug", "highfive", "kiss", "uptime", "typo", "typos", "followage", "deaths",
    "heartrate"
}
mod_commands = {
    "addcommand", "removecommand", "editcommand", "removetypos", "addpoints", "removepoints", "permit", "removequote", "quoteadd",
    "settitle", "setgame", "edittypos", "deathadd", "deathremove", "shoutout", "marker", "checkupdate", "startlotto", "drawlotto"
}
builtin_aliases = {
    "cmds", "back", "so", "typocount", "edittypo", "removetypo", "death+", "death-", "mysub", "sr", "lurkleader"
}

# Logs
webroot = "/var/www/"
logs_directory = os.path.join(webroot, "logs")
log_types = ["bot", "chat", "twitch", "api", "chat_history", "event_log", "websocket"]

# Ensure directories exist
for log_type in log_types:
    directory_path = os.path.join(logs_directory, log_type)
    os.makedirs(directory_path, mode=0o755, exist_ok=True)

# Create a function to setup individual loggers for clarity
def setup_logger(name, log_file, level=logging.INFO):
    logger = logging.getLogger(name)
    logger.setLevel(level)
    # Clear any existing handlers to prevent duplicates
    if logger.hasHandlers():
        logger.handlers.clear()
    # Setup rotating file handler
    handler = RotatingFileHandler(
        log_file,
        maxBytes=10485760, # 10MB
        backupCount=5,
        encoding='utf-8'
    )
    formatter = logging.Formatter('%(asctime)s - %(name)s - %(levelname)s - %(message)s', datefmt='%Y-%m-%d %H:%M:%S')
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

# Log startup messages
startup_msg = f"Logger initialized for channel: {CHANNEL_NAME} (Bot Version: {VERSION}{SYSTEM})"
for logger in loggers.values():
    logger.info(startup_msg)

# Setup Globals
global bot_owner
global stream_online
global current_game
global stream_title
global bot_started
global SPOTIFY_REFRESH_TOKEN
global SPOTIFY_ACCESS_TOKEN
global next_spotify_refresh_time
global HEARTRATE
global TWITCH_SHOUTOUT_GLOBAL_COOLDOWN
global TWITCH_SHOUTOUT_USER_COOLDOWN
global last_shoutout_time
global shoutout_user
global last_message_time

# Initialize instances for the translator, shoutout queue, websockets, and permitted users for protection
translator = GoogleTranslator()                         # Translator instance 
scheduled_tasks = set()                                 # Set for scheduled tasks
shoutout_queue = Queue()                                # Queue for shoutouts
specterSocket = SocketClient()                          # Socket client instance for specter
hyperateSocket = SocketClient()                         # Socket client instance for hyperate
ureg = UnitRegistry()                                   # Unit registry instance
permitted_users = {}                                    # Dictionary for permitted users
connected = set()                                       # Set for connected users
pending_removals = {}                                   # Dictionary for pending removals
shoutout_tracker = {}                                   # Dictionary for tracking shoutouts
command_last_used = {}                                  # Dictionary for tracking command usage
last_poll_progress_update = 0                           # Variable for last poll progress update
last_message_time = 0                                   # Variable for last message time
chat_line_count = 0                                     # Tracks the number of chat messages
chat_trigger_tasks = {}                                 # Maps message IDs to chat line counts
song_requests = {}                                      # Tracks song request from users
looped_tasks = {}                                       # Set for looped tasks

# Initialize global variables
bot_started = datetime.now()                            # Time the bot started
stream_online = False                                   # Whether the stream is currently online 
current_game = None                                     # Current game being streamed 
stream_title = None                                     # Title of the stream 
SPOTIFY_REFRESH_TOKEN = None                            # Spotify API refresh token 
SPOTIFY_ACCESS_TOKEN = None                             # Spotify API access token 
next_spotify_refresh_time = None                        # Time for the next Spotify token refresh 
HEARTRATE = None                                        # Current heart rate value 
TWITCH_SHOUTOUT_GLOBAL_COOLDOWN = timedelta(minutes=2)  # Global cooldown for shoutouts
TWITCH_SHOUTOUT_USER_COOLDOWN = timedelta(minutes=60)   # User-specific cooldown for shoutouts
last_shoutout_time = datetime.min                       # Last time a shoutout was performed
bot_owner = "gfaundead"                                 # Bot owner's username

# Function to handle termination signals
async def signal_handler(sig, frame):
    bot_logger.info("Received termination signal. Shutting down gracefully...")
    await specterSocket.disconnect()      # Disconnect the SocketClient
    await hyperateSocket.disconnect()     # Disconnect the SocketClient
    sys.exit(0)  # Exit the program

# Register the signal handler
signal.signal(signal.SIGTERM, signal_handler)
signal.signal(signal.SIGINT, signal_handler)  # Handle Ctrl+C as well

# Setup Token Refresh
async def twitch_token_refresh():
    global REFRESH_TOKEN
    # Wait for 5 minutes before the first token refresh
    await asyncio.sleep(300)
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
            await asyncio.sleep(sleep_time)

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
        async with aiohttp.ClientSession() as session:
            async with session.post(url, data=body) as response:
                if response.status == 200:
                    response_json = await response.json()
                    new_access_token = response_json.get('access_token')
                    expires_in = response_json.get('expires_in', 14400)  # Default to 4 hours if not provided
                    next_refresh_time = time.time() + expires_in - 300  # Refresh 5 minutes before expiration
                    if new_access_token:
                        # Update the global access token
                        CHANNEL_AUTH = new_access_token
                        if BACKUP_SYSTEM:
                            OAUTH_TOKEN = f"oauth:{CHANNEL_AUTH}"
                        twitch_logger.info(f"Refreshed token. New Access Token: {CHANNEL_AUTH}.")
                        sqldb = await access_website_database()
                        try:
                            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                                # Insert or update the access token for the given twitch_user_id
                                query = "INSERT INTO twitch_bot_access (twitch_user_id, twitch_access_token) VALUES (%s, %s) ON DUPLICATE KEY UPDATE twitch_access_token = %s;"
                                await cursor.execute(query, (CHANNEL_ID, CHANNEL_AUTH, CHANNEL_AUTH))
                                await sqldb.commit()
                        except Exception as e:
                            twitch_logger.error(f"Database update failed: {e}")
                        finally:
                            await sqldb.ensure_closed()
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

# Setup Spotify Access
async def spotify_token_refresh():
    global SPOTIFY_REFRESH_TOKEN, SPOTIFY_ACCESS_TOKEN, next_spotify_refresh_time
    try:
        # Connect to the database to retrieve the user's Spotify tokens
        sqldb = await access_website_database()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            # Fetch the user ID for the specified CHANNEL_NAME
            await cursor.execute("SELECT id FROM users WHERE username = %s", (CHANNEL_NAME,))
            user_row = await cursor.fetchone()
            if user_row:
                user_id = user_row["id"]
                # Fetch the Spotify tokens associated with the user_id
                await cursor.execute("SELECT access_token, refresh_token FROM spotify_tokens WHERE user_id = %s", (user_id,))
                tokens_row = await cursor.fetchone()
                if not tokens_row:
                    bot_logger.info(f"No Spotify tokens found for user {CHANNEL_NAME}.")
                    await sqldb.ensure_closed()
                    return
                SPOTIFY_ACCESS_TOKEN = tokens_row["access_token"]
                SPOTIFY_REFRESH_TOKEN = tokens_row["refresh_token"]
            else:
                bot_logger.error(f"No user found with username {CHANNEL_NAME}.")
                await sqldb.ensure_closed()
                return
        await sqldb.ensure_closed()
        await asyncio.sleep(300)  # 5 minutes initial sleep
        SPOTIFY_ACCESS_TOKEN, SPOTIFY_REFRESH_TOKEN, next_spotify_refresh_time = await refresh_spotify_token(SPOTIFY_REFRESH_TOKEN, user_id)
        # Set next refresh time to 55 minutes from now (1 hour - 5 minutes buffer)
        next_spotify_refresh_time = time.time() + 60 * 60 - 300
        while True:
            current_time = time.time()
            if current_time >= next_spotify_refresh_time:
                SPOTIFY_ACCESS_TOKEN, SPOTIFY_REFRESH_TOKEN, next_spotify_refresh_time = await refresh_spotify_token(SPOTIFY_REFRESH_TOKEN, user_id)
            else:
                time_until_expiration = next_spotify_refresh_time - current_time
                sleep_time = min(60, max(300, time_until_expiration)) # Adjust sleep time dynamically
                await asyncio.sleep(sleep_time)
    except Exception as e:
        bot_logger.error(f"An error occurred in spotify_token_refresh: {e}")

# Function to refresh Spotify token
async def refresh_spotify_token(current_refresh_token, user_id):
    global SPOTIFY_ACCESS_TOKEN, SPOTIFY_REFRESH_TOKEN, next_spotify_refresh_time
    url = "https://accounts.spotify.com/api/token"
    data = {
        "grant_type": "refresh_token",
        "refresh_token": current_refresh_token,
        "client_id": SPOTIFY_CLIENT_ID,
        "client_secret": SPOTIFY_CLIENT_SECRET,
    }
    try:
        async with aiohttp.ClientSession() as session:
            async with session.post(url, data=data) as response:
                if response.status == 200:
                    tokens = await response.json()
                    new_access_token = tokens.get("access_token")
                    new_refresh_token = tokens.get("refresh_token", current_refresh_token) # Use existing if not provided
                    expires_in = tokens.get("expires_in", 3600) # Default to 1 hour if not provided
                    next_refresh_time = time.time() + expires_in - 300 # Refresh 5 minutes before expiration
                    SPOTIFY_ACCESS_TOKEN = new_access_token
                    SPOTIFY_REFRESH_TOKEN = new_refresh_token
                    # Save the new tokens in the database
                    sqldb = await access_website_database()
                    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                        await cursor.execute(
                            "UPDATE spotify_tokens SET access_token = %s, refresh_token = %s WHERE user_id = %s",
                            (new_access_token, new_refresh_token, user_id)
                        )
                        await sqldb.commit()
                    await sqldb.ensure_closed()
                    return new_access_token, new_refresh_token, next_refresh_time
                else:
                    error_response = await response.json()
                    bot_logger.error(f"Spotify token refresh failed: HTTP {response.status} - {error_response}")
                    return SPOTIFY_ACCESS_TOKEN, SPOTIFY_REFRESH_TOKEN, next_spotify_refresh_time
    except Exception as e:
        bot_logger.error(f"Spotify token refresh error: {e}")
        return SPOTIFY_ACCESS_TOKEN, SPOTIFY_REFRESH_TOKEN, next_spotify_refresh_time

# Setup Twitch EventSub
async def twitch_eventsub():
    twitch_websocket_uri = "wss://eventsub.wss.twitch.tv/ws?keepalive_timeout_seconds=600"
    while True:
        try:
            async with websockets.connect(twitch_websocket_uri) as twitch_websocket:
                # Receive and parse the welcome message
                eventsub_welcome_message = await twitch_websocket.recv()
                eventsub_welcome_data = json.loads(eventsub_welcome_message)
                # Validate the message type
                if eventsub_welcome_data.get('metadata', {}).get('message_type') == 'session_welcome':
                    session_id = eventsub_welcome_data['payload']['session']['id']
                    keepalive_timeout = eventsub_welcome_data['payload']['session']['keepalive_timeout_seconds']
                    event_logger.info(f"Connected with session ID: {session_id}")
                    event_logger.info(f"Keepalive timeout: {keepalive_timeout} seconds")
                    # Subscribe to the events using the session ID and auth token
                    await subscribe_to_events(session_id)
                    # Manage keepalive and listen for messages concurrently
                    await asyncio.gather(twitch_receive_messages(twitch_websocket, keepalive_timeout))
        except websockets.ConnectionClosedError as e:
            event_logger.error(f"WebSocket connection closed unexpectedly: {e}")
            await asyncio.sleep(10)  # Wait before retrying
        except Exception as e:
            event_logger.error(f"An unexpected error occurred: {e}")
            await asyncio.sleep(10)  # Wait before reconnecting

async def subscribe_to_events(session_id):
    global CHANNEL_ID, CHANNEL_AUTH, CLIENT_ID
    url = "https://api.twitch.tv/helix/eventsub/subscriptions"
    headers = {
        "Client-Id": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}",
        "Content-Type": "application/json"
    }
    v1topics = [
        "channel.moderate",
        "stream.online",
        "stream.offline",
        "channel.subscribe",
        "channel.subscription.gift",
        "channel.subscription.message",
        "channel.cheer",
        "channel.raid",
        "channel.hype_train.begin",
        "channel.hype_train.end",
        "channel.ad_break.begin",
        "channel.charity_campaign.donate",
        "channel.channel_points_automatic_reward_redemption.add",
        "channel.channel_points_custom_reward_redemption.add",
        "channel.poll.begin",
        "channel.poll.end",
        "automod.message.hold",
        "channel.suspicious_user.message",
        "channel.shoutout.create",
        "channel.shoutout.receive"
    ]
    v2topics = [
        "channel.follow",
        "channel.update"
    ]
    responses = []
    async with aiohttp.ClientSession() as v1topic_session:
        for v1topic in v1topics:
            if v1topic == "channel.raid":
                payload = {
                    "type": v1topic,
                    "version": "1",
                    "condition": {
                        "to_broadcaster_user_id": CHANNEL_ID
                    },
                    "transport": {
                        "method": "websocket",
                        "session_id": session_id
                    }
                }
            elif v1topic == "automod.message.hold":
                payload = {
                    "type": v1topic,
                    "version": "1",
                    "condition": {
                        "broadcaster_user_id": CHANNEL_ID,
                        "moderator_user_id": CHANNEL_ID
                    },
                    "transport": {
                        "method": "websocket",
                        "session_id": session_id
                    }
                }
            elif v1topic == "channel.suspicious_user.message":
                payload = {
                    "type": v1topic,
                    "version": "1",
                    "condition": {
                        "broadcaster_user_id": CHANNEL_ID,
                        "moderator_user_id": CHANNEL_ID
                    },
                    "transport": {
                        "method": "websocket",
                        "session_id": session_id
                    }
                }
            elif v1topic == "channel.chat.user_message_hold":
                payload = {
                    "type": v1topic,
                    "version": "1",
                    "condition": {
                        "broadcaster_user_id": CHANNEL_ID,
                        "moderator_user_id": CHANNEL_ID
                    },
                    "transport": {
                        "method": "websocket",
                        "session_id": session_id
                    }
                }
            elif v1topic == "channel.shoutout.create" or v1topic == "channel.shoutout.receive":
                payload = {
                    "type": v1topic,
                    "version": "1",
                    "condition": {
                        "broadcaster_user_id": CHANNEL_ID,
                        "moderator_user_id": CHANNEL_ID
                    },
                    "transport": {
                        "method": "websocket",
                        "session_id": session_id
                    }
                }
            else:
                payload = {
                    "type": v1topic,
                    "version": "1",
                    "condition": {
                        "broadcaster_user_id": CHANNEL_ID
                    },
                    "transport": {
                        "method": "websocket",
                        "session_id": session_id
                    }
                }
            # asynchronous POST request
            async with v1topic_session.post(url, headers=headers, json=payload) as response:
                if response.status in (200, 202):
                    responses.append(await response.json())
                    twitch_logger.info(f"Subscribed to {v1topic} successfully.")
    async with aiohttp.ClientSession() as v2topic_session:
        for v2topic in v2topics:
            if v2topic == "channel.follow":
                payload = {
                    "type": v2topic,
                    "version": "2",
                    "condition": {
                        "broadcaster_user_id": CHANNEL_ID,
                        "moderator_user_id": CHANNEL_ID
                    },
                    "transport": {
                        "method": "websocket",
                        "session_id": session_id
                    }
                }
            else:
                payload = {
                    "type": v2topic,
                    "version": "2",
                    "condition": {
                        "broadcaster_user_id": CHANNEL_ID
                    },
                    "transport": {
                        "method": "websocket",
                        "session_id": session_id
                    }
                }
            # asynchronous POST request
            async with v2topic_session.post(url, headers=headers, json=payload) as response:
                if response.status in (200, 202):
                    responses.append(await response.json())
                    twitch_logger.info(f"Subscribed to {v2topic} successfully.")

async def twitch_receive_messages(twitch_websocket, keepalive_timeout):
    while True:
        try:
            message = await asyncio.wait_for(twitch_websocket.recv(), timeout=keepalive_timeout)
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
        except asyncio.TimeoutError:
            event_logger.error("Keepalive timeout exceeded, reconnecting...")
            await twitch_websocket.close()
            continue  # Continue the loop to allow reconnection logic
        except websockets.ConnectionClosedError as e:
            event_logger.error(f"WebSocket connection closed unexpectedly: {str(e)}")
            break  # Exit the loop for reconnection
        except Exception as e:
            event_logger.error(f"Error receiving message: {e}")
            break  # Exit the loop on critical error

async def connect_to_tipping_services():
    global streamelements_token, streamlabs_token
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute("SELECT StreamElements, StreamLabs FROM tipping_settings LIMIT 1")
            result = await cursor.fetchone()
            if result:
                streamelements_token = result.get('StreamElements')
                streamlabs_token = result.get('StreamLabs')
                tasks = []
                if streamelements_token:
                    tasks.append(connect_to_streamelements())
                if streamlabs_token:
                    tasks.append(connect_to_streamlabs())
                if tasks:
                    await asyncio.gather(*tasks)
                else:
                    event_logger.error("No valid token found for either StreamElements or StreamLabs.")
            else:
                event_logger.error("No tipping settings found in the database.")
    except aiomysql.MySQLError as err:
        event_logger.error(f"Database error: {err}")
    finally:
        await sqldb.ensure_closed()

async def connect_to_streamelements():
    global streamelements_token
    uri = "wss://astro.streamelements.com"
    try:
        async with websockets.connect(uri) as streamelements_websocket:
            # Send the authentication message
            nonce = str(uuid.uuid4())
            auth_message = {
                'type': 'subscribe',
                'nonce': nonce,
                'data': {
                    'topic': 'channel.activities',
                    'token': streamelements_token,
                    'token_type': 'jwt'
                }
            }
            await streamelements_websocket.send(json.dumps(auth_message))
            sanitized_auth_message = auth_message.copy()
            sanitized_auth_message['data']['token'] = "[REDACTED]"
            event_logger.info(f"Sent auth message: {sanitized_auth_message}")
            # Listen for messages
            while True:
                message = await streamelements_websocket.recv()
                sanitized_message = message.replace(streamelements_token, "[REDACTED]")
                event_logger.info(f"StreamElements Message: {sanitized_message}")
                await process_message(message, "StreamElements")
    except websockets.ConnectionClosed as e:
        event_logger.error(f"StreamElements WebSocket connection closed: {e}")
    except Exception as e:
        event_logger.error(f"StreamElements WebSocket error: {e}")

async def connect_to_streamlabs():
    global streamlabs_token
    uri = f"wss://sockets.streamlabs.com/socket.io/?token={streamlabs_token}&EIO=3&transport=websocket"
    sanitized_uri = uri.replace(streamlabs_token, "[REDACTED]")
    try:
        async with websockets.connect(uri) as streamlabs_websocket:
            event_logger.info(f"Connected to StreamLabs WebSocket with URI: {sanitized_uri}")
            # Listen for messages
            while True:
                message = await streamlabs_websocket.recv()
                sanitized_message = message.replace(streamlabs_token, "[REDACTED]")
                event_logger.info(f"StreamLabs Message: {sanitized_message}")
                await process_message(message, "StreamLabs")
    except websockets.ConnectionClosed as e:
        event_logger.error(f"StreamLabs WebSocket connection closed: {e}")
    except Exception as e:
        event_logger.error(f"StreamLabs WebSocket error: {e}")

async def process_message(message, source):
    global streamelements_token, streamlabs_token
    try:
        data = json.loads(message)
        if source == "StreamElements":
            if data.get('type') == 'response':
                # Handle the subscription response
                if 'error' in data:
                    sanitized_message = data['data']['message'].replace(streamelements_token, "[REDACTED]") if 'message' in data['data'] else None
                    handle_streamelements_error(data['error'], sanitized_message)
                else:
                    sanitized_message = data['data']['message'].replace(streamelements_token, "[REDACTED]") if 'message' in data['data'] else None
                    event_logger.info(f"StreamElements subscription success: {sanitized_message}")
            else:
                sanitized_message = json.dumps(data).replace(streamelements_token, "[REDACTED]")
                await process_tipping_message(json.loads(sanitized_message), source)
        elif source == "StreamLabs":
            sanitized_message = message.replace(streamlabs_token, "[REDACTED]")
            await process_tipping_message(json.loads(sanitized_message), source)
    except Exception as e:
        event_logger.error(f"Error processing message from {source}: {e}")

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
    event_logger.error(f"StreamElements error: {error_message} - {sanitized_message}")

async def process_tipping_message(data, source):
    try:
        channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
        send_message = None
        if source == "StreamElements" and data.get('type') == 'tip':
            user = data['data']['username']
            amount = data['data']['amount']
            tip_message = data['data']['message']
            send_message = f"{user} just tipped {amount}! Message: {tip_message}"
            event_logger.info(f"StreamElemenets Tip: {send_message}")
        elif source == "StreamLabs" and 'event' in data and data['event'] == 'donation':
            for donation in data['data']['donations']:
                user = donation['name']
                amount = donation['amount']
                tip_message = donation['message']
                send_message = f"{user} just tipped {amount}! Message: {tip_message}"
                event_logger.info(f"StreamLabs Tip: {send_message}")
        if send_message:
            await channel.send(send_message)
            # Save tipping data directly in this function
            sqldb = await get_mysql_connection()
            try:
                async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute(
                        "INSERT INTO tipping (username, amount, message, source) VALUES (%s, %s, %s, %s)",
                        (user, amount, tip_message, source)
                    )
                    await sqldb.commit()
            except aiomysql.MySQLError as err:
                event_logger.error(f"Database error: {err}")
            finally:
                await sqldb.ensure_closed()
    except Exception as e:
        event_logger.error(f"Error processing tipping message: {e}")

async def process_twitch_eventsub_message(message):
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
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
                    asyncio.create_task(process_followers_event(
                        event_data["user_id"],
                        event_data["user_name"]
                    ))
                # Subscription Event
                elif event_type == "channel.subscribe":
                    tier = event_data["tier"]
                    tier_name = tier_mapping.get(tier, tier)
                    asyncio.create_task(process_subscription_event(
                        event_data["user_id"],
                        event_data["user_name"],
                        tier_name,
                        event_data.get("cumulative_months", 1)
                    ))
                # Subscription Message Event
                elif event_type == "channel.subscription.message":
                    tier = event_data["tier"]
                    tier_name = tier_mapping.get(tier, tier)
                    subscription_message = event_data.get("message", {}).get("text", "")
                    asyncio.create_task(process_subscription_message_event(
                        event_data["user_id"],
                        event_data["user_name"],
                        tier_name,
                        event_data.get("cumulative_months", 1)
                    ))
                # Subscription Gift Event
                elif event_type == "channel.subscription.gift":
                    tier = event_data["tier"]
                    tier_name = tier_mapping.get(tier, tier)
                    asyncio.create_task(process_giftsub_event(
                        event_data["user_name"],
                        tier_name,
                        event_data["total"],
                        event_data.get("is_anonymous", False),
                        event_data.get("cumulative_total")
                    ))
                # Cheer Event
                elif event_type == "channel.cheer":
                    asyncio.create_task(process_cheer_event(
                        event_data["user_id"],
                        event_data["user_name"],
                        event_data["bits"]
                    ))
                # Raid Event
                elif event_type == "channel.raid":
                    asyncio.create_task(process_raid_event(
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
                    await channel.send(alert_message)
                    await cursor.execute("SELECT * FROM twitch_sound_alerts WHERE twitch_alert_id = %s", ("Hype Train Start",))
                    result = await cursor.fetchone()
                    if result and result.get("sound_mapping"):
                        sound_file = "twitch/" . result.get("sound_mapping")
                        asyncio.create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
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
                    await channel.send(alert_message)
                    await cursor.execute("SELECT * FROM twitch_sound_alerts WHERE twitch_alert_id = %s", ("Hype Train End",))
                    result = await cursor.fetchone()
                    if result and result.get("sound_mapping"):
                        sound_file = "twitch/" . result.get("sound_mapping")
                        asyncio.create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
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
                    asyncio.create_task(handle_ad_break_start(event_data["duration_seconds"]))
                # Charity Campaign Donate Event
                elif event_type == 'channel.charity_campaign.donate':
                    user = event_data["event"]["user_name"]
                    charity = event_data["event"]["charity_name"]
                    value = event_data["event"]["amount"]["value"]
                    currency = event_data["event"]["amount"]["currency"]
                    value_formatted = "{:,.2f}".format(value)
                    message = f"Thank you so much {user} for your ${value_formatted}{currency} donation to {charity}. Your support means so much to us and to {charity}."
                    await channel.send(message)
                # Moderation Event
                elif event_type == 'channel.moderate':
                    moderator_user_name = event_data["event"].get("moderator_user_name", "Unknown Moderator")
                    # Handle timeout action
                    if event_data["event"].get("action") == "timeout":
                        timeout_info = event_data["event"].get("timeout", {})
                        user_name = timeout_info.get("user_name", "Unknown User")
                        reason = timeout_info.get("reason", "No reason provided")
                        expires_at_str = timeout_info.get("expires_at")
                        if expires_at_str:
                            expires_at = datetime.strptime(expires_at_str, "%Y-%m-%dT%H:%M:%SZ")
                            expires_at_formatted = expires_at.strftime("%Y-%m-%d %H:%M:%S")
                        else:
                            expires_at_formatted = "No expiration time provided"
                        discord_message = f'{user_name} has been timed out, their timeout expires at {expires_at_formatted} for the reason "{reason}"'
                        discord_title = "New User Timeout!"
                        discord_image = "clock.png"
                    # Handle untimeout action
                    elif event_data["event"].get("action") == "untimeout":
                        untimeout_info = event_data["event"].get("untimeout", {})
                        user_name = untimeout_info.get("user_name", "Unknown User")
                        discord_message = f"{user_name} has had their timeout removed by {moderator_user_name}."
                        discord_title = "New Untimeout User!"
                        discord_image = "clock.png"
                    # Handle ban action
                    elif event_data["event"].get("action") == "ban":
                        banned_info = event_data["event"].get("ban", {})
                        banned_user_name = banned_info.get("user_name", "Unknown User")
                        reason = banned_info.get("reason", "No reason provided")
                        discord_message = f'{banned_user_name} has been banned for "{reason}" by {moderator_user_name}'
                        discord_title = "New User Ban!"
                        discord_image = "ban.png"
                    # Handle unban action
                    elif event_data["event"].get("action") == "unban":
                        unban_info = event_data["event"].get("unban", {})
                        banned_user_name = unban_info.get("user_name", "Unknown User")
                        discord_message = f'{banned_user_name} has been unbanned by {moderator_user_name}'
                        discord_title = "New Unban!"
                        discord_image = "ban.png"
                    # Check if the necessary data is available
                    if discord_message and discord_title and discord_image:
                        # Send to Discord if all checks pass
                        asyncio.create_task(send_to_discord_mod(discord_message, discord_title, discord_image))
                    else:
                        # Log the incomplete event for later analysis
                        twitch_logger.info(f"Incomplete mod event: {event_data}")
                # Channel Point Rewards Event
                elif event_type in [
                    "channel.channel_points_automatic_reward_redemption.add", 
                    "channel.channel_points_custom_reward_redemption.add"
                    ]:
                    asyncio.create_task(process_channel_point_rewards(event_data, event_type))
                # Poll Event
                elif event_type in ["channel.poll.begin", "channel.poll.end"]:
                    if event_type == "channel.poll.begin":
                        poll_id = event_data.get("id")
                        poll_title = event_data.get("title")
                        poll_ends_at = datetime.fromisoformat(event_data["ends_at"].replace("Z", "+00:00"))
                        utc_now = datetime.now(timezone.utc)
                        time_until_end = (poll_ends_at - utc_now).total_seconds()
                        half_time = int(time_until_end / 2)
                        minutes, seconds = divmod(time_until_end, 60)
                        if minutes and seconds:
                            message = f"Poll '{poll_title}' has started! Poll ending in {int(minutes)} minutes and {int(seconds)} seconds."
                        elif minutes:
                            message = f"Poll '{poll_title}' has started! Poll ending in {int(minutes)} minutes."
                        else:
                            message = f"Poll '{poll_title}' has started! Poll ending in {int(seconds)} seconds."
                        asyncio.create_task(handel_twitch_poll(event="poll.begin", poll_title=poll_title, half_time=half_time, message=message))
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
                        await sqldb.commit()
                        sql_options = ["one", "two", "three", "four", "five"]
                        sql_query = "UPDATE poll_results SET " + ", ".join([f"poll_option_{i+1} = %s" for i in range(len(sql_options))]) + " WHERE poll_id = %s"
                        params = [sorted_choices[i]["title"] if i < len(sorted_choices) else None for i in range(len(sql_options))] + [poll_id]
                        await cursor.execute(sql_query, params)
                        await sqldb.commit()
                        asyncio.create_task(handel_twitch_poll(event="poll.end", poll_title=poll_title, message=message))
                # Stream Online/Offline Event
                elif event_type in ["stream.online", "stream.offline"]:
                    if event_type == "stream.online":
                        bot_logger.info(f"Stream is now online!")
                        asyncio.create_task(websocket_notice(event="STREAM_ONLINE"))
                    else:
                        bot_logger.info(f"Stream is now offline.")
                        asyncio.create_task(websocket_notice(event="STREAM_OFFLINE"))
                # AutoMod Message Hold Event
                elif event_type == "automod.message.hold":
                    event_logger.info(f"Got an AutoMod Message Hold: {event_data}")
                    messageContent = event_data["event"]["message"]
                    messageAuthor = event_data["event"]["user_name"]
                    messageAuthorID = event_data["event"]["user_id"]
                    spam_pattern = await get_spam_patterns()
                    for pattern in spam_pattern:
                        if pattern.search(messageContent):
                            twitch_logger.info(f"Banning user {messageAuthor} with ID {messageAuthorID} for spam pattern match.")
                            asyncio.create_task(ban_user(messageAuthor, messageAuthorID))
                # User Message Hold Event
                elif event_type == "channel.chat.user_message_hold":
                    event_logger.info(f"Got a User Message Hold in Chat: {event_data}")
                    messageContent = event_data["event"]["message"]["text"]
                    messageAuthor = event_data["event"]["user_name"]
                    messageAuthorID = event_data["event"]["user_id"]
                    spam_pattern = await get_spam_patterns()
                    for pattern in spam_pattern:
                        if pattern.search(messageContent):
                            twitch_logger.info(f"Banning user {messageAuthor} with ID {messageAuthorID} for spam pattern match.")
                            asyncio.create_task(ban_user(messageAuthor, messageAuthorID))
                # Suspicious User Message Event
                elif event_type == "channel.suspicious_user.message":
                    event_logger.info(f"Got a Suspicious User Message: {event_data}")
                    messageContent = event_data["event"]["message"]["text"]
                    messageAuthor = event_data["event"]["user_name"]
                    messageAuthorID = event_data["event"]["user_id"]
                    lowTrustStatus = event_data["event"]["low_trust_status"]
                    banEvasionTypes = event_data["event"]["types"]
                    if banEvasionTypes:
                        twitch_logger.info(f"Suspicious user {messageAuthor} has the following types: {banEvasionTypes}")
                    if lowTrustStatus == "active_monitoring":
                        bot_logger.info(f"Banning suspicious user {messageAuthor} with ID {messageAuthorID} due to active monitoring status.")
                        asyncio.create_task(ban_user(messageAuthor, messageAuthorID))
                elif event_type == "channel.shoutout.create" or event_type == "channel.shoutout.receive":
                    if event_type == "channel.shoutout.create":
                        global shoutout_user
                        user_id = event_data['event']['to_broadcaster_user_id']
                        user_to_shoutout = event_data['event']['to_broadcaster_user_name']
                        if shoutout_user.lower() == user_to_shoutout.lower():
                            return
                        game = await get_latest_stream_game(user_id, user_to_shoutout)
                        if not game:
                            shoutout_message = (
                                f"Hey, huge shoutout to @{user_to_shoutout}! "
                                f"You should go give them a follow over at "
                                f"https://www.twitch.tv/{user_to_shoutout}"
                            )
                        else:
                            shoutout_message = (
                                f"Hey, huge shoutout to @{user_to_shoutout}! "
                                f"You should go give them a follow over at "
                                f"https://www.twitch.tv/{user_to_shoutout} where they were playing: {game}"
                            )
                    elif event_type == "channel.shoutout.receive":
                        shoutout_message = f"@{event_data['event']['from_broadcaster_user_name']} has given @{CHANNEL_NAME} a shoutout."
                    else:
                        shoutout_message = f"Sorry, @{CHANNEL_NAME}, I see a shoutout, however I was unable to get the correct inforamtion from twitch to process the request."
                    await channel.send(shoutout_message)
                else:
                    # Logging for unknown event types
                    twitch_logger.error(f"Received message with unknown event type: {event_type}")
    except Exception as e:
        event_logger.error(f"Error processing EventSub message: {e}")
    finally:
        await sqldb.ensure_closed()

# Connect and manage reconnection for Internal Socket Server
async def specter_websocket():
    specter_websocket_uri = "wss://websocket.botofthespecter.com"
    while True:
        try:
            # Attempt to connect to the WebSocket server
            bot_logger.info(f"Attempting to connect to Internal WebSocket Server")
            await specterSocket.connect(specter_websocket_uri)
            await specterSocket.wait()  # Keep the connection open to receive messages
        except socketio.exceptions.ConnectionError as e:
            bot_logger.error(f"Internal WebSocket Connection Failed: {e}")
            await asyncio.sleep(10)  # Wait and retry connection
        except Exception as e:
            bot_logger.error(f"An unexpected error occurred with Internal WebSocket: {e}")
            await asyncio.sleep(10)

@specterSocket.event
async def connect():
    websocket_logger.info("Successfully established connection to internal websocket server")
    registration_data = {
        'code': API_TOKEN,
        'channel': CHANNEL_NAME,
        'name': f'Twitch Bot {SYSTEM} V{VERSION}'
    }
    try:
        await specterSocket.emit('REGISTER', registration_data)
        websocket_logger.info("Client registration sent")
    except Exception as e:
        websocket_logger.error(f"Failed to register client: {e}")

@specterSocket.event
async def connect_error(data):
    websocket_logger.error(f"Connection failed: {data}")

@specterSocket.event
async def disconnect():
    websocket_logger.warning("Client disconnected from server")

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

# Connect and manage reconnection for HypeRate Heart Rate
async def hyperate_websocket():
    while True:
        try:
            bot_logger.info("HypeRate info: Attempting to connect to HypeRate Heart Rate WebSocket Server")
            hyperate_websocket_uri = f"wss://app.hyperate.io/socket/websocket?token={HYPERATE_API_KEY}"
            async with websockets.connect(hyperate_websocket_uri) as hyperate_websocket:
                bot_logger.info("HypeRate info: Successfully connected to the WebSocket.")
                # Send 'phx_join' message to join the appropriate channel
                await join_channel(hyperate_websocket)
                # Send the heartbeat every 10 seconds
                asyncio.create_task(send_heartbeat(hyperate_websocket))
                while True:
                    try:
                        # Continuously wait for incoming messages
                        global HEARTRATE
                        data = await hyperate_websocket.recv()
                        data = json.loads(data)
                        HEARTRATE = data['payload'].get('hr', None)
                    except websockets.ConnectionClosed:
                        bot_logger.warning("HypeRate WebSocket connection closed, reconnecting...")
                        break
        except Exception as e:
            bot_logger.error(f"HypeRate error: An unexpected error occurred with HypeRate Heart Rate WebSocket: {e}")
            await asyncio.sleep(10)  # Retry connection after a brief wait

async def send_heartbeat(hyperate_websocket):
    while True:
        await asyncio.sleep(10)  # Send heartbeat every 10 seconds
        heartbeat_payload = {
            "topic": "phoenix",
            "event": "heartbeat",
            "payload": {},
            "ref": 0
        }
        try:
            await hyperate_websocket.send(json.dumps(heartbeat_payload))
        except Exception as e:
            bot_logger.error(f"Error sending heartbeat: {e}")
            break

async def join_channel(hyperate_websocket):
    try:
        sqldb = await get_mysql_connection()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute('SELECT heartrate_code FROM profile')
            heartrate_code_data = await cursor.fetchone()
            if not heartrate_code_data:
                bot_logger.error("HypeRate error: No Heart Rate Code found in database, aborting connection.")
                return
            heartrate_code = heartrate_code_data['heartrate_code']
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
        bot_logger.error(f"HypeRate error: Error during 'join_channel' operation: {e}")
    finally:
        await sqldb.ensure_closed()

# Bot classes
class GameNotFoundException(Exception):
    pass

class GameUpdateFailedException(Exception):
    pass

class TwitchBot(commands.Bot):
    # Event Message to get the bot ready
    def __init__(self, token, prefix, channel_name):
        super().__init__(token=token, prefix=prefix, initial_channels=[channel_name], case_insensitive=True)
        self.channel_name = channel_name

    async def event_ready(self):
        bot_logger.info(f'Logged in as "{self.nick}"')
        channel = self.get_channel(self.channel_name)
        await update_version_control()
        await builtin_commands_creation()
        await check_stream_online()
        asyncio.create_task(known_users())
        asyncio.create_task(channel_point_rewards())
        looped_tasks["twitch_token_refresh"] = asyncio.get_event_loop().create_task(twitch_token_refresh())
        looped_tasks["spotify_token_refresh"] = asyncio.get_event_loop().create_task(spotify_token_refresh())
        looped_tasks["twitch_eventsub"] = asyncio.get_event_loop().create_task(twitch_eventsub())
        looped_tasks["specter_websocket"] = asyncio.get_event_loop().create_task(specter_websocket())
        looped_tasks["hyperate_websocket"] = asyncio.get_event_loop().create_task(hyperate_websocket())
        looped_tasks["connect_to_tipping_services"] = asyncio.get_event_loop().create_task(connect_to_tipping_services())
        looped_tasks["midnight"] = asyncio.get_event_loop().create_task(midnight())
        looped_tasks["shoutout_worker"] = asyncio.get_event_loop().create_task(shoutout_worker())
        looped_tasks["periodic_watch_time_update"] = asyncio.get_event_loop().create_task(periodic_watch_time_update())
        looped_tasks["check_song_requests"] = asyncio.get_event_loop().create_task(check_song_requests())
        await channel.send(f"SpecterSystems connected and ready! Running V{VERSION} {SYSTEM}")

    async def event_channel_joined(self, channel):
        self.target_channel = channel 
        bot_logger.info(f"Joined channel: {channel.name}")

    # Errors
    async def event_command_error(self, ctx, error: Exception) -> None:
        command = ctx.message.content.split()[0][1:]
        if isinstance(error, commands.CommandOnCooldown):
            bot_logger.info(f"[COOLDOWN] Command: '{command}' is on cooldown for {round(error.retry_after)} seconds.")
            retry_after = round(error.retry_after)
            message = f"Command '{command}' is on cooldown. Try again in {retry_after} seconds."
            channel = self.get_channel(self.channel_name)
            if channel:
                await self.target_channel.send(message)
            else:
                bot_logger.error(f"Unable to send cooldown message: Target channel '{CHANNEL_NAME}' not joined yet.")
        elif isinstance(error, commands.CommandNotFound):
            # Check if the command is a custom command
            sqldb = await get_mysql_connection()
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute('SELECT * FROM custom_commands WHERE command = %s', (command,))
                result = await cursor.fetchone()
                if result:
                    bot_logger.debug(f"[CUSTOM COMMAND] Command '{command}' exists in the database. Ignoring error.")
                    await cursor.close()
                    await sqldb.ensure_closed()
                    return
            bot_logger.error(f"Command '{command}' was not found in the bot or custom commands.")
        else:
            bot_logger.error(f"Command: '{command}', Error: {type(error).__name__}, Details: {error}")

    # Function to check all messages and push out a custom command.
    async def event_message(self, message):
        sqldb = await get_mysql_connection()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            channel = message.channel
            messageAuthor = ""
            messageAuthorID = ""
            bannedUser = None
            try:
                # Ignore messages from the bot itself
                if message.echo:
                    chat_history_logger.info(f"Chat message from {message.author.name}: {message.content}")
                    return
                if not message.author or not hasattr(message.author, 'name'):
                    return
                # Log the message content
                chat_history_logger.info(f"Chat message from {message.author.name}: {message.content}")
                # Handle commands
                await self.handle_commands(message)
                messageContent = message.content.strip().lower() if message.content else ""
                messageAuthor = message.author.name if message.author else ""
                messageAuthorID = message.author.id if message.author else ""
                AuthorMessage = message.content if message.content else ""
                # Check if the message matches the spam pattern
                spam_pattern = await get_spam_patterns()  
                if spam_pattern:  # Check if spam_pattern is not empty
                    for pattern in spam_pattern:
                        if pattern.search(messageContent):
                            bot_logger.info(f"Banning user {messageAuthor} with ID {messageAuthorID} for spam pattern match.")
                            asyncio.create_task(ban_user(messageAuthor, messageAuthorID))
                            bannedUser = messageAuthor
                            return
                if messageContent.startswith('!'):
                    command_parts = messageContent.split()
                    command = command_parts[0][1:]  # Extract the command without '!'
                    if command in builtin_commands or command in mod_commands or command in builtin_aliases:
                        chat_logger.info(f"{messageAuthor} used a built-in command called: {command}")
                        return  # It's a built-in command or alias, do nothing more
                    # Check if the command exists in the database and respond
                    await cursor.execute("SELECT timezone FROM profile")
                    tz_result = await cursor.fetchone()
                    if tz_result and tz_result.get("timezone"):
                        timezone = tz_result.get("timezone")
                        tz = pytz.timezone(timezone)
                        chat_logger.info(f"TZ: {tz} | Timezone: {timezone}")
                    else:
                        tz = pytz.UTC
                        chat_logger.info("Timezone not set, defaulting to UTC")
                    await cursor.execute('SELECT response, status, cooldown FROM custom_commands WHERE command = %s', (command,))
                    cc_result = await cursor.fetchone()
                    if cc_result:
                        response = cc_result.get("response")
                        status = cc_result.get("status")
                        cooldown = cc_result.get("cooldown")
                        if status == 'Enabled':
                            cooldown = int(cooldown)
                            # Checking if the command is on cooldown
                            last_used = command_last_used.get(command, None)
                            if last_used:
                                time_since_last_used = (datetime.now() - last_used).total_seconds()
                                if time_since_last_used < cooldown:
                                    remaining_time = cooldown - time_since_last_used
                                    chat_logger.info(f"{command} is on cooldown. {int(remaining_time)} seconds remaining.")
                                    await channel.send(f"The command {command} is on cooldown. Please wait {int(remaining_time)} seconds.")
                                    return
                            command_last_used[command] = datetime.now()
                            switches = [
                                '(customapi.', '(count)', '(daysuntil.', '(command.', '(user)', '(author)', 
                                '(random.percent)', '(random.number)', '(random.percent.', '(random.number.',
                                '(random.pick.', '(math.', '(call.', '(usercount)', '(timeuntil.'
                            ]
                            responses_to_send = []
                            while any(switch in response for switch in switches):
                                # Handle (count)
                                if '(count)' in response:
                                    try:
                                        await update_custom_count(command)
                                        get_count = await get_custom_count(command)
                                        response = response.replace('(count)', str(get_count))
                                    except Exception as e:
                                        chat_logger.error(f"{e}")
                                # Handle (usercount)
                                if '(usercount)' in response:
                                    try:
                                        user_mention = re.search(r'@(\w+)', messageContent)
                                        user_name = user_mention.group(1) if user_mention else messageAuthor
                                        # Get the user count for the specific command
                                        await cursor.execute('SELECT count FROM user_counts WHERE command = %s AND user = %s', (command, user_name))
                                        result = await cursor.fetchone()
                                        if result:
                                            user_count = result.get("count")
                                        else:
                                            # If no entry found, initialize it to 0
                                            user_count = 0
                                            await cursor.execute('INSERT INTO user_counts (command, user, count) VALUES (%s, %s, %s)', (command, user_name, user_count))
                                            await cursor.connection.commit()
                                        # Increment the count
                                        user_count += 1
                                        await cursor.execute('UPDATE user_counts SET count = %s WHERE command = %s AND user = %s', (user_count, command, user_name))
                                        await cursor.connection.commit()
                                        # Fetch the updated count
                                        await cursor.execute('SELECT count FROM user_counts WHERE command = %s AND user = %s', (command, user_name))
                                        updated_result = await cursor.fetchone()
                                        if updated_result:
                                            updated_user_count = updated_result.get("count")
                                        else:
                                            updated_user_count = 0
                                        # Replace the (usercount) placeholder with the updated user count
                                        response = response.replace('(usercount)', str(updated_user_count))
                                    except Exception as e:
                                        chat_logger.error(f"Error while handling (usercount): {e}")
                                        response = response.replace('(usercount)', "Error")
                                # Handle (daysuntil.)
                                if '(daysuntil.' in response:
                                    get_date = re.search(r'\(daysuntil\.(\d{4}-\d{2}-\d{2})\)', response)
                                    if get_date:
                                        date_str = get_date.group(1)
                                        event_date = datetime.strptime(date_str, "%Y-%m-%d").date()
                                        current_date = datetime.now(tz).date()
                                        days_left = (event_date - current_date).days
                                        # If days_left is negative, try next year
                                        if days_left < 0:
                                            next_year_date = event_date.replace(year=event_date.year + 1)
                                            days_left = (next_year_date - current_date).days
                                        response = response.replace(f"(daysuntil.{date_str})", str(days_left))
                                # Handle (timeuntil.)
                                if '(timeuntil.' in response:
                                    # Try first for full date-time format
                                    get_datetime = re.search(r'\(timeuntil\.(\d{4}-\d{2}-\d{2}(?:-\d{1,2}-\d{2})?)\)', response)
                                    if get_datetime:
                                        datetime_str = get_datetime.group(1)
                                        # Check if time components are included
                                        if '-' in datetime_str[10:]:  # Full date-time format
                                            event_datetime = datetime.strptime(datetime_str, "%Y-%m-%d-%H-%M").replace(tzinfo=tz)
                                        else:  # Date only format, default to midnight
                                            event_datetime = datetime.strptime(datetime_str + "-00-00", "%Y-%m-%d-%H-%M").replace(tzinfo=tz)
                                        current_datetime = datetime.now(tz)
                                        time_left = event_datetime - current_datetime
                                        # If time_left is negative, try next year
                                        if time_left.days < 0:
                                            event_datetime = event_datetime.replace(year=event_datetime.year + 1)
                                            time_left = event_datetime - current_datetime
                                        days_left = time_left.days
                                        hours_left, remainder = divmod(time_left.seconds, 3600)
                                        minutes_left, _ = divmod(remainder, 60)
                                        time_left_str = f"{days_left} days, {hours_left} hours, and {minutes_left} minutes"
                                        # Replace the original placeholder with the calculated time
                                        response = response.replace(f"(timeuntil.{datetime_str})", time_left_str)
                                # Handle (user) and (author)
                                if '(user)' in response:
                                    user_mention = re.search(r'@(\w+)', messageContent)
                                    user_name = user_mention.group(1) if user_mention else messageAuthor
                                    response = response.replace('(user)', user_name)
                                if '(author)' in response:
                                    response = response.replace('(author)', messageAuthor)
                                # Handle (command.)
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
                                            chat_logger.error(f"{sub_command} is no longer available.")
                                            await channel.send(f"The command {sub_command} is no longer available.")
                                # Handle (call.)
                                if '(call.' in response:
                                    calling_match = re.search(r'\(call\.(\w+)\)', response)
                                    if calling_match:
                                        match_call = calling_match.group(1)
                                        await self.call_command(match_call, message)
                                # Handle random replacements
                                if '(random.percent' in response or '(random.number' in response or '(random.pick.' in response:
                                    # Unified pattern for all placeholders
                                    pattern = r'\((random\.(percent|number|pick))(?:\.(.+?))?\)'
                                    matches = re.finditer(pattern, response)
                                    for match in matches:
                                        category = match.group(1)  # 'random.percent', 'random.number', or 'random.pick'
                                        details = match.group(3)  # Range (x-y) or items for pick
                                        replacement = ''  # Initialize the replacement string
                                        if 'percent' in category or 'number' in category:
                                            # Default bounds for random.percent and random.number
                                            lower_bound, upper_bound = 0, 100
                                            if details:  # If range is specified, extract it
                                                range_match = re.match(r'(\d+)-(\d+)', details)
                                                if range_match:
                                                    lower_bound, upper_bound = int(range_match.group(1)), int(range_match.group(2))
                                            random_value = random.randint(lower_bound, upper_bound)
                                            replacement = f'{random_value}%' if 'percent' in category else str(random_value)
                                        elif 'pick' in category:
                                            # Split the details into items to pick from
                                            items = details.split('.') if details else []
                                            replacement = random.choice(items) if items else ''
                                        # Replace the placeholder with the generated value
                                        response = response.replace(match.group(0), replacement)
                                # Handle (math.x+y)
                                if '(math.' in response:
                                    math_match = re.search(r'\(math\.(.+)\)', response)
                                    if math_match:
                                        math_expression = math_match.group(1)
                                        try:
                                            math_result = eval(math_expression)
                                            response = response.replace(f'(math.{math_expression})', str(math_result))
                                        except Exception as e:
                                            chat_logger.error(f"Math expression error: {e}")
                                            response = response.replace(f'(math.{math_expression})', "Error")
                                # Handle (customapi.)
                                if '(customapi.' in response:
                                    url_match = re.search(r'\(customapi\.(\S+)\)', response)
                                    if url_match:
                                        url = url_match.group(1)
                                        json_flag = False
                                        if url.startswith('json.'):
                                            json_flag = True
                                            url = url[5:]  # Remove 'json.' prefix
                                        api_response = await fetch_api_response(url, json_flag=json_flag)
                                        response = response.replace(f"(customapi.{url})", api_response)
                            await channel.send(response)
                            for resp in responses_to_send:
                                chat_logger.info(f"{command} command ran with response: {resp}")
                                await channel.send(resp)
                        else:
                            chat_logger.info(f"{command} not ran because it's disabled.")
                    else:
                        chat_logger.info(f"{command} not found in the database.")
                # Handle AI responses
                if f'@{self.nick.lower()}' in message.content.lower():
                    user_message = message.content.lower().replace(f'@{self.nick.lower()}', '').strip()
                    if not user_message:
                        await channel.send(f'Hello, {message.author.name}!')
                    else:
                        await self.handle_ai_response(user_message, messageAuthorID, message.author.name)
                if 'http://' in AuthorMessage or 'https://' in AuthorMessage:
                    # Fetch url_blocking option from the protection table in the user's database
                    await cursor.execute('SELECT url_blocking FROM protection')
                    result = await cursor.fetchone()
                    url_blocking = bool(result.get("url_blocking")) if result else False
                    # Proceed if URL blocking is enabled
                    if url_blocking:
                        # Check if user has permission to post links
                        if messageAuthor in permitted_users and time.time() < permitted_users[messageAuthor]:
                            return  # User is permitted, skip URL blocking
                        if await command_permissions("mod", messageAuthor):
                            return  # Mods and broadcaster have permission by default
                        # Fetch whitelist and blacklist from the database
                        await cursor.execute('SELECT link FROM link_whitelist')
                        whitelist_result = await cursor.fetchall()  # Fetch whitelist results
                        whitelisted_links = [row['link'] for row in whitelist_result] if whitelist_result else []
                        await cursor.execute('SELECT link FROM link_blacklisting')
                        blacklist_result = await cursor.fetchall()  # Fetch blacklist results
                        blacklisted_links = [row['link'] for row in blacklist_result] if blacklist_result else []
                        # Check if message contains whitelisted or blacklisted links using domain matching
                        contains_whitelisted_link = await match_domain_or_link(AuthorMessage, whitelisted_links)
                        contains_blacklisted_link = await match_domain_or_link(AuthorMessage, blacklisted_links)
                        # Check for Twitch clip links
                        contains_twitch_clip_link = 'https://clips.twitch.tv/' in AuthorMessage
                        # Process based on whitelist/blacklist match results
                        if contains_blacklisted_link:
                            # Delete the message if it contains a blacklisted URL
                            await message.delete()
                            chat_logger.info(f"Deleted message from {messageAuthor} containing a blacklisted URL: {AuthorMessage}")
                            await channel.send(f"Code Red! Link escapee! Mods have been alerted and are on the hunt for the missing URL.")
                            return
                        elif not contains_whitelisted_link and not contains_twitch_clip_link:
                            await message.delete()
                            chat_logger.info(f"Deleted message from {messageAuthor} containing a URL: {AuthorMessage}")
                            await channel.send(f"{messageAuthor}, whoa there! We appreciate you sharing, but links aren't allowed in chat without a mod's okay.")
                            return
                        else:
                            chat_logger.info(f"URL found in message from {messageAuthor}, not deleted due to being whitelisted or a Twitch clip link.")
                    else:
                        chat_logger.info(f"URL found in message from {messageAuthor}, but URL blocking is disabled.")
                else:
                    pass
            except Exception as e:
                if isinstance(e, AttributeError) and "NoneType" in str(e):
                    pass
                else:
                    bot_logger.error(f"An error occurred in event_message: {e}")
            finally:
                await cursor.close()
                await sqldb.ensure_closed()
                await self.message_counting_and_welcome_messages(messageAuthor, messageAuthorID, bannedUser)

    async def message_counting_and_welcome_messages(self, messageAuthor, messageAuthorID, bannedUser):
        if messageAuthor in [bannedUser, None, ""]:
            return
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Check user level
                is_vip = await is_user_vip(messageAuthorID)
                is_mod = await is_user_mod(messageAuthorID)
                is_broadcaster = messageAuthor.lower() == CHANNEL_NAME.lower()
                user_level = 'broadcaster' if is_broadcaster else 'mod' if is_mod else 'vip' if is_vip else 'normal'
                # Insert into the database the number of chats during the stream
                await cursor.execute(
                    'INSERT INTO message_counts (username, message_count, user_level) VALUES (%s, 1, %s) '
                    'ON DUPLICATE KEY UPDATE message_count = message_count + 1, user_level = %s',
                    (messageAuthor, user_level, user_level)
                )
                await sqldb.commit()
                # Has the user been seen during this stream
                await cursor.execute('SELECT * FROM seen_today WHERE user_id = %s', (messageAuthorID,))
                if await cursor.fetchone():
                    return
                # Check if the user is the broadcaster
                if messageAuthor.lower() == CHANNEL_NAME.lower():
                    return
                # Check if the user is new or returning
                await cursor.execute('SELECT * FROM seen_users WHERE username = %s', (messageAuthor,))
                user_data = await cursor.fetchone()
                if user_data:
                    # The user is returning
                    has_welcome_message = user_data["welcome_message"]
                    user_status_enabled = user_data.get("status", 'True') == 'True'
                else:
                    # The user is new
                    user_data = None
                    has_welcome_message = None
                    user_status_enabled = True
                    # Insert the new user into the seen_users table
                    await cursor.execute('INSERT INTO seen_users (username, status) VALUES (%s, %s)', (messageAuthor, "True"))
                    await sqldb.commit()
                # Query the streamer preferences for the welcome message settings
                await cursor.execute('SELECT * FROM streamer_preferences WHERE id = 1')
                preferences = await cursor.fetchone()
                send_welcome_messages = int(preferences["send_welcome_messages"])
                new_default_welcome_message = preferences["new_default_welcome_message"]
                new_default_vip_welcome_message = preferences["new_default_vip_welcome_message"]
                new_default_mod_welcome_message = preferences["new_default_mod_welcome_message"]
                default_welcome_message = preferences["default_welcome_message"]
                default_vip_welcome_message = preferences["default_vip_welcome_message"]
                default_mod_welcome_message = preferences["default_mod_welcome_message"]
                # Replace (user) in the welcome messages with the actual username
                def replace_user_placeholder(message, username):
                    return message.replace("(user)", username)
                # Add user to `seen_today`
                await cursor.execute('INSERT INTO seen_today (user_id, username) VALUES (%s, %s)', (messageAuthorID, messageAuthor))
                await sqldb.commit()
                if user_status_enabled and send_welcome_messages:
                    if user_data is None:
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
                    # Send the welcome message
                    asyncio.create_task(websocket_notice(event="WALKON", user=messageAuthor))
                    await self.send_message_to_channel(message_to_send)
                else:
                    chat_logger.info(f"User status for {messageAuthor} is disabled or welcome messages are turned off.")
        except Exception as e:
            chat_logger.error(f"Error in message_counting for {messageAuthor}: {e}")
        finally:
            await sqldb.ensure_closed()
            await self.user_points(messageAuthor, messageAuthorID)
            await self.user_grouping(messageAuthor, messageAuthorID)
            await handle_chat_message(messageAuthor)

    async def user_points(self, messageAuthor, messageAuthorID):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                settings = await get_point_settings()
                if not settings or 'chat_points' not in settings or 'excluded_users' not in settings:
                    chat_logger.error("Error: Point settings are missing or incomplete.")
                    return
                chat_points = settings['chat_points']
                excluded_users = settings['excluded_users'].split(',')
                #bot_logger.info(f"Excluded users: {excluded_users}")
                author_lower = messageAuthor.lower()
                if author_lower not in excluded_users:
                    await cursor.execute("SELECT points FROM bot_points WHERE user_id = %s", (messageAuthorID,))
                    result = await cursor.fetchone()
                    current_points = result.get('points') if result else 0
                    new_points = current_points + chat_points
                    if result:
                        await cursor.execute("UPDATE bot_points SET points = %s WHERE user_id = %s", (new_points, messageAuthorID))
                        #bot_logger.info(f"Updated {settings['point_name']} for {messageAuthor} in the database.")
                    else:
                        await cursor.execute(
                            "INSERT INTO bot_points (user_id, user_name, points) VALUES (%s, %s, %s)",
                            (messageAuthorID, messageAuthor, new_points)
                        )
                        bot_logger.info(f"Inserted new user {messageAuthor} with {settings['point_name']} {new_points} into the database.")
                    await sqldb.commit()
                else:
                    return
        except Exception as e:
            chat_logger.error(f"Error in user_points: {e}")
        finally:
            await sqldb.ensure_closed()

    async def user_grouping(self, messageAuthor, messageAuthorID):
        sqldb = await get_mysql_connection()
        try:
            group_names = []
            # Check if the user is the broadcaster
            if messageAuthor == self.channel_name:
                return
            # Check if there was a user passed
            if messageAuthor == "None":
                return
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
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
                                "ON DUPLICATE KEY UPDATE group_name = %s",
                                (messageAuthor, name, name)
                            )
                            await sqldb.commit()
                            #bot_logger.info(f"User '{messageAuthor}' assigned to group '{name}' successfully.")
                        except aiomysql.IntegrityError:
                            bot_logger.error(f"Failed to assign user '{messageAuthor}' to group '{name}'.")
                    else:
                        bot_logger.error(f"Group '{name}' does not exist.")
        except Exception as e:
            bot_logger.error(f"An error occurred in user_grouping: {e}")
        finally:
            await sqldb.ensure_closed()

    async def call_command(self, command_name, ctx):
        command_method = getattr(self, f"{command_name}_command", None)
        if command_method:
            await command_method(ctx)
        else:
            await ctx.send(f"Command '{command_name}' not found.")

    async def handle_ai_response(self, user_message, user_id, message_author_name):
        ai_response = await self.get_ai_response(user_message, user_id)
        # Split the response if it's longer than 255 characters
        messages = [ai_response[i:i+255] for i in range(0, len(ai_response), 255)]
        # Send each part of the response as a separate message
        for part in messages:
            await self.send_message_to_channel(f"{part}")

    async def send_message_to_channel(self, message):
        channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
        await channel.send(message)

    async def get_ai_response(self, user_message, user_id):
        premium_tier = await check_premium_feature()
        if premium_tier in (2000, 3000, 4000):
            # Premium feature access granted
            try:
                async with aiohttp.ClientSession() as session:
                    payload = {
                        "message": user_message,
                        "channel": CHANNEL_NAME,
                        "message_user": user_id
                    }
                    async with session.post('https://ai.botofthespecter.com/', json=payload) as response:
                        response.raise_for_status()
                        ai_response = await response.text()
                        api_logger.info(f"AI response received: {ai_response}")
                        return ai_response
            except aiohttp.ClientError as e:
                bot_logger.error(f"Error getting AI response: {e}")
                return "Sorry, I could not understand your request."
        else:
            # No premium access
            return "This channel doesn't have a premium subscription to use this feature."

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='commands', aliases=['cmds'])
    async def commands_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("commands",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        # If the user is a mod, include both mod_commands and builtin_commands
                        is_mod = await command_permissions("mod", ctx.author)
                        if is_mod:
                            mod_commands_list = ", ".join(sorted(f"!{command}" for command in mod_commands))
                            await ctx.send(f"Moderator commands: {mod_commands_list}")
                        # Include builtin commands for both mod and normal users
                        builtin_commands_list = ", ".join(sorted(f"!{command}" for command in builtin_commands))
                        await ctx.send(f"General commands: {builtin_commands_list}")
                        # Custom commands link
                        custom_response_message = f"Custom commands: https://members.botofthespecter.com/{CHANNEL_NAME}/"
                        await ctx.send(custom_response_message)
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the commands command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred while executing the 'commands' command: {str(e)}")
            await ctx.send("An error occurred while fetching the twitch_commands. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='bot')
    async def bot_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("bot",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        chat_logger.info(f"{ctx.author.name} ran the Bot Command.")
                        await ctx.send(f"This amazing bot is built by the one and the only {bot_owner}. Check me out on my website: https://botofthespecter.com")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the bot command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the bot command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='forceonline')
    async def forceonline_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("forceonline",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        chat_logger.info(f"Stream status forcibly set to online by {ctx.author.name}.")
                        bot_logger.info(f"Stream is now online!")
                        await ctx.send("Stream status has been forcibly set to online.")
                        asyncio.create_task(websocket_notice(event="STREAM_ONLINE"))
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to use the force online command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in forceonline_command: {e}")
            await ctx.send(f"An error occurred while executing the command. {e}")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='forceoffline')
    async def forceoffline_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("forceoffline",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")  # Unpack the status and permissions
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        chat_logger.info(f"Stream status forcibly set to offline by {ctx.author.name}.")
                        bot_logger.info(f"Stream is now offline.")
                        await ctx.send("Stream status has been forcibly set to offline.")
                        asyncio.create_task(websocket_notice(event="STREAM_OFFLINE"))
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to use the force offline command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
                else:
                    await ctx.send("Command not found.")
        except Exception as e:
            chat_logger.error(f"Error in forceoffline_command: {e}")
            await ctx.send(f"An error occurred while executing the command. {e}")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='version')
    async def version_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("version",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        global bot_started
                        uptime = datetime.now() - bot_started
                        uptime_days = uptime.days
                        uptime_hours, remainder = divmod(uptime.seconds, 3600)
                        uptime_minutes, _ = divmod(remainder, 60)
                        # Build the message
                        message = f"The version that is currently running is V{VERSION} {SYSTEM}. Bot has been running for: "
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
                        await ctx.send(f"{message[:-2]}")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the version command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the version command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='roadmap')
    async def roadmap_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("roadmap",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        await ctx.send("Here's the roadmap for the bot: https://trello.com/b/EPXSCmKc/specterbot")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the roadmap command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the roadmap command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='weather')
    async def weather_command(self, ctx, *, location: str = None) -> None:
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("weather",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        if not location:
                            location = await get_streamer_weather()
                        if location:
                            async with aiohttp.ClientSession() as session:
                                response = await session.get(f"https://api.botofthespecter.com/weather?api_key={API_TOKEN}&location={location}")
                                result = await response.json()
                                if "detail" in result and "404: Location" in result["detail"]:
                                    await ctx.send(f"Error: The location '{location}' was not found.")
                                    api_logger.info(f"API - BotOfTheSpecter - WeatherCommand - {result}")
                                else:
                                    api_logger.info(f"API - BotOfTheSpecter - WeatherCommand - {result}")
                        else:
                            await ctx.send("Unable to retrieve location.")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the weather command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the weather command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='points')
    async def points_command(self, ctx):
        global bot_owner
        user_id = str(ctx.author.id)
        user_name = ctx.author.name
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("points",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        # Check if the user exists in the database
                        await cursor.execute("SELECT points FROM bot_points WHERE user_id = %s", (user_id,))
                        result = await cursor.fetchone()
                        if result:
                            points = result.get("points")
                            chat_logger.info(f"{user_name} has {points} points")
                        else:
                            points = 0
                            chat_logger.info(f"{user_name} has {points} points")
                            await cursor.execute(
                                "INSERT INTO bot_points (user_id, user_name, points) VALUES (%s, %s, %s)",
                                (user_id, user_name, points)
                            )
                            await sqldb.commit()
                        await ctx.send(f'@{user_name}, you have {points} points.')
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the points command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the points command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='addpoints')
    async def addpoints_command(self, ctx, user: str, points_to_add: int):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("addpoints",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        user = user.lstrip('@')  # Remove @ if present
                        user_id = str(ctx.author.id)
                        user_name = user if user else ctx.author.name
                        await cursor.execute("SELECT points FROM bot_points WHERE user_id = %s", (user_id,))
                        result = await cursor.fetchone()
                        if result:
                            new_points = result["points"] + points_to_add
                            await cursor.execute("UPDATE bot_points SET points = %s WHERE user_id = %s", (new_points, user_id))
                        else:
                            new_points = points_to_add
                            await cursor.execute(
                                "INSERT INTO bot_points (user_id, user_name, points) VALUES (%s, %s, %s)",
                                (user_id, user_name, new_points)
                            )
                        await sqldb.commit()
                        await ctx.send(f"Added {points_to_add} points to {user_name}. They now have {new_points} points.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of addpoints_command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='removepoints')
    async def removepoints_command(self, ctx, user: str, points_to_remove: int):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("removepoints",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        user = user.lstrip('@')  # Remove @ if present
                        user_id = str(ctx.author.id)
                        user_name = user if user else ctx.author.name
                        await cursor.execute("SELECT points FROM bot_points WHERE user_id = %s", (user_id,))
                        result = await cursor.fetchone()
                        if result:
                            new_points = max(0, result["points"] - points_to_remove)
                            await cursor.execute("UPDATE bot_points SET points = %s WHERE user_id = %s", (new_points, user_id))
                            await sqldb.commit()
                            await ctx.send(f"Removed {points_to_remove} points from {user_name}. They now have {new_points} points.")
                        else:
                            await ctx.send(f"{user_name} does not have any points.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of removepoints_command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='time')
    async def time_command(self, ctx, *, timezone: str = None) -> None:
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("time",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        if timezone:
                            geolocator = Nominatim(user_agent="BotOfTheSpecter")
                            location_data = geolocator.geocode(timezone)
                            if not location_data:
                                await ctx.send(f"Could not find the time location that you requested.")
                                chat_logger.info(f"Could not find the time location that you requested.")
                                return
                            timezone_api_key = os.getenv('TIMEZONE_API')
                            timezone_url = f"http://api.timezonedb.com/v2.1/get-time-zone?key={timezone_api_key}&format=json&by=position&lat={location_data.latitude}&lng={location_data.longitude}"
                            async with aiohttp.ClientSession() as session:
                                async with session.get(timezone_url) as response:
                                    if response.status != 200:
                                        await ctx.send(f"Could not retrieve time information from the API.")
                                        chat_logger.info(f"Failed to retrieve time information from the API, status code: {response.status}")
                                        return
                                    timezone_data = await response.json()
                            if timezone_data['status'] != "OK":
                                await ctx.send(f"Could not find the time location that you requested.")
                                chat_logger.info(f"Could not find the time location that you requested.")
                                return
                            timezone_str = timezone_data["zoneName"]
                            tz = pytz.timezone(timezone_str)
                            chat_logger.info(f"TZ: {tz} | Timezone: {timezone_str}")
                            current_time = datetime.now(tz)
                            time_format_date = current_time.strftime("%B %d, %Y")
                            time_format_time = current_time.strftime("%I:%M %p")
                            time_format_week = current_time.strftime("%A")
                            time_format = f"The time for {timezone} is {time_format_week}, {time_format_date} and the time is: {time_format_time}"
                        else:
                            await cursor.execute("SELECT timezone FROM profile")
                            result = await cursor.fetchone()
                            if result and result.get("timezone"):
                                timezone = result.get("timezone")
                                tz = pytz.timezone(timezone)
                                chat_logger.info(f"TZ: {tz} | Timezone: {timezone}")
                                current_time = datetime.now(tz)
                                time_format_date = current_time.strftime("%B %d, %Y")
                                time_format_time = current_time.strftime("%I:%M %p")
                                time_format_week = current_time.strftime("%A")
                                time_format = f"It is {time_format_week}, {time_format_date} and the time is: {time_format_time}"
                            else:
                                await ctx.send("Streamer timezone is not set.")
                                return
                        await ctx.send(time_format)
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the time command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the time command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='joke')
    async def joke_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("joke",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        # Retrieve the blacklist from the joke_settings table
                        await cursor.execute("SELECT blacklist FROM joke_settings WHERE id = 1")
                        blacklist_result = await cursor.fetchone()
                        if blacklist_result:
                            # Parse the blacklist
                            blacklist = json.loads(blacklist_result.get("blacklist"))
                            blacklist_lower = {cat.lower() for cat in blacklist}
                            joke = await Jokes()
                            while True:
                                # Fetch a joke from the JokeAPI
                                get_joke = await joke.get_joke()
                                # Resolve the category and check against the blacklist
                                category = get_joke["category"].lower()
                                if category not in blacklist_lower:
                                    break
                            # Send the joke based on its type
                            if get_joke["type"] == "single":
                                await ctx.send(f"Here's a joke from {get_joke['category']}: {get_joke['joke']}")
                            else:
                                await ctx.send(f"Here's a joke from {get_joke['category']}: {get_joke['setup']} | {get_joke['delivery']}")
                        else:
                            await ctx.send("Error: Could not fetch the blacklist settings.")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the joke command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the joke command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='quote')
    async def quote_command(self, ctx, number: int = None):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("quote",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        if number is None:  # If no number is provided, get a random quote
                            await cursor.execute("SELECT quote FROM quotes ORDER BY RAND() LIMIT 1")
                            quote = await cursor.fetchone()
                            if quote:
                                await ctx.send("Random Quote: " + quote["quote"])
                            else:
                                await ctx.send("No quotes available.")
                        else:  # If a number is provided, retrieve the quote by its ID
                            await cursor.execute("SELECT quote FROM quotes WHERE id = %s", (number,))
                            quote = await cursor.fetchone()
                            if quote:
                                await ctx.send(f"Quote {number}: " + quote["quote"])
                            else:
                                await ctx.send(f"No quote found with ID {number}.")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to run the quote command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the quote command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='quoteadd')
    async def quoteadd_command(self, ctx, *, quote):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("quoteadd",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        await cursor.execute("INSERT INTO quotes (quote) VALUES (%s)", (quote,))
                        await sqldb.commit()
                        await ctx.send("Quote added successfully: " + quote)
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to add a quote but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the quoteadd command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='removequote')
    async def quoteremove_command(self, ctx, number: int = None):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("removequote",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        if number is None:
                            await ctx.send("Please specify the ID to remove.")
                            return
                        await cursor.execute("DELETE FROM quotes WHERE id = %s", (number,))
                        await sqldb.commit()
                        if cursor.rowcount > 0:  # Check if a row was deleted
                            await ctx.send(f"Quote {number} has been removed.")
                        else:
                            await ctx.send(f"No quote found with ID {number}.")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to remove a quote but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the removequote command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='permit')
    async def permit_command(self, ctx, permit_user: str = None):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch the status and permissions for the permit command
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("permit",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the required permissions
                    if await command_permissions(permissions, ctx.author):
                        permit_user = permit_user.lstrip('@')
                        if permit_user:
                            permitted_users[permit_user] = time.time() + 30
                            await ctx.send(f"{permit_user} is now permitted to post links for the next 30 seconds.")
                        else:
                            await ctx.send("Please specify a user to permit.")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to use the permit command but lacked permissions.")
                        await ctx.send("You do not have the correct permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the permit command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='settitle')
    async def settitle_command(self, ctx, *, title: str = None) -> None:
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch the status and permissions for the settitle command
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("settitle",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the required permissions
                    if await command_permissions(permissions, ctx.author):
                        if title is None:
                            await ctx.send("Stream titles cannot be blank. You must provide a title for the stream.")
                            return
                        # Update the stream title
                        await trigger_twitch_title_update(title)
                        twitch_logger.info(f'Setting stream title to: {title}')
                        await ctx.send(f'Stream title updated to: {title}')
                    else:
                        await ctx.send("You do not have the correct permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the settitle command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='setgame')
    async def setgame_command(self, ctx, *, game: str = None) -> None:
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch the status and permissions for the setgame command
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("setgame",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Verify user permissions
                    if await command_permissions(permissions, ctx.author):
                        if game is None:
                            await ctx.send("You must provide a game for the stream.")
                            return
                        try:
                            game_name = await update_twitch_game(game)
                            await ctx.send(f'Stream game updated to: {game_name}')
                        except GameNotFoundException as e:
                            await ctx.send(f"Game not found: {str(e)}")
                        except GameUpdateFailedException as e:
                            await ctx.send(f"Failed to update game: {str(e)}")
                        except Exception as e:
                            await ctx.send(f'An error occurred in setgame command: {str(e)}')
                    else:
                        await ctx.send("You do not have the correct permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the setgame command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=60, bucket=commands.Bucket.default)
    @commands.command(name='song')
    async def song_command(self, ctx):
        global stream_online, song_requests, bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch the status and permissions for the song command
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("song",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await ctx.send("You do not have the required permissions to use this command.")
                    return
                # Get the current song and artist from Spotify
                song_name, artist_name, song_id = await get_spotify_current_song()
                if song_name and artist_name:
                    # If the stream is offline, notify that the user that the streamer is listening to music while offline
                    if not stream_online:
                        await ctx.send(f"{CHANNEL_NAME} is currently listening to \"{song_name} by {artist_name}\" while being offline.")
                        return
                    # Check if the song is in the tracked list and if a user is associated
                    requested_by = None
                    if song_id in song_requests:
                        requested_by = song_requests[song_id].get("user")
                    if requested_by:
                        await ctx.send(f"The current playing song is: {song_name} by {artist_name}, requested by {requested_by}")
                    else:
                        await ctx.send(f"The current playing song is: {song_name} by {artist_name}")
                    return
                if not stream_online:
                    await ctx.send("Sorry, I can only get the current playing song while the stream is online.")
                    return
                # If no song on Spotify, check the alternative method if premium
                premium_tier = await check_premium_feature()
                if premium_tier in (1000, 2000, 3000, 4000):
                    # Premium feature access granted
                    await ctx.send("Please stand by, checking what song is currently playing...")
                    try:
                        song_info = await shazam_the_song()
                        await ctx.send(song_info)
                        await delete_recorded_files()
                    except Exception as e:
                        chat_logger.error(f"An error occurred while getting current song: {e}")
                        await ctx.send("Sorry, there was an error retrieving the current song.")
                else:
                    # No premium access
                    await ctx.send("This channel doesn't have a premium subscription to use the alternative method.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the song command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=60, bucket=commands.Bucket.member)
    @commands.command(name='songrequest', aliases=['sr'])
    async def songrequest_command(self, ctx):
        global SPOTIFY_ACCESS_TOKEN, song_requests, bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch the status and permissions for the songrequest command
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("songrequest",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        await ctx.send(f"Requesting songs is currently disabled.")
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await ctx.send("You do not have the required permissions to use this command.")
                    return
            headers = { "Authorization": f"Bearer {SPOTIFY_ACCESS_TOKEN}" }
            message = ctx.message.content
            parts = message.split(" ", 1)
            if len(parts) < 2 or not parts[1].strip():
                await ctx.send("Please provide a song title and optionally the artist. Example: !songrequest [song title] by [artist]")
                return
            message_content = parts[1].strip()
            search = message_content.replace(" ", "%20")
            search_url = f"https://api.spotify.com/v1/search?q={search}&type=track&limit=1"
            async with aiohttp.ClientSession() as search_session:
                async with search_session.get(search_url, headers=headers) as response:
                    if response.status == 200:
                        data = await response.json()
                        tracks = data.get("tracks", {}).get("items", [])
                        for track in tracks:
                            song_id = track["uri"]
                            song_name = track["name"]
                            artist_name = track["artists"][0]["name"]
                            unwanted_keywords = ["instrumental", "karaoke version"]
                            if not any(keyword in song_name.lower() or keyword in artist_name.lower() for keyword in unwanted_keywords):
                                api_logger.info(f"Song Request from {ctx.message.author.name} for {song_name} by {artist_name} song id: {song_id}")
                                song_requests[song_id] = {
                                    "user": ctx.message.author.name,
                                    "song_name": song_name,
                                    "artist_name": artist_name,
                                    "timestamp": datetime.now()
                                }
                            else:
                                await ctx.send(f"No song found: {message_content}")
                                return
                    else:
                        # Map Spotify API response codes to plain English explanations
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
                        # Spotify API error handling
                        api_logger.error(f"Spotify returned response code: {response.status}")
                        error_message = SPOTIFY_ERROR_MESSAGES.get(
                            response.status, 
                            "Spotify gave me an unknown error. Try again in a moment."
                        )
                        await ctx.send(f"Sorry, I couldn't add the song to the queue. {error_message}")
                        return
            request_url = f"https://api.spotify.com/v1/me/player/queue?uri={song_id}"
            async with aiohttp.ClientSession() as queue_session:
                async with queue_session.post(request_url, headers=headers) as response:
                    if response.status == 200:
                        await ctx.send(f"The song {song_name} by {artist_name} has been added to the queue.")
                    else:
                        api_logger.error(f"Spotify returned response code: {response.status}")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the songrequest command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=30, bucket=commands.Bucket.member)
    @commands.command(name='songqueue', aliases=['sq', 'queue'])
    async def songqueue_command(self, ctx):
        global SPOTIFY_ACCESS_TOKEN, song_requests, bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch the status and permissions for the songqueue command
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("songqueue",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        await ctx.send(f"Sorry, checking the song queue is currently disabled.")
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await ctx.send("You do not have the required permissions to use this command.")
                    return
            headers = { "Authorization": f"Bearer {SPOTIFY_ACCESS_TOKEN}" }
            # Request the queue information from Spotify
            queue_url = "https://api.spotify.com/v1/me/player/queue"
            async with aiohttp.ClientSession() as queue_session:
                async with queue_session.get(queue_url, headers=headers) as response:
                    if response.status == 200:
                        data = await response.json()
                        if data and 'queue' in data:
                            queue = data['queue']
                            queue_length = len(queue)
                            # Get the currently playing song
                            song_name, artist_name, song_id = await get_spotify_current_song()
                            # Check if the current song was requested by someone
                            current_song_requester = None
                            if song_id in song_requests:
                                current_song_requester = song_requests[song_id].get("user")
                            # Send message for the current song
                            if song_name and artist_name:
                                if current_song_requester:
                                    await ctx.send(f"🎵 Now Playing: {song_name} by {artist_name} (requested by {current_song_requester})")
                                else:
                                    await ctx.send(f"🎵 Now Playing: {song_name} by {artist_name}")
                            # Format the song queue
                            song_list = []
                            for idx, song in enumerate(queue, start=1):
                                song_id = song['uri']
                                song_name = song['name']
                                artist_name = song['artists'][0]['name']
                                requester = None
                                # Check if a song is in the requests list and fetch the requester
                                if song_id in song_requests:
                                    requester = song_requests[song_id].get("user")
                                # Format the song entry with the requester
                                if requester:
                                    song_list.append(f"{idx}. {song_name} by {artist_name} (requested by {requester}) ")
                                else:
                                    song_list.append(f"{idx}. {song_name} by {artist_name} ")
                                if idx >= 3:  # Limit the display to the first 3 songs
                                    break
                            # Add a note if there are more songs in the queue
                            if queue_length > 3:
                                song_list.append(f"...and {queue_length - 3} more songs in the queue.")
                            # Send the queue to chat
                            if song_list:
                                await ctx.send(f"Upcoming Songs:\n" + "\n".join(song_list))
                            else:
                                await ctx.send("The queue is empty right now. Add some songs!")
                        else:
                            await ctx.send("It seems like nothing is playing on Spotify right now.")
                    else:
                        error_message = {
                            401: "I lost access to Spotify. Please reauthorize the bot.",
                            403: "Spotify says I can't access the queue. Please check permissions.",
                            404: "I couldn't find any queue. Is Spotify open and playing?",
                            429: "Spotify is overloaded right now. Try again in a moment.",
                            500: "Spotify is having technical difficulties. Let's try later.",
                        }.get(response.status, "Something went wrong with Spotify. Please try again soon.")
                        await ctx.send(f"Sorry, I couldn't fetch the queue. {error_message}")
                        api_logger.error(f"Spotify returned response code: {response.status}")
        except Exception as e:
            await ctx.send("Something went wrong while fetching the song queue. Please try again later.")
            api_logger.error(f"Error in songqueue_command: {e}")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='timer')
    async def timer_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch the status and permissions for the timer command
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("timer",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await ctx.send("You do not have the required permissions to use this command.")
                    return
                # Check if the user already has an active timer
                await cursor.execute("SELECT end_time FROM active_timers WHERE user_id=%s", (ctx.author.id,))
                active_timer = await cursor.fetchone()
                if active_timer:
                    await ctx.send(f"@{ctx.author.name}, you already have an active timer.")
                    return
                content = ctx.message.content.strip()
                try:
                    _, minutes = content.split(' ')
                    minutes = int(minutes)
                except ValueError:
                    # Default to 5 minutes if the user didn't provide a valid value
                    minutes = 5
                end_time = datetime.now(timezone.utc) + timedelta(minutes=minutes)
                await cursor.execute("INSERT INTO active_timers (user_id, end_time) VALUES (%s, %s)", (ctx.author.id, end_time))
                await sqldb.commit()
                await ctx.send(f"Timer started for {minutes} minute(s) @{ctx.author.name}.")
                await asyncio.sleep(minutes * 60)
                await ctx.send(f"The {minutes} minute timer has ended @{ctx.author.name}!")
                # Remove the timer from the active_timers table
                await cursor.execute("DELETE FROM active_timers WHERE user_id=%s", (ctx.author.id,))
                await sqldb.commit()
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the timer command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='stoptimer')
    async def stoptimer_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch the status and permissions for the stoptimer command
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("stoptimer",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await ctx.send("You do not have the required permissions to use this command.")
                    return
                await cursor.execute("SELECT end_time FROM active_timers WHERE user_id=%s", (ctx.author.id,))
                active_timer = await cursor.fetchone()
                if not active_timer:
                    await ctx.send(f"@{ctx.author.name}, you don't have an active timer.")
                    return
                await cursor.execute("DELETE FROM active_timers WHERE user_id=%s", (ctx.author.id,))
                await sqldb.commit()
                await ctx.send(f"Your timer has been stopped @{ctx.author.name}.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the stoptimer command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='checktimer')
    async def checktimer_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch the status and permissions for the checktimer command
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("checktimer",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await ctx.send("You do not have the required permissions to use this command.")
                    return
                await cursor.execute("SELECT end_time FROM active_timers WHERE user_id=%s", (ctx.author.id,))
                active_timer = await cursor.fetchone()
                if not active_timer:
                    await ctx.send(f"@{ctx.author.name}, you don't have an active timer.")
                    return
                end_time = active_timer["end_time"]
                remaining_time = end_time - datetime.now(timezone.utc)
                minutes_left = remaining_time.total_seconds() // 60
                seconds_left = remaining_time.total_seconds() % 60
                await ctx.send(f"@{ctx.author.name}, your timer has {int(minutes_left)} minute(s) and {int(seconds_left)} second(s) left.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the checktimer command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='hug')
    async def hug_command(self, ctx, *, mentioned_username: str = None):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch the status and permissions for the hug command
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("hug",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await ctx.send("You do not have the required permissions to use this command.")
                    return
                # Remove any '@' symbol from the mentioned username if present
                if mentioned_username:
                    mentioned_username = mentioned_username.lstrip('@')
                else:
                    await ctx.send("Usage: !hug @username")
                    return
                if mentioned_username == ctx.author.name:
                    await ctx.send("You can't hug yourself.")
                    return
                # Check if the mentioned username is valid on Twitch
                is_valid_user = await is_valid_twitch_user(mentioned_username)
                if not is_valid_user:
                    chat_logger.error(f"User {mentioned_username} does not exist on Twitch. Instead, you hugged the air.")
                    await ctx.send(f"The user @{mentioned_username} does not exist on Twitch.")
                    return
                # Increment hug count in the database
                await cursor.execute(
                    'INSERT INTO hug_counts (username, hug_count) VALUES (%s, 1) '
                    'ON DUPLICATE KEY UPDATE hug_count = hug_count + 1', 
                    (mentioned_username,)
                )
                await sqldb.commit()
                # Retrieve the updated count
                await cursor.execute('SELECT hug_count FROM hug_counts WHERE username = %s', (mentioned_username,))
                hug_count_result = await cursor.fetchone()
                if hug_count_result:
                    hug_count = hug_count_result.get("hug_count")
                    # Send the message
                    chat_logger.info(f"{mentioned_username} has been hugged by {ctx.author.name}. They have been hugged: {hug_count}")
                    await ctx.send(f"@{mentioned_username} has been hugged by @{ctx.author.name}, they have been hugged {hug_count} times.")
                    if mentioned_username == BOT_USERNAME:
                        author = ctx.author.name
                        await return_the_action_back(ctx, author, "hug")
                else:
                    chat_logger.error(f"No hug count found for user: {mentioned_username}")
                    await ctx.send(f"Sorry @{ctx.author.name}, you can't hug @{mentioned_username} right now, there's an issue in my system.")
        except Exception as e:
            chat_logger.error(f"Error in hug command: {e}")
            await ctx.send("An error occurred while processing the command.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='highfive')
    async def highfive_command(self, ctx, *, mentioned_username: str = None):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch the status and permissions for the hug command
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("highfive",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await ctx.send("You do not have the required permissions to use this command.")
                    return
                # Remove any '@' symbol from the mentioned username if present
                if mentioned_username:
                    mentioned_username = mentioned_username.lstrip('@')
                else:
                    await ctx.send("Usage: !highfive @username")
                    return
                if mentioned_username == ctx.author.name:
                    await ctx.send("You can't high-five yourself.")
                    return
                # Check if the mentioned username is valid on Twitch
                is_valid_user = await is_valid_twitch_user(mentioned_username)
                if not is_valid_user:
                    chat_logger.error(f"User {mentioned_username} does not exist on Twitch. You swung and hit only air.")
                    await ctx.send(f"The user @{mentioned_username} does not exist on Twitch.")
                    return
                # Increment highfive count in the database
                await cursor.execute(
                    'INSERT INTO highfive_counts (username, highfive_count) VALUES (%s, 1) '
                    'ON DUPLICATE KEY UPDATE highfive_count = highfive_count + 1', 
                    (mentioned_username,)
                )
                await sqldb.commit()
                # Retrieve the updated count
                await cursor.execute('SELECT highfive_count FROM highfive_counts WHERE username = %s', (mentioned_username,))
                highfive_count_result = await cursor.fetchone()
                if highfive_count_result:
                    highfive_count = highfive_count_result.get("highfive_count")
                    # Send the message
                    chat_logger.info(f"{mentioned_username} has been high-fived by {ctx.author.name}. They have been high-fived: {highfive_count}")
                    await ctx.send(f"@{mentioned_username} has been high-fived by @{ctx.author.name}, they have been high-fived {highfive_count} times.")
                    if mentioned_username == BOT_USERNAME:
                        author = ctx.author.name
                        await return_the_action_back(ctx, author, "highfive")
                else:
                    chat_logger.error(f"No high-five count found for user: {mentioned_username}")
                    await ctx.send(f"Sorry @{ctx.author.name}, you can't high-five @{mentioned_username} right now, there's an issue in my system.")
        except Exception as e:
            chat_logger.error(f"Error in highfive command: {e}")
            await ctx.send("An error occurred while processing the command.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='kiss')
    async def kiss_command(self, ctx, *, mentioned_username: str = None):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch the status and permissions for the kiss command
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("kiss",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                # Verify user permissions
                if not await command_permissions(permissions, ctx.author):
                    await ctx.send("You do not have the required permissions to use this command.")
                    return
                # Remove any '@' symbol from the mentioned username if present
                if mentioned_username:
                    mentioned_username = mentioned_username.lstrip('@')
                else:
                    await ctx.send("Usage: !kiss @username")
                    return
                if mentioned_username == ctx.author.name:
                    await ctx.send("You can't kiss yourself.")
                    return
                # Check if the mentioned username is valid on Twitch
                is_valid_user = await is_valid_twitch_user(mentioned_username)
                if not is_valid_user:
                    chat_logger.error(f"User {mentioned_username} does not exist on Twitch. You kissed the air.")
                    await ctx.send(f"The user @{mentioned_username} does not exist on Twitch.")
                    return
                # Increment kiss count in the database
                await cursor.execute(
                    'INSERT INTO kiss_counts (username, kiss_count) VALUES (%s, 1) '
                    'ON DUPLICATE KEY UPDATE kiss_count = kiss_count + 1', 
                    (mentioned_username,)
                )
                await sqldb.commit()
                # Retrieve the updated count
                await cursor.execute('SELECT kiss_count FROM kiss_counts WHERE username = %s', (mentioned_username,))
                kiss_count_result = await cursor.fetchone()
                if kiss_count_result:
                    kiss_count = kiss_count_result.get("kiss_count")
                    # Send the message
                    chat_logger.info(f"{mentioned_username} has been kissed by {ctx.author.name}. They have been kissed: {kiss_count}")
                    await ctx.send(f"@{mentioned_username} has been given a peck on the cheek by @{ctx.author.name}, they have been kissed {kiss_count} times.")
                    if mentioned_username == BOT_USERNAME:
                        author = ctx.author.name
                        await return_the_action_back(ctx, author, "kiss")
                else:
                    chat_logger.error(f"No kiss count found for user: {mentioned_username}")
                    await ctx.send(f"Sorry @{ctx.author.name}, you can't kiss @{mentioned_username} right now, there's an issue in my system.")
        except Exception as e:
            chat_logger.error(f"Error in kiss command: {e}")
            await ctx.send("An error occurred while processing the command.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='ping')
    async def ping_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("ping",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        # Using subprocess to run the ping command
                        result = subprocess.run(["ping", "-c", "1", "ping.botofthespecter.com"], stdout=subprocess.PIPE)
                        # Decode the result from bytes to string and search for the time
                        output = result.stdout.decode('utf-8')
                        match = re.search(r"time=(\d+\.\d+) ms", output)
                        if match:
                            ping_time = match.group(1)
                            bot_logger.info(f"Pong: {ping_time} ms")
                            # Updated message to make it clear to the user
                            await ctx.send(f'Pong: {ping_time} ms – Response time from the bot server to the internet.')
                        else:
                            bot_logger.error(f"Error Pinging. {output}")
                            await ctx.send(f'Error pinging the internet from the bot server.')
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to use the ping command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in ping_command: {e}")
            await ctx.send(f"An error occurred while executing the command. {e}")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='translate')
    async def translate_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("translate",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        # Get the message content after the command
                        message = ctx.message.content[len("!translate "):]
                        # Check if there is a message to translate
                        if not message:
                            await ctx.send("Please provide a message to translate.")
                            return
                        try:
                            # Check if the input message is too short
                            if len(message.strip()) < 5:
                                await ctx.send("The provided message is too short for reliable translation.")
                                return
                            translate_message = GoogleTranslator(source='auto', target='en').translate(text=message)
                            await ctx.send(f"Translation: {translate_message}")
                        except AttributeError as ae:
                            chat_logger.error(f"AttributeError: {ae}")
                            await ctx.send("An error occurred while detecting the language.")
                        except Exception as e:
                            chat_logger.error(f"Translating error: {e}")
                            await ctx.send("An error occurred while translating the message.")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to use the translate command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in translate_command: {e}")
            await ctx.send(f"An error occurred while executing the command. {e}")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='cheerleader', aliases=['bitsleader'])
    async def cheerleader_command(self, ctx):
        global bot_owner, CLIENT_ID, CHANNEL_AUTH
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("cheerleader",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
                        headers = {
                            'Client-ID': CLIENT_ID,
                            'Authorization': f'Bearer {CHANNEL_AUTH}'
                        }
                        params = {
                            'count': 1
                        }
                        async with aiohttp.ClientSession() as session:
                            async with session.get('https://api.twitch.tv/helix/bits/leaderboard', headers=headers, params=params) as response:
                                if response.status == 200:
                                    data = await response.json()
                                    if data['data']:
                                        top_cheerer = data['data'][0]
                                        score = "{:,}".format(top_cheerer['score'])
                                        await ctx.send(f"The current top cheerleader is {top_cheerer['user_name']} with {score} bits!")
                                    else:
                                        await ctx.send("There is no one currently in the leaderboard for bits; cheer to take this spot.")
                                elif response.status == 401:
                                    await ctx.send("Sorry, something went wrong while reaching the Twitch API.")
                                else:
                                    await ctx.send("Sorry, I couldn't fetch the leaderboard.")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to use the cheerleader command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in cheerleader_command: {e}")
            await ctx.send(f"An error occurred while executing the command. {e}")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='mybits')
    async def mybits_command(self, ctx):
        global bot_owner, CLIENT_ID, CHANNEL_AUTH
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Fetch both the status and permissions from the database
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("mybits",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    # If the command is disabled, stop execution
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the correct permissions
                    if await command_permissions(permissions, ctx.author):
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
                        async with aiohttp.ClientSession() as session:
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
                                            await sqldb.commit()
                                            bits = "{:,}".format(api_bits)
                                            await ctx.send(f"You have given {bits} bits in total.")
                                        elif api_bits < db_bits:
                                            # Inform the user that the local database has a higher value
                                            bits = "{:,}".format(db_bits)
                                            await ctx.send(f"Our records show you have given {bits} bits in total.")
                                        else:
                                            bits = "{:,}".format(api_bits)
                                            await ctx.send(f"You have given {bits} bits in total.")
                                    else:
                                        await ctx.send("You haven't given any bits yet.")
                                elif response.status == 401:
                                    await ctx.send("Sorry, something went wrong while reaching the Twitch API.")
                                else:
                                    await ctx.send("Sorry, I couldn't fetch your bits information.")
                    else:
                        chat_logger.info(f"{ctx.author.name} tried to use the mybits command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in mybits_command: {e}")
            await ctx.send(f"An error occurred while executing the command. {e}")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='lurk')
    async def lurk_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("lurk",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        chat_logger.info(f"{ctx.author.name} tried to use the lurk command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                    user_id = str(ctx.author.id)
                    now = datetime.now()
                    if ctx.author.name.lower() == CHANNEL_NAME.lower():
                        await ctx.send(f"You cannot lurk in your own channel, Streamer.")
                        chat_logger.info(f"{ctx.author.name} tried to lurk in their own channel.")
                        return
                    # Check if the user is already in the lurk table
                    await cursor.execute('SELECT start_time FROM lurk_times WHERE user_id = %s', (user_id,))
                    result = await cursor.fetchone()
                    if result:
                        previous_start_time = datetime.strptime(result["start_time"], "%Y-%m-%d %H:%M:%S")
                        lurk_duration = now - previous_start_time
                        days, seconds = divmod(lurk_duration.total_seconds(), 86400)
                        months, days = divmod(days, 30)
                        hours, remainder = divmod(seconds, 3600)
                        minutes, seconds = divmod(remainder, 60)
                        periods = [("months", int(months)), ("days", int(days)), ("hours", int(hours)), ("minutes", int(minutes)), ("seconds", int(seconds))]
                        time_string = ", ".join(f"{value} {name}" for name, value in periods if value)
                        await ctx.send(f"Continuing to lurk, {ctx.author.name}? No problem, you've been lurking for {time_string}. I've reset your lurk time.")
                        chat_logger.info(f"{ctx.author.name} refreshed their lurk time after {time_string}.")
                    else:
                        await ctx.send(f"Thanks for lurking, {ctx.author.name}! See you soon.")
                        chat_logger.info(f"{ctx.author.name} is now lurking.")
                    # Update the start time in the database
                    formatted_datetime = now.strftime("%Y-%m-%d %H:%M:%S")
                    await cursor.execute('INSERT INTO lurk_times (user_id, start_time) VALUES (%s, %s) ON DUPLICATE KEY UPDATE start_time = %s', (user_id, formatted_datetime, formatted_datetime))
                    await sqldb.commit()
        except Exception as e:
            chat_logger.error(f"Error in lurk_command: {e}")
            await ctx.send(f"Oops, something went wrong while trying to lurk.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='lurking')
    async def lurking_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("lurking",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        chat_logger.info(f"{ctx.author.name} tried to use the lurking command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                    user_id = ctx.author.id
                    if ctx.author.name.lower() == CHANNEL_NAME.lower():
                        await ctx.send(f"Streamer, you're always present!")
                        chat_logger.info(f"{ctx.author.name} tried to check lurk time in their own channel.")
                        return
                    await cursor.execute('SELECT start_time FROM lurk_times WHERE user_id = %s', (user_id,))
                    result = await cursor.fetchone()
                    if result:
                        start_time = datetime.strptime(result["start_time"], "%Y-%m-%d %H:%M:%S")
                        elapsed_time = datetime.now() - start_time
                        # Calculate the duration
                        days = elapsed_time.days
                        months = days // 30
                        days %= 30
                        hours, seconds = divmod(elapsed_time.seconds, 3600)
                        minutes, seconds = divmod(seconds, 60)
                        # Build the time string
                        periods = [("months", int(months)), ("days", int(days)), ("hours", int(hours)), ("minutes", int(minutes)), ("seconds", int(seconds))]
                        time_string = ", ".join(f"{value} {name}" for name, value in periods if value)
                        # Send the lurk time message
                        await ctx.send(f"{ctx.author.name}, you've been lurking for {time_string} so far.")
                        chat_logger.info(f"{ctx.author.name} checked their lurk time: {time_string}.")
                    else:
                        await ctx.send(f"{ctx.author.name}, you're not currently lurking.")
                        chat_logger.info(f"{ctx.author.name} tried to check lurk time but is not lurking.")
        except Exception as e:
            chat_logger.error(f"Error in lurking_command: {e}")
            await ctx.send(f"Oops, something went wrong while trying to check your lurk time.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='lurklead', aliases=['lurkleader'])
    async def lurklead_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("lurklead",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        chat_logger.info(f"{ctx.author.name} tried to use the lurklead command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                    try:
                        await cursor.execute('SELECT user_id, start_time FROM lurk_times')
                        lurkers = await cursor.fetchall()
                        longest_lurk = None
                        longest_lurk_user_id = None
                        now = datetime.now()
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
                                days, seconds = divmod(longest_lurk.total_seconds(), 86400)
                                months, days = divmod(days, 30)
                                hours, remainder = divmod(seconds, 3600)
                                minutes, seconds = divmod(remainder, 60)
                                periods = [("months", int(months)), ("days", int(days)), ("hours", int(hours)), ("minutes", int(minutes)), ("seconds", int(seconds))]
                                time_string = ", ".join(f"{value} {name}" for name, value in periods if value)
                                await ctx.send(f"{display_name} is currently lurking the most with {time_string} on the clock.")
                                chat_logger.info(f"Lurklead command run. User {display_name} has the longest lurk time of {time_string}.")
                            else:
                                await ctx.send("There was an issue retrieving the display name of the lurk leader.")
                        else:
                            await ctx.send("No one is currently lurking.")
                            chat_logger.info("Lurklead command run but no lurkers found.")
                    except Exception as e:
                        chat_logger.error(f"Error in lurklead_command: {e}")
                        await ctx.send("Oops, something went wrong while trying to find the lurk leader.")
        except Exception as e:
            chat_logger.error(f"Error in lurklead_command: {e}")
            await ctx.send("Oops, something went wrong while trying to check the command status.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='unlurk', aliases=('back',))
    async def unlurk_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("unlurk",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                    user_id = ctx.author.id
                    if ctx.author.name.lower() == CHANNEL_NAME.lower():
                        await ctx.send(f"Streamer, you've been here all along!")
                        chat_logger.info(f"{ctx.author.name} tried to unlurk in their own channel.")
                        return
                    await cursor.execute('SELECT start_time FROM lurk_times WHERE user_id = %s', (user_id,))
                    result = await cursor.fetchone()
                    if result:
                        time_now = datetime.now()
                        # Convert start_time from string to datetime
                        start_time = datetime.strptime(result["start_time"], "%Y-%m-%d %H:%M:%S")
                        elapsed_time = time_now - start_time
                        # Calculate the duration
                        days, seconds = divmod(elapsed_time.total_seconds(), 86400)
                        months, days = divmod(days, 30)
                        hours, remainder = divmod(seconds, 3600)
                        minutes, seconds = divmod(remainder, 60)
                        # Build the time string
                        periods = [("months", int(months)), ("days", int(days)), ("hours", int(hours)), ("minutes", int(minutes)), ("seconds", int(seconds))]
                        time_string = ", ".join(f"{value} {name}" for name, value in periods if value)
                        # Log the unlurk command execution and send a response
                        chat_logger.info(f"{ctx.author.name} is no longer lurking. Time lurking: {time_string}")
                        await ctx.send(f"{ctx.author.name} has returned from the shadows after {time_string}, welcome back!")
                        # Remove the user's start time from the database
                        await cursor.execute('DELETE FROM lurk_times WHERE user_id = %s', (user_id,))
                        await sqldb.commit()
                    else:
                        await ctx.send(f"{ctx.author.name} has returned from lurking, welcome back!")
        except Exception as e:
            chat_logger.error(f"Error in unlurk_command: {e}... Time now: {datetime.now()}... User Time {start_time if 'start_time' in locals() else 'N/A'}")
            await ctx.send("Oops, something went wrong with the unlurk command.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='clip')
    async def clip_command(self, ctx):
        global stream_online, bot_owner, CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("clip",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                    if not stream_online:
                        await ctx.send("Sorry, I can only create clips while the stream is online.")
                        return
                    headers = {
                        "Client-ID": CLIENT_ID,
                        "Authorization": f"Bearer {CHANNEL_AUTH}"
                    }
                    params = {
                        "broadcaster_id": CHANNEL_ID
                    }
                    async with aiohttp.ClientSession() as session:
                        async with session.post('https://api.twitch.tv/helix/clips', headers=headers, params=params) as clip_response:
                            if clip_response.status == 202:
                                clip_data = await clip_response.json()
                                clip_id = clip_data['data'][0]['id']
                                clip_url = f"http://clips.twitch.tv/{clip_id}"
                                await ctx.send(f"{ctx.author.name} created a clip: {clip_url}")
                                marker_description = f"Clip creation by {ctx.author.name}"
                                if await make_stream_marker(marker_description):
                                    twitch_logger.info(f"A stream marker was created for the clip: {marker_description}.")
                                else:
                                    twitch_logger.info("Failed to create a stream marker for the clip.")
                            else:
                                marker_description = f"Failed to create clip."
                                if await make_stream_marker(marker_description):
                                    twitch_logger.info(f"A stream marker was created for the clip: {marker_description}.")
                                else:
                                    twitch_logger.info("Failed to create a stream marker for the clip.")
                                await ctx.send(marker_description)
                                twitch_logger.error(f"Clip Error Code: {clip_response.status}")
        except Exception as e:
            twitch_logger.error(f"Error in clip_command: {e}")
            await ctx.send("An error occurred while executing the clip command.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='marker')
    async def marker_command(self, ctx, *, description: str):
        global stream_online, bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("marker",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not stream_online:
                        await ctx.send("Sorry, I can only make a marker while the stream is online.")
                        return
                    if await command_permissions(permissions, ctx.author):
                        marker_description = description if description else f"Marker made by {ctx.author.name}"
                        if await make_stream_marker(marker_description):
                            await ctx.send(f'A stream marker was created with the description: "{marker_description}".')
                        else:
                            await ctx.send("Failed to create a stream marker.")
                    else:
                        await ctx.send("You do not have the correct permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the marker command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='subscription', aliases=['mysub'])
    async def subscription_command(self, ctx):
        global bot_owner, CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("subscription",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if await command_permissions(permissions, ctx.author):
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
                        async with aiohttp.ClientSession() as session:
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
                                                await ctx.send(f"{user_name}, your gift subscription from {gifter_name} is {tier_name}.")
                                            else:
                                                await ctx.send(f"{user_name}, you are currently subscribed at {tier_name}.")
                                    else:
                                        await ctx.send(f"You are currently not subscribed to {CHANNEL_NAME}, you can subscribe here: https://subs.twitch.tv/{CHANNEL_NAME}")
                                else:
                                    await ctx.send("Failed to retrieve subscription information. Please try again later.")
                                    twitch_logger.error(f"Failed to retrieve subscription information. Status code: {subscription_response.status}")
                    else:
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the subscription command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='uptime')
    async def uptime_command(self, ctx):
        global stream_online, bot_owner, CLIENT_ID, CHANNEL_AUTH, CHANNEL_NAME
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("uptime",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not stream_online:
                        await ctx.send(f"{CHANNEL_NAME} is currently offline.")
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
                            async with aiohttp.ClientSession() as session:
                                async with session.get('https://api.twitch.tv/helix/streams', headers=headers, params=params) as response:
                                    if response.status == 200:
                                        data = await response.json()
                                        if data['data']:  # If stream is live
                                            started_at_str = data['data'][0]['started_at']
                                            started_at = datetime.strptime(started_at_str.replace('Z', '+00:00'), "%Y-%m-%dT%H:%M:%S%z")
                                            uptime = datetime.now(timezone.utc) - started_at
                                            hours, remainder = divmod(uptime.seconds, 3600)
                                            minutes, seconds = divmod(remainder, 60)
                                            await ctx.send(f"The stream has been live for {hours} hours, {minutes} minutes, and {seconds} seconds.")
                                            chat_logger.info(f"{CHANNEL_NAME} has been online for {uptime}.")
                                        else:
                                            await ctx.send(f"{CHANNEL_NAME} is currently offline.")
                                            api_logger.info(f"{CHANNEL_NAME} is currently offline.")
                                    else:
                                        await ctx.send(f"Failed to retrieve stream data. Status: {response.status}")
                                        chat_logger.error(f"Failed to retrieve stream data. Status: {response.status}")
                        except Exception as e:
                            chat_logger.error(f"Error retrieving stream data: {e}")
                            await ctx.send("Oops, something went wrong while trying to check uptime.")
                    else:
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the uptime command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=5, bucket=commands.Bucket.member)
    @commands.command(name='typo')
    async def typo_command(self, ctx, *, mentioned_username: str = None):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("typo",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if await command_permissions(permissions, ctx.author):
                        chat_logger.info("Typo Command ran.")
                        # Check if the broadcaster is running the command
                        if ctx.author.name.lower() == CHANNEL_NAME.lower() or (mentioned_username and mentioned_username.lower() == CHANNEL_NAME.lower()):
                            await ctx.send("Dear Streamer, you can never have a typo in your own channel.")
                            return
                        # Determine the target user: mentioned user or the command caller
                        target_user = mentioned_username.lower().lstrip('@') if mentioned_username else ctx.author.name.lower()
                        # Increment typo count in the database
                        await cursor.execute('INSERT INTO user_typos (username, typo_count) VALUES (%s, 1) ON DUPLICATE KEY UPDATE typo_count = typo_count + 1', (target_user,))
                        await sqldb.commit()
                        # Retrieve the updated count
                        await cursor.execute('SELECT typo_count FROM user_typos WHERE username = %s', (target_user,))
                        result = await cursor.fetchone()
                        typo_count = result.get("typo_count") if result else 0
                        # Send the message
                        chat_logger.info(f"{target_user} has made a new typo in chat, their count is now at {typo_count}.")
                        await ctx.send(f"Congratulations {target_user}, you've made a typo! You've made a typo in chat {typo_count} times.")
                    else:
                        await ctx.send("You do not have the required permissions to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in typo_command: {e}", exc_info=True)
            await ctx.send(f"An error occurred while trying to add to your typo count.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='typos', aliases=('typocount',))
    async def typos_command(self, ctx, *, mentioned_username: str = None):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("typos",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        chat_logger.info(f"{ctx.author.name} tried to use the typos command but lacked permissions.")
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                    chat_logger.info("Typos Command ran.")
                    if ctx.author.name.lower() == CHANNEL_NAME.lower():
                        await ctx.send(f"Dear Streamer, you can never have a typo in your own channel.")
                        return
                    mentioned_username_lower = mentioned_username.lower() if mentioned_username else ctx.author.name.lower()
                    target_user = mentioned_username_lower.lstrip('@')
                    await cursor.execute('SELECT typo_count FROM user_typos WHERE username = %s', (target_user,))
                    result = await cursor.fetchone()
                    typo_count = result.get("typo_count") if result else 0
                    chat_logger.info(f"{target_user} has made {typo_count} typos in chat.")
                    await ctx.send(f"{target_user} has made {typo_count} typos in chat.")
        except Exception as e:
            chat_logger.error(f"Error in typos_command: {e}")
            await ctx.send(f"An error occurred while trying to check typos.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='edittypos', aliases=('edittypo',))
    async def edittypo_command(self, ctx, mentioned_username: str = None, new_count: int = None):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("edittypos",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send(f"You do not have the required permissions to use this command.")
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
                            await ctx.send("Usage: !edittypos @username [amount]")
                            return
                        # Check if new_count is not provided
                        if new_count is None:
                            chat_logger.error("There was no count added to the command to edit.")
                            await ctx.send(f"Usage: !edittypos @{target_user} [amount]")
                            return
                        # Check if new_count is non-negative
                        if new_count < 0:
                            chat_logger.error(f"Typo count for {target_user} tried to be set to {new_count}.")
                            await ctx.send(f"Typo count cannot be negative.")
                            return
                        # Check if the user exists in the database
                        await cursor.execute('SELECT typo_count FROM user_typos WHERE username = %s', (target_user,))
                        result = await cursor.fetchone()
                        if result is not None:
                            # Update typo count in the database
                            await cursor.execute('UPDATE user_typos SET typo_count = %s WHERE username = %s', (new_count, target_user))
                            await sqldb.commit()
                            chat_logger.info(f"Typo count for {target_user} has been updated to {new_count}.")
                            await ctx.send(f"Typo count for {target_user} has been updated to {new_count}.")
                        else:
                            # If user does not exist, add the user with the given typo count
                            await cursor.execute('INSERT INTO user_typos (username, typo_count) VALUES (%s, %s)', (target_user, new_count))
                            await sqldb.commit()
                            chat_logger.info(f"Typo count for {target_user} has been set to {new_count}.")
                            await ctx.send(f"Typo count for {target_user} has been set to {new_count}.")
                    except Exception as e:
                        chat_logger.error(f"Error in edit_typo_command: {e}")
                        await ctx.send(f"An error occurred while trying to edit typos. {e}")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the edittypos command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='removetypos', aliases=('removetypo',))
    async def removetypos_command(self, ctx, mentioned_username: str = None, decrease_amount: int = 1):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("removetypos",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send(f"You do not have the required permissions to use this command.")
                        return
                    if mentioned_username is None:
                        chat_logger.error("Command missing username parameter.")
                        await ctx.send(f"Usage: !removetypos @username")
                        return
                    mentioned_username_lower = mentioned_username.lower() if mentioned_username else ctx.author.name.lower()
                    target_user = mentioned_username_lower.lstrip('@')
                    chat_logger.info(f"Remove Typos Command ran with params: {target_user}, decrease_amount: {decrease_amount}")
                    if decrease_amount < 0:
                        chat_logger.error(f"Invalid decrease amount {decrease_amount} for typo count of {target_user}.")
                        await ctx.send(f"Remove amount cannot be negative.")
                        return
                    await cursor.execute('SELECT typo_count FROM user_typos WHERE username = %s', (target_user,))
                    result = await cursor.fetchone()
                    if result:
                        current_count = result.get("typo_count")
                        new_count = max(0, current_count - decrease_amount)
                        await cursor.execute('UPDATE user_typos SET typo_count = %s WHERE username = %s', (new_count, target_user))
                        await sqldb.commit()
                        await ctx.send(f"Typo count for {target_user} decreased by {decrease_amount}. New count: {new_count}.")
                    else:
                        await ctx.send(f"No typo record found for {target_user}.")
        except Exception as e:
            chat_logger.error(f"Error in remove_typos_command: {e}")
            await ctx.send(f"An error occurred while trying to remove typos.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='steam')
    async def steam_command(self, ctx):
        global current_game, bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("steam",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
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
                async with aiohttp.ClientSession() as session:
                    response = await session.get("http://api.steampowered.com/ISteamApps/GetAppList/v2")
                    if response.status == 200:
                        data = await response.json()
                        steam_app_list = {app['name'].lower(): app['appid'] for app in data['applist']['apps']}
                        # Save to file
                        with open(file_path, 'w') as file:
                            json.dump(data, file)
                    else:
                        await ctx.send("Failed to fetch Steam games list.")
                        return
            game_name_lower = current_game.lower()
            if game_name_lower.startswith('the '):
                game_name_without_the = game_name_lower[4:]
                if game_name_without_the in steam_app_list:
                    game_id = steam_app_list[game_name_without_the]
                    store_url = f"https://store.steampowered.com/app/{game_id}"
                    await ctx.send(f"{current_game} is available on Steam, you can get it here: {store_url}")
                    return
            if game_name_lower in steam_app_list:
                game_id = steam_app_list[game_name_lower]
                store_url = f"https://store.steampowered.com/app/{game_id}"
                await ctx.send(f"{current_game} is available on Steam, you can get it here: {store_url}")
            else:
                await ctx.send("This game is not available on Steam.")
        except Exception as e:
            chat_logger.error(f"Error in steam_command: {e}")
            await ctx.send("An error occurred while trying to check the Steam store.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='deaths')
    async def deaths_command(self, ctx):
        global current_game, bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("deaths",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                if current_game is None:
                    await ctx.send("Current game is not set. Can't see death count.")
                    return
                chat_logger.info("Deaths command ran.")
                await cursor.execute('SELECT death_count FROM game_deaths WHERE game_name = %s', (current_game,))
                game_death_count_result = await cursor.fetchone()
                game_death_count = game_death_count_result.get("death_count") if game_death_count_result else 0
                await cursor.execute('SELECT death_count FROM total_deaths')
                total_death_count_result = await cursor.fetchone()
                total_death_count = total_death_count_result.get("death_count") if total_death_count_result else 0
                await cursor.execute('SELECT death_count FROM per_stream_deaths WHERE game_name = %s', (current_game,))
                stream_death_count_result = await cursor.fetchone()
                stream_death_count = stream_death_count_result.get("death_count") if stream_death_count_result else 0
                chat_logger.info(f"{ctx.author.name} has reviewed the death count for {current_game}. Total deaths are: {total_death_count}. Stream deaths are: {stream_death_count}")
                await ctx.send(f"We have died {game_death_count} times in {current_game}, with a total of {total_death_count} deaths in all games. This stream, we've died {stream_death_count} times.")
                if await command_permissions("mod", ctx.author):
                    chat_logger.info(f"Sending DEATHS event with game: {current_game}, death count: {stream_death_count}")
                    asyncio.create_task(websocket_notice(event="DEATHS", death=stream_death_count, game=current_game))
        except Exception as e:
            chat_logger.error(f"Error in deaths_command: {e}")
            await ctx.send(f"An error occurred while executing the command. {e}")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='deathadd', aliases=['death+'])
    async def deathadd_command(self, ctx):
        global current_game, bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("deathadd",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                if current_game is None:
                    await ctx.send("Current game is not set. Cannot add death to nothing.")
                    return
                try:
                    chat_logger.info("Death Add Command ran by a mod or broadcaster.")
                    await cursor.execute("SELECT COUNT(*) FROM total_deaths")
                    count_result = await cursor.fetchone()
                    if count_result is not None and count_result.get("count") == 0:
                        await cursor.execute("INSERT INTO total_deaths (death_count) VALUES (0)")
                        await sqldb.commit()
                        chat_logger.info("Initialized total_deaths table.")
                    await cursor.execute(
                        'INSERT INTO game_deaths (game_name, death_count) VALUES (%s, 1) ON DUPLICATE KEY UPDATE death_count = death_count + 1',
                        (current_game,))
                    await cursor.execute('UPDATE total_deaths SET death_count = death_count + 1')
                    # Update per_stream_deaths
                    await cursor.execute(
                        'INSERT INTO per_stream_deaths (game_name, death_count) VALUES (%s, 1) ON DUPLICATE KEY UPDATE death_count = death_count + 1',
                        (current_game,))
                    await sqldb.commit()
                    await cursor.execute('SELECT death_count FROM game_deaths WHERE game_name = %s', (current_game,))
                    game_death_count_result = await cursor.fetchone()
                    game_death_count = game_death_count_result.get("death_count") if game_death_count_result else 0
                    await cursor.execute('SELECT death_count FROM total_deaths')
                    total_death_count_result = await cursor.fetchone()
                    total_death_count = total_death_count_result.get("death_count") if total_death_count_result else 0
                    await cursor.execute('SELECT death_count FROM per_stream_deaths WHERE game_name = %s', (current_game,))
                    stream_death_count_result = await cursor.fetchone()
                    stream_death_count = stream_death_count_result.get("death_count") if stream_death_count_result else 0
                    chat_logger.info(f"{current_game} now has {game_death_count} deaths.")
                    chat_logger.info(f"Total death count has been updated to: {total_death_count}")
                    chat_logger.info(f"Stream death count for {current_game} is now: {stream_death_count}")
                    await ctx.send(f"We have died {game_death_count} times in {current_game}, with a total of {total_death_count} deaths in all games. This stream, we've died {stream_death_count} times in {current_game}.")
                    asyncio.create_task(websocket_notice(event="DEATHS", death=stream_death_count, game=current_game))
                except Exception as e:
                    await ctx.send(f"An error occurred while executing the command. {e}")
                    chat_logger.error(f"Error in deathadd_command: {e}")
        except Exception as e:
            chat_logger.error(f"Unexpected error in deathadd_command: {e}")
            await ctx.send(f"An unexpected error occurred: {e}")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='deathremove', aliases=['death-'])
    async def deathremove_command(self, ctx):
        global current_game, bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("deathremove",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                if current_game is None:
                    await ctx.send("Current game is not set. Can't remove from nothing.")
                    return
                try:
                    chat_logger.info("Death Remove Command Ran")
                    await cursor.execute(
                        'UPDATE game_deaths SET death_count = CASE WHEN death_count > 0 THEN death_count - 1 ELSE 0 END WHERE game_name = %s',
                        (current_game,))
                    await cursor.execute('UPDATE total_deaths SET death_count = CASE WHEN death_count > 0 THEN death_count - 1 ELSE 0 END')
                    await cursor.execute(
                        'UPDATE per_stream_deaths SET death_count = CASE WHEN death_count > 0 THEN death_count - 1 ELSE 0 END WHERE game_name = %s',
                        (current_game,))
                    await sqldb.commit()
                    await cursor.execute('SELECT death_count FROM game_deaths WHERE game_name = %s', (current_game,))
                    game_death_count_result = await cursor.fetchone()
                    game_death_count = game_death_count_result.get("death_count") if game_death_count_result else 0
                    await cursor.execute('SELECT death_count FROM total_deaths')
                    total_death_count_result = await cursor.fetchone()
                    total_death_count = total_death_count_result.get("death_count") if total_death_count_result else 0
                    await cursor.execute('SELECT death_count FROM per_stream_deaths WHERE game_name = %s', (current_game,))
                    stream_death_count_result = await cursor.fetchone()
                    stream_death_count = stream_death_count_result.get("death_count") if stream_death_count_result else 0
                    chat_logger.info(f"{current_game} death has been removed, we now have {game_death_count} deaths.")
                    chat_logger.info(f"Total death count has been updated to: {total_death_count} to reflect the removal.")
                    await ctx.send(f"Death removed from {current_game}, count is now {game_death_count}. Total deaths in all games: {total_death_count}.")
                    asyncio.create_task(websocket_notice(event="DEATHS", death=stream_death_count, game=current_game))
                except Exception as e:
                    await ctx.send(f"An error occurred while executing the command. {e}")
                    chat_logger.error(f"Error in deathremove_command: {e}")
        except Exception as e:
            chat_logger.error(f"Unexpected error in deathremove_command: {e}")
            await ctx.send(f"An unexpected error occurred: {e}")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='game')
    async def game_command(self, ctx):
        global current_game, bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("game",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                if current_game is not None:
                    await ctx.send(f"The current game we're playing is: {current_game}")
                else:
                    await ctx.send("We're not currently streaming any specific game category.")
        except Exception as e:
            chat_logger.error(f"Error in game_command: {e}")
            await ctx.send("Oops, something went wrong while trying to retrieve the game information.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='followage')
    async def followage_command(self, ctx, *, mentioned_username: str = None):
        global bot_owner, CLIENT_ID, CHANNEL_AUTH
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("followage",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
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
                            await ctx.send(f"The user {target_user} is not a user on Twitch.")
                            return
                    else:
                        params = {
                            'user_id': ctx.author.id,
                            'broadcaster_id': CHANNEL_ID
                        }
                    async with aiohttp.ClientSession() as session:
                        async with session.get('https://api.twitch.tv/helix/channels/followers', headers=headers, params=params) as response:
                            if response.status == 200:
                                data = await response.json()
                                if data['total'] > 0:
                                    followed_at_str = data['data'][0]['followed_at']
                                    followed_at = datetime.strptime(followed_at_str.replace('Z', '+00:00'), "%Y-%m-%dT%H:%M:%S%z")
                                    followage = datetime.now(timezone.utc) - followed_at
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
                                    await ctx.send(f"{target_user} has been following for: {followage_text}.")
                                    chat_logger.info(f"{target_user} has been following for: {followage_text}.")
                                else:
                                    await ctx.send(f"{target_user} does not follow {CHANNEL_NAME}.")
                                    chat_logger.info(f"{target_user} does not follow {CHANNEL_NAME}.")
                            else:
                                await ctx.send(f"Failed to retrieve followage information for {target_user}.")
                                chat_logger.info(f"Failed to retrieve followage information for {target_user}.")
                except Exception as e:
                    chat_logger.error(f"Error retrieving followage: {e}")
                    await ctx.send(f"Oops, something went wrong while trying to check followage.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the followage command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='schedule')
    async def schedule_command(self, ctx):
        global bot_owner, CLIENT_ID, CHANNEL_AUTH
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("schedule",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                await cursor.execute("SELECT timezone FROM profile")
                timezone_row = await cursor.fetchone()
                timezone = timezone_row["timezone"] if timezone_row else 'UTC'
                tz = pytz.timezone(timezone)
                current_time = datetime.now(tz)
                headers = {
                    'Client-ID': CLIENT_ID,
                    'Authorization': f'Bearer {CHANNEL_AUTH}'
                }
                params = {
                    'broadcaster_id': CHANNEL_ID,
                    'first': '3'
                }
                try:
                    async with aiohttp.ClientSession() as session:
                        async with session.get('https://api.twitch.tv/helix/schedule', headers=headers, params=params) as response:
                            if response.status == 200:
                                data = await response.json()
                                segments = data['data']['segments']
                                vacation = data['data'].get('vacation')
                                # Check if vacation is ongoing
                                if vacation and 'start_time' in vacation and 'end_time' in vacation:
                                    vacation_start = datetime.strptime(vacation['start_time'][:-1], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=pytz.utc).astimezone(tz)
                                    vacation_end = datetime.strptime(vacation['end_time'][:-1], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=pytz.utc).astimezone(tz)
                                    if vacation_start <= current_time <= vacation_end:
                                        # Check if there is a stream within 2 days after the vacation ends
                                        for segment in segments:
                                            start_time_utc = datetime.strptime(segment['start_time'][:-1], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=pytz.utc)
                                            start_time = start_time_utc.astimezone(tz)
                                            if start_time >= vacation_end and (start_time - current_time).days <= 2:
                                                await ctx.send(f"I'm on vacation until {vacation_end.strftime('%A, %d %B %Y')} ({vacation_end.strftime('%H:%M %Z')} UTC). My next stream is on {start_time.strftime('%A, %d %B %Y')} ({start_time.strftime('%H:%M %Z')} UTC).")
                                                return
                                        await ctx.send(f"I'm on vacation until {vacation_end.strftime('%A, %d %B %Y')} ({vacation_end.strftime('%H:%M %Z')} UTC). No streams during this time!")
                                        return
                                next_stream = None
                                canceled_stream = None
                                for segment in segments:
                                    # Check if the segment is canceled
                                    if segment.get('canceled_until'):
                                        canceled_until = datetime.strptime(segment['canceled_until'][:-1], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=pytz.utc).astimezone(tz)
                                        start_time_utc = datetime.strptime(segment['start_time'][:-1], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=pytz.utc)
                                        canceled_stream = (start_time_utc.astimezone(tz), canceled_until)
                                        continue
                                    start_time_utc = datetime.strptime(segment['start_time'][:-1], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=pytz.utc)
                                    start_time = start_time_utc.astimezone(tz)
                                    if start_time > current_time:
                                        next_stream = segment
                                        break  # Exit the loop after finding the first upcoming stream
                                if canceled_stream:
                                    canceled_time, canceled_until = canceled_stream
                                    await ctx.send(f"The next stream scheduled for {canceled_time.strftime('%A, %d %B %Y')} ({canceled_time.strftime('%H:%M %Z')} UTC) has been canceled.")
                                if next_stream:
                                    start_date_utc = next_stream['start_time'].split('T')[0]  # Extract date from start_time
                                    start_time_utc = datetime.strptime(next_stream['start_time'][:-1], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=pytz.utc)
                                    start_time = start_time_utc.astimezone(tz)
                                    time_until = start_time - current_time
                                    # Format time_until
                                    days, seconds = time_until.days, time_until.seconds
                                    hours = seconds // 3600
                                    minutes = (seconds % 3600) // 60
                                    seconds = (seconds % 60)
                                    time_str = f"{days} days, {hours} hours, {minutes} minutes, {seconds} seconds" if days else f"{hours} hours, {minutes} minutes, {seconds} seconds"
                                    await ctx.send(f"The next stream will be on {start_date_utc} at {start_time.strftime('%H:%M %Z')} ({start_time_utc.strftime('%H:%M')} UTC), which is in {time_str}. Check out the full schedule here: https://www.twitch.tv/{CHANNEL_NAME}/schedule")
                                else:
                                    await ctx.send(f"There are no upcoming streams in the next three days.")
                            else:
                                await ctx.send(f"Something went wrong while trying to get the schedule from Twitch.")
                except Exception as e:
                    chat_logger.error(f"Error retrieving schedule: {e}")
                    await ctx.send(f"Oops, something went wrong while trying to check the schedule.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the schedule command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='checkupdate')
    async def checkupdate_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("checkupdate",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                API_URL = "https://api.botofthespecter.com/versions"
                async with ClientSession() as session:
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
                                await ctx.send(message)
                            else:
                                message = f"There is no {SYSTEM.lower()} update pending. You are currently running V{VERSION}."
                                bot_logger.info(f"{message}")
                                await ctx.send(message)
                        else:
                            await ctx.send("Failed to check for updates. Please try again later.")
        except Exception as e:
            chat_logger.error(f"Error in checkupdate_command: {e}")
            await ctx.send("Oops, something went wrong while trying to check for updates.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='shoutout', aliases=('so',))
    async def shoutout_command(self, ctx, user_to_shoutout: str = None):
        global bot_owner, shoutout_user
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("shoutout",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
            chat_logger.info(f"Shoutout command running from {ctx.author.name}")
            if not user_to_shoutout:
                chat_logger.error(f"Shoutout command missing username parameter.")
                await ctx.send(f"Usage: !so @username")
                return
            try:
                chat_logger.info(f"Shoutout command trying to run.")
                user_to_shoutout = user_to_shoutout.lstrip('@')
                is_valid_user = await is_valid_twitch_user(user_to_shoutout)
                if not is_valid_user:
                    chat_logger.error(f"User {user_to_shoutout} does not exist on Twitch.")
                    await ctx.send(f"The user @{user_to_shoutout} does not exist on Twitch.")
                    return
                chat_logger.info(f"Shoutout for {user_to_shoutout} ran by {ctx.author.name}")
                user_info = await self.fetch_users(names=[user_to_shoutout])
                if not user_info:
                    await ctx.send("Failed to fetch user information.")
                    return
                user_id = user_info[0].id
                game = await get_latest_stream_game(user_id, user_to_shoutout)
                if not game:
                    shoutout_message = (
                        f"Hey, huge shoutout to @{user_to_shoutout}! "
                        f"You should go give them a follow over at "
                        f"https://www.twitch.tv/{user_to_shoutout}"
                    )
                else:
                    shoutout_message = (
                        f"Hey, huge shoutout to @{user_to_shoutout}! "
                        f"You should go give them a follow over at "
                        f"https://www.twitch.tv/{user_to_shoutout} where they were playing: {game}"
                    )
                chat_logger.info(shoutout_message)
                await ctx.send(shoutout_message)
                await add_shoutout(user_to_shoutout, user_id)
            except Exception as e:
                chat_logger.error(f"Error in shoutout_command: {e}")
                await ctx.send("An error occurred while processing the shoutout command.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the shoutout command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='addcommand')
    async def addcommand_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("addcommand",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the required permissions for this command
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                # Parse the command and response from the message
                try:
                    command, response = ctx.message.content.strip().split(' ', 1)[1].split(' ', 1)
                except ValueError:
                    await ctx.send(f"Invalid command format. Use: !addcommand [command] [response]")
                    return
                # Insert the command and response into the database
                async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute('INSERT INTO custom_commands (command, response, status) VALUES (%s, %s, %s)', (command, response, 'Enabled'))
                    await sqldb.commit()
                chat_logger.info(f"{ctx.author.name} has added the command !{command} with the response: {response}")
                await ctx.send(f'Custom command added: !{command}')
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the addcommand command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='editcommand')
    async def editcommand_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("editcommand",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the required permissions for this command
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                # Parse the command and new response from the message
                try:
                    command, new_response = ctx.message.content.strip().split(' ', 1)[1].split(' ', 1)
                except ValueError:
                    await ctx.send(f"Invalid command format. Use: !editcommand [command] [new_response]")
                    return
                # Update the command's response in the database
                async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute('UPDATE custom_commands SET response = %s WHERE command = %s', (new_response, command))
                    await sqldb.commit()
                chat_logger.info(f"{ctx.author.name} has edited the command !{command} to have the new response: {new_response}")
                await ctx.send(f'Custom command edited: !{command}')
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the editcommand command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='removecommand')
    async def removecommand_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("removecommand",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the required permissions for this command
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                # Parse the command from the message
                try:
                    command = ctx.message.content.strip().split(' ')[1]
                except IndexError:
                    await ctx.send(f"Invalid command format. Use: !removecommand [command]")
                    return
                # Delete the command from the database
                async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute('DELETE FROM custom_commands WHERE command = %s', (command,))
                    await sqldb.commit()
                chat_logger.info(f"{ctx.author.name} has removed {command}")
                await ctx.send(f'Custom command removed: !{command}')
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the removecommand command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='enablecommand')
    async def enablecommand_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("enablecommand",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the required permissions for this command
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                # Parse the command from the message
                try:
                    command = ctx.message.content.strip().split(' ')[1]
                except IndexError:
                    await ctx.send(f"Invalid command format. Use: !enablecommand [command]")
                    return
                # First check if it's a built-in command
                await cursor.execute('SELECT command FROM builtin_commands WHERE command = %s', (command,))
                builtin_result = await cursor.fetchone()
                if builtin_result:
                    # It's a built-in command, enable it
                    await cursor.execute('UPDATE builtin_commands SET status = %s WHERE command = %s', ('Enabled', command))
                    await sqldb.commit()
                    chat_logger.info(f"{ctx.author.name} has enabled the built-in command: {command}")
                    await ctx.send(f'Built-in command enabled: !{command}')
                else:
                    # Check if it's a custom command
                    await cursor.execute('SELECT command FROM custom_commands WHERE command = %s', (command,))
                    custom_result = await cursor.fetchone()
                    if custom_result:
                        # It's a custom command, enable it
                        await cursor.execute('UPDATE custom_commands SET status = %s WHERE command = %s', ('Enabled', command))
                        await sqldb.commit()
                        chat_logger.info(f"{ctx.author.name} has enabled the custom command: {command}")
                        await ctx.send(f'Custom command enabled: !{command}')
                    else:
                        # Command doesn't exist in either table
                        await ctx.send(f"Command !{command} not found.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the enablecommand command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='disablecommand')
    async def disablecommand_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("disablecommand",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    # Check if the user has the required permissions for this command
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                # Parse the command from the message
                try:
                    command = ctx.message.content.strip().split(' ')[1]
                except IndexError:
                    await ctx.send(f"Invalid command format. Use: !disablecommand [command]")
                    return
                # First check if it's a built-in command
                await cursor.execute('SELECT command FROM builtin_commands WHERE command = %s', (command,))
                builtin_result = await cursor.fetchone()
                if builtin_result:
                    # It's a built-in command, disable it
                    await cursor.execute('UPDATE builtin_commands SET status = %s WHERE command = %s', ('Disabled', command))
                    await sqldb.commit()
                    chat_logger.info(f"{ctx.author.name} has disabled the built-in command: {command}")
                    await ctx.send(f'Built-in command disabled: !{command}')
                else:
                    # Check if it's a custom command
                    await cursor.execute('SELECT command FROM custom_commands WHERE command = %s', (command,))
                    custom_result = await cursor.fetchone()
                    if custom_result:
                        # It's a custom command, disable it
                        await cursor.execute('UPDATE custom_commands SET status = %s WHERE command = %s', ('Disabled', command))
                        await sqldb.commit()
                        chat_logger.info(f"{ctx.author.name} has disabled the custom command: {command}")
                        await ctx.send(f'Custom command disabled: !{command}')
                    else:
                        # Command doesn't exist in either table
                        await ctx.send(f"Command !{command} not found.")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the disablecommand command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='slots')
    async def slots_command(self, ctx):
        global bot_owner
        user_id = str(ctx.author.id)
        user_name = ctx.author.name
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("slots",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                    # Fetch user's points from the database
                    await cursor.execute("SELECT points FROM bot_points WHERE user_id = %s", (user_id,))
                    user_data = await cursor.fetchone()
                    if not user_data:
                        await cursor.execute(
                            "INSERT INTO bot_points (user_id, user_name, points) VALUES (%s, %s, %s)",
                            (user_id, user_name, 0)
                        )
                        await sqldb.commit()
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
                    await sqldb.commit()
                    await ctx.send(message)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the slots command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name='kill')
    async def kill_command(self, ctx, mention: str = None):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("kill",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                async with aiohttp.ClientSession() as session:
                    async with session.get(f"https://api.botofthespecter.com/kill?api_key={API_TOKEN}") as response:
                        if response.status == 200:
                            data = await response.json()
                            kill_message = data.get("killcommand", {})
                            if not kill_message:
                                chat_logger.error("No 'killcommand' found in the API response.")
                                await ctx.send("No kill messages found.")
                                return
                        else:
                            chat_logger.error(f"Failed to fetch kill messages from API. Status code: {response.status}")
                            await ctx.send("Unable to retrieve kill messages.")
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
                await ctx.send(result)
                chat_logger.info(f"Kill command executed by {ctx.author.name}: {result}")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the kill command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=5, bucket=commands.Bucket.member)
    @commands.command(name="roulette")
    async def roulette_command(self, ctx):
        global bot_owner
        user_id = str(ctx.author.id)
        user_name = ctx.author.name
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("roulette",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                # Fetch user's points from the database
                await cursor.execute("SELECT points FROM bot_points WHERE user_id = %s", (user_id,))
                user_data = await cursor.fetchone()
                if not user_data:
                    await cursor.execute(
                        "INSERT INTO bot_points (user_id, user_name, points) VALUES (%s, %s, %s)",
                        (user_id, user_name, 0)
                    )
                    await sqldb.commit()
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
                    await sqldb.commit()
                await ctx.send(message)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the roulette command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.member)
    @commands.command(name="rps")
    async def rps_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("rps",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                choices = ["rock", "paper", "scissors"]
                bot_choice = random.choice(choices)
                user_input = ctx.message.content.split(' ')[1].lower() if len(ctx.message.content.split(' ')) > 1 else None
                if user_input not in choices:
                    await ctx.send(f'Please choose "Rock", "Paper" or "Scissors". Usage: !rps <choice>')
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
                await ctx.send(result)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the RPS command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name="story")
    async def story_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("story",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                words = ctx.message.content.split(' ')[1:]
                if len(words) < 5:
                    await ctx.send(f"{ctx.author.name}, please provide 5 words. (noun, verb, adjective, adverb, action) Usage: !story <word1> <word2> <word3> <word4> <word5>")
                    return
                template = "Once upon a time, there was a {0} who loved to {1}. One day, they found a {2} {3} and decided to {4}."
                story = template.format(*words)
                response = await self.handle_ai_response(story, ctx.author.id, ctx.author.name)
                await ctx.send(response)
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the story command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name="convert")
    async def convert_command(self, ctx, *args):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Check if the 'convert' command is enabled
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("convert",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                try:
                    startwitch = ["€", "$", "£", "¥", "₹", "₣", "₽", "₺", "₩", "₼", "₱", "₪", "₴", "₭", "₨", "฿", "₮", "₳", "₵", "ƒ", "៛", "﷼", "R$"]
                    if len(args) == 3 and (args[0].startswith(symbol) for symbol in startwitch):
                        # Handle currency conversion
                        amount_str = args[0]
                        amount = float(amount_str[1:])
                        from_currency = args[1].upper()
                        to_currency = args[2].upper()
                        converted_amount = await convert_currency(amount, from_currency, to_currency)
                        formatted_converted_amount = f"{converted_amount:,.2f}"
                        await ctx.send(f"The currency exchange for {amount_str} {from_currency} is {formatted_converted_amount} {to_currency}")
                    elif len(args) == 3:
                        # Handle unit conversion
                        amount_str = args[0]
                        amount = float(amount_str)
                        from_unit = args[1]
                        to_unit = args[2]
                        quantity = amount * ureg(from_unit)
                        converted_quantity = quantity.to(to_unit)
                        formatted_converted_quantity = f"{converted_quantity.magnitude:,.2f}"
                        await ctx.send(f"{amount_str} {from_unit} in {to_unit} is {formatted_converted_quantity} {converted_quantity.units}")
                    else:
                        await ctx.send("Invalid format. Please use: !convert <amount> <unit> <to_unit> or !convert $<amount> <from_currency> <to_currency>")
                except Exception as e:
                    await ctx.send("Failed to convert. Please ensure the format is correct: !convert <amount> <unit> <to_unit> or !convert $<amount> <from_currency> <to_currency.")
                    sanitized_error = str(e).replace(EXCHANGE_RATE_API_KEY, '[API_KEY]')
                    api_logger.error(f"An error occurred in convert command: {sanitized_error}")
        except Exception as e:
            chat_logger.error(f"An unexpected error occurred during the execution of the convert command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=5, bucket=commands.Bucket.default)
    @commands.command(name='todo')
    async def todo_command(self, ctx: commands.Context):
        global bot_owner
        message_content = ctx.message.content.strip()
        user = ctx.author
        user_id = user.id
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("todo",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
            if message_content.lower() == '!todo':
                await ctx.send(f"{user.name}, check the todo list at https://members.botofthespecter.com/{CHANNEL_NAME}/")
                chat_logger.info(f"{user.name} viewed the todo list.")
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
                        await ctx.send(f"{user.name}, you do not have the required permissions for this action.")
                        chat_logger.warning(f"{user.name} attempted to {action} without proper permissions.")
                        return
                await actions[action](ctx, params, user_id, sqldb)
                chat_logger.info(f"{user.name} executed the action {action} with params {params}.")
            else:
                await ctx.send(f"{user.name}, unrecognized action. Please use Add, Edit, Remove, Complete, Confirm, or View.")
                chat_logger.warning(f"{user.name} used an unrecognized action: {action}.")
        except Exception as e:
            bot_logger.error(f"An error occurred in todo_command: {e}")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=5, bucket=commands.Bucket.default)
    @commands.command(name="subathon")
    async def subathon_command(self, ctx, action: str = None, minutes: int = None):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("subathon",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
            user = ctx.author
            # Check permissions for valid actions
            if action in ['start', 'stop', 'pause', 'resume', 'addtime']:
                if not await command_permissions("mod", user):
                    await ctx.send(f"{user.name}, you do not have the required permissions for this action.")
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
                    await ctx.send(f"{user.name}, please provide the number of minutes to add. Usage: !subathon addtime <minutes>")
            elif action == "status":
                await subathon_status(ctx)
            else:
                await ctx.send(f"{user.name}, invalid action. Use !subathon start|stop|pause|resume|addtime|status")
        except Exception as e:
            chat_logger.error(f"An error occurred during the execution of the subathon command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='heartrate')
    async def heartrate_command(self, ctx):
        global HEARTRATE, bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Check if the 'convert' command is enabled
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("heartrate",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                    if HEARTRATE is None:
                        await ctx.send(f"The Heart Rate is not turned on right now.")
                    else:
                        await ctx.send(f"The current Heart Rate is: {HEARTRATE}")
        except Exception as e:
            chat_logger.error(f"An error occurred in the heartrate command: {e}")
            await ctx.send("An unexpected error occurred. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.user)
    @commands.command(name='watchtime')
    async def watchtime_command(self, ctx):
        global bot_owner
        user_id = ctx.author.id
        username = ctx.author.name
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                # Check if the 'convert' command is enabled
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("watchtime",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
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
                    await ctx.send(f"@{username}, you have watched for {live_str} live, and {offline_str} offline.")
                else:
                    # If no watch time data is found
                    await ctx.send(f"@{username}, no watch time data recorded for you yet.")
        except Exception as e:
            bot_logger.error(f"Error fetching watch time for {username}: {e}")
            await ctx.send(f"@{username}, an error occurred while fetching your watch time.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='startlotto')
    async def startlotto_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("startlotto",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
                        return
                done = await generate_winning_lotto_numbers()
                if done == True:
                    await ctx.send("Lotto numbers have been generated. Good luck everyone!")
                elif done == "exists":
                    await ctx.send("Lotto numbers have already been generated. Ready to draw the winners.")
                else:
                    await ctx.send("There was an error generating the lotto numbers.")
        except Exception as e:
            bot_logger.error(f"Error in starting lotto game: {e}")
            await ctx.send("There was an error generating the lotto numbers.")
        finally:
            await sqldb.ensure_closed()

    @commands.cooldown(rate=1, per=15, bucket=commands.Bucket.default)
    @commands.command(name='drawlotto')
    async def drawlotto_command(self, ctx):
        global bot_owner
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status, permission FROM builtin_commands WHERE command=%s", ("drawlotto",))
                result = await cursor.fetchone()
                if result:
                    status = result.get("status")
                    permissions = result.get("permission")
                    if status == 'Disabled' and ctx.author.name != bot_owner:
                        return
                    if not await command_permissions(permissions, ctx.author):
                        await ctx.send("You do not have the required permissions to use this command.")
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
                    await ctx.send("No winning numbers selected. The draw cannot proceed.")
                    return  # If there are no winning numbers, end the draw
                # Extract winning numbers and supplementary numbers
                winning_set = set(map(int, winning_lotto_numbers["winning_numbers"].split(', ')))
                supplementary_set = set(map(int, winning_lotto_numbers["supplementary_numbers"].split(', ')))
                if not user_lotto_numbers:
                    await ctx.send(f"No users have played the lotto yet!")
                    return  # If no users have played, send a message and exit
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
                        await sqldb.commit()
                        # Retrieve updated points
                        await cursor.execute("SELECT points FROM bot_points WHERE user_name = %s", (user_name,))
                        total_points_data = await cursor.fetchone()
                        total_points = total_points_data["points"] if total_points_data else prize
                        # Send message about the win
                        message = f"@{user_name} you've won {division} and received {prize} points! Total points: {total_points}"
                        await ctx.send(message)
                    # Remove user lotto entry after the draw
                    await cursor.execute("DELETE FROM stream_lotto WHERE username = %s", (user_name,))
                    await sqldb.commit()
                # Clear winning numbers after the draw
                await cursor.execute("TRUNCATE TABLE stream_lotto_winning_numbers")
                await sqldb.commit()
                if not user_lotto_numbers:
                    await ctx.send(f"No winners this time! The winning numbers were: {winning_set} and Supplementary: {supplementary_set}")
        except Exception as e:
            bot_logger.error(f"Error in Drawing Lotto Winners: {e}")
            await ctx.send("Sorry, there is an error in drawing the lotto winners.")
        finally:
            await sqldb.ensure_closed()

# Functions for all the commands
##
# Function  to check if the user is a real user on Twitch
async def is_valid_twitch_user(user_name):
    global CLIENT_ID, CHANNEL_AUTH
    url = f"https://api.twitch.tv/helix/users?login={user_name}"
    headers = {
        "Client-ID": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}"
    }
    async with aiohttp.ClientSession() as session:
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
    async with aiohttp.ClientSession() as session:
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
    # Check if the user is the broadcaster
    elif user.name == CHANNEL_NAME:
        chat_logger.info(f"Command Permission checked, {user.name} is the Broadcaster")
        return True
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
        async with aiohttp.ClientSession() as session:
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
    async with aiohttp.ClientSession() as session:
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
    async with aiohttp.ClientSession() as session:
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
    async with aiohttp.ClientSession() as session:
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
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute('INSERT INTO seen_users (username, status) VALUES (%s, %s)', (username, "True"))
            await sqldb.commit()
    except Exception as e:
        bot_logger.error(f"Error occurred while adding user '{username}' to seen_users table: {e}")
    finally:
        await sqldb.ensure_closed()

# Function to fetch custom API responses
async def fetch_api_response(url, json_flag=False):
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(url) as response:
                if response.status == 200:
                    if json_flag:
                        return await response.json()
                    else:
                        return await response.text()
                else:
                    return f"Status Error: {response.status}"
    except Exception as e:
        return f"Exception Error: {str(e)}"

# Function to update custom counts
async def update_custom_count(command):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute('SELECT count FROM custom_counts WHERE command = %s', (command,))
            result = await cursor.fetchone()
            if result:
                current_count = result.get("count")
                new_count = current_count + 1
                await cursor.execute('UPDATE custom_counts SET count = %s WHERE command = %s', (new_count, command))
                chat_logger.info(f"Updated count for command '{command}' to {new_count}.")
            else:
                await cursor.execute('INSERT INTO custom_counts (command, count) VALUES (%s, %s)', (command, 1))
                chat_logger.info(f"Inserted new command '{command}' with count 1.")
        await sqldb.commit()
    except Exception as e:
        chat_logger.error(f"Error updating count for command '{command}': {e}")
        await sqldb.rollback()
    finally:
        await sqldb.ensure_closed()

async def get_custom_count(command):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
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
        await sqldb.ensure_closed()

# Functions for weather
async def get_streamer_weather():
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute("SELECT weather_location FROM profile")
            info = await cursor.fetchone()
            if info:
                location = info["weather_location"]
                chat_logger.info(f"Got {location} weather info.")
                return location
            else:
                return None
    finally:
        await sqldb.ensure_closed()

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
    async with aiohttp.ClientSession() as session:
        async with session.patch(url, headers=headers, json=params) as response:
            if response.status == 200:
                twitch_logger.info(f'Stream title updated to: {new_title}')
            else:
                twitch_logger.error(f'Failed to update stream title: {await response.text()}')

# Function to update the current stream category
async def update_twitch_game(game_name: str):
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
    # API URLs
    internal_api_url = "https://api.botofthespecter.com/games"
    twitch_game_update_url = "https://api.twitch.tv/helix/channels"
    # Headers for Twitch API
    twitch_headers = {
        "Authorization": f"Bearer {CHANNEL_AUTH}",
        "Client-ID": CLIENT_ID,
        "Content-Type": "application/json",
    }
    # Fetch game ID using internal API
    async with aiohttp.ClientSession() as session:
        # Call internal API to get the game ID
        params = {
            "api_key": API_TOKEN,
            "twitch_auth_token": CHANNEL_AUTH,
            "game_name": game_name,
        }
        async with session.get(internal_api_url, params=params) as response:
            if response.status == 200:
                data = await response.json()
                if "id" in data:
                    game_id = data["id"]
                    game_name = data["name"]
                else:
                    raise GameNotFoundException(f"Game '{game_name}' not found in internal API response.")
            else:
                error_message = await response.text()
                raise GameNotFoundException(f"Failed to fetch game ID from internal API: {error_message}")
        # Update the Twitch stream game/category
        payload = {
            "broadcaster_id": CHANNEL_ID,
            "game_id": game_id
        }
        # Update the Twitch stream game/category
        async with session.patch(twitch_game_update_url, headers=twitch_headers, json=payload) as twitch_response:
            if twitch_response.status == 200:
                twitch_logger.info(f"Stream game updated to: {game_name}")
                return game_name
            else:
                error_message = await twitch_response.text()
                raise GameUpdateFailedException(f"Failed to update stream game: {error_message}")

# Enqueue shoutout requests
async def add_shoutout(user_to_shoutout, user_id):
    await shoutout_queue.put((user_to_shoutout, user_id))
    twitch_logger.info(f"Added shoutout request for {user_to_shoutout} to the queue.")

# Worker to process shoutout queue
async def shoutout_worker():
    global last_shoutout_time
    while True:
        user_to_shoutout, user_id = await shoutout_queue.get()
        now = datetime.now()
        # Check global cooldown
        if last_shoutout_time and now - last_shoutout_time < TWITCH_SHOUTOUT_GLOBAL_COOLDOWN:
            wait_time = (TWITCH_SHOUTOUT_GLOBAL_COOLDOWN - (now - last_shoutout_time)).total_seconds()
            twitch_logger.info(f"Waiting {wait_time} seconds for global cooldown.")
            await asyncio.sleep(wait_time)
        # Check user-specific cooldown
        if user_id in shoutout_tracker:
            last_user_shoutout_time = shoutout_tracker[user_id]
            if now - last_user_shoutout_time < TWITCH_SHOUTOUT_USER_COOLDOWN:
                twitch_logger.info(f"Skipping shoutout for {user_to_shoutout}. User-specific cooldown in effect.")
                shoutout_queue.task_done()
                continue
        # Trigger the shoutout
        await trigger_twitch_shoutout(user_to_shoutout, user_id)
        twitch_logger.info(f"Shoutout sent for {user_to_shoutout}.")
        shoutout_user[user_to_shoutout] = {"timestamp": time.time()}
        asyncio.create_task(remove_shoutout_user(user_to_shoutout, 60))
        # Update cooldown trackers
        last_shoutout_time = datetime.now()
        shoutout_tracker[user_id] = last_shoutout_time
        # Mark the task as done
        shoutout_queue.task_done()

# Function to trigger a Twitch shoutout via Twitch API
async def trigger_twitch_shoutout(user_to_shoutout, user_id):
    sqldb = await access_website_database()
    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
        bot_id = "971436498"
        await cursor.execute(f"SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = {bot_id} LIMIT 1")
        result = await cursor.fetchone()
        bot_auth = result.get('twitch_access_token')
        await sqldb.ensure_closed()
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
            async with aiohttp.ClientSession() as session:
                async with session.post(url, headers=headers, json=payload) as response:
                    if response.status in (200, 204):
                        twitch_logger.info(f"Shoutout triggered successfully for {user_to_shoutout}.")
                    else:
                        twitch_logger.error(f"Failed to trigger shoutout. Status: {response.status}. Message: {await response.text()}")
        except aiohttp.ClientError as e:
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
    async with aiohttp.ClientSession() as session:
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
        async with aiohttp.ClientSession() as session:
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
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
    event_logger.info(f"Fourthwall event received: {data}")
    # Check if 'data' is a string and needs to be parsed
    if isinstance(data.get('data'), str):
        try:
            # Parse the string to convert it to a dictionary
            data['data'] = ast.literal_eval(data['data'])
        except (ValueError, SyntaxError) as e:
            event_logger.error(f"Failed to parse data: {e}")
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
            event_logger.info(f"New Order: {purchaser_name} bought {item_quantity} x {item_name} for {total_price} {currency}")
            # Prepare the message to send
            message = f"🎉 {purchaser_name} just bought {item_quantity} x {item_name} for {total_price} {currency}!"
            await channel.send(message)
        elif event_type == 'DONATION':
            donor_username = event_data['username']
            donation_amount = event_data['amounts']['total']['value']
            currency = event_data['amounts']['total']['currency']
            message_from_supporter = event_data.get('message', '')
            # Log the donation details and prepare the message
            if message_from_supporter:
                event_logger.info(f"New Donation: {donor_username} donated {donation_amount} {currency} with message: {message_from_supporter}")
                message = f"💰 {donor_username} just donated {donation_amount} {currency}! Message: {message_from_supporter}"
            else:
                event_logger.info(f"New Donation: {donor_username} donated {donation_amount} {currency}")
                message = f"💰 {donor_username} just donated {donation_amount} {currency}! Thank you!"
            await channel.send(message)
        elif event_type == 'GIVEAWAY_PURCHASED':
            purchaser_username = event_data['username']
            item_name = event_data['offer']['name']
            total_price = event_data['amounts']['total']['value']
            currency = event_data['amounts']['total']['currency']
            # Log the giveaway purchase details
            event_logger.info(f"New Giveaway Purchase: {purchaser_username} purchased giveaway '{item_name}' for {total_price} {currency}")
            # Prepare and send the message
            message = f"🎁 {purchaser_username} just purchased a giveaway: {item_name} for {total_price} {currency}!"
            await channel.send(message)
            # Process each gift
            for idx, gift in enumerate(event_data.get('gifts', []), start=1):
                gift_status = gift['status']
                winner = gift.get('winner', {})
                winner_username = winner.get('username', "No winner yet")
                # Log each gift's status and winner details
                event_logger.info(f"Gift {idx} is {gift_status} with winner: {winner_username}")
                # Prepare and send the gift status message
                gift_message = f"🎁 Gift {idx}: Status - {gift_status}. Winner: {winner_username}."
                await channel.send(gift_message)
        elif event_type == 'SUBSCRIPTION_PURCHASED':
            subscriber_nickname = event_data['nickname']
            subscription_variant = event_data['subscription']['variant']
            interval = subscription_variant['interval']
            amount = subscription_variant['amount']['value']
            currency = subscription_variant['amount']['currency']
            # Log the subscription purchase details
            event_logger.info(f"New Subscription: {subscriber_nickname} subscribed {interval} for {amount} {currency}")
            # Prepare and send the message
            message = f"🎉 {subscriber_nickname} just subscribed for {interval}, paying {amount} {currency}!"
            await channel.send(message)
        else:
            event_logger.info(f"Unhandled Fourthwall event: {event_type}")
    except KeyError as e:
        event_logger.error(f"Error processing event '{event_type}': Missing key {e}")
    except Exception as e:
        event_logger.error(f"Unexpected error processing event '{event_type}': {e}")

# Function to process KOFI events
async def process_kofi_event(data):
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
    if isinstance(data.get('data'), str):
        try:
            data['data'] = ast.literal_eval(data['data'])
        except (ValueError, SyntaxError) as e:
            event_logger.error(f"Failed to parse data: {e}")
            return
    if not isinstance(data.get('data'), dict):
        event_logger.error(f"Unexpected data structure: {data}")
        return
    # Extract event type and data
    event_type = data.get('data', {}).get('type', None)
    event_data = data.get('data', {})
    message_to_send = None
    if event_type is None:
        event_logger.info(f"Unhandled KOFI event: {event_type}")
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
                event_logger.info(f"Donation: {donor_name} donated {amount} {currency} with message: {message}")
                message_to_send = f"💰 {donor_name} donated {amount} {currency}. Message: {message}"
            else:
                event_logger.info(f"Donation: {donor_name} donated {amount} {currency}")
                message_to_send = f"💰 {donor_name} donated {amount} {currency}. Thank you!"
        elif event_type == 'Subscription':
            subscriber_name = event_data.get('from_name', 'Unknown')
            amount = event_data.get('amount', 'Unknown')
            currency = event_data.get('currency', 'Unknown')
            is_first_payment = event_data.get('is_first_subscription_payment', False)
            tier_name = event_data.get('tier_name', 'None')
            # Log the subscription details and build the message to send to chat
            if is_first_payment:
                event_logger.info(f"Subscription: {subscriber_name} subscribed to {tier_name} for {amount} {currency} (First payment)")
                message_to_send = f"🎉 {subscriber_name} subscribed to {tier_name} for {amount} {currency} (First payment)!"
            else:
                event_logger.info(f"Subscription: {subscriber_name} renewed {tier_name} for {amount} {currency}")
                message_to_send = f"🎉 {subscriber_name} renewed {tier_name} for {amount} {currency}!"
        elif event_type == 'Shop Order':
            purchaser_name = event_data.get('from_name', 'Unknown')
            amount = event_data.get('amount', 'Unknown')
            currency = event_data.get('currency', 'Unknown')
            shop_items = event_data.get('shop_items', [])
            item_summary = ", ".join([f"{item['quantity']} x {item['variation_name']}" for item in shop_items])
            message_to_send = f"🛒 {purchaser_name} purchased items for {amount} {currency}. Items: {item_summary}"
            # Log the shop order details
            event_logger.info(f"Shop Order: {purchaser_name} ordered items for {amount} {currency}. Items: {item_summary}")
        else:
            event_logger.info(f"Unhandled KOFI event: {event_type}")
            return
        # Only send a message if it was successfully created
        if message_to_send:
            await channel.send(message_to_send)
    except KeyError as e:
        event_logger.error(f"Error processing event '{event_type}': Missing key {e}")
    except Exception as e:
        event_logger.error(f"Unexpected error processing event '{event_type}': {e}")

async def process_patreon_event(data):
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
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
    await channel.send(message)

async def process_weather_websocket(data):
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
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
    now = datetime.now(pytz.timezone("UTC"))
    minutes_ago = now.minute  # Get current minutes (0-59)
    # Format the message
    message = (f"The weather as of {minutes_ago} min ago in {location} is {status} with a temperature of "
               f"{temperature_c}°C ({temperature_f}°F). Wind is blowing from the {wind_direction} at "
               f"{wind_speed_kph} kph ({wind_speed_mph} mph) with {humidity}% humidity.")
    # Log and send message
    event_logger.info(f"Sending weather update: {message}")
    await channel.send(message)

# Function to process the stream being online
async def process_stream_online_websocket():
    global stream_online, current_game, CLIENT_ID, CHANNEL_AUTH, CHANNEL_NAME
    stream_online = True
    looped_tasks["timed_message"] = asyncio.get_event_loop().create_task(timed_message())
    looped_tasks["handle_upcoming_ads"] = asyncio.get_event_loop().create_task(handle_upcoming_ads())
    await generate_winning_lotto_numbers()
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
    # Reach out to the Twitch API to get stream data
    async with aiohttp.ClientSession() as session:
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
        current_game = data['data'][0].get('game_name', None)
        image_data = data['data'][0].get('thumbnail_url', None)
    else:
        current_game = None
        image_data = None
    if image_data:
        image = image_data.replace("{width}", "1280").replace("{height}", "720")
    else:
        image = ""
    # Send a message to the chat announcing the stream is online
    message = f"Stream is now online! Streaming {current_game}" if current_game else "Stream is now online!"
    await channel.send(message)
    await send_to_discord_stream_online(message, image)
    # Log the status to the file
    os.makedirs(f'/var/www/logs/online', exist_ok=True)
    with open(f'/var/www/logs/online/{CHANNEL_NAME}.txt', 'w') as file:
        file.write('True')

# Function to process the stream being offline
async def process_stream_offline_websocket():
    global stream_online, scheduled_clear_task
    stream_online = False  # Update the stream status
    # Cancel any previous scheduled task to avoid duplication
    if 'scheduled_clear_task' in globals() and scheduled_clear_task:
        scheduled_clear_task.cancel()
    # Schedule the clearing task with a 5-minute delay
    scheduled_clear_task = asyncio.create_task(delayed_clear_tables())
    bot_logger.info("Scheduled task to clear tables if stream remains offline for 5 minutes.")
    # Log the status to the file
    os.makedirs(f'/var/www/logs/online', exist_ok=True)
    with open(f'/var/www/logs/online/{CHANNEL_NAME}.txt', 'w') as file:
        file.write('False')

# Function to clear both tables if the stream remains offline after 5 minutes
async def delayed_clear_tables():
    global stream_online
    for _ in range(30):  # Check every 10 seconds for 5 minutes
        await asyncio.sleep(10)
        if stream_online:
            bot_logger.info("Stream is back online. Skipping table clear.")
            return
    # If the stream is still offline after 5 minutes, clear the tables
    await clear_seen_today()
    await clear_credits_data()
    await clear_per_stream_deaths()
    await clear_lotto_numbers()
    if "timed_message" in looped_tasks:
        looped_tasks["timed_message"].cancel()
    if "handle_upcoming_ads" in looped_tasks:
        looped_tasks["handle_upcoming_ads"].cancel()
    bot_logger.info("Tables and lotto entries cleared after stream remained offline.")

# Function to clear the seen users table at the end of stream
async def clear_seen_today():
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute('TRUNCATE TABLE seen_today')
            await sqldb.commit()
            bot_logger.info('Seen today table cleared successfully.')
    except aiomysql.Error as err:
        bot_logger.error(f'Failed to clear seen today table: {err}')
    finally:
        await sqldb.ensure_closed()

# Function to clear the ending credits table at the end of stream
async def clear_credits_data():
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute('TRUNCATE TABLE stream_credits')
            await sqldb.commit()
            bot_logger.info('Stream credits table cleared successfully.')
    except aiomysql.Error as err:
        bot_logger.error(f'Failed to clear stream credits table: {err}')
    finally:
        await sqldb.ensure_closed()

# Function to clear the death count per stream
async def clear_per_stream_deaths():
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute('TRUNCATE TABLE per_stream_deaths')
            await sqldb.commit()
            bot_logger.info('Per Stream Death Count cleared successfully.')
    except aiomysql.Error as err:
        bot_logger.error(f'Failed to clear Per Stream Death Count: {err}')
    finally:
        await sqldb.ensure_closed()

async def clear_lotto_numbers():
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute('TRUNCATE TABLE stream_lotto')
            await sqldb.commit()
            await cursor.execute('TRUNCATE TABLE stream_lotto_winning_numbers')
            await sqldb.commit()
            bot_logger.info('Lotto Numbers cleared successfully.')
    except aiomysql.Error as err:
        bot_logger.error(f'Failed to clear Lotto Numbers: {err}')
    finally:
        await sqldb.ensure_closed()

# Function for timed messages
async def timed_message():
    global scheduled_tasks, chat_trigger_tasks, stream_online, chat_line_count
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            if stream_online:
                # Fetch enabled messages with their interval, chat trigger, and ID
                await cursor.execute('SELECT id, interval_count, chat_line_trigger, message FROM timed_messages WHERE status = "true"')
                messages = await cursor.fetchall()
                chat_logger.info(f"Timed Messages: {messages}")
                # Cancel and clear any old tasks
                for task in scheduled_tasks:
                    task.cancel()
                scheduled_tasks.clear()
                chat_trigger_tasks.clear()
                # Schedule each message for chat triggers
                for row in messages:
                    message_id = row["id"]
                    interval = row["interval_count"]
                    chat_line_trigger = row["chat_line_trigger"]
                    message = row["message"]
                    # Handle chat line triggers
                    if chat_line_trigger and int(chat_line_trigger) > 0:
                        chat_logger.info(f"Tracking Message ID: {message_id} - '{message}' for chat line trigger: {chat_line_trigger}")
                        chat_trigger_tasks[message_id] = {
                            "chat_line_trigger": int(chat_line_trigger),
                            "message": message,
                            "last_trigger_count": chat_line_count,  # Start tracking from the current global counter
                            "interval": interval,  # Store the interval for later use
                        }
                    elif interval and int(interval) > 0: # handle interval based messages
                        wait_time = int(interval) * 60
                        task = asyncio.create_task(send_timed_message(message_id, message, wait_time))
                        scheduled_tasks.add(task) # Add task to the set
            else:
                # Cancel all scheduled tasks if the stream goes offline
                bot_logger.info("Stream is offline. Resetting counters and cancelling all timed messages.")
                chat_line_count = 0  # Reset global chat counter
                for task in scheduled_tasks:
                    task.cancel()
                scheduled_tasks.clear()
                chat_trigger_tasks.clear()
    except Exception as e:
        bot_logger.error(f"An error occurred in timed_message: {e}")
    finally:
        await sqldb.ensure_closed()

async def handle_chat_message(messageAuthor):
    global chat_trigger_tasks, chat_line_count, stream_online
    if not stream_online:
        return
    if messageAuthor.lower() == BOT_USERNAME.lower():
        return
    if BACKUP_SYSTEM and messageAuthor.lower() == CHANNEL_NAME.lower():
        return
    # Increment the global chat message counter
    chat_line_count += 1
    # Check each tracked message for trigger conditions
    for message_id, trigger_info in chat_trigger_tasks.items():
        chat_line_trigger = trigger_info["chat_line_trigger"]
        last_trigger_count = trigger_info["last_trigger_count"]
        message = trigger_info["message"]
        interval = trigger_info["interval"]
        # Check if enough new chat lines have occurred since the last trigger
        if chat_line_count - last_trigger_count >= chat_line_trigger:
            trigger_info["last_trigger_count"] = chat_line_count  # Update last trigger count
            # interval check.
            if interval and int(interval) > 0:
                wait_time = int(interval) * 60
                asyncio.create_task(send_timed_message(message_id, message, wait_time))
            else:
                asyncio.create_task(send_timed_message(message_id, message, 0)) #send immediately
            # Remove the task after it has been triggered
            del chat_trigger_tasks[message_id] # Remove item.

async def send_timed_message(message_id, message, delay):
    global stream_online, last_message_time
    if delay > 0:
        await asyncio.sleep(delay)
    if stream_online:
        # Ensure a delay between consecutive messages
        current_time = asyncio.get_event_loop().time()
        safe_gap = 60
        if last_message_time != 0: # Check if last_message_time has been initialized
            elapsed = current_time - last_message_time
            if elapsed < safe_gap:
                wait_time = safe_gap - elapsed
                chat_logger.info(f"Waiting {wait_time} more seconds before sending Timed Message ID: {message_id}")
                await asyncio.sleep(wait_time)
        chat_logger.info(f"Sending Timed Message ID: {message_id} - {message}")
        try:
            channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
            await channel.send(message)
            last_message_time = asyncio.get_event_loop().time()
        except Exception as e:
            bot_logger.error(f"Error sending message: {e}")
    else:
        chat_logger.info(f'Stream is offline. Message ID: {message_id} not sent.')

# Function to get the song via Spotify
async def get_spotify_current_song():
    global SPOTIFY_ACCESS_TOKEN, song_requests
    headers = { "Authorization": f"Bearer {SPOTIFY_ACCESS_TOKEN}" }
    async with aiohttp.ClientSession() as session:
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
                    return song_name, artist_name, song_id  # Return song name, artist name and song id as tuple
                else:
                    return None, None  # No song playing
            elif response.status == 204:
                # 204 No Content means no song is currently playing
                return None, None
            else:
                # Handle potential Spotify API errors
                api_logger.error(f"Spotify API error: {response.status}")
                return None, None

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
        working_dir = "/var/www/logs/songs"
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
        async with aiohttp.ClientSession() as session:
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
    sqldb = await access_website_database()
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
        async with aiohttp.ClientSession() as session:
            async with session.post(url, data=audio_bytes, headers=headers, params=querystring, timeout=15) as response:
                # Check requests remaining for the API
                if "x-ratelimit-requests-remaining" in response.headers:
                    requests_left = response.headers['x-ratelimit-requests-remaining']
                    file_path = "/var/www/api/shazam.txt"
                    with open(file_path, 'w') as file:
                        file.write(requests_left)
                    api_logger.info(f"There are {requests_left} requests lefts for the song command.")
                    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                        await cursor.execute("UPDATE api_counts SET count=%s WHERE type=%s", (requests_left, "shazam"))
                        await sqldb.commit()
                        sqldb.close()
                    if int(requests_left) == 0:
                        return {"error": "Sorry, no more requests for song info are available for the rest of the month. Requests reset each month on the 23rd."}
                return await response.json()
    except Exception as e:
        api_logger.error(f"An error occurred while detecting song: {e}")
        return {}

async def convert_to_raw_audio(in_file, out_file):
    try:
        ffmpeg_path = "/usr/bin/ffmpeg"
        proc = await asyncio.create_subprocess_exec(
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
        session = streamlink.Streamlink()
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

## Functions for the EventSub
# Function for AD BREAK
async def handle_ad_break_start(duration_seconds):
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute("SELECT * FROM ad_notice_settings WHERE id = %s", (1,))
            settings = await cursor.fetchone()
            if settings:
                ad_start_message = settings["ad_start_message"]
                ad_end_message = settings["ad_end_message"]
                enable_ad_notice = settings["enable_ad_notice"]
            else:
                ad_start_message = "Ads are running for (duration). We'll be right back after these ads."
                ad_end_message = "Thanks for sticking with us through the ads! Welcome back, everyone!"
                enable_ad_notice = True
    finally:
        await sqldb.ensure_closed()
    if enable_ad_notice:
        minutes = duration_seconds // 60
        seconds = duration_seconds % 60
        if minutes == 0:
            formatted_duration = f"{seconds} seconds"
        elif seconds == 0:
            formatted_duration = f"{minutes} minutes"
        else:
            formatted_duration = f"{minutes} minutes, {seconds} seconds"
        ad_start_message = ad_start_message.replace("(duration)", formatted_duration)
        await channel.send(ad_start_message)
        @routines.routine(seconds=duration_seconds, iterations=1, wait_first=True)
        async def handle_ad_break_end(channel):
            await channel.send(ad_end_message)
        handle_ad_break_end.start(channel)

# Fcuntion for POLLS
async def handel_twitch_poll(event=None, poll_title=None, half_time=None, message=None):
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
    if not channel:
        return
    if event == "poll.start":
        await channel.send(message)
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
        async def handel_twitch_poll_half_message(channel):
            await channel.send(half_way_message)
        handel_twitch_poll_half_message.start(channel)
    elif event == "poll.end":
        await channel.send(message)
        handel_twitch_poll_half_message.cancel()

# Function for RAIDS
async def process_raid_event(from_broadcaster_id, from_broadcaster_name, viewer_count):
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
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
            await sqldb.commit()
            # Send raid notification to Discord Logs, Twitch Chat, and Websocket
            discord_message = f"{from_broadcaster_name} has raided with {viewer_count} viewers!"
            await send_to_discord(discord_message, "New Raid!", "raid.png")
            asyncio.create_task(websocket_notice("TWITCH_RAID", user=from_broadcaster_name, raid_viewers=viewer_count))
            # Send a message to the Twitch channel
            await cursor.execute("SELECT alert_message FROM twitch_chat_alerts WHERE alert_type = %s", ("raid_alert",))
            result = await cursor.fetchone()
            if result and result.get("alert_message"):
                alert_message = result.get("alert_message")
            else:
                alert_message = "Incredible! (user) and (viewers) viewers have joined the party! Let's give them a warm welcome!"
            alert_message = alert_message.replace("(user)", from_broadcaster_name).replace("(viewers)", str(viewer_count))
            await channel.send(alert_message)
            marker_description = f"New Raid from {from_broadcaster_name}"
            if await make_stream_marker(marker_description):
                twitch_logger.info(f"A stream marker was created: {marker_description}.")
            else:
                twitch_logger.info("Failed to create a stream marker.")
            await cursor.execute("SELECT * FROM twitch_sound_alerts WHERE twitch_alert_id = %s", ("Raid",))
            result = await cursor.fetchone()
            if result and result.get("sound_mapping"):
                sound_file = "twitch/" . result.get("sound_mapping")
                asyncio.create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
    finally:
        await sqldb.ensure_closed()

# Function for BITS
async def process_cheer_event(user_id, user_name, bits):
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
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
                discord_message = f"{user_name} just cheered {bits} bits!"
                if bits < 100:
                    image = "cheer.png"
                elif 100 <= bits < 1000:
                    image = "cheer100.png"
                else:
                    image = "cheer1000.png"
            alert_message = alert_message.replace("(user)", user_name).replace("(bits)", str(bits)).replace("(total-bits)", str(total_bits))
            await channel.send(alert_message)
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
            await sqldb.commit()
            # Add time to subathon if it's running
            subathon_state = await get_subathon_state()
            if subathon_state and not subathon_state[4]:  # If subathon is running
                cheer_add_time = int(settings['cheer_add'])  # Retrieve the time to add for cheers
                await addtime_subathon(channel, cheer_add_time)  # Call to add time based on cheers
            # Send cheer notification to Discord Logs, Twitch Chat, and Websocket
            await send_to_discord(discord_message, "New Cheer!", image)
            asyncio.create_task(websocket_notice("TWITCH_CHEER", user=user_name, cheer_amount=bits))
            marker_description = f"New Cheer from {user_name}"
            if await make_stream_marker(marker_description):
                twitch_logger.info(f"A stream marker was created: {marker_description}.")
            else:
                twitch_logger.info("Failed to create a stream marker.")
            await cursor.execute("SELECT * FROM twitch_sound_alerts WHERE twitch_alert_id = %s", ("Cheer",))
            result = await cursor.fetchone()
            if result and result.get("sound_mapping"):
                sound_file = "twitch/" . result.get("sound_mapping")
                asyncio.create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
    finally:
        await sqldb.ensure_closed()

# Function for Subscriptions
async def process_subscription_event(user_id, user_name, sub_plan, event_months):
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
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
            await sqldb.commit()
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
                await addtime_subathon(channel, sub_add_time)  # Call to add time based on subscriptions
            # Send notification messages
            await cursor.execute("SELECT alert_message FROM twitch_chat_alerts WHERE alert_type = %s", ("subscription_alert",))
            result = await cursor.fetchone()
            if result and result.get("alert_message"):
                alert_message = result.get("alert_message")
            else:
                alert_message = "Thank you (user) for subscribing! You are now a (tier) subscriber for (months) months!"
            alert_message = alert_message.replace("(user)", user_name).replace("(tier)", sub_plan).replace("(months)", str(event_months))
            discord_message = f"{user_name} just subscribed at {sub_plan}!"
            try:
                await send_to_discord(discord_message, "New Subscriber!", "sub.png")
                event_logger.info("Sent message to Discord")
            except Exception as e:
                event_logger.error(f"Failed to send message to Discord: {e}")
            try:
                asyncio.create_task(websocket_notice("TWITCH_SUB", user=user_name, sub_tier=sub_plan, sub_months=event_months))
                event_logger.info("Sent WebSocket notice")
            except Exception as e:
                event_logger.error(f"Failed to send WebSocket notice: {e}")
            # Retrieve the channel object
            try:
                await channel.send(alert_message)
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
                sound_file = "twitch/" . result.get("sound_mapping")
                asyncio.create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
    except Exception as e:
        event_logger.error(f"Error processing subscription event for user {user_name} ({user_id}): {e}")
    finally:
        await sqldb.ensure_closed()

# Function for Resubscriptions with Messages
async def process_subscription_message_event(user_id, user_name, sub_plan, event_months):
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
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
            await sqldb.commit()
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
                await addtime_subathon(channel, sub_add_time)  # Call to add time based on subscriptions
            # Send notification messages
            await cursor.execute("SELECT alert_message FROM twitch_chat_alerts WHERE alert_type = %s", ("subscription_alert",))
            result = await cursor.fetchone()
            if result and result.get("alert_message"):
                alert_message = result.get("alert_message")
            else:
                alert_message = "Thank you (user) for subscribing! You are now a (tier) subscriber for (months) months!"
            alert_message = alert_message.replace("(user)", user_name).replace("(tier)", sub_plan).replace("(months)", str(event_months))
            discord_message = f"{user_name} just resubscribed at {sub_plan}!"
            try:
                await send_to_discord(discord_message, "New Resubscription!", "sub.png")
                event_logger.info("Sent message to Discord")
            except Exception as e:
                event_logger.error(f"Failed to send message to Discord: {e}")
            try:
                asyncio.create_task(websocket_notice("TWITCH_SUB", user=user_name, sub_tier=sub_plan, sub_months=event_months))
                event_logger.info("Sent WebSocket notice")
            except Exception as e:
                event_logger.error(f"Failed to send WebSocket notice: {e}")
            # Retrieve the channel object
            try:
                await channel.send(alert_message)
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
                sound_file = "twitch/" . result.get("sound_mapping")
                asyncio.create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
    except Exception as e:
        event_logger.error(f"Error processing subscription message event for user {user_name} ({user_id}): {e}")
    finally:
        await sqldb.ensure_closed()

# Function for Gift Subscriptions
async def process_giftsub_event(gifter_user_name, givent_sub_plan, number_gifts, anonymous, total_gifted):
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute('INSERT INTO stream_credits (username, event, data) VALUES (%s, %s, %s)', (gifter_user_name, "Gift Subscriptions", f"{number_gifts} - GIFT SUBSCRIPTIONS"))
            await sqldb.commit()
            await cursor.execute("SELECT alert_message FROM twitch_chat_alerts WHERE alert_type = %s", ("gift_subscription_alert",))
            result = await cursor.fetchone()
            if result and result.get("alert_message"):
                alert_message = result.get("alert_message")
            else:
                alert_message = "Thank you (user) for gifting a (tier) subscription to (count) members! You have gifted a total of (total-gifted) to the community!"
            if anonymous:
                discord_message = f"An Anonymous Gifter just gifted {number_gifts} of gift subscriptions!"
                await send_to_discord(discord_message, "New Gifted Subscription!", "sub.png")
                giftsubfrom = "Anonymous"
            else:
                giftsubfrom = gifter_user_name
                discord_message = f"{giftsubfrom} just gifted {number_gifts} of gift subscriptions!"
                await send_to_discord(discord_message, "New Gifted Subscription!", "sub.png")
            alert_message = alert_message.replace("(user)", giftsubfrom).replace("(count)", str(number_gifts)).replace("(tier)", givent_sub_plan).replace("(total-gifted)", str(total_gifted))
            await channel.send(alert_message)
            marker_description = f"New Gift Subs from {giftsubfrom}"
            if await make_stream_marker(marker_description):
                twitch_logger.info(f"A stream marker was created: {marker_description}.")
            else:
                twitch_logger.info("Failed to create a stream marker.")
            await cursor.execute("SELECT * FROM twitch_sound_alerts WHERE twitch_alert_id = %s", ("Gift Subscription",))
            result = await cursor.fetchone()
            if result and result.get("sound_mapping"):
                sound_file = "twitch/" . result.get("sound_mapping")
                asyncio.create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
    finally:
        await sqldb.ensure_closed()

# Function for FOLLOWERS
async def process_followers_event(user_id, user_name):
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
    sqldb = await get_mysql_connection()
    try:
        time_now = datetime.now()
        followed_at = time_now.strftime("%Y-%m-%d %H:%M:%S")
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
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
            await sqldb.commit()
        # Send follow notification to Discord Logs, Twitch Chat and Websocket
        await cursor.execute("SELECT alert_message FROM twitch_chat_alerts WHERE alert_type = %s", ("follower_alert",))
        result = await cursor.fetchone()
        if result and result.get("alert_message"):
            alert_message = result.get("alert_message")
        else:
            alert_message = "Thank you (user) for following! Welcome to the channel!"
        alert_message = alert_message.replace("(user)", user_name)
        await channel.send(alert_message)
        discord_message = f"{user_name} just followed!"
        await send_to_discord(discord_message, "New Follower!", "follow.png")
        asyncio.create_task(websocket_notice("TWITCH_FOLLOW", user=user_name))
        marker_description = f"New Twitch Follower: {user_name}"
        if await make_stream_marker(marker_description):
            twitch_logger.info(f"A stream marker was created: {marker_description}.")
        else:
            twitch_logger.info("Failed to create a stream marker.")
        await cursor.execute("SELECT * FROM twitch_sound_alerts WHERE twitch_alert_id = %s", ("Follow",))
        result = await cursor.fetchone()
        if result and result.get("sound_mapping"):
            sound_file = "twitch/" . result.get("sound_mapping")
            asyncio.create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
    finally:
        await sqldb.ensure_closed()

# Function to ban a user
async def ban_user(username, user_id, use_streamer=False):
    # Connect to the database
    sqldb = await access_website_database()
    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
        # Determine which user ID to use for the API request
        api_user_id = CHANNEL_ID if use_streamer else "971436498" if not BACKUP_SYSTEM else CHANNEL_ID
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
    async with aiohttp.ClientSession() as session:
        async with session.post(ban_url, headers=headers, json=data) as response:
            if response.status == 200:
                twitch_logger.info(f"{username} has been banned for sending a spam message in chat.")
            else:
                error_text = await response.text()
                twitch_logger.error(f"Failed to ban user: {username}. Status Code: {response.status}, Response: {error_text}")

# Function to build the Discord Notice
async def send_to_discord(message, title, image):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute("SELECT discord_alert, timezone FROM profile")
            result = await cursor.fetchone()
            if not result or not result.get("discord_alert"):
                bot_logger.error("Discord URL not found or is None.")
                return
            discord_url = result.get("discord_alert")
            timezone = result.get("timezone") if result.get("timezone") else 'UTC'
            tz = pytz.timezone(timezone)
            current_time = datetime.now(tz)
            time_format_date = current_time.strftime("%B %d, %Y")
            time_format_time = current_time.strftime("%I:%M %p")
            time_format = f"{time_format_date} at {time_format_time}"
            payload = {
                "username": "BotOfTheSpecter",
                "avatar_url": "https://cdn.botofthespecter.com/logo.png",
                "embeds": [{
                    "description": message,
                    "title": title,
                    "thumbnail": {"url": f"https://cdn.botofthespecter.com/webhook/{image}"},
                    "footer": {"text": f"Autoposted by BotOfTheSpecter - {time_format}"}
                }]
            }
            async with aiohttp.ClientSession() as session:
                async with session.post(discord_url, json=payload) as response:
                    if response.status not in [200, 204]:
                        bot_logger.error(f"Failed to send to Discord - Error: {response.status}")
    except Exception as e:
        bot_logger.error(f"Request to Discord failed: {e}")
    finally:
        await sqldb.ensure_closed()

# Function to build the Discord Mod Notice 
async def send_to_discord_mod(message, title, image):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute("SELECT discord_mod FROM profile")
            result = await cursor.fetchone()
            if not result or not result.get("discord_mod"):
                bot_logger.error("Discord URL for mod notifications not found or is None.")
                return
            discord_url = result.get("discord_mod")
            await cursor.execute("SELECT timezone FROM profile")
            timezone_result = await cursor.fetchone()
            timezone = timezone_result.get("timezone", 'UTC')
            tz = pytz.timezone(timezone)
            current_time = datetime.now(tz)
            time_format_date = current_time.strftime("%B %d, %Y")
            time_format_time = current_time.strftime("%I:%M %p")
            time_format = f"{time_format_date} at {time_format_time}"
            payload = {
                "username": "BotOfTheSpecter",
                "avatar_url": "https://cdn.botofthespecter.com/logo.png",
                "embeds": [{
                    "description": message,
                    "title": title,
                    "thumbnail": {"url": f"https://cdn.botofthespecter.com/webhook/{image}"},
                    "footer": {"text": f"Autoposted by BotOfTheSpecter - {time_format}"}
                }]
            }
            async with aiohttp.ClientSession() as session:
                async with session.post(discord_url, json=payload) as response:
                    if response.status not in [200, 204]:
                        bot_logger.error(f"Failed to send to Discord - Error: {response.status}")
    except Exception as e:
        bot_logger.error(f"Request to Discord failed: {e}")
    finally:
        await sqldb.ensure_closed()

# Function to send a message to Discord when the stream is online
async def send_to_discord_stream_online(message, image):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute("SELECT timezone, discord_alert_online FROM profile")
            result = await cursor.fetchone()
            if not result:
                bot_logger.error("Required profile information not found.")
                return
            timezone = result.get("timezone") if result.get("timezone") else 'UTC'
            discord_url = result.get("discord_alert_online")
            if not discord_url:
                return
            # Generate the current time based on the fetched timezone
            tz = pytz.timezone(timezone)
            current_time = datetime.now(tz)
            time_format_date = current_time.strftime("%B %d, %Y")
            time_format_time = current_time.strftime("%I:%M %p")
            time_format = f"{time_format_date} at {time_format_time}"
            title = f"{CHANNEL_NAME} is now live on Twitch!"
            payload = {
                "username": "BotOfTheSpecter",
                "avatar_url": "https://cdn.botofthespecter.com/logo.png",
                "content": "@everyone",
                "embeds": [{
                    "description": message,
                    "title": title,
                    "url": f"https://twitch.tv/{CHANNEL_NAME}",
                    "footer": {"text": f"Autoposted by BotOfTheSpecter - {time_format}"}
                }]
            }
            if image:
                payload["embeds"][0]["image"] = {
                    "url": image,
                    "height": 720,
                    "width": 1280
                }
            else:
                bot_logger.warning("No image URL provided; sending message without image.")
            async with aiohttp.ClientSession() as session:
                async with session.post(discord_url, json=payload) as response:
                    if response.status in (200, 204):
                        bot_logger.info(f"Message sent to Discord successfully - Status Code: {response.status}")
                    else:
                        bot_logger.error(f"Failed to send to Discord - Status Code: {response.status}, Response: {await response.text()}")
    except Exception as e:
        bot_logger.error(f"An error occurred while sending a message to Discord: {e}")
    finally:
        await sqldb.ensure_closed()

# Unified function to connect to the websocket server and push notices
async def websocket_notice(
    event, user=None, death=None, game=None, weather=None, cheer_amount=None,
    sub_tier=None, sub_months=None, raid_viewers=None, text=None, sound=None,
    video=None, additional_data=None, rewards_data=None
):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            async with ClientSession() as session:
                params = {
                    'code': API_TOKEN,
                    'event': event
                }
                # Event-specific parameter handling
                if event == "WALKON" and user:
                    for ext in ['.mp3', '.mp4']:
                        walkon_file_path = f"/var/www/walkons/{CHANNEL_NAME}/{user}{ext}"
                        if os.path.exists(walkon_file_path):
                            params['channel'] = CHANNEL_NAME
                            params['user'] = user
                            params['ext'] = ext
                            break
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
                        query = "SELECT voice, language FROM tts_settings WHERE user = %s"
                        await cursor.execute(query, (user,))
                        result = await cursor.fetchone()
                        if result:
                            params['voice'] = result.get('voice', 'default')
                            params['language'] = result.get('language', 'en')
                        else:
                            params['voice'] = 'default'
                            params['language'] = 'en'
                    except aiomysql.Error as e:
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
                elif event == "SOUND_ALERT" and sound:
                    params['sound'] = f"https://soundalerts.botofthespecter.com/{CHANNEL_NAME}/{sound}"
                elif event == "VIDEO_ALERT" and video:
                    params['video'] = f"https://videoalerts.botofthespecter.com/{CHANNEL_NAME}/{video}"
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
    finally:
        await sqldb.ensure_closed()

# Function to create the command in the database if it doesn't exist
async def builtin_commands_creation():
    sqldb = await get_mysql_connection()
    try:
        all_commands = list(mod_commands) + list(builtin_commands)
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
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
                await sqldb.commit()
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
                await sqldb.commit()
                for command in new_commands:
                    bot_logger.info(f"Command '{command}' added to database successfully.")
    except aiomysql.Error as e:
        bot_logger.error(f"builtin_commands_creation function error: {e}")
    finally:
        await sqldb.ensure_closed()

# Function to tell the website what version of the bot is currently running
async def update_version_control():
    try:
        # Define the directory path
        directory = "/var/www/logs/version/"
        # Ensure the directory exists, create it if it doesn't
        if not os.path.exists(directory):
            os.makedirs(directory)
        # Determine file name based on SYSTEM value
        if SYSTEM == "STABLE":
            file_name = f"{CHANNEL_NAME}_version_control.txt"
        elif SYSTEM == "BETA":
            file_name = f"{CHANNEL_NAME}_beta_version_control.txt"
        elif SYSTEM == "ALPHA":
            file_name = f"{CHANNEL_NAME}_alpha_version_control.txt"
        else:
            raise ValueError("Invalid SYSTEM value. Expected STABLE, BETA, or ALPHA.")
        # Define the full file path
        file_path = os.path.join(directory, file_name)
        # Delete the file if it exists
        if os.path.exists(file_path):
            os.remove(file_path)
        # Write the new version to the file
        with open(file_path, "w") as file:
            file.write(VERSION)
        bot_logger.info(f"Version control file updated: {file_path}")
    except Exception as e:
        bot_logger.error(f"An error occurred in update_version_control: {e}")

async def check_stream_online():
    global stream_online, current_game, CLIENT_ID, CHANNEL_AUTH, CHANNEL_NAME
    async with aiohttp.ClientSession() as session:
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
            # Check if the stream is offline
            if not data.get('data'):
                stream_online = False
                current_game = None
                bot_logger.info(f"Bot Starting, Stream is offline.")
                # Log the status to the file
                os.makedirs(f'/var/www/logs/online', exist_ok=True)
                with open(f'/var/www/logs/online/{CHANNEL_NAME}.txt', 'w') as file:
                    file.write('False')
            else:
                looped_tasks["timed_message"] = asyncio.get_event_loop().create_task(timed_message())
                looped_tasks["handle_upcoming_ads"] = asyncio.get_event_loop().create_task(handle_upcoming_ads())
                # Stream is online, extract the game name
                stream_online = True
                game = data['data'][0].get('game_name', None)
                current_game = game
                bot_logger.info(f"Bot Starting, Stream is online. Game: {current_game}")
                # Log the status to the file
                os.makedirs(f'/var/www/logs/online', exist_ok=True)
                with open(f'/var/www/logs/online/{CHANNEL_NAME}.txt', 'w') as file:
                    file.write('True')
    return

async def convert_currency(amount, from_currency, to_currency):
    global EXCHANGE_RATE_API_KEY
    url = f"https://v6.exchangerate-api.com/v6/{EXCHANGE_RATE_API_KEY}/pair/{from_currency}/{to_currency}/{amount}"
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(url) as response:
                response.raise_for_status()
                data = await response.json()
                if data['result'] == "success":
                    converted_amount = data['conversion_result']
                    api_logger.info(f"Converted {amount} {from_currency} to {converted_amount:.2f} {to_currency}")
                    # Read the remaining requests from the file, subtract 1, and write it back
                    file_path = "/var/www/api/exchangerate.txt"
                    try:
                        with open(file_path, 'r') as file:
                            remaining_requests = int(file.read())
                    except (FileNotFoundError, ValueError):
                        # If the file doesn't exist or contains invalid data, initialize with a default value
                        remaining_requests = 1500
                    remaining_requests -= 1
                    with open(file_path, 'w') as file:
                        file.write(str(remaining_requests))
                    api_logger.info(f"Exchangerate API Requests Remaining: {remaining_requests}")
                    return converted_amount
                else:
                    error_message = data.get('error-type', 'Unknown error')
                    api_logger.error(f"convert_currency Error: {error_message}")
                    sanitized_error = str(error_message).replace(EXCHANGE_RATE_API_KEY, '[EXCHANGE_RATE_API_KEY]')
                    return f"Sorry, I got an error: {sanitized_error}"
    except aiohttp.ClientError as e:
        sanitized_error = str(e).replace(EXCHANGE_RATE_API_KEY, '[EXCHANGE_RATE_API_KEY]')
        api_logger.error(f"Failed to convert {amount} {from_currency} to {to_currency}. Error: {sanitized_error}")
        raise

# Channel Point Rewards Proccessing
async def process_channel_point_rewards(event_data, event_type):
    sqldb = await get_mysql_connection()
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
        try:
            user_name = event_data["user_name"]
            user_id = event_data["user_id"]
            reward_data = event_data.get("reward", {})
            reward_id = reward_data.get("id")
            reward_title = reward_data.get("title" if event_type.endswith(".add") else "type")
            asyncio.create_task(websocket_notice(event="TWITCH_CHANNELPOINTS", rewards_data=event_data))
            # Check for TTS reward
            if "tts" in reward_title.lower():
                tts_message = event_data["user_input"]
                asyncio.create_task(websocket_notice(event="TTS", text=tts_message))
                return
            # Check for Lotto Numbers reward
            elif "lotto" in reward_title.lower():
                winning_numbers_str = await generate_user_lotto_numbers(user_name)
                # Handling errors (check if the result is an error message)
                if isinstance(winning_numbers_str, dict) and 'error' in winning_numbers_str:
                    await channel.send(f"Error: {winning_numbers_str['error']}")
                    return
                # Send the combined numbers (winning and supplementary) as one message
                await channel.send(f"{user_name} here are your Lotto numbers! {winning_numbers_str}")
                # Log the generated numbers for debugging and records
                chat_logger.info(f"Lotto numbers generated: {user_name} - {winning_numbers_str}")
                return
            # Check for Fortune reward
            elif "fortune" in reward_title.lower():
                fortune_message = await tell_fortune()
                fortune_message = fortune_message[0].lower() + fortune_message[1:]
                await channel.send(f"{user_name}, {fortune_message}")
                chat_logger.info(f'Fortune told "{fortune_message}" for {user_name}')
                return
            # Sound alert logic
            await cursor.execute("SELECT sound_mapping FROM sound_alerts WHERE reward_id = %s", (reward_id,))
            sound_result = await cursor.fetchone()
            if (sound_result and sound_result["sound_mapping"]):
                sound_file = sound_result.get("sound_mapping")
                event_logger.info(f"Got {event_type} - Found Sound Mapping - {reward_id} - {sound_file}")
                asyncio.create_task(websocket_notice(event="SOUND_ALERT", sound=sound_file))
            # Video alert logic
            await cursor.execute("SELECT video_mapping FROM video_alerts WHERE reward_id = %s", (reward_id,))
            video_result = await cursor.fetchone()
            if (video_result and video_result["video_mapping"]):
                video_file = video_result.get("video_mapping")
                event_logger.info(f"Got {event_type} - Found Video Mapping - {reward_id} - {video_file}")
                asyncio.create_task(websocket_notice(event="VIDEO_ALERT", video=video_file))
            # Custom message handling
            await cursor.execute("SELECT custom_message FROM channel_point_rewards WHERE reward_id = %s", (reward_id,))
            custom_message_result = await cursor.fetchone()
            if (custom_message_result and custom_message_result["custom_message"]):
                custom_message = custom_message_result.get("custom_message")
                if custom_message:
                    if '(user)' in custom_message:
                        custom_message = custom_message.replace('(user)', user_name)
                    # Handle (usercount)
                    if '(usercount)' in custom_message:
                        try:
                            # Get the user count for the specific reward
                            await cursor.execute('SELECT count FROM reward_counts WHERE reward_id = %s AND user = %s', (reward_id, user_name))
                            result = await cursor.fetchone()
                            if result:
                                user_count = result.get("count")
                            else:
                                # If no entry found, initialize it to 0
                                user_count = 0
                                await cursor.execute('INSERT INTO reward_counts (reward_id, user, count) VALUES (%s, %s, %s)', (reward_id, user_name, user_count))
                                await sqldb.commit()
                            # Increment the count
                            user_count += 1
                            await cursor.execute('UPDATE reward_counts SET count = %s WHERE reward_id = %s AND user = %s', (user_count, reward_id, user_name))
                            await sqldb.commit()
                            # Fetch the updated count
                            await cursor.execute('SELECT count FROM reward_counts WHERE reward_id = %s AND user = %s', (reward_id, user_name))
                            updated_result = await cursor.fetchone()
                            if updated_result:
                                updated_user_count = updated_result.get("count")
                            else:
                                updated_user_count = 0
                            # Replace the (usercount) placeholder with the updated user count
                            custom_message = custom_message.replace('(usercount)', str(updated_user_count))
                        except Exception as e:
                            chat_logger.error(f"Error while handling (usercount) in channel points: {e}")
                            custom_message = custom_message.replace('(usercount)', "Error")
                await channel.send(custom_message)
        except Exception as e:
            event_logger.error(f"An error occurred while processing the reward: {str(e)}")
        finally:
            await sqldb.ensure_closed()

async def channel_point_rewards():
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
    # Check the broadcaster's type
    user_api_url = f"https://api.twitch.tv/helix/users?id={CHANNEL_ID}"
    headers = {
        "Client-Id": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}"
    }
    try:
        # Get MySQL connection
        sqldb = await get_mysql_connection()
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            async with aiohttp.ClientSession() as session:
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
                            reward_id = reward.get("id")
                            reward_title = reward.get("title")
                            reward_cost = reward.get("cost")
                            # Check if the reward already exists in the database
                            await cursor.execute("SELECT COUNT(*) FROM channel_point_rewards WHERE reward_id = %s", (reward_id,))
                            count_result = await cursor.fetchone()
                            if count_result["COUNT(*)"] == 0:
                                # Insert new reward
                                api_logger.info(f"Inserting new reward: {reward_id}, {reward_title}, {reward_cost}")
                                await cursor.execute(
                                    "INSERT INTO channel_point_rewards (reward_id, reward_title, reward_cost) "
                                    "VALUES (%s, %s, %s)", (reward_id, reward_title, reward_cost)
                                )
                            else:
                                # Update existing reward
                                await cursor.execute(
                                    "UPDATE channel_point_rewards SET reward_title = %s, reward_cost = %s "
                                    "WHERE reward_id = %s", (reward_title, reward_cost, reward_id)
                                )
                        api_logger.info("Rewards processed successfully.")
                    else:
                        api_logger.error(f"Failed to fetch rewards: {response.status} {response.reason}")
                        
        await sqldb.commit()
    except Exception as e:
        api_logger.error(f"An error occurred in channel_point_rewards: {str(e)}")
    finally:
        if sqldb:
            sqldb.close()
            await sqldb.ensure_closed()

async def generate_winning_lotto_numbers():
    sqldb = await get_mysql_connection()
    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
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
        await sqldb.commit()
        await sqldb.ensure_closed()
    return True

# Function to generate random Lotto numbers
async def generate_user_lotto_numbers(user_name):
    user_name = user_name.lower()
    sqldb = await get_mysql_connection()
    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
        # Check if there are winning numbers in the database
        await cursor.execute("SELECT winning_numbers, supplementary_numbers FROM stream_lotto_winning_numbers")
        game_running = await cursor.fetchone()
        # Check if the user has already played
        await cursor.execute("SELECT username FROM stream_lotto WHERE username = %s", (user_name,))
        user_exists = await cursor.fetchone()
        if user_exists:
            return {"error": "you've already played the lotto, please wait until the next round."}
        # If there are no winning numbers, return error
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
        await sqldb.commit()
        return all_numbers_str

# Function to fetch a random fortune
async def tell_fortune():
    url = f"https://api.botofthespecter.com/fortune?api_key={API_TOKEN}"
    async with aiohttp.ClientSession() as session:
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
async def add_task(ctx, params, user_id, sqldb):
    user = ctx.author
    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
        if params:
            try:
                task_and_category = params[0].strip().split('"')
                task_description = task_and_category[1].strip()
                category_id = int(task_and_category[2].strip()) if len(task_and_category) > 2 and task_and_category[2].strip() else 1
                await cursor.execute("INSERT INTO todos (objective, category) VALUES (%s, %s)", (task_description, category_id))
                task_id = cursor.lastrowid
                await sqldb.commit()
                category_name = await fetch_category_name(cursor, category_id)
                await ctx.send(f'{user.name}, your task "{task_description}" ID {task_id} has been added to category "{category_name or ("Unknown" if category_name is None else category_name)}".')
                chat_logger.info(f"{user.name} added a task: '{task_description}' in category: '{category_name or 'Unknown'}' with ID {task_id}.")
            except (ValueError, IndexError):
                await ctx.send(f"{user.name}, please provide a valid task description and optional category ID.")
                chat_logger.warning(f"{user.name} provided invalid task description or category ID for adding a task.")
        else:
            await ctx.send(f"{user.name}, please provide a task to add.")
            chat_logger.warning(f"{user.name} did not provide any task to add.")

# ToDo List Function - Edit Task
async def edit_task(ctx, params, user_id, sqldb):
    user = ctx.author
    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
        if params:
            try:
                todo_id_str, new_task = params[0].split(',', 1)
                todo_id = int(todo_id_str.strip())
                new_task = new_task.strip()
                await cursor.execute("UPDATE todos SET objective = %s WHERE id = %s", (new_task, todo_id))
                if cursor.rowcount == 0:
                    await ctx.send(f"{user.name}, task ID {todo_id} does not exist.")
                    chat_logger.warning(f"{user.name} tried to edit non-existing task ID {todo_id}.")
                else:
                    await sqldb.commit()
                    await ctx.send(f"{user.name}, task {todo_id} has been updated to \"{new_task}\".")
                    chat_logger.info(f"{user.name} edited task ID {todo_id} to new task: '{new_task}'.")
            except ValueError:
                await ctx.send(f"{user.name}, please provide the task ID and new description separated by a comma.")
                chat_logger.warning(f"{user.name} provided invalid format for editing a task.")
        else:
            await ctx.send(f"{user.name}, please provide the task ID and new description.")
            chat_logger.warning(f"{user.name} did not provide task ID and new description for editing.")

# ToDo List Function - Remove Task
async def remove_task(ctx, params, user_id, sqldb):
    user = ctx.author
    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
        if params:
            try:
                todo_id = int(params[0].strip())
                await cursor.execute("SELECT id FROM todos WHERE id = %s", (todo_id,))
                if await cursor.fetchone():
                    pending_removals[user_id] = todo_id
                    await ctx.send(f"{user.name}, please use `!todo confirm` to remove task ID {todo_id}.")
                    chat_logger.info(f"{user.name} initiated removal of task ID {todo_id}.")
                else:
                    await ctx.send(f"{user.name}, task ID {todo_id} does not exist.")
                    chat_logger.warning(f"{user.name} tried to remove non-existing task ID {todo_id}.")
            except ValueError:
                await ctx.send(f"{user.name}, please provide a valid task ID to remove.")
                chat_logger.warning(f"{user.name} provided invalid task ID for removal.")
        else:
            await ctx.send(f"{user.name}, please provide the task ID to remove.")
            chat_logger.warning(f"{user.name} did not provide task ID for removal.")

# ToDo List Function - Complete Task
async def complete_task(ctx, params, user_id, sqldb):
    user = ctx.author
    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
        if params:
            try:
                todo_id = int(params[0].strip())
                await cursor.execute("UPDATE todos SET completed = 'Yes' WHERE id = %s", (todo_id,))
                if cursor.rowcount == 0:
                    await ctx.send(f"{user.name}, task ID {todo_id} does not exist.")
                    chat_logger.warning(f"{user.name} tried to complete non-existing task ID {todo_id}.")
                else:
                    await sqldb.commit()
                    await ctx.send(f"{user.name}, task {todo_id} has been marked as complete.")
                    chat_logger.info(f"{user.name} marked task ID {todo_id} as complete.")
            except ValueError:
                await ctx.send(f"{user.name}, please provide a valid task ID to mark as complete.")
                chat_logger.warning(f"{user.name} provided invalid task ID for completion.")
        else:
            await ctx.send(f"{user.name}, please provide the task ID to mark as complete.")
            chat_logger.warning(f"{user.name} did not provide task ID for completion.")

# ToDo List Function - Confirm Removal
async def confirm_removal(ctx, params, user_id, sqldb):
    user = ctx.author
    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
        if user_id in pending_removals:
            todo_id = pending_removals.pop(user_id)
            await cursor.execute("DELETE FROM todos WHERE id = %s", (todo_id,))
            await sqldb.commit()
            await ctx.send(f"{user.name}, task ID {todo_id} has been removed.")
            chat_logger.info(f"{user.name} confirmed and removed task ID {todo_id}.")
        else:
            await ctx.send(f"{user.name}, you have no pending task removal to confirm.")
            chat_logger.warning(f"{user.name} tried to confirm removal without pending task.")

# ToDo List Function - View Task
async def view_task(ctx, params, user_id, sqldb):
    user = ctx.author
    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
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
                    await ctx.send(f"Task ID {todo_id}: Description: {objective} Category: {category_name or 'Unknown'} Completed: {completed}")
                    chat_logger.info(f"{user.name} viewed task ID {todo_id}.")
                else:
                    await ctx.send(f"{user.name}, task ID {todo_id} does not exist.")
                    chat_logger.warning(f"{user.name} tried to view non-existing task ID {todo_id}.")
            except ValueError:
                await ctx.send(f"{user.name}, please provide a valid task ID to view.")
                chat_logger.warning(f"{user.name} provided invalid task ID for viewing.")
        else:
            await ctx.send(f"{user.name}, please provide the task ID to view.")
            chat_logger.warning(f"{user.name} did not provide task ID for viewing.")

# Function to get Category Names for the ToDo List
async def fetch_category_name(cursor, category_id):
    await cursor.execute("SELECT category FROM categories WHERE id = %s", (category_id,))
    result = await cursor.fetchone()
    return result.get("category") if result else None

# Function to start subathon timer
async def start_subathon(ctx):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            subathon_state = await get_subathon_state()
            if subathon_state and not subathon_state["paused"]:
                await ctx.send(f"A subathon is already running!")
                return
            if subathon_state and subathon_state["paused"]:
                await resume_subathon(ctx)
            else:
                await cursor.execute("SELECT * FROM subathon_settings LIMIT 1")
                settings = await cursor.fetchone()
                if settings:
                    starting_minutes = settings["starting_minutes"]
                    subathon_start_time = datetime.now()
                    subathon_end_time = subathon_start_time + timedelta(minutes=starting_minutes)
                    await cursor.execute("INSERT INTO subathon (start_time, end_time, starting_minutes, paused, remaining_minutes) VALUES (%s, %s, %s, %s, %s)", (subathon_start_time, subathon_end_time, starting_minutes, False, 0))
                    await sqldb.commit()
                    await ctx.send(f"Subathon started!")
                    asyncio.create_task(subathon_countdown())
                    # Send websocket notice
                    additional_data = {'starting_minutes': starting_minutes}
                    asyncio.create_task(websocket_notice("SUBATHON_START", additional_data))
                else:
                    await ctx.send(f"Can't start subathon, please go to the dashboard and set up subathons.")
    finally:
        await cursor.close()
        await sqldb.ensure_closed()

# Function to stop subathon timer
async def stop_subathon(ctx):
    sqldb = await get_mysql_connection()
    subathon_state = await get_subathon_state()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            if subathon_state and not subathon_state["paused"]:
                await cursor.execute("UPDATE subathon SET paused = %s WHERE id = %s", (True, subathon_state["id"]))
                await sqldb.commit()
                await ctx.send(f"Subathon ended!")
                # Send websocket notice
                asyncio.create_task(websocket_notice("SUBATHON_STOP"))
            else:
                await ctx.send(f"No subathon active.")
    finally:
        await cursor.close()
        await sqldb.ensure_closed()

# Function to pause subathon
async def pause_subathon(ctx):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            subathon_state = await get_subathon_state()
            if subathon_state and not subathon_state["paused"]:
                remaining_minutes = (subathon_state["end_time"] - datetime.now()).total_seconds() // 60
                await cursor.execute("UPDATE subathon SET paused = %s, remaining_minutes = %s WHERE id = %s", (True, remaining_minutes, subathon_state["id"]))
                await sqldb.commit()
                await ctx.send(f"Subathon paused with {int(remaining_minutes)} minutes remaining.")
                # Send websocket notice
                additional_data = {'remaining_minutes': remaining_minutes}
                asyncio.create_task(websocket_notice("SUBATHON_PAUSE", additional_data))
            else:
                await ctx.send("No subathon is active or it's already paused!")
    finally:
        await cursor.close()
        await sqldb.ensure_closed()

# Function to resume subathon
async def resume_subathon(ctx):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            subathon_state = await get_subathon_state()
            if subathon_state and subathon_state["paused"]:
                subathon_end_time = datetime.now() + timedelta(minutes=subathon_state["remaining_minutes"])
                await cursor.execute("UPDATE subathon SET paused = %s, remaining_minutes = %s, end_time = %s WHERE id = %s", (False, 0, subathon_end_time, subathon_state["id"]))
                await sqldb.commit()
                await ctx.send(f"Subathon resumed with {int(subathon_state['remaining_minutes'])} minutes remaining!")
                asyncio.create_task(subathon_countdown())
                # Send websocket notice
                additional_data = {'remaining_minutes': subathon_state["remaining_minutes"]}
                asyncio.create_task(websocket_notice("SUBATHON_RESUME", additional_data))
    finally:
        await cursor.close()
        await sqldb.ensure_closed()

# Function to Add Time to subathon
async def addtime_subathon(ctx, minutes):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            subathon_state = await get_subathon_state()
            if subathon_state and not subathon_state["paused"]:
                subathon_end_time = subathon_state["end_time"] + timedelta(minutes=minutes)
                await cursor.execute("UPDATE subathon SET end_time = %s WHERE id = %s", (subathon_end_time, subathon_state["id"]))
                await sqldb.commit()
                await ctx.send(f"Added {minutes} minutes to the subathon timer!")
                # Send websocket notice
                additional_data = {'added_minutes': minutes}
                asyncio.create_task(websocket_notice("SUBATHON_ADD_TIME", additional_data))
            else:
                await ctx.send("No subathon is active or it's paused!")
    finally:
        await cursor.close()
        await sqldb.ensure_closed()

# Function to get the current subathon status
async def subathon_status(ctx):
    subathon_state = await get_subathon_state()
    if subathon_state:
        if subathon_state["paused"]:
            await ctx.send(f"Subathon is paused with {subathon_state['remaining_minutes']} minutes remaining.")
        else:
            remaining = subathon_state["end_time"] - datetime.now()
            await ctx.send(f"Subathon time remaining: {remaining}.")
    else:
        await ctx.send("No subathon is active!")

# Function to start the subathon countdown
async def subathon_countdown():
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
    while True:
        subathon_state = await get_subathon_state()
        if subathon_state and not subathon_state["paused"]:
            now = datetime.now()
            if now >= subathon_state["end_time"]:
                await channel.send(f"Subathon has ended!")
                sqldb = await get_mysql_connection()
                try:
                    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                        await cursor.execute("UPDATE subathon SET paused = %s WHERE id = %s", (True, subathon_state["id"]))
                        await sqldb.commit()
                finally:
                    await cursor.close()
                    await sqldb.ensure_closed()
            break
        await asyncio.sleep(60)  # Check every minute

# Function to get the current subathon state
async def get_subathon_state():
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute("SELECT * FROM subathon ORDER BY id DESC LIMIT 1")
            return await cursor.fetchone()
    finally:
        await cursor.close()
        await sqldb.ensure_closed()

# Function to run at midnight each night
async def midnight():
    # Get the timezone once outside the loop
    sqldb = await get_mysql_connection()
    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
        await cursor.execute("SELECT timezone FROM profile")
        result = await cursor.fetchone()
        if result and result.get("timezone"):
            timezone = result.get("timezone")
            tz = pytz.timezone(timezone)
        else:
            # Default to UTC if no timezone is set
            bot_logger.info("No timezone set for the user. Defaulting to UTC.")
            tz = pytz.UTC  # Set to UTC
    while True:
        # Get the current time in the user's timezone
        current_time = datetime.now(tz)
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
                channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
                await channel.send(message)
            # Sleep for 120 seconds to avoid sending the message multiple times
            await asyncio.sleep(120)
        else:
            # Sleep for 10 seconds before checking again
            await asyncio.sleep(10)

async def reload_env_vars():
    # Load in all the globals
    global SQL_HOST, SQL_USER, SQL_PASSWORD, ADMIN_API_KEY, USE_BACKUP_SYSTEM
    global BACKUP_SYSTEM, OAUTH_TOKEN, CLIENT_ID, CLIENT_SECRET, TWITCH_GQL
    global SHAZAM_API, STEAM_API, SPOTIFY_CLIENT_ID, SPOTIFY_CLIENT_SECRET
    global EXCHANGE_RATE_API_KEY, HYPERATE_API_KEY, CHANNEL_AUTH
    # Reload the .env file
    load_dotenv()
    SQL_HOST = os.getenv('SQL_HOST')
    SQL_USER = os.getenv('SQL_USER')
    SQL_PASSWORD = os.getenv('SQL_PASSWORD')
    ADMIN_API_KEY = os.getenv('ADMIN_KEY')
    USE_BACKUP_SYSTEM = os.getenv('USE_BACKUP_SYSTEM', 'False').lower() == 'true'
    if USE_BACKUP_SYSTEM:
        BACKUP_SYSTEM = True
        OAUTH_TOKEN = f"oauth:{CHANNEL_AUTH}"
        CLIENT_ID = os.getenv('BACKUP_CLIENT_ID')
        CLIENT_SECRET = os.getenv('BACKUP_SECRET_KEY')
    else:
        BACKUP_SYSTEM = False
        OAUTH_TOKEN = os.getenv('OAUTH_TOKEN')
        CLIENT_ID = os.getenv('CLIENT_ID')
        CLIENT_SECRET = os.getenv('CLIENT_SECRET')
    TWITCH_GQL = os.getenv('TWITCH_GQL')
    SHAZAM_API = os.getenv('SHAZAM_API')
    STEAM_API = os.getenv('STEAM_API')
    SPOTIFY_CLIENT_ID = os.getenv('SPOTIFY_CLIENT_ID')
    SPOTIFY_CLIENT_SECRET = os.getenv('SPOTIFY_CLIENT_SECRET')
    EXCHANGE_RATE_API_KEY = os.getenv('EXCHANGE_RATE_API')
    HYPERATE_API_KEY = os.getenv('HYPERATE_API_KEY')
    # Log or handle any environment variable updates
    bot_logger.info("Reloaded environment variables")

async def get_point_settings():
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
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
        await cursor.close()
        await sqldb.ensure_closed()

async def known_users():
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
    sqldb = await get_mysql_connection()
    try:
        headers = {
            "Authorization": f"Bearer {CHANNEL_AUTH}",
            "Client-Id": CLIENT_ID,
            "Content-Type": "application/json"
        }
        async with aiohttp.ClientSession() as session:
            # Get all the mods and put them into the database
            url_mods = f'https://api.twitch.tv/helix/moderation/moderators?broadcaster_id={CHANNEL_ID}'
            async with session.get(url_mods, headers=headers) as response:
                if response.status == 200:
                    data = await response.json()
                    moderators = data.get('data', [])
                    mod_list = [mod['user_name'] for mod in moderators]
                    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                        for mod in mod_list:
                            await cursor.execute("INSERT INTO everyone (username, group_name) VALUES (%s, %s) ON DUPLICATE KEY UPDATE group_name = %s", (mod, "MOD", "MOD"))
                        await sqldb.commit()
                else:
                    api_logger.error(f"Failed to fetch moderators: {response.status} - {await response.text()}")
            # Get all the VIPs and put them into the database
            url_vips = f'https://api.twitch.tv/helix/channels/vips?broadcaster_id={CHANNEL_ID}'
            async with session.get(url_vips, headers=headers) as response:
                if response.status == 200:
                    data = await response.json()
                    vips = data.get('data', [])
                    vip_list = [vip['user_name'] for vip in vips]
                    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                        for vip in vip_list:
                            await cursor.execute("INSERT INTO everyone (username, group_name) VALUES (%s, %s) ON DUPLICATE KEY UPDATE group_name = %s", (vip, "VIP", "VIP"))
                        await sqldb.commit()
                else:
                    api_logger.error(f"Failed to fetch VIPs: {response.status} - {await response.text()}")
    except Exception as e:
        bot_logger.error(f"An error occurred in known_users: {e}")
    finally:
        await sqldb.ensure_closed()

async def check_premium_feature():
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID, ADMIN_API_KEY
    try:
        twitch_user_url = "https://api.twitch.tv/helix/users"
        twitch_subscriptions_url = f"https://api.twitch.tv/helix/subscriptions/user?broadcaster_id=140296994&user_id={CHANNEL_ID}"
        beta_users_url = f"https://api.botofthespecter.com/authorizedusers?api_key={ADMIN_API_KEY}"
        headers = {
            "Client-ID": CLIENT_ID,
            "Authorization": f"Bearer {CHANNEL_AUTH}",
        }
        async with aiohttp.ClientSession() as session:
            # Check Display Name and get Auth List from Specter API
            async with session.get(twitch_user_url, headers=headers) as response:
                response.raise_for_status()
                user_data = await response.json()
                display_name = user_data["data"][0]["display_name"]
            # Check if the user is in the authorized list
            async with session.get(beta_users_url) as response:
                response.raise_for_status()
                auth_data = await response.json()
                auth_data = {key: value.lower() if isinstance(value, str) else value for key, value in auth_data.items()}
                if display_name in auth_data["users"]:
                    return 4000
            # If user not found in Auth List, check if they're a subscriber
            async with session.get(twitch_subscriptions_url, headers=headers) as response:
                response.raise_for_status()
                data = await response.json()
                if data.get("data"):
                    return int(data["data"][0]["tier"])
                else:
                    return 0  # Return 0 if not subscribed
    except aiohttp.ClientError as e:
        sanitized_message = str(e).replace(ADMIN_API_KEY, "[ADMIN_API_KEY]")
        twitch_logger.error(f"Error checking user/subscription: {sanitized_message}")
        return 0  # Return 0 for any API error

# Make a Stream Marker for events
async def make_stream_marker(description: str):
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
    payload = {
        "user_id": CHANNEL_ID,
        "description": description
    }
    headers = {
        "Client-ID": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}",
        "Content-Type": "application/json"
    }
    try:
        async with aiohttp.ClientSession() as session:
            async with session.post('https://api.twitch.tv/helix/streams/markers', headers=headers, json=payload) as marker_response:
                if marker_response.status == 200:
                    return True
                else:
                    return False
    except aiohttp.ClientError as e:
        twitch_logger.error(f"Error creating stream marker: {e}")
        return False

# Connect to database
async def get_mysql_connection():
    return await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db=CHANNEL_NAME
    )

# Connect to database to get Spam Patterns
async def get_spam_patterns():
    # Connect to your MySQL database
    pattern_db = await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db="spam_pattern",
    )
    async with pattern_db.cursor(aiomysql.DictCursor) as cursor:
        await cursor.execute("SELECT spam_pattern FROM spam_patterns")
        results = await cursor.fetchall()
    # Close the connection
    pattern_db.close()
    # Compile the regular expressions
    compiled_patterns = [re.compile(row["spam_pattern"], re.IGNORECASE) for row in results if row["spam_pattern"]]
    return compiled_patterns

# Connect to database to get settings from the website
async def access_website_database():
    # Connect to your MySQL database
    return await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db="website",
    )

# Function to check if a URL or domain matches whitelisted or blacklisted URLs
async def match_domain_or_link(message, domain_list):
    for domain in domain_list:
        pattern = re.escape(domain)
        if re.search(rf"(https?://)?(www\.)?{pattern}(\/|$)", message):
            return True
    return False

# Function(s) to track watch time for users in active channel
async def periodic_watch_time_update():
    while True:
        # Fetch active users from Twitch API
        active_users = await fetch_active_users()
        if not active_users:
            bot_logger.warning("No active users found. Skipping this interval.")
        else:
            # Pass the active users (raw data) to the watch time tracker
            await track_watch_time(active_users)
        # Wait for 60 seconds before the next check
        await asyncio.sleep(60)

# Function to get a list of users that are active in chat
async def fetch_active_users():
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID
    headers = {
        "Client-ID": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}"
    }
    url = f"https://api.twitch.tv/helix/chat/chatters?broadcaster_id={CHANNEL_ID}&moderator_id={CHANNEL_ID}"
    async with aiohttp.ClientSession() as session:
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
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            current_time = int(time.time())
            for user in active_users:
                user_login = user['user_login']
                user_id = user['user_id']
                # Fetch the excluded_users list from the watch_time_excluded_users table
                await cursor.execute("SELECT excluded_users FROM watch_time_excluded_users LIMIT 1")
                excluded_users_data = await cursor.fetchone()
                excluded_users = excluded_users_data['excluded_users'] if excluded_users_data else ''
                excluded_users_list = excluded_users.split(',') if excluded_users else []
                # Skip the user if they are marked as excluded
                if user_login in excluded_users_list:
                    continue  # Skip to the next user if excluded
                # Fetch existing watch time data for the user from the watch_time table
                await cursor.execute("SELECT total_watch_time_live, total_watch_time_offline, last_active FROM watch_time WHERE user_id = %s", (user_id,))
                user_data = await cursor.fetchone()
                if user_data:
                    total_watch_time_live = user_data['total_watch_time_live']
                    total_watch_time_offline = user_data['total_watch_time_offline']
                    if stream_online:
                        total_watch_time_live += 60
                    else:
                        total_watch_time_offline += 60
                    # Update watch time in the database
                    await cursor.execute("UPDATE watch_time SET total_watch_time_live = %s, total_watch_time_offline = %s, last_active = %s WHERE user_id = %s", (total_watch_time_live, total_watch_time_offline, current_time, user_id))
                else:
                    # Insert new user data if not found
                    await cursor.execute("INSERT INTO watch_time (user_id, username, total_watch_time_live, total_watch_time_offline, last_active) VALUES (%s, %s, %s, %s, %s)", (user_id, user_login, 60 if stream_online else 0, 60 if not stream_online else 0, current_time))
            await sqldb.commit()
    except Exception as e:
        bot_logger.error(f"Error in track_watch_time: {e}", exc_info=True)
    finally:
        await sqldb.ensure_closed()

# Function to periodically check the queue
async def check_song_requests():
    global SPOTIFY_ACCESS_TOKEN, song_requests
    while True:
        await asyncio.sleep(180)
        if song_requests:
            headers = { "Authorization": f"Bearer {SPOTIFY_ACCESS_TOKEN}" }
            queue_url = "https://api.spotify.com/v1/me/player/queue"
            async with aiohttp.ClientSession() as session:
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
    sqldb = await get_mysql_connection()
    count = None
    async with sqldb.cursor(aiomysql.DictCursor) as cursor:
        if action == "kiss":
            await cursor.execute(
                'INSERT INTO kiss_counts (username, kiss_count) VALUES (%s, 1) '
                'ON DUPLICATE KEY UPDATE kiss_count = kiss_count + 1', 
                (author,)
            )
            await sqldb.commit()
            await cursor.execute('SELECT kiss_count FROM kiss_counts WHERE username = %s', (author,))
        elif action == "hug":
            await cursor.execute(
                'INSERT INTO hug_counts (username, hug_count) VALUES (%s, 1) '
                'ON DUPLICATE KEY UPDATE hug_count = hug_count + 1', 
                (author,)
            )
            await sqldb.commit()
            await cursor.execute('SELECT hug_count FROM hug_counts WHERE username = %s', (author,))
        elif action == "highfive":
            await cursor.execute(
                'INSERT INTO highfive_counts (username, highfive_count) VALUES (%s, 1) '
                'ON DUPLICATE KEY UPDATE highfive_count = highfive_count + 1', 
                (author,)
            )
            await sqldb.commit()
            await cursor.execute('SELECT highfive_count FROM highfive_counts WHERE username = %s', (author,))
            action = "high five"
        else:
            return
        result = await cursor.fetchone()
        if result:
            count = list(result.values())[0]
    await sqldb.ensure_closed()
    if count is not None:
        await ctx.send(f"Thanks for the {action}, {author}! I've given you a {action} too, you have been {action} {count} times!")

# Function to remove the temp user from the shoutout_user dict
async def remove_shoutout_user(username: str, delay: int):
    global shoutout_user
    await asyncio.sleep(delay)
    if shoutout_user:
        chat_logger.info(f"Removed temporary shoutout data for {username}")
        shoutout_user = None

# Handel upcoming Twitch Ads
async def handle_upcoming_ads():
    global CLIENT_ID, CHANNEL_AUTH, CHANNEL_ID, stream_online
    channel = BOTS_TWITCH_BOT.get_channel(CHANNEL_NAME)
    headers = {
        "Client-ID": CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}"
    }
    url = f"https://api.twitch.tv/helix/channels/ads?broadcaster_id={CHANNEL_ID}"
    while True:
        await asyncio.sleep(60)
        if not stream_online:
            continue
        # Get ad notification settings from database
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT * FROM ad_notice_settings WHERE id = %s", (1,))
                settings = await cursor.fetchone()
                if settings:
                    enable_ad_notice = settings.get("enable_ad_notice", True)
                    ad_upcoming_message = settings.get("ad_upcoming_message", "Upcoming ad break in (minutes) minutes!")
                else:
                    # Default settings if not found in database
                    enable_ad_notice = True
                    ad_upcoming_message = "Upcoming ad break in (minutes) minutes!"
        except Exception as e:
            api_logger.error(f"Error retrieving ad notice settings: {e}")
            enable_ad_notice = True
            ad_upcoming_message = "Upcoming ad break in (minutes) minutes!"
        finally:
            await sqldb.ensure_closed()
        # Skip if notifications are disabled
        if not enable_ad_notice:
            continue
        try:
            async with aiohttp.ClientSession() as session:
                async with session.get(url, headers=headers) as response:
                    if response.status == 200:
                        data = await response.json()
                        ads_data = data.get("data", [])
                        if ads_data:
                            preroll_free_time = ads_data[0].get("preroll_free_time")
                            ad_duration = ads_data[0].get("ad_duration")
                            initial_snooze_count = ads_data[0].get("snooze_count")
                            max_time = ad_duration + 300  # Allow a 5-minute buffer
                            if preroll_free_time > ad_duration and preroll_free_time < max_time:
                                time_to_ad = preroll_free_time - 180
                                time_to_ad_minutes = int(time_to_ad / 60)
                                message = ad_upcoming_message.replace("(minutes)", str(time_to_ad_minutes))
                                if time_to_ad_minutes == 1:
                                    message = message.replace("minutes", "minute")
                                await channel.send(message)
                                api_logger.info(f"Sent ad notification: {message}")
                                # Start monitoring for snoozes until the ad starts
                                elapsed_time = 0
                                ad_window = preroll_free_time
                                last_snooze_count = initial_snooze_count
                                while elapsed_time < ad_window:
                                    await asyncio.sleep(30)
                                    elapsed_time += 30
                                    async with session.get(url, headers=headers) as check_response:
                                        if check_response.status == 200:
                                            check_data = await check_response.json()
                                            check_ads = check_data.get("data", [])
                                            if check_ads:
                                                current_snooze_count = check_ads[0].get("snooze_count")
                                                if current_snooze_count < last_snooze_count:
                                                    await channel.send("Ad break snoozed.")
                                                    api_logger.info("Ad break snoozed detected.")
                                                    last_snooze_count = current_snooze_count
                                                # Update the remaining preroll free time
                                                preroll_free_time = check_ads[0].get("preroll_free_time")
                                                if preroll_free_time <= 0:
                                                    api_logger.info("Ad break should have started.")
                                                    break
                                        else:
                                            api_logger.warning(f"Failed to fetch ad data during snooze check. Status: {check_response.status}, Response: {await check_response.text()}")
                                await asyncio.sleep(600)  # Wait 10 minutes before next check
                        else:
                            api_logger.info("No upcoming ad breaks scheduled.")
                    else:
                        api_logger.warning(f"Failed to fetch ad data. Status: {response.status}, Response: {await response.text()}")
        except Exception as e:
            api_logger.error(f"Error in handle_upcoming_ads: {e}")

# Here is the TwitchBot
BOTS_TWITCH_BOT = TwitchBot(
    token=OAUTH_TOKEN,
    prefix='!',
    channel_name=CHANNEL_NAME
)

# Run the bot
def start_bot():
    # Start the bot
    BOTS_TWITCH_BOT.run()

if __name__ == '__main__':
    start_bot()