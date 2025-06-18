import aiomysql
import os
from dotenv import load_dotenv
load_dotenv()

SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER')
SQL_PASSWORD = os.getenv('SQL_PASSWORD')

async def get_mysql_connection(CHANNEL_NAME):
    return await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db=CHANNEL_NAME
    )

async def access_website_database():
    return await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db="website",
    )

async def get_spam_patterns():
    pattern_db = await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db="spam_pattern",
    )
    async with pattern_db.cursor(aiomysql.DictCursor) as cursor:
        await cursor.execute("SELECT spam_pattern FROM spam_patterns")
        results = await cursor.fetchall()
    pattern_db.close()
    # Compile the regular expressions
    import re
    compiled_patterns = [re.compile(row["spam_pattern"], re.IGNORECASE) for row in results if row["spam_pattern"]]
    return compiled_patterns