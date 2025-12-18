import os
import sys
import time
import logging
import asyncio
from datetime import datetime, timedelta
from logging.handlers import RotatingFileHandler
import aiomysql
import aiohttp
from dotenv import load_dotenv

# Load environment variables
load_dotenv()

# Configuration
SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER')
SQL_PASSWORD = os.getenv('SQL_PASSWORD')
CLIENT_ID = os.getenv('CLIENT_ID')
CLIENT_SECRET = os.getenv('CLIENT_SECRET')
LOG_FILE = '/home/botofthespecter/logs/custom_bot_token_refresh.log'
REFRESH_INTERVAL = 14400  # 4 hours in seconds

# Setup logging
os.makedirs(os.path.dirname(LOG_FILE), exist_ok=True)
logger = logging.getLogger('CustomBotTokenRefresh')
logger.setLevel(logging.INFO)
handler = RotatingFileHandler(LOG_FILE, maxBytes=10485760, backupCount=5, encoding='utf-8')
formatter = logging.Formatter('%(asctime)s - %(levelname)s - %(message)s', datefmt='%Y-%m-%d %H:%M:%S')
handler.setFormatter(formatter)
logger.addHandler(handler)

# Also log to console
console_handler = logging.StreamHandler(sys.stdout)
console_handler.setFormatter(formatter)
logger.addHandler(console_handler)

async def get_database_connection():
    try:
        connection = await aiomysql.connect(
            host=SQL_HOST,
            user=SQL_USER,
            password=SQL_PASSWORD,
            db='website',
            cursorclass=aiomysql.DictCursor
        )
        return connection
    except Exception as e:
        logger.error(f"Failed to connect to database: {e}")
        return None

async def get_custom_bots_needing_refresh():
    connection = await get_database_connection()
    if not connection:
        return []
    try:
        async with connection.cursor() as cursor:
            # Get bots where token expires within next hour or is already expired
            query = """
                SELECT 
                    cb.bot_channel_id,
                    cb.bot_username,
                    cb.access_token,
                    cb.refresh_token,
                    cb.token_expires,
                    cb.channel_id,
                    u.username
                FROM custom_bots cb
                JOIN users u ON cb.channel_id = u.id
                WHERE cb.refresh_token IS NOT NULL 
                AND cb.refresh_token != ''
                AND (cb.token_expires IS NULL OR cb.token_expires <= DATE_ADD(NOW(), INTERVAL 1 HOUR))
                AND cb.is_verified = 1
            """
            await cursor.execute(query)
            bots = await cursor.fetchall()
            return bots
    except Exception as e:
        logger.error(f"Error fetching custom bots: {e}")
        return []
    finally:
        connection.close()

async def refresh_bot_token(bot_channel_id, refresh_token, bot_username, channel_username):
    url = 'https://id.twitch.tv/oauth2/token'
    data = {
        'grant_type': 'refresh_token',
        'refresh_token': refresh_token,
        'client_id': CLIENT_ID,
        'client_secret': CLIENT_SECRET
    }
    try:
        async with aiohttp.ClientSession() as session:
            async with session.post(url, data=data, timeout=aiohttp.ClientTimeout(total=10)) as response:
                if response.status != 200:
                    error_text = await response.text()
                    logger.error(f"Failed to refresh token for {bot_username} (channel: {channel_username}): HTTP {response.status} - {error_text}")
                    return None
                result = await response.json()
                if 'access_token' not in result:
                    logger.error(f"Invalid response for {bot_username}: {result}")
                    return None
                new_access = result['access_token']
                new_refresh = result.get('refresh_token', refresh_token)
                expires_in = result.get('expires_in', 14400)  # Default 4 hours
                expires_at = datetime.now() + timedelta(seconds=expires_in)
                logger.info(f"Successfully refreshed token for {bot_username} (channel: {channel_username}). Expires at: {expires_at}")
                return {
                    'access_token': new_access,
                    'refresh_token': new_refresh,
                    'expires_at': expires_at.strftime('%Y-%m-%d %H:%M:%S'),
                    'bot_channel_id': bot_channel_id
                }
    except asyncio.TimeoutError:
        logger.error(f"Timeout while refreshing token for {bot_username} (channel: {channel_username})")
        return None
    except Exception as e:
        logger.error(f"Error refreshing token for {bot_username} (channel: {channel_username}): {e}")
        return None

async def update_bot_token(token_data):
    connection = await get_database_connection()
    if not connection:
        return False
    try:
        async with connection.cursor() as cursor:
            query = """
                UPDATE custom_bots 
                SET access_token = %s, 
                    refresh_token = %s, 
                    token_expires = %s
                WHERE bot_channel_id = %s
                LIMIT 1
            """
            await cursor.execute(query, (
                token_data['access_token'],
                token_data['refresh_token'],
                token_data['expires_at'],
                token_data['bot_channel_id']
            ))
            await connection.commit()
            return True
    except Exception as e:
        logger.error(f"Error updating token in database: {e}")
        return False
    finally:
        connection.close()

async def refresh_all_custom_bot_tokens():
    logger.info("Starting custom bot token refresh cycle...")
    # Validate environment variables
    if not all([SQL_HOST, SQL_USER, SQL_PASSWORD, CLIENT_ID, CLIENT_SECRET]):
        logger.error("Missing required environment variables. Please check .env file.")
        return
    # Get bots that need refresh
    bots = await get_custom_bots_needing_refresh()
    if not bots:
        logger.info("No custom bots need token refresh at this time.")
        return
    logger.info(f"Found {len(bots)} custom bot(s) needing token refresh")
    # Refresh each bot's token
    success_count = 0
    fail_count = 0
    for bot in bots:
        bot_channel_id = bot['bot_channel_id']
        refresh_token = bot['refresh_token']
        bot_username = bot['bot_username']
        channel_username = bot['username']
        # Refresh the token
        token_data = await refresh_bot_token(bot_channel_id, refresh_token, bot_username, channel_username)
        if token_data:
            # Update database
            if await update_bot_token(token_data):
                success_count += 1
                logger.info(f"✓ Successfully updated token for {bot_username} (channel: {channel_username})")
            else:
                fail_count += 1
                logger.error(f"✗ Failed to save token for {bot_username} (channel: {channel_username})")
        else:
            fail_count += 1
            logger.error(f"✗ Failed to refresh token for {bot_username} (channel: {channel_username})")
        # Small delay between requests to avoid rate limiting
        await asyncio.sleep(1)
    logger.info(f"Token refresh cycle complete. Success: {success_count}, Failed: {fail_count}")

async def main():
    logger.info("🚀 Starting Custom Bot Token Refresh process...")
    try:
        await refresh_all_custom_bot_tokens()
    except Exception as e:
        logger.error(f"❌ Error during token refresh process: {e}")
    logger.info("🔒 Custom Bot Token Refresh process completed")
    logger.info("")  # Blank line for separation between runs

if __name__ == '__main__':
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        logger.info("Custom Bot Token Refresh process stopped by user")
        sys.exit(0)
    except Exception as e:
        logger.error(f"Fatal error: {e}")
        sys.exit(1)