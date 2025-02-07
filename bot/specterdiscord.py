import asyncio
import logging
import os
import signal
import aiohttp
import discord
from discord.ext import commands
from discord import app_commands
from dotenv import load_dotenv
import aiomysql

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

# Global configuration class
class Config:
    def __init__(self):
        self.discord_token = os.getenv("DISCORD_TOKEN")
        self.api_token = os.getenv("API_KEY")

config = Config()

# Define the bot version
BOT_VERSION = "1.0"

# Bot class
class BotOfTheSpecter(commands.Bot):
    def __init__(self, discord_token, discord_logger, **kwargs):
        intents = discord.Intents.default()
        intents.message_content = True
        super().__init__(command_prefix="!", intents=intents, **kwargs)
        self.discord_token = discord_token
        self.logger = discord_logger
        self.typing_speed = 50
        self.processed_messages_file = f"/var/www/logs/discord/messages.txt"
        self.version = BOT_VERSION
        # Ensure the log file exists
        if not os.path.exists(self.processed_messages_file):
            open(self.processed_messages_file, 'w').close()

    async def on_ready(self):
        self.logger.info(f'Logged in as {self.user} (ID: {self.user.id})')
        self.logger.info(f'Bot version: {self.version}')
        await self.add_cog(QuoteCog(self, config.api_token, self.logger))
        await self.add_cog(TicketCog(self, self.logger))
        self.logger.info("BotOfTheSpecter Discord Bot has started.")

    async def setup_hook(self):
        # Sync the slash commands when the bot starts
        await self.tree.sync()

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
        self.OWNER_ID = 127783626917150720

    async def init_db(self):
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
                            category VARCHAR(50) DEFAULT 'general'
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
                    # Create ticket_attachments table
                    await cur.execute("""
                        CREATE TABLE IF NOT EXISTS ticket_attachments (
                            attachment_id INT AUTO_INCREMENT PRIMARY KEY,
                            ticket_id INT NOT NULL,
                            file_url VARCHAR(512) NOT NULL,
                            file_name VARCHAR(255) NOT NULL,
                            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
            self.logger.error(f"Error initializing database: {e}")
            raise

    async def get_settings(self, guild_id: int):
        if not self.pool:
            await self.init_db()
        async with self.pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute(
                    "SELECT * FROM ticket_settings WHERE guild_id = %s",
                    (guild_id,)
                )
                return await cur.fetchone()

    async def create_ticket(self, guild_id: int, user_id: int, username: str, issue: str) -> int:
        settings = await self.get_settings(guild_id)
        if not settings or not settings['enabled']:
            raise ValueError("Ticket system is not set up in this server")

        if not self.pool:
            await self.init_db()
        async with self.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "INSERT INTO tickets (user_id, username, issue) VALUES (%s, %s, %s)",
                    (user_id, username, issue)
                )
                ticket_id = cur.lastrowid
                # Log the ticket creation in history
                await cur.execute(
                    "INSERT INTO ticket_history (ticket_id, user_id, username, action, details) VALUES (%s, %s, %s, %s, %s)",
                    (ticket_id, user_id, username, "created", "Ticket created")
                )
                return ticket_id

    async def get_ticket(self, ticket_id: int):
        if not self.pool:
            await self.init_db()
        async with self.pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute(
                    "SELECT * FROM tickets WHERE ticket_id = %s",
                    (ticket_id,)
                )
                return await cur.fetchone()

    async def close_ticket(self, ticket_id: int, user_id: int, username: str):
        if not self.pool:
            await self.init_db()
        async with self.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "UPDATE tickets SET status = 'closed', closed_at = NOW() WHERE ticket_id = %s",
                    (ticket_id,)
                )
                # Log the ticket closure in history
                await cur.execute(
                    "INSERT INTO ticket_history (ticket_id, user_id, username, action, details) VALUES (%s, %s, %s, %s, %s)",
                    (ticket_id, user_id, username, "closed", "Ticket closed")
                )

    @commands.command(name="ticket")
    async def create_ticket_command(self, ctx, *, issue: str):
        """Create a support ticket"""
        ticket_id = await self.create_ticket(ctx.guild.id, ctx.author.id, str(ctx.author), issue)
        
        embed = discord.Embed(
            title="Support Ticket Created",
            color=discord.Color.green()
        )
        embed.add_field(name="Ticket ID", value=f"#{ticket_id}", inline=False)
        embed.add_field(name="Issue", value=issue, inline=False)
        embed.set_footer(text=f"Created by {ctx.author}")
        await ctx.send(embed=embed)
        self.logger.info(f"Ticket #{ticket_id} created by {ctx.author}")

    @app_commands.command(name="ticket", description="Create a support ticket")
    async def slash_ticket(self, interaction: discord.Interaction, issue: str):
        ticket_id = await self.create_ticket(interaction.guild_id, interaction.user.id, str(interaction.user), issue)
        embed = discord.Embed(
            title="Support Ticket Created",
            color=discord.Color.green()
        )
        embed.add_field(name="Ticket ID", value=f"#{ticket_id}", inline=False)
        embed.add_field(name="Issue", value=issue, inline=False)
        embed.set_footer(text=f"Created by {interaction.user}")
        await interaction.response.send_message(embed=embed)
        self.logger.info(f"Ticket #{ticket_id} created by {interaction.user}")

    @commands.command(name="viewticket")
    @commands.has_permissions(administrator=True)
    async def view_ticket(self, ctx, ticket_id: int):
        """View a ticket (Admin only)"""
        ticket = await self.get_ticket(ticket_id)
        if ticket:
            embed = discord.Embed(
                title=f"Ticket #{ticket_id}",
                color=discord.Color.blue()
            )
            embed.add_field(name="User", value=ticket['username'], inline=False)
            embed.add_field(name="Issue", value=ticket['issue'], inline=False)
            embed.add_field(name="Status", value=ticket['status'], inline=False)
            embed.add_field(name="Created At", value=ticket['created_at'], inline=False)
            await ctx.send(embed=embed)
            self.logger.info(f"Ticket #{ticket_id} viewed by {ctx.author}")
        else:
            await ctx.send("Ticket not found!")

    @app_commands.command(name="viewticket", description="View a support ticket (Admin only)")
    @app_commands.default_permissions(administrator=True)
    async def slash_view_ticket(self, interaction: discord.Interaction, ticket_id: int):
        ticket = await self.get_ticket(ticket_id)
        if ticket:
            embed = discord.Embed(
                title=f"Ticket #{ticket_id}",
                color=discord.Color.blue()
            )
            embed.add_field(name="User", value=ticket['username'], inline=False)
            embed.add_field(name="Issue", value=ticket['issue'], inline=False)
            embed.add_field(name="Status", value=ticket['status'], inline=False)
            embed.add_field(name="Created At", value=ticket['created_at'], inline=False)
            await interaction.response.send_message(embed=embed)
            self.logger.info(f"Ticket #{ticket_id} viewed by {interaction.user}")
        else:
            await interaction.response.send_message("Ticket not found!")

    @commands.command(name="closeticket")
    @commands.has_permissions(administrator=True)
    async def close_ticket_command(self, ctx, ticket_id: int):
        """Close a ticket (Admin only)"""
        ticket = await self.get_ticket(ticket_id)
        if ticket and ticket['status'] == 'open':
            await self.close_ticket(ticket_id, ticket['user_id'], ticket['username'])
            await ctx.send(f"Ticket #{ticket_id} has been closed.")
            self.logger.info(f"Ticket #{ticket_id} closed by {ctx.author}")
        else:
            await ctx.send("Ticket not found or already closed!")

    @app_commands.command(name="closeticket", description="Close a support ticket (Admin only)")
    @app_commands.default_permissions(administrator=True)
    async def slash_close_ticket(self, interaction: discord.Interaction, ticket_id: int):
        ticket = await self.get_ticket(ticket_id)
        if ticket and ticket['status'] == 'open':
            await self.close_ticket(ticket_id, ticket['user_id'], ticket['username'])
            await interaction.response.send_message(f"Ticket #{ticket_id} has been closed.")
            self.logger.info(f"Ticket #{ticket_id} closed by {interaction.user}")
        else:
            await interaction.response.send_message("Ticket not found or already closed!")

    @commands.command(name="setuptickets")
    async def setup_tickets(self, ctx):
        """Set up the ticket system (Bot Owner Only)"""
        # Check if user is the bot owner
        if ctx.author.id != self.OWNER_ID:
            await ctx.send("Only the bot owner can set up the ticket system.")
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

            # Create the info message
            embed = discord.Embed(
                title="🎫 Support Ticket System",
                description=(
                    "Welcome to our support ticket system!\n\n"
                    "To create a new support ticket, use one of these commands:\n"
                    "• `/ticket <issue>` - Create a ticket using slash command\n"
                    "• `!ticket <issue>` - Create a ticket using text command\n\n"
                    "**Example:**\n"
                    "`!ticket I need help with my account`\n\n"
                    "Your ticket will be created and our support team will assist you as soon as possible."
                ),
                color=discord.Color.blue()
            )
            embed.add_field(
                name="Important Notes",
                value=(
                    "• Please provide a clear description of your issue\n"
                    "• One ticket per issue\n"
                    "• Be patient while waiting for a response\n"
                    "• Keep all communication respectful"
                ),
                inline=False
            )
            embed.set_footer(text="Bot of the Specter Support System")

            # Clear existing messages in info channel
            await info_channel.purge()
            await info_channel.send(embed=embed)

            # Set channel permissions
            await info_channel.set_permissions(ctx.guild.default_role, send_messages=False)
            await category.set_permissions(ctx.guild.default_role, read_messages=False)

            await ctx.send(f"✅ Ticket system has been set up successfully!\nPlease check {info_channel.mention} for the info message.")
            self.logger.info(f"Ticket system set up completed in {ctx.guild.name}")

        except Exception as e:
            self.logger.error(f"Error setting up ticket system: {e}")
            await ctx.send("❌ An error occurred while setting up the ticket system. Please check the logs.")

    @app_commands.command(name="setuptickets", description="Set up the ticket system (Bot Owner Only)")
    async def slash_setup_tickets(self, interaction: discord.Interaction):
        if interaction.user.id != self.OWNER_ID:
            await interaction.response.send_message("Only the bot owner can set up the ticket system.")
            return

        await interaction.response.defer()
        
        try:
            # Create the category if it doesn't exist
            category = discord.utils.get(interaction.guild.categories, name="Open Tickets")
            if not category:
                category = await interaction.guild.create_category(
                    name="Open Tickets",
                    reason="Ticket System Setup"
                )
                self.logger.info(f"Created 'Open Tickets' category in {interaction.guild.name}")

            # Rest of the setup code (same as above)
            # ... (copy the same setup logic from the regular command)

            await interaction.followup.send(f"✅ Ticket system has been set up successfully!\nPlease check {info_channel.mention} for the info message.")
            self.logger.info(f"Ticket system set up completed in {interaction.guild.name}")

        except Exception as e:
            self.logger.error(f"Error setting up ticket system: {e}")
            await interaction.followup.send("❌ An error occurred while setting up the ticket system. Please check the logs.")

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
        self.logger.info("Starting BotOfTheSpecter Discord Bot")
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