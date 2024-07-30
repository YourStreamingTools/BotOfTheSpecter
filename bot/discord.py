import asyncio
import logging
import os
import json
import signal
import discord
from discord.ext import commands
from threading import Thread
from enum import Enum
import argparse
from dotenv import load_dotenv
import aiomysql
import socketio

# Load environment variables from .env file
load_dotenv()

# Define logging directory
webroot = "/var/www/"
logs_directory = "logs"
discord_logs = os.path.join(logs_directory, "discord")

# Ensure directory exists
for directory in [logs_directory, discord_logs]:
    directory_path = os.path.join(webroot, directory)
    if not os.path.exists(directory_path):
        os.makedirs(directory_path)

# Function to setup logger
def setup_logger(name, log_file, level=logging.ERROR):
    handler = logging.FileHandler(log_file)
    formatter = logging.Formatter('%(asctime)s - %(levelname)s - %(message)s')
    handler.setFormatter(formatter)
    logger = logging.getLogger(name)
    logger.setLevel(level)
    logger.addHandler(handler)
    return logger

# MySQL connection
async def get_mysql_connection():
    return await aiomysql.connect(
        host=os.getenv('SQL_HOST'),
        user=os.getenv('SQL_USER'),
        password=os.getenv('SQL_PASSWORD'),
        db='website'
    )

# Fetch admin user ID and live channel ID from the database
async def fetch_discord_details(username):
    connection = await get_mysql_connection()
    async with connection.cursor() as cursor:
        await cursor.execute("""
            SELECT du.discord_id, du.live_channel_id
            FROM discord_users du
            JOIN users u ON du.user_id = u.id
            WHERE u.username = %s
        """, (username,))
        result = await cursor.fetchone()
        await connection.close()
        if result:
            return result[0], result[1]
        return None, None

# Fetch API token from the database
async def fetch_api_token(username):
    connection = await get_mysql_connection()
    async with connection.cursor() as cursor:
        await cursor.execute("""
            SELECT api_key
            FROM users
            WHERE username = %s
        """, (username,))
        result = await cursor.fetchone()
        await connection.close()
        if result:
            return result[0]
        return None

class ChannelType(Enum):
    GUILD_TEXT = 0
    DM = 1
    GUILD_VOICE = 2
    GROUP_DM = 3
    GUILD_CATEGORY = 4
    GUILD_ANNOUNCEMENT = 5
    ANNOUNCEMENT_THREAD = 10
    PUBLIC_THREAD = 11
    PRIVATE_THREAD = 12
    GUILD_STAGE_VOICE = 13
    GUILD_DIRECTORY = 14
    GUILD_FORUM = 15
    GUILD_MEDIA = 16

    @classmethod
    def has_value(cls, value):
        return value in cls._value2member_map_

class BotOfTheSpecter(commands.Bot):
    def __init__(self, discord_token, live_channel_id, channel_name, api_token, discord_logger, **kwargs):
        intents = discord.Intents.default()
        intents.message_content = True
        super().__init__("!", intents=intents, **kwargs)
        self.discord_token = discord_token
        self.live_channel_id = live_channel_id
        self.live_channel = None
        self.admin_user_id = kwargs.get("admin_user_id", 0)
        self.channel_name = channel_name
        self.api_token = api_token
        self.logger = discord_logger

    async def on_ready(self):
        self.logger.info(f'Logged in as {self.user} (ID: {self.user.id})')
        self.live_channel = self.get_channel(self.live_channel_id)
        await self.add_cog(WebSocketCog(self, self.api_token, self.logger))

    async def on_message(self, message: discord.Message) -> None:
        if message.author == self.user:
            return
        if message.channel.type == discord.ChannelType.private and message.author.id == self.admin_user_id:
            if message.content == "!offline":
                await message.reply("Marking the channel as offline.")
                await self.update_channel_status(self.live_channel, "offline")
            elif message.content == "!online":
                await message.reply("Marking the channel as live.")
                await self.update_channel_status(self.live_channel, "online")
            else:
                await message.reply("I'm not sure what you want.")
        return await super().on_message(message)
    
    async def update_channel_status(self, channel: discord.VoiceChannel, status: str):
        if not channel:
            return
        if status == "offline":
            await self.set_channel_name(channel, f"🔴 {self.channel_name} isn't live")
        elif status == "online":
            await self.set_channel_name(channel, f"🟢 {self.channel_name} is live!")

    async def set_channel_name(self, channel: discord.VoiceChannel, name: str):
        if channel:
            await channel.edit(name=name)

class WebSocketCog(commands.Cog, name='WebSocket'):
    def __init__(self, bot: BotOfTheSpecter, api_token: str, logger=None):
        self.logger = logger or logging.getLogger(self.__class__.__name__)
        self.bot = bot
        self.api_token = api_token
        self.sio = socketio.AsyncClient()

        @self.sio.event
        async def connect():
            self.logger.info("Connected to WebSocket server")
            await self.sio.emit('REGISTER', {'code': self.api_token})

        @self.sio.event
        async def disconnect():
            self.logger.info("Disconnected from WebSocket server")

        @self.sio.event
        async def STREAM_ONLINE(data):
            self.logger.info(f"Received STREAM_ONLINE event: {data}")
            await self.bot.update_channel_status(self.bot.live_channel, "online")

        @self.sio.event
        async def STREAM_OFFLINE(data):
            self.logger.info(f"Received STREAM_OFFLINE event: {data}")
            await self.bot.update_channel_status(self.bot.live_channel, "offline")

        self.bot.loop.create_task(self.start_websocket())

    async def start_websocket(self):
        try:
            await self.sio.connect('wss://websocket.botofthespecter.com:8080')
            await self.sio.wait()
        except Exception as e:
            self.logger.error(f"WebSocket connection error: {e}")
            await asyncio.sleep(5)
            self.bot.loop.create_task(self.start_websocket())

    def cog_unload(self):
        self.bot.loop.create_task(self.sio.disconnect())

class DiscordBotRunner:
    def __init__(self, discord_token, channel_name, discord_logger):
        self.logger = discord_logger
        self.discord_token = discord_token
        self.channel_name = channel_name
        self.bot = None
        self.loop = None
        signal.signal(signal.SIGTERM, self.sig_handler)
        signal.signal(signal.SIGINT, self.sig_handler)

    def sig_handler(self, signum, frame):
        signame = signal.Signals(signum).name
        self.logger.error(f'Caught Signal {signame} ({signum})')
        self.stop()

    def stop(self):
        if self.bot is not None:
            future = asyncio.run_coroutine_threadsafe(self.bot.close(), self.loop)
            try:
                future.result(5)
            except TimeoutError:
                self.logger.error("Timeout error - Bot didn't respond. Forcing close.")

    def run(self):
        self.loop = asyncio.new_event_loop()
        asyncio.set_event_loop(self.loop)
        self.loop.run_until_complete(self.initialize_bot())

    async def initialize_bot(self):
        api_token = await fetch_api_token(self.channel_name)
        admin_user_id, live_channel_id = await fetch_discord_details(self.channel_name)
        self.bot = BotOfTheSpecter(self.discord_token, live_channel_id, self.channel_name, api_token, self.logger, admin_user_id=admin_user_id)
        await self.bot.start(self.discord_token)

def main():
    parser = argparse.ArgumentParser(description="BotOfTheSpecter Discord Bot")
    parser.add_argument("-channel", dest="channel_name", required=True, help="Target Twitch channel name")
    args = parser.parse_args()

    bot_log_file = os.path.join(webroot, discord_logs, f"{args.channel_name}.txt")
    discord_logger = setup_logger('discord', bot_log_file, level=logging.ERROR)
    discord_token = os.getenv("DISCORD_TOKEN")
    bot_runner = DiscordBotRunner(discord_token, args.channel_name, discord_logger)
    bot_runner.run()

if __name__ == "__main__":
    main()