import os
import asyncio
import aiohttp
import aiomysql
import base64
import logging
from logging.handlers import RotatingFileHandler
from dotenv import load_dotenv

load_dotenv()

# Spotify OAuth Configuration
SPOTIFY_CLIENT_ID = os.getenv('SPOTIFY_CLIENT_ID')
SPOTIFY_CLIENT_SECRET = os.getenv('SPOTIFY_CLIENT_SECRET')
DB_HOST = os.getenv('SQL_HOST')
DB_USER = os.getenv('SQL_USER')
DB_PASS = os.getenv('SQL_PASSWORD')
DB_NAME = "website"
TOKEN_URL = "https://accounts.spotify.com/api/token"

# Configure logging with rotation (keep last 5 runs, 50KB each)
log_dir = os.path.join(os.path.dirname(__file__), 'logs')
os.makedirs(log_dir, exist_ok=True)
log_file = os.path.join(log_dir, 'spotify_refresh.log')
logger = logging.getLogger('spotify_refresh')
logger.setLevel(logging.INFO)

# File handler with rotation
file_handler = RotatingFileHandler(log_file, maxBytes=50*1024, backupCount=5)
file_handler.setFormatter(logging.Formatter('%(message)s'))
logger.addHandler(file_handler)

# Console handler for terminal output
console_handler = logging.StreamHandler()
console_handler.setFormatter(logging.Formatter('%(message)s'))
logger.addHandler(console_handler)

async def get_username(pool, user_id):
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute("SELECT username FROM users WHERE id = %s", (user_id,))
                result = await cur.fetchone()
                return result[0] if result else None
    except Exception as e:
        print(f"⚠️  Failed to fetch username for user_id: {user_id} - {str(e)}")
        return None

async def refresh_spotify_token(session, pool, user_id, refresh_token, own_client, client_id, client_secret):
    try:
        # Fetch username for display
        username = await get_username(pool, user_id)
        username_display = f"{username}" if username else f"ID:{user_id}"
        data = {"grant_type": "refresh_token","refresh_token": refresh_token,}
        # Use user's own credentials if enabled, otherwise global
        if own_client == 1 and client_id and client_secret:
            auth_string = base64.b64encode(f"{client_id}:{client_secret}".encode()).decode()
        else:
            auth_string = base64.b64encode(f"{SPOTIFY_CLIENT_ID}:{SPOTIFY_CLIENT_SECRET}".encode()).decode()
        headers = {'Content-Type': 'application/x-www-form-urlencoded','Authorization': f'Basic {auth_string}'}
        async with session.post(TOKEN_URL, data=data, headers=headers) as response:
            result = await response.json()
            if response.status == 200 and 'access_token' in result:
                new_access_token = result['access_token']
                new_refresh_token = result.get('refresh_token', refresh_token)
                async with pool.acquire() as conn:
                    async with conn.cursor() as cur:
                        await cur.execute(
                            "UPDATE spotify_tokens SET access_token = %s, refresh_token = %s WHERE user_id = %s",
                            (new_access_token, new_refresh_token, user_id)
                        )
                        await conn.commit()
                # Only return success status, don't log individual users
                return {"success": True, "username": username_display}
            else:
                error_msg = result.get('error', 'Unknown error')
                error_desc = result.get('error_description', '')
                logger.error(f"❌ Failed to refresh Spotify token for user: {username_display}")
                logger.error(f"   Error: {error_msg} - {error_desc}")
                return {"success": False, "username": username_display, "error": f"{error_msg} - {error_desc}"}
    except Exception as e:
        logger.error(f"🔥 Exception refreshing Spotify token for user: {user_id} - {str(e)}")
        return {"success": False, "username": username, "error": str(e)}

async def main():
    logger.info("🚀 Starting Spotify token refresh process...")
    # Validate environment variables
    if not SPOTIFY_CLIENT_ID or not SPOTIFY_CLIENT_SECRET:
        logger.error("❌ Missing Spotify client credentials in environment variables")
        logger.error("   Please set SPOTIFY_CLIENT_ID and SPOTIFY_CLIENT_SECRET")
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
        # Fetch all users with Spotify refresh tokens
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute("SELECT user_id, refresh_token, own_client, client_id, client_secret FROM spotify_tokens WHERE refresh_token IS NOT NULL AND refresh_token != ''")
                tokens = await cur.fetchall()
        if not tokens:
            logger.info("ℹ️  No Spotify users with refresh tokens found")
            return
        logger.info(f"📊 Found {len(tokens)} Spotify users with refresh tokens")
        # Refresh tokens concurrently
        async with aiohttp.ClientSession() as session:
            tasks = [
                refresh_spotify_token(session, pool, user_id, refresh_token, own_client, client_id, client_secret) 
                for user_id, refresh_token, own_client, client_id, client_secret in tokens
            ]
            results = await asyncio.gather(*tasks, return_exceptions=True)
        # Count and report results
        successful = sum(1 for r in results if isinstance(r, dict) and r.get("success"))
        failed = len(results) - successful
        logger.info(f"\n📈 Spotify token refresh completed:")
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