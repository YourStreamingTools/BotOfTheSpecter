# Standard library imports
import os
import re
import asyncio
import argparse
import datetime
from datetime import datetime, timezone, timedelta
import logging
import subprocess
import websockets
import json
import time
import random
import base64

# Third-party imports
import aiohttp
import requests
import mysql.connector
from translate import Translator
from googletrans import Translator, LANGUAGES
from twitchio.ext import commands
import streamlink
import pyowm
import pytz

# Parse command-line arguments
parser = argparse.ArgumentParser(description="BotOfTheSpecter Chat Bot")
parser.add_argument("-channel", dest="target_channel", required=True, help="Target Twitch channel name")
parser.add_argument("-channelid", dest="channel_id", required=True, help="Twitch user ID")
parser.add_argument("-token", dest="channel_auth_token", required=True, help="Auth Token for authentication")
parser.add_argument("-refresh", dest="refresh_token", required=True, help="Refresh Token for authentication")
args = parser.parse_args()

# Twitch bot settings
CHANNEL_NAME = args.target_channel
CHANNEL_ID = args.channel_id
CHANNEL_AUTH = args.channel_auth_token
REFRESH_TOKEN = args.refresh_token
BOT_USERNAME = "botofthespecter"
VERSION = "4.1"
SQL_HOST = ""  # CHANGE TO MAKE THIS WORK
SQL_USER = ""  # CHANGE TO MAKE THIS WORK
SQL_PASSWORD = ""  # CHANGE TO MAKE THIS WORK
OAUTH_TOKEN = ""  # CHANGE TO MAKE THIS WORK
CLIENT_ID = ""  # CHANGE TO MAKE THIS WORK
CLIENT_SECRET = ""  # CHANGE TO MAKE THIS WORK
TWITCH_API_AUTH = ""  # CHANGE TO MAKE THIS WORK
TWITCH_GQL = ""  # CHANGE TO MAKE THIS WORK
SHAZAM_API = ""  # CHANGE TO MAKE THIS WORK
WEATHER_API = ""  # CHANGE TO MAKE THIS WORK
STEAM_API = ""  # CHANGE TO MAKE THIS WORK
TWITCH_API_CLIENT_ID = CLIENT_ID
builtin_commands = {"commands", "bot", "roadmap", "quote", "timer", "game", "ping", "weather", "time", "song", "translate", "cheerleader", "steam", "schedule", "mybits", "lurk", "unlurk", "lurking", "lurklead", "clip", "subscription", "hug", "kiss", "uptime", "typo", "typos", "followage", "deaths"}
mod_commands = {"addcommand", "removecommand", "removetypos", "permit", "removequote", "quoteadd", "settitle", "setgame", "edittypos", "deathadd", "deathremove", "shoutout", "marker", "checkupdate"}
builtin_aliases = {"cmds", "back", "so", "typocount", "edittypo", "removetypo", "death+", "death-", "mysub"}

# Logs
webroot = "/var/www/"
logs_directory = "logs"
bot_logs = os.path.join(logs_directory, "bot")
chat_logs = os.path.join(logs_directory, "chat")
twitch_logs = os.path.join(logs_directory, "twitch")
api_logs = os.path.join(logs_directory, "api")
chat_history_logs = os.path.join(logs_directory, "chat_history")

# Ensure directories exist
for directory in [logs_directory, bot_logs, chat_logs, twitch_logs, api_logs, chat_history_logs]:
    directory_path = os.path.join(webroot, directory)
    if not os.path.exists(directory_path):
        os.makedirs(directory_path)

# Create a function to setup individual loggers for clarity
def setup_logger(name, log_file, level=logging.INFO):
    handler = logging.FileHandler(log_file)    
    formatter = logging.Formatter('%(asctime)s - %(levelname)s - %(message)s')
    handler.setFormatter(formatter)

    logger = logging.getLogger(name)
    logger.setLevel(level)
    logger.addHandler(handler)

    return logger

# Function to get today's date in the required format
def get_today_date():
    return datetime.now().strftime("%d-%m-%Y")

# Setup bot logger
bot_log_file = os.path.join(webroot, bot_logs, f"{CHANNEL_NAME}.txt")
bot_logger = setup_logger('bot', bot_log_file)

# Setup chat logger
chat_log_file = os.path.join(webroot, chat_logs, f"{CHANNEL_NAME}.txt")
chat_logger = setup_logger('chat', chat_log_file)

# Setup twitch logger
twitch_log_file = os.path.join(webroot, twitch_logs, f"{CHANNEL_NAME}.txt")
twitch_logger = setup_logger('twitch', twitch_log_file)

# Setup API logger
api_log_file = os.path.join(webroot, api_logs, f"{CHANNEL_NAME}.txt")
api_logger = setup_logger("api", api_log_file)

# Setup chat history logger
chat_history_folder = os.path.join(webroot, chat_history_logs, CHANNEL_NAME)
if not os.path.exists(chat_history_folder):
    os.makedirs(chat_history_folder)
chat_history_log_file = os.path.join(chat_history_folder, f"{get_today_date()}.txt")
chat_history_logger = setup_logger('chat_history', chat_history_log_file)

# Connect to MySQL
mysql_connection = mysql.connector.connect(
    host=SQL_HOST,
    user=SQL_USER,
    password=SQL_PASSWORD
)
mysql_cursor = mysql_connection.cursor()

# Create MySQL database nammed after the channel, if it doesn't exist
mysql_cursor.execute("CREATE DATABASE IF NOT EXISTS {}".format(CHANNEL_NAME))
mysql_connection.commit()
mysql_cursor.execute("USE {}".format(CHANNEL_NAME))
mysql_connection.commit()
# Create the tables if it doesn't exist
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS everyone (
    username TEXT,
    group_name TEXT DEFAULT NULL,
    PRIMARY KEY (username(255))
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS `groups` (
    `id` int NOT NULL AUTO_INCREMENT,
    `name` text,
    PRIMARY KEY (`id`)
    ) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS custom_commands (
        command TEXT,
        response TEXT,
        status TEXT,
        PRIMARY KEY (command(255))
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS builtin_commands (
        command TEXT,
        status TEXT,      
        PRIMARY KEY (command(255))
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS user_typos (
        username TEXT,
        typo_count INTEGER DEFAULT 0,
        PRIMARY KEY (username(255))
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS lurk_times (
        user_id TEXT,
        start_time TEXT NOT NULL,
        PRIMARY KEY (user_id(255))
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS hug_counts (
        username TEXT,
        hug_count INTEGER DEFAULT 0,
        PRIMARY KEY (username(255))
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS kiss_counts (
        username TEXT,
        kiss_count INTEGER DEFAULT 0,
        PRIMARY KEY (username(255))
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS total_deaths (
        death_count INTEGER DEFAULT 0
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS game_deaths (
        game_name TEXT,
        death_count INTEGER DEFAULT 0,
        PRIMARY KEY (game_name(255))
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS custom_counts (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        command TEXT NOT NULL,
        count INTEGER NOT NULL
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS bits_data (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        user_id TEXT,
        user_name TEXT,
        bits INTEGER,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS subscription_data (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        user_id TEXT,
        user_name TEXT,
        sub_plan TEXT,
        months INTEGER,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS followers_data (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        user_id TEXT,
        user_name TEXT,
        followed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS raid_data (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        raider_name TEXT,
        raider_id TEXT,
        viewers INTEGER,
        raid_count INTEGER,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS quotes (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        quote TEXT
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS seen_users (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        username TEXT,
        welcome_message TEXT DEFAULT NULL,
        status TEXT
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS seen_today (
        user_id TEXT,
        PRIMARY KEY (user_id(255))
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS timed_messages (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        interval_count INTEGER,
        message TEXT
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS profile (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        timezone TEXT DEFAULT NULL,
        weather_location TEXT DEFAULT NULL,
        discord_alert TEXT DEFAULT NULL,
        discord_mod TEXT DEFAULT NULL,
        discord_alert_online TEXT DEFAULT NULL
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS protection (
        url_blocking TEXT,
        profanity TEXT
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS link_whitelist (
        link TEXT,
        PRIMARY KEY (link(255))
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS link_blacklisting (
        link TEXT,
        PRIMARY KEY (link(255))
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS stream_credits (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        username TEXT,
        event TEXT,
        data INTEGER
    ) ENGINE=InnoDB
''')
mysql_cursor.execute('''
    CREATE TABLE IF NOT EXISTS message_counts (
        username TEXT,
        message_count INTEGER NOT NULL,
        user_level TEXT NOT NULL,
        PRIMARY KEY (username(255))
    ) ENGINE=InnoDB
''')
mysql_connection.commit()

# Initialize instances for the translator, shoutout queue, webshockets and permitted users for protection
translator = Translator(service_urls=['translate.google.com'])
shoutout_queue = asyncio.Queue()
scheduled_tasks = asyncio.Queue()
permitted_users = {}
bot_logger.info("Bot script started.")
connected = set()
scheduled_tasks = []
global stream_online
global current_game
global stream_title
stream_online = False
current_game = None

# Setup Token Refresh
async def refresh_token_every_day():
    global REFRESH_TOKEN
    next_refresh_time = time.time() + 4 * 60 * 60 - 300  # 4 hours in seconds, minus 5 minutes for refresh
    while True:
        current_time = time.time()
        time_until_expiration = next_refresh_time - current_time

        if current_time >= next_refresh_time:
            REFRESH_TOKEN, next_refresh_time = await refresh_token(REFRESH_TOKEN)
        else:
            if time_until_expiration > 3600:  # More than 1 hour until expiration
                sleep_time = 3600  # Check again in 1 hour
            elif time_until_expiration > 300:  # More than 5 minutes until expiration
                sleep_time = 300  # Check again in 5 minutes
            else:
                sleep_time = 60  # Check every minute when close to expiration
            
            # Log only when the check frequency changes or is about to refresh
            twitch_logger.info(f"Next token check in {sleep_time // 60} minutes. Token is still valid for {time_until_expiration // 60} minutes and {time_until_expiration % 60} seconds.")
            await asyncio.sleep(sleep_time)  # Wait before checking again, based on the time until expiration

async def refresh_token(current_refresh_token):
    global CHANNEL_AUTH, REFRESH_TOKEN
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
                    if new_access_token:
                        # Token refreshed successfully
                        new_refresh_token = response_json.get('refresh_token', current_refresh_token)

                        # Update the global variables with the new tokens
                        CHANNEL_AUTH = new_access_token
                        REFRESH_TOKEN = new_refresh_token

                        # Calculate the next refresh time to be 5 minutes before the 4-hour mark
                        next_refresh_time = time.time() + 4 * 60 * 60 - 300  # 4 hours in seconds, minus 5 minutes, so we refresh before actual expiration

                        log_message = "Refreshed token. New Access Token: {}, New Refresh Token: {}".format(new_access_token, new_refresh_token)
                        twitch_logger.info(log_message)

                        return new_refresh_token, next_refresh_time
                    else:
                        twitch_logger.error("Token refresh failed: 'access_token' not found in response")
                else:
                    twitch_logger.error(f"Token refresh failed: HTTP {response.status}")
    except Exception as e:
        # Log the error if token refresh fails
        twitch_logger.error(f"Token refresh failed: {e}")

# Setup Twitch EventSub
async def twitch_eventsub():
    twitch_websocket_uri = "wss://eventsub.wss.twitch.tv/ws?keepalive_timeout_seconds=600"

    while True:
        try:
            async with websockets.connect(twitch_websocket_uri) as websocket:
                # Receive and parse the welcome message
                eventsub_welcome_message = await websocket.recv()
                eventsub_welcome_data = json.loads(eventsub_welcome_message)

                # Validate the message type
                if eventsub_welcome_data.get('metadata', {}).get('message_type') == 'session_welcome':
                    session_id = eventsub_welcome_data['payload']['session']['id']
                    keepalive_timeout = eventsub_welcome_data['payload']['session']['keepalive_timeout_seconds']

                    bot_logger.info(f"Connected with session ID: {session_id}")
                    bot_logger.info(f"Keepalive timeout: {keepalive_timeout} seconds")

                    # Subscribe to the events using the session ID and auth token
                    await subscribe_to_events(session_id)

                    # Manage keepalive and listen for messages concurrently
                    await asyncio.gather(receive_messages(websocket, keepalive_timeout))

        except websockets.ConnectionClosedError as e:
            bot_logger.error(f"WebSocket connection closed unexpectedly: {e}")
            await asyncio.sleep(10)  # Wait before retrying
        except Exception as e:
            bot_logger.error(f"An unexpected error occurred: {e}")
            await asyncio.sleep(10)  # Wait before reconnecting

async def subscribe_to_events(session_id):
    url = "https://api.twitch.tv/helix/eventsub/subscriptions"
    headers = {
        "Authorization": f"Bearer {CHANNEL_AUTH}",
        "Client-Id": TWITCH_API_CLIENT_ID,
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
        "channel.charity_campaign.donate"
    ]

    v2topics = [
        "channel.follow",
        "channel.update",
    ]

    responses = []
    async with aiohttp.ClientSession() as session:
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
            async with session.post(url, headers=headers, json=payload) as response:
                if response.status in (200, 202):
                    bot_logger.info(f"WebSocket subscription successful for {v1topic}")
                    responses.append(await response.json())

    async with aiohttp.ClientSession() as session:
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
            async with session.post(url, headers=headers, json=payload) as response:
                if response.status in (200, 202):
                    bot_logger.info(f"WebSocket subscription successful for {v2topic}")
                    responses.append(await response.json())

async def receive_messages(websocket, keepalive_timeout):
    while True:
        try:
            message = await asyncio.wait_for(websocket.recv(), timeout=keepalive_timeout)
            message_data = json.loads(message)
            # bot_logger.info(f"Received message: {message}")

            if 'metadata' in message_data:
                message_type = message_data['metadata'].get('message_type')
                if message_type == 'session_keepalive':
                    bot_logger.info("Received session keepalive message")
                else:
                    # bot_logger.info(f"Received message type: {message_type}")
                    bot_logger.info(f"Info from Twitch EventSub: {message_data}")
                    await process_eventsub_message(message_data)
            else:
                bot_logger.error("Received unrecognized message format")

        except asyncio.TimeoutError:
            bot_logger.error("Keepalive timeout exceeded, reconnecting...")
            await websocket.close()
            break  # Exit the loop to allow reconnection logic

        except websockets.ConnectionClosedError as e:
            bot_logger.error(f"WebSocket connection closed unexpectedly: {str(e)}")
            break  # Exit the loop for reconnection

        except Exception as e:
            bot_logger.error(f"Error receiving message: {e}")
            break  # Exit the loop on critical error

async def process_eventsub_message(message):
    channel = bot.get_channel(CHANNEL_NAME)
    try:
        event_type = message.get("payload", {}).get("subscription", {}).get("type")
        event_data = message.get("payload", {}).get("event")

        # Process based on event type directly (no need to decode further)
        if event_type:
            if event_type == "channel.follow":
                await process_followers_event(
                    event_data["user_id"],
                    event_data["user_name"],
                    event_data["followed_at"]
                )
            elif event_type == "channel.subscribe":
                tier_mapping = {
                    "1000": "Tier 1",
                    "2000": "Tier 2",
                    "3000": "Tier 3"
                }
                tier = event_data["tier"]
                tier_name = tier_mapping.get(tier, tier)
                await process_subscription_event(
                    event_data["user_id"],
                    event_data["user_name"],
                    tier_name,
                    event_data.get("cumulative_months", 1)
                )
            elif event_type == "channel.subscription.message":
                bot_logger.info(f"Subsctiption (Message) Event Data: {event_data}")
                tier_mapping = {
                    "1000": "Tier 1",
                    "2000": "Tier 2",
                    "3000": "Tier 3"
                }
                tier = event_data["tier"]
                tier_name = tier_mapping.get(tier, tier)
                await process_subscription_message_event(
                    event_data["user_id"],
                    event_data["user_name"],
                    tier_name,
                    event_data("message"),
                    event_data.get("cumulative_months", 1)
                )
            elif event_type == "channel.subscription.gift":
                bot_logger.info(f"Gift Subsctiption Event Data: {event_data}")
                tier_mapping = {
                    "1000": "Tier 1",
                    "2000": "Tier 2",
                    "3000": "Tier 3"
                }
                tier = event_data["tier"]
                tier_name = tier_mapping.get(tier, tier)
                await process_giftsub_event(
                    event_data["user_id"],
                    event_data["user_name"],
                    tier_name,
                    event_data["total"],
                    event_data.get("is_anonymous", False)
                )
            elif event_type == "channel.cheer":
                await process_cheer_event(
                    event_data["user_id"],
                    event_data["user_name"],
                    event_data["bits"]
                )
            elif event_type == "channel.raid":
                bot_logger.info(f"Raid Event Data: {event_data}")
                await process_raid_event(
                    event_data["from_broadcaster_user_id"],
                    event_data["from_broadcaster_user_name"],
                    event_data["viewers"]
                )
            elif event_type == "channel.hype_train.begin":
                bot_logger.info(f"Hype Train Start Event Data: {event_data}")
                level = event_data["level"]
                await channel.send(f"The Hype Train has started! Starting at level: {level}")
            elif event_type == "channel.hype_train.end":
                bot_logger.info(f"Hype Train End Event Data: {event_data}")
                level = event_data["level"]
                top_contributions = event_data.get("top_contributions", [])
                # Craft a message about the end of the Hype Train
                message = f"The Hype Train has ended at level {level}! Top contributions:"
                for contribution in top_contributions:
                    user_name = contribution["user_name"]
                    contribution_type = contribution["type"]
                    total_formatted = "{:,}".format(contribution["total"])
                    total = total_formatted
                    message += f"\n{user_name} contributed {total} {contribution_type}."
                # Send the message to the chat
                await channel.send(message)
            elif event_type == 'channel.update':
                global current_game
                global stream_title
                title = event_data["title"]
                category_name = event_data["category_name"]
                stream_title = title
                current_game = category_name
                bot_logger.info(f"Channel Updated with the following data: Title: {stream_title}. Category: {category_name}.")
            elif event_type == 'channel.ad_break.begin':
                duration_seconds = event_data["event"]["duration_seconds"]
                minutes = duration_seconds // 60
                seconds = duration_seconds % 60
                if minutes == 0:
                    formatted_duration = f"{seconds} seconds"
                elif seconds == 0:
                    formatted_duration = f"{minutes} minutes"
                else:
                    formatted_duration = f"{minutes} minutes, {seconds} seconds"

                await channel.send(f"An ad is about to run for {formatted_duration}. We'll be right back after these ads.")
            elif event_type == 'channel.charity_campaign.donate':
                user = event_data["event"]["user_name"]
                charity = event_data["event"]["charity_name"]
                value = event_data["event"]["amount"]["value"]
                currency = event_data["event"]["amount"]["currency"]
                vaule_formatted = "{:,.2f}".format(value)

                message = f"Thank you so much {user} for your ${vaule_formatted}{currency} donation to {charity}. Your support means so much to us and to {charity}."
                await channel.send(message)
            elif event_type == 'channel.moderate':
                moderator_user_name = event_data["event"]["moderator_user_name"]
                if event_data["event"]["action"] == "timeout":
                    timeout_info = event_data["event"]["timeout"]
                    user_name = timeout_info["user_name"]
                    reason = timeout_info["reason"]
                    expires_at = datetime.strptime(timeout_info["expires_at"], "%Y-%m-%dT%H:%M:%SZ")
                    expires_at_formatted = expires_at.strftime("%Y-%m-%d %H:%M:%S")
                    discord_message = f'{user_name} has been timmed out, their timeout expires at {expires_at_formatted} for the reason "{reason}"'
                    discord_title = "New User Timeout!"
                    discord_image = "clock.png"
                elif event_data["event"]["action"] == "untimeout":
                    untimeout_info = event_data["event"]["untimeout"]
                    user_name = untimeout_info["user_name"]
                    discord_message = f"{user_name} has had their timeout removed by {moderator_user_name}."
                    discord_title = "New Untimeout User!"
                    discord_image = "clock.png"
                elif event_data["event"]["action"] == "ban":
                    banned_info = event_data["event"]["ban"]
                    banned_user_name = banned_info["user_name"]
                    reason = banned_info["reason"]
                    discord_message = f'{banned_user_name} has been banned for "{reason}" by {moderator_user_name}'
                    discord_title = "New User Ban!"
                    discord_image = "ban.png"
                elif event_data["event"]["action"] == "unban":
                    unban_info = event_data["event"]["unban"]
                    banned_user_name = unban_info["user_name"]
                    discord_message = f'{banned_user_name} has been unbanned by {moderator_user_name}'
                    discord_title = "New Unban!"
                    discord_image = "ban.png"
                await send_to_discord_mod(discord_message, discord_title, discord_image)
            elif event_type in ["stream.online", "stream.offline"]:
                if event_type == "stream.online":
                    await process_stream_online()
                else:
                    await process_stream_offline()

            # Logging for unknown event types
            else:
                twitch_logger.error(f"Received message with unknown event type: {event_type}")

    except Exception as e:
        twitch_logger.exception("An error occurred while processing EventSub message:", exc_info=e)

class BotOfTheSpecter(commands.Bot):
    # Event Message to get the bot ready
    def __init__(self, token, prefix, channel_name):
        super().__init__(token=token, prefix=prefix, initial_channels=[channel_name])
        self.channel_name = channel_name

    async def event_ready(self):
        bot_logger.info(f'Logged in as | {self.nick}')
        channel = self.get_channel(self.channel_name)
        await channel.send(f"/me is connected and ready! Running V{VERSION}")
        await check_stream_online()
        await update_version_control()
        asyncio.get_event_loop().create_task(twitch_eventsub())
        asyncio.get_event_loop().create_task(timed_message())

    # Function to check all messages and push out a custom command.
    async def event_message(self, message):
        # Ignore messages from the bot itself
        if message.echo:
            return

        # Log the message content
        chat_history_logger.info(f"Chat message from {message.author.name}: {message.content}")

        # Check for a valid author before proceeding
        if message.author is None:
            bot_logger.error("Received a message without a valid author.")
            return

        # Log the message content
        chat_history_logger.info(f"Chat message from {message.author.name}: {message.content}")

        # Get message content to check if the message is a custom command
        messageContent = message.content.strip().lower()
        messageAuthor = message.author.name
        messageAuthorID = message.author.id
        AuthorMessage = message.content
        group_names = []

        # Handle commands
        await self.handle_commands(message)

        if messageContent.startswith('!'):
            command_parts = messageContent.split()
            command = command_parts[0][1:]  # Extract the command without '!'

            # Log all command usage
            chat_logger.info(f"{messageAuthor} used the command: {command}")

            if command in builtin_commands or command in builtin_aliases:
                chat_logger.info(f"{messageAuthor} used a built-in command called: {command}")
                return  # It's a built-in command or alias, do nothing more

            # Check if the command exists in a hypothetical database and respond
            mysql_cursor.execute('SELECT response, status FROM custom_commands WHERE command = %s', (command,))
            result = mysql_cursor.fetchone()

            if result:
                if result[1] == 'Enabled':
                    response = result[0]
                    switches = ['(customapi.', '(count)', '(daysuntil.']
                    while any(switch in response for switch in switches):
                        if '(customapi.' in response:
                            url_match = re.search(r'\(customapi\.(\S+)\)', response)
                            if url_match:
                                url = url_match.group(1)
                                api_response = fetch_api_response(url)
                                response = response.replace(f"(customapi.{url})", api_response)
                        if '(count)' in response:
                            try:
                                update_custom_count(command)
                                get_count = get_custom_count(command)
                                response = response.replace('(count)', str(get_count))
                            except Exception as e:
                                chat_logger.error(f"{e}")
                        if '(daysuntil.' in response:
                            get_date = re.search(r'\(daysuntil\.(\d{4}-\d{2}-\d{2})\)', response)
                            if get_date:
                                date_str = get_date.group(1)
                                event_date = datetime.strptime(date_str, "%Y-%m-%d").date()
                                current_date = datetime.now().date()
                                days_left = (event_date - current_date).days
                                response = response.replace(f"(daysuntil.{date_str})", str(days_left))
                    chat_logger.info(f"{command} command ran with response: {response}")
                    await message.channel.send(response)
                else:
                    chat_logger.info(f"{command} not ran because it's disabled.")
            else:
                pass
        else:
            pass

        if 'http://' in AuthorMessage or 'https://' in AuthorMessage:
            # Fetch url_blocking option from the protection table in the user's database
            mysql_cursor.execute('SELECT url_blocking FROM protection')
            result = mysql_cursor.fetchone()
            if result:
                url_blocking = bool(result[0])
            else:
                # If url_blocking not found in the database, default to False
                url_blocking = False

            # Check if url_blocking is enabled
            if url_blocking:
                # Check if the user is permitted to post links
                if messageAuthor in permitted_users and time.time() < permitted_users[messageAuthor]:
                    # User is permitted, skip URL blocking
                    return

                if is_mod_or_broadcaster(messageAuthor):
                    # User is a mod or is the broadcaster, they are by default permitted.
                    return

                # Fetch link whitelist from the database
                mysql_cursor.execute('SELECT link FROM link_whitelist')
                whitelisted_links = mysql_cursor.fetchall()
                whitelisted_links = [link[0] for link in whitelisted_links]

                mysql_cursor.execute('SELECT link FROM link_blacklisting')
                blacklisted_links = mysql_cursor.fetchall()
                blacklisted_links = [link[0] for link in blacklisted_links]

                # Check if the message content contains any whitelisted or blacklisted link
                contains_whitelisted_link = any(link in AuthorMessage for link in whitelisted_links)
                contains_blacklisted_link = any(link in AuthorMessage for link in blacklisted_links)

                # Check if the message content contains a Twitch clip link
                contains_twitch_clip_link = 'https://clips.twitch.tv/' in AuthorMessage

                if contains_blacklisted_link:
                    # Delete the message if it contains a blacklisted URL
                    await message.delete()
                    chat_logger.info(f"Deleted message from {messageAuthor} containing a blacklisted URL: {AuthorMessage}")
                    await message.channel.send(f"Oops! That link looks like it's gone on an adventure! Please ask a mod to give it a check and launch an investigation to find out where it's disappeared to!")
                    return  # Stop further processing
                elif not contains_whitelisted_link and not contains_twitch_clip_link:
                    # Delete the message if it contains a URL and it's not whitelisted or a Twitch clip link
                    await message.delete()
                    chat_logger.info(f"Deleted message from {messageAuthor} containing a URL: {AuthorMessage}")
                    # Notify the user not to post links without permission
                    await message.channel.send(f"{messageAuthor}, links are not authorized in chat, ask moderator or the Broadcaster for permission.")
                    return  # Stop further processing
                else:
                    chat_logger.info(f"URL found in message from {messageAuthor}, not deleted due to being whitelisted or a Twitch clip link.")
            else:
                chat_logger.info(f"URL found in message from {messageAuthor}, but URL blocking is disabled.")
        else:
            pass

        # Check user level
        is_vip = is_user_vip(messageAuthorID)
        is_mod = is_user_moderator(messageAuthorID)
        user_level = 'mod' if is_mod else 'vip' if is_vip else 'normal'

        # Insert into the database the number of chats during the stream
        mysql_cursor.execute('''
            INSERT INTO message_counts (username, message_count, user_level)
            VALUES (%s, 1, %s)
            ON DUPLICATE KEY UPDATE message_count = message_count + 1, user_level = %s
        ''', (messageAuthor, user_level, user_level))
        mysql_connection.commit()

        # Has the user been seen during this stream
        mysql_cursor.execute('SELECT * FROM seen_today WHERE user_id = %s', (messageAuthorID,))
        temp_seen_users = mysql_cursor.fetchone()

        # Check if the user is in the list of already seen users
        if temp_seen_users:
            #bot_logger.info(f"{messageAuthor} has already had their welcome message.")
            return

        # Check if the user is the broadcaster
        if messageAuthor.lower() == CHANNEL_NAME.lower():
            #bot_logger.info(f"{CHANNEL_NAME} can't have a welcome message.")
            return

        # Check if the user is a VIP or MOD
        is_vip = is_user_vip(messageAuthorID)
        bot_logger.info(f"{messageAuthor} - VIP={is_vip}")
        is_mod = is_user_moderator(messageAuthorID)
        bot_logger.info(f"{messageAuthor} - MOD={is_mod}")

        # Check if the user is new or returning
        mysql_cursor.execute('SELECT * FROM seen_users WHERE username = %s', (messageAuthor,))
        user_data = mysql_cursor.fetchone()

        if user_data:
            user_status = True
            welcome_message = user_data[2]
            user_status_enabled = user_data[3]
            mysql_cursor.execute('INSERT INTO seen_today (user_id) VALUES (%s)', (messageAuthorID,))
            mysql_connection.commit()
            # twitch_logger.info(f"{messageAuthor} has been found in the database.")
        else:
            user_status = False
            welcome_message = None
            user_status_enabled = 'True'
            mysql_cursor.execute('INSERT INTO seen_today (user_id) VALUES (%s)', (messageAuthorID,))
            mysql_connection.commit()
            # twitch_logger.info(f"{messageAuthor} has not been found in the database.")

        if user_status_enabled == 'True':
            if is_vip:
                # VIP user
                if user_status and welcome_message:
                    # Returning user with custom welcome message
                    await message.channel.send(welcome_message)
                elif user_status:
                    # Returning user
                    vip_welcome_message = f"ATTENTION! A very important person has entered the chat, welcome {messageAuthor}!"
                    await message.channel.send(vip_welcome_message)
                else:
                    # New user
                    await user_is_seen(messageAuthor)
                    new_vip_welcome_message = f"ATTENTION! A very important person has entered the chat, let's give {messageAuthor} a warm welcome!"
                    await message.channel.send(new_vip_welcome_message)
            elif is_mod:
                # Moderator user
                if user_status and welcome_message:
                    # Returning user with custom welcome message
                    await message.channel.send(welcome_message)
                elif user_status:
                    # Returning user
                    mod_welcome_message = f"MOD ON DUTY! Welcome in {messageAuthor}. The power of the sword has increased!"
                    await message.channel.send(mod_welcome_message)
                else:
                    # New user
                    await user_is_seen(messageAuthor)
                    new_mod_welcome_message = f"MOD ON DUTY! Welcome in {messageAuthor}. The power of the sword has increased! Let's give {messageAuthor} a warm welcome!"
                    await message.channel.send(new_mod_welcome_message)
            else:
                # Non-VIP and Non-mod user
                if user_status and welcome_message:
                    # Returning user with custom welcome message
                    await message.channel.send(welcome_message)
                elif user_status:
                    # Returning user
                    welcome_back_message = f"Welcome back {messageAuthor}, glad to see you again!"
                    await message.channel.send(welcome_back_message)
                else:
                    # New user
                    await user_is_seen(messageAuthor)
                    new_user_welcome_message = f"{messageAuthor} is new to the community, let's give them a warm welcome!"
                    await message.channel.send(new_user_welcome_message)
        else:
            # Status disabled for user
            chat_logger.info(f"Message not sent for {messageAuthor} as status is disabled.")

        # Check if the user is the broadcaster
        if messageAuthor == CHANNEL_NAME:
            return

        # Check if the user is a subscriber
        subscription_tier = is_user_subscribed(messageAuthorID)
        if subscription_tier:
            # Map subscription tier to group name
            if subscription_tier == "Tier 1":
                group_names.append("Subscriber T1")
            elif subscription_tier == "Tier 2":
                group_names.append("Subscriber T2")
            elif subscription_tier == "Tier 3":
                group_names.append("Subscriber T3")

        # Check if the user is a VIP
        if is_user_vip(messageAuthorID):
            group_names.append("VIP")

        # Assign user to groups
        for name in group_names:
            mysql_cursor.execute("SELECT * FROM 'groups' WHERE name=%s", (name,))
            group = mysql_cursor.fetchone()
            if group:
                try:
                    mysql_cursor.execute("INSERT OR REPLACE INTO everyone (username, group_name) VALUES (%s, %s)", (messageAuthor, name))
                    mysql_connection.commit()
                    bot_logger.info(f"User '{messageAuthor}' assigned to group '{name}' successfully.")
                except mysql.IntegrityError:
                    bot_logger.error(f"Failed to assign user '{messageAuthor}' to group '{name}'.")
            else:
                bot_logger.error(f"Group '{name}' does not exist.")

    @commands.command(name='commands', aliases=['cmds',])
    async def commands_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("commands",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
            
        is_mod = is_mod_or_broadcaster(ctx.author)
        if is_mod:
            # If the user is a mod, include both custom_commands and builtin_commands
            all_commands = list(mod_commands) + list(builtin_commands)
        else:
            # If the user is not a mod, only include builtin_commands
            all_commands = list(builtin_commands)

        # Construct the list of available commands to the user
        commands_list = ", ".join(sorted(f"!{command}" for command in all_commands))

        # Construct the response messages
        response_message = f"Available commands to you: {commands_list}"
        custom_response_message = f"Available Custom Commands: https://commands.botofthespecter.com/?user={CHANNEL_NAME}"

        # Sending the response messages to the chat
        await ctx.send(response_message)
        await ctx.send(custom_response_message)

    @commands.command(name='bot')
    async def bot_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("bot",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        chat_logger.info(f"{ctx.author.name} ran the Bot Command.")
        await ctx.send(f"This amazing bot is built by the one and the only gfaUnDead.")
    
    @commands.command(name='roadmap')
    async def roadmap_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("roadmap",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        await ctx.send("Here's the roadmap for the bot: https://trello.com/b/EPXSCmKc/specterbot")

    @commands.command(name='weather')
    async def weather_command(self, ctx, location: str = None) -> None:
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("weather",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        if location:
            if ' ' in location:
                await ctx.send(f"Please provide the location in the format: City,CountryCode (e.g. Sydney,AU)")
                return
            weather_info = get_weather(location)
        else:
            location = get_streamer_weather()
            if location:
                weather_info = get_weather(location)
            else:
                weather_info = "I'm sorry, something went wrong trying to get the current weather."
        await ctx.send(weather_info)

    @commands.command(name='time')
    async def time_command(self, ctx, timezone: str = None) -> None:
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("time",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        if timezone:
            tz = pytz.timezone(timezone)
            chat_logger.info(f"TZ: {tz} | Timezone: {timezone}")
            current_time = datetime.now(tz)
            time_format_date = current_time.strftime("%B %d, %Y")
            time_format_time = current_time.strftime("%I:%M %p")
            time_format_week = current_time.strftime("%A")
            time_format = f"For the timezone {timezone}, it is {time_format_week}, {time_format_date} and the time is: {time_format_time}"
        else:
            mysql_cursor.execute("SELECT timezone FROM profile")
            timezone = mysql_cursor.fetchone()[0]
            if timezone:
                tz = pytz.timezone(timezone)
                chat_logger.info(f"TZ: {tz} | Timezone: {timezone}")
                current_time = datetime.now(tz)
                time_format_date = current_time.strftime("%B %d, %Y")
                time_format_time = current_time.strftime("%I:%M %p")
                time_format_week = current_time.strftime("%A")
                time_format = f"It is {time_format_week}, {time_format_date} and the time is: {time_format_time}"
            else:
                ctx.send(f"Streamer timezone is not set.")
        await ctx.send(time_format)
    
    @commands.command(name='quote')
    async def quote_command(self, ctx, number: int = None):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("quote",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        if number is None:  # If no number is provided, get a random quote
            mysql_cursor.execute("SELECT quote FROM quotes ORDER BY RANDOM() LIMIT 1")
            quote = mysql_cursor.fetchone()
            if quote:
                await ctx.send("Random Quote: " + quote[0])
            else:
                await ctx.send("No quotes available.")
        else:  # If a number is provided, retrieve the quote by its ID
            mysql_cursor.execute("SELECT quote FROM quotes WHERE id = %s", (number,))
            quote = mysql_cursor.fetchone()
            if quote:
                await ctx.send(f"Quote {number}: " + quote[0])
            else:
                await ctx.send(f"No quote found with ID {number}.")

    @commands.command(name='quoteadd')
    async def quote_add_command(self, ctx, *, quote):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("quoteadd",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        mysql_cursor.execute("INSERT INTO quotes (quote) VALUES (%s)", (quote,))
        mysql_connection.commit()
        await ctx.send("Quote added successfully: " + quote)

    @commands.command(name='removequote')
    async def quote_remove_command(self, ctx, number: int = None):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("removequote",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        if number is None:
            ctx.send("Please specify the ID to remove.")
            return
        
        mysql_cursor.execute("DELETE FROM quotes WHERE ID = %s", (number,))
        mysql_connection.commit()
        await ctx.send(f"Quote {number} has been removed.")
    
    @commands.command(name='permit')
    async def permit_command(ctx, permit_user: str = None):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("permit",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        if is_mod_or_broadcaster(ctx.author):
            permit_user = permit_user.lstrip('@')
            if permit_user:
                permitted_users[permit_user] = time.time() + 30
                await ctx.send(f"{permit_user} is now permitted to post links for the next 30 seconds.")
            else:
                await ctx.send("Please specify a user to permit.")
        else:
            chat_logger.info(f"{ctx.author.name} tried to use the command, !permit, but couldn't as they are not a moderator.")
            await ctx.send("You must be a moderator or the broadcaster to use this command.")

    # Command to set stream title
    @commands.command(name='settitle')
    async def set_title_command(self, ctx, *, title: str = None) -> None:
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("settitle",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        if is_mod_or_broadcaster(ctx.author):
            if title is None:
                await ctx.send(f"Stream titles can not be blank. You must provide a title for the stream.")
                return

            # Update the stream title
            await trigger_twitch_title_update(title)
            twitch_logger.info(f'Setting stream title to: {title}')
            await ctx.send(f'Stream title updated to: {title}')
        else:
            await ctx.send(f"You must be a moderator or the broadcaster to use this command.")

    # Command to set stream game/category
    @commands.command(name='setgame')
    async def set_game_command(self, ctx, *, game: str = None) -> None:
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("setgame",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        if is_mod_or_broadcaster(ctx.author):
            if game is None:
                await ctx.send("You must provide a game for the stream.")
                return

            # Get the game ID
            try:
                game_id = await get_game_id(game)
                # Update the stream game/category
                await trigger_twitch_game_update(game_id)
                twitch_logger.info(f'Setting stream game to: {game}')
                await ctx.send(f'Stream game updated to: {game}')
            except GameNotFoundException as e:
                await ctx.send(str(e))
            except GameUpdateFailedException as e:
                await ctx.send(str(e))
            except Exception as e:
                await ctx.send(f'An error occurred: {str(e)}')
        else:
            await ctx.send("You must be a moderator or the broadcaster to use this command.")

    @commands.command(name='song')
    async def get_current_song_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("song",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        global stream_online
        if not stream_online:
            await ctx.send("Sorry, I can only get the current playing song while the stream is online.")
            return
        
        await ctx.send("Please stand by, checking what song is currently playing...")
        try:
            song_info = await get_song_info_command()
            await ctx.send(song_info)
            await delete_recorded_files()
        except Exception as e:
            chat_logger.error(f"An error occurred while getting current song: {e}")
            await ctx.send("Sorry, there was an error retrieving the current song.")

    @commands.command(name='timer')
    async def start_timer_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("timer",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        content = ctx.message.content.strip()
        try:
            _, minutes = content.split(' ')
            minutes = int(minutes)
        except ValueError:
            # Default to 5 minutes if the user didn't provide a valid value
            minutes = 5

        await ctx.send(f"Timer started for {minutes} minute(s) @{ctx.author.name}.")

        # Set a fixed interval of 30 seconds for countdown messages
        interval = 30

        # Wait for the first countdown message after the initial delay
        await asyncio.sleep(interval)

        for remaining_seconds in range((minutes * 60) - interval, 0, -interval):
            minutes_left = remaining_seconds // 60
            seconds_left = remaining_seconds % 60

            # Format the countdown message
            countdown_message = f"@{ctx.author.name}, timer has "

            if minutes_left > 0:
                countdown_message += f"{minutes_left} minute(s) "

            if seconds_left > 0:
                countdown_message += f"{seconds_left} second(s) left."
            else:
                countdown_message += "left."

            # Send countdown message
            await ctx.send(countdown_message)

            # Wait for the fixed interval of 30 seconds before sending the next message
            await asyncio.sleep(interval)

        await ctx.send(f"The {minutes} minute timer has ended @{ctx.author.name}!")

    @commands.command(name='hug')
    async def hug_command(self, ctx, *, mentioned_username: str = None):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("hug",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        if mentioned_username:
            target_user = mentioned_username.lstrip('@')

            # Increment hug count in the database
            mysql_cursor.execute('INSERT INTO hug_counts (username, hug_count) VALUES (%s, 1) ON CONFLICT(username) DO UPDATE SET hug_count = hug_count + 1', (target_user,))
            mysql_connection.commit()

            # Retrieve the updated count
            mysql_cursor.execute('SELECT hug_count FROM hug_counts WHERE username = %s', (target_user,))
            hug_count = mysql_cursor.fetchone()[0]

            # Send the message
            chat_logger.info(f"{target_user} has been hugged by {ctx.author.name}. They have been hugged: {hug_count}")
            await ctx.send(f"@{target_user} has been hugged by @{ctx.author.name}, they have been hugged {hug_count} times.")
        else:
            chat_logger.info(f"{ctx.author.name} tried to run the command without user mentioned.")
            await ctx.send("Usage: !hug @username")

    @commands.command(name='kiss')
    async def kiss_command(self, ctx, *, mentioned_username: str = None):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("kiss",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        if mentioned_username:
            target_user = mentioned_username.lstrip('@')

            # Increment kiss count in the database
            mysql_cursor.execute('INSERT INTO kiss_counts (username, kiss_count) VALUES (%s, 1) ON CONFLICT(username) DO UPDATE SET kiss_count = kiss_count + 1', (target_user,))
            mysql_connection.commit()

            # Retrieve the updated count
            mysql_cursor.execute('SELECT kiss_count FROM kiss_counts WHERE username = %s', (target_user,))
            kiss_count = mysql_cursor.fetchone()[0]

            # Send the message
            chat_logger.info(f"{target_user} has been kissed by {ctx.author.name}. They have been kissed: {kiss_count}")
            await ctx.send(f"@{target_user} has been kissed by @{ctx.author.name}, they have been kissed {kiss_count} times.")
        else:
            chat_logger.info(f"{ctx.author.name} tried to run the command without user mentioned.")
            await ctx.send("Usage: !kiss @username")

    @commands.command(name='ping')
    async def ping_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("ping",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        # Using subprocess to run the ping command
        result = subprocess.run(["ping", "-c", "1", "ping.botofthespecter.com"], stdout=subprocess.PIPE)
    
        # Decode the result from bytes to string and search for the time
        output = result.stdout.decode('utf-8')
        match = re.search(r"time=(\d+\.\d+) ms", output)
    
        if match:
            ping_time = match.group(1)
            bot_logger.info(f"Pong: {ping_time} ms")
            await ctx.send(f'Pong: {ping_time} ms')
        else:
            bot_logger.error(f"Error Pinging. {output}")
            await ctx.send(f'Error pinging')
    
    @commands.command(name='translate')
    async def translate_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("translate",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
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

            # Debugging: Log the message content
            chat_logger.info(f"Message content: {message}")

            # Detect the language of the input text
            detected_lang = translator.detect(message)
            source_lang = detected_lang.lang if detected_lang else None

            # Debugging: Log detected language
            chat_logger.info(f"Detected language: {source_lang}")

            if source_lang:
                source_lang_name = LANGUAGES.get(source_lang, "Unknown")
                chat_logger.info(f"Translator Detected Language as: {source_lang_name}.")

                # Translate the message to English
                translated_message = translator.translate(message, src=source_lang, dest='en').text
                chat_logger.info(f'Translated from "{message}" to "{translated_message}"')

                # Send the translated message along with the source language
                await ctx.send(f"Detected Language: {source_lang_name}. Translation: {translated_message}")
            else:
                await ctx.send("Unable to detect the source language.")
        except AttributeError as ae:
            chat_logger.error(f"AttributeError: {ae}")
            await ctx.send("An error occurred while detecting the language.")
        except Exception as e:
            chat_logger.error(f"Translating error: {e}")
            await ctx.send("An error occurred while translating the message.")

    @commands.command(name='cheerleader')
    async def cheerleader_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("cheerleader",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        headers = {
            'Client-ID': CLIENT_ID,
            'Authorization': f'Bearer {CHANNEL_AUTH}'
        }
        params = {
            'count': 1
        }
        response = requests.get('https://api.twitch.tv/helix/bits/leaderboard', headers=headers, params=params)
        if response.status_code == 200:
            data = response.json()
            if data['data']:
                top_cheerer = data['data'][0]
                score = "{:,}".format(top_cheerer['score'])
                await ctx.send(f"The current top cheerleader is {top_cheerer['user_name']} with {score} bits!")
            else:
                await ctx.send("There is no one currently in the leaderboard for bits, cheer to take this spot.")
        elif response.status_code == 401:
            await ctx.send("Sorry, something went wrong while reaching the Twitch API.")
        else:
            await ctx.send("Sorry, I couldn't fetch the leaderboard.")

    @commands.command(name='mybits')
    async def mybits_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("mybits",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        user_id = str(ctx.author.id)
        headers = {
            'Client-ID': CLIENT_ID,
            'Authorization': f'Bearer {CHANNEL_AUTH}'
        }
        params = {
            'user_id': user_id
        }
        response = requests.get('https://api.twitch.tv/helix/bits/leaderboard', headers=headers, params=params)
        if response.status_code == 200:
            data = response.json()
            if data['data']:
                user_bits = data['data'][0]
                bits = "{:,}".format(user_bits['score'])
                await ctx.send(f"You have given {bits} bits in total.")
            else:
                await ctx.send("You haven't given any bits yet.")
        elif response.status_code == 401:
            await ctx.send("Sorry, something went wrong while reaching the Twitch API.")
        else:
            await ctx.send("Sorry, I couldn't fetch your bits information.")

    @commands.command(name='lurk')
    async def lurk_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("lurk",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        try:
            user_id = str(ctx.author.id)
            now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

            if ctx.author.name.lower() == CHANNEL_NAME.lower():
                await ctx.send(f"You cannot lurk in your own channel, Streamer.")
                chat_logger.info(f"{ctx.author.name} tried to lurk in their own channel.")
                return

            # Check if the user is already in the lurk table
            mysql_cursor.execute('SELECT start_time FROM lurk_times WHERE user_id = %s', (user_id,))
            result = mysql_cursor.fetchone()

            if result:
                # User was lurking before
                previous_start_time = datetime.strptime(result[0], "%Y-%m-%d %H:%M:%S")
                lurk_duration = now - previous_start_time

                # Calculate the duration
                days, seconds = divmod(lurk_duration.total_seconds(), 86400)
                months, days = divmod(days, 30)
                hours, remainder = divmod(seconds, 3600)
                minutes, seconds = divmod(remainder, 60)

                # Create time string
                periods = [("months", int(months)), ("days", int(days)), ("hours", int(hours)), ("minutes", int(minutes)), ("seconds", int(seconds))]
                time_string = ", ".join(f"{value} {name}" for name, value in periods if value)

                # Inform the user of their previous lurk time
                await ctx.send(f"Continuing to lurk, {ctx.author.name}? No problem, you've been lurking for {time_string}. I've reset your lurk time.")
                chat_logger.info(f"{ctx.author.name} refreshed their lurk time after {time_string}.")
            else:
                # User is not in the lurk table
                await ctx.send(f"Thanks for lurking, {ctx.author.name}! See you soon.")
                chat_logger.info(f"{ctx.author.name} is now lurking.")

            # Update the start time in the database
            formatted_datetime = now.strftime("%Y-%m-%d %H:%M:%S")
            mysql_cursor.execute('INSERT INTO lurk_times (user_id, start_time) VALUES (%s, %s) ON DUPLICATE KEY UPDATE start_time = %s', (user_id, formatted_datetime, formatted_datetime))
            mysql_connection.commit()
        except Exception as e:
            chat_logger.error(f"Error in lurk_command: {e}")
            await ctx.send(f"Oops, something went wrong while trying to lurk.")

    @commands.command(name='lurking')
    async def lurking_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("lurking",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        try:
            user_id = str(ctx.author.id)

            if ctx.author.name.lower() == CHANNEL_NAME.lower():
                await ctx.send(f"Streamer, you're always present!")
                chat_logger.info(f"{ctx.author.name} tried to check lurk time in their own channel.")
                return

            mysql_cursor.execute('SELECT start_time FROM lurk_times WHERE user_id = %s', (user_id,))
            result = mysql_cursor.fetchone()

            if result:
                start_time = datetime.strptime(result[0], "%Y-%m-%d %H:%M:%S")
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

    @commands.command(name='lurklead')
    async def lurklead_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("lurklead",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        try:
            mysql_cursor.execute('SELECT user_id, start_time FROM lurk_times')
            lurkers = mysql_cursor.fetchall()

            longest_lurk = None
            longest_lurk_user_id = None
            now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

            for user_id, start_time in lurkers:
                start_time = datetime.strptime(start_time, "%Y-%m-%d %H:%M:%S")
                lurk_duration = now - start_time

                if longest_lurk is None or lurk_duration > longest_lurk:
                    longest_lurk = lurk_duration
                    longest_lurk_user_id = user_id

            if longest_lurk_user_id:
                display_name = await get_display_name(longest_lurk_user_id)

                if display_name:
                    # Calculate the duration
                    days, seconds = divmod(longest_lurk.total_seconds(), 86400)
                    months, days = divmod(days, 30)
                    hours, remainder = divmod(seconds, 3600)
                    minutes, seconds = divmod(remainder, 60)

                    # Build the time string
                    periods = [("months", int(months)), ("days", int(days)), ("hours", int(hours)), ("minutes", int(minutes)), ("seconds", int(seconds))]
                    time_string = ", ".join(f"{value} {name}" for name, value in periods if value)

                    # Send the message
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

    @commands.command(name='unlurk', aliases=('back',))
    async def unlurk_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("unlurk",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        try:
            user_id = str(ctx.author.id)
            if ctx.author.name.lower() == CHANNEL_NAME.lower():
                await ctx.send(f"Streamer, you've been here all along!")
                chat_logger.info(f"{ctx.author.name} tried to unlurk in their own channel.")
                return

            mysql_cursor.execute('SELECT start_time FROM lurk_times WHERE user_id = %s', (user_id,))
            result = mysql_cursor.fetchone()

            if result:
                start_time = datetime.strptime(result[0], "%Y-%m-%d %H:%M:%S")
                elapsed_time = datetime.now() - start_time

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
                mysql_cursor.execute('DELETE FROM lurk_times WHERE user_id = %s', (user_id,))
                mysql_connection.commit()
            else:
                await ctx.send(f"{ctx.author.name} has returned from lurking, welcome back!")
        except Exception as e:
            chat_logger.error(f"Error in unlurk_command: {e}")
            await ctx.send(f"Oops, something went wrong with the unlurk command.")

    @commands.command(name='clip')
    async def clip_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("clip",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        global stream_online
        try:
            if not stream_online:
                await ctx.send("Sorry, I can only create clips while the stream is online.")
                return

            # Headers & Params for TwitchAPI
            headers = {
                "Client-ID": TWITCH_API_CLIENT_ID,
                "Authorization": f"Bearer {CHANNEL_AUTH}"
            }
            params = {
                "broadcaster_id": CHANNEL_ID
            }
            clip_response = requests.post('https://api.twitch.tv/helix/clips', headers=headers, params=params)
            if clip_response.status_code == 202:
                clip_data = clip_response.json()
                clip_id = clip_data['data'][0]['id']
                clip_url = f"http://clips.twitch.tv/{clip_id}"
                await ctx.send(f"{ctx.author.name} created a clip: {clip_url}")

                # Create a stream marker
                marker_description = f"Clip created by {ctx.author.name}"
                marker_payload = {
                    "user_id": CHANNEL_ID,
                    "description": marker_description
                }
                marker_headers = {
                    "Client-ID": TWITCH_API_CLIENT_ID,
                    "Authorization": f"Bearer {CHANNEL_AUTH}",
                    "Content-Type": "application/json"
                }
                marker_response = requests.post('https://api.twitch.tv/helix/streams/markers', headers=marker_headers, json=marker_payload)
                if marker_response.status_code == 200:
                    marker_data = marker_response.json()
                    marker_created_at = marker_data['data'][0]['created_at']
                    twitch_logger.info(f"A stream marker was created at {marker_created_at} with description: {marker_description}.")
                else:
                    twitch_logger.info("Failed to create a stream marker for the clip.")

            else:
                await ctx.send(f"Failed to create clip.")
                twitch_logger.error(f"Clip Error Code: {clip_response.status_code}")
        except requests.exceptions.RequestException as e:
            twitch_logger.error(f"Error making clip: {e}")
            await ctx.send("An error occurred while making the request. Please try again later.")

    @commands.command(name='marker')
    async def marker_command(self, ctx, *, description: str):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("marker",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        if is_mod_or_broadcaster(ctx.author):
            if description:
                marker_description = description
            else:
                marker_description = f"Marker made by {ctx.author.name}"
            try:
                marker_payload = {
                    "user_id": CHANNEL_ID,
                    "description": marker_description
                }
                marker_headers = {
                    "Client-ID": TWITCH_API_CLIENT_ID,
                    "Authorization": f"Bearer {CHANNEL_AUTH}",
                    "Content-Type": "application/json"
                }
                marker_response = requests.post('https://api.twitch.tv/helix/streams/markers', headers=marker_headers, json=marker_payload)
                if marker_response.status_code == 200:
                    await ctx.send(f'A stream marker was created with the description: "{marker_description}".')
                else:
                    await ctx.send("Failed to create a stream marker.")
            except requests.exceptions.RequestException as e:
                twitch_logger.error(f"Error creating stream marker: {e}")
        else:
            await ctx.send(f"You must be a moderator or the broadcaster to use this command.")

    @commands.command(name='subscription', aliases=['mysub'])
    async def subscription_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("subscription",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        try:
            # Headers & Params for Twitch API
            user_id = ctx.author.id
            headers = {
                "Client-ID": TWITCH_API_CLIENT_ID,
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
            subscription_response = requests.get('https://api.twitch.tv/helix/subscriptions', headers=headers, params=params)
            if subscription_response.status_code == 200:
                subscription_data = subscription_response.json()
                subscriptions = subscription_data.get('data', [])

                if subscriptions:
                    # Iterate over each subscription
                    for subscription in subscriptions:
                        user_name = subscription['user_name']
                        tier = subscription['tier']
                        is_gift = subscription['is_gift']
                        gifter_name = subscription['gifter_name'] if is_gift else None
                        tier_name = tier_mapping.get(tier, tier)

                        # Prepare message based on subscription status
                        if is_gift:
                            await ctx.send(f"{user_name}, your gift subscription from {gifter_name} is {tier_name}.")
                        else:
                            await ctx.send(f"{user_name}, you are currently subscribed at {tier_name}.")
                else:
                    # If no subscriptions found for the provided user ID
                    await ctx.send(f"You are currently not subscribed to {CHANNEL_NAME}, you can subscribe here: https://subs.twitch.tv/{CHANNEL_NAME}")
            else:
                await ctx.send(f"Failed to retrieve subscription information. Please try again later.")
                twitch_logger.error(f"Failed to retrieve subscription information. Status code: {subscription_response.status_code}")

        except requests.exceptions.RequestException as e:
            twitch_logger.error(f"Error retrieving subscription information: {e}")
            await ctx.send("An error occurred while making the request. Please try again later.")

    @commands.command(name='uptime')
    async def uptime_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("uptime",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
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
                            started_at = datetime.strptime(started_at_str.replace('Z', '+00:00'), "%Y-%m-%d %H:%M:%S%z")
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
    
    @commands.command(name='typo')
    async def typo_command(self, ctx, *, mentioned_username: str = None):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("typo",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        chat_logger.info("Typo Command ran.")
        # Check if the broadcaster is running the command
        if ctx.author.name.lower() == CHANNEL_NAME.lower() or (mentioned_username and mentioned_username.lower() == CHANNEL_NAME.lower()):
            await ctx.send("Dear Streamer, you can never have a typo in your own channel.")
            return

        # Determine the target user: mentioned user or the command caller
        target_user = mentioned_username.lower().lstrip('@') if mentioned_username else ctx.author.name.lower()

        # Increment typo count in the database
        mysql_cursor.execute('INSERT INTO user_typos (username, typo_count) VALUES (%s, 1) ON CONFLICT(username) DO UPDATE SET typo_count = typo_count + 1', (target_user,))
        mysql_connection.commit()

        # Retrieve the updated count
        mysql_cursor.execute('SELECT typo_count FROM user_typos WHERE username = %s', (target_user,))
        typo_count = mysql_cursor.fetchone()[0]

        # Send the message
        chat_logger.info(f"{target_user} has made a new typo in chat, their count is now at {typo_count}.")
        await ctx.send(f"Congratulations {target_user}, you've made a typo! You've made a typo in chat {typo_count} times.")
    
    @commands.command(name='typos', aliases=('typocount',))
    async def typos_command(self, ctx, *, mentioned_username: str = None):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("typos",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        chat_logger.info("Typos Command ran.")
        # Check if the broadcaster is running the command
        if ctx.author.name.lower() == CHANNEL_NAME.lower():
            await ctx.send(f"Dear Streamer, you can never have a typo in your own channel.")
            return

        # Determine the target user: mentioned user or the command caller
        mentioned_username_lower = mentioned_username.lower() if mentioned_username else ctx.author.name.lower()
        target_user = mentioned_username_lower.lstrip('@')

        # Retrieve the typo count
        mysql_cursor.execute('SELECT typo_count FROM user_typos WHERE username = %s', (target_user,))
        result = mysql_cursor.fetchone()
        typo_count = result[0] if result else 0

        # Send the message
        chat_logger.info(f"{target_user} has made {typo_count} typos in chat.")
        await ctx.send(f"{target_user} has made {typo_count} typos in chat.")

    @commands.command(name='edittypos', aliases=('edittypo',))
    async def edit_typo_command(self, ctx, mentioned_username: str = None, new_count: int = None):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("edittypos",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        if is_mod_or_broadcaster(ctx.author):
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
                if new_count is not None and new_count < 0:
                    chat_logger.error(f"Typo count for {target_user} tried to be {new_count}.")
                    await ctx.send(f"Typo count cannot be negative.")
                    return

                # Check if the user exists in the database
                mysql_cursor.execute('SELECT typo_count FROM user_typos WHERE username = %s', (target_user,))
                result = mysql_cursor.fetchone()
    
                if result is not None:
                    # Update typo count in the database
                    mysql_cursor.execute('UPDATE user_typos SET typo_count = %s WHERE username = %s', (new_count, target_user))
                    mysql_connection.commit()
                    chat_logger.info(f"Typo count for {target_user} has been updated to {new_count}.")
                    await ctx.send(f"Typo count for {target_user} has been updated to {new_count}.")
                else:
                    # If user does not exist, send an error message and add the user with the given typo count
                    await ctx.send(f"No record for {target_user}. Adding them with the typo count.")
                    mysql_cursor.execute('INSERT INTO user_typos (username, typo_count) VALUES (%s, %s)', (target_user, new_count))
                    mysql_connection.commit()
                    chat_logger.info(f"Typo count for {target_user} has been set to {new_count}.")
                    await ctx.send(f"Typo count for {target_user} has been set to {new_count}.")
            except Exception as e:
                chat_logger.error(f"Error in edit_typo_command: {e}")
                await ctx.send(f"An error occurred while trying to edit typos. {e}")
        else:
            await ctx.send(f"You must be a moderator or the broadcaster to use this command.")

    @commands.command(name='removetypos', aliases=('removetypo',))
    async def remove_typos_command(self, ctx, mentioned_username: str = None, decrease_amount: int = 1):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("removetypos",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        try:
            if is_mod_or_broadcaster(ctx.author):
                # Ensure a username is mentioned
                if not mentioned_username is None:
                    chat_logger.error("Command missing username parameter.")
                    await ctx.send(f"Usage: !remotetypos @username")
                    return
            
                # Determine the target user: mentioned user or the command caller
                mentioned_username_lower = mentioned_username.lower() if mentioned_username else ctx.author.name.lower()
                target_user = mentioned_username_lower.lstrip('@')
                chat_logger.info(f"Remove Typos Command ran with params")
                
                # Validate decrease_amount is non-negative
                if decrease_amount < 0:
                    chat_logger.error(f"Typo count for {target_user} tried to be {new_count}.")
                    await ctx.send(f"Remove amount cannot be negative.")
                    return

                # Check if the user exists in the database
                mysql_cursor.execute('SELECT typo_count FROM user_typos WHERE username = %s', (target_user,))
                result = mysql_cursor.fetchone()

                if result:
                    current_count = result[0]
                    new_count = max(0, current_count - decrease_amount)  # Ensure count doesn't go below 0
                    mysql_cursor.execute('UPDATE user_typos SET typo_count = %s WHERE username = %s', (new_count, target_user))
                    mysql_connection.commit()
                    await ctx.send(f"Typo count for {target_user} decreased by {decrease_amount}. New count: {new_count}.")
                else:
                    await ctx.send(f"No typo record found for {target_user}.")
            else:
                await ctx.send(f"You must be a moderator or the broadcaster to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in remove_typos_command: {e}")
            await ctx.send(f"An error occurred while trying to remove typos.")

    @commands.command(name='steam')
    async def steam_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("steam",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        global current_game

        async with aiohttp.ClientSession() as session:
            response = await session.get("http://api.steampowered.com/ISteamApps/GetAppList/v2")
            if response.status == 200:
                data = await response.json()
                steam_app_list = {app['name'].lower(): app['appid'] for app in data['applist']['apps']}
            else:
                await ctx.send("Failed to fetch Steam games list.")
                return

        # Normalize the game name to lowercase to improve matching chances
        game_name_lower = current_game.lower()

        # First try with "The" at the beginning
        if game_name_lower.startswith('The '):
            game_name_without_the = game_name_lower[4:]
            if game_name_without_the in steam_app_list:
                game_id = steam_app_list[game_name_without_the]
                store_url = f"https://store.steampowered.com/app/{game_id}"
                await ctx.send(f"{current_game} is available on Steam, you can get it here: {store_url}")
                return

        # If the game with "The" at the beginning is not found, try without it
        if game_name_lower in steam_app_list:
            game_id = steam_app_list[game_name_lower]
            store_url = f"https://store.steampowered.com/app/{game_id}"
            await ctx.send(f"{current_game} is available on Steam, you can get it here: {store_url}")
        else:
            await ctx.send("This game is not available on Steam.")

    @commands.command(name='deaths')
    async def deaths_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("deaths",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        try:
            global current_game
            chat_logger.info("Deaths command ran.")

            # Retrieve the game-specific death count
            mysql_cursor.execute('SELECT death_count FROM game_deaths WHERE game_name = %s', (current_game,))
            game_death_count_result = mysql_cursor.fetchone()
            game_death_count = game_death_count_result[0] if game_death_count_result else 0

            # Retrieve the total death count
            mysql_cursor.execute('SELECT death_count FROM total_deaths')
            total_death_count_result = mysql_cursor.fetchone()
            total_death_count = total_death_count_result[0] if total_death_count_result else 0

            chat_logger.info(f"{ctx.author.name} has reviewed the death count for {current_game}. Total deaths are: {total_death_count}")
            await ctx.send(f"We have died {game_death_count} times in {current_game}, with a total of {total_death_count} deaths in all games.")
        except Exception as e:
            await ctx.send(f"An error occurred while executing the command. {e}")
            chat_logger.error(f"Error in deaths_command: {e}")

    @commands.command(name='deathadd', aliases=['death+',])
    async def deathadd_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("deathadd",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        if is_mod_or_broadcaster(ctx.author):
            global current_game
            try:
                chat_logger.info("Death Add Command ran.")

                # Ensure there is exactly one row in total_deaths
                mysql_cursor.execute("SELECT COUNT(*) FROM total_deaths")
                if mysql_cursor.fetchone()[0] == 0:
                    mysql_cursor.execute("INSERT INTO total_deaths (death_count) VALUES (0)")
                    mysql_connection.commit()

                # Increment game-specific death count & total death count
                mysql_cursor.execute('INSERT INTO game_deaths (game_name, death_count) VALUES (%s, 1) ON CONFLICT(game_name) DO UPDATE SET death_count = death_count + 1 WHERE game_name = %s', (current_game, current_game))
                mysql_cursor.execute('UPDATE total_deaths SET death_count = death_count + 1')
                mysql_connection.commit()

                # Retrieve updated counts
                mysql_cursor.execute('SELECT death_count FROM game_deaths WHERE game_name = %s', (current_game,))
                game_death_count_result = mysql_cursor.fetchone()
                game_death_count = game_death_count_result[0] if game_death_count_result else 0

                mysql_cursor.execute('SELECT death_count FROM total_deaths')
                total_death_count_result = mysql_cursor.fetchone()
                total_death_count = total_death_count_result[0] if total_death_count_result else 0

                chat_logger.info(f"{current_game} now has {game_death_count} deaths.")
                chat_logger.info(f"Total Death count has been updated to: {total_death_count}")
                await ctx.send(f"We have died {game_death_count} times in {current_game}, with a total of {total_death_count} deaths in all games.")
            except Exception as e:
                await ctx.send(f"An error occurred while executing the command. {e}")
                chat_logger.error(f"Error in deathadd_command: {e}")
        else:
            chat_logger.info(f"{ctx.author.name} tried to use the command, death add, but couldn't has they are not a moderator.")
            await ctx.send("You must be a moderator or the broadcaster to use this command.")

    @commands.command(name='deathremove', aliases=['death-',])
    async def deathremove_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("deathremove",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        if is_mod_or_broadcaster(ctx.author):
            global current_game
            try:
                chat_logger.info("Death Remove Command Ran")

                # Decrement game-specific death count & total death count (ensure it doesn't go below 0)
                mysql_cursor.execute('UPDATE game_deaths SET death_count = CASE WHEN death_count > 0 THEN death_count - 1 ELSE 0 END WHERE game_name = %s', (current_game,))
                mysql_cursor.execute('UPDATE total_deaths SET death_count = CASE WHEN death_count > 0 THEN death_count - 1 ELSE 0 END')
                mysql_connection.commit()

                # Retrieve updated counts
                mysql_cursor.execute('SELECT death_count FROM game_deaths WHERE game_name = %s', (current_game,))
                game_death_count_result = mysql_cursor.fetchone()
                game_death_count = game_death_count_result[0] if game_death_count_result else 0

                mysql_cursor.execute('SELECT death_count FROM total_deaths')
                total_death_count_result = mysql_cursor.fetchone()
                total_death_count = total_death_count_result[0] if total_death_count_result else 0

                # Send the message
                chat_logger.info(f"{current_game} death has been removed, we now have {game_death_count} deaths.")
                chat_logger.info(f"Total Death count has been updated to: {total_death_count} to reflect the removal.")
                await ctx.send(f"Death removed from {current_game}, count is now {game_death_count}. Total deaths in all games: {total_death_count}.")
            except Exception as e:
                await ctx.send(f"An error occurred while executing the command. {e}")
                chat_logger.error(f"Error in deaths_command: {e}")
        else:
            chat_logger.info(f"{ctx.author.name} tried to use the command, death remove, but couldn't has they are not a moderator.")
            await ctx.send("You must be a moderator or the broadcaster to use this command.")
    
    @commands.command(name='game')
    async def game_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("game",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        global current_game
        if current_game is not None:
            await ctx.send(f"The current game we're playing is: {current_game}")
        else:
            await ctx.send("We're not currently streaming any specific game category.")

    @commands.command(name='followage')
    async def followage_command(self, ctx, *, mentioned_username: str = None):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("followage",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        target_user = mentioned_username.lstrip('@') if mentioned_username else ctx.author.name
        headers = {
            'Client-ID': CLIENT_ID,
            'Authorization': f'Bearer {CHANNEL_AUTH}'
        }
        if mentioned_username:
            user_info = await self.fetch_users(names=[target_user])
            if user_info:
                mentioned_user_id = user_info[0].id
                params = {
                    'user_id': CHANNEL_ID,
                    'broadcaster_id': mentioned_user_id
                }
        else:
            params = {
                'user_id': CHANNEL_ID,
                'broadcaster_id': ctx.author.id
            }
        try:
            async with aiohttp.ClientSession() as session:
                async with session.get('https://api.twitch.tv/helix/channels/followed', headers=headers, params=params) as response:
                    if response.status == 200:
                        data = await response.json()
                        followage_text = None

                        # Iterate over followed channels to find the target user
                        for followed_channel in data['data']:
                            if followed_channel['broadcaster_login'] == target_user.lower():
                                followed_at_str = followed_channel['followed_at']
                                followed_at = datetime.strptime(followed_at_str.replace('Z', '+00:00'), "%Y-%m-%d %H:%M:%S%z")
                                followage = datetime.now(timezone.utc) - followed_at
                                years = followage.days // 365
                                remaining_days = followage.days % 365
                                months = remaining_days // 30
                                remaining_days %= 30
                                days = remaining_days

                                if years > 0:
                                    years_text = f"{years} {'year' if years == 1 else 'years'}"
                                else:
                                    years_text = ""

                                if months > 0:
                                    months_text = f"{months} {'month' if months == 1 else 'months'}"
                                else:
                                    months_text = ""

                                if days > 0:
                                    days_text = f"{days} {'day' if days == 1 else 'days'}"
                                else:
                                    days_text = ""

                                # Join the non-empty parts with commas
                                parts = [part for part in [years_text, months_text, days_text] if part]
                                followage_text = ", ".join(parts)
                                break

                        if followage_text:
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

    @commands.command(name='schedule')
    async def schedule_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("schedule",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        mysql_cursor.execute("SELECT timezone FROM profile")
        timezone_row = mysql_cursor.fetchone()
        if timezone_row:
            timezone = timezone_row[0]
        else:
            timezone = 'UTC'
        tz = pytz.timezone(timezone)
        current_time = datetime.now(tz)
        headers = {
            'Client-ID': CLIENT_ID,
            'Authorization': f'Bearer {CHANNEL_AUTH}'
        }
        params = {
            'broadcaster_id': CHANNEL_ID,
            'first': '2'
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
                        for segment in segments:
                            start_time_utc = datetime.strptime(segment['start_time'][:-1], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=pytz.utc)
                            start_time = start_time_utc.astimezone(tz)
                            if start_time > current_time:
                                next_stream = segment
                                break  # Exit the loop after finding the first upcoming stream

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
                            await ctx.send(f"There are no upcoming streams in the next two days.")
                    else:
                        await ctx.send(f"Something went wrong while trying to get the schedule from Twitch.")
        except Exception as e:
            chat_logger.error(f"Error retrieving schedule: {e}")
            await ctx.send(f"Oops, something went wrong while trying to check the schedule.")

    @commands.command(name='checkupdate')
    async def check_update_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("checkupdate",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        if is_mod_or_broadcaster(ctx.author):
            REMOTE_VERSION_URL = "https://api.botofthespecter.com/beta_version_control.txt"
            async with aiohttp.ClientSession() as session:
                async with session.get(REMOTE_VERSION_URL) as response:
                    if response.status == 200:
                        remote_version = await response.text()
                        remote_version = remote_version.strip()
                        if remote_version != VERSION:
                            remote_major, remote_minor, remote_patch = map(int, remote_version.split('.'))
                            local_major, local_minor, local_patch = map(int, VERSION.split('.'))
                            if remote_major > local_major or \
                                    (remote_major == local_major and remote_minor > local_minor) or \
                                    (remote_major == local_major and remote_minor == local_minor and remote_patch > local_patch):
                                message = f"A new update (V{remote_version}) is available. Please head over to the website and restart the bot. You are currently running V{VERSION}."
                            elif remote_patch > local_patch:
                                message = f"A new hotfix update (V{remote_version}) is available. Please head over to the website and restart the bot. You are currently running V{VERSION}."
                            else:
                                # If versions are equal or local version is ahead
                                message = f"There is no update pending. You are currently running V{VERSION}."
                            bot_logger.info(f"Bot update available. (V{remote_version})")
                            await ctx.send(message)
                        else:
                            message = f"There is no update pending. You are currently running V{VERSION}."
                            bot_logger.info(f"{message}")
                            await ctx.send(message)
        else:
            chat_logger.info(f"{ctx.author.name} tried to use the command, !checkupdate, but couldn't as they are not a moderator.")
            await ctx.send("You must be a moderator or the broadcaster to use this command.")
    
    @commands.command(name='shoutout', aliases=('so',))
    async def shoutout_command(self, ctx, user_to_shoutout: str = None):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("shoutout",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        if is_mod_or_broadcaster(ctx.author):
            chat_logger.info(f"Shoutout command running from {ctx.author.name}")
            if user_to_shoutout is None:
                chat_logger.error(f"Shoutout command missing username parameter.")
                await ctx.send(f"Usage: !so @username")
                return
            try:
                chat_logger.info(f"Shoutout command trying to run.")
                # Remove @ from the username if present
                user_to_shoutout = user_to_shoutout.lstrip('@')

                # Check if the user exists on Twitch
                if not await is_valid_twitch_user(user_to_shoutout):
                    chat_logger.error(f"User {user_to_shoutout} does not exist on Twitch. Failed to give shoutout")
                    await ctx.send(f"The user @{user_to_shoutout} does not exist on Twitch.")
                    return

                chat_logger.info(f"Shoutout for {user_to_shoutout} ran by {ctx.author.name}")
                user_info = await self.fetch_users(names=[user_to_shoutout])
                mentioned_user_id = user_info[0].id
                game = await get_latest_stream_game(mentioned_user_id, user_to_shoutout)

                if not game:
                    shoutout_message = (
                        f"Hey, huge shoutout to @{user_to_shoutout}! "
                        f"You should go give them a follow over at "
                        f"https://www.twitch.tv/{user_to_shoutout}"
                    )
                    chat_logger.info(shoutout_message)
                    await ctx.send(shoutout_message)
                else:
                    shoutout_message = (
                        f"Hey, huge shoutout to @{user_to_shoutout}! "
                        f"You should go give them a follow over at "
                        f"https://www.twitch.tv/{user_to_shoutout} where they were playing: {game}"
                    )
                    chat_logger.info(shoutout_message)
                    await ctx.send(shoutout_message)

                # Trigger the Twitch shoutout
                await trigger_twitch_shoutout(shoutout_queue, user_to_shoutout, mentioned_user_id)

            except Exception as e:
                chat_logger.error(f"Error in shoutout_command: {e}")
        else:
            chat_logger.info(f"{ctx.author.name} tried to use the command, !shoutout, but couldn't as they are not a moderator.")
            await ctx.send("You must be a moderator or the broadcaster to use this command.")

    @commands.command(name='addcommand')
    async def add_command_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("addcommand",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        # Check if the user is a moderator or the broadcaster
        if is_mod_or_broadcaster(ctx.author):
            # Parse the command and response from the message
            try:
                command, response = ctx.message.content.strip().split(' ', 1)[1].split(' ', 1)
            except ValueError:
                await ctx.send(f"Invalid command format. Use: !addcommand [command] [response]")
                return

            # Insert the command and response into the database
            mysql_cursor.execute('INSERT OR REPLACE INTO custom_commands (command, response) VALUES (%s, %s)', (command, response))
            mysql_connection.commit()
            chat_logger.info(f"{ctx.author.name} has added the command !{command} with the response: {response}")
            await ctx.send(f'Custom command added: !{command}')
        else:
            await ctx.send(f"You must be a moderator or the broadcaster to use this command.")

    @commands.command(name='removecommand')
    async def remove_command_command(self, ctx):
        mysql_cursor.execute("SELECT status FROM custom_commands WHERE command=%s", ("removecommand",))
        result = mysql_cursor.fetchone()
        if result:
            status = result[0]
            if status == 'Disabled':
                return
        # Check if the user is a moderator or the broadcaster
        if is_mod_or_broadcaster(ctx.author):
            try:
                command = ctx.message.content.strip().split(' ')[1]
            except IndexError:
                await ctx.send(f"Invalid command format. Use: !removecommand [command]")
                return

            # Delete the command from the database
            mysql_cursor.execute('DELETE FROM custom_commands WHERE command = %s', (command,))
            mysql_connection.commit()
            chat_logger.info(f"{ctx.author.name} has removed {command}")
            await ctx.send(f'Custom command removed: !{command}')
        else:
            await ctx.send(f"You must be a moderator or the broadcaster to use this command.")

# Functions for all the commands
##
# Function  to check if the user is a real user on Twitch
async def is_valid_twitch_user(user_to_shoutout):
    # Twitch API endpoint to check if a user exists
    url = f"https://api.twitch.tv/helix/users?login={user_to_shoutout}"

    # Headers including the Twitch Client ID
    headers = {
        "Client-ID": TWITCH_API_CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}"
    }

    # Send a GET request to the Twitch API
    response = requests.get(url, headers=headers)

    # Check if the response is successful and if the user exists
    if response.status_code == 200:
        data = response.json()
        if data['data']:
            return True  # User exists
        else:
            return False  # User does not exist
    else:
        # If there's an error with the request or response, return False
        return False

# Function to get the diplay name of the user from their user id
async def get_display_name(user_id):
    # Replace with actual method to get display name from Twitch API
    url = f"https://api.twitch.tv/helix/users?id={user_id}"
    headers = {
        "Client-ID": TWITCH_API_CLIENT_ID,
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
def is_mod_or_broadcaster(user):
    # Check if the user is the bot owner
    if user.name == 'gfaundead':
        twitch_logger.info(f"User is gfaUnDead. (Bot owner)")
        return True

    # Check if the user is the broadcaster
    elif user.name == CHANNEL_NAME:
        twitch_logger.info(f"User {user.name} is the Broadcaster")
        return True

    # Check if the user is a moderator
    elif is_user_mod(user):
        return True

    # If none of the above, the user is neither the bot owner, broadcaster, nor a moderator
    else:
        twitch_logger.info(f"User {user.name} does not have required permissions.")
        return False

# Function to check if a user is a MOD of the channel using the Twitch API
def is_user_mod(user):
    # Send request to Twitch API to check if user is a moderator
    headers = {
        "Authorization": f"Bearer {CHANNEL_AUTH}",
        "Client-ID": TWITCH_API_CLIENT_ID,
    }
    params = {
        "broadcaster_id": f"{CHANNEL_ID}",
        "user_name": user.name
    }

    response = requests.get("https://api.twitch.tv/helix/moderation/moderators", headers=headers, params=params)
    if response.status_code == 200:
        moderators = response.json().get("data", [])
        for mod in moderators:
            if mod["user_name"].lower() == user.name.lower():
                twitch_logger.info(f"User {user.name} is a Moderator")
                return True
            return False
    return False

# Function to check if a user is a VIP of the channel using the Twitch API
def is_user_vip(user_trigger_id):
    headers = {
        'Client-ID': TWITCH_API_CLIENT_ID,
        'Authorization': f'Bearer {CHANNEL_AUTH}',
    }
    params = {
        'broadcaster_id': CHANNEL_ID
    }
    try:
        response = requests.get('https://api.twitch.tv/helix/channels/vips', headers=headers, params=params)
        if response.status_code == 200:
            vips = response.json().get("data", [])
            for vip in vips:
                if vip["user_id"] == user_trigger_id:
                    user_name = vip["user_name"]
                    twitch_logger.info(f"User {user_name} is a VIP Member")
                    return True
                twitch_logger.info(f"User {user_name} is not a VIP Member")
                return False
        return False
    except requests.RequestException as e:
        twitch_logger.error(f"Failed to retrieve VIP status: {e}")
    return False

# Function to check if a user is a subscriber of the channel
def is_user_subscribed(user_id):
    headers = {
        "Client-ID": TWITCH_API_CLIENT_ID,
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
    subscription_response = requests.get('https://api.twitch.tv/helix/subscriptions', headers=headers, params=params)
    if subscription_response.status_code == 200:
        subscription_data = subscription_response.json()
        subscriptions = subscription_data.get('data', [])
        if subscriptions:
            # Iterate over each subscription
            for subscription in subscriptions:
                tier = subscription['tier']
                tier_name = tier_mapping.get(tier, tier)
                return tier_name
    return None

# Function to check if a user is a MOD of the channel using the Twitch API
def is_user_moderator(user_trigger_id):
    # Send request to Twitch API to check if user is a moderator
    headers = {
        "Authorization": f"Bearer {CHANNEL_AUTH}",
        "Client-ID": TWITCH_API_CLIENT_ID,
    }
    params = {
        "broadcaster_id": f"{CHANNEL_ID}",
        "user_id": user_trigger_id
    }

    response = requests.get("https://api.twitch.tv/helix/moderation/moderators", headers=headers, params=params)
    if response.status_code == 200:
        moderators = response.json().get("data", [])
        for mod in moderators:
            if mod["user_id"] == user_trigger_id:
                user_name = mod["user_name"]
                twitch_logger.info(f"User {user_name} is a Moderator")
                return True
            return False
    return False

# Function to add user to the table of known users
async def user_is_seen(username):
    try:
        mysql_cursor.execute('INSERT INTO seen_users (username) VALUES (%s)', (username,))
        mysql_connection.commit()
    except Exception as e:
        bot_logger.error(f"Error occurred while adding user '{username}' to seen_users table: {e}")

# Function to fetch custom API responses
def fetch_api_response(url):
    try:
        response = requests.get(url)
        if response.status_code == 200:
            return response.text
        else:
            return f"Status Error: {response.status_code}"
    except Exception as e:
        return f"Exception Error: {str(e)}"

# Function to update custom counts
def update_custom_count(command):
    mysql_cursor.execute('SELECT count FROM custom_counts WHERE command = %s', (command,))
    result = mysql_cursor.fetchone()
    if result:
        current_count = result[0]
        new_count = current_count + 1
        mysql_cursor.execute('UPDATE custom_counts SET count = %s WHERE command = %s', (new_count, command))
    else:
        mysql_cursor.execute('INSERT INTO custom_counts (command, count) VALUES (%s, %s)', (command, 1))
    mysql_connection.commit()

def get_custom_count(command):
    mysql_cursor.execute('SELECT count FROM custom_counts WHERE command = %s', (command,))
    result = mysql_cursor.fetchone()
    if result:
        return result[0]
    else:
        return 0

# Functions for weather
async def get_streamer_weather():
    mysql_cursor.execute("SELECT weather_location FROM profile")
    info = mysql_cursor.fetchone()
    location = info[0]
    chat_logger.info(f"Got {location} weather info.")
    return location

async def getWindDirection(deg):
    cardinalDirections = {
        'N': (337.5, 22.5),
        'NE': (22.5, 67.5),
        'E': (67.5, 112.5),
        'SE': (112.5, 157.5),
        'S': (157.5, 202.5),
        'SW': (202.5, 247.5),
        'W': (247.5, 292.5),
        'NW': (292.5, 337.5)
    }
    for direction, (start, end) in cardinalDirections.items():
        if deg >= start and deg < end:
            return direction
    return 'N/A'

async def get_weather(location):
    owm = pyowm.OWM(WEATHER_API)
    try:
        observation = owm.weather_manager().weather_at_place(location)
        weather = observation.weather
        status = weather.detailed_status
        temperature = weather.temperature('celsius')['temp']
        temperature_f = round(temperature * 9 / 5 + 32, 1)
        wind_speed = round(weather.wind()['speed'])
        wind_speed_mph = round(wind_speed / 1.6, 2)
        humidity = weather.humidity
        wind_direction = getWindDirection(weather.wind()['deg'])

        return f"The weather in {location} is {status} with a temperature of {temperature}°C ({temperature_f}°F). Wind is blowing from the {wind_direction} at {wind_speed}kph ({wind_speed_mph}mph) and the humidity is {humidity}%."
    except pyowm.exceptions.NotFoundError:
        return f"Location '{location}' not found."
    except AttributeError:
        return f"An error occurred while processing the weather data for '{location}'."

# Function to trigger updating stream title or game
class GameNotFoundException(Exception):
    pass
class GameUpdateFailedException(Exception):
    pass

async def trigger_twitch_title_update(new_title):
    # Twitch API
    url = "https://api.twitch.tv/helix/channels"
    headers = {
        "Authorization": f"Bearer {CHANNEL_AUTH}",
        "Client-ID": TWITCH_API_CLIENT_ID,
    }
    params = {
        "broadcaster_id": CHANNEL_ID,
        "title": new_title
    }
    response = requests.patch(url, headers=headers, json=params)
    if response.status_code == 200:
        twitch_logger.info(f'Stream title updated to: {new_title}')
    else:
        twitch_logger.error(f'Failed to update stream title: {response.text}')

async def trigger_twitch_game_update(new_game_id):
    # Twitch API
    url = "https://api.twitch.tv/helix/channels"
    headers = {
        "Authorization": f"Bearer {CHANNEL_AUTH}",
        "Client-ID": TWITCH_API_CLIENT_ID,
    }
    params = {
        "broadcaster_id": CHANNEL_ID,
        "game_id": new_game_id
    }
    response = requests.patch(url, headers=headers, json=params)
    if response.status_code == 200:
        twitch_logger.info(f'Stream game updated to: {new_game_id}')
    else:
        twitch_logger.error(f'Failed to update stream game: {response.text}')
        raise GameUpdateFailedException(f'Failed to update stream game')

async def get_game_id(game_name):
    # Twitch API
    url = "https://api.twitch.tv/helix/games/top"
    headers = {
        "Authorization": f"Bearer {CHANNEL_AUTH}",
        "Client-ID": TWITCH_API_CLIENT_ID,
    }
    params = {
        "name": game_name
    }
    response = requests.get(url, headers=headers, params=params)
    if response.status_code == 200:
        data = response.json()
        if data and 'data' in data and len(data['data']) > 0:
            return data['data'][0]['id']
    twitch_logger.error(f"Game '{game_name}' not found.")
    raise GameNotFoundException(f"Game '{game_name}' not found.")

# Function to trigger a twitch shoutout via Twitch API
async def trigger_twitch_shoutout(shoutout_queue, user_to_shoutout, mentioned_user_id):
    # Add the shoutout request to the queue
    await shoutout_queue.put((user_to_shoutout, mentioned_user_id))

    # Check if the queue is empty and no shoutout is currently being processed
    if shoutout_queue.qsize() == 1:
        await process_shoutouts(shoutout_queue)

async def get_latest_stream_game(broadcaster_id, user_to_shoutout):
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

async def process_shoutouts(shoutout_queue):
    while not shoutout_queue.empty():
        user_to_shoutout, mentioned_user_id = await shoutout_queue.get()
        twitch_logger.info(f"Processing Shoutout via Twitch for {user_to_shoutout}={mentioned_user_id}")
        url = 'https://api.twitch.tv/helix/chat/shoutouts'
        headers = {
            "Authorization": f"Bearer {CHANNEL_AUTH}",
            "Client-ID": TWITCH_API_CLIENT_ID,
        }
        payload = {
            "from_broadcaster_id": CHANNEL_ID,
            "to_broadcaster_id": mentioned_user_id,
            "moderator_id": CHANNEL_ID
        }

        try:
            async with aiohttp.ClientSession() as session:
                async with session.post(url, headers=headers, json=payload) as response:
                    if response.status == 429:
                        # Rate limit exceeded, wait for cooldown period (3 minutes) before retrying
                        retry_after = 180  # 3 minutes in seconds
                        twitch_logger.error(f"Rate limit exceeded. Retrying after {retry_after} seconds.")
                        await asyncio.sleep(retry_after)
                        continue  # Retry the request
                    elif response.status in (200, 204):
                        twitch_logger.info(f"Shoutout triggered successfully for {user_to_shoutout}.")
                        await asyncio.sleep(180)  # Wait for 3 minutes before processing the next shoutout
                    else:
                        twitch_logger.error(f"Failed to trigger shoutout. Status: {response.status}. Message: {await response.text()}")
                        # Retry the request (exponential backoff can be implemented here)
                        await asyncio.sleep(5)  # Wait for 5 seconds before retrying
                        continue
                    await shoutout_queue.task_done()
        except aiohttp.ClientError as e:
            twitch_logger.error(f"Error triggering shoutout: {e}")

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

# Function to process the stream being online
async def process_stream_online():
    global stream_online
    global current_game
    stream_online = True
    bot_logger.info(f"Stream is now online!")
    asyncio.get_event_loop().create_task(timed_message())

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
    else:
        current_game = None
    image_data = data['data']["thumbnail_url"]
    image = image_data.replace("{width}", "1280").replace("{height}", "720")

    # Send a message to the chat announcing the stream is online
    message = f"Stream is now online! Streaming {current_game}" if current_game else "Stream is now online!"
    await send_online_message(message)
    await send_to_discord_stream_online(message, image)

async def process_stream_offline():
    global stream_online
    stream_online = False  # Update the stream status
    await clear_seen_today()
    await clear_credits_data()
    bot_logger.info(f"Stream is now offline.")

# Function to send the online message to channel
async def send_online_message(message):
    await asyncio.sleep(5)
    channel = bot.get_channel(CHANNEL_NAME)
    if channel:
        bot_logger.info(f"Attempted to send message: {message}")
        await channel.send(message)
    else:
        bot_logger.error("Failed to send message")

# Function to clear the seen users table at the end of stream
async def clear_seen_today():
    mysql_cursor.execute('DELETE FROM seen_today')
    mysql_connection.commit()

# Function to clear the ending credits table at the end of stream
async def clear_credits_data():
    mysql_cursor.execute('DELETE FROM stream_credits')
    mysql_connection.commit()

# Function for timed messages
async def timed_message():
    global scheduled_tasks
    global stream_online
    if stream_online:
        mysql_cursor.execute('SELECT interval_count, message FROM timed_messages')
        messages = mysql_cursor.fetchall()
        bot_logger.info(f"Timed Messages: {messages}")
        
        # Store the messages currently scheduled
        current_messages = [task.get_name() for task in scheduled_tasks]

        # Check for messages to add or remove
        for interval, message in messages:
            if message in current_messages:
                # Message already scheduled, continue to next message
                continue

            bot_logger.info(f"Timed Message: {message} has a {interval} minute wait.")
            time_now = datetime.now()
            send_time = time_now + timedelta(minutes=int(interval))
            wait_time = (send_time - time_now).total_seconds()
            bot_logger.info(f"Scheduling message: '{message}' to be sent in {wait_time} seconds")
            task = asyncio.create_task(send_timed_message(message, wait_time))
            task.set_name(message)  # Set a name for the task
            scheduled_tasks.append(task)  # Keep track of the task
        
        # Check for messages to remove
        for task in scheduled_tasks:
            if task.get_name() not in [message for _, message in messages]:
                # Message no longer in database, cancel the task
                task.cancel()
                scheduled_tasks.remove(task)
    else:
        # Cancel all scheduled tasks if the stream goes offline
        for task in scheduled_tasks:
            task.cancel()
        scheduled_tasks.clear()  # Clear the list of tasks

async def send_timed_message(message, delay):
    global stream_online
    await asyncio.sleep(delay)
    try:
        if stream_online:
            channel = bot.get_channel(CHANNEL_NAME)
            bot_logger.info(f"Sending Timed Message: {message}")
            await channel.send(message)
        else:
            bot_logger.info("Stream is offline. Message not sent.")
    except asyncio.CancelledError:
        bot_logger.info(f"Task cancelled for {message}")

# Function to get the current playing song
async def get_song_info_command():
    try:
        song_info = await get_song_info()
        if "error" in song_info:
            error_message = song_info["error"]
            chat_logger.error(f"Error: {error_message}")
            return error_message
        else:
            artist = song_info.get('artist', '')
            song = song_info.get('song', '')
            message = f"The current song is: {song} by {artist}"
            chat_logger.info(message)
            return message
    except Exception as e:
        api_logger.error(f"An error occurred while getting song info: {e}")
        return "Error: Failed to get song information."

async def get_song_info():
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
            matches = await detect_song(songb64)

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

async def detect_song(raw_audio_b64):
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
        response = requests.post(url, data=audio_bytes, headers=headers, params=querystring, timeout=15)
        # Check requests remaining for the API
        if "x-ratelimit-requests-remaining" in response.headers:
            requests_left = response.headers['x-ratelimit-requests-remaining']
            api_logger.info(f"There are {requests_left} requests lefts for the song command.")
            if requests_left == 0:
                return {"error": "Sorry, no more requests for song info are available for the rest of the month. Requests reset each month on the 23rd."}
        return response.json()
    except Exception as e:
        api_logger.error(f"An error occurred while detecting song: {e}")
        return {}

async def convert_to_raw_audio(in_file, out_file):
    try:
        ffmpeg_path = "/usr/bin/ffmpeg"
        proc = await asyncio.create_subprocess_exec(
            ffmpeg_path, '-i', in_file, "-vn", "-ar", "44100", "-ac", "1", "-c:a", "pcm_s16le", "-f", "s16le", out_file,
            stdout=asyncio.subprocess.DEVNULL,
            stderr=asyncio.subprocess.DEVNULL)
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
# Function for RAIDS
async def process_raid_event(from_broadcaster_id, from_broadcaster_name, viewer_count):
    # Check if the raiding broadcaster exists in the database
    mysql_cursor.execute('SELECT raid_count FROM raid_data WHERE raider_id = %s', (from_broadcaster_id,))
    existing_raid_count = mysql_cursor.fetchone()
    mysql_cursor.execute('SELECT viewers FROM raid_data WHERE raider_id = %s', (from_broadcaster_id,))
    existing_viewer_count = mysql_cursor.fetchone()

    if existing_raid_count:
        # Update the raid count for the raiding broadcaster
        raid_count = existing_raid_count[0] + 1
        viewers = existing_viewer_count[0] + viewer_count
        mysql_cursor.execute('''
            UPDATE raid_data SET raid_count = %s WHERE raider_id = %s
        ''', (raid_count, from_broadcaster_id))
        mysql_cursor.execute('''
            UPDATE raid_data SET viewers = %s WHERE raider_id = %s
        ''', (viewers, from_broadcaster_id))
    else:
        # Insert a new record for the raiding broadcaster
        mysql_cursor.execute('''
            INSERT INTO raid_data (raider_id, raider_name, raid_count, viewers)
            VALUES (%s, %s, %s, %s)
        ''', (from_broadcaster_id, from_broadcaster_name, 1, viewer_count))

    # Insert data into stream_credits table
    mysql_cursor.execute('''
        INSERT INTO stream_credits (username, event, data)
        VALUES (%s, %s, %s)
    ''', (from_broadcaster_name, "raid", viewer_count))

    # Commit changes to the database
    mysql_connection.commit()

    discord_message = f"{from_broadcaster_name} has raided with {viewer_count} viewers!"
    await send_to_discord(discord_message, "New Raid!", "raid.png")
    # Send a message to the channel about the raid
    channel = bot.get_channel(CHANNEL_NAME)
    await channel.send(f"Wow! {from_broadcaster_name} is raiding with {viewer_count} viewers!")

# Function for BITS
async def process_cheer_event(user_id, user_name, bits):
    # Check if the user exists in the database
    mysql_cursor.execute('SELECT bits FROM bits_data WHERE user_id = %s OR user_name = %s', (user_id, user_name))
    existing_bits = mysql_cursor.fetchone()

    if existing_bits:
        # Update the user's total bits count
        total_bits = existing_bits[0] + bits
        mysql_cursor.execute('''
            UPDATE bits_data
            SET bits = %s
            WHERE user_id = %s OR user_name = %s
        ''', (total_bits, user_id, user_name))
        
        # Send message to channel with total bits
        channel = bot.get_channel(CHANNEL_NAME)
        await channel.send(f"Thank you {user_name} for {bits} bits! You've given a total of {total_bits} bits.")
    else:
        # Insert a new record for the user
        mysql_cursor.execute('''
            INSERT INTO bits_data (user_id, user_name, bits)
            VALUES (%s, %s, %s)
        ''', (user_id, user_name, bits))
        
        discord_message = f"{user_name} just cheered {bits} bits!"
        if bits < 100:
            image = "cheer.png"
        elif 100 <= bits < 1000:
            image = "cheer100.png"
        else:
            image = "cheer1000.png"

        await send_to_discord(discord_message, "New Cheer!", image)
        # Send message to channel without total bits
        channel = bot.get_channel(CHANNEL_NAME)
        await channel.send(f"Thank you {user_name} for {bits} bits!")

    # Insert data into stream_credits table
    mysql_cursor.execute('''
    INSERT INTO stream_credits (username, event, data)
    VALUES (%s, %s, %s)
    ''', (user_name, "bits", bits))
    mysql_connection.commit()

async def process_subscription_event(user_id, user_name, sub_plan, event_months):
    # Check if the user exists in the database
    mysql_cursor.execute('SELECT sub_plan, months FROM subscription_data WHERE user_id = %s', (user_id,))
    existing_subscription = mysql_cursor.fetchone()

    if existing_subscription:
        # User exists in the database
        existing_sub_plan, db_months = existing_subscription
        if existing_sub_plan != sub_plan:
            # User upgraded their subscription plan
            mysql_cursor.execute('''
                UPDATE subscription_data
                SET sub_plan = %s, months = %s
                WHERE user_id = %s
            ''', (sub_plan, db_months, user_id))
        else:
            # User maintained the same subscription plan, update cumulative months
            mysql_cursor.execute('''
                UPDATE subscription_data
                SET months = %s
                WHERE user_id = %s
            ''', (db_months, user_id))
    else:
        # User does not exist in the database, insert new record
        mysql_cursor.execute('''
            INSERT INTO subscription_data (user_id, user_name, sub_plan, months)
            VALUES (%s, %s, %s, %s)
        ''', (user_id, user_name, sub_plan, event_months))

    # Insert data into stream_credits table
    mysql_cursor.execute('''
        INSERT INTO stream_credits (username, event, data)
        VALUES (%s, %s, %s)
    ''', (user_name, "subscriptions", f"{sub_plan} - {event_months} months"))

    # Commit changes to the database
    mysql_connection.commit()

    # Construct the message to be sent to the channel & send the message to the channel
    message = f"Thank you {user_name} for subscribing! You are now a {sub_plan} subscriber for {event_months} months!"
    discord_message = f"{user_name} just subscribed at {sub_plan}!"
    await send_to_discord(discord_message, "New Subscriber!", "sub.png")
    # Send the message to the channel
    channel = bot.get_channel(CHANNEL_NAME)
    await channel.send(message)

async def process_subscription_message_event(user_id, user_name, sub_plan, subscriber_message, event_months):
    # Check if the user exists in the database
    mysql_cursor.execute('SELECT sub_plan, months FROM subscription_data WHERE user_id = %s', (user_id,))
    existing_subscription = mysql_cursor.fetchone()

    if existing_subscription:
        # User exists in the database
        existing_sub_plan, db_months = existing_subscription
        if existing_sub_plan != sub_plan:
            # User upgraded their subscription plan
            mysql_cursor.execute('''
                UPDATE subscription_data
                SET sub_plan = %s, months = %s
                WHERE user_id = %s
            ''', (sub_plan, db_months, user_id))
        else:
            # User maintained the same subscription plan, update cumulative months
            mysql_cursor.execute('''
                UPDATE subscription_data
                SET months = %s
                WHERE user_id = %s
            ''', (db_months, user_id))
    else:
        # User does not exist in the database, insert new record
        mysql_cursor.execute('''
            INSERT INTO subscription_data (user_id, user_name, sub_plan, months)
            VALUES (%s, %s, %s, %s)
        ''', (user_id, user_name, sub_plan, event_months))

    # Insert data into stream_credits table with the subscriber's message
    mysql_cursor.execute('''
        INSERT INTO stream_credits (username, event, data)
        VALUES (%s, %s, %s)
    ''', (user_name, "subscriptions", f"{sub_plan} - {event_months} months."))

    # Commit changes to the database
    mysql_connection.commit()

    if subscriber_message.strip():
        # Construct the message to be sent to the channel, including the subscriber's message
        message = f"Thank you {user_name} for subscribing at {sub_plan}! Your message: '{subscriber_message}'"
    else:
        # Construct the message to be sent to the channel, including the subscriber's message
        message = f"Thank you {user_name} for subscribing at {sub_plan}!"

    discord_message = f"{user_name} just subscribed at {sub_plan}!"
    await send_to_discord(discord_message, "New Subscriber!", "sub.png")

    # Send the message to the channel
    channel = bot.get_channel(CHANNEL_NAME)
    await channel.send(message)

async def process_giftsub_event(recipient_user_id, recipient_user_name, sub_plan, user_name, anonymous):
    # Check if the recipient user exists in the database
    mysql_cursor.execute('SELECT months FROM subscription_data WHERE user_id = %s', (recipient_user_id,))
    existing_months = mysql_cursor.fetchone()

    if existing_months:
        # Recipient user exists in the database
        existing_months = existing_months[0]
        # Update the existing subscription with the new cumulative months
        updated_months = existing_months + 1
        mysql_cursor.execute('''
            UPDATE subscription_data
            SET sub_plan = %s, months = %s
            WHERE user_id = %s
        ''', (sub_plan, updated_months, recipient_user_id))
    else:
        # Recipient user does not exist in the database, insert new record
        mysql_cursor.execute('''
            INSERT INTO subscription_data (user_id, user_name, sub_plan, months)
            VALUES (%s, %s, %s, %s)
        ''', (recipient_user_id, recipient_user_name, sub_plan, 1))

    # Insert subscription data into stream_credits table
    mysql_cursor.execute('''
        INSERT INTO stream_credits (username, event, data)
        VALUES (%s, %s, %s)
    ''', (recipient_user_name, "subscriptions", f"{sub_plan} - GIFT SUBSCRIPTION"))

    # Commit changes to the database
    mysql_connection.commit()

    if anonymous == True:
        message = f"Thank you for gifting a {sub_plan} subscription to {recipient_user_name}! They are now a {sub_plan} subscriber!"
        discord_message = f"An Anonymous Gifter just gifted {recipient_user_name} a subscription!"
        await send_to_discord(discord_message, "New Gifted Subscription!", "sub.png")
    else:
        message = f"Thank you {user_name} for gifting a {sub_plan} subscription to {recipient_user_name}! They are now a {sub_plan} subscriber!"
        discord_message = f"{user_name} just gifted {recipient_user_name} a subscription!"
        await send_to_discord(discord_message, "New Gifted Subscription!", "sub.png")

    # Send the message to the channel
    channel = bot.get_channel(CHANNEL_NAME)
    await channel.send(message)

# Function for FOLLOWERS
async def process_followers_event(user_id, user_name, followed_at_twitch):
    datetime_obj = datetime.strptime(followed_at_twitch, "%Y-%m-%dT%H:%M:%S.%fZ")
    followed_at = datetime_obj.strftime("%Y-%m-%d %H:%M:%S")

    # Insert a new record for the follower
    mysql_cursor.execute('''
        INSERT INTO followers_data (user_id, user_name, followed_at)
        VALUES (%s, %s, %s)
    ''', (user_id, user_name, followed_at))

    # Insert data into stream_credits table
    mysql_cursor.execute('''
        INSERT INTO stream_credits (username, event, data)
        VALUES (%s, %s, %s)
    ''', (user_name, "follow", ""))

    # Commit changes to the database
    mysql_connection.commit()

    # Construct the message to be sent to the channel
    message = f"Thank you {user_name} for following! Welcome to the channel!"
    discord_message = f"{user_name} just followed!"
    await send_to_discord(discord_message, "New Follower!", "follow.png")

    # Send the message to the channel
    channel = bot.get_channel(CHANNEL_NAME)
    await channel.send(message)

# Function to build the Discord Notice
async def send_to_discord(message, title, image):
    mysql_cursor.execute("SELECT discord_alert FROM profile")
    discord_url = mysql_cursor.fetchone()

    mysql_cursor.execute("SELECT timezone FROM profile")
    timezone = mysql_cursor.fetchone()[0]
    if timezone:
        tz = pytz.timezone(timezone)
        current_time = datetime.now(tz)
        time_format_date = current_time.strftime("%B %d, %Y")
        time_format_time = current_time.strftime("%I:%M %p")
        time_format = f"{time_format_date} at {time_format_time}"
    else:
        timezone = 'UTC'
        tz = pytz.timezone(timezone)
        current_time = datetime.now(tz)
        time_format_date = current_time.strftime("%B %d, %Y")
        time_format_time = current_time.strftime("%I:%M %p")
        time_format = f"{time_format_date} at {time_format_time}"

    payload = {"embeds": []}
    if discord_url:
        discord_url = discord_url[0]
        payload["username"] = "BotOfTheSpecter"
        payload["avatar_url"] = "https://cdn.botofthespecter.com/logo.png"
        payload["embeds"] = [{
            "description": message,
            "title": title,
            "thumbnail": {"url": f"https://cdn.botofthespecter.com/webhook/{image}"},
            "footer": {"text": f"Autoposted by BotOfTheSpecter - {time_format}"}
        }]
        
        response = requests.post(discord_url, json=payload)
        if response.status_code == 200 or response.status_code == 204:
            # bot_logger.info(f"Sent to Disord {response.status_code}")
            return
        else:
            bot_logger.error(f"Failed to send to Discord - Error: {response.status_code}")
    else:
        bot_logger.error(f"Discord URL not found.")
        return

# Function to build the Discord Mod Notice 
async def send_to_discord_mod(message, title, image):
    mysql_cursor.execute("SELECT discord_mod FROM profile")
    discord_url = mysql_cursor.fetchone()

    mysql_cursor.execute("SELECT timezone FROM profile")
    timezone = mysql_cursor.fetchone()[0]
    if timezone:
        tz = pytz.timezone(timezone)
        current_time = datetime.now(tz)
        time_format_date = current_time.strftime("%B %d, %Y")
        time_format_time = current_time.strftime("%I:%M %p")
        time_format = f"{time_format_date} at {time_format_time}"
    else:
        timezone = 'UTC'
        tz = pytz.timezone(timezone)
        current_time = datetime.now(tz)
        time_format_date = current_time.strftime("%B %d, %Y")
        time_format_time = current_time.strftime("%I:%M %p")
        time_format = f"{time_format_date} at {time_format_time}"

    payload = {"embeds": []}
    if discord_url:
        discord_url = discord_url[0]
        payload["username"] = "BotOfTheSpecter"
        payload["avatar_url"] = "https://cdn.botofthespecter.com/logo.png"
        payload["embeds"] = [{
            "description": message,
            "title": title,
            "thumbnail": {"url": f"https://cdn.botofthespecter.com/webhook/{image}"},
            "footer": {"text": f"Autoposted by BotOfTheSpecter - {time_format}"}
        }]
        
        response = requests.post(discord_url, json=payload)
        if response.status_code == 200 or response.status_code == 204:
            # bot_logger.info(f"Sent to Disord {response.status_code}")
            return
        else:
            bot_logger.error(f"Failed to send to Discord - Error: {response.status_code}")
    else:
        bot_logger.error(f"Discord URL not found.")
        return

# Function to build the Discord Notice for Stream Online
async def send_to_discord_stream_online(message, image):
    mysql_cursor.execute("SELECT discord_alert_online FROM profile")
    discord_url = mysql_cursor.fetchone()

    mysql_cursor.execute("SELECT timezone FROM profile")
    timezone = mysql_cursor.fetchone()[0]
    if timezone:
        tz = pytz.timezone(timezone)
        current_time = datetime.now(tz)
        time_format_date = current_time.strftime("%B %d, %Y")
        time_format_time = current_time.strftime("%I:%M %p")
        time_format = f"{time_format_date} at {time_format_time}"
    else:
        timezone = 'UTC'
        tz = pytz.timezone(timezone)
        current_time = datetime.now(tz)
        time_format_date = current_time.strftime("%B %d, %Y")
        time_format_time = current_time.strftime("%I:%M %p")
        time_format = f"{time_format_date} at {time_format_time}"

    title = f"{CHANNEL_NAME} is now live on Twitch!"
    payload = {"content": "@everyone"}
    if discord_url:
        discord_url = discord_url[0]
        payload = {"embeds": []}
        payload["username"] = "BotOfTheSpecter"
        payload["avatar_url"] = "https://cdn.botofthespecter.com/logo.png"
        payload["embeds"] = [{
            "description": message,
            "title": title,
            "url": f"https://twitch.tv/{CHANNEL_NAME}",
            "image": {"url":
                      f"{image}",
                      "height": "720",
                      "width": "1280"
                      },
            "footer": {"text": f"Autoposted by BotOfTheSpecter - {time_format}"}
        }]
        
        response = requests.post(discord_url, json=payload)
        if response.status_code == 200 or response.status_code == 204:
            # bot_logger.info(f"Sent to Disord {response.status_code}")
            return
        else:
            bot_logger.error(f"Failed to send to Discord - Error: {response.status_code}")
    else:
        bot_logger.error(f"Discord URL not found.")
        return

# Function to create a new group if it doesn't exist
async def group_creation():
    group_names = ["VIP", "Subscriber T1", "Subscriber T2", "Subscriber T3"]
    try:
        # Check if groups already exist in the table
        mysql_cursor.execute("SELECT name FROM `groups` WHERE name IN %s", (tuple(group_names),))
        existing_groups = [row[0] for row in mysql_cursor.fetchall()]

        # Filter out existing groups
        new_groups = [name for name in group_names if name not in existing_groups]

        # Insert new groups
        if new_groups:
            placeholders = ', '.join(['%s'] * len(new_groups))
            mysql_cursor.executemany("INSERT INTO `groups` (name) VALUES (" + placeholders + ")", [(name,) for name in new_groups])
            mysql_connection.commit()

            for name in new_groups:
                bot_logger.info(f"Group '{name}' created successfully.")
    except mysql.connector.Error as err:
        bot_logger.error(f"Failed to create groups: {err}")

# Function to create the command in the database if it doesn't exist
async def builtin_commands_creation():
    all_commands = list(mod_commands) + list(builtin_commands)
    try:
        for command in all_commands:
            mysql_cursor.execute("SELECT * FROM builtin_commands WHERE command=%s", (command,))
            if not mysql_cursor.fetchone():
                mysql_cursor.execute("INSERT INTO builtin_commands (command) VALUES (%s)", (command,))
                mysql_connection.commit()
                bot_logger.info(f"Command '{command}' added to database successfully.")
    except mysql.connector.Error as e:
        bot_logger.error(f"Error: {e}")

# Function to tell the website what version of the bot is currently running
async def update_version_control():
    try:
        # Define the directory path
        directory = "/var/www/logs/version/"
        
        # Ensure the directory exists, create it if it doesn't
        if not os.path.exists(directory):
            os.makedirs(directory)
        
        # Define the file path with the channel name
        file_path = os.path.join(directory, f"{CHANNEL_NAME}_beta_version_control.txt")
        
        # Delete the file if it exists
        if os.path.exists(file_path):
            os.remove(file_path)
        
        # Write the new version to the file
        with open(file_path, "w") as file:
            file.write(VERSION)
    except Exception as e:
        bot_logger.error(f"An error occurred: {e}")

async def check_stream_online():
    global stream_online
    global current_game

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
                bot_logger.info(f"Bot Restarted, Stream is offline.")
            else:
                # Stream is online, extract the game name
                stream_online = True
                game = data['data'][0].get('game_name', None)
                current_game = game
                bot_logger.info(f"Bot Restarted, Stream is online.")
    return

# Here is the BOT
bot = BotOfTheSpecter(
    token=OAUTH_TOKEN,
    prefix='!',
    channel_name=CHANNEL_NAME
)

# Errors
@bot.event
async def event_command_error(error):
    bot_logger.error(f"Error occurred: {error}")

# Run the bot
def start_bot():
    # Schedule bot tasks
    asyncio.get_event_loop().create_task(refresh_token_every_day())
    
    # Create groups if they don't exist
    group_creation()

    # Create built-in commands in the database
    builtin_commands_creation()

    # Start the bot
    bot.run()

if __name__ == '__main__':
    start_bot()