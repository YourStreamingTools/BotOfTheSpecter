import argparse
import asyncio
import logging
import os
import signal
import aiohttp
import discord
from discord.ext import commands
from dotenv import load_dotenv

# Load environment variables from .env file
load_dotenv()

# Define logging directory
logs_directory = "/var/www/logs"
discord_logs = os.path.join(logs_directory, "discord")

# Ensure directory exists
for directory in [logs_directory, discord_logs]:
    if not os.path.exists(directory):
        os.makedirs(directory)

# Function to setup logger
def setup_logger(name, log_file, level=logging.INFO):
    handler = logging.FileHandler(log_file)
    formatter = logging.Formatter('%(asctime)s - %(levelname)s - %(message)s')
    handler.setFormatter(formatter)
    logger = logging.getLogger(name)
    logger.setLevel(level)
    logger.addHandler(handler)
    return logger

# Bot class
class BotOfTheSpecter(commands.Bot):
    def __init__(self, discord_token, discord_logger, **kwargs):
        intents = discord.Intents.default()
        intents.message_content = True
        super().__init__("!", intents=intents, **kwargs)
        self.discord_token = discord_token
        self.logger = discord_logger
        self.typing_speed = 50
        self.http._HTTPClient__session = aiohttp.ClientSession(connector=aiohttp.TCPConnector(ssl=False))
        self.processed_messages_file = f"/var/www/logs/discord/messages.txt"
        # Ensure the log file exists
        if not os.path.exists(self.processed_messages_file):
            open(self.processed_messages_file, 'w').close()

    async def on_ready(self):
        self.logger.info(f'Logged in as {self.user} (ID: {self.user.id})')
        self.logger.info("BotOfTheSpecter Discord Bot has started.")

    async def get_ai_response(self, user_message):
        try:
            async with aiohttp.ClientSession() as session:
                payload = {
                    "message": user_message,
                    "channel": self.channel_name,
                }
                async with session.post('https://ai.botofthespecter.com/', json=payload) as response:
                    response.raise_for_status()  # Raise an exception for bad responses
                    ai_response = await response.text()  # Read response as plain text
                    self.logger.info(f"AI response received: {ai_response}")
                    return ai_response
        except aiohttp.ClientError as e:
            self.logger.error(f"Error getting AI response: {e}")
            return "Sorry, I could not understand your request."

    async def on_message(self, message):
        # Ignore bot's own messages
        if message.author == self.user:
            return
        # Use the message ID to track if it's already been processed
        message_id = str(message.id)
        # Check if the message ID is already in the file
        with open(self.processed_messages_file, 'r') as file:
            processed_messages = file.read().splitlines()
        if message_id in processed_messages:
            self.logger.info(f"Message ID {message_id} has already been processed. Skipping.")
            return
        # Process the message
        if isinstance(message.channel, discord.DMChannel):
            async with message.channel.typing():
                ai_response = await self.get_ai_response(message.content)
                typing_delay = len(ai_response) / self.typing_speed
                await asyncio.sleep(typing_delay)
                await message.author.send(ai_response)
            # Mark the message as processed by appending the message ID to the file
            with open(self.processed_messages_file, 'a') as file:
                file.write(message_id + '\n')
        # If the message is in a server channel, process commands
        await self.process_commands(message)

class DiscordBotRunner:
    def __init__(self, discord_token, discord_logger):
        self.logger = discord_logger
        self.discord_token = discord_token
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
            self.logger.info("Stopping BotOfTheSpecter Discord Bot")
            future = asyncio.run_coroutine_threadsafe(self.bot.close(), self.loop)
            try:
                future.result(5)
            except TimeoutError:
                self.logger.error("Timeout error - Bot didn't respond. Forcing close.")
            except asyncio.CancelledError:
                self.logger.error("Bot task was cancelled. Forcing close.")

    def run(self):
        self.loop = asyncio.new_event_loop()
        asyncio.set_event_loop(self.loop)
        self.logger.info("Starting BotOfTheSpecter Discord Bot")
        try:
            self.loop.run_until_complete(self.initialize_bot())
        except asyncio.CancelledError:
            self.logger.error("BotRunner task was cancelled.")
        finally:
            self.loop.close()

    async def initialize_bot(self):
        self.bot = BotOfTheSpecter(self.discord_token, self.logger)
        await self.bot.start(self.discord_token)

def main():
    bot_log_file = os.path.join(discord_logs, f"discordbot.txt")
    discord_logger = setup_logger('discord', bot_log_file, level=logging.INFO)
    discord_token = os.getenv("DISCORD_TOKEN")
    bot_runner = DiscordBotRunner(discord_token, discord_logger)
    bot_runner.run()

if __name__ == "__main__":
    main()