import os
import aiohttp
import aiomysql
import random
import json
from fastapi import FastAPI, HTTPException, Depends
from fastapi.responses import FileResponse
import uvicorn
from pydantic import BaseModel
from typing import Dict, List
from jokeapi import Jokes
from dotenv import load_dotenv, find_dotenv

# Load ENV file
load_dotenv(find_dotenv("/var/www/bot/.env"))
SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER')
SQL_PASSWORD = os.getenv('SQL_PASSWORD')
ADMIN_KEY = os.getenv('ADMIN_KEY')
favicon_path = "https://cdn.botofthespecter.com/logo.png"

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
    openapi_tags=tags_metadata,
    swagger_ui_parameters={"defaultModelsExpandDepth": -1}
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

# Define the response model for validation errors
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

# Define the response model for KillCommandResponse
class KillCommandResponse(BaseModel):
    killcommand: Dict[str, str]
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

# Define the response model for Jokes
class JokeResponse(BaseModel):
    error: bool
    category: str
    type: str
    joke: str = None
    setup: str = None
    delivery: str = None
    flags: Dict[str, bool]
    id: int
    safe: bool
    lang: str
    class Config:
        json_schema_extra = {
            "example": {
                "error": False,
                "category": "Programming",
                "type": "single",
                "joke": "A man is smoking a cigarette and blowing smoke rings into the air. His girlfriend becomes irritated with the smoke and says \"Can't you see the warning on the cigarette pack? Smoking is hazardous to your health!\" to which the man replies, \"I am a programmer. We don't worry about warnings; we only worry about errors.\"",
                "flags": {
                    "nsfw": False,
                    "religious": False,
                    "political": False,
                    "racist": False,
                    "sexist": False,
                    "explicit": False
                },
                "id": 38,
                "safe": True,
                "lang": "en"
            }
        }

# Define the response model for Quotes
class QuoteResponse(BaseModel):
    author: str
    quote: str
    class Config:
        json_schema_extra = {
            "example": {
                "author": "Winston Churchill",
                "quote": "Success is not final, failure is not fatal: It is the courage to continue that counts."
            }
        }

# Create Docs Favicon
@app.get('/favicon.ico', include_in_schema=False)
async def favicon():
    return FileResponse(favicon_path)

# Quotes endpoint
@app.get(
    "/quotes",
    response_model=QuoteResponse,
    summary="Get a random quote",
    tags=["Commands"]
)
async def get_quote(api_key: str = Depends(verify_api_key)):
    quotes_path = "/var/www/api/quotes.json"
    if not os.path.exists(quotes_path):
        raise HTTPException(status_code=404, detail="Quotes file not found")
    with open(quotes_path, "r") as quotes_file:
        quotes = json.load(quotes_file)
    # Select a random author
    random_author = random.choice(list(quotes.keys()))
    # Select a random quote from the chosen author
    random_quote = random.choice(quotes[random_author])
    return {"author": random_author, "quote": random_quote}

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
@app.get(
    "/joke",
    response_model=JokeResponse,
    summary="Get a random joke",
    tags=["Commands"]
)
async def get_joke(api_key: str = Depends(verify_api_key)):
    jokes = await Jokes()
    get_joke = await jokes.get_joke(blacklist=['nsfw', 'racist', 'sexist', 'political', 'religious'])
    if "category" not in get_joke:
        raise HTTPException(status_code=500, detail="Error: Unable to retrieve joke from API.")
    # Return the joke response
    return get_joke

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