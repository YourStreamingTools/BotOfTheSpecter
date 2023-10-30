import os
import asyncio
import twitchio
from twitchio.ext import commands
from twitchio.ext import pubsub
from twitchio.ext import eventsub
import twitchAPI
from twitchAPI.twitch import Twitch
from twitchAPI.oauth import UserAuthenticator
from twitchAPI.oauth import refresh_access_token
from twitchAPI.type import AuthScope
import sqlite3
import argparse
import requests
import logging
import signal
import aiohttp
import time
from datetime import datetime
from datetime import timedelta

# Parse command-line arguments
parser = argparse.ArgumentParser(description="BotOfTheSpecter Chat Bot")
parser.add_argument("-channel", dest="target_channel", required=True, help="Target Twitch channel name")
parser.add_argument("-channelid", dest="channel_id", required=True, help="Twitch user ID")
parser.add_argument("-token", dest="channel_auth_token", required=True, help="Auth Token for authentication")
args = parser.parse_args()

# Define a signal handler function to handle Ctrl+C (SIGINT)
def signal_handler(sig, frame):
    global running
    print("Exiting gracefully...")
    running = False

# Set up the signal handler to listen for Ctrl+C
signal.signal(signal.SIGINT, signal_handler)

# Twitch bot settings
BOT_USERNAME = ""  # CHANGE TO MAKE THIS WORK
OAUTH_TOKEN = ""  # CHANGE TO MAKE THIS WORK
CLIENT_ID = ""    # CHANGE TO MAKE THIS WORK
CLIENT_SECRET = "" # CHANGE TO MAKE THIS WORK
TWITCH_API_CLIENT_ID = CLIENT_ID
CHANNEL_NAME = args.target_channel
CHANNEL_ID = args.channel_id
CHANNEL_AUTH = args.channel_auth_token

# Create the bot instance
bot = commands.Bot(
    token=OAUTH_TOKEN,
    prefix='!',
    initial_channels=[CHANNEL_NAME],
    nick=BOT_USERNAME,
)

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

# Logs
webroot = "/var/www/html"
logs_directory = "logs"
bot_logs = os.path.join(logs_directory, "bot")
chat_logs = os.path.join(logs_directory, "chat")
twitch_logs = os.path.join(logs_directory, "twitch")

for directory in [logs_directory, bot_logs]:
    directory_path = os.path.join(webroot, directory)
    if not os.path.exists(directory_path):
        os.makedirs(directory_path)

log_file = os.path.join(webroot, bot_logs, f"{CHANNEL_NAME}.txt")
logging.basicConfig(filename=log_file, level=logging.INFO, format="%(asctime)s - %(levelname)s - %(message)s")

# Logs for Chat
chat_logger = logging.getLogger("chat")
chat_log_file = os.path.join(webroot, chat_logs, f"{CHANNEL_NAME}.txt")
chat_handler = logging.FileHandler(chat_log_file)
chat_logger.setLevel(logging.INFO)
chat_logger.addHandler(chat_handler)

# Logs for Twitch
twitch_logger = logging.getLogger("twitch")
twitch_log_file = os.path.join(webroot, twitch_logs, f"{CHANNEL_NAME}.txt")
twitch_handler = logging.FileHandler(twitch_log_file)
twitch_logger.setLevel(logging.INFO)
twitch_logger.addHandler(twitch_handler)

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
    logging.info(f'Logged in as | {bot_instance.nick}')
    logging.info(f'User id is | {bot_instance.user_id}')
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
    streak = {subscriber.streak}
    months = {subscriber.cumulative_months}
    gift = {subscriber.is_gift}
    giftanonymous = {subscriber.is_anonymous}
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

@bot.command(name='timer')
async def start_timer(ctx: commands.Context):
    content = ctx.message.content.strip()
    try:
        _, minutes = content.split(' ')
        minutes = int(minutes)
    except ValueError:
        # Default to 5 minutes if the user didn't provide a valid value
        minutes = 5

    await ctx.send(f"Timer started for {minutes} minute(s).")
    await asyncio.sleep(minutes * 60)  # Convert minutes to seconds
    await ctx.send(f"@{ctx.author.name}, your {minutes} minute timer has ended!")

@bot.command(name='so')
async def shoutout(ctx: commands.Context):
    user = ctx.author
    is_mod = user.is_mod

    if not is_mod:
        await ctx.send("You must be a moderator to use the !so command.")
        return

    user_to_shoutout = ctx.message.content.strip().split(' ')[-1]
    user_id = await get_user_id(user_to_shoutout)

    if not user_id:
        await ctx.send(f"Sorry, I couldn't find a user with the name {user_to_shoutout}.")
        return

    game = await get_latest_stream_game(user_id)

    if not game:
        await ctx.send(f"Sorry, I couldn't determine the last game {user_to_shoutout} played.")
        return

    shoutout_message = (
        f"Hey, huge shoutout to @{user_to_shoutout}! "
        f"You should go give them a follow over at "
        f"https://www.twitch.tv/{user_to_shoutout} where they were playing: {game}"
    )
    await ctx.send(shoutout_message)

async def get_user_id(user_to_shoutout):
    url = f"https://api.twitch.tv/helix/users?login={user_to_shoutout}"
    headers = {
        "Client-ID": TWITCH_API_CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}",
    }
    response = await fetch_json(url, headers)
    if response and "data" in response:
        return response["data"][0]["id"]
    return None

async def get_latest_stream_game(user_id):
    url = f"https://api.twitch.tv/helix/streams?user_id={user_id}"
    headers = {
        "Client-ID": TWITCH_API_CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}",
    }
    response = await fetch_json(url, headers)
    if response and "data" in response:
        return response["data"][0]["game_name"]
    return None

async def fetch_json(url, headers):
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(url, headers=headers) as response:
                if response.status == 200:
                    return await response.json()
    except Exception as e:
        print(f"Error fetching data: {e}")
    return None

@bot.command(name='addcommand')
async def add_command(ctx: commands.Context):
    # Check if the user is a moderator or broadcaster
    if is_mod_or_broadcaster(ctx.author):
        # Parse the command and response from the message
        command, response = ctx.message.content.strip().split(' ', 1)[1].split(' ', 1)

        # Insert the command and response into the database
        cursor.execute('INSERT OR REPLACE INTO custom_commands (command, response) VALUES (?, ?)', (command, response))
        conn.commit()
        await ctx.send(f'Custom command added: !{command}')

def is_mod_or_broadcaster(user):
    return 'moderator' in user.badges or user.is_mod

@bot.command(name='removecommand')
async def remove_command(ctx: commands.Context):
    # Check if the user is a moderator or broadcaster
    if is_mod_or_broadcaster(ctx.author):
        # Parse the command to remove from the message
        command = ctx.message.content.strip().split(' ')[1]

        # Delete the command from the database
        cursor.execute('DELETE FROM custom_commands WHERE command = ?', (command,))
        conn.commit()
        await ctx.send(f'Custom command removed: !{command}')

def is_mod_or_broadcaster(user):
    return 'moderator' in user.badges or user.is_mod

@bot.event
async def event_message(ctx):
    # Get the message content
    message_content = ctx.content.strip()
    chat_logger.info(f"Chat message from {ctx.author.name}: {ctx.content}")
    
    # Check if the message starts with an exclamation mark
    if message_content.startswith('!'):
        # Extract the potential command (excluding the exclamation mark)
        command = message_content[1:]

        # Check if the command exists in the database
        cursor.execute('SELECT response FROM custom_commands WHERE command = ?', (command,))
        result = cursor.fetchone()

        if result:
            response = result[0]
            await ctx.channel.send(response)
        else:
            await ctx.channel.send(f'No such command found: !{command}')

# Run the bot
bot.run()