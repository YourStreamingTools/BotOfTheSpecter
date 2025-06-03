import os
import asyncio
import aiohttp
import aiomysql
from dotenv import load_dotenv
load_dotenv()

CLIENT_ID = os.getenv('STREAMELEMENTS_CLIENT_ID')
CLIENT_SECRET = os.getenv('STREAMELEMENTS_SECRET_KEY')
DB_HOST = os.getenv('SQL_HOST')
DB_USER = os.getenv('SQL_USER')
DB_PASS = os.getenv('SQL_PASSWORD')
DB_NAME = "website"
TOKEN_URL = "https://api.streamelements.com/oauth2/token"

async def refresh_token(session, pool, twitch_user_id, refresh_token):
    data = { 'grant_type': 'refresh_token', 'client_id': CLIENT_ID, 'client_secret': CLIENT_SECRET, 'refresh_token': refresh_token}
    headers = { 'Content-Type': 'application/x-www-form-urlencoded' }
    async with session.post(TOKEN_URL, data=data, headers=headers) as resp:
        result = await resp.json()
        if resp.status == 200 and 'access_token' in result:
            new_access = result['access_token']
            new_refresh = result.get('refresh_token', refresh_token)
            async with pool.acquire() as conn:
                async with conn.cursor() as cur:
                    await cur.execute("UPDATE streamelements_tokens SET access_token=%s, refresh_token=%s WHERE twitch_user_id=%s", (new_access, new_refresh, twitch_user_id))
                    await conn.commit()
            print(f"Refreshed for {twitch_user_id}")
        else:
            print(f"Failed for {twitch_user_id}: {result}")

async def main():
    pool = await aiomysql.create_pool(host=DB_HOST, user=DB_USER, password=DB_PASS, db=DB_NAME, autocommit=True)
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute("SELECT twitch_user_id, refresh_token FROM streamelements_tokens")
            tokens = await cur.fetchall()
    async with aiohttp.ClientSession() as session:
        tasks = [refresh_token(session, pool, twitch_user_id, refresh_token) for twitch_user_id, refresh_token in tokens]
        await asyncio.gather(*tasks)
    pool.close()
    await pool.wait_closed()

if __name__ == "__main__":
    asyncio.run(main())