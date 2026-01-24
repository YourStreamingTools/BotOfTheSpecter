# Standard library imports
import os
import random
import json
import logging
from logging.handlers import RotatingFileHandler
import asyncio
import datetime
import urllib
from datetime import datetime, timedelta, timezone
import traceback

# Third-party imports
import aiohttp
import aiomysql
import uvicorn
import aioping
from paramiko import SSHClient, AutoAddPolicy
import time
from fastapi import FastAPI, HTTPException, Request, status, Query, Form
from fastapi.responses import RedirectResponse, JSONResponse
from fastapi.middleware.cors import CORSMiddleware
from fastapi.exceptions import RequestValidationError
from pydantic import BaseModel, Field
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
# SSH credentials for bot status checking
BOTS_SSH_HOST = os.getenv('BOTS_SSH_HOST')
BOTS_SSH_USERNAME = os.getenv('BOTS_SSH_USERNAME')
BOTS_SSH_PASSWORD = os.getenv('BOTS_SSH_PASSWORD')

# Validate required database environment variables
if not all([SQL_HOST, SQL_USER, SQL_PASSWORD]):
    missing_vars = [var for var, val in [('SQL_HOST', SQL_HOST), ('SQL_USER', SQL_USER), ('SQL_PASSWORD', SQL_PASSWORD)] if not val]
    logging.error(f"Missing required database environment variables: {missing_vars}")
    raise ValueError(f"Missing required database environment variables: {missing_vars}")

ADMIN_KEY = os.getenv('ADMIN_KEY')
WEATHER_API = os.getenv('WEATHER_API')

# Setup Logger with rotation
log_file = "/home/botofthespecter/log.txt" if os.path.exists("/home/botofthespecter") else "/home/fastapi/log.txt"
logger = logging.getLogger()
logger.setLevel(logging.INFO)

# Create rotating file handler (100MB per file, keep 10 backup files)
rotating_handler = RotatingFileHandler(
    log_file,
    maxBytes=100 * 1024 * 1024,  # 100MB
    backupCount=10,
    encoding='utf-8'
)
rotating_handler.setLevel(logging.INFO)

# Create formatter
formatter = logging.Formatter("%(asctime)s - %(levelname)s - %(message)s")
rotating_handler.setFormatter(formatter)

# Add handler to logger
logger.addHandler(rotating_handler)

# Define the tags metadata
tags_metadata = [
    {
        "name": "Public",
        "description": "Public endpoints that don't require authentication. Free to use for anyone.",
    },
    {
        "name": "Commands",
        "description": "Endpoints for retrieving command responses and data. Requires user API key. Admins can query any user's data with `channel` parameter.",
    },
    {
        "name": "User Account",
        "description": "Endpoints for managing user account data and bot status. Requires user API key. Admins can query any user's data with `channel` parameter.",
    },
    {
        "name": "Webhooks",
        "description": "Endpoints for receiving webhook events from external services. Requires API key for authentication.",
    },
    {
        "name": "WebSocket Triggers",
        "description": "Endpoints that trigger real-time events via WebSocket to the bot and overlays. Requires user API key.",
    },
    {
        "name": "Admin Only",
        "description": "Administrative endpoints that require admin API key. Service-specific admin keys are restricted to their designated service.",
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
            local_now = datetime.now()  # local system time
            local_now_str = local_now.strftime('%Y-%m-%d %H:%M:%S')
            await cur.execute("""
                UPDATE api_counts
                SET count = %s,
                    updated = %s
                WHERE type = %s
            """, (new_count, local_now_str, count_type))
            await conn.commit()
            logging.info(f"Successfully updated {count_type} count to {new_count}")
    except Exception as e:
        logging.error(f"Error updating API count for {count_type}: {e}")
        raise
    finally:
        conn.close()

# Midnight function
async def midnight():
    last_local_reload = None  # date of last local midnight reload
    last_utc_reset = None     # date of last UTC reset
    try:
        while True:
            now_utc = datetime.now(timezone.utc)
            now_local = datetime.now()
            next_utc_midnight = datetime(now_utc.year, now_utc.month, now_utc.day, tzinfo=timezone.utc) + timedelta(days=1)
            next_local_midnight = datetime(now_local.year, now_local.month, now_local.day) + timedelta(days=1)
            seconds_until_utc = (next_utc_midnight - now_utc).total_seconds()
            seconds_until_local = (next_local_midnight - now_local).total_seconds()
            # Sleep until the next event (either local or UTC midnight)
            sleep_seconds = max(0, min(seconds_until_utc, seconds_until_local))
            await asyncio.sleep(sleep_seconds)
            # Recompute times after waking
            now_utc = datetime.now(timezone.utc)
            now_local = datetime.now()
            # Reload .env at local midnight (once per local date)
            try:
                if last_local_reload != now_local.date() and now_local.hour == 0:
                    try:
                        load_dotenv(find_dotenv())
                        logging.info("Reloaded .env at local midnight")
                    except Exception as e:
                        logging.error(f"Failed to reload .env at local midnight: {e}")
                    last_local_reload = now_local.date()
            except Exception as e:
                logging.error(f"Error during local midnight .env reload check: {e}")
            # Perform UTC resets at UTC midnight (once per UTC date)
            try:
                if last_utc_reset != now_utc.date() and now_utc.hour == 0:
                    conn = await get_mysql_connection()
                    try:
                        async with conn.cursor() as cur:
                            # Reset weather requests every UTC midnight
                            try:
                                await update_api_count("weather", 1000)
                            except Exception as e:
                                logging.error(f"Failed to reset weather count: {e}")
                            # Reset API counts for types that reset on a specific day (day-of-month in UTC)
                            await cur.execute("SELECT type, reset_day FROM api_counts WHERE type in ('shazam', 'exchangerate')")
                            reset_days = await cur.fetchall()
                            for api_type, reset_day in reset_days:
                                try:
                                    rd = int(reset_day)
                                except Exception:
                                    # Skip invalid reset_day values
                                    continue
                                # If the reset_day equals today's UTC day-of-month, reset that API count
                                if rd == now_utc.day:
                                    if api_type == "shazam":
                                        try:
                                            await update_api_count("shazam", 500)
                                        except Exception as e:
                                            logging.error(f"Failed to reset shazam count: {e}")
                                    elif api_type == "exchangerate":
                                        try:
                                            await update_api_count("exchangerate", 1500)
                                        except Exception as e:
                                            logging.error(f"Failed to reset exchangerate count: {e}")
                    finally:
                        conn.close()
                    last_utc_reset = now_utc.date()
            except asyncio.CancelledError:
                # Allow cancellation to propagate so shutdown is clean
                raise
            except Exception as e:
                logging.error(f"Failed to run UTC midnight reset tasks: {e}")
                # Back off a bit before trying again to avoid busy-loop on persistent errors
                await asyncio.sleep(60)
    except asyncio.CancelledError:
        logging.info("midnight task cancelled")
        return

# Lifespan event handler
@asynccontextmanager
async def lifespan(app: FastAPI):
    logging.info("=" * 80)
    logging.info("API SERVER STARTING UP")
    logging.info("=" * 80)
    midnight_task = asyncio.create_task(midnight())
    # Yield control back to FastAPI (letting it continue with startup and handling requests)
    yield
    # After shutdown, cancel the midnight task
    logging.info("API SERVER SHUTTING DOWN")
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

@app.exception_handler(RequestValidationError)
async def validation_exception_handler(request: Request, exc: RequestValidationError):
    logging.error("=" * 80)
    logging.error("PYDANTIC VALIDATION ERROR")
    logging.error(f"URL: {request.url}")
    logging.error(f"Method: {request.method}")
    logging.error(f"Errors: {exc.errors()}")
    logging.error(f"Body: {exc.body}")
    logging.error("=" * 80)
    return JSONResponse(
        status_code=422,
        content={"detail": exc.errors(), "body": exc.body},
    )

@app.exception_handler(Exception)
async def general_exception_handler(request: Request, exc: Exception):
    logging.error("=" * 80)
    logging.error("UNHANDLED EXCEPTION")
    logging.error(f"URL: {request.url}")
    logging.error(f"Method: {request.method}")
    logging.error(f"Exception: {exc}")
    logging.error(f"Traceback: {traceback.format_exc()}")
    logging.error("=" * 80)
    return JSONResponse(
        status_code=500,
        content={"detail": "Internal server error", "error": str(exc)},
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
async def verify_admin_key(admin_key: str, service: str = None):
    try:
        async with aiomysql.create_pool(
            host=SQL_HOST,
            user=SQL_USER,
            password=SQL_PASSWORD,
            db="website",
            port=SQL_PORT,
            autocommit=True
        ) as pool:
            async with pool.acquire() as conn:
                async with conn.cursor(aiomysql.DictCursor) as cur:
                    # Get the service for this admin key
                    await cur.execute(
                        "SELECT service FROM admin_api_keys WHERE api_key = %s",
                        (admin_key,)
                    )
                    result = await cur.fetchone()
                    if not result:
                        return False
                    key_service = result['service']
                    is_super_admin = (key_service == 'admin')
                    # Super admin can access everything
                    if is_super_admin:
                        return True
                    # Service-specific admin can only access their service
                    if service and key_service.lower() == service.lower():
                        return True
                    # Service-specific key used for wrong service or no service specified
                    if service and key_service.lower() != service.lower():
                        logging.warning(f"Admin key for service '{key_service}' attempted to access '{service}'")
                        return False
                    # No service specified but key is service-specific
                    return False
    except Exception as e:
        logging.error(f"Error verifying admin key: {e}")
        return False

async def verify_key(api_key: str, service: str = None) -> dict | None:
    admin_result = await verify_admin_key(api_key, service)
    if admin_result:
        return {"type": "admin", "service": service}
    username = await verify_api_key(api_key)
    if username:
        return {"type": "user", "username": username}
    return None

def resolve_username(key_info: dict, username_param: str = None) -> str:
    if key_info["type"] == "admin":
        if not username_param:
            raise HTTPException(
                status_code=400,
                detail="Username parameter required when using admin key"
            )
        return username_param
    return key_info["username"]

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

class FreeStuffWebhookPayload(BaseModel):
    type: str = Field(..., description="Event type (ping, announcement_created, product_updated)")
    timestamp: str = Field(..., description="ISO 8601 timestamp")
    data: dict = Field(..., description="Event-specific data")
    class Config:
        json_schema_extra = {
            "example": {
                "type": "fsb:event:announcement_created",
                "timestamp": "2022-11-03T20:26:10.344522Z",
                "data": {"id": 12345, "products": [1, 2, 3]}
            }
        }

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
    beta_version: str
    stable_version: str
    discord_bot: str
    class Config:
        json_schema_extra = {
            "example": {
                "beta_version": "5.5",
                "stable_version": "5.4",
                "discord_bot": "5.3.0"
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

# Define the response model for Bot Status
class BotStatusResponse(BaseModel):
    running: bool
    pid: int = None
    version: str = None
    bot_type: str = None
    outdated: bool = None
    latest_version: str = None
    class Config:
        json_schema_extra = {
            "example": {
                "running": True,
                "pid": 12345,
                "version": "5.5",
                "bot_type": "stable",
                "outdated": True,
                "latest_version": "5.7.1"
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

# Define the response model for User Points
class UserPointsResponse(BaseModel):
    username: str = Field(..., example="testuser")
    points: int = Field(..., example=1000)
    point_name: str = Field(..., example="Points")
    class Config:
        json_schema_extra = {"example": {"username": "testuser", "points": 1000, "point_name": "Points"}}

# Define the response model for User Points Modification
class UserPointsModificationResponse(BaseModel):
    username: str = Field(..., example="testuser")
    previous_points: int = Field(..., example=1000)
    amount: int = Field(..., example=100)
    new_points: int = Field(..., example=1100)
    point_name: str = Field(..., example="Points")
    class Config:
        json_schema_extra = {"example": {"username": "testuser", "previous_points": 1000, "amount": 100, "new_points": 1100, "point_name": "Points"}}

# Define the response model for Account information
class AccountResponse(BaseModel):
    id: int
    username: str
    twitch_display_name: str | None = None
    twitch_user_id: str
    access_token: str | None = None
    refresh_token: str | None = None
    useable_access_token: str | None = None
    useable_access_token_updated: str | None = None
    api_key: str
    is_admin: bool
    beta_access: bool
    is_technical: bool
    signup_date: str
    last_login: str
    profile_image: str | None = None
    email: str | None = None
    language: str | None = None
    use_custom: int | None = None
    use_self: int | None = None
    spotify_access_token: str | None = None
    spotify_refresh_token: str | None = None
    discord_access_token: str | None = None
    discord_refresh_token: str | None = None

# Define the response model for FreeStuff Games
class FreeStuffGame(BaseModel):
    id: int = Field(..., example=1)
    game_id: str = Field(None, example="steam_12345")
    game_title: str = Field(..., example="Awesome Game")
    game_org: str = Field(..., example="Steam")
    game_thumbnail: str = Field(None, example="https://example.com/image.jpg")
    game_url: str = Field(None, example="https://example.com/game")
    game_description: str = Field(None, example="An awesome free game")
    game_price: str = Field(..., example="Was $29.99")
    received_at: str = Field(..., example="2026-01-22 10:30:00")
    class Config:
        json_schema_extra = {
            "example": {
                "id": 1,
                "game_id": "steam_12345",
                "game_title": "Awesome Game",
                "game_org": "Steam",
                "game_thumbnail": "https://example.com/image.jpg",
                "game_url": "https://example.com/game",
                "game_description": "An awesome free game",
                "game_price": "Was $29.99",
                "received_at": "2026-01-22 10:30:00"
            }
        }

class FreeStuffGamesResponse(BaseModel):
    games: List[FreeStuffGame] = Field(..., example=[])
    count: int = Field(..., example=5)
    class Config:
        json_schema_extra = {
            "example": {
                "games": [
                    {
                        "id": 1,
                        "game_id": "steam_12345",
                        "game_title": "Awesome Game",
                        "game_org": "Steam",
                        "game_thumbnail": "https://example.com/image.jpg",
                        "game_url": "https://example.com/game",
                        "game_description": "An awesome free game",
                        "game_price": "Was $29.99",
                        "received_at": "2026-01-22 10:30:00"
                    }
                ],
                "count": 5
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

async def save_freestuff_game(webhook_data):
    try:
        conn = await get_mysql_connection()
        try:
            async with conn.cursor() as cur:
                # Create table if it doesn't exist
                await cur.execute("""
                    CREATE TABLE IF NOT EXISTS freestuff_games (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        game_id VARCHAR(255),
                        game_title VARCHAR(500),
                        game_org VARCHAR(255),
                        game_thumbnail TEXT,
                        game_url TEXT,
                        game_description TEXT,
                        game_price VARCHAR(50),
                        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_received_at (received_at)
                    )
                """)
                await conn.commit()
                # Extract game data from webhook
                data = webhook_data.get("data", {})
                product = data.get("product", {})
                game_id = product.get("id")
                game_title = product.get("title", "Unknown Game")
                game_org = product.get("org", {}).get("name", "Unknown")
                # Get thumbnail - prefer Steam if available
                thumbnails = product.get("thumbnails", {})
                game_thumbnail = thumbnails.get("steam_library_600x900") or thumbnails.get("org_logo") or ""
                # Get URL
                urls = product.get("urls", {})
                game_url = urls.get("default") or urls.get("org") or ""
                game_description = product.get("description", "")
                # Get price info
                prices = product.get("prices", [])
                game_price = "Free"
                if prices:
                    for price in prices:
                        if price.get("oldValue"):
                            game_price = f"Was ${price.get('oldValue')/100:.2f}"
                            break
                # Insert new game
                await cur.execute("""
                    INSERT INTO freestuff_games 
                    (game_id, game_title, game_org, game_thumbnail, game_url, game_description, game_price)
                    VALUES (%s, %s, %s, %s, %s, %s, %s)
                """, (game_id, game_title, game_org, game_thumbnail, game_url, game_description, game_price))
                await conn.commit()
                # Keep only last 5 games
                await cur.execute("""
                    DELETE FROM freestuff_games 
                    WHERE id NOT IN (
                        SELECT id FROM (
                            SELECT id FROM freestuff_games 
                            ORDER BY received_at DESC 
                            LIMIT 5
                        ) AS keep_games
                    )
                """)
                await conn.commit()
                logging.info(f"Saved FreeStuff game: {game_title} ({game_org})")
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Error saving FreeStuff game to database: {e}")

@app.post(
    "/freestuff",
    summary="Receive and process FreeStuff Webhook Requests",
    description="Receives FreeStuff webhooks (ping, announcements, product updates) and forwards to WebSocket.",
    tags=["Webhooks"],
    status_code=status.HTTP_204_NO_CONTENT,
    operation_id="process_freestuff_webhook",
    include_in_schema=False
)
async def handle_freestuff_webhook(request: Request, api_key: str = Query(...)):
    key_info = await verify_key(api_key, service="FreeStuff")
    if not key_info or key_info["type"] != "admin":
        raise HTTPException(status_code=401, detail="Invalid Admin API Key")
    webhook_id = request.headers.get("Webhook-Id")
    compatibility_date = request.headers.get("X-Compatibility-Date")
    try:
        webhook_data = await request.json()
        if "type" not in webhook_data or "timestamp" not in webhook_data:
            raise HTTPException(status_code=400, detail="Invalid webhook: missing type/timestamp")
        event_type = webhook_data.get("type")
        logging.info(f"FreeStuff: {event_type} | ID: {webhook_id} | Compat: {compatibility_date}")
        # Save game announcement to database
        if event_type == "fsb:announcement:free-games":
            await save_freestuff_game(webhook_data)
        if event_type == "fsb:event:ping":
            manual = webhook_data.get("data", {}).get("manual", False)
            logging.info(f"FreeStuff ping ({'manual' if manual else 'automatic'})")
            return JSONResponse(status_code=204, content=None, headers={"X-Client-Library": "BotOfTheSpecter/1.0"})
    except json.JSONDecodeError as e:
        logging.error(f"Invalid JSON in FreeStuff webhook: {e}")
        raise HTTPException(status_code=400, detail="Invalid JSON payload")
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error processing FreeStuff webhook: {e}")
        raise HTTPException(status_code=500, detail="Internal server error")
    async with aiohttp.ClientSession() as session:
        try:
            params = {"code": api_key, "event": "FREESTUFF", "data": webhook_data}
            encoded_params = urlencode(params)
            url = f"https://websocket.botofthespecter.com/notify?{encoded_params}"
            async with session.get(url, timeout=10) as response:
                if response.status != 200:
                    logging.error(f"WebSocket forward failed: {response.status}")
                    raise HTTPException(status_code=500, detail="Error forwarding to websocket")
        except asyncio.TimeoutError:
            logging.error("Timeout forwarding FreeStuff event")
            raise HTTPException(status_code=500, detail="Timeout forwarding to websocket")
        except HTTPException:
            raise
        except Exception as e:
            logging.error(f"Error forwarding FreeStuff: {e}")
            raise HTTPException(status_code=500, detail="Error forwarding to websocket")
    return JSONResponse(status_code=204, content=None, headers={"X-Client-Library": "BotOfTheSpecter/1.0"})

# FreeStuff Games List Endpoint
@app.get(
    "/freestuff/games",
    response_model=FreeStuffGamesResponse,
    summary="Get recent free games",
    description="Retrieve the last 5 free games announced via FreeStuff webhooks.",
    tags=["Public"],
    operation_id="get_freestuff_games"
)
async def get_freestuff_games():
    logging.info("FreeStuff Games endpoint called")
    try:
        conn = await get_mysql_connection()
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("""
                    SELECT id, game_id, game_title, game_org, game_thumbnail, 
                           game_url, game_description, game_price, received_at
                    FROM freestuff_games
                    ORDER BY received_at DESC
                    LIMIT 5
                """)
                games = await cur.fetchall()
                logging.info(f"Found {len(games) if games else 0} games")
                # Convert datetime to string for JSON serialization
                if games:
                    for game in games:
                        if game.get('received_at'):
                            game['received_at'] = game['received_at'].strftime('%Y-%m-%d %H:%M:%S')
                
                result = {"games": games or [], "count": len(games) if games else 0}
                logging.info(f"Returning result with {result['count']} games")
                return result
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Error fetching FreeStuff games: {type(e).__name__}: {str(e)}")
        logging.error(f"Traceback: {traceback.format_exc()}")
        raise HTTPException(status_code=500, detail=f"Error fetching games: {str(e)}")

# Account Information Endpoint
@app.get(
    "/account",
    response_model=AccountResponse,
    summary="Get account information",
    description="Retrieve all account information for the authenticated user based on their API key.",
    tags=["User Account"],
    operation_id="get_account_info"
)
async def get_account_info(api_key: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    conn = await get_mysql_connection()
    try:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute("""
                SELECT 
                    u.id, u.username, u.twitch_display_name, u.twitch_user_id,
                    u.access_token, u.refresh_token, u.api_key,
                    u.is_admin, u.beta_access, u.is_technical,
                    u.signup_date, u.last_login, u.profile_image,
                    u.email, u.language, u.use_custom, u.use_self,
                    s.access_token AS spotify_access_token,
                    s.refresh_token AS spotify_refresh_token,
                    d.access_token AS discord_access_token,
                    d.refresh_token AS discord_refresh_token,
                    t.twitch_access_token AS useable_access_token,
                    t.updated_at AS useable_access_token_updated
                FROM users u
                LEFT JOIN spotify_tokens s ON u.id = s.user_id
                LEFT JOIN discord_users d ON u.id = d.user_id
                LEFT JOIN twitch_bot_access t ON u.twitch_user_id = t.twitch_user_id
                WHERE u.username = %s
            """, (username,))
            result = await cur.fetchone()
            if not result:
                raise HTTPException(status_code=404, detail="Account not found")
            return {
                "id": result["id"],
                "username": result["username"],
                "twitch_display_name": result["twitch_display_name"],
                "twitch_user_id": result["twitch_user_id"],
                "access_token": result["access_token"],
                "refresh_token": result["refresh_token"],
                "useable_access_token": result["useable_access_token"],
                "useable_access_token_updated": str(result["useable_access_token_updated"]) if result["useable_access_token_updated"] is not None else None,
                "api_key": result["api_key"],
                "is_admin": bool(result["is_admin"]),
                "beta_access": bool(result["beta_access"]),
                "is_technical": bool(result["is_technical"]),
                "signup_date": str(result["signup_date"]),
                "last_login": str(result["last_login"]),
                "profile_image": result["profile_image"],
                "email": result["email"],
                "language": result["language"],
                "use_custom": result["use_custom"],
                "use_self": result["use_self"],
                "spotify_access_token": result["spotify_access_token"],
                "spotify_refresh_token": result["spotify_refresh_token"],
                "discord_access_token": result["discord_access_token"],
                "discord_refresh_token": result["discord_refresh_token"]
            }
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error retrieving account info for username '{username}': {e}")
        logging.error(f"Traceback: {traceback.format_exc()}")
        raise HTTPException(status_code=500, detail=f"Error retrieving account information: {str(e)}")
    finally:
        conn.close()

# Quotes endpoint
@app.get(
    "/quotes",
    response_model=QuoteResponse,
    summary="Get a random quote",
    description="Retrieve a random quote from the database of quotes, based on a random author.",
    tags=["Commands"],
    operation_id="get_random_quote"
)
async def quotes(api_key: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
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
async def fortune(api_key: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
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
    description="Fetch the beta, stable, and discord bot version numbers.",
    tags=["Public"],
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
    tags=["Public"],
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
    tags=["Public"],
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
    tags=["Public"],
    operation_id="get_database_heartbeat"
)
async def database_heartbeat():
    db_host = os.getenv('SQL-HOST')
    is_alive = await check_icmp_ping(db_host)
    return {"status": "OK" if is_alive else "OFF"}

# Chat instructions endpoint
@app.get(
    "/chat-instructions",
    summary="Get AI chat instructions",
    description="Return the AI system instructions used by the Twitch chat bot (?discord flag switches to the Discord-specific instructions file if present)",
    tags=["Public"],
    operation_id="get_chat_instructions"
)
async def chat_instructions(request: Request, discord: bool = Query(False, description="Return Discord-specific AI instructions if available")):
    # Prefer Discord-specific instructions when the query flag is set
    use_discord = discord
    # Decide which file to load
    base_dir = "/home/botofthespecter"
    discord_path = os.path.join(base_dir, "ai.discord.json")
    default_path = os.path.join(base_dir, "ai.json")
    path = discord_path if use_discord and os.path.exists(discord_path) else default_path
    try:
        if os.path.exists(path):
            with open(path, "r", encoding="utf-8") as f:
                data = json.load(f)
            return JSONResponse(status_code=200, content=data)
        # Not found
        raise HTTPException(status_code=404, detail=f"AI instructions not found at {path}")
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error loading AI instructions from {path}: {e}")
        raise HTTPException(status_code=500, detail="Failed to load AI instructions")

# Public API Requests Remaining (for song)
@app.get(
    "/api/song",
    response_model=PublicAPIResponse,
    summary="Get the remaining song requests",
    description="Get the number of remaining song requests for the current reset period.",
    tags=["Public"],
    operation_id="get_song_requests_remaining"
)
async def api_song():
    try:
        # Get count from database
        count, reset_day = await get_api_count("shazam")
        # Calculate days until reset (based on UTC now)
        reset_day = int(reset_day)
        today = datetime.now(timezone.utc)
        if today.day >= reset_day:
            next_month = today.month + 1 if today.month < 12 else 1
            next_year = today.year + 1 if today.month == 12 else today.year
            next_reset = datetime(next_year, next_month, reset_day, tzinfo=timezone.utc)
        else:
            next_reset = datetime(today.year, today.month, reset_day, tzinfo=timezone.utc)
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
    tags=["Public"],
    operation_id="get_exchangerate_requests_remaining"
)
async def api_exchangerate():
    try:
        # Get count from database
        count, reset_day = await get_api_count("exchangerate")
        # Calculate days until reset (based on UTC now)
        reset_day = int(reset_day)
        today = datetime.now(timezone.utc)
        if today.day >= reset_day:
            next_month = today.month + 1 if today.month < 12 else 1
            next_year = today.year + 1 if today.month == 12 else today.year
            next_reset = datetime(next_year, next_month, reset_day, tzinfo=timezone.utc)
        else:
            next_reset = datetime(today.year, today.month, reset_day, tzinfo=timezone.utc)
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
    tags=["Public"],
    operation_id="get_weather_requests_remaining"
)
async def api_weather_requests_remaining():
    try:
        # Calculate time remaining until next UTC midnight
        now = datetime.now(timezone.utc)
        midnight = datetime(now.year, now.month, now.day, tzinfo=timezone.utc) + timedelta(days=1)
        time_until_midnight = int((midnight - now).total_seconds())
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
async def kill_responses(api_key: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
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
async def joke(api_key: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    jokes = await Jokes()
    get_joke = await jokes.get_joke(blacklist=['nsfw', 'racist', 'sexist', 'political', 'religious'])
    if "category" not in get_joke:
        raise HTTPException(status_code=500, detail="Error: Unable to retrieve joke from API.")
    return get_joke

# Sound Alerts Endpoint
@app.get(
    "/sound-alerts",
    summary="Get list of sound alerts for user",
    description="Retrieve a list of all sound alert files available for the authenticated user from the website server.",
    tags=["Commands"],
    operation_id="get_sound_alerts"
)
async def get_sound_alerts(api_key: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    try:
        # Get SSH credentials from environment
        website_ssh_host = os.getenv('WEB-HOST')
        website_ssh_username = os.getenv('SSH_USERNAME')
        website_ssh_password = os.getenv('SSH_PASSWORD')
        # Connect to website server via SSH
        ssh_client = SSHClient()
        ssh_client.set_missing_host_key_policy(AutoAddPolicy())
        # Run connection in executor to avoid blocking
        await asyncio.get_event_loop().run_in_executor(
            None,
            lambda: ssh_client.connect(
                hostname=website_ssh_host,
                port=22,
                username=website_ssh_username,
                password=website_ssh_password,
                timeout=10
            )
        )
        try:
            # Build the command to list files in the user's sound alerts directory
            sound_alerts_dir = f"/var/www/soundalerts/{username}"
            # List only files directly in the directory (maxdepth 1), excluding the twitch subdirectory
            command = f'ls -1 "{sound_alerts_dir}" 2>/dev/null | grep -v "^twitch$" | while read f; do [ -f "{sound_alerts_dir}/$f" ] && echo "$f"; done | sort'
            # Execute command in executor to avoid blocking
            stdin, stdout, stderr = await asyncio.get_event_loop().run_in_executor(
                None,
                lambda: ssh_client.exec_command(command)
            )
            # Read output in executor
            stdout_data = await asyncio.get_event_loop().run_in_executor(
                None,
                stdout.read
            )
            stderr_data = await asyncio.get_event_loop().run_in_executor(
                None,
                stderr.read
            )
            return_code = stdout.channel.recv_exit_status()
            if return_code != 0:
                error_msg = stderr_data.decode('utf-8').strip()
                if "No such file" in error_msg or "cannot access" in error_msg:
                    raise HTTPException(status_code=404, detail=f"No sound alerts directory found for user '{channel}'")
                logging.error(f"Error listing sound alerts for '{channel}': {error_msg}")
                raise HTTPException(status_code=500, detail=f"Error retrieving sound alerts")
            # Parse output and filter for valid audio/video extensions
            output = stdout_data.decode('utf-8').strip()
            if not output:
                sound_files = []
            else:
                valid_extensions = ('.mp3', '.wav', '.ogg', '.m4a', '.mp4', '.webm', '.avi', '.mov')
                all_files = output.split('\n')
                sound_files = [f for f in all_files if f.lower().endswith(valid_extensions)]
            # Return formatted JSON response
            return {
                "user": channel,
                "total_sounds": len(sound_files),
                "sounds": sound_files
            }
        finally:
            # Close SSH connection
            ssh_client.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error retrieving sound alerts for user '{channel}': {e}")
        raise HTTPException(status_code=500, detail=f"Error retrieving sound alerts: {str(e)}")

# Custom Commands Endpoint
@app.get(
    "/custom-commands",
    summary="Get list of custom commands for your account",
    description="Retrieve a list of all custom commands available for your account from your database.",
    tags=["Commands"],
    operation_id="get_custom_commands"
)
async def get_custom_commands(api_key: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    try:
        # Connect to user's database
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                # Query all custom commands
                await cursor.execute("""
                    SELECT command, response, status
                    FROM custom_commands
                    ORDER BY command ASC
                """)
                commands = await cursor.fetchall()
            # Format the response
            command_list = []
            for cmd in commands:
                command_list.append({
                    "command": cmd['command'],
                    "response": cmd['response'],
                    "status": cmd['status']
                })
            return {
                "user": username,
                "total_commands": len(command_list),
                "commands": command_list
            }
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error retrieving custom commands for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error retrieving custom commands: {str(e)}")

# Weather Data Endpoint
@app.get(
    "/weather",
    summary="Get weather data and trigger WebSocket weather event",
    description="Retrieve current weather data for a given location and send it to the WebSocket server.",
    tags=["Commands"],
    operation_id="get_weather_data_and_trigger_event"
)
async def fetch_weather_via_api(api_key: str = Query(...), location: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
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
    tags=["WebSocket Triggers"],
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
    tags=["WebSocket Triggers"],
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
    tags=["WebSocket Triggers"],
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
    description="Trigger a sound alert for the specified sound file via the WebSocket server.",
    tags=["WebSocket Triggers"],
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

# WebSocket Custom Command Trigger
@app.get(
    "/websocket/custom_command",
    summary="Trigger Custom Command via API",
    description="Trigger a custom command via the WebSocket server. The bot will listen for this event and execute the command in chat.",
    tags=["WebSocket Triggers"],
    operation_id="trigger_websocket_custom_command"
)
async def websocket_custom_command(api_key: str = Query(...), command: str = Query(..., description="The custom command to trigger (without the ! prefix)")):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = valid
    # Verify the command exists in the user's database
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("""
                    SELECT command, response, status
                    FROM custom_commands
                    WHERE command = %s
                """, (command,))
                cmd_data = await cursor.fetchone()
        finally:
            connection.close()
        if not cmd_data:
            raise HTTPException(status_code=404, detail=f"Custom command '{command}' not found")
        if cmd_data['status'] != 'Enabled':
            raise HTTPException(status_code=400, detail=f"Custom command '{command}' is not enabled")
        # Trigger the WebSocket event
        params = {
            "event": "CUSTOM_COMMAND",
            "command": command,
            "response": cmd_data['response']
        }
        await websocket_notice("CUSTOM_COMMAND", params, api_key)
        return {"status": "success", "command": command, "response": cmd_data['response']}
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error triggering custom command '{command}' for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error triggering custom command: {str(e)}")

# WebSocket Stream Online Trigger
@app.get(
    "/websocket/stream_online",
    summary="Trigger Stream Online via API",
    description="Send a 'Stream Online' event to the WebSocket server to notify that the stream is live.",
    tags=["WebSocket Triggers"],
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
    tags=["WebSocket Triggers"],
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
    tags=["WebSocket Triggers"],
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

# User Points Endpoint
@app.get(
    "/user-points",
    response_model=UserPointsResponse,
    summary="Get user points",
    description="Retrieve the number of points for a specific user from the bot_points table.",
    tags=["Commands"],
    operation_id="get_user_points"
)
async def get_user_points(api_key: str = Query(..., description="API key for authentication"), username: str = Query(..., description="Username to retrieve points for")):
    # Verify the API key
    auth_username = await verify_api_key(api_key)
    if not auth_username:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    try:
        # Connect to the user's database
        conn = await get_mysql_connection_user(auth_username)
        async with conn.cursor(aiomysql.DictCursor) as cursor:
            # Query the bot_points table for the specified username
            await cursor.execute(
                "SELECT points FROM bot_points WHERE user_name = %s",
                (username,)
            )
            result = await cursor.fetchone()
            points = result['points'] if result else 0
            # Query the bot_settings table for the point_name
            await cursor.execute(
                "SELECT point_name FROM bot_settings LIMIT 1"
            )
            settings_result = await cursor.fetchone()
            point_name = settings_result['point_name'] if settings_result and settings_result['point_name'] else "Points"
            logging.info(f"Retrieved {points} {point_name} for user {username} (auth: {auth_username})")
            return {"username": username, "points": points, "point_name": point_name}
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error retrieving points for user {username}: {e}")
        raise HTTPException(status_code=500, detail=f"Error retrieving user points: {str(e)}")
    finally:
        conn.close()

# Credit User Points Endpoint
@app.post(
    "/user-points/credit",
    response_model=UserPointsModificationResponse,
    summary="Credit points to a user",
    description="Add points to a specific user in the bot_points table. If the user doesn't exist, they will be created with the credited amount.",
    tags=["Commands"],
    operation_id="credit_user_points"
)
async def credit_user_points(api_key: str = Query(..., description="API key for authentication"), username: str = Query(..., description="Username to credit points to"), amount: int = Query(..., description="Amount of points to credit", gt=0)):
    # Verify the API key
    auth_username = await verify_api_key(api_key)
    if not auth_username:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    try:
        # Connect to the user's database
        conn = await get_mysql_connection_user(auth_username)
        async with conn.cursor(aiomysql.DictCursor) as cursor:
            # Get current points
            await cursor.execute(
                "SELECT points FROM bot_points WHERE user_name = %s",
                (username,)
            )
            result = await cursor.fetchone()
            previous_points = result['points'] if result else 0
            new_points = previous_points + amount
            # Insert or update points
            if result:
                await cursor.execute(
                    "UPDATE bot_points SET points = %s WHERE user_name = %s",
                    (new_points, username)
                )
            else:
                await cursor.execute(
                    "INSERT INTO bot_points (user_name, points) VALUES (%s, %s)",
                    (username, new_points)
                )
            await conn.commit()
            # Get point_name from settings
            await cursor.execute("SELECT point_name FROM bot_settings LIMIT 1")
            settings_result = await cursor.fetchone()
            point_name = settings_result['point_name'] if settings_result and settings_result['point_name'] else "Points"
            logging.info(f"Credited {amount} {point_name} to {username} ({previous_points} -> {new_points}) (auth: {auth_username})")
            return {
                "username": username,
                "previous_points": previous_points,
                "amount": amount,
                "new_points": new_points,
                "point_name": point_name
            }
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error crediting points for user {username}: {e}")
        raise HTTPException(status_code=500, detail=f"Error crediting user points: {str(e)}")
    finally:
        conn.close()

# Debit User Points Endpoint
@app.post(
    "/user-points/debit",
    response_model=UserPointsModificationResponse,
    summary="Debit points from a user",
    description="Subtract points from a specific user in the bot_points table. User must exist and have sufficient points.",
    tags=["Commands"],
    operation_id="debit_user_points"
)
async def debit_user_points(api_key: str = Query(..., description="API key for authentication"), username: str = Query(..., description="Username to debit points from"), amount: int = Query(..., description="Amount of points to debit", gt=0), allow_negative: bool = Query(False, description="Allow points to go negative")):
    # Verify the API key
    auth_username = await verify_api_key(api_key)
    if not auth_username:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    try:
        # Connect to the user's database
        conn = await get_mysql_connection_user(auth_username)
        async with conn.cursor(aiomysql.DictCursor) as cursor:
            # Get current points
            await cursor.execute(
                "SELECT points FROM bot_points WHERE user_name = %s",
                (username,)
            )
            result = await cursor.fetchone()
            if not result:
                raise HTTPException(status_code=404, detail=f"User '{username}' not found in points table")
            previous_points = result['points']
            new_points = previous_points - amount
            # Check if points would go negative
            if new_points < 0 and not allow_negative:
                raise HTTPException(status_code=400, detail=f"Insufficient points. User has {previous_points} points but {amount} requested.")
            # Update points
            await cursor.execute(
                "UPDATE bot_points SET points = %s WHERE user_name = %s",
                (new_points, username)
            )
            await conn.commit()
            # Get point_name from settings
            await cursor.execute("SELECT point_name FROM bot_settings LIMIT 1")
            settings_result = await cursor.fetchone()
            point_name = settings_result['point_name'] if settings_result and settings_result['point_name'] else "Points"
            logging.info(f"Debited {amount} {point_name} from {username} ({previous_points} -> {new_points}) (auth: {auth_username})")
            return {
                "username": username,
                "previous_points": previous_points,
                "amount": amount,
                "new_points": new_points,
                "point_name": point_name
            }
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error debiting points for user {username}: {e}")
        raise HTTPException(status_code=500, detail=f"Error debiting user points: {str(e)}")
    finally:
        conn.close()

# Hidden Endpoints
# Function to verify the location of the user for weather
@app.get(
    "/weather/location",
    summary="Get website-specific weather location",
    description="Retrieve the correct location information for a given query from the website.",
    operation_id="get_web_weather_location",
    include_in_schema=False
)
async def web_weather(api_key: str = Query(...), location: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    try:
        location_data, lat, lon = await get_weather_lat_lon(location)
        if lat is None or lon is None:
            raise HTTPException(status_code=404, detail=f"Location '{location}' not found.")
        # Decrement weather count (UTC-based usage)
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
    twitch_auth_token: str = Query(..., description="Twitch OAuth token for IGDB authorization"),
    channel: str = Query(None)
):
    key_info = await verify_key(api_key)
    if not key_info:
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
    summary="Get a list of authorized users for full beta access to the entire Specter Ecosystem",
    tags=["Admin Only"]
)
async def authorized_users(api_key: str = Query(...)):
    key_info = await verify_key(api_key)
    if not key_info or key_info["type"] != "admin":
        raise HTTPException(status_code=401, detail="Invalid Admin API Key")
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
    tags=["User Account"],
    include_in_schema=False
)
async def check_key(api_key: str = Query(...)):
    key_info = await verify_key(api_key)
    if not key_info:
        return {"status": "Invalid API Key"}
    if key_info["type"] == "admin":
        return {"status": "Valid API Key", "username": "ADMIN"}
    return {"status": "Valid API Key", "username": key_info["username"]}

# Check if stream is online
@app.get(
    "/streamonline",
    summary="Check if the stream is online",
    tags=["User Account"],
    include_in_schema=False
)
async def stream_online(api_key: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
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
    tags=["Admin Only"]
)
async def discord_linked(api_key: str = Query(...), user_id: str = Query(...)):
    # Validate the admin API key
    key_info = await verify_key(api_key)
    if not key_info or key_info["type"] != "admin":
        raise HTTPException(status_code=401, detail="Invalid Admin API Key")
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

# Function to check bot status via SSH
async def get_bot_status_via_ssh(username: str) -> dict:
    # Load latest versions from versions.json
    versions_path = "/home/botofthespecter/versions.json"
    if not os.path.exists(versions_path):
        versions_path = "/home/fastapi/versions.json"
    latest_versions = {}
    try:
        with open(versions_path, "r") as versions_file:
            latest_versions = json.load(versions_file)
    except Exception as e:
        logging.error(f"Error loading versions.json: {e}")
    if not all([BOTS_SSH_HOST, BOTS_SSH_USERNAME, BOTS_SSH_PASSWORD]):
        return {
            "running": False,
            "pid": None,
            "version": None,
            "bot_type": None,
            "outdated": None,
            "latest_version": None
        }
    try:
        # Create SSH client
        ssh = SSHClient()
        ssh.set_missing_host_key_policy(AutoAddPolicy())
        # Connect to SSH server
        ssh.connect(
            hostname=BOTS_SSH_HOST,
            username=BOTS_SSH_USERNAME,
            password=BOTS_SSH_PASSWORD,
            timeout=10
        )
        # Execute the running_bots.py script
        stdin, stdout, stderr = ssh.exec_command("python3 /home/botofthespecter/running_bots.py 2>&1")
        output = stdout.read().decode('utf-8')
        # Close SSH connection
        ssh.close()
        # Parse the output to find the specific user's bot
        lines = output.split('\n')
        section = ''
        for line in lines:
            line = line.strip()
            # Identify which section we're in
            if 'Stable bots running:' in line:
                section = 'stable'
            elif 'Beta bots running:' in line:
                section = 'beta'
            elif 'Custom bots running:' in line:
                section = 'custom'
            # Parse bot information line
            # Format: - Channel: username, PID: 12345, Version: 3.0 | Status
            import re
            match = re.match(r'- Channel: (\S+), PID: (\d+), Version: (.+?)\s*\|(.+)', line)
            if match:
                channel = match.group(1)
                pid = int(match.group(2))
                version = match.group(3)
                status_text = match.group(4).strip()
                is_outdated = 'OUTDATED' in status_text
                # Check if this is the user we're looking for
                if channel.lower() == username.lower():
                    # Determine the latest version based on bot type
                    latest_version = None
                    if section == 'stable' and 'stable_version' in latest_versions:
                        latest_version = latest_versions['stable_version']
                    elif section == 'beta' and 'beta_version' in latest_versions:
                        latest_version = latest_versions['beta_version']
                    elif section == 'custom' and 'stable_version' in latest_versions:
                        # Custom bots compare against stable version
                        latest_version = latest_versions['stable_version']
                    return {
                        "running": True,
                        "pid": pid,
                        "version": version,
                        "bot_type": section,
                        "outdated": is_outdated,
                        "latest_version": latest_version
                    }
        # If we get here, the bot is not running
        return {
            "running": False,
            "pid": None,
            "version": None,
            "bot_type": None,
            "outdated": None,
            "latest_version": None
        }
    except Exception as e:
        logging.error(f"Error checking bot status for {username}: {str(e)}")
        return {
            "running": False,
            "pid": None,
            "version": None,
            "bot_type": None,
            "outdated": None,
            "latest_version": None
        }

# Bot Status Endpoint
@app.get(
    "/bot/status",
    response_model=BotStatusResponse,
    summary="Get chat bot status",
    description="Check if your chat bot is currently running and retrieve its status information.",
    tags=["User Account"],
    operation_id="get_bot_status"
)
async def bot_status(api_key: str = Query(..., description="Your API key for authentication"), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid API key"
        )
    username = resolve_username(key_info, channel)
    # Get bot status via SSH
    bot_status_info = await get_bot_status_via_ssh(username)
    return BotStatusResponse(**bot_status_info)

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