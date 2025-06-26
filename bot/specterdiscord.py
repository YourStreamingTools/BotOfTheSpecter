# Standard library
import asyncio
import logging
import os
import signal
import json
# Third-party libraries
import aiohttp
import discord
from discord.ext import commands
from discord import app_commands
from dotenv import load_dotenv
import aiomysql
import socketio

# Load environment variables from .env file
load_dotenv()

# Define logging directory
logs_directory = "/var/www/logs"
discord_logs = os.path.join(logs_directory, "specterdiscord")

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

# Channel mapping class to manage multiple Discord servers
class ChannelMapping:
    def __init__(self):
        self.mappings = {}  # channel_code -> {guild_id, channel_id, channel_name}
        self.load_mappings()
    def load_mappings(self):
        mapping_file = "/home/botofthespecter/discord_channel_mappings.json"
        try:
            if os.path.exists(mapping_file):
                with open(mapping_file, 'r') as f:
                    self.mappings = json.load(f)
        except Exception as e:
            print(f"Error loading channel mappings: {e}")
    def save_mappings(self):
        mapping_file = "/home/botofthespecter/discord_channel_mappings.json"
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

# Global configuration class
class Config:
    def __init__(self):
        self.discord_token = os.getenv("DISCORD_TOKEN")
        self.api_token = os.getenv("API_KEY")

config = Config()

# Define the bot information
BOT_VERSION = "2.0"
BOT_COLOR = 0x001C1D

# Discord Bot Service Version
DISCORD_BOT_SERVICE_VERSION = "5.0.0"
DISCORD_VERSION_FILE = "/var/www/logs/version/discord_version_control.txt"

# Bot class
class BotOfTheSpecter(commands.Bot):
    def __init__(self, discord_token, discord_logger, **kwargs):
        intents = discord.Intents.default()
        intents.message_content = True
        super().__init__(command_prefix="!", intents=intents, **kwargs)
        self.discord_token = discord_token
        self.logger = discord_logger
        self.typing_speed = 50
        self.processed_messages_file = f"/home/botofthespecter/logs/discord/messages.txt"
        self.version = BOT_VERSION
        self.pool = None  # Initialize the pool attribute
        self.channel_mapping = ChannelMapping()
        self.websocket_client = None
        # Ensure the log directory and file exist
        messages_dir = os.path.dirname(self.processed_messages_file)
        if not os.path.exists(messages_dir):
            os.makedirs(messages_dir)
        if not os.path.exists(self.processed_messages_file):
            open(self.processed_messages_file, 'w').close()

    async def on_ready(self):
        self.logger.info(f'Logged in as {self.user} (ID: {self.user.id})')
        self.logger.info(f'Bot version: {self.version}')
        # Update the global version file for dashboard display
        try:
            os.makedirs(os.path.dirname(DISCORD_VERSION_FILE), exist_ok=True)
            with open(DISCORD_VERSION_FILE, "w") as f:
                f.write(DISCORD_BOT_SERVICE_VERSION + "\n")
            self.logger.info(f"Updated Discord bot version file: {DISCORD_BOT_SERVICE_VERSION}")
        except Exception as e:
            self.logger.error(f"Failed to update Discord bot version file: {e}")
        # Set the initial presence
        await self.update_presence()
        await self.add_cog(QuoteCog(self, config.api_token, self.logger))
        await self.add_cog(TicketCog(self, self.logger))
        await self.add_cog(ChannelManagementCog(self, self.logger))
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
                file.write(message_id + '\n')
        # If the message is in a server channel, process commands
        await self.process_commands(message)

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
        import socketio
        self.websocket_client = socketio.AsyncClient(logger=False, engineio_logger=False)
        admin_key = os.getenv("ADMIN_KEY")
        websocket_url = "wss://websocket.botofthespecter.com"
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
            await channel.send(message)
            self.logger.info(f"Sent {event_type} notification to {guild.name}#{channel.name}")

    async def handle_stream_event(self, event_type, data):
        resolver = DiscordChannelResolver(self.logger)
        code = data.get("channel_code", "unknown")
        user_id = await resolver.get_user_id_from_api_key(code)
        if not user_id:
            self.logger.warning(f"No user_id found for api_key/code: {code}")
            return
        discord_info = await resolver.get_discord_info_from_user_id(user_id)
        if not discord_info:
            self.logger.warning(f"No discord info found for user_id: {user_id}")
            return
        guild = self.get_guild(int(discord_info["guild_id"]))
        if not guild:
            self.logger.warning(f"Bot not in guild {discord_info['guild_id']} for user_id {user_id}")
            return
        channel = guild.get_channel(int(discord_info["live_channel_id"]))
        if not channel:
            self.logger.warning(f"Channel {discord_info['live_channel_id']} not found in guild {guild.name}")
            return
        if event_type == "ONLINE":
            message = discord_info["online_text"] or "🟢 Stream is now LIVE!"
        else:
            message = discord_info["offline_text"] or "🔴 Stream is now OFFLINE"
        await channel.send(message)
        self.logger.info(f"Sent stream {event_type} notification to {guild.name}#{channel.name}")

    def format_twitch_message(self, event_type, data):
        username = data.get("username", "Unknown User")
        if event_type == "FOLLOW":
            return f"💙 **{username}** just followed the stream!"
        elif event_type == "SUBSCRIPTION":
            months = data.get("months", 1)
            tier = data.get("tier", "1")
            message_text = data.get("message", "")
            msg = f"⭐ **{username}** just subscribed"
            if months > 1:
                msg += f" for {months} months"
            msg += f" (Tier {tier})!"
            if message_text:
                msg += f"\n> {message_text}"
            return msg
        elif event_type == "CHEER":
            bits = data.get("bits", 0)
            message_text = data.get("message", "")
            msg = f"💎 **{username}** cheered {bits} bits!"
            if message_text:
                msg += f"\n> {message_text}"
            return msg
        elif event_type == "RAID":
            viewers = data.get("viewers", 0)
            return f"🚀 **{username}** raided with {viewers} viewers!"
        return None

class QuoteCog(commands.Cog, name='Quote'):
    def __init__(self, bot: BotOfTheSpecter, api_token: str, logger=None):
        self.bot = bot
        self.api_token = api_token
        self.logger = logger or logging.getLogger(self.__class__.__name__)
        self.typing_speed = 50
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

class TicketCog(commands.Cog, name='Tickets'):
    def __init__(self, bot: commands.Bot, logger=None):
        self.bot = bot
        self.logger = logger or logging.getLogger(self.__class__.__name__)
        self.pool = None
        self.OWNER_ID = 127783626917150720              # gfaUnDead User ID (Owner)
        self.SUPPORT_GUILD_ID = 1103694163930787880     # YourStreamingTools Server ID
        self.SUPPORT_ROLE = 1337400720403468288         # Support Team Role
        self.MOD_CHANNEL_ID = 1103695077928345683       # Moderator Channel ID

    async def init_ticket_database(self):
        # First create a connection without specifying a database
        temp_pool = await aiomysql.create_pool(
            host=os.getenv('SQL_HOST'),
            user=os.getenv('SQL_USER'),
            password=os.getenv('SQL_PASSWORD'),
            autocommit=True
        )
        try:
            # Create database if it doesn't exist
            async with temp_pool.acquire() as conn:
                async with conn.cursor() as cur:
                    await cur.execute("CREATE DATABASE IF NOT EXISTS tickets")
            # Close the temporary pool
            temp_pool.close()
            await temp_pool.wait_closed()
            # Create the main connection pool with the tickets database
            self.pool = await aiomysql.create_pool(
                host=os.getenv('SQL_HOST'),
                user=os.getenv('SQL_USER'),
                password=os.getenv('SQL_PASSWORD'),
                db='tickets',
                autocommit=True
            )
            self.logger.info("Successfully initialized ticket database connection pool")
            # Create necessary tables
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cur:
                    # Create tickets table
                    await cur.execute("""
                        CREATE TABLE IF NOT EXISTS tickets (
                            ticket_id INT AUTO_INCREMENT PRIMARY KEY,
                            user_id BIGINT NOT NULL,
                            username VARCHAR(255) NOT NULL,
                            issue TEXT NOT NULL,
                            status VARCHAR(20) DEFAULT 'open',
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            closed_at TIMESTAMP NULL,
                            priority VARCHAR(20) DEFAULT 'normal',
                            category VARCHAR(50) DEFAULT 'general',
                            channel_id BIGINT NULL
                        )
                    """)
                    # Create ticket_comments table
                    await cur.execute("""
                        CREATE TABLE IF NOT EXISTS ticket_comments (
                            comment_id INT AUTO_INCREMENT PRIMARY KEY,
                            ticket_id INT NOT NULL,
                            user_id BIGINT NOT NULL,
                            username VARCHAR(255) NOT NULL,
                            comment TEXT NOT NULL,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            FOREIGN KEY (ticket_id) REFERENCES tickets(ticket_id)
                            ON DELETE CASCADE
                        )
                    """)
                    # Create ticket_history table
                    await cur.execute("""
                        CREATE TABLE IF NOT EXISTS ticket_history (
                            history_id INT AUTO_INCREMENT PRIMARY KEY,
                            ticket_id INT NOT NULL,
                            user_id BIGINT NOT NULL,
                            username VARCHAR(255) NOT NULL,
                            action VARCHAR(50) NOT NULL,
                            details TEXT,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            FOREIGN KEY (ticket_id) REFERENCES tickets(ticket_id)
                            ON DELETE CASCADE
                        )
                    """)
                    # Create ticket_settings table
                    await cur.execute("""
                        CREATE TABLE IF NOT EXISTS ticket_settings (
                            guild_id BIGINT PRIMARY KEY,
                            info_channel_id BIGINT,
                            category_id BIGINT,
                            enabled BOOLEAN DEFAULT FALSE,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                        )
                    """)
            self.logger.info("Successfully initialized ticket database and tables")
        except Exception as e:
            self.logger.error(f"Error initializing ticket database: {e}")
            raise

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
        support_role = guild.get_role(self.SUPPORT_ROLE)
        # Create the ticket channel
        channel = await guild.create_text_channel(
            name=f"ticket-{ticket_id}",
            category=category,
            topic=f"Support Ticket #{ticket_id} | User: {user.name}"
        )
        # Set permissions
        await channel.set_permissions(guild.default_role, read_messages=False)
        await channel.set_permissions(user, read_messages=True, send_messages=True)
        await channel.set_permissions(support_role, read_messages=True, send_messages=True)
        # Welcome message for the user
        await channel.send(f"Welcome to your support ticket channel, {user.mention}!")
        # Create an embed with instructions
        embed = discord.Embed(
            title="Instructions",
            description=(
                "Please provide the following information:\n"
                "1. A detailed description of your issue\n"
                "2. What you've tried so far (if applicable)\n"
                "3. Any relevant screenshots or files\n\n"
                "Our support team will assist you as soon as possible.\n"
                "Please be patient and remain respectful throughout the process.\n\n"
                "If you wish to close the ticket at any time, use '!ticket close' to notify the support team."
            ),
            color=BOT_COLOR
        )
        await channel.send(embed=embed)  # Send the embed message to the channel
        # Notify the support team about the new ticket
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
                await cur.execute("INSERT INTO tickets (user_id, username, issue) VALUES (%s, %s, %s)",
                    (user_id, username, "Awaiting user's issue description"))
                ticket_id = cur.lastrowid
                await cur.execute("INSERT INTO ticket_history (ticket_id, user_id, username, action, details) VALUES (%s, %s, %s, %s, %s)",
                    (ticket_id, user_id, username, "created", "Ticket channel created"))
                return ticket_id

    async def close_ticket(self, ticket_id: int, channel_id: int, closer_id: int, closer_name: str, reason: str = "No reason provided"):
        if not self.pool:
            await self.init_ticket_database()
        # Get the channel and ticket information
        channel = self.bot.get_channel(channel_id)
        if not channel:
            raise ValueError("Channel not found")
        # Get ticket information to identify the ticket creator
        async with self.pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT user_id FROM tickets WHERE ticket_id = %s", (ticket_id,))
                ticket_data = await cur.fetchone()
                if not ticket_data:
                    raise ValueError("Ticket not found in database")
                # Update ticket status and store channel_id
                await cur.execute("UPDATE tickets SET status = 'closed', closed_at = NOW(), channel_id = %s WHERE ticket_id = %s",(channel_id, ticket_id))
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
                        color=BOT_COLOR
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
                    owner = channel.guild.get_member(self.OWNER_ID)
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

    async def get_ticket(self, ticket_id: int):
        if not self.pool:
            await self.init_ticket_database()
        async with self.pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT * FROM tickets WHERE ticket_id = %s", (ticket_id,))
                return await cur.fetchone()

    @commands.command(name="ticket")
    async def ticket_command(self, ctx, action: str = None, *, reason: str = None):
        """Ticket system commands"""
        # Check if the command is used in the correct server
        if ctx.guild.id != self.SUPPORT_GUILD_ID:
            await ctx.send("❌ This command can only be used in the support server.", delete_after=10)
            return
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
                ticket = await self.get_ticket(ticket_id)
                if not ticket:
                    embed = discord.Embed(
                        title="Ticket Closure Error",
                        description="It seems there is an issue: you're in a ticket channel, but I can't find the associated ticket ID number for this channel.",
                        color=discord.Color.yellow()
                    )
                    await ctx.send(embed=embed)
                    return
                # Check if the user has the support role
                support_role = ctx.guild.get_role(self.SUPPORT_ROLE)
                if support_role not in ctx.author.roles:
                    # Send closure message in channel
                    embed = discord.Embed(
                        title="Ticket Closure Notice",
                        description=(
                            "Only the support team can close tickets.\n"
                            "If you need further assistance, please provide more details in this ticket channel\n"
                            "before a support team member closes this ticket for you."
                            "\n\n"
                            "The support team has been notified that you wish to close this ticket.\n"
                            "When we close tickets, this ticket will be marked as closed and archived.\n\n"
                            "If you need further assistance in the future, please create a new ticket."
                        ),
                        color=discord.Color.yellow()
                    )
                    await ctx.send(embed=embed)
                    # Notify support team with a plain text message
                    await ctx.channel.send(f"{support_role.mention} requested ticket closure.")
                    return
                await self.close_ticket(ticket_id, ctx.channel.id, ctx.author.id, str(ctx.author), reason)
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
    async def setup_tickets(self, ctx):
        """Set up the ticket system (Bot Owner Only)"""
        # Check if user is in the correct server
        if ctx.guild.id != self.SUPPORT_GUILD_ID:
            await ctx.send(
                "❌ The ticket system can only be set up in the YourStreamingTools Discord server.\n"
                "This is a centralized support system - please join <https://discord.com/invite/ANwEkpauHJ> "
                "to create support tickets."
            )
            return
        # Check if command is used in the moderator channel
        if ctx.channel.id != self.MOD_CHANNEL_ID:
            await ctx.send(
                "❌ This command can only be used in the <#1103695077928345683> channel.",
                delete_after=10
            )
            return
        # Check if user is the bot owner
        if ctx.author.id != self.OWNER_ID:
            await ctx.send(
                "❌ Only the bot owner can set up the ticket system.\n"
                "The ticket system is managed centrally through the YourStreamingTools Discord server.\n"
                "Please join <https://discord.com/invite/ANwEkpauHJ> for support."
            )
            return
        try:
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
                        (guild_id, info_channel_id, category_id, enabled) 
                        VALUES (%s, %s, %s, TRUE)
                        ON DUPLICATE KEY UPDATE 
                        info_channel_id = VALUES(info_channel_id),
                        category_id = VALUES(category_id),
                        enabled = TRUE,
                        updated_at = CURRENT_TIMESTAMP
                    """, (ctx.guild.id, info_channel.id, category.id))
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
                title="🎫 YourStreamingTools Support System",
                description=(
                    "**Welcome to our support ticket system!**\n\n"
                    "To create a new support ticket: `!ticket create`\n\n"
                    "Once your ticket is created, you'll get access to a private channel where you can describe your issue in detail and communicate with our support team."
                    "\n\n"
                    "Important Notes\n"
                    "• Your ticket will be created in a private channel\n"
                    "• Provide a clear description of your issue in the ticket channel\n"
                    "• One ticket per issue\n"
                    "• Be patient while waiting for a response\n"
                    "• Keep all communication respectful\n"
                    "• Only support team members can close tickets"
                ),
                color=BOT_COLOR
            )
            # Add a warning message about channel usage
            warning_embed = discord.Embed(
                title="⚠️ Channel Information",
                description=(
                    "This channel is for creating support tickets only.\n"
                    "Please use the command `!ticket create` to open a ticket.\n"
                    "Regular messages will be automatically deleted."
                ),
                color=discord.Color.yellow()
            )
            # Send the new info message
            await info_channel.send(embed=embed)
            await info_channel.send(embed=warning_embed)
            await ctx.send(f"✅ Ticket system has been set up successfully!\nPlease check {info_channel.mention} for the info message.")
            self.logger.info(f"Ticket system set up completed in {ctx.guild.name}")
        except Exception as e:
            self.logger.error(f"Error setting up ticket system: {e}")
            await ctx.send("❌ An error occurred while setting up the ticket system. Please check the logs.")

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
                        "INSERT INTO ticket_comments (ticket_id, user_id, username, comment) VALUES (%s, %s, %s, %s)",
                        (ticket_id, message.author.id, str(message.author), message.content)
                    )
            self.logger.info(f"Auto-saved comment for ticket {ticket_id} from {message.author}")

class ChannelManagementCog(commands.Cog, name='Channel Management'):
    def __init__(self, bot: BotOfTheSpecter, logger=None):
        self.bot = bot
        self.logger = logger

    @app_commands.command(name="map_channel", description="Map a Twitch channel code to this Discord channel")
    @app_commands.describe(channel_code="The Twitch channel code to map to this Discord channel")
    async def map_channel(self, interaction: discord.Interaction, channel_code: str):
        if not interaction.user.guild_permissions.administrator:
            await interaction.response.send_message("❌ You need administrator permissions to use this command.", ephemeral=True)
            return
        self.bot.channel_mapping.add_mapping(
            channel_code,
            interaction.guild.id,
            interaction.channel.id,
            interaction.channel.name
        )
        embed = discord.Embed(
            title="✅ Channel Mapped Successfully",
            description=f"Twitch channel code `{channel_code}` is now mapped to {interaction.channel.mention}",
            color=discord.Color.green()
        )
        await interaction.response.send_message(embed=embed)
        if self.logger:
            self.logger.info(f"Mapped channel code {channel_code} to {interaction.guild.name}#{interaction.channel.name}")

    @app_commands.command(name="unmap_channel", description="Remove the mapping for a Twitch channel code")
    @app_commands.describe(channel_code="The Twitch channel code to unmap")
    async def unmap_channel(self, interaction: discord.Interaction, channel_code: str):
        if not interaction.user.guild_permissions.administrator:
            await interaction.response.send_message("❌ You need administrator permissions to use this command.", ephemeral=True)
            return
        if self.bot.channel_mapping.remove_mapping(channel_code):
            embed = discord.Embed(
                title="✅ Channel Unmapped Successfully",
                description=f"Twitch channel code `{channel_code}` has been unmapped",
                color=discord.Color.green()
            )
            if self.logger:
                self.logger.info(f"Unmapped channel code {channel_code}")
        else:
            embed = discord.Embed(
                title="❌ Channel Not Found",
                description=f"No mapping found for channel code `{channel_code}`",
                color=discord.Color.red()
            )
        await interaction.response.send_message(embed=embed)

    @app_commands.command(name="list_mappings", description="List all channel mappings for this server")
    async def list_mappings(self, interaction: discord.Interaction):
        if not interaction.user.guild_permissions.administrator:
            await interaction.response.send_message("❌ You need administrator permissions to use this command.", ephemeral=True)
            return
        guild_mappings = []
        for code, mapping in self.bot.channel_mapping.mappings.items():
            if mapping["guild_id"] == interaction.guild.id:
                channel = interaction.guild.get_channel(mapping["channel_id"])
                channel_mention = channel.mention if channel else f"#{mapping['channel_name']} (deleted)"
                guild_mappings.append(f"`{code}` → {channel_mention}")
        if guild_mappings:
            embed = discord.Embed(
                title="📋 Channel Mappings",
                description="\n".join(guild_mappings),
                color=BOT_COLOR
            )
        else:
            embed = discord.Embed(
                title="📋 Channel Mappings",
                description="No channel mappings found for this server.",
                color=BOT_COLOR
            )
        await interaction.response.send_message(embed=embed)

class MySQLHelper:
    def __init__(self, logger=None):
        self.logger = logger
    async def get_connection(self, database_name):
        sql_host = os.getenv('SQL_HOST')
        sql_user = os.getenv('SQL_USER')
        sql_password = os.getenv('SQL_PASSWORD')
        if not sql_host or not sql_user or not sql_password:
            if self.logger:
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
            if self.logger:
                self.logger.error(f"Error connecting to MySQL: {e}")
            return None
    async def fetchone(self, query, params=None, database_name='website', dict_cursor=False):
        conn = await self.get_connection(database_name)
        if not conn:
            return None
        try:
            cursor_type = aiomysql.DictCursor if dict_cursor else None
            async with conn.cursor(cursor_type) as cursor:
                await cursor.execute(query, params)
                row = await cursor.fetchone()
                return row
        except Exception as e:
            if self.logger:
                self.logger.error(f"MySQL fetchone error: {e}")
            return None
        finally:
            conn.close()
    async def fetchall(self, query, params=None, database_name='website', dict_cursor=False):
        conn = await self.get_connection(database_name)
        if not conn:
            return None
        try:
            cursor_type = aiomysql.DictCursor if dict_cursor else None
            async with conn.cursor(cursor_type) as cursor:
                await cursor.execute(query, params)
                rows = await cursor.fetchall()
                return rows
        except Exception as e:
            if self.logger:
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
            if self.logger:
                self.logger.error(f"MySQL execute error: {e}")
            return None
        finally:
            conn.close()

class DiscordChannelResolver:
    def __init__(self, logger=None, mysql_helper=None):
        self.logger = logger
        self.mysql = mysql_helper or MySQLHelper(logger)
    async def get_user_id_from_api_key(self, api_key):
        row = await self.mysql.fetchone(
            "SELECT id FROM users WHERE api_key = %s", (api_key,), database_name='website')
        return row[0] if row else None
    async def get_discord_info_from_user_id(self, user_id):
        row = await self.mysql.fetchone(
            "SELECT guild_id, live_channel_id, online_text, offline_text FROM discord_users WHERE user_id = %s",
            (user_id,), database_name='website', dict_cursor=True)
        return row if row else None

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
    bot_log_file = os.path.join(discord_logs, f"discordbot.txt")
    discord_logger = setup_logger('discord', bot_log_file, level=logging.INFO)
    discord_logger.info(f"Starting BotOfTheSpecter Discord Bot version {BOT_VERSION}")
    bot_runner = DiscordBotRunner(discord_logger)
    bot_runner.run()

if __name__ == "__main__":
    main()

class WebsocketListener:
    def __init__(self, bot, logger=None):
        self.bot = bot
        self.logger = logger
        self.sio = None
    async def start(self):
        self.sio = socketio.AsyncClient(logger=False, engineio_logger=False)
        admin_key = os.getenv("ADMIN_KEY")
        websocket_url = "wss://websocket.botofthespecter.com"
        @self.sio.event
        async def connect():
            if self.logger:
                self.logger.info("Connected to websocket server")
            await self.sio.emit("REGISTER", {
                "code": admin_key,
                "global_listener": True,
                "channel": "Global",
                "name": "Discord Bot Global Listener"
            })
        @self.sio.event
        async def disconnect():
            if self.logger:
                self.logger.info("Disconnected from websocket server")
        @self.sio.event
        async def SUCCESS(data):
            if self.logger:
                self.logger.info(f"Websocket registration successful: {data}")
        @self.sio.event
        async def ERROR(data):
            if self.logger:
                self.logger.error(f"Websocket error: {data}")
        @self.sio.event
        async def STREAM_ONLINE(data):
            await self.bot.handle_stream_event("ONLINE", data)
        @self.sio.event
        async def STREAM_OFFLINE(data):
            await self.bot.handle_stream_event("OFFLINE", data)
        await self.sio.connect(websocket_url)