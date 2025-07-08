# Standard library
import asyncio
import logging
import os
import signal
import json
import tempfile
import random
import time
from datetime import datetime, timezone
import subprocess
from subprocess import PIPE
import re

# Third-party libraries
import aiohttp
import discord
from discord.ext import commands
from discord import app_commands
from dotenv import load_dotenv
import aiomysql
import socketio
import yt_dlp

# Global configuration class
class Config:
    def __init__(self):
        # Load environment variables from .env file
        load_dotenv()
        # Environment variables
        self.discord_token = os.getenv("DISCORD_TOKEN")
        self.api_token = os.getenv("API_KEY")
        self.admin_key = os.getenv("ADMIN_KEY")
        self.sql_host = os.getenv('SQL_HOST')
        self.sql_user = os.getenv('SQL_USER')
        self.sql_password = os.getenv('SQL_PASSWORD')
        # Bot information
        self.bot_color = 0x001C1D
        self.discord_bot_service_version = "5.2.0"
        self.bot_version = self.discord_bot_service_version
        # File paths
        self.discord_version_file = "/var/www/logs/version/discord_version_control.txt"
        self.logs_directory = "/home/botofthespecter/logs/"
        self.discord_logs = os.path.join(self.logs_directory, "specterdiscord")
        self.processed_messages_file = f"/home/botofthespecter/logs/discord/messages.txt"
        self.cookies_path = "/home/botofthespecter/ytdl-cookies.txt"
        self.music_directory = '/mnt/cdn/music'
        # URLs
        self.websocket_url = "wss://websocket.botofthespecter.com"
        self.api_base_url = "https://api.botofthespecter.com"
        # Music player settings
        self.typing_speed = 50
        self.volume_default = 0.1
        # Status file path template
        self.stream_status_file = "/home/botofthespecter/logs/online/{channel_name}.txt"

# Initialize the configuration
config = Config()

# Ensure directories exist
for directory in [config.logs_directory, config.discord_logs]:
    if not os.path.exists(directory):
        os.makedirs(directory)

# Ensure directories exist
for directory in [config.logs_directory, config.discord_logs]:
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

# Setup websocket listener for global actions
class WebsocketListener:
    def __init__(self, bot, logger=None):
        self.bot = bot
        self.logger = logger
        self.sio = None
    async def start(self):
        self.sio = socketio.AsyncClient(logger=False, engineio_logger=False)
        admin_key = config.admin_key
        websocket_url = config.websocket_url
        @self.sio.event
        async def connect():
            self.logger.info("Connected to websocket server")
            await self.sio.emit("REGISTER", {
                "code": admin_key,
                "global_listener": True,
                "channel": "Global",
                "name": "Discord Bot Global Listener"
            })
        @self.sio.event
        async def disconnect():
            self.logger.info("Disconnected from websocket server")
        @self.sio.event
        async def SUCCESS(data):
            self.logger.info(f"Websocket registration successful: {data}")
        @self.sio.event
        async def ERROR(data):
            self.logger.error(f"Websocket error: {data}")
        @self.sio.event
        async def STREAM_ONLINE(data):
            self.logger.info(f"Received STREAM_ONLINE event: {data}")
            await self.bot.handle_stream_event("ONLINE", data)
        @self.sio.event
        async def STREAM_OFFLINE(data):
            self.logger.info(f"Received STREAM_OFFLINE event: {data}")
            await self.bot.handle_stream_event("OFFLINE", data)
        # Log all other events generically
        @self.sio.on('*')
        async def catch_all(event, data):
            self.logger.info(f"Received websocket event '{event}': {data}")
        await self.sio.connect(websocket_url)

# Channel mapping class to manage multiple Discord servers
class ChannelMapping:
    def __init__(self):
        self.mappings = {}  # channel_code -> {guild_id, channel_id, channel_name}
        self.load_mappings()
    def load_mappings(self):
        mapping_file = f"{config.discord_logs}/discord_channel_mappings.json"
        try:
            if os.path.exists(mapping_file):
                with open(mapping_file, 'r') as f:
                    self.mappings = json.load(f)
        except Exception as e:
            print(f"Error loading channel mappings: {e}")
    def save_mappings(self):
        mapping_file = f"{config.discord_logs}/discord_channel_mappings.json"
        try:
            with open(mapping_file, 'w') as f:
                json.dump(self.mappings, f, indent=2)
        except Exception as e:
            print(f"Error saving channel mappings: {e}")
    def add_mapping(self, channel_code, guild_id, channel_id, channel_name):
        self.mappings[channel_code] = {
            "guild_id": guild_id,
            "channel_id": channel_id,
            "channel_name": channel_name
        }
        self.save_mappings()
    def get_mapping(self, channel_code):
        return self.mappings.get(channel_code)
    def remove_mapping(self, channel_code):
        if channel_code in self.mappings:
            del self.mappings[channel_code]
            self.save_mappings()
            return True
        return False

# Bot class
class BotOfTheSpecter(commands.Bot):
    def __init__(self, discord_token, discord_logger, **kwargs):
        intents = discord.Intents.default()
        intents.message_content = True
        intents.voice_states = True
        super().__init__(command_prefix="!", intents=intents, **kwargs)
        self.discord_token = discord_token
        self.logger = discord_logger
        self.typing_speed = config.typing_speed
        self.processed_messages_file = config.processed_messages_file
        self.version = config.bot_version
        self.pool = None  # Initialize the pool attribute
        self.channel_mapping = ChannelMapping()
        self.websocket_client = None
        self.cooldowns = {}
        # Define internal commands that should never be overridden by custom commands
        self.internal_commands = {
            # Voice commands
            'connect', 'join', 'summon',
            'disconnect', 'leave', 'dc',
            'voice_status', 'vstatus',
            # Music commands
            'play', 'skip', 's', 'next',
            'stop', 'pause', 'queue', 'q', 'playlist',
            'song', 'volume',
            # Utility commands
            'quote', 'ticket', 'setuptickets', 'settings'
        }
        # Ensure the log directory and file exist
        messages_dir = os.path.dirname(self.processed_messages_file)
        if not os.path.exists(messages_dir):
            os.makedirs(messages_dir)
        if not os.path.exists(self.processed_messages_file):
            open(self.processed_messages_file, 'w').close()
        self.stream_status_file_template = config.stream_status_file

    def read_stream_status(self, channel_name):
        # Use the configured path template for the stream status file
        normalized_channel_name = channel_name.lower().replace(' ', '')
        status_file_path = self.stream_status_file_template.format(channel_name=normalized_channel_name)
        self.logger.debug(f"Checking stream status file: {status_file_path}")
        if os.path.exists(status_file_path):
            try:
                with open(status_file_path, "r") as file:
                    status = file.read().strip()
                    is_online = status.lower() == "true"
                    self.logger.debug(f"Stream status for {normalized_channel_name}: {status} -> {is_online}")
                    return is_online
            except Exception as e:
                self.logger.error(f"Error reading stream status file for {normalized_channel_name}: {e}")
                return False
        else:
            self.logger.warning(f"Stream status file not found for {normalized_channel_name}: {status_file_path}")
            return False

    async def on_ready(self):
        self.logger.info(f'Logged in as {self.user} (ID: {self.user.id})')
        self.logger.info(f'Bot version: {self.version}')
        # Update the global version file for dashboard display
        try:
            os.makedirs(os.path.dirname(config.discord_version_file), exist_ok=True)
            with open(config.discord_version_file, "w") as f:
                f.write(config.discord_bot_service_version + os.linesep)
            self.logger.info(f"Updated Discord bot version file: {config.discord_bot_service_version}")
        except Exception as e:
            self.logger.error(f"Failed to update Discord bot version file: {e}")
        # Set the initial presence and check stream status for each guild
        for guild in self.guilds:
            # Automatic mapping: get Twitch display name from SQL
            resolver = DiscordChannelResolver(self.logger)
            discord_info = await resolver.get_discord_info_from_guild_id(guild.id)
            if discord_info:
                twitch_display_name = discord_info.get('twitch_display_name', guild.name)
                channel_key = twitch_display_name.lower().replace(' ', '')
                # Combine mapping and status into a single log entry
                online = self.read_stream_status(channel_key)
                self.logger.info(
                    f"Guild '{guild.name}' (ID: {guild.id}) auto-mapped to Twitch user '{twitch_display_name}', "
                    f"stream is {'online' if online else 'offline'} for channel key '{channel_key}'."
                )
        await self.update_presence()
        await self.add_cog(QuoteCog(self, config.api_token, self.logger))
        await self.add_cog(TicketCog(self, self.logger))
        await self.add_cog(VoiceCog(self, self.logger))
        self.logger.info("BotOfTheSpecter Discord Bot has started.")
        # Start websocket listener in the background
        self.websocket_listener = WebsocketListener(self, self.logger)
        asyncio.create_task(self.websocket_listener.start())

    async def setup_hook(self):
        # Sync the slash commands when the bot starts
        try:
            await self.tree.sync()
            self.logger.info("Successfully synced slash commands.")
        except Exception as e:
            self.logger.error(f"Error syncing slash commands: {e}")
        # Add error handler for command tree
        self.tree.on_error = self.on_app_command_error

    async def on_app_command_error(self, interaction: discord.Interaction, error: app_commands.AppCommandError):
        # Ignore CommandNotFound errors (commands from other bots)
        if isinstance(error, app_commands.CommandNotFound):
            return
        # Log other errors as usual
        self.logger.error(f"Error in application command: {str(error)}")

    async def get_ai_response(self, user_message, channel_name):
        try:
            async with aiohttp.ClientSession() as session:
                payload = {
                    "message": user_message,
                    "channel": channel_name,
                }
                self.logger.info(f"Sending payload to AI: {payload}")
                async with session.post('https://ai.botofthespecter.com/', json=payload) as response:
                    self.logger.info(f"AI server response status: {response.status}")
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
        except Exception as e:
            self.logger.error(f"Unexpected error in get_ai_response: {e}")
            return ["Sorry, I encountered an error processing your request."]

    async def on_message(self, message):
        # Ignore bot's own messages
        if message.author == self.user:
            return
        # Determine the "channel_name" based on the source of the message
        if isinstance(message.channel, discord.DMChannel):
            channel = message.channel
            channel_name = str(message.author.id)  # Use user ID for DMs
        else:
            channel = message.channel
            channel_name = str(message.guild.name)  # Use guild name for server messages
        # Use the message ID to track if it's already been processed
        message_id = str(message.id)
        with open(self.processed_messages_file, 'r') as file:
            processed_messages = file.read().splitlines()
        if message_id in processed_messages:
            self.logger.info(f"Message ID {message_id} has already been processed. Skipping.")
            return
        # Process the message if it's in a DM channel
        if isinstance(message.channel, discord.DMChannel):
            try:
                # Fetch AI responses
                ai_responses = await self.get_ai_response(message.content, channel_name)
                # Only enter typing context if there are responses to send
                if ai_responses:
                    async with channel.typing():
                        self.logger.info(f"Processing message from {message.author}: {message.content}")
                        # Send each chunk of AI response
                        for ai_response in ai_responses:
                            if ai_response:  # Ensure we're not sending an empty message
                                typing_delay = len(ai_response) / self.typing_speed
                                await asyncio.sleep(typing_delay)  # Simulate typing speed
                                await message.author.send(ai_response)
                                self.logger.info(f"Sent AI response to {message.author}: {ai_response}")
                            else:
                                self.logger.error("AI response chunk was empty, not sending.")
            except discord.HTTPException as e:
                self.logger.error(f"Failed to send message: {e}")
            except Exception as e:
                self.logger.error(f"Unexpected error in on_message: {e}")
            # Mark the message as processed by appending the message ID to the file
            with open(self.processed_messages_file, 'a') as file:
                file.write(message_id + os.linesep)
        # If the message is in a server channel, check for custom commands
        elif isinstance(message.channel, discord.TextChannel):
            # Check if message starts with "!" (custom command)
            if message.content.startswith("!"):
                command_text = message.content[1:].strip().lower()
                command_name = command_text.split()[0] if command_text else ""
                self.logger.info(f"Received command: '{command_name}' from user {message.author} in guild {message.guild.name}")
                if command_name in self.internal_commands:
                    self.logger.info(f"Processing internal command: {command_name}")
                    await self.process_commands(message)
                else:
                    self.logger.info(f"Checking if '{command_name}' is a custom command")
                    custom_command_executed = await self.handle_custom_command(message)
                    if not custom_command_executed:
                        self.logger.info(f"No custom command found for '{command_name}', processing as built-in command")
                        await self.process_commands(message)
            else:
                # Not a command with "!" prefix, process as normal
                await self.process_commands(message)

    async def handle_custom_command(self, message):
        try:
            # Extract command from message (remove "!" prefix)
            command_text = message.content[1:].strip().lower()
            # Get the first word as the command (in case there are parameters)
            command_name = command_text.split()[0] if command_text else ""
            if not command_name:
                return False
            # Get the guild ID to find the corresponding Twitch user
            guild_id = message.guild.id
            # Use the resolver to get Discord info from guild ID
            resolver = DiscordChannelResolver(self.logger)
            discord_info = await resolver.get_discord_info_from_guild_id(guild_id)
            if not discord_info:
                self.logger.warning(f"No Discord info found for guild {guild_id}")
                return False
            # Get the Twitch display name to use as database name
            twitch_display_name = discord_info.get('twitch_display_name')
            if not twitch_display_name:
                self.logger.warning(f"No Twitch display name found for guild {guild_id}")
                return False
            # Convert display name to database name format (lowercase, no spaces)
            database_name = twitch_display_name.lower().replace(' ', '')
            # Query the custom_commands table in the user's database
            mysql_helper = MySQLHelper(self.logger)
            custom_command = await mysql_helper.fetchone(
                "SELECT command, response, status, cooldown FROM custom_commands WHERE command = %s",
                (command_name,),
                database_name=database_name,
                dict_cursor=True
            )
            if custom_command:
                # Check if the command is enabled
                if custom_command['status'] == 'Enabled':
                    cooldown_seconds = custom_command.get('cooldown', 0) or 0
                    now = time.time()
                    cooldown_key = (guild_id, command_name)
                    last_used = self.cooldowns.get(cooldown_key, 0)
                    if now - last_used < cooldown_seconds:
                        # Still in cooldown, do not respond
                        self.logger.info(f"Command '{command_name}' in guild {guild_id} is on cooldown.")
                        return True  # Command exists but is on cooldown
                    # Set cooldown
                    self.cooldowns[cooldown_key] = now
                    response = custom_command['response']
                    # Process custom variables - check for supported switches
                    switches = [
                        '(customapi.', '(count)', '(daysuntil.', '(command.', '(user)', '(author)', 
                        '(random.percent)', '(random.number)', '(random.percent.', '(random.number.',
                        '(random.pick.', '(math.', '(call.', '(usercount)', '(timeuntil.'
                    ]
                    # Basic variable replacements
                    response = response.replace('(user)', message.author.display_name)
                    response = response.replace('(author)', message.author.display_name)
                    # Process more complex variables if they exist
                    follow_up_commands = []
                    if any(switch in response for switch in switches):
                        response, follow_up_commands = await self.process_custom_variables(response, message, database_name)
                    # Send the main response (only if not empty after processing)
                    if response.strip():
                        await message.channel.send(response)
                    # Execute follow-up commands
                    for follow_command in follow_up_commands:
                        follow_response = await mysql_helper.fetchone(
                            "SELECT response FROM custom_commands WHERE command = %s",
                            (follow_command,),
                            database_name=database_name,
                            dict_cursor=True
                        )
                        if follow_response:
                            # Process variables in the follow-up command response too
                            follow_message_response = follow_response['response']
                            follow_message_response = follow_message_response.replace('(user)', message.author.display_name)
                            follow_message_response = follow_message_response.replace('(author)', message.author.display_name)
                            if any(switch in follow_message_response for switch in switches):
                                follow_message_response, _ = await self.process_custom_variables(follow_message_response, message, database_name)
                            # Send the follow-up command response
                            if follow_message_response.strip():
                                await message.channel.send(follow_message_response)
                        else:
                            self.logger.error(f"Follow-up command '{follow_command}' not found in database")
                    self.logger.info(f"Executed custom command '{command_name}' for {database_name} in guild {message.guild.name} (ID: {guild_id})")
                    # Mark the message as processed
                    with open(self.processed_messages_file, 'a') as file:
                        file.write(str(message.id) + os.linesep)
                    return True  # Custom command was executed successfully
                else:
                    self.logger.info(f"Custom command '{command_name}' is disabled for {database_name}")
                    return True  # Command exists but is disabled
            else:
                self.logger.debug(f"Custom command '{command_name}' not found for {database_name}")
                return False  # No custom command found
        except Exception as e:
            self.logger.error(f"Error handling custom command: {e}")
            return False  # Error occurred, let Discord try to process it

    async def process_custom_variables(self, response, message, database_name):
        try:
            mysql_helper = MySQLHelper(self.logger)
            command = message.content[1:].split()[0]
            messageAuthor = message.author.display_name
            messageContent = message.content
            tz = datetime.now().astimezone().tzinfo  # Get current timezone
            # Handle (command.)
            follow_up_commands = []
            if '(command.' in response:
                # Find all command references
                command_matches = re.findall(r'\(command\.(\w+)\)', response)
                for sub_command in command_matches:
                    # Remove the command reference from the response
                    response = response.replace(f"(command.{sub_command})", "")
                    # Add to follow-up commands list
                    follow_up_commands.append(sub_command)
                # Clean up any extra spaces left by removing commands
                response = re.sub(r'\s+', ' ', response).strip()
            # Handle (count) - Discord only displays, never increments
            if '(count)' in response:
                try:
                    # Get current count from database
                    count_row = await mysql_helper.fetchone(
                        "SELECT count FROM custom_counts WHERE command = %s",
                        (command,),
                        database_name=database_name,
                        dict_cursor=True
                    )
                    current_count = count_row['count'] if count_row else 0
                    # For Discord, only display the current count, do not increment
                    response = response.replace('(count)', str(current_count))
                except Exception as e:
                    self.logger.error(f"Error handling (count): {e}")
                    response = response.replace('(count)', "0")
            # Handle (usercount) - Discord only displays, never increments
            if '(usercount)' in response:
                try:
                    user_mention = re.search(r'@(\w+)', messageContent)
                    user_name = user_mention.group(1) if user_mention else messageAuthor
                    # Get the user count for the specific command
                    result = await mysql_helper.fetchone(
                        "SELECT count FROM user_counts WHERE command = %s AND user = %s",
                        (command, user_name),
                        database_name=database_name,
                        dict_cursor=True
                    )
                    user_count = result['count'] if result else 0
                    # For Discord, only display the current count, do not increment
                    response = response.replace('(usercount)', str(user_count))
                except Exception as e:
                    self.logger.error(f"Error while handling (usercount): {e}")
                    response = response.replace('(usercount)', "0")
            # Handle (daysuntil.)
            if '(daysuntil.' in response:
                get_date = re.search(r'\(daysuntil\.(\d{4}-\d{2}-\d{2})\)', response)
                if get_date:
                    date_str = get_date.group(1)
                    event_date = datetime.strptime(date_str, "%Y-%m-%d").date()
                    current_date = datetime.now(tz).date()
                    days_left = (event_date - current_date).days
                    # If days_left is negative, try next year
                    if days_left < 0:
                        next_year_date = event_date.replace(year=event_date.year + 1)
                        days_left = (next_year_date - current_date).days
                    response = response.replace(f"(daysuntil.{date_str})", str(days_left))
            # Handle (timeuntil.)
            if '(timeuntil.' in response:
                # Try first for full date-time format
                get_datetime = re.search(r'\(timeuntil\.(\d{4}-\d{2}-\d{2}(?:-\d{1,2}-\d{2})?)\)', response)
                if get_datetime:
                    datetime_str = get_datetime.group(1)
                    # Check if time components are included
                    if '-' in datetime_str[10:]:  # Full date-time format
                        event_datetime = datetime.strptime(datetime_str, "%Y-%m-%d-%H-%M").replace(tzinfo=tz)
                    else:  # Date only format, default to midnight
                        event_datetime = datetime.strptime(datetime_str + "-00-00", "%Y-%m-%d-%H-%M").replace(tzinfo=tz)
                    current_datetime = datetime.now(tz)
                    time_left = event_datetime - current_datetime
                    # If time_left is negative, try next year
                    if time_left.days < 0:
                        event_datetime = event_datetime.replace(year=event_datetime.year + 1)
                        time_left = event_datetime - current_datetime
                    days_left = time_left.days
                    hours_left, remainder = divmod(time_left.seconds, 3600)
                    minutes_left, _ = divmod(remainder, 60)
                    time_left_str = f"{days_left} days, {hours_left} hours, and {minutes_left} minutes"
                    # Replace the original placeholder with the calculated time
                    response = response.replace(f"(timeuntil.{datetime_str})", time_left_str)
            # Handle (user) and (author)
            if '(user)' in response:
                user_mention = re.search(r'@(\w+)', messageContent)
                user_name = user_mention.group(1) if user_mention else messageAuthor
                response = response.replace('(user)', user_name)
            if '(author)' in response:
                response = response.replace('(author)', messageAuthor)
            # Handle (call.)
            if '(call.' in response:
                calling_match = re.search(r'\(call\.(\w+)\)', response)
                if calling_match:
                    match_call = calling_match.group(1)
                    # For Discord, we'll just log this as it requires implementation
                    self.logger.info(f"Call function requested: {match_call}")
                    response = response.replace(f"(call.{match_call})", f"[Call: {match_call}]")
            # Handle random replacements
            if '(random.percent' in response or '(random.number' in response or '(random.pick.' in response:
                # Unified pattern for all placeholders
                pattern = r'\((random\.(percent|number|pick))(?:\.(.+?))?\)'
                matches = re.finditer(pattern, response)
                for match in matches:
                    category = match.group(1)  # 'random.percent', 'random.number', or 'random.pick'
                    details = match.group(3)  # Range (x-y) or items for pick
                    replacement = ''  # Initialize the replacement string
                    if 'percent' in category or 'number' in category:
                        # Default bounds for random.percent and random.number
                        lower_bound, upper_bound = 0, 100
                        if details:  # If range is specified, extract it
                            range_match = re.match(r'(\d+)-(\d+)', details)
                            if range_match:
                                lower_bound, upper_bound = int(range_match.group(1)), int(range_match.group(2))
                        random_value = random.randint(lower_bound, upper_bound)
                        replacement = f'{random_value}%' if 'percent' in category else str(random_value)
                    elif 'pick' in category:
                        # Split the details into items to pick from
                        items = details.split('.') if details else []
                        replacement = random.choice(items) if items else ''
                    # Replace the placeholder with the generated value
                    response = response.replace(match.group(0), replacement)
            # Handle (math.x+y)
            if '(math.' in response:
                math_match = re.search(r'\(math\.(.+)\)', response)
                if math_match:
                    math_expression = math_match.group(1)
                    try:
                        math_result = eval(math_expression)
                        response = response.replace(f'(math.{math_expression})', str(math_result))
                    except Exception as e:
                        self.logger.error(f"Math expression error: {e}")
                        response = response.replace(f'(math.{math_expression})', "Error")
            # Handle (customapi.)
            if '(customapi.' in response:
                url_match = re.search(r'\(customapi\.(\S+)\)', response)
                if url_match:
                    url = url_match.group(1)
                    json_flag = False
                    if url.startswith('json.'):
                        json_flag = True
                        url = url[5:]  # Remove 'json.' prefix
                    api_response = await self.fetch_api_response(url, json_flag=json_flag)
                    response = response.replace(f"(customapi.{url_match.group(1)})", api_response)
        except Exception as e:
            self.logger.error(f"Error processing custom variables: {e}")
        return response, follow_up_commands

    async def fetch_api_response(self, url, json_flag=False):
        try:
            async with aiohttp.ClientSession() as session:
                async with session.get(url, timeout=5) as resp:
                    if resp.status == 200:
                        if json_flag:
                            api_response = await resp.json()
                            return str(api_response)[:200]  # Limit response length
                        else:
                            api_response = await resp.text()
                            return api_response.strip()[:200]  # Limit response length
                    else:
                        return "API Error"
        except Exception as e:
            self.logger.error(f"API fetch error: {e}")
            return "API Unavailable"

    async def update_presence(self):
        server_count = len(self.guilds)  # Get the number of servers the bot is in
        await self.change_presence(activity=discord.Activity(type=discord.ActivityType.watching, name=f"{server_count} servers"))
        self.logger.info(f"Updated presence to 'Watching {server_count} servers'.")

    async def periodic_presence_update(self):
        await self.wait_until_ready()  # Wait until the bot is ready
        while not self.is_closed():
            await self.update_presence()  # Update the presence
            await asyncio.sleep(300)  # Wait for 5 minutes (300 seconds)

    async def connect_to_websocket(self):
        self.websocket_client = socketio.AsyncClient(logger=False, engineio_logger=False)
        admin_key = config.admin_key
        websocket_url = config.websocket_url
        @self.websocket_client.event
        async def connect():
            self.logger.info("Connected to websocket server")
            await self.websocket_client.emit("REGISTER", {
                "code": admin_key,
                "global_listener": True,
                "channel": "Global",
                "name": "Discord Bot Global Listener"
            })
        @self.websocket_client.event
        async def disconnect():
            self.logger.info("Disconnected from websocket server")
        @self.websocket_client.event
        async def SUCCESS(data):
            self.logger.info(f"Websocket registration successful: {data}")
        @self.websocket_client.event
        async def ERROR(data):
            self.logger.error(f"Websocket error: {data}")
        @self.websocket_client.event
        async def TWITCH_FOLLOW(data):
            await self.handle_twitch_event("FOLLOW", data)
        @self.websocket_client.event
        async def TWITCH_SUB(data):
            await self.handle_twitch_event("SUBSCRIPTION", data)
        @self.websocket_client.event
        async def TWITCH_CHEER(data):
            await self.handle_twitch_event("CHEER", data)
        @self.websocket_client.event
        async def TWITCH_RAID(data):
            await self.handle_twitch_event("RAID", data)
        @self.websocket_client.event
        async def STREAM_ONLINE(data):
            await self.handle_stream_event("ONLINE", data)
        @self.websocket_client.event
        async def STREAM_OFFLINE(data):
            await self.handle_stream_event("OFFLINE", data)
        await self.websocket_client.connect(websocket_url)

    async def handle_twitch_event(self, event_type, data):
        channel_code = data.get("channel_code", "unknown")
        mapping = self.channel_mapping.get_mapping(channel_code)
        if not mapping:
            self.logger.warning(f"No Discord mapping found for channel code: {channel_code}")
            return
        guild = self.get_guild(mapping["guild_id"])
        if not guild:
            self.logger.warning(f"Bot not in guild {mapping['guild_id']} for channel {channel_code}")
            return
        channel = guild.get_channel(mapping["channel_id"])
        if not channel:
            self.logger.warning(f"Channel {mapping['channel_id']} not found in guild {guild.name}")
            return
        message = self.format_twitch_message(event_type, data)
        if message:
            await channel.send(embed=message)
            self.logger.info(f"Sent {event_type} message to {guild.name}#{channel.name}")

    async def handle_stream_event(self, event_type, data):
        resolver = DiscordChannelResolver(self.logger)
        code = data.get("channel_code", "unknown")
        self.logger.info(f"Processing {event_type} event for channel_code: {code}")
        user_id = await resolver.get_user_id_from_api_key(code)
        if not user_id:
            self.logger.warning(f"No user_id found for channel_code: {code}")
            return
        discord_info = await resolver.get_discord_info_from_user_id(user_id)
        if not discord_info:
            return
        guild = self.get_guild(int(discord_info["guild_id"]))
        if not guild:
            self.logger.warning(f"Bot not in guild {discord_info['guild_id']} for user_id {user_id}")
            return
        channel = guild.get_channel(int(discord_info["live_channel_id"]))
        if not channel:
            self.logger.warning(f"Channel {discord_info['live_channel_id']} not found in guild {guild.name}")
            return
        # Set message and channel name based on event_type
        if event_type == "ONLINE":
            message = discord_info["online_text"] or "Stream is now LIVE!"
            channel_update = f"🟢 {message}"
        else:
            message = discord_info["offline_text"] or "Stream is now OFFLINE"
            channel_update = f"🔴 {message}"
        await channel.send(message)
        # Attempt to update the channel name if it is different
        if channel.name != channel_update:
            try:
                await channel.edit(name=channel_update, reason="Stream status update")
                self.logger.info(f"Updated channel name to '{channel_update}' for stream {event_type}")
            except Exception as e:
                self.logger.error(f"Failed to update channel name: {e}")
            self.logger.info(f"Set status to \"{event_type}\" for {guild.name}#{channel.name}")
        else:
            self.logger.info(f"Status not set to \"{event_type}\" for {guild.name}#{channel.name} - channel name already matches \"{channel_update}\"")

    def format_twitch_message(self, event_type, data):
        username = data.get("username", "Unknown User")
        embed = None
        if event_type == "FOLLOW":
            embed = discord.Embed(
                title="💙 New Follower!",
                description=f"**{username}** just followed the stream!",
                color=discord.Color.blue()
            )
        elif event_type == "SUBSCRIPTION":
            months = data.get("months", 1)
            tier = data.get("tier")
            message_text = data.get("message", "")
            desc = f"**{username}** just subscribed"
            if months > 1:
                desc += f" for {months} months"
            desc += f" (Tier {tier})!"
            embed = discord.Embed(
                title="⭐ New Subscription!",
                description=desc,
                color=discord.Color.gold()
            )
            if message_text:
                embed.add_field(name="Message", value=message_text, inline=False)
        elif event_type == "CHEER":
            bits = data.get("bits", 0)
            message_text = data.get("message", "")
            embed = discord.Embed(
                title="💎 Cheer!",
                description=f"**{username}** cheered {bits} bits!",
                color=discord.Color.purple()
            )
            if message_text:
                embed.add_field(name="Message", value=message_text, inline=False)
        elif event_type == "RAID":
            viewers = data.get("viewers", 0)
            embed = discord.Embed(
                title="🚀 Raid!",
                description=f"**{username}** raided with {viewers} viewers!",
                color=discord.Color.green()
            )
        return embed

# QuoteCog class for fetching and sending public quotes
class QuoteCog(commands.Cog, name='Quote'):
    def __init__(self, bot: BotOfTheSpecter, api_token: str, logger=None):
        self.bot = bot
        self.api_token = api_token
        self.logger = logger or logging.getLogger(self.__class__.__name__)
        self.typing_speed = config.typing_speed
        # Register the slash command
        self.bot.tree.add_command(
            app_commands.Command(
                name="quote",
                description="Get a random quote",
                callback=self.slash_quote,
            )
        )

    @commands.command(name="quote")
    async def get_quote(self, ctx):
        await self.fetch_and_send_quote(ctx)

    async def slash_quote(self, interaction: discord.Interaction):
        await self.fetch_and_send_quote(interaction)

    async def fetch_and_send_quote(self, ctx_or_interaction):
        if isinstance(ctx_or_interaction, commands.Context):
            ctx = ctx_or_interaction
        else:
            ctx = await commands.Context.from_interaction(ctx_or_interaction)
        url = f"{config.api_base_url}/quotes?api_key={self.api_token}"
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

# Ticket management cog
class TicketCog(commands.Cog, name='Tickets'):
    def __init__(self, bot: commands.Bot, logger=None):
        self.bot = bot
        self.logger = logger or logging.getLogger(self.__class__.__name__)
        self.pool = None

    async def init_ticket_database(self):
        if self.pool is None:
            self.pool = await aiomysql.create_pool(
                host=config.sql_host,
                user=config.sql_user,
                password=config.sql_password,
                db='tickets',
                autocommit=True
            )

    async def get_settings(self, guild_id: int):
        if not self.pool:
            await self.init_ticket_database()
        async with self.pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute(
                    "SELECT * FROM ticket_settings WHERE guild_id = %s",
                    (guild_id,)
                )
                return await cur.fetchone()

    async def create_ticket_channel(self, guild_id: int, user_id: int, ticket_id: int):
        settings = await self.get_settings(guild_id)
        if not settings:
            raise ValueError("Ticket system not set up")
        guild = self.bot.get_guild(guild_id)
        category = guild.get_channel(settings['category_id'])
        user = guild.get_member(user_id)
        if user is None:
            user = await guild.fetch_member(user_id)
        support_role = guild.get_role(settings['support_role_id']) if settings.get('support_role_id') else None
        # Create the ticket channel
        channel = await guild.create_text_channel(
            name=f"ticket-{ticket_id}",
            category=category,
            topic=f"Support Ticket #{ticket_id} | User: {user.name}"
        )
        # Set permissions
        await channel.set_permissions(guild.default_role, read_messages=False)
        await channel.set_permissions(user, read_messages=True, send_messages=True)
        if support_role:
            await channel.set_permissions(support_role, read_messages=True, send_messages=True)
        # Welcome message for the user
        await channel.send(f"Welcome to your support ticket channel, {user.mention}!")
        # Create an embed with instructions
        embed = discord.Embed(
            title="Instructions",
            description=(
                "Please provide the following information:" + os.linesep +
                "1. A detailed description of your issue" + os.linesep +
                "2. What you've tried so far (if applicable)" + os.linesep +
                "3. Any relevant screenshots or files" + os.linesep + os.linesep +
                "Our support team will assist you as soon as possible." + os.linesep +
                "Please be patient and remain respectful throughout the process." + os.linesep + os.linesep +
                "If you wish to close the ticket at any time, use `!ticket close` to notify the support team."
            ),
            color=config.bot_color
        )
        await channel.send(embed=embed)  # Send the embed message to the channel
        # Notify the support team about the new ticket
        if support_role:
            await channel.send(f"{support_role.mention} A new support ticket has been created!")
        return channel

    async def create_ticket(self, guild_id: int, user_id: int, username: str) -> int:
        settings = await self.get_settings(guild_id)
        if not settings or not settings['enabled']:
            raise ValueError("Ticket system is not set up in this server")
        if not self.pool:
            await self.init_ticket_database()
        async with self.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute("INSERT INTO tickets (guild_id, user_id, username, issue) VALUES (%s, %s, %s, %s)",
                    (guild_id, user_id, username, "Awaiting user's issue description"))
                ticket_id = cur.lastrowid
                await cur.execute("INSERT INTO ticket_history (ticket_id, user_id, username, action, details) VALUES (%s, %s, %s, %s, %s)",
                    (ticket_id, user_id, username, "created", "Ticket channel created"))
                return ticket_id

    async def close_ticket(self, ticket_id: int, channel_id: int, closer_id: int, closer_name: str, reason: str = "No reason provided", guild_id: int = None):
        if not self.pool:
            await self.init_ticket_database()
        channel = self.bot.get_channel(channel_id)
        if not channel:
            raise ValueError("Channel not found")
        async with self.pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT user_id FROM tickets WHERE ticket_id = %s AND guild_id = %s", (ticket_id, guild_id))
                ticket_data = await cur.fetchone()
                if not ticket_data:
                    raise ValueError("Ticket not found in database")
                # Update ticket status and store channel_id
                await cur.execute("UPDATE tickets SET status = 'closed', closed_at = NOW(), channel_id = %s WHERE ticket_id = %s AND guild_id = %s",(channel_id, ticket_id, guild_id))
                # Log the closure in history with the reason
                await cur.execute("INSERT INTO ticket_history (ticket_id, user_id, username, action, details) VALUES (%s, %s, %s, %s, %s)",
                    (ticket_id, closer_id, closer_name, "closed", reason))
        if ticket_data:
            # Try to send DM to ticket creator with the reason
            try:
                ticket_creator = channel.guild.get_member(ticket_data['user_id'])
                if ticket_creator:
                    settings = await self.get_settings(channel.guild.id)
                    dm_embed = discord.Embed(
                        title="Support Ticket Closed",
                        description=(
                            f"Your support ticket ({ticket_id}) has been closed by the support team. "
                            f"{f'Reason for closure: {reason}' if reason != 'No reason provided' else ' '}"
                            f"If you need further assistance or if this ticket was closed by mistake, "
                            f"please return to <#{settings['info_channel_id']}> and create a new ticket "
                            f"using `!ticket create`."
                        ),
                        color=config.bot_color
                    )
                    await ticket_creator.send(embed=dm_embed)
                    self.logger.info(f"Sent closure DM to user {ticket_creator.name} for ticket #{ticket_id} with reason: {reason if reason != 'No reason provided' else 'No reason provided'}")
            except discord.Forbidden:
                self.logger.warning(f"Could not send DM to user {ticket_data['user_id']} for ticket #{ticket_id}")
            except Exception as e:
                self.logger.error(f"Error sending closure DM: {e}")
            # Wait 10 seconds before proceeding with closure
            await asyncio.sleep(10)
            try:
                # Get or create the Closed Tickets category
                closed_category = discord.utils.get(channel.guild.categories, name="Closed Tickets")
                if not closed_category:
                    closed_category = await channel.guild.create_category(
                        name="Closed Tickets",
                        reason="Ticket System Archive"
                    )
                    # Set permissions for Closed Tickets category
                    await closed_category.set_permissions(channel.guild.default_role, read_messages=False)
                    # Give owner access if they exist in settings
                    if settings.get('owner_id'):
                        owner = channel.guild.get_member(settings['owner_id'])
                        if owner:
                            await closed_category.set_permissions(owner, read_messages=True, send_messages=False)
                # Remove ticket creator's access
                if ticket_creator:
                    await channel.set_permissions(ticket_creator, overwrite=discord.PermissionOverwrite())
                # Move channel to Closed Tickets category
                await channel.edit(
                    category=closed_category,
                    sync_permissions=False,  # Don't sync with category permissions
                    locked=True  # Lock the channel
                )
                # Update channel topic to indicate it's closed
                new_topic = f"{channel.topic} [CLOSED]" if channel.topic else "[CLOSED]"
                await channel.edit(topic=new_topic)
                # Replace plain text closure message with an embed
                embed_closed = discord.Embed(
                    title="Ticket Closed",
                    description="This ticket is now closed. Further replies in this chat are disabled.",
                    color=discord.Color.red()
                )
                await channel.send(embed=embed_closed)
                self.logger.info(f"Ticket #{ticket_id} closed and archived successfully")
            except discord.Forbidden:
                self.logger.error(f"Missing permissions to modify channel for ticket #{ticket_id}")
                raise
            except Exception as e:
                self.logger.error(f"Error archiving ticket #{ticket_id}: {e}")
                raise

    async def get_ticket(self, ticket_id: int, guild_id: int):
        if not self.pool:
            await self.init_ticket_database()
        async with self.pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT * FROM tickets WHERE ticket_id = %s AND guild_id = %s", (ticket_id, guild_id))
                return await cur.fetchone()

    @commands.command(name="ticket")
    async def ticket_command(self, ctx, action: str = None, *, reason: str = None):
        if action is None:
            await ctx.send("Please specify an action: `create` to create a ticket or `close` to close your ticket.", delete_after=10)
            return
        if action.lower() == "create":
            try:
                # Remove the command message for a clear channel
                await ctx.message.delete()
                ticket_id = await self.create_ticket(ctx.guild.id, ctx.author.id, str(ctx.author))
                channel = await self.create_ticket_channel(ctx.guild.id, ctx.author.id, ticket_id)
                await ctx.send(
                    f"✅ Your ticket has been created! Please check {channel.mention} to provide your issue details.",
                    delete_after=10
                )
                self.logger.info(f"Ticket #{ticket_id} created by {ctx.author} with channel {channel.name}")
            except ValueError as e:
                self.logger.error(f"Error: {str(e)}")
                await ctx.send(
                    f"There was an error in trying to create your support ticket, the support team has been notified about this issue.",
                    delete_after=10
                )
            except Exception as e:
                self.logger.error(f"Error creating ticket: {e}")
                await ctx.send(
                    "An error occurred while creating your ticket, the support team has been notified about this issue.",
                    delete_after=10
                )
        elif action.lower() == "close":
            # Check if the command is used in a ticket channel
            if not ctx.channel.name.startswith("ticket-"):
                embed = discord.Embed(
                    title="Ticket Closure Error",
                    description="This command can only be used in a ticket channel.",
                    color=discord.Color.yellow()
                )
                await ctx.send(embed=embed, delete_after=10)
                return
            try:
                # Fetch ticket id from the channel name
                ticket_id = int(ctx.channel.name.split("-")[1])
                ticket = await self.get_ticket(ticket_id, ctx.guild.id)
                if not ticket:
                    embed = discord.Embed(
                        title="Ticket Closure Error",
                        description="It seems there is an issue: you're in a ticket channel, but I can't find the associated ticket ID number for this channel.",
                        color=discord.Color.yellow()
                    )
                    await ctx.send(embed=embed)
                    return
                settings = await self.get_settings(ctx.guild.id)
                support_role = None
                if settings and settings.get('support_role_id'):
                    support_role = ctx.guild.get_role(settings['support_role_id'])
                    if support_role and support_role not in ctx.author.roles:
                        # Send closure message in channel
                        embed = discord.Embed(
                            title="Ticket Closure Notice",
                            description=(
                                "Only the support team can close tickets." + os.linesep +
                                "If you need further assistance, please provide more details in this ticket channel" + os.linesep +
                                "before a support team member closes this ticket for you." +
                                os.linesep + os.linesep +
                                "The support team has been notified that you wish to close this ticket." + os.linesep +
                                "When we close tickets, this ticket will be marked as closed and archived." + os.linesep + os.linesep +
                                "If you need further assistance in the future, please create a new ticket."
                            ),
                            color=discord.Color.yellow()
                        )
                        await ctx.send(embed=embed)
                        # Notify support team with a plain text message
                        await ctx.channel.send(f"{support_role.mention} requested ticket closure.")
                        return
                await self.close_ticket(ticket_id, ctx.channel.id, ctx.author.id, str(ctx.author), reason, ctx.guild.id)
                self.logger.info(f"Ticket #{ticket_id} closed by {ctx.author} with reason: {reason}")
            except Exception as e:
                self.logger.error(f"Error closing ticket: {e}")
                embed = discord.Embed(
                    title="Ticket Closure Error",
                    description="An error occurred while closing the ticket.",
                    color=discord.Color.yellow()
                )
                await ctx.send(embed=embed)
        elif action.lower() == "issue":
            # Update ticket issue description logic:
            if not ctx.channel.name.startswith("ticket-"):
                embed = discord.Embed(
                    title="Ticket Update Error",
                    description="This command can only be used in a ticket channel.",
                    color=discord.Color.yellow()
                )
                await ctx.send(embed=embed, delete_after=10)
                return
            if not reason:
                embed = discord.Embed(
                    title="Ticket Update Error",
                    description="Please provide the new issue description.",
                    color=discord.Color.yellow()
                )
                await ctx.send(embed=embed, delete_after=10)
                return
            try:
                ticket_id = int(ctx.channel.name.split("-")[1])
                if not self.pool:
                    await self.init_ticket_database()
                async with self.pool.acquire() as conn:
                    async with conn.cursor(aiomysql.DictCursor) as cur:
                        # Check if the channel_id in the database is correct
                        await cur.execute("SELECT channel_id FROM tickets WHERE ticket_id = %s", (ticket_id,))
                        ticket = await cur.fetchone()
                        correct_channel = ctx.channel.id
                        if ticket:
                            current_channel = ticket.get("channel_id")
                            if current_channel is None or current_channel != correct_channel:
                                # Update both issue and channel_id
                                await cur.execute(
                                    "UPDATE tickets SET issue = %s, channel_id = %s WHERE ticket_id = %s",
                                    (reason, correct_channel, ticket_id)
                                )
                                self.logger.info(f"Ticket #{ticket_id} channel_id updated from {current_channel} to {correct_channel}")
                            else:
                                # Only update the issue as channel_id is correct
                                await cur.execute(
                                    "UPDATE tickets SET issue = %s WHERE ticket_id = %s",
                                    (reason, ticket_id)
                                )
                                self.logger.info(f"Ticket #{ticket_id} issue updated with correct channel_id {correct_channel}")
                        else:
                            embed = discord.Embed(
                                title="Ticket Update Error",
                                description="Ticket not found.",
                                color=discord.Color.yellow()
                            )
                            await ctx.send(embed=embed)
                            return
                embed = discord.Embed(
                    title="Ticket Updated",
                    description=f"✅ Ticket #{ticket_id} issue updated.",
                    color=discord.Color.yellow()
                )
                await ctx.send(embed=embed)
                self.logger.info(f"Ticket #{ticket_id} issue updated by {ctx.author}")
            except Exception as e:
                self.logger.error(f"Error updating ticket issue: {e}")
                embed = discord.Embed(
                    title="Ticket Update Error",
                    description="An error occurred while updating the ticket issue.",
                    color=discord.Color.yellow()
                )
                await ctx.send(embed=embed)
        else:
            await ctx.send("Invalid actions. Use `!ticket create` to create a ticket, `!ticket close` to close your ticket, or `!ticket issue` to update your ticket description.")

    @commands.command(name="setuptickets")
    @commands.has_permissions(administrator=True)
    async def setup_tickets(self, ctx):
        if not self.pool:
            await self.init_ticket_database()
        def check(message):
            return message.author == ctx.author and message.channel == ctx.channel
        try:
            setup_embed = discord.Embed(
                title="🎫 Ticket System Setup",
                description="Let's set up your ticket system! I'll ask you a few questions.",
                color=config.bot_color
            )
            await ctx.send(embed=setup_embed)
            role_embed = discord.Embed(
                title="Support Role Setup",
                description=(
                    "Please provide the **Role ID** for your support team role." + os.linesep + os.linesep +
                    "**How to get a Role ID:**" + os.linesep +
                    "1. Go to Server Settings → Roles" + os.linesep +
                    "2. Right-click on the role you want" + os.linesep +
                    "3. Select 'Copy ID'" + os.linesep +
                    "4. Paste the ID number here" + os.linesep + os.linesep +
                    "**Example ID format:** `123456789012345678` (this is just an example)" + os.linesep + os.linesep +
                    "**Or type `skip` to create a new support role automatically.**"
                ),
                color=discord.Color.blue()
            )
            await ctx.send(embed=role_embed)
            support_role = None
            try:
                role_response = await self.bot.wait_for('message', check=check, timeout=60.0)
                if role_response.content.lower() == 'skip':
                    # Create new support role and assign to the user
                    support_role = await ctx.guild.create_role(
                        name="Support Team",
                        color=discord.Color.blue(),
                        permissions=discord.Permissions(
                            manage_messages=True,
                            read_message_history=True,
                            send_messages=True,
                            read_messages=True,
                            view_channel=True
                        ),
                        reason="Ticket System - Support Role Creation"
                    )
                    # Assign the role to the user setting up the system
                    await ctx.author.add_roles(support_role, reason="Ticket System Setup - Auto-assigned support role")
                    success_embed = discord.Embed(
                        title="✅ Support Role Created",
                        description=f"Created new support role: {support_role.mention}" + os.linesep + "You have been assigned this role automatically.",
                        color=discord.Color.green()
                    )
                    await ctx.send(embed=success_embed)
                    self.logger.info(f"Created support role '{support_role.name}' and assigned to {ctx.author} in {ctx.guild.name}")
                else:
                    try:
                        role_id = int(role_response.content.strip())
                        support_role = ctx.guild.get_role(role_id)
                        if not support_role:
                            await ctx.send("❌ Role not found! Creating a new support role instead...")
                            support_role = await ctx.guild.create_role(
                                name="Support Team",
                                color=discord.Color.blue(),
                                permissions=discord.Permissions(
                                    manage_messages=True,
                                    read_message_history=True,
                                    send_messages=True,
                                    read_messages=True,
                                    view_channel=True
                                ),
                                reason="Ticket System - Support Role Creation (Invalid ID provided)"
                            )
                            await ctx.author.add_roles(support_role, reason="Ticket System Setup - Auto-assigned support role")
                        else:
                            found_embed = discord.Embed(
                                title="✅ Support Role Found",
                                description=f"Using existing role: {support_role.mention}",
                                color=discord.Color.green()
                            )
                            await ctx.send(embed=found_embed)
                    except ValueError:
                        await ctx.send("❌ Invalid Role ID! Creating a new support role instead...")
                        support_role = await ctx.guild.create_role(
                            name="Support Team",
                            color=discord.Color.blue(),
                            permissions=discord.Permissions(
                                manage_messages=True,
                                read_message_history=True,
                                send_messages=True,
                                read_messages=True,
                                view_channel=True
                            ),
                            reason="Ticket System - Support Role Creation (Invalid ID format)"
                        )
                        await ctx.author.add_roles(support_role, reason="Ticket System Setup - Auto-assigned support role")
            except asyncio.TimeoutError:
                await ctx.send("❌ Setup timed out! Creating a new support role automatically...")
                support_role = await ctx.guild.create_role(
                    name="Support Team",
                    color=discord.Color.blue(),
                    permissions=discord.Permissions(
                        manage_messages=True,
                        read_message_history=True,
                        send_messages=True,
                        read_messages=True,
                        view_channel=True
                    ),
                    reason="Ticket System - Support Role Creation (Timeout)"
                )
                await ctx.author.add_roles(support_role, reason="Ticket System Setup - Auto-assigned support role")
            mod_embed = discord.Embed(
                title="Mod Channel Setup",
                description=(
                    "Please provide the **Channel ID** or mention a channel for ticket system management." + os.linesep + os.linesep +
                    "**This channel will be used for:**" + os.linesep +
                    "• Editing ticket system settings" + os.linesep +
                    "• Receiving update notifications" + os.linesep +
                    "• Managing ticket configurations" + os.linesep +
                    "• Administrative alerts and logs" + os.linesep + os.linesep +
                    "**How to provide channel:**" + os.linesep +
                    "• Mention the channel: #mod-logs" + os.linesep +
                    "• Or provide Channel ID: `123456789012345678` (example ID format)" + os.linesep + os.linesep +
                    "**Or type `skip` to skip this step.**"
                ),
                color=discord.Color.blue()
            )
            await ctx.send(embed=mod_embed)
            mod_channel = None
            try:
                mod_response = await self.bot.wait_for('message', check=check, timeout=60.0)
                if mod_response.content.lower() != 'skip':
                    # Try to extract channel from mention or ID
                    if mod_response.channel_mentions:
                        mod_channel = mod_response.channel_mentions[0]
                        channel_embed = discord.Embed(
                            title="✅ Mod Channel Configured",
                            description=f"Management channel set: {mod_channel.mention}" + os.linesep + os.linesep + "This channel will receive ticket system updates and allow settings management.",
                            color=discord.Color.green()
                        )
                        await ctx.send(embed=channel_embed)
                    else:
                        try:
                            channel_id = int(mod_response.content.strip())
                            mod_channel = ctx.guild.get_channel(channel_id)
                            if mod_channel:
                                channel_embed = discord.Embed(
                                    title="✅ Mod Channel Configured",
                                    description=f"Management channel set: {mod_channel.mention}" + os.linesep + os.linesep + "This channel will receive ticket system updates and allow settings management.",
                                    color=discord.Color.green()
                                )
                                await ctx.send(embed=channel_embed)
                            else:
                                await ctx.send("⚠️ Channel not found, skipping mod channel setup.")
                        except ValueError:
                            await ctx.send("⚠️ Invalid channel format, skipping mod channel setup.")
                else:
                    await ctx.send("⚠️ Skipping mod channel setup.")
            except asyncio.TimeoutError:
                await ctx.send("⚠️ Timed out, skipping mod channel setup.")
            # Create the category if it doesn't exist
            category = discord.utils.get(ctx.guild.categories, name="Open Tickets")
            if not category:
                category = await ctx.guild.create_category(
                    name="Open Tickets",
                    reason="Ticket System Setup"
                )
                self.logger.info(f"Created 'Open Tickets' category in {ctx.guild.name}")
            # Create info channel if it doesn't exist
            info_channel = discord.utils.get(category.channels, name="ticket-info")
            if not info_channel:
                info_channel = await ctx.guild.create_text_channel(
                    name="ticket-info",
                    category=category,
                    topic="How to create support tickets",
                    reason="Ticket System Setup"
                )
                self.logger.info(f"Created ticket-info channel in {ctx.guild.name}")
            else:
                # If the channel already exists, delete existing messages
                await info_channel.purge()  # This will delete all messages in the channel
            # Save settings to database
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cur:
                    await cur.execute("""
                        INSERT INTO ticket_settings 
                        (guild_id, owner_id, info_channel_id, category_id, support_role_id, mod_channel_id, enabled) 
                        VALUES (%s, %s, %s, %s, %s, %s, TRUE)
                        ON DUPLICATE KEY UPDATE 
                        owner_id = VALUES(owner_id),
                        info_channel_id = VALUES(info_channel_id),
                        category_id = VALUES(category_id),
                        support_role_id = VALUES(support_role_id),
                        mod_channel_id = VALUES(mod_channel_id),
                        enabled = TRUE,
                        updated_at = CURRENT_TIMESTAMP
                    """, (ctx.guild.id, ctx.author.id, info_channel.id, category.id, 
                          support_role.id if support_role else None, 
                          mod_channel.id if mod_channel else None))
            # Set channel permissions
            await info_channel.set_permissions(
                ctx.guild.default_role,  # or interaction.guild.default_role for slash command
                read_messages=True,      # Allow everyone to see the channel
                send_messages=True,      # Allow sending messages (for commands)
                add_reactions=False,     # Prevent reactions
                embed_links=False,       # Prevent embeds
                attach_files=False,      # Prevent file attachments
                use_application_commands=True  # Allow slash commands
            )
            # Set up channel slowmode to prevent spam
            await info_channel.edit(slowmode_delay=5)  # 5 seconds between messages
            # Set proper permissions for the Open Tickets category
            await category.set_permissions(
                ctx.guild.default_role,  # or interaction.guild.default_role for slash command
                read_messages=False,     # Hide all ticket channels by default
                send_messages=False
            )
            # Create the info message
            embed = discord.Embed(
                title=f"🎟️ {ctx.guild.name} Support System",
                description=(
                    "**Welcome to our support ticket system!**" + os.linesep + os.linesep +
                    "To create a new support ticket: `!ticket create`" + os.linesep + os.linesep +
                    "Once your ticket is created, you'll get access to a private channel where you can describe your issue in detail and communicate with our support team." +
                    os.linesep + os.linesep +
                    "Important Notes" + os.linesep +
                    "• Your ticket will be created in a private channel" + os.linesep +
                    "• Provide a clear description of your issue in the ticket channel" + os.linesep +
                    "• One ticket per issue" + os.linesep +
                    "• Be patient while waiting for a response" + os.linesep +
                    "• Keep all communication respectful" + os.linesep +
                    "• Only support team members can close tickets"
                ),
                color=config.bot_color
            )
            # Add a warning message about channel usage
            warning_embed = discord.Embed(
                title="⚠️ Channel Information",
                description=(
                    "This channel is for creating support tickets only." + os.linesep +
                    "Please use the command `!ticket create` to open a ticket." + os.linesep +
                    "Regular messages will be automatically deleted."
                ),
                color=discord.Color.yellow()
            )
            # Send the new info message
            await info_channel.send(embed=embed)
            await info_channel.send(embed=warning_embed)
            # Create detailed setup confirmation message
            final_embed = discord.Embed(
                title="✅ Ticket System Setup Complete!",
                description=f"Your ticket system has been successfully configured!" + os.linesep + os.linesep + f"Please check {info_channel.mention} for user instructions.",
                color=discord.Color.green()
            )
            final_embed.add_field(
                name="📋 Configuration Summary",
                value=(
                    f"**Support Role:** {support_role.mention if support_role else 'None'}" + os.linesep +
                    f"**Management Channel:** {mod_channel.mention + ' (for settings & updates)' if mod_channel else 'None'}" + os.linesep +
                    f"**Ticket Category:** {category.mention}" + os.linesep +
                    f"**Info Channel:** {info_channel.mention}"
                ),
                inline=False
            )
            if support_role and ctx.author in support_role.members:
                final_embed.add_field(
                    name="🎉 Role Assignment",
                    value=f"You have been assigned the {support_role.mention} role!",
                    inline=False
                )
            final_embed.set_footer(text="Users can now create tickets using !ticket create")
            
            await ctx.send(embed=final_embed)
            self.logger.info(f"Ticket system set up completed in {ctx.guild.name} by {ctx.author}")
        except Exception as e:
            self.logger.error(f"Error setting up ticket system: {e}")
            await ctx.send("❌ An error occurred while setting up the ticket system.")

    @commands.command(name="settings")
    @commands.has_permissions(manage_guild=True)
    async def check_settings(self, ctx):
        try:
            # Get ticket settings first to check if mod_channel_id is set
            settings = await self.get_settings(ctx.guild.id)
            if not settings:
                embed = discord.Embed(
                    title="❌ Settings Error",
                    description="No ticket system settings found. Please run `!setuptickets` first.",
                    color=discord.Color.red()
                )
                await ctx.send(embed=embed)
                return
            # Check if command is being used in the mod channel
            mod_channel_id = settings.get('mod_channel_id')
            if not mod_channel_id:
                embed = discord.Embed(
                    title="❌ No Mod Channel",
                    description="No moderator channel is configured. Please run `!setuptickets` to configure it.",
                    color=discord.Color.red()
                )
                await ctx.send(embed=embed)
                return
            try:
                mod_channel_id = int(mod_channel_id)
            except (ValueError, TypeError):
                embed = discord.Embed(
                    title="❌ Invalid Mod Channel",
                    description="The moderator channel ID is invalid. Please run `!setuptickets` again.",
                    color=discord.Color.red()
                )
                await ctx.send(embed=embed)
                return
            if ctx.channel.id != mod_channel_id:
                mod_channel = self.bot.get_channel(mod_channel_id)
                mod_channel_mention = mod_channel.mention if mod_channel else f"<#{mod_channel_id}>"
                embed = discord.Embed(
                    title="❌ Wrong Channel",
                    description=f"This command can only be used in the moderator channel: {mod_channel_mention}",
                    color=discord.Color.red()
                )
                await ctx.send(embed=embed, delete_after=10)
                return
            # Get Discord user settings using MySQLHelper directly since DiscordChannelResolver is defined later
            mysql_helper = MySQLHelper(self.logger)
            # Get discord user info from guild_id
            discord_info = await mysql_helper.fetchone(
                "SELECT user_id, live_channel_id, online_text, offline_text FROM discord_users WHERE guild_id = %s",
                (ctx.guild.id,), database_name='website', dict_cursor=True)
            # Get twitch_display_name if discord_info exists
            twitch_name = None
            if discord_info:
                user_row = await mysql_helper.fetchone(
                    "SELECT twitch_display_name FROM users WHERE id = %s", 
                    (discord_info['user_id'],), database_name='website', dict_cursor=True)
                if user_row:
                    twitch_name = user_row['twitch_display_name']
            # Create settings embed
            embed = discord.Embed(
                title="⚙️ Discord Bot Settings",
                description=f"Current settings for **{ctx.guild.name}**",
                color=config.bot_color
            )
            # Ticket System Settings
            embed.add_field(
                name="🎫 Ticket System",
                value=(
                    f"**Status:** {'✅ Enabled' if settings.get('enabled') else '❌ Disabled'}\n"
                    f"**Category:** <#{settings.get('category_id')}>\n"
                    f"**Info Channel:** <#{settings.get('info_channel_id')}>\n"
                    f"**Support Role:** <@&{settings.get('support_role_id')}>\n"
                    f"**Mod Channel:** <#{settings.get('mod_channel_id')}>"
                ),
                inline=False
            )
            # Discord Stream Settings
            if discord_info:
                live_channel = f"<#{discord_info.get('live_channel_id')}>" if discord_info.get('live_channel_id') else "Not set"
                online_text = discord_info.get('online_text') or "Stream is now LIVE!"
                offline_text = discord_info.get('offline_text') or "Stream is now OFFLINE"
                twitch_display = twitch_name or "Not linked"
                embed.add_field(
                    name="📺 Stream Notifications",
                    value=(
                        f"**Twitch Channel:** {twitch_display}\n"
                        f"**Live Channel:** {live_channel}\n"
                        f"**Online Message:** {online_text}\n"
                        f"**Offline Message:** {offline_text}"
                    ),
                    inline=False
                )
            else:
                embed.add_field(
                    name="📺 Stream Notifications",
                    value="❌ No stream notification settings configured",
                    inline=False
                )
            # Bot Information
            embed.add_field(
                name="🤖 Bot Information",
                value=(
                    f"**Version:** {config.bot_version}\n"
                    f"**Servers:** {len(self.bot.guilds)}\n"
                    f"**Voice Connected:** {'✅ Yes' if ctx.guild.voice_client else '❌ No'}"
                ),
                inline=False
            )
            embed.set_footer(text=f"Requested by {ctx.author.display_name}", icon_url=ctx.author.avatar.url if ctx.author.avatar else None)
            embed.timestamp = datetime.now(timezone.utc)
            await ctx.send(embed=embed)
        except Exception as e:
            self.logger.error(f"Error in settings command: {e}")
            embed = discord.Embed(
                title="❌ Error",
                description="An error occurred while retrieving settings.",
                color=discord.Color.red()
            )
            await ctx.send(embed=embed)

    @commands.Cog.listener()
    async def on_message(self, message):
        # Ignore bot messages
        if message.author.bot:
            return
        try:
            # Check if this is a ticket-info channel
            settings = await self.get_settings(message.guild.id)
            if not settings:
                return
            if message.channel.id == settings['info_channel_id']:
                # Check if message is a ticket command
                is_ticket_command = message.content.startswith('!ticket')
                is_ticket_command_create = message.content.startswith('!ticket create')
                if not is_ticket_command:
                    # Delete non-ticket messages
                    await message.delete()
                    # Send a temporary warning message
                    warning = await message.channel.send(
                        f"{message.author.mention} This channel is for ticket commands only. "
                        "Please use `!ticket create` to open a ticket."
                    )
                    # Set a delay before deleting the warning message
                    await asyncio.sleep(10)  # Wait for 10 seconds
                    await warning.delete()  # Delete the warning message after the delay
                    self.logger.info(f"Deleted non-ticket message from {message.author} in ticket-info channel")
                elif not is_ticket_command_create:
                    # Delete the command message
                    await message.delete()
                    warning = await message.channel.send(
                        f"{message.author.mention} This channel is for ticket commands only. "
                        "Please use `!ticket create` to open a ticket."
                    )
                    # Set a delay before deleting the warning message
                    await asyncio.sleep(10)  # Wait for 10 seconds
                    await warning.delete()  # Delete the warning message after the delay
                    self.logger.info(f"Deleted ticket create command from {message.author} in ticket-info channel")
                return
        except Exception as e:
            self.logger.error(f"Error in ticket-info message watcher: {e}")
        # New auto-save for comments in ticket channels
        if hasattr(message.channel, "name") and message.channel.name.startswith("ticket-"):
            # Ignore commands
            if message.content.startswith("!ticket"):
                return
            try:
                parts = message.channel.name.split("-")
                if len(parts) < 2:
                    return
                ticket_id = int(parts[1])
            except Exception:
                return
            if not self.pool:
                await self.init_ticket_database()
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cur:
                    await cur.execute(
                        "INSERT INTO ticket_comments (guild_id, ticket_id, user_id, username, comment) VALUES (%s, %s, %s, %s, %s)",
                        (message.guild.id, ticket_id, message.author.id, str(message.author), message.content)
                    )
            self.logger.info(f"Auto-saved comment for ticket {ticket_id} from {message.author}")

# Music player class
class MusicPlayer:
    def __init__(self, bot, logger=None):
        self.bot = bot
        self.logger = logger
        self.queues = {}       # guild_id -> list of dicts with {'query', 'title', 'user', 'file_path'}
        self.is_playing = {}   # guild_id -> bool
        self.current_track = {}  # guild_id -> current track info
        self.volumes = {}      # Initialize volume settings per guild
        self.track_start = {}
        self.track_duration = {}
        cookies_path = config.cookies_path
        # yt-dlp configuration
        self.ytdl_format_options = {
            'format': 'bestaudio/best',
            'outtmpl': os.path.join(tempfile.gettempdir(), 'bot_music_cache', '%(id)s.%(ext)s'),
            'restrictfilenames': True,
            'noplaylist': True,
            'nocheckcertificate': True,
            'ignoreerrors': False,
            'logtostderr': False,
            'quiet': True,
            'no_warnings': True,
            'default_search': 'auto',
            'source_address': '0.0.0.0',
            'cookiefile': cookies_path,
            'extractaudio': True,
            'audioformat': 'mp3',
            'audioquality': '192K',
        }
        self.ffmpeg_options = {
            'before_options': '-reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5',
            'options': '-vn -filter:a "volume=0.5"'
        }
        # Create cache directory for pre-downloaded YouTube tracks if it doesn't exist
        self.download_dir = os.path.join(tempfile.gettempdir(), 'bot_music_cache')
        os.makedirs(self.download_dir, exist_ok=True)
        # Initialize the periodic cleanup task
        asyncio.create_task(self.periodic_cleanup())
        self.play_lock = asyncio.Lock()

    async def predownload_youtube(self, url):
        loop = asyncio.get_event_loop()
        info = None
        file_path = None
        # Try multiple format configurations in order of preference
        format_options = [
            # First try: Best audio with conversion to MP3
            {
                'format': 'bestaudio/best',
                'extractaudio': True,
                'audioformat': 'mp3',
                'audioquality': '192K',
            },
            # Second try: Best available format
            {
                'format': 'best[ext=m4a]/best[ext=mp3]/best',
            },
            # Third try: Any available format
            {
                'format': 'worst',
            }
        ]
        def get_info_only():
            try:
                self.logger.info(f"[YT-DLP] Getting info for: {url}")
                ydl_opts_info = self.ytdl_format_options.copy()
                ydl_opts_info['nodownload'] = True
                # Remove extraction options for info-only
                ydl_opts_info.pop('extractaudio', None)
                ydl_opts_info.pop('audioformat', None)
                ydl_opts_info.pop('audioquality', None)
                with yt_dlp.YoutubeDL(ydl_opts_info) as ydl:
                    return ydl.extract_info(url, download=False)
            except Exception as e:
                self.logger.error(f"[YT-DLP] Error getting info for {url}: {e}")
                return None
        # Get video info first
        try:
            info = await loop.run_in_executor(None, get_info_only)
            if info:
                # Check if file already exists based on video ID
                video_id = info.get('id', 'unknown')
                # Look for any existing file with this video ID
                for ext in ['mp3', 'webm', 'm4a', 'opus', 'mp4']:
                    expected_filename = os.path.join(self.download_dir, f"{video_id}.{ext}")
                    if os.path.exists(expected_filename):
                        self.logger.info(f"[YT-DLP] File already exists: {expected_filename}")
                        return expected_filename, info
        except Exception as e:
            self.logger.error(f"[YT-DLP] Error checking existing file: {e}")
        # Try downloading with different format options
        for i, format_override in enumerate(format_options):
            def run_yt():
                try:
                    self.logger.info(f"[YT-DLP] Downloading attempt {i+1}: {url}")
                    ydl_opts = self.ytdl_format_options.copy()
                    ydl_opts.update(format_override)
                    with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                        result = ydl.extract_info(url, download=True)
                        return result
                except Exception as e:
                    self.logger.error(f"[YT-DLP] Download attempt {i+1} failed for {url}: {e}")
                    return None
            try:
                info = await loop.run_in_executor(None, run_yt)
                if info:
                    file_path = None
                    # Try to get the file path from requested_downloads, fallback to _filename
                    if 'requested_downloads' in info and info['requested_downloads']:
                        file_path = info['requested_downloads'][0].get('filepath') or info['requested_downloads'][0].get('filename')
                    if not file_path:
                        file_path = info.get('_filename')
                    
                    # If we got a valid file path, we're done
                    if file_path and os.path.exists(file_path):
                        self.logger.info(f"[YT-DLP] Successfully downloaded: {file_path}")
                        break
                    else:
                        self.logger.warning(f"[YT-DLP] Download succeeded but file not found: {file_path}")
                        file_path = None
            except Exception as e:
                self.logger.error(f"[YT-DLP] Exception in download attempt {i+1}: {e}")
                continue
        if not file_path:
            self.logger.error(f"[YT-DLP] All download attempts failed for: {url}")
        return file_path, info

    async def add_to_queue(self, ctx, query):
        guild_id = ctx.guild.id
        user = ctx.author
        if guild_id not in self.queues:
            self.queues[guild_id] = []
            self.is_playing[guild_id] = False
            self.current_track[guild_id] = None
        file_path = None
        title = query
        is_youtube = query.startswith('http')
        # YouTube URL validation
        if is_youtube:
            valid_prefixes = [
                'https://www.youtube.com/',
                'https://youtube.com/',
                'https://youtu.be/'
            ]
            if not any(query.startswith(prefix) for prefix in valid_prefixes):
                embed = discord.Embed(
                    title="❌ Invalid Link",
                    description="Only YouTube links are supported. Please use a link starting with https://www.youtube.com, https://youtube.com, or https://youtu.be/",
                    color=discord.Color.red()
                )
                msg = await ctx.send(embed=embed)
                try:
                    await msg.delete(delay=7)
                except Exception:
                    pass
                return
            # Fetch YouTube info synchronously for title display
            file_path, info = await self.predownload_youtube(query)
            if info and 'title' in info:
                title = info['title']
            else:
                title = query
                # If download failed completely, show error
                if not file_path:
                    embed = discord.Embed(
                        title="❌ Download Failed",
                        description=f"Failed to download from YouTube: {query}",
                        color=discord.Color.red()
                    )
                    msg = await ctx.send(embed=embed)
                    try:
                        await msg.delete(delay=10)
                        await ctx.message.delete()
                    except Exception:
                        pass
                    return
        else:
            title = query if query.endswith('.mp3') else f'{query}.mp3'
        track_info = {
            'query': query,
            'title': title,
            'user': user.display_name,
            'is_youtube': is_youtube,
            'file_path': file_path
        }
        self.queues[guild_id].append(track_info)
        if is_youtube:
            embed = discord.Embed(
                title="🎵 Added to Queue",
                description=f"**{title}** (requested by {user.display_name})",
                color=discord.Color.green()
            )
            msg = await ctx.send(embed=embed)
            try:
                await msg.delete(delay=5)
                await ctx.message.delete()
            except Exception:
                pass
        else:
            try:
                await ctx.message.delete()
            except Exception:
                pass
        voice_client = ctx.guild.voice_client
        is_playing_flag = self.is_playing.get(guild_id, False)
        voice_is_playing = voice_client.is_playing() if voice_client else False
        current_track = self.current_track.get(guild_id)
        current_track_user = current_track.get('user', 'None') if current_track else 'None'
        self.logger.info(f"[QUEUE] Added '{title}' by {user.display_name} to queue for guild {guild_id}")
        self.logger.info(f"[QUEUE] is_playing flag: {is_playing_flag}, voice_client.is_playing(): {voice_is_playing}, current track user: {current_track_user}")
        if not is_playing_flag and not voice_is_playing:
            self.logger.info(f"[QUEUE] Nothing playing, starting _play_next for guild {guild_id}")
            await self._play_next(ctx)
        else:
            self.logger.info(f"[QUEUE] Music already playing for guild {guild_id}, song queued and will play after current track")

    # Keep the legacy play method for compatibility
    async def play(self, ctx, query):
        await self.add_to_queue(ctx, query)

    async def _play_next(self, ctx):
        async with self.play_lock:
            guild_id = ctx.guild.id
            vc = ctx.guild.voice_client
            self.logger.info(f"[PLAY_NEXT] Called for guild {guild_id}")
            # Ensure guild has proper entries in our dictionaries
            if guild_id not in self.queues:
                self.queues[guild_id] = []
            if guild_id not in self.is_playing:
                self.is_playing[guild_id] = False
            # No longer in voice, stop scheduling
            if not vc or not vc.is_connected():
                self.logger.info(f"[PLAY_NEXT] Not connected to voice for guild {guild_id}, stopping")
                self.is_playing[guild_id] = False
                return
            queue = self.queues.get(guild_id, [])
            if not queue:
                self.logger.info(f"[PLAY_NEXT] Queue empty for guild {guild_id}")
                # Reset flag so add_to_queue won't spin
                self.is_playing[guild_id] = False
                # Only play random CDN if not already playing one
                current_track = self.current_track.get(guild_id)
                if not (current_track and current_track.get('user') == 'CDN' and self.is_playing[guild_id]):
                    self.logger.info(f"[PLAY_NEXT] Starting random CDN music for guild {guild_id}")
                    await self.play_random_cdn_mp3(ctx)
                return
            self.logger.info(f"[PLAY_NEXT] Processing queue for guild {guild_id}, {len(queue)} songs remaining")
            self.is_playing[guild_id] = True
            track_info = queue.pop(0)
            self.current_track[guild_id] = track_info
            query = track_info['query']
            source = None
            # Robust file_path handling for YouTube
            if track_info['is_youtube']:
                # Check if file is already downloaded from add_to_queue
                file_path = track_info.get('file_path')
                if file_path and os.path.exists(file_path):
                    self.logger.info(f"[YT-DLP] Using pre-downloaded file: {file_path}")
                else:
                    # File not downloaded or doesn't exist, download now
                    self.logger.info(f"[YT-DLP] File not pre-downloaded, downloading now: {query}")
                    file_path, info = await self.predownload_youtube(query)
                    if info and 'title' in info:
                        track_info['title'] = info['title']
                    track_info['file_path'] = file_path
                if not file_path or not os.path.exists(file_path):
                    # Could not download, skip to next
                    self.logger.error(f"[YT-DLP] Could not download file for {query}, skipping.")
                    return await self._play_next(ctx)
                self.logger.info(f"[FFMPEG] Playing YouTube file: {file_path}")
                source = discord.FFmpegPCMAudio(file_path, options=self.ffmpeg_options.get('options'))
                # Try to get duration
                try:
                    self.logger.info(f"[FFMPEG] ffprobe for duration: {file_path}")
                    result = subprocess.run([
                        'ffprobe', '-v', 'error', '-show_entries', 'format=duration',
                        '-of', 'default=noprint_wrappers=1:nokey=1', file_path
                    ], stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
                    duration = int(float(result.stdout)) if result.stdout else None
                except Exception as e:
                    self.logger.error(f"[FFMPEG] Error getting duration: {e}")
                    duration = None
            else:
                path = os.path.join(config.music_directory, query if query.endswith('.mp3') else f'{query}.mp3')
                if not os.path.exists(path):
                    self.logger.error(f"[FFMPEG] CDN file not found: {path}. Skipping track.")
                    try:
                        await ctx.send(f"Song skipped, can't play '{query}'.")
                    except Exception:
                        pass
                    return await self._play_next(ctx)  # Skip to next track
                self.logger.info(f"[FFMPEG] Playing CDN file: {path}")
                source = discord.FFmpegPCMAudio(path, options=self.ffmpeg_options.get('options'))
                try:
                    self.logger.info(f"[FFMPEG] ffprobe for duration: {path}")
                    result = subprocess.run([
                        'ffprobe', '-v', 'error', '-show_entries', 'format=duration',
                        '-of', 'default=noprint_wrappers=1:nokey=1', path
                    ], stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
                    duration = int(float(result.stdout)) if result.stdout else None
                except Exception as e:
                    self.logger.error(f"[FFMPEG] Error getting duration: {e}")
                    duration = None
            self.track_duration[guild_id] = duration
            self.track_start[guild_id] = time.time()
            vc = ctx.voice_client
            source = discord.PCMVolumeTransformer(source, volume=self.volumes.get(guild_id, config.volume_default))
            def after_play(error):
                # Reset playing state when track finishes
                self.is_playing[guild_id] = False
                if error:
                    self.logger.error(f"[FFMPEG] Playback error: {error}")
                else:
                    self.logger.info(f"[FFMPEG] Track finished for guild {guild_id}")
                # Clean up the audio file immediately after playback (YouTube files only)
                if track_info['is_youtube'] and 'file_path' in track_info and track_info['file_path']:
                    # Only remove if in the expected download dir
                    if track_info['file_path'].startswith(self.download_dir):
                        self.logger.info(f"[FFMPEG] Cleaning up file: {track_info['file_path']}")
                        try:
                            os.remove(track_info['file_path'])
                        except Exception as e:
                            self.logger.error(f"[FFMPEG] Error cleaning up file: {e}")
                # Always call _play_next to ensure music continues
                coro = self._play_next(ctx)
                fut = asyncio.run_coroutine_threadsafe(coro, self.bot.loop)
                try:
                    fut.result()
                except Exception as e:
                    self.logger.error(f"[FFMPEG] Error in after_play: {e}")
            # Don't stop if this is being called while nothing should be playing
            if vc.is_playing():
                current_track = self.current_track.get(guild_id)
                if current_track:
                    current_user = current_track.get('user', 'Unknown')
                    current_title = current_track.get('title', 'Unknown')
                else:
                    current_user = 'Unknown'
                    current_title = 'Unknown'
                self.logger.info(f"[PLAY_NEXT] Stopping current track '{current_title}' by {current_user} to play '{track_info['title']}' by {track_info['user']}")
                vc.stop()
            else:
                self.logger.info(f"[PLAY_NEXT] Starting '{track_info['title']}' by {track_info['user']} (nothing was playing)")
            vc.play(source, after=after_play)

    async def skip(self, ctx):
        vc = ctx.voice_client
        if not vc:
            embed = discord.Embed(
                title="⚠️ Not Connected",
                description="Not connected to a voice channel.",
                color=discord.Color.orange()
            )
            msg = await ctx.send(embed=embed)
            try:
                await msg.delete(delay=5)
                await ctx.message.delete()
            except Exception:
                pass
            return
        vc.stop()
        embed = discord.Embed(
            title="⏭️ Skipped",
            description="Skipped current track.",
            color=discord.Color.blue()
        )
        msg = await ctx.send(embed=embed)
        try:
            await msg.delete(delay=5)
            await ctx.message.delete()
        except Exception:
            pass

    async def stop(self, ctx):
        vc = ctx.voice_client
        guild_id = ctx.guild.id
        if not vc:
            embed = discord.Embed(
                title="⚠️ Not Connected",
                description="Not connected to a voice channel.",
                color=discord.Color.orange()
            )
            msg = await ctx.send(embed=embed)
            try:
                await msg.delete(delay=5)
                await ctx.message.delete()
            except Exception:
                pass
            return
        vc.stop()
        self.queues[guild_id] = []
        self.current_track[guild_id] = None
        await vc.disconnect()
        embed = discord.Embed(
            title="⏹️ Stopped",
            description="Stopped playback and cleared queue.",
            color=discord.Color.red()
        )
        msg = await ctx.send(embed=embed)
        try:
            await msg.delete(delay=5)
            await ctx.message.delete()
        except Exception:
            pass

    async def get_queue(self, ctx):
        guild_id = ctx.guild.id
        queue = self.queues.get(guild_id, [])
        if not queue:
            embed = discord.Embed(
                title="🎵 Music Queue",
                description="The queue is empty.",
                color=discord.Color.blue()
            )
            msg = await ctx.send(embed=embed)
            try:
                await msg.delete(delay=10)
                await ctx.message.delete()
            except Exception:
                pass
            return
        queue_list = []
        max_display = 5
        for i, track in enumerate(queue[:max_display], 1):
            title = track['title'] if track['is_youtube'] else 'CDN Music'
            queue_list.append(f"{i}. {title} (by {track['user']})")
        current = self.current_track[guild_id]
        current_text = ""
        if current:
            current_title = current['title'] if current['is_youtube'] else 'CDN Music'
            current_text = f"**Now Playing:** {current_title}" + os.linesep + os.linesep
        desc = f"{current_text}**Up Next:**" + os.linesep + (os.linesep.join(queue_list))
        if len(queue) > max_display:
            desc += os.linesep + f"... and {len(queue) - max_display} more tracks"
        embed = discord.Embed(
            title="🎵 Music Queue",
            description=desc,
            color=config.bot_color
        )
        msg = await ctx.send(embed=embed)
        try:
            await msg.delete(delay=10)
            await ctx.message.delete()
        except Exception:
            pass

    async def now_playing(self, ctx):
        guild_id = ctx.guild.id
        track = self.current_track.get(guild_id)
        if not track:
            await self.play_random_cdn_mp3(ctx)
            return
        title = track['title']
        if not track.get('is_youtube') and title.lower().endswith('.mp3'):
            title = title[:-4]
        source_label = 'YouTube' if track.get('is_youtube') else 'CDN'
        requested_by = track.get('user', 'Unknown')
        duration = self.track_duration.get(guild_id)
        start = self.track_start.get(guild_id)
        desc = f"{title}"
        embed = discord.Embed(
            title="Now Playing",
            description=desc,
            color=discord.Color.purple()
        )
        embed.add_field(name="Source", value=source_label, inline=True)
        embed.add_field(name="Requested by", value=requested_by, inline=True)
        if duration and start:
            elapsed = int(time.time() - start)
            elapsed_str = str(datetime.timedelta(seconds=elapsed))
            duration_str = str(datetime.timedelta(seconds=int(duration)))
            embed.add_field(name="Progress", value=f"{elapsed_str} / {duration_str}", inline=True)
        msg = await ctx.send(embed=embed)
        try:
            await msg.delete(delay=5)
            await ctx.message.delete()
        except Exception:
            pass

    async def cleanup_cache(self):
        # Remove files not referenced in any queue or current_track
        referenced = set()
        for q in self.queues.values():
            for t in q:
                if t.get('file_path'):
                    referenced.add(t['file_path'])
        for t in self.current_track.values():
            if t and t.get('file_path'):
                referenced.add(t['file_path'])
        for fname in os.listdir(self.download_dir):
            fpath = os.path.join(self.download_dir, fname)
            if fpath not in referenced:
                try:
                    os.remove(fpath)
                    self.logger.info(f"Removed unused cached file: {fpath}")
                except Exception as e:
                    self.logger.error(f"Failed to remove {fpath}: {e}")

    async def periodic_cleanup(self):
        while True:
            await asyncio.sleep(900)  # every 15 minutes
            await self.cleanup_cache()

    async def play_random_cdn_mp3(self, ctx):
        music_dir = config.music_directory
        mp3_files = [f for f in os.listdir(music_dir) if f.lower().endswith('.mp3')]
        random_mp3 = random.choice(mp3_files)
        path = os.path.join(music_dir, random_mp3)
        vc = ctx.voice_client
        self.logger.info(f"[CDN] play_random_cdn_mp3 called for guild {ctx.guild.id}")
        if not vc or not vc.is_connected():
            embed = discord.Embed(
                title="Voice Channel Error",
                description="Not connected to a voice channel.",
                color=discord.Color.red()
            )
            await ctx.send(embed=embed)
            return
        if vc.is_playing():
            self.logger.info(f"[CDN] Voice client already playing for guild {ctx.guild.id}, not starting CDN music")
            return
        self.logger.info(f"[CDN] Starting random CDN mp3: {random_mp3} at {path}")
        source = discord.FFmpegPCMAudio(path, options=self.ffmpeg_options.get('options'))
        source = discord.PCMVolumeTransformer(source, volume=self.volumes.get(ctx.guild.id, config.volume_default))
        def after_play(error):
            # Reset playing state when track finishes
            self.is_playing[ctx.guild.id] = False
            if error:
                self.logger.error(f"[CDN] Playback error: {error}")
            else:
                self.logger.info(f"[CDN] CDN track '{random_mp3}' finished for guild {ctx.guild.id}")
            coro = self._play_next(ctx)
            fut = asyncio.run_coroutine_threadsafe(coro, self.bot.loop)
            try:
                fut.result()
            except Exception as e:
                self.logger.error(f"[CDN] Error in after_play: {e}")
        # Set playing state before starting playback to avoid race conditions
        self.is_playing[ctx.guild.id] = True
        self.current_track[ctx.guild.id] = {
            'query': random_mp3,
            'title': random_mp3,
            'user': 'CDN',
            'is_youtube': False,
            'file_path': path
        }
        vc.play(source, after=after_play)
        try:
            self.logger.info(f"[FFMPEG] ffprobe for duration: {path}")
            result = subprocess.run([
                'ffprobe', '-v', 'error', '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1', path
            ], stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
            duration = int(float(result.stdout)) if result.stdout else None
        except Exception as e:
            self.logger.error(f"[FFMPEG] Error getting duration: {e}")
            duration = None
        self.track_duration[ctx.guild.id] = duration
        self.track_start[ctx.guild.id] = time.time()

# VoiceCog class for managing voice connections
class VoiceCog(commands.Cog, name='Voice'):
    def __init__(self, bot: commands.Bot, logger=None):
        self.bot = bot
        self.logger = logger or logging.getLogger(self.__class__.__name__)
        self.voice_clients = {}  # Guild ID -> VoiceClient mapping
        self.music_player = MusicPlayer(bot, logger)
        self.logger.info("VoiceCog initialized successfully")
    async def cog_check(self, ctx):
        return ctx.guild is not None
    @commands.command(name="connect", aliases=["join", "summon"])
    async def connect_voice(self, ctx, *, channel: discord.VoiceChannel = None):
        self.logger.info(f"Connect command called by {ctx.author} in {ctx.guild.name}")
        await self._handle_connect(ctx, channel)
    @app_commands.command(name="connect", description="Connect the bot to a voice channel")
    @app_commands.describe(channel="The voice channel to connect to (optional - defaults to your current channel)")
    async def slash_connect_voice(self, interaction: discord.Interaction, channel: discord.VoiceChannel = None):
        ctx = await commands.Context.from_interaction(interaction)
        await self._handle_connect(ctx, channel)

    async def _handle_connect(self, ctx, channel: discord.VoiceChannel = None):
        self.logger.info(f"_handle_connect called by {ctx.author} in {ctx.guild.name}")
        try:
            # If no channel specified, try to get the user's current voice channel
            if channel is None:
                if ctx.author.voice is None:
                    embed = discord.Embed(
                        title="❌ Voice Connection Error",
                        description="You are not connected to a voice channel. Please join a voice channel or specify one.",
                        color=discord.Color.red()
                    )
                    msg = await ctx.send(embed=embed)
                    try:
                        await msg.delete(delay=5)
                        await ctx.message.delete(delay=5)
                    except Exception:
                        pass
                    self.logger.info(f"User {ctx.author} not in voice channel")
                    return
                channel = ctx.author.voice.channel
            # Check if bot has permissions to connect to the channel
            permissions = channel.permissions_for(ctx.guild.me)
            if not permissions.connect:
                embed = discord.Embed(
                    title="❌ Permission Error",
                    description=f"I don't have permission to connect to {channel.mention}.",
                    color=discord.Color.red()
                )
                msg = await ctx.send(embed=embed)
                try:
                    await msg.delete(delay=5)
                    await ctx.message.delete(delay=5)
                except Exception:
                    pass
                return
            if not permissions.speak:
                embed = discord.Embed(
                    title="⚠️ Limited Permissions",
                    description=f"I can connect to {channel.mention} but don't have permission to speak.",
                    color=discord.Color.yellow()
                )
                msg = await ctx.send(embed=embed)
                try:
                    await msg.delete(delay=5)
                    await ctx.message.delete(delay=5)
                except Exception:
                    pass
            # Check if bot is already connected to a voice channel in this guild
            if ctx.guild.id in self.voice_clients and self.voice_clients[ctx.guild.id].is_connected():
                current_channel = self.voice_clients[ctx.guild.id].channel
                if current_channel == channel:
                    embed = discord.Embed(
                        title="ℹ️ Already Connected",
                        description=f"I'm already connected to {channel.mention}.",
                        color=discord.Color.blue()
                    )
                    msg = await ctx.send(embed=embed)
                    try:
                        await msg.delete(delay=5)
                        await ctx.message.delete(delay=5)
                    except Exception:
                        pass
                    return
                else:
                    # Move to the new channel
                    await self.voice_clients[ctx.guild.id].move_to(channel)
                    embed = discord.Embed(
                        title="✅ Moved to Voice Channel",
                        description=f"Moved from {current_channel.mention} to {channel.mention}.",
                        color=discord.Color.green()
                    )
                    msg = await ctx.send(embed=embed)
                    try:
                        await msg.delete(delay=5)
                        await ctx.message.delete(delay=5)
                    except Exception:
                        pass
                    self.logger.info(f"Moved voice connection from {current_channel.name} to {channel.name} in {ctx.guild.name}")
                    return
            # Connect to the voice channel
            voice_client = await channel.connect()
            self.voice_clients[ctx.guild.id] = voice_client
            embed = discord.Embed(
                title="✅ Connected to Voice Channel",
                description=f"Successfully connected to {channel.mention}.",
                color=discord.Color.green()
            )
            response = await ctx.send(embed=embed)
            # remove the bot response and the command after 5 seconds to keep channel clean
            await response.delete(delay=5)
            await ctx.message.delete(delay=5)
            self.logger.info(f"Sent connect success message to {ctx.guild.name}")
            self.logger.info(f"Connected to voice channel {channel.name} in {ctx.guild.name}")
            # Play a random mp3 if nothing is queued or playing
            guild_id = ctx.guild.id
            queue_empty = not self.music_player.queues.get(guild_id)
            is_playing = self.music_player.is_playing.get(guild_id, False)
            if queue_empty and not is_playing:
                await self.music_player.play_random_cdn_mp3(ctx)
        except discord.ClientException as e:
            embed = discord.Embed(
                title="❌ Connection Error",
                description=f"Failed to connect to voice channel: {str(e)}",
                color=discord.Color.red()
            )
            response = await ctx.send(embed=embed)
            await response.delete(delay=5)
            await ctx.message.delete(delay=5)
            self.logger.error(f"Discord client error connecting to voice: {e}")
        except Exception as e:
            embed = discord.Embed(
                title="❌ Unexpected Error",
                description="An unexpected error occurred while connecting to the voice channel.",
                color=discord.Color.red()
            )
            response = await ctx.send(embed=embed)
            await response.delete(delay=5)
            await ctx.message.delete(delay=5)
            self.logger.error(f"Unexpected error connecting to voice: {e}")

    @commands.command(name="disconnect", aliases=["leave", "dc"])
    async def disconnect_voice(self, ctx):
        await self._handle_disconnect(ctx)

    @app_commands.command(name="disconnect", description="Disconnect the bot from the voice channel")
    async def slash_disconnect_voice(self, interaction: discord.Interaction):
        ctx = await commands.Context.from_interaction(interaction)
        await self._handle_disconnect(ctx)

    async def _handle_disconnect(self, ctx):
        try:
            if ctx.guild.id not in self.voice_clients:
                embed = discord.Embed(
                    title="ℹ️ Not Connected",
                    description="I'm not connected to any voice channel in this server.",
                    color=discord.Color.blue()
                )
                msg = await ctx.send(embed=embed)
                try:
                    await msg.delete(delay=5)
                    await ctx.message.delete(delay=5)
                except Exception:
                    pass
                return
            voice_client = self.voice_clients[ctx.guild.id]
            if not voice_client.is_connected():
                embed = discord.Embed(
                    title="ℹ️ Not Connected",
                    description="I'm not connected to any voice channel in this server.",
                    color=discord.Color.blue()
                )
                msg = await ctx.send(embed=embed)
                try:
                    await msg.delete(delay=5)
                    await ctx.message.delete(delay=5)
                except Exception:
                    pass
                del self.voice_clients[ctx.guild.id]
                return
            channel_name = voice_client.channel.name
            await voice_client.disconnect()
            del self.voice_clients[ctx.guild.id]
            embed = discord.Embed(
                title="✅ Disconnected",
                description=f"Successfully disconnected from {channel_name}.",
                color=discord.Color.green()
            )
            response = await ctx.send(embed=embed)
            await response.delete(delay=5)
            await ctx.message.delete(delay=5)
            self.logger.info(f"Disconnected from voice channel {channel_name} in {ctx.guild.name}")
        except Exception as e:
            embed = discord.Embed(
                title="❌ Disconnect Error",
                description="An error occurred while disconnecting from the voice channel.",
                color=discord.Color.red()
            )
            response = await ctx.send(embed=embed)
            await response.delete(delay=5)
            await ctx.message.delete(delay=5)
            self.logger.error(f"Error disconnecting from voice: {e}")

    @commands.command(name="voice_status", aliases=["vstatus"])
    async def voice_status(self, ctx):
        await self._handle_voice_status(ctx)

    @app_commands.command(name="voice_status", description="Check the bot's current voice connection status")
    async def slash_voice_status(self, interaction: discord.Interaction):
        ctx = await commands.Context.from_interaction(interaction)
        await self._handle_voice_status(ctx)

    async def _handle_voice_status(self, ctx):
        if ctx.guild.id in self.voice_clients and self.voice_clients[ctx.guild.id].is_connected():
            voice_client = self.voice_clients[ctx.guild.id]
            channel = voice_client.channel
            # Get channel member count
            member_count = len(channel.members)
            # Handle latency display
            latency = voice_client.latency
            if latency == float('inf') or latency > 1000:
                latency_display = "N/A"
            else:
                latency_display = f"{latency * 1000:.1f}ms"
            embed = discord.Embed(
                title="🔊 Voice Status",
                color=discord.Color.green()
            )
            embed.add_field(name="Connected To", value=channel.mention, inline=True)
            embed.add_field(name="Channel Members", value=str(member_count), inline=True)
            embed.add_field(name="Latency", value=latency_display, inline=True)
            msg = await ctx.send(embed=embed)
        else:
            embed = discord.Embed(
                title="🔇 Voice Status",
                description="Not connected to any voice channel in this server.",
                color=discord.Color.red()
            )
            msg = await ctx.send(embed=embed)
        try:
            await msg.delete(delay=5)
            await ctx.message.delete(delay=5)
        except Exception:
            pass

    @connect_voice.error
    async def connect_error(self, ctx, error):
        if isinstance(error, commands.ChannelNotFound):
            embed = discord.Embed(
                title="❌ Channel Not Found",
                description="Could not find the specified voice channel.",
                color=discord.Color.red()
            )
            await ctx.send(embed=embed)
        elif isinstance(error, commands.BotMissingPermissions):
            embed = discord.Embed(
                title="❌ Missing Permissions",
                description="I don't have the required permissions to connect to voice channels.",
                color=discord.Color.red()
            )
            await ctx.send(embed=embed)
        else:
            self.logger.error(f"Unhandled error in connect command: {error}")

    @commands.Cog.listener()
    async def on_voice_state_update(self, member, before, after):
        if member == self.bot.user:
            return
        # Check if the bot should auto-disconnect when alone
        for guild_id, voice_client in list(self.voice_clients.items()):
            if not voice_client.is_connected():
                continue
            channel = voice_client.channel
            # Count non-bot members in the channel
            human_members = [m for m in channel.members if not m.bot]
            if len(human_members) == 0:
                self.logger.info(f"Auto-disconnecting from {channel.name} in {channel.guild.name} - no human members")
                await voice_client.disconnect()
                del self.voice_clients[guild_id]

    def cog_unload(self):
        for voice_client in self.voice_clients.values():
            if voice_client.is_connected():
                asyncio.create_task(voice_client.disconnect())
        self.voice_clients.clear()

    # Music commands
    def _user_in_linked_voice_text_channel(self, ctx):
        if not ctx.author.voice or not ctx.author.voice.channel:
            return False
        voice_channel = ctx.author.voice.channel
        # Discord voice channels can have a linked text channel (voice_channel.guild.text_channels)
        linked_text_channel = getattr(voice_channel, 'linked_text_channel', None)
        # If using Discord's new voice chat text channel feature
        if linked_text_channel:
            return ctx.channel.id == linked_text_channel.id
        # Fallback: try to find a text channel with the same name as the voice channel
        for text_channel in ctx.guild.text_channels:
            if text_channel.name == voice_channel.name and ctx.channel.id == text_channel.id:
                return True
        # If no linked text channel, allow everywhere (legacy behavior)
        return True

    @commands.command(name="play")
    async def play_music(self, ctx, *, query: str):
        if not ctx.author.voice:
            embed = discord.Embed(
                title="❌ Not in Voice Channel",
                description="You need to be in a voice channel to use this command!",
                color=discord.Color.red()
            )
            msg = await ctx.send(embed=embed)
            try:
                await msg.delete(delay=5)
                await ctx.message.delete(delay=5)
            except Exception as e:
                self.logger.warning(f"Failed to delete user message on !play command: {e}")
            return
        if not self._user_in_linked_voice_text_channel(ctx):
            embed = discord.Embed(
                title="❌ Wrong Channel",
                description="Please use this command in the text chat channel linked to your voice channel.",
                color=discord.Color.red()
            )
            msg = await ctx.send(embed=embed)
            try:
                await msg.delete(delay=5)
                await ctx.message.delete(delay=5)
            except Exception as e:
                self.logger.warning(f"Failed to delete user message on !play command: {e}")
            return
        if not ctx.voice_client:
            try:
                await self._handle_connect(ctx, ctx.author.voice.channel)
            except Exception as e:
                await ctx.send(f"Failed to connect to voice channel: {str(e)}")
                return
        await self.music_player.add_to_queue(ctx, query)

    @app_commands.command(name="play", description="Play music from YouTube or add to queue")
    @app_commands.describe(query="The song name or YouTube URL to play")
    async def slash_play_music(self, interaction: discord.Interaction, query: str):
        await interaction.response.defer()
        if not interaction.user.voice:
            await interaction.followup.send("You need to be in a voice channel to use this command!")
            return
        if not interaction.guild.voice_client:
            try:
                voice_channel = interaction.user.voice.channel
                await voice_channel.connect()
            except Exception as e:
                await interaction.followup.send(f"Failed to connect to voice channel: {str(e)}")
                return
        class MockContext:
            def __init__(self, interaction):
                self.guild = interaction.guild
                self.author = interaction.user
                self.voice_client = interaction.guild.voice_client
                self.send = interaction.followup.send
        ctx = MockContext(interaction)
        await self.music_player.add_to_queue(ctx, query)

    @commands.command(name="skip", aliases=["s", "next"])
    async def skip_music(self, ctx):
        if not ctx.author.voice or not self._user_in_linked_voice_text_channel(ctx):
            embed = discord.Embed(
                title="❌ Wrong Channel",
                description="Please use this command in the text chat channel linked to your voice channel.",
                color=discord.Color.red()
            )
            msg = await ctx.send(embed=embed)
            try:
                await msg.delete(delay=5)
                await ctx.message.delete(delay=5)
            except Exception:
                pass
            return
        await self.music_player.skip(ctx)

    @app_commands.command(name="skip", description="Skip the current song")
    async def slash_skip_music(self, interaction: discord.Interaction):
        await interaction.response.defer()
        class MockContext:
            def __init__(self, interaction):
                self.guild = interaction.guild
                self.voice_client = interaction.guild.voice_client
                self.send = interaction.followup.send
        ctx = MockContext(interaction)
        await self.music_player.skip(ctx)

    @commands.command(name="stop", aliases=["pause"])
    async def stop_music(self, ctx):
        if not ctx.author.voice or not self._user_in_linked_voice_text_channel(ctx):
            embed = discord.Embed(
                title="❌ Wrong Channel",
                description="Please use this command in the text chat channel linked to your voice channel.",
                color=discord.Color.red()
            )
            msg = await ctx.send(embed=embed)
            try:
                await msg.delete(delay=5)
                await ctx.message.delete(delay=5)
            except Exception:
                pass
            return
        await self.music_player.stop(ctx)

    @app_commands.command(name="stop", description="Stop music and clear the queue")
    async def slash_stop_music(self, interaction: discord.Interaction):
        await interaction.response.defer()
        class MockContext:
            def __init__(self, interaction):
                self.guild = interaction.guild
                self.voice_client = interaction.guild.voice_client
                self.send = interaction.followup.send
        ctx = MockContext(interaction)
        await self.music_player.stop(ctx)

    @commands.command(name="queue", aliases=["q", "playlist"])
    async def show_queue(self, ctx):
        if not ctx.author.voice or not ctx.voice_client:
            embed = discord.Embed(
                title="❌ Not in Voice Channel",
                description="You need to be in a voice channel to use this command!",
                color=discord.Color.red()
            )
            msg = await ctx.send(embed=embed)
            try:
                await msg.delete(delay=5)
                await ctx.message.delete(delay=5)
            except Exception as e:
                self.logger.warning(f"Failed to delete messages on !queue command (not in voice): {e}")
            return
        if not self._user_in_linked_voice_text_channel(ctx):
            embed = discord.Embed(
                title="❌ Wrong Channel",
                description="Please use this command in the text chat channel linked to your voice channel.",
                color=discord.Color.red()
            )
            msg = await ctx.send(embed=embed)
            try:
                await msg.delete(delay=5)
                await ctx.message.delete(delay=5)
            except Exception as e:
                self.logger.warning(f"Failed to delete messages on !queue command (wrong channel): {e}")
            return
        await self.music_player.get_queue(ctx)

    @app_commands.command(name="queue", description="Show the current music queue")
    async def slash_show_queue(self, interaction: discord.Interaction):
        await interaction.response.defer()
        class MockContext:
            def __init__(self, interaction):
                self.guild = interaction.guild
                self.voice_client = interaction.guild.voice_client
                self.send = interaction.followup.send
        ctx = MockContext(interaction)
        await self.music_player.get_queue(ctx)

    @commands.command(name="song")
    async def current_song(self, ctx):
        guild_id = ctx.guild.id
        current = self.music_player.current_track.get(guild_id)
        if not current:
            await ctx.send("No song is currently playing.")
            return
        title = current['title']
        if not current.get('is_youtube') and title.lower().endswith('.mp3'):
            title = title[:-4]
        source_label = 'YouTube' if current.get('is_youtube') else 'CDN'
        requested_by = current.get('user', 'Unknown')
        duration = self.music_player.track_duration.get(guild_id)
        start = self.music_player.track_start.get(guild_id)
        desc = f"{title}"
        embed = discord.Embed(
            title="Now Playing",
            description=desc,
            color=discord.Color.purple()
        )
        embed.add_field(name="Source", value=source_label, inline=True)
        embed.add_field(name="Requested by", value=requested_by, inline=True)
        if duration and start:
            elapsed = int(time.time() - start)
            elapsed_str = str(datetime.timedelta(seconds=elapsed))
            duration_str = str(datetime.timedelta(seconds=int(duration)))
            embed.add_field(name="Progress", value=f"{elapsed_str} / {duration_str}", inline=True)
        msg = await ctx.send(embed=embed)
        try:
            await msg.delete(delay=5)
            await ctx.message.delete(delay=5)
        except Exception:
            pass

    @app_commands.command(name="song", description="Show the current song playing")
    async def slash_current_song(self, interaction: discord.Interaction):
        guild_id = interaction.guild.id
        current = self.music_player.current_track.get(guild_id)
        # If idle, enqueue and play random CDN track
        if not current:
            await interaction.response.send_message("No song is currently playing.", ephemeral=True)
            return
        title = current['title']
        if not current.get('is_youtube') and title.lower().endswith('.mp3'):
            title = title[:-4]
        source_label = 'YouTube' if current.get('is_youtube') else 'CDN'
        duration = self.music_player.track_duration.get(guild_id)
        start = self.music_player.track_start.get(guild_id)
        desc = f"{title}"
        embed = discord.Embed(
            title="Now Playing",
            description=desc,
            color=discord.Color.purple()
        )
        embed.add_field(name="Source", value=source_label, inline=True)
        embed.add_field(name="Requested by", value=current.get('user', 'Unknown'), inline=True)
        if duration and start:
            elapsed = int(time.time() - start)
            elapsed_str = str(datetime.timedelta(seconds=elapsed))
            duration_str = str(datetime.timedelta(seconds=int(duration)))
            embed.add_field(name="Progress", value=f"{elapsed_str} / {duration_str}", inline=True)
        await interaction.response.send_message(embed=embed, ephemeral=True)

    @commands.command(name="volume")
    async def set_volume(self, ctx, volume: int = None):
        # If no volume provided, show current volume
        if volume is None:
            current_vol = self.music_player.volumes.get(ctx.guild.id, config.volume_default)
            current_vol_percent = int(current_vol * 100)
            embed = discord.Embed(
                title="🔊 Current Volume",
                description=f"Volume is currently set to **{current_vol_percent}%**",
                color=discord.Color.blue()
            )
            msg = await ctx.send(embed=embed)
            try:
                await msg.delete(delay=5)
                await ctx.message.delete()
            except Exception:
                pass
            return
        # Validate volume range
        if volume < 0 or volume > 100:
            embed = discord.Embed(
                title="❌ Invalid Volume",
                description="Please provide a volume between 0 and 100.",
                color=discord.Color.red()
            )
            msg = await ctx.send(embed=embed)
            try:
                await msg.delete(delay=5)
                await ctx.message.delete()
            except Exception:
                pass
            return
        # Set new volume
        vol = volume / 100
        self.music_player.volumes[ctx.guild.id] = vol
        # Adjust live volume if playing
        vc = ctx.voice_client
        if vc and hasattr(vc, 'source') and isinstance(vc.source, discord.PCMVolumeTransformer):
            vc.source.volume = vol
        embed = discord.Embed(
            title="🔊 Volume Changed",
            description=f"Set volume to **{volume}%**",
            color=discord.Color.green()
        )
        msg = await ctx.send(embed=embed)
        try:
            await msg.delete(delay=5)
            await ctx.message.delete()
        except Exception:
            pass

    @app_commands.command(name="volume", description="Show current volume or set playback volume (0-100)")
    @app_commands.describe(volume="Volume percentage (0-100) - leave empty to see current volume")
    async def slash_volume(self, interaction: discord.Interaction, volume: int = None):
        # If no volume provided, show current volume
        if volume is None:
            current_vol = self.music_player.volumes.get(interaction.guild.id, config.volume_default)
            current_vol_percent = int(current_vol * 100)
            embed = discord.Embed(
                title="🔊 Current Volume",
                description=f"Volume is currently set to **{current_vol_percent}%**",
                color=discord.Color.blue()
            )
            await interaction.response.send_message(embed=embed, ephemeral=True)
            return
        # Validate volume range
        if volume < 0 or volume > 100:
            embed = discord.Embed(
                title="❌ Invalid Volume",
                description="Please provide a volume between 0 and 100.",
                color=discord.Color.red()
            )
            await interaction.response.send_message(embed=embed, ephemeral=True)
            return
        # Set new volume
        vol = volume / 100
        self.music_player.volumes[interaction.guild.id] = vol
        vc = interaction.guild.voice_client
        if vc and hasattr(vc, 'source') and isinstance(vc.source, discord.PCMVolumeTransformer):
            vc.source.volume = vol
        embed = discord.Embed(
            title="🔊 Volume Changed",
            description=f"Set volume to **{volume}%**",
            color=discord.Color.green()
        )
        await interaction.response.send_message(embed=embed, ephemeral=True)

# ChannelManagementCog class
class DiscordChannelResolver:
    def __init__(self, logger=None, mysql_helper=None):
        self.logger = logger
        self.mysql = mysql_helper or MySQLHelper(logger)
    async def get_user_id_from_api_key(self, api_key):
        self.logger.info(f"Looking up user_id for api_key/code: {api_key}")
        row = await self.mysql.fetchone(
            "SELECT id, username FROM users WHERE api_key = %s", (api_key,), database_name='website')
        if row:
            self.logger.info(f"Query result for api_key/code {api_key}: user_id={row[0]}, username={row[1]}")
        else:
            self.logger.warning(f"No user found for api_key/code {api_key}")
            # Extra debug: list all api_keys in the table
            all_keys = await self.mysql.fetchall(
                "SELECT api_key, username FROM users", database_name='website')
            self.logger.warning(f"All api_keys in users table: {[(k[0], k[1]) for k in all_keys] if all_keys else 'None found'}")
        return row[0] if row else None
    async def get_discord_info_from_user_id(self, user_id):
        self.logger.info(f"Looking up discord info for user_id: {user_id}")
        user_row = await self.mysql.fetchone(
            "SELECT username FROM users WHERE id = %s", (user_id,), database_name='website')
        username = user_row[0] if user_row else f"user_id_{user_id}"
        row = await self.mysql.fetchone(
            "SELECT guild_id, live_channel_id, online_text, offline_text FROM discord_users WHERE user_id = %s",
            (user_id,), database_name='website', dict_cursor=True)
        if row:
            self.logger.info(f"Discord info found for {username} (user_id: {user_id}): guild_id={row['guild_id']}, live_channel_id={row['live_channel_id']}")
        else:
            self.logger.warning(f"No discord info found for {username} (user_id: {user_id})")
        return row if row else None
    async def get_discord_info_from_guild_id(self, guild_id):
        # Find discord_users row by guild_id
        row = await self.mysql.fetchone(
            "SELECT user_id, live_channel_id, online_text, offline_text FROM discord_users WHERE guild_id = %s",
            (guild_id,), database_name='website', dict_cursor=True)
        if not row:
            return None
        user_id = row['user_id']
        # Get twitch_display_name from users table
        user_row = await self.mysql.fetchone(
            "SELECT twitch_display_name FROM users WHERE id = %s", (user_id,), database_name='website', dict_cursor=True)
        if user_row:
            row['twitch_display_name'] = user_row['twitch_display_name']
        else:
            row['twitch_display_name'] = None
        return row

# MySQLHelper class for database operations
class MySQLHelper:
    def __init__(self, logger=None):
        self.logger = logger
    async def get_connection(self, database_name):
        sql_host = config.sql_host
        sql_user = config.sql_user
        sql_password = config.sql_password
        if not sql_host or not sql_user or not sql_password:
            self.logger.error("Missing SQL connection parameters. Please check the .env file.")
            return None
        try:
            conn = await aiomysql.connect(
                host=sql_host,
                user=sql_user,
                password=sql_password,
                db=database_name
            )
            return conn
        except Exception as e:
            self.logger.error(f"Error connecting to MySQL: {e}")
            return None
    async def fetchone(self, query, params=None, database_name='website', dict_cursor=False):
        conn = await self.get_connection(database_name)
        if not conn:
            return None
        try:
            if dict_cursor:
                async with conn.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute(query, params)
                    row = await cursor.fetchone()
                    return row
            else:
                async with conn.cursor() as cursor:
                    await cursor.execute(query, params)
                    row = await cursor.fetchone()
                    return row
        except Exception as e:
            self.logger.error(f"MySQL fetchone error: {e}")
            return None
        finally:
            conn.close()
    async def fetchall(self, query, params=None, database_name='website', dict_cursor=False):
        conn = await self.get_connection(database_name)
        if not conn:
            return None
        try:
            if dict_cursor:
                async with conn.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute(query, params)
                    rows = await cursor.fetchall()
                    return rows
            else:
                async with conn.cursor() as cursor:
                    await cursor.execute(query, params)
                    rows = await cursor.fetchall()
                    return rows
        except Exception as e:
            self.logger.error(f"MySQL fetchall error: {e}")
            return None
        finally:
            conn.close()
    async def execute(self, query, params=None, database_name='website'):
        conn = await self.get_connection(database_name)
        if not conn:
            return None
        try:
            async with conn.cursor() as cursor:
                await cursor.execute(query, params)
                await conn.commit()
                return cursor.rowcount
        except Exception as e:
            self.logger.error(f"MySQL execute error: {e}")
            return None
        finally:
            conn.close()

# DiscordBotRunner class to manage the bot lifecycle
class DiscordBotRunner:
    def __init__(self, discord_logger):
        self.logger = discord_logger
        self.discord_token = config.discord_token
        self.bot = None
        self.loop = None
        signal.signal(signal.SIGTERM, self.sig_handler)
        signal.signal(signal.SIGINT, self.sig_handler)

    def sig_handler(self, signum, frame):
        signame = signal.Signals(signum).name
        self.logger.error(f'Caught Signal {signame} ({signum})')
        self.loop.create_task(self.stop_bot())

    async def stop_bot(self):
        if self.bot is not None:
            self.logger.info("Stopping BotOfTheSpecter Discord Bot")
            tasks = [t for t in asyncio.all_tasks(self.loop) if not t.done()]
            list(map(lambda task: task.cancel(), tasks))
            try:
                await asyncio.gather(*tasks, return_exceptions=True)
                await self.bot.close()
            except asyncio.CancelledError as e:
                self.logger.error(f"Bot task was cancelled. Error: {e}")
            finally:
                self.loop.stop()

    def run(self):
        self.loop = asyncio.new_event_loop()
        asyncio.set_event_loop(self.loop)
        try:
            self.loop.run_until_complete(self.initialize_bot())
        except asyncio.CancelledError:
            self.logger.error("BotRunner task was cancelled.")
        finally:
            self.loop.run_until_complete(self.loop.shutdown_asyncgens())
            self.loop.close()

    async def initialize_bot(self):
        self.bot = BotOfTheSpecter(self.discord_token, self.logger)
        await self.bot.start(self.discord_token)

def main():
    bot_log_file = os.path.join(config.discord_logs, f"discordbot.txt")
    discord_logger = setup_logger('discord', bot_log_file, level=logging.INFO)
    discord_logger.info(f"Starting BotOfTheSpecter Discord Bot version {config.bot_version}")
    bot_runner = DiscordBotRunner(discord_logger)
    bot_runner.run()

if __name__ == "__main__":
    main()