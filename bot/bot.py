# Standard library imports
import argparse
import datetime
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
BOT_USERNAME = "botofthespecter"
OAUTH_TOKEN = ""  # CHANGE TO MAKE THIS WORK
CLIENT_ID = ""    # CHANGE TO MAKE THIS WORK
CLIENT_SECRET = "" # CHANGE TO MAKE THIS WORK
TWITCH_API_AUTH = "" # CHANGE TO MAKE THIS WORK
TWITCH_API_CLIENT_ID = CLIENT_ID
CHANNEL_NAME = args.target_channel
CHANNEL_ID = args.channel_id
CHANNEL_AUTH = args.channel_auth_token
builtin_commands = {"so", "shoutout", "ping", "timer", "addcommand", "removecommand"}

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

# WILL FIX THIS, BUT TO GET THE BOT TO RUN WITHOUT ISSUES, I'VE COMMENTED THIS OUT #
# client = twitchio.Client(token=CHANNEL_AUTH)
# async def main():
#     topics = [
#         pubsub.bits(CHANNEL_AUTH)[CHANNEL_ID],
#         pubsub.channel_subscriptions(CHANNEL_AUTH)[CHANNEL_ID],
#         pubsub.channel_points(CHANNEL_AUTH)[CHANNEL_ID],
#         pubsub.channel_follow(CHANNEL_AUTH)[CHANNEL_ID]
#     ]
#     await client.pubsub.subscribe_topics(topics)
#     await client.start()
# client.loop.run_until_complete(main())

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

@bot.event
async def event_ready():
    bot_logger.info('Logged in as | {bot_instance.nick}')
    bot_logger.info('User id is | {bot_instance.user_id}')
    chat_logger.info("Chat logger initialized.")
    twitch_logger.info("Twitch logger initialized.")

    await pubsub_client.pubsub_channel_subscribe(CLIENT_ID, f'channel.{CHANNEL_NAME}')
    # Send the message indicating the bot is ready
    await channel.send(f"Ready and waiting.")

@bot.event()
async def on_pubsub_channel_subscription(data):
    twitch_logger.info(f"Channel subscription event: {data}")
    if data['type'] == 'stream.online':
        print(f"The stream is now online, {BOT_USERNAME} is ready!")
        await channel.send(f'The stream is now online, {BOT_USERNAME} is ready!')

@bot.event()
async def event_new_follower(follower):
    print(f"New follower: {follower.name}")
    twitch_logger.info(f"New follower: {follower.name}")
    await channel.send(f'New follower: {follower.name}')

@bot.event()
async def event_cheer(cheerer, message):
    print(f"{cheerer.display_name} cheered {message.bits} bits!")
    twitch_logger.info(f"{cheerer.display_name} cheered {message.bits} bits!")
    await channel.send(f'{cheerer.display_name} cheered {message.bits} bits!')

@bot.event()
async def event_subscribe(subscriber):
    streak = subscriber.streak
    months = subscriber.cumulative_months
    gift = subscriber.is_gift
    giftanonymous = subscriber.is_anonymous
    if gift == False:
        print(f"{subscriber.display_name} just subscribed to the channel!")
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
        chat_logger.info("Timer command ran.")
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
        chat_logger.info("Ping command ran.")
        # Using subprocess to run the ping command
        result = subprocess.run(["ping", "-c", "1", "ping.botofthespecter.com"], stdout=subprocess.PIPE)
    
        # Decode the result from bytes to string and search for the time
        output = result.stdout.decode('utf-8')
        match = re.search(r"time=(\d+\.\d+) ms", output)
    
        if match:
            ping_time = match.group(1)
            await ctx.send(f'Pong: {ping_time} ms')
        else:
            await ctx.send(f'Error pinging')
    
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
                return

            shoutout_message = (
                f"Hey, huge shoutout to @{user_to_shoutout}! "
                f"You should go give them a follow over at "
                f"https://www.twitch.tv/{user_to_shoutout} where they were playing: {game}"
            )
            chat_logger.info(shoutout_message)
            await ctx.send(shoutout_message)
        except Exception as e:
            chat_logger.error(f"Error in shoutout_command: {e}")

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
    
    @bot.event
    async def event_message(ctx):
        if ctx.author.bot:
            return
    
        # Get the message content
        message_content = ctx.content.strip()
        chat_logger.info(f"Chat message from {ctx.author.name}: {ctx.content}")
    
        # Check if the message starts with an exclamation mark
        if message_content.startswith('!'):
            # Extract the potential command (excluding the exclamation mark)
            command = message_content[1:]
    
            # If the command is one of the built-in commands, simply return and do nothing
            if command in builtin_commands:
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

def is_mod_or_broadcaster(user):
    twitch_logger.info(f"User {user} is Mod")
    return 'moderator' in user.get('badges', {}) or 'broadcaster' in user.get('badges', {}) or user.is_mod

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
    return shoutout_user_id

async def fetch_twitch_author_user_id(author_name):
    url = f"https://decapi.me/twitch/id/{author_name}"
    moderator_user_id = await fetch_json(url)
    return moderator_user_id

async def trigger_twitch_shoutout(user_to_shoutout, ctx):
    # Fetching the shoutout user ID
    shoutout_user_id = await fetch_twitch_shoutout_user_id(user_to_shoutout)
    moderator_user_id = await fetch_twitch_author_user_id(ctx.author.name)

    url = 'https://api.twitch.tv/helix/chat/shoutouts'
    headers = {
        "Authorization": f"Bearer {TWITCH_API_AUTH}",
        "Client-ID": TWITCH_API_CLIENT_ID,
    }
    payload = {
        "from_broadcaster_id": CHANNEL_ID,
        "to_broadcaster_id": shoutout_user_id,
        "moderator_id": moderator_user_id
    }

    try:
        async with aiohttp.ClientSession() as session:
            async with session.post(url, headers=headers, json=payload) as response:
                if response.status == 200:
                    return await response.json()
                else:
                    twitch_logger.error(f"Failed to trigger shoutout. Status: {response.status}. Message: {await response.text()}")
                    return None
    except Exception as e:
        twitch_logger.error(f"Error triggering shoutout: {e}")
        return None

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

# Run the bot
bot.run()