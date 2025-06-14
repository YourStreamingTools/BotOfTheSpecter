import os
import asyncio
import aiohttp
import aiomysql
import base64
from dotenv import load_dotenv

load_dotenv()

# Discord OAuth Configuration
DISCORD_CLIENT_ID = os.getenv('DISCORD_CLIENT_ID')
DISCORD_CLIENT_SECRET = os.getenv('DISCORD_CLIENT_SECRET')
DB_HOST = os.getenv('SQL_HOST')
DB_USER = os.getenv('SQL_USER')
DB_PASS = os.getenv('SQL_PASSWORD')
DB_NAME = "website"
TOKEN_URL = "https://discord.com/api/oauth2/token"

async def refresh_discord_token(session, pool, user_id, refresh_token):
    try:
        # Prepare the refresh token request
        data = {'grant_type': 'refresh_token','refresh_token': refresh_token}
        # Use HTTP Basic authentication as recommended by Discord
        auth_string = base64.b64encode(f"{DISCORD_CLIENT_ID}:{DISCORD_CLIENT_SECRET}".encode()).decode()
        headers = {'Content-Type': 'application/x-www-form-urlencoded','Authorization': f'Basic {auth_string}'}
        async with session.post(TOKEN_URL, data=data, headers=headers) as resp:
            result = await resp.json()
            if resp.status == 200 and 'access_token' in result:
                # Successfully refreshed the token
                new_access_token = result['access_token']
                new_refresh_token = result.get('refresh_token', refresh_token)  # Discord may or may not return a new refresh token
                expires_in = result.get('expires_in', 604800)  # Default to 7 days if not provided
                # Update the database with new tokens
                async with pool.acquire() as conn:
                    async with conn.cursor() as cur:
                        await cur.execute(
                            "UPDATE discord_users SET access_token=%s, refresh_token=%s, expires_in=%s WHERE user_id=%s",
                            (new_access_token, new_refresh_token, expires_in, user_id)
                        )
                        await conn.commit()
                print(f"✅ Successfully refreshed Discord token for user_id: {user_id}")
                return True
            else:
                # Handle errors
                error_msg = result.get('error', 'Unknown error')
                error_desc = result.get('error_description', '')
                print(f"❌ Failed to refresh Discord token for user_id: {user_id}")
                print(f"   Error: {error_msg} - {error_desc}")
                return False
    except Exception as e:
        print(f"🔥 Exception refreshing Discord token for user_id: {user_id} - {str(e)}")
        return False

async def main():
    print("🚀 Starting Discord token refresh process...")
    # Validate environment variables
    if not DISCORD_CLIENT_ID or not DISCORD_CLIENT_SECRET:
        print("❌ Missing Discord client credentials in environment variables")
        print("   Please set DISCORD_CLIENT_ID and DISCORD_CLIENT_SECRET")
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
        # Fetch all users with Discord refresh tokens
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute("SELECT user_id, refresh_token FROM discord_users WHERE refresh_token IS NOT NULL AND refresh_token != ''")
                tokens = await cur.fetchall()
        if not tokens:
            print("ℹ️  No Discord users with refresh tokens found")
            return
        print(f"📊 Found {len(tokens)} Discord users with refresh tokens")
        # Refresh tokens concurrently
        async with aiohttp.ClientSession() as session:
            tasks = [
                refresh_discord_token(session, pool, user_id, refresh_token) 
                for user_id, refresh_token in tokens
            ]
            results = await asyncio.gather(*tasks, return_exceptions=True)
        # Count successful refreshes
        successful = sum(1 for result in results if result is True)
        failed = len(results) - successful
        print(f"\n📈 Discord token refresh completed:")
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
