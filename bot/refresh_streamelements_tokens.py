import os
import asyncio
import aiohttp
import aiomysql
import logging
from logging.handlers import RotatingFileHandler
from dotenv import load_dotenv
load_dotenv()

# StreamElements OAuth Configuration
CLIENT_ID = os.getenv('STREAMELEMENTS_CLIENT_ID')
CLIENT_SECRET = os.getenv('STREAMELEMENTS_SECRET_KEY')
DB_HOST = os.getenv('SQL_HOST')
DB_USER = os.getenv('SQL_USER')
DB_PASS = os.getenv('SQL_PASSWORD')
DB_NAME = "website"
TOKEN_URL = "https://api.streamelements.com/oauth2/token"

# Configure logging with rotation (keep last 5 runs, 50KB each)
log_dir = os.path.join(os.path.dirname(__file__), 'logs')
os.makedirs(log_dir, exist_ok=True)
log_file = os.path.join(log_dir, 'refresh_streamelements_tokens.log')
logger = logging.getLogger('streamelements_refresh')
logger.setLevel(logging.INFO)

# File handler with rotation
file_handler = RotatingFileHandler(log_file, maxBytes=50*1024, backupCount=5)
file_handler.setFormatter(logging.Formatter('%(message)s'))
logger.addHandler(file_handler)

# Console handler for terminal output
console_handler = logging.StreamHandler()
console_handler.setFormatter(logging.Formatter('%(message)s'))
logger.addHandler(console_handler)

async def get_username(pool, twitch_user_id):
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute("SELECT username FROM users WHERE twitch_user_id = %s", (twitch_user_id,))
                result = await cur.fetchone()
                return result[0] if result else None
    except Exception as e:
        print(f"⚠️  Failed to fetch username for twitch_user_id: {twitch_user_id} - {str(e)}")
        return None

async def refresh_streamelements_token(session, pool, twitch_user_id, refresh_token_value):
    try:
        # Fetch username for display
        username = await get_username(pool, twitch_user_id)
        username_display = f"{username}" if username else f"ID:{twitch_user_id}"
        data = {
            'grant_type': 'refresh_token',
            'client_id': CLIENT_ID,
            'client_secret': CLIENT_SECRET,
            'refresh_token': refresh_token_value
        }
        headers = {'Content-Type': 'application/x-www-form-urlencoded'}
        async with session.post(TOKEN_URL, data=data, headers=headers) as resp:
            result = await resp.json()
            if resp.status == 200 and 'access_token' in result:
                new_access = result['access_token']
                new_refresh = result.get('refresh_token', refresh_token_value)
                async with pool.acquire() as conn:
                    async with conn.cursor() as cur:
                        await cur.execute(
                            "UPDATE streamelements_tokens SET access_token=%s, refresh_token=%s WHERE twitch_user_id=%s",
                            (new_access, new_refresh, twitch_user_id)
                        )
                        await conn.commit()
                # Only return success status, don't log individual users
                return {"success": True, "username": username_display}
            else:
                error_msg = result.get('error', 'Unknown error')
                error_desc = result.get('error_description', '')
                logger.error(f"❌ Failed to refresh StreamElements token for user: {username_display}")
                logger.error(f"   Error: {error_msg} - {error_desc}")
                return {"success": False, "username": username_display, "error": f"{error_msg} - {error_desc}"}
    except Exception as e:
        logger.error(f"🔥 Exception refreshing StreamElements token for user: {twitch_user_id} - {str(e)}")
        return {"success": False, "username": username, "error": str(e)}

async def main():
    logger.info("🚀 Starting StreamElements token refresh process...")
    # Validate environment variables
    if not CLIENT_ID or not CLIENT_SECRET:
        logger.error("❌ Missing StreamElements client credentials in environment variables")
        logger.error("   Please set STREAMELEMENTS_CLIENT_ID and STREAMELEMENTS_SECRET_KEY")
        return
    if not DB_HOST or not DB_USER or not DB_PASS:
        logger.error("❌ Missing database credentials in environment variables")
        logger.error("   Please set SQL_HOST, SQL_USER, and SQL_PASSWORD")
        return
    # Create database connection pool
    try:
        pool = await aiomysql.create_pool(
            host=DB_HOST,
            user=DB_USER,
            password=DB_PASS,
            db=DB_NAME,
            autocommit=True
        )
    except Exception as e:
        logger.error(f"❌ Failed to connect to database: {str(e)}")
        return
    try:
        # Fetch all users with StreamElements refresh tokens
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute("SELECT twitch_user_id, refresh_token FROM streamelements_tokens WHERE refresh_token IS NOT NULL AND refresh_token != ''")
                tokens = await cur.fetchall()
        if not tokens:
            logger.info("ℹ️  No StreamElements users with refresh tokens found")
            return
        logger.info(f"📊 Found {len(tokens)} StreamElements users with refresh tokens")
        # Refresh tokens concurrently
        async with aiohttp.ClientSession() as session:
            tasks = [
                refresh_streamelements_token(session, pool, twitch_user_id, refresh_token_val)
                for twitch_user_id, refresh_token_val in tokens
            ]
            results = await asyncio.gather(*tasks, return_exceptions=True)
        # Count and report results
        successful = sum(1 for r in results if isinstance(r, dict) and r.get("success"))
        failed = len(results) - successful
        logger.info(f"\n📈 StreamElements token refresh completed:")
        logger.info(f"   ✅ Successful: {successful}")
        logger.info(f"   ❌ Failed: {failed}")
        logger.info(f"   📊 Total: {len(results)}")
        # Log failed users if any
        if failed > 0:
            failed_users = [r.get("username", "Unknown") for r in results if isinstance(r, dict) and not r.get("success")]
            logger.warning(f"   ⚠️  Failed users: {', '.join(failed_users)}")
    except Exception as e:
        logger.error(f"❌ Error during token refresh process: {str(e)}")
    finally:
        # Clean up database pool
        pool.close()
        await pool.wait_closed()
        logger.info("🔒 Database connection closed")
        logger.info("")  # Blank line for separation between runs

if __name__ == "__main__":
    asyncio.run(main())