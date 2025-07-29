# Standard library imports
import os
import random
import json
import logging
import asyncio
import datetime
import urllib
from datetime import datetime, timedelta

# Third-party imports
import aiohttp
import aiomysql
import uvicorn
import aioping
from fastapi import FastAPI, HTTPException, Request, status, Query, Form
from fastapi.responses import RedirectResponse, JSONResponse
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from typing import Dict, List
from jokeapi import Jokes
from dotenv import load_dotenv, find_dotenv
from urllib.parse import urlencode
from contextlib import asynccontextmanager

# Load ENV file
load_dotenv(find_dotenv("/home/botofthespecter/.env"))
SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER') 
SQL_PASSWORD = os.getenv('SQL_PASSWORD')
SQL_PORT = int(os.getenv('SQL_PORT'))

# Validate required database environment variables
if not all([SQL_HOST, SQL_USER, SQL_PASSWORD]):
    missing_vars = [var for var, val in [('SQL_HOST', SQL_HOST), ('SQL_USER', SQL_USER), ('SQL_PASSWORD', SQL_PASSWORD)] if not val]
    logging.error(f"Missing required database environment variables: {missing_vars}")
    raise ValueError(f"Missing required database environment variables: {missing_vars}")

ADMIN_KEY = os.getenv('ADMIN_KEY')
WEATHER_API = os.getenv('WEATHER_API')

# Setup Logger
log_file = "/home/botofthespecter/log.txt" if os.path.exists("/home/botofthespecter") else "/home/fastapi/log.txt"
logging.basicConfig(
    level=logging.INFO,
    filename=log_file,
    format="%(asctime)s - %(levelname)s - %(message)s"
)

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
        "name": "Webhooks",
        "description": "Endpoints for interacting with external webhook requests.",
    },
    {
        "name": "Websocket",
        "description": "Endpoints for interacting with the internal WebSocket server.",
    },
]

# Function to get API count from database
async def get_api_count(count_type):
    conn = await get_mysql_connection()
    try:
        async with conn.cursor() as cur:
            await cur.execute("SELECT count, reset_day FROM api_counts WHERE type=%s", (count_type,))
            result = await cur.fetchone()
            if result:
                return result[0], result[1]  # Returns count and reset_day
            # If no record exists, initialize with default values
            if count_type == "weather":
                await cur.execute("INSERT INTO api_counts (type, count, reset_day) VALUES (%s, 1000, 0)", (count_type,))
                await conn.commit()
                return 1000, 0
            elif count_type == "shazam":
                await cur.execute("INSERT INTO api_counts (type, count, reset_day) VALUES (%s, 500, 23)", (count_type,))
                await conn.commit()
                return 500, 23
            elif count_type == "exchangerate":
                await cur.execute("INSERT INTO api_counts (type, count, reset_day) VALUES (%s, 1500, 14)", (count_type,))
                await conn.commit()
                return 1500, 14
            return None, None  # Fallback for unknown types
    finally:
        conn.close()

# Function to update API count in database
async def update_api_count(count_type, new_count):
    conn = await get_mysql_connection()
    try:
        async with conn.cursor() as cur:
            # Update the count, even if it's the same value, to trigger the ON UPDATE CURRENT_TIMESTAMP
            await cur.execute("""
                UPDATE api_counts 
                SET count = %s 
                WHERE type = %s AND (count != %s OR count = %s)
            """, (new_count, count_type, new_count, new_count))
            await conn.commit()
            logging.info(f"Successfully updated {count_type} count to {new_count}")
    except Exception as e:
        logging.error(f"Error updating API count for {count_type}: {e}")
        raise
    finally:
        conn.close()

# Midnight function
async def midnight():
    while True:
        # Get the current time
        current_time = datetime.now()
        try:
            # Connect to database to check reset days
            conn = await get_mysql_connection()
            try:
                async with conn.cursor() as cur:
                    # Reset weather requests at midnight (this happens daily)
                    if current_time.hour == 0 and current_time.minute == 0:
                        # Reload the .env file at midnight
                        load_dotenv()
                        # Reset weather requests to 1000
                        await update_api_count("weather", 1000)
                    # Get reset days for other API types
                    await cur.execute("SELECT type, reset_day FROM api_counts WHERE type in ('shazam', 'exchangerate')")
                    reset_days = await cur.fetchall()
                    for api_type, reset_day in reset_days:
                        if current_time.day == reset_day and current_time.hour == 0 and current_time.minute == 0:
                            if api_type == "shazam":
                                await update_api_count("shazam", 500)
                            elif api_type == "exchangerate":
                                await update_api_count("exchangerate", 1500)
            finally:
                conn.close()
        except Exception as e:
            # Handle any errors during the reset
            logging.error(f"Failed to reset API request counts: {e}")
        # Sleep for 60 seconds before checking again
        await asyncio.sleep(60)

# Lifespan event handler
@asynccontextmanager
async def lifespan(app: FastAPI):
    midnight_task = asyncio.create_task(midnight())
    # Yield control back to FastAPI (letting it continue with startup and handling requests)
    yield
    # After shutdown, cancel the midnight task
    midnight_task.cancel()
    try:
        await midnight_task
    except asyncio.CancelledError:
        pass

# Initialize FastAPI app with metadata and tags, including the lifespan handler
app = FastAPI(
    lifespan=lifespan,
    title="BotOfTheSpecter",
    description="API Endpoints for BotOfTheSpecter",
    version="1.0.0",
    terms_of_service="https://botofthespecter.com/terms-of-service.php",
    contact={
        "name": "BotOfTheSpecter",
        "url": "https://botofthespecter.com/",
        "email": "questions@botofthespecter.com",
    },
    openapi_tags=tags_metadata,
    swagger_ui_parameters={"defaultModelsExpandDepth": -1}
)

# Add CORS middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # Allow all origins
    allow_credentials=True,
    allow_methods=["*"],  # Allow all methods, including OPTIONS
    allow_headers=["*"],  # Allow all headers
)

# Make a connection to the MySQL Server
async def get_mysql_connection():
    return await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        port=SQL_PORT,
        db="website"
    )

async def get_mysql_connection_user(username):
    return await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        port=SQL_PORT,
        db=username
    )

# Check if a host is reachable via ICMP ping
async def check_icmp_ping(host: str) -> bool:
    try:
        await aioping.ping(host, timeout=2)
        return True
    except TimeoutError:
        return False

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

# Verify the ADMIN API Key Given
async def verify_admin_key(admin_key: str):
    global ADMIN_KEY
    logging.info(f"ADMIN_KEY loaded: {ADMIN_KEY is not None}")
    if ADMIN_KEY:
        logging.info(f"ADMIN_KEY length: {len(ADMIN_KEY)}")
        logging.info(f"Provided key length: {len(admin_key)}")
        logging.info(f"Keys match: {admin_key == ADMIN_KEY}")
    else:
        logging.error("ADMIN_KEY is None or empty")
    
    if admin_key != ADMIN_KEY:
        return False
    return True

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
        url = f'https://websocket.botofthespecter.com/notify?{encoded_params}'
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
    ext: str = ".mp3"

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

# Define the response model for Fortunes
class FortuneResponse(BaseModel):
    fortune: str
    class Config:
        json_schema_extra = {
            "example": {
                "fortune": "You are very talented in many ways.",
            }
        }

# Define the response model for Version Control
class VersionControlResponse(BaseModel):
    alpha_version: str
    beta_version: str
    stable_version: str
    discord_bot: str
    class Config:
        json_schema_extra = {
            "example": {
                "alpha_version": "5.5",
                "beta_version": "5.4",
                "stable_version": "5.3",
                "discord_bot": "4.3.4"
            }
        }

# Define the response model for Websocket Heartbeat
class HeartbeatControlResponse(BaseModel):
    status: str
    class Config:
        json_schema_extra = {
            "example": {
                "status": "OK",
            }
        }

# Public API response model
class PublicAPIResponse(BaseModel):
    requests_remaining: str
    days_remaining: int
    class Config:
        json_schema_extra = {
            "example": {
                "requests_remaining": "100",
                "days_remaining": "6",
            }
        }

class PublicAPIDailyResponse(BaseModel):
    requests_remaining: str
    time_remaining: str
    class Config:
        json_schema_extra = {
            "example": {
                "requests_remaining": "600",
                "time_remaining": "3 hours, 24 minutes, 16 seconds",
            }
        }

# Define the /fourthwall endpoint for handling webhook data
@app.post(
    "/fourthwall",
    summary="Receive and process FOURTHWALL Webhook Requests",
    description="This endpoint allows you to send webhook data from FOURTHWALL to be processed by the bot's WebSocket server.",
    tags=["Webhooks"],
    status_code=status.HTTP_200_OK,
    operation_id="process_fourthwall_webhook"
)
async def handle_fourthwall_webhook(request: Request, api_key: str = Query(...)):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    try:
        webhook_data = await request.json()
        logging.info(f"{webhook_data}")
    except Exception as e:
        raise HTTPException(status_code=400, detail="Invalid JSON payload")
    async with aiohttp.ClientSession() as session:
        try:
            params = {
                "code": api_key,
                "event": "FOURTHWALL",
                "data": webhook_data
            }
            encoded_params = urlencode(params)
            url = f"https://websocket.botofthespecter.com/notify?{encoded_params}"
            async with session.get(url, timeout=10) as response:
                if response.status != 200:
                    raise HTTPException(
                        status_code=response.status,
                        detail=f"Failed to send HTTP event 'FOURTHWALL' to websocket server."
                    )
        except Exception as e:
            logging.error(f"{e}")
    return {"status": "success", "message": "Webhook received"}

# Kofi Webhook Endpoint
@app.post(
    "/kofi",
    summary="Receive and process KOFI Webhook Requests",
    description="This endpoint allows you to receive KOFI webhook events and forward them to the WebSocket server.",
    tags=["Webhooks"],
    status_code=status.HTTP_200_OK,
    operation_id="process_kofi_webhook"
)
async def handle_kofi_webhook(api_key: str = Query(...), data: str = Form(...)):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    try:
        # Parse the 'data' field (which is a JSON string)
        kofi_data = json.loads(data)
        # Forward the data to the WebSocket server
        async with aiohttp.ClientSession() as session:
            params = {
                "code": api_key,
                "event": "KOFI",
                "data": kofi_data
            }
            encoded_params = urlencode(params)
            url = f"https://websocket.botofthespecter.com/notify?{encoded_params}"
            async with session.get(url, timeout=10) as response:
                if response.status != 200:
                    raise HTTPException(
                        status_code=response.status,
                        detail=f"Failed to send KOFI event to WebSocket server."
                    )
        return {"status": "success", "message": "Kofi event forwarded to WebSocket server"}
    except Exception as e:
        logging.error(f"Error forwarding Kofi webhook: {e}")
        raise HTTPException(status_code=500, detail=f"Error forwarding Kofi webhook: {str(e)}")

# Define the /patreon endpoint for handling webhook data
@app.post(
    "/patreon",
    summary="Receive and process Patreon Webhook Requests",
    description="This endpoint allows you to send webhook data from Patreon to be processed by the bot's WebSocket server.",
    tags=["Webhooks"],
    status_code=status.HTTP_200_OK,
    operation_id="process_patreon_webhook"
)
async def handle_patreon_webhook(request: Request, api_key: str = Query(...)):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    try:
        webhook_data = await request.json()
        logging.info(f"Received Patreon Webhook data: {webhook_data}") # Log the received webhook data
    except Exception as e:
        logging.error(f"Error parsing Patreon webhook JSON payload: {e}")
        raise HTTPException(status_code=400, detail="Invalid JSON payload")
    async with aiohttp.ClientSession() as session:
        try:
            params = {
                "code": api_key,
                "event": "PATREON",
                "data": webhook_data
            }
            encoded_params = urlencode(params)
            url = f"https://websocket.botofthespecter.com/notify?{encoded_params}"
            async with session.get(url, timeout=10) as response:
                if response.status != 200:
                    error_detail = f"Failed to send HTTP event 'PATREON' to websocket server. Status code: {response.status}" # More specific error message
                    logging.error(error_detail)
                    raise HTTPException(
                        status_code=response.status,
                        detail=error_detail
                    )
        except Exception as e:
            logging.error(f"Error sending Patreon event to websocket server: {e}")
            return JSONResponse(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, content={"status": "error", "message": "Error forwarding to websocket server"}) # Return 500 on websocket send failure
    return {"status": "success", "message": "Patreon Webhook received and processed"}

# Quotes endpoint
@app.get(
    "/quotes",
    response_model=QuoteResponse,
    summary="Get a random quote",
    description="Retrieve a random quote from the database of quotes, based on a random author.",
    tags=["Commands"],
    operation_id="get_random_quote"
)
async def quotes(api_key: str = Query(...)):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    # Check for quotes file in preferred location first
    quotes_path = "/home/botofthespecter/quotes.json"
    if not os.path.exists(quotes_path):
        quotes_path = "/home/fastapi/quotes.json"
    if not os.path.exists(quotes_path):
        raise HTTPException(status_code=404, detail="Quotes file not found")
    with open(quotes_path, "r") as quotes_file:
        quotes = json.load(quotes_file)
    random_author = random.choice(list(quotes.keys()))
    random_quote = random.choice(quotes[random_author])
    return {"author": random_author, "quote": random_quote}

# Fortune endpoint
@app.get(
    "/fortune",
    response_model=FortuneResponse,
    summary="Get a random fortune",
    description="Retrieve a random fortune from the database of fortunes.",
    tags=["Commands"],
    operation_id="get_random_fortune"
)
async def fortune(api_key: str = Query(...)):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    # Check for fortunes file in preferred location first
    fortunes_path = "/home/botofthespecter/fortunes.json"
    if not os.path.exists(fortunes_path):
        fortunes_path = "/home/fastapi/fortunes.json"
    if not os.path.exists(fortunes_path):
        raise HTTPException(status_code=404, detail="Fortunes file not found")
    # Load fortunes from JSON file
    with open(fortunes_path, "r") as fortunes_file:
        data = json.load(fortunes_file)
    if "fortunes" not in data or not data["fortunes"]:
        raise HTTPException(status_code=404, detail="No fortunes available")
    # Randomly select a fortune
    random_fortune = random.choice(data["fortunes"])
    return {"fortune": random_fortune}

# Version Control endpoint
@app.get(
    "/versions",
    response_model=VersionControlResponse,
    summary="Get the current bot versions",
    description="Fetch the alpha, beta, and stable version numbers of the bot.",
    tags=["BotOfTheSpecter"],
    operation_id="get_bot_versions"
)
async def versions():
    # Check for versions file in preferred location first
    versions_path = "/home/botofthespecter/versions.json"
    if not os.path.exists(versions_path):
        versions_path = "/home/fastapi/versions.json"
    if not os.path.exists(versions_path):
        raise HTTPException(status_code=404, detail="Version file not found")
    with open(versions_path, "r") as versions_file:
        versions = json.load(versions_file)
    return versions

# Websocket Heartbeat endpoint
@app.get(
    "/heartbeat/websocket",
    response_model=HeartbeatControlResponse,
    summary="Get the heartbeat status of the websocket server",
    description="Retrieve the current heartbeat status of the WebSocket server.",
    tags=["Heartbeats"],
    operation_id="get_websocket_heartbeat"
)
async def websocket_heartbeat():
    url = "https://websocket.botofthespecter.com/heartbeat"
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(url) as response:
                if response.status == 200:
                    heartbeat_data = await response.json()
                    return heartbeat_data
                else:
                    return {"status": "OFF"}
    except Exception:
        return {"status": "OFF"}

# Heartbeat endpoint
@app.get(
    "/heartbeat/api",
    response_model=HeartbeatControlResponse,
    summary="Get the heartbeat status of the API server",
    description="Retrieve the current heartbeat status of the API server.",
    tags=["Heartbeats"],
    operation_id="get_api_heartbeat"
)
async def api_heartbeat():
    api_host = "localhost"
    is_alive = await check_icmp_ping(api_host)
    return {"status": "OK" if is_alive else "OFF"}

@app.get(
    "/heartbeat/database",
    response_model=HeartbeatControlResponse,
    summary="Get the heartbeat status of the database server",
    description="Retrieve the current heartbeat status of the database server.",
    tags=["Heartbeats"],
    operation_id="get_database_heartbeat"
)
async def database_heartbeat():
    db_host = "10.240.0.40"
    is_alive = await check_icmp_ping(db_host)
    return {"status": "OK" if is_alive else "OFF"}

# Public API Requests Remaining (for song)
@app.get(
    "/api/song",
    response_model=PublicAPIResponse,
    summary="Get the remaining song requests",
    description="Get the number of remaining song requests for the current reset period.",
    tags=["BotOfTheSpecter"],
    operation_id="get_song_requests_remaining"
)
async def api_song():
    try:
        # Get count from database
        count, reset_day = await get_api_count("shazam")
        # Calculate days until reset
        reset_day = int(reset_day)
        today = datetime.now()
        if today.day >= reset_day:
            next_month = today.month + 1 if today.month < 12 else 1
            next_year = today.year + 1 if today.month == 12 else today.year
            next_reset = datetime(next_year, next_month, reset_day)
        else:
            next_reset = datetime(today.year, today.month, reset_day)
        days_until_reset = (next_reset - today).days
        return {"requests_remaining": str(count), "days_remaining": days_until_reset}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Error retrieving song API count: {str(e)}")

# Public API Requests Remaining (for exchange rate)
@app.get(
    "/api/exchangerate",
    response_model=PublicAPIResponse,
    summary="Get the remaining exchangerate requests",
    description="Retrieve the number of remaining exchange rate requests for the current reset period.",
    tags=["BotOfTheSpecter"],
    operation_id="get_exchangerate_requests_remaining"
)
async def api_exchangerate():
    try:
        # Get count from database
        count, reset_day = await get_api_count("exchangerate")
        # Calculate days until reset
        reset_day = int(reset_day)
        today = datetime.now()
        if today.day >= reset_day:
            next_month = today.month + 1 if today.month < 12 else 1
            next_year = today.year + 1 if today.month == 12 else today.year
            next_reset = datetime(next_year, next_month, reset_day)
        else:
            next_reset = datetime(today.year, today.month, reset_day)
        days_until_reset = (next_reset - today).days
        return {"requests_remaining": str(count), "days_remaining": days_until_reset}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Error retrieving exchangerate API count: {str(e)}")

# Public API Requests Remaining (for weather)
@app.get(
    "/api/weather",
    response_model=PublicAPIDailyResponse,
    summary="Get the remaining weather API requests",
    description="Retrieve the number of remaining weather API requests for the current day, as well as the time remaining until midnight.",
    tags=["BotOfTheSpecter"],
    operation_id="get_weather_requests_remaining"
)
async def api_weather_requests_remaining():
    try:
        # Calculate time remaining until midnight
        now = datetime.now()
        midnight = datetime(now.year, now.month, now.day) + timedelta(days=1)
        time_until_midnight = (midnight - now).seconds
        hours, remainder = divmod(time_until_midnight, 3600)
        minutes, seconds = divmod(remainder, 60)
        if hours > 0: time_remaining = f"{hours} hours, {minutes} minutes, {seconds} seconds"
        elif minutes > 0: time_remaining = f"{minutes} minutes, {seconds} seconds"
        else: time_remaining = f"{seconds} seconds"
        # Get count from database
        count, _ = await get_api_count("weather")
        return {"requests_remaining": str(count), "time_remaining": time_remaining}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Error retrieving weather API count: {str(e)}")

# killCommand EndPoint
@app.get(
    "/kill",
    response_model=KillCommandResponse,
    summary="Retrieve the Kill Command Responses",
    description="Fetch kill command responses for various events.",
    tags=["Commands"],
    operation_id="get_kill_commands"
)
async def kill_responses(api_key: str = Query(...)):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    # Check for kill command file in preferred location first
    kill_command_path = "/home/botofthespecter/killCommand.json"
    if not os.path.exists(kill_command_path):
        kill_command_path = "/home/fastapi/killCommand.json"
    if not os.path.exists(kill_command_path):
        raise HTTPException(status_code=404, detail="File not found")
    with open(kill_command_path, "r") as kill_command_file:
        kill_commands = json.load(kill_command_file)
    return {"killcommand": kill_commands}

# Joke Endpoint
@app.get(
    "/joke",
    response_model=JokeResponse,
    summary="Get a random joke",
    description="Fetch a random joke from a joke API, filtered to exclude inappropriate content.",
    tags=["Commands"],
    operation_id="get_random_joke"
)
async def joke(api_key: str = Query(...)):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    jokes = await Jokes()
    get_joke = await jokes.get_joke(blacklist=['nsfw', 'racist', 'sexist', 'political', 'religious'])
    if "category" not in get_joke:
        raise HTTPException(status_code=500, detail="Error: Unable to retrieve joke from API.")
    return get_joke

# Weather Data Endpoint
@app.get(
    "/weather",
    summary="Get weather data and trigger WebSocket weather event",
    description="Retrieve current weather data for a given location and send it to the WebSocket server.",
    tags=["Commands"],
    operation_id="get_weather_data_and_trigger_event"
)
async def fetch_weather_via_api(api_key: str = Query(...), location: str = Query(...)):
    # Validate the API key before proceeding
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    # Fetch weather data
    try:
        location_data, lat, lon = await get_weather_lat_lon(location)
        if lat is None or lon is None:
            raise HTTPException(status_code=404, detail=f"Location '{location}' not found.")
        weather_data_metric = await fetch_weather_data(lat, lon, units='metric')
        weather_data_imperial = await fetch_weather_data(lat, lon, units='imperial')
        if not weather_data_metric or not weather_data_imperial:
            raise HTTPException(status_code=500, detail="Error fetching weather data.")
        # Build full location string including state and country if available
        full_location = location_data.get("name", "")
        if location_data.get("state"):
            full_location += f", {location_data.get('state')}"
        if location_data.get("country"):
            full_location += f", {location_data.get('country')}"
        # Format weather data & include full location data
        formatted_weather_data = format_weather_data(weather_data_metric, weather_data_imperial, full_location)
        # Get current weather API count from database and decrement by 2
        count, _ = await get_api_count("weather")
        new_count = count - 3  # Decrease by 3 for both metric and imperial requests and location data
        await update_api_count("weather", new_count)
        # Trigger WebSocket weather event
        params = {"event": "WEATHER_DATA", "weather_data": formatted_weather_data}
        await websocket_notice("WEATHER_DATA", params, api_key)
        return {
            "status": "success",
            "weather_data": formatted_weather_data,
            "remaining_requests": new_count,
            "location_data": location_data
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Error: {str(e)}")

# Functions to fetch weather data
async def get_weather_lat_lon(location):
    location = location.replace(" ", "%20")
    async with aiohttp.ClientSession() as session:
        async with session.get(f"https://api.openweathermap.org/geo/1.0/direct?q={location}&limit=1&appid={WEATHER_API}") as response:
            data = await response.json()
            if len(data) > 0:
                return data[0], data[0]['lat'], data[0]['lon']
            return None, None, None

async def fetch_weather_data(lat, lon, units='metric'):
    async with aiohttp.ClientSession() as session:
        async with session.get(f"https://api.openweathermap.org/data/3.0/onecall?lat={lat}&lon={lon}&exclude=minutely,hourly,daily,alerts&units={units}&appid={WEATHER_API}") as response:
            data = await response.json()
            # Log the response for debugging
            logging.info(f"Weather API response: {data}")
            if 'current' not in data:
                raise ValueError("Invalid weather data received: 'current' section missing")
            return data['current']

def format_weather_data(current_metric, current_imperial, location):
    try:
        status = current_metric['weather'][0]['description']
    except (KeyError, IndexError):
        status = 'Unknown status'
    temperature_c = current_metric.get('temp', 'Unknown')
    temperature_f = current_imperial.get('temp', 'Unknown')
    wind_speed_kph = current_metric.get('wind_speed', 'Unknown')
    wind_speed_mph = current_imperial.get('wind_speed', 'Unknown')
    humidity = current_metric.get('humidity', 'Unknown')
    wind_direction = get_wind_direction(current_metric.get('wind_deg', 0))
    icon = current_metric.get('weather', [{}])[0].get('icon', '01d')
    icon_url = f"https://openweathermap.org/img/wn/{icon}@2x.png"
    return {
        "status": status,
        "temperature": f"{temperature_c}°C | {temperature_f}°F",
        "wind": f"{wind_speed_kph} kph | {wind_speed_mph} mph {wind_direction}",
        "humidity": f"Humidity: {humidity}%",
        "icon": icon_url,
        "location": location
    }

def get_wind_direction(deg):
    directions = { 'N': (337.5, 22.5), 'NE': (22.5, 67.5), 'E': (67.5, 112.5), 'SE': (112.5, 157.5),
                   'S': (157.5, 202.5), 'SW': (202.5, 247.5), 'W': (247.5, 292.5), 'NW': (292.5, 337.5) }
    for direction, (start, end) in directions.items():
        # Handle the wrap-around for North
        if direction == 'N':
            if deg >= start or deg < end:
                return direction
        else:
            if start <= deg < end:
                return direction
    return "N/A"

# WebSocket TTS Trigger
@app.get(
    "/websocket/tts",
    summary="Trigger TTS via API",
    description="Send a text-to-speech (TTS) event to the WebSocket server, allowing TTS to be triggered via API.",
    tags=["Websocket"],
    response_model=dict,
    operation_id="trigger_websocket_tts"
)
async def websocket_tts(api_key: str = Query(...), text: str = Query(...)):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    params = {"event": "TTS", "text": text}
    await websocket_notice("TTS", params, api_key)
    return {"status": "success"}

# WebSocket Walkon Trigger
@app.get(
    "/websocket/walkon",
    summary="Trigger Walkon via API",
    description="Trigger the 'Walkon' event for a specified user via the WebSocket server. Supports .mp3 (audio) and .mp4 (video) walkons.",
    tags=["Websocket"],
    operation_id="trigger_websocket_walkon"
)
async def websocket_walkon(api_key: str = Query(...), user: str = Query(...), ext: str = Query(".mp3", description="File extension (.mp3 or .mp4)")):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    channel = valid
    # Validate extension
    if ext not in [".mp3", ".mp4"]:
        raise HTTPException(status_code=400, detail="Invalid extension. Only .mp3 and .mp4 are supported")
    # Trigger the WebSocket event
    params = {"event": "WALKON", "channel": channel, "user": user, "ext": ext}
    await websocket_notice("WALKON", params, api_key)
    return {"status": "success"}

# WebSocket Deaths Trigger
@app.get(
    "/websocket/deaths",
    summary="Trigger Deaths via API",
    description="Trigger the 'Deaths' event with custom death text for a game via the WebSocket server.",
    tags=["Websocket"],
    operation_id="trigger_websocket_deaths"
)
async def websocket_deaths(request: DeathsRequest, api_key: str = Query(...)):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    params = {"event": "DEATHS", "death-text": request.death, "game": request.game}
    await websocket_notice("DEATHS", params, api_key)
    return {"status": "success"}

# WebSocket Sound Alert Trigger
@app.get(
    "/websocket/sound_alert",
    summary="Trigger Sound Alert via API",
    description="Trigger a sound alert for the specified sound file via the WebSocket server. The sound file should be located in the channel's sound alerts directory.",
    tags=["Websocket"],
    operation_id="trigger_websocket_sound_alert"
)
async def websocket_sound_alert(api_key: str = Query(...), sound: str = Query(..., description="Sound file name (defaults to .mp3 extension if no extension provided)")):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    channel = valid
    # Add .mp3 extension if no extension is provided
    if not sound.endswith(('.mp3', '.wav', '.ogg', '.m4a')):
        sound += '.mp3'
    # Build the sound URL
    sound_url = f"https://soundalerts.botofthespecter.com/{channel}/{sound}"
    # Trigger the WebSocket event
    params = {"event": "SOUND_ALERT", "sound": sound_url}
    await websocket_notice("SOUND_ALERT", params, api_key)
    return {"status": "success"}

# WebSocket Stream Online Trigger
@app.get(
    "/websocket/stream_online",
    summary="Trigger Stream Online via API",
    description="Send a 'Stream Online' event to the WebSocket server to notify that the stream is live.",
    tags=["Websocket"],
    operation_id="trigger_websocket_stream_online"
)
async def websocket_stream_online(api_key: str = Query(...)):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    params = {"event": "STREAM_ONLINE"}
    await websocket_notice("STREAM_ONLINE", params, api_key)
    return {"status": "success"}

# WebSocket Stream Offline Trigger
@app.get(
    "/websocket/stream_offline",
    summary="Trigger Stream Offline via API",
    description="Send a 'Stream Offline' event to the WebSocket server to notify that the stream is offline.",
    tags=["Websocket"],
    operation_id="trigger_websocket_stream_offline"
)
async def websocket_stream_offline(api_key: str = Query(...)):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    params = {"event": "STREAM_OFFLINE"}
    await websocket_notice("STREAM_OFFLINE", params, api_key)
    return {"status": "success"}

# Endpoint for receiving the event and forwarding it to the websocket server
@app.post(
    "/SEND_OBS_EVENT",
    summary="Pass OBS events to the websocket server",
    description="Send a 'OBS EVENT' to the WebSocket server to notify the system of a change in the OBS Connector.",
    tags=["Websocket"],
    operation_id="trigger_websocket_obs_event"
)
async def send_event_to_specter(api_key: str = Query(...), data: str = Form(...)):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    try:
        event_data = json.loads(data)
        params = {
            'code': api_key,
            'event': 'SEND_OBS_EVENT',
            'data': event_data
        }
        async with aiohttp.ClientSession() as session:
            encoded_params = urllib.parse.urlencode(params, quote_via=urllib.parse.quote_plus)
            url = f'https://websocket.botofthespecter.com/notify?{encoded_params}'
            async with session.get(url) as response:
                if response.status == 200:
                    return {"message": "Event sent successfully", "status": response.status}
                else:
                    raise HTTPException(status_code=response.status, detail="Failed to send event to websocket server")
    except json.JSONDecodeError:
        raise HTTPException(status_code=400, detail="Invalid JSON format in 'data'")
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Error occurred: {str(e)}")

# Hidden Endpoints
# Function to verify the location of the user for weather
@app.get(
    "/weather/location",
    summary="Get website-specific weather location",
    description="Retrieve the correct location information for a given query from the website.",
    operation_id="get_web_weather_location",
    include_in_schema=False
)
async def web_weather(api_key: str = Query(...), location: str = Query(...)):
    # Validate the API key
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    try:
        location_data, lat, lon = await get_weather_lat_lon(location)
        if lat is None or lon is None:
            raise HTTPException(status_code=404, detail=f"Location '{location}' not found.")
        count, _ = await get_api_count("weather")
        new_count = count - 1  # Decrease by 1 for location data
        await update_api_count("weather", new_count)
        # Format output in a human-readable format
        name = location_data.get("name", "Unknown")
        country = location_data.get("country", "Unknown")
        state = location_data.get("state", "")
        formatted = f"Location: {name}"
        if state:
            formatted += f", {state}"
        formatted += f", {country} (Latitude: {lat}, Longitude: {lon})"
        return {"message": formatted}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Error: {str(e)}")

# Games endpoint
@app.get(
    "/games",
    summary="Search for a game",
    include_in_schema=False
)
async def get_game(
    api_key: str = Query(..., description="API key to authenticate the request"),
    game_name: str = Query(..., description="Name of the game to search for"),
    twitch_auth_token: str = Query(..., description="Twitch OAuth token for IGDB authorization")
):
    # Validate API key
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    # IGDB API details
    igdb_url = "https://api.igdb.com/v4/games"
    headers = {
        "Client-ID": "mrjucsmsnri89ifucl66jj1n35jkj8",
        "Authorization": f"Bearer {twitch_auth_token}",
        "Content-Type": "application/json"
    }
    body = f'fields name; search "{game_name}"; limit 1;'
    # Make the request to IGDB
    async with aiohttp.ClientSession() as session:
        async with session.post(igdb_url, headers=headers, data=body) as response:
            if response.status != 200:
                raise HTTPException(
                    status_code=response.status,
                    detail=f"Error from IGDB: {await response.text()}"
                )
            game_data = await response.json()
            # Handle empty response
            if not game_data:
                raise HTTPException(status_code=404, detail="Game not found")
            # Return the first game
            game = game_data[0]
            return {"id": game["id"], "name": game["name"]}

# Get a list of authorized users
@app.get(
    "/authorizedusers",
    summary="Get a list of authorized users",
    include_in_schema=False
)
async def authorized_users(api_key: str = Query(...)):
    valid = await verify_admin_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    try:
        conn = await get_mysql_connection()
        try:
            async with conn.cursor() as cur:
                await cur.execute("SELECT twitch_display_name FROM users WHERE beta_access = 1")
                users = await cur.fetchall()
                user_list = [row[0] for row in users]
        finally:
            conn.close()
        return {"authorized_users": user_list}
    except Exception as e:
        logging.error(f"Error fetching authorized users from database: {e}")
        raise HTTPException(status_code=500, detail="Error fetching authorized users from database")

# Check API Key Given
@app.get(
    "/checkkey",
    summary="Check if the API key is valid",
    include_in_schema=False
)
async def check_key(api_key: str = Query(...)):
    valid = await verify_api_key(api_key)
    if not valid:
        admin_valid = await verify_admin_key(api_key)
        if not admin_valid:
            return {"status": "Invalid API Key"}
        return {"status": "Valid API Key", "username": "ADMIN"}
    return {"status": "Valid API Key", "username": valid}

# Check if stream is online
@app.get(
    "/streamonline",
    summary="Check if the stream is online",
    include_in_schema=False
)
async def stream_online(api_key: str = Query(...)):
    # Validate the API key and get username (channel name)
    username = await verify_api_key(api_key)
    if not username:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    # Check stream status from database
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor() as cur:
                # Query the stream_status table for the user's status
                await cur.execute("SELECT status FROM stream_status")
                result = await cur.fetchone()
                if result:
                    # Convert status string to boolean
                    is_online = result[0].lower() == "true"
                else:
                    # If no record found, assume stream is offline
                    is_online = False
        finally:
            conn.close()
        # Return just the online status
        return {"online": is_online}
    except Exception as e:
        logging.error(f"Error checking stream online status from database: {e}")
        raise HTTPException(status_code=500, detail=f"Error checking stream online status: {str(e)}")

# Check if Discord user is linked
@app.get(
    "/discord/linked",
    summary="Check if Discord user is linked",
    include_in_schema=False
)
async def discord_linked(api_key: str = Query(...), user_id: str = Query(...)):
    # Validate the admin API key
    valid = await verify_admin_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    try:
        conn = await get_mysql_connection()
        try:
            async with conn.cursor() as cur:
                # Check if the Discord user ID exists in the discord_users table
                await cur.execute("SELECT discord_id FROM discord_users WHERE discord_id = %s", (user_id,))
                result = await cur.fetchone()
                # Return the user_id and whether they are linked
                linked = result is not None
                return {"user_id": user_id, "linked": linked}
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Error checking Discord user link status: {e}")
        raise HTTPException(status_code=500, detail=f"Error checking Discord user link status: {str(e)}")

# Any root request go to the docs page
@app.get("/", include_in_schema=False)
async def read_root():
    return RedirectResponse(url="/docs")

# Any favicon ico request get's passed onto CDN for the ico
@app.get("/favicon.ico", include_in_schema=False)
async def favicon():
    return "https://cdn.botofthespecter.com/favicon.ico"

if __name__ == "__main__":
    # Use Let's Encrypt certificates only
    ssl_cert_path = "/etc/letsencrypt/live/api.botofthespecter.com/fullchain.pem"
    ssl_key_path = "/etc/letsencrypt/live/api.botofthespecter.com/privkey.pem"
    logging.info(f"Using SSL certificates: {ssl_cert_path}")
    uvicorn.run(
        app,
        host="0.0.0.0",
        port=443,
        ssl_certfile=ssl_cert_path,
        ssl_keyfile=ssl_key_path
    )