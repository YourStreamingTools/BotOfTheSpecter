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

# Third-party imports
import aiohttp
import asyncio
import requests
import sqlite3
import flask
from flask import Flask, request
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
args = parser.parse_args()

# Twitch bot settings
CHANNEL_NAME = args.target_channel
CHANNEL_ID = args.channel_id
CHANNEL_AUTH = args.channel_auth_token
BOT_USERNAME = "botofthespecter"
WEBHOOK_SECRET = "" # CHANGE TO MAKE THIS WORK
CALLBACK_URL = f"" # CHANGE TO MAKE THIS WORK
OAUTH_TOKEN = ""  # CHANGE TO MAKE THIS WORK
CLIENT_ID = ""    # CHANGE TO MAKE THIS WORK
TWITCH_API_CLIENT_ID = CLIENT_ID
CLIENT_SECRET = "" # CHANGE TO MAKE THIS WORK
TWITCH_API_AUTH = "" # CHANGE TO MAKE THIS WOR
builtin_commands = {"so", "shoutout", "ping", "lurk", "unlurk", "back", "uptime", "timer", "addcommand", "removecommand"}
lurk_start_times = {}

app = Flask("TwitchWebhookServer")

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
    topics = [
        pubsub.bits(CHANNEL_AUTH)[CHANNEL_ID],
        pubsub.channel_subscriptions(CHANNEL_AUTH)[CHANNEL_ID],
        pubsub.channel_points(CHANNEL_AUTH)[CHANNEL_ID]
    ]
    await client.pubsub.subscribe_topics(topics)
    await client.start()
client.loop.run_until_complete(main())

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

@app.route('/webhook/<channel_name>', methods=['POST'])
def webhook(channel_name):
    if channel_name.lower() != CHANNEL_NAME.lower():
        return "Invalid channel", 400

    data = request.json

    # Handle the challenge request
    if 'challenge' in data:
        return data['challenge']

    # Process the follower event
    follower_name = data['event']['user_name']
    # Ensure the follower event is for the correct channel
    if data['event']['broadcaster_user_login'].lower() == channel_name.lower():
        asyncio.run(channel.send(f'New follower: {follower_name}'))  # Send a message to your Twitch channel
        twitch_logger.info(f"New follower: {follower_name}")

    return 'OK', 200

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
            user_id = ctx.author.id
            now = datetime.now()
            if ctx.author.name.lower() == CHANNEL_NAME.lower():
                await ctx.send(f"You cannot lurk in your own channel, Mr Streamer.")
                chat_logger.info(f"{ctx.author.name} tried to lurk in their own channel.")
                return
            if user_id in lurk_start_times:
                # Calculate the time difference since they started lurking
                lurk_duration = now - lurk_start_times[user_id]
                # Convert the duration to seconds
                total_seconds = int(lurk_duration.total_seconds())
                # Calculate hours, minutes, and seconds
                hours, remainder = divmod(total_seconds, 3600)
                minutes, seconds = divmod(remainder, 60)
                # Create time string
                periods = [("hours", hours), ("minutes", minutes), ("seconds", seconds)]
                time_string = ", ".join(f"{value} {name}" for name, value in periods if value)
                # Inform the user of their previous lurk time
                await ctx.send(f"Continuing to lurk, {ctx.author.name}? No problem, you've been lurking for {time_string}. I've reset your lurk time.")
                chat_logger.info(f"{ctx.author.name} refreshed their lurk time after {time_string}.")
            else:
                await ctx.send(f"Thanks for lurking, {ctx.author.name}! See you soon.")
                chat_logger.info(f"{ctx.author.name} is now lurking.")

            # Set or reset the start time for the user.
            lurk_start_times[user_id] = now
        except Exception as e:
            chat_logger.error(f"Error in lurk_command: {e}")
            await ctx.send("Oops, something went wrong while trying to lurk.")

    @bot.command(name="unlurk", aliases=("back",))
    async def unlurk_command(ctx: commands.Context):
        try:
            if ctx.author.name.lower() == CHANNEL_NAME.lower():
                await ctx.send(f"Mr Streamer, you've been here all along!")
                chat_logger.info(f"{ctx.author.name} tried to unlurk in their own channel.")
                return
            # Check if the user was lurking
            if ctx.author.id in lurk_start_times:
                # Calculate the elapsed time
                start_time = lurk_start_times[ctx.author.id]
                elapsed_time = datetime.now() - start_time
                minutes, seconds = divmod(int(elapsed_time.total_seconds()), 60)
                hours, minutes = divmod(minutes, 60)

                # Build the time string
                periods = [("hours", hours), ("minutes", minutes), ("seconds", seconds)]
                time_string = ", ".join(f"{value} {name}" for name, value in periods if value)

                # Log the unlurk command execution
                chat_logger.info(f"{ctx.author.name} is no longer lurking. Time lurking: {time_string}")
                await ctx.send(f"{ctx.author.name} has returned from the shadows after {time_string}, welcome back!")

                # Remove the user's start time from the dictionary
                del lurk_start_times[ctx.author.id]
            else:
                # If the user wasn't lurking, send a different message
                await ctx.send(f"{ctx.author.name} has returned from lurking, welcome back!")
        except Exception as e:
            chat_logger.error(f"Error in unlurk_command: {e}")
            # Send an error message to the Twitch chat
            await ctx.send("Oops, something went wrong with the unlurk command.")

    @bot.command(name='addcommand')
    async def add_command(ctx: commands.Context):
        chat_logger.info("Add Command ran.")
        # Check if the user is a moderator or broadcaster
        if is_mod_or_broadcaster(ctx.author):
            # Parse the command and response from the message
            command, response = ctx.message.content.strip().split(' ', 1)[1].split(' ', 1)
    
            # Insert the command and response into the database
            cursor.execute('INSERT OR REPLACE INTO custom_commands (command, response) VALUES (?, ?)', (command, response))
            conn.commit()
            await ctx.send(f'Custom command added: !{command}')
    
    @bot.command(name='removecommand')
    async def remove_command(ctx: commands.Context):
        chat_logger.info("Remove Command ran.")
        # Check if the user is a moderator or broadcaster
        if is_mod_or_broadcaster(ctx.author):
            # Parse the command to remove from the message
            command = ctx.message.content.strip().split(' ')[1]
    
            # Delete the command from the database
            cursor.execute('DELETE FROM custom_commands WHERE command = ?', (command,))
            conn.commit()
            await ctx.send(f'Custom command removed: !{command}')

    @bot.command(name='uptime')
    async def uptime_command(ctx: commands.Context):
        url = f"https://decapi.me/twitch/uptime/{CHANNEL_NAME}"

        try:
            async with aiohttp.ClientSession() as session:
                async with session.get(url) as response:
                    if response.status == 200:
                        uptime_text = await response.text()

                        # Check if the API response is that the channel is offline
                        if 'is offline' in uptime_text:
                            await ctx.send(f"{uptime_text}")
                        else:
                            # If the channel is live, send a custom message with the uptime
                            await ctx.send(f"We've been live for {uptime_text}.")
                    else:
                        chat_logger.error(f"Failed to retrieve uptime. Status: {response.status}.")
                        await ctx.send(f"Sorry, I couldn't retrieve the uptime right now. {response.status}")
        except Exception as e:
            chat_logger.error(f"Error retrieving uptime: {e}")
            await ctx.send(f"Oops, something went wrong while trying to check uptime.")

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

            # If the command is one of the built-in commands, process it using the bot's command processing
            if command in builtin_commands:
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
            chat_logger.info(f"Shoutout for {user_to_shoutout} ran by {ctx.author.name}")

            is_mod = ctx.author.is_mod

            if not is_mod:
                chat_logger.info(f"User {ctx.author.name} is not a mod, failed to run shoutout command.")
                await ctx.send(f"You must be a moderator to use the !so command.")
                return

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
        'version': '1',
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
if __name__ == '__main__':
    create_eventsub_subscription(CHANNEL_NAME)
    app.run()
bot.run()