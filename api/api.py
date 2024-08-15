import os
import aiohttp
import aiomysql
import random
import json
from fastapi import FastAPI, HTTPException, Depends, Body
from fastapi.responses import FileResponse, HTMLResponse
import uvicorn
from pydantic import BaseModel
from typing import Dict, List
from jokeapi import Jokes
from dotenv import load_dotenv, find_dotenv
from urllib.parse import urlencode

# Load ENV file
load_dotenv(find_dotenv("/home/fastapi/.env"))
SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER')
SQL_PASSWORD = os.getenv('SQL_PASSWORD')
ADMIN_KEY = os.getenv('ADMIN_KEY')

# Define the tags metadata
tags_metadata = [
    {
        "name": "BotOfTheSpecter",
        "description": "Endpoints related to public bot functionalities and operations.",
    },
    {
        "name": "Commands",
        "description": "Endpoints for managing and retrieving command responses.",
    },
    {
        "name": "Websocket",
        "description": "Endpoints for interacting with the internal WebSocket server.",
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
async def verify_api_key(api_key):
    conn = await get_mysql_connection()
    try:
        async with conn.cursor() as cur:
            await cur.execute("SELECT username, api_key FROM users WHERE api_key=%s", (api_key,))
            result = await cur.fetchone()
            if result is None:
                return None  # Return None if the API key is invalid
            return result[0]  # Return the username associated with the API key
    finally:
        conn.close()

# Verify the ADMIN Key Given
async def verify_admin_key(api_key):
    if api_key != ADMIN_KEY:
        raise HTTPException(
            status_code=403,
            detail="Forbidden: Invalid Admin Key",
        )

# Function to connect to the websocket server and push a notice
async def websocket_notice(event, params, api_key):
    valid = await verify_api_key(api_key)  # Validate the API key before proceeding
    if not valid:  # Check if the API key is valid
        raise HTTPException(
            status_code=401,
            detail="Invalid API Key",
        )
    async with aiohttp.ClientSession() as session:
        params['code'] = api_key  # Pass the verified API key to the websocket server
        encoded_params = urlencode(params)
        url = f'https://websocket.botofthespecter.com:8080/notify?{encoded_params}'
        async with session.get(url) as response:
            if response.status != 200:
                raise HTTPException(
                    status_code=response.status,
                    detail=f"Failed to send HTTP event '{event}' to websocket server."
                )

# Define models for the websocket events
class TTSRequest(BaseModel):
    text: str

class WalkonRequest(BaseModel):
    user: str

class DeathsRequest(BaseModel):
    death: str
    game: str

class WeatherRequest(BaseModel):
    location: str

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

# Define the response model for Version Control
class VersionControlResponse(BaseModel):
    beta_version: str
    stable_version: str
    class Config:
        json_schema_extra = {
            "example": {
                "beta_version": "4.6",
                "stable_version": "4.5.2"
            }
        }

# Quotes endpoint
@app.get(
    "/quotes",
    response_model=QuoteResponse,
    summary="Get a random quote",
    tags=["Commands"]
)
async def quotes(api_key):
    valid = await verify_api_key(api_key)  # Validate the API key before proceeding
    if not valid:  # Check if the API key is valid
        raise HTTPException(
            status_code=401,
            detail="Invalid API Key",
        )
    quotes_path = "/home/fastapi/quotes.json"
    if not os.path.exists(quotes_path):
        raise HTTPException(status_code=404, detail="Quotes file not found")
    with open(quotes_path, "r") as quotes_file:
        quotes = json.load(quotes_file)
    # Select a random author
    random_author = random.choice(list(quotes.keys()))
    # Select a random quote from the chosen author
    random_quote = random.choice(quotes[random_author])
    return {"author": random_author, "quote": random_quote}

# Version Control endpoint
@app.get(
    "/versions",
    response_model=VersionControlResponse,
    summary="Get the current bot versions",
    tags=["BotOfTheSpecter"]
)
async def versions():
    versions_path = "/home/fastapi/versions.json"
    if not os.path.exists(versions_path):
        raise HTTPException(status_code=404, detail="Version file not found")
    with open(versions_path, "r") as versions_file:
        versions = json.load(versions_file)
    return versions

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
async def kill_responses(api_key):
    valid = await verify_api_key(api_key)  # Validate the API key before proceeding
    if not valid:  # Check if the API key is valid
        raise HTTPException(
            status_code=401,
            detail="Invalid API Key",
        )
    kill_command_path = "/home/fastapi/killCommand.json"
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
async def joke(api_key):
    valid = await verify_api_key(api_key)  # Validate the API key before proceeding
    if not valid:  # Check if the API key is valid
        raise HTTPException(
            status_code=401,
            detail="Invalid API Key",
        )
    jokes = await Jokes()
    get_joke = await jokes.get_joke(blacklist=['nsfw', 'racist', 'sexist', 'political', 'religious'])
    if "category" not in get_joke:
        raise HTTPException(status_code=500, detail="Error: Unable to retrieve joke from API.")
    # Return the joke response
    return get_joke

# Websocket endpoints
@app.get(
    "/websocket/tts",
    summary="Trigger TTS via API",
    tags=["Websocket"],
)
async def websocket_tts(request: TTSRequest, api_key):
    valid = await verify_api_key(api_key)  # Validate the API key before proceeding
    if not valid:  # Check if the API key is valid
        raise HTTPException(
            status_code=401,
            detail="Invalid API Key",
        )
    params = {"event": "TTS", "text": request.text}
    await websocket_notice("TTS", params, api_key)
    return {"status": "success"}

@app.get(
    "/websocket/walkon",
    summary="Trigger WALKON via API",
    tags=["Websocket"]
)
async def websocket_walkon(request: WalkonRequest, api_key):
    valid = await verify_api_key(api_key)  # Validate the API key before proceeding
    if not valid:  # Check if the API key is valid
        raise HTTPException(
            status_code=401,
            detail="Invalid API Key",
        )
    channel = valid  # Fetch the channel (username) from the database
    walkon_file_path = f"/var/www/walkons/{channel}/{request.user}.mp3"
    if os.path.exists(walkon_file_path):
        params = {"event": "WALKON", "channel": channel, "user": request.user}
        await websocket_notice("WALKON", params, api_key)
        return {"status": "success"}
    else:
        raise HTTPException(
            status_code=404,
            detail=f"Walkon file for user '{request.user}' does not exist."
        )

@app.get(
    "/websocket/deaths",
    summary="Trigger DEATHS via API",
    tags=["Websocket"]
)
async def websocket_deaths(request: DeathsRequest, api_key):
    valid = await verify_api_key(api_key)  # Validate the API key before proceeding
    if not valid:  # Check if the API key is valid
        raise HTTPException(
            status_code=401,
            detail="Invalid API Key",
        )
    params = {"event": "DEATHS", "death-text": request.death, "game": request.game}
    await websocket_notice("DEATHS", params, api_key)
    return {"status": "success"}

@app.get(
    "/websocket/weather",
    summary="Trigger WEATHER via API",
    tags=["Websocket"]
)
async def websocket_weather(request: WeatherRequest, api_key):
    valid = await verify_api_key(api_key)  # Validate the API key before proceeding
    if not valid:  # Check if the API key is valid
        raise HTTPException(
            status_code=401,
            detail="Invalid API Key",
        )
    params = {"event": "WEATHER", "location": request.location}
    await websocket_notice("WEATHER", params, api_key)
    return {"status": "success"}

@app.get(
    "/websocket/stream_online",
    summary="Trigger STREAM_ONLINE via API",
    tags=["Websocket"]
)
async def websocket_stream_online(api_key):
    valid = await verify_api_key(api_key)  # Validate the API key before proceeding
    if not valid:  # Check if the API key is valid
        raise HTTPException(
            status_code=401,
            detail="Invalid API Key",
        )
    params = {"event": "STREAM_ONLINE"}
    await websocket_notice("STREAM_ONLINE", params, api_key)
    return {"status": "success"}

@app.get(
    "/websocket/stream_offline",
    summary="Trigger STREAM_OFFLINE via API",
    tags=["Websocket"]
)
async def websocket_stream_offline(api_key):
    valid = await verify_api_key(api_key)  # Validate the API key before proceeding
    if not valid:  # Check if the API key is valid
        raise HTTPException(
            status_code=401,
            detail="Invalid API Key",
        )
    params = {"event": "STREAM_OFFLINE"}
    await websocket_notice("STREAM_OFFLINE", params, api_key)
    return {"status": "success"}

# authorizedusers EndPoint (hidden from docs)
@app.get(
    "/authorizedusers",
    summary="Get a list of authorized users",
    include_in_schema=False
)
async def authorized_users(api_key: str = Depends(verify_admin_key)):
    auth_users_path = "/home/fastapi/authusers.json"
    if not os.path.exists(auth_users_path):
        raise HTTPException(status_code=404, detail="File not found")
    with open(auth_users_path, "r") as auth_users_file:
        auth_users = json.load(auth_users_file)
    return auth_users

@app.get("/", include_in_schema=False)
def read_root():
    html_content = """
    <html>
        <head>
            <meta http-equiv="refresh" content="0;url=/docs">
        </head>
        <body>
        </body>
    </html>
    """
    return HTMLResponse(content=html_content, status_code=200)

@app.get("/favicon.ico", include_in_schema=False)
def favicon():
    return "https://cdn.botofthespecter.com/logo.ico"

if __name__ == "__main__":
    uvicorn.run(
        app,
        host="0.0.0.0",
        port=443,
        ssl_certfile="/home/fastapi/ssl/fullchain.pem",
        ssl_keyfile="/home/fastapi/ssl/privkey.pem"
    )