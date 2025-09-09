import os
import asyncio
import aiohttp
import aiomysql
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

async def refresh_spotify_token(session, pool, user_id, refresh_token):
    try:
        data = {
            "grant_type": "refresh_token",
            "refresh_token": refresh_token,
            "client_id": SPOTIFY_CLIENT_ID,
            "client_secret": SPOTIFY_CLIENT_SECRET,
        }
        async with session.post(TOKEN_URL, data=data) as response:
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
                print(f"✅ Successfully refreshed Spotify token for user_id: {user_id}")
                return True
            else:
                error_msg = result.get('error', 'Unknown error')
                error_desc = result.get('error_description', '')
                print(f"❌ Failed to refresh Spotify token for user_id: {user_id}")
                print(f"   Error: {error_msg} - {error_desc}")
                return False
    except Exception as e:
        print(f"🔥 Exception refreshing Spotify token for user_id: {user_id} - {str(e)}")
        return False

async def main():
    print("🚀 Starting Spotify token refresh process...")
    # Validate environment variables
    if not SPOTIFY_CLIENT_ID or not SPOTIFY_CLIENT_SECRET:
        print("❌ Missing Spotify client credentials in environment variables")
        print("   Please set SPOTIFY_CLIENT_ID and SPOTIFY_CLIENT_SECRET")
        return
    if not DB_HOST or not DB_USER or not DB_PASS:
        print("❌ Missing database credentials in environment variables")
        print("   Please set SQL_HOST, SQL_USER, and SQL_PASSWORD")
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
        print(f"❌ Failed to connect to database: {str(e)}")
        return
    try:
        # Fetch all users with Spotify refresh tokens
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute("SELECT user_id, refresh_token FROM spotify_tokens WHERE refresh_token IS NOT NULL AND refresh_token != ''")
                tokens = await cur.fetchall()
        if not tokens:
            print("ℹ️  No Spotify users with refresh tokens found")
            return
        print(f"📊 Found {len(tokens)} Spotify users with refresh tokens")
        # Refresh tokens concurrently
        async with aiohttp.ClientSession() as session:
            tasks = [
                refresh_spotify_token(session, pool, user_id, refresh_token) 
                for user_id, refresh_token in tokens
            ]
            results = await asyncio.gather(*tasks, return_exceptions=True)
        # Count successful refreshes
        successful = sum(1 for result in results if result is True)
        failed = len(results) - successful
        print(f"\n📈 Spotify token refresh completed:")
        print(f"   ✅ Successful: {successful}")
        print(f"   ❌ Failed: {failed}")
        print(f"   📊 Total: {len(results)}")
    except Exception as e:
        print(f"❌ Error during token refresh process: {str(e)}")
    finally:
        # Clean up database pool
        pool.close()
        await pool.wait_closed()
        print("🔒 Database connection closed")

if __name__ == "__main__":
    asyncio.run(main())