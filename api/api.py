import os
from fastapi import FastAPI, HTTPException, Depends
import uvicorn
from pydantic import BaseModel
from typing import Dict
import json
import aiomysql
from dotenv import load_dotenv, find_dotenv

# Load ENV file and get SQL Data
load_dotenv(find_dotenv("/var/www/bot/.env"))
SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER')
SQL_PASSWORD = os.getenv('SQL_PASSWORD')

app = FastAPI()

# Make a connection to the MySQL Server
async def get_mysql_connection():
    return await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db="website"
    )

# Verify the API Key Given
async def verify_api_key(api_key: str):
    conn = await get_mysql_connection()
    try:
        async with conn.cursor() as cur:
            await cur.execute("SELECT api_key FROM users WHERE api_key=%s", (api_key,))
            result = await cur.fetchone()
            if result is None:
                raise HTTPException(
                    status_code=401,
                    detail="Invalid API Key",
                )
    finally:
        conn.close()

# Define the response model
class KillCommandResponse(BaseModel):
    class Config:
        json_schema_extra = {
            "example": {
                "killcommand": {
                    "killcommand.self.1": "$1 managed to kill themself.",
                    "killcommand.self.2": "$1 died from an unknown cause.",
                    "killcommand.self.3": "$1 was crushed by a boulder, or some piece of debris.",
                    "killcommand.self.4": "$1 exploded.",
                    "killcommand.self.5": "$1 forgot how to breathe."
                }
            }
        }

# Load the killCommand JSON file.
with open("/var/www/api/killCommand.json", "r") as killCommand:
    kill_commands = json.load(killCommand)

# killCommand EndPoint
@app.get(
    "/killcommand",
    response_model=KillCommandResponse,
    summary="Retrieve the Kill Command Responses"
)
async def get_kill_commands(api_key: str = Depends(verify_api_key)):
    return {"killcommand": kill_commands}

if __name__ == "__main__":
    uvicorn.run(
        app,
        host="0.0.0.0",
        port=8000,
        ssl_certfile="/etc/letsencrypt/live/botofthespecter.com-0001/fullchain.pem",
        ssl_keyfile="/etc/letsencrypt/live/botofthespecter.com-0001/privkey.pem"
    )