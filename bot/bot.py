# Standard library imports
import os
import re
import asyncio
import queue
import argparse
import datetime
from datetime import datetime
import logging
import subprocess
import threading

# Third-party imports
import aiohttp
import requests
import sqlite3
from translate import Translator
from googletrans import Translator, LANGUAGES
import twitchio
from twitchio import Client
from twitchio.ext import commands, eventsub, pubsub

# Parse command-line arguments
parser = argparse.ArgumentParser(description="BotOfTheSpecter Chat Bot")
parser.add_argument("-channel", dest="target_channel", required=True, help="Target Twitch channel name")
parser.add_argument("-channelid", dest="channel_id", required=True, help="Twitch user ID")
parser.add_argument("-token", dest="channel_auth_token", required=True, help="Auth Token for authentication")
parser.add_argument("-port", dest="webhook_port", required=True, type=int, help="Port for the webhook server")
args = parser.parse_args()

# Twitch bot settings
CHANNEL_NAME = args.target_channel
CHANNEL_ID = args.channel_id
CHANNEL_AUTH = args.channel_auth_token
WEBHOOK_PORT = args.webhook_port
BOT_USERNAME = "botofthespecter"
VERSION = "1.9.3"
DECAPI = "" # CHANGE TO MAKE THIS WORK
WEBHOOK_SECRET = "" # CHANGE TO MAKE THIS WORK
CALLBACK_URL = f"" # CHANGE TO MAKE THIS WORK
OAUTH_TOKEN = "" # CHANGE TO MAKE THIS WORK
CLIENT_ID = "" # CHANGE TO MAKE THIS WORK
REFRESH_TOKEN = OAUTH_TOKEN
TWITCH_API_CLIENT_ID = CLIENT_ID
CLIENT_SECRET = "" # CHANGE TO MAKE THIS WORK
TWITCH_API_AUTH = "" # CHANGE TO MAKE THIS WORK
builtin_commands = {"commands", "bot", "timer", "ping", "cheerleader", "lurk", "unlurk", "lurking", "lurklead", "hug", "kiss", "uptime", "typo", "typos", "followage", "so", "deaths"}
mod_commands = {"addcommand", "removecommand", "removetypos", "edittypos", "deathadd", "deathremove"}
builtin_aliases = {"cmds", "back", "shoutout", "typocount", "edittypo", "removetypo", "death+", "death-"}

# Logs
webroot = "/var/www/"
logs_directory = "logs"
bot_logs = os.path.join(logs_directory, "bot")
chat_logs = os.path.join(logs_directory, "chat")
twitch_logs = os.path.join(logs_directory, "twitch")
api_logs = os.path.join(logs_directory, "api")

# Ensure directories exist
for directory in [logs_directory, bot_logs, chat_logs, twitch_logs, api_logs]:
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

# Create the bot instance and connect to TwitchAPI
bot = commands.Bot(
    token=OAUTH_TOKEN,
    prefix="!",
    initial_channels=[CHANNEL_NAME],
    nick="BotOfTheSpecter",
)
twitch_logger.info("Created the bot instance")

client = twitchio.Client(token=TWITCH_API_AUTH)
async def main():
    channel_id_int = int(CHANNEL_ID)
    pubsub_pool = pubsub.PubSubPool(client)
    await pubsub_pool.subscribe_channel(REFRESH_TOKEN, channel_id_int, [pubsub.PubSubBits, pubsub.PubSubSubscriptions, pubsub.PubSubChannelPoints])
    await pubsub_pool.listen()

# Create an instance of your Bot class
bot_instance = bot
channel = bot.get_channel('{CHANNEL_NAME}')
# Define the pubsub_client outside the class
pubsub_client = pubsub.PubSubPool(bot_instance)

# Create the database and table if it doesn't exist
commands_directory = "/var/www/bot/commands"
if not os.path.exists(commands_directory):
    os.makedirs(commands_directory)
database_file = os.path.join(commands_directory, f"{CHANNEL_NAME}_commands.db")
conn = sqlite3.connect(database_file)
cursor = conn.cursor()
cursor.execute('''
    CREATE TABLE IF NOT EXISTS custom_commands (
        command TEXT PRIMARY KEY,
        response TEXT
    )
''')
cursor.execute('''
    CREATE TABLE IF NOT EXISTS user_typos (
        username TEXT PRIMARY KEY,
        typo_count INTEGER DEFAULT 0
    )
''')
cursor.execute('''
    CREATE TABLE IF NOT EXISTS lurk_times (
        user_id TEXT PRIMARY KEY,
        start_time TEXT NOT NULL
    )
''')
cursor.execute('''
    CREATE TABLE IF NOT EXISTS hug_counts (
        username TEXT PRIMARY KEY,
        hug_count INTEGER DEFAULT 0
    )
''')
cursor.execute('''
    CREATE TABLE IF NOT EXISTS kiss_counts (
        username TEXT PRIMARY KEY,
        kiss_count INTEGER DEFAULT 0
    )
''')
cursor.execute('''
    CREATE TABLE IF NOT EXISTS total_deaths (
        death_count INTEGER DEFAULT 0
    )
''')
cursor.execute('''
    CREATE TABLE IF NOT EXISTS game_deaths (
        game_name TEXT PRIMARY KEY,
        death_count INTEGER DEFAULT 0
    )
''')
conn.commit()

# Initialize instances for the translator and shoutout queue
translator = Translator(service_urls=['translate.google.com'])
shoutout_queue = queue.Queue()
bot_logger.info("Bot script started.")

# Setup Token Refresh
async def refresh_token_every_day():
    while True:
        await refresh_token(CHANNEL_AUTH)
        await asyncio.sleep(86400)

async def refresh_token(refresh_token):
    global OAUTH_TOKEN, REFRESH_TOKEN
    
    url = 'https://id.twitch.tv/oauth2/token'
    body = {
        'grant_type': 'refresh_token',
        'refresh_token': refresh_token,
        'client_id': CLIENT_ID,
        'client_secret': CLIENT_SECRET,
    }
    async with aiohttp.ClientSession() as session:
        async with session.post(url, data=body) as response:
            response_json = await response.json()
            new_access_token = response_json['access_token']
            new_refresh_token = response_json.get('refresh_token', refresh_token)
            
            # Log the response details
            log_message = "Refresh token response - Access token: %s, Refresh token: %s"
            twitch_logger.info(log_message, new_access_token, new_refresh_token)

    # Now, update the global variables
    OAUTH_TOKEN = new_access_token
    REFRESH_TOKEN = new_refresh_token

class Bot(commands.Bot):
    # Event Message to get the bot ready
    async def event_ready(self):
        bot_logger.info(f'Logged in as | {BOT_USERNAME}')
        channel = self.get_channel(CHANNEL_NAME)
        if channel:
            await channel.send("The bot is now connected and ready!")
            
    # Function to check all messages and push out a custom command.
    async def event_message(self, message):
        # Log the message content & make sure the bot ignores its own messages
        chat_logger.info(f"Chat message from {message.author.name}: {message.content}")
        if message.echo:
            return

        # It's important to process commands if the message is a command
        await self.handle_commands(message)

        # Custom command processing (if not using built-in command processing)
        message_content = message.content.strip()

        # Check if the message starts with an exclamation mark for commands
        if message_content.startswith('!'):
            parts = message_content.split()
            command = parts[0][1:]  # Extract the command without '!'

            # Check if the command exists in a hypothetical database and respond
            cursor.execute('SELECT response FROM custom_commands WHERE command = ?', (command,))
            result = cursor.fetchone()

        if result:
            response = result[0]
            chat_logger.info(f"{command} command ran.")
            await message.channel.send(response)
        else:
            chat_logger.info(f"{command} command not found.")
            await message.channel.send(f'No such command found: !{command}')

    @bot.command(name="commands", aliases=["cmds",])
    async def commands_command(ctx: commands.Context):
        is_mod = is_mod_or_broadcaster(ctx.author)

        if is_mod:
            # If the user is a mod, include both mod_commands and builtin_commands
            all_commands = list(mod_commands) + list(builtin_commands)
            commands_list = ", ".join(sorted(f"!{command}" for command in all_commands))
        else:
            # If the user is not a mod, only include builtin_commands
            commands_list = ", ".join(sorted(f"!{command}" for command in builtin_commands))

        response_message = f"Available commands to you: {commands_list}"

        # Sending the response message to the chat
        await ctx.send(response_message)

    @bot.command(name='bot')
    async def bot_command(ctx: commands.Context):
        chat_logger.info(f"{ctx.author} ran the Bot Command.")
        await ctx.send(f"This amazing bot is built by the one and the only gfaUnDead.")

    @bot.command(name='timer')
    async def start_timer(ctx: commands.Context):
        chat_logger.info(f"Timer command ran.")
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

    @bot.command(name='hug')
    async def hug_command(ctx: commands.Context, *, mentioned_username: str = None):
        if mentioned_username:
            target_user = mentioned_username.lstrip('@')

            # Increment hug count in the database
            cursor.execute('INSERT INTO hug_counts (username, hug_count) VALUES (?, 1) ON CONFLICT(username) DO UPDATE SET hug_count = hug_count + 1', (target_user,))
            conn.commit()

            # Retrieve the updated count
            cursor.execute('SELECT hug_count FROM hug_counts WHERE username = ?', (target_user,))
            hug_count = cursor.fetchone()[0]

            # Send the message
            chat_logger.info(f"{target_user} has been hugged by {ctx.author}. They have been hugged: {hug_count}")
            await ctx.send(f"@{target_user} has been hugged by @{ctx.author.name}, they have been hugged {hug_count} times.")
        else:
            chat_logger.info(f"{ctx.author} tried to run the command without user mentioned.")
            await ctx.send("Usage: !hug @username")

    @bot.command(name='kiss')
    async def kiss_command(ctx: commands.Context, *, mentioned_username: str = None):
        if mentioned_username:
            target_user = mentioned_username.lstrip('@')

            # Increment kiss count in the database
            cursor.execute('INSERT INTO kiss_counts (username, kiss_count) VALUES (?, 1) ON CONFLICT(username) DO UPDATE SET kiss_count = kiss_count + 1', (target_user,))
            conn.commit()

            # Retrieve the updated count
            cursor.execute('SELECT kiss_count FROM kiss_counts WHERE username = ?', (target_user,))
            kiss_count = cursor.fetchone()[0]

            # Send the message
            chat_logger.info(f"{target_user} has been kissed by {ctx.author}. They have been kissed: {kiss_count}")
            await ctx.send(f"@{target_user} has been kissed by @{ctx.author.name}, they have been kissed {kiss_count} times.")
        else:
            chat_logger.info(f"{ctx.author} tried to run the command without user mentioned.")
            await ctx.send("Usage: !kiss @username")

    @bot.command(name='ping')
    async def ping_command(ctx: commands.Context):
        chat_logger.info(f"Ping command ran.")
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
    
    @bot.command(name="translate")
    async def translate_command(ctx: commands.Context):
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

    @bot.command(name='cheerleader')
    async def cheerleader_command(ctx):
        headers = {
            'Client-ID': CLIENT_ID,
            'Authorization': f'Bearer {REFRESH_TOKEN}'
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
        else:
            await ctx.send("Sorry, I couldn't fetch the leaderboard.")

    @bot.command(name='lurk')
    async def lurk_command(ctx: commands.Context):
        try:
            user_id = str(ctx.author.id)
            now = datetime.now()

            if ctx.author.name.lower() == CHANNEL_NAME.lower():
                await ctx.send(f"You cannot lurk in your own channel, Streamer.")
                chat_logger.info(f"{ctx.author.name} tried to lurk in their own channel.")
                return

            # Check if the user is already in the lurk table
            cursor.execute('SELECT start_time FROM lurk_times WHERE user_id = ?', (user_id,))
            result = cursor.fetchone()

            if result:
                # User was lurking before
                previous_start_time = datetime.fromisoformat(result[0])
                lurk_duration = now - previous_start_time

                # Calculate the duration
                days, seconds = divmod(lurk_duration.total_seconds(), 86400)
                hours, remainder = divmod(seconds, 3600)
                minutes, seconds = divmod(remainder, 60)

                # Create time string
                periods = [("days", int(days)), ("hours", int(hours)), ("minutes", int(minutes)), ("seconds", int(seconds))]
                time_string = ", ".join(f"{value} {name}" for name, value in periods if value)

                # Inform the user of their previous lurk time
                await ctx.send(f"Continuing to lurk, {ctx.author.name}? No problem, you've been lurking for {time_string}. I've reset your lurk time.")
                chat_logger.info(f"{ctx.author.name} refreshed their lurk time after {time_string}.")
            else:
                # User is not in the lurk table
                await ctx.send(f"Thanks for lurking, {ctx.author.name}! See you soon.")
                chat_logger.info(f"{ctx.author.name} is now lurking.")

            # Update the start time in the database
            cursor.execute('INSERT OR REPLACE INTO lurk_times (user_id, start_time) VALUES (?, ?)', (user_id, now.isoformat()))
            conn.commit()
        except Exception as e:
            chat_logger.error(f"Error in lurk_command: {e}")
            await ctx.send(f"Oops, something went wrong while trying to lurk.")

    @bot.command(name="lurking")
    async def lurking_command(ctx: commands.Context):
        try:
            user_id = str(ctx.author.id)
    
            if ctx.author.name.lower() == CHANNEL_NAME.lower():
                await ctx.send(f"Streamer, you're always present!")
                chat_logger.info(f"{ctx.author.name} tried to check lurk time in their own channel.")
                return
    
            cursor.execute('SELECT start_time FROM lurk_times WHERE user_id = ?', (user_id,))
            result = cursor.fetchone()
    
            if result:
                start_time = datetime.fromisoformat(result[0])
                elapsed_time = datetime.now() - start_time
    
                # Calculate the duration
                days, seconds = divmod(elapsed_time.total_seconds(), 86400)
                hours, remainder = divmod(seconds, 3600)
                minutes, seconds = divmod(remainder, 60)
    
                # Build the time string
                periods = [("days", int(days)), ("hours", int(hours)), ("minutes", int(minutes)), ("seconds", int(seconds))]
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

    @bot.command(name="lurklead")
    async def lurklead_command(ctx: commands.Context):
        try:
            cursor.execute('SELECT user_id, start_time FROM lurk_times')
            lurkers = cursor.fetchall()
    
            longest_lurk = None
            longest_lurk_user_id = None
            now = datetime.now()
    
            for user_id, start_time in lurkers:
                start_time = datetime.fromisoformat(start_time)
                lurk_duration = now - start_time
    
                if longest_lurk is None or lurk_duration > longest_lurk:
                    longest_lurk = lurk_duration
                    longest_lurk_user_id = user_id
    
            if longest_lurk_user_id:
                display_name = await get_display_name(longest_lurk_user_id)
    
                if display_name:
                    # Calculate the duration
                    days, seconds = divmod(longest_lurk.total_seconds(), 86400)
                    hours, remainder = divmod(seconds, 3600)
                    minutes, seconds = divmod(remainder, 60)
    
                    # Build the time string
                    periods = [("days", int(days)), ("hours", int(hours)), ("minutes", int(minutes)), ("seconds", int(seconds))]
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

    @bot.command(name="unlurk", aliases=("back",))
    async def unlurk_command(ctx: commands.Context):
        try:
            user_id = str(ctx.author.id)
            if ctx.author.name.lower() == CHANNEL_NAME.lower():
                await ctx.send(f"Streamer, you've been here all along!")
                chat_logger.info(f"{ctx.author.name} tried to unlurk in their own channel.")
                return

            cursor.execute('SELECT start_time FROM lurk_times WHERE user_id = ?', (user_id,))
            result = cursor.fetchone()

            if result:
                start_time = datetime.fromisoformat(result[0])
                elapsed_time = datetime.now() - start_time

                # Calculate the duration
                days, seconds = divmod(elapsed_time.total_seconds(), 86400)
                hours, remainder = divmod(seconds, 3600)
                minutes, seconds = divmod(remainder, 60)

                # Build the time string
                periods = [("days", int(days)), ("hours", int(hours)), ("minutes", int(minutes)), ("seconds", int(seconds))]
                time_string = ", ".join(f"{value} {name}" for name, value in periods if value)

                # Log the unlurk command execution and send a response
                chat_logger.info(f"{ctx.author.name} is no longer lurking. Time lurking: {time_string}")
                await ctx.send(f"{ctx.author.name} has returned from the shadows after {time_string}, welcome back!")

                # Remove the user's start time from the database
                cursor.execute('DELETE FROM lurk_times WHERE user_id = ?', (user_id,))
                conn.commit()
            else:
                await ctx.send(f"{ctx.author.name} has returned from lurking, welcome back!")
        except Exception as e:
            chat_logger.error(f"Error in unlurk_command: {e}")
            await ctx.send(f"Oops, something went wrong with the unlurk command.")

    @bot.command(name='uptime')
    async def uptime_command(ctx: commands.Context):
        chat_logger.info("Uptime Command ran.")
        uptime_url = f"https://decapi.me/twitch/uptime/{CHANNEL_NAME}"
        try:
            async with aiohttp.ClientSession() as session:
                async with session.get(uptime_url) as response:
                    api_logger.info(f"{response}")
                    if response.status == 200:
                        uptime_text = await response.text()

                        # Check if the API response is that the channel is offline
                        if 'is offline' in uptime_text:
                            await ctx.send(f"{uptime_text}")
                        else:
                            # If the channel is live, send a custom message with the uptime
                            await ctx.send(f"We've been live for {uptime_text}.")
                            chat_logger.info(f"{CHANNEL_NAME} has been online for {uptime_text}.")
                    else:
                        chat_logger.error(f"Failed to retrieve uptime. Status: {response.status}.")
                        await ctx.send(f"Sorry, I couldn't retrieve the uptime right now. {response.status}")
        except Exception as e:
            chat_logger.error(f"Error retrieving uptime: {e}")
            await ctx.send(f"Oops, something went wrong while trying to check uptime.")
    
    @bot.command(name='typo')
    async def typo_command(ctx: commands.Context, *, mentioned_username: str = None):
        chat_logger.info("Typo Command ran.")
        # Check if the broadcaster is running the command
        if ctx.author.name.lower() == CHANNEL_NAME.lower():
            await ctx.send(f"Dear Streamer, you can never have a typo in your own channel.")
            return

        # Determine the target user: mentioned user or the command caller
        mentioned_username_lower = mentioned_username.lower() if mentioned_username else ctx.author.name.lower()
        target_user = mentioned_username_lower.lstrip('@')

        # Increment typo count in the database
        cursor.execute('INSERT INTO user_typos (username, typo_count) VALUES (?, 1) ON CONFLICT(username) DO UPDATE SET typo_count = typo_count + 1', (target_user,))
        conn.commit()

        # Retrieve the updated count
        cursor.execute('SELECT typo_count FROM user_typos WHERE username = ?', (target_user,))
        typo_count = cursor.fetchone()[0]

        # Send the message
        chat_logger.info(f"{target_user} has done a new typo in chat, they're count is now at {typo_count}.")
        await ctx.send(f"Congratulations {target_user}, you've done a typo! {target_user} you've done a typo in chat {typo_count} times.")
    
    @bot.command(name="typos", aliases=("typocount",))
    async def typos_command(ctx: commands.Context, *, mentioned_username: str = None):
        chat_logger.info("Typos Command ran.")
        # Check if the broadcaster is running the command
        if ctx.author.name.lower() == CHANNEL_NAME.lower():
            await ctx.send(f"Dear Streamer, you can never have a typo in your own channel.")
            return

        # Determine the target user: mentioned user or the command caller
        mentioned_username_lower = mentioned_username.lower() if mentioned_username else ctx.author.name.lower()
        target_user = mentioned_username_lower.lstrip('@')

        # Retrieve the typo count
        cursor.execute('SELECT typo_count FROM user_typos WHERE username = ?', (target_user,))
        result = cursor.fetchone()
        typo_count = result[0] if result else 0

        # Send the message
        chat_logger.info(f"{target_user} has made {typo_count} typos in chat.")
        await ctx.send(f"{target_user} has made {typo_count} typos in chat.")

    @bot.command(name="edittypos", aliases=("edittypo",))
    async def edit_typo_command(ctx: commands.Context, mentioned_username: str = None, new_count: int = None):
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
                cursor.execute('SELECT typo_count FROM user_typos WHERE username = ?', (target_user,))
                result = cursor.fetchone()
    
                if result is not None:
                    # Update typo count in the database
                    cursor.execute('UPDATE user_typos SET typo_count = ? WHERE username = ?', (new_count, target_user))
                    conn.commit()
                    chat_logger.info(f"Typo count for {target_user} has been updated to {new_count}.")
                    await ctx.send(f"Typo count for {target_user} has been updated to {new_count}.")
                else:
                    # If user does not exist, send an error message and add the user with the given typo count
                    await ctx.send(f"No record for {target_user}. Adding them with the typo count.")
                    cursor.execute('INSERT INTO user_typos (username, typo_count) VALUES (?, ?)', (target_user, new_count))
                    conn.commit()
                    chat_logger.info(f"Typo count for {target_user} has been set to {new_count}.")
                    await ctx.send(f"Typo count for {target_user} has been set to {new_count}.")
            except Exception as e:
                chat_logger.error(f"Error in edit_typo_command: {e}")
                await ctx.send(f"An error occurred while trying to edit typos. {e}")
        else:
            await ctx.send(f"You must be a moderator or the broadcaster to use this command.")

    @bot.command(name="removetypos", aliases=("removetypo",))
    async def remove_typos_command(ctx: commands.Context, mentioned_username: str = None, decrease_amount: int = 1):
        chat_logger.info("Remove Typos Command ran.")
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
                cursor.execute('SELECT typo_count FROM user_typos WHERE username = ?', (target_user,))
                result = cursor.fetchone()

                if result:
                    current_count = result[0]
                    new_count = max(0, current_count - decrease_amount)  # Ensure count doesn't go below 0
                    cursor.execute('UPDATE user_typos SET typo_count = ? WHERE username = ?', (new_count, target_user))
                    conn.commit()
                    await ctx.send(f"Typo count for {target_user} decreased by {decrease_amount}. New count: {new_count}.")
                else:
                    await ctx.send(f"No typo record found for {target_user}.")
            else:
                await ctx.send(f"You must be a moderator or the broadcaster to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in remove_typos_command: {e}")
            await ctx.send(f"An error occurred while trying to remove typos.")

    @bot.command(name='deaths')
    async def deaths_command(ctx: commands.Context):
        chat_logger.info("Deaths command ran.")
        current_game = await get_current_stream_game()

        # Retrieve the game-specific death count
        cursor.execute('SELECT death_count FROM game_deaths WHERE game_name = ?', (current_game,))
        game_death_count = cursor.fetchone()
        game_death_count = game_death_count[0] if game_death_count else 0

        # Retrieve the total death count
        cursor.execute('SELECT death_count FROM total_deaths')
        total_death_count = cursor.fetchone()[0]

        # Send the message
        chat_logger.info(f"{ctx.author} has reviewed the death count for {current_game}. Total deaths are: {total_death_count}")
        await ctx.send(f"We have died {game_death_count} times in {current_game}, with a total of {total_death_count} deaths in all games.")

    @bot.command(name="deathadd", aliases=["death+",])
    async def deathadd_command(ctx: commands.Context):
        if is_mod_or_broadcaster(ctx.author):
            chat_logger.info("Death Add Command ran.")
            current_game = await get_current_stream_game()

            # Increment game-specific death count
            cursor.execute('INSERT INTO game_deaths (game_name, death_count) VALUES (?, 1) ON CONFLICT(game_name) DO UPDATE SET death_count = death_count + 1', (current_game,))

            # Increment total death count
            cursor.execute('UPDATE total_deaths SET death_count = death_count + 1')
            conn.commit()

            # Retrieve updated counts
            cursor.execute('SELECT death_count FROM game_deaths WHERE game_name = ?', (current_game,))
            game_death_count = cursor.fetchone()[0]

            cursor.execute('SELECT death_count FROM total_deaths')
            total_death_count = cursor.fetchone()[0]

            # Send the message
            chat_logger.info(f"{current_game} now has {game_death_count} deaths.")
            chat_logger.info(f"Total Death count has been updated to: {total_death_count}")
            await ctx.send(f"We have died {game_death_count} times in {current_game}, with a total of {total_death_count} deaths in all games.")
        else:
            chat_logger.info(f"{ctx.author} tried to use the command, death add, but couldn't has they are not a moderator.")
            await ctx.reply("You must be a moderator or the broadcaster to use this command.")

    @bot.command(name="deathremove", aliases=["death-",])
    async def deathremove_command(ctx: commands.Context):
        if is_mod_or_broadcaster(ctx.author):
            chat_logger.info("Death Remove Command Ran")
            current_game = await get_current_stream_game()

            # Decrement game-specific death count (ensure it doesn't go below 0)
            cursor.execute('UPDATE game_deaths SET death_count = GREATEST(0, death_count - 1) WHERE game_name = ?', (current_game,))

            # Decrement total death count (ensure it doesn't go below 0)
            cursor.execute('UPDATE total_deaths SET death_count = GREATEST(0, death_count - 1)')
            conn.commit()

            # Retrieve updated counts
            cursor.execute('SELECT death_count FROM game_deaths WHERE game_name = ?', (current_game,))
            game_death_count = cursor.fetchone()[0]

            cursor.execute('SELECT death_count FROM total_deaths')
            total_death_count = cursor.fetchone()[0]

            # Send the message
            chat_logger.info(f"{current_game} death has been removed, we now have {game_death_count} deaths.")
            chat_logger.info(f"Total Death count has been updated to: {total_death_count} to reflect the removal.")
            await ctx.send(f"Death removed from {current_game}, count is now {game_death_count}. Total deaths in all games: {total_death_count}.")
        else:
            chat_logger.info(f"{ctx.author} tried to use the command, death remove, but couldn't has they are not a moderator.")
            await ctx.reply("You must be a moderator or the broadcaster to use this command.")
    
    @bot.command(name='game')
    async def game_command(ctx: commands.Context):
        current_game = await get_current_stream_game()
        chat_logger.info(f"Game Command has been ran. Current game is: {current_game}")
        await ctx.send(f"The current game we're playing is: {current_game}")

    @bot.command(name='followage')
    async def followage_command(ctx: commands.Context, *, mentioned_username: str = None):
        chat_logger.info("Follow Age Command ran.")
        target_user = mentioned_username.lstrip('@') if mentioned_username else ctx.author.name
        followage_url = f"https://decapi.me/twitch/followage/{CHANNEL_NAME}/{target_user}?token={DECAPI}"
        try:
            async with aiohttp.ClientSession() as session:
                async with session.get(followage_url) as response:
                    api_logger.info(f"{response}")
                    if response.status == 200:
                        followage_text = await response.text()

                        # Send the response message as received from DecAPI
                        chat_logger.info(f"{target_user} has been following for: {followage_text}.")
                        await ctx.send(f"{target_user} has been following for: {followage_text}")
                    else:
                        chat_logger.info(f"Failed to retrieve followage information for {target_user}.")
                        await ctx.send(f"Failed to retrieve followage information for {target_user}.")
        except Exception as e:
            chat_logger.error(f"Error retrieving followage: {e}")
            await ctx.send(f"Oops, something went wrong while trying to check followage.")

    @bot.command(name="checkupdate")
    async def check_update_command(ctx: commands.Context):
        if is_mod_or_broadcaster(ctx.author):
            REMOTE_VERSION_URL = "https://api.botofthespecter.com/bot_version_control.txt"
            response = requests.get(REMOTE_VERSION_URL)
            remote_version = response.text.strip()

            if remote_version != VERSION:
                message = f"A new update (V{remote_version}) is available. Please head over to the website and restart the bot. You are currently running V{VERSION}."
                bot_logger.info(f"Bot update available. (V{remote_version})")
                await ctx.send(f"{message}")
            else:
                message = f"There is no update pending. You are currently running V{VERSION}."
                bot_logger.info(f"{message}")
                await ctx.send(f"{message}")
        else:
            chat_logger.info(f"{ctx.author} tried to use the command, !checkupdate, but couldn't has they are not a moderator.")
            await ctx.reply("You must be a moderator or the broadcaster to use this command.")
    
    @bot.command(name="so", aliases=("shoutout",))
    async def shoutout_command(ctx: commands.Context, user_to_shoutout: str = None):
        chat_logger.info(f"Shoutout command attempting to run.")
        if is_mod_or_broadcaster(ctx.author):
            chat_logger.info(f"Shoutout command running from {ctx.author}")
            if user_to_shoutout is None:
                    chat_logger.error(f"Shoutout command missing username parameter.")
                    await ctx.send(f"Usage: !so @username")
                    return
            try:
                chat_logger.info(f"Shoutout command trying to run.")
                # Remove @ from the username if present
                user_to_shoutout = user_to_shoutout.lstrip('@')

                chat_logger.info(f"Shoutout for {user_to_shoutout} ran by {ctx.author.name}")

                game = await get_latest_stream_game(user_to_shoutout)

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
                await trigger_twitch_shoutout(user_to_shoutout)

            except Exception as e:
                chat_logger.error(f"Error in shoutout_command: {e}")
        else:
            chat_logger.info(f"{ctx.author} tried to use the command, !shoutout, but couldn't has they are not a moderator.")
            await ctx.send("You must be a moderator or the broadcaster to use this command.")

    @bot.command(name='addcommand')
    async def add_command(ctx: commands.Context):
        chat_logger.info("Add Command ran.")
        # Check if the user is a moderator or the broadcaster
        if is_mod_or_broadcaster(ctx.author):
            # Parse the command and response from the message
            try:
                command, response = ctx.message.content.strip().split(' ', 1)[1].split(' ', 1)
            except ValueError:
                await ctx.send(f"Invalid command format. Use: !addcommand [command] [response]")
                return

            # Insert the command and response into the database
            cursor.execute('INSERT OR REPLACE INTO custom_commands (command, response) VALUES (?, ?)', (command, response))
            conn.commit()
            chat_logger.info(f"{ctx.author} has added the command !{command} with the response: {response}")
            await ctx.send(f'Custom command added: !{command}')
        else:
            await ctx.send(f"You must be a moderator or the broadcaster to use this command.")

    @bot.command(name='removecommand')
    async def remove_command(ctx: commands.Context):
        chat_logger.info("Remove Command ran.")
        # Check if the user is a moderator or the broadcaster
        if is_mod_or_broadcaster(ctx.author):
            try:
                command = ctx.message.content.strip().split(' ')[1]
            except IndexError:
                await ctx.send(f"Invalid command format. Use: !removecommand [command]")
                return

            # Delete the command from the database
            cursor.execute('DELETE FROM custom_commands WHERE command = ?', (command,))
            conn.commit()
            chat_logger.info(f"{ctx.author} has removed {command}")
            await ctx.send(f'Custom command removed: !{command}')
        else:
            await ctx.send(f"You must be a moderator or the broadcaster to use this command.")

# Functions for all the commands
##
# Function to get the current streaming category for the channel.
async def get_current_stream_game():
    url = f"https://decapi.me/twitch/game/{CHANNEL_NAME}"

    response = await fetch_json(url)
    api_logger.info(f"Response from DecAPI for current game: {response}")

    if response and isinstance(response, str) and response != "null":
        twitch_logger.info(f"Current game for {CHANNEL_NAME}: {response}.")
        return response

    api_logger.error(f"Failed to get current game for {CHANNEL_NAME}.")
    return None

# Function to get the diplay name of the user from their user id
async def get_display_name(user_id):
    # Replace with actual method to get display name from Twitch API
    url = f"https://api.twitch.tv/helix/users?id={user_id}"
    headers = {
        "Client-ID": TWITCH_API_CLIENT_ID,
        "Authorization": f"Bearer {REFRESH_TOKEN}"
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
    elif is_user_moderator(user):
        twitch_logger.info(f"User {user.name} is a Moderator")
        return True

    # If none of the above, the user is neither the bot owner, broadcaster, nor a moderator
    else:
        twitch_logger.info(f"User {user.name} does not have required permissions.")
        return False

def is_user_moderator(user):
    # Send request to Twitch API to check if user is a moderator
    headers = {
        "Authorization": f"Bearer {REFRESH_TOKEN}",
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
                return True
            return False
    return False

# Function to trigger a twitch shoutout via Twitch API
async def trigger_twitch_shoutout(user_to_shoutout):
    # Fetching the shoutout user ID
    shoutout_user_id = await fetch_twitch_shoutout_user_id(user_to_shoutout)

    # Add the shoutout request to the queue
    shoutout_queue.put((user_to_shoutout, shoutout_user_id))

    # Check if the queue is empty and no shoutout is currently being processed
    if shoutout_queue.qsize() == 1:
        await process_shoutouts()

async def fetch_twitch_shoutout_user_id(user_to_shoutout):
    url = f"https://decapi.me/twitch/id/{user_to_shoutout}"

    async with aiohttp.ClientSession() as session:
        async with session.get(url) as response:
            if response.status == 200:
                shoutout_user_id = await response.text()
                api_logger.info(f"Response from DecAPI: {shoutout_user_id}")
                return shoutout_user_id
            else:
                api_logger.error(f"Failed to fetch Twitch ID. Status: {response.status}")
                return None

async def get_latest_stream_game(user_to_shoutout):
    url = f"https://decapi.me/twitch/game/{user_to_shoutout}"

    async with aiohttp.ClientSession() as session:
        async with session.get(url) as response:
            if response.status == 200:
                game_name = await response.text()
                api_logger.info(f"Response from DecAPI: {game_name}")

                if game_name and game_name.lower() != "null":
                    twitch_logger.info(f"Got {user_to_shoutout} Last Game: {game_name}.")
                    return game_name
                else:
                    api_logger.error(f"User {user_to_shoutout} is not currently playing a game.")
                    return None
            else:
                api_logger.error(f"Failed to get {user_to_shoutout} Last Game. Status: {response.status}")
                return None

async def process_shoutouts():
    while not shoutout_queue.empty():
        user_to_shoutout, shoutout_user_id = shoutout_queue.get()
        twitch_logger.info(f"Processing Shoutout via Twitch for {user_to_shoutout}={shoutout_user_id}")
        url = 'https://api.twitch.tv/helix/chat/shoutouts'
        headers = {
            "Authorization": f"Bearer {REFRESH_TOKEN}",
            "Client-ID": TWITCH_API_CLIENT_ID,
        }
        payload = {
            "from_broadcaster_id": CHANNEL_ID,
            "to_broadcaster_id": shoutout_user_id,
            "moderator_id": CHANNEL_ID
        }

        try:
            async with aiohttp.ClientSession() as session:
                async with session.post(url, headers=headers, json=payload) as response:
                    if response.status in (200, 204):
                        twitch_logger.info(f"Shoutout triggered successfully for {user_to_shoutout}.")
                        await asyncio.sleep(180)  # Wait for 3 minutes before processing the next shoutout
                    else:
                        twitch_logger.error(f"Failed to trigger shoutout. Status: {response.status}. Message: {await response.text()}")
                    shoutout_queue.task_done()
        except Exception as e:
            twitch_logger.error(f"Error triggering shoutout: {e}")
            shoutout_queue.task_done()

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

# Function for checking updates
async def check_auto_update():
    while True:
        REMOTE_VERSION_URL = "https://api.botofthespecter.com/bot_version_control.txt"
        async with aiohttp.ClientSession() as session:
            async with session.get(REMOTE_VERSION_URL) as response:
                if response.status == 200:
                    remote_version = await response.text()
                    remote_version = remote_version.strip()
                    if remote_version != VERSION:
                        message = f"A new update (V{remote_version}) is available. Please head over to the website and restart the bot. You are currently running V{VERSION}."
                        bot_logger.info(message)
                        channel = bot.get_channel(CHANNEL_NAME)
                        if channel:
                            await channel.send(message)
        await asyncio.sleep(1800)

# Function to check if the stream is online
async def check_stream_online():
    stream_was_offline = True
    greeted_users = set()
    while True:
        async with aiohttp.ClientSession() as session:
            async with session.get(f"https://decapi.me/twitch/uptime/{CHANNEL_NAME}") as response:
                text = await response.text()
                # Check if the stream is offline
                if "{CHANNEL_NAME} is offline" in text:
                    stream_was_offline = True
                else:
                    # If the stream was previously offline and is now online, reset greeted users
                    if stream_was_offline:
                        greeted_users.clear()
                        print("Stream is online. Resetting greeted users.")
                    stream_was_offline = False
        await asyncio.sleep(300)  # Check every 5 minutes

# Run the bot
def start_bot():
    asyncio.get_event_loop().create_task(refresh_token_every_day())
    asyncio.get_event_loop().create_task(check_auto_update())
    asyncio.get_event_loop().create_task(check_stream_online())
    bot.run()

if __name__ == '__main__':
    start_bot()