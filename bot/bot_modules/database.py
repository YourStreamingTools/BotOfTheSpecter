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