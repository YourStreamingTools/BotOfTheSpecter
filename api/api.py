import os
import aiohttp
import aiomysql
import random
import json
import paramiko
import uvicorn
import datetime
import logging
import asyncio
from datetime import datetime, timedelta
from fastapi import FastAPI, HTTPException, Depends, Request, Query, status
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from typing import Dict, List
from jokeapi import Jokes
from dotenv import load_dotenv, find_dotenv
from urllib.parse import urlencode
from contextlib import asynccontextmanager

# Load ENV file
load_dotenv(find_dotenv("/home/fastapi/.env"))
SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER')
SQL_PASSWORD = os.getenv('SQL_PASSWORD')
ADMIN_KEY = os.getenv('ADMIN_KEY')
SFTP_HOST = "10.240.0.169"
SFTP_USER = os.getenv("SFPT_USERNAME")
SFTP_PASSWORD = os.getenv("SFPT_PASSWORD")
WEATHER_API = os.getenv('WEATHER_API')

# Setup Logger
logging.basicConfig(
    level=logging.INFO,
    filename="/home/fastapi/log.txt",
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

# Midnight function
async def midnight():
    while True:
        # Get the current time
        current_time = datetime.now()
        # Paths to the log files
        weather_log_file = "/home/fastapi/api/weather_requests.txt"
        shazam_log_file = "/home/fastapi/api/shazam_requests.txt"
        exchangerate_log_file = "/home/fastapi/api/exchangerate_requests.txt"
        try:
            # Connect via SFTP
            ssh = paramiko.SSHClient()
            ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
            ssh.connect(hostname=SFTP_HOST, port=22, username=SFTP_USER, password=SFTP_PASSWORD)
            sftp = ssh.open_sftp()
            # Reset weather requests at midnight (this happens daily)
            if current_time.hour == 0 and current_time.minute == 0:
                # Reload the .env file at midnight
                load_dotenv()
                # Reset weather requests to 1000
                with open(weather_log_file, "w") as weather_file:
                    weather_file.write("1000")
                # Transfer the weather requests file via SFTP
                sftp.put(weather_log_file, "/var/www/api/weather.txt")
            # Reset song identifications on the 23rd of each month
            if current_time.day == 23 and current_time.hour == 0 and current_time.minute == 0:
                # Reset song identifications to 500
                with open(shazam_log_file, "w") as shazam_file:
                    shazam_file.write("500")
                # Transfer the song identification file via SFTP
                sftp.put(shazam_log_file, "/var/www/api/shazam.txt")
            # Reset exchange rate checks on the 14th of each month
            if current_time.day == 14 and current_time.hour == 0 and current_time.minute == 0:
                # Reset exchange rate checks to 1500
                with open(exchangerate_log_file, "w") as exchangerate_file:
                    exchangerate_file.write("1500")
                # Transfer the exchange rate file via SFTP
                sftp.put(exchangerate_log_file, "/var/www/api/exchangerate.txt")
            # Close the SFTP connection
            sftp.close()
            ssh.close()
        except Exception as e:
            # Handle any errors during the reset and SFTP transfer
            logging.error(f"Failed to reset API request files or transfer via SFTP: {e}")
        # Sleep for 60 seconds before checking again
        await asyncio.sleep(60)

# Lifespan event handler
@asynccontextmanager
async def lifespan(app: FastAPI):
    # Start the midnight task
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
                "beta_version": "4.7",
                "stable_version": "4.6"
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
    time_remaining: int
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
    # Extract JSON data from the Fourthwall webhook
    try:
        webhook_data = await request.json()
        logging.info(f"{webhook_data}")
    except Exception as e:
        raise HTTPException(status_code=400, detail="Invalid JSON payload")
    # Send the data to the WebSocket server
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

# Websocket HeartBeat
@app.get(
    "/websocket/heartbeat",
    response_model=HeartbeatControlResponse,
    summary="Get the heartbeat status of the websocket server",
    tags=["BotOfTheSpecter", "Websocket"]
)
async def websocket_heartbeat():
    url = "https://websocket.botofthespecter.com/heartbeat"
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(url) as response:
                if response.status == 200:
                    heartbeat_data = await response.json()  # Fetch the JSON data
                    return heartbeat_data  # Return the data as the response
                else:
                    return {"status": "OFF"} 
    except Exception:
        return {"status": "OFF"}

# Public API Requests Remaining
@app.get(
    "/api/song",
    response_model=PublicAPIResponse,
    summary="Get the current remaining requests for the song command",
    tags=["BotOfTheSpecter"]
)
async def api_song():
    try:
        reset_day = 23
        today = datetime.now()
        if today.day >= reset_day:
            next_month = today.month + 1 if today.month < 12 else 1
            next_year = today.year + 1 if today.month == 12 else today.year
            next_reset = datetime(next_year, next_month, reset_day)
        else:
            next_reset = datetime(today.year, today.month, reset_day)
        days_until_reset = (next_reset - today).days
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        get_requests_remaining = "/var/www/api/shazam.txt"
        ssh.connect(hostname=SFTP_HOST, port=22, username=SFTP_USER, password=SFTP_PASSWORD)
        sftp = ssh.open_sftp()
        with sftp.open(get_requests_remaining, "r") as requests_remaining:
            file_content = requests_remaining.read()
        sftp.close()
        ssh.close()
        return {"requests_remaining": file_content, "days_remaining": days_until_reset}
    except Exception as e:
        sanitized_error = str(e).replace(SFTP_USER, '[SFTP_USER]')
        sanitized_error = str(e).replace(SFTP_PASSWORD, '[SFTP_PASSWORD]')
        raise HTTPException(status_code=500, detail=f"{sanitized_error}")

@app.get(
    "/api/exchangerate",
    response_model=PublicAPIResponse,
    summary="Get the current remaining requests for the convert command for exchangerates",
    tags=["BotOfTheSpecter"]
)
async def api_exchangerate():
    try:
        reset_day = 14
        today = datetime.now()
        if today.day >= reset_day:
            next_month = today.month + 1 if today.month < 12 else 1
            next_year = today.year + 1 if today.month == 12 else today.year
            next_reset = datetime(next_year, next_month, reset_day)
        else:
            next_reset = datetime(today.year, today.month, reset_day)
        days_until_reset = (next_reset - today).days
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        get_requests_remaining = "/var/www/api/exchangerate.txt"
        ssh.connect(hostname=SFTP_HOST, port=22, username=SFTP_USER, password=SFTP_PASSWORD)
        sftp = ssh.open_sftp()
        with sftp.open(get_requests_remaining, "r") as requests_remaining:
            file_content = requests_remaining.read()
        sftp.close()
        ssh.close()
        return {"requests_remaining": file_content, "days_remaining": days_until_reset}
    except Exception as e:
        sanitized_error = str(e).replace(SFTP_USER, '[SFTP_USER]')
        sanitized_error = str(e).replace(SFTP_PASSWORD, '[SFTP_PASSWORD]')
        raise HTTPException(status_code=500, detail=f"SFTP connection failed: {sanitized_error}")

@app.get(
    "/api/weather",
    response_model=PublicAPIDailyResponse,
    summary="Get the current remaining requests for the weather API",
    tags=["BotOfTheSpecter"]
)
async def api_weather_requests_remaining():
    try:
        # Calculate the time remaining until midnight
        now = datetime.now()
        midnight = datetime(now.year, now.month, now.day) + timedelta(days=1)
        time_until_midnight = (midnight - now).seconds
        # Determine the format for displaying the remaining time
        hours, remainder = divmod(time_until_midnight, 3600)
        minutes, seconds = divmod(remainder, 60)
        if hours > 0:
            time_remaining = f"{hours} hours, {minutes} minutes, {seconds} seconds"
        elif minutes > 0:
            time_remaining = f"{minutes} minutes, {seconds} seconds"
        else:
            time_remaining = f"{seconds} seconds"
        # Connect to SFTP and retrieve the number of requests remaining
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        weather_requests_file = "/var/www/api/weather.txt"
        ssh.connect(hostname=SFTP_HOST, port=22, username=SFTP_USER, password=SFTP_PASSWORD)
        sftp = ssh.open_sftp()
        with sftp.open(weather_requests_file, "r") as requests_remaining:
            file_content = requests_remaining.read()
        sftp.close()
        ssh.close()
        return {"requests_remaining": file_content, "time_remaining": time_remaining}
    except Exception as e:
        sanitized_error = str(e).replace(SFTP_USER, '[SFTP_USER]').replace(SFTP_PASSWORD, '[SFTP_PASSWORD]')
        raise HTTPException(status_code=500, detail=f"SFTP connection failed: {sanitized_error}")

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

# Weather endpoint
@app.get(
    "/weather",
    summary="Get weather data and trigger WebSocket weather event",
    tags=["Commands"]
)
async def fetch_weather_via_api(api_key: str = Query(...), location: str = Query(...)):
    # Validate the API key before proceeding
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    # Fetch weather data
    try:
        lat, lon = await get_lat_lon(location)
        if lat is None or lon is None:
            raise HTTPException(status_code=404, detail=f"Location '{location}' not found.")
        weather_data_metric = await fetch_weather_data(lat, lon, units='metric')
        weather_data_imperial = await fetch_weather_data(lat, lon, units='imperial')
        if not weather_data_metric or not weather_data_imperial:
            raise HTTPException(status_code=500, detail="Error fetching weather data.")
        # Format weather data
        formatted_weather_data = format_weather_data(weather_data_metric, weather_data_imperial, location)
        # Log the request for tracking remaining requests
        log_file_path = "/home/fastapi/api/weather_requests.txt"
        remaining_requests = 1000  # Default daily request limit
        # Check current remaining requests
        try:
            with open(log_file_path, "r") as log_file:
                remaining_requests = int(log_file.read().strip())
        except FileNotFoundError:
            # If log file does not exist, we start with the max requests
            remaining_requests = 1000
        # Reduce remaining requests by 1
        remaining_requests -= 1
        # Log the new remaining request count
        with open(log_file_path, "w") as log_file:
            log_file.write(str(remaining_requests))
        # Transfer the updated file to the bot's server via SFTP
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        ssh.connect(hostname=SFTP_HOST, port=22, username=SFTP_USER, password=SFTP_PASSWORD)
        sftp = ssh.open_sftp()
        sftp.put(log_file_path, "/var/www/api/weather.txt")  # Transfer to the bot's server location
        sftp.close()
        ssh.close()
        # Trigger WebSocket weather event
        params = {"event": "WEATHER_DATA", "weather_data": formatted_weather_data}
        await websocket_notice("WEATHER_DATA", params, api_key)
        return {"status": "success", "weather_data": formatted_weather_data, "remaining_requests": remaining_requests}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Error: {str(e)}")

# Functions to fetch weather data
async def get_lat_lon(location):
    async with aiohttp.ClientSession() as session:
        async with session.get(f"http://api.openweathermap.org/geo/1.0/direct?q={location}&limit=1&appid={WEATHER_API}") as response:
            data = await response.json()
            if len(data) > 0:
                return data[0]['lat'], data[0]['lon']
            return None, None

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
        if start <= deg < end:
            return direction
    return "N/A"

# Websocket endpoints
@app.get(
    "/websocket/tts",
    summary="Trigger TTS via API",
    tags=["Websocket"],
    response_model=dict,
)
async def websocket_tts(api_key: str = Query(...), text: str = Query(...)):
    valid = await verify_api_key(api_key)  # Validate the API key before proceeding
    if not valid:  # Check if the API key is valid
        raise HTTPException(
            status_code=401,
            detail="Invalid API Key",
        )
    params = {"event": "TTS", "text": text}
    await websocket_notice("TTS", params, api_key)
    return {"status": "success"}

@app.get(
    "/websocket/walkon",
    summary="Trigger WALKON via API",
    tags=["Websocket"]
)
async def websocket_walkon(request: WalkonRequest, api_key, user):
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

# Hidden Endpoints
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
async def read_root():
    return RedirectResponse(url="/docs")

@app.get("/favicon.ico", include_in_schema=False)
async def favicon():
    return "https://cdn.botofthespecter.com/logo.ico"

if __name__ == "__main__":
    uvicorn.run(
        app,
        host="0.0.0.0",
        port=443,
        ssl_certfile="/home/fastapi/ssl/fullchain.pem",
        ssl_keyfile="/home/fastapi/ssl/privkey.pem"
    )