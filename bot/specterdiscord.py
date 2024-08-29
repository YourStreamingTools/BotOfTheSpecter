import argparse
import asyncio
import logging
import os
import signal
import aiohttp
import discord
from discord.ext import commands as commands
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

# Global configuration class
class Config:
    def __init__(self):
        self.api_token = None

config = Config()

# Bot class
class BotOfTheSpecter(commands.Bot):
    def __init__(self, discord_token, discord_logger, **kwargs):
        intents = discord.Intents.default()
        intents.message_content = True
        super().__init__("!", intents=intents, **kwargs)
        self.discord_token = discord_token
        self.logger = discord_logger
        self.typing_speed = 50
        self.processed_messages_file = f"/var/www/logs/discord/messages.txt"
        # Ensure the log file exists
        if not os.path.exists(self.processed_messages_file):
            open(self.processed_messages_file, 'w').close()

    async def on_ready(self):
        self.logger.info(f'Logged in as {self.user} (ID: {self.user.id})')
        await self.add_cog(QuoteCog(self, config.api_token, self.logger))
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
                    # Split the response into chunks of 2000 characters
                    if ai_response:  # Ensure response is not empty
                        chunks = [ai_response[i:i + 2000] for i in range(0, len(ai_response), 2000)]
                        return chunks
                    else:
                        self.logger.error("Received empty AI response")
                        return ["Sorry, I could not understand your request."]
        except aiohttp.ClientError as e:
            self.logger.error(f"Error getting AI response: {e}")
            return ["Sorry, I could not understand your request."]

    async def on_message(self, message):
        # Ignore bot's own messages
        if message.author == self.user:
            return
        # Use the message ID to track if it's already been processed
        message_id = str(message.id)
        with open(self.processed_messages_file, 'r') as file:
            processed_messages = file.read().splitlines()
        if message_id in processed_messages:
            self.logger.info(f"Message ID {message_id} has already been processed. Skipping.")
            return
        # Process the message
        if isinstance(message.channel, discord.DMChannel):
            async with message.channel.typing():
                try:
                    ai_responses = await self.get_ai_response(message.content)
                    for ai_response in ai_responses:
                        typing_delay = len(ai_response) / self.typing_speed
                        await asyncio.sleep(typing_delay)
                        await message.author.send(ai_response)
                    self.logger.info(f"Sent AI response to {message.author}")
                except discord.HTTPException as e:
                    self.logger.error(f"Failed to send message: {e}")
            # Mark the message as processed by appending the message ID to the file
            with open(self.processed_messages_file, 'a') as file:
                file.write(message_id + '\n')
        # If the message is in a server channel, process commands
        await self.process_commands(message)

class QuoteCog(commands.Cog, name='Quote'):
    def __init__(self, bot: BotOfTheSpecter, api_token: str, logger=None):
        self.bot = bot
        self.api_token = api_token
        self.logger = logger or logging.getLogger(self.__class__.__name__)
        self.typing_speed = 50

    @commands.command(name="quote")
    async def get_quote(self, ctx):
        if ctx.guild is None:
            return
        url = f"https://api.botofthespecter.com/quotes?api_key={self.api_token}"
        try:
            async with aiohttp.ClientSession() as session:
                async with ctx.typing():
                    async with session.get(url) as response:
                        if response.status == 200:
                            quote_data = await response.json()
                            if "quote" in quote_data and "author" in quote_data:
                                quote = quote_data["quote"]
                                author = quote_data["author"]
                                message = f'📜 **Quote:** "{quote}" — *{author}*'
                                # Calculate delay based on message length
                                typing_delay = len(message) / self.typing_speed
                                await asyncio.sleep(typing_delay)
                                await ctx.send(message)
                            else:
                                await ctx.send("Sorry, I couldn't fetch a quote at this time.")
                        else:
                            self.logger.error(f"Failed to fetch quote. Status code: {response.status}")
                            await ctx.send("Sorry, I couldn't fetch a quote at this time.")
        except Exception as e:
            self.logger.error(f"Error fetching quote: {e}")
            await ctx.send("An error occurred while fetching the quote.")

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
            for task in asyncio.all_tasks(self.loop):
                task.cancel()
            try:
                self.loop.run_until_complete(self.bot.close())
            except asyncio.CancelledError:
                self.logger.error("Bot task was cancelled.")

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