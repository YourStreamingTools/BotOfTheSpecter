import os
import asyncio
import twitchio
from twitchio.ext import commands
from twitchio.ext import pubsub
import sqlite3
import time
import argparse
import requests
from datetime import datetime
import logging
import signal
import aiohttp

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

# Create an instance of your Bot class
bot_instance = bot

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

@commands.command()
async def start_bot(self, ctx: commands.Context):
    requests_made = 0  # Initialize requests_made
    start_time = time.time()  # Initialize start_time
    while True:
        current_time = int(time.time())  # Get current UNIX timestamp
        # Configure logging here if needed

@bot.event()
async def on_pubsub_channel_subscription(data):
    if data['type'] == 'stream.online':
        print(f"The stream is now online, {BOT_USERNAME} is ready!")

@bot.event()
async def event_new_follower(follower):
    print(f"New follower: {follower.name}")

@bot.event()
async def event_cheer(cheerer, message):
    print(f"{cheerer.display_name} cheered {message.bits} bits!")

    
@bot.event()
async def event_subscribe(subscriber):
    print(f"{subscriber.display_name} just subscribed to the channel!")

@bot.command(name='so')
async def shoutout(ctx: commands.Context):
    # Check if the user who executed the command is a moderator or the broadcaster
    user = ctx.author
    is_mod = False

    # Check if the user is a moderator by examining their badges
    if user.badges:
        for badge in user.badges:
            if badge == 'moderator':
                is_mod = True
                break

    # Check if the user is a moderator or the broadcaster
    if is_mod or user.is_mod:
        # Get the user to shout out
        user_to_shoutout = ctx.message.content.strip().split(' ')[-1]

        # Fetch the user's Twitch ID using the API
        user_id = await get_user_id(user_to_shoutout)

        if user_id:
            # Fetch the user's latest stream info to get the game they are currently playing
            game = await get_latest_stream_game(user_id)

            if game:
                # Create the shoutout message
                shoutout_message = (
                    f"Hey, did you know {user_to_shoutout} streams too? "
                    f"They're pretty fun to watch as well! You should go give them a follow over at "
                    f"https://www.twitch.tv/{user_to_shoutout} where they were playing: {game}"
                )

                # Send the shoutout message in the chat
                await ctx.send(shoutout_message)
            else:
                await ctx.send(f"Sorry, I couldn't determine the last game {user_to_shoutout} played.")
        else:
            await ctx.send(f"Sorry, I couldn't find a user with the name {user_to_shoutout}.")
    else:
        await ctx.send("You must be a moderator to use the !so command.")

# Function to get the user's Twitch ID using the API
async def get_user_id(username):
    url = f"https://api.twitch.tv/helix/users?login={username}"
    headers = {
        "Client-ID": TWITCH_API_CLIENT_ID,
        "Authorization": f"Bearer {CHANNEL_AUTH}",
    }
    response = await fetch_json(url, headers)

    if response and "data" in response:
        return response["data"][0]["id"]
    return None

# Function to get the user's latest stream info
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

# Function to make a GET request and parse the response as JSON
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

@bot.command(name='execute')
async def execute_command(ctx: commands.Context):
    # Get the command to execute from the message
    command = ctx.message.content.strip().split(' ')[1]

    # Check if the command exists in the database
    cursor.execute('SELECT response FROM custom_commands WHERE command = ?', (command,))
    result = cursor.fetchone()

    if result:
        response = result[0]
        await ctx.send(response)
    else:
        await ctx.send(f'Custom command !{command} not found.')

def is_mod_or_broadcaster(user):
    return 'moderator' in user.badges or user.is_mod

# Run the bot
bot.run()