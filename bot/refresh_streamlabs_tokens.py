import os
import asyncio
import aiohttp
import aiomysql
import time
from dotenv import load_dotenv

load_dotenv()

# StreamLabs OAuth Configuration
STREAMLABS_CLIENT_ID = os.getenv('STREAMLABS_CLIENT_ID')
STREAMLABS_CLIENT_SECRET = os.getenv('STREAMLABS_SECRET_KEY')
DB_HOST = os.getenv('SQL_HOST')
DB_USER = os.getenv('SQL_USER')
DB_PASS = os.getenv('SQL_PASSWORD')
DB_NAME = "website"
TOKEN_URL = "https://streamlabs.com/api/v1.0/token"
REDIRECT_URI = "https://dashboard.botofthespecter.com/streamlabs.php"

async def refresh_streamlabs_token(session, pool, twitch_user_id, refresh_token_value):
    try:
        data = {
            'grant_type': 'refresh_token',
            'client_id': STREAMLABS_CLIENT_ID,
            'client_secret': STREAMLABS_CLIENT_SECRET,
            'redirect_uri': REDIRECT_URI,
            'refresh_token': refresh_token_value
        }
        headers = {'Content-Type': 'application/x-www-form-urlencoded'}
        async with session.post(TOKEN_URL, data=data, headers=headers) as resp:
            result = await resp.json()
            if resp.status == 200 and 'access_token' in result:
                new_access = result['access_token']
                new_refresh = result.get('refresh_token', refresh_token_value)
                new_expires_in = result.get('expires_in', 3600)
                created_at_timestamp = int(time.time())
                # Update database with new tokens
                async with pool.acquire() as conn:
                    async with conn.cursor() as cur:
                        await cur.execute(
                            "UPDATE streamlabs_tokens SET access_token=%s, refresh_token=%s, expires_in=%s, created_at=%s WHERE twitch_user_id=%s",
                            (new_access, new_refresh, new_expires_in, created_at_timestamp, twitch_user_id)
                        )
                        await conn.commit()
                print(f"✅ Successfully refreshed StreamLabs token for twitch_user_id: {twitch_user_id}")
                return True
            else:
                error_msg = result.get('error', 'Unknown error')
                error_desc = result.get('error_description', '')
                print(f"❌ Failed to refresh StreamLabs token for twitch_user_id: {twitch_user_id}")
                print(f"   Error: {error_msg} - {error_desc}")
                return False
    except Exception as e:
        print(f"🔥 Exception refreshing StreamLabs token for twitch_user_id: {twitch_user_id} - {str(e)}")
        return False

async def main():
    print("🚀 Starting StreamLabs token refresh process...")
    # Validate environment variables
    if not STREAMLABS_CLIENT_ID or not STREAMLABS_CLIENT_SECRET:
        print("❌ Missing StreamLabs client credentials in environment variables")
        print("   Please set STREAMLABS_CLIENT_ID and STREAMLABS_SECRET_KEY")
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
        # Fetch all users with StreamLabs refresh tokens
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "SELECT twitch_user_id, refresh_token FROM streamlabs_tokens WHERE refresh_token IS NOT NULL AND refresh_token != ''"
                )
                tokens = await cur.fetchall()
        if not tokens:
            print("ℹ️  No StreamLabs users with refresh tokens found")
            return
        print(f"📊 Found {len(tokens)} StreamLabs users with refresh tokens")
        # Refresh tokens concurrently
        async with aiohttp.ClientSession() as session:
            tasks = [
                refresh_streamlabs_token(session, pool, twitch_user_id, refresh_token_val)
                for twitch_user_id, refresh_token_val in tokens
            ]
            results = await asyncio.gather(*tasks, return_exceptions=True)
        # Count successful refreshes
        successful = sum(1 for result in results if result is True)
        failed = len(results) - successful
        print(f"\n📈 StreamLabs token refresh completed:")
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
