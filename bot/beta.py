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
import socketio
import json
import time
import random
import base64

# Third-party imports
import aiohttp
import requests
import aiomysql
from mysql.connector import errorcode
from deep_translator import GoogleTranslator
from twitchio.ext import commands
import streamlink
import pyowm
import pytz
from jokeapi import Jokes
import openai
import uuid

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
VERSION = "4.5"
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
OPENAI_KEY = ""  # CHANGE TO MAKE THIS WORK
TWITCH_API_CLIENT_ID = CLIENT_ID
builtin_commands = {"commands", "bot", "roadmap", "quote", "rps", "story", "roulette", "kill", "slots", "timer", "game", "joke", "ping", "weather", "time", "song", "translate", "cheerleader", "steam", "schedule", "mybits", "lurk", "unlurk", "lurking", "lurklead", "clip", "subscription", "hug", "kiss", "uptime", "typo", "typos", "followage", "deaths"}
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

# Initialize instances for the translator, shoutout queue, webshockets and permitted users for protection
translator = GoogleTranslator
shoutout_queue = asyncio.Queue()
scheduled_tasks = asyncio.Queue()
openai.api_key = OPENAI_KEY
permitted_users = {}
bot_logger.info("Bot script started.")
connected = set()
scheduled_tasks = []
last_poll_progress_update = 0
global stream_online
global current_game
global stream_title
global botstarted
botstarted = datetime.now()
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
        "channel.charity_campaign.donate",
        "channel.channel_points_automatic_reward_redemption.add",
        "channel.channel_points_custom_reward_redemption.add",
        "channel.poll.begin",
        "channel.poll.progress",
        "channel.poll.end"
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

async def connect_to_tipping_services():
    global streamelements_token, streamlabs_token
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor(aiomysql.DictCursor) as cursor:
            await cursor.execute("SELECT StreamElements, StreamLabs FROM tipping_settings LIMIT 1")
            result = await cursor.fetchone()

            streamelements_token = result['StreamElements']
            streamlabs_token = result['StreamLabs']

            tasks = []
            if streamelements_token:
                tasks.append(connect_to_streamelements())
            if streamlabs_token:
                tasks.append(connect_to_streamlabs())

            if tasks:
                await asyncio.gather(*tasks)
            else:
                bot_logger.error("No valid token found for either StreamElements or StreamLabs.")
    except aiomysql.MySQLError as err:
        bot_logger.error(f"Database error: {err}")
    finally:
        await sqldb.ensure_closed()

async def connect_to_streamelements():
    global streamelements_token
    uri = "wss://astro.streamelements.com"
    async with websockets.connect(uri) as websocket:
        # Send the authentication message
        nonce = str(uuid.uuid4())
        await websocket.send(json.dumps({
            'type': 'subscribe',
            'nonce': nonce,
            'data': {
                'topic': 'channel.tip',
                'token': streamelements_token,
                'token_type': 'jwt'
            }
        }))
        
        # Listen for messages
        while True:
            message = await websocket.recv()
            await process_message(message, "StreamElements")

async def connect_to_streamlabs():
    global streamlabs_token
    uri = f"wss://sockets.streamlabs.com/socket.io/?token={streamlabs_token}&EIO=3&transport=websocket"
    async with websockets.connect(uri) as websocket:
        # Listen for messages
        while True:
            message = await websocket.recv()
            await process_message(message, "StreamLabs")

async def process_message(message, source):
    data = json.loads(message)
    if source == "StreamElements" and data.get('type') == 'response':
        # Handle the subscription response
        if 'error' in data:
            handle_streamelements_error(data['error'], data['data']['message'])
        else:
            bot_logger.info(f"StreamElements subscription success: {data['data']['message']}")
    else:
        await process_tipping_message(data, source)

def handle_streamelements_error(error, message):
    error_messages = {
        "err_internal_error": "An internal error occurred.",
        "err_bad_request": "The request was malformed or invalid.",
        "err_unauthorized": "The request lacked valid authentication credentials.",
        "rate_limit_exceeded": "The rate limit for the API has been exceeded.",
        "invalid_message": "The message was invalid or could not be processed."
    }
    error_message = error_messages.get(error, "Unknown error occurred.")
    bot_logger.error(f"StreamElements error: {error_message} - {message}")

async def process_tipping_message(data, source):
    channel = bot.get_channel(CHANNEL_NAME)
    send_message = None

    if source == "StreamElements" and data.get('type') == 'tip':
        user = data['data']['username']
        amount = data['data']['amount']
        tip_message = data['data']['message']
        send_message = f"{user} just tipped {amount}! Message: {tip_message}"
    elif source == "StreamLabs" and 'event' in data and data['event'] == 'donation':
        for donation in data['data']['donations']:
            user = donation['name']
            amount = donation['amount']
            tip_message = donation['message']
            send_message = f"{user} just tipped {amount}! Message: {tip_message}"
    
    if send_message:
        await channel.send(send_message)
        # Save tipping data directly in this function
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute(
                    "INSERT INTO tipping (username, amount, message, source) VALUES (%s, %s, %s, %s)",
                    (user, amount, tip_message, source)
                )
                await sqldb.commit()
        except aiomysql.MySQLError as err:
            bot_logger.error(f"Database error: {err}")
        finally:
            await sqldb.ensure_closed()

async def process_eventsub_message(message):
    channel = bot.get_channel(CHANNEL_NAME)
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor() as cursor:
            event_type = message.get("payload", {}).get("subscription", {}).get("type")
            event_data = message.get("payload", {}).get("event")
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
                    bot_logger.info(f"Subscription (Message) Event Data: {event_data}")
                    tier_mapping = {
                        "1000": "Tier 1",
                        "2000": "Tier 2",
                        "3000": "Tier 3"
                    }
                    tier = event_data["tier"]
                    tier_name = tier_mapping.get(tier, tier)
                    subscription_message = event_data.get("message", "")
                    await process_subscription_message_event(
                        event_data["user_id"],
                        event_data["user_name"],
                        tier_name,
                        subscription_message,
                        event_data.get("cumulative_months", 1)
                    )
                elif event_type == "channel.subscription.gift":
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
                    message = f"The Hype Train has ended at level {level}! Top contributions:"
                    for contribution in top_contributions:
                        user_name = contribution["user_name"]
                        contribution_type = contribution["type"]
                        total_formatted = "{:,}".format(contribution["total"])
                        total = total_formatted
                        message += f"\n{user_name} contributed {total} {contribution_type}."
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
                    duration_seconds = event_data["duration_seconds"]
                    minutes = duration_seconds // 60
                    seconds = duration_seconds % 60
                    if minutes == 0:
                        formatted_duration = f"{seconds} seconds"
                    elif seconds == 0:
                        formatted_duration = f"{minutes} minutes"
                    else:
                        formatted_duration = f"{minutes} minutes, {seconds} seconds"
                    await channel.send(f"An ad is running for {formatted_duration}. We'll be right back after these ads.")
                    await asyncio.sleep(duration_seconds)
                    await channel.send("Thanks for sticking with us through the ads! Welcome back, everyone!")
                elif event_type == 'channel.charity_campaign.donate':
                    user = event_data["event"]["user_name"]
                    charity = event_data["event"]["charity_name"]
                    value = event_data["event"]["amount"]["value"]
                    currency = event_data["event"]["amount"]["currency"]
                    value_formatted = "{:,.2f}".format(value)
                    message = f"Thank you so much {user} for your ${value_formatted}{currency} donation to {charity}. Your support means so much to us and to {charity}."
                    await channel.send(message)
                elif event_type == 'channel.moderate':
                    moderator_user_name = event_data["event"]["moderator_user_name"]
                    if event_data["event"]["action"] == "timeout":
                        timeout_info = event_data["event"]["timeout"]
                        user_name = timeout_info["user_name"]
                        reason = timeout_info["reason"]
                        expires_at = datetime.strptime(timeout_info["expires_at"], "%Y-%m-%dT%H:%M:%SZ")
                        expires_at_formatted = expires_at.strftime("%Y-%m-%d %H:%M:%S")
                        discord_message = f'{user_name} has been timed out, their timeout expires at {expires_at_formatted} for the reason "{reason}"'
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
                elif event_type in ["channel.channel_points_automatic_reward_redemption.add", "channel.channel_points_custom_reward_redemption.add"]:
                    if event_type == "channel.channel_points_automatic_reward_redemption.add":
                        bot_logger.info(f"Channel Point Automatic Reward Event Data: {event_data}")
                        reward_id = event_data.get("id")
                        reward_title = event_data["reward"].get("type")
                        reward_cost = event_data["reward"].get("cost")
                        await cursor.execute("SELECT COUNT(*), custom_message FROM channel_point_rewards WHERE reward_id = %s", (reward_id,))
                        result = await cursor.fetchone()
                        if result is not None and len(result) == 2:
                            if result[0] == 0:
                                await cursor.execute("INSERT INTO channel_point_rewards (reward_id, reward_title, reward_cost) VALUES (%s, %s, %s)", (reward_id, reward_title, reward_cost))
                            else:
                                existing_custom_message = result[1]
                                if existing_custom_message:
                                    message = existing_custom_message
                                    await channel.send(message)
                                else:
                                    pass
                        else:
                            bot_logger.error("Error: Unexpected result from database query.")
                    elif event_type == "channel.channel_points_custom_reward_redemption.add":
                        bot_logger.info(f"Channel Point Custom Reward Event Data: {event_data}")
                        reward_id = event_data["reward"].get("id")
                        reward_title = event_data["reward"].get("title")
                        reward_cost = event_data["reward"].get("cost")
                        await cursor.execute("SELECT COUNT(*), custom_message FROM channel_point_rewards WHERE reward_id = %s", (reward_id,))
                        result = await cursor.fetchone()
                        if result is not None and len(result) == 2:
                            if result[0] == 0:
                                await cursor.execute("INSERT INTO channel_point_rewards (reward_id, reward_title, reward_cost) VALUES (%s, %s, %s)", (reward_id, reward_title, reward_cost))
                            else:
                                existing_custom_message = result[1]
                                if existing_custom_message:
                                    message = existing_custom_message
                                    await channel.send(message)
                                else:
                                    pass
                        else:
                            bot_logger.error("Error: Unexpected result from database query.")
                elif event_type in ["channel.poll.begin", "channel.poll.progress", "channel.poll.end"]:
                    if event_type == "channel.poll.begin":
                        poll_title = event_data.get("title")
                        choices_titles = [choice.get("title") for choice in event_data.get("choices", [])]
                        bits_voting_enabled = event_data.get("bits_voting", {}).get("is_enabled")
                        bits_amount_per_vote = event_data.get("bits_voting", {}).get("amount_per_vote") if bits_voting_enabled else False
                        channel_points_voting_enabled = event_data.get("channel_points_voting", {}).get("is_enabled")
                        channel_points_amount_per_vote = event_data.get("channel_points_voting", {}).get("amount_per_vote") if channel_points_voting_enabled else False
                        poll_ends_at = datetime.strptime(event_data.get("ends_at")[:-11], "%Y-%m-%dT%H:%M:%S")
                        message = f"Poll '{poll_title}' has started! \n"
                        message += "Choices: \n"
                        for choice_title in choices_titles:
                            message += f"- {choice_title} \n"
                        if bits_voting_enabled:
                            message += f"Bits Voting Enabled: Amount per Vote - {bits_amount_per_vote} \n"
                        if channel_points_voting_enabled:
                            message += f"Channel Points Voting Enabled: Amount per Vote - {channel_points_amount_per_vote} \n"
                        tz = pytz.timezone("UTC")
                        utc_now = datetime.now(tz)
                        poll_ends_at_utc = tz.localize(poll_ends_at)
                        time_until_end = poll_ends_at_utc - utc_now
                        minutes, seconds = divmod(time_until_end.total_seconds(), 60)
                        if minutes > 0:
                            message += f"Poll ending in {int(minutes)} minutes"
                            is_minutes = True
                        else:
                            is_minutes = False
                        if seconds > 0:
                            if is_minutes is not False:
                                message += f" and {int(seconds)} seconds."
                            else:
                                message += f"Poll ending in {int(seconds)} seconds."
                        else:
                            message += "."
                        await channel.send(message)
                    elif event_type == "channel.poll.progress":
                        current_time = time.time()
                        last_poll_progress_update = current_time
                        if current_time - last_poll_progress_update >= 30:
                            poll_title = event_data.get("title")
                            choices_data = []
                            for choice in event_data.get("choices", []):
                                choice_title = choice.get("title")
                                bits_votes = choice.get("bits_votes") if event_data.get("bits_voting", {}).get("is_enabled") else False
                                channel_points_votes = choice.get("channel_points_votes") if event_data.get("channel_points_voting", {}).get("is_enabled") else False
                                total_votes = choice.get("votes")
                                choices_data.append({
                                    "title": choice_title,
                                    "bits_votes": bits_votes,
                                    "channel_points_votes": channel_points_votes,
                                    "total_votes": total_votes
                                })
                            message = f"Poll Progress: {poll_title}\n"
                            for choice_data in choices_data:
                                choice_title = choice_data["title"]
                                bits_votes = choice_data["bits_votes"]
                                channel_points_votes = choice_data["channel_points_votes"]
                                total_votes = choice_data["total_votes"]
                                message += f"- {choice_title}: Bits Votes - {bits_votes}, Channel Points Votes - {channel_points_votes}, Total Votes - {total_votes}\n"
                            await channel.send(message)
                    elif event_type == "channel.poll.end":
                        poll_id = event_data.get("id")
                        poll_title = event_data.get("title")
                        choices_data = []
                        for choice in event_data.get("choices", []):
                            choice_title = choice.get("title")
                            bits_votes = choice.get("bits_votes") if event_data.get("bits_voting", {}).get("is_enabled") else False
                            channel_points_votes = choice.get("channel_points_votes") if event_data.get("channel_points_voting", {}).get("is_enabled") else False
                            total_votes = choice.get("votes")
                            choices_data.append({
                                "title": choice_title,
                                "bits_votes": bits_votes,
                                "channel_points_votes": channel_points_votes,
                                "total_votes": total_votes
                            })
                        sorted_choices = sorted(choices_data, key=lambda x: x["total_votes"], reverse=True)
                        winning_choice = sorted_choices[0] if sorted_choices else None
                        message = f"The poll '{poll_title}' has ended! \n"
                        if winning_choice:
                            message += f"The winning choice is '{winning_choice['title']}' with {winning_choice['total_votes']} votes. \n"
                        else:
                            message += f"The winning choice is '{winning_choice['title']}' but there are no votes recorded for this poll. \n"
                        for choice_data in sorted_choices:
                            message += f"- {choice_data['title']}: Bits Votes - {choice_data['bits_votes']}, Channel Points Votes - {choice_data['channel_points_votes']}, Total Votes - {choice_data['total_votes']} \n"
                        await channel.send(message)
                        await cursor.execute("INSERT INTO poll_results (poll_id, poll_name) VALUES (%s, %s)", (poll_id, poll_title))
                        await sqldb.commit()
                        sql_options = ["one", "two", "three", "four", "five"]
                        sql_query = "UPDATE poll_results SET "
                        for i in enumerate(sql_options, start=1):
                            sql_query += f"poll_option_{i} = %s, "
                        sql_query = sql_query.rstrip(", ")
                        sql_query += " WHERE poll_id = %s"
                        params = [choice_data["title"] if i < len(sorted_choices) else None for i, choice_data in enumerate(sorted_choices)] + [None, None, None, None, None, poll_id]
                        await cursor.execute(sql_query, params)
                        await sqldb.commit()
                elif event_type in ["stream.online", "stream.offline"]:
                    if event_type == "stream.online":
                        await process_stream_online()
                    else:
                        await process_stream_offline()
                # Logging for unknown event types
                else:
                    twitch_logger.error(f"Received message with unknown event type: {event_type}")

    except Exception as e:
        bot_logger.error(f"Error processing EventSub message: {e}")
    finally:
        await sqldb.ensure_closed()

class BotOfTheSpecter(commands.Bot):
    # Event Message to get the bot ready
    def __init__(self, token, prefix, channel_name):
        super().__init__(token=token, prefix=prefix, initial_channels=[channel_name])
        self.channel_name = channel_name

    async def event_ready(self):
        bot_logger.info(f'Logged in as | {self.nick}')
        channel = self.get_channel(self.channel_name)
        await setup_database()
        await check_stream_online()
        await update_version_control()
        await group_creation()
        await builtin_commands_creation()
        await known_users()
        asyncio.get_event_loop().create_task(twitch_eventsub())
        asyncio.get_event_loop().create_task(timed_message())
        await channel.send(f"/me is connected and ready! Running V{VERSION}")

    # Function to check all messages and push out a custom command.
    async def event_message(self, message):
        sqldb = await get_mysql_connection()
        async with sqldb.cursor() as cursor:
            channel = message.channel
            messageAuthor = None
            messageAuthorID = None
            messageContent = None
            AuthorMessage = None
            try:
                # Ignore messages from the bot itself
                if message.echo:
                    return

                # Log the message content
                chat_history_logger.info(f"Chat message from {message.author.name}: {message.content}")

                # Check for a valid author before proceeding
                if message.author is None:
                    bot_logger.error("Received a message without a valid author.")
                    return

                # Handle commands
                await self.handle_commands(message)

                messageContent = message.content.strip().lower() if message.content else ""
                messageAuthor = message.author.name if message.author else ""
                messageAuthorID = message.author.id if message.author else ""
                AuthorMessage = message.content if message.content else ""

                if messageContent.startswith('!'):
                    command_parts = messageContent.split()
                    command = command_parts[0][1:]  # Extract the command without '!'

                    # Log all command usage
                    chat_logger.info(f"{messageAuthor} used the command: {command}")

                    if command in builtin_commands or command in builtin_aliases:
                        chat_logger.info(f"{messageAuthor} used a built-in command called: {command}")
                        return  # It's a built-in command or alias, do nothing more

                    # Check if the command exists in a hypothetical database and respond
                    await cursor.execute('SELECT response, status FROM custom_commands WHERE command = %s', (command,))
                    result = await cursor.fetchone()
                    if result:
                        if result[1] == 'Enabled':
                            response = result[0]
                            switches = ['(customapi.', '(count)', '(daysuntil.', '(command.', '(user)', '(command.']
                            responses_to_send = []

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

                                if '(user)' in response:
                                    user_mention = re.search(r'<@(\d+)>', messageContent)
                                    if user_mention:
                                        mentioned_user_id = user_mention.group(1)
                                        # Use mentioned user's name
                                        user_name = await get_display_name(mentioned_user_id)
                                    else:
                                        # Default to message author's name
                                        user_name = messageAuthor
                                    response = response.replace('(user)', user_name)

                                if '(command.' in response:
                                    command_match = re.search(r'\(command\.(\w+)\)', response)
                                    if command_match:
                                        sub_command = command_match.group(1)
                                        await cursor.execute('SELECT response FROM custom_commands WHERE command = %s', (sub_command,))
                                        sub_response = await cursor.fetchone()
                                        if sub_response:
                                            response = response.replace(f"(command.{sub_command})", sub_response[0])
                                            responses_to_send.append(sub_response[0])
                                        else:
                                            chat_logger.error(f"{sub_command} is no longer available.")
                                            await channel.send(f"The command {sub_command} is no longer available.")

                            # Send the individual responses
                            if len(responses_to_send) > 1:
                                for resp in responses_to_send:
                                    chat_logger.info(f"{command} command ran with response: {resp}")
                                    await channel.send(resp)
                            else:
                                await channel.send(response)
                        else:
                            chat_logger.info(f"{command} not ran because it's disabled.")
                    else:
                        chat_logger.info(f"{command} not found in the database.")

                if 'http://' in AuthorMessage or 'https://' in AuthorMessage:
                    # Fetch url_blocking option from the protection table in the user's database
                    await cursor.execute('SELECT url_blocking FROM protection')
                    result = await cursor.fetchone()
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
                        if command_permissions(messageAuthor):
                            # User is a mod or is the broadcaster, they are by default permitted.
                            return

                        # Fetch link whitelist from the database
                        await cursor.execute('SELECT link FROM link_whitelist')
                        whitelisted_links = await cursor.fetchall()
                        whitelisted_links = [link[0] for link in whitelisted_links]
                        await cursor.execute('SELECT link FROM link_blacklisting')
                        blacklisted_links = await cursor.fetchall()
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
                            await channel.send(f"Oops! That link looks like it's gone on an adventure! Please ask a mod to give it a check and launch an investigation to find out where it's disappeared to!")
                            return  # Stop further processing
                        elif not contains_whitelisted_link and not contains_twitch_clip_link:
                            # Delete the message if it contains a URL and it's not whitelisted or a Twitch clip link
                            await message.delete()
                            chat_logger.info(f"Deleted message from {messageAuthor} containing a URL: {AuthorMessage}")
                            # Notify the user not to post links without permission
                            await channel.send(f"{messageAuthor}, links are not authorized in chat, ask moderator or the Broadcaster for permission.")
                            return  # Stop further processing
                        else:
                            chat_logger.info(f"URL found in message from {messageAuthor}, not deleted due to being whitelisted or a Twitch clip link.")
                    else:
                        chat_logger.info(f"URL found in message from {messageAuthor}, but URL blocking is disabled.")
                else:
                    pass
            except Exception as e:
                bot_logger.error(f"An error occurred in event_message: {e}")
            finally:
                await cursor.close()
                await sqldb.ensure_closed()
                await self.message_counting(messageAuthor, messageAuthorID, message)

    async def message_counting(self, messageAuthor, messageAuthorID, message):
        if messageAuthor is None:
            chat_logger.error("messageAuthor is None")
            return
        sqldb = await get_mysql_connection()
        channel = message.channel
        try:
            async with sqldb.cursor() as cursor:
                # Check user level
                is_vip = await is_user_vip(messageAuthor)
                is_mod = await is_user_mod(messageAuthor)
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
                temp_seen_users = await cursor.fetchone()

                # Check if the user is in the list of already seen users
                if temp_seen_users:
                    return

                # Check if the user is new or returning
                await cursor.execute('SELECT * FROM seen_users WHERE username = %s', (messageAuthor,))
                user_data = await cursor.fetchone()

                if user_data:
                    # Check if the user is the broadcaster
                    if messageAuthor.lower() == CHANNEL_NAME.lower():
                        return
                    user_status = True
                    welcome_message = user_data[2]
                    user_status_enabled = user_data[3]
                    await cursor.execute('INSERT INTO seen_today (user_id) VALUES (%s)', (messageAuthorID,))
                    await sqldb.commit()
                    await websocket_notice(CHANNEL_NAME, "walkon", messageAuthor)
                else:
                    # Check if the user is the broadcaster
                    if messageAuthor.lower() == CHANNEL_NAME.lower():
                        return
                    user_status = False
                    welcome_message = None
                    user_status_enabled = 'True'
                    await cursor.execute('INSERT INTO seen_today (user_id) VALUES (%s)', (messageAuthorID,))
                    await sqldb.commit()
                    await websocket_notice(CHANNEL_NAME, "walkon", messageAuthor)

                if user_status_enabled == 'True':
                    if is_vip:
                        # VIP user
                        if user_status and welcome_message:
                            # Returning user with custom welcome message
                            await channel.send(welcome_message)
                        elif user_status:
                            # Returning user
                            vip_welcome_message = f"ATTENTION! A very important person has entered the chat, welcome {messageAuthor}!"
                            await channel.send(vip_welcome_message)
                        else:
                            # New user
                            await user_is_seen(messageAuthor)
                            new_vip_welcome_message = f"ATTENTION! A very important person has entered the chat, let's give {messageAuthor} a warm welcome!"
                            await channel.send(new_vip_welcome_message)
                    elif is_mod:
                        # Moderator user
                        if user_status and welcome_message:
                            # Returning user with custom welcome message
                            await channel.send(welcome_message)
                        elif user_status:
                            # Returning user
                            mod_welcome_message = f"MOD ON DUTY! Welcome in {messageAuthor}. The power of the sword has increased!"
                            await channel.send(mod_welcome_message)
                        else:
                            # New user
                            await user_is_seen(messageAuthor)
                            new_mod_welcome_message = f"MOD ON DUTY! Welcome in {messageAuthor}. The power of the sword has increased! Let's give {messageAuthor} a warm welcome!"
                            await channel.send(new_mod_welcome_message)
                    else:
                        # Non-VIP and Non-mod user
                        if user_status and welcome_message:
                            # Returning user with custom welcome message
                            await channel.send(welcome_message)
                        elif user_status:
                            # Returning user
                            welcome_back_message = f"Welcome back {messageAuthor}, glad to see you again!"
                            await channel.send(welcome_back_message)
                        else:
                            # New user
                            await user_is_seen(messageAuthor)
                            new_user_welcome_message = f"{messageAuthor} is new to the community, let's give them a warm welcome!"
                            await channel.send(new_user_welcome_message)
                else:
                    chat_logger.info(f"User status for {messageAuthor} is disabled.")
        except Exception as e:
            chat_logger.error(f"Error in message_counting: {e}")
        finally:
            await sqldb.ensure_closed()
            await self.user_grouping(messageAuthor, messageAuthorID)

    async def user_grouping(self, messageAuthor, messageAuthorID):
        sqldb = await get_mysql_connection()
        try:
            group_names = []
            # Check if the user is the broadcaster
            if messageAuthor == CHANNEL_NAME:
                return
            # Check if there was a user passed
            if messageAuthor == "None":
                return
            async with sqldb.cursor() as cursor:
                # Check if the user is a moderator
                if await is_user_mod(messageAuthor):
                    group_names = ["MOD"]  # Override any other groups
                else:
                    # Check if the user is a VIP
                    if await is_user_vip(messageAuthor):
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
                            bot_logger.info(f"User '{messageAuthor}' assigned to group '{name}' successfully.")
                        except aiomysql.IntegrityError:
                            bot_logger.error(f"Failed to assign user '{messageAuthor}' to group '{name}'.")
                    else:
                        bot_logger.error(f"Group '{name}' does not exist.")
        except Exception as e:
            bot_logger.error(f"An error occurred in user_grouping: {e}")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='commands', aliases=['cmds'])
    async def commands_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("commands",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return

                is_mod = await command_permissions(ctx.author)
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
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='bot')
    async def bot_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("bot",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                chat_logger.info(f"{ctx.author.name} ran the Bot Command.")
                await ctx.send(f"This amazing bot is built by the one and the only gfaUnDead.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='version')
    async def version_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("version",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                global botstarted
                uptime = datetime.now() - botstarted
                uptime_days = uptime.days
                uptime_hours, remainder = divmod(uptime.seconds, 3600)
                uptime_minutes, _ = divmod(remainder, 60)
                message = f"The version that is currently running is V{VERSION}. "
                message += f"Bot started at {botstarted.strftime('%Y-%m-%d %H:%M:%S')}, uptime is "
                if uptime_days > 0:
                    message += f"{uptime_days} days, "
                if uptime_hours > 0:
                    message += f"{uptime_hours} hours, "
                if uptime_minutes > 0 or (uptime_days == 0 and uptime_hours == 0):
                    message += f"{uptime_minutes} minutes, "
                await ctx.send(f"{message[:-2]}")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='roadmap')
    async def roadmap_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("roadmap",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                await ctx.send("Here's the roadmap for the bot: https://trello.com/b/EPXSCmKc/specterbot")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='weather')
    async def weather_command(self, ctx, location: str = None) -> None:
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("weather",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                if location:
                    if ' ' in location:
                        await ctx.send("Please provide the location in the format: City,CountryCode (e.g. Sydney,AU)")
                        return
                    weather_info = await get_weather(location)
                else:
                    location = await get_streamer_weather()
                    if location:
                        weather_info = await get_weather(location)
                    else:
                        weather_info = "I'm sorry, something went wrong trying to get the current weather."
                await ctx.send(weather_info)
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='time')
    async def time_command(self, ctx, timezone: str = None) -> None:
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("time",))
                result = await cursor.fetchone()
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
                    await cursor.execute("SELECT timezone FROM profile")
                    result = await cursor.fetchone()
                    if result and result[0]:
                        timezone = result[0]
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
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='joke')
    async def joke_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("joke",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                joke = await Jokes()
                get_joke = await joke.get_joke(blacklist=['nsfw', 'racist', 'sexist', 'political', 'religious'])
                category = get_joke["category"]
                if get_joke["type"] == "single":
                    await ctx.send(f"Here's a joke from {category}: {get_joke['joke']}")
                else:
                    await ctx.send(f"Here's a joke from {category}:")
                    await ctx.send(f"{get_joke['setup']}")
                    await asyncio.sleep(2)
                    await ctx.send(f"{get_joke['delivery']}")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='quote')
    async def quote_command(self, ctx, number: int = None):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("quote",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                if number is None:  # If no number is provided, get a random quote
                    await cursor.execute("SELECT quote FROM quotes ORDER BY RAND() LIMIT 1")
                    quote = await cursor.fetchone()
                    if quote:
                        await ctx.send("Random Quote: " + quote[0])
                    else:
                        await ctx.send("No quotes available.")
                else:  # If a number is provided, retrieve the quote by its ID
                    await cursor.execute("SELECT quote FROM quotes WHERE id = %s", (number,))
                    quote = await cursor.fetchone()
                    if quote:
                        await ctx.send(f"Quote {number}: " + quote[0])
                    else:
                        await ctx.send(f"No quote found with ID {number}.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='quoteadd')
    async def quote_add_command(self, ctx, *, quote):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("quoteadd",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                await cursor.execute("INSERT INTO quotes (quote) VALUES (%s)", (quote,))
                await sqldb.commit()
                await ctx.send("Quote added successfully: " + quote)
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='removequote')
    async def quote_remove_command(self, ctx, number: int = None):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("removequote",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                if number is None:
                    await ctx.send("Please specify the ID to remove.")
                    return
                await cursor.execute("DELETE FROM quotes WHERE ID = %s", (number,))
                await sqldb.commit()
                await ctx.send(f"Quote {number} has been removed.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='permit')
    async def permit_command(ctx, permit_user: str = None):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("permit",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                if await command_permissions(ctx.author):
                    permit_user = permit_user.lstrip('@')
                    if permit_user:
                        permitted_users[permit_user] = time.time() + 30
                        await ctx.send(f"{permit_user} is now permitted to post links for the next 30 seconds.")
                    else:
                        await ctx.send("Please specify a user to permit.")
                else:
                    chat_logger.info(f"{ctx.author.name} tried to use the command, !permit, but couldn't as they are not a moderator.")
                    await ctx.send("You must be a moderator or the broadcaster to use this command.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='settitle')
    async def set_title_command(self, ctx, *, title: str = None) -> None:
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("settitle",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                if await command_permissions(ctx.author):
                    if title is None:
                        await ctx.send("Stream titles cannot be blank. You must provide a title for the stream.")
                        return
                    # Update the stream title
                    await trigger_twitch_title_update(title)
                    twitch_logger.info(f'Setting stream title to: {title}')
                    await ctx.send(f'Stream title updated to: {title}')
                else:
                    await ctx.send("You must be a moderator or the broadcaster to use this command.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='setgame')
    async def set_game_command(self, ctx, *, game: str = None) -> None:
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("setgame",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                if await command_permissions(ctx.author):
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
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='song')
    async def get_current_song_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("song",))
                result = await cursor.fetchone()
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
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='timer')
    async def start_timer_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("timer",))
                result = await cursor.fetchone()
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
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='hug')
    async def hug_command(self, ctx, *, mentioned_username: str = None):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("hug",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                if mentioned_username:
                    target_user = mentioned_username.lstrip('@')
                    # Increment hug count in the database
                    await cursor.execute(
                        'INSERT INTO hug_counts (username, hug_count) VALUES (%s, 1) '
                        'ON DUPLICATE KEY UPDATE hug_count = hug_count + 1', 
                        (target_user,)
                    )
                    await sqldb.commit()
                    # Retrieve the updated count
                    await cursor.execute('SELECT hug_count FROM hug_counts WHERE username = %s', (target_user,))
                    hug_count = await cursor.fetchone()[0]
                    # Send the message
                    chat_logger.info(f"{target_user} has been hugged by {ctx.author.name}. They have been hugged: {hug_count}")
                    await ctx.send(f"@{target_user} has been hugged by @{ctx.author.name}, they have been hugged {hug_count} times.")
                else:
                    chat_logger.info(f"{ctx.author.name} tried to run the command without user mentioned.")
                    await ctx.send("Usage: !hug @username")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='kiss')
    async def kiss_command(self, ctx, *, mentioned_username: str = None):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("kiss",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                if mentioned_username:
                    target_user = mentioned_username.lstrip('@')
                    # Increment kiss count in the database
                    await cursor.execute(
                        'INSERT INTO kiss_counts (username, kiss_count) VALUES (%s, 1) '
                        'ON DUPLICATE KEY UPDATE kiss_count = kiss_count + 1', 
                        (target_user,)
                    )
                    await sqldb.commit()
                    # Retrieve the updated count
                    await cursor.execute('SELECT kiss_count FROM kiss_counts WHERE username = %s', (target_user,))
                    kiss_count = await cursor.fetchone()[0]
                    # Send the message
                    chat_logger.info(f"{target_user} has been kissed by {ctx.author.name}. They have been kissed: {kiss_count}")
                    await ctx.send(f"@{target_user} has been kissed by @{ctx.author.name}, they have been kissed {kiss_count} times.")
                else:
                    chat_logger.info(f"{ctx.author.name} tried to run the command without user mentioned.")
                    await ctx.send("Usage: !kiss @username")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='ping')
    async def ping_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("ping",))
                result = await cursor.fetchone()
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
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='translate')
    async def translate_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("translate",))
                result = await cursor.fetchone()
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
                    translate_message = GoogleTranslator(source='auto', target='en').translate(text=message)
                    await ctx.send(f"Translation: {translate_message}")
                except AttributeError as ae:
                    chat_logger.error(f"AttributeError: {ae}")
                    await ctx.send("An error occurred while detecting the language.")
                except Exception as e:
                    chat_logger.error(f"Translating error: {e}")
                    await ctx.send("An error occurred while translating the message.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='cheerleader')
    async def cheerleader_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("cheerleader",))
                result = await cursor.fetchone()
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
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='mybits')
    async def mybits_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("mybits",))
                result = await cursor.fetchone()
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
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='lurk')
    async def lurk_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("lurk",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
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
                    # User was lurking before
                    previous_start_time = result[0]
                    # Convert previous_start_time from string to datetime
                    previous_start_time = datetime.strptime(previous_start_time, "%Y-%m-%d %H:%M:%S")
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
                await cursor.execute('INSERT INTO lurk_times (user_id, start_time) VALUES (%s, %s) ON DUPLICATE KEY UPDATE start_time = %s', (user_id, formatted_datetime, formatted_datetime))
                await sqldb.commit()
        except Exception as e:
            chat_logger.error(f"Error in lurk_command: {e}")
            await ctx.send(f"Oops, something went wrong while trying to lurk.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='lurking')
    async def lurking_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("lurking",))
                result = await cursor.fetchone()
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
                    await cursor.execute('SELECT start_time FROM lurk_times WHERE user_id = %s', (user_id,))
                    result = await cursor.fetchone()
                    if result:
                        start_time = result[0]
                        # Convert start_time from string to datetime
                        start_time = datetime.strptime(start_time, "%Y-%m-%d %H:%M:%S")
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

    @commands.command(name='lurklead')
    async def lurklead_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("lurklead",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                try:
                    await cursor.execute('SELECT user_id, start_time FROM lurk_times')
                    lurkers = await cursor.fetchall()
                    longest_lurk = None
                    longest_lurk_user_id = None
                    now = datetime.now()
                    for user_id, start_time in lurkers:
                        # Convert start_time from string to datetime
                        start_time = datetime.strptime(start_time, "%Y-%m-%d %H:%M:%S")
                        lurk_duration = now - start_time
                        if longest_lurk is None or lurk_duration.total_seconds() > longest_lurk.total_seconds():
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
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='unlurk', aliases=('back',))
    async def unlurk_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("unlurk",))
                result = await cursor.fetchone()
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
                    await cursor.execute('SELECT start_time FROM lurk_times WHERE user_id = %s', (user_id,))
                    result = await cursor.fetchone()
                    if result:
                        start_time = result[0]
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
                        await cursor.execute('DELETE FROM lurk_times WHERE user_id = %s', (user_id,))
                        await sqldb.commit()
                    else:
                        await ctx.send(f"{ctx.author.name} has returned from lurking, welcome back!")
                except Exception as e:
                    chat_logger.error(f"Error in unlurk_command: {e}")
                    await ctx.send(f"Oops, something went wrong with the unlurk command.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='clip')
    async def clip_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("clip",))
                result = await cursor.fetchone()
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
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='marker')
    async def marker_command(self, ctx, *, description: str):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("marker",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                if await command_permissions(ctx.author):
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
                    await ctx.send("You must be a moderator or the broadcaster to use this command.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='subscription', aliases=['mysub'])
    async def subscription_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("subscription",))
                result = await cursor.fetchone()
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
                        await ctx.send("Failed to retrieve subscription information. Please try again later.")
                        twitch_logger.error(f"Failed to retrieve subscription information. Status code: {subscription_response.status_code}")
                except requests.exceptions.RequestException as e:
                    twitch_logger.error(f"Error retrieving subscription information: {e}")
                    await ctx.send("An error occurred while making the request. Please try again later.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='uptime')
    async def uptime_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("uptime",))
                result = await cursor.fetchone()
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
        finally:
            await sqldb.ensure_closed()
    
    @commands.command(name='typo')
    async def typo_command(self, ctx, *, mentioned_username: str = None):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("typo",))
                result = await cursor.fetchone()
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
                await cursor.execute('INSERT INTO user_typos (username, typo_count) VALUES (%s, 1) ON DUPLICATE KEY UPDATE typo_count = typo_count + 1', (target_user,))
                await sqldb.commit()
                # Retrieve the updated count
                await cursor.execute('SELECT typo_count FROM user_typos WHERE username = %s', (target_user,))
                typo_count = await cursor.fetchone()[0]
                # Send the message
                chat_logger.info(f"{target_user} has made a new typo in chat, their count is now at {typo_count}.")
                await ctx.send(f"Congratulations {target_user}, you've made a typo! You've made a typo in chat {typo_count} times.")
        except Exception as e:
            chat_logger.error(f"Error in typo_command: {e}")
            await ctx.send(f"An error occurred while trying to add to your typo count.")
        finally:
            await sqldb.ensure_closed()
    
    @commands.command(name='typos', aliases=('typocount',))
    async def typos_command(self, ctx, *, mentioned_username: str = None):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("typos",))
                result = await cursor.fetchone()
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
                await cursor.execute('SELECT typo_count FROM user_typos WHERE username = %s', (target_user,))
                result = await cursor.fetchone()
                typo_count = result[0] if result else 0
                # Send the message
                chat_logger.info(f"{target_user} has made {typo_count} typos in chat.")
                await ctx.send(f"{target_user} has made {typo_count} typos in chat.")
        except Exception as e:
            chat_logger.error(f"Error in typos_command: {e}")
            await ctx.send(f"An error occurred while trying to check typos.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='edittypos', aliases=('edittypo',))
    async def edit_typo_command(self, ctx, mentioned_username: str = None, new_count: int = None):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("edittypos",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                if await command_permissions(ctx.author):
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
                else:
                    await ctx.send(f"You must be a moderator or the broadcaster to use this command.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='removetypos', aliases=('removetypo',))
    async def remove_typos_command(self, ctx, mentioned_username: str = None, decrease_amount: int = 1):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("removetypos",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                if await command_permissions(ctx.author):
                    # Ensure a username is mentioned
                    if mentioned_username is None:
                        chat_logger.error("Command missing username parameter.")
                        await ctx.send(f"Usage: !removetypos @username")
                        return
                    # Determine the target user: mentioned user or the command caller
                    mentioned_username_lower = mentioned_username.lower() if mentioned_username else ctx.author.name.lower()
                    target_user = mentioned_username_lower.lstrip('@')
                    chat_logger.info(f"Remove Typos Command ran with params")
                    # Validate decrease_amount is non-negative
                    if decrease_amount < 0:
                        chat_logger.error(f"Invalid decrease amount {decrease_amount} for typo count of {target_user}.")
                        await ctx.send(f"Remove amount cannot be negative.")
                        return
                    # Check if the user exists in the database
                    await cursor.execute('SELECT typo_count FROM user_typos WHERE username = %s', (target_user,))
                    result = await cursor.fetchone()
                    if result:
                        current_count = result[0]
                        new_count = max(0, current_count - decrease_amount)  # Ensure count doesn't go below 0
                        await cursor.execute('UPDATE user_typos SET typo_count = %s WHERE username = %s', (new_count, target_user))
                        await sqldb.commit()
                        await ctx.send(f"Typo count for {target_user} decreased by {decrease_amount}. New count: {new_count}.")
                    else:
                        await ctx.send(f"No typo record found for {target_user}.")
                else:
                    await ctx.send(f"You must be a moderator or the broadcaster to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in remove_typos_command: {e}")
            await ctx.send(f"An error occurred while trying to remove typos.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='steam')
    async def steam_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("steam",))
                result = await cursor.fetchone()
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
        except Exception as e:
            chat_logger.error(f"Error in steam_command: {e}")
            await ctx.send("An error occurred while trying to check the Steam store.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='deaths')
    async def deaths_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("deaths",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                try:
                    global current_game
                    chat_logger.info("Deaths command ran.")
                    # Retrieve the game-specific death count
                    await cursor.execute('SELECT death_count FROM game_deaths WHERE game_name = %s', (current_game,))
                    game_death_count_result = await cursor.fetchone()
                    game_death_count = game_death_count_result[0] if game_death_count_result else 0
                    # Retrieve the total death count
                    await cursor.execute('SELECT death_count FROM total_deaths')
                    total_death_count_result = await cursor.fetchone()
                    total_death_count = total_death_count_result[0] if total_death_count_result else 0
                    chat_logger.info(f"{ctx.author.name} has reviewed the death count for {current_game}. Total deaths are: {total_death_count}")
                    await ctx.send(f"We have died {game_death_count} times in {current_game}, with a total of {total_death_count} deaths in all games.")
                except Exception as e:
                    await ctx.send(f"An error occurred while executing the command. {e}")
                    chat_logger.error(f"Error in deaths_command: {e}")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='deathadd', aliases=['death+'])
    async def deathadd_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("deathadd",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        chat_logger.info("Death Add Command is disabled.")
                        return
                else:
                    chat_logger.info("No status found for Death Add Command.")
                if await command_permissions(ctx.author):
                    global current_game
                    try:
                        chat_logger.info("Death Add Command ran by a mod or broadcaster.")
                        # Ensure there is exactly one row in total_deaths
                        await cursor.execute("SELECT COUNT(*) FROM total_deaths")
                        count_result = await cursor.fetchone()
                        if count_result is not None and count_result[0] == 0:
                            await cursor.execute("INSERT INTO total_deaths (death_count) VALUES (0)")
                            await sqldb.commit()
                            chat_logger.info("Initialized total_deaths table.")
                        # Increment game-specific death count & total death count
                        await cursor.execute(
                            'INSERT INTO game_deaths (game_name, death_count) VALUES (%s, 1) ON DUPLICATE KEY UPDATE death_count = death_count + 1',
                            (current_game,))
                        await cursor.execute('UPDATE total_deaths SET death_count = death_count + 1')
                        await sqldb.commit()
                        chat_logger.info("Updated death counts in game_deaths and total_deaths tables.")
                        # Retrieve updated counts
                        await cursor.execute('SELECT death_count FROM game_deaths WHERE game_name = %s', (current_game,))
                        game_death_count_result = await cursor.fetchone()
                        game_death_count = game_death_count_result[0] if game_death_count_result else 0
                        await cursor.execute('SELECT death_count FROM total_deaths')
                        total_death_count_result = await cursor.fetchone()
                        total_death_count = total_death_count_result[0] if total_death_count_result else 0
                        chat_logger.info(f"{current_game} now has {game_death_count} deaths.")
                        chat_logger.info(f"Total Death count has been updated to: {total_death_count}")
                        await ctx.send(f"We have died {game_death_count} times in {current_game}, with a total of {total_death_count} deaths in all games.")
                    except Exception as e:
                        await ctx.send(f"An error occurred while executing the command. {e}")
                        chat_logger.error(f"Error in deathadd_command: {e}")
                else:
                    chat_logger.info(f"{ctx.author.name} tried to use the command, death add, but couldn't as they are not a moderator.")
                    await ctx.send("You must be a moderator or the broadcaster to use this command.")
        except Exception as e:
            chat_logger.error(f"Unexpected error in deathadd_command: {e}")
            await ctx.send(f"An unexpected error occurred: {e}")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='deathremove', aliases=['death-',])
    async def deathremove_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("deathremove",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                if await command_permissions(ctx.author):
                    global current_game
                    try:
                        chat_logger.info("Death Remove Command Ran")
                        # Decrement game-specific death count & total death count (ensure it doesn't go below 0)
                        await cursor.execute('UPDATE game_deaths SET death_count = CASE WHEN death_count > 0 THEN death_count - 1 ELSE 0 END WHERE game_name = %s', (current_game,))
                        await cursor.execute('UPDATE total_deaths SET death_count = CASE WHEN death_count > 0 THEN death_count - 1 ELSE 0 END')
                        await sqldb.commit()
                        # Retrieve updated counts
                        await cursor.execute('SELECT death_count FROM game_deaths WHERE game_name = %s', (current_game,))
                        game_death_count_result = await cursor.fetchone()
                        game_death_count = game_death_count_result[0] if game_death_count_result else 0
                        await cursor.execute('SELECT death_count FROM total_deaths')
                        total_death_count_result = await cursor.fetchone()
                        total_death_count = total_death_count_result[0] if total_death_count_result else 0
                        # Send the message
                        chat_logger.info(f"{current_game} death has been removed, we now have {game_death_count} deaths.")
                        chat_logger.info(f"Total Death count has been updated to: {total_death_count} to reflect the removal.")
                        await ctx.send(f"Death removed from {current_game}, count is now {game_death_count}. Total deaths in all games: {total_death_count}.")
                    except Exception as e:
                        await ctx.send(f"An error occurred while executing the command. {e}")
                        chat_logger.error(f"Error in deaths_command: {e}")
                else:
                    chat_logger.info(f"{ctx.author.name} tried to use the command, death remove, but couldn't as they are not a moderator.")
                    await ctx.send("You must be a moderator or the broadcaster to use this command.")
        finally:
            await sqldb.ensure_closed()
    
    @commands.command(name='game')
    async def game_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("game",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                global current_game
                if current_game is not None:
                    await ctx.send(f"The current game we're playing is: {current_game}")
                else:
                    await ctx.send("We're not currently streaming any specific game category.")
        except Exception as e:
            chat_logger.error(f"Error in game_command: {e}")
            await ctx.send("Oops, something went wrong while trying to retrieve the game information.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='followage')
    async def followage_command(self, ctx, *, mentioned_username: str = None):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("followage",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
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
                            mentioned_user_id = user_info[0].id
                            params = {
                                'from_id': mentioned_user_id,
                                'to_id': CHANNEL_ID
                            }
                        else:
                            await ctx.send(f"User {target_user} not found.")
                            return
                    else:
                        params = {
                            'from_id': ctx.author.id,
                            'to_id': CHANNEL_ID
                        }
                    async with aiohttp.ClientSession() as session:
                        async with session.get('https://api.twitch.tv/helix/users/follows', headers=headers, params=params) as response:
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
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='schedule')
    async def schedule_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("schedule",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                await cursor.execute("SELECT timezone FROM profile")
                timezone_row = await cursor.fetchone()
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
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='checkupdate')
    async def check_update_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("checkupdate",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
            if command_permissions(ctx.author):
                REMOTE_VERSION_URL = "https://api.botofthespecter.com/version_control.txt"
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
        finally:
            await sqldb.ensure_closed()
    
    @commands.command(name='shoutout', aliases=('so',))
    async def shoutout_command(self, ctx, user_to_shoutout: str = None):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("shoutout",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
            if command_permissions(ctx.author):
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
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='addcommand')
    async def add_command_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("addcommand",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
            # Check if the user is a moderator or the broadcaster
            if command_permissions(ctx.author):
                # Parse the command and response from the message
                try:
                    command, response = ctx.message.content.strip().split(' ', 1)[1].split(' ', 1)
                except ValueError:
                    await ctx.send(f"Invalid command format. Use: !addcommand [command] [response]")
                    return

                # Insert the command and response into the database
                async with sqldb.cursor() as cursor:
                    await cursor.execute('INSERT INTO custom_commands (command, response, status) VALUES (%s, %s, %s)', (command, response, 'Enabled'))
                    await sqldb.commit()
                chat_logger.info(f"{ctx.author.name} has added the command !{command} with the response: {response}")
                await ctx.send(f'Custom command added: !{command}')
            else:
                await ctx.send(f"You must be a moderator or the broadcaster to use this command.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='removecommand')
    async def remove_command_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("removecommand",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
            # Check if the user is a moderator or the broadcaster
            if command_permissions(ctx.author):
                try:
                    command = ctx.message.content.strip().split(' ')[1]
                except IndexError:
                    await ctx.send(f"Invalid command format. Use: !removecommand [command]")
                    return
                # Delete the command from the database
                async with sqldb.cursor() as cursor:
                    await cursor.execute('DELETE FROM custom_commands WHERE command = %s', (command,))
                    await sqldb.commit()
                chat_logger.info(f"{ctx.author.name} has removed {command}")
                await ctx.send(f'Custom command removed: !{command}')
            else:
                await ctx.send(f"You must be a moderator or the broadcaster to use this command.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='disablecommand')
    async def disable_command_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("disablecommand",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
            # Check if the user is a moderator or the broadcaster
            if command_permissions(ctx.author):
                try:
                    command = ctx.message.content.strip().split(' ')[1]
                except IndexError:
                    await ctx.send(f"Invalid command format. Use: !disablecommand [command]")
                    return

                # Disable the command in the database
                async with sqldb.cursor() as cursor:
                    await cursor.execute('UPDATE builtin_commands SET status = %s WHERE command = %s', ('Disabled', command))
                    await sqldb.commit()
                chat_logger.info(f"{ctx.author.name} has disabled the command: {command}")
                await ctx.send(f'Custom command disabled: !{command}')
            else:
                await ctx.send(f"You must be a moderator or the broadcaster to use this command.")
        finally:
            await sqldb.ensure_closed()

    @commands.command(name='slots')
    async def slots_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("slots",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                slots = ["🍒", "🍋", "🍊", "🍉", "🍇", "🍓", "⭐"]
                result = [random.choice(slots) for _ in range(3)]
                if result[0] == result[1] == result[2]:
                    message = f"{ctx.author.name}, {''.join(result)}"
                    message += f" You Win!"
                else:
                    message = f"{ctx.author.name}, {''.join(result)}"
                    message += f" Better luck next time."
                await ctx.send(message)
        finally:
            await sqldb.ensure_closed()

    @commands.command(name="kill")
    async def kill_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("kill",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                response = requests.get("https://api.botofthespecter.com/killCommand.json")
                data = response.json()
                kill_message = data
                if ctx.message.mentions:
                    target = ctx.message.mentions[0].name
                    message_key = [key for key in kill_message if "other" in key]
                    message = random.choice([kill_message[key] for key in message_key])
                    result = message.replace("$1", ctx.author.name).replace("$2", target)
                else:
                    message_key = [key for key in kill_message if "self" in key]
                    message = random.choice([kill_message[key] for key in message_key])
                    result = message.replace("$1", ctx.author.name)
                message = result
                await ctx.send(message)
        finally:
            await sqldb.ensure_closed()

    @commands.command(name="roulette")
    async def roulette_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("roulette",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                outcomes = [
                    f"and survives!"
                    f"and gets shot!"
                ]
                result = random.choice(outcomes)
                message = f"{ctx.author.name} pulls the trigger...{result}"
                await ctx.send(message)
        finally:
            await sqldb.ensure_closed()

    @commands.command(name="rps")
    async def rps_command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("rps",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                    choices = ["rock","paper","scissors"]
                    bot_choice = random.choice(choices)
                    user_input = ctx.message.content.split(' ')[1].lower() if len(ctx.message.content.split(' ')) > 1 else None
                    if user_input not in choices:
                        await ctx.send(f'Please choose "Rock", "Paper" or "Scissors". Usage: !rps <choice>')
                        return
                    user_choice = user_input
                    if user_choice == bot_choice:
                        result = f"It's a tie! We both chose {bot_choice}."
                    elif (user_choice == 'rock' and bot_choice == 'Scissors') or \
                        (user_choice == 'paper' and bot_choice == 'Rock') or \
                        (user_choice == 'scissors' and bot_choice == 'Paper'):
                        result = f"You Win! You chose {user_choice} and I chose {bot_choice}."
                    else:
                        result = f"You lose! You chose {user_choice} and I chose {bot_choice}"
                    message = result
                    await ctx.send(message)
        finally:
            await sqldb.ensure_closed()

    @commands.command(name="story")
    async def command(self, ctx):
        sqldb = await get_mysql_connection()
        try:
            async with sqldb.cursor() as cursor:
                await cursor.execute("SELECT status FROM builtin_commands WHERE command=%s", ("rps",))
                result = await cursor.fetchone()
                if result:
                    status = result[0]
                    if status == 'Disabled':
                        return
                    words = ctx.message.content.split(' ')[1:]
                    if len(words) < 5:
                        await ctx.send(f"{ctx.author.name}, please provide 5 words. (noun, verb, adjective, adverb, action) Usage: !story <word1> <word2> <word3> <word4> <word5>")
                        return
                    template = f"Once upon a time, there was a {0} who loved to {1}. One day, they found a {2} {3} and decided to {4}."
                    story = template.format(*words)
                    response = openai.Completion.create(
                        engine="gpt-3.5-turbo",
                        prompt=story,
                        max_tokens=100
                    )
                    generated = response.choices[0].text.strip()
                    await ctx.send(generated)
        finally:
            await sqldb.ensure_closed()

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
async def command_permissions(user):
    # Check if the user is the bot owner
    if user.name == 'gfaundead':
        twitch_logger.info(f"User is gfaUnDead. (Bot owner)")
        return True

    # Check if the user is the broadcaster
    elif user.name == CHANNEL_NAME:
        twitch_logger.info(f"User {user.name} is the Broadcaster")
        return True

    # Check if the user is a moderator
    elif await is_user_mod(user.name):
        return True

    # If none of the above, the user is neither the bot owner, broadcaster, nor a moderator
    else:
        twitch_logger.info(f"User {user.name} does not have required permissions.")
        return False

async def is_user_mod(username):
    sqldb = await get_mysql_connection()
    async with sqldb.cursor() as cursor:
        try:
            # Query the database to check if the user is a moderator
            await cursor.execute("SELECT group_name FROM everyone WHERE username = %s", (username,))
            result = await cursor.fetchone()
            if result and result[0] == 'MOD':
                twitch_logger.info(f"User {username} is a Moderator")
                return True
            else:
                return False
        except Exception as e:
            twitch_logger.error(f"An error occurred in is_user_mod: {e}")
            return False
        finally:
            await sqldb.ensure_closed()

# Function to check if a user is a VIP of the channel using the Twitch API
async def is_user_vip(username):
    sqldb = await get_mysql_connection()
    async with sqldb.cursor() as cursor:
        try:
            # Query the database to check if the user is a VIP
            await cursor.execute("SELECT group_name FROM everyone WHERE username = %s", (username,))
            result = await cursor.fetchone()
            if result and result[0] == 'VIP':
                twitch_logger.info(f"User ID {username} is a VIP Member")
                return True
            else:
                twitch_logger.info(f"User ID {username} is not a VIP Member")
                return False
        except Exception as e:
            twitch_logger.error(f"An error occurred in is_user_vip: {e}")
            return False
        finally:
            await sqldb.ensure_closed()


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

# Function to add user to the table of known users
async def user_is_seen(username):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor() as cursor:
            await cursor.execute('INSERT INTO seen_users (username, status) VALUES (%s, %s)', (username, "True"))
            await sqldb.commit()
    except Exception as e:
        bot_logger.error(f"Error occurred while adding user '{username}' to seen_users table: {e}")
    finally:
        await sqldb.ensure_closed()

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
async def update_custom_count(command):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor() as cursor:
            await cursor.execute('SELECT count FROM custom_counts WHERE command = %s', (command,))
            result = await cursor.fetchone()
            if result:
                current_count = result[0]
                new_count = current_count + 1
                await cursor.execute('UPDATE custom_counts SET count = %s WHERE command = %s', (new_count, command))
            else:
                await cursor.execute('INSERT INTO custom_counts (command, count) VALUES (%s, %s)', (command, 1))
        await sqldb.commit()
    finally:
        await sqldb.ensure_closed()

async def get_custom_count(command):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor() as cursor:
            await cursor.execute('SELECT count FROM custom_counts WHERE command = %s', (command,))
            result = await cursor.fetchone()
            if result:
                return result[0]
            else:
                return 0
    finally:
        await sqldb.ensure_closed()

# Functions for weather
async def get_streamer_weather():
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor() as cursor:
            await cursor.execute("SELECT weather_location FROM profile")
            info = await cursor.fetchone()
            if info:
                location = info[0]
                chat_logger.info(f"Got {location} weather info.")
                return location
            else:
                return None
    finally:
        await sqldb.ensure_closed()

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
        wind_direction = await getWindDirection(weather.wind()['deg'])

        return f"The weather in {location} is {status} with a temperature of {temperature}°C ({temperature_f}°F). Wind is blowing from the {wind_direction} at {wind_speed}kph ({wind_speed_mph}mph) and the humidity is {humidity}%."
    except pyowm.exceptions.NotFoundError:
        return f"Location '{location}' not found."
    except AttributeError:
        return f"An error occurred while processing the weather data for '{location}'."

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
        image_data = data['data'][0].get('thumbnail_url', None)
    else:
        current_game = None
        image_data = None

    if image_data:
        image = image_data.replace("{width}", "1280").replace("{height}", "720")
    else:
        image = None

    # Send a message to the chat announcing the stream is online
    message = f"Stream is now online! Streaming {current_game}" if current_game else "Stream is now online!"
    await send_online_message(message)
    if image:
        await send_to_discord_stream_online(message, image)
    else:
        await send_to_discord_stream_online(message, "")

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
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor() as cursor:
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
        async with sqldb.cursor() as cursor:
            await cursor.execute('TRUNCATE TABLE stream_credits')
            await sqldb.commit()
            bot_logger.info('Stream credits table cleared successfully.')
    except aiomysql.Error as err:
        bot_logger.error(f'Failed to clear stream credits table: {err}')
    finally:
        await sqldb.ensure_closed()

# Function for timed messages
async def timed_message():
    sqldb = await get_mysql_connection()
    async with sqldb.cursor() as cursor:
        global scheduled_tasks
        global stream_online
        if stream_online:
            await cursor.execute('SELECT interval_count, message FROM timed_messages')
            messages = await cursor.fetchall()
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
    await sqldb.ensure_closed()

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
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor() as cursor:
            await cursor.execute('SELECT raid_count, viewers FROM raid_data WHERE raider_id = %s', (from_broadcaster_id,))
            existing_data = await cursor.fetchone()
            if existing_data:
                existing_raid_count, existing_viewer_count = existing_data
                raid_count = existing_raid_count + 1
                viewers = existing_viewer_count + viewer_count
                await cursor.execute('UPDATE raid_data SET raid_count = %s, viewers = %s WHERE raider_id = %s', (raid_count, viewers, from_broadcaster_id))
            else:
                await cursor.execute('INSERT INTO raid_data (raider_id, raider_name, raid_count, viewers) VALUES (%s, %s, %s, %s)', (from_broadcaster_id, from_broadcaster_name, 1, viewer_count))
            await cursor.execute('INSERT INTO stream_credits (username, event, data) VALUES (%s, %s, %s)', (from_broadcaster_name, "raid", viewer_count))
            await sqldb.commit()
        discord_message = f"{from_broadcaster_name} has raided with {viewer_count} viewers!"
        await send_to_discord(discord_message, "New Raid!", "raid.png")
        channel = bot.get_channel(CHANNEL_NAME)
        await channel.send(f"Incredible! {from_broadcaster_name} and {viewer_count} viewers have joined the party! Let's give them a warm welcome!")
    finally:
        await sqldb.ensure_closed()

# Function for BITS
async def process_cheer_event(user_id, user_name, bits):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor() as cursor:
            await cursor.execute('SELECT bits FROM bits_data WHERE user_id = %s OR user_name = %s', (user_id, user_name))
            existing_bits = await cursor.fetchone()
            channel = bot.get_channel(CHANNEL_NAME)
            if existing_bits:
                total_bits = existing_bits[0] + bits
                await cursor.execute('UPDATE bits_data SET bits = %s WHERE user_id = %s OR user_name = %s', (total_bits, user_id, user_name))
                await channel.send(f"Thank you {user_name} for {bits} bits! You've given a total of {total_bits} bits.")
            else:
                await cursor.execute('INSERT INTO bits_data (user_id, user_name, bits) VALUES (%s, %s, %s)', (user_id, user_name, bits))
                discord_message = f"{user_name} just cheered {bits} bits!"
                if bits < 100:
                    image = "cheer.png"
                elif 100 <= bits < 1000:
                    image = "cheer100.png"
                else:
                    image = "cheer1000.png"
                await send_to_discord(discord_message, "New Cheer!", image)
                await channel.send(f"Thank you {user_name} for {bits} bits!")
            await cursor.execute('INSERT INTO stream_credits (username, event, data) VALUES (%s, %s, %s)', (user_name, "bits", bits))
            await sqldb.commit()
    finally:
        await sqldb.ensure_closed()

# Function for Subscriptions
async def process_subscription_event(user_id, user_name, sub_plan, event_months):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor() as cursor:
            await cursor.execute('SELECT sub_plan, months FROM subscription_data WHERE user_id = %s', (user_id,))
            existing_subscription = await cursor.fetchone()
            if existing_subscription:
                existing_sub_plan, db_months = existing_subscription
                if existing_sub_plan != sub_plan:
                    await cursor.execute('UPDATE subscription_data SET sub_plan = %s, months = %s WHERE user_id = %s', (sub_plan, db_months, user_id))
                else:
                    await cursor.execute('UPDATE subscription_data SET months = %s WHERE user_id = %s', (db_months, user_id))
            else:
                await cursor.execute('INSERT INTO subscription_data (user_id, user_name, sub_plan, months) VALUES (%s, %s, %s, %s)', (user_id, user_name, sub_plan, event_months))
            await cursor.execute('INSERT INTO stream_credits (username, event, data) VALUES (%s, %s, %s)', (user_name, "subscriptions", f"{sub_plan} - {event_months} months"))
            await sqldb.commit()
            message = f"Thank you {user_name} for subscribing! You are now a {sub_plan} subscriber for {event_months} months!"
            discord_message = f"{user_name} just subscribed at {sub_plan}!"
            await send_to_discord(discord_message, "New Subscriber!", "sub.png")
            # Send the message to the channel
            channel = bot.get_channel(CHANNEL_NAME)
            await channel.send(message)
    finally:
        await sqldb.ensure_closed()

# Function for Resubscriptions with Messages
async def process_subscription_message_event(user_id, user_name, sub_plan, subscriber_message, event_months):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor() as cursor:
            await cursor.execute('SELECT sub_plan, months FROM subscription_data WHERE user_id = %s', (user_id,))
            existing_subscription = await cursor.fetchone()
            if existing_subscription:
                existing_sub_plan, db_months = existing_subscription
                if existing_sub_plan != sub_plan:
                    await cursor.execute('UPDATE subscription_data SET sub_plan = %s, months = %s WHERE user_id = %s', (sub_plan, db_months, user_id))
                else:
                    await cursor.execute('UPDATE subscription_data SET months = %s WHERE user_id = %s', (db_months, user_id))
            else:
                await cursor.execute('INSERT INTO subscription_data (user_id, user_name, sub_plan, months) VALUES (%s, %s, %s, %s)', (user_id, user_name, sub_plan, event_months))
            await cursor.execute('INSERT INTO stream_credits (username, event, data) VALUES (%s, %s, %s)', (user_name, "subscriptions", event_months))
            await sqldb.commit()
            if subscriber_message.strip():
                message = f"Thank you {user_name} for subscribing at {sub_plan}! Your message: '{subscriber_message}'"
            else:
                message = f"Thank you {user_name} for subscribing at {sub_plan}!"
            discord_message = f"{user_name} just subscribed at {sub_plan}!"
            await send_to_discord(discord_message, "New Subscriber!", "sub.png")
            channel = bot.get_channel(CHANNEL_NAME)
            await channel.send(message)
    finally:
        await sqldb.ensure_closed()

# Function for Gift Subscriptions
async def process_giftsub_event(recipient_user_id, recipient_user_name, sub_plan, user_name, anonymous):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor() as cursor:
            await cursor.execute('SELECT months FROM subscription_data WHERE user_id = %s', (recipient_user_id,))
            existing_months = await cursor.fetchone()
            if existing_months:
                existing_months = existing_months[0]
                updated_months = existing_months + 1
                await cursor.execute('UPDATE subscription_data SET sub_plan = %s, months = %s WHERE user_id = %s', (sub_plan, updated_months, recipient_user_id))
            else:
                await cursor.execute('INSERT INTO subscription_data (user_id, user_name, sub_plan, months) VALUES (%s, %s, %s, %s)', (recipient_user_id, recipient_user_name, sub_plan, 1))
            await cursor.execute('INSERT INTO stream_credits (username, event, data) VALUES (%s, %s, %s)', (recipient_user_name, "subscriptions", f"{sub_plan} - GIFT SUBSCRIPTION"))
            await sqldb.commit()
            if anonymous:
                message = f"Thank you for gifting a {sub_plan} subscription to {recipient_user_name}! They are now a {sub_plan} subscriber!"
                discord_message = f"An Anonymous Gifter just gifted {recipient_user_name} a subscription!"
                await send_to_discord(discord_message, "New Gifted Subscription!", "sub.png")
            else:
                message = f"Thank you {user_name} for gifting a {sub_plan} subscription to {recipient_user_name}! They are now a {sub_plan} subscriber!"
                discord_message = f"{user_name} just gifted {recipient_user_name} a subscription!"
                await send_to_discord(discord_message, "New Gifted Subscription!", "sub.png")
            channel = bot.get_channel(CHANNEL_NAME)
            await channel.send(message)
    finally:
        await sqldb.ensure_closed()

# Function for FOLLOWERS
async def process_followers_event(user_id, user_name, followed_at_twitch):
    sqldb = await get_mysql_connection()
    try:
        followed_at_twitch = followed_at_twitch[:26]
        time_now = datetime.now()
        followed_at = time_now.strftime("%Y-%m-%d %H:%M:%S")
        async with sqldb.cursor() as cursor:
            await cursor.execute('INSERT INTO followers_data (user_id, user_name, followed_at) VALUES (%s, %s, %s)', (user_id, user_name, followed_at))
            await cursor.execute('INSERT INTO stream_credits (username, event, data) VALUES (%s, %s, %s)', (user_name, "follow", 0))
            await sqldb.commit()
        message = f"Thank you {user_name} for following! Welcome to the channel!"
        discord_message = f"{user_name} just followed!"
        await send_to_discord(discord_message, "New Follower!", "follow.png")
        channel = bot.get_channel(CHANNEL_NAME)
        await channel.send(message)
    finally:
        await sqldb.ensure_closed()

# Function to build the Discord Notice
async def send_to_discord(message, title, image):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor() as cursor:
            await cursor.execute("SELECT discord_alert FROM profile")
            result = await cursor.fetchone()
            if not result or not result[0]:
                bot_logger.error("Discord URL not found or is None.")
                return
            discord_url = result[0]
            await cursor.execute("SELECT timezone FROM profile")
            timezone_result = await cursor.fetchone()
            timezone = timezone_result[0] if timezone_result else 'UTC'
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
            try:
                response = requests.post(discord_url, json=payload)
                if response.status_code in [200, 204]:
                    return
                else:
                    bot_logger.error(f"Failed to send to Discord - Error: {response.status_code}")
            except requests.exceptions.RequestException as e:
                bot_logger.error(f"Request to Discord failed: {e}")
    finally:
        await sqldb.ensure_closed()

# Function to build the Discord Mod Notice 
async def send_to_discord_mod(message, title, image):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor() as cursor:
            await cursor.execute("SELECT discord_mod FROM profile")
            result = await cursor.fetchone()
            if not result or not result[0]:
                bot_logger.error("Discord URL for mod notifications not found or is None.")
                return
            discord_url = result[0]
            await cursor.execute("SELECT timezone FROM profile")
            timezone_result = await cursor.fetchone()
            timezone = timezone_result[0] if timezone_result else 'UTC'
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
            try:
                response = requests.post(discord_url, json=payload)
                if response.status_code in [200, 204]:
                    return
                else:
                    bot_logger.error(f"Failed to send to Discord - Error: {response.status_code}")
            except requests.exceptions.RequestException as e:
                bot_logger.error(f"Request to Discord failed: {e}")
    finally:
        await sqldb.ensure_closed()

# Function to build the Discord Notice for Stream Online
async def send_to_discord_stream_online(message, image):
    sqldb = await get_mysql_connection()
    try:
        async with sqldb.cursor() as cursor:
            await cursor.execute("SELECT timezone FROM profile")
            timezone_result = await cursor.fetchone()
            timezone = timezone_result[0] if timezone_result else 'UTC'
            tz = pytz.timezone(timezone)
            current_time = datetime.now(tz)
            time_format_date = current_time.strftime("%B %d, %Y")
            time_format_time = current_time.strftime("%I:%M %p")
            time_format = f"{time_format_date} at {time_format_time}"
            await cursor.execute("SELECT discord_alert_online FROM profile")
            discord_url_result = await cursor.fetchone()
            if discord_url_result:
                discord_url = discord_url_result[0]
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
                response = requests.post(discord_url, json=payload)
                if response.status_code in (200, 204):
                    bot_logger.info(f"Message sent to Discord successfully - Status Code: {response.status_code}")
                else:
                    bot_logger.error(f"Failed to send to Discord - Error: {response.status_code}")
            else:
                bot_logger.error("Discord URL not found.")
    finally:
        await sqldb.ensure_closed()

# Function to conenct to the websocket server and push a notice
async def websocket_notice(channel, event, user):
    sio = socketio.AsyncClient()

    @sio.event
    async def connect():
        # Register with the API Key
        registration_message = {'code': API_TOKEN}
        await sio.emit('REGISTER', registration_message)
        # Await confirmation of successful registration if necessary
        registration_response = await sio.call('REGISTER', registration_message)
        bot_logger.info(f"Registration response: {registration_response}")
        # Construct and send the event message
        event_message = {
            "channel": channel,
            "event": event,
            "user": user
        }
        await sio.emit(event, event_message)
        # Await response from the server if necessary
        event_response = await sio.call(event, event_message)
        bot_logger.info(f"Event response: {event_response}")
    @sio.event
    async def connect_error(data):
        bot_logger.error(f"The connection failed: {data}")
    @sio.event
    async def disconnect():
        pass
    await sio.connect('https://websocket.botofthespecter.com:8080', transports=['websocket'])
    await sio.wait()

# Function to create a new group if it doesn't exist
async def group_creation():
    sqldb = await get_mysql_connection()
    try:
        group_names = ["MOD", "VIP", "Subscriber T1", "Subscriber T2", "Subscriber T3"]
        try:
            async with sqldb.cursor() as cursor:
                # Create placeholders for each group name
                placeholders = ', '.join(['%s'] * len(group_names))
                # Construct the query string with the placeholders
                query = f"SELECT name FROM `groups` WHERE name IN ({placeholders})"
                # Execute the query with the tuple of group names
                await cursor.execute(query, tuple(group_names))
                # Fetch the existing groups from the database
                existing_groups = [row[0] for row in await cursor.fetchall()]
                # Filter out existing groups
                new_groups = [name for name in group_names if name not in existing_groups]
                # Insert new groups
                if new_groups:
                    for name in new_groups:
                        await cursor.execute("INSERT INTO `groups` (name) VALUES (%s)", (name,))
                    await sqldb.commit()  # Commit once after all inserts
                    for name in new_groups:
                        bot_logger.info(f"Group '{name}' created successfully.")
        except aiomysql.Error as err:
            bot_logger.error(f"Failed to create groups: {err}")
    finally:
        await sqldb.ensure_closed()

# Function to create the command in the database if it doesn't exist
async def builtin_commands_creation():
    sqldb = await get_mysql_connection()
    try:
        all_commands = list(mod_commands) + list(builtin_commands)
        async with sqldb.cursor() as cursor:
            # Create placeholders for the query
            placeholders = ', '.join(['%s'] * len(all_commands))
            # Construct the query string with the placeholders
            query = f"SELECT command FROM builtin_commands WHERE command IN ({placeholders})"
            # Execute the query with the tuple of all commands
            await cursor.execute(query, tuple(all_commands))
            # Fetch the existing commands from the database
            existing_commands = [row[0] for row in await cursor.fetchall()]
            # Filter out existing commands
            new_commands = [command for command in all_commands if command not in existing_commands]
            # Insert new commands
            if new_commands:
                values = [(command, 'Enabled') for command in new_commands]
                # Insert query with placeholders for each command
                insert_query = "INSERT INTO builtin_commands (command, status) VALUES (%s, %s)"
                await cursor.executemany(insert_query, values)  # Use executemany here
                await sqldb.commit()
                for command in new_commands:
                    bot_logger.info(f"Command '{command}' added to database successfully.")
    except aiomysql.Error as e:
        bot_logger.error(f"Error: {e}")
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
        # Define the file path with the channel name
        file_path = os.path.join(directory, f"{CHANNEL_NAME}_version_control.txt")
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
                bot_logger.info(f"Bot Restarted, Stream is online. Game: {current_game}")
    return

async def known_users():
    sqldb = await get_mysql_connection()
    try:
        headers = {
            "Authorization": f"Bearer {CHANNEL_AUTH}",
            "Client-Id": TWITCH_API_CLIENT_ID,
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
                    async with sqldb.cursor() as cursor:
                        for mod in mod_list:
                            await cursor.execute("INSERT INTO everyone (username, group_name) VALUES (%s, %s) ON DUPLICATE KEY UPDATE group_name = %s", (mod, "MOD", "MOD"))
                        await sqldb.commit()
                    bot_logger.info(f"Added moderators to the database: {mod_list}")
                else:
                    bot_logger.error(f"Failed to fetch moderators: {response.status} - {await response.text()}")

            # Get all the VIPs and put them into the database
            url_vips = f'https://api.twitch.tv/helix/channels/vips?broadcaster_id={CHANNEL_ID}'
            async with session.get(url_vips, headers=headers) as response:
                if response.status == 200:
                    data = await response.json()
                    vips = data.get('data', [])
                    vip_list = [vip['user_name'] for vip in vips]
                    async with sqldb.cursor() as cursor:
                        for vip in vip_list:
                            await cursor.execute("INSERT INTO everyone (username, group_name) VALUES (%s, %s) ON DUPLICATE KEY UPDATE group_name = %s", (vip, "VIP", "VIP"))
                        await sqldb.commit()
                    bot_logger.info(f"Added VIPs to the database: {vip_list}")
                else:
                    bot_logger.error(f"Failed to fetch VIPs: {response.status} - {await response.text()}")
    except Exception as e:
        bot_logger.error(f"An error occurred in known_users: {e}")
    finally:
        await sqldb.ensure_closed()

async def get_mysql_connection():
    return await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db=CHANNEL_NAME
    )

async def setup_database():
    try:
        conn = await aiomysql.connect(
            host=SQL_HOST,
            user=SQL_USER,
            password=SQL_PASSWORD
        )
        async with conn.cursor() as cursor:
            # Create MySQL database named after the channel, if it doesn't exist
            await cursor.execute("CREATE DATABASE IF NOT EXISTS `{}`".format(CHANNEL_NAME))
            await cursor.execute("USE `{}`".format(CHANNEL_NAME))

            # List of table creation statements
            tables = {
                'everyone': '''
                    CREATE TABLE IF NOT EXISTS everyone (
                        username VARCHAR(255),
                        group_name VARCHAR(255) DEFAULT NULL,
                        PRIMARY KEY (username)
                    ) ENGINE=InnoDB
                ''',
                'groups': '''
                    CREATE TABLE IF NOT EXISTS `groups` (
                        id INT NOT NULL AUTO_INCREMENT,
                        name VARCHAR(255),
                        PRIMARY KEY (id)
                    ) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
                ''',
                'custom_commands': '''
                    CREATE TABLE IF NOT EXISTS custom_commands (
                        command VARCHAR(255),
                        response TEXT,
                        status VARCHAR(255),
                        PRIMARY KEY (command)
                    ) ENGINE=InnoDB
                ''',
                'builtin_commands': '''
                    CREATE TABLE IF NOT EXISTS builtin_commands (
                        command VARCHAR(255),
                        status VARCHAR(255),
                        PRIMARY KEY (command)
                    ) ENGINE=InnoDB
                ''',
                'user_typos': '''
                    CREATE TABLE IF NOT EXISTS user_typos (
                        username VARCHAR(255),
                        typo_count INT DEFAULT 0,
                        PRIMARY KEY (username)
                    ) ENGINE=InnoDB
                ''',
                'lurk_times': '''
                    CREATE TABLE IF NOT EXISTS lurk_times (
                        user_id VARCHAR(255),
                        start_time VARCHAR(255) NOT NULL,
                        PRIMARY KEY (user_id)
                    ) ENGINE=InnoDB
                ''',
                'hug_counts': '''
                    CREATE TABLE IF NOT EXISTS hug_counts (
                        username VARCHAR(255),
                        hug_count INT DEFAULT 0,
                        PRIMARY KEY (username)
                    ) ENGINE=InnoDB
                ''',
                'kiss_counts': '''
                    CREATE TABLE IF NOT EXISTS kiss_counts (
                        username VARCHAR(255),
                        kiss_count INT DEFAULT 0,
                        PRIMARY KEY (username)
                    ) ENGINE=InnoDB
                ''',
                'total_deaths': '''
                    CREATE TABLE IF NOT EXISTS total_deaths (
                        death_count INT DEFAULT 0
                    ) ENGINE=InnoDB
                ''',
                'game_deaths': '''
                    CREATE TABLE IF NOT EXISTS game_deaths (
                        game_name VARCHAR(255),
                        death_count INT DEFAULT 0,
                        PRIMARY KEY (game_name)
                    ) ENGINE=InnoDB
                ''',
                'custom_counts': '''
                    CREATE TABLE IF NOT EXISTS custom_counts (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        command VARCHAR(255) NOT NULL,
                        count INT NOT NULL
                    ) ENGINE=InnoDB
                ''',
                'bits_data': '''
                    CREATE TABLE IF NOT EXISTS bits_data (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        user_id VARCHAR(255),
                        user_name VARCHAR(255),
                        bits INT,
                        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB
                ''',
                'subscription_data': '''
                    CREATE TABLE IF NOT EXISTS subscription_data (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        user_id VARCHAR(255),
                        user_name VARCHAR(255),
                        sub_plan VARCHAR(255),
                        months INT,
                        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB
                ''',
                'followers_data': '''
                    CREATE TABLE IF NOT EXISTS followers_data (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        user_id VARCHAR(255),
                        user_name VARCHAR(255),
                        followed_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB
                ''',
                'raid_data': '''
                    CREATE TABLE IF NOT EXISTS raid_data (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        raider_name VARCHAR(255),
                        raider_id VARCHAR(255),
                        viewers INT,
                        raid_count INT,
                        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB
                ''',
                'quotes': '''
                    CREATE TABLE IF NOT EXISTS quotes (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        quote TEXT
                    ) ENGINE=InnoDB
                ''',
                'seen_users': '''
                    CREATE TABLE IF NOT EXISTS seen_users (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        username VARCHAR(255),
                        welcome_message VARCHAR(255) DEFAULT NULL,
                        status VARCHAR(255)
                    ) ENGINE=InnoDB
                ''',
                'seen_today': '''
                    CREATE TABLE IF NOT EXISTS seen_today (
                        user_id VARCHAR(255),
                        PRIMARY KEY (user_id)
                    ) ENGINE=InnoDB
                ''',
                'timed_messages': '''
                    CREATE TABLE IF NOT EXISTS timed_messages (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        interval_count INT,
                        message TEXT
                    ) ENGINE=InnoDB
                ''',
                'profile': '''
                    CREATE TABLE IF NOT EXISTS profile (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        timezone VARCHAR(255) DEFAULT NULL,
                        weather_location VARCHAR(255) DEFAULT NULL,
                        discord_alert VARCHAR(255) DEFAULT NULL,
                        discord_mod VARCHAR(255) DEFAULT NULL,
                        discord_alert_online VARCHAR(255) DEFAULT NULL
                    ) ENGINE=InnoDB
                ''',
                'protection': '''
                    CREATE TABLE IF NOT EXISTS protection (
                        url_blocking VARCHAR(255),
                        profanity VARCHAR(255)
                    ) ENGINE=InnoDB
                ''',
                'link_whitelist': '''
                    CREATE TABLE IF NOT EXISTS link_whitelist (
                        link VARCHAR(255),
                        PRIMARY KEY (link)
                    ) ENGINE=InnoDB
                ''',
                'link_blacklisting': '''
                    CREATE TABLE IF NOT EXISTS link_blacklisting (
                        link VARCHAR(255),
                        PRIMARY KEY (link)
                    ) ENGINE=InnoDB
                ''',
                'stream_credits': '''
                    CREATE TABLE IF NOT EXISTS stream_credits (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        username VARCHAR(255),
                        event VARCHAR(255),
                        data INT
                    ) ENGINE=InnoDB
                ''',
                'message_counts': '''
                    CREATE TABLE IF NOT EXISTS message_counts (
                        username VARCHAR(255),
                        message_count INT NOT NULL,
                        user_level VARCHAR(255) NOT NULL,
                        PRIMARY KEY (username)
                    ) ENGINE=InnoDB
                ''',
                'channel_point_rewards': '''
                    CREATE TABLE IF NOT EXISTS channel_point_rewards (
                        reward_id VARCHAR(255),
                        reward_title VARCHAR(255),
                        reward_cost VARCHAR(255),
                        custom_message TEXT,
                        PRIMARY KEY (reward_id)
                    ) ENGINE=InnoDB
                ''',
                'poll_results': '''
                    CREATE TABLE IF NOT EXISTS poll_results (
                        poll_id VARCHAR(255),
                        poll_name VARCHAR(255),
                        poll_option_one VARCHAR(255),
                        poll_option_two VARCHAR(255),
                        poll_option_three VARCHAR(255),
                        poll_option_four VARCHAR(255),
                        poll_option_five VARCHAR(255),
                        poll_option_one_results INT,
                        poll_option_two_results INT,
                        poll_option_three_results INT,
                        poll_option_four_results INT,
                        poll_option_five_results INT,
                        bits_used INT,
                        channel_points_used INT,
                        started_at DATETIME,
                        ended_at DATETIME
                    ) ENGINE=InnoDB
                ''',
                'tipping_settings': '''
                    CREATE TABLE IF NOT EXISTS tipping_settings (
                        StreamElements VARCHAR(255) DEFAULT NULL,
                        StreamLabs VARCHAR(255) DEFAULT NULL
                    ) ENGINE=InnoDB
                ''',
                'tipping': '''
                    CREATE TABLE IF NOT EXISTS tipping (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        username VARCHAR(255),
                        amount DECIMAL(10, 2),
                        message TEXT,
                        source VARCHAR(255),
                        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB
                '''
            }

            # Create tables
            for table_name, table_schema in tables.items():
                try:
                    await cursor.execute(table_schema)
                except aiomysql.Error as err:
                    if err.errno == errorcode.ER_TABLE_EXISTS_ERROR:
                        bot_logger.info(f"Table {table_name} already exists.")
                    else:
                        bot_logger.error(f"Error creating table {table_name}: {err}")

            await conn.commit()
    except aiomysql.Error as err:
        bot_logger.error(err)
    finally:
        conn.close()

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

    # Start the bot
    bot.run()

if __name__ == '__main__':
    start_bot()