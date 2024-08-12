import os
import aiohttp
from fastapi import FastAPI, HTTPException, Depends
import uvicorn
from pydantic import BaseModel
from typing import Dict, List
import json
import aiomysql
from dotenv import load_dotenv, find_dotenv

# Load ENV file and get SQL Data
load_dotenv(find_dotenv("/var/www/bot/.env"))
SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER')
SQL_PASSWORD = os.getenv('SQL_PASSWORD')
ADMIN_KEY = os.getenv('ADMIN_KEY')

# Define the tags metadata
tags_metadata = [
    {
        "name": "Commands",
        "description": "Operations related to commands, including retrieving command responses.",
    },
]

# Initialize FastAPI app with metadata and tags
app = FastAPI(
    title="BotOfTheSpecter",
    description="API Endpoints for BotOfTheSpecter",
    version="1.0.0",
    terms_of_service="https://botofthespecter.com/terms-of-service.php",
    contact={
        "name": "BotOfTheSpecter",
        "url": "https://discord.com/invite/ANwEkpauHJ",
        "email": "questions@botofthespecter.com",
    },
    openapi_tags=tags_metadata
)

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

# Verify the ADMIN Key Given
async def verify_admin_key(api_key: str):
    if api_key != ADMIN_KEY:
        raise HTTPException(
            status_code=403,
            detail="Forbidden: Invalid Admin Key",
        )

# Define the response model
class ValidationErrorDetail(BaseModel):
    loc: List[str]
    msg: str
    type: str

class ValidationErrorResponse(BaseModel):
    detail: List[ValidationErrorDetail]
    class Config:
        json_schema_extra = {
            "example": {
                "detail": [
                    {
                        "loc": ["body", "api_key"],
                        "msg": "field required",
                        "type": "value_error.missing"
                    }
                ]
            }
        }

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

# killCommand EndPoint
@app.get(
    "/kill",
    response_model=KillCommandResponse,
    summary="Retrieve the Kill Command Responses",
    responses={
        422: {
            "model": ValidationErrorResponse,
            "description": "Validation Error"
        }
    },
    tags=["Commands"]
)
async def get_kill_responses(api_key: str = Depends(verify_api_key)):
    kill_command_path = "/var/www/api/killCommand.json"
    if not os.path.exists(kill_command_path):
        raise HTTPException(status_code=404, detail="File not found")
    with open(kill_command_path, "r") as kill_command_file:
        kill_commands = json.load(kill_command_file)
    return {"killcommand": kill_commands}

# Joke endpoint
@app.get("/joke", summary="Get a random joke")
async def get_joke(api_key: str = Depends(verify_api_key)):
    jokes_api_url = "https://v2.jokeapi.dev/joke/Programming,Miscellaneous,Pun,Spooky,Christmas?blacklistFlags=nsfw,religious,political,racist,sexist,explicit"
    async with aiohttp.ClientSession() as session:
        async with session.get(jokes_api_url) as response:
            if response.status != 200:
                raise HTTPException(status_code=502, detail="Failed to retrieve joke from the API")
            data = await response.json()
    # Check if the joke type is present in the data
    if "type" not in data:
        raise HTTPException(status_code=500, detail="Error: Unable to retrieve joke from API.")
    # Get the joke based on the type
    if data['type'] == 'single':
        joke = data['joke']
    elif data['type'] == 'twopart':
        joke = f"{data['setup']}\n{data['delivery']}"
    else:
        raise HTTPException(status_code=500, detail="Error: Invalid joke type.")
    return {"joke": joke}

# authorizedusers EndPoint (hidden from docs)
@app.get("/authorizedusers", include_in_schema=False)
async def get_authorized_users(api_key: str = Depends(verify_admin_key)):
    auth_users_path = "/var/www/api/authusers.json"
    if not os.path.exists(auth_users_path):
        raise HTTPException(status_code=404, detail="File not found")
    with open(auth_users_path, "r") as auth_users_file:
        auth_users = json.load(auth_users_file)
    return auth_users

if __name__ == "__main__":
    uvicorn.run(
        app,
        host="0.0.0.0",
        port=8000,
        ssl_certfile="/etc/letsencrypt/live/botofthespecter.com-0001/fullchain.pem",
        ssl_keyfile="/etc/letsencrypt/live/botofthespecter.com-0001/privkey.pem"
    )