# Standard library imports
import argparse
import datetime
from datetime import datetime
import logging
import os
import re
import signal
import subprocess
import time
import threading

# Third-party imports
import aiohttp
import asyncio
import requests
import sqlite3
import flask
from flask import Flask, request
from flask_app import start_app
import twitchAPI
from twitchAPI.chat import Chat
from twitchAPI.oauth import UserAuthenticator, refresh_access_token
from twitchAPI.twitch import Twitch
from twitchAPI.type import AuthScope
import twitchio
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
DECAPI = "" # CHANGE TO MAKE THIS WORK
BOT_USERNAME = "botofthespecter"
WEBHOOK_SECRET = "" # CHANGE TO MAKE THIS WORK
CALLBACK_URL = f"" # CHANGE TO MAKE THIS WORK
OAUTH_TOKEN = "" # CHANGE TO MAKE THIS WORK
CLIENT_ID = "" # CHANGE TO MAKE THIS WORK
TWITCH_API_CLIENT_ID = CLIENT_ID
CLIENT_SECRET = "" # CHANGE TO MAKE THIS WORK
TWITCH_API_AUTH = "" # CHANGE TO MAKE THIS WORK
builtin_commands = {"commands", "timer", "ping", "lurk", "unlurk", "addcommand", "removecommand", "uptime", "typo", "typos", "edittypos", "followage", "so", "removetypos"}
builtin_aliases = {"cmds", "back", "shoutout", "typocount", "edittypo", "removetypo"}

# Logs
webroot = "/var/www/html"
logs_directory = "logs"
bot_logs = os.path.join(logs_directory, "bot")
chat_logs = os.path.join(logs_directory, "chat")
twitch_logs = os.path.join(logs_directory, "twitch")

# Ensure directories exist
for directory in [logs_directory, bot_logs, chat_logs, twitch_logs]:
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

bot_logger.info("Bot script started.")
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
    await pubsub_pool.subscribe_channel(CHANNEL_AUTH, channel_id_int, [pubsub.PubSubBits, pubsub.PubSubSubscriptions, pubsub.PubSubChannelPoints])
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
conn.commit()

@client.event
async def event_ready():
    bot_logger.info('Logged in as | {bot_instance.nick}')
    bot_logger.info('User id is | {bot_instance.user_id}')
    chat_logger.info("Chat logger initialized.")
    twitch_logger.info("Twitch logger initialized.")

    # Send the message indicating the bot is ready
    await channel.send(f"Ready and waiting.")

@client.event()
async def on_pubsub_channel_subscription(data):
    twitch_logger.info(f"Channel subscription event: {data}")
    if data['type'] == 'stream.online':
        await channel.send(f'The stream is now online, {BOT_USERNAME} is ready!')

@client.event()
async def event_cheer(cheerer, message):
    twitch_logger.info(f"{cheerer.display_name} cheered {message.bits} bits!")
    await channel.send(f'{cheerer.display_name} cheered {message.bits} bits!')

@client.event()
async def event_subscribe(subscriber):
    streak = subscriber.streak
    months = subscriber.cumulative_months
    gift = subscriber.is_gift
    giftanonymous = subscriber.is_anonymous
    if gift == False:
        if streak > 1:
            await channel.send(f'{subscriber.display_name} has resubsribed for {subscriber.cumulative_months} Months on a {streak} Month Streak at Tier: {subscriber.tier}!')
            twitch_logger.info(f'{subscriber.display_name} has resubsribed for {subscriber.cumulative_months} Months on a {streak} Month Streak at Tier: {subscriber.tier}!')
        if months > 2:
            await channel.send(f'{subscriber.display_name} has resubsribed for {subscriber.cumulative_months} Months at Tier: {subscriber.tier}!')
            twitch_logger.info(f'{subscriber.display_name} has resubsribed for {subscriber.cumulative_months} Months at Tier: {subscriber.tier}!')
        else:
            await channel.send(f'{subscriber.display_name} just subscribed to the channel at Tier: {subscriber.tier}!')
            twitch_logger.info(f"{subscriber.display_name} just subscribed to the channel at Tier: {subscriber.tier}!")
    else:
        if giftanonymous == True:
            await channel.send(f'Anonymous Gifter gifted {subscriber.cumulative_total} subs to the channel.')
            twitch_logger.info(f'Anonymous Gifter gifted {subscriber.cumulative_total} subs to the channel.')
        else:
            await channel.send(f'{subscriber.display_name} gifted {subscriber.cumulative_total} subs to the channel.')
            twitch_logger.info(f'{subscriber.display_name} gifted {subscriber.cumulative_total} subs to the channel.')
                
class Bot(commands.Bot):
    @bot.command(name="commands", aliases=["cmds"])
    async def commands_command(ctx: commands.Context):
        # Prefix each command with an exclamation mark
        commands_list = ", ".join(sorted(f"!{command}" for command in builtin_commands))
        response_message = f"Built-in commands: {commands_list}"
        
        # Sending the response message to the chat
        await ctx.send(response_message)

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
        await asyncio.sleep(minutes * 60)  # Convert minutes to seconds
        await ctx.send(f"The {minutes} minute timer has ended @{ctx.author.name}!")
    
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
            await ctx.send(f'Error pinging')
    
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
                total_seconds = int(lurk_duration.total_seconds())
                hours, remainder = divmod(total_seconds, 3600)
                minutes, seconds = divmod(remainder, 60)

                # Create time string
                periods = [("hours", hours), ("minutes", minutes), ("seconds", seconds)]
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
            await ctx.send("Oops, something went wrong while trying to lurk.")

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
                minutes, seconds = divmod(int(elapsed_time.total_seconds()), 60)
                hours, minutes = divmod(minutes, 60)

                # Build the time string
                periods = [("hours", hours), ("minutes", minutes), ("seconds", seconds)]
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
            await ctx.send("Oops, something went wrong with the unlurk command.")

    @bot.command(name='addcommand')
    async def add_command(ctx: commands.Context):
        chat_logger.info("Add Command ran.")
        # Check if the user is a moderator or broadcaster
        if is_mod_or_broadcaster(ctx.author):
            # Parse the command and response from the message
            try:
                command, response = ctx.message.content.strip().split(' ', 1)[1].split(' ', 1)
            except ValueError:
                await ctx.send("Invalid command format. Use: !addcommand [command] [response]")
                return

            # Insert the command and response into the database
            cursor.execute('INSERT OR REPLACE INTO custom_commands (command, response) VALUES (?, ?)', (command, response))
            conn.commit()
            await ctx.send(f'Custom command added: !{command}')
        else:
            await ctx.send("You must be a moderator or broadcaster to use this command.")

    @bot.command(name='removecommand')
    async def remove_command(ctx: commands.Context):
        chat_logger.info("Remove Command ran.")
        # Check if the user is a moderator or broadcaster
        if is_mod_or_broadcaster(ctx.author):
            try:
                command = ctx.message.content.strip().split(' ')[1]
            except IndexError:
                await ctx.send("Invalid command format. Use: !removecommand [command]")
                return

            # Delete the command from the database
            cursor.execute('DELETE FROM custom_commands WHERE command = ?', (command,))
            conn.commit()
            await ctx.send(f'Custom command removed: !{command}')
        else:
            await ctx.send("You must be a moderator or broadcaster to use this command.")

    @bot.command(name='uptime')
    async def uptime_command(ctx: commands.Context):
        chat_logger.info("Uptime Command ran.")
        uptime_url = f"https://decapi.me/twitch/uptime/{CHANNEL_NAME}"
        try:
            async with aiohttp.ClientSession() as session:
                async with session.get(uptime_url) as response:
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
            await ctx.send("Dear Streamer, you can never have a typo in your own channel.")
            return

        # Determine the target user: mentioned user or the command caller
        target_user = mentioned_username.lstrip('@') if mentioned_username else ctx.author.name

        # Increment typo count in the database
        cursor.execute('INSERT INTO user_typos (username, typo_count) VALUES (?, 1) ON CONFLICT(username) DO UPDATE SET typo_count = typo_count + 1', (target_user,))
        conn.commit()

        # Retrieve the updated count
        cursor.execute('SELECT typo_count FROM user_typos WHERE username = ?', (target_user,))
        typo_count = cursor.fetchone()[0]

        # Send the message
        await ctx.send(f"Congratulations {target_user}, you've done a typo! {target_user} you've done a typo in chat {typo_count} times.")
    
    @bot.command(name='typos', aliases=('typocount',))
    async def typos_command(ctx: commands.Context, *, mentioned_username: str = None):
        chat_logger.info("Typos Command ran.")
        # Check if the broadcaster is running the command
        if ctx.author.name.lower() == CHANNEL_NAME.lower():
            await ctx.send("Dear Streamer, you can never have a typo in your own channel.")
            return

        # Determine the target user: mentioned user or the command caller
        target_user = mentioned_username.lstrip('@') if mentioned_username else ctx.author.name

        # Retrieve the typo count
        cursor.execute('SELECT typo_count FROM user_typos WHERE username = ?', (target_user,))
        result = cursor.fetchone()
        typo_count = result[0] if result else 0

        # Send the message
        await ctx.send(f"{target_user} has made {typo_count} typos in chat.")

    @bot.command(name='edittypos', aliases=('edittypo',))
    async def edit_typo_command(ctx: commands.Context, mentioned_username: str = None, new_count: int = None):
        chat_logger.info("Edit Typos Command ran.")
        try:
            if is_mod_or_broadcaster(ctx.author):
                if not mentioned_username or new_count is None:
                    chat_logger.error("Command missing parameters.")
                    await ctx.send("Usage: !edittypos @username [amount]")
                    return
            
                chat_logger.info(f"Edit Typos Command ran with params: {mentioned_username}, {new_count}")

                # Remove @ from the username if present
                target_user = mentioned_username.lstrip('@')

                # Validate new_count is non-negative
                if new_count < 0:
                    await ctx.send("Typo count cannot be negative.")
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
                    # If user does not exist, send an error message or add the user with the given typo count
                    await ctx.send(f"No record for {target_user}. Adding them with the typo count.")
                    cursor.execute('INSERT INTO user_typos (username, typo_count) VALUES (?, ?)', (target_user, new_count))
                    conn.commit()
                    chat_logger.info(f"Typo count for {target_user} has been set to {new_count}.")
                    await ctx.send(f"Typo count for {target_user} has been set to {new_count}.")
            else:
                await ctx.send("You must be a moderator or broadcaster to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in edit_typo_command: {e}")
            await ctx.send("An error occurred while trying to edit typos.")

    @bot.command(name='removetypos', aliases=('removetypo',))
    async def remove_typos_command(ctx: commands.Context, mentioned_username: str = None, decrease_amount: int = 1):
        chat_logger.info("Remove Typos Command ran.")
        try:
            if is_mod_or_broadcaster(ctx.author):
                # Ensure a username is mentioned
                if not mentioned_username:
                    await ctx.send("Usage: !removetypos @username [amount]")
                    return
            
                # Remove @ from the username if present
                target_user = mentioned_username.lstrip('@')

                # Validate decrease_amount is non-negative
                if decrease_amount < 0:
                    await ctx.send("Remove amount cannot be negative.")
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
                await ctx.send("You must be a moderator or broadcaster to use this command.")
        except Exception as e:
            chat_logger.error(f"Error in remove_typos_command: {e}")
            await ctx.send("An error occurred while trying to remove typos.")

    @bot.command(name='followage')
    async def followage_command(ctx: commands.Context, *, mentioned_username: str = None):
        chat_logger.info("Follow Age Command ran.")
        target_user = mentioned_username.lstrip('@') if mentioned_username else ctx.author.name
        followage_url = f"https://decapi.me/twitch/followage/{CHANNEL_NAME}/{target_user}?token={DECAPI}"
        try:
            async with aiohttp.ClientSession() as session:
                async with session.get(followage_url) as response:
                    if response.status == 200:
                        followage_text = await response.text()

                        # Send the response message as received from DecAPI
                        await ctx.send(f"{target_user} has been following for: {followage_text}")
                    else:
                        await ctx.send(f"Failed to retrieve followage information for {target_user}.")
        except Exception as e:
            chat_logger.error(f"Error retrieving followage: {e}")
            await ctx.send(f"Oops, something went wrong while trying to check followage.")

    @bot.event
    async def event_message(ctx):
        if ctx.author.bot:
            return
        
        # Get the message content
        message_content = ctx.content.strip()
        chat_logger.info(f"Chat message from {ctx.author.name}: {ctx.content}")
        
        # Check if the message starts with an exclamation mark
        if message_content.startswith('!'):
            # Split the message into command and its arguments
            parts = message_content.split()
            command = parts[0][1:]  # Extract the command without '!'
            args = parts[1:]  # Remaining parts are arguments
    
            # Combine commands and aliases for checking
            all_commands = builtin_commands | builtin_aliases
    
            # If the command or alias is one of the built-in commands, process it using the bot's command processing
            if command in all_commands:
                await bot.process_commands(ctx)
                return
            
            # Check if the command exists in the database
            cursor.execute('SELECT response FROM custom_commands WHERE command = ?', (command,))
            result = cursor.fetchone()
            
            if result:
                response = result[0]
                chat_logger.info(f"{command} command ran.")
                await ctx.channel.send(response)
            else:
                chat_logger.info(f"{command} command not found.")
                await ctx.channel.send(f'No such command found: !{command}')

    @bot.command(name="so", aliases=("shoutout",))
    async def shoutout_command(ctx: commands.Context, user_to_shoutout: str):
        try:
            is_mod = ctx.author.is_mod

            if not is_mod:
                chat_logger.info(f"User {ctx.author.name} is not a mod, failed to run shoutout command.")
                await ctx.send(f"You must be a moderator to use the !so command.")
                return

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
            await trigger_twitch_shoutout(user_to_shoutout, ctx)

        except Exception as e:
            chat_logger.error(f"Error in shoutout_command: {e}")

def is_mod_or_broadcaster(user):
    twitch_logger.info(f"User {user} is Mod")
    return 'moderator' in user.get('badges', {}) or 'broadcaster' in user.get('badges', {}) or user.is_mod

async def trigger_twitch_shoutout(user_to_shoutout, ctx):
    # Fetching the shoutout user ID
    shoutout_user_id = await fetch_twitch_shoutout_user_id(user_to_shoutout)
    moderator_user_id = await fetch_twitch_author_user_id(ctx.author.name)
    
    url = 'https://api.twitch.tv/helix/chat/shoutouts'
    headers = {
        "Authorization": f"Bearer {CHANNEL_AUTH}",
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
                    return {}
                else:
                    twitch_logger.error(f"Failed to trigger shoutout. Status: {response.status}. Message: {await response.text()}")
                    return None
    except Exception as e:
        twitch_logger.error(f"Error triggering shoutout: {e}")
        return None
    
async def get_latest_stream_game(user_to_shoutout):
    url = f"https://decapi.me/twitch/game/{user_to_shoutout}"
    
    response = await fetch_json(url)  # No headers required for this API
    
    # Add debug logger
    twitch_logger.debug(f"Response from DecAPI: {response}")
    
    # API directly returns the game name as a string
    if response and isinstance(response, str) and response != "null":  # 'null' is a possible response if the user isn't live
        twitch_logger.info(f"Got {user_to_shoutout} Last Game: {response}.")
        return response
    
    twitch_logger.error(f"Failed to get {user_to_shoutout} Last Game.")
    return None

async def fetch_twitch_shoutout_user_id(user_to_shoutout):
    url = f"https://decapi.me/twitch/id/{user_to_shoutout}"
    shoutout_user_id = await fetch_json(url)
    twitch_logger.debug(f"Response from DecAPI: {shoutout_user_id}")
    return shoutout_user_id

async def fetch_twitch_author_user_id(author_name):
    url = f"https://decapi.me/twitch/id/{author_name}"
    moderator_user_id = await fetch_json(url)
    twitch_logger.debug(f"Response from DecAPI: {moderator_user_id}")
    return moderator_user_id

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
        twitch_logger.error(f"Error fetching data: {e}")
    return None

async def handle_shoutout_command(user, user_to_shoutout):
    # Ensure user is a mod or broadcaster
    if not is_mod_or_broadcaster(user):
        twitch_logger.warning(f"User {user} is not allowed to use the shoutout command.")
        return "Only mods or the broadcaster can use the shoutout command."

    # Get latest game of the user to shoutout
    game_name = await get_latest_stream_game(user_to_shoutout)
    if not game_name:
        return f"Could not fetch the latest game for {user_to_shoutout}."
    
    # Fetch the Twitch ID for the user to shoutout
    shoutout_user_id = await fetch_twitch_shoutout_user_id(user_to_shoutout)
    if not shoutout_user_id:
        return f"Could not fetch the Twitch ID for {user_to_shoutout}."
    
    # Trigger the shoutout
    result = await trigger_twitch_shoutout(shoutout_user_id, user.id)
    if result:
        return f"Shoutout to @{user_to_shoutout} who was last seen playing {game_name}!"
    else:
        return f"Failed to shoutout {user_to_shoutout}."

def create_eventsub_subscription(CHANNEL_NAME):
    headers = {
        'Client-ID': CLIENT_ID,
        'Authorization': f'Bearer {CHANNEL_AUTH}',
        'Content-Type': 'application/json'
    }
    json_data = {
        'type': 'channel.follow',
        'version': '2',
        'condition': {
            'broadcaster_user_id': CHANNEL_ID
        },
        'transport': {
            'method': 'webhook',
            'callback': CALLBACK_URL,
            'secret': WEBHOOK_SECRET
        }
    }
    response = requests.post('https://api.twitch.tv/helix/eventsub/subscriptions', headers=headers, json=json_data)
    print(response.json())
    pass

# Run the bot
def start_bot():
    bot.run()

if __name__ == '__main__':
    start_bot()