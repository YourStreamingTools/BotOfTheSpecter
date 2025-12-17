# Standard library
import asyncio
import json
import logging
import os
import re
import signal
import subprocess
import tempfile
import time
import traceback
import urllib.parse
from datetime import datetime, timezone, timedelta, timezone as dt_timezone
from pathlib import Path
import random

# Third-party libraries
import aiohttp
import aiomysql
import discord
from discord.ext import commands
from discord import app_commands
from dotenv import load_dotenv
import pytz
import socketio
import yt_dlp
from openai import AsyncOpenAI

# Bot version
VERSION = "6.0"

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
        self.twitch_client_id = os.getenv('CLIENT_ID')
        self.twitch_bearer_token = os.getenv('TWITCH_OAUTH_API_TOKEN')
        self.bot_owner_id = 127783626917150720
        self.discord_application_id = os.getenv('DISCORD_APPLICATION_ID')
        # Bot information
        self.bot_color = 0x001C1D
        self.bot_version = VERSION
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

# OpenAI / AI system configuration
OPENAI_API_KEY = os.getenv("OPENAI_KEY")
OPENAI_INSTRUCTIONS_ENDPOINT = 'https://api.botofthespecter.com/chat-instructions'
INSTRUCTIONS_CACHE_TTL = 300  # seconds
_cached_instructions = None
_cached_instructions_time = 0
# Directory to persist per-user chat history
HISTORY_DIR = '/home/botofthespecter/ai/chat-history/discord'
# Discord max message length
MAX_CHAT_MESSAGE_LENGTH = 2000
# Initialize AsyncOpenAI client if key is present
openai_client = AsyncOpenAI(api_key=OPENAI_API_KEY)

# Utility function to safely convert database IDs to integers
def safe_int_convert(value, default=None, logger=None):
    if value is None:
        return default
    try:
        return int(value)
    except (ValueError, TypeError):
        if logger:
            logger.warning(f"Failed to convert '{value}' to int, using default: {default}")
        return default

# Ensure directories exist with proper error handling
def ensure_directory_exists(directory_path, description=""):
    try:
        if not os.path.exists(directory_path):
            os.makedirs(directory_path, mode=0o755, exist_ok=True)
            print(f"Created {description} directory: {directory_path}")
        else:
            print(f"{description} directory exists: {directory_path}")
        return True
    except Exception as e:
        print(f"Error creating {description} directory {directory_path}: {e}")
        return False

def split_message_preserve_markdown(text: str, max_len: int):
    if not text:
        return [""]
    paragraphs = text.split('\n\n')
    chunks = []
    current = ""
    def finalize_chunk(chunk):
        # Ensure code fence balance within chunk. If odd number of ``` present, close it.
        backticks = '```'
        count = chunk.count(backticks)
        if count % 2 == 1:
            # Try to find language on the last opening fence
            idx = chunk.rfind(backticks)
            lang = ''
            # Attempt to capture language token after the fence
            rest = chunk[idx + len(backticks):]
            if rest and '\n' in rest:
                first_line = rest.split('\n', 1)[0].strip()
                if first_line and len(first_line) <= 32 and ' ' not in first_line:
                    lang = first_line
            # Close the fence to keep Discord rendering correct
            chunk = chunk + '\n' + backticks
            # Return chunk plus reopen info for next chunk
            reopen = backticks + (lang if lang else '') + '\n'
            return chunk, reopen
        return chunk, None
    reopen_prefix = ''
    for p in paragraphs:
        piece = (reopen_prefix + p).strip()
        reopen_prefix = ''
        if not current:
            current = piece
        elif len(current) + 2 + len(piece) <= max_len:
            current = current + '\n\n' + piece
        else:
            # finalize current
            finalized, reopen = finalize_chunk(current)
            chunks.append(finalized)
            if reopen:
                reopen_prefix = reopen
            current = piece
            # If single paragraph is still too large, split by words
            if len(current) > max_len:
                words = current.split(' ')
                cur2 = ''
                for w in words:
                    if not cur2:
                        cur2 = w
                    elif len(cur2) + 1 + len(w) <= max_len:
                        cur2 = cur2 + ' ' + w
                    else:
                        f, r = finalize_chunk(cur2)
                        chunks.append(f)
                        cur2 = w
                        if r:
                            reopen_prefix = r
                if cur2:
                    current = cur2
                else:
                    current = ''
    if current:
        finalized, reopen = finalize_chunk(current)
        chunks.append(finalized)
        if reopen:
            # If reopen remains (unlikely at final chunk), just append closing fence already done.
            pass
    # As a safety: ensure no chunk exceeds max_len by forcing splits
    safe_chunks = []
    for c in chunks:
        if len(c) <= max_len:
            safe_chunks.append(c)
        else:
            # naive split
            for i in range(0, len(c), max_len):
                safe_chunks.append(c[i:i+max_len])
    return safe_chunks

# Ensure all required directories exist
directories_to_create = [
    (config.logs_directory, "logs"),
    (config.discord_logs, "discord logs"),
    (os.path.dirname(config.processed_messages_file), "processed messages")
]

for directory_path, description in directories_to_create:
    ensure_directory_exists(directory_path, description)

# Function to setup logger
def setup_logger(name, log_file, level=logging.INFO):
    handler = logging.FileHandler(log_file)
    formatter = logging.Formatter('%(asctime)s - %(levelname)s - %(message)s')
    handler.setFormatter(formatter)
    logger = logging.getLogger(name)
    logger.setLevel(level)
    logger.addHandler(handler)
    return logger

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
            self.logger.error(f"Error connecting to MySQL database '{database_name}': {e}")
            return None

    async def get_live_notification(self, guild_id, username):
        conn = await self.get_connection('specterdiscordbot')
        if conn is None:
            self.logger.error("Failed to get connection for database: specterdiscordbot")
            return None
        try:
            async with conn.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    "SELECT * FROM live_notifications WHERE guild_id = %s AND LOWER(username) = %s",
                    (guild_id, str(username).lower())
                )
                return await cursor.fetchone()
        except Exception as e:
            self.logger.error(f"Error fetching live notification: {e}")
            return None
        finally:
            if conn:
                conn.close()

    async def insert_live_notification(self, guild_id, username, stream_id, started_at, posted_at):
        conn = await self.get_connection('specterdiscordbot')
        if conn is None:
            self.logger.error("Failed to get connection for database: specterdiscordbot")
            return
        try:
            async with conn.cursor() as cursor:
                await cursor.execute(
                    """
                    INSERT INTO live_notifications (guild_id, username, stream_id, started_at, posted_at)
                    VALUES (%s, %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE stream_id = VALUES(stream_id), started_at = VALUES(started_at), posted_at = VALUES(posted_at)
                    """,
                    (guild_id, str(username).lower(), stream_id, started_at, posted_at)
                )
                await conn.commit()
        except Exception as e:
            self.logger.error(f"Error inserting/updating live notification: {e}")
        finally:
            if conn:
                conn.close()

    async def delete_live_notification(self, guild_id, username):
        conn = await self.get_connection('specterdiscordbot')
        if conn is None:
            self.logger.error("Failed to get connection for database: specterdiscordbot")
            return
        try:
            async with conn.cursor() as cursor:
                await cursor.execute(
                    "DELETE FROM live_notifications WHERE guild_id = %s AND LOWER(username) = %s",
                    (guild_id, str(username).lower())
                )
                await conn.commit()
        except Exception as e:
            self.logger.error(f"Error deleting live notification: {e}")
        finally:
            if conn:
                conn.close()

    async def fetchone(self, query, params=None, database_name='website', dict_cursor=False):
        conn = await self.get_connection(database_name)
        if conn is None:
            self.logger.error(f"Failed to get connection for database: {database_name}")
            return None
        try:
            if dict_cursor:
                async with conn.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute(query, params)
                    return await cursor.fetchone()
            else:
                async with conn.cursor() as cursor:
                    await cursor.execute(query, params)
                    return await cursor.fetchone()
        except Exception as e:
            self.logger.error(f"MySQL fetchone error: {e}")
            return None
        finally:
            if conn:
                conn.close()

    async def fetchall(self, query, params=None, database_name='website', dict_cursor=False):
        conn = await self.get_connection(database_name)
        if conn is None:
            self.logger.error(f"Failed to get connection for database: {database_name}")
            return []
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
            return []
        finally:
            if conn:
                conn.close()

    async def execute(self, query, params=None, database_name='website'):
        conn = await self.get_connection(database_name)
        if conn is None:
            self.logger.error(f"Failed to get connection for database: {database_name}")
            return 0
        try:
            async with conn.cursor() as cursor:
                await cursor.execute(query, params)
                await conn.commit()
                return cursor.rowcount
        except Exception as e:
            self.logger.error(f"MySQL execute error: {e}")
            return None
        finally:
            if conn:
                conn.close()

    async def track_message(self, bot_system='discordbot'):
        conn = await self.get_connection('website')
        if conn is None:
            self.logger.error("Failed to get connection for website database to track message")
            return
        try:
            async with conn.cursor(aiomysql.DictCursor) as cursor:
                # Get current record
                await cursor.execute(
                    "SELECT messages_sent, counted_since FROM bot_messages WHERE bot_system = %s",
                    (bot_system,)
                )
                record = await cursor.fetchone()
                if record is None:
                    # First entry for this bot system
                    await cursor.execute(
                        """INSERT INTO bot_messages (bot_system, counted_since, messages_sent, last_updated)
                           VALUES (%s, NOW(), 1, NOW())""",
                        (bot_system,)
                    )
                    self.logger.info(f"Created initial tracking record for {bot_system}")
                elif record['messages_sent'] == 0 or record['counted_since'] is None:
                    # First message being counted
                    await cursor.execute(
                        """UPDATE bot_messages 
                           SET counted_since = NOW(), messages_sent = 1, last_updated = NOW()
                           WHERE bot_system = %s""",
                        (bot_system,)
                    )
                    self.logger.debug(f"Initialized message counting for {bot_system}")
                else:
                    # Subsequent messages
                    await cursor.execute(
                        """UPDATE bot_messages 
                           SET messages_sent = messages_sent + 1, last_updated = NOW()
                           WHERE bot_system = %s""",
                        (bot_system,)
                    )
                await conn.commit()
        except Exception as e:
            self.logger.error(f"Error tracking message for {bot_system}: {e}")
        finally:
            if conn:
                conn.close()

# Setup websocket listener for global actions
class WebsocketListener:
    def __init__(self, bot, logger=None):
        self.bot = bot
        self.logger = logger
        self.specterSocket = None

    async def start(self):
        self.specterSocket = socketio.AsyncClient(logger=False, engineio_logger=False)
        admin_key = config.admin_key
        websocket_url = config.websocket_url
        # Register event handlers for the websocket client
        @self.specterSocket.event
        async def connect():
            self.logger.info("Connected to websocket server")
            await self.specterSocket.emit("REGISTER", {
                "code": admin_key,
                "global_listener": True,
                "channel": "Global",
                "name": "Discord Bot Global Listener"
            })
        # Disconnect event handler
        @self.specterSocket.event
        async def disconnect():
            self.logger.info("Disconnected from websocket server")
        # Success event handler
        @self.specterSocket.event
        async def SUCCESS(data):
            self.logger.info(f"Websocket registration successful: {data}")
        # Error event handler
        @self.specterSocket.event
        async def ERROR(data):
            self.logger.error(f"Websocket error: {data}")
        # Event handlers for Twitch Follows
        @self.specterSocket.event
        async def TWITCH_FOLLOW(data):
            await self.bot.handle_twitch_event("FOLLOW", data)
        # Event handlers for Twitch Subscription Events
        @self.specterSocket.event
        async def TWITCH_SUB(data):
            await self.bot.handle_twitch_event("SUBSCRIPTION", data)
        # Event handlers for Twitch Bits (Cheer) Events
        @self.specterSocket.event
        async def TWITCH_CHEER(data):
            await self.bot.handle_twitch_event("CHEER", data)
        # Event handlers for Twitch Raid Events
        @self.specterSocket.event
        async def TWITCH_RAID(data):
            await self.bot.handle_twitch_event("RAID", data)
        # Event handlers for Twitch Stream Online Events
        @self.specterSocket.event
        async def STREAM_ONLINE(data):
            await self.bot.handle_stream_event("ONLINE", data)
            try:
                if hasattr(self.bot, 'live_channel_manager') and self.bot.live_channel_manager:
                    asyncio.create_task(self.bot.live_channel_manager.handle_stream_online(data))
            except Exception as e:
                self.logger.debug(f"Error invoking LiveChannelManager.handle_stream_online: {e}")
        # Event handlers for Twitch Stream Offline Events
        @self.specterSocket.event
        async def STREAM_OFFLINE(data):
            await self.bot.handle_stream_event("OFFLINE", data)
            try:
                channel_code = data.get('channel_code') or data.get('code')
                if channel_code and hasattr(self.bot, 'live_channel_manager') and self.bot.live_channel_manager:
                    asyncio.create_task(self.bot.live_channel_manager.mark_offline(channel_code))
            except Exception as e:
                self.logger.debug(f"Error invoking LiveChannelManager.mark_offline: {e}")
        # Event handler for posting reaction roles message
        @self.specterSocket.on('POST_REACTION_ROLES_MESSAGE')
        async def post_reaction_roles_message(data):
            self.logger.info("POST_REACTION_ROLES_MESSAGE event handler called!")
            # Forward to ServerManagement cog if loaded
            server_mgmt = self.bot.get_cog('Server Management')
            if server_mgmt:
                self.logger.info("Forwarding to ServerManagement cog")
                await server_mgmt.post_reaction_roles_message(data)
            else:
                self.logger.warning("ServerManagement cog not loaded, cannot handle reaction roles message")
        # Event handler for posting rules message
        @self.specterSocket.on('POST_RULES_MESSAGE')
        async def post_rules_message(data):
            self.logger.info("POST_RULES_MESSAGE event handler called!")
            # Forward to ServerManagement cog if loaded
            server_mgmt = self.bot.get_cog('Server Management')
            if server_mgmt:
                self.logger.info("Forwarding to ServerManagement cog")
                await server_mgmt.post_rules_message(data)
            else:
                self.logger.warning("ServerManagement cog not loaded, cannot handle rules message")
        # Event handler for posting stream schedule message
        @self.specterSocket.on('POST_STREAM_SCHEDULE_MESSAGE')
        async def post_stream_schedule_message(data):
            self.logger.info("POST_STREAM_SCHEDULE_MESSAGE event handler called!")
            # Forward to ServerManagement cog if loaded
            server_mgmt = self.bot.get_cog('Server Management')
            if server_mgmt:
                self.logger.info("Forwarding to ServerManagement cog")
                await server_mgmt.post_stream_schedule_message(data)
            else:
                self.logger.warning("ServerManagement cog not loaded, cannot handle stream schedule message")
        # Log all other events generically
        @self.specterSocket.on('*')
        async def catch_all(event, data):
            if event and event.startswith('OBS_'):
                # Ignore OBS-related events since Discord bot doesn't need to process them
                return
            self.logger.info(f"Received websocket event '{event}': {data}")
        await self.specterSocket.connect(websocket_url)

# Channel mapping class to manage multiple Discord servers
class ChannelMapping:
    def __init__(self, logger=None):
        self.logger = logger
        self.mysql = MySQLHelper(logger)
        self.mappings = {}  # Memory cache: channel_code -> full mapping data
        self._refresh_task = None
        self._ready = asyncio.Event()
        asyncio.create_task(self._init_and_start_refresh())

    async def _init_and_start_refresh(self):
        await self.refresh_mappings()
        self._ready.set()
        self._refresh_task = asyncio.create_task(self._periodic_refresh())

    async def _periodic_refresh(self):
        while True:
            await asyncio.sleep(300)  # Refresh every 5 minutes
            await self.refresh_mappings()

    async def refresh_mappings(self):
        try:
            # Since we ensure schema exists, always try enhanced schema first
            rows = await self.mysql.fetchall(
                """SELECT channel_code, user_id, username, twitch_display_name, twitch_user_id,
                          guild_id, guild_name, channel_id, channel_name, 
                          stream_alert_channel_id, moderation_channel_id, alert_channel_id,
                          online_text, offline_text, is_active, event_count, last_event_type,
                          last_seen_at, created_at, updated_at
                   FROM channel_mappings WHERE is_active = 1""",
                database_name='specterdiscordbot', dict_cursor=True
            )
            self.mappings = {row['channel_code']: dict(row) for row in rows}
        except Exception as e:
            self.logger.error(f"Error loading channel mappings from DB: {e}")
            # Fallback to basic schema if there's still an issue
            try:
                rows = await self.mysql.fetchall(
                    "SELECT channel_code, guild_id, channel_id, channel_name FROM channel_mappings",
                    database_name='specterdiscordbot', dict_cursor=True
                )
                self.mappings = {row['channel_code']: dict(row) for row in rows}
            except Exception as e2:
                self.logger.error(f"Error loading channel mappings with basic schema: {e2}")
                self.mappings = {}

    async def get_mapping(self, channel_code):
        await self._ready.wait()
        if channel_code in self.mappings:
            # Update last_seen_at for active mappings
            await self._update_last_seen(channel_code)
            return self.mappings[channel_code]
        try:
            # Check if enhanced schema exists by checking for user_id column
            columns = await self._get_table_columns()
            if 'user_id' in columns:
                # Use enhanced schema
                row = await self.mysql.fetchone(
                    """SELECT channel_code, user_id, username, twitch_display_name, twitch_user_id,
                              guild_id, guild_name, channel_id, channel_name, 
                              stream_alert_channel_id, moderation_channel_id, alert_channel_id,
                              online_text, offline_text, is_active, event_count, last_event_type,
                              last_seen_at, created_at, updated_at
                       FROM channel_mappings WHERE channel_code = %s AND is_active = 1""",
                    (channel_code,), database_name='specterdiscordbot', dict_cursor=True
                )
            else:
                # Use basic schema
                row = await self.mysql.fetchone(
                    "SELECT channel_code, guild_id, channel_id, channel_name FROM channel_mappings WHERE channel_code = %s",
                    (channel_code,), database_name='specterdiscordbot', dict_cursor=True
                )
        except Exception as e:
            self.logger.debug(f"Database query failed for {channel_code}: {e}")
            row = None
        if row:
            self.mappings[channel_code] = dict(row)
            await self._update_last_seen(channel_code)
            self.logger.info(f"Loaded mapping for {channel_code} from database to memory cache")
            return self.mappings[channel_code]
        # If no mapping found in database, try to create one from users table
        try:
            mapping = await self._create_mapping_from_users_table(channel_code)
            if mapping:
                return mapping
        except Exception as e:
            self.logger.error(f"Error creating mapping from users table for {channel_code}: {e}")
        return None

    async def _create_mapping_from_users_table(self, channel_code):
        try:
            # Get user info from users table using api_key
            user_row = await self.mysql.fetchone(
                "SELECT id, username, twitch_display_name, twitch_user_id FROM users WHERE api_key = %s",
                (channel_code,), database_name='website', dict_cursor=True
            )
            if not user_row:
                self.logger.debug(f"No user found for channel_code: {channel_code}")
                return None
            # Get Discord info
            discord_row = await self.mysql.fetchone(
                """SELECT guild_id, live_channel_id, stream_alert_channel_id, 
                          moderation_channel_id, alert_channel_id, online_text, offline_text
                   FROM discord_users WHERE user_id = %s""",
                (user_row['id'],), database_name='website', dict_cursor=True
            )
            if not discord_row:
                self.logger.debug(f"No Discord setup found for user {user_row['username']}")
                return None
            # Create the mapping in database with available fields
            mapping_data = {
                'channel_code': channel_code,
                'user_id': user_row['id'],
                'username': user_row['username'],
                'twitch_display_name': user_row.get('twitch_display_name', user_row['username']),
                'twitch_user_id': user_row.get('twitch_user_id'),
                'guild_id': discord_row['guild_id'],
                'guild_name': None,
                'channel_id': discord_row['live_channel_id'],
                'channel_name': f"live-{user_row['username'].lower()}",
                'stream_alert_channel_id': discord_row.get('stream_alert_channel_id'),
                'moderation_channel_id': discord_row.get('moderation_channel_id'),
                'alert_channel_id': discord_row.get('alert_channel_id'),
                'online_text': discord_row.get('online_text'),
                'offline_text': discord_row.get('offline_text'),
            }
            await self._insert_mapping(mapping_data)
            self.logger.info(f"Auto-created mapping for {channel_code} from users table")
            return self.mappings.get(channel_code)
        except Exception as e:
            self.logger.error(f"Error creating mapping from users table for {channel_code}: {e}")
            return None

    async def _insert_mapping(self, mapping_data):
        try:
            # Ensure all required fields have safe default values
            safe_mapping_data = {
                'channel_code': mapping_data.get('channel_code'),
                'user_id': mapping_data.get('user_id'),
                'username': mapping_data.get('username'),
                'twitch_display_name': mapping_data.get('twitch_display_name'),
                'twitch_user_id': mapping_data.get('twitch_user_id'),
                'guild_id': mapping_data.get('guild_id'),
                'guild_name': mapping_data.get('guild_name'),
                'channel_id': mapping_data.get('channel_id'),
                'channel_name': mapping_data.get('channel_name'),
                'stream_alert_channel_id': mapping_data.get('stream_alert_channel_id'),
                'moderation_channel_id': mapping_data.get('moderation_channel_id'),
                'alert_channel_id': mapping_data.get('alert_channel_id'),
                'online_text': mapping_data.get('online_text'),
                'offline_text': mapping_data.get('offline_text')
            }
            
            # Use enhanced schema since we ensure it exists
            await self.mysql.execute(
                """REPLACE INTO channel_mappings 
                   (channel_code, user_id, username, twitch_display_name, twitch_user_id,
                    guild_id, guild_name, channel_id, channel_name, stream_alert_channel_id, 
                    moderation_channel_id, alert_channel_id, online_text, offline_text, 
                    is_active, event_count, last_seen_at, created_at, updated_at)
                   VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 1, 0, NOW(), NOW(), NOW())""",
                (
                    safe_mapping_data['channel_code'], safe_mapping_data['user_id'], safe_mapping_data['username'],
                    safe_mapping_data['twitch_display_name'], safe_mapping_data['twitch_user_id'],
                    safe_mapping_data['guild_id'], safe_mapping_data['guild_name'], safe_mapping_data['channel_id'], 
                    safe_mapping_data['channel_name'], safe_mapping_data['stream_alert_channel_id'], 
                    safe_mapping_data['moderation_channel_id'], safe_mapping_data['alert_channel_id'], 
                    safe_mapping_data['online_text'], safe_mapping_data['offline_text']
                ),
                database_name='specterdiscordbot'
            )
            self.logger.debug(f"Inserted mapping using enhanced schema for {mapping_data['channel_code']}")
            # Add to memory cache
            self.mappings[mapping_data['channel_code']] = mapping_data
        except Exception as e:
            self.logger.error(f"Error inserting mapping to DB: {e}")
            # Fallback to basic schema if enhanced schema fails
            try:
                # Provide safe defaults for basic schema
                fallback_channel_name = mapping_data.get('channel_name') or mapping_data.get('username') or 'Unknown'
                await self.mysql.execute(
                    "REPLACE INTO channel_mappings (channel_code, guild_id, channel_id, channel_name) VALUES (%s, %s, %s, %s)",
                    (mapping_data['channel_code'], mapping_data['guild_id'], mapping_data['channel_id'], 
                     fallback_channel_name),
                    database_name='specterdiscordbot'
                )
                self.logger.debug(f"Inserted mapping using basic schema fallback for {mapping_data['channel_code']}")
                # Add to memory cache
                self.mappings[mapping_data['channel_code']] = mapping_data
            except Exception as e2:
                self.logger.error(f"Error inserting mapping with basic schema fallback: {e2}")

    async def _update_last_seen(self, channel_code):
        try:
            await self.mysql.execute(
                "UPDATE channel_mappings SET last_seen_at = NOW() WHERE channel_code = %s",
                (channel_code,), database_name='specterdiscordbot'
            )
        except Exception:
            pass  # Ignore if there's an error

    async def increment_event_count(self, channel_code, event_type):
        try:
            await self.mysql.execute(
                """UPDATE channel_mappings 
                   SET event_count = event_count + 1, last_event_type = %s, last_seen_at = NOW() 
                   WHERE channel_code = %s""",
                (event_type, channel_code), database_name='specterdiscordbot'
            )
            # Update memory cache
            if channel_code in self.mappings:
                self.mappings[channel_code]['event_count'] = self.mappings[channel_code].get('event_count', 0) + 1
                self.mappings[channel_code]['last_event_type'] = event_type
                self.mappings[channel_code]['last_seen_at'] = datetime.now()
        except Exception:
            pass  # Ignore if there's an error

    async def update_discord_info(self, channel_code, guild_name=None, channel_name=None):
        try:
            updates = []
            params = []
            if guild_name:
                updates.append("guild_name = %s")
                params.append(guild_name)
            if channel_name:
                updates.append("channel_name = %s")
                params.append(channel_name)
            if updates:
                params.append(channel_code)
                await self.mysql.execute(
                    f"UPDATE channel_mappings SET {', '.join(updates)} WHERE channel_code = %s",
                    params, database_name='specterdiscordbot'
                )
                # Update memory cache
                if channel_code in self.mappings:
                    if guild_name:
                        self.mappings[channel_code]['guild_name'] = guild_name
                    if channel_name:
                        self.mappings[channel_code]['channel_name'] = channel_name
        except Exception as e:
            self.logger.error(f"Error updating Discord info for {channel_code}: {e}")

    async def get_fresh_channel_ids(self, guild_id):
        try:
            discord_info = await self.mysql.fetchone(
                "SELECT stream_alert_channel_id, moderation_channel_id, alert_channel_id FROM discord_users WHERE guild_id = %s",
                (guild_id,), database_name='website', dict_cursor=True)
            return discord_info if discord_info else {}
        except Exception as e:
            self.logger.error(f"Error getting fresh channel IDs for guild {guild_id}: {e}")
            return {}

    async def _check_table_exists(self):
        try:
            rows = await self.mysql.fetchall(
                "SHOW TABLES LIKE 'channel_mappings'", database_name='specterdiscordbot'
            )
            return len(rows) > 0
        except Exception as e:
            self.logger.debug(f"Could not check if table exists: {e}")
            return False

    async def _check_index_exists(self, index_name):
        try:
            rows = await self.mysql.fetchall(
                "SHOW INDEX FROM channel_mappings WHERE Key_name = %s",
                (index_name,), database_name='specterdiscordbot'
            )
            return len(rows) > 0
        except Exception as e:
            self.logger.debug(f"Could not check if index {index_name} exists: {e}")
            return False

    async def _get_table_columns(self):
        try:
            rows = await self.mysql.fetchall(
                "DESCRIBE channel_mappings", database_name='specterdiscordbot'
            )
            return [row[0] for row in rows]
        except Exception as e:
            self.logger.debug(f"Could not get table columns: {e}")
            return ['channel_code', 'guild_id', 'channel_id', 'channel_name']  # Basic schema

    async def add_mapping(self, channel_code, guild_id, channel_id, channel_name):
        mapping = await self.get_mapping(channel_code)
        if mapping:
            # Update existing mapping with any new Discord info
            await self.update_discord_info(channel_code, channel_name=channel_name)
        else:
            # Create enhanced mapping with all fields
            mapping_data = {
                'channel_code': channel_code,
                'user_id': None,
                'username': None,
                'twitch_display_name': None,
                'twitch_user_id': None,
                'guild_id': guild_id,
                'guild_name': None,
                'channel_id': channel_id,
                'channel_name': channel_name,
                'stream_alert_channel_id': None,
                'moderation_channel_id': None,
                'alert_channel_id': None,
                'online_text': None,
                'offline_text': None
            }
            await self._insert_mapping(mapping_data)

    async def remove_mapping(self, channel_code):
        try:
            # Try soft delete first
            await self.mysql.execute(
                "UPDATE channel_mappings SET is_active = 0 WHERE channel_code = %s",
                (channel_code,), database_name='specterdiscordbot'
            )
        except Exception:
            # Fallback to hard delete for basic schema
            await self.mysql.execute(
                "DELETE FROM channel_mappings WHERE channel_code = %s",
                (channel_code,), database_name='specterdiscordbot'
            )
        if channel_code in self.mappings:
            del self.mappings[channel_code]
        self.logger.info(f"Removed mapping for {channel_code}")
        return True

    async def populate_missing_mappings_from_users(self, bot):
        try:
            # Get all users with Discord setups that might be missing from channel_mappings
            users_rows = await self.mysql.fetchall(
                """SELECT DISTINCT u.api_key 
                   FROM users u 
                   JOIN discord_users du ON u.id = du.user_id 
                   WHERE u.api_key IS NOT NULL AND u.api_key != ''""",
                database_name='website', dict_cursor=True
            )
            created_count = 0
            for row in users_rows:
                channel_code = row['api_key']
                # Use get_mapping which will auto-create if needed
                mapping = await self.get_mapping(channel_code)
                if mapping and channel_code not in self.mappings:
                    created_count += 1
            if created_count > 0:
                self.logger.info(f"Auto-created {created_count} missing channel mappings")
        except Exception as e:
            self.logger.error(f"Error in populate_missing_mappings_from_users: {e}")

class LiveChannelManager:
    def __init__(self, bot, logger=None, check_interval=60):
        self.bot = bot
        self.logger = logger or logging.getLogger(self.__class__.__name__)
        self.mysql = MySQLHelper(self.logger)
        self.check_interval = check_interval
        self._validate_task = None

    async def mark_online(self, channel_code, username=None, twitch_user_id=None, stream_id=None, started_at=None, details=None):
        try:
            # Avoid persisting sensitive values such as channel_code/code inside the details
            if isinstance(details, dict):
                details_copy = dict(details)
                details_copy.pop('channel_code', None)
                details_copy.pop('code', None)
                details_json = json.dumps(details_copy)
            else:
                details_json = json.dumps(details) if details is not None else None
            # Normalize started_at ISO strings like '2025-11-17T05:56:01Z' into MySQL DATETIME format 'YYYY-MM-DD HH:MM:SS'
            if isinstance(started_at, str) and 'T' in started_at:
                try:
                    sa = started_at.replace('T', ' ')
                    sa = sa.replace('Z', '')
                    started_at = sa
                except Exception:
                    pass
            # Persist by username only (never persist channel_code). Username must be provided.
            if not username:
                self.logger.warning(f"mark_online called without username for channel_code {channel_code}; skipping persist")
                return
            # Check existing online state to avoid noisy logs when the stream is still the same
            existing = await self.get_online_stream_by_username(username)
            existing_stream_id = existing.get('stream_id') if existing else None
            existing_stream_id_str = str(existing_stream_id) if existing_stream_id is not None else None
            stream_id_str = str(stream_id) if stream_id is not None else None
            # If this is a new online record (was offline) or stream_id changed, persist and log
            if not existing or existing_stream_id_str != stream_id_str:
                await self.mysql.execute(
                    """
                    REPLACE INTO online_streams
                    (username, twitch_user_id, stream_id, started_at, last_checked, details)
                    VALUES (%s, %s, %s, %s, NOW(), %s)
                    """,
                    (str(username).lower(), twitch_user_id, stream_id, started_at, details_json),
                    database_name='specterdiscordbot'
                )
                if not existing:
                    self.logger.info(f"Marked online: {username} stream_id={stream_id}")
            else:
                # Existing row with same stream_id — update last_checked and details silently at DEBUG
                try:
                    await self.mysql.execute(
                        "UPDATE online_streams SET last_checked = NOW(), details = %s WHERE LOWER(username) = %s",
                        (details_json, str(username).lower()),
                        database_name='specterdiscordbot'
                    )
                except Exception as e:
                    # If update fails, fallback to replace to ensure state is present
                    await self.mysql.execute(
                        """
                        REPLACE INTO online_streams
                        (username, twitch_user_id, stream_id, started_at, last_checked, details)
                        VALUES (%s, %s, %s, %s, NOW(), %s)
                        """,
                        (str(username).lower(), twitch_user_id, stream_id, started_at, details_json),
                        database_name='specterdiscordbot'
                    )
        except Exception as e:
            self.logger.error(f"Error marking online for {channel_code}: {e}")

    async def mark_offline(self, channel_code):
        try:
            # Resolve the username for the channel_code and delete by username only
            uname = None
            try:
                row = await self.mysql.fetchone("SELECT username FROM channel_mappings WHERE channel_code = %s", (channel_code,), database_name='specterdiscordbot', dict_cursor=True)
                if row and row.get('username'):
                    uname = row.get('username')
            except Exception:
                pass
            if not uname:
                # Fall back to website users table
                try:
                    user_row = await self.mysql.fetchone("SELECT username FROM users WHERE api_key = %s", (channel_code,), database_name='website', dict_cursor=True)
                    if user_row and user_row.get('username'):
                        uname = user_row.get('username')
                except Exception:
                    pass
            if uname:
                # Only delete if it currently exists to avoid noisy logs
                existing = await self.get_online_stream_by_username(uname)
                if existing:
                    await self.mysql.execute(
                        "DELETE FROM online_streams WHERE LOWER(username) = %s",
                        (str(uname).lower(),),
                        database_name='specterdiscordbot'
                    )
                else:
                    self.logger.debug(f"mark_offline called but no online_streams entry found for username {uname}; skipping DB delete")
            else:
                # As a last resort, no username resolved; avoid deleting by channel_code to maintain privacy
                self.logger.debug(f"Could not resolve username for channel_code {channel_code} when marking offline; skipping DB delete to avoid storing channel_code")
            # Log by username if available to avoid persisting or exposing channel_code
            uname_log = uname if uname else f"channel_code not resolved"
            # Only log offline if we actually removed something
            if uname and existing:
                self.logger.info(f"Marked offline: {uname_log}")
            else:
                # Keep this quiet unless debugging is enabled
                self.logger.debug(f"No online state to mark offline for {uname_log}")
        except Exception as e:
            self.logger.error(f"Error marking offline for {channel_code}: {e}")

    async def clear_live_notifications_for_channel(self, channel_code, username=None):
        try:
            if not username:
                # Try to resolve username from channel_code
                try:
                    row = await self.mysql.fetchone("SELECT username FROM channel_mappings WHERE channel_code = %s", (channel_code,), database_name='specterdiscordbot', dict_cursor=True)
                    if row and row.get('username'):
                        username = row.get('username')
                except Exception:
                    pass
                if not username:
                    try:
                        user_row = await self.mysql.fetchone("SELECT username FROM users WHERE api_key = %s", (channel_code,), database_name='website', dict_cursor=True)
                        if user_row and user_row.get('username'):
                            username = user_row.get('username')
                    except Exception:
                        pass
            if username:
                try:
                    # Get all guilds where this user has a live_notification
                    all_notifications = await self.mysql.fetchall(
                        "SELECT guild_id FROM live_notifications WHERE LOWER(username) = %s",
                        (str(username).lower(),),
                        database_name='specterdiscordbot',
                        dict_cursor=True
                    )
                    if all_notifications:
                        # Delete all live_notifications for this username across all guilds
                        await self.mysql.execute(
                            "DELETE FROM live_notifications WHERE LOWER(username) = %s",
                            (str(username).lower(),),
                            database_name='specterdiscordbot'
                        )
                        self.logger.info(f"Cleared {len(all_notifications)} live_notifications for {username} on OFFLINE event")
                except Exception as e:
                    self.logger.debug(f"Error clearing live_notifications for {username}: {e}")
        except Exception as e:
            self.logger.error(f"Error in clear_live_notifications_for_channel for {channel_code}: {e}")

    async def get_online_stream(self, channel_code):
        try:
            # Resolve username from channel_code (without storing channel_code in DB)
            uname = None
            try:
                res = await self.mysql.fetchone("SELECT username FROM channel_mappings WHERE channel_code = %s", (channel_code,), database_name='specterdiscordbot', dict_cursor=True)
                if res and res.get('username'):
                    uname = res.get('username')
            except Exception:
                pass
            if not uname:
                try:
                    user_row = await self.mysql.fetchone("SELECT username FROM users WHERE api_key = %s", (channel_code,), database_name='website', dict_cursor=True)
                    if user_row and user_row.get('username'):
                        uname = user_row.get('username')
                except Exception:
                    pass
            if not uname:
                return None
            row = await self.mysql.fetchone(
                "SELECT * FROM online_streams WHERE LOWER(username) = %s",
                (str(uname).lower(),),
                database_name='specterdiscordbot',
                dict_cursor=True
            )
            return row
        except Exception as e:
            self.logger.error(f"Error fetching online stream for {channel_code}: {e}")
            return None

    async def get_all_online_streams(self):
        try:
            rows = await self.mysql.fetchall(
                "SELECT * FROM online_streams",
                (),
                database_name='specterdiscordbot',
                dict_cursor=True
            )
            return rows
        except Exception as e:
            self.logger.error(f"Error fetching all online streams: {e}")
            return []

    async def get_online_stream_by_username(self, username):
        try:
            if not username:
                return None
            row = await self.mysql.fetchone(
                "SELECT * FROM online_streams WHERE LOWER(username) = %s",
                (str(username).lower(),),
                database_name='specterdiscordbot',
                dict_cursor=True
            )
            return row
        except Exception as e:
            self.logger.error(f"Error fetching online stream by username {username}: {e}")
            return None

    async def mark_offline_by_username(self, username):
        try:
            if not username:
                return
            await self.mysql.execute(
                "DELETE FROM online_streams WHERE LOWER(username) = %s",
                (str(username).lower(),),
                database_name='specterdiscordbot'
            )
        except Exception as e:
            self.logger.error(f"Error marking offline by username {username}: {e}")

    async def _resolve_channel_code_for_username(self, username):
        try:
            if not username:
                return None
            uname = str(username).lower()
            if self.bot and hasattr(self.bot, 'channel_mapping') and self.bot.channel_mapping:
                mappings = self.bot.channel_mapping.mappings
                for code, entry in mappings.items():
                    entry_uname = entry.get('username')
                    if entry_uname and str(entry_uname).strip().lower() == uname:
                        return code
            # Fallback to DB lookup
            row = await self.mysql.fetchone("SELECT channel_code FROM channel_mappings WHERE LOWER(username) = %s", (uname,), database_name='specterdiscordbot', dict_cursor=True)
            if row and row.get('channel_code'):
                return row.get('channel_code')
            return None
        except Exception as e:
            self.logger.debug(f"Error resolving channel_code for username {username}: {e}")
            return None

    async def handle_stream_online(self, data):
        try:
            channel_code = data.get('channel_code') or data.get('code')
            if not channel_code:
                self.logger.warning("STREAM_ONLINE event without channel_code, ignoring")
                return
            username = data.get('twitch-username') or data.get('twitch_username') or None
            twitch_user_id = data.get('twitch-user-id') or data.get('twitch_user_id')
            stream_id = data.get('stream_id') or data.get('twitch-stream-id') or data.get('twitch_stream_id')
            started_at = data.get('started_at') or data.get('startedAt')
            # If we have no username or stream id, attempt a fallback check
            if not username or not stream_id:
                # Try to look up via website DB using the channel_code
                user_row = await self.mysql.fetchone(
                    "SELECT username FROM users WHERE api_key = %s",
                    (channel_code,), database_name='website', dict_cursor=True
                )
                if user_row:
                    username = username or user_row.get('username')
                # If still missing, perform API check by username if known
            # If we have a username and bot, verify via bot.get_stream_info
            if username and self.bot:
                # use bot.get_stream_info which returns (game_name, stream_title) or ('Unknown Game', 'No Title')
                try:
                    game, stream_title = await self.bot.get_stream_info(username)
                    details = {'game': game}
                    await self.mark_online(channel_code, username=username, twitch_user_id=twitch_user_id, stream_id=stream_id, started_at=started_at, details=details)
                    return
                except Exception:
                    # If get_stream_info fails, fallback to persisting websocket data
                    pass
            # If we reach here, persist what we have from websocket anyway
            details = data
            await self.mark_online(channel_code, username=username, twitch_user_id=twitch_user_id, stream_id=stream_id, started_at=started_at, details=details)
        except Exception as e:
            self.logger.error(f"Error handling STREAM_ONLINE: {e}")

    async def fallback_check_and_mark(self, channel_code):
        try:
            # Resolve username from website DB
            user_row = await self.mysql.fetchone("SELECT username FROM users WHERE api_key = %s", (channel_code,), database_name='website', dict_cursor=True)
            if not user_row:
                return
            username = user_row.get('username')
            if not username:
                return
            # Ask the bot to get stream info via Helix
            try:
                results = await self.twitch_get_streams_by_logins([str(username).lower()])
                if results:
                    stream_info = results[0]
                    # If this channel isn't already marked online, post to the channel's announcement
                    try:
                        existing = await self.get_online_stream_by_username(username)
                        if not existing:
                            self.logger.info(f"Fallback detected live stream for {username} on channel_code {channel_code}. Triggering announcement flow.")
                            # Build event data similar to the websocket STREAM_ONLINE payload
                            event_data = {
                                'channel_code': channel_code,
                                'twitch-username': username,
                                'twitch_user_id': stream_info.get('user_id'),
                                'twitch-stream-id': stream_info.get('id'),
                                'started_at': stream_info.get('started_at'),
                                'details': stream_info
                            }
                            # Call the bot's stream event handler to perform the same announcement logic
                            try:
                                if self.bot:
                                    await self.bot.handle_stream_event('ONLINE', event_data)
                            except Exception as e:
                                self.logger.debug(f"Error invoking bot.handle_stream_event for fallback announcement: {e}")
                    except Exception as e:
                        self.logger.debug(f"Error determining existing online_streams entry for username {username}: {e}")
                    # Save full info now so we persist the online state
                    await self.mark_online(
                        channel_code,
                        username=username,
                        twitch_user_id=stream_info.get('user_id'),
                        stream_id=stream_info.get('id'),
                        started_at=stream_info.get('started_at'),
                        details=stream_info
                    )
                else:
                    # Not live, remove any existing record
                    await self.mark_offline(channel_code)
                    # Also try to remove any live_notifications row (safely)
                    try:
                        row = await self.mysql.fetchone("SELECT guild_id, username FROM channel_mappings WHERE channel_code = %s", (channel_code,), database_name='specterdiscordbot', dict_cursor=True)
                        if row:
                            guild_id = row.get('guild_id')
                            username = row.get('username')
                            if guild_id and username:
                                await self.mysql.execute("DELETE FROM live_notifications WHERE guild_id = %s AND LOWER(username) = %s", (guild_id, str(username).lower()), database_name='specterdiscordbot')
                    except Exception:
                        pass
                return
            except Exception as e:
                # fallback to old get_stream_info if Helix call fails
                self.logger.debug(f"Helix call failed in fallback_check_and_mark: {e}")
                game, stream_title = await self.bot.get_stream_info(username)
                details = {'game': game}
                await self.mark_online(channel_code, username=username, twitch_user_id=None, stream_id=None, started_at=None, details=details)
            else:
                # Not live, remove any existing record
                await self.mark_offline(channel_code)
                # Also try to remove any live_notifications row (safely)
                try:
                    row = await self.mysql.fetchone("SELECT guild_id, username FROM channel_mappings WHERE channel_code = %s", (channel_code,), database_name='specterdiscordbot', dict_cursor=True)
                    if row:
                        guild_id = row.get('guild_id')
                        username = row.get('username')
                        if guild_id and username:
                            await self.mysql.execute("DELETE FROM live_notifications WHERE guild_id = %s AND LOWER(username) = %s", (guild_id, str(username).lower()), database_name='specterdiscordbot')
                except Exception:
                    pass
        except Exception as e:
            self.logger.error(f"Error in fallback_check_and_mark for {channel_code}: {e}")

    async def start_periodic_validation(self):
        if self._validate_task:
            return
        self.logger.info(f"Starting LiveChannelManager periodic validation every {self.check_interval} seconds")
        async def _task():
            while True:
                try:
                    records = await self.get_all_online_streams()
                    # Collect usernames for a batch check
                    batch = []
                    record_map = {}
                    for r in records:
                        uname = r.get('username')
                        if uname:
                            uname_key = str(uname).strip().lower()
                            if uname_key:
                                batch.append(uname_key)
                                record_map[uname_key] = r
                    # Process in chunks of 100
                    chunk_size = 100
                    self.logger.debug(f"Validating {len(batch)} online stream(s) in batches of {chunk_size}")
                    for i in range(0, len(batch), chunk_size):
                        chunk = batch[i:i+chunk_size]
                        head = await self.twitch_get_streams_by_logins(chunk)
                        live_logins = {s['user_login'].lower(): s for s in head} if head else {}
                        # Mark or unmark
                        for uname in chunk:
                            # The record_map maps username -> online_stream row
                            # We'll verify live presence and mark online/offline by username
                            if uname in live_logins:
                                s = live_logins[uname.lower()]
                                # Persist online state by username only
                                await self.mark_online(None, username=s.get('user_login'), twitch_user_id=s.get('user_id'), stream_id=s.get('id'), started_at=s.get('started_at'), details=s)
                            else:
                                # Only mark offline if the stream has actually ended
                                try:
                                    record = record_map.get(uname)
                                    if record:
                                        created_at = record.get('created_at')
                                        if created_at:
                                            time_diff = datetime.now(timezone.utc) - created_at.replace(tzinfo=timezone.utc) if created_at.tzinfo is None else datetime.now(timezone.utc) - created_at
                                            # Only mark offline if record is older than 2 minutes (allows for API delays)
                                            if time_diff < timedelta(minutes=2):
                                                self.logger.debug(f"Skipping offline mark for {uname}, record too recent ({time_diff.total_seconds()}s old)")
                                                continue
                                except Exception as e:
                                    self.logger.debug(f"Error checking record age for {uname}: {e}")
                                # Mark offline by username
                                await self.mark_offline_by_username(uname)
                    # Now also check channel owners that are not currently in online_streams but may have gone live
                    try:
                        # Get all mappings from channel_mapping
                        mappings = {}
                        if hasattr(self.bot, 'channel_mapping') and self.bot.channel_mapping:
                            # Ensure mapping cache is refreshed
                            mappings = self.bot.channel_mapping.mappings.copy()
                        check_candidates = []
                        check_map = {}
                        # Build a list of usernames for mappings not in current online_streams
                        for code, m in mappings.items():
                            uname = m.get('username')
                            if not uname:
                                continue
                            uname_key = str(uname).strip().lower()
                            if not uname_key:
                                continue
                            if uname_key not in record_map:
                                check_candidates.append(uname_key)
                                check_map[uname_key] = code
                        # Now batch check those candidate usernames
                        for j in range(0, len(check_candidates), chunk_size):
                            chunk2 = check_candidates[j:j+chunk_size]
                            try:
                                head = await self.twitch_get_streams_by_logins(chunk2)
                                if head:
                                    live_logins2 = {s['user_login'].lower(): s for s in head}
                                    for uname in chunk2:
                                        if uname in live_logins2:
                                            code = check_map.get(uname)
                                            s = live_logins2[uname]
                                            try:
                                                existing = await self.get_online_stream_by_username(uname)
                                                if not existing:
                                                    # Get guild_id from the mapping to check live_notifications
                                                    mapping = mappings.get(code, {})
                                                    guild_id = mapping.get('guild_id')
                                                    # Check if we've already posted a notification for this user in this guild
                                                    should_post = True
                                                    if guild_id:
                                                        try:
                                                            existing_notification = await self.mysql.fetchone(
                                                                "SELECT * FROM live_notifications WHERE guild_id = %s AND LOWER(username) = %s",
                                                                (guild_id, str(uname).lower()),
                                                                database_name='specterdiscordbot',
                                                                dict_cursor=True
                                                            )
                                                            if existing_notification:
                                                                self.logger.debug(f"Live notification already posted for {uname} in guild {guild_id}, skipping duplicate in periodic fallback")
                                                                should_post = False
                                                        except Exception as e:
                                                            self.logger.debug(f"Error checking live_notifications for periodic fallback {code}: {e}")
                                                    if should_post:
                                                        # Build event data and trigger posting via bot
                                                        event_data = {
                                                            'channel_code': code,
                                                            'twitch-username': s.get('user_login'),
                                                            'twitch_user_id': s.get('user_id'),
                                                            'twitch-stream-id': s.get('id'),
                                                            'started_at': s.get('started_at'),
                                                            'details': s
                                                        }
                                                        self.logger.info(f"Periodic fallback: detected live for mapping {code} ({uname}) — calling handle_stream_event")
                                                        try:
                                                            if self.bot:
                                                                await self.bot.handle_stream_event('ONLINE', event_data)
                                                        except Exception as inner_e:
                                                            self.logger.debug(f"Error triggering periodic fallback announcement for {code}: {inner_e}")
                                                        # Mark online (persist) after posting
                                                        await self.mark_online(code, username=s.get('user_login'), twitch_user_id=s.get('user_id'), stream_id=s.get('id'), started_at=s.get('started_at'), details=s)
                                                    else:
                                                        # Notification already exists, just update state without posting
                                                        await self.mark_online(code, username=s.get('user_login'), twitch_user_id=s.get('user_id'), stream_id=s.get('id'), started_at=s.get('started_at'), details=s)
                                            except Exception as inner_e:
                                                self.logger.debug(f"Error checking existing online stream for periodic fallback {code}: {inner_e}")
                            except Exception as e:
                                self.logger.debug(f"Error during periodic helix check for new live channels: {e}")
                            await asyncio.sleep(0.2)
                    except Exception as e:
                        self.logger.debug(f"Unexpected error in periodic fallback checks: {e}")
                except Exception as e:
                    self.logger.error(f"LiveChannelManager validation loop error: {e}")
                await asyncio.sleep(self.check_interval)
        self._validate_task = asyncio.create_task(_task())

    async def twitch_get_streams_by_logins(self, user_logins: list):
        if not user_logins:
            return []
        # Use config-borne client id and app bearer token
        client_id = config.twitch_client_id
        bearer = config.twitch_bearer_token
        if not client_id or not bearer:
            self.logger.debug("Missing twitch client id or bearer token for Helix API call")
            return []
        results = []
        # Normalize and ensure we only pass valid lower-case login names. Helix expects lowercase user_login values.
        user_logins = [str(u).strip().lower() for u in user_logins if u]
        # Helix supports up to 100 logins per request
        chunk_size = 100
        async with aiohttp.ClientSession() as session:
            for i in range(0, len(user_logins), chunk_size):
                chunk = user_logins[i:i+chunk_size]
                params = '&'.join([f"user_login={urllib.parse.quote_plus(u)}" for u in chunk])
                # Ensure we request only active live streams to reduce noise
                params = params + '&type=live&first=100'
                url = f"https://api.twitch.tv/helix/streams?{params}"
                headers = {
                    'Client-Id': client_id,
                    'Authorization': f"Bearer {bearer}"
                }
                try:
                    async with session.get(url, headers=headers, timeout=10) as resp:
                        if resp.status == 200:
                            data = await resp.json()
                            streams = data.get('data', [])
                            results.extend(streams)
                        else:
                            self.logger.debug(f"Twitch Helix streams request failed: {resp.status} - {await resp.text()}")
                except Exception as e:
                    self.logger.debug(f"Twitch Helix request exception: {e}")
                # small sleep to avoid bursting if many chunks
                await asyncio.sleep(0.5)
        return results

# Bot class
class BotOfTheSpecter(commands.Bot):
    def __init__(self, discord_token, discord_logger, **kwargs):
        intents = discord.Intents.default()
        intents.message_content = True
        intents.voice_states = True
        intents.members = True
        super().__init__(command_prefix="!", intents=intents, **kwargs)
        self.discord_token = discord_token
        self.logger = discord_logger
        self.typing_speed = config.typing_speed
        self.processed_messages_file = config.processed_messages_file
        self.version = config.bot_version
        self.pool = None  # Initialize the pool attribute
        self.channel_mapping = ChannelMapping(logger=discord_logger)
        # Live channel manager - track currently online streams
        self.live_channel_manager = LiveChannelManager(self, self.logger)
        self.mysql_helper = MySQLHelper(logger=discord_logger)
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
            'quote', 'ticket', 'setuptickets', 'settings',
            # Admin commands
            'checklinked'
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
                return False
        else:
            return False

    def _is_message_directed_at_bot(self, message) -> bool:
        # Check if bot is mentioned
        if self.user in message.mentions:
            return True
        # Check if this is a reply to the bot
        if message.reference:
            # Try to get the replied-to message
            replied_to = message.reference.resolved
            if replied_to and replied_to.author == self.user:
                return True
        return False

    async def on_ready(self):
        self.logger.info(f'Logged in as {self.user} (ID: {self.user.id})')
        self.logger.info(f'Bot version: {self.version}')
        # Update the global version file for dashboard display
        try:
            os.makedirs(os.path.dirname(config.discord_version_file), exist_ok=True)
            with open(config.discord_version_file, "w") as f:
                f.write(config.bot_version + os.linesep)
            self.logger.info(f"Updated Discord bot version file: {config.bot_version}")
        except Exception as e:
            self.logger.error(f"Failed to update Discord bot version file: {e}")
        # Auto-populate missing channel mappings from users table
        await self.channel_mapping.populate_missing_mappings_from_users(self)
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
        await self.add_cog(UtilityCog(self, self.logger))
        ticket_cog = TicketCog(self, self.logger)
        await self.add_cog(ticket_cog)
        await self.add_cog(VoiceCog(self, self.logger))
        await self.add_cog(StreamerPostingCog(self, self.logger))
        await self.add_cog(ServerManagement(self, self.logger))
        await self.add_cog(RoleHistoryCog(self, self.logger))
        await self.add_cog(RoleTrackingCog(self, self.logger))
        await self.add_cog(ServerRoleManagementCog(self, self.logger))
        await self.add_cog(MessageTrackingCog(self, self.logger))
        await self.add_cog(ModerationCog(self, self.logger))
        await self.add_cog(AdminCog(self, self.logger))
        await self.add_cog(UserTrackingCog(self, self.logger))
        self.logger.info("BotOfTheSpecter Discord Bot has started.")
        # Start websocket listener in the background
        self.websocket_listener = WebsocketListener(self, self.logger)
        asyncio.create_task(self.websocket_listener.start())
        # Start live channel validation background task
        asyncio.create_task(self.live_channel_manager.start_periodic_validation())

    async def setup_hook(self):
        # Sync the slash commands when the bot starts
        try:
            await self.tree.sync()
            self.logger.info("Successfully synced slash commands.")
        except Exception as e:
            self.logger.error(f"Error syncing slash commands: {e}")
        # Add error handler for command tree
        self.tree.on_error = self.on_app_command_error
        self.logger.info("Registered persistent button handlers for role and rules buttons")

    async def on_app_command_error(self, interaction: discord.Interaction, error: app_commands.AppCommandError):
        # Ignore CommandNotFound errors (commands from other bots)
        if isinstance(error, app_commands.CommandNotFound):
            return
        # Log other errors as usual
        self.logger.error(f"Error in application command: {str(error)}")

    async def on_interaction(self, interaction: discord.Interaction):
        # Check if this is a button click on a component (message button)
        if interaction.type == discord.InteractionType.component:
            custom_id = interaction.data.get('custom_id', '')
            # Handle persistent role buttons
            if custom_id.startswith('role_'):
                try:
                    # Defer immediately to prevent timeout
                    await interaction.response.defer(ephemeral=True)
                    role_id = int(custom_id.replace('role_', ''))
                    role = interaction.guild.get_role(role_id)
                    if not role:
                        await self.send_interaction_response(interaction, content="❌ Role not found.", ephemeral=True)
                        return
                    member = interaction.user
                    self.logger.info(f"[PERSISTENT_ROLE] Role button clicked by {member.name} for role {role.name}")
                    if role in member.roles:
                        await member.remove_roles(role, reason="Role button - user requested removal")
                        await self.send_interaction_response(interaction, content=f"✅ Removed role **{role.name}**", ephemeral=True)
                        self.logger.info(f"[PERSISTENT_ROLE] Role {role.name} removed from {member.name}")
                    else:
                        await member.add_roles(role, reason="Role button - user requested assignment")
                        await self.send_interaction_response(interaction, content=f"✅ Added role **{role.name}**", ephemeral=True)
                        self.logger.info(f"[PERSISTENT_ROLE] Role {role.name} added to {member.name}")
                except discord.Forbidden:
                    try:
                        if not interaction.response.is_done():
                            await interaction.response.defer(ephemeral=True)
                        await self.send_interaction_response(interaction, content="❌ I don't have permission to manage this role.", ephemeral=True)
                    except:
                        pass
                    self.logger.error(f"[PERSISTENT_ROLE] Permission denied when managing role")
                except Exception as e:
                    self.logger.error(f"[PERSISTENT_ROLE] Error: {e}")
                    try:
                        if not interaction.response.is_done():
                            await interaction.response.defer(ephemeral=True)
                        await self.send_interaction_response(interaction, content="❌ An error occurred. Please try again.", ephemeral=True)
                    except:
                        pass
            # Handle persistent rules buttons
            elif custom_id.startswith('rules_accept_'):
                try:
                    # Defer immediately to prevent timeout
                    await interaction.response.defer(ephemeral=True)
                    role_id = int(custom_id.replace('rules_accept_', ''))
                    role = interaction.guild.get_role(role_id)
                    if not role:
                        await self.send_interaction_response(interaction, content="❌ Role not found.", ephemeral=True)
                        return
                    user = interaction.user
                    self.logger.info(f"[PERSISTENT_RULES] Rules button clicked by {user.name} for role {role.name}")
                    if role in user.roles:
                        await self.send_interaction_response(interaction, content="✅ You have already accepted the rules!", ephemeral=True)
                    else:
                        await user.add_roles(role, reason="Accepted server rules via button")
                        await self.send_interaction_response(interaction, content="✅ Thank you for accepting the rules! You now have access to the server. 🎉", ephemeral=True)
                        self.logger.info(f"[PERSISTENT_RULES] Rules accepted by {user.name}")
                except discord.Forbidden:
                    try:
                        if not interaction.response.is_done():
                            await interaction.response.defer(ephemeral=True)
                        await self.send_interaction_response(interaction, content="❌ I don't have permission to assign this role.", ephemeral=True)
                    except:
                        pass
                    self.logger.error(f"[PERSISTENT_RULES] Permission denied when assigning role")
                except Exception as e:
                    self.logger.error(f"[PERSISTENT_RULES] Error: {e}")
                    try:
                        if not interaction.response.is_done():
                            await interaction.response.defer(ephemeral=True)
                        await self.send_interaction_response(interaction, content="❌ An error occurred. Please try again.", ephemeral=True)
                    except:
                        pass

    async def on_member_join(self, member):
        self.logger.info(f"Member joined: {member.name}#{member.discriminator} (ID: {member.id}) in guild {member.guild.name}")
        # Send websocket notification
        mysql_helper = MySQLHelper(self.logger)
        # Get the channel_code for this guild to use as API token for websocket notification
        channel_code_row = await mysql_helper.fetchone("SELECT channel_code FROM channel_mappings WHERE guild_id = %s", (str(member.guild.id),), database_name='specterdiscordbot', dict_cursor=True)
        if not channel_code_row:
            self.logger.warning(f"No channel_code found for guild {member.guild.id}, skipping websocket notification")
            return
        channel_code = channel_code_row['channel_code']
        # Prepare params for websocket server notification
        params = {
            'code': channel_code,
            'event': 'DISCORD_JOIN',
            'member': member.name
        }
        # Send notification to websocket server
        try:
            url = f"https://websocket.botofthespecter.com/notify?{urllib.parse.urlencode(params)}"
            async with aiohttp.ClientSession() as session:
                async with session.get(url) as response:
                    if response.status == 200:
                        self.logger.info(f"Successfully sent member join notification for {member.name} in guild {member.guild.name}")
                    else:
                        self.logger.error(f"Failed to send member join notification: HTTP {response.status}")
        except Exception as e:
            self.logger.error(f"Failed to send member join notification to websocket server: {e}")

    async def get_ai_response(self, user_message, channel_name, guild_id=None):
        try:
            # Ensure history directory exists
            try:
                Path(HISTORY_DIR).mkdir(parents=True, exist_ok=True)
            except Exception as e:
                self.logger.debug(f"Could not create history directory {HISTORY_DIR}: {e}")
            # Build messages list starting from system instructions (with small local cache)
            messages = []
            global _cached_instructions, _cached_instructions_time
            sys_instr = None
            try:
                now = time.time()
                if _cached_instructions and (now - _cached_instructions_time) < INSTRUCTIONS_CACHE_TTL:
                    sys_instr = _cached_instructions
                else:
                    self.logger.debug(f"Fetching system instructions from {OPENAI_INSTRUCTIONS_ENDPOINT}")
                    async with aiohttp.ClientSession() as session:
                        try:
                            async with session.get(OPENAI_INSTRUCTIONS_ENDPOINT + "?discord=true", timeout=10) as resp:
                                if resp.status == 200:
                                    sys_instr = await resp.json()
                                    _cached_instructions = sys_instr
                                    _cached_instructions_time = now
                                else:
                                    self.logger.error(f"Failed to fetch instructions: HTTP {resp.status}")
                        except Exception as e:
                            self.logger.error(f"HTTP error fetching instructions: {e}")
            except Exception as e:
                self.logger.error(f"Error while loading system instructions: {e}")
            # Accept several JSON shapes: list of messages, {system: '...'}, or {messages: [...]}
            try:
                if isinstance(sys_instr, list):
                    messages.extend(sys_instr)
                elif isinstance(sys_instr, dict):
                    if 'system' in sys_instr and isinstance(sys_instr['system'], str):
                        messages.append({'role': 'system', 'content': sys_instr['system']})
                    elif 'messages' in sys_instr and isinstance(sys_instr['messages'], list):
                        messages.extend(sys_instr['messages'])
            except Exception as e:
                self.logger.error(f"Failed to parse system instructions JSON: {e}")
            # Add a system message to tell the AI which Discord user/channel it's speaking to
            try:
                if guild_id:
                    user_context = f"You are speaking to Discord user in a server channel '{channel_name}'. Address them politely and keep responses concise and suitable for Discord chat."
                else:
                    user_context = f"You are speaking to Discord user in DM channel '{channel_name}'. Address them politely and keep responses concise and suitable for Discord chat."
                messages.append({'role': 'system', 'content': user_context})
                limiter = f"Important: Keep your final reply under {MAX_CHAT_MESSAGE_LENGTH} characters total so it fits in one Discord message. If you need to be concise, prefer short sentences and avoid long lists."
                messages.append({'role': 'system', 'content': limiter})
            except Exception as e:
                self.logger.debug(f"Failed to build user context for AI: {e}")
            # Load per-channel/user chat history and insert as prior messages
            # If guild_id is provided, use guild_id_user_id.json format, otherwise use channel_name.json
            try:
                if guild_id:
                    history_filename = f"{guild_id}_{channel_name}.json"
                else:
                    history_filename = f"{channel_name}.json"
                history_file = Path(HISTORY_DIR) / history_filename
                history = []
                if history_file.exists():
                    try:
                        with history_file.open('r', encoding='utf-8') as hf:
                            history = json.load(hf)
                    except Exception as e:
                        self.logger.debug(f"Failed to read history for {history_filename}: {e}")
                # History is expected to be a list of {role: 'user'|'assistant', content: '...'}
                if isinstance(history, list) and len(history) > 0:
                    recent = history[-8:]
                    for item in recent:
                        if isinstance(item, dict) and 'role' in item and 'content' in item:
                            messages.append({'role': item['role'], 'content': item['content']})
            except Exception as e:
                self.logger.debug(f"Error loading chat history: {e}")
            # Append the current user message as the latest user turn
            messages.append({'role': 'user', 'content': user_message})
            # Ensure openai client is available
            if not openai_client:
                self.logger.error("OpenAI client is not configured (missing OPENAI_KEY)")
                return ["AI service is not configured. Please contact the bot administrator."]
            # Call OpenAI chat completion via AsyncOpenAI client
            try:
                self.logger.debug("Calling OpenAI chat completion from specterdiscord.get_ai_response")
                chat_client = getattr(openai_client, 'chat', None)
                ai_text = None
                resp = None
                if chat_client and hasattr(chat_client, 'completions') and hasattr(chat_client.completions, 'create'):
                    resp = await chat_client.completions.create(model="gpt-5-nano", messages=messages)
                    if isinstance(resp, dict) and 'choices' in resp and len(resp['choices']) > 0:
                        choice = resp['choices'][0]
                        if 'message' in choice and 'content' in choice['message']:
                            ai_text = choice['message']['content']
                        elif 'text' in choice:
                            ai_text = choice['text']
                    else:
                        choices = getattr(resp, 'choices', None)
                        if choices and len(choices) > 0:
                            ai_text = getattr(choices[0].message, 'content', None)
                elif hasattr(openai_client, 'chat_completions') and hasattr(openai_client.chat_completions, 'create'):
                    resp = await openai_client.chat_completions.create(model="gpt-5-nano", messages=messages)
                    if isinstance(resp, dict) and 'choices' in resp and len(resp['choices']) > 0:
                        ai_text = resp['choices'][0].get('message', {}).get('content') or resp['choices'][0].get('text')
                    else:
                        choices = getattr(resp, 'choices', None)
                        if choices and len(choices) > 0:
                            ai_text = getattr(choices[0].message, 'content', None)
                else:
                    self.logger.error("No compatible chat completions method found on openai_client")
                    return ["AI chat completions API is not available."]
            except Exception as e:
                self.logger.error(f"Error calling chat completion API: {e}")
                return ["An error occurred while contacting the AI chat service."]
            if not ai_text:
                self.logger.error(f"Chat completion returned no usable text: {resp}")
                return ["The AI chat service returned an unexpected response."]
            self.logger.info("AI response received from chat completion")
            # Persist the user message and AI response to per-user history
            try:
                if guild_id:
                    history_filename = f"{guild_id}_{channel_name}.json"
                else:
                    history_filename = f"{channel_name}.json"
                history_file = Path(HISTORY_DIR) / history_filename
                history = []
                if history_file.exists():
                    try:
                        with history_file.open('r', encoding='utf-8') as hf:
                            history = json.load(hf)
                    except Exception as e:
                        self.logger.debug(f"Failed to read existing history for append {history_filename}: {e}")
                history.append({'role': 'user', 'content': user_message})
                history.append({'role': 'assistant', 'content': ai_text})
                if len(history) > 200:
                    history = history[-200:]
                try:
                    with history_file.open('w', encoding='utf-8') as hf:
                        json.dump(history, hf, ensure_ascii=False, indent=2)
                except Exception as e:
                    self.logger.debug(f"Failed to write history for {history_filename}: {e}")
            except Exception as e:
                self.logger.debug(f"Error while persisting chat history: {e}")
            # Split into chunks that fit within Discord's message limit and return
            try:
                chunks = split_message_preserve_markdown(ai_text, MAX_CHAT_MESSAGE_LENGTH)
                return chunks
            except Exception as e:
                self.logger.error(f"Error chunking AI response: {e}")
                return [ai_text]
        except Exception as e:
            self.logger.error(f"Unexpected error in get_ai_response: {e}")
            return ["Sorry, I encountered an error processing your request."]

    async def get_ai_response_stream(self, user_message, channel_name, guild_id=None):
        try:
            # Ensure history directory exists
            try:
                Path(HISTORY_DIR).mkdir(parents=True, exist_ok=True)
            except Exception as e:
                self.logger.debug(f"Could not create history directory {HISTORY_DIR}: {e}")
            # Build messages list starting from system instructions (with small local cache)
            messages = []
            global _cached_instructions, _cached_instructions_time
            sys_instr = None
            try:
                now = time.time()
                if _cached_instructions and (now - _cached_instructions_time) < INSTRUCTIONS_CACHE_TTL:
                    sys_instr = _cached_instructions
                else:
                    self.logger.debug(f"Fetching system instructions from {OPENAI_INSTRUCTIONS_ENDPOINT} (discord)")
                    async with aiohttp.ClientSession() as session:
                        try:
                            async with session.get(OPENAI_INSTRUCTIONS_ENDPOINT + "?discord=true", timeout=10) as resp:
                                if resp.status == 200:
                                    sys_instr = await resp.json()
                                    _cached_instructions = sys_instr
                                    _cached_instructions_time = now
                                else:
                                    self.logger.error(f"Failed to fetch instructions: HTTP {resp.status}")
                        except Exception as e:
                            self.logger.error(f"HTTP error fetching instructions: {e}")
            except Exception as e:
                self.logger.error(f"Error while loading system instructions: {e}")
            # Accept several JSON shapes: list of messages, {system: '...'}, or {messages: [...]}
            try:
                if isinstance(sys_instr, list):
                    messages.extend(sys_instr)
                elif isinstance(sys_instr, dict):
                    if 'system' in sys_instr and isinstance(sys_instr['system'], str):
                        messages.append({'role': 'system', 'content': sys_instr['system']})
                    elif 'messages' in sys_instr and isinstance(sys_instr['messages'], list):
                        messages.extend(sys_instr['messages'])
            except Exception as e:
                self.logger.error(f"Failed to parse system instructions JSON: {e}")
            # Add a system message to tell the AI which Discord user/channel it's speaking to
            try:
                if guild_id:
                    user_context = f"You are speaking to Discord user in a server channel '{channel_name}'. Address them politely and keep responses concise and suitable for Discord chat."
                else:
                    user_context = f"You are speaking to Discord user in DM channel '{channel_name}'. Address them politely and keep responses concise and suitable for Discord chat."
                messages.append({'role': 'system', 'content': user_context})
                limiter = f"Important: Keep your final reply under {MAX_CHAT_MESSAGE_LENGTH} characters total so it fits in one Discord message. If you need to be concise, prefer short sentences and avoid long lists."
                messages.append({'role': 'system', 'content': limiter})
            except Exception as e:
                self.logger.debug(f"Failed to build user context for AI stream: {e}")
            # Load per-channel/user chat history and insert as prior messages
            try:
                if guild_id:
                    history_filename = f"{guild_id}_{channel_name}.json"
                else:
                    history_filename = f"{channel_name}.json"
                history_file = Path(HISTORY_DIR) / history_filename
                history = []
                if history_file.exists():
                    try:
                        with history_file.open('r', encoding='utf-8') as hf:
                            history = json.load(hf)
                    except Exception as e:
                        self.logger.debug(f"Failed to read history for {history_filename}: {e}")
                # History is expected to be a list of {role: 'user'|'assistant', content: '...'}
                if isinstance(history, list) and len(history) > 0:
                    recent = history[-8:]
                    for item in recent:
                        if isinstance(item, dict) and 'role' in item and 'content' in item:
                            messages.append({'role': item['role'], 'content': item['content']})
            except Exception as e:
                self.logger.debug(f"Error loading chat history (stream): {e}")
            # Append the current user message as the latest user turn
            messages.append({'role': 'user', 'content': user_message})
            # Ensure openai client is available
            if not openai_client:
                self.logger.error("OpenAI client is not configured (missing OPENAI_KEY)")
                yield "AI service is not configured. Please contact the bot administrator."
                return
            # Try streaming from the AsyncOpenAI client if supported
            try:
                chat_client = getattr(openai_client, 'chat', None)
                buffer = ""
                streamed = False
                if chat_client and hasattr(chat_client, 'completions') and hasattr(chat_client.completions, 'stream'):
                    self.logger.debug("Using openai_client.chat.completions.stream for streaming response")
                    try:
                        async with chat_client.completions.stream(model="gpt-5-nano", messages=messages) as stream:
                            async for chunk in stream:
                                # chunk shapes vary; try common fields
                                delta = ""
                                try:
                                    if isinstance(chunk, dict):
                                        choice = chunk.get('choices', [None])[0]
                                        if choice:
                                            delta = (choice.get('delta') or {}).get('content') or choice.get('text', '')
                                    else:
                                        # Some clients return objects with attributes
                                        choice = getattr(chunk, 'choices', None)
                                        if choice and len(choice) > 0:
                                            delta = getattr(choice[0].delta, 'content', None) or getattr(choice[0], 'text', '')
                                except Exception:
                                    pass
                                if delta:
                                    buffer += delta
                                    streamed = True
                                    yield delta
                    except Exception as e:
                        self.logger.debug(f"Stream context failed for chat.completions.stream: {e}")
                elif hasattr(openai_client, 'chat_completions') and hasattr(openai_client.chat_completions, 'stream'):
                    self.logger.debug("Using openai_client.chat_completions.stream for streaming response")
                    try:
                        async with openai_client.chat_completions.stream(model="gpt-5-nano", messages=messages) as stream:
                            async for chunk in stream:
                                delta = ""
                                try:
                                    if isinstance(chunk, dict):
                                        choice = chunk.get('choices', [None])[0]
                                        if choice:
                                            delta = (choice.get('delta') or {}).get('content') or choice.get('text', '')
                                    else:
                                        choice = getattr(chunk, 'choices', None)
                                        if choice and len(choice) > 0:
                                            delta = getattr(choice[0].delta, 'content', None) or getattr(choice[0], 'text', '')
                                except Exception:
                                    pass
                                if delta:
                                    buffer += delta
                                    streamed = True
                                    yield delta
                    except Exception as e:
                        self.logger.debug(f"Stream context failed for chat_completions.stream: {e}")
                # If streaming wasn't used or yielded nothing, fall back to the non-streaming path
                if not streamed:
                    self.logger.debug("Streaming not available or yielded no content; falling back to non-stream completion")
                    # Attempt the same completion styles as the non-streaming function
                    resp = None
                    ai_text = None
                    if chat_client and hasattr(chat_client, 'completions') and hasattr(chat_client.completions, 'create'):
                        resp = await chat_client.completions.create(model="gpt-5-nano", messages=messages)
                        if isinstance(resp, dict) and 'choices' in resp and len(resp['choices']) > 0:
                            choice = resp['choices'][0]
                            if 'message' in choice and 'content' in choice['message']:
                                ai_text = choice['message']['content']
                            elif 'text' in choice:
                                ai_text = choice['text']
                        else:
                            choices = getattr(resp, 'choices', None)
                            if choices and len(choices) > 0:
                                ai_text = getattr(choices[0].message, 'content', None)
                    elif hasattr(openai_client, 'chat_completions') and hasattr(openai_client.chat_completions, 'create'):
                        resp = await openai_client.chat_completions.create(model="gpt-5-nano", messages=messages)
                        if isinstance(resp, dict) and 'choices' in resp and len(resp['choices']) > 0:
                            ai_text = resp['choices'][0].get('message', {}).get('content') or resp['choices'][0].get('text')
                        else:
                            choices = getattr(resp, 'choices', None)
                            if choices and len(choices) > 0:
                                ai_text = getattr(choices[0].message, 'content', None)
                    if not ai_text:
                        self.logger.error(f"Chat completion (fallback) returned no usable text: {resp}")
                        yield "The AI chat service returned an unexpected response."
                    else:
                        buffer = ai_text
                        yield ai_text
                # Persist the user message and final AI response to per-user history
                try:
                    if buffer:
                        if guild_id:
                            history_filename = f"{guild_id}_{channel_name}.json"
                        else:
                            history_filename = f"{channel_name}.json"
                        history_file = Path(HISTORY_DIR) / history_filename
                        history = []
                        if history_file.exists():
                            try:
                                with history_file.open('r', encoding='utf-8') as hf:
                                    history = json.load(hf)
                            except Exception as e:
                                self.logger.debug(f"Failed to read existing history for append {history_filename}: {e}")
                        history.append({'role': 'user', 'content': user_message})
                        history.append({'role': 'assistant', 'content': buffer})
                        if len(history) > 200:
                            history = history[-200:]
                        try:
                            with history_file.open('w', encoding='utf-8') as hf:
                                json.dump(history, hf, ensure_ascii=False, indent=2)
                        except Exception as e:
                            self.logger.debug(f"Failed to write history for {history_filename}: {e}")
                        else:
                            try:
                                self.logger.info(f"Persisted AI history for {history_filename} (len={len(buffer)})")
                                full_response = buffer.replace(os.linesep, ' ')
                                self.logger.info(f"Final AI response for {history_filename}: {full_response}")
                            except Exception:
                                pass
                except Exception as e:
                    self.logger.debug(f"Error while persisting streamed chat history: {e}")
            except Exception as e:
                self.logger.error(f"Error streaming chat completion: {e}")
                yield "An error occurred while contacting the AI chat service."
        except Exception as e:
            self.logger.error(f"Unexpected error in get_ai_response_stream: {e}")
            yield "Sorry, I encountered an error processing your request."

    async def on_message(self, message):
        # Ignore bot's own messages
        if message.author == self.user:
            return
        # Process the message if it's in a DM channel
        if isinstance(message.channel, discord.DMChannel):
            # Check if this is a command first
            if message.content.startswith("!"):
                # Process as a command
                await self.process_commands(message)
                return
            # Not a command, process as AI chat
            # Determine the "channel_name" based on the source of the message
            channel = message.channel
            channel_name = str(message.author.id)  # Use user ID for DMs
            # Use the message ID to track if it's already been processed
            message_id = str(message.id)
            try:
                with open(self.processed_messages_file, 'r') as file:
                    processed_messages = file.read().splitlines()
                if message_id in processed_messages:
                    self.logger.info(f"Message ID {message_id} has already been processed. Skipping.")
                    return
            except FileNotFoundError:
                self.logger.info("Processed messages file not found, creating new one")
                processed_messages = []
            try:
                # If the message includes attachments, attempt to fetch small text attachments
                attachment_info = ""
                try:
                    if message.attachments:
                        att_texts = []
                        for att in message.attachments:
                            try:
                                # Skip very large files
                                if getattr(att, 'size', 0) and att.size > 200_000:  # 200KB
                                    att_texts.append(f"[Attachment: {att.filename} - skipped (size {att.size} bytes)]")
                                    continue
                                # Try to download and decode as text
                                async with aiohttp.ClientSession() as session:
                                    async with session.get(att.url, timeout=10) as resp:
                                        if resp.status == 200:
                                            try:
                                                txt = await resp.text()
                                                # Limit included text
                                                if len(txt) > 10000:
                                                    txt = txt[:10000] + "\n...[truncated]"
                                                att_texts.append(f"[Attachment: {att.filename}]\n" + txt)
                                            except UnicodeDecodeError:
                                                att_texts.append(f"[Attachment: {att.filename} - binary content omitted]")
                                        else:
                                            att_texts.append(f"[Attachment: {att.filename} - failed to fetch (HTTP {resp.status})]")
                            except Exception:
                                att_texts.append(f"[Attachment: {getattr(att, 'filename', 'unknown')} - error fetching]")
                        if att_texts:
                            attachment_info = "\n\n" + os.linesep.join(att_texts)
                except Exception:
                    # Non-fatal: if attachments handling fails, continue with original message
                    attachment_info = ""
                user_input_for_ai = (message.content or "") + attachment_info
                # Start typing immediately and stream the AI response so the user sees typing right away
                self.logger.info(f"Processing message from {message.author}: {message.content} (streaming)")
                async with channel.typing():
                    buffer = ""
                    try:
                        async for delta in self.get_ai_response_stream(user_input_for_ai, channel_name):
                            if not delta:
                                continue
                            buffer += delta
                        # Streaming finished. Send final content in one or more messages (chunked).
                        if buffer:
                            chunks = split_message_preserve_markdown(buffer, MAX_CHAT_MESSAGE_LENGTH)
                            for chunk in chunks:
                                # Simulate typing delay proportional to length for UX
                                typing_delay = len(chunk) / self.typing_speed
                                await asyncio.sleep(min(typing_delay, 3))
                                await message.author.send(chunk)
                                await self.mysql_helper.track_message('discordbot')
                        else:
                            await message.author.send("The AI did not return any text.")
                            await self.mysql_helper.track_message('discordbot')
                    except Exception as stream_exc:
                        self.logger.error(f"Streaming failed: {stream_exc}; falling back to non-streaming send")
                        # Fallback: call the non-streaming API and send chunks as before
                        ai_responses = await self.get_ai_response(message.content, channel_name)
                        if ai_responses:
                            for ai_response in ai_responses:
                                if ai_response:
                                    typing_delay = len(ai_response) / self.typing_speed
                                    await asyncio.sleep(typing_delay)
                                    await message.author.send(ai_response)
                                    await self.mysql_helper.track_message('discordbot')
                                else:
                                    self.logger.error("AI response chunk was empty, not sending.")
                    except Exception as stream_exc:
                        self.logger.error(f"Streaming failed: {stream_exc}; falling back to non-streaming send")
                        # Fallback: call the non-streaming API and send chunks as before
                        ai_responses = await self.get_ai_response(message.content, channel_name)
                        if ai_responses:
                            for ai_response in ai_responses:
                                if ai_response:
                                    typing_delay = len(ai_response) / self.typing_speed
                                    await asyncio.sleep(typing_delay)
                                    await message.author.send(ai_response)
                                    await self.mysql_helper.track_message('discordbot')
                                else:
                                    self.logger.error("AI response chunk was empty, not sending.")
            except discord.HTTPException as e:
                self.logger.error(f"Failed to send message: {e}")
            except Exception as e:
                self.logger.error(f"Unexpected error in on_message: {e}")
            # Mark the message as processed by appending the message ID to the file
            with open(self.processed_messages_file, 'a') as file:
                file.write(message_id + os.linesep)
        # If the message is in a server channel (text or voice), check for tickets first, then custom commands
        elif isinstance(message.channel, (discord.TextChannel, discord.VoiceChannel)):
            try:
                # TICKET SYSTEM LOGIC - Check if this is a ticket-info channel
                ticket_cog = self.get_cog('Tickets')
                if ticket_cog:
                    settings = await ticket_cog.get_settings(message.guild.id)
                    if settings and message.channel.id == settings['info_channel_id']:
                        # Check if message is a ticket command
                        is_ticket_command = message.content.startswith('!ticket')
                        if not is_ticket_command:
                            # Delete non-ticket messages
                            await message.delete()
                            # Send a temporary warning message
                            warning = await message.channel.send(
                                f"{message.author.mention} This channel is for ticket commands only. "
                                "Please use `!ticket create` to open a ticket."
                            )
                            await self.mysql_helper.track_message('discordbot')
                            # Set a delay before deleting the warning message
                            await asyncio.sleep(10)  # Wait for 10 seconds
                            await warning.delete()  # Delete the warning message after the delay
                            self.logger.info(f"Deleted non-ticket message from {message.author} in ticket-info channel")
                            return
            except Exception as e:
                self.logger.error(f"Error in ticket-info message watcher: {e}")
            # AI CHAT LOGIC - Check if message is directed at bot (mention or reply)
            if self._is_message_directed_at_bot(message):
                try:
                    # Use guild_id and user_id for chat history file
                    channel_identifier = str(message.author.id)
                    guild_id = message.guild.id
                    # Track if message was already processed to avoid duplicates
                    message_id = str(message.id)
                    try:
                        with open(self.processed_messages_file, 'r') as file:
                            processed_messages = file.read().splitlines()
                        if message_id in processed_messages:
                            self.logger.info(f"Message ID {message_id} has already been processed. Skipping.")
                            return
                    except FileNotFoundError:
                        processed_messages = []
                    user_input_for_ai = message.content or ""
                    self.logger.info(f"Processing AI request from {message.author} in server {message.guild.name}: {message.content} (streaming)")
                    # Show typing indicator
                    async with message.channel.typing():
                        buffer = ""
                        try:
                            # Stream the AI response
                            async for delta in self.get_ai_response_stream(user_input_for_ai, channel_identifier, guild_id=guild_id):
                                if not delta:
                                    continue
                                buffer += delta
                            # Streaming finished. Send final content in one or more messages (chunked), replying to the user
                            if buffer:
                                chunks = split_message_preserve_markdown(buffer, MAX_CHAT_MESSAGE_LENGTH)
                                for chunk in chunks:
                                    # Simulate typing delay proportional to length for UX
                                    typing_delay = len(chunk) / self.typing_speed
                                    await asyncio.sleep(min(typing_delay, 3))
                                    # Reply to the user's message so it's clear who it's for
                                    await message.reply(chunk, mention_author=False)
                            else:
                                await message.reply("The AI did not return any text.", mention_author=False)
                        except Exception as stream_exc:
                            self.logger.error(f"Streaming failed: {stream_exc}; falling back to non-streaming send")
                            # Fallback: call the non-streaming API and send chunks as before
                            ai_responses = await self.get_ai_response(user_input_for_ai, channel_identifier, guild_id=guild_id)
                            if ai_responses:
                                for ai_response in ai_responses:
                                    if ai_response:
                                        typing_delay = len(ai_response) / self.typing_speed
                                        await asyncio.sleep(typing_delay)
                                        await message.reply(ai_response, mention_author=False)
                                    else:
                                        self.logger.error("AI response chunk was empty, not sending.")
                    # Mark the message as processed
                    with open(self.processed_messages_file, 'a') as file:
                        file.write(message_id + os.linesep)
                    return
                except discord.HTTPException as e:
                    self.logger.error(f"Failed to send message: {e}")
                    try:
                        await message.reply("Sorry, I failed to send my response. Please try again.", mention_author=False)
                    except Exception:
                        pass
                except Exception as e:
                    self.logger.error(f"Unexpected error in AI chat for server: {e}")
                    try:
                        await message.reply("Sorry, an error occurred processing your request.", mention_author=False)
                    except Exception:
                        pass
                return  # Don't process further if we handled an AI request
            try:
                # Auto-save logic for ticket channels - delegate to TicketCog
                if hasattr(message.channel, "name") and message.channel.name.startswith("ticket-"):
                    if not message.content.startswith("!"):
                        ticket_cog = self.get_cog('Tickets')
                        if ticket_cog:
                            pass
                        return
            except Exception as e:
                self.logger.error(f"Error in ticket auto-save logic: {e}")
            if message.content.startswith("!"):
                command_text = message.content[1:].strip().lower()
                command_name = command_text.split()[0] if command_text else ""
                self.logger.info(f"Received command: '{command_name}' from user {message.author} in guild {message.guild.name}")
                # Prioritize internal/built-in commands before consulting custom commands
                if command_name in self.internal_commands:
                    self.logger.info(f"Processing internal command: {command_name}")
                    await self.process_commands(message)
                    return
                # If a built-in command is registered with this bot, process it directly.
                cmd_obj = self.get_command(command_name)
                if cmd_obj:
                    self.logger.info(f"Detected built-in command: {command_name}, processing built-in command")
                    await self.process_commands(message)
                    return
                # Not an internal/built-in command, check if it's a custom command for this guild
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
                        await self.mysql_helper.track_message('discordbot')
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
                                await self.mysql_helper.track_message('discordbot')
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

    async def _send_message_with_fallback(self, channel, embed=None, fallback_text="", content=None, logger_context=""):
        try:
            if embed:
                # If both content and embed provided, include both
                if content:
                    await channel.send(content=content, embed=embed)
                else:
                    await channel.send(embed=embed)
            else:
                # Prefer explicit content over fallback_text
                await channel.send(content if content is not None else fallback_text)
            # Track the message
            await self.mysql_helper.track_message('discordbot')
            return True
        except discord.Forbidden:
            self.logger.error(f"Missing permissions to send {logger_context} message in #{channel.name} (ID: {channel.id})")
            # Try sending as plain text if embed failed
            fallback = content if content is not None else fallback_text
            if fallback:
                try:
                    await channel.send(fallback)
                    self.logger.info(f"Sent {logger_context} as plain text fallback in #{channel.name}")
                    # Track the fallback message
                    await self.mysql_helper.track_message('discordbot')
                    return True
                except Exception as fallback_error:
                    self.logger.error(f"Fallback text message also failed in #{channel.name}: {fallback_error}")
            return False
        except Exception as e:
            # Channel.name may not exist for DMChannel etc; guard log formatting
            chan_name = getattr(channel, 'name', str(channel))
            self.logger.error(f"Failed to send {logger_context} message to #{chan_name}: {type(e).__name__}: {e}")
            return False

    async def send_interaction_response(self, interaction: discord.Interaction, content: str = None, embed: discord.Embed = None, ephemeral: bool = False):
        try:
            await interaction.followup.send(content=content, embed=embed, ephemeral=ephemeral)
            # Track the message
            await self.mysql_helper.track_message('discordbot')
            return True
        except Exception as e:
            self.logger.error(f"Failed to send interaction response: {type(e).__name__}: {e}")
            return False

    async def handle_twitch_event(self, event_type, data):
        channel_code = data.get("channel_code", "unknown")
        mapping = await self.channel_mapping.get_mapping(channel_code)
        if not mapping:
            # Enhanced error message with debugging info
            total_mappings = len(self.channel_mapping.mappings)
            self.logger.warning(f"No Discord mapping found for channel code: {channel_code} (total mappings: {total_mappings})")
            if total_mappings > 0:
                sample_codes = list(self.channel_mapping.mappings.keys())[:3]  # Show first 3 as sample
                self.logger.info(f"Sample existing channel codes: {sample_codes}")
            return
        # Increment event counter
        await self.channel_mapping.increment_event_count(channel_code, event_type)
        try:
            guild = self.get_guild(int(mapping["guild_id"]))
        except (ValueError, TypeError):
            self.logger.error(f"Invalid guild_id '{mapping['guild_id']}' for channel {channel_code}")
            return
        if not guild:
            self.logger.warning(f"Bot not in guild {mapping['guild_id']} for channel {channel_code}")
            return
        # Always check database first to ensure we have the latest channel settings
        guild_id = mapping["guild_id"]
        # Check cache values first (for logging comparison)
        cached_stream_alert_id = mapping.get("stream_alert_channel_id")
        cached_moderation_id = mapping.get("moderation_channel_id")
        cached_alert_id = mapping.get("alert_channel_id")
        # Always get fresh channel IDs from website database
        fresh_channel_ids = await self.channel_mapping.get_fresh_channel_ids(guild_id)
        if not fresh_channel_ids:
            self.logger.warning(f"No Discord info found for guild {guild_id}")
            return
        # Use database values as authoritative source
        stream_alert_channel_id = fresh_channel_ids.get("stream_alert_channel_id")
        moderation_channel_id = fresh_channel_ids.get("moderation_channel_id")
        alert_channel_id = fresh_channel_ids.get("alert_channel_id")
        # Log if cache differs from database
        if (cached_stream_alert_id != stream_alert_channel_id or 
            cached_moderation_id != moderation_channel_id or 
            cached_alert_id != alert_channel_id):
            self.logger.info(f"Cache mismatch for guild {guild_id}: "
                           f"stream_alert_cache={cached_stream_alert_id} vs db={stream_alert_channel_id}, "
                           f"moderation_cache={cached_moderation_id} vs db={moderation_channel_id}, "
                           f"alert_cache={cached_alert_id} vs db={alert_channel_id}")
        # Determine which channel to send the message to based on event type
        channel_id = None
        mention_everyone = False
        if event_type in ["FOLLOW", "SUBSCRIPTION", "CHEER", "RAID"]:
            if not alert_channel_id:
                self.logger.warning(f"No alert_channel_id for {event_type} event in guild {guild_id}")
                return
            channel_id = alert_channel_id
        elif event_type in ["ONLINE", "OFFLINE"]:
            if not stream_alert_channel_id:
                self.logger.warning(f"No stream_alert_channel_id for {event_type} event in guild {guild_id}")
                return
            channel_id = stream_alert_channel_id
            mention_everyone = True
        elif event_type == "MODERATION":
            if not moderation_channel_id:
                self.logger.warning(f"No moderation_channel_id for {event_type} event in guild {guild_id}")
                return
            channel_id = moderation_channel_id
        try:
            channel = guild.get_channel(int(channel_id))
        except (ValueError, TypeError):
            self.logger.error(f"Invalid channel_id '{channel_id}' for event {event_type}")
            return
        if not channel:
            self.logger.warning(f"Channel {channel_id} not found in guild {guild.name}")
            return
        message = await self.format_twitch_message(event_type, data, channel_code)
        if message:
            content = "@everyone" if mention_everyone else None
            await self._send_message_with_fallback(
                channel=channel,
                embed=message,
                content=content,
                logger_context=f"{event_type} event"
            )
            # For OFFLINE events, ensure the online status is cleared in the LiveChannelManager
            if event_type == "OFFLINE":
                try:
                    if hasattr(self, 'live_channel_manager') and self.live_channel_manager:
                        await self.live_channel_manager.mark_offline(channel_code)
                        # Also clear live_notifications specifically for offline events
                        await self.live_channel_manager.clear_live_notifications_for_channel(channel_code)
                    # Also remove any live notification entry in website DB
                    try:
                        mysql_helper = MySQLHelper(self.logger)
                        username = mapping.get('username') or (await mysql_helper.fetchone("SELECT username FROM users WHERE api_key = %s", (channel_code,), database_name='website', dict_cursor=True)).get('username')
                        if username:
                            await mysql_helper.delete_live_notification(guild.id, username)
                    except Exception as e:
                        self.logger.debug(f"Error removing live_notifications row for offline event for {channel_code}: {e}")
                except Exception as e:
                    self.logger.debug(f"Error clearing online state for {channel_code} during OFFLINE event: {e}")
        else:
            self.logger.warning(f"No message formatted for {event_type} event")

    async def format_twitch_message(self, event_type, data, channel_code):
        mysql_helper = MySQLHelper(self.logger)
        # Get the account username from the website database using the channel_code as api_key
        user_row = await mysql_helper.fetchone("SELECT username FROM users WHERE api_key = %s", (channel_code,), database_name='website', dict_cursor=True)
        account_username = user_row['username'] if user_row else "Unknown User"
        thumbnail_url = "https://cdn.botofthespecter.com/webhook"
        # Get the appropriate username based on event type
        twitch_username = data.get("twitch-username", "Unknown User")
        message_text = data.get("message", "")
        embed = None
        if event_type == "FOLLOW":
            embed = discord.Embed(
                title="New Follower!",
                description=f"**{twitch_username}** just followed the stream!",
                color=discord.Color.blue()
            )
            embed.set_thumbnail(url=(f"{thumbnail_url}/follow.png"))
        elif event_type == "SUBSCRIPTION":
            months = data.get("twitch-sub-months", 1)
            tier = data.get("twitch-tier")
            desc = f"**{twitch_username}** just subscribed"
            if months > 1:
                desc += f" for {months} months"
            desc += f" (Tier {tier})!"
            embed = discord.Embed(
                title="New Subscriber!",
                description=desc,
                color=discord.Color.gold()
            )
            embed.set_thumbnail(url=(f"{thumbnail_url}/sub.png"))
        elif event_type == "CHEER":
            bits = data.get("twitch-cheer-amount", 0)
            embed = discord.Embed(
                title="New Cheer!",
                description=f"**{twitch_username}** cheered {bits} bits!",
                color=discord.Color.purple()
            )
            if bits < 100:
                image = "cheer.png"
            elif 100 <= bits < 1000:
                image = "cheer100.png"
            else:
                image = "cheer1000.png"
            embed.set_thumbnail(url=(f"{thumbnail_url}/{image}"))
        elif event_type == "RAID":
            viewers = data.get("twitch-raid", 0)
            embed = discord.Embed(
                title="New Raid!",
                description=f"**{twitch_username}** raided with {viewers} viewers!",
                color=discord.Color.green()
            )
            embed.set_thumbnail(url=(f"{thumbnail_url}/raid.png"))
        elif event_type == "MODERATION":
            action = data.get("action", "unknown")
            moderator_name = data.get("moderator_user_name", "Unknown Moderator")
            title = f"Moderation Action: {action.title()}"
            description = f"**Moderator:** {moderator_name}\n"
            # Handle different actions
            if action == "timeout":
                user_name = data.get("timeout", {}).get("user_name", "Unknown User")
                reason = data.get("timeout", {}).get("reason", "No reason provided")
                description += f"**User:** {user_name}\n**Action:** Timed out\n**Reason:** {reason}"
                color = discord.Color.orange()
            elif action == "untimeout":
                user_name = data.get("untimeout", {}).get("user_name", "Unknown User")
                description += f"**User:** {user_name}\n**Action:** Untimed out"
                color = discord.Color.blue()
            elif action == "ban":
                user_name = data.get("ban", {}).get("user_name", "Unknown User")
                reason = data.get("ban", {}).get("reason", "No reason provided")
                description += f"**User:** {user_name}\n**Action:** Banned\n**Reason:** {reason}"
                color = discord.Color.red()
            elif action == "unban":
                user_name = data.get("unban", {}).get("user_name", "Unknown User")
                description += f"**User:** {user_name}\n**Action:** Unbanned"
                color = discord.Color.green()
            elif action == "warn":
                user_name = data.get("warn", {}).get("user_name", "Unknown User")
                reason = data.get("warn", {}).get("reason", "No reason provided")
                description += f"**User:** {user_name}\n**Action:** Warned\n**Reason:** {reason}"
                color = discord.Color.yellow()
            elif action == "mod":
                user_name = data.get("mod", {}).get("user_name", "Unknown User")
                description += f"**User:** {user_name}\n**Action:** Added as Moderator"
                color = discord.Color.blue()
            elif action == "unmod":
                user_name = data.get("unmod", {}).get("user_name", "Unknown User")
                description += f"**User:** {user_name}\n**Action:** Removed as Moderator"
                color = discord.Color.grey()
            elif action == "vip":
                user_name = data.get("vip", {}).get("user_name", "Unknown User")
                description += f"**User:** {user_name}\n**Action:** Added as VIP"
                color = discord.Color.purple()
            elif action == "unvip":
                user_name = data.get("unvip", {}).get("user_name", "Unknown User")
                description += f"**User:** {user_name}\n**Action:** Removed as VIP"
                color = discord.Color.grey()
            elif action == "delete":
                user_name = data.get("delete", {}).get("user_name", "Unknown User")
                description += f"**User:** {user_name}\n**Action:** Message Deleted"
                color = discord.Color.dark_grey()
            else:
                description += f"**Action:** {action.title()}"
                color = discord.Color.grey()
            embed = discord.Embed(
                title=title,
                description=description,
                color=color
            )
            embed.set_thumbnail(url=(f"{thumbnail_url}/mod.png"))
        if message_text:
            embed.insert_field_at(index=1, name="Message", value=message_text, inline=False)
        timestamp = await self.format_discord_embed_timestamp(channel_code)
        embed.set_footer(text=f"Auto posted by BotOfTheSpecter | {timestamp}")
        return embed

    async def format_discord_embed_timestamp(self, channel_code):
        mysql_helper = MySQLHelper(self.logger)
        # Get the username from the website database using the channel_code as api_key
        user_row = await mysql_helper.fetchone("SELECT username FROM users WHERE api_key = %s", (channel_code,), database_name='website', dict_cursor=True)
        if not user_row:
            timezone = 'UTC'
        else:
            username = user_row['username']
            # Query the user's database for the timezone
            timezone_info = await mysql_helper.fetchone("SELECT timezone FROM profile", (), database_name=username.lower(), dict_cursor=True)
            if timezone_info and timezone_info.get("timezone"):
                timezone = timezone_info.get("timezone")
            else:
                timezone = 'UTC'
        tz = pytz.timezone(timezone)
        current_time = datetime.now(tz)
        time_format_date = current_time.strftime("%B %d, %Y")
        time_format_time = current_time.strftime("%I:%M %p")
        timezone_abbr = current_time.strftime("%Z")
        time_format = f"{time_format_date} at {time_format_time} {timezone_abbr}"
        return time_format

    async def get_stream_info(self, channel_name):
        channel_name = channel_name.lower()
        mysql_helper = MySQLHelper(self.logger)
        twitch_user_id = await mysql_helper.fetchone(
            "SELECT twitch_user_id FROM users WHERE LOWER(username) = %s",
            (channel_name,), database_name='website', dict_cursor=True
        )
        twitch_user_id = twitch_user_id["twitch_user_id"]
        auth_token = await mysql_helper.fetchone(
            "SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = %s",
            (twitch_user_id,), database_name='website', dict_cursor=True
        )
        auth_token = auth_token['twitch_access_token']
        async with aiohttp.ClientSession() as session:
            headers = {"Client-ID": config.twitch_client_id,"Authorization": f"Bearer {auth_token}"}
            async with session.get(f"https://api.twitch.tv/helix/streams?user_id={twitch_user_id}&type=live&first=1", headers=headers) as resp:
                if resp.status == 200:
                    data = await resp.json()
                    stream_data = data.get("data", [{}])[0]
                    game_name = stream_data.get("game_name", "Unknown Game")
                    stream_title = stream_data.get("title", "No Title")
                    return game_name, stream_title
                else:
                    self.logger.error(f"Failed to fetch stream info: {resp.status}")
                    return "Unknown Game", "No Title"

    async def get_user_profile_image(self, username):
        try:
            profile_image_url = await self.get_user_profile_image_from_twitch(username)
            if profile_image_url:
                self.logger.info(f"Found profile image from Twitch API for {username}: {profile_image_url}")
                return profile_image_url
            self.logger.info(f"No profile image found for {username} from Twitch API")
            return None
        except Exception as e:
            self.logger.error(f"Exception getting profile image for {username}: {e}")
            return None

    async def get_user_profile_image_from_twitch(self, username):
        try:
            client_id = config.twitch_client_id
            bearer = config.twitch_bearer_token
            if not client_id or not bearer:
                self.logger.debug(f"Missing Twitch API credentials, cannot fetch profile for {username}")
                return None
            url = f"https://api.twitch.tv/helix/users?login={urllib.parse.quote_plus(str(username).strip().lower())}"
            headers = {
                'Client-ID': client_id,
                'Authorization': f"Bearer {bearer}"
            }
            async with aiohttp.ClientSession() as session:
                try:
                    async with session.get(url, headers=headers, timeout=10) as resp:
                        if resp.status == 200:
                            data = await resp.json()
                            users = data.get('data', [])
                            if users:
                                profile_image_url = users[0].get('profile_image_url')
                                if profile_image_url:
                                    return profile_image_url
                        elif resp.status == 401:
                            self.logger.debug(f"Twitch API authentication failed for profile image request")
                        else:
                            self.logger.debug(f"Twitch API request failed for {username}: {resp.status} - {await resp.text()}")
                except asyncio.TimeoutError:
                    self.logger.debug(f"Twitch API request timeout for profile image of {username}")
                except Exception as e:
                    self.logger.debug(f"Exception fetching profile image from Twitch API for {username}: {e}")
        except Exception as e:
            self.logger.error(f"Error in get_user_profile_image_from_twitch for {username}: {e}")
        return None

    async def handle_stream_event(self, event_type, data):
        code = data.get("channel_code", "unknown")
        self.logger.info(f"Processing {event_type} event for channel_code: {code}")
        # Use enhanced caching system - this will auto-create mapping if needed
        mapping = await self.channel_mapping.get_mapping(code)
        if not mapping:
            self.logger.warning(f"Could not resolve mapping for channel_code: {code}")
            return
        # Increment event counter
        await self.channel_mapping.increment_event_count(code, event_type)
        guild = self.get_guild(int(mapping["guild_id"]))
        if not guild:
            self.logger.warning(f"Bot not in guild {mapping['guild_id']} for channel_code {code}")
            return
        channel = guild.get_channel(int(mapping["channel_id"]))
        if not channel:
            self.logger.warning(f"Channel {mapping['channel_id']} not found in guild {guild.name}")
            return
        # Update Discord info in cache
        await self.channel_mapping.update_discord_info(code, guild.name, channel.name)
        # Use cached message text or defaults
        online_text = mapping.get("online_text") or "Stream is now LIVE!"
        offline_text = mapping.get("offline_text") or "Stream is now OFFLINE"
        # Set message and channel name based on event_type
        if event_type == "ONLINE":
            message = online_text
            channel_update = f"🟢 {message}"
        else:
            message = offline_text
            channel_update = f"🔴 {message}"
        # Send status message to the channel
        self.logger.info(f"Attempting to send status message to #{channel.name} (ID: {channel.id}): '{channel_update}'")
        try:
            msg = await channel.send(channel_update)
            self.logger.info(f"✅ SUCCESS: Sent status message (ID: {msg.id}) to #{channel.name}: '{channel_update}'")
        except discord.Forbidden as e:
            self.logger.error(f"❌ PERMISSION DENIED: Cannot send messages in #{channel.name} (ID: {channel.id})")
            self.logger.error(f"   Error: {e}")
        except Exception as e:
            self.logger.error(f"❌ Failed to send status message to #{channel.name}: {type(e).__name__}: {e}")
        # Send notification to stream_channel_id for online events
        # Always check database first to ensure we have the latest channel settings
        databases_checked = []
        # Check cache value first (for logging comparison)
        cached_channel_id = mapping.get("stream_channel_id")
        self.logger.info(f"Mapping cache for {code}: stream_channel_id = {cached_channel_id}")
        databases_checked.append(f"specterdiscordbot_cache: {cached_channel_id}")
        # Always get fresh channel IDs from website database
        fresh_channel_ids = await self.channel_mapping.get_fresh_channel_ids(guild.id)
        website_channel_id = fresh_channel_ids.get("stream_alert_channel_id")
        self.logger.info(f"Website database query for guild {guild.id}: stream_alert_channel_id = {website_channel_id}")
        databases_checked.append(f"website: {website_channel_id}")
        # Use database value as the authoritative source
        stream_channel_id = website_channel_id
        # Log if cache differs from database
        if cached_channel_id != website_channel_id:
            self.logger.info(f"Cache mismatch detected for {code}: cache={cached_channel_id}, database={website_channel_id}")
        if event_type == "ONLINE" and stream_channel_id:
            self.logger.info(f"Sending live notification for {code} to stream_channel_id {stream_channel_id}")
            try:
                stream_channel = guild.get_channel(int(stream_channel_id))
                if stream_channel:
                    self.logger.info(f"Stream channel found: {stream_channel.name}")
                    mysql_helper = MySQLHelper(self.logger)
                    user_row = await mysql_helper.fetchone("SELECT username FROM users WHERE api_key = %s", (code,), database_name='website', dict_cursor=True)
                    account_username = user_row['username'] if user_row else "Unknown User"
                    # Get stream alert settings from database
                    discord_info = await mysql_helper.fetchone(
                        "SELECT stream_alert_everyone, stream_alert_custom_role FROM discord_users WHERE guild_id = %s",
                        (guild.id,), database_name='website', dict_cursor=True)
                    mention_text = ""
                    if discord_info:
                        if discord_info.get('stream_alert_everyone') == 1:
                            mention_text = "@everyone "
                        elif discord_info.get('stream_alert_custom_role'):
                            try:
                                role_id = int(discord_info['stream_alert_custom_role'])
                                role = guild.get_role(role_id)
                                if role:
                                    mention_text = f"{role.mention} "
                            except (ValueError, TypeError):
                                self.logger.warning(f"Invalid custom role ID for guild {guild.id}: {discord_info['stream_alert_custom_role']}")
                    else:
                        self.logger.info("No discord_info found for guild")
                    self.logger.info(f"Final mention text for {account_username}: '{mention_text}'")
                    # Decide if we should skip this posting due to existing notification already sent
                    skip_post = False
                    try:
                        # Check if we've already posted a live notification for this user in this guild
                        existing_notification = await mysql_helper.fetchone(
                            "SELECT * FROM live_notifications WHERE guild_id = %s AND LOWER(username) = %s",
                            (guild.id, str(account_username).lower()),
                            database_name='specterdiscordbot',
                            dict_cursor=True
                        )
                        if existing_notification:
                            self.logger.info(f"Live notification already posted for {account_username} in guild {guild.id} at {existing_notification.get('posted_at')}")
                            skip_post = True
                    except Exception as e:
                        self.logger.debug(f"Error checking existing live notifications for {account_username}: {e}")
                    # Also check online_streams as fallback
                    if not skip_post:
                        try:
                            if hasattr(self, 'live_channel_manager') and self.live_channel_manager:
                                existing = await self.live_channel_manager.get_online_stream(code)
                                if existing:
                                    skip_post = True
                                    self.logger.info(f"Stream already marked online for {code}, skipping duplicate notification")
                        except Exception as e:
                            self.logger.debug(f"Error checking live_channel_manager for {code}: {e}")
                    # Get user profile image and stream info
                    if skip_post:
                        self.logger.info(f"Skipping live notification for {account_username} - already posted to guild {guild.id}")
                    else:
                        self.logger.info(f"Fetching user profile image and stream info for {account_username}")
                        profile_image_url = await self.get_user_profile_image(account_username)
                        self.logger.info(f"User profile image - {profile_image_url}")
                        game_name, stream_title = await self.get_stream_info(account_username)
                        self.logger.info(f"Stream info - Game: {game_name}, Title: {stream_title}")
                        # Get current date for footer
                        self.logger.info(f"Getting timestamp for embed footer...")
                        current_date = await self.format_discord_embed_timestamp(code)
                        self.logger.info(f"Timestamp retrieved: {current_date}")
                        # Create embed
                        embed = discord.Embed(
                            title=f"{account_username} is now live on Twitch!",
                            url=f"https://twitch.tv/{account_username}",
                            description=f"Stream is now online!\nStreaming: {game_name}\nStream Title: {stream_title}",
                            color=discord.Color.from_rgb(145, 70, 255)
                        )
                        # Set thumbnail to user profile image
                        profile_image_to_use = profile_image_url or "https://static-cdn.jtvnw.net/ttv-static/404_preview-1280x720.jpg"
                        embed.set_thumbnail(url=profile_image_to_use)
                        self.logger.info(f"Using profile image: {profile_image_to_use}")
                        # Set footer
                        embed.set_footer(text=f"Auto posted by BotOfTheSpecter | {current_date}")
                        self.logger.info(f"Attempting to send live notification to #{stream_channel.name} (ID: {stream_channel.id}) with mention: '{mention_text.strip() or 'none'}'")
                        sent_success = False
                        try:
                            message = await stream_channel.send(content=mention_text, embed=embed)
                            sent_success = True
                            self.logger.info(f"✅ SUCCESS: Sent live notification (message ID: {message.id}) for {account_username} in #{stream_channel.name}")
                        except discord.Forbidden as e:
                            self.logger.error(f"❌ PERMISSION DENIED: Cannot send messages in #{stream_channel.name} (ID: {stream_channel.id})!")
                            self.logger.error(f"   Missing permissions. Bot needs: View Channel, Send Messages, Embed Links")
                            self.logger.error(f"   Error details: {e}")
                        except discord.HTTPException as e:
                            self.logger.error(f"❌ Discord HTTP error sending notification to #{stream_channel.name}: Status {e.status}")
                            self.logger.error(f"   Response: {e.text}")
                        except Exception as e:
                            self.logger.error(f"❌ Unexpected error sending live notification to #{stream_channel.name}: {type(e).__name__}")
                            self.logger.error(f"   Details: {e}")
                            self.logger.error(f"   Traceback: {traceback.format_exc()}")
                        # After send attempt, if successful persist the live notification and mark online
                        if sent_success:
                            try:
                                posted_at = datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')
                                started_at = posted_at
                                # Fetch stream_id from Twitch API
                                stream_id = None
                                try:
                                    user_row = await mysql_helper.fetchone(
                                        "SELECT twitch_user_id FROM users WHERE LOWER(username) = %s",
                                        (str(account_username).lower(),), database_name='website', dict_cursor=True
                                    )
                                    if user_row and user_row.get('twitch_user_id'):
                                        twitch_user_id = user_row['twitch_user_id']
                                        auth_token_row = await mysql_helper.fetchone(
                                            "SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = %s",
                                            (twitch_user_id,), database_name='website', dict_cursor=True
                                        )
                                        if auth_token_row:
                                            auth_token = auth_token_row['twitch_access_token']
                                            async with aiohttp.ClientSession() as session:
                                                headers = {"Client-ID": config.twitch_client_id, "Authorization": f"Bearer {auth_token}"}
                                                async with session.get(f"https://api.twitch.tv/helix/streams?user_id={twitch_user_id}&type=live&first=1", headers=headers) as resp:
                                                    if resp.status == 200:
                                                        data = await resp.json()
                                                        stream_data = data.get("data", [{}])[0]
                                                        stream_id = stream_data.get("id")
                                except Exception as e:
                                    self.logger.debug(f"Error fetching stream_id from Twitch API for {account_username}: {e}")
                                # Use stream_id or empty string as fallback (database may require non-null)
                                stream_id = stream_id or ""
                                await mysql_helper.insert_live_notification(guild.id, account_username, stream_id, started_at, posted_at)
                                if hasattr(self, 'live_channel_manager') and self.live_channel_manager:
                                    await self.live_channel_manager.mark_online(code, username=account_username, twitch_user_id=None, stream_id=stream_id or None, started_at=started_at, details=None)
                            except Exception as e:
                                self.logger.debug(f"Error persisting live notification or marking online for {code}: {e}")
                else:
                    self.logger.warning(f"Stream channel not found for id {stream_channel_id} in guild {guild.id}")
            except Exception as e:
                self.logger.error(f"❌ CRITICAL ERROR in stream notification block for {code}: {type(e).__name__}")
                self.logger.error(f"   Details: {e}")
                self.logger.error(f"   Full traceback: {traceback.format_exc()}")
                self.logger.error(f"   This error prevented the notification from being sent, but channel rename will still be attempted.")
        else:
            # Log exactly what was checked and found
            databases_info = " | ".join(databases_checked)
            self.logger.info(f"No live notification sent for {code} - event_type: {event_type}, final_stream_channel_id: {stream_channel_id}, databases_checked: [{databases_info}]")
        # Attempt to update the channel name if it is different
        self.logger.info(f"Channel rename check: current='{channel.name}' vs target='{channel_update}'")
        if channel.name != channel_update:
            self.logger.info(f"Names differ, attempting to rename channel #{channel.name} to '{channel_update}'")
            try:
                await channel.edit(name=channel_update, reason="Stream status update")
                self.logger.info(f"✅ SUCCESS: Updated channel name to '{channel_update}' for stream {event_type}")
            except discord.Forbidden:
                self.logger.error(f"❌ PERMISSION DENIED: Cannot rename channel #{channel.name} (ID: {channel.id}). Check 'Manage Channels' permission!")
            except discord.HTTPException as e:
                self.logger.error(f"❌ Discord HTTP error renaming channel: {e.status} - {e.text}")
            except Exception as e:
                self.logger.error(f"❌ Failed to update channel name: {type(e).__name__}: {e}")
        else:
            self.logger.info(f"Channel name already matches target '{channel_update}' - skipping rename")
        self.logger.info(f"Completed processing {event_type} event for channel_code: {code}")

    async def _process_stream_alert(self, guild, code, stream_channel_id, event_type):
        try:
            self.logger.info(f"Processing stream alert for {code} in guild {guild.id}")
            stream_channel_id_int = int(stream_channel_id)
            stream_channel = guild.get_channel(stream_channel_id_int)
            if not stream_channel:
                self.logger.warning(f"Stream alert channel {stream_channel_id} not found in guild {guild.id}")
                return
            self.logger.info(f"Found stream alert channel: #{stream_channel.name} (ID: {stream_channel.id})")
            # Get user info
            mysql_helper = MySQLHelper(self.logger)
            user_row = await mysql_helper.fetchone("SELECT username FROM users WHERE api_key = %s", (code,), database_name='website', dict_cursor=True)
            account_username = user_row['username'] if user_row else "Unknown User"
            self.logger.info(f"Stream alert for account: {account_username}")
            # Get stream alert settings
            discord_info = await mysql_helper.fetchone(
                "SELECT stream_alert_everyone, stream_alert_custom_role FROM discord_users WHERE guild_id = %s",
                (guild.id,), database_name='website', dict_cursor=True)
            mention_text = ""
            if discord_info:
                if discord_info.get('stream_alert_everyone') == 1:
                    mention_text = "@everyone "
                elif discord_info.get('stream_alert_custom_role'):
                    try:
                        role_id = int(discord_info['stream_alert_custom_role'])
                        role = guild.get_role(role_id)
                        if role:
                            mention_text = f"{role.mention} "
                    except (ValueError, TypeError):
                        self.logger.warning(f"Invalid custom role ID: {discord_info['stream_alert_custom_role']}")
            # Get stream info
            game_name, stream_title = await self.get_stream_info(account_username)
            current_date = await self.format_discord_embed_timestamp(code)
            # Create embed
            embed = discord.Embed(
                title=f"{account_username} is now live on Twitch!",
                url=f"https://twitch.tv/{account_username}",
                description=f"Stream is now online! Streaming: {game_name}",
                color=discord.Color.from_rgb(145, 70, 255)
            )
            profile_image_url = await self.get_user_profile_image(account_username)
            profile_image_to_use = profile_image_url or "https://static-cdn.jtvnw.net/ttv-static/404_preview-1280x720.jpg"
            embed.set_thumbnail(url=profile_image_to_use)
            embed.set_footer(text=f"Auto posted by BotOfTheSpecter | {current_date}")
            # Send notification
            await stream_channel.send(content=mention_text, embed=embed)
            self.logger.info(f"Successfully sent stream alert for {account_username} to #{stream_channel.name}")
        except Exception as e:
            self.logger.error(f"Exception in _process_stream_alert for {code}: {e}")

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

# Utility cog for timestamp conversion and other utilities
class UtilityCog(commands.Cog, name='Utility'):
    def __init__(self, bot: commands.Bot, logger=None):
        self.bot = bot
        self.logger = logger

    @commands.command(name="timestamp", aliases=["ts", "discordtime"])
    async def convert_to_timestamp(self, ctx, *, time_input: str):
        try:
            timestamp = None
            # Try to parse as Unix timestamp (integer)
            try:
                timestamp = int(time_input)
                # Validate it's a reasonable timestamp (after 2000 and before 2100)
                if timestamp < 946684800 or timestamp > 4102444800:
                    timestamp = None
            except ValueError:
                pass
            # If not a valid timestamp, try to parse as date/datetime string
            if timestamp is None:
                formats = [
                    "%Y-%m-%d %H:%M:%S",
                    "%Y-%m-%d %H:%M",
                    "%Y-%m-%d",
                    "%m/%d/%Y %H:%M:%S",
                    "%m/%d/%Y %H:%M",
                    "%m/%d/%Y",
                    "%d/%m/%Y %H:%M:%S",
                    "%d/%m/%Y %H:%M",
                    "%d/%m/%Y",
                    "%Y-%m-%d %I:%M:%S %p",
                    "%Y-%m-%d %I:%M %p",
                    "%m/%d/%Y %I:%M:%S %p",
                    "%m/%d/%Y %I:%M %p"
                ]
                dt = None
                for fmt in formats:
                    try:
                        dt = datetime.strptime(time_input, fmt)
                        break
                    except ValueError:
                        continue
                if dt is None:
                    await ctx.send("❌ Could not parse the date/time. Please use format like:\n"
                                   "- `2021-06-27 21:48:37` (YYYY-MM-DD HH:MM:SS)\n"
                                   "- `2021-06-27` (YYYY-MM-DD)\n"
                                   "- `1624855717` (Unix timestamp)")
                    return
                timestamp = int(dt.timestamp())
            # Create embed with all Discord timestamp formats
            embed = discord.Embed(
                title="Discord Timestamp Converter",
                description=f"Unix Timestamp: `{timestamp}`",
                color=discord.Color.blue()
            )
            # Add all timestamp format variations
            formats = {
                "Short Date Time": f"<t:{timestamp}>",
                "Short Date Time (alt)": f"<t:{timestamp}:f>",
                "Long Date Time": f"<t:{timestamp}:F>",
                "Short Date": f"<t:{timestamp}:d>",
                "Long Date": f"<t:{timestamp}:D>",
                "Short Time": f"<t:{timestamp}:t>",
                "Long Time": f"<t:{timestamp}:T>",
                "Relative Time": f"<t:{timestamp}:R>"
            }
            for name, value in formats.items():
                embed.add_field(name=name, value=f"`{value}`", inline=False)
            # Add preview field showing what they look like
            embed.add_field(name="Preview (How they appear):", value="", inline=False)
            for name, value in formats.items():
                # Extract just the rendered timestamp for preview
                embed.add_field(name=f"→ {name}", value=value, inline=False)
            await ctx.send(embed=embed)
        except Exception as e:
            self.logger.error(f"Error in timestamp conversion: {e}")
            await ctx.send(f"❌ An error occurred: {e}")

    @commands.command(name="epochnow", aliases=["now", "currenttime"])
    async def current_epoch(self, ctx):
        try:
            current_timestamp = int(time.time())
            current_time = datetime.now()
            embed = discord.Embed(
                title="Current Unix Timestamp",
                description=f"`{current_timestamp}`",
                color=discord.Color.green()
            )
            embed.add_field(name="Local Time", value=current_time.strftime("%Y-%m-%d %H:%M:%S"), inline=False)
            # Show all Discord timestamp formats for current time
            formats = {
                "Short Date Time": f"<t:{current_timestamp}>",
                "Long Date Time": f"<t:{current_timestamp}:F>",
                "Long Date": f"<t:{current_timestamp}:D>",
                "Long Time": f"<t:{current_timestamp}:T>",
                "Relative": f"<t:{current_timestamp}:R>"
            }
            format_text = "\n".join([f"**{name}:** `{value}`" for name, value in formats.items()])
            embed.add_field(name="Discord Formats", value=format_text, inline=False)
            await ctx.send(embed=embed)
        except Exception as e:
            self.logger.error(f"Error getting current epoch: {e}")
            await ctx.send(f"❌ An error occurred: {e}")

# Ticket management cog
class TicketCog(commands.Cog, name='Tickets'):
    def __init__(self, bot: commands.Bot, logger=None):
        self.bot = bot
        self.logger = logger or logging.getLogger(self.__class__.__name__)
        self.pool = None

    async def validate_ticket_command_channel(self, ctx, action):
        if action != "create":
            return True  # Only validate 'create' commands for channel restrictions
        settings = await self.get_settings(ctx.guild.id)
        if not settings or not settings.get('info_channel_id'):
            return True  # No restrictions if no info channel is set
        if str(ctx.channel.id) != str(settings['info_channel_id']):
            # Remove the command message
            try:
                await ctx.message.delete()
            except discord.NotFound:
                pass
            # Get the info channel
            info_channel = ctx.guild.get_channel(int(settings['info_channel_id']))
            if info_channel:
                # Send warning message with channel mention
                await ctx.send(
                    f"{ctx.author.mention} Ticket creation is only allowed in {info_channel.mention}. "
                    f"Please go to that channel and use `!ticket create` there.",
                    delete_after=15
                )
                self.logger.info(f"Redirected {ctx.author} to use ticket command in correct channel")
            else:
                # Info channel not found, send generic warning
                await ctx.send(
                    f"{ctx.author.mention} Ticket creation is only allowed in the designated ticket info channel. "
                    f"Please ask an admin for the correct channel.",
                    delete_after=15
                )
                self.logger.warning(f"Info channel {settings['info_channel_id']} not found in guild {ctx.guild.id}")
            return False
        return True

    async def check_other_ticket_commands_in_wrong_channel(self, ctx, action):
        settings = await self.get_settings(ctx.guild.id)
        if not settings:
            return True
        # For commands like 'close' and 'issue', they should be used in ticket channels
        if action in ["close", "issue"] and not ctx.channel.name.startswith("ticket-"):
            embed = discord.Embed(
                title="Ticket Command Error",
                description=f"The `!ticket {action}` command can only be used in a ticket channel.",
                color=discord.Color.yellow()
            )
            await ctx.send(embed=embed, delete_after=10)
            return False
        return True

    async def get_settings(self, guild_id: int):
        if not self.pool:
            self.pool = await aiomysql.create_pool(
                host=config.sql_host,
                user=config.sql_user,
                password=config.sql_password,
                db='specterdiscordbot',
                autocommit=True
            )
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
        category = guild.get_channel(int(settings['category_id']))
        user = guild.get_member(user_id)
        if user is None:
            user = await guild.fetch_member(user_id)
        support_role = guild.get_role(int(settings['support_role_id'])) if settings.get('support_role_id') else None
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
            self.pool = await aiomysql.create_pool(
                host=config.sql_host,
                user=config.sql_user,
                password=config.sql_password,
                db='specterdiscordbot',
                autocommit=True
            )
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
            self.pool = await aiomysql.create_pool(
                host=config.sql_host,
                user=config.sql_user,
                password=config.sql_password,
                db='specterdiscordbot',
                autocommit=True
            )
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
            # Get settings once at the beginning for use throughout the method
            settings = await self.get_settings(channel.guild.id)
            ticket_creator = None
            # Try to send DM to ticket creator with the reason
            try:
                ticket_creator = channel.guild.get_member(int(ticket_data['user_id']))
                if ticket_creator:
                    dm_embed = discord.Embed(
                        title="Support Ticket Closed",
                        description=(
                            f"Your support ticket ({ticket_id}) has been closed by the support team. "
                            f"{f'Reason for closure: {reason}' if reason != 'No reason provided' else ' '}"
                            f"If you need further assistance or if this ticket was closed by mistake, "
                            f"please return to <#{settings['info_channel_id'] if settings else 'the ticket info channel'}> and create a new ticket "
                            f"using `!ticket create`."
                        ),
                        color=config.bot_color
                    )
                    await ticket_creator.send(embed=dm_embed)
                    self.logger.info(f"Sent closure DM to user {ticket_creator.name} for ticket #{ticket_id} with reason: {reason if reason != 'No reason provided' else 'No reason provided'}")
            except (ValueError, TypeError):
                self.logger.warning(f"Invalid user_id '{ticket_data['user_id']}' in ticket database")
            except discord.Forbidden:
                self.logger.warning(f"Could not send DM to user {ticket_data['user_id']} for ticket #{ticket_id}")
            except Exception as e:
                self.logger.error(f"Error sending closure DM: {e}")
            # Wait 10 seconds before proceeding with closure
            await asyncio.sleep(10)
            try:
                # Get or create the Closed Tickets category
                closed_category = None
                if settings and settings.get('closed_category_id'):
                    # Try to get the saved closed category first
                    closed_category = channel.guild.get_channel(int(settings['closed_category_id']))
                    if not closed_category:
                        self.logger.warning(f"Saved closed category {settings['closed_category_id']} not found, will create new one")
                if not closed_category:
                    # Look for existing "Closed Tickets" category
                    closed_category = discord.utils.get(channel.guild.categories, name="Closed Tickets")
                    
                if not closed_category:
                    # Create new category if none exists
                    closed_category = await channel.guild.create_category(
                        name="Closed Tickets",
                        reason="Ticket System Archive"
                    )
                    self.logger.info(f"Created new 'Closed Tickets' category with ID {closed_category.id}")
                    # Set permissions for Closed Tickets category
                    await closed_category.set_permissions(channel.guild.default_role, read_messages=False)
                    # Give owner access if they exist in settings
                    if settings and settings.get('owner_id'):
                        owner = channel.guild.get_member(int(settings['owner_id']))
                        if owner:
                            await closed_category.set_permissions(owner, read_messages=True, send_messages=False)
                # Save the closed category ID to database if it's not already saved or if it changed
                if not settings or settings.get('closed_category_id') != str(closed_category.id):
                    async with self.pool.acquire() as conn:
                        async with conn.cursor() as cur:
                            await cur.execute(
                                "UPDATE ticket_settings SET closed_category_id = %s WHERE guild_id = %s",
                                (closed_category.id, channel.guild.id)
                            )
                    self.logger.info(f"Saved closed category ID {closed_category.id} to database for guild {channel.guild.id}")
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
            self.pool = await aiomysql.create_pool(
                host=config.sql_host,
                user=config.sql_user,
                password=config.sql_password,
                db='specterdiscordbot',
                autocommit=True
            )
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
            # Validate if the command is being used in the correct channel
            if not await self.validate_ticket_command_channel(ctx, action.lower()):
                return  # validation method handles the error response
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
            # Validate if the command is being used in the correct channel type
            if not await self.check_other_ticket_commands_in_wrong_channel(ctx, action.lower()):
                return
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
                    support_role = ctx.guild.get_role(int(settings['support_role_id']))
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
            # Validate if the command is being used in the correct channel type
            if not await self.check_other_ticket_commands_in_wrong_channel(ctx, action.lower()):
                return
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
                    self.pool = await aiomysql.create_pool(
                        host=config.sql_host,
                        user=config.sql_user,
                        password=config.sql_password,
                        db='specterdiscordbot',
                        autocommit=True
                    )
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
            self.pool = await aiomysql.create_pool(
                host=config.sql_host,
                user=config.sql_user,
                password=config.sql_password,
                db='specterdiscordbot',
                autocommit=True
            )
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
            # Create or find the Closed Tickets category
            closed_category = discord.utils.get(ctx.guild.categories, name="Closed Tickets")
            if not closed_category:
                closed_category = await ctx.guild.create_category(
                    name="Closed Tickets",
                    reason="Ticket System Setup"
                )
                # Set permissions for Closed Tickets category
                await closed_category.set_permissions(ctx.guild.default_role, read_messages=False)
                # Give owner access
                await closed_category.set_permissions(ctx.author, read_messages=True, send_messages=False)
                self.logger.info(f"Created 'Closed Tickets' category in {ctx.guild.name}")
            # Save settings to database
            async with self.pool.acquire() as conn:
                async with conn.cursor() as cur:
                    await cur.execute("""
                        INSERT INTO ticket_settings 
                        (guild_id, owner_id, info_channel_id, category_id, closed_category_id, support_role_id, mod_channel_id, enabled) 
                        VALUES (%s, %s, %s, %s, %s, %s, %s, TRUE)
                        ON DUPLICATE KEY UPDATE 
                        owner_id = VALUES(owner_id),
                        info_channel_id = VALUES(info_channel_id),
                        category_id = VALUES(category_id),
                        closed_category_id = VALUES(closed_category_id),
                        support_role_id = VALUES(support_role_id),
                        mod_channel_id = VALUES(mod_channel_id),
                        enabled = TRUE,
                        updated_at = CURRENT_TIMESTAMP
                    """, (ctx.guild.id, ctx.author.id, info_channel.id, category.id, closed_category.id,
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
                    f"**Open Tickets Category:** {category.mention}" + os.linesep +
                    f"**Closed Tickets Category:** {closed_category.mention}" + os.linesep +
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
            closed_category_display = f"<#{settings.get('closed_category_id')}>" if settings.get('closed_category_id') else "Not set"
            embed.add_field(
                name="🎫 Ticket System",
                value=(
                    f"**Status:** {'✅ Enabled' if settings.get('enabled') else '❌ Disabled'}\n"
                    f"**Open Category:** <#{settings.get('category_id')}>\n"
                    f"**Closed Category:** {closed_category_display}\n"
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
            # Guard: message.guild can be None for DMs or system messages
            if message.guild is None:
                return
            settings = await self.get_settings(message.guild.id)
            if not settings:
                return
            # settings['info_channel_id'] may be stored as string in DB, compare safely
            msg_channel_id = str(message.channel.id) if hasattr(message.channel, 'id') else None
            info_channel_id = str(settings.get('info_channel_id')) if settings.get('info_channel_id') is not None else None
            if msg_channel_id and info_channel_id and msg_channel_id == info_channel_id:
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
                    await self.mysql_helper.track_message('discordbot')
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
                    await self.mysql_helper.track_message('discordbot')                    # Set a delay before deleting the warning message
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
                self.pool = await aiomysql.create_pool(
                    host=config.sql_host,
                    user=config.sql_user,
                    password=config.sql_password,
                    db='specterdiscordbot',
                    autocommit=True
                )
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
        # Create fallback text
        fallback_text = f"🎵 **Music Queue**\n\n{desc}"
        success = await self.bot._send_message_with_fallback(
            channel=ctx.channel,
            embed=embed,
            fallback_text=fallback_text,
            logger_context="music queue"
        )
        if success:
            try:
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
            elapsed_str = str(timedelta(seconds=elapsed))
            duration_str = str(timedelta(seconds=int(duration)))
            embed.add_field(name="Progress", value=f"{elapsed_str} / {duration_str}", inline=True)
        # Create fallback text
        fallback_text = f"🎵 **Currently Playing:** {title}\n**Source:** {source_label}\n**Requested by:** {requested_by}"
        if duration and start:
            elapsed = int(time.time() - start)
            elapsed_str = str(timedelta(seconds=elapsed))
            duration_str = str(timedelta(seconds=int(duration)))
            fallback_text += f"\n**Progress:** {elapsed_str} / {duration_str}"
        success = await self.bot._send_message_with_fallback(
            channel=ctx.channel,
            embed=embed,
            fallback_text=fallback_text,
            logger_context="now playing"
        )
        if success:
            try:
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
        self.logger = logger
        self.voice_clients = {}  # Guild ID -> VoiceClient mapping
        self._connect_locks = {}
        self._connect_attempts = {}
        self.music_player = MusicPlayer(bot, logger)
        self.logger.info("VoiceCog initialized successfully")

    async def cog_check(self, ctx):
        return ctx.guild is not None

    @commands.command(name="connect", aliases=["join", "summon"])
    async def connect_voice(self, ctx, *, channel: discord.VoiceChannel = None):
        self.logger.info(f"Connect command called by {ctx.author} in {ctx.guild.name}")
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
            # Prevent concurrent connect attempts per guild using a per-guild lock
            guild_id = ctx.guild.id
            now = time.time()
            attempt_info = self._connect_attempts.get(guild_id)
            # Reset attempt_info if it's old (older than 1 minute)
            if attempt_info and now - attempt_info.get('first_attempt', now) > 60:
                attempt_info = {'count': 0, 'first_attempt': now}
                self._connect_attempts[guild_id] = attempt_info
            if attempt_info and attempt_info.get('count', 0) >= 3 and now - attempt_info.get('first_attempt', now) <= 60:
                # Too many connect attempts in a short time - inform user and skip
                embed = discord.Embed(
                    title="⚠️ Voice Connection Blocked",
                    description="Too many recent attempts to connect to voice; please try again later.",
                    color=discord.Color.orange()
                )
                msg = await ctx.send(embed=embed)
                try:
                    await msg.delete(delay=5)
                    await ctx.message.delete(delay=5)
                except Exception:
                    pass
                self.logger.warning(f"Blocked repeated connect attempts to voice in guild {guild_id}")
                return
            lock = self._connect_locks.get(ctx.guild.id)
            if not lock:
                lock = asyncio.Lock()
                self._connect_locks[ctx.guild.id] = lock
            async with lock:
                # Double-check if the bot is already connected after acquiring the lock
                existing_vc = self.voice_clients.get(ctx.guild.id)
                if existing_vc and existing_vc.is_connected():
                    current_channel = existing_vc.channel
                    embed = discord.Embed(
                        title="ℹ️ Already Connected",
                        description=f"I'm already connected to {current_channel.mention}.",
                        color=discord.Color.blue()
                    )
                    msg = await ctx.send(embed=embed)
                    try:
                        await msg.delete(delay=5)
                        await ctx.message.delete(delay=5)
                    except Exception:
                        pass
                    return
                # If we have a stale VoiceClient object (not connected), try to clean it up first
                if existing_vc and not existing_vc.is_connected():
                    try:
                        await existing_vc.disconnect()
                    except Exception:
                        pass
                    try:
                        del self.voice_clients[ctx.guild.id]
                    except KeyError:
                        pass
                try:
                    voice_client = await channel.connect()
                except discord.errors.ConnectionClosed as e:
                    # update attempts info on failure
                    attempt_info = self._connect_attempts.get(guild_id, {'count': 0, 'first_attempt': now})
                    attempt_info['count'] = attempt_info.get('count', 0) + 1
                    attempt_info['first_attempt'] = attempt_info.get('first_attempt', now)
                    self._connect_attempts[guild_id] = attempt_info
                    # Log additional diagnostics for connection failures
                    try:
                        self.logger.error(f"Voice ConnectionClosed for guild {ctx.guild.id} - code={e.code}, reason={e}")
                    except Exception:
                        self.logger.error(f"Voice ConnectionClosed for guild {ctx.guild.id}: {e}")
                    raise
                except Exception as e:
                    # update attempts info on failure
                    attempt_info = self._connect_attempts.get(guild_id, {'count': 0, 'first_attempt': now})
                    attempt_info['count'] = attempt_info.get('count', 0) + 1
                    attempt_info['first_attempt'] = attempt_info.get('first_attempt', now)
                    self._connect_attempts[guild_id] = attempt_info
                    # Log additional diagnostics for connection failures
                    self.logger.error(f"Error during channel.connect() for guild {ctx.guild.id} - {type(e).__name__}: {e}")
                    raise
                else:
                    # On success: reset attempts
                    if guild_id in self._connect_attempts:
                        try:
                            del self._connect_attempts[guild_id]
                        except KeyError:
                            pass
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
                # Only start playback if the voice_client is connected
                vc = self.voice_clients.get(guild_id)
                if vc and vc.is_connected():
                    try:
                        await self.music_player.play_random_cdn_mp3(ctx)
                    except Exception as e:
                        self.logger.error(f"Failed to start CDN playback after connect for guild {guild_id}: {e}")
                else:
                    self.logger.warning(f"Skipping automatic CDN playback; voice client not connected for guild {guild_id}")
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
            voice_client = self.voice_clients.get(ctx.guild.id)
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
                # cleanup stale voice_client mapping if present
                try:
                    if ctx.guild.id in self.voice_clients:
                        try:
                            await voice_client.disconnect()
                        except Exception:
                            pass
                        del self.voice_clients[ctx.guild.id]
                except Exception:
                    pass
                return
            channel_name = voice_client.channel.name
            await voice_client.disconnect()
            try:
                if ctx.guild.id in self.voice_clients:
                    del self.voice_clients[ctx.guild.id]
            except Exception:
                pass
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
            # Create fallback text
            fallback_text = f"🔊 **Voice Status**\n**Connected To:** {channel.name}\n**Channel Members:** {member_count}\n**Latency:** {latency_display}"
            success = await self._send_message_with_fallback(
                channel=ctx.channel,
                embed=embed,
                fallback_text=fallback_text,
                logger_context="voice status (connected)"
            )
        else:
            embed = discord.Embed(
                title="🔇 Voice Status",
                description="Not connected to any voice channel in this server.",
                color=discord.Color.red()
            )
            success = await self._send_message_with_fallback(
                channel=ctx.channel,
                embed=embed,
                fallback_text="🔇 **Voice Status**\nNot connected to any voice channel in this server.",
                logger_context="voice status (disconnected)"
            )
        if success:
            try:
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
            # If the bot itself was disconnected from voice (e.g., due to server/handshake close), cleanup mapping
            try:
                # before and after may be None or missing attributes
                before_channel = getattr(before, 'channel', None)
                after_channel = getattr(after, 'channel', None)
                if before_channel and not after_channel:
                    guild_id = before_channel.guild.id
                    # Remove mapping so future connections attempt cleanly
                    if guild_id in self.voice_clients:
                        try:
                            del self.voice_clients[guild_id]
                        except Exception:
                            pass
                        self.logger.info(f"Voice client mapping for guild {guild_id} removed due to bot being disconnected from voice")
            except Exception:
                pass
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

    @commands.command(name="song", aliases=["np", "nowplaying", "now-playing", "now_playing"]) 
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
            elapsed_str = str(timedelta(seconds=elapsed))
            duration_str = str(timedelta(seconds=int(duration)))
            embed.add_field(name="Progress", value=f"{elapsed_str} / {duration_str}", inline=True)
        msg = await ctx.send(embed=embed)
        try:
            await msg.delete(delay=5)
            await ctx.message.delete(delay=5)
        except Exception:
            pass

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

# Twitch to Discord Streamer Posting
class StreamerPostingCog(commands.Cog, name='Streamer Posting'):
    def __init__(self, bot, logger=None):
        self.bot = bot
        self.logger = logger
        self.mysql = MySQLHelper(logger)
        # Monitoring state tracking
        self.monitored_guilds = {}  # guild_id -> guild_data
        self.last_db_check = 0  # Timestamp of last database check
        self.db_check_interval = 300  # Check database every 5 minutes
        self.live_users = {}  # (guild_id, username) -> stream_data
        # Start the monitoring task
        self.monitoring_task = asyncio.create_task(self.start_monitoring())

    async def refresh_guild_data(self):
        current_time = time.time()
        if current_time - self.last_db_check < self.db_check_interval:
            return
        self.last_db_check = current_time
        new_monitored_guilds = {}
        # Get all guilds the bot is currently connected to
        bot_guilds = self.bot.guilds
        for guild in bot_guilds:
            guild_id = guild.id
            try:
                # Check if this guild is configured in the database
                discord_user = await self.mysql.fetchone(
                    "SELECT user_id, member_streams_id FROM discord_users WHERE guild_id = %s AND member_streams_id IS NOT NULL AND member_streams_id != ''",
                    (guild_id,), database_name='website', dict_cursor=True
                )
                if not discord_user:
                    # Guild is not configured for member streams monitoring
                    continue
                user_id = discord_user.get('user_id')
                member_streams_id = discord_user.get('member_streams_id')
                if not user_id or not member_streams_id:
                    self.logger.warning(f"Guild {guild_id} ({guild.name}): Missing configuration data")
                    continue
                # Convert to int if stored as string
                try:
                    member_streams_id = int(member_streams_id)
                except (ValueError, TypeError):
                    self.logger.warning(f"Guild {guild_id} ({guild.name}): Invalid member streams channel ID: {member_streams_id}")
                    continue
                # Verify the Discord channel exists and bot has access
                discord_channel = self.bot.get_channel(member_streams_id)
                if not discord_channel:
                    # Try to get channel from guild directly as fallback
                    discord_channel = guild.get_channel(member_streams_id)
                    if not discord_channel:
                        self.logger.warning(f"Guild {guild_id} ({guild.name}): Channel {member_streams_id} not found - may have been deleted or bot lacks access")
                        continue
                    else:
                        self.logger.info(f"Guild {guild_id} ({guild.name}): Found channel via guild lookup: #{discord_channel.name}")
                # Check if bot has permission to send messages in the channel
                bot_member = guild.get_member(self.bot.user.id)
                if not bot_member:
                    self.logger.warning(f"Guild {guild_id} ({guild.name}): Bot is not a member of this guild")
                    continue
                channel_permissions = discord_channel.permissions_for(bot_member)
                if not channel_permissions.send_messages:
                    self.logger.warning(f"Guild {guild_id} ({guild.name}): Bot lacks send_messages permission in #{discord_channel.name}")
                    continue
                if not channel_permissions.embed_links:
                    self.logger.warning(f"Guild {guild_id} ({guild.name}): Bot lacks embed_links permission in #{discord_channel.name}")
                    continue
                # Get channel info (streamer's info)
                get_channel_info = await self.mysql.fetchone(
                    "SELECT username, twitch_user_id FROM users WHERE id = %s", 
                    (user_id,), database_name='website', dict_cursor=True
                )
                if not get_channel_info:
                    self.logger.warning(f"Guild {guild_id} ({guild.name}): No user info found for user_id {user_id}")
                    continue
                channel_name = get_channel_info.get('username')
                twitch_user_id = get_channel_info.get('twitch_user_id')
                if not channel_name or not twitch_user_id:
                    self.logger.warning(f"Guild {guild_id} ({guild.name}): Missing user configuration")
                    continue
                # Get auth token
                get_auth_token = await self.mysql.fetchone(
                    "SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = %s", 
                    (twitch_user_id,), database_name='website'
                )
                if not get_auth_token:
                    self.logger.warning(f"Guild {guild_id} ({guild.name}): No auth token found for twitch_user_id {twitch_user_id}")
                    continue
                # Handle both dict and tuple response formats for auth token query
                if isinstance(get_auth_token, dict):
                    auth_token = get_auth_token.get('twitch_access_token')
                else:
                    # Fallback to tuple access (first column)
                    auth_token = get_auth_token[0] if get_auth_token else None
                if not auth_token:
                    self.logger.warning(f"Guild {guild_id} ({guild.name}): No valid auth token available")
                    continue
                # Get member streams to monitor
                users = await self.mysql.fetchall(
                    "SELECT username, stream_url FROM member_streams",
                    database_name=channel_name, dict_cursor=True
                )
                if users:
                    new_monitored_guilds[guild_id] = {
                        'guild_name': guild.name,
                        'channel_name': channel_name,
                        'twitch_user_id': twitch_user_id,
                        'auth_token': auth_token,
                        'member_streams_id': member_streams_id,
                        'discord_channel': discord_channel,
                        'users': users,
                        'user_id': user_id
                    }
            except Exception as e:
                self.logger.error(f"Error processing guild {guild_id} ({guild.name if guild else 'Unknown'}): {e}")
                continue
        # Update monitored guilds
        old_guilds = set(self.monitored_guilds.keys())
        new_guilds = set(new_monitored_guilds.keys())
        added_guilds = new_guilds - old_guilds
        removed_guilds = old_guilds - new_guilds
        if removed_guilds:
            # Clean up live users for removed guilds
            self.live_users = {k: v for k, v in self.live_users.items() if k[0] not in removed_guilds}
        self.monitored_guilds = new_monitored_guilds

    async def check_streams_for_guild(self, guild_id, guild_data):
        users = guild_data['users']
        auth_token = guild_data['auth_token']
        if not users:
            return []
        # Only include usernames that match Twitch login format: lowercase, no spaces, only underscores, numbers, and letters
        twitch_login_regex = re.compile(r'^[a-z0-9_]+$')
        usernames = [str(user['username']).strip().lower().replace(' ', '_') for user in users if user.get('username') and str(user['username']).strip() and twitch_login_regex.match(str(user['username']).strip().lower().replace(' ', '_'))]
        if not usernames:
            self.logger.warning(f"No valid usernames to check for guild {guild_id}. Users: {users}")
            return []
        all_stream_data = []
        if len(usernames) <= 100:
            try:
                async with aiohttp.ClientSession() as session:
                    url = "https://api.twitch.tv/helix/streams"
                    headers = {
                        "Client-ID": config.twitch_client_id,
                        "Authorization": f"Bearer {auth_token}"
                    }
                    # Build params as a list of tuples for multiple user_login
                    params = [("user_login", username) for username in usernames]
                    params.append(("type", "live"))
                    params.append(("first", "100"))
                    self.logger.debug(f"Twitch API params for guild {guild_id}: {params}")
                    async with session.get(url, headers=headers, params=params) as response:
                        if response.status != 200:
                            if response.status == 401:
                                # Try to get bot's auth token as fallback
                                self.logger.warning(f"401 Unauthorized for guild {guild_id}, attempting to use bot auth token")
                                bot_auth_token = await self.mysql.fetchone(
                                    "SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = %s", 
                                    ("971436498",), database_name='website'
                                )
                                if bot_auth_token:
                                    if isinstance(bot_auth_token, dict):
                                        bot_auth_token = bot_auth_token.get('twitch_access_token')
                                    else:
                                        bot_auth_token = bot_auth_token[0] if bot_auth_token else None
                                    if bot_auth_token:
                                        self.logger.info(f"Retrying with bot auth token for guild {guild_id}")
                                        retry_headers = {
                                            "Client-ID": config.twitch_client_id,
                                            "Authorization": f"Bearer {bot_auth_token}"
                                        }
                                        async with session.get(url, headers=retry_headers, params=params) as retry_response:
                                            if retry_response.status == 200:
                                                data = await retry_response.json()
                                                all_stream_data = data.get('data', [])
                                            else:
                                                self.logger.error(f"Failed to fetch streams with bot token for guild {guild_id}: {retry_response.status} {await retry_response.text()} | Params: {params}")
                                                return []
                                    else:
                                        self.logger.error(f"No valid bot auth token available for guild {guild_id}")
                                        return []
                                else:
                                    self.logger.error(f"Could not retrieve bot auth token for guild {guild_id}")
                                    return []
                            else:
                                self.logger.error(f"Failed to fetch streams for guild {guild_id}: {response.status} {await response.text()} | Params: {params}")
                                return []
                        else:
                            data = await response.json()
                            all_stream_data = data.get('data', [])
            except Exception as e:
                self.logger.error(f"Error checking streams for guild {guild_id}: {e}")
                return []
        else:
            # Handle more than 100 users by batching requests
            self.logger.info(f"Guild {guild_id} has {len(usernames)} users, using batch requests")
            # Split usernames into chunks of 100
            username_chunks = [usernames[i:i + 100] for i in range(0, len(usernames), 100)]
            try:
                async with aiohttp.ClientSession() as session:
                    url = "https://api.twitch.tv/helix/streams"
                    headers = {
                        "Client-ID": config.twitch_client_id,
                        "Authorization": f"Bearer {auth_token}"
                    }
                    for chunk_index, username_chunk in enumerate(username_chunks):
                        params = [("user_login", username) for username in username_chunk if username]
                        params.append(("type", "live"))
                        params.append(("first", "100"))
                        self.logger.debug(f"Twitch API batch params for guild {guild_id}, batch {chunk_index + 1}: {params}")
                        async with session.get(url, headers=headers, params=params) as response:
                            if response.status != 200:
                                if response.status == 401:
                                    # Try to get bot's auth token as fallback
                                    self.logger.warning(f"401 Unauthorized for guild {guild_id} batch {chunk_index + 1}, attempting to use bot auth token")
                                    bot_auth_token = await self.mysql.fetchone(
                                        "SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = %s", 
                                        ("971436498",), database_name='website'
                                    )
                                    if bot_auth_token:
                                        if isinstance(bot_auth_token, dict):
                                            bot_auth_token = bot_auth_token.get('twitch_access_token')
                                        else:
                                            bot_auth_token = bot_auth_token[0] if bot_auth_token else None
                                        if bot_auth_token:
                                            self.logger.info(f"Retrying batch {chunk_index + 1} with bot auth token for guild {guild_id}")
                                            retry_headers = {
                                                "Client-ID": config.twitch_client_id,
                                                "Authorization": f"Bearer {bot_auth_token}"
                                            }
                                            async with session.get(url, headers=retry_headers, params=params) as retry_response:
                                                if retry_response.status == 200:
                                                    data = await retry_response.json()
                                                    batch_streams = data.get('data', [])
                                                    all_stream_data.extend(batch_streams)
                                                else:
                                                    self.logger.error(f"Failed to fetch streams batch {chunk_index + 1} with bot token for guild {guild_id}: {retry_response.status} {await retry_response.text()} | Params: {params}")
                                        else:
                                            self.logger.error(f"No valid bot auth token available for guild {guild_id} batch {chunk_index + 1}")
                                    else:
                                        self.logger.error(f"Could not retrieve bot auth token for guild {guild_id} batch {chunk_index + 1}")
                                else:
                                    self.logger.error(f"Failed to fetch streams batch {chunk_index + 1} for guild {guild_id}: {response.status} {await response.text()} | Params: {params}")
                                continue
                            data = await response.json()
                            batch_streams = data.get('data', [])
                            all_stream_data.extend(batch_streams)
                        # Small delay between requests to be API-friendly
                        if chunk_index < len(username_chunks) - 1:
                            await asyncio.sleep(0.1)
            except Exception as e:
                self.logger.error(f"Error in batch checking streams for guild {guild_id}: {e}")
                return []
        # Enrich stream data with stream URLs
        for stream in all_stream_data:
            username = stream['user_login']
            # Find the corresponding user to get their stream_url (case-insensitive match)
            user_info = next((user for user in users if str(user.get('username', '')).strip().lower() == username.lower()), None)
            if user_info:
                stream['stream_url'] = user_info['stream_url']
            else:
                stream['stream_url'] = f"https://twitch.tv/{username}"
        return all_stream_data

    async def post_live_notification(self, guild_id, stream_data, discord_channel_id):
        user_login = stream_data['user_login']
        # Resolve channel_code (api_key) from website DB for use with live_channel_manager
        mysql_helper = MySQLHelper(self.logger)
        user_row = await mysql_helper.fetchone(
            "SELECT api_key FROM users WHERE LOWER(username) = %s",
            (str(user_login).lower(),), database_name='website', dict_cursor=True
        )
        channel_code = user_row['api_key'] if user_row else None
        # If we have a live channel manager, and/or existing live_notifications, check for duplicates
        if channel_code and hasattr(self.bot, 'live_channel_manager') and self.bot.live_channel_manager:
            try:
                online = await self.bot.live_channel_manager.get_online_stream(channel_code)
            except Exception as e:
                self.logger.debug(f"Error checking live_channel_manager for {channel_code}: {e}")
                online = None
        else:
            online = None
        # Also check the live_notifications table for an already posted message for this guild & username
        try:
            ln_row = await self.mysql.fetchone("SELECT stream_id FROM live_notifications WHERE guild_id = %s AND LOWER(username) = %s", (guild_id, str(user_login).lower()), database_name='specterdiscordbot', dict_cursor=True)
        except Exception:
            ln_row = None
        # Determine candidate stream ID
        stream_id = stream_data.get('id') or stream_data.get('stream_id')
        if ln_row and ln_row.get('stream_id') and stream_id and str(ln_row.get('stream_id')) == str(stream_id):
            return False
        if online and online.get('stream_id') and stream_id and str(online.get('stream_id')) == str(stream_id):
            return False
        title = stream_data['title']
        game_name = stream_data['game_name']
        stream_url = stream_data.get('stream_url', f"https://twitch.tv/{user_login}")
        # Get user's profile image from database instead of Twitch thumbnail
        profile_image_url = await self.bot.get_user_profile_image(user_login)
        embed = discord.Embed(
            description=f"""
                ### **[{user_login}]({stream_url}) is now live on Twitch!**
                {title}{os.linesep}Playing: {game_name}
            """,
            color=discord.Color.purple()
        )
        # Set thumbnail using user's profile image or fallback
        if profile_image_url:
            embed.set_thumbnail(url=profile_image_url)
            self.logger.info(f"Using profile image for {user_login}: {profile_image_url}")
        else:
            # Fallback to default image if no profile image
            fallback_url = "https://static-cdn.jtvnw.net/ttv-static/404_preview-1280x720.jpg"
            embed.set_thumbnail(url=fallback_url)
            self.logger.info(f"Using fallback thumbnail for {user_login}: {fallback_url}")
        embed.add_field(name="Watch Here", value=f"{stream_url}", inline=True)
        embed.set_footer(text=f"Auto posted by BotOfTheSpecter | {time.strftime('%Y-%m-%d %H:%M:%S', time.localtime())}")
        try:
            channel = self.bot.get_channel(int(discord_channel_id))
        except (ValueError, TypeError):
            self.logger.error(f"Invalid discord_channel_id '{discord_channel_id}' for guild {guild_id}")
            return False
        if channel:
            # Use the bot's fallback method for sending messages
            success = await self.bot._send_message_with_fallback(
                channel=channel,
                embed=embed,
                logger_context=f"live notification for {user_login} in guild {guild_id}"
            )
            # If successfully sent, return True (DB insertion will be handled by caller)
            return success
        else:
            self.logger.error(f"Could not find Discord channel {discord_channel_id} for guild {guild_id}")
            return False

    async def process_guild_streams(self, guild_id, guild_data):
        current_streams = await self.check_streams_for_guild(guild_id, guild_data)
        discord_channel_id = guild_data['member_streams_id']
        # Get currently live users for this guild from DB
        # Fetch all live notifications for this guild
        conn = await self.mysql.get_connection('specterdiscordbot')
        guild_live_users = {}
        if conn:
            try:
                async with conn.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute("SELECT * FROM live_notifications WHERE guild_id = %s", (guild_id,))
                    rows = await cursor.fetchall()
                    for row in rows:
                        guild_live_users[row['username']] = row
            except Exception as e:
                self.logger.error(f"Error fetching live notifications for guild {guild_id}: {e}")
            finally:
                if conn:
                    conn.close()
        else:
            self.logger.error(f"Failed to get database connection for guild {guild_id} live notifications")
        # Process each current stream
        current_live_usernames = set()
        for stream in current_streams:
            username = stream['user_login']
            current_live_usernames.add(username)
            # Check if already posted in DB
            live_notification = guild_live_users.get(username)
            if live_notification and live_notification.get('stream_id') == stream['id']:
                continue  # Already posted for this stream_id, skip
            success = await self.post_live_notification(guild_id, stream, discord_channel_id)
            if success:
                # Convert Twitch API datetime format to MySQL compatible format
                started_at_raw = stream['started_at']
                try:
                    # Parse ISO 8601 format and convert to MySQL datetime format
                    started_at_dt = datetime.fromisoformat(started_at_raw.replace('Z', '+00:00'))
                    started_at = started_at_dt.strftime('%Y-%m-%d %H:%M:%S')
                except Exception as e:
                    self.logger.error(f"Error parsing started_at datetime '{started_at_raw}': {e}")
                    started_at = datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')
                posted_at = datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')
                await self.mysql.insert_live_notification(
                    guild_id, username, stream['id'], started_at, posted_at
                )
                # Use live_channel_manager to record the online state persistently
                try:
                    user_row = await self.mysql.fetchone(
                        "SELECT api_key FROM users WHERE LOWER(username) = %s",
                        (str(username).lower(),), database_name='website', dict_cursor=True
                    )
                    channel_code = user_row['api_key'] if user_row else None
                    if channel_code and hasattr(self.bot, 'live_channel_manager') and self.bot.live_channel_manager:
                        await self.bot.live_channel_manager.mark_online(
                            channel_code,
                            username=username,
                            twitch_user_id=None,
                            stream_id=stream['id'],
                            started_at=started_at,
                            details=stream
                        )
                except Exception as e:
                    self.logger.debug(f"Error marking online for {username} via live_channel_manager: {e}")
                self.logger.info(f"Started monitoring {username} for guild {guild_id} (persisted)")
        # Check for users who went offline
        previously_live = set(guild_live_users.keys())
        went_offline = previously_live - current_live_usernames
        for username in went_offline:
            # Before marking offline, check if the stream was just marked online recently
            # This prevents false offline detections due to API lag or race conditions
            try:
                if hasattr(self.bot, 'live_channel_manager') and self.bot.live_channel_manager:
                    existing = await self.bot.live_channel_manager.get_online_stream_by_username(username)
                    if existing:
                        created_at = existing.get('created_at')
                        if created_at:
                            time_diff = datetime.now(timezone.utc) - created_at.replace(tzinfo=timezone.utc) if created_at.tzinfo is None else datetime.now(timezone.utc) - created_at
                            # Only mark offline if record is older than 3 minutes (longer grace period for StreamerPostingCog)
                            if time_diff < timedelta(minutes=3):
                                self.logger.debug(f"Skipping offline mark for {username} in guild {guild_id}, stream record too recent ({time_diff.total_seconds()}s old)")
                                continue
            except Exception as e:
                self.logger.debug(f"Error checking stream age for {username}: {e}")
            # Remove the live notification from the website DB
            await self.mysql.delete_live_notification(guild_id, username)
            # Also mark offline in the live_channel_manager
            try:
                user_row = await self.mysql.fetchone(
                    "SELECT api_key FROM users WHERE LOWER(username) = %s",
                    (str(username).lower(),), database_name='website', dict_cursor=True
                )
                channel_code = user_row['api_key'] if user_row else None
                if channel_code and hasattr(self.bot, 'live_channel_manager') and self.bot.live_channel_manager:
                    await self.bot.live_channel_manager.mark_offline(channel_code)
            except Exception as e:
                self.logger.debug(f"Error marking offline via live_channel_manager for {username}: {e}")
            guild = self.bot.get_guild(guild_id)
            guild_name = guild.name if guild else f"Unknown Guild ({guild_id})"
            self.logger.info(f"User {username} went offline for guild {guild_name} (ID: {guild_id}, removed from DB)")

    async def start_monitoring(self):
        await self.bot.wait_until_ready()
        self.logger.info("StreamerPostingCog monitoring started")
        while not self.bot.is_closed():
            try:
                # Refresh guild data periodically
                await self.refresh_guild_data()
                # Process each monitored guild
                for guild_id, guild_data in self.monitored_guilds.items():
                    await self.process_guild_streams(guild_id, guild_data)
                # Log monitoring status
                total_live = len(self.live_users)
                if total_live > 0:
                    self.logger.info(f"Currently monitoring {total_live} live streams across {len(self.monitored_guilds)} guilds")
                # Wait before next check
                await asyncio.sleep(60)  # Check every minute
            except Exception as e:
                self.logger.error(f"Error in monitoring loop: {e}")
                await asyncio.sleep(60)

    def cog_unload(self):
        if hasattr(self, 'monitoring_task'):
            self.monitoring_task.cancel()

    # Legacy methods for backward compatibility
    async def get_users(self):
        self.logger.warning("get_users() is deprecated, use refresh_guild_data() instead")
        await self.refresh_guild_data()
        return [], None, None

    async def get_streams(self):
        self.logger.warning("get_streams() is deprecated, monitoring is now automatic")
        return [], None, None

    async def post_streams(self):
        self.logger.warning("post_streams() is deprecated, monitoring is now automatic via start_monitoring()")
        pass

# Button view for role assignment
class RoleButton(discord.ui.Button):
    def __init__(self, role: discord.Role, emoji, label: str, button_style=discord.ButtonStyle.primary, logger=None):
        # Only pass emoji if it's not None
        if emoji:
            super().__init__(
                style=button_style,
                label=label,
                emoji=emoji,
                custom_id=f"role_{role.id}"
            )
        else:
            super().__init__(
                style=button_style,
                label=label,
                custom_id=f"role_{role.id}"
            )
        self.role = role
        self.logger = logger

    async def callback(self, interaction: discord.Interaction):
        try:
            member = interaction.user
            guild_name = interaction.guild.name if interaction.guild else "DM"
            self.logger.info(f"[ROLE_BUTTON] Role button clicked by {member.name}#{member.discriminator} ({member.id}) for role '{self.role.name}' in {guild_name}")
            self.logger.info(f"[ROLE_BUTTON] Interaction ID: {interaction.id}, Guild ID: {interaction.guild_id}, Channel ID: {interaction.channel_id}")
            self.logger.info(f"[ROLE_BUTTON] Bot permissions in channel: {interaction.app_permissions if interaction.app_permissions else 'Unknown'}")
            self.logger.info(f"[ROLE_BUTTON] Role hierarchy - Target role position: {self.role.position}, Bot top role position: {interaction.guild.me.top_role.position}")
            # Defer the interaction immediately to prevent timeout
            await interaction.response.defer(ephemeral=True)
            self.logger.info(f"[ROLE_BUTTON] Interaction deferred successfully")
            if self.role in member.roles:
                try:
                    self.logger.info(f"[ROLE_BUTTON] User has role, attempting to remove...")
                    await member.remove_roles(self.role, reason="Role button - user requested removal")
                    self.logger.info(f"[ROLE_BUTTON] Role removed, sending followup response...")
                    await interaction.followup.send(f"✅ Removed role **{self.role.name}**", ephemeral=True)
                    self.logger.info(f"[ROLE_BUTTON] Response sent - Successfully removed role '{self.role.name}' from {member.name}#{member.discriminator}")
                except discord.Forbidden as e:
                    self.logger.error(f"[ROLE_BUTTON] Forbidden error when removing role: {e}")
                    await interaction.followup.send("❌ I don't have permission to remove this role. Please contact a server administrator.", ephemeral=True)
                    self.logger.error(f"[ROLE_BUTTON] Missing permissions to remove role '{self.role.name}' from {member.name}#{member.discriminator}")
                except Exception as e:
                    self.logger.error(f"[ROLE_BUTTON] Exception when removing role: {type(e).__name__} - {e}")
                    self.logger.error(f"[ROLE_BUTTON] Traceback: {traceback.format_exc()}")
                    await interaction.followup.send("❌ An error occurred while removing the role. Please try again or contact a server administrator.", ephemeral=True)
                    self.logger.error(f"[ROLE_BUTTON] Error removing role '{self.role.name}' from {member.name}#{member.discriminator}: {e}")
            else:
                try:
                    self.logger.info(f"[ROLE_BUTTON] User doesn't have role, attempting to add...")
                    await member.add_roles(self.role, reason="Role button - user requested assignment")
                    self.logger.info(f"[ROLE_BUTTON] Role added, sending followup response...")
                    await interaction.followup.send(f"✅ Added role **{self.role.name}**", ephemeral=True)
                    self.logger.info(f"[ROLE_BUTTON] Response sent - Successfully added role '{self.role.name}' to {member.name}#{member.discriminator}")
                except discord.Forbidden as e:
                    self.logger.error(f"[ROLE_BUTTON] Forbidden error when adding role: {e}")
                    await interaction.followup.send("❌ I don't have permission to assign this role. Please contact a server administrator.", ephemeral=True)
                    self.logger.error(f"[ROLE_BUTTON] Missing permissions to assign role '{self.role.name}' to {member.name}#{member.discriminator}")
                except Exception as e:
                    self.logger.error(f"[ROLE_BUTTON] Exception when adding role: {type(e).__name__} - {e}")
                    self.logger.error(f"[ROLE_BUTTON] Traceback: {traceback.format_exc()}")
                    await interaction.followup.send("❌ An error occurred while assigning the role. Please try again or contact a server administrator.", ephemeral=True)
                    self.logger.error(f"[ROLE_BUTTON] Error assigning role '{self.role.name}' to {member.name}#{member.discriminator}: {e}")
        except Exception as e:
            self.logger.error(f"[ROLE_BUTTON] Unexpected error in RoleButton callback for user {interaction.user.name}#{interaction.user.discriminator}: {type(e).__name__} - {e}")
            self.logger.error(f"[ROLE_BUTTON] Full traceback: {traceback.format_exc()}")
            try:
                if not interaction.response.is_done():
                    await interaction.response.defer(ephemeral=True)
                await interaction.followup.send("❌ An unexpected error occurred. Please try again or contact a server administrator.", ephemeral=True)
                self.logger.info(f"[ROLE_BUTTON] Error response sent successfully")
            except discord.InteractionResponded:
                self.logger.warning(f"[ROLE_BUTTON] Interaction already responded for role button error with {interaction.user.name}#{interaction.user.discriminator}")
            except Exception as response_error:
                self.logger.error(f"[ROLE_BUTTON] Failed to send error response for role button: {type(response_error).__name__} - {response_error}")

class RoleButtonView(discord.ui.View):
    def __init__(self):
        super().__init__(timeout=None)  # No timeout - buttons persist forever

# Button for rules acceptance
class RulesAcceptButton(discord.ui.Button):
    def __init__(self, role: discord.Role, logger=None):
        super().__init__(
            style=discord.ButtonStyle.success,
            label="ACCEPT RULES",
            emoji="✅",
            custom_id=f"rules_accept_{role.id}"
        )
        self.role = role
        self.logger = logger

    async def callback(self, interaction: discord.Interaction):
        try:
            user = interaction.user
            guild_name = interaction.guild.name if interaction.guild else "DM"
            self.logger.info(f"[RULES_ACCEPT] Rules accept button clicked by {user.name}#{user.discriminator} ({user.id}) for role '{self.role.name}' in {guild_name}")
            self.logger.info(f"[RULES_ACCEPT] Interaction ID: {interaction.id}, Guild ID: {interaction.guild_id}, Channel ID: {interaction.channel_id}")
            self.logger.info(f"[RULES_ACCEPT] Bot permissions in channel: {interaction.app_permissions if interaction.app_permissions else 'Unknown'}")
            self.logger.info(f"[RULES_ACCEPT] Role hierarchy - Target role position: {self.role.position}, Bot top role position: {interaction.guild.me.top_role.position}")
            # Defer the interaction immediately to prevent timeout
            await interaction.response.defer(ephemeral=True)
            self.logger.info(f"[RULES_ACCEPT] Interaction deferred successfully")
            # Check if user already has the role
            if self.role in user.roles:
                self.logger.info(f"[RULES_ACCEPT] User already has role, sending followup response...")
                await interaction.followup.send(
                    "✅ You have already accepted the rules!",
                    ephemeral=True
                )
                self.logger.info(f"[RULES_ACCEPT] Response sent successfully - User {user.name}#{user.discriminator} tried to accept rules again - already has role '{self.role.name}'")
                return
            # Assign the role
            try:
                self.logger.info(f"[RULES_ACCEPT] Attempting to add role '{self.role.name}' to user...")
                await user.add_roles(self.role, reason="Accepted server rules via button")
                self.logger.info(f"[RULES_ACCEPT] Role added successfully, sending followup response...")
                await interaction.followup.send(
                    "✅ Thank you for accepting the rules! You now have access to the server. 🎉",
                    ephemeral=True
                )
                self.logger.info(f"[RULES_ACCEPT] Response sent successfully - assigned rules acceptance role '{self.role.name}' to {user.name}#{user.discriminator}")
            except discord.Forbidden as e:
                self.logger.error(f"[RULES_ACCEPT] Forbidden error when assigning role: {e}")
                await interaction.followup.send(
                    "❌ I don't have permission to assign roles. Please contact a server administrator.",
                    ephemeral=True
                )
                self.logger.error(f"[RULES_ACCEPT] Missing permissions to assign rules role '{self.role.name}' to {user.name}#{user.discriminator}")
            except Exception as e:
                self.logger.error(f"[RULES_ACCEPT] Exception when assigning role: {type(e).__name__} - {e}")
                self.logger.error(f"[RULES_ACCEPT] Traceback: {traceback.format_exc()}")
                await interaction.followup.send(
                    "❌ An error occurred while assigning your role. Please contact a server administrator.",
                    ephemeral=True
                )
                self.logger.error(f"[RULES_ACCEPT] Error assigning rules role '{self.role.name}' to {user.name}#{user.discriminator}: {e}")
        except Exception as e:
            self.logger.error(f"[RULES_ACCEPT] Unexpected error in RulesAcceptButton callback for user {interaction.user.name}#{interaction.user.discriminator}: {type(e).__name__} - {e}")
            self.logger.error(f"[RULES_ACCEPT] Full traceback: {traceback.format_exc()}")
            try:
                if not interaction.response.is_done():
                    await interaction.response.defer(ephemeral=True)
                await interaction.followup.send("❌ An unexpected error occurred. Please try again or contact a server administrator.", ephemeral=True)
                self.logger.info(f"[RULES_ACCEPT] Error response sent successfully")
            except discord.InteractionResponded:
                self.logger.warning(f"[RULES_ACCEPT] Interaction already responded for rules button error with {interaction.user.name}#{interaction.user.discriminator}")
            except Exception as response_error:
                self.logger.error(f"[RULES_ACCEPT] Failed to send error response for rules button: {type(response_error).__name__} - {response_error}")

class RulesButtonView(discord.ui.View):
    def __init__(self, role: discord.Role, logger=None):
        super().__init__(timeout=None)
        self.add_item(RulesAcceptButton(role, logger))

# Server Management class for handling reaction roles and other server features
class ServerManagement(commands.Cog, name='Server Management'):
    def __init__(self, bot: BotOfTheSpecter, logger=None):
        self.bot = bot
        self.logger = logger or logging.getLogger(self.__class__.__name__)
        self.mysql = MySQLHelper(logger)
        # Cache for reaction role configurations: {message_id: {config}}
        self.reaction_roles_cache = {}
        # Cache for timezone update tasks: {(server_id, timezone_message_id): asyncio.Task}
        self.timezone_update_tasks = {}
        asyncio.create_task(self._refresh_reaction_roles_cache())
        asyncio.create_task(self._resume_stream_schedule_updates())

    async def _resume_stream_schedule_updates(self):
        try:
            self.logger.info("Attempting to resume stream schedule timezone updates...")
            # Wait a moment for the bot to be fully ready
            await asyncio.sleep(2)
            # Fetch all stream schedule messages from database
            query = "SELECT server_id, channel_id, message_id, timezone_message_id, timezone FROM stream_schedule_messages WHERE timezone_message_id IS NOT NULL AND timezone IS NOT NULL"
            schedules = await self.mysql.fetchall(query, database_name='specterdiscordbot', dict_cursor=True)
            if not schedules:
                self.logger.info("No stream schedule messages found in database")
                return
            self.logger.info(f"Found {len(schedules)} stream schedule message(s) to resume")
            for schedule in schedules:
                try:
                    server_id = str(schedule['server_id'])
                    channel_id = str(schedule['channel_id'])
                    timezone_message_id = str(schedule['timezone_message_id'])
                    timezone = schedule['timezone']
                    # Get the guild
                    guild = self.bot.get_guild(int(server_id))
                    if not guild:
                        self.logger.warning(f"Guild {server_id} not found, skipping stream schedule resume")
                        continue
                    # Verify the timezone message still exists
                    try:
                        channel = guild.get_channel(int(channel_id))
                        if not channel:
                            self.logger.warning(f"Channel {channel_id} not found in guild {server_id}, skipping")
                            continue
                        message = await channel.fetch_message(int(timezone_message_id))
                        self.logger.info(f"Resuming timezone update for server {server_id}, timezone: {timezone}")
                        # Start the background update task for this timezone message
                        await self._start_timezone_update_task(server_id, channel_id, timezone_message_id, timezone, guild.name)
                    except discord.NotFound:
                        self.logger.info(f"Timezone message {timezone_message_id} no longer exists in channel {channel_id}, skipping")
                    except discord.Forbidden:
                        self.logger.warning(f"Missing permissions to access message in channel {channel_id}")
                    except Exception as e:
                        self.logger.error(f"Error verifying timezone message {timezone_message_id}: {e}")
                except Exception as e:
                    self.logger.error(f"Error resuming stream schedule for server {server_id}: {e}")
        except Exception as e:
            self.logger.error(f"Error in _resume_stream_schedule_updates: {e}")
            self.logger.error(f"Traceback: {traceback.format_exc()}")

    async def _refresh_reaction_roles_cache(self):
        try:
            query = "SELECT server_id, channel_id, message_id, message_text, mappings, role_mappings, allow_multiple FROM role_selection_messages"
            results = await self.mysql.fetchall(query, database_name='specterdiscordbot')
            # Clear existing cache
            self.reaction_roles_cache.clear()
            for row in results:
                try:
                    server_id = str(row[0])
                    channel_id = str(row[1])
                    message_id = str(row[2])
                    message_text = row[3]
                    mappings = row[4]
                    role_mappings_json = row[5]
                    allow_multiple = bool(row[6])
                    # Parse role_mappings JSON
                    if isinstance(role_mappings_json, str):
                        role_mappings = json.loads(role_mappings_json)
                    else:
                        role_mappings = role_mappings_json
                    # Store in cache by message_id
                    self.reaction_roles_cache[int(message_id)] = {
                        'server_id': server_id,
                        'channel_id': channel_id,
                        'message': message_text,
                        'mappings': mappings,
                        'role_mappings': role_mappings,
                        'allow_multiple': allow_multiple
                    }
                    self.logger.debug(f"Cached role selection message {message_id} for server {server_id}")
                except Exception as e:
                    self.logger.error(f"Error processing role selection message for server {row[0]}: {e}")
            self.logger.info(f"Role selection cache refreshed: {len(self.reaction_roles_cache)} messages loaded")
        except Exception as e:
            self.logger.error(f"Error refreshing role selection cache: {e}")

    async def post_reaction_roles_message(self, data):
        try:
            self.logger.info(f"post_reaction_roles_message called with data: {data}")
            server_id = data.get('server_id')
            channel_id = data.get('channel_id')
            message = data.get('message', '')
            mappings = data.get('mappings', '')
            allow_multiple = data.get('allow_multiple', False)
            self.logger.info(f"Parsed data - server_id: {server_id}, channel_id: {channel_id}, message length: {len(message)}, mappings length: {len(mappings)}")
            if not server_id or not channel_id:
                self.logger.error("Missing server_id or channel_id in reaction roles message data")
                return
            # Get the guild
            try:
                guild = self.bot.get_guild(int(server_id))
                self.logger.info(f"Got guild: {guild.name if guild else 'None'} (ID: {server_id})")
            except (ValueError, TypeError) as e:
                self.logger.error(f"Invalid server_id: {server_id}, error: {e}")
                return
            if not guild:
                self.logger.error(f"Bot not in guild {server_id}")
                return
            # Get the channel
            try:
                channel = guild.get_channel(int(channel_id))
                self.logger.info(f"Got channel: #{channel.name if channel else 'None'} (ID: {channel_id})")
            except (ValueError, TypeError) as e:
                self.logger.error(f"Invalid channel_id: {channel_id}, error: {e}")
                return
            if not channel:
                self.logger.error(f"Channel {channel_id} not found in guild {guild.name}")
                return
            # Create button view
            view = RoleButtonView()
            role_mappings = {}
            # Parse mappings and create buttons
            self.logger.info(f"Attempting to send message to #{channel.name}: {message}")
            if mappings:
                mapping_lines = mappings.strip().split('\n')
                self.logger.info(f"Processing {len(mapping_lines)} role button mappings")
                for line in mapping_lines:
                    line = line.strip()
                    if not line or '@' not in line:
                        continue
                    try:
                        # Parse format: either ":emoji: Description @Role" or "Description @Role"
                        emoji_to_use = None
                        emoji_name = None
                        button_style = discord.ButtonStyle.primary  # Default style
                        if line.startswith(':') and line.count(':') >= 2:
                            # Format with emoji: ":emoji_name: Description @Role [color]"
                            first_colon = line.index(':', 1)
                            emoji_name = line[1:first_colon]
                            rest = line[first_colon+1:].strip()
                            # Check for custom server emoji
                            custom_emoji = discord.utils.get(guild.emojis, name=emoji_name)
                            emoji_to_use = custom_emoji if custom_emoji else None
                        else:
                            # Format without emoji: "Description @Role [color]"
                            rest = line
                        # Extract description, role name, and optional color
                        if '@' in rest:
                            description, role_part = rest.rsplit('@', 1)
                            description = description.strip()
                            # Check for optional color in square brackets
                            if '[' in role_part and ']' in role_part:
                                # Extract role name and color
                                role_name = role_part[:role_part.index('[')].strip()
                                color_spec = role_part[role_part.index('[')+1:role_part.index(']')].strip().lower()
                                # Map color names to Discord button styles
                                color_map = {
                                    'primary': discord.ButtonStyle.primary,
                                    'blue': discord.ButtonStyle.primary,
                                    'blurple': discord.ButtonStyle.primary,
                                    'secondary': discord.ButtonStyle.secondary,
                                    'gray': discord.ButtonStyle.secondary,
                                    'grey': discord.ButtonStyle.secondary,
                                    'success': discord.ButtonStyle.success,
                                    'green': discord.ButtonStyle.success,
                                    'danger': discord.ButtonStyle.danger,
                                    'red': discord.ButtonStyle.danger
                                }
                                button_style = color_map.get(color_spec, discord.ButtonStyle.primary)
                                self.logger.info(f"Color specified: {color_spec} -> {button_style.name}")
                            else:
                                role_name = role_part.strip()
                            # Find role by name
                            role = discord.utils.get(guild.roles, name=role_name)
                            if role:
                                # Create and add button (with or without emoji, with specified color)
                                button = RoleButton(role, emoji_to_use, description, button_style, self.logger)
                                view.add_item(button)
                                # Store mapping
                                if emoji_name:
                                    role_mappings[f':{emoji_name}:'] = str(role.id)
                                else:
                                    role_mappings[description] = str(role.id)
                                emoji_status = "with emoji" if emoji_to_use else "text-only"
                                self.logger.info(f"Added {emoji_status} {button_style.name} button for role {role_name} with label '{description}'")
                            else:
                                self.logger.warning(f"Role '{role_name}' not found in guild {guild.name}")
                    except Exception as e:
                        self.logger.error(f"Error processing mapping line '{line}': {e}")
            try:
                # Check for existing message and delete it before sending new one
                try:
                    query = "SELECT channel_id, message_id FROM role_selection_messages WHERE server_id = %s"
                    result = await self.mysql.fetchone(query, params=(server_id,), database_name='specterdiscordbot')
                    if result:
                        old_channel_id = result[0]
                        old_message_id = result[1]
                        if old_message_id and old_channel_id:
                            try:
                                # Try to get the old channel (it might be different from current)
                                old_channel = guild.get_channel(int(old_channel_id))
                                if old_channel:
                                    try:
                                        # Fetch and delete the old message
                                        old_message = await old_channel.fetch_message(int(old_message_id))
                                        await old_message.delete()
                                        self.logger.info(f"Deleted old role selection message (ID: {old_message_id}) from #{old_channel.name}")
                                    except discord.NotFound:
                                        self.logger.info(f"Old message (ID: {old_message_id}) no longer exists, proceeding with new message")
                                    except discord.Forbidden:
                                        self.logger.warning(f"Missing permissions to delete old message (ID: {old_message_id})")
                                    except Exception as e:
                                        self.logger.error(f"Error deleting old message: {e}")
                                # Remove old message from cache if it exists
                                if int(old_message_id) in self.reaction_roles_cache:
                                    del self.reaction_roles_cache[int(old_message_id)]
                                    self.logger.debug(f"Removed old message {old_message_id} from cache")
                            except Exception as e:
                                self.logger.error(f"Error cleaning up old message: {e}")
                except Exception as e:
                    self.logger.error(f"Error checking for existing message: {e}")
                # Create embed for the message
                embed = discord.Embed(
                    title="Role Selection",
                    description=message,
                    color=config.bot_color
                )
                embed.set_footer(text=f"Click the buttons below to get or remove roles | {guild.name}")
                # Send embed with buttons
                sent_message = await channel.send(embed=embed, view=view)
                message_id = sent_message.id
                self.logger.info(f"Successfully posted role selection embed (ID: {message_id}) with {len(view.children)} buttons to #{channel.name}")
                # Save to database using INSERT ... ON DUPLICATE KEY UPDATE
                try:
                    self.logger.info(f"Preparing to save to database - server_id: {server_id}, channel_id: {channel_id}, message_id: {message_id}")
                    self.logger.info(f"Message text: {message[:100] if message else 'None'}...")
                    self.logger.info(f"Mappings: {mappings[:100] if mappings else 'None'}...")
                    self.logger.info(f"Role mappings dict: {role_mappings}")
                    self.logger.info(f"Allow multiple: {allow_multiple}")
                    # Verify table exists first
                    check_query = "SHOW TABLES LIKE 'role_selection_messages'"
                    table_exists = await self.mysql.fetchone(check_query, database_name='specterdiscordbot')
                    if not table_exists:
                        self.logger.error("Table role_selection_messages does not exist!")
                    insert_query = """
                        INSERT INTO role_selection_messages 
                        (server_id, channel_id, message_id, message_text, mappings, role_mappings, allow_multiple)
                        VALUES (%s, %s, %s, %s, %s, %s, %s)
                        ON DUPLICATE KEY UPDATE
                        channel_id = %s,
                        message_id = %s,
                        message_text = %s,
                        mappings = %s,
                        role_mappings = %s,
                        allow_multiple = %s
                    """
                    # For ON DUPLICATE KEY UPDATE, we need to provide values twice
                    role_mappings_json = json.dumps(role_mappings)
                    params = (
                        str(server_id), str(channel_id), str(message_id), message, mappings, role_mappings_json, bool(allow_multiple),
                        str(channel_id), str(message_id), message, mappings, role_mappings_json, bool(allow_multiple)
                    )
                    self.logger.info(f"Executing INSERT with {len(params)} parameters")
                    self.logger.debug(f"Parameters: {params}")
                    result = await self.mysql.execute(
                        insert_query, 
                        params=params,
                        database_name='specterdiscordbot'
                    )
                    self.logger.info(f"Successfully executed INSERT for message {message_id}, affected rows: {result}")
                    # Update cache
                    self.reaction_roles_cache[message_id] = {
                        'server_id': server_id,
                        'channel_id': channel_id,
                        'message': message,
                        'mappings': mappings,
                        'role_mappings': role_mappings,
                        'allow_multiple': allow_multiple
                    }
                    self.logger.info(f"Saved role selection message (ID: {message_id}) to database for server {server_id}")
                except Exception as e:
                    self.logger.error(f"Error saving role selection message to database: {e}")
                    self.logger.error(f"Traceback: {traceback.format_exc()}")
            except discord.Forbidden:
                self.logger.error(f"Missing permissions to send messages in #{channel.name} (ID: {channel_id})")
            except discord.HTTPException as e:
                self.logger.error(f"Discord HTTP error sending reaction roles message: {e}")
            except Exception as e:
                self.logger.error(f"Unexpected error sending reaction roles message: {e}")
        except Exception as e:
            self.logger.error(f"Error in post_reaction_roles_message: {e}")

    async def post_rules_message(self, data):
        try:
            self.logger.info(f"post_rules_message called with data: {data}")
            server_id = data.get('server_id')
            channel_id = data.get('channel_id')
            title = data.get('title', 'Server Rules')
            rules = data.get('rules', '')
            color_hex = data.get('color', '#5865f2')
            accept_role_id = data.get('accept_role_id', '')
            self.logger.info(f"Parsed data - server_id: {server_id}, channel_id: {channel_id}, title: {title}, color: {color_hex}, accept_role_id: {accept_role_id}")
            if not server_id or not channel_id:
                self.logger.error("Missing server_id or channel_id in rules message data")
                return
            # Get the guild
            try:
                guild = self.bot.get_guild(int(server_id))
                self.logger.info(f"Got guild: {guild.name if guild else 'None'} (ID: {server_id})")
            except (ValueError, TypeError) as e:
                self.logger.error(f"Invalid server_id: {server_id}, error: {e}")
                return
            if not guild:
                self.logger.error(f"Bot not in guild {server_id}")
                return
            # Get the channel
            try:
                channel = guild.get_channel(int(channel_id))
                self.logger.info(f"Got channel: #{channel.name if channel else 'None'} (ID: {channel_id})")
            except (ValueError, TypeError) as e:
                self.logger.error(f"Invalid channel_id: {channel_id}, error: {e}")
                return
            if not channel:
                self.logger.error(f"Channel {channel_id} not found in guild {guild.name}")
                return
            # Check for existing rules message and delete it
            try:
                query = "SELECT channel_id, message_id FROM rules_messages WHERE server_id = %s"
                existing_message = await self.mysql.fetchone(query, params=(str(server_id),), database_name='specterdiscordbot', dict_cursor=True)
                if existing_message:
                    old_channel_id = existing_message['channel_id']
                    old_message_id = existing_message['message_id']
                    self.logger.info(f"Found existing rules message (ID: {old_message_id}) in channel {old_channel_id}")
                    try:
                        # Try to delete the old message
                        old_channel = guild.get_channel(int(old_channel_id))
                        if old_channel:
                            try:
                                old_message = await old_channel.fetch_message(int(old_message_id))
                                await old_message.delete()
                                self.logger.info(f"Deleted old rules message (ID: {old_message_id}) from #{old_channel.name}")
                            except discord.NotFound:
                                self.logger.info(f"Old rules message (ID: {old_message_id}) no longer exists, proceeding with new message")
                            except discord.Forbidden:
                                self.logger.warning(f"Missing permissions to delete old rules message (ID: {old_message_id})")
                            except Exception as e:
                                self.logger.error(f"Error deleting old rules message: {e}")
                    except Exception as e:
                        self.logger.error(f"Error cleaning up old rules message: {e}")
            except Exception as e:
                self.logger.error(f"Error checking for existing rules message: {e}")
            # Convert hex color to Discord color
            try:
                # Remove # if present and convert to integer
                color_int = int(color_hex.replace('#', ''), 16)
                embed_color = discord.Color(color_int)
                self.logger.info(f"Converted color {color_hex} to Discord color")
            except (ValueError, TypeError) as e:
                self.logger.warning(f"Invalid color {color_hex}, using default Discord blurple: {e}")
                embed_color = discord.Color.blurple()
            # Create embed for the rules
            embed = discord.Embed(
                title=title,
                description=rules,
                color=embed_color
            )
            embed.set_footer(text=f"Server Rules | {guild.name}")
            try:
                # Send the embed with button if accept_role_id is set
                if accept_role_id:
                    try:
                        self.logger.info(f"[POST_RULES] Accept role ID provided: {accept_role_id}, attempting to get role object...")
                        # Get the role object
                        accept_role = guild.get_role(int(accept_role_id))
                        if not accept_role:
                            self.logger.error(f"[POST_RULES] Accept role {accept_role_id} not found in guild {guild.name}")
                            # Send without button
                            sent_message = await channel.send(embed=embed)
                        else:
                            self.logger.info(f"[POST_RULES] Found accept role: {accept_role.name} (ID: {accept_role.id}, Position: {accept_role.position})")
                            self.logger.info(f"[POST_RULES] Bot's highest role: {guild.me.top_role.name} (Position: {guild.me.top_role.position})")
                            # Create button view with the role
                            self.logger.info(f"[POST_RULES] Creating RulesButtonView...")
                            view = RulesButtonView(accept_role, self.logger)
                            self.logger.info(f"[POST_RULES] Sending message with embed and view...")
                            sent_message = await channel.send(embed=embed, view=view)
                            self.logger.info(f"[POST_RULES] Successfully posted rules embed with ACCEPT RULES button to #{channel.name} (Message ID: {sent_message.id})")
                    except (ValueError, TypeError) as e:
                        self.logger.error(f"[POST_RULES] Invalid accept_role_id: {accept_role_id}, error: {type(e).__name__} - {e}")
                        # Send without button
                        sent_message = await channel.send(embed=embed)
                    except Exception as e:
                        self.logger.error(f"[POST_RULES] Error creating rules button: {type(e).__name__} - {e}")
                        self.logger.error(f"[POST_RULES] Traceback: {traceback.format_exc()}")
                        # Send without button as fallback
                        sent_message = await channel.send(embed=embed)
                else:
                    # No accept role, send without button
                    self.logger.info(f"[POST_RULES] No accept_role_id provided, sending without button")
                    sent_message = await channel.send(embed=embed)
                message_id = sent_message.id
                self.logger.info(f"Successfully posted rules embed (ID: {message_id}) to #{channel.name}")
                # Save to database using INSERT ... ON DUPLICATE KEY UPDATE
                try:
                    self.logger.info(f"Preparing to save to database - server_id: {server_id}, channel_id: {channel_id}, message_id: {message_id}")
                    # Verify table exists first
                    check_query = "SHOW TABLES LIKE 'rules_messages'"
                    table_exists = await self.mysql.fetchone(check_query, database_name='specterdiscordbot')
                    if not table_exists:
                        self.logger.error("Table rules_messages does not exist!")
                    insert_query = """
                        INSERT INTO rules_messages 
                        (server_id, channel_id, message_id, title, rules_content, color, accept_role_id)
                        VALUES (%s, %s, %s, %s, %s, %s, %s)
                        ON DUPLICATE KEY UPDATE
                        channel_id = %s,
                        message_id = %s,
                        title = %s,
                        rules_content = %s,
                        color = %s,
                        accept_role_id = %s
                    """
                    # For ON DUPLICATE KEY UPDATE, we need to provide values twice
                    params = (
                        str(server_id), str(channel_id), str(message_id), title, rules, color_hex, str(accept_role_id) if accept_role_id else '',
                        str(channel_id), str(message_id), title, rules, color_hex, str(accept_role_id) if accept_role_id else ''
                    )
                    self.logger.info(f"Executing INSERT with {len(params)} parameters")
                    result = await self.mysql.execute(
                        insert_query, 
                        params=params,
                        database_name='specterdiscordbot'
                    )
                    self.logger.info(f"Successfully saved rules message (ID: {message_id}) to database for server {server_id}, affected rows: {result}")
                except Exception as e:
                    self.logger.error(f"Error saving rules message to database: {e}")
                    self.logger.error(f"Traceback: {traceback.format_exc()}")
            except discord.Forbidden:
                self.logger.error(f"Missing permissions to send messages in #{channel.name} (ID: {channel_id})")
            except discord.HTTPException as e:
                self.logger.error(f"Discord HTTP error sending rules message: {e}")
            except Exception as e:
                self.logger.error(f"Unexpected error sending rules message: {e}")
        except Exception as e:
            self.logger.error(f"Error in post_rules_message: {e}")
            self.logger.error(f"Traceback: {traceback.format_exc()}")

    def _generate_timezone_conversions(self, base_timezone: str) -> str:
        try:
            # Common timezone mappings
            common_zones = {
                'UTC': 'UTC',
                'EST': 'US/Eastern',
                'CST': 'US/Central',
                'MST': 'US/Mountain',
                'PST': 'US/Pacific',
                'GMT': 'Europe/London',
                'CET': 'Europe/Paris',
                'IST': 'Asia/Kolkata',
                'JST': 'Asia/Tokyo',
                'AEST': 'Australia/Sydney',
            }
            # Try to parse the base timezone
            try:
                if base_timezone.upper() in common_zones:
                    base_tz_name = common_zones[base_timezone.upper()]
                    base_tz = pytz.timezone(base_tz_name)
                    base_tz_display = base_timezone.upper()
                else:
                    # Try direct timezone name
                    base_tz_name = base_timezone
                    base_tz = pytz.timezone(base_timezone)
                    base_tz_display = base_timezone
            except (pytz.exceptions.UnknownTimeZoneError, Exception):
                self.logger.warning(f"Unknown timezone: {base_timezone}, defaulting to UTC")
                base_tz_name = 'UTC'
                base_tz = pytz.UTC
                base_tz_display = 'UTC'
            # Get current UTC time first, then convert to target timezone
            now_utc = datetime.now(pytz.UTC)
            # Convert UTC time to base timezone
            now_base = now_utc.astimezone(base_tz)
            base_time_str = now_base.strftime("%H:%M %Z")
            # Build conversion string with base timezone first, then main timezones
            conversion_lines = [f"**{base_tz_display}**: {base_time_str}"]
            # Add conversions for major timezones in order: UTC, US East, US West, US Central, US Mountain, London, Tokyo
            conversion_zones = [
                ('UTC', 'UTC'),
                ('America/New_York', 'US East'),
                ('America/Los_Angeles', 'US West'),
                ('America/Chicago', 'US Central'),
                ('America/Denver', 'US Mountain'),
                ('Europe/London', 'London'),
                ('Asia/Tokyo', 'Tokyo'),
            ]
            for tz_name, tz_display in conversion_zones:
                # Skip if this timezone is the same as the base timezone
                if tz_name != base_tz_name:
                    try:
                        tz = pytz.timezone(tz_name)
                        now_tz = now_utc.astimezone(tz)
                        time_str = now_tz.strftime("%H:%M")
                        # Calculate time difference between base timezone and this timezone
                        base_offset = now_base.utcoffset().total_seconds()
                        tz_offset = now_tz.utcoffset().total_seconds()
                        diff_seconds = tz_offset - base_offset
                        diff_hours = int(diff_seconds / 3600)
                        diff_minutes = abs(int((diff_seconds % 3600) / 60))
                        # Format the time difference
                        if diff_hours == 0 and diff_minutes == 0:
                            diff_str = "(Same)"
                        elif diff_minutes == 0:
                            sign = "+" if diff_hours > 0 else ""
                            diff_str = f"({sign}{diff_hours}h)"
                        else:
                            sign = "+" if diff_hours >= 0 else ""
                            diff_str = f"({sign}{diff_hours}h {diff_minutes}m)" if diff_hours >= 0 else f"({diff_hours}h {diff_minutes}m)"
                        conversion_lines.append(f"**{tz_display}**: {time_str} {diff_str}")
                    except Exception as e:
                        self.logger.warning(f"Could not convert to {tz_name}: {e}")
            return "\n".join(conversion_lines)
        except ImportError:
            self.logger.warning("pytz not available, showing base timezone only")
            return f"**{base_timezone}** (install pytz for timezone conversions)"
        except Exception as e:
            self.logger.error(f"Error generating timezone conversions: {e}")
            return f"**{base_timezone}**"

    def _get_base_timezone_time(self, base_timezone: str) -> str:
        try:
            # Common timezone mappings
            common_zones = {
                'UTC': 'UTC',
                'EST': 'US/Eastern',
                'CST': 'US/Central',
                'MST': 'US/Mountain',
                'PST': 'US/Pacific',
                'GMT': 'Europe/London',
                'CET': 'Europe/Paris',
                'IST': 'Asia/Kolkata',
                'JST': 'Asia/Tokyo',
                'AEST': 'Australia/Sydney',
            }
            # Try to parse the base timezone
            try:
                if base_timezone.upper() in common_zones:
                    base_tz_name = common_zones[base_timezone.upper()]
                    base_tz = pytz.timezone(base_tz_name)
                    base_tz_display = base_timezone.upper()
                else:
                    # Try direct timezone name
                    base_tz_name = base_timezone
                    base_tz = pytz.timezone(base_timezone)
                    base_tz_display = base_timezone
            except Exception:
                self.logger.warning(f"Unknown timezone: {base_timezone}, defaulting to UTC")
                base_tz = pytz.UTC
                base_tz_display = 'UTC'
            # Get current UTC time first, then convert to target timezone
            now_utc = datetime.now(pytz.UTC)
            # Convert UTC time to base timezone
            now_base = now_utc.astimezone(base_tz)
            base_time_str = now_base.strftime("%H:%M %Z")
            return f"**{base_tz_display}**: {base_time_str}"
        except ImportError:
            return f"**{base_timezone}**"
        except Exception as e:
            self.logger.error(f"Error getting base timezone time: {e}")
            return f"**{base_timezone}**"

    def _create_timezone_embed(self, base_timezone: str, guild_name: str) -> discord.Embed:
        try:
            timezone_info = self._generate_timezone_conversions(base_timezone)
            embed = discord.Embed(
                title="⏰ Timezone Conversions",
                description=timezone_info,
                color=discord.Color.gold()
            )
            embed.set_footer(text=f"Updates every minute | {guild_name}")
            return embed
        except Exception as e:
            self.logger.error(f"Error creating timezone embed: {e}")
            return discord.Embed(
                title="⏰ Stream Schedule - Timezone",
                description=f"**{base_timezone}**",
                color=discord.Color.gold()
            )

    async def _start_timezone_update_task(self, server_id: str, channel_id: str, timezone_message_id: str, base_timezone: str, guild_name: str):
        try:
            task_key = (server_id, timezone_message_id)
            # Cancel any existing task for this timezone message
            if task_key in self.timezone_update_tasks:
                old_task = self.timezone_update_tasks[task_key]
                if not old_task.done():
                    old_task.cancel()
                    self.logger.info(f"Cancelled old timezone update task for server {server_id}")
            # Create and start the new update task
            update_task = asyncio.create_task(
                self._update_timezone_message_loop(server_id, channel_id, timezone_message_id, base_timezone, guild_name)
            )
            self.timezone_update_tasks[task_key] = update_task
            self.logger.info(f"Started timezone update task for server {server_id}")
        except Exception as e:
            self.logger.error(f"Error starting timezone update task: {e}")

    async def _update_timezone_message_loop(self, server_id: str, channel_id: str, timezone_message_id: str, base_timezone: str, guild_name: str):
        try:
            guild = self.bot.get_guild(int(server_id))
            if not guild:
                self.logger.error(f"Guild {server_id} not found for timezone updates")
                return
            channel = guild.get_channel(int(channel_id))
            if not channel:
                self.logger.error(f"Channel {channel_id} not found in guild {server_id} for timezone updates")
                return
            self.logger.info(f"Starting timezone update loop for message {timezone_message_id} in channel {channel_id}")
            update_count = 0
            while True:
                try:
                    update_count += 1
                    # Fetch and update the message
                    try:
                        message = await channel.fetch_message(int(timezone_message_id))
                        new_embed = self._create_timezone_embed(base_timezone, guild_name)
                        await message.edit(embed=new_embed)
                        now_utc = datetime.now(pytz.UTC)
                        self.logger.debug(f"Updated timezone message at {now_utc.strftime('%H:%M:%S UTC')} for server {server_id}")
                    except discord.NotFound:
                        self.logger.warning(f"Timezone message (ID: {timezone_message_id}) not found, stopping updates for server {server_id}")
                        break
                    except discord.Forbidden:
                        self.logger.warning(f"Missing permissions to edit timezone message (ID: {timezone_message_id}) in server {server_id}")
                        break
                    except discord.HTTPException as e:
                        self.logger.error(f"Discord HTTP error updating timezone message: {e}")
                        # Continue trying despite error
                    except Exception as e:
                        self.logger.error(f"Error updating timezone message: {e}")
                        self.logger.error(f"Traceback: {traceback.format_exc()}")
                        # Continue trying despite error
                    
                    # Calculate time until the next minute boundary for more accurate updates
                    now_utc = datetime.now(pytz.UTC)
                    seconds_in_current_minute = now_utc.second + (now_utc.microsecond / 1_000_000)
                    sleep_time = 60 - seconds_in_current_minute + 0.1  # Small buffer to ensure we're past the minute boundary
                    sleep_time = max(1, sleep_time)  # Ensure at least 1 second sleep
                    await asyncio.sleep(sleep_time)
                except asyncio.CancelledError:
                    self.logger.info(f"Timezone update task cancelled for server {server_id}")
                    break
                except Exception as e:
                    self.logger.error(f"Unexpected error in timezone update loop: {e}")
                    self.logger.error(f"Traceback: {traceback.format_exc()}")
                    break
        except Exception as e:
            self.logger.error(f"Error in timezone update loop initialization: {e}")
            self.logger.error(f"Traceback: {traceback.format_exc()}")

    async def post_stream_schedule_message(self, data):
        try:
            self.logger.info(f"post_stream_schedule_message called with data: {data}")
            server_id = data.get('server_id')
            channel_id = data.get('channel_id')
            title = data.get('title', 'Stream Schedule')
            schedule = data.get('schedule', '')
            color_hex = data.get('color', '#9146ff')
            timezone = data.get('timezone', 'UTC')
            self.logger.info(f"Parsed data - server_id: {server_id}, channel_id: {channel_id}, title: {title}, color: {color_hex}, timezone: {timezone}")
            if not server_id or not channel_id:
                self.logger.error("Missing server_id or channel_id in stream schedule message data")
                return
            # Get the guild
            try:
                guild = self.bot.get_guild(int(server_id))
                self.logger.info(f"Got guild: {guild.name if guild else 'None'} (ID: {server_id})")
            except (ValueError, TypeError) as e:
                self.logger.error(f"Invalid server_id: {server_id}, error: {e}")
                return
            if not guild:
                self.logger.error(f"Bot not in guild {server_id}")
                return
            # Get the channel
            try:
                channel = guild.get_channel(int(channel_id))
                self.logger.info(f"Got channel: #{channel.name if channel else 'None'} (ID: {channel_id})")
            except (ValueError, TypeError) as e:
                self.logger.error(f"Invalid channel_id: {channel_id}, error: {e}")
                return
            if not channel:
                self.logger.error(f"Channel {channel_id} not found in guild {guild.name}")
                return
            # Fetch Twitch username from channel code to create Twitch schedule link
            twitch_username = None
            channel_code = data.get('channel_code')
            try:
                if channel_code:
                    self.logger.info(f"Using channel_code from data: {channel_code}")
                    # Use the channel_code (API key) to get the actual username from the users table
                    user_row = await self.mysql.fetchone("SELECT username FROM users WHERE api_key = %s", (channel_code,), database_name='website', dict_cursor=True)
                    if user_row:
                        twitch_username = user_row['username']
                        self.logger.info(f"Found Twitch username: {twitch_username} for guild {server_id}")
                    else:
                        self.logger.warning(f"No user found with api_key: {channel_code}")
                else:
                    self.logger.warning("No channel_code provided in data for fetching Twitch username")
            except Exception as e:
                self.logger.warning(f"Could not fetch Twitch username for schedule link: {e}")
            # Check for existing stream schedule message and delete it
            try:
                query = "SELECT channel_id, message_id, timezone_message_id FROM stream_schedule_messages WHERE server_id = %s"
                existing_message = await self.mysql.fetchone(query, params=(str(server_id),), database_name='specterdiscordbot', dict_cursor=True)
                if existing_message:
                    old_channel_id = existing_message['channel_id']
                    old_message_id = existing_message['message_id']
                    old_timezone_message_id = existing_message.get('timezone_message_id')
                    self.logger.info(f"Found existing stream schedule message (ID: {old_message_id}) in channel {old_channel_id}")
                    try:
                        # Try to delete the old message
                        old_channel = guild.get_channel(int(old_channel_id))
                        if old_channel:
                            try:
                                old_message = await old_channel.fetch_message(int(old_message_id))
                                await old_message.delete()
                                self.logger.info(f"Deleted old stream schedule message (ID: {old_message_id}) from #{old_channel.name}")
                            except discord.NotFound:
                                self.logger.info(f"Old stream schedule message (ID: {old_message_id}) no longer exists, proceeding with new message")
                            except discord.Forbidden:
                                self.logger.warning(f"Missing permissions to delete old stream schedule message (ID: {old_message_id})")
                            except Exception as e:
                                self.logger.error(f"Error deleting old stream schedule message: {e}")
                    except Exception as e:
                        self.logger.error(f"Error cleaning up old stream schedule message: {e}")
                    # Also delete old timezone message if it exists
                    if old_timezone_message_id:
                        try:
                            old_tz_message = await old_channel.fetch_message(int(old_timezone_message_id))
                            await old_tz_message.delete()
                            self.logger.info(f"Deleted old timezone message (ID: {old_timezone_message_id}) from #{old_channel.name}")
                        except discord.NotFound:
                            self.logger.info(f"Old timezone message (ID: {old_timezone_message_id}) no longer exists")
                        except discord.Forbidden:
                            self.logger.warning(f"Missing permissions to delete old timezone message (ID: {old_timezone_message_id})")
                        except Exception as e:
                            self.logger.error(f"Error deleting old timezone message: {e}")
            except Exception as e:
                self.logger.error(f"Error checking for existing stream schedule message: {e}")
            # Convert hex color to Discord color
            try:
                # Remove # if present and convert to integer
                color_int = int(color_hex.replace('#', ''), 16)
                embed_color = discord.Color(color_int)
                self.logger.info(f"Converted color {color_hex} to Discord color")
            except (ValueError, TypeError) as e:
                self.logger.warning(f"Invalid color {color_hex}, using default Twitch purple: {e}")
                embed_color = discord.Color.from_rgb(145, 70, 255)
            # Create embed for the stream schedule with URL if Twitch username available
            embed_kwargs = {
                'title': title,
                'description': schedule,
                'color': embed_color
            }
            # Add URL to embed if we have a Twitch username
            if twitch_username:
                embed_kwargs['url'] = f"https://www.twitch.tv/{twitch_username}/schedule"
                self.logger.info(f"Added Twitch schedule URL to embed for {twitch_username}")
            embed = discord.Embed(**embed_kwargs)
            # Add only the base timezone time to main embed if timezone specified
            if timezone:
                embed.add_field(name="Stream Schedule Timezone", value=timezone, inline=False)
            embed.set_footer(text=f"Stream Schedule | {guild.name}")
            try:
                # Send the main schedule embed
                sent_message = await channel.send(embed=embed)
                message_id = sent_message.id
                self.logger.info(f"Successfully posted stream schedule embed (ID: {message_id}) to #{channel.name}")
                # Send separate timezone message if timezone is specified
                timezone_message_id = None
                if timezone:
                    try:
                        timezone_embed = self._create_timezone_embed(timezone, guild.name)
                        timezone_message = await channel.send(embed=timezone_embed)
                        timezone_message_id = timezone_message.id
                        self.logger.info(f"Successfully posted timezone message (ID: {timezone_message_id}) to #{channel.name}")
                        # Start the background update task for this timezone message
                        await self._start_timezone_update_task(str(server_id), str(channel_id), str(timezone_message_id), timezone, guild.name)
                    except Exception as e:
                        self.logger.error(f"Error posting timezone message: {e}")
                # Save to database using INSERT ... ON DUPLICATE KEY UPDATE
                try:
                    self.logger.info(f"Preparing to save to database - server_id: {server_id}, channel_id: {channel_id}, message_id: {message_id}, timezone_message_id: {timezone_message_id}")
                    # Verify table exists first
                    check_query = "SHOW TABLES LIKE 'stream_schedule_messages'"
                    table_exists = await self.mysql.fetchone(check_query, database_name='specterdiscordbot')
                    if not table_exists:
                        self.logger.error("Table stream_schedule_messages does not exist!")
                    insert_query = """
                        INSERT INTO stream_schedule_messages 
                        (server_id, channel_id, message_id, timezone_message_id, title, schedule_content, color, timezone)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                        ON DUPLICATE KEY UPDATE
                        channel_id = %s,
                        message_id = %s,
                        timezone_message_id = %s,
                        title = %s,
                        schedule_content = %s,
                        color = %s,
                        timezone = %s
                    """
                    # For ON DUPLICATE KEY UPDATE, we need to provide values twice
                    params = (
                        str(server_id), str(channel_id), str(message_id), str(timezone_message_id) if timezone_message_id else '', title, schedule, color_hex, timezone,
                        str(channel_id), str(message_id), str(timezone_message_id) if timezone_message_id else '', title, schedule, color_hex, timezone
                    )
                    self.logger.info(f"Executing INSERT with {len(params)} parameters")
                    result = await self.mysql.execute(
                        insert_query, 
                        params=params,
                        database_name='specterdiscordbot'
                    )
                    self.logger.info(f"Successfully saved stream schedule message (ID: {message_id}) and timezone message (ID: {timezone_message_id}) to database for server {server_id}, affected rows: {result}")
                except Exception as e:
                    self.logger.error(f"Error saving stream schedule message to database: {e}")
                    self.logger.error(f"Traceback: {traceback.format_exc()}")
            except discord.Forbidden:
                self.logger.error(f"Missing permissions to send messages in #{channel.name} (ID: {channel_id})")
            except discord.HTTPException as e:
                self.logger.error(f"Discord HTTP error sending stream schedule message: {e}")
            except Exception as e:
                self.logger.error(f"Unexpected error sending stream schedule message: {e}")
        except Exception as e:
            self.logger.error(f"Error in post_stream_schedule_message: {e}")
            self.logger.error(f"Traceback: {traceback.format_exc()}")

    @commands.Cog.listener()
    async def on_raw_reaction_add(self, payload: discord.RawReactionActionEvent):
        try:
            # Ignore bot reactions
            if payload.user_id == self.bot.user.id:
                return
            # Check if this message is a reaction roles message
            message_id = payload.message_id
            if message_id not in self.reaction_roles_cache:
                return
            config = self.reaction_roles_cache[message_id]
            # Get the guild
            guild = self.bot.get_guild(payload.guild_id)
            if not guild:
                return
            # Get the member
            member = guild.get_member(payload.user_id)
            if not member:
                return
            # Get role mappings
            role_mappings = config.get('role_mappings', {})
            if not role_mappings:
                self.logger.warning(f"No role mappings found for message {message_id}")
                return
            # Get emoji string - handle both custom and Unicode emoji
            if payload.emoji.id:
                # Custom emoji - use name with colons to match stored format
                emoji_str = f':{payload.emoji.name}:'
            else:
                # Unicode emoji - use the actual character
                emoji_str = str(payload.emoji)
            # Check if this emoji is mapped to a role
            if emoji_str not in role_mappings:
                self.logger.debug(f"Emoji {emoji_str} not found in role mappings for message {message_id}")
                return
            role_id = role_mappings[emoji_str]
            try:
                role = guild.get_role(int(role_id))
                if role:
                    # Check if allow_multiple is False
                    if not config.get('allow_multiple', False):
                        # Remove all other reaction roles from this message
                        for other_emoji, other_role_id in role_mappings.items():
                            if other_role_id != role_id:
                                try:
                                    other_role = guild.get_role(int(other_role_id))
                                    if other_role and other_role in member.roles:
                                        await member.remove_roles(other_role, reason="Reaction role - single role mode")
                                        self.logger.debug(f"Removed role {other_role.name} from {member.name} (single role mode)")
                                except Exception as e:
                                    self.logger.error(f"Error removing other reaction role: {e}")
                    # Add the new role
                    await member.add_roles(role, reason="Reaction role")
                    self.logger.info(f"Assigned role {role.name} to {member.name} via reaction roles")
                else:
                    self.logger.warning(f"Role {role_id} not found in guild {guild.name}")
            except ValueError:
                self.logger.error(f"Invalid role_id: {role_id}")
            except discord.Forbidden:
                self.logger.error(f"Missing permissions to assign role {role_id} to {member.name}")
            except Exception as e:
                self.logger.error(f"Error assigning reaction role: {e}")
        except Exception as e:
            self.logger.error(f"Error in on_raw_reaction_add: {e}")

    @commands.Cog.listener()
    async def on_raw_reaction_remove(self, payload: discord.RawReactionActionEvent):
        try:
            # Ignore bot reactions
            if payload.user_id == self.bot.user.id:
                return
            # Check if this message is a reaction roles message
            message_id = payload.message_id
            if message_id not in self.reaction_roles_cache:
                return
            config = self.reaction_roles_cache[message_id]
            # Get the guild
            guild = self.bot.get_guild(payload.guild_id)
            if not guild:
                return
            # Get the member
            member = guild.get_member(payload.user_id)
            if not member:
                return
            # Get role mappings
            role_mappings = config.get('role_mappings', {})
            if not role_mappings:
                self.logger.warning(f"No role mappings found for message {message_id}")
                return
            # Get emoji string - handle both custom and Unicode emoji
            if payload.emoji.id:
                # Custom emoji - use name with colons to match stored format
                emoji_str = f':{payload.emoji.name}:'
            else:
                # Unicode emoji - use the actual character
                emoji_str = str(payload.emoji)
            # Check if this emoji is mapped to a role
            if emoji_str not in role_mappings:
                self.logger.debug(f"Emoji {emoji_str} not found in role mappings for message {message_id}")
                return
            role_id = role_mappings[emoji_str]
            try:
                role = guild.get_role(int(role_id))
                if role and role in member.roles:
                    await member.remove_roles(role, reason="Reaction role removed")
                    self.logger.info(f"Removed role {role.name} from {member.name} via reaction roles")
                else:
                    self.logger.debug(f"Role {role_id} not found or member doesn't have it")
            except ValueError:
                self.logger.error(f"Invalid role_id: {role_id}")
            except discord.Forbidden:
                self.logger.error(f"Missing permissions to remove role {role_id} from {member.name}")
            except Exception as e:
                self.logger.error(f"Error removing reaction role: {e}")
        except Exception as e:
            self.logger.error(f"Error in on_raw_reaction_remove: {e}")

    @commands.command(name="refreshreactionroles")
    @commands.has_permissions(administrator=True)
    async def refresh_reaction_roles(self, ctx):
        try:
            await self._refresh_reaction_roles_cache()
            await ctx.send("✅ Reaction roles cache has been refreshed!")
            self.logger.info(f"Reaction roles cache refreshed by {ctx.author.name} in {ctx.guild.name}")
        except Exception as e:
            await ctx.send(f"❌ Error refreshing reaction roles cache: {e}")
            self.logger.error(f"Error in refresh_reaction_roles command: {e}")

    async def handle_welcome_message(self, member: discord.Member):
        try:
            # Query the database for welcome message configuration
            query = """
                SELECT welcome_message_configuration_channel, 
                       welcome_message_configuration_message, 
                       welcome_message_configuration_default,
                       welcome_message_configuration_embed,
                       welcome_message_configuration_colour
                FROM server_management 
                WHERE server_id = %s
            """
            result = await self.mysql.fetchone(query, params=(str(member.guild.id),), database_name='specterdiscordbot')
            if not result or not result[0]:
                self.logger.debug(f"No welcome message configured for guild {member.guild.name}")
                return False
            welcome_channel_id, custom_message, use_default, use_embed, embed_colour = result
            # Get the welcome channel
            try:
                welcome_channel = member.guild.get_channel(int(welcome_channel_id))
                if not welcome_channel:
                    self.logger.warning(f"Welcome channel with ID {welcome_channel_id} not found in guild {member.guild.name}")
                    return False
                # Determine the message text
                if use_default == 1:
                    # Default welcome message
                    message_text = f"Welcome {member.name} to our server, we're so glad you joined us!"
                else:
                    message_text = custom_message.replace("{user}", member.name)
                # Send as embed or plain text based on configuration
                if use_embed == 1:
                    # Send as rich embed with custom colour
                    # Convert hex colour to integer
                    try:
                        colour_hex = embed_colour if embed_colour else "#00d1b2"
                        colour_int = int(colour_hex.replace("#", ""), 16)
                    except (ValueError, AttributeError):
                        self.logger.warning(f"Invalid colour format '{embed_colour}', using default")
                        colour_int = 0x00d1b2  # Default turquoise colour
                    embed = discord.Embed(
                        title="Welcome!",
                        description=message_text,
                        color=colour_int
                    )
                    embed.set_thumbnail(url=member.display_avatar.url)
                    embed.set_footer(text=f"{member.guild.name} | Member #{member.guild.member_count}")
                    await welcome_channel.send(embed=embed)
                    self.logger.info(f"Sent welcome embed to {member.name} in {welcome_channel.name} (guild: {member.guild.name}) with colour {colour_hex}")
                else:
                    # Send as plain text
                    await welcome_channel.send(message_text)
                    self.logger.info(f"Sent welcome message to {member.name} in {welcome_channel.name} (guild: {member.guild.name})")
                return True
            except ValueError:
                self.logger.error(f"Invalid welcome channel ID: {welcome_channel_id}")
                return False
            except discord.Forbidden:
                self.logger.error(f"Missing permissions to send welcome message in guild {member.guild.name}")
                return False
            except discord.HTTPException as e:
                self.logger.error(f"Failed to send welcome message due to HTTP error: {e}")
                return False
        except Exception as e:
            self.logger.error(f"Error in handle_welcome_message: {e}")
            return False

    async def handle_auto_role_assignment(self, member: discord.Member):
        try:
            # Query the database for auto role configuration
            query = "SELECT auto_role_assignment_configuration_role_id FROM server_management WHERE server_id = %s"
            result = await self.mysql.fetchone(query, params=(str(member.guild.id),), database_name='specterdiscordbot')
            if not result or not result[0]:
                self.logger.debug(f"No auto role configured for guild {member.guild.name}")
                return False
            auto_role_id = result[0]
            # Get the role object
            try:
                auto_role = member.guild.get_role(int(auto_role_id))
                if not auto_role:
                    self.logger.warning(f"Auto role with ID {auto_role_id} not found in guild {member.guild.name}")
                    return False
                # Assign the role to the member
                await member.add_roles(auto_role, reason="Auto role assignment on member join")
                self.logger.info(f"Successfully assigned auto role '{auto_role.name}' to {member.name} in guild {member.guild.name}")
                return True
            except ValueError:
                self.logger.error(f"Invalid auto role ID: {auto_role_id}")
                return False
            except discord.Forbidden:
                self.logger.error(f"Missing permissions to assign auto role in guild {member.guild.name}")
                return False
            except discord.HTTPException as e:
                self.logger.error(f"Failed to assign auto role due to HTTP error: {e}")
                return False
        except Exception as e:
            self.logger.error(f"Error in handle_auto_role_assignment: {e}")
            return False

    @commands.Cog.listener()
    async def on_member_join(self, member: discord.Member):
        self.logger.info(f"ServerManagement: Member joined {member.name}#{member.discriminator} in guild {member.guild.name}")
        # Handle welcome message
        await self.handle_welcome_message(member)
        # Handle auto role assignment
        await self.handle_auto_role_assignment(member)

    def cog_unload(self):
        self.logger.info("ServerManagement cog unloaded")

# Role History cog - tracks member roles for restoration when they rejoin
class RoleHistoryCog(commands.Cog, name='Role History'):
    def __init__(self, bot: BotOfTheSpecter, logger=None):
        self.bot = bot
        self.logger = logger or logging.getLogger(self.__class__.__name__)
        self.mysql = MySQLHelper(logger)
        # Start periodic cleanup and member role check tasks
        asyncio.create_task(self._periodic_cleanup())
        asyncio.create_task(self._periodic_member_role_check())

    async def _get_settings(self, server_id: str):
        try:
            # Fetch from server_management table (where dashboard saves settings)
            query = "SELECT role_history_configuration FROM server_management WHERE server_id = %s"
            result = await self.mysql.fetchone(query, params=(str(server_id),), database_name='specterdiscordbot', dict_cursor=True)
            if result and result['role_history_configuration']:
                try:
                    config_json = json.loads(result['role_history_configuration'])
                    return {
                        'enabled': bool(config_json.get('enabled', False)),
                        'retention_days': int(config_json.get('retention_days', 30))
                    }
                except (json.JSONDecodeError, TypeError, ValueError) as e:
                    self.logger.warning(f"Error parsing role_history_configuration JSON for server {server_id}: {e}")
            # Return default settings if not found
            return {
                'enabled': False,
                'retention_days': 30
            }
        except Exception as e:
            self.logger.error(f"Error fetching role history settings for server {server_id}: {e}")
            return {'enabled': False, 'retention_days': 30}

    async def _save_role_history(self, server_id: str, user_id: str, role_ids: list, retention_days: int):
        try:
            if not role_ids:
                self.logger.debug(f"No roles to save for user {user_id} in server {server_id}")
                return
            role_ids_json = json.dumps(role_ids)
            # Use UPSERT to insert or update the record
            # last_checked will be updated to CURRENT_TIMESTAMP automatically
            query = """
                INSERT INTO role_history (server_id, user_id, role_ids)
                VALUES (%s, %s, %s)
                ON DUPLICATE KEY UPDATE
                role_ids = VALUES(role_ids),
                last_checked = CURRENT_TIMESTAMP
            """
            params = (str(server_id), str(user_id), role_ids_json)
            await self.mysql.execute(query, params=params, database_name='specterdiscordbot')
            self.logger.info(f"Saved role history for user {user_id} in server {server_id} ({len(role_ids)} roles, retention: {retention_days} days)")
        except Exception as e:
            self.logger.error(f"Error saving role history: {e}")

    async def _restore_member_roles(self, member: discord.Member, role_ids: list):
        try:
            restored_count = 0
            for role_id in role_ids:
                try:
                    role = member.guild.get_role(int(role_id))
                    if role:
                        # Check if bot can assign this role
                        if role >= member.guild.me.top_role:
                            self.logger.warning(f"Cannot assign role {role.name} to {member.name}: bot's top role is too low")
                            continue
                        if role in member.roles:
                            self.logger.debug(f"Member {member.name} already has role {role.name}")
                            continue
                        await member.add_roles(role, reason="Role History restoration")
                        restored_count += 1
                        self.logger.debug(f"Restored role {role.name} to {member.name}")
                    else:
                        self.logger.debug(f"Role {role_id} no longer exists in guild {member.guild.name}")
                except (ValueError, TypeError) as e:
                    self.logger.warning(f"Invalid role ID {role_id}: {e}")
                except discord.Forbidden:
                    self.logger.warning(f"Missing permissions to assign role {role_id} to {member.name}")
                except Exception as e:
                    self.logger.error(f"Error assigning role {role_id} to {member.name}: {e}")
            if restored_count > 0:
                self.logger.info(f"Restored {restored_count} roles to {member.name} in {member.guild.name}")
            return restored_count
        except Exception as e:
            self.logger.error(f"Error in _restore_member_roles: {e}")
            return 0

    async def _cleanup_expired_roles(self):
        try:
            # Try to use per-server retention settings if available, otherwise use 30-day default
            # This query checks if role_history_configuration exists in server_management table
            query = """
                DELETE rh FROM role_history rh
                WHERE TIMESTAMPDIFF(DAY, rh.last_checked, NOW()) > COALESCE(
                    (SELECT CAST(JSON_EXTRACT(sm.role_history_configuration, '$.retention_days') AS UNSIGNED)
                     FROM server_management sm 
                     WHERE sm.server_id = rh.server_id
                     AND sm.role_history_configuration IS NOT NULL),
                    30
                )
            """
            result = await self.mysql.execute(query, database_name='specterdiscordbot')
            if result and result > 0:
                self.logger.info(f"Cleaned up {result} expired role history entries")
        except Exception as e:
            self.logger.debug(f"Attempted per-server cleanup (may fail if column doesn't exist yet): {e}")
            # Fallback to simple 30-day cleanup
            try:
                fallback_query = "DELETE FROM role_history WHERE TIMESTAMPDIFF(DAY, last_checked, NOW()) > 30"
                result = await self.mysql.execute(fallback_query, database_name='specterdiscordbot')
                if result and result > 0:
                    self.logger.info(f"Cleaned up {result} expired role history entries (30-day default)")
            except Exception as fallback_e:
                self.logger.error(f"Error in fallback cleanup: {fallback_e}")

    async def _periodic_cleanup(self):
        try:
            while not self.bot.is_closed():
                await asyncio.sleep(3600)  # Run every hour
                await self._cleanup_expired_roles()
        except asyncio.CancelledError:
            self.logger.debug("Periodic cleanup task cancelled")
        except Exception as e:
            self.logger.error(f"Error in periodic cleanup: {e}")

    async def _periodic_member_role_check(self):
        try:
            while not self.bot.is_closed():
                await asyncio.sleep(600)  # Run every 10 minutes
                for guild in self.bot.guilds:
                    try:
                        await self._scan_guild_members(guild)
                    except Exception as e:
                        self.logger.error(f"Error scanning guild {guild.name}: {e}")
        except asyncio.CancelledError:
            self.logger.debug("Periodic member role check task cancelled")
        except Exception as e:
            self.logger.error(f"Error in periodic member role check: {e}")
            self.logger.error(f"Error in periodic member role check: {e}")

    async def _scan_guild_members(self, guild: discord.Guild):
        try:
            settings = await self._get_settings(str(guild.id))
            if not settings['enabled']:
                return
            scanned_count = 0
            updated_count = 0
            async for member in guild.fetch_members(limit=None):
                scanned_count += 1
                # Skip bots
                if member.bot:
                    continue
                # Get current roles (exclude @everyone)
                current_role_ids = [str(role.id) for role in member.roles if role.name != "@everyone"]
                # Check if we have existing role history for this member
                query = "SELECT role_ids FROM role_history WHERE server_id = %s AND user_id = %s"
                result = await self.mysql.fetchone(
                    query,
                    params=(str(guild.id), str(member.id)),
                    database_name='specterdiscordbot',
                    dict_cursor=True
                )
                if result:
                    # Member has history, update if roles changed
                    try:
                        existing_role_ids = json.loads(result['role_ids']) if isinstance(result['role_ids'], str) else result['role_ids']
                    except (json.JSONDecodeError, TypeError):
                        existing_role_ids = []
                    # Check if roles have changed
                    if set(current_role_ids) != set(existing_role_ids):
                        # Roles changed, update the record
                        update_query = "UPDATE role_history SET role_ids = %s, last_checked = CURRENT_TIMESTAMP WHERE server_id = %s AND user_id = %s"
                        role_ids_json = json.dumps(current_role_ids)
                        await self.mysql.execute(
                            update_query,
                            params=(role_ids_json, str(guild.id), str(member.id)),
                            database_name='specterdiscordbot'
                        )
                        updated_count += 1
                        self.logger.debug(f"Updated roles for {member.name} in {guild.name}: {len(existing_role_ids)} → {len(current_role_ids)} roles")
                    else:
                        # Roles unchanged, just update last_checked
                        update_query = "UPDATE role_history SET last_checked = CURRENT_TIMESTAMP WHERE server_id = %s AND user_id = %s"
                        await self.mysql.execute(
                            update_query,
                            params=(str(guild.id), str(member.id)),
                            database_name='specterdiscordbot'
                        )
                else:
                    # New member (no history), create initial record if they have roles
                    if current_role_ids:
                        role_ids_json = json.dumps(current_role_ids)
                        insert_query = """
                            INSERT INTO role_history (server_id, user_id, role_ids)
                            VALUES (%s, %s, %s)
                            ON DUPLICATE KEY UPDATE
                            role_ids = VALUES(role_ids),
                            last_checked = CURRENT_TIMESTAMP
                        """
                        await self.mysql.execute(
                            insert_query,
                            params=(str(guild.id), str(member.id), role_ids_json),
                            database_name='specterdiscordbot'
                        )
                        updated_count += 1
                        self.logger.debug(f"Created initial role history for {member.name} in {guild.name} ({len(current_role_ids)} roles)")
                # Add small delay to avoid rate limiting
                await asyncio.sleep(0.01)
        except Exception as e:
            self.logger.error(f"Error scanning guild members for {guild.name}: {e}")

    @commands.Cog.listener()
    async def on_member_remove(self, member: discord.Member):
        try:
            settings = await self._get_settings(str(member.guild.id))
            if not settings['enabled']:
                self.logger.debug(f"Role History is disabled for server {member.guild.name}")
                return
            # Get list of role IDs (excluding @everyone role)
            role_ids = [str(role.id) for role in member.roles if role != member.guild.default_role]
            await self._save_role_history(
                str(member.guild.id),
                str(member.id),
                role_ids,
                settings['retention_days']
            )
            self.logger.info(f"Recorded role history for {member.name} leaving {member.guild.name}")
        except Exception as e:
            self.logger.error(f"Error in on_member_remove: {e}")

    @commands.Cog.listener()
    async def on_member_join(self, member: discord.Member):
        try:
            settings = await self._get_settings(str(member.guild.id))
            if not settings['enabled']:
                self.logger.debug(f"Role History is disabled for server {member.guild.name}")
                return
            # Check for saved role history
            query = """
                SELECT role_ids, last_checked 
                FROM role_history 
                WHERE server_id = %s AND user_id = %s
            """
            result = await self.mysql.fetchone(
                query,
                params=(str(member.guild.id), str(member.id)),
                database_name='specterdiscordbot',
                dict_cursor=True
            )
            if result:
                try:
                    role_ids = result['role_ids']
                    last_checked = result['last_checked']
                    # Parse role_ids from JSON
                    if isinstance(role_ids, str):
                        role_ids = json.loads(role_ids)
                    # Check if the record is within retention period
                    days_since_checked = (datetime.now(timezone.utc) - last_checked.replace(tzinfo=timezone.utc)).days
                    if days_since_checked <= settings['retention_days']:
                        # Within retention period, restore roles
                        restored_count = await self._restore_member_roles(member, role_ids)
                        self.logger.info(f"Member {member.name} ({member.id}) rejoined {member.guild.name} - restored {restored_count} roles (last_checked: {days_since_checked} days ago)")
                    else:
                        # Outside retention period, don't restore
                        self.logger.info(f"Member {member.name} ({member.id}) rejoined {member.guild.name} but role history expired ({days_since_checked} days > {settings['retention_days']} day retention)")
                    # Always delete the history entry after attempting restoration
                    delete_query = "DELETE FROM role_history WHERE server_id = %s AND user_id = %s"
                    await self.mysql.execute(
                        delete_query,
                        params=(str(member.guild.id), str(member.id)),
                        database_name='specterdiscordbot'
                    )
                except json.JSONDecodeError as e:
                    self.logger.error(f"Error parsing role history JSON for {member.name}: {e}")
                except Exception as e:
                    self.logger.error(f"Error processing role history for {member.name}: {e}")
            else:
                self.logger.debug(f"No role history found for {member.name} ({member.id}) in {member.guild.name}")
        except Exception as e:
            self.logger.error(f"Error in on_member_join: {e}")

    @commands.command(name="rolehistoryinfo")
    @commands.has_permissions(administrator=True)
    async def role_history_info(self, ctx):
        try:
            settings = await self._get_settings(str(ctx.guild.id))
            embed = discord.Embed(
                title="Role History Settings",
                color=config.bot_color
            )
            embed.add_field(name="Status", value="✅ Enabled" if settings['enabled'] else "❌ Disabled", inline=False)
            embed.add_field(name="Retention Period", value=f"{settings['retention_days']} days", inline=False)
            embed.set_footer(text=f"{ctx.guild.name} | Configure from dashboard")
            await ctx.send(embed=embed)
        except Exception as e:
            await ctx.send(f"❌ Error retrieving role history settings: {e}")
            self.logger.error(f"Error in role_history_info command: {e}")

    @commands.command(name="rolehistorycleanup")
    @commands.has_permissions(administrator=True)
    async def role_history_cleanup(self, ctx):
        try:
            await self._cleanup_expired_roles()
            await ctx.send("✅ Role history cleanup completed!")
            self.logger.info(f"Manual role history cleanup triggered by {ctx.author.name} in {ctx.guild.name}")
        except Exception as e:
            await ctx.send(f"❌ Error during cleanup: {e}")
            self.logger.error(f"Error in role_history_cleanup command: {e}")

    @commands.command(name="rolehistoryscan")
    @commands.has_permissions(administrator=True)
    async def role_history_scan(self, ctx):
        try:
            embed = discord.Embed(
                title="Role History Scan",
                description="Scanning all members in this guild...",
                color=discord.Color.blue()
            )
            msg = await ctx.send(embed=embed)
            await self._scan_guild_members(ctx.guild)
            embed = discord.Embed(
                title="✅ Role History Scan Complete",
                description="All members have been scanned and their role history has been updated.",
                color=discord.Color.green()
            )
            embed.set_footer(text=f"{ctx.guild.name} | Scan completed")
            await msg.edit(embed=embed)
            self.logger.info(f"Manual role history scan triggered by {ctx.author.name} in {ctx.guild.name}")
        except Exception as e:
            embed = discord.Embed(
                title="❌ Role History Scan Error",
                description=f"An error occurred during the scan: {str(e)}",
                color=discord.Color.red()
            )
            await ctx.send(embed=embed)
            self.logger.error(f"Error in role_history_scan command: {e}")

    @commands.command(name="rolehistorystatus")
    @commands.has_permissions(administrator=True)
    async def role_history_status(self, ctx):
        try:
            query = "SELECT COUNT(DISTINCT user_id) as total_users FROM role_history WHERE server_id = %s"
            result = await self.mysql.fetchone(
                query,
                params=(str(ctx.guild.id),),
                database_name='specterdiscordbot',
                dict_cursor=True
            )
            total_users = result['total_users'] if result else 0
            # Get recently updated records
            query_recent = """
                SELECT COUNT(*) as recent_count 
                FROM role_history 
                WHERE server_id = %s AND last_checked > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            """
            result_recent = await self.mysql.fetchone(
                query_recent,
                params=(str(ctx.guild.id),),
                database_name='specterdiscordbot',
                dict_cursor=True
            )
            recent_count = result_recent['recent_count'] if result_recent else 0
            embed = discord.Embed(
                title="Role History Status",
                color=config.bot_color
            )
            embed.add_field(name="Total Users Tracked", value=str(total_users), inline=True)
            embed.add_field(name="Updated (Last 24h)", value=str(recent_count), inline=True)
            embed.add_field(name="Guild Member Count", value=str(ctx.guild.member_count), inline=True)
            settings = await self._get_settings(str(ctx.guild.id))
            embed.add_field(name="Status", value="✅ Enabled" if settings['enabled'] else "❌ Disabled", inline=False)
            embed.add_field(name="Retention Period", value=f"{settings['retention_days']} days", inline=False)
            embed.set_footer(text=f"{ctx.guild.name} | Use !rolehistoryscan to update now")
            await ctx.send(embed=embed)
        except Exception as e:
            await ctx.send(f"❌ Error retrieving role history status: {e}")
            self.logger.error(f"Error in role_history_status command: {e}")

    def cog_unload(self):
        self.logger.info("RoleHistoryCog cog unloaded")

# Role Tracking cog - logs role changes to a designated channel
class RoleTrackingCog(commands.Cog, name='Role Tracking'):
    def __init__(self, bot: BotOfTheSpecter, logger=None):
        self.bot = bot
        self.logger = logger or logging.getLogger(self.__class__.__name__)
        self.mysql = MySQLHelper(logger)

    async def _get_settings(self, server_id: str):
        try:
            query = "SELECT role_tracking_configuration FROM server_management WHERE server_id = %s"
            result = await self.mysql.fetchone(query, params=(str(server_id),), database_name='specterdiscordbot', dict_cursor=True)
            if result and result['role_tracking_configuration']:
                try:
                    config_json = json.loads(result['role_tracking_configuration'])
                    return {
                        'enabled': bool(config_json.get('enabled', False)),
                        'log_channel_id': config_json.get('log_channel_id', None),
                        'track_additions': bool(config_json.get('track_additions', True)),
                        'track_removals': bool(config_json.get('track_removals', True))
                    }
                except (json.JSONDecodeError, TypeError, ValueError) as e:
                    self.logger.warning(f"Error parsing role_tracking_configuration JSON for server {server_id}: {e}")
            return {
                'enabled': False,
                'log_channel_id': None,
                'track_additions': True,
                'track_removals': True
            }
        except Exception as e:
            self.logger.error(f"Error fetching role tracking settings for server {server_id}: {e}")
            return {'enabled': False, 'log_channel_id': None, 'track_additions': True, 'track_removals': True}

    async def _log_role_change(self, guild_id: str, user_id: str, username: str, action: str, role_id: str, role_name: str, changed_by: str = None):
        try:
            query = """
                INSERT INTO role_tracking_logs (server_id, user_id, username, action, role_id, role_name, changed_by)
                VALUES (%s, %s, %s, %s, %s, %s, %s)
            """
            await self.mysql.execute(
                query,
                params=(str(guild_id), str(user_id), username, action, str(role_id), role_name, changed_by),
                database_name='specterdiscordbot'
            )
        except Exception as e:
            self.logger.error(f"Error logging role change: {e}")

    async def _send_log_message(self, guild: discord.Guild, channel_id: str, embed: discord.Embed):
        try:
            channel = guild.get_channel(int(channel_id))
            if channel and isinstance(channel, discord.TextChannel):
                await channel.send(embed=embed)
            else:
                self.logger.warning(f"Role tracking log channel {channel_id} not found in {guild.name}")
        except Exception as e:
            self.logger.error(f"Error sending role tracking log: {e}")

    @commands.Cog.listener()
    async def on_member_update(self, before: discord.Member, after: discord.Member):
        try:
            settings = await self._get_settings(str(after.guild.id))
            if not settings['enabled'] or not settings['log_channel_id']:
                return
            # Get role differences
            before_roles = set(before.roles)
            after_roles = set(after.roles)
            # Check for added roles
            added_roles = after_roles - before_roles
            removed_roles = before_roles - after_roles
            # Try to get who made the change from audit logs
            changed_by = None
            try:
                async for entry in after.guild.audit_logs(limit=5, action=discord.AuditLogAction.member_role_update):
                    if entry.target.id == after.id:
                        changed_by = f"{entry.user.name}#{entry.user.discriminator}"
                        break
            except discord.Forbidden:
                changed_by = "Unknown (no audit log access)"
            except Exception as e:
                self.logger.warning(f"Error fetching audit logs: {e}")
                changed_by = "Unknown"
            # Log added roles (if tracking additions is enabled)
            if settings['track_additions']:
                for role in added_roles:
                    if role.name == "@everyone":
                        continue
                    # Log to database
                    await self._log_role_change(
                        str(after.guild.id),
                        str(after.id),
                        after.name,
                        'added',
                        str(role.id),
                        role.name,
                        changed_by
                    )
                    # Send log message
                    embed = discord.Embed(
                        title="Role Added",
                        description=f"**{after.mention}** received the **{role.name}** role",
                        color=discord.Color.green(),
                        timestamp=datetime.now(timezone.utc)
                    )
                    embed.set_author(name=after.name, icon_url=after.avatar.url if after.avatar else None)
                    embed.add_field(name="User ID", value=str(after.id), inline=True)
                    embed.add_field(name="Role ID", value=str(role.id), inline=True)
                    embed.set_footer(text=after.guild.name)
                    await self._send_log_message(after.guild, settings['log_channel_id'], embed)
            # Log removed roles (if tracking removals is enabled)
            if settings['track_removals']:
                for role in removed_roles:
                    if role.name == "@everyone":
                        continue
                    # Log to database
                    await self._log_role_change(
                        str(after.guild.id),
                        str(after.id),
                        after.name,
                        'removed',
                        str(role.id),
                        role.name,
                        changed_by
                    )
                    # Send log message
                    embed = discord.Embed(
                        title="Role Removed",
                        description=f"**{after.mention}** lost the **{role.name}** role",
                        color=discord.Color.red(),
                        timestamp=datetime.now(timezone.utc)
                    )
                    embed.set_author(name=after.name, icon_url=after.avatar.url if after.avatar else None)
                    embed.add_field(name="User ID", value=str(after.id), inline=True)
                    embed.add_field(name="Role ID", value=str(role.id), inline=True)
                    embed.set_footer(text=after.guild.name)
                    await self._send_log_message(after.guild, settings['log_channel_id'], embed)
            # Update role history if the role history feature is enabled
            if added_roles or removed_roles:
                role_history_settings = await self.bot.get_cog('Role History')._get_settings(str(after.guild.id)) if self.bot.get_cog('Role History') else None
                if role_history_settings and role_history_settings['enabled']:
                    current_role_ids = [str(role.id) for role in after.roles if role.name != "@everyone"]
                    role_ids_json = json.dumps(current_role_ids)
                    update_query = "UPDATE role_history SET role_ids = %s, last_checked = CURRENT_TIMESTAMP WHERE server_id = %s AND user_id = %s"
                    await self.mysql.execute(
                        update_query,
                        params=(role_ids_json, str(after.guild.id), str(after.id)),
                        database_name='specterdiscordbot'
                    )
        except Exception as e:
            self.logger.error(f"Error in on_member_update: {e}")

    def cog_unload(self):
        self.logger.info("RoleTrackingCog cog unloaded")

# Server Role Management cog - tracks role creation and deletion
class ServerRoleManagementCog(commands.Cog, name='Server Role Management'):
    def __init__(self, bot: BotOfTheSpecter, logger=None):
        self.bot = bot
        self.logger = logger or logging.getLogger(self.__class__.__name__)
        self.mysql = MySQLHelper(logger)

    async def _get_settings(self, server_id: str):
        try:
            query = "SELECT server_role_management_configuration FROM server_management WHERE server_id = %s"
            result = await self.mysql.fetchone(query, params=(str(server_id),), database_name='specterdiscordbot', dict_cursor=True)
            if result and result['server_role_management_configuration']:
                try:
                    config_json = json.loads(result['server_role_management_configuration'])
                    return {
                        'enabled': bool(config_json.get('enabled', False)),
                        'log_channel_id': config_json.get('log_channel_id', None),
                        'track_creation': bool(config_json.get('track_creation', True)),
                        'track_deletion': bool(config_json.get('track_deletion', True)),
                        'track_edits': bool(config_json.get('track_edits', True))
                    }
                except (json.JSONDecodeError, TypeError, ValueError) as e:
                    self.logger.warning(f"Error parsing server_role_management_configuration JSON for server {server_id}: {e}")
            return {
                'enabled': False,
                'log_channel_id': None,
                'track_creation': True,
                'track_deletion': True,
                'track_edits': True
            }
        except Exception as e:
            self.logger.error(f"Error fetching server role management settings for server {server_id}: {e}")
            return {'enabled': False, 'log_channel_id': None, 'track_creation': True, 'track_deletion': True, 'track_edits': True}

    async def _log_role_change(self, guild_id: str, action: str, role_id: str, role_name: str, created_by: str = None):
        try:
            insert_query = """
                INSERT INTO server_role_management_logs (server_id, action, role_id, role_name, created_by)
                VALUES (%s, %s, %s, %s, %s)
            """
            await self.mysql.execute(
                insert_query,
                params=(str(guild_id), action, str(role_id), role_name, created_by),
                database_name='specterdiscordbot'
            )
        except Exception as e:
            self.logger.error(f"Error logging role {action} for server {guild_id}: {e}")

    async def _send_log_message(self, guild: discord.Guild, channel_id: str, embed: discord.Embed):
        try:
            channel = guild.get_channel(int(channel_id))
            if channel and isinstance(channel, discord.TextChannel):
                await channel.send(embed=embed)
            else:
                self.logger.warning(f"Server role management log channel {channel_id} not found in {guild.name}")
        except Exception as e:
            self.logger.error(f"Error sending server role management log: {e}")

    @commands.Cog.listener()
    async def on_guild_role_create(self, role: discord.Role):
        try:
            settings = await self._get_settings(str(role.guild.id))
            if not settings['enabled'] or not settings['log_channel_id']:
                return
            # Only log if tracking creations is enabled
            if not settings['track_creation']:
                return
            # Get who created the role from audit logs
            created_by = "Unknown"
            try:
                async for entry in role.guild.audit_logs(limit=5, action=discord.AuditLogAction.role_create):
                    if entry.target.id == role.id:
                        created_by = f"{entry.user.name}#{entry.user.discriminator}"
                        break
            except discord.Forbidden:
                created_by = "Unknown (no audit log access)"
            except Exception as e:
                self.logger.warning(f"Error fetching audit logs for role creation: {e}")
            # Log to database
            await self._log_role_change(
                str(role.guild.id),
                'created',
                str(role.id),
                role.name,
                created_by
            )
            # Send log message
            embed = discord.Embed(
                title="Role Created",
                description=f"**{role.name}** role has been created",
                color=discord.Color.green(),
                timestamp=datetime.now(timezone.utc)
            )
            embed.add_field(name="Role ID", value=str(role.id), inline=True)
            embed.add_field(name="Created By", value=created_by, inline=True)
            if role.color:
                embed.add_field(name="Color", value=str(role.color), inline=True)
            embed.set_footer(text=role.guild.name)
            await self._send_log_message(role.guild, settings['log_channel_id'], embed)
        except Exception as e:
            self.logger.error(f"Error in on_guild_role_create: {e}")

    @commands.Cog.listener()
    async def on_guild_role_delete(self, role: discord.Role):
        try:
            settings = await self._get_settings(str(role.guild.id))
            if not settings['enabled'] or not settings['log_channel_id']:
                return
            # Only log if tracking deletions is enabled
            if not settings['track_deletion']:
                return
            # Get who deleted the role from audit logs
            deleted_by = "Unknown"
            try:
                async for entry in role.guild.audit_logs(limit=5, action=discord.AuditLogAction.role_delete):
                    if entry.target.id == role.id:
                        deleted_by = f"{entry.user.name}#{entry.user.discriminator}"
                        break
            except discord.Forbidden:
                deleted_by = "Unknown (no audit log access)"
            except Exception as e:
                self.logger.warning(f"Error fetching audit logs for role deletion: {e}")
            # Log to database
            await self._log_role_change(
                str(role.guild.id),
                'deleted',
                str(role.id),
                role.name,
                deleted_by
            )
            # Send log message
            embed = discord.Embed(
                title="Role Deleted",
                description=f"**{role.name}** role has been deleted",
                color=discord.Color.red(),
                timestamp=datetime.now(timezone.utc)
            )
            embed.add_field(name="Role ID", value=str(role.id), inline=True)
            embed.add_field(name="Deleted By", value=deleted_by, inline=True)
            embed.set_footer(text=role.guild.name)
            await self._send_log_message(role.guild, settings['log_channel_id'], embed)
        except Exception as e:
            self.logger.error(f"Error in on_guild_role_delete: {e}")

    @commands.Cog.listener()
    async def on_guild_role_update(self, before: discord.Role, after: discord.Role):
        try:
            settings = await self._get_settings(str(after.guild.id))
            if not settings['enabled'] or not settings['log_channel_id']:
                return
            # Only log if tracking edits is enabled
            if not settings.get('track_edits', True):
                return
            # Check what changed
            changes = []
            if before.name != after.name:
                changes.append(f"Name: **{before.name}** → **{after.name}**")
            if before.color != after.color:
                changes.append(f"Color: **{before.color}** → **{after.color}**")
            if before.permissions != after.permissions:
                changes.append("Permissions were modified")
            if before.mentionable != after.mentionable:
                changes.append(f"Mentionable: **{before.mentionable}** → **{after.mentionable}**")
            if before.hoist != after.hoist:
                changes.append(f"Hoist: **{before.hoist}** → **{after.hoist}**")
            # If no changes detected, return
            if not changes:
                return
            # Get who edited the role from audit logs
            edited_by = "Unknown"
            try:
                async for entry in after.guild.audit_logs(limit=10, action=discord.AuditLogAction.role_update):
                    if entry.target and entry.target.id == after.id:
                        edited_by = f"{entry.user.name}#{entry.user.discriminator}"
                        break
            except discord.Forbidden:
                edited_by = "Unknown (no audit log access)"
            except Exception as e:
                self.logger.warning(f"Error fetching audit logs for role update: {e}")
            # Log to database with changes as note
            changes_str = " | ".join(changes)
            await self._log_role_change(
                str(after.guild.id),
                'edited',
                str(after.id),
                after.name,
                edited_by
            )
            # Send log message
            embed = discord.Embed(
                title="Role Edited",
                description=f"**{after.name}** role has been modified",
                color=discord.Color.orange(),
                timestamp=datetime.now(timezone.utc)
            )
            embed.add_field(name="Role ID", value=str(after.id), inline=True)
            embed.add_field(name="Edited By", value=edited_by, inline=True)
            embed.add_field(name="Changes", value="\n".join(changes), inline=False)
            embed.set_footer(text=after.guild.name)
            await self._send_log_message(after.guild, settings['log_channel_id'], embed)
        except Exception as e:
            self.logger.error(f"Error in on_guild_role_update: {e}")

    def cog_unload(self):
        self.logger.info("ServerRoleManagementCog cog unloaded")

# Message Tracking cog - tracks message edits and deletions
class MessageTrackingCog(commands.Cog, name='Message Tracking'):
    def __init__(self, bot: BotOfTheSpecter, logger=None):
        self.bot = bot
        self.logger = logger or logging.getLogger(self.__class__.__name__)
        self.mysql = MySQLHelper(logger)

    async def _get_settings(self, guild_id):
        try:
            result = await self.mysql.fetchone(
                "SELECT message_tracking_configuration FROM server_management WHERE server_id = %s",
                (guild_id,),
                database_name='specterdiscordbot',
                dict_cursor=True
            )
            if result and result['message_tracking_configuration']:
                config = json.loads(result['message_tracking_configuration'])
                return {
                    'enabled': config.get('enabled', False),
                    'log_channel_id': config.get('log_channel_id', ''),
                    'track_edits': config.get('track_edits', True),
                    'track_deletes': config.get('track_deletes', True)
                }
            return {'enabled': False, 'log_channel_id': '', 'track_edits': True, 'track_deletes': True}
        except Exception as e:
            self.logger.error(f"Error getting message tracking settings: {e}")
            return {'enabled': False, 'log_channel_id': '', 'track_edits': True, 'track_deletes': True}

    async def _log_message_change(self, guild_id, channel_id, message_id, user_id, username, action, original_content, edited_content=None):
        try:
            await self.mysql.execute(
                """INSERT INTO message_tracking_logs 
                (server_id, channel_id, message_id, user_id, username, action, original_content, edited_content)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s)""",
                (guild_id, channel_id, message_id, user_id, username, action, original_content, edited_content),
                database_name='specterdiscordbot'
            )
        except Exception as e:
            self.logger.error(f"Error logging message change: {e}")

    async def _send_log_message(self, guild, log_channel_id, embed):
        try:
            channel = guild.get_channel(int(log_channel_id))
            if channel:
                await channel.send(embed=embed)
            else:
                self.logger.warning(f"Message tracking log channel {log_channel_id} not found in guild {guild.id}")
        except Exception as e:
            self.logger.error(f"Error sending message log: {e}")

    @commands.Cog.listener()
    async def on_message_edit(self, before, after):
        if before.guild is None or before.author.bot:
            return
        try:
            settings = await self._get_settings(before.guild.id)
            if not settings['enabled'] or not settings['track_edits']:
                return
            await self._log_message_change(
                before.guild.id,
                before.channel.id,
                before.id,
                before.author.id,
                str(before.author),
                'edited',
                before.content,
                after.content
            )
            # Send embed to log channel
            embed = discord.Embed(
                title="Message Edited",
                color=discord.Color.from_rgb(255, 165, 0),
                timestamp=datetime.now(timezone.utc)
            )
            embed.add_field(name="User", value=str(before.author), inline=True)
            embed.add_field(name="Channel", value=before.channel.mention, inline=True)
            embed.add_field(name="Original Content", value=before.content[:1024] if before.content else "(no content)", inline=False)
            embed.add_field(name="Edited Content", value=after.content[:1024] if after.content else "(no content)", inline=False)
            embed.set_footer(text=before.guild.name)
            await self._send_log_message(before.guild, settings['log_channel_id'], embed)
        except Exception as e:
            self.logger.error(f"Error in on_message_edit: {e}")

    @commands.Cog.listener()
    async def on_message_delete(self, message):
        if message.guild is None or message.author.bot:
            return
        try:
            settings = await self._get_settings(message.guild.id)
            if not settings['enabled'] or not settings['track_deletes']:
                return
            await self._log_message_change(
                message.guild.id,
                message.channel.id,
                message.id,
                message.author.id,
                str(message.author),
                'deleted',
                message.content
            )
            # Send embed to log channel
            embed = discord.Embed(
                title="Message Deleted",
                color=discord.Color.from_rgb(255, 0, 0),
                timestamp=datetime.now(timezone.utc)
            )
            embed.add_field(name="User", value=str(message.author), inline=True)
            embed.add_field(name="Channel", value=message.channel.mention, inline=True)
            embed.add_field(name="Content", value=message.content[:1024] if message.content else "(no content)", inline=False)
            embed.set_footer(text=message.guild.name)
            await self._send_log_message(message.guild, settings['log_channel_id'], embed)
        except Exception as e:
            self.logger.error(f"Error in on_message_delete: {e}")

    def cog_unload(self):
        self.logger.info("MessageTrackingCog cog unloaded")

# User Tracking cog - tracks user join and leave events
class UserTrackingCog(commands.Cog, name='User Tracking'):
    def __init__(self, bot: BotOfTheSpecter, logger=None):
        self.bot = bot
        self.logger = logger or logging.getLogger(self.__class__.__name__)
        self.mysql = MySQLHelper(logger)

    async def _get_settings(self, server_id: str):
        try:
            query = "SELECT user_tracking_configuration FROM server_management WHERE server_id = %s"
            result = await self.mysql.fetchone(query, params=(str(server_id),), database_name='specterdiscordbot', dict_cursor=True)
            if result and result['user_tracking_configuration']:
                try:
                    config_json = json.loads(result['user_tracking_configuration'])
                    return {
                        'enabled': bool(config_json.get('enabled', False)),
                        'log_channel_id': config_json.get('log_channel_id', None),
                        'track_joins': bool(config_json.get('track_joins', True)),
                        'track_leaves': bool(config_json.get('track_leaves', True)),
                        'track_nickname': bool(config_json.get('track_nickname', True)),
                        'track_username': bool(config_json.get('track_username', True)),
                        'track_avatar': bool(config_json.get('track_avatar', True)),
                        'track_status': bool(config_json.get('track_status', True))
                    }
                except (json.JSONDecodeError, TypeError, ValueError) as e:
                    self.logger.warning(f"Error parsing user_tracking_configuration JSON for server {server_id}: {e}")
            return {
                'enabled': False,
                'log_channel_id': None,
                'track_joins': True,
                'track_leaves': True,
                'track_nickname': True,
                'track_username': True,
                'track_avatar': True,
                'track_status': True
            }
        except Exception as e:
            self.logger.error(f"Error fetching user tracking settings for server {server_id}: {e}")
            return {'enabled': False, 'log_channel_id': None, 'track_joins': True, 'track_leaves': True, 'track_nickname': True, 'track_username': True, 'track_avatar': True, 'track_status': True}

    async def _log_user_event(self, guild_id: str, user_id: str, username: str, action: str):
        try:
            insert_query = """
                INSERT INTO user_tracking_logs (server_id, user_id, username, action)
                VALUES (%s, %s, %s, %s)
            """
            await self.mysql.execute(
                insert_query,
                params=(str(guild_id), str(user_id), username, action),
                database_name='specterdiscordbot'
            )
        except Exception as e:
            self.logger.error(f"Error logging user {action} for server {guild_id}: {e}")

    async def _send_log_message(self, guild: discord.Guild, channel_id: str, embed: discord.Embed):
        try:
            channel = guild.get_channel(int(channel_id))
            if channel and isinstance(channel, discord.TextChannel):
                await channel.send(embed=embed)
            else:
                self.logger.warning(f"User tracking log channel {channel_id} not found in {guild.name}")
        except Exception as e:
            self.logger.error(f"Error sending user tracking log: {e}")

    @commands.Cog.listener()
    async def on_member_join(self, member: discord.Member):
        try:
            settings = await self._get_settings(str(member.guild.id))
            if not settings['enabled'] or not settings['log_channel_id']:
                return
            # Only log if tracking joins is enabled
            if not settings['track_joins']:
                return
            # Log to database
            await self._log_user_event(
                str(member.guild.id),
                str(member.id),
                member.name,
                'joined'
            )
            # Send log message
            embed = discord.Embed(
                title="User Joined",
                description=f"**{member.mention}** has joined the server",
                color=discord.Color.green(),
                timestamp=datetime.now(timezone.utc)
            )
            embed.set_author(name=member.name, icon_url=member.avatar.url if member.avatar else None)
            embed.add_field(name="User ID", value=str(member.id), inline=True)
            embed.add_field(name="Account Created", value=member.created_at.strftime("%Y-%m-%d %H:%M:%S"), inline=True)
            embed.set_footer(text=member.guild.name)
            await self._send_log_message(member.guild, settings['log_channel_id'], embed)
        except Exception as e:
            self.logger.error(f"Error in on_member_join: {e}")

    @commands.Cog.listener()
    async def on_member_remove(self, member: discord.Member):
        try:
            settings = await self._get_settings(str(member.guild.id))
            if not settings['enabled'] or not settings['log_channel_id']:
                return
            # Only log if tracking leaves is enabled
            if not settings['track_leaves']:
                return
            # Log to database
            await self._log_user_event(
                str(member.guild.id),
                str(member.id),
                member.name,
                'left'
            )
            # Send log message
            embed = discord.Embed(
                title="User Left",
                description=f"**{member.mention}** has left the server",
                color=discord.Color.red(),
                timestamp=datetime.now(timezone.utc)
            )
            embed.set_author(name=member.name, icon_url=member.avatar.url if member.avatar else None)
            embed.add_field(name="User ID", value=str(member.id), inline=True)
            embed.add_field(name="Join Date", value=member.joined_at.strftime("%Y-%m-%d %H:%M:%S") if member.joined_at else "Unknown", inline=True)
            embed.set_footer(text=member.guild.name)
            await self._send_log_message(member.guild, settings['log_channel_id'], embed)
        except Exception as e:
            self.logger.error(f"Error in on_member_remove: {e}")

    @commands.Cog.listener()
    async def on_member_update(self, before: discord.Member, after: discord.Member):
        try:
            settings = await self._get_settings(str(after.guild.id))
            if not settings['enabled'] or not settings['log_channel_id']:
                return
            changes = []
            # Track nickname changes
            if settings['track_nickname'] and before.nick != after.nick:
                before_nick = before.nick if before.nick else "(no nickname)"
                after_nick = after.nick if after.nick else "(no nickname)"
                changes.append(f"Nickname: **{before_nick}** → **{after_nick}**")
                await self._log_user_event(str(after.guild.id), str(after.id), after.name, 'nickname_changed')
            # Track username changes (Discord tag/name)
            if settings['track_username'] and before.name != after.name:
                changes.append(f"Username: **{before.name}** → **{after.name}**")
                await self._log_user_event(str(after.guild.id), str(after.id), after.name, 'username_changed')
            # Track avatar changes
            if settings['track_avatar'] and before.avatar != after.avatar:
                changes.append("Avatar: Profile picture was changed")
                await self._log_user_event(str(after.guild.id), str(after.id), after.name, 'avatar_changed')
            # Track status changes (only manual status updates, not bot presence)
            if settings['track_status'] and before.status != after.status:
                status_map = {
                    discord.Status.online: "Online",
                    discord.Status.idle: "Idle",
                    discord.Status.dnd: "Do Not Disturb",
                    discord.Status.offline: "Offline",
                    discord.Status.invisible: "Invisible"
                }
                before_status = status_map.get(before.status, str(before.status))
                after_status = status_map.get(after.status, str(after.status))
                changes.append(f"Status: **{before_status}** → **{after_status}**")
                await self._log_user_event(str(after.guild.id), str(after.id), after.name, 'status_changed')
            # If there are changes, send a log message
            if changes:
                embed = discord.Embed(
                    title="User Profile Updated",
                    description=f"**{after.mention}** updated their profile",
                    color=discord.Color.cyan(),
                    timestamp=datetime.now(timezone.utc)
                )
                embed.set_author(name=after.name, icon_url=after.avatar.url if after.avatar else None)
                embed.add_field(name="User ID", value=str(after.id), inline=True)
                embed.add_field(name="Changes", value="\n".join(changes), inline=False)
                embed.set_footer(text=after.guild.name)
                await self._send_log_message(after.guild, settings['log_channel_id'], embed)
        except Exception as e:
            self.logger.error(f"Error in on_member_update: {e}")

    def cog_unload(self):
        self.logger.info("UserTrackingCog cog unloaded")

# Moderation cog - commands for server moderators
class ModerationCog(commands.Cog, name='Moderation'):
    def __init__(self, bot: BotOfTheSpecter, logger=None):
        self.bot = bot
        self.logger = logger or logging.getLogger(self.__class__.__name__)

    @commands.command(name="purge", aliases=["clear", "delete"])
    @commands.has_permissions(manage_messages=True)
    @commands.bot_has_permissions(manage_messages=True)
    async def purge_messages(self, ctx, amount: int = None):
        if amount is None:
            await ctx.send("❌ Please specify the number of messages to delete. Usage: `!purge <number>`", delete_after=5)
            return
        if amount < 1:
            await ctx.send("❌ Please specify a number greater than 0.", delete_after=5)
            return
        if amount > 100:
            await ctx.send("❌ You can only delete up to 100 messages at a time.", delete_after=5)
            return
        try:
            # Delete the command message first
            await ctx.message.delete()
            # Delete the specified number of messages
            deleted = await ctx.channel.purge(limit=amount)
            # Send confirmation message that auto-deletes after 5 seconds
            confirmation = await ctx.send(f"✅ Successfully deleted {len(deleted)} message(s).", delete_after=5)
            self.logger.info(f"User {ctx.author} ({ctx.author.id}) purged {len(deleted)} messages in #{ctx.channel.name} ({ctx.channel.id}) in guild {ctx.guild.name} ({ctx.guild.id})")
        except discord.Forbidden:
            await ctx.send("❌ I don't have permission to delete messages in this channel.", delete_after=5)
            self.logger.error(f"Missing permissions to purge messages in #{ctx.channel.name} ({ctx.channel.id})")
        except discord.HTTPException as e:
            await ctx.send(f"❌ An error occurred while deleting messages: {e}", delete_after=5)
            self.logger.error(f"HTTP error while purging messages: {e}")
        except Exception as e:
            await ctx.send(f"❌ An unexpected error occurred: {e}", delete_after=5)
            self.logger.error(f"Error in purge command: {e}")

    @purge_messages.error
    async def purge_error(self, ctx, error):
        if isinstance(error, commands.MissingPermissions):
            await ctx.send("❌ You need the 'Manage Messages' permission to use this command.", delete_after=5)
        elif isinstance(error, commands.BotMissingPermissions):
            await ctx.send("❌ I need the 'Manage Messages' permission to delete messages.", delete_after=5)
        elif isinstance(error, commands.BadArgument):
            await ctx.send("❌ Please provide a valid number. Usage: `!purge <number>`", delete_after=5)
        else:
            await ctx.send(f"❌ An error occurred: {error}", delete_after=5)
            self.logger.error(f"Error in purge command: {error}")

    def cog_unload(self):
        self.logger.info("ModerationCog cog unloaded")

# Admin commands cog - restricted to bot owner
class AdminCog(commands.Cog, name='Admin'):
    def __init__(self, bot: BotOfTheSpecter, logger=None):
        self.bot = bot
        self.logger = logger or logging.getLogger(self.__class__.__name__)
        self.admin_key = config.admin_key
        self.api_base_url = config.api_base_url

    def cog_check(self, ctx):
        return ctx.author.id == config.bot_owner_id

    @commands.command(name="checklinked")
    async def check_linked_users(self, ctx):
        if ctx.guild is None:
            await ctx.send("This command can only be used in a server.")
            return
        # Restrict to StreamingTools server only
        if ctx.guild.id != 1103694163930787880:
            await ctx.send("❌ This command can only be used in the StreamingTools server.")
            return
        await self.perform_linked_check(ctx)

    async def perform_linked_check(self, ctx):
        # Send initial message
        status_msg = await ctx.send("🔍 Checking linked users and assigning roles... This may take a while.")
        # Get the WebsiteLinked role (we know it exists in StreamingTools server)
        website_linked_role = ctx.guild.get_role(1393938902364061726)
        linked_users = []
        unlinked_users = []
        error_users = []
        roles_assigned = 0
        roles_already_had = 0
        total_users = 0
        try:
            # Get all members in the guild (excluding bots)
            members = [member for member in ctx.guild.members if not member.bot]
            total_users = len(members)
            self.logger.info(f"Checking {total_users} users for link status in guild {ctx.guild.name}")
            # Process users in batches to avoid rate limits
            batch_size = 10
            for i in range(0, len(members), batch_size):
                batch = members[i:i + batch_size]
                # Update status every 50 users
                if i % 50 == 0:
                    try:
                        progress_text = f"🔍 Checking linked users and assigning roles... ({i}/{total_users} processed)"
                        await status_msg.edit(content=progress_text)
                    except:
                        pass  # Ignore edit failures
                # Check each user in the batch
                tasks = []
                for member in batch:
                    tasks.append(self.check_user_link_status(member))
                # Wait for all tasks in the batch to complete
                batch_results = await asyncio.gather(*tasks, return_exceptions=True)
                # Process results
                for j, result in enumerate(batch_results):
                    member = batch[j]
                    if isinstance(result, Exception):
                        self.logger.error(f"Error checking user {member.display_name} ({member.id}): {result}")
                        error_users.append(member)
                    elif result is True:
                        linked_users.append(member)
                        # Assign role to linked user if they don't already have it
                        if website_linked_role:
                            if website_linked_role not in member.roles:
                                try:
                                    await member.add_roles(website_linked_role, reason="Verified as linked to website")
                                    roles_assigned += 1
                                    self.logger.info(f"Assigned WebsiteLinked role to {member.display_name} ({member.id})")
                                except Exception as role_error:
                                    self.logger.error(f"Failed to assign role to {member.display_name}: {role_error}")
                            else:
                                roles_already_had += 1
                    elif result is False:
                        unlinked_users.append(member)
                    else:
                        error_users.append(member)
                # Small delay between batches to be respectful to the API
                await asyncio.sleep(0.5)
            # Create summary embed
            embed = discord.Embed(
                title="🔗 Linked Users Check Results",
                color=config.bot_color,
                timestamp=datetime.now(timezone.utc)
            )
            summary_text = (f"**Total Users:** {total_users}\n"
                          f"**Linked:** {len(linked_users)}\n"
                          f"**Unlinked:** {len(unlinked_users)}\n"
                          f"**Errors:** {len(error_users)}\n"
                          f"\n**Role Assignment:**\n"
                          f"**Roles Assigned:** {roles_assigned}\n"
                          f"**Already Had Role:** {roles_already_had}")
            embed.add_field(
                name="📊 Summary",
                value=summary_text,
                inline=False
            )
            # Add linked users (limit to prevent embed size issues)
            if linked_users:
                linked_list = []
                for user in linked_users[:20]:  # Limit to first 20
                    linked_list.append(f"• {user.display_name} ({user.id})")
                linked_text = "\n".join(linked_list)
                if len(linked_users) > 20:
                    linked_text += f"\n... and {len(linked_users) - 20} more"
                embed.add_field(
                    name="✅ Linked Users",
                    value=linked_text if linked_text else "None",
                    inline=False
                )
            # Add unlinked users (limit to prevent embed size issues)
            if unlinked_users:
                unlinked_list = []
                for user in unlinked_users[:20]:  # Limit to first 20
                    unlinked_list.append(f"• {user.display_name} ({user.id})")
                unlinked_text = "\n".join(unlinked_list)
                if len(unlinked_users) > 20:
                    unlinked_text += f"\n... and {len(unlinked_users) - 20} more"
                embed.add_field(
                    name="❌ Unlinked Users",
                    value=unlinked_text if unlinked_text else "None",
                    inline=False
                )
            # Add errors if any
            if error_users:
                error_list = []
                for user in error_users[:10]:  # Limit to first 10
                    error_list.append(f"• {user.display_name} ({user.id})")
                error_text = "\n".join(error_list)
                if len(error_users) > 10:
                    error_text += f"\n... and {len(error_users) - 10} more"
                embed.add_field(
                    name="⚠️ Errors",
                    value=error_text,
                    inline=False
                )
            embed.set_footer(text=f"Checked by {ctx.author.display_name}")
            # Delete status message and send results
            try:
                await status_msg.delete()
            except:
                pass  # Ignore delete failures
            await ctx.send(embed=embed)
            completion_msg = f"Link check completed: {len(linked_users)} linked, {len(unlinked_users)} unlinked, {len(error_users)} errors, {roles_assigned} roles assigned"
            self.logger.info(completion_msg)
        except Exception as e:
            self.logger.error(f"Error in check_linked_users command: {e}")
            try:
                await status_msg.edit(content=f"❌ An error occurred while checking users: {e}")
            except:
                await ctx.send(f"❌ An error occurred while checking users: {e}")

    async def check_user_link_status(self, member):
        try:
            url = f"{self.api_base_url}/discord/linked"
            params = {
                'api_key': self.admin_key,
                'user_id': member.id
            }
            async with aiohttp.ClientSession() as session:
                async with session.get(url, params=params, timeout=10) as response:
                    if response.status == 200:
                        data = await response.json()
                        return data.get('linked', False)
                    else:
                        self.logger.warning(f"API returned status {response.status} for user {member.id}")
                        return None
        except asyncio.TimeoutError:
            self.logger.warning(f"Timeout checking link status for user {member.id}")
            return None
        except Exception as e:
            self.logger.error(f"Error checking link status for user {member.id}: {e}")
            return None

    @check_linked_users.error
    async def check_linked_users_error(self, ctx, error):
        if isinstance(error, commands.CheckFailure):
            await ctx.send("❌ This command can only be used by the bot owner.")
        else:
            self.logger.error(f"Error in checklinked command: {error}")
            await ctx.send("❌ An error occurred while processing the command.")

    @commands.command(name="online_streams")
    async def online_streams(self, ctx):
        ADMIN_CHANNEL_ID = 1103695077928345683
        if ctx.channel.id != ADMIN_CHANNEL_ID:
            try:
                await ctx.message.add_reaction("❌")
            except Exception:
                pass
            return await ctx.send("Sorry, you can't run the command.")
        # Fetch all online streams and render as embed(s)
        try:
            live_rows = []
            if hasattr(self.bot, 'live_channel_manager') and self.bot.live_channel_manager:
                live_rows = await self.bot.live_channel_manager.get_all_online_streams()
            if not live_rows:
                return await ctx.send("No live channels are currently being tracked.")
            # Helper: safely parse details JSON when present
            def _parse_details(details_raw):
                if not details_raw:
                    return {}
                if isinstance(details_raw, dict):
                    return details_raw
                try:
                    return json.loads(details_raw)
                except Exception:
                    # Keep minimal fallback: attempt to parse via simple replacement
                    try:
                        return json.loads(str(details_raw))
                    except Exception:
                        return {}
            # Build embed fields per live row. Limit fields per embed to 20 for safety.
            fields_per_embed = 20
            chunks = [live_rows[i:i + fields_per_embed] for i in range(0, len(live_rows), fields_per_embed)]
            total = len(live_rows)
            for chunk_idx, chunk in enumerate(chunks, start=1):
                embed = discord.Embed(
                    title=f"Live Channels ({total})",
                    description=f"Page {chunk_idx} of {len(chunks)}",
                    color=config.bot_color
                )
                # Summarize the chunk as fields where the field name is the username
                for r in chunk:
                    username = r.get('username') or 'Unknown'
                    twitch_user_id = r.get('twitch_user_id') or ''
                    stream_id = r.get('stream_id') or ''
                    started_at = str(r.get('started_at')) if r.get('started_at') else ''
                    last_checked = str(r.get('last_checked')) if r.get('last_checked') else ''
                    details_obj = _parse_details(r.get('details'))
                    title = details_obj.get('title') or details_obj.get('stream_title') or ''
                    game_name = details_obj.get('game_name') or details_obj.get('game') or ''
                    short_title = (title[:250] + '...') if len(title) > 250 else title
                    stream_url = f"https://twitch.tv/{username}"
                    value_lines = [f"Twitch ID: {twitch_user_id}", f"Stream ID: {stream_id}", f"Started At: {started_at}", f"Last Checked: {last_checked}", f"URL: {stream_url}"]
                    if game_name:
                        value_lines.insert(0, f"Game: {game_name}")
                    if short_title:
                        value_lines.insert(0, f"Title: {short_title}")
                    value = "\n".join([vl for vl in value_lines if vl]) or "—"
                    # Add each user as a separate field; ensure we don't exceed the field character limits
                    try:
                        embed.add_field(name=str(username), value=value, inline=False)
                    except Exception:
                        # If something goes wrong, fallback to a minimal field
                        embed.add_field(name=str(username), value=f"Twitch ID: {twitch_user_id} | Stream ID: {stream_id}", inline=False)
                embed.set_footer(text=f"BotOfTheSpecter — {total} total live channels")
                await ctx.send(embed=embed)
        except Exception as e:
            self.logger.error(f"Error in online_streams command: {e}")
            await ctx.send("Failed to fetch online streams. Check logs for details.")

    @commands.command(name="live_status")
    async def live_status(self, ctx, username: str = None):
        ADMIN_CHANNEL_ID = 1103695077928345683
        if ctx.channel.id != ADMIN_CHANNEL_ID:
            try:
                await ctx.message.add_reaction("❌")
            except Exception:
                pass
            return await ctx.send("Sorry, you can't run the command.")
        if not username:
            return await ctx.send("Please provide a username, e.g. `!live_status someuser`.")
        username = str(username).lower()
        try:
            row = None
            if hasattr(self.bot, 'live_channel_manager') and self.bot.live_channel_manager:
                row = await self.bot.live_channel_manager.get_online_stream_by_username(username)
            if not row:
                return await ctx.send(f"User `{username}` is not currently marked as live or not tracked.")
            # Parse details safely
            details_raw = row.get('details') or ''
            details_obj = {}
            if isinstance(details_raw, dict):
                details_obj = details_raw
            elif isinstance(details_raw, str) and details_raw:
                try:
                    details_obj = json.loads(details_raw)
                except Exception:
                    details_obj = {}
            twitch_user_id = row.get('twitch_user_id') or ''
            stream_id = row.get('stream_id') or ''
            started_at = str(row.get('started_at')) if row.get('started_at') else ''
            last_checked = str(row.get('last_checked')) if row.get('last_checked') else ''
            title = details_obj.get('title') or details_obj.get('stream_title') or ''
            game_name = details_obj.get('game_name') or details_obj.get('game') or ''
            thumbnail = details_obj.get('thumbnail_url') or details_obj.get('thumbnail') or None
            stream_url = f"https://twitch.tv/{username}"
            embed = discord.Embed(
                title=f"{username} — Live Status",
                description=(title if title else f"{username} is live on Twitch") + f"\n[Watch on Twitch]({stream_url})",
                color=config.bot_color
            )
            # Key fields
            embed.add_field(name="Twitch ID", value=str(twitch_user_id) or "—", inline=True)
            embed.add_field(name="Stream ID", value=str(stream_id) or "—", inline=True)
            embed.add_field(name="Started At", value=started_at or "—", inline=False)
            embed.add_field(name="Last Checked", value=last_checked or "—", inline=False)
            if game_name:
                embed.add_field(name="Game", value=game_name, inline=False)
            # Add a small details summary if there's extra info (viewer count, tags, etc.)
            extra_lines = []
            if 'viewer_count' in details_obj:
                extra_lines.append(f"Viewers: {details_obj.get('viewer_count')}")
            if 'is_mature' in details_obj:
                extra_lines.append(f"Mature: {details_obj.get('is_mature')}")
            if extra_lines:
                embed.add_field(name='Extra', value=' | '.join(extra_lines), inline=False)
            if thumbnail:
                try:
                    embed.set_thumbnail(url=thumbnail)
                except Exception:
                    pass
            embed.set_footer(text=f"BotOfTheSpecter — live status for {username}")
            await ctx.send(embed=embed)
        except Exception as e:
            self.logger.error(f"Error in live_status command for {username}: {e}")
            await ctx.send("Failed to fetch live status. Check logs for details.")

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