import os
import time
import logging
import asyncio
import aiomysql
from dotenv import load_dotenv

load_dotenv()
logger = logging.getLogger(__name__)
logger.setLevel(logging.INFO)

SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER')
SQL_PASSWORD = os.getenv('SQL_PASSWORD')

async def access_website_database():
    try:
        return await aiomysql.connect(
            host=SQL_HOST,
            user=SQL_USER,
            password=SQL_PASSWORD,
            db="website",
        )
    except Exception as e:
        logger.error(f"Failed to connect to the database: {e}")
        return None

async def get_all_usernames():
    conn = await access_website_database()
    if not conn:
        logger.error("Database connection is None. Cannot fetch usernames.")
        return []
    try:
        async with conn.cursor() as cursor:
            await cursor.execute("SELECT username FROM users")
            rows = await cursor.fetchall()
        return [row[0] for row in rows]
    except Exception as e:
        logger.error(f"Error while fetching usernames: {e}")
        return []
    finally:
        if conn and not conn.closed:
            await conn.ensure_closed()

async def remove_old_videos():
    base_dir = "/mnt/s3/bots-stream"
    usernames = await get_all_usernames()
    for username in usernames:
        user_dir = os.path.join(base_dir, username)
        if os.path.exists(user_dir) and os.path.isdir(user_dir):
            for file in os.listdir(user_dir):
                file_path = os.path.join(user_dir, file)
                if os.path.isfile(file_path):
                    age_hours = (time.time() - os.path.getmtime(file_path)) / 3600
                    if age_hours > 24:
                        os.remove(file_path)
                        logger.info(f"Removed old file: {file_path}")

if __name__ == "__main__":
    asyncio.run(remove_old_videos())