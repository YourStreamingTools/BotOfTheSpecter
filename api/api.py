# Standard library imports
import os
import random
import json
import copy
import secrets
import hashlib
import hmac
import base64
import time as _time
import logging
from logging.handlers import RotatingFileHandler
import asyncio
import datetime
import urllib
import socket
from datetime import datetime, timedelta, timezone
import traceback
import re
import shlex

# Third-party imports
import aiohttp
import aiomysql
from passlib.context import CryptContext
pwd_context = CryptContext(schemes=["bcrypt"], deprecated="auto")
import uvicorn
import aioping
import asyncssh
from fastapi import FastAPI, HTTPException, Request, status, Query, Form
from fastapi.responses import RedirectResponse, JSONResponse
from fastapi.middleware.cors import CORSMiddleware
from fastapi.exceptions import RequestValidationError
from fastapi.openapi.docs import get_swagger_ui_html, get_redoc_html
from fastapi.openapi.utils import get_openapi
from starlette.responses import Response
from pydantic import BaseModel, Field
from typing import Dict, List, Any, Optional
from jokeapi import Jokes
from dotenv import load_dotenv, find_dotenv
from urllib.parse import urlencode, parse_qsl, quote
from contextlib import asynccontextmanager
import math
import ipaddress

# Load ENV file
load_dotenv(find_dotenv("/home/botofthespecter/.env"))
SQL_HOST = os.getenv('SQL_HOST')
SQL_USER = os.getenv('SQL_USER') 
SQL_PASSWORD = os.getenv('SQL_PASSWORD')
SQL_PORT = int(os.getenv('SQL_PORT'))
# SSH credentials for bot status checking
BOTS_SSH_HOST = os.getenv('BOT-SRV-HOST')
WEB1_SSH_HOST = os.getenv('WEB-HOST')
SQL_SSH_HOST = os.getenv('SQL-HOST')
WEBSOCKET_SSH_HOST = os.getenv('WEBSOCKET-HOST')
SSH_USERNAME = os.getenv('SSH_USERNAME')
SSH_PASSWORD = os.getenv('SSH_PASSWORD')

# Validate required database environment variables
if not all([SQL_HOST, SQL_USER, SQL_PASSWORD]):
    missing_vars = [var for var, val in [('SQL_HOST', SQL_HOST), ('SQL_USER', SQL_USER), ('SQL_PASSWORD', SQL_PASSWORD)] if not val]
    logging.error(f"Missing required database environment variables: {missing_vars}")
    raise ValueError(f"Missing required database environment variables: {missing_vars}")

ADMIN_KEY = os.getenv('ADMIN_KEY')
WEATHER_API = os.getenv('WEATHER_API')
STEAM_API = os.getenv('STEAM_API')
TWITCH_OAUTH_API_TOKEN = os.getenv('TWITCH_OAUTH_API_TOKEN')
TWITCH_OAUTH_API_CLIENT_ID = os.getenv('TWITCH_OAUTH_API_CLIENT_ID')
CLIENT_ID = os.getenv('CLIENT_ID')
CLIENT_SECRET = os.getenv('CLIENT_SECRET')
DISCORD_TWITCH_LINK_BASE_URL = os.getenv('DISCORD_TWITCH_LINK_BASE_URL', 'https://botofthespecter.com/discord_twitch_link.php')
DISCORD_TWITCH_LINK_TOKEN_TTL_MINUTES = int(os.getenv('DISCORD_TWITCH_LINK_TOKEN_TTL_MINUTES', '30'))

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

# Local IP whitelist (reads /home/botofthespecter/ips.txt)
_ips_file = "/home/botofthespecter/ips.txt"
_allowed_ip_networks = []
_ips_file_mtime = 0

VALID_PERMISSIONS = {"everyone", "vip", "all-subs", "t1-sub", "t2-sub", "t3-sub", "mod", "broadcaster"}

def _load_allowed_ips():
    global _allowed_ip_networks, _ips_file_mtime
    try:
        mtime = os.path.getmtime(_ips_file)
        if _allowed_ip_networks and mtime == _ips_file_mtime:
            return
        allowed = []
        with open(_ips_file, 'r') as fh:
            for line in fh:
                line = line.strip()
                if not line or line.startswith('#'):
                    continue
                try:
                    allowed.append(ipaddress.ip_network(line))
                except ValueError:
                    logger.warning(f"Invalid IP/network in {_ips_file}: {line}")
        _allowed_ip_networks = allowed
        _ips_file_mtime = mtime
        logger.info(f"Loaded {_ips_file} with {len(_allowed_ip_networks)} networks")
    except FileNotFoundError:
        _allowed_ip_networks = []
    except Exception:
        logger.exception("Error loading ips file")

def _is_ip_allowed(ip: str) -> bool:
    try:
        _load_allowed_ips()
        addr = ipaddress.ip_address(ip)
        for net in _allowed_ip_networks:
            if addr in net:
                return True
    except Exception:
        # Invalid IP format or load error -> treat as not allowed
        pass
    return False

def _format_duration(seconds: int) -> str:
    if seconds < 0:
        seconds = 0
    parts = []
    days, rem = divmod(seconds, 86400)
    if days:
        parts.append(f"{days} day{'s' if days != 1 else ''}")
    hours, rem = divmod(rem, 3600)
    if hours:
        parts.append(f"{hours} hour{'s' if hours != 1 else ''}")
    minutes, secs = divmod(rem, 60)
    if minutes:
        parts.append(f"{minutes} minute{'s' if minutes != 1 else ''}")
    parts.append(f"{secs} second{'s' if secs != 1 else ''}")
    return ", ".join(parts)

async def _read_uptime_marker_via_ssh(host: str, username: str, password: str, marker_path: str, server_label: str) -> dict | None:
    if not all([host, username, password]):
        return None
    timeout_seconds = int(os.getenv('SSH_CONNECT_TIMEOUT', '8'))
    try:
        logging.info(f"Attempting SSH to {host} as {username} to stat {marker_path} ({server_label})")
        async with asyncssh.connect(
            host, username=username, password=password,
            known_hosts=None, connect_timeout=timeout_seconds,
        ) as conn:
            async with conn.start_sftp_client() as sftp:
                st = await sftp.stat(marker_path)
                started_at_dt = datetime.fromtimestamp(st.mtime)
                uptime_seconds = int((datetime.now() - started_at_dt).total_seconds())
                result = {
                    "uptime": _format_duration(uptime_seconds),
                    "started_at": started_at_dt.strftime('%Y-%m-%d %H:%M:%S')
                }
                logging.info(f"Successfully read {server_label} uptime marker (started_at={result['started_at']})")
                return result
    except Exception as exc:
        logging.error(f"Error fetching {server_label} uptime via SSH ({host}): {exc}")
        return None

# In-memory cache for the Twitch app token from bot_chat_token
_twitch_app_creds_cache: dict = {"loaded_at": 0.0, "access_token": None, "client_id": None}
_TWITCH_APP_CREDS_TTL = 60  # seconds

async def get_twitch_app_credentials() -> dict | None:
    global _twitch_app_creds_cache
    import time as _time
    now = _time.time()
    if (now - _twitch_app_creds_cache["loaded_at"]) < _TWITCH_APP_CREDS_TTL and _twitch_app_creds_cache["access_token"]:
        return _twitch_app_creds_cache
    access_token = None
    client_id = None
    try:
        conn = await get_mysql_connection()
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT * FROM bot_chat_token ORDER BY id ASC LIMIT 1")
                row = await cur.fetchone()
                if row:
                    for key in ("twitch_oauth_api_token", "oauth", "chat_oauth_token", "twitch_oauth_token", "twitch_access_token", "bot_oauth_token"):
                        if row.get(key):
                            access_token = str(row[key]).strip()
                            break
                    for key in ("twitch_client_id", "client_id", "clientID"):
                        if row.get(key):
                            client_id = str(row[key]).strip()
                            break
        finally:
            conn.close()
    except Exception as e:
        logging.warning(f"Failed to fetch Twitch app credentials from bot_chat_token: {e}")
    if not access_token:
        return None
    # bot_chat_token may not have a client_id column; fall back to CLIENT_ID env var
    if not client_id:
        client_id = CLIENT_ID
    if not client_id:
        return None
    _twitch_app_creds_cache = {"loaded_at": now, "access_token": access_token, "client_id": client_id}
    return _twitch_app_creds_cache

async def _get_user_twitch_auth(username: str) -> dict | None:
    conn = await get_mysql_connection()
    try:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(
                """
                SELECT u.twitch_user_id, t.twitch_access_token, t.updated_at
                FROM users u
                LEFT JOIN twitch_bot_access t ON u.twitch_user_id = t.twitch_user_id
                WHERE u.username = %s
                """,
                (username,)
            )
            row = await cur.fetchone()
            if not row or not row.get("twitch_user_id") or not row.get("twitch_access_token"):
                return None
            return {
                "access_token": row["twitch_access_token"],
                "twitch_user_id": str(row["twitch_user_id"]),
                "updated_at": row.get("updated_at"),
            }
    finally:
        conn.close()

def _twitch_token_is_stale(updated_at) -> bool:
    if updated_at is None:
        return True
    try:
        return (datetime.now() - updated_at) >= timedelta(hours=4)
    except Exception:
        return True

async def _get_twitch_profile_images(logins) -> dict:
    if not logins:
        return {}
    unique_logins = list({(l or "").strip().lower() for l in logins if l and (l or "").strip()})
    if not unique_logins:
        return {}
    app_creds = await get_twitch_app_credentials()
    if not app_creds:
        return {}
    headers = {
        "Client-ID": app_creds["client_id"],
        "Authorization": f"Bearer {app_creds['access_token']}",
    }
    result = {}
    chunk_size = 100
    try:
        async with aiohttp.ClientSession() as session:
            for i in range(0, len(unique_logins), chunk_size):
                chunk = unique_logins[i:i + chunk_size]
                params = [("login", l) for l in chunk]
                async with session.get(
                    "https://api.twitch.tv/helix/users",
                    headers=headers,
                    params=params,
                ) as resp:
                    if resp.status != 200:
                        continue
                    body = await resp.json(content_type=None)
                    for entry in (body or {}).get("data", []) or []:
                        login = (entry.get("login") or "").lower()
                        image = entry.get("profile_image_url")
                        if login and image:
                            result[login] = image
    except Exception as e:
        logging.warning(f"Twitch profile image lookup failed: {e}")
    return result

# Bot launch / control helpers
BOT_SCRIPT_PATHS = {
    "stable": "/home/botofthespecter/bot.py",
    "beta": "/home/botofthespecter/beta.py",
}
BOT_VERSION_FILE_TEMPLATES = {
    "stable": "/home/botofthespecter/logs/version/{username}_version_control.txt",
    "beta": "/home/botofthespecter/logs/version/beta/{username}_beta_version_control.txt",
}
BOT_STATUS_SCRIPT = "/home/botofthespecter/status.py"

async def _get_user_bot_launch_credentials(username: str) -> dict | None:
    conn = await get_mysql_connection()
    try:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(
                """
                SELECT id, twitch_user_id, access_token, refresh_token, api_key, use_custom, use_self
                FROM users
                WHERE username = %s
                LIMIT 1
                """,
                (username,)
            )
            row = await cur.fetchone()
            if not row:
                return None
            return {
                "user_id": str(row.get("id") or ""),
                "twitch_user_id": str(row.get("twitch_user_id") or ""),
                "access_token": (row.get("access_token") or "").strip(),
                "refresh_token": (row.get("refresh_token") or "").strip(),
                "api_key": (row.get("api_key") or "").strip(),
                "use_custom": int(row.get("use_custom") or 0) == 1,
                "use_self": int(row.get("use_self") or 0) == 1,
            }
    finally:
        conn.close()

async def _refresh_twitch_user_token(twitch_user_id: str, refresh_token: str) -> tuple:
    if not (CLIENT_ID and CLIENT_SECRET and refresh_token):
        return None, None
    try:
        async with aiohttp.ClientSession() as session:
            async with session.post(
                "https://id.twitch.tv/oauth2/token",
                data={
                    "client_id": CLIENT_ID,
                    "client_secret": CLIENT_SECRET,
                    "grant_type": "refresh_token",
                    "refresh_token": refresh_token,
                },
                timeout=aiohttp.ClientTimeout(total=15),
            ) as resp:
                if resp.status != 200:
                    body = await resp.text()
                    logging.warning(f"Twitch token refresh failed for twitch_user_id={twitch_user_id}: HTTP {resp.status}: {body[:200]}")
                    return None, None
                payload = await resp.json(content_type=None)
                new_access = payload.get("access_token")
                new_refresh = payload.get("refresh_token") or refresh_token
                if not new_access:
                    return None, None
                conn = await get_mysql_connection()
                try:
                    async with conn.cursor() as cur:
                        await cur.execute(
                            "UPDATE users SET access_token = %s, refresh_token = %s WHERE twitch_user_id = %s",
                            (new_access, new_refresh, twitch_user_id),
                        )
                    await conn.commit()
                finally:
                    conn.close()
                return new_access, new_refresh
    except Exception as e:
        logging.error(f"Twitch token refresh exception for twitch_user_id={twitch_user_id}: {e}")
        return None, None

async def _ensure_fresh_twitch_token(twitch_user_id: str, access_token: str, refresh_token: str) -> tuple:
    if access_token:
        try:
            async with aiohttp.ClientSession() as session:
                async with session.get(
                    "https://id.twitch.tv/oauth2/validate",
                    headers={"Authorization": f"OAuth {access_token}"},
                    timeout=aiohttp.ClientTimeout(total=10),
                ) as resp:
                    if resp.status == 200:
                        return access_token, refresh_token
        except Exception as e:
            logging.warning(f"Twitch token validate failed for twitch_user_id={twitch_user_id}: {e}")
    return await _refresh_twitch_user_token(twitch_user_id, refresh_token)

async def _check_bot_pid(conn, bot_type: str, username: str, command_timeout: int) -> int | None:
    result = await asyncio.wait_for(
        conn.run(f"python {BOT_STATUS_SCRIPT} -system {bot_type} -channel {username}"),
        timeout=command_timeout,
    )
    output = (result.stdout or "").strip()
    m = re.search(r'process ID:\s*(\d+)', output, re.IGNORECASE) or re.search(r'PID\s+(\d+)', output, re.IGNORECASE)
    if m:
        pid = int(m.group(1))
        return pid if pid > 0 else None
    return None

async def _read_version_file(conn, version_file: str, command_timeout: int) -> str | None:
    try:
        result = await asyncio.wait_for(
            conn.run(f"cat {shlex.quote(version_file)}"),
            timeout=command_timeout,
        )
        v = (result.stdout or "").strip()
        return v or None
    except Exception:
        return None

async def _get_user_custom_bot_params(user_id: str, twitch_user_id: str, use_custom: bool, use_self: bool) -> dict:
    params = {"use_custom_bot": False, "custom_bot_username": None, "use_self": bool(use_self)}
    if not use_custom:
        return params
    lookup_id = (user_id or "").strip()
    legacy_id = (twitch_user_id or "").strip()
    if not lookup_id and not legacy_id:
        return params
    conn = await get_mysql_connection()
    try:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            row = None
            if lookup_id:
                await cur.execute(
                    "SELECT bot_username, is_verified FROM custom_bots WHERE channel_id = %s LIMIT 1",
                    (lookup_id,),
                )
                row = await cur.fetchone()
            if not row and legacy_id:
                await cur.execute(
                    "SELECT bot_username, is_verified FROM custom_bots WHERE channel_id = %s LIMIT 1",
                    (legacy_id,),
                )
                row = await cur.fetchone()
    finally:
        conn.close()
    if not row:
        logging.warning(f"_get_user_custom_bot_params: no custom_bots row for channel_id={lookup_id or legacy_id}")
        return params
    if int(row.get("is_verified") or 0) != 1:
        logging.warning(f"_get_user_custom_bot_params: custom bot exists but not verified for channel_id={lookup_id or legacy_id}")
        return params
    bot_username = (row.get("bot_username") or "").strip()
    if not bot_username:
        logging.warning(f"_get_user_custom_bot_params: bot_username empty for channel_id={lookup_id or legacy_id}")
        return params
    params["use_custom_bot"] = True
    params["custom_bot_username"] = bot_username
    params["use_self"] = False
    return params

# Process start time for basic uptime reporting
_process_start_time = datetime.now()

# In-memory per-IP timestamp store for /system/uptime rate limiting
_uptime_requests = {}  # ip -> last_request_epoch_seconds
_uptime_lock = asyncio.Lock()  # protect access to _uptime_requests
_uptime_rate_limit_seconds = max(1, int(os.getenv('SYSTEM_UPTIME_RATE_LIMIT_SECONDS', '300')))

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
    {
        "name": "Extension",
        "description": "Read-only endpoints for the Twitch Extension. No API key required. Uses the broadcaster's Twitch channel ID to identify the channel.",
    },
    {
        "name": "Channel",
        "description": "Endpoints that act on the authenticated broadcaster's Twitch channel. Requires user API key and a valid Twitch user access token with the necessary scopes.",
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
    # Ensure the admin-managed custom webhooks table exists
    await ensure_custom_webhooks_table()
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
    docs_url=None,
    redoc_url=None,
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

# v2 header-based API key auth compatibility layer.
#
# All authenticated endpoints are auto-mapped to /v2/<path> with X-API-KEY header auth.
# The only v1-style paths kept in v2 docs are those listed in _V2_PUBLIC_PATHS (no auth)
# and _V2_WEBHOOK_PATHS (external services that must keep api_key in the URL).
_v2_api_key_required_paths_cache: set | None = None

def _get_v2_api_key_required_paths() -> set:
    global _v2_api_key_required_paths_cache
    if _v2_api_key_required_paths_cache is not None:
        return _v2_api_key_required_paths_cache
    excluded = _V2_PUBLIC_PATHS_SET | _V2_WEBHOOK_PATHS_SET | _V2_DOCS_PATHS
    paths = set()
    for route in app.routes:
        path = getattr(route, "path", None)
        if not path:
            continue
        if not getattr(route, "include_in_schema", True):
            continue
        if path.startswith("/v2"):
            continue
        if path in excluded:
            continue
        paths.add(path)
    _v2_api_key_required_paths_cache = paths
    return paths


def _sanitize_user_command_name(command: str) -> str:
    lowered = (command or "").replace(" ", "").lower()
    return "".join(ch for ch in lowered if ("a" <= ch <= "z") or ch.isdigit())

_V2_PUBLIC_PATHS = [
    "/freestuff/games",
    "/freestuff/latest",
    "/versions",
    "/commands/info",
    "/heartbeat/websocket",
    "/heartbeat/api",
    "/heartbeat/database",
    "/system/uptime",
    "/chat-instructions",
    "/api/song",
    "/api/exchangerate",
    "/api/weather",
    "/api/steamapplist",
    "/extension/commands",
    "/extension/deaths",
    "/extension/quotes",
    "/extension/typos",
    "/extension/lurkers",
    "/extension/hugs",
    "/extension/kisses",
    "/extension/highfives",
    "/extension/custom-counts",
    "/extension/user-counts",
    "/extension/reward-counts",
    "/extension/watch-time",
    "/extension/todos",
]
_V2_PUBLIC_PATHS_SET = set(_V2_PUBLIC_PATHS)

_V2_WEBHOOK_PATHS = [
    "/fourthwall",
    "/kofi",
    "/patreon",
    "/github",
    "/freestuff",
    "/kick/{username}"
]
_V2_WEBHOOK_PATHS_SET = set(_V2_WEBHOOK_PATHS)

_V2_DOCS_PATHS = {
    "/v2/docs",
    "/v2/openapi.json",
    "/v2/redoc",
}

_V2_OPENAPI_VERSION = "2.0.0"
_V2_OPENAPI_DESCRIPTION = (
    "API Endpoints for BotOfTheSpecter \n\n"
    "Authentication:\n"
    "- Use header X-API-KEY for authenticated /v2 endpoints.\n"
    "- Query api_key is rejected on /v2 endpoints.\n"
    "- Webhooks remain on existing non-v2 paths and auth flow."
)

@app.middleware("http")
async def v2_api_key_header_middleware(request: Request, call_next):
    # CORS preflight (OPTIONS) carries no custom headers (e.g. X-API-KEY) and must never
    # require auth -- pass it straight through so CORSMiddleware answers the preflight.
    # The subsequent real request still requires X-API-KEY below.
    if request.method == "OPTIONS":
        return await call_next(request)
    path = request.scope.get("path", "")
    if path in _V2_DOCS_PATHS:
        return await call_next(request)
    if not path.startswith("/v2/"):
        return await call_next(request)
    legacy_path = path[3:]
    if legacy_path in _V2_PUBLIC_PATHS_SET:
        request.scope["path"] = legacy_path
        return await call_next(request)
    if legacy_path not in _get_v2_api_key_required_paths():
        return JSONResponse(
            status_code=404,
            content={"detail": "v2 endpoint not found"},
        )
    header_api_key = request.headers.get("X-API-KEY")
    if not header_api_key:
        return JSONResponse(
            status_code=401,
            content={"detail": "Missing X-API-KEY header"},
        )
    query_items = parse_qsl(
        request.scope.get("query_string", b"").decode("latin-1"),
        keep_blank_values=True,
    )
    if any(key.lower() == "api_key" for key, _ in query_items):
        return JSONResponse(
            status_code=400,
            content={"detail": "Use X-API-KEY header for v2 endpoints"},
        )
    query_items.append(("api_key", header_api_key))
    request.scope["path"] = legacy_path
    request.scope["query_string"] = urlencode(query_items, doseq=True).encode("latin-1")
    return await call_next(request)

_OPENAPI_HTTP_METHODS = {"get", "put", "post", "delete", "patch", "options", "head", "trace"}

def _ensure_standard_openapi_responses(openapi_schema: dict) -> dict:
    components = openapi_schema.setdefault("components", {})
    schemas = components.setdefault("schemas", {})
    schemas.setdefault("ValidationError", {
        "title": "ValidationError",
        "required": ["loc", "msg", "type"],
        "type": "object",
        "properties": {
            "loc": {
                "title": "Location",
                "type": "array",
                "items": {"anyOf": [{"type": "string"}, {"type": "integer"}]},
            },
            "msg": {"title": "Message", "type": "string"},
            "type": {"title": "Error Type", "type": "string"},
        },
    })
    schemas.setdefault("HTTPValidationError", {
        "title": "HTTPValidationError",
        "type": "object",
        "properties": {
            "detail": {
                "title": "Detail",
                "type": "array",
                "items": {"$ref": "#/components/schemas/ValidationError"},
            }
        },
    })
    schemas.setdefault("StandardError", {
        "title": "StandardError",
        "type": "object",
        "required": ["detail"],
        "properties": {
            "detail": {
                "title": "Detail",
                "type": "string",
                "example": "Error message describing what went wrong",
            }
        },
        "example": {"detail": "Error message describing what went wrong"},
    })
    schemas.setdefault("StandardSuccessResponse", {
        "title": "StandardSuccessResponse",
        "type": "object",
        "description": "Generic JSON response payload.",
        "additionalProperties": True,
        "example": {"status": "success"},
    })
    error_ref = {"application/json": {"schema": {"$ref": "#/components/schemas/StandardError"}}}
    success_ref = {"application/json": {"schema": {"$ref": "#/components/schemas/StandardSuccessResponse"}}}

    def _response_has_schema(resp: dict) -> bool:
        if not isinstance(resp, dict):
            return False
        content = resp.get("content")
        if not isinstance(content, dict):
            return False
        for media in content.values():
            if isinstance(media, dict) and media.get("schema"):
                return True
        return False

    paths = openapi_schema.get("paths", {})
    for path_item in paths.values():
        if not isinstance(path_item, dict):
            continue
        for method, operation in path_item.items():
            if method.lower() not in _OPENAPI_HTTP_METHODS or not isinstance(operation, dict):
                continue
            responses = operation.setdefault("responses", {})
            success_codes = [c for c in list(responses.keys()) if str(c).isdigit() and str(c).startswith("2")]
            if not success_codes:
                responses["200"] = {"description": "Successful Response", "content": success_ref}
            else:
                for code in success_codes:
                    resp = responses.get(code)
                    if isinstance(resp, dict) and not _response_has_schema(resp):
                        resp.setdefault("description", "Successful Response")
                        resp["content"] = success_ref
            for code, description in (
                ("400", "Bad Request"),
                ("401", "Unauthorized"),
                ("404", "Not Found"),
                ("500", "Internal Server Error"),
            ):
                existing = responses.get(code)
                if existing is None:
                    responses[code] = {"description": description, "content": error_ref}
                elif isinstance(existing, dict) and not _response_has_schema(existing):
                    existing.setdefault("description", description)
                    existing["content"] = error_ref
            responses.setdefault("422", {
                "description": "Validation Error",
                "content": {
                    "application/json": {
                        "schema": {"$ref": "#/components/schemas/HTTPValidationError"}
                    }
                },
            })
    return openapi_schema

def custom_openapi():
    if app.openapi_schema:
        return app.openapi_schema
    openapi_schema = get_openapi(
        title=app.title,
        version=app.version,
        description=app.description,
        routes=app.routes,
        tags=tags_metadata,
    )
    openapi_schema.setdefault("info", {})["termsOfService"] = "https://botofthespecter.com/terms-of-service.php"
    openapi_schema["info"]["contact"] = {
        "name": "BotOfTheSpecter",
        "url": "https://botofthespecter.com/",
        "email": "questions@botofthespecter.com",
    }
    app.openapi_schema = _ensure_standard_openapi_responses(openapi_schema)
    return app.openapi_schema

app.openapi = custom_openapi

def build_v2_openapi_schema():
    openapi_schema = get_openapi(
        title=f"{app.title} v2",
        version=_V2_OPENAPI_VERSION,
        description=_V2_OPENAPI_DESCRIPTION,
        routes=app.routes,
        tags=tags_metadata,
    )
    openapi_schema.setdefault("info", {})["termsOfService"] = "https://botofthespecter.com/terms-of-service.php"
    openapi_schema["info"]["contact"] = {
        "name": "BotOfTheSpecter",
        "url": "https://botofthespecter.com/",
        "email": "questions@botofthespecter.com",
    }
    paths = openapi_schema.setdefault("paths", {})
    for legacy_path in _V2_WEBHOOK_PATHS:
        if legacy_path not in paths:
            continue
        webhook_operations = {}
        for method, operation in paths[legacy_path].items():
            if method.startswith("x-"):
                continue
            v2_operation = copy.deepcopy(operation)
            operation_id = v2_operation.get("operationId", f"{method}_{legacy_path.strip('/').replace('/', '_')}")
            v2_operation["operationId"] = f"v2_{operation_id}"
            webhook_operations[method] = v2_operation
        paths[legacy_path] = webhook_operations
    for legacy_path in _V2_PUBLIC_PATHS:
        if legacy_path not in paths:
            continue
        public_operations = {}
        for method, operation in paths[legacy_path].items():
            if method.startswith("x-"):
                continue
            v2_operation = copy.deepcopy(operation)
            operation_id = v2_operation.get("operationId", f"{method}_{legacy_path.strip('/').replace('/', '_')}")
            v2_operation["operationId"] = f"v2_{operation_id}"
            public_operations[method] = v2_operation
        paths[legacy_path] = public_operations
    for legacy_path in _get_v2_api_key_required_paths():
        if legacy_path not in paths:
            continue
        v2_path = f"/v2{legacy_path}"
        v2_operations = {}
        for method, operation in paths[legacy_path].items():
            if method.startswith("x-"):
                continue
            v2_operation = copy.deepcopy(operation)
            operation_id = v2_operation.get("operationId", f"{method}_{legacy_path.strip('/').replace('/', '_')}")
            v2_operation["operationId"] = f"v2_{operation_id}"
            params = v2_operation.get("parameters", [])
            params = [
                p for p in params
                if not (p.get("in") == "query" and p.get("name") == "api_key")
            ]
            params.insert(0, {
                "name": "X-API-KEY",
                "in": "header",
                "required": True,
                "schema": {"type": "string"},
                "description": "API key for v2 endpoint authentication",
            })
            v2_operation["parameters"] = params
            v2_operations[method] = v2_operation
        paths[v2_path] = v2_operations
    for path_to_remove in list(paths.keys()):
        if (
            path_to_remove not in _V2_DOCS_PATHS
            and not path_to_remove.startswith("/v2")
            and path_to_remove not in _V2_PUBLIC_PATHS_SET
            and path_to_remove not in _V2_WEBHOOK_PATHS_SET
        ):
            paths.pop(path_to_remove, None)
    return _ensure_standard_openapi_responses(openapi_schema)

@app.get("/docs", include_in_schema=False)
@app.get("/v1/docs", include_in_schema=False)
async def docs_v1_redirect():
    resp = get_swagger_ui_html(
        openapi_url="/openapi.json",
        title=f"{app.title} - v1 Docs",
        swagger_ui_parameters={"defaultModelsExpandDepth": -1},
    )
    try:
        body = resp.body.decode("utf-8")
    except Exception:
        return resp
    insert_html = (
        '<div style="padding:8px 16px;text-align:right;background:#fafafa;border-bottom:1px solid #eee;">'
        '<a href="/v2/docs" style="display:inline-block;padding:6px 10px;border-radius:4px;border:1px solid #ddd;background:#fff;color:#111;text-decoration:none;font-weight:600;">Switch to V2</a>'
        '</div>'
    )
    if "<body" in body:
        body = body.replace("<body>", "<body>" + insert_html, 1)
    else:
        body = insert_html + body
    resp.body = body.encode("utf-8")
    resp.headers["content-length"] = str(len(resp.body))
    return resp

@app.get("/v1/openapi.json", include_in_schema=False)
async def openapi_v1_redirect():
    return RedirectResponse(url="/openapi.json")

@app.get("/v1/redoc", include_in_schema=False)
async def redoc_v1_redirect():
    return RedirectResponse(url="/docs")

@app.get("/v2/openapi.json", include_in_schema=False)
async def openapi_v2():
    return JSONResponse(content=build_v2_openapi_schema())

@app.get("/v2/docs", include_in_schema=False)
async def docs_v2():
    resp = get_swagger_ui_html(
        openapi_url="/v2/openapi.json",
        title=f"{app.title} - v2 Docs",
        swagger_ui_parameters={"defaultModelsExpandDepth": -1},
    )
    try:
        body = resp.body.decode("utf-8")
    except Exception:
        return resp
    insert_html = (
        '<div style="padding:8px 16px;text-align:right;background:#fafafa;border-bottom:1px solid #eee;">'
        '<a href="/v1/docs" style="display:inline-block;padding:6px 10px;border-radius:4px;border:1px solid #ddd;background:#fff;color:#111;text-decoration:none;font-weight:600;">Switch back to V1</a>'
        '</div>'
    )
    if "<body" in body:
        body = body.replace("<body>", "<body>" + insert_html, 1)
    else:
        body = insert_html + body
    resp.body = body.encode("utf-8")
    resp.headers["content-length"] = str(len(resp.body))
    return resp

@app.get("/v2/redoc", include_in_schema=False)
async def redoc_v2():
    resp = get_redoc_html(
        openapi_url="/v2/openapi.json",
        title=f"{app.title} - v2 ReDoc",
    )
    try:
        body = resp.body.decode("utf-8")
    except Exception:
        return resp
    insert_html = (
        '<div style="padding:8px 16px;text-align:right;background:#fafafa;border-bottom:1px solid #eee;">'
        '<a href="/v1/docs" style="display:inline-block;padding:6px 10px;border-radius:4px;border:1px solid #ddd;background:#fff;color:#111;text-decoration:none;font-weight:600;">Switch back to V1</a>'
        '</div>'
    )
    if "<body" in body:
        body = body.replace("<body>", "<body>" + insert_html, 1)
    else:
        body = insert_html + body
    resp.body = body.encode("utf-8")
    resp.headers["content-length"] = str(len(resp.body))
    return resp

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

# Get User info from DB using user_id
async def get_user_info(user_id):
    conn = await get_mysql_connection()
    try:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute("SELECT * FROM users WHERE twitch_user_id=%s", (user_id,))
            result = await cur.fetchone()
            return result  # Returns a dictionary of user info or None if not found
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
    if username_param:
        requested = username_param.strip().lower()
        authenticated = str(key_info["username"]).strip().lower()
        if requested and requested != authenticated:
            raise HTTPException(
                status_code=403,
                detail="The 'channel' parameter is only allowed for admin API keys"
            )
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
    kick_bot: str
    class Config:
        json_schema_extra = {
            "example": {
                "beta_version": "5.5",
                "stable_version": "5.4",
                "discord_bot": "5.3.0",
                "kick_bot": "1.0.0"
            }
        }

# Define the response model for Builtin Commands Info
class BuiltinCommandsResponse(BaseModel):
    commands: Dict
    class Config:
        json_schema_extra = {
            "example": {
                "commands": {
                    "ping": {
                        "description": "Check bot responsiveness",
                        "aliases": ["pong"]
                    },
                    "version": {
                        "description": "Display bot version"
                    }
                }
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

class StatusResponse(BaseModel):
    status: str
    class Config:
        json_schema_extra = {
            "example": {
                "status": "success",
            }
        }

class UptimeLocalReadResponse(BaseModel):
    uptime: str
    started_at: str

class UptimeSectionResponse(BaseModel):
    local_read: UptimeLocalReadResponse = Field(..., alias="Local Read")
    local_metrics_script: Dict[str, Any] | None = Field(None, alias="Local Metrics Script")
    external_api_metrics: Dict[str, Any] | None = Field(None, alias="External API Metrics")
    class Config:
        allow_population_by_field_name = True
        extra = "allow"

class SystemUptimeResponse(BaseModel):
    API: UptimeSectionResponse
    WEBSOCKET: UptimeSectionResponse
    WEB1: UptimeSectionResponse | None = None
    SQL: UptimeSectionResponse | None = None
    BOTS: UptimeSectionResponse | None = None
    class Config:
        extra = "allow"
        json_schema_extra = {
            "example": {
                "API": {
                    "Local Read": {
                        "uptime": "4 minutes, 48 seconds",
                        "started_at": "2026-02-17 22:09:19"
                    },
                    "Local Metrics Script": {
                        "cpu_percent": 35.0,
                        "ram_percent": 49.7
                    }
                },
                "WEBSOCKET": {
                    "Local Read": {
                        "uptime": "15 hours, 45 minutes, 14 seconds",
                        "started_at": "2026-02-17 06:28:55"
                    }
                }
            }
        }

# Define the response model for Bot Status
class BotStatusResponse(BaseModel):
    running: bool
    pid: int | None = None
    version: str | None = None
    bot_type: str | None = None
    outdated: bool | None = None
    latest_version: str | None = None
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

STEAM_APP_LIST_CACHE_TTL_SECONDS = max(60, int(os.getenv('STEAM_APP_LIST_CACHE_TTL_SECONDS', '3600')))
STEAM_APP_LIST_CACHE_PATHS = [
    "/home/botofthespecter/steamapplist.json",
]
_steam_app_list_cache: Dict[str, int] = {}
_steam_app_list_cache_loaded_at = datetime.fromtimestamp(0, tz=timezone.utc)

# Versions file paths (with fallback)
VERSIONS_FILE_PATHS = [
    "/home/botofthespecter/versions.json",
    "/home/fastapi/versions.json",
]

def _normalize_steam_app_list(payload: Any) -> Dict[str, int]:
    if not isinstance(payload, dict):
        return {}
    if "response" in payload:
        apps = ((payload.get("response") or {}).get("apps") or [])
        return {
            str(app.get("name", "")).lower(): int(app.get("appid"))
            for app in apps
            if app.get("name") and app.get("appid")
        }
    if "applist" in payload:
        applist = payload.get("applist") or {}
        apps = applist.get("apps") or []
        if isinstance(apps, dict):
            apps = apps.get("app") or []
        return {
            str(app.get("name", "")).lower(): int(app.get("appid"))
            for app in apps
            if app.get("name") and app.get("appid")
        }
    return {
        str(name).lower(): int(appid)
        for name, appid in payload.items()
        if name and appid is not None
    }

def _load_steam_app_list_from_disk(now_utc: datetime, allow_expired: bool = False) -> Dict[str, int]:
    for cache_path in STEAM_APP_LIST_CACHE_PATHS:
        try:
            if not os.path.exists(cache_path):
                continue
            age = now_utc.timestamp() - os.path.getmtime(cache_path)
            if not allow_expired and age > STEAM_APP_LIST_CACHE_TTL_SECONDS:
                continue
            with open(cache_path, "r", encoding="utf-8") as cache_file:
                cached_payload = json.load(cache_file)
            normalized = _normalize_steam_app_list(cached_payload)
            if normalized:
                return normalized
        except Exception as exc:
            logging.warning(f"Failed reading Steam cache file {cache_path}: {exc}")
    return {}

def _save_steam_app_list_to_disk(app_map: Dict[str, int]):
    for cache_path in STEAM_APP_LIST_CACHE_PATHS:
        try:
            cache_dir = os.path.dirname(cache_path)
            if cache_dir:
                os.makedirs(cache_dir, exist_ok=True)
            with open(cache_path, "w", encoding="utf-8") as cache_file:
                json.dump(app_map, cache_file)
            return
        except Exception as exc:
            logging.warning(f"Failed writing Steam cache file {cache_path}: {exc}")

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

class CheckKeyResponse(BaseModel):
    status: str
    # Optional — only set when the key validates. The invalid-key branch
    # returns just `status` so the model must allow that shape.
    username: Optional[str] = None
    class Config:
        json_schema_extra = {
            "example": {
                "status": "Valid API Key",
                "username": "testuser"
            }
        }

class StreamOnlineResponse(BaseModel):
    channel: str
    online: bool
    stream_title: str | None = None
    game_name: str | None = None
    class Config:
        json_schema_extra = {
            "example": {
                "channel": "testuser",
                "online": True,
                "stream_title": "Road to Partner - Chill Coding",
                "game_name": "Software and Game Development"
            }
        }

# Define the response model for Death Counter
class DeathCountResponse(BaseModel):
    game: str | None = None
    game_deaths: int = 0
    stream_deaths: int = 0
    total_deaths: int = 0
    class Config:
        json_schema_extra = {"example": {"game": "Elden Ring", "game_deaths": 12, "stream_deaths": 3, "total_deaths": 47}}

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
    app_password_set: bool = False

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
                "data": json.dumps(webhook_data)
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
                "data": json.dumps(kofi_data)
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
                "data": json.dumps(webhook_data)
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
                # Normalize products list (supports different webhook shapes)
                data = webhook_data.get("data", {}) or {}
                products = []
                if isinstance(data, dict) and isinstance(data.get("resolvedProducts"), list):
                    products = data.get("resolvedProducts", [])
                elif isinstance(data, dict) and isinstance(data.get("product"), dict):
                    products = [data.get("product")]
                elif isinstance(data, dict) and ("id" in data or "title" in data):
                    # data itself may be a product
                    products = [data]
                # Fallback: if no products found, try top-level 'product' or 'data' keys
                if not products and isinstance(webhook_data.get("product"), dict):
                    products = [webhook_data.get("product")]
                # Determine a received_at timestamp (prefer webhook timestamp if present)
                received_at = None
                ts = webhook_data.get("timestamp")
                if ts:
                    try:
                        received_at = datetime.fromtimestamp(int(ts) / 1000, tz=timezone.utc)
                    except Exception:
                        try:
                            received_at = datetime.fromtimestamp(int(ts), tz=timezone.utc)
                        except Exception:
                            received_at = None
                if not products:
                    logging.warning("FreeStuff webhook contained no recognized product data")
                    return
                for product in products:
                    try:
                        # Extract fields with sensible fallbacks
                        game_id = product.get("id") or product.get("gameId") or None
                        game_title = product.get("title") or product.get("name") or "Unknown Game"
                        game_org = product.get("store") or (product.get("org") or {}).get("name") or product.get("org") or "FreeStuff"
                        # Thumbnail - prefer images array first then thumbnails map
                        game_thumbnail = ""
                        images = product.get("images") or []
                        if images and isinstance(images, list) and images[0].get("url"):
                            game_thumbnail = images[0].get("url")
                        else:
                            thumbnails = product.get("thumbnails") or {}
                            game_thumbnail = thumbnails.get("steam_library_600x900") or thumbnails.get("org_logo") or thumbnails.get("thumbnail") or ""
                        # URL - support list or dict shapes
                        game_url = ""
                        urls = product.get("urls") or []
                        if isinstance(urls, list) and urls:
                            first = urls[0]
                            if isinstance(first, dict):
                                game_url = first.get("url") or ""
                            else:
                                game_url = first
                        elif isinstance(urls, dict):
                            game_url = urls.get("default") or urls.get("org") or ""
                        # Description - may be a list of localized entries
                        game_description = ""
                        desc = product.get("description")
                        if isinstance(desc, list):
                            # prefer English
                            for d in desc:
                                if d.get("lang") in ("en-US", "en"):
                                    game_description = d.get("text", "")
                                    break
                            if not game_description and desc:
                                game_description = desc[0].get("text", "")
                        elif isinstance(desc, str):
                            game_description = desc
                        # Price - check prices list for oldValue (cents)
                        game_price = "Free"
                        prices = product.get("prices") or []
                        if isinstance(prices, list) and prices:
                            for price in prices:
                                if price and price.get("oldValue"):
                                    try:
                                        game_price = f"Was ${price.get('oldValue')/100:.2f}"
                                        break
                                    except Exception:
                                        pass
                        # Use provided received_at when possible, else NULL (DB default will set now)
                        if received_at:
                            received_at_param = received_at.strftime("%Y-%m-%d %H:%M:%S")
                        else:
                            received_at_param = None
                        # Upsert logic: update if a record with same game_id exists, else insert.
                        existing_id = None
                        if game_id:
                            await cur.execute("SELECT id FROM freestuff_games WHERE game_id = %s LIMIT 1", (game_id,))
                            row = await cur.fetchone()
                            if row:
                                existing_id = row[0]
                        if not existing_id:
                            # Fallback to match on title+org
                            await cur.execute("SELECT id FROM freestuff_games WHERE game_title = %s AND game_org = %s LIMIT 1", (game_title, game_org))
                            row = await cur.fetchone()
                            if row:
                                existing_id = row[0]
                        if existing_id:
                            # Update existing record
                            if received_at_param:
                                await cur.execute(
                                    """
                                    UPDATE freestuff_games SET game_id=%s, game_title=%s, game_org=%s,
                                    game_thumbnail=%s, game_url=%s, game_description=%s, game_price=%s, received_at=%s
                                    WHERE id=%s
                                    """,
                                    (game_id, game_title, game_org, game_thumbnail, game_url, game_description, game_price, received_at_param, existing_id)
                                )
                            else:
                                await cur.execute(
                                    """
                                    UPDATE freestuff_games SET game_id=%s, game_title=%s, game_org=%s,
                                    game_thumbnail=%s, game_url=%s, game_description=%s, game_price=%s
                                    WHERE id=%s
                                    """,
                                    (game_id, game_title, game_org, game_thumbnail, game_url, game_description, game_price, existing_id)
                                )
                        else:
                            # Insert with received_at only if we have a valid timestamp; otherwise let DB default (CURRENT_TIMESTAMP) apply
                            if received_at_param:
                                await cur.execute(
                                    """
                                    INSERT INTO freestuff_games 
                                    (game_id, game_title, game_org, game_thumbnail, game_url, game_description, game_price, received_at)
                                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                                    """,
                                    (game_id, game_title, game_org, game_thumbnail, game_url, game_description, game_price, received_at_param)
                                )
                            else:
                                await cur.execute(
                                    """
                                    INSERT INTO freestuff_games 
                                    (game_id, game_title, game_org, game_thumbnail, game_url, game_description, game_price)
                                    VALUES (%s, %s, %s, %s, %s, %s, %s)
                                    """,
                                    (game_id, game_title, game_org, game_thumbnail, game_url, game_description, game_price)
                                )
                        await conn.commit()
                        logging.info(f"Saved/updated FreeStuff game: {game_title} ({game_org})")
                    except Exception as ie:
                        logging.error(f"Error processing product in FreeStuff webhook: {ie}")
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
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Error saving FreeStuff game to database: {e}")

@app.post(
    "/freestuff",
    summary="Receive and process FreeStuff Webhook Requests",
    description="Receives FreeStuff webhooks (ping, announcements, product updates) and forwards to WebSocket.",
    tags=["Admin Only"],
    status_code=status.HTTP_204_NO_CONTENT,
    operation_id="process_freestuff_webhook",
    include_in_schema=True
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
        # Save game announcement to database (handle multiple announcement event types)
        if event_type and "announcement" in event_type:
            logging.info(f"FreeStuff announcement received (type={event_type}), attempting to save to DB")
            await save_freestuff_game(webhook_data)
        elif event_type == "fsb:event:ping":
            manual = webhook_data.get("data", {}).get("manual", False)
            logging.info(f"FreeStuff ping ({'manual' if manual else 'automatic'})")
            return Response(status_code=204, headers={"X-Client-Library": "BotOfTheSpecter/1.0"})
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
            params = {"code": api_key, "event": "FREESTUFF", "data": json.dumps(webhook_data)}
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
    return Response(status_code=204, headers={"X-Client-Library": "BotOfTheSpecter/1.0"})

# GitHub Webhook Endpoint
@app.post(
    "/github",
    summary="Receive and process GitHub Webhook Requests",
    description="Receives GitHub webhook events and forwards them to the WebSocket server.",
    tags=["Admin Only"],
    status_code=status.HTTP_200_OK,
    operation_id="process_github_webhook",
    include_in_schema=True
)
async def handle_github_webhook(request: Request, api_key: str = Query(...)):
    key_info = await verify_key(api_key, service="GitHub")
    if not key_info or key_info["type"] != "admin":
        raise HTTPException(status_code=401, detail="Invalid Admin API Key")
    github_event = request.headers.get("X-GitHub-Event", "unknown")
    github_delivery = request.headers.get("X-GitHub-Delivery")
    try:
        webhook_data = await request.json()
    except Exception as e:
        logging.error(f"Invalid JSON in GitHub webhook: {e}")
        raise HTTPException(status_code=400, detail="Invalid JSON payload")
    logging.info(f"GitHub: event={github_event} | delivery={github_delivery}")
    async with aiohttp.ClientSession() as session:
        try:
            payload = {
                "event": github_event,
                "delivery": github_delivery,
                "data": webhook_data
            }
            params = {"code": api_key, "event": "GITHUB", "data": json.dumps(payload)}
            encoded_params = urlencode(params)
            url = f"https://websocket.botofthespecter.com/notify?{encoded_params}"
            async with session.get(url, timeout=10) as response:
                if response.status != 200:
                    logging.error(f"WebSocket forward failed: {response.status}")
                    raise HTTPException(status_code=500, detail="Error forwarding to websocket")
        except asyncio.TimeoutError:
            logging.error("Timeout forwarding GitHub event")
            raise HTTPException(status_code=500, detail="Timeout forwarding to websocket")
        except HTTPException:
            raise
        except Exception as e:
            logging.error(f"Error forwarding GitHub: {e}")
            raise HTTPException(status_code=500, detail="Error forwarding to websocket")
    return {"status": "success", "message": "GitHub Webhook received"}

# ---------------------------------------------------------------------------
# Kick.com Webhook Endpoint
# URL: POST /kick/{username}
# Kick posts events here; we verify the signature, look up the user's API key
# by username, then forward the event to the internal WebSocket server so the
# kick bot (which is connected via Socket.IO) can receive it.
# ---------------------------------------------------------------------------

# Kick public-key cache — fetched from Kick's API, refreshed every hour
_kick_pubkey_cache: bytes | None = None
_kick_pubkey_cache_time: float   = 0.0
_KICK_PUBKEY_CACHE_TTL           = 3600  # seconds
_KICK_PUBKEY_URL                 = "https://api.kick.com/public/v1/public-key"

async def _get_kick_public_key() -> bytes | None:
    global _kick_pubkey_cache, _kick_pubkey_cache_time
    now = _time.time()
    if _kick_pubkey_cache and (now - _kick_pubkey_cache_time) < _KICK_PUBKEY_CACHE_TTL:
        return _kick_pubkey_cache
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(_KICK_PUBKEY_URL, timeout=10) as r:
                if r.status == 200:
                    payload   = await r.json()
                    key_b64   = payload.get("public_key") or payload.get("key", "")
                    _kick_pubkey_cache      = base64.b64decode(key_b64)
                    _kick_pubkey_cache_time = now
                    return _kick_pubkey_cache
                logging.error(f"[KICK] Public key fetch failed: HTTP {r.status}")
    except Exception as e:
        logging.error(f"[KICK] Could not fetch public key: {e}")
    return None

async def _verify_kick_signature(message_id: str, timestamp: str, raw_body: bytes, signature_b64: str) -> bool:
    try:
        from cryptography.hazmat.primitives import hashes, serialization
        from cryptography.hazmat.primitives.asymmetric import padding as asym_padding
        from cryptography.exceptions import InvalidSignature
        pubkey_der = await _get_kick_public_key()
        if not pubkey_der:
            logging.warning("[KICK] Public key unavailable — accepting delivery without verification")
            return True   # fail-open; tighten once the key endpoint is confirmed reachable
        public_key   = serialization.load_der_public_key(pubkey_der)
        signed_bytes = f"{message_id}.{timestamp}.".encode() + raw_body
        sig_bytes    = base64.b64decode(signature_b64)
        public_key.verify(sig_bytes, signed_bytes, asym_padding.PKCS1v15(), hashes.SHA256())
        return True
    except InvalidSignature:
        return False
    except Exception as e:
        logging.error(f"[KICK] Signature verification error: {e}")
        return False

async def _get_api_key_for_username(username: str) -> str | None:
    conn = await get_mysql_connection()
    try:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT api_key FROM users WHERE username = %s LIMIT 1",
                (username.lower(),)
            )
            row = await cur.fetchone()
            return row[0] if row else None
    finally:
        conn.close()

async def _get_admin_key_for_service(service: str) -> str | None:
    # Used by global-scope custom webhooks: the service-scoped admin key (created
    # on the API Keys admin page) is the code forwarded to the WebSocket server.
    conn = await get_mysql_connection()
    try:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT api_key FROM admin_api_keys WHERE service = %s LIMIT 1",
                (service,)
            )
            row = await cur.fetchone()
            return row[0] if row else None
    finally:
        conn.close()

# Kick event type (from webhook header) → internal WebSocket event name
_KICK_EVENT_MAP: dict[str, str] = {
    "chat.message.sent":                 "KICK_CHAT",
    "channel.followed":                  "KICK_FOLLOW",
    "channel.subscription.new":          "KICK_SUB",
    "channel.subscription.renewal":      "KICK_RESUB",
    "channel.subscription.gifts":        "KICK_GIFTSUB",
    "channel.reward.redemption.updated": "KICK_REDEMPTION",
    "livestream.status.updated":         "KICK_STREAM_STATUS",
    "livestream.metadata.updated":       "KICK_STREAM_METADATA",
    "moderation.banned":                 "KICK_BAN",
    "kicks.gifted":                      "KICK_KICKS_GIFTED",
}

@app.post(
    "/kick/{username}",
    summary="Receive Kick.com Webhook Events",
    description=(
        "Kick posts real-time events to this endpoint. The channel slug is used as the URL path "
        "so we can route the event to the correct bot instance. The Kick RSA-SHA256 signature is "
        "verified before forwarding the event to the internal WebSocket server."
    ),
    tags=["Webhooks"],
    status_code=status.HTTP_200_OK,
    operation_id="receive_kick_webhook"
)
async def receive_kick_webhook(username: str, request: Request):
    raw_body   = await request.body()
    msg_id     = request.headers.get("Kick-Event-Message-Id",  "")
    timestamp  = request.headers.get("Kick-Event-Timestamp",   "")
    signature  = request.headers.get("Kick-Event-Signature",   "")
    event_type = request.headers.get("Kick-Event-Type",        "")
    if signature:
        valid = await _verify_kick_signature(msg_id, timestamp, raw_body, signature)
        if not valid:
            logging.warning(f"[KICK] Bad signature — channel={username!r} event={event_type!r}")
            raise HTTPException(status_code=403, detail="Invalid Kick webhook signature")
    try:
        payload = json.loads(raw_body)
    except json.JSONDecodeError:
        raise HTTPException(status_code=400, detail="Invalid JSON payload")
    logging.info(f"[KICK] webhook received | channel={username!r} | event={event_type!r}")
    ws_event = _KICK_EVENT_MAP.get(event_type, "KICK_UNKNOWN")
    api_key = await _get_api_key_for_username(username)
    if not api_key:
        # Unknown channel — return 200 so Kick doesn't keep retrying
        logging.warning(f"[KICK] No user found for username={username!r}, ignoring event")
        return {"status": "ok", "note": "channel not registered"}
    async with aiohttp.ClientSession() as session:
        try:
            params = {
                "code":    api_key,
                "event":   ws_event,
                "channel": username,
                "data":    json.dumps(payload),
            }
            url = f"https://websocket.botofthespecter.com/notify?{urlencode(params)}"
            async with session.get(url, timeout=10) as response:
                if response.status != 200:
                    logging.error(f"[KICK] WS forward failed: HTTP {response.status}")
                    raise HTTPException(status_code=500, detail="Error forwarding to WebSocket server")
        except asyncio.TimeoutError:
            logging.error("[KICK] Timeout forwarding event to WebSocket server")
            raise HTTPException(status_code=500, detail="Timeout forwarding to WebSocket server")
        except HTTPException:
            raise
        except Exception as e:
            logging.error(f"[KICK] Unexpected error forwarding event: {e}")
            raise HTTPException(status_code=500, detail="Error forwarding to WebSocket server")
    return {"status": "ok", "event": ws_event}

# ---------------------------------------------------------------------------
# Custom Inbound Webhooks (admin-defined)
# ---------------------------------------------------------------------------
# Admins create inbound webhook receivers from the admin panel (rows in
# website.custom_webhooks). External services POST to /webhook/{slug}; the
# request is verified per the webhook's configured mode (none/secret/hmac) and
# the payload is forwarded to the internal WebSocket server as the configured
# event — routed to a specific channel or to admin global-listeners. This lets a
# new integration go live WITHOUT editing api.py or restarting the API server.
# ---------------------------------------------------------------------------

CUSTOM_WEBHOOKS_TABLE_DDL = """
    CREATE TABLE IF NOT EXISTS custom_webhooks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(64) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        service VARCHAR(64) NOT NULL,
        event_name VARCHAR(64) NOT NULL,
        scope ENUM('channel','global') NOT NULL DEFAULT 'channel',
        target_username VARCHAR(255) NULL,
        verify_mode ENUM('none','secret','hmac') NOT NULL DEFAULT 'secret',
        secret VARCHAR(255) NULL,
        secret_header VARCHAR(64) NOT NULL DEFAULT 'X-Webhook-Secret',
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_by VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_received_at TIMESTAMP NULL DEFAULT NULL,
        received_count INT NOT NULL DEFAULT 0,
        INDEX idx_enabled (enabled)
    )
"""

async def ensure_custom_webhooks_table():
    # Idempotent; api.py owns the schema. The admin PHP page also issues
    # CREATE TABLE IF NOT EXISTS defensively in case it runs before a deploy.
    try:
        conn = await get_mysql_connection()
        try:
            async with conn.cursor() as cur:
                await cur.execute(CUSTOM_WEBHOOKS_TABLE_DDL)
                await conn.commit()
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Failed to ensure custom_webhooks table: {e}")

def _verify_custom_webhook(verify_mode: str, secret: str, secret_header: str, request: Request, raw_body: bytes) -> bool:
    # Constant-time verification of an inbound custom webhook request.
    if verify_mode == "none":
        return True
    if not secret:
        return False
    header_name = secret_header or ("X-Webhook-Signature" if verify_mode == "hmac" else "X-Webhook-Secret")
    provided = request.headers.get(header_name, "")
    if not provided:
        return False
    if verify_mode == "secret":
        return hmac.compare_digest(str(provided), str(secret))
    if verify_mode == "hmac":
        sig = provided[len("sha256="):] if provided.startswith("sha256=") else provided
        computed = hmac.new(secret.encode("utf-8"), raw_body, hashlib.sha256).hexdigest()
        return hmac.compare_digest(sig.lower(), computed.lower())
    return False

@app.post(
    "/webhook/{slug}",
    summary="Receive a Custom (admin-defined) Inbound Webhook",
    description=(
        "Generic inbound webhook receiver. Admins define each webhook (slug, secret, "
        "routing) from the admin panel; external services POST here. The request is "
        "verified per the webhook's configured mode (none/secret/hmac) and the payload "
        "is forwarded to the internal WebSocket server as the configured event. No api.py "
        "edit or restart is needed to add a new integration. Auth is the per-webhook "
        "secret, not an API key."
    ),
    tags=["Admin Only"],
    status_code=status.HTTP_200_OK,
    operation_id="receive_custom_webhook"
)
async def receive_custom_webhook(slug: str, request: Request):
    raw_body = await request.body()
    # Look up the webhook config by slug
    conn = await get_mysql_connection()
    try:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(
                "SELECT slug, name, service, event_name, scope, target_username, "
                "verify_mode, secret, secret_header, enabled FROM custom_webhooks "
                "WHERE slug = %s LIMIT 1",
                (slug,)
            )
            webhook = await cur.fetchone()
    finally:
        conn.close()
    # 404 for missing OR disabled — don't leak which slugs exist
    if not webhook or not webhook.get("enabled"):
        raise HTTPException(status_code=404, detail="Not Found")
    # Verify the request per the configured mode
    if not _verify_custom_webhook(webhook["verify_mode"], webhook["secret"], webhook["secret_header"], request, raw_body):
        logging.warning(f"[CUSTOM_WEBHOOK] Verification failed | slug={slug!r} mode={webhook['verify_mode']!r}")
        raise HTTPException(status_code=403, detail="Invalid webhook signature")
    # Parse the JSON payload
    try:
        payload = json.loads(raw_body) if raw_body else {}
    except json.JSONDecodeError:
        raise HTTPException(status_code=400, detail="Invalid JSON payload")
    service    = webhook["service"]
    event_name = webhook["event_name"]
    scope      = webhook["scope"]
    # Resolve the routing code. channel -> the streamer's api_key (reaches their
    # clients). global -> the service-scoped admin key (reaches admin global-
    # listeners; the WebSocket server identifies the service by this key). We never
    # forward the super-admin/master key, so it can't end up in WS access logs.
    if scope == "global":
        code = await _get_admin_key_for_service(service)
        if not code:
            logging.error(f"[CUSTOM_WEBHOOK] slug={slug!r} global scope but no admin key exists for service={service!r}")
            return {"status": "ok", "note": "service admin key not configured"}
        channel_label = service
    else:
        target = webhook.get("target_username")
        if not target:
            logging.warning(f"[CUSTOM_WEBHOOK] slug={slug!r} scope=channel but no target_username")
            return {"status": "ok", "note": "no target channel configured"}
        code = await _get_api_key_for_username(target)
        if not code:
            logging.warning(f"[CUSTOM_WEBHOOK] slug={slug!r} target {target!r} not registered")
            return {"status": "ok", "note": "channel not registered"}
        channel_label = target
    logging.info(f"[CUSTOM_WEBHOOK] received | slug={slug!r} service={service!r} event={event_name!r} scope={scope!r}")
    # Forward to the internal WebSocket server
    async with aiohttp.ClientSession() as session:
        try:
            params = {
                "code":    code,
                "event":   event_name,
                "service": service,
                "channel": channel_label,
                "data":    json.dumps(payload),
            }
            url = f"https://websocket.botofthespecter.com/notify?{urlencode(params)}"
            async with session.get(url, timeout=10) as response:
                if response.status != 200:
                    logging.error(f"[CUSTOM_WEBHOOK] WS forward failed: HTTP {response.status} slug={slug!r}")
                    raise HTTPException(status_code=502, detail="Error forwarding to WebSocket server")
        except asyncio.TimeoutError:
            logging.error(f"[CUSTOM_WEBHOOK] Timeout forwarding to WebSocket server slug={slug!r}")
            raise HTTPException(status_code=502, detail="Timeout forwarding to WebSocket server")
        except HTTPException:
            raise
        except Exception as e:
            logging.error(f"[CUSTOM_WEBHOOK] Unexpected error forwarding slug={slug!r}: {e}")
            raise HTTPException(status_code=502, detail="Error forwarding to WebSocket server")
    # Best-effort observability update
    try:
        conn = await get_mysql_connection()
        try:
            async with conn.cursor() as cur:
                await cur.execute(
                    "UPDATE custom_webhooks SET last_received_at = UTC_TIMESTAMP(), "
                    "received_count = received_count + 1 WHERE slug = %s",
                    (slug,)
                )
                await conn.commit()
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"[CUSTOM_WEBHOOK] Failed to update stats slug={slug!r}: {e}")
    return {"status": "success", "event": event_name}

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
                        else:
                            # Ensure response model validation passes by returning an empty string when timestamp is missing
                            game['received_at'] = ''
                result = {"games": games or [], "count": len(games) if games else 0}
                logging.info(f"Returning result with {result['count']} games")
                return result
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Error fetching FreeStuff games: {type(e).__name__}: {str(e)}")
        logging.error(f"Traceback: {traceback.format_exc()}")
        raise HTTPException(status_code=500, detail=f"Error fetching games: {str(e)}")

# Latest FreeStuff Game Endpoint
@app.get(
    "/freestuff/latest",
    response_model=FreeStuffGame,
    summary="Get the most recent free game",
    description="Retrieve the most recent free game announced via FreeStuff webhooks.",
    tags=["Public"],
    operation_id="get_freestuff_latest"
)
async def get_freestuff_latest():
    logging.info("FreeStuff Latest endpoint called")
    try:
        conn = await get_mysql_connection()
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("""
                    SELECT id, game_id, game_title, game_org, game_thumbnail, game_url, game_description, game_price, received_at
                    FROM freestuff_games
                    ORDER BY received_at DESC
                    LIMIT 1
                """)
                game = await cur.fetchone()
                if not game:
                    raise HTTPException(status_code=404, detail="No free games found")
                if game.get('received_at'):
                    game['received_at'] = game['received_at'].strftime('%Y-%m-%d %H:%M:%S')
                else:
                    game['received_at'] = ''
                return game
        finally:
            conn.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error fetching latest FreeStuff game: {type(e).__name__}: {str(e)}")
        logging.error(f"Traceback: {traceback.format_exc()}")
        raise HTTPException(status_code=500, detail=f"Error fetching latest free game: {str(e)}")

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
                    (u.app_password IS NOT NULL) AS app_password_set,
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
                "discord_refresh_token": result["discord_refresh_token"],
                "app_password_set": bool(result["app_password_set"])
            }
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error retrieving account info for username '{username}': {e}")
        logging.error(f"Traceback: {traceback.format_exc()}")
        raise HTTPException(status_code=500, detail=f"Error retrieving account information: {str(e)}")
    finally:
        conn.close()

# App Login Endpoint (v2 only — accessed via POST /v2/account/app-login with X-API-KEY header)
class AppLoginBody(BaseModel):
    password: str = Field(..., description="Plaintext app password to verify")

@app.post(
    "/account/app-login",
    summary="Verify app password",
    description="Verify the app password for the authenticated user.",
    tags=["User Account"],
    operation_id="app_login"
)
async def app_login(body: AppLoginBody, api_key: str = Query(...)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, None)
    conn = await get_mysql_connection()
    try:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(
                "SELECT app_password FROM users WHERE username = %s",
                (username,)
            )
            result = await cur.fetchone()
        if not result:
            return JSONResponse(status_code=404, content={"status": "error", "message": "User not found"})
        if not result["app_password"]:
            return JSONResponse(status_code=400, content={"status": "error", "message": "No app password set for this account"})
        if not pwd_context.verify(body.password, result["app_password"]):
            return JSONResponse(status_code=401, content={"status": "invalid"})
        return JSONResponse(status_code=200, content={"status": "success"})
    except Exception as e:
        logging.error(f"Error during app login for user '{username}': {e}")
        return JSONResponse(status_code=500, content={"status": "error", "message": "Internal server error"})

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
    # Find the first available versions file from the paths list
    versions_path = None
    for path in VERSIONS_FILE_PATHS:
        if os.path.exists(path):
            versions_path = path
            break
    if not versions_path:
        raise HTTPException(status_code=404, detail="Version file not found")
    with open(versions_path, "r") as versions_file:
        versions = json.load(versions_file)
    return versions

# Builtin Commands Info endpoint
@app.get(
    "/commands/info",
    response_model=BuiltinCommandsResponse,
    summary="Get builtin commands information",
    description="Retrieve all builtin commands with their descriptions, aliases, and force levels.",
    tags=["Public"],
    operation_id="get_builtin_commands_info"
)
async def builtin_commands_info():
    commands_path = "/home/botofthespecter/builtin_commands.json"
    if not os.path.exists(commands_path):
        raise HTTPException(status_code=404, detail="Builtin commands file not found")
    with open(commands_path, "r") as commands_file:
        commands_data = json.load(commands_file)
    return commands_data

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

@app.get(
    "/system/uptime",
    response_model=SystemUptimeResponse,
    summary="Get API process uptime",
    description="Return the API process uptime. Public endpoint with per-IP rate limit (default 5 minutes). Whitelisted IPs are exempt.",
    tags=["Public"],
    operation_id="get_system_uptime"
)
async def system_uptime(request: Request):
    for spoof_header in ('X-Forwarded-For', 'X-Real-IP'):
        if spoof_header in request.headers:
            peer = request.client.host if request.client else 'unknown'
            logging.warning(
                f"Rejected {request.url.path} from {peer} - "
                f"unexpected {spoof_header}: {request.headers[spoof_header]}"
            )
            raise HTTPException(status_code=403, detail="Access Forbidden")
    client_ip = request.client.host if request.client else '127.0.0.1'
    # Whitelisted IPs are exempt from throttling
    try:
        whitelisted = _is_ip_allowed(client_ip)
    except Exception:
        logging.exception("Error checking IP whitelist")
        whitelisted = False
    # If not whitelisted, enforce per-IP throttle (in-memory)
    if not whitelisted:
        now_ts = datetime.now().timestamp()
        async with _uptime_lock:
            last_ts = _uptime_requests.get(client_ip)
            if last_ts:
                elapsed = now_ts - last_ts
                if elapsed < _uptime_rate_limit_seconds:
                    retry_after = max(1, int(_uptime_rate_limit_seconds - elapsed))
                    raise HTTPException(status_code=429, detail={"error": "rate_limited", "retry_after": retry_after})
            # Update last call timestamp
            _uptime_requests[client_ip] = now_ts
    # Prepare grouped response structure
    uptime_seconds = int((datetime.now() - _process_start_time).total_seconds())
    api_section = {"Local Read": {"uptime": _format_duration(uptime_seconds), "started_at": _process_start_time.strftime('%Y-%m-%d %H:%M:%S')}}
    websocket_section = {"Local Read": {"uptime": "Unknown", "started_at": "Unknown"}}
    other_sections = {}
    # Attempt to fetch uptime markers via SSH from remote hosts
    ws_marker_path = os.getenv('WEBSOCKET_UPTIME_MARKER_PATH', '/home/botofthespecter/websocket_uptime')
    server_marker_paths = {
        'WEB1': os.getenv('WEB1_UPTIME_MARKER_PATH', '/home/botofthespecter/web1_uptime'),
        'SQL': os.getenv('SQL_UPTIME_MARKER_PATH', '/home/botofthespecter/sql_uptime'),
        'BOTS': os.getenv('BOTS_UPTIME_MARKER_PATH', '/home/botofthespecter/bots_uptime'),
    }
    websocket_uptime = await _read_uptime_marker_via_ssh(
        host=WEBSOCKET_SSH_HOST,
        username=SSH_USERNAME,
        password=SSH_PASSWORD,
        marker_path=ws_marker_path,
        server_label='websocket'
    )
    if websocket_uptime:
        websocket_section['Local Read'] = websocket_uptime
    remote_servers = {
        'WEB1': WEB1_SSH_HOST,
        'SQL': SQL_SSH_HOST,
        'BOTS': BOTS_SSH_HOST,
    }
    for section_name, host in remote_servers.items():
        section = other_sections.setdefault(section_name, {'Local Read': {'uptime': 'Unknown', 'started_at': 'Unknown'}})
        uptime_info = await _read_uptime_marker_via_ssh(
            host=host,
            username=SSH_USERNAME,
            password=SSH_PASSWORD,
            marker_path=server_marker_paths[section_name],
            server_label=section_name.lower()
        )
        if uptime_info:
            section['Local Read'] = uptime_info
    # Database fallback: include system_metrics rows for websocket, api, and others
    db_ok = False
    db_rows_count = 0
    try:
        conn = await get_mysql_connection()
        db_ok = True
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT server_name, cpu_percent, ram_percent, ram_used, ram_total, disk_percent, disk_used, disk_total, net_sent, net_recv, last_updated FROM system_metrics")
                rows = await cur.fetchall()
                db_rows_count = len(rows) if rows else 0
                for row in rows or []:
                    server = row.get('server_name')
                    if not server:
                        continue
                    metrics = {
                        'cpu_percent': row.get('cpu_percent'),
                        'ram_percent': row.get('ram_percent'),
                        'ram_used': row.get('ram_used'),
                        'ram_total': row.get('ram_total'),
                        'disk_percent': row.get('disk_percent'),
                        'disk_used': row.get('disk_used'),
                        'disk_total': row.get('disk_total'),
                        'net_sent': row.get('net_sent'),
                        'net_recv': row.get('net_recv')
                    }
                    # place metrics under the appropriate section (label as Local Metrics Script)
                    if server == 'api':
                        api_section['Local Metrics Script'] = metrics
                    elif server == 'websocket':
                        websocket_section['Local Metrics Script'] = metrics
                    else:
                        server_key = server.upper()
                        section = other_sections.setdefault(
                            server_key,
                            {'Local Read': {'uptime': 'Unknown', 'started_at': 'Unknown'}}
                        )
                        section['Local Metrics Script'] = metrics
        finally:
            conn.close()
    except Exception:
        logging.exception("Error fetching system_metrics from database")
    # Call Hetrixtools for external uptime data (if configured) and attach summaries
    hetrix_key = os.getenv('HETRIXTOOLS_API_KEY')
    monitor_map = {
        'API': 'HETRIX_MONITOR_API',
        'WEBSOCKET': 'HETRIX_MONITOR_WEBSOCKET',
        'WEB1': 'HETRIX_MONITOR_WEB1',
        'SQL': 'HETRIX_MONITOR_SQL',
        'BOTS': 'HETRIX_MONITOR_BOTS'
    }
    hetrix_ok = False
    if hetrix_key:
        async with aiohttp.ClientSession() as session:
            for section_name, env_name in monitor_map.items():
                monitor_id = os.getenv(env_name)
                if not monitor_id:
                    continue
                url = f"https://api.hetrixtools.com/v3/uptime-monitors/{monitor_id}/report"
                headers = {"Authorization": f"Bearer {hetrix_key}"}
                # prepare per-section diagnostics container
                section_diag = {"ok": False, "status": None, "body": None, "error": None}
                try:
                    async with session.get(url, headers=headers, timeout=10) as resp:
                        section_diag['status'] = resp.status
                        # try to read response body safely
                        try:
                            text = await resp.text()
                            # attempt to parse JSON, but keep raw text on failure
                            try:
                                body = json.loads(text) if text else None
                            except Exception:
                                body = text
                            section_diag['body'] = body
                        except Exception as e:
                            section_diag['error'] = f"failed-to-read-response-body: {e}"
                            logging.exception(f"Failed to read Hetrixtools response body for {section_name}")
                        if resp.status == 200 and isinstance(section_diag.get('body'), dict):
                            # sanitize body to remove response_time fields we don't want to expose
                            data = section_diag['body']
                            sanitized = copy.deepcopy(data)
                            # drop top-level response_time in summary
                            if isinstance(sanitized.get('summary'), dict):
                                sanitized['summary'].pop('response_time', None)
                            # drop any response_time entries under daily data
                            if isinstance(sanitized.get('data'), dict):
                                for day_key, day_val in sanitized['data'].items():
                                    if isinstance(day_val, dict):
                                        # remove direct response_time container
                                        day_val.pop('response_time', None)
                                        # also remove nested response_time fields if present in sub-objects
                                        for sub_k, sub_v in list(day_val.items()):
                                            if isinstance(sub_v, dict) and 'response_time' in sub_v:
                                                sub_v.pop('response_time', None)
                            # replace diagnostics body with sanitized copy
                            section_diag['body'] = sanitized
                            summary = sanitized.get('summary')
                            # attach into the appropriate section
                            if section_name == 'API':
                                target = api_section
                            elif section_name == 'WEBSOCKET':
                                target = websocket_section
                            elif section_name == 'WEB1':
                                target = other_sections.setdefault('WEB1', {})
                            elif section_name == 'SQL':
                                target = other_sections.setdefault('SQL', {})
                            elif section_name == 'BOTS':
                                target = other_sections.setdefault('BOTS', {})
                            else:
                                target = other_sections.setdefault(section_name, {})
                            if summary:
                                target['External API Metrics'] = summary
                                # include most-recent-day details when available
                                data_days = sanitized.get('data', {})
                                if isinstance(data_days, dict):
                                    latest_day = sorted(data_days.keys(), reverse=True)[0] if data_days else None
                                    if latest_day:
                                        target.setdefault('External API Metrics', {})['latest_day'] = {latest_day: data_days[latest_day]}
                            section_diag['ok'] = True
                            hetrix_ok = True
                        else:
                            # non-200 or unexpected body
                            logging.warning(f"Hetrixtools request for {section_name} monitor {monitor_id} returned status={resp.status}; body={section_diag.get('body')}")
                except Exception as exc:
                    section_diag['error'] = str(exc)
                    logging.exception(f"Error fetching Hetrixtools report for {section_name}: {exc}")
                # store per-monitor diagnostics only in logs (removed debug exposure)
                logging.debug(f"Hetrixtools diag for {section_name}: {section_diag}")
    # Build final grouped response
    final = {
        'API': api_section,
        'WEBSOCKET': websocket_section
    }
    # include any other servers (WEB1, SQL, BOTS, etc.)
    if other_sections:
        final.update(other_sections)
    return final

# Chat instructions endpoint
@app.get(
    "/chat-instructions",
    summary="Get AI chat instructions",
    description="Return AI system instructions used by the bot. Use ?discord=true for Discord chat instructions, ?ad_messages=true for ad-break AI instructions, or ?home_ai=true for bot-home-channel AI instructions.",
    tags=["Public"],
    operation_id="get_chat_instructions"
)
async def chat_instructions(
    request: Request,
    discord: bool = Query(False, description="Return Discord-specific AI instructions if available"),
    ad_messages: bool = Query(False, description="Return ad-break AI instructions if available"),
    home_ai: bool = Query(False, description="Return bot-home-channel AI instructions if available")
):
    # Prefer Discord-specific instructions when the query flag is set
    use_discord = discord
    use_ad_messages = ad_messages
    use_home_ai = home_ai
    server_dir = "/home/botofthespecter"
    repo_dir = os.path.dirname(os.path.abspath(__file__))
    def _resolve_ai_file(filename: str):
        # Prefer the server copy, fall back to the repo-shipped copy.
        for d in (server_dir, repo_dir):
            candidate = os.path.join(d, filename)
            if os.path.exists(candidate):
                return candidate
        return None
    filename = "ai.json"
    path = _resolve_ai_file(filename)
    try:
        server_copy = os.path.join(server_dir, filename)
        repo_copy = os.path.join(repo_dir, filename)
        if (os.path.exists(server_copy) and os.path.exists(repo_copy)
                and os.path.abspath(server_copy) != os.path.abspath(repo_copy)
                and os.path.getmtime(repo_copy) > os.path.getmtime(server_copy) + 1):
            logging.warning(
                f"AI instructions '{filename}': deployed server copy is OLDER than the "
                f"repo-shipped copy — the server file may be stale. Redeploy it "
                f"(e.g. run api/deploy-ai-instructions.sh)."
            )
    except Exception:
        pass
    try:
        if path and os.path.exists(path):
            with open(path, "r", encoding="utf-8-sig") as f:
                data = json.load(f)
            messages = []
            seen = set()
            def add_messages(section_key):
                section = data.get(section_key, {})
                if isinstance(section, dict):
                    for message in section.get("messages", []):
                        if not isinstance(message, dict):
                            continue
                        key = (message.get("role"), message.get("content"))
                        if key not in seen:
                            seen.add(key)
                            messages.append(message)
            add_messages("global")
            if use_ad_messages:
                add_messages("ad_messages")
            elif use_home_ai:
                add_messages("home")
            elif use_discord:
                add_messages("discord")
            else:
                add_messages("default")
                # Fallback for legacy flat ai.json files that still use root-level messages
                if not messages and isinstance(data.get("messages"), list):
                    for message in data.get("messages", []):
                        if not isinstance(message, dict):
                            continue
                        key = (message.get("role"), message.get("content"))
                        if key not in seen:
                            seen.add(key)
                            messages.append(message)
            return JSONResponse(status_code=200, content={"messages": messages})
        # Not found in either location
        raise HTTPException(status_code=404, detail=f"AI instructions not found for {filename}")
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error loading AI instructions ({filename}): {e}")
        try:
            if os.getenv('AI_INSTRUCTIONS_DEBUG', '0') == '1':
                raise HTTPException(status_code=500, detail=f"Failed to load AI instructions: {e}")
        except Exception:
            # Fall-through to the standard error response
            pass
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


@app.get(
    "/api/steamapplist",
    summary="Get Steam app list mapping",
    description="Return a lowercased Steam game-name to appid mapping cached by the API server.",
    tags=["Public"],
    operation_id="get_steam_app_list"
)
async def api_steamapplist():
    global _steam_app_list_cache, _steam_app_list_cache_loaded_at
    now_utc = datetime.now(timezone.utc)
    in_memory_age = (now_utc - _steam_app_list_cache_loaded_at).total_seconds()
    if _steam_app_list_cache and in_memory_age < STEAM_APP_LIST_CACHE_TTL_SECONDS:
        return _steam_app_list_cache
    disk_cache = _load_steam_app_list_from_disk(now_utc)
    if disk_cache:
        _steam_app_list_cache = disk_cache
        _steam_app_list_cache_loaded_at = now_utc
        return disk_cache
    primary_url = "https://api.steampowered.com/IStoreService/GetAppList/v1/"
    request_attempts = [{"format": "json"}]
    if STEAM_API:
        request_attempts.insert(0, {"format": "json", "key": STEAM_API})
    else:
        logging.warning("STEAM_API key is not configured; attempting unauthenticated Steam app list request")
    try:
        timeout = aiohttp.ClientTimeout(total=20)
        payload = None
        last_status = None
        last_error_snippet = ""
        async with aiohttp.ClientSession(timeout=timeout) as session:
            for query_params in request_attempts:
                try:
                    combined_map: Dict[str, int] = {}
                    last_appid = 0
                    more_results = True
                    while more_results:
                        store_params = dict(query_params)
                        store_params.update({
                            "max_results": 50000,
                            "last_appid": last_appid,
                        })
                        async with session.get(
                            primary_url,
                            params=store_params,
                            headers={
                                "Accept": "application/json",
                                "User-Agent": "BotOfTheSpecter/1.0",
                            },
                        ) as response:
                            if response.status != 200:
                                upstream_error = await response.text()
                                last_status = response.status
                                last_error_snippet = (upstream_error or "")[:400]
                                logging.error(
                                    "Steam IStoreService call failed with status %s (params=%s): %s",
                                    response.status,
                                    "with_key" if query_params.get("key") else "without_key",
                                    last_error_snippet,
                                )
                                combined_map = {}
                                break
                            page_payload = await response.json(content_type=None)
                            page_normalized = _normalize_steam_app_list(page_payload)
                            if not page_normalized:
                                break
                            combined_map.update(page_normalized)
                            response_obj = (page_payload or {}).get("response") or {}
                            more_results = bool(response_obj.get("have_more_results"))
                            last_appid = int(response_obj.get("last_appid") or 0)
                            if more_results and last_appid <= 0:
                                break
                    if combined_map:
                        payload = combined_map
                        break
                except Exception as request_exc:
                    logging.error(
                        "Steam API request error (params=%s): %s",
                        "with_key" if query_params.get("key") else "without_key",
                        request_exc,
                    )
        if payload is None:
            stale_disk_cache = _load_steam_app_list_from_disk(now_utc, allow_expired=True)
            if stale_disk_cache:
                logging.warning("Steam API unavailable; serving stale Steam app list cache")
                _steam_app_list_cache = stale_disk_cache
                _steam_app_list_cache_loaded_at = now_utc
                return stale_disk_cache
            logging.error(
                "Steam API unavailable and no stale cache available (status=%s, error=%s)",
                last_status,
                last_error_snippet,
            )
            raise HTTPException(status_code=502, detail="Steam API unavailable")
        normalized = _normalize_steam_app_list(payload)
        if not normalized:
            stale_disk_cache = _load_steam_app_list_from_disk(now_utc, allow_expired=True)
            if stale_disk_cache:
                logging.warning("Steam API returned empty payload; serving stale Steam app list cache")
                _steam_app_list_cache = stale_disk_cache
                _steam_app_list_cache_loaded_at = now_utc
                return stale_disk_cache
            raise HTTPException(status_code=502, detail="Steam API returned empty app list")
        _steam_app_list_cache = normalized
        _steam_app_list_cache_loaded_at = now_utc
        _save_steam_app_list_to_disk(normalized)
        return normalized
    except HTTPException:
        raise
    except Exception as exc:
        logging.error(f"Error retrieving Steam app list: {exc}")
        raise HTTPException(status_code=500, detail="Error retrieving Steam app list")

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
        website_ssh_host = os.getenv('WEB-HOST')
        website_ssh_username = os.getenv('SSH_USERNAME')
        website_ssh_password = os.getenv('SSH_PASSWORD')
        sound_alerts_dir = f"/var/www/soundalerts/{username}"
        command = f'ls -1 "{sound_alerts_dir}" 2>/dev/null | grep -v "^twitch$" | while read f; do [ -f "{sound_alerts_dir}/$f" ] && echo "$f"; done | sort'
        async with asyncssh.connect(
            website_ssh_host, port=22,
            username=website_ssh_username, password=website_ssh_password,
            known_hosts=None, connect_timeout=10,
        ) as conn:
            result = await conn.run(command)
        if result.exit_status != 0:
            error_msg = result.stderr.strip()
            if "No such file" in error_msg or "cannot access" in error_msg:
                raise HTTPException(status_code=404, detail=f"No sound alerts directory found for user '{channel}'")
            logging.error(f"Error listing sound alerts for '{channel}': {error_msg}")
            raise HTTPException(status_code=500, detail="Error retrieving sound alerts")
        output = result.stdout.strip()
        if not output:
            sound_files = []
        else:
            valid_extensions = ('.mp3', '.wav', '.ogg', '.m4a', '.mp4', '.webm', '.avi', '.mov')
            sound_files = [f for f in output.split('\n') if f.lower().endswith(valid_extensions)]
        return {
            "user": username,
            "total_sounds": len(sound_files),
            "sounds": sound_files
        }
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error retrieving sound alerts for user '{channel}': {e}")
        raise HTTPException(status_code=500, detail=f"Error retrieving sound alerts: {str(e)}")

# Walkons Endpoint
@app.get(
    "/walkons",
    summary="Get list of walkons for your channel",
    description="Retrieve every walkon audio/video file configured for your channel, mapping each viewer username to its file extension and public URL.",
    tags=["Commands"],
    operation_id="get_walkons"
)
async def get_walkons(api_key: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    try:
        website_ssh_host = os.getenv('WEB-HOST')
        website_ssh_username = os.getenv('SSH_USERNAME')
        website_ssh_password = os.getenv('SSH_PASSWORD')
        walkons_dir = f"/var/www/walkons/{username}"
        command = f'ls -1 "{walkons_dir}" 2>/dev/null | while read f; do [ -f "{walkons_dir}/$f" ] && echo "$f"; done | sort'
        async with asyncssh.connect(
            website_ssh_host, port=22,
            username=website_ssh_username, password=website_ssh_password,
            known_hosts=None, connect_timeout=10,
        ) as conn:
            result = await conn.run(command)
        if result.exit_status != 0:
            error_msg = result.stderr.strip()
            if "No such file" in error_msg or "cannot access" in error_msg:
                raise HTTPException(status_code=404, detail=f"No walkons directory found for user '{username}'")
            logging.error(f"Error listing walkons for '{username}': {error_msg}")
            raise HTTPException(status_code=500, detail="Error retrieving walkons")
        output = result.stdout.strip()
        walkons = []
        if output:
            valid_extensions = (".mp3", ".mp4")
            for filename in output.split('\n'):
                ext = os.path.splitext(filename)[1].lower()
                if ext not in valid_extensions:
                    continue
                viewer = filename[:-len(ext)]
                walkons.append({
                    "username": viewer,
                    "ext": ext,
                    "filename": filename,
                    "url": f"https://walkons.botofthespecter.com/{username}/{filename}",
                })
        return {
            "user": username,
            "total_walkons": len(walkons),
            "walkons": walkons,
        }
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error retrieving walkons for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error retrieving walkons: {str(e)}")

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
                    SELECT command, response, status, cooldown, permission
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
                    "status": cmd['status'],
                    "cooldown": cmd['cooldown'],
                    "permission": cmd['permission']
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

@app.post(
    "/custom-commands/add",
    summary="Add a custom command",
    description="Add a new custom command to your account's database.",
    tags=["Commands"],
    operation_id="add_custom_command"
)
async def add_custom_command(
    api_key: str = Query(...),
    command: str = Query(..., description="Command name (without ! prefix)"),
    response: str = Query(..., description="Command response text", max_length=500),
    cooldown: int = Query(15, description="Cooldown in seconds", ge=0),
    permission: str = Query("everyone", description="Permission level (e.g. everyone, subscriber, moderator)"),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    command_name = _sanitize_user_command_name(command)
    if not command_name:
        raise HTTPException(status_code=400, detail="Command name is invalid after sanitization")
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    "SELECT command FROM custom_commands WHERE command = %s",
                    (command_name,),
                )
                if await cursor.fetchone():
                    raise HTTPException(status_code=409, detail=f"Command '{command_name}' already exists")
                await cursor.execute(
                    """
                    INSERT INTO custom_commands (command, response, status, cooldown, permission)
                    VALUES (%s, %s, 'Enabled', %s, %s)
                    """,
                    (command_name, response, cooldown, permission),
                )
                await connection.commit()
                if cursor.rowcount <= 0:
                    raise HTTPException(status_code=500, detail="Command was not added to the database")
            return {
                "status": "success",
                "user": username,
                "command": command_name,
                "message": f"Command '{command_name}' added successfully",
            }
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error adding custom command for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error adding custom command: {str(e)}")

@app.put(
    "/custom-commands/update",
    summary="Update a custom command",
    description="Update an existing custom command in your account's database. Only provided fields are changed.",
    tags=["Commands"],
    operation_id="update_custom_command"
)
async def update_custom_command(
    api_key: str = Query(...),
    command: str = Query(..., description="Command name to update (without ! prefix)"),
    response: str = Query(None, description="New response text", max_length=500),
    cooldown: int = Query(None, description="New cooldown in seconds", ge=0),
    permission: str = Query(None, description="New permission level"),
    status: str = Query(None, description="New status (Enabled or Disabled)"),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    command_name = _sanitize_user_command_name(command)
    if not command_name:
        raise HTTPException(status_code=400, detail="Command name is invalid after sanitization")
    fields, values = [], []
    if response is not None:
        fields.append("response = %s"); values.append(response)
    if cooldown is not None:
        fields.append("cooldown = %s"); values.append(cooldown)
    if permission is not None:
        if permission not in VALID_PERMISSIONS:
            raise HTTPException(status_code=400, detail=f"permission must be one of: {', '.join(sorted(VALID_PERMISSIONS))}")
        fields.append("permission = %s"); values.append(permission)
    if status is not None:
        if status not in ("Enabled", "Disabled"):
            raise HTTPException(status_code=400, detail="status must be 'Enabled' or 'Disabled'")
        fields.append("status = %s"); values.append(status)
    if not fields:
        raise HTTPException(status_code=400, detail="No fields provided to update")
    values.append(command_name)
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    f"UPDATE custom_commands SET {', '.join(fields)} WHERE command = %s",
                    values,
                )
                await connection.commit()
                if cursor.rowcount <= 0:
                    raise HTTPException(status_code=404, detail=f"Command '{command_name}' not found")
            return {
                "status": "success",
                "user": username,
                "command": command_name,
                "message": f"Command '{command_name}' updated successfully",
            }
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error updating custom command for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error updating custom command: {str(e)}")

@app.delete(
    "/custom-commands/delete",
    summary="Delete a custom command",
    description="Remove a custom command from your account's database.",
    tags=["Commands"],
    operation_id="delete_custom_command"
)
async def delete_custom_command(
    api_key: str = Query(...),
    command: str = Query(..., description="Command name to delete (without ! prefix)"),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    command_name = _sanitize_user_command_name(command)
    if not command_name:
        raise HTTPException(status_code=400, detail="Command name is invalid after sanitization")
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    "DELETE FROM custom_commands WHERE command = %s",
                    (command_name,),
                )
                await connection.commit()
                if cursor.rowcount <= 0:
                    raise HTTPException(status_code=404, detail=f"Command '{command_name}' not found")
            return {
                "status": "success",
                "user": username,
                "command": command_name,
                "message": f"Command '{command_name}' deleted successfully",
            }
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error deleting custom command for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error deleting custom command: {str(e)}")

# Timed Messages (Timers) Endpoints
# Manage the bot's timed messages (the dashboard "Timed Messages" page). One table,
# two timer kinds via trigger_type: 'timer' (every interval_count minutes),
# 'chat_lines' (every chat_line_trigger messages), or 'both'. Validation mirrors
# dashboard/timed_messages.php: interval 5-480 (min 60 if the message contains a
# (shoutout.username) variable), chat_line_trigger >= 5.
import re as _re

_SHOUTOUT_VAR_RE = _re.compile(r"\(shoutout\.\w+\)")
VALID_TRIGGER_TYPES = {"timer", "chat_lines", "both"}

def _validate_timer_fields(trigger_type: str, message: str, interval_count, chat_line_trigger):
    # Returns (interval_count|None, chat_line_trigger|None) or raises HTTPException(400).
    if trigger_type not in VALID_TRIGGER_TYPES:
        raise HTTPException(status_code=400, detail=f"trigger_type must be one of: {', '.join(sorted(VALID_TRIGGER_TYPES))}")
    if not message or not message.strip():
        raise HTTPException(status_code=400, detail="message is required")
    has_shoutout = bool(_SHOUTOUT_VAR_RE.search(message))
    interval_out = None
    chat_out = None
    if trigger_type in ("timer", "both"):
        if interval_count is None:
            raise HTTPException(status_code=400, detail="interval_count is required for this trigger_type")
        int_min = 60 if has_shoutout else 5
        if interval_count < int_min or interval_count > 480:
            detail = ("interval_count must be at least 60 when the message uses a (shoutout.username) variable"
                      if has_shoutout else "interval_count must be between 5 and 480")
            raise HTTPException(status_code=400, detail=detail)
        interval_out = interval_count
    if trigger_type in ("chat_lines", "both"):
        if chat_line_trigger is None:
            raise HTTPException(status_code=400, detail="chat_line_trigger is required for this trigger_type")
        if chat_line_trigger < 5:
            raise HTTPException(status_code=400, detail="chat_line_trigger must be at least 5")
        chat_out = chat_line_trigger
    return interval_out, chat_out

@app.get(
    "/timers",
    summary="Get list of timed messages for your account",
    description="Retrieve all timed messages (timer and chat-line triggers) configured for your account.",
    tags=["Commands"],
    operation_id="get_timers"
)
async def get_timers(api_key: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    "SELECT id, interval_count, chat_line_trigger, message, status, trigger_type FROM timed_messages ORDER BY id ASC"
                )
                rows = await cursor.fetchall()
            timers = []
            for row in rows:
                # status is stored as 1/0 (legacy default 'True'); normalize to a bool.
                raw_status = row['status']
                enabled = str(raw_status).strip().lower() in ('1', 'true')
                timers.append({
                    "id": row['id'],
                    "trigger_type": row['trigger_type'],
                    "interval_count": row['interval_count'],
                    "chat_line_trigger": row['chat_line_trigger'],
                    "message": row['message'],
                    "enabled": enabled,
                })
            return {"user": username, "total_timers": len(timers), "timers": timers}
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error retrieving timers for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error retrieving timers: {str(e)}")

@app.post(
    "/timers/add",
    summary="Add a timed message",
    description="Add a new timed message. trigger_type 'timer' uses interval_count (minutes, 5-480; min 60 with a (shoutout.username) variable); 'chat_lines' uses chat_line_trigger (>=5); 'both' uses both.",
    tags=["Commands"],
    operation_id="add_timer"
)
async def add_timer(
    api_key: str = Query(...),
    message: str = Query(..., description="The message the bot will post", max_length=500),
    trigger_type: str = Query("timer", description="timer | chat_lines | both"),
    interval_count: int = Query(None, description="Minutes between posts (5-480)", ge=1),
    chat_line_trigger: int = Query(None, description="Chat lines between posts (>=5)", ge=1),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    interval_out, chat_out = _validate_timer_fields(trigger_type, message, interval_count, chat_line_trigger)
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                # Fill the lowest unused id to mirror the dashboard's numbering.
                await cursor.execute(
                    "SELECT MIN(seq.id) AS next_id FROM "
                    "(SELECT 1 AS id UNION ALL SELECT id + 1 FROM timed_messages) seq "
                    "LEFT JOIN timed_messages t ON seq.id = t.id WHERE t.id IS NULL"
                )
                gap_row = await cursor.fetchone()
                next_id = gap_row['next_id'] if gap_row and gap_row.get('next_id') else None
                if next_id:
                    await cursor.execute(
                        "INSERT INTO timed_messages (id, interval_count, chat_line_trigger, message, status, trigger_type) "
                        "VALUES (%s, %s, %s, %s, 1, %s)",
                        (next_id, interval_out, chat_out, message, trigger_type),
                    )
                    new_id = next_id
                else:
                    await cursor.execute(
                        "INSERT INTO timed_messages (interval_count, chat_line_trigger, message, status, trigger_type) "
                        "VALUES (%s, %s, %s, 1, %s)",
                        (interval_out, chat_out, message, trigger_type),
                    )
                    new_id = cursor.lastrowid
                await connection.commit()
                if cursor.rowcount <= 0:
                    raise HTTPException(status_code=500, detail="Timer was not added to the database")
            return {"status": "success", "user": username, "id": new_id, "message": "Timer added successfully"}
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error adding timer for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error adding timer: {str(e)}")

@app.put(
    "/timers/update",
    summary="Update a timed message",
    description="Update an existing timed message by id. Provide trigger_type plus the fields it needs; the relevant interval_count / chat_line_trigger is set and the other is cleared. enabled toggles status.",
    tags=["Commands"],
    operation_id="update_timer"
)
async def update_timer(
    api_key: str = Query(...),
    id: int = Query(..., description="Timer id to update"),
    message: str = Query(..., description="The message the bot will post", max_length=500),
    trigger_type: str = Query(..., description="timer | chat_lines | both"),
    interval_count: int = Query(None, description="Minutes between posts (5-480)", ge=1),
    chat_line_trigger: int = Query(None, description="Chat lines between posts (>=5)", ge=1),
    enabled: bool = Query(True, description="Whether the timer is active"),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    interval_out, chat_out = _validate_timer_fields(trigger_type, message, interval_count, chat_line_trigger)
    status_int = 1 if enabled else 0
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    "UPDATE timed_messages SET interval_count = %s, chat_line_trigger = %s, message = %s, status = %s, trigger_type = %s WHERE id = %s",
                    (interval_out, chat_out, message, status_int, trigger_type, id),
                )
                await connection.commit()
                if cursor.rowcount <= 0:
                    raise HTTPException(status_code=404, detail=f"Timer {id} not found")
            return {"status": "success", "user": username, "id": id, "message": "Timer updated successfully"}
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error updating timer for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error updating timer: {str(e)}")

@app.put(
    "/timers/toggle",
    summary="Enable or disable a timed message",
    description="Flip a timed message's active status without touching its other fields.",
    tags=["Commands"],
    operation_id="toggle_timer"
)
async def toggle_timer(
    api_key: str = Query(...),
    id: int = Query(..., description="Timer id to toggle"),
    enabled: bool = Query(..., description="True to enable, False to disable"),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    status_int = 1 if enabled else 0
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    "UPDATE timed_messages SET status = %s WHERE id = %s",
                    (status_int, id),
                )
                await connection.commit()
                if cursor.rowcount <= 0:
                    raise HTTPException(status_code=404, detail=f"Timer {id} not found")
            return {"status": "success", "user": username, "id": id, "enabled": enabled, "message": "Timer status updated"}
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error toggling timer for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error toggling timer: {str(e)}")

@app.delete(
    "/timers/delete",
    summary="Delete a timed message",
    description="Remove a timed message from your account by id.",
    tags=["Commands"],
    operation_id="delete_timer"
)
async def delete_timer(
    api_key: str = Query(...),
    id: int = Query(..., description="Timer id to delete"),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("DELETE FROM timed_messages WHERE id = %s", (id,))
                await connection.commit()
                if cursor.rowcount <= 0:
                    raise HTTPException(status_code=404, detail=f"Timer {id} not found")
            return {"status": "success", "user": username, "id": id, "message": "Timer deleted successfully"}
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error deleting timer for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error deleting timer: {str(e)}")

VALID_RAFFLE_STATUS = {"scheduled", "running", "ended"}
VALID_FOLLOW_UNITS = {"days", "weeks", "months", "years"}

def _raffle_config_params(name, prize, number_of_winners, is_weighted,
                          weight_sub_t1, weight_sub_t2, weight_sub_t3, weight_vip,
                          exclude_mods, subscribers_only, followers_only,
                          followers_min_enabled, followers_min_value, followers_min_unit):
    if not name or not name.strip():
        raise HTTPException(status_code=400, detail="name is required")
    if number_of_winners is None or number_of_winners <= 0:
        raise HTTPException(status_code=400, detail="number_of_winners must be a positive integer")
    for label, w in (("weight_sub_t1", weight_sub_t1), ("weight_sub_t2", weight_sub_t2),
                     ("weight_sub_t3", weight_sub_t3), ("weight_vip", weight_vip)):
        if w is None or w < 1 or w > 999.99:
            raise HTTPException(status_code=400, detail=f"{label} must be between 1.00 and 999.99")
    unit = (followers_min_unit or "days").strip().lower()
    if unit not in VALID_FOLLOW_UNITS:
        unit = "days"
    fo = 1 if followers_only else 0
    fme = 1 if followers_min_enabled else 0
    fmv = max(0, int(followers_min_value or 0))
    if not fo:
        fme, fmv, unit = 0, 0, "days"
    if not fme:
        fmv, unit = 0, "days"
    return {
        "name": name.strip(),
        "prize": (prize or "").strip(),
        "number_of_winners": int(number_of_winners),
        "is_weighted": 1 if is_weighted else 0,
        "weight_sub_t1": float(weight_sub_t1),
        "weight_sub_t2": float(weight_sub_t2),
        "weight_sub_t3": float(weight_sub_t3),
        "weight_vip": float(weight_vip),
        "exclude_mods": 1 if exclude_mods else 0,
        "subscribers_only": 1 if subscribers_only else 0,
        "followers_only": fo,
        "followers_min_enabled": fme,
        "followers_min_value": fmv,
        "followers_min_unit": unit,
    }

@app.get(
    "/raffles",
    summary="Get list of raffles (giveaways) for your account",
    description="Retrieve all raffles for your account with their config, status, entry/winner counts, and drawn winners.",
    tags=["Commands"],
    operation_id="get_raffles"
)
async def get_raffles(api_key: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    "SELECT id, name, prize, number_of_winners, status, is_weighted, "
                    "weight_sub_t1, weight_sub_t2, weight_sub_t3, weight_vip, exclude_mods, "
                    "subscribers_only, followers_only, followers_min_enabled, followers_min_value, "
                    "followers_min_unit, created_at, "
                    "(SELECT COUNT(*) FROM raffle_entries e WHERE e.raffle_id = raffles.id) AS entry_count, "
                    "(SELECT COUNT(*) FROM raffle_winners w WHERE w.raffle_id = raffles.id) AS winner_count "
                    "FROM raffles ORDER BY created_at DESC LIMIT 100"
                )
                rows = await cursor.fetchall()
                # Attach winner usernames in one extra query (avoid N+1 over the list).
                winners_by_raffle = {}
                ids = [row['id'] for row in rows]
                if ids:
                    placeholders = ", ".join(["%s"] * len(ids))
                    await cursor.execute(
                        f"SELECT raffle_id, username FROM raffle_winners WHERE raffle_id IN ({placeholders}) ORDER BY id ASC",
                        ids,
                    )
                    for wr in await cursor.fetchall():
                        winners_by_raffle.setdefault(wr['raffle_id'], []).append(wr['username'])
            raffles = []
            for row in rows:
                raffles.append({
                    "id": row['id'],
                    "name": row['name'],
                    "prize": row['prize'],
                    "number_of_winners": row['number_of_winners'],
                    "status": row['status'],
                    "is_weighted": bool(row['is_weighted']),
                    "weight_sub_t1": float(row['weight_sub_t1']) if row['weight_sub_t1'] is not None else None,
                    "weight_sub_t2": float(row['weight_sub_t2']) if row['weight_sub_t2'] is not None else None,
                    "weight_sub_t3": float(row['weight_sub_t3']) if row['weight_sub_t3'] is not None else None,
                    "weight_vip": float(row['weight_vip']) if row['weight_vip'] is not None else None,
                    "exclude_mods": bool(row['exclude_mods']),
                    "subscribers_only": bool(row['subscribers_only']),
                    "followers_only": bool(row['followers_only']),
                    "followers_min_enabled": bool(row['followers_min_enabled']),
                    "followers_min_value": row['followers_min_value'],
                    "followers_min_unit": row['followers_min_unit'],
                    "created_at": row['created_at'].isoformat() if row['created_at'] else None,
                    "entry_count": int(row['entry_count'] or 0),
                    "winner_count": int(row['winner_count'] or 0),
                    "winners": winners_by_raffle.get(row['id'], []),
                })
            return {"user": username, "total_raffles": len(raffles), "raffles": raffles}
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error retrieving raffles for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error retrieving raffles: {str(e)}")

@app.get(
    "/raffles/entries",
    summary="Get the entries for a raffle",
    description="Retrieve all entrants for a raffle (written by viewers via !joinraffle). Read-only.",
    tags=["Commands"],
    operation_id="get_raffle_entries"
)
async def get_raffle_entries(api_key: str = Query(...), raffle_id: int = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    "SELECT id, raffle_id, user_id, username, weight, source, entered_at "
                    "FROM raffle_entries WHERE raffle_id = %s ORDER BY id ASC",
                    (raffle_id,),
                )
                rows = await cursor.fetchall()
            entries = [{
                "id": r['id'],
                "raffle_id": r['raffle_id'],
                "user_id": r['user_id'],
                "username": r['username'],
                "weight": int(r['weight']) if r['weight'] is not None else 1,
                "source": r['source'],
                "entered_at": r['entered_at'].isoformat() if r['entered_at'] else None,
            } for r in rows]
            return {"user": username, "raffle_id": raffle_id, "total_entries": len(entries), "entries": entries}
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error retrieving raffle entries for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error retrieving raffle entries: {str(e)}")

@app.get(
    "/raffles/winners",
    summary="Get the winners for a raffle",
    description="Retrieve the drawn winners for a raffle.",
    tags=["Commands"],
    operation_id="get_raffle_winners"
)
async def get_raffle_winners(api_key: str = Query(...), raffle_id: int = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    "SELECT id, raffle_id, entry_id, user_id, username, source, won_at "
                    "FROM raffle_winners WHERE raffle_id = %s ORDER BY id ASC",
                    (raffle_id,),
                )
                rows = await cursor.fetchall()
            winners = [{
                "id": r['id'],
                "raffle_id": r['raffle_id'],
                "entry_id": r['entry_id'],
                "user_id": r['user_id'],
                "username": r['username'],
                "source": r['source'],
                "won_at": r['won_at'].isoformat() if r['won_at'] else None,
            } for r in rows]
            return {"user": username, "raffle_id": raffle_id, "total_winners": len(winners), "winners": winners}
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error retrieving raffle winners for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error retrieving raffle winners: {str(e)}")

@app.post(
    "/raffles/add",
    summary="Create a raffle (giveaway)",
    description="Create a new raffle in the 'scheduled' state. Configure winners, optional weighting (sub tiers + VIP multipliers), and entry restrictions (exclude mods, subscribers only, followers only with an optional minimum follow time).",
    tags=["Commands"],
    operation_id="add_raffle"
)
async def add_raffle(
    api_key: str = Query(...),
    name: str = Query(..., description="Raffle name", max_length=255),
    prize: str = Query("", description="Prize description"),
    number_of_winners: int = Query(1, description="How many winners to draw", ge=1),
    is_weighted: bool = Query(False, description="Weight entries by sub tier / VIP"),
    weight_sub_t1: float = Query(2.0, description="Tier 1 sub weight multiplier"),
    weight_sub_t2: float = Query(3.0, description="Tier 2 sub weight multiplier"),
    weight_sub_t3: float = Query(4.0, description="Tier 3 sub weight multiplier"),
    weight_vip: float = Query(1.5, description="VIP weight multiplier"),
    exclude_mods: bool = Query(False, description="Exclude moderators"),
    subscribers_only: bool = Query(False, description="Subscribers only"),
    followers_only: bool = Query(False, description="Followers only"),
    followers_min_enabled: bool = Query(False, description="Require a minimum follow time"),
    followers_min_value: int = Query(0, description="Minimum follow time amount", ge=0),
    followers_min_unit: str = Query("days", description="days | weeks | months | years"),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    cfg = _raffle_config_params(name, prize, number_of_winners, is_weighted,
                                weight_sub_t1, weight_sub_t2, weight_sub_t3, weight_vip,
                                exclude_mods, subscribers_only, followers_only,
                                followers_min_enabled, followers_min_value, followers_min_unit)
    cols = list(cfg.keys())
    vals = [cfg[c] for c in cols]
    placeholders = ", ".join(["%s"] * len(cols))
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                # Plain AUTO_INCREMENT id, matching how the dashboard (raffles.php) and the
                # bot (!createraffle) create raffles in this same table.
                await cursor.execute(
                    f"INSERT INTO raffles (status, {', '.join(cols)}) VALUES ('scheduled', {placeholders})",
                    vals,
                )
                new_id = cursor.lastrowid
                await connection.commit()
                if cursor.rowcount <= 0:
                    raise HTTPException(status_code=500, detail="Raffle was not added to the database")
            return {"status": "success", "user": username, "id": new_id, "message": "Raffle added successfully"}
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error adding raffle for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error adding raffle: {str(e)}")

@app.put(
    "/raffles/update",
    summary="Update a raffle's configuration",
    description="Update a raffle by id. Allowed only while the raffle is 'scheduled' (entry weights are baked in at join time, so editing once it is running/ended is rejected with 409).",
    tags=["Commands"],
    operation_id="update_raffle"
)
async def update_raffle(
    api_key: str = Query(...),
    id: int = Query(..., description="Raffle id to update"),
    name: str = Query(..., description="Raffle name", max_length=255),
    prize: str = Query("", description="Prize description"),
    number_of_winners: int = Query(1, description="How many winners to draw", ge=1),
    is_weighted: bool = Query(False, description="Weight entries by sub tier / VIP"),
    weight_sub_t1: float = Query(2.0, description="Tier 1 sub weight multiplier"),
    weight_sub_t2: float = Query(3.0, description="Tier 2 sub weight multiplier"),
    weight_sub_t3: float = Query(4.0, description="Tier 3 sub weight multiplier"),
    weight_vip: float = Query(1.5, description="VIP weight multiplier"),
    exclude_mods: bool = Query(False, description="Exclude moderators"),
    subscribers_only: bool = Query(False, description="Subscribers only"),
    followers_only: bool = Query(False, description="Followers only"),
    followers_min_enabled: bool = Query(False, description="Require a minimum follow time"),
    followers_min_value: int = Query(0, description="Minimum follow time amount", ge=0),
    followers_min_unit: str = Query("days", description="days | weeks | months | years"),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    cfg = _raffle_config_params(name, prize, number_of_winners, is_weighted,
                                weight_sub_t1, weight_sub_t2, weight_sub_t3, weight_vip,
                                exclude_mods, subscribers_only, followers_only,
                                followers_min_enabled, followers_min_value, followers_min_unit)
    cols = list(cfg.keys())
    set_clause = ", ".join(f"{c} = %s" for c in cols)
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status FROM raffles WHERE id = %s", (id,))
                existing = await cursor.fetchone()
                if not existing:
                    raise HTTPException(status_code=404, detail=f"Raffle {id} not found")
                if existing['status'] != 'scheduled':
                    raise HTTPException(status_code=409, detail="Only scheduled raffles can be edited")
                await cursor.execute(
                    f"UPDATE raffles SET {set_clause} WHERE id = %s",
                    [*[cfg[c] for c in cols], id],
                )
                await connection.commit()
            return {"status": "success", "user": username, "id": id, "message": "Raffle updated successfully"}
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error updating raffle for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error updating raffle: {str(e)}")

@app.put(
    "/raffles/start",
    summary="Start a scheduled raffle",
    description="Move a raffle from 'scheduled' to 'running' so viewers can enter with !joinraffle.",
    tags=["Commands"],
    operation_id="start_raffle"
)
async def start_raffle(api_key: str = Query(...), id: int = Query(..., description="Raffle id to start"), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status FROM raffles WHERE id = %s", (id,))
                existing = await cursor.fetchone()
                if not existing:
                    raise HTTPException(status_code=404, detail=f"Raffle {id} not found")
                if existing['status'] != 'scheduled':
                    raise HTTPException(status_code=409, detail="Only scheduled raffles can be started")
                await cursor.execute("UPDATE raffles SET status = 'running' WHERE id = %s", (id,))
                await connection.commit()
            return {"status": "success", "user": username, "id": id, "raffle_status": "running", "message": "Raffle started"}
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error starting raffle for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error starting raffle: {str(e)}")

@app.put(
    "/raffles/stop",
    summary="Stop a running raffle without drawing",
    description="End a running raffle ('running' -> 'ended') without selecting winners. Entries are kept.",
    tags=["Commands"],
    operation_id="stop_raffle"
)
async def stop_raffle(api_key: str = Query(...), id: int = Query(..., description="Raffle id to stop"), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("SELECT status FROM raffles WHERE id = %s", (id,))
                existing = await cursor.fetchone()
                if not existing:
                    raise HTTPException(status_code=404, detail=f"Raffle {id} not found")
                if existing['status'] != 'running':
                    raise HTTPException(status_code=409, detail="Only running raffles can be stopped")
                await cursor.execute("UPDATE raffles SET status = 'ended' WHERE id = %s", (id,))
                await connection.commit()
            return {"status": "success", "user": username, "id": id, "raffle_status": "ended", "message": "Raffle stopped"}
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error stopping raffle for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error stopping raffle: {str(e)}")

@app.post(
    "/raffles/draw",
    summary="Draw the winners of a running raffle",
    description="Select winners from a running raffle using weighted random selection without replacement (using each entry's stored weight), record them, mark the raffle 'ended', and broadcast a RAFFLE_WINNER event so the bot announces the winner(s) in chat.",
    tags=["Commands"],
    operation_id="draw_raffle"
)
async def draw_raffle(api_key: str = Query(...), id: int = Query(..., description="Raffle id to draw"), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    try:
        connection = await get_mysql_connection_user(username)
        winners = []
        raffle_name = None
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    "SELECT id, name, prize, number_of_winners, status FROM raffles WHERE id = %s",
                    (id,),
                )
                raffle = await cursor.fetchone()
                if not raffle:
                    raise HTTPException(status_code=404, detail=f"Raffle {id} not found")
                if raffle['status'] != 'running':
                    raise HTTPException(status_code=409, detail="Raffle must be running to draw winners")
                raffle_name = raffle['name']
                num = int(raffle['number_of_winners'] or 1)
                await cursor.execute(
                    "SELECT id, username, user_id, weight FROM raffle_entries WHERE raffle_id = %s",
                    (id,),
                )
                entry_rows = await cursor.fetchall()
                if not entry_rows:
                    raise HTTPException(status_code=400, detail="Raffle has no entries to draw from")
                # Weighted roulette without replacement (mirrors bot/dashboard draw).
                available = [{
                    "id": e['id'], "username": e['username'], "user_id": e['user_id'],
                    "weight": max(1, int(e['weight'] or 1)),
                } for e in entry_rows]
                for _ in range(min(num, len(available))):
                    total = sum(e['weight'] for e in available)
                    pick = random.randint(1, total)
                    running = 0
                    win_idx = -1
                    for idx, e in enumerate(available):
                        running += e['weight']
                        if running >= pick:
                            win_idx = idx
                            break
                    if win_idx < 0:
                        break
                    winners.append(available.pop(win_idx))
                if not winners:
                    raise HTTPException(status_code=500, detail="Failed to select winners")
                for w in winners:
                    await cursor.execute(
                        "INSERT INTO raffle_winners (raffle_id, entry_id, username, user_id) VALUES (%s, %s, %s, %s)",
                        (id, w['id'], w['username'], w['user_id']),
                    )
                await cursor.execute("UPDATE raffles SET status = 'ended' WHERE id = %s", (id,))
                await connection.commit()
        finally:
            connection.close()
        # Best-effort: broadcast each winner so the bot shouts them in chat. The draw is
        # already committed; a notify failure must not fail the request.
        winner_names = [w['username'] for w in winners]
        try:
            for wname in winner_names:
                params = {"event": "RAFFLE_WINNER", "channel": username, "raffle_name": raffle_name, "winner": wname}
                await websocket_notice("RAFFLE_WINNER", params, api_key)
        except Exception as notify_err:
            logging.warning(f"Raffle {id} drawn but winner notify failed: {notify_err}")
        return {"status": "success", "user": username, "id": id, "winners": winner_names, "message": "Raffle winners drawn"}
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error drawing raffle for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error drawing raffle: {str(e)}")

@app.delete(
    "/raffles/delete",
    summary="Delete a raffle",
    description="Remove a raffle (and, via cascade, its entries and winners) by id. Allowed in any state.",
    tags=["Commands"],
    operation_id="delete_raffle"
)
async def delete_raffle(api_key: str = Query(...), id: int = Query(..., description="Raffle id to delete"), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("DELETE FROM raffles WHERE id = %s", (id,))
                await connection.commit()
                if cursor.rowcount <= 0:
                    raise HTTPException(status_code=404, detail=f"Raffle {id} not found")
            return {"status": "success", "user": username, "id": id, "message": "Raffle deleted successfully"}
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error deleting raffle for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error deleting raffle: {str(e)}")

# Built-in Commands Endpoints
@app.get(
    "/builtin-commands",
    summary="Get list of built-in commands",
    description="Retrieve all built-in commands and their enabled/disabled status from your account's database.",
    tags=["Commands"],
    operation_id="get_builtin_commands"
)
async def get_builtin_commands(api_key: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute("""
                    SELECT command, status, permission, cooldown_rate, cooldown_time, cooldown_bucket
                    FROM builtin_commands
                    ORDER BY command ASC
                """)
                commands = await cursor.fetchall()
            return {
                "user": username,
                "total_commands": len(commands),
                "commands": [
                    {
                        "command": cmd["command"],
                        "status": cmd["status"],
                        "permission": cmd["permission"],
                        "cooldown_rate": cmd["cooldown_rate"],
                        "cooldown_time": cmd["cooldown_time"],
                        "cooldown_bucket": cmd["cooldown_bucket"],
                    }
                    for cmd in commands
                ],
            }
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error retrieving built-in commands for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error retrieving built-in commands: {str(e)}")

@app.put(
    "/builtin-commands/update",
    summary="Update a built-in command",
    description="Update the status and/or permission of a built-in command in your account's database.",
    tags=["Commands"],
    operation_id="update_builtin_command"
)
async def update_builtin_command(
    api_key: str = Query(...),
    command: str = Query(..., description="Built-in command name"),
    status: str = Query(None, description="New status: Enabled or Disabled"),
    permission: str = Query(None, description="New permission level"),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    if status is not None and status not in ("Enabled", "Disabled"):
        raise HTTPException(status_code=400, detail="status must be 'Enabled' or 'Disabled'")
    if permission is not None and permission not in VALID_PERMISSIONS:
        raise HTTPException(status_code=400, detail=f"permission must be one of: {', '.join(sorted(VALID_PERMISSIONS))}")
    fields, values = [], []
    if status is not None:
        fields.append("status = %s"); values.append(status)
    if permission is not None:
        fields.append("permission = %s"); values.append(permission)
    if not fields:
        raise HTTPException(status_code=400, detail="No fields provided to update")
    values.append(command)
    try:
        connection = await get_mysql_connection_user(username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    f"UPDATE builtin_commands SET {', '.join(fields)} WHERE command = %s",
                    values,
                )
                await connection.commit()
                if cursor.rowcount <= 0:
                    raise HTTPException(status_code=404, detail=f"Built-in command '{command}' not found")
            return {
                "status": "success",
                "user": username,
                "command": command,
                "message": f"Built-in command '{command}' updated successfully",
            }
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error updating built-in command for user '{username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error updating built-in command: {str(e)}")

# User Managed Commands Endpoint
@app.get(
    "/user-commands/get",
    summary="Get list of user managed commands",
    description="Retrieve all user managed commands available for your account from your database.",
    tags=["Commands"],
    operation_id="get_user_managed_commands"
)
async def get_user_managed_commands(
    api_key: str = Query(...),
    username: str = Query(..., description="Target username to fetch commands for"),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    owner_username = resolve_username(key_info, channel)
    target_username = username.strip()
    if not target_username:
        raise HTTPException(status_code=400, detail="username is required")
    try:
        connection = await get_mysql_connection_user(owner_username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    """
                    SELECT command, response, status, cooldown, user_id
                    FROM custom_user_commands
                    WHERE user_id = %s
                    ORDER BY command ASC
                    """,
                    (target_username,),
                )
                commands = await cursor.fetchall()
            command_list = []
            for cmd in commands:
                command_list.append({
                    "command": cmd["command"],
                    "response": cmd["response"],
                    "status": cmd["status"],
                    "cooldown": cmd["cooldown"],
                    "username": cmd["user_id"],
                })
            profile_images = await _get_twitch_profile_images([target_username])
            profile_image_url = profile_images.get(target_username.lower())
            return {
                "user": owner_username,
                "target_username": target_username,
                "profile_image_url": profile_image_url,
                "total_commands": len(command_list),
                "commands": command_list,
            }
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error retrieving user managed commands for user '{owner_username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error retrieving user managed commands: {str(e)}")

@app.get(
    "/user-commands/get/all",
    summary="Get all user managed commands",
    description="Retrieve every user managed command in your database, across all target users.",
    tags=["Commands"],
    operation_id="get_all_user_managed_commands"
)
async def get_all_user_managed_commands(
    api_key: str = Query(...),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    owner_username = resolve_username(key_info, channel)
    try:
        connection = await get_mysql_connection_user(owner_username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    """
                    SELECT command, response, status, cooldown, user_id
                    FROM custom_user_commands
                    ORDER BY user_id ASC, command ASC
                    """
                )
                commands = await cursor.fetchall()
            grouped: dict[str, list] = {}
            for cmd in commands:
                username_key = cmd["user_id"] or ""
                grouped.setdefault(username_key, []).append({
                    "command": cmd["command"],
                    "response": cmd["response"],
                    "status": cmd["status"],
                    "cooldown": cmd["cooldown"],
                })
            profile_images_lookup = await _get_twitch_profile_images(list(grouped.keys()))
            profile_images = {
                username_key: profile_images_lookup.get(username_key.lower())
                for username_key in grouped.keys()
            }
            return {
                "user": owner_username,
                "total_commands": len(commands),
                "commands": grouped,
                "profile_images": profile_images,
            }
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error retrieving all user managed commands for user '{owner_username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error retrieving all user managed commands: {str(e)}")

@app.post(
    "/user-commands/add",
    summary="Add a user managed command",
    description="Add a user managed command, matching dashboard sanitization and duplicate checks.",
    tags=["Commands"],
    operation_id="add_user_managed_command"
)
async def add_user_managed_command(
    api_key: str = Query(...),
    command: str = Query(..., description="Command name (without ! prefix)"),
    response: str = Query(..., description="Command response", max_length=500),
    cooldown: int = Query(15, description="Cooldown in seconds", ge=1),
    username: str = Query(..., description="Target username for this command"),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    owner_username = resolve_username(key_info, channel)
    new_command = _sanitize_user_command_name(command)
    if not new_command:
        raise HTTPException(status_code=400, detail="Command name is invalid after sanitization")
    target_username = username.strip()
    if not target_username:
        raise HTTPException(status_code=400, detail="username is required")
    try:
        connection = await get_mysql_connection_user(owner_username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    "SELECT command FROM custom_user_commands WHERE command = %s",
                    (new_command,),
                )
                existing = await cursor.fetchone()
                if existing:
                    raise HTTPException(
                        status_code=409,
                        detail=f"Command '{new_command}' already exists. Please choose a different name.",
                    )
                await cursor.execute(
                    """
                    INSERT INTO custom_user_commands (command, response, status, cooldown, user_id)
                    VALUES (%s, %s, 'Enabled', %s, %s)
                    """,
                    (new_command, response, cooldown, target_username),
                )
                await connection.commit()
                if cursor.rowcount <= 0:
                    raise HTTPException(status_code=500, detail="Command was not added to the database")
            return {
                "status": "success",
                "user": owner_username,
                "command": new_command,
                "username": target_username,
                "message": f"User command '{new_command}' for username '{target_username}' added successfully!",
            }
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error adding user managed command for user '{owner_username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error adding user managed command: {str(e)}")

@app.put(
    "/user-commands/update",
    summary="Update a user managed command",
    description="Update an existing user managed command. Only provided fields are changed.",
    tags=["Commands"],
    operation_id="update_user_managed_command"
)
async def update_user_managed_command(
    api_key: str = Query(...),
    command: str = Query(..., description="Existing command name to update (without ! prefix)"),
    username: str = Query(..., description="Target username this command belongs to"),
    new_command: str = Query(None, description="New command name to rename to (without ! prefix)"),
    response: str = Query(None, description="New response text", max_length=500),
    cooldown: int = Query(None, description="New cooldown in seconds", ge=1),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    owner_username = resolve_username(key_info, channel)
    command_name = _sanitize_user_command_name(command)
    if not command_name:
        raise HTTPException(status_code=400, detail="Command name is invalid after sanitization")
    target_username = username.strip()
    if not target_username:
        raise HTTPException(status_code=400, detail="username is required")
    renamed_command = None
    if new_command is not None:
        renamed_command = _sanitize_user_command_name(new_command)
        if not renamed_command:
            raise HTTPException(status_code=400, detail="new_command is invalid after sanitization")
    fields, values = [], []
    if renamed_command is not None and renamed_command != command_name:
        fields.append("command = %s"); values.append(renamed_command)
    if response is not None:
        fields.append("response = %s"); values.append(response)
    if cooldown is not None:
        fields.append("cooldown = %s"); values.append(cooldown)
    if not fields:
        raise HTTPException(status_code=400, detail="No fields provided to update")
    values.extend([command_name, target_username])
    try:
        connection = await get_mysql_connection_user(owner_username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                if renamed_command is not None and renamed_command != command_name:
                    await cursor.execute(
                        "SELECT command FROM custom_user_commands WHERE command = %s",
                        (renamed_command,),
                    )
                    if await cursor.fetchone():
                        raise HTTPException(
                            status_code=409,
                            detail=f"Command '{renamed_command}' already exists. Please choose a different name.",
                        )
                await cursor.execute(
                    f"UPDATE custom_user_commands SET {', '.join(fields)} WHERE command = %s AND user_id = %s",
                    values,
                )
                await connection.commit()
                if cursor.rowcount <= 0:
                    raise HTTPException(
                        status_code=404,
                        detail=f"Command '{command_name}' for username '{target_username}' not found",
                    )
            final_name = renamed_command if renamed_command is not None else command_name
            return {
                "status": "success",
                "user": owner_username,
                "command": final_name,
                "previous_command": command_name if renamed_command and renamed_command != command_name else None,
                "username": target_username,
                "message": f"User command '{final_name}' for username '{target_username}' updated successfully!",
            }
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error updating user managed command for user '{owner_username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error updating user managed command: {str(e)}")

@app.delete(
    "/user-commands/remove",
    summary="Remove a user managed command",
    description="Remove a user managed command by command name.",
    tags=["Commands"],
    operation_id="remove_user_managed_command"
)
async def remove_user_managed_command(
    api_key: str = Query(...),
    command: str = Query(..., description="Command name to remove (without ! prefix)"),
    username: str = Query(..., description="Target username this command belongs to"),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    owner_username = resolve_username(key_info, channel)
    command_name = _sanitize_user_command_name(command)
    if not command_name:
        raise HTTPException(status_code=400, detail="Command name is invalid after sanitization")
    target_username = username.strip()
    if not target_username:
        raise HTTPException(status_code=400, detail="username is required")
    try:
        connection = await get_mysql_connection_user(owner_username)
        try:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    "DELETE FROM custom_user_commands WHERE command = %s AND user_id = %s",
                    (command_name, target_username),
                )
                await connection.commit()
                if cursor.rowcount <= 0:
                    raise HTTPException(
                        status_code=404,
                        detail=f"Command '{command_name}' for username '{target_username}' not found",
                    )
            return {
                "status": "success",
                "user": owner_username,
                "command": command_name,
                "username": target_username,
                "message": f"User command '{command_name}' for username '{target_username}' deleted successfully!",
            }
        finally:
            connection.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error removing user managed command for user '{owner_username}': {e}")
        raise HTTPException(status_code=500, detail=f"Error removing user managed command: {str(e)}")

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
        params = {"event": "WEATHER_DATA", "weather_data": json.dumps(formatted_weather_data)}
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
    description="Send a text-to-speech (TTS) event to the WebSocket server. If voice or language are omitted, the user's saved TTS settings are used (defaulting to Alloy/en).",
    tags=["WebSocket Triggers"],
    response_model=StatusResponse,
    operation_id="trigger_websocket_tts"
)
async def websocket_tts(
    api_key: str = Query(...),
    text: str = Query(...),
    voice: str = Query(None, description="TTS voice to use. Defaults to the voice saved in the user's TTS settings."),
    language: str = Query(None, description="TTS language to use. Defaults to the language saved in the user's TTS settings."),
):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = valid
    # If either setting is missing, load defaults from the user's tts_settings table
    if not voice or not language:
        try:
            conn = await get_mysql_connection_user(username)
            try:
                async with conn.cursor(aiomysql.DictCursor) as cur:
                    await cur.execute("SELECT voice, language FROM tts_settings LIMIT 1")
                    row = await cur.fetchone()
            finally:
                conn.close()
            if row:
                if not voice:
                    voice = row.get("voice") or "Alloy"
                if not language:
                    language = row.get("language") or "en"
        except Exception as e:
            logging.warning(f"Could not load TTS settings for '{username}': {e}")
        voice = voice or "Alloy"
        language = language or "en"
    params = {"event": "TTS", "text": text, "voice": voice, "language": language}
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

# WebSocket Raffle Winner Trigger
@app.get(
    "/websocket/raffle_winner",
    summary="Trigger Raffle Winner via API",
    description="Notify WebSocket clients and the bot that a raffle winner has been selected.",
    tags=["WebSocket Triggers"],
    operation_id="trigger_websocket_raffle_winner"
)
async def websocket_raffle_winner(api_key: str = Query(...), raffle_name: str = Query(...), winner: str = Query(...)):
    valid = await verify_api_key(api_key)
    if not valid:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    params = {"event": "RAFFLE_WINNER", "channel": valid, "raffle_name": raffle_name, "winner": winner}
    await websocket_notice("RAFFLE_WINNER", params, api_key)
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
    summary="Validate a weather location",
    description="Used by the website profile page to verify that a user-entered location returns a valid result before saving it as their default weather location.",
    tags=["User Account"],
    operation_id="get_web_weather_location",
    include_in_schema=True
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
    response_model=CheckKeyResponse,
    summary="Check if the API key is valid",
    description="Validate an API key and return whether it is valid, including the resolved username.",
    tags=["User Account"],
    operation_id="check_api_key"
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
    response_model=StreamOnlineResponse,
    summary="Check if the stream is online",
    description="Check the current stream online status for the authenticated user.",
    tags=["User Account"],
    operation_id="get_stream_online_status"
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
        stream_title = None
        game_name = None
        if is_online:
            twitch_creds = await get_twitch_app_credentials()
            if twitch_creds:
                helix_url = "https://api.twitch.tv/helix/streams"
                headers = {
                    "Client-ID": twitch_creds["client_id"],
                    "Authorization": f"Bearer {twitch_creds['access_token']}"
                }
                params = {"user_login": username}
                try:
                    async with aiohttp.ClientSession() as session:
                        async with session.get(helix_url, headers=headers, params=params) as response:
                            if response.status == 200:
                                data = await response.json()
                                items = data.get("data", [])
                                if items:
                                    stream_title = items[0].get("title")
                                    game_name = items[0].get("game_name")
                            else:
                                twitch_error = await response.text()
                                logging.warning(f"Twitch Helix stream lookup failed for '{username}' with status {response.status}: {twitch_error}")
                except Exception as twitch_exc:
                    logging.warning(f"Failed to fetch Twitch stream metadata for '{username}': {twitch_exc}")
        return {
            "channel": username,
            "online": is_online,
            "stream_title": stream_title,
            "game_name": game_name,
        }
    except Exception as e:
        logging.error(f"Error checking stream online status from database: {e}")
        raise HTTPException(status_code=500, detail=f"Error checking stream online status: {str(e)}")

# Rolling window labels -> day count (calendar-free, timezone-safe).
_DASH_WINDOWS = {"today": 1, "24h": 1, "7d": 7, "30d": 30}

async def _dashboard_period_counts(cur, expr: str, param):
    # expr is one of two FIXED internal SQL expressions ("NOW() - INTERVAL %s DAY"
    # or "FROM_UNIXTIME(%s)") -- never user input -- with `param` bound safely.
    out = {}
    await cur.execute(f"SELECT COUNT(*) AS c FROM followers_data WHERE followed_at >= {expr}", (param,))
    out["followers"] = int((await cur.fetchone())["c"])
    await cur.execute(f"SELECT sub_plan, COUNT(*) AS c FROM subscription_data WHERE timestamp >= {expr} GROUP BY sub_plan", (param,))
    subs = {"t1": 0, "t2": 0, "t3": 0, "prime": 0, "total": 0}
    plan_map = {"1000": "t1", "2000": "t2", "3000": "t3", "prime": "prime"}
    for r in await cur.fetchall():
        cnt = int(r["c"])
        subs["total"] += cnt
        key = plan_map.get(str(r["sub_plan"] or "").strip().lower())
        if key:
            subs[key] += cnt
    out["subs"] = subs
    await cur.execute(f"SELECT COALESCE(SUM(bits),0) AS s FROM bits_data WHERE timestamp >= {expr}", (param,))
    out["bits"] = int((await cur.fetchone())["s"])
    await cur.execute(f"SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS s FROM tipping WHERE COALESCE(created_at, timestamp) >= {expr}", (param,))
    trow = await cur.fetchone()
    out["tips"] = {"count": int(trow["c"]), "amount": float(trow["s"] or 0)}
    await cur.execute(f"SELECT COUNT(*) AS c, COALESCE(SUM(viewers),0) AS v FROM raid_data WHERE timestamp >= {expr}", (param,))
    rrow = await cur.fetchone()
    out["raids"] = {"count": int(rrow["c"]), "viewers": int(rrow["v"])}
    await cur.execute(f"SELECT COUNT(*) AS c FROM seen_users WHERE first_seen >= {expr}", (param,))
    out["new_viewers"] = int((await cur.fetchone())["c"])
    await cur.execute(f"SELECT COUNT(*) AS c FROM quotes WHERE added >= {expr}", (param,))
    out["new_quotes"] = int((await cur.fetchone())["c"])
    await cur.execute(f"SELECT COUNT(*) AS c FROM chat_history WHERE timestamp >= {expr}", (param,))
    out["chat_messages"] = int((await cur.fetchone())["c"])
    return out

class DashboardLiveResponse(BaseModel):
    channel: str
    online: bool
    started_at: str | None = None
    uptime_seconds: int = 0
    viewer_count: int = 0
    title: str | None = None
    game: str | None = None

@app.get(
    "/dashboard/live",
    response_model=DashboardLiveResponse,
    summary="Dashboard: live stream seed",
    description="Seed the dashboard live ribbon (online, uptime, viewers, title, game). The dashboard keeps it current via WebSocket STREAM_ONLINE/STREAM_OFFLINE events; the socket replays no state, so this seed is required on load.",
    tags=["Dashboard"],
    operation_id="get_dashboard_live"
)
async def get_dashboard_live(api_key: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    db_online = False
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor() as cur:
                await cur.execute("SELECT status FROM stream_status")
                row = await cur.fetchone()
                if row and row[0]:
                    db_online = str(row[0]).lower() == "true"
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Dashboard live: stream_status read failed for '{username}': {e}")
    online = db_online
    started_at = None
    uptime_seconds = 0
    viewer_count = 0
    title = None
    game = None
    creds = await get_twitch_app_credentials()
    if creds:
        try:
            async with aiohttp.ClientSession() as session:
                async with session.get(
                    "https://api.twitch.tv/helix/streams",
                    headers={"Client-ID": creds["client_id"], "Authorization": f"Bearer {creds['access_token']}"},
                    params={"user_login": username},
                ) as resp:
                    if resp.status == 200:
                        data = await resp.json()
                        items = data.get("data", [])
                        if items:
                            s = items[0]
                            online = True
                            started_at = s.get("started_at")
                            viewer_count = int(s.get("viewer_count") or 0)
                            title = s.get("title")
                            game = s.get("game_name")
                            if started_at:
                                try:
                                    start_dt = datetime.fromisoformat(started_at.replace("Z", "+00:00"))
                                    uptime_seconds = max(0, int((datetime.now(timezone.utc) - start_dt).total_seconds()))
                                except Exception:
                                    uptime_seconds = 0
                        else:
                            online = False
        except Exception as e:
            logging.warning(f"Dashboard live: Helix lookup failed for '{username}': {e}")
    return {
        "channel": username,
        "online": online,
        "started_at": started_at,
        "uptime_seconds": uptime_seconds,
        "viewer_count": viewer_count,
        "title": title,
        "game": game,
    }

class DashboardSummaryResponse(BaseModel):
    channel: str
    window_days: int
    lifetime: dict
    window: dict
    since_visit: dict | None = None

@app.get(
    "/dashboard/summary",
    response_model=DashboardSummaryResponse,
    summary="Dashboard: bot-activity totals + windowed deltas",
    description="Lifetime totals of what the bot did (all-time), plus rolling-window deltas (today|24h|7d|30d) and optional since-last-visit deltas for the authenticated channel.",
    tags=["Dashboard"],
    operation_id="get_dashboard_summary"
)
async def get_dashboard_summary(api_key: str = Query(...), channel: str = Query(None), window: str = Query("7d"), since: int = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    days = _DASH_WINDOWS.get(str(window).lower(), 7)
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                lifetime = {}
                await cur.execute("SELECT COALESCE(SUM(count),0) AS s FROM custom_counts")
                cc = int((await cur.fetchone())["s"])
                await cur.execute("SELECT COALESCE(SUM(count),0) AS s FROM user_counts")
                uc = int((await cur.fetchone())["s"])
                lifetime["commands"] = cc + uc
                await cur.execute("SELECT COALESCE(SUM(count),0) AS s FROM reward_counts")
                lifetime["rewards"] = int((await cur.fetchone())["s"])
                await cur.execute("SELECT COALESCE(SUM(death_count),0) AS s FROM game_deaths")
                lifetime["deaths"] = int((await cur.fetchone())["s"])
                await cur.execute("SELECT COUNT(*) AS c FROM song_request_analytics")
                lifetime["songs"] = int((await cur.fetchone())["c"])
                await cur.execute("SELECT COUNT(*) AS c FROM seen_users")
                lifetime["welcomed"] = int((await cur.fetchone())["c"])
                await cur.execute("SELECT COUNT(*) AS c FROM shoutout_history")
                lifetime["shoutouts"] = int((await cur.fetchone())["c"])
                await cur.execute("SELECT COUNT(*) AS c FROM quotes")
                lifetime["quotes"] = int((await cur.fetchone())["c"])
                await cur.execute("SELECT COALESCE(SUM(points),0) AS s FROM bot_points")
                lifetime["points"] = int((await cur.fetchone())["s"])
                await cur.execute(
                    "SELECT (COALESCE((SELECT SUM(hug_count) FROM hug_counts),0) + "
                    "COALESCE((SELECT SUM(kiss_count) FROM kiss_counts),0) + "
                    "COALESCE((SELECT SUM(highfive_count) FROM highfive_counts),0)) AS s"
                )
                lifetime["interactions"] = int((await cur.fetchone())["s"])
                window_deltas = await _dashboard_period_counts(cur, "NOW() - INTERVAL %s DAY", days)
                since_deltas = None
                if since:
                    since_deltas = await _dashboard_period_counts(cur, "FROM_UNIXTIME(%s)", int(since))
        finally:
            conn.close()
        return {"channel": username, "window_days": days, "lifetime": lifetime, "window": window_deltas, "since_visit": since_deltas}
    except Exception as e:
        logging.error(f"Dashboard summary error for '{username}': {e}")
        raise HTTPException(status_code=500, detail="Error retrieving dashboard summary")

class DashboardTrendsResponse(BaseModel):
    channel: str
    days: int
    series: dict

@app.get(
    "/dashboard/trends",
    response_model=DashboardTrendsResponse,
    summary="Dashboard: daily trend buckets",
    description="Daily counts for sparklines (followers, subs, bits, chat volume) over the last N days for the authenticated channel.",
    tags=["Dashboard"],
    operation_id="get_dashboard_trends"
)
async def get_dashboard_trends(api_key: str = Query(...), channel: str = Query(None), days: int = Query(30)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    days = max(1, min(int(days), 365))
    queries = [
        ("followers", "SELECT DATE(followed_at) AS d, COUNT(*) AS c FROM followers_data WHERE followed_at >= NOW() - INTERVAL %s DAY GROUP BY DATE(followed_at) ORDER BY d"),
        ("subs", "SELECT DATE(timestamp) AS d, COUNT(*) AS c FROM subscription_data WHERE timestamp >= NOW() - INTERVAL %s DAY GROUP BY DATE(timestamp) ORDER BY d"),
        ("bits", "SELECT DATE(timestamp) AS d, COALESCE(SUM(bits),0) AS c FROM bits_data WHERE timestamp >= NOW() - INTERVAL %s DAY GROUP BY DATE(timestamp) ORDER BY d"),
        ("chat", "SELECT DATE(timestamp) AS d, COUNT(*) AS c FROM chat_history WHERE timestamp >= NOW() - INTERVAL %s DAY GROUP BY DATE(timestamp) ORDER BY d"),
    ]
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                series = {}
                for key, sql in queries:
                    await cur.execute(sql, (days,))
                    series[key] = [{"date": str(r["d"]), "count": int(r["c"])} for r in await cur.fetchall()]
        finally:
            conn.close()
        return {"channel": username, "days": days, "series": series}
    except Exception as e:
        logging.error(f"Dashboard trends error for '{username}': {e}")
        raise HTTPException(status_code=500, detail="Error retrieving dashboard trends")

KNOWN_BOT_LOGINS = {
    "botofthespecter", "nightbot", "streamelements", "streamlabs", "moobot",
    "fossabot", "wizebot", "soundalerts", "pretzelrocks", "streamstickers",
    "tangiabot", "kofistreambot", "blerp", "own3d", "streamlootsbot",
    "restreambot", "songlistbot", "deepbot", "vivbot", "phantombot", "coebot",
    "sery_bot", "lattemotte", "creatisbot", "commanderroot", "anotherttvviewer",
    "communityshowcase", "lurxx", "0_applejuice_0", "electricallongboard",
    "feardn", "icewaslit", "p0lizei_", "n3td3v", "8hvdes", "host_giveaway",
    "twitchprimereminder", "streamfahrer", "logviewer", "v_and_k", "the_marlchurch",
}

class DashboardLeaderboardsResponse(BaseModel):
    channel: str
    limit: int
    top_commands: list
    top_rewards: list
    watch_time: list
    streaks: list
    deaths_by_game: list
    chat_leaders: list
    top_songs: list
    interactions: dict

@app.get(
    "/dashboard/leaderboards",
    response_model=DashboardLeaderboardsResponse,
    summary="Dashboard: community leaderboards",
    description="Top commands, rewards, watch-time, loyalty streaks, deaths by game, chat leaders, top songs and interaction leaders for the authenticated channel.",
    tags=["Dashboard"],
    operation_id="get_dashboard_leaderboards"
)
async def get_dashboard_leaderboards(api_key: str = Query(...), channel: str = Query(None), limit: int = Query(10)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    limit = max(1, min(int(limit), 50))
    ilim = min(limit, 5)
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                excluded_logins = set(KNOWN_BOT_LOGINS)
                try:
                    await cur.execute("SELECT excluded_users FROM watch_time_excluded_users LIMIT 1")
                    ex_row = await cur.fetchone()
                    if ex_row and ex_row.get("excluded_users"):
                        excluded_logins.update(u.strip().lower() for u in ex_row["excluded_users"].split(",") if u.strip())
                except Exception:
                    pass  # table may not exist yet for a brand-new channel
                bot_ph = ",".join(["%s"] * len(excluded_logins))  # placeholders only, no user data
                excluded_params = tuple(excluded_logins)
                await cur.execute("SELECT command, count FROM custom_counts ORDER BY count DESC LIMIT %s", (limit,))
                top_commands = [{"command": r["command"], "count": int(r["count"] or 0)} for r in await cur.fetchall()]
                await cur.execute(
                    "SELECT COALESCE(c.reward_title, rc.reward_id) AS title, SUM(rc.count) AS total "
                    "FROM reward_counts rc LEFT JOIN channel_point_rewards c ON rc.reward_id = c.reward_id "
                    "GROUP BY rc.reward_id, c.reward_title ORDER BY total DESC LIMIT %s",
                    (limit,)
                )
                top_rewards = [{"reward_title": r["title"], "count": int(r["total"] or 0)} for r in await cur.fetchall()]
                await cur.execute(
                    f"SELECT username, total_watch_time_live, total_watch_time_offline FROM watch_time "
                    f"WHERE LOWER(username) NOT IN ({bot_ph}) ORDER BY total_watch_time_live DESC LIMIT %s",
                    excluded_params + (limit,)
                )
                watch_time = [{"username": r["username"], "live": int(r["total_watch_time_live"] or 0), "offline": int(r["total_watch_time_offline"] or 0)} for r in await cur.fetchall()]
                await cur.execute("SELECT user_name, streak_value, highest_streak, total_streams_watched FROM analytic_stream_watch_streak ORDER BY highest_streak DESC LIMIT %s", (limit,))
                streaks = [{"username": r["user_name"], "current": int(r["streak_value"] or 0), "highest": int(r["highest_streak"] or 0), "total": int(r["total_streams_watched"] or 0)} for r in await cur.fetchall()]
                await cur.execute("SELECT game_name, death_count FROM game_deaths ORDER BY death_count DESC LIMIT %s", (limit,))
                deaths_by_game = [{"game": r["game_name"], "deaths": int(r["death_count"] or 0)} for r in await cur.fetchall()]
                await cur.execute(
                    f"SELECT username, message_count FROM message_counts "
                    f"WHERE LOWER(username) NOT IN ({bot_ph}) ORDER BY message_count DESC LIMIT %s",
                    excluded_params + (limit,)
                )
                chat_leaders = [{"username": r["username"], "messages": int(r["message_count"] or 0)} for r in await cur.fetchall()]
                await cur.execute("SELECT song_name, artist_name, COUNT(*) AS c FROM song_request_analytics GROUP BY song_name, artist_name ORDER BY c DESC LIMIT %s", (limit,))
                top_songs = [{"song": r["song_name"], "artist": r["artist_name"], "count": int(r["c"] or 0)} for r in await cur.fetchall()]
                interactions = {}
                await cur.execute("SELECT username, hug_count AS c FROM hug_counts ORDER BY hug_count DESC LIMIT %s", (ilim,))
                interactions["hugs"] = [{"username": r["username"], "count": int(r["c"] or 0)} for r in await cur.fetchall()]
                await cur.execute("SELECT username, kiss_count AS c FROM kiss_counts ORDER BY kiss_count DESC LIMIT %s", (ilim,))
                interactions["kisses"] = [{"username": r["username"], "count": int(r["c"] or 0)} for r in await cur.fetchall()]
                await cur.execute("SELECT username, highfive_count AS c FROM highfive_counts ORDER BY highfive_count DESC LIMIT %s", (ilim,))
                interactions["highfives"] = [{"username": r["username"], "count": int(r["c"] or 0)} for r in await cur.fetchall()]
        finally:
            conn.close()
        return {
            "channel": username, "limit": limit,
            "top_commands": top_commands, "top_rewards": top_rewards, "watch_time": watch_time,
            "streaks": streaks, "deaths_by_game": deaths_by_game, "chat_leaders": chat_leaders,
            "top_songs": top_songs, "interactions": interactions,
        }
    except Exception as e:
        logging.error(f"Dashboard leaderboards error for '{username}': {e}")
        raise HTTPException(status_code=500, detail="Error retrieving dashboard leaderboards")

class DashboardActivityResponse(BaseModel):
    channel: str
    items: list

@app.get(
    "/dashboard/activity",
    response_model=DashboardActivityResponse,
    summary="Dashboard: recent activity feed",
    description="Most recent events merged across follows, subs, cheers, tips, raids, redemptions and quotes for the authenticated channel, newest first.",
    tags=["Dashboard"],
    operation_id="get_dashboard_activity"
)
async def get_dashboard_activity(api_key: str = Query(...), channel: str = Query(None), limit: int = Query(25)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    limit = max(1, min(int(limit), 100))
    sql = (
        "SELECT * FROM ("
        " SELECT 'follow' AS type, CONVERT(user_name USING utf8mb4) AS actor, CONVERT('' USING utf8mb4) AS detail, followed_at AS at FROM followers_data"
        " UNION ALL"
        " SELECT 'sub', CONVERT(user_name USING utf8mb4), CONVERT(CONCAT(COALESCE(sub_plan,''), CASE WHEN months IS NOT NULL THEN CONCAT(' ', months, 'mo') ELSE '' END) USING utf8mb4), timestamp FROM subscription_data"
        " UNION ALL"
        " SELECT 'cheer', CONVERT(user_name USING utf8mb4), CONVERT(CAST(bits AS CHAR) USING utf8mb4), timestamp FROM bits_data"
        " UNION ALL"
        " SELECT 'tip', CONVERT(username USING utf8mb4), CONVERT(CONCAT(COALESCE(CAST(amount AS CHAR),''), ' ', COALESCE(currency,'')) USING utf8mb4), COALESCE(created_at, timestamp) FROM tipping"
        " UNION ALL"
        " SELECT 'raid', CONVERT(raider_name USING utf8mb4), CONVERT(CAST(viewers AS CHAR) USING utf8mb4), timestamp FROM raid_data"
        " UNION ALL"
        " SELECT 'redeem', CONVERT(sr.username USING utf8mb4), COALESCE(CONVERT(cpr.reward_title USING utf8mb4), CONVERT(sr.reward_id USING utf8mb4)), sr.redeemed_at FROM stored_redeems sr LEFT JOIN channel_point_rewards cpr ON CONVERT(sr.reward_id USING utf8mb4) = CONVERT(cpr.reward_id USING utf8mb4)"
        " UNION ALL"
        " SELECT 'quote', CONVERT('' USING utf8mb4), CONVERT(LEFT(quote, 80) USING utf8mb4), added FROM quotes"
        ") AS feed WHERE at IS NOT NULL ORDER BY at DESC LIMIT %s"
    )
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute(sql, (limit,))
                rows = await cur.fetchall()
        finally:
            conn.close()
        items = []
        for r in rows:
            at = r["at"]
            at_str = at.isoformat() if hasattr(at, "isoformat") else (str(at) if at is not None else None)
            items.append({
                "type": r["type"],
                "actor": r["actor"] or "",
                "detail": (str(r["detail"]).strip() if r["detail"] is not None else ""),
                "at": at_str,
            })
        return {"channel": username, "items": items}
    except Exception as e:
        logging.error(f"Dashboard activity error for '{username}': {e}")
        raise HTTPException(status_code=500, detail="Error retrieving dashboard activity")

# Death Counter Endpoint
@app.get(
    "/deaths",
    response_model=DeathCountResponse,
    summary="Get current death counter",
    description="Retrieve the current total death count for the authenticated user.",
    tags=["User Account"],
    operation_id="get_death_count"
)
async def get_death_count(api_key: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    current_game = await _get_current_game_from_twitch(username)
    if not current_game:
        raise HTTPException(status_code=404, detail="No active stream or current game not set")
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT death_count FROM game_deaths WHERE game_name = %s", (current_game,))
                row = await cur.fetchone()
                game_deaths = row["death_count"] if row else 0
                await cur.execute("SELECT death_count FROM per_stream_deaths WHERE game_name = %s", (current_game,))
                row = await cur.fetchone()
                stream_deaths = row["death_count"] if row else 0
                await cur.execute("SELECT COALESCE(SUM(death_count), 0) AS total FROM game_deaths")
                row = await cur.fetchone()
                total_deaths = int(row["total"]) if row else 0
        finally:
            conn.close()
        return {"game": current_game, "game_deaths": game_deaths, "stream_deaths": stream_deaths, "total_deaths": total_deaths}
    except Exception as e:
        logging.error(f"Error fetching death count for '{username}': {e}")
        raise HTTPException(status_code=500, detail="Error fetching death count")

async def _get_current_game_from_twitch(username: str) -> str | None:
    twitch_creds = await get_twitch_app_credentials()
    if not twitch_creds:
        return None
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(
                "https://api.twitch.tv/helix/streams",
                headers={"Client-ID": twitch_creds["client_id"], "Authorization": f"Bearer {twitch_creds['access_token']}"},
                params={"user_login": username}
            ) as resp:
                if resp.status == 200:
                    data = await resp.json()
                    items = data.get("data", [])
                    if items:
                        return items[0].get("game_name")
    except Exception as e:
        logging.warning(f"Failed to fetch current game from Twitch for '{username}': {e}")
    return None

@app.post(
    "/deaths/add",
    summary="Add a death to the current game",
    description="Increment the death counter for the current game by 1 in both game_deaths and per_stream_deaths.",
    tags=["User Account"],
    operation_id="add_death"
)
async def add_death(api_key: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    current_game = await _get_current_game_from_twitch(username)
    if not current_game:
        raise HTTPException(status_code=404, detail="No active stream or current game not set")
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("""
                    INSERT INTO game_deaths (game_name, death_count) VALUES (%s, 1)
                    ON DUPLICATE KEY UPDATE death_count = death_count + 1
                """, (current_game,))
                await cur.execute("""
                    INSERT INTO per_stream_deaths (game_name, death_count) VALUES (%s, 1)
                    ON DUPLICATE KEY UPDATE death_count = death_count + 1
                """, (current_game,))
                await conn.commit()
                await cur.execute("SELECT death_count FROM game_deaths WHERE game_name = %s", (current_game,))
                row = await cur.fetchone()
                game_deaths = row["death_count"] if row else 1
                await cur.execute("SELECT death_count FROM per_stream_deaths WHERE game_name = %s", (current_game,))
                row = await cur.fetchone()
                stream_deaths = row["death_count"] if row else 1
                await cur.execute("SELECT COALESCE(SUM(death_count), 0) AS total FROM game_deaths")
                row = await cur.fetchone()
                total_deaths = int(row["total"]) if row else 0
        finally:
            conn.close()
        try:
            await websocket_notice("DEATHS", {"event": "DEATHS", "death-text": stream_deaths, "game": current_game}, api_key)
        except Exception as ws_exc:
            logging.warning(f"Death add: websocket notify failed for '{username}': {ws_exc}")
        return {"game": current_game, "game_deaths": game_deaths, "stream_deaths": stream_deaths, "total_deaths": total_deaths}
    except Exception as e:
        logging.error(f"Error adding death for '{username}': {e}")
        raise HTTPException(status_code=500, detail="Error adding death")

@app.post(
    "/deaths/remove",
    summary="Remove a death from the current game",
    description="Decrement the death counter for the current game by 1 in both game_deaths and per_stream_deaths. Will not go below 0.",
    tags=["User Account"],
    operation_id="remove_death"
)
async def remove_death(api_key: str = Query(...), channel: str = Query(None)):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    current_game = await _get_current_game_from_twitch(username)
    if not current_game:
        raise HTTPException(status_code=404, detail="No active stream or current game not set")
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("""
                    UPDATE game_deaths SET death_count = GREATEST(death_count - 1, 0)
                    WHERE game_name = %s
                """, (current_game,))
                await cur.execute("""
                    UPDATE per_stream_deaths SET death_count = GREATEST(death_count - 1, 0)
                    WHERE game_name = %s
                """, (current_game,))
                await conn.commit()
                await cur.execute("SELECT death_count FROM game_deaths WHERE game_name = %s", (current_game,))
                row = await cur.fetchone()
                game_deaths = row["death_count"] if row else 0
                await cur.execute("SELECT death_count FROM per_stream_deaths WHERE game_name = %s", (current_game,))
                row = await cur.fetchone()
                stream_deaths = row["death_count"] if row else 0
                await cur.execute("SELECT COALESCE(SUM(death_count), 0) AS total FROM game_deaths")
                row = await cur.fetchone()
                total_deaths = int(row["total"]) if row else 0
        finally:
            conn.close()
        try:
            await websocket_notice("DEATHS", {"event": "DEATHS", "death-text": stream_deaths, "game": current_game}, api_key)
        except Exception as ws_exc:
            logging.warning(f"Death remove: websocket notify failed for '{username}': {ws_exc}")
        return {"game": current_game, "game_deaths": game_deaths, "stream_deaths": stream_deaths, "total_deaths": total_deaths}
    except Exception as e:
        logging.error(f"Error removing death for '{username}': {e}")
        raise HTTPException(status_code=500, detail="Error removing death")

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

@app.get(
    "/discord/twitch-link",
    summary="Get Discord to Twitch link",
    tags=["Admin Only"]
)
async def discord_twitch_link_status(api_key: str = Query(...), discord_user_id: str = Query(...)):
    key_info = await verify_key(api_key)
    if not key_info or key_info["type"] != "admin":
        raise HTTPException(status_code=401, detail="Invalid Admin API Key")
    try:
        conn = await get_mysql_connection()
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute(
                    """
                    SELECT discord_user_id, twitch_user_id, twitch_username, created_at, updated_at
                    FROM discord_twitch_links
                    WHERE discord_user_id = %s
                    """,
                    (str(discord_user_id),)
                )
                row = await cur.fetchone()
                return {
                    "discord_user_id": str(discord_user_id),
                    "linked": row is not None,
                    "link": row
                }
        finally:
            conn.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error checking Discord/Twitch link status: {e}")
        raise HTTPException(status_code=500, detail="Error checking Discord/Twitch link status")

@app.post(
    "/discord/twitch-link/request",
    summary="Create one-time Twitch link token for a Discord user",
    tags=["Admin Only"]
)
async def discord_twitch_link_request(api_key: str = Query(...), discord_user_id: str = Query(...)):
    key_info = await verify_key(api_key)
    if not key_info or key_info["type"] != "admin":
        raise HTTPException(status_code=401, detail="Invalid Admin API Key")
    discord_user_id = str(discord_user_id).strip()
    if not discord_user_id:
        raise HTTPException(status_code=400, detail="discord_user_id is required")
    try:
        conn = await get_mysql_connection()
        try:
            raw_token = secrets.token_urlsafe(32)
            token_hash = hashlib.sha256(raw_token.encode("utf-8")).hexdigest()
            async with conn.cursor() as cur:
                await cur.execute(
                    "DELETE FROM discord_twitch_link_tokens WHERE discord_user_id = %s AND used_at IS NULL",
                    (discord_user_id,)
                )
                await cur.execute(
                    "DELETE FROM discord_twitch_link_tokens WHERE token_expires_at < UTC_TIMESTAMP()"
                )
                await cur.execute(
                    """
                    INSERT INTO discord_twitch_link_tokens (discord_user_id, token_hash, token_expires_at)
                    VALUES (%s, %s, DATE_ADD(UTC_TIMESTAMP(), INTERVAL %s MINUTE))
                    """,
                    (discord_user_id, token_hash, DISCORD_TWITCH_LINK_TOKEN_TTL_MINUTES)
                )
            await conn.commit()
            link_url = f"{DISCORD_TWITCH_LINK_BASE_URL}?token={quote(raw_token, safe='')}"
            return {
                "discord_user_id": discord_user_id,
                "link_url": link_url,
                "expires_in_minutes": DISCORD_TWITCH_LINK_TOKEN_TTL_MINUTES
            }
        finally:
            conn.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error creating Discord/Twitch link token: {e}")
        raise HTTPException(status_code=500, detail="Error creating link token")

@app.post(
    "/discord/twitch-link/unlink",
    summary="Unlink Discord user from Twitch account",
    tags=["Admin Only"]
)
async def discord_twitch_unlink(api_key: str = Query(...), discord_user_id: str = Query(...)):
    key_info = await verify_key(api_key)
    if not key_info or key_info["type"] != "admin":
        raise HTTPException(status_code=401, detail="Invalid Admin API Key")
    discord_user_id = str(discord_user_id).strip()
    if not discord_user_id:
        raise HTTPException(status_code=400, detail="discord_user_id is required")
    try:
        conn = await get_mysql_connection()
        try:
            async with conn.cursor() as cur:
                await cur.execute("DELETE FROM discord_twitch_links WHERE discord_user_id = %s", (discord_user_id,))
                unlinked_rows = cur.rowcount
                await cur.execute(
                    "DELETE FROM discord_twitch_link_tokens WHERE discord_user_id = %s AND used_at IS NULL",
                    (discord_user_id,)
                )
                cleared_pending_tokens = cur.rowcount
            await conn.commit()
            return {
                "discord_user_id": discord_user_id,
                "unlinked": unlinked_rows > 0,
                "unlinked_rows": unlinked_rows,
                "cleared_pending_tokens": cleared_pending_tokens
            }
        finally:
            conn.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error unlinking Discord/Twitch link: {e}")
        raise HTTPException(status_code=500, detail="Error unlinking Discord/Twitch link")

@app.post(
    "/discord/twitch-link/confirm",
    summary="Confirm Discord to Twitch link using one-time token",
    tags=["User Account"]
)
async def discord_twitch_link_confirm(api_key: str = Query(...), token: str = Query(...)):
    key_info = await verify_key(api_key)
    if not key_info or key_info["type"] != "user":
        raise HTTPException(status_code=401, detail="Invalid API Key")
    token = (token or "").strip()
    if not token:
        raise HTTPException(status_code=400, detail="token is required")
    token_hash = hashlib.sha256(token.encode("utf-8")).hexdigest()
    username = key_info["username"]
    try:
        conn = await get_mysql_connection()
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute(
                    """
                    SELECT id, discord_user_id
                    FROM discord_twitch_link_tokens
                    WHERE token_hash = %s
                      AND used_at IS NULL
                      AND token_expires_at >= UTC_TIMESTAMP()
                    """,
                    (token_hash,)
                )
                token_row = await cur.fetchone()
                if not token_row:
                    raise HTTPException(status_code=400, detail="Invalid or expired token")
                await cur.execute(
                    "SELECT id, username, twitch_user_id, twitch_display_name FROM users WHERE username = %s",
                    (username,)
                )
                user_row = await cur.fetchone()
                if not user_row:
                    raise HTTPException(status_code=404, detail="User account not found")
                twitch_user_id = str(user_row.get("twitch_user_id") or "").strip()
                if not twitch_user_id:
                    raise HTTPException(status_code=400, detail="Twitch account is missing for this user")
                twitch_username = user_row.get("twitch_display_name") or user_row.get("username")
                discord_user_id = str(token_row["discord_user_id"])
                await cur.execute(
                    """
                    SELECT discord_user_id
                    FROM discord_twitch_links
                    WHERE twitch_user_id = %s AND discord_user_id <> %s
                    """,
                    (twitch_user_id, discord_user_id)
                )
                conflict = await cur.fetchone()
                if conflict:
                    raise HTTPException(status_code=409, detail="This Twitch account is already linked to another Discord user")
                await cur.execute(
                    """
                    INSERT INTO discord_twitch_links (discord_user_id, twitch_user_id, twitch_username)
                    VALUES (%s, %s, %s)
                    ON DUPLICATE KEY UPDATE
                        twitch_user_id = VALUES(twitch_user_id),
                        twitch_username = VALUES(twitch_username),
                        updated_at = CURRENT_TIMESTAMP
                    """,
                    (discord_user_id, twitch_user_id, twitch_username)
                )
                await cur.execute(
                    "UPDATE discord_twitch_link_tokens SET used_at = UTC_TIMESTAMP() WHERE id = %s",
                    (token_row["id"],)
                )
            await conn.commit()
            return {
                "success": True,
                "discord_user_id": discord_user_id,
                "twitch_user_id": twitch_user_id,
                "twitch_username": twitch_username
            }
        finally:
            conn.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error confirming Discord/Twitch link: {e}")
        raise HTTPException(status_code=500, detail="Error confirming Discord/Twitch link")

# Function to check bot status via SSH
async def get_bot_status_via_ssh(username: str) -> dict:
    # Load latest versions from versions.json
    versions_path = None
    for path in VERSIONS_FILE_PATHS:
        if os.path.exists(path):
            versions_path = path
            break
    latest_versions = {}
    if versions_path:
        try:
            with open(versions_path, "r") as versions_file:
                latest_versions = json.load(versions_file)
        except Exception as e:
            logging.error(f"[bot_status] failed to load versions.json: {e}")
    if not all([BOTS_SSH_HOST, SSH_USERNAME, SSH_PASSWORD]):
        logging.warning(f"[bot_status] skipped for '{username}': missing SSH credentials (BOT-SRV-HOST={BOTS_SSH_HOST!r}, SSH_USERNAME set={bool(SSH_USERNAME)}, SSH_PASSWORD set={bool(SSH_PASSWORD)})")
        return {
            "running": False,
            "pid": None,
            "version": None,
            "bot_type": None,
            "outdated": None,
            "latest_version": latest_versions.get("stable_version")
        }
    status_script = "/home/botofthespecter/status.py"
    version_base = "/home/botofthespecter/logs/version"
    version_files = {
        "stable": f"{version_base}/{username}_version_control.txt",
        "beta": f"{version_base}/beta/{username}_beta_version_control.txt",
        "custom": f"{version_base}/custom/{username}_custom_version_control.txt",
    }
    latest_version_map = {
        "stable": latest_versions.get("stable_version"),
        "beta": latest_versions.get("beta_version"),
        "custom": latest_versions.get("stable_version"),
    }
    connect_timeout = int(os.getenv("BOTS_SSH_TIMEOUT", "25"))
    command_timeout = int(os.getenv("BOTS_SSH_COMMAND_TIMEOUT", "20"))
    t0 = _time.monotonic()
    logging.info(f"[bot_status] connecting to host={BOTS_SSH_HOST!r} user={SSH_USERNAME!r} for '{username}'")
    try:
        async with asyncssh.connect(
            BOTS_SSH_HOST,
            username=SSH_USERNAME,
            password=SSH_PASSWORD,
            known_hosts=None,
            connect_timeout=connect_timeout,
        ) as conn:
            logging.info(f"[bot_status] SSH connected in {_time.monotonic()-t0:.2f}s")
            found_type = None
            found_pid = None
            for bot_type in ["stable", "beta", "custom"]:
                t1 = _time.monotonic()
                result = await asyncio.wait_for(
                    conn.run(f"python {status_script} -system {bot_type} -channel {username}"),
                    timeout=command_timeout,
                )
                output = result.stdout.strip()
                logging.info(f"[bot_status] status.py -{bot_type} for '{username}' in {_time.monotonic()-t1:.2f}s: {output!r}")
                m = re.search(r'process ID:\s*(\d+)', output, re.IGNORECASE)
                if m:
                    found_type = bot_type
                    found_pid = int(m.group(1))
                    break
            if found_type:
                t2 = _time.monotonic()
                cat = await asyncio.wait_for(
                    conn.run(f"cat {version_files[found_type]}"),
                    timeout=command_timeout,
                )
                version = cat.stdout.strip() or None
                logging.info(f"[bot_status] version={version!r} in {_time.monotonic()-t2:.2f}s; total {_time.monotonic()-t0:.2f}s")
                latest = latest_version_map[found_type]
                is_outdated = False
                if version and latest:
                    try:
                        is_outdated = tuple(map(int, version.split('.'))) < tuple(map(int, latest.split('.')))
                    except (ValueError, AttributeError):
                        is_outdated = False
                return {
                    "running": True,
                    "pid": found_pid,
                    "version": version,
                    "bot_type": found_type,
                    "outdated": is_outdated,
                    "latest_version": latest
                }
            logging.info(f"[bot_status] bot not running for '{username}' (total {_time.monotonic()-t0:.2f}s)")
            return {
                "running": False,
                "pid": None,
                "version": None,
                "bot_type": None,
                "outdated": None,
                "latest_version": latest_versions.get("stable_version")
            }
    except Exception as e:
        logging.error(f"[bot_status] error for '{username}' after {_time.monotonic()-t0:.2f}s: {type(e).__name__}: {e}", exc_info=True)
        return {
            "running": False,
            "pid": None,
            "version": None,
            "bot_type": None,
            "outdated": None,
            "latest_version": latest_versions.get("stable_version")
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

class BotActionResponse(BaseModel):
    success: bool = Field(..., example=True)
    state: str = Field(..., example="started", description="Discriminator for the result. One of: started, already_running, stopped, already_stopped, start_pending.")
    running: bool = Field(..., example=True, description="Whether the bot is currently running after the action.")
    pid: int | None = Field(None, example=12345, description="PID of the running bot process, if any.")
    bot_type: str = Field(..., example="stable", description="Bot variant (stable or beta).")
    version: str | None = Field(None, example="6.7", description="Version of the running bot, if known.")
    message: str = Field(..., example="Bot started successfully")

@app.post(
    "/bot/start",
    summary="Start the chat bot",
    description="Start the chat bot for the authenticated user. Validates the user's Twitch access token (refreshing it via the user's refresh token if expired) before launching, and stops any other bot variant that is running for this user. Idempotent — if the requested bot variant is already running, returns success with the existing PID.",
    tags=["User Account"],
    operation_id="start_bot",
    response_model=BotActionResponse,
)
async def start_bot(
    api_key: str = Query(..., description="Your API key for authentication"),
    bot_type: str = Query("stable", description="Bot variant to start (stable or beta)."),
    custom: bool = Query(False, description="Beta only: launch with -custom mode using the channel's verified custom bot account. Mutually exclusive with self."),
    self_mode: bool = Query(False, alias="self", description="Beta only: launch with the -self flag. Mutually exclusive with custom."),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API key")
    username = resolve_username(key_info, channel)
    if bot_type not in BOT_SCRIPT_PATHS:
        raise HTTPException(
            status_code=400,
            detail=f"Invalid bot_type. Must be one of: {', '.join(BOT_SCRIPT_PATHS.keys())}",
        )
    if custom and self_mode:
        raise HTTPException(
            status_code=400,
            detail="custom and self are mutually exclusive. Provide only one.",
        )
    if bot_type != "beta" and (custom or self_mode):
        raise HTTPException(
            status_code=400,
            detail="custom and self flags are only valid when bot_type=beta.",
        )
    creds = await _get_user_bot_launch_credentials(username)
    if not creds:
        raise HTTPException(status_code=404, detail=f"User '{username}' not found")
    missing = [k for k in ("twitch_user_id", "access_token", "refresh_token", "api_key") if not creds.get(k)]
    if missing:
        raise HTTPException(status_code=400, detail=f"Missing required user data: {', '.join(missing)}")
    fresh_access, fresh_refresh = await _ensure_fresh_twitch_token(
        creds["twitch_user_id"], creds["access_token"], creds["refresh_token"]
    )
    if not fresh_access or not fresh_refresh:
        raise HTTPException(
            status_code=401,
            detail="Twitch access token is expired and refresh failed; user must re-authorize.",
        )
    if not all([BOTS_SSH_HOST, SSH_USERNAME, SSH_PASSWORD]):
        raise HTTPException(status_code=500, detail="Bot host SSH credentials are not configured")
    bot_script = BOT_SCRIPT_PATHS[bot_type]
    version_file = BOT_VERSION_FILE_TEMPLATES[bot_type].format(username=username)
    crash_log = f"/home/botofthespecter/logs/{username}_crash.log"
    screen_session = "specter_" + re.sub(r'[^a-zA-Z0-9_]', '_', username)
    if bot_type == "beta":
        if custom:
            beta_params = await _get_user_custom_bot_params(
                creds["user_id"], creds["twitch_user_id"],
                use_custom=True, use_self=False,
            )
            if not beta_params["use_custom_bot"]:
                raise HTTPException(
                    status_code=400,
                    detail="custom mode requested but no verified custom bot is configured for this channel.",
                )
        elif self_mode:
            beta_params = {"use_custom_bot": False, "custom_bot_username": None, "use_self": True}
        else:
            beta_params = {"use_custom_bot": False, "custom_bot_username": None, "use_self": False}
    else:
        beta_params = {"use_custom_bot": False, "custom_bot_username": None, "use_self": False}
    connect_timeout = int(os.getenv("BOTS_SSH_TIMEOUT", "25"))
    command_timeout = int(os.getenv("BOTS_SSH_COMMAND_TIMEOUT", "20"))
    try:
        async with asyncssh.connect(
            BOTS_SSH_HOST,
            username=SSH_USERNAME,
            password=SSH_PASSWORD,
            known_hosts=None,
            connect_timeout=connect_timeout,
        ) as conn:
            running_pid = await _check_bot_pid(conn, bot_type, username, command_timeout)
            other_msg = ""
            for other_type in BOT_SCRIPT_PATHS:
                if other_type == bot_type:
                    continue
                other_pid = await _check_bot_pid(conn, other_type, username, command_timeout)
                if other_pid:
                    other_script = BOT_SCRIPT_PATHS[other_type]
                    pgrep_cmd = f"pgrep -f 'python.*{other_script} -channel {username}'"
                    pgrep_result = await asyncio.wait_for(
                        conn.run(pgrep_cmd), timeout=command_timeout,
                    )
                    pids_to_kill = [p.strip() for p in (pgrep_result.stdout or "").split("\n") if p.strip().isdigit()]
                    if not pids_to_kill:
                        pids_to_kill = [str(other_pid)]
                    for pid in pids_to_kill:
                        await asyncio.wait_for(
                            conn.run(f"kill -s kill {pid}"),
                            timeout=command_timeout,
                        )
                    other_screen = "specter_" + re.sub(r'[^a-zA-Z0-9_]', '_', username)
                    await asyncio.wait_for(
                        conn.run(f"screen -S {shlex.quote(other_screen)} -X quit 2>/dev/null; true"),
                        timeout=command_timeout,
                    )
                    await asyncio.sleep(0.5)
                    other_msg += f"Stopped {other_type} bot (PIDs: {', '.join(pids_to_kill)}). "
            if running_pid:
                version = await _read_version_file(conn, version_file, command_timeout)
                return BotActionResponse(
                    success=True, state="already_running",
                    running=True, pid=running_pid, bot_type=bot_type,
                    version=version,
                    message=f"{other_msg}Bot is already running (PID {running_pid}). No action taken.",
                )
            args = [
                "python", "-u",
                shlex.quote(bot_script),
                "-channel", shlex.quote(username),
                "-channelid", shlex.quote(creds["twitch_user_id"]),
                "-token", shlex.quote(fresh_access),
                "-refresh", shlex.quote(fresh_refresh),
                "-apitoken", shlex.quote(creds["api_key"]),
            ]
            if bot_type == "beta" and beta_params["use_custom_bot"] and beta_params["custom_bot_username"]:
                args.extend(["-custom", "-botusername", shlex.quote(beta_params["custom_bot_username"])])
            if bot_type == "beta" and beta_params["use_self"]:
                args.append("-self")
            bot_invocation = " ".join(args)
            wrapped = f"bash -c {shlex.quote(bot_invocation + ' 2>&1 | tee -a ' + crash_log)}"
            start_cmd = f"screen -dmS {shlex.quote(screen_session)} {wrapped}"
            await asyncio.wait_for(conn.run(start_cmd), timeout=command_timeout)
            await asyncio.sleep(0.5)
            new_pid = await _check_bot_pid(conn, bot_type, username, command_timeout)
            if new_pid:
                version = await _read_version_file(conn, version_file, command_timeout)
                return BotActionResponse(
                    success=True, state="started",
                    running=True, pid=new_pid, bot_type=bot_type,
                    version=version, message=f"{other_msg}Bot started successfully",
                )
            return BotActionResponse(
                success=True, state="start_pending",
                running=False, pid=None, bot_type=bot_type,
                version=None,
                message=f"{other_msg}Bot start command sent. Status will update shortly.",
            )
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Bot start failed for '{username}' ({bot_type}): {e}", exc_info=True)
        raise HTTPException(status_code=502, detail=f"Bot start failed: {e}")

@app.post(
    "/bot/stop",
    summary="Stop the chat bot",
    description="Stop the chat bot for the authenticated user. Sends SIGKILL to all matching bot processes and tears down the screen session.",
    tags=["User Account"],
    operation_id="stop_bot",
    response_model=BotActionResponse,
)
async def stop_bot(
    api_key: str = Query(..., description="Your API key for authentication"),
    bot_type: str = Query("stable", description="Bot variant to stop (stable or beta)."),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API key")
    username = resolve_username(key_info, channel)
    if bot_type not in BOT_SCRIPT_PATHS:
        raise HTTPException(
            status_code=400,
            detail=f"Invalid bot_type. Must be one of: {', '.join(BOT_SCRIPT_PATHS.keys())}",
        )
    if not all([BOTS_SSH_HOST, SSH_USERNAME, SSH_PASSWORD]):
        raise HTTPException(status_code=500, detail="Bot host SSH credentials are not configured")
    bot_script = BOT_SCRIPT_PATHS[bot_type]
    screen_session = "specter_" + re.sub(r'[^a-zA-Z0-9_]', '_', username)
    connect_timeout = int(os.getenv("BOTS_SSH_TIMEOUT", "25"))
    command_timeout = int(os.getenv("BOTS_SSH_COMMAND_TIMEOUT", "20"))
    try:
        async with asyncssh.connect(
            BOTS_SSH_HOST,
            username=SSH_USERNAME,
            password=SSH_PASSWORD,
            known_hosts=None,
            connect_timeout=connect_timeout,
        ) as conn:
            pgrep_cmd = f"pgrep -f 'python.*{bot_script} -channel {username}'"
            result = await asyncio.wait_for(conn.run(pgrep_cmd), timeout=command_timeout)
            output = (result.stdout or "").strip()
            killed = []
            if output:
                for line in output.split("\n"):
                    line = line.strip()
                    if line.isdigit():
                        await asyncio.wait_for(
                            conn.run(f"kill -s kill {line}"),
                            timeout=command_timeout,
                        )
                        killed.append(line)
                await asyncio.wait_for(
                    conn.run(f"screen -S {shlex.quote(screen_session)} -X quit 2>/dev/null; true"),
                    timeout=command_timeout,
                )
                await asyncio.wait_for(
                    conn.run(f"tmux kill-session -t {shlex.quote(screen_session)} 2>/dev/null; true"),
                    timeout=command_timeout,
                )
                return BotActionResponse(
                    success=True, state="stopped",
                    running=False, pid=None, bot_type=bot_type,
                    version=None,
                    message=f"Bot stopped (killed PIDs: {', '.join(killed)})",
                )
            return BotActionResponse(
                success=True, state="already_stopped",
                running=False, pid=None, bot_type=bot_type,
                version=None,
                message="Bot is not running. No action taken.",
            )
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Bot stop failed for '{username}' ({bot_type}): {e}", exc_info=True)
        raise HTTPException(status_code=502, detail=f"Bot stop failed: {e}")

# ─── Twitch Extension Endpoints ──────────────────────────────────────────────
# Read-only, no API key required. Uses the broadcaster's Twitch channel ID
# (auth.channelId from the Twitch Extension Helper) to identify the channel.

async def get_username_by_channel_id(channel_id: str):
    conn = await get_mysql_connection()
    try:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(
                "SELECT username FROM users WHERE twitch_user_id = %s LIMIT 1",
                (channel_id,)
            )
            result = await cur.fetchone()
            return result["username"] if result else None
    finally:
        conn.close()

@app.get("/extension/commands", tags=["Extension"], summary="Get custom commands for a channel")
async def extension_commands(channel_id: str = Query(..., description="Broadcaster's Twitch user ID")):
    username = await get_username_by_channel_id(channel_id)
    if not username:
        raise HTTPException(status_code=404, detail="Channel not found")
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute(
                    "SELECT command, response, status FROM custom_commands WHERE status = 'enabled' ORDER BY command ASC"
                )
                rows = await cur.fetchall()
            return {"channel": username, "commands": rows}
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Extension commands error for channel_id {channel_id}: {e}")
        raise HTTPException(status_code=500, detail="Error retrieving commands")

@app.get("/extension/deaths", tags=["Extension"], summary="Get death counts for a channel")
async def extension_deaths(channel_id: str = Query(..., description="Broadcaster's Twitch user ID")):
    username = await get_username_by_channel_id(channel_id)
    if not username:
        raise HTTPException(status_code=404, detail="Channel not found")
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT death_count FROM total_deaths LIMIT 1")
                total_row = await cur.fetchone()
                await cur.execute("SELECT game_name, death_count FROM game_deaths ORDER BY death_count DESC")
                game_rows = await cur.fetchall()
            return {
                "channel": username,
                "total_deaths": total_row["death_count"] if total_row else 0,
                "game_deaths": game_rows
            }
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Extension deaths error for channel_id {channel_id}: {e}")
        raise HTTPException(status_code=500, detail="Error retrieving deaths")

@app.get("/extension/quotes", tags=["Extension"], summary="Get quotes for a channel")
async def extension_quotes(channel_id: str = Query(..., description="Broadcaster's Twitch user ID")):
    username = await get_username_by_channel_id(channel_id)
    if not username:
        raise HTTPException(status_code=404, detail="Channel not found")
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT id, quote, author FROM quotes ORDER BY id DESC")
                rows = await cur.fetchall()
            return {"channel": username, "quotes": rows}
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Extension quotes error for channel_id {channel_id}: {e}")
        raise HTTPException(status_code=500, detail="Error retrieving quotes")

@app.get("/extension/typos", tags=["Extension"], summary="Get typo counts for a channel")
async def extension_typos(channel_id: str = Query(..., description="Broadcaster's Twitch user ID")):
    username = await get_username_by_channel_id(channel_id)
    if not username:
        raise HTTPException(status_code=404, detail="Channel not found")
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT username, typo_count FROM user_typos ORDER BY typo_count DESC")
                rows = await cur.fetchall()
            return {"channel": username, "typos": rows}
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Extension typos error for channel_id {channel_id}: {e}")
        raise HTTPException(status_code=500, detail="Error retrieving typos")

@app.get("/extension/lurkers", tags=["Extension"], summary="Get current lurkers for a channel")
async def extension_lurkers(channel_id: str = Query(..., description="Broadcaster's Twitch user ID")):
    username = await get_username_by_channel_id(channel_id)
    if not username:
        raise HTTPException(status_code=404, detail="Channel not found")
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT user_id, start_time FROM lurk_times ORDER BY start_time ASC")
                rows = await cur.fetchall()
            return {"channel": username, "lurkers": rows}
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Extension lurkers error for channel_id {channel_id}: {e}")
        raise HTTPException(status_code=500, detail="Error retrieving lurkers")

@app.get("/extension/hugs", tags=["Extension"], summary="Get hug counts for a channel")
async def extension_hugs(channel_id: str = Query(..., description="Broadcaster's Twitch user ID")):
    username = await get_username_by_channel_id(channel_id)
    if not username:
        raise HTTPException(status_code=404, detail="Channel not found")
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT SUM(hug_count) AS total FROM hug_counts")
                total_row = await cur.fetchone()
                await cur.execute("SELECT username, hug_count FROM hug_counts ORDER BY hug_count DESC")
                rows = await cur.fetchall()
            return {
                "channel": username,
                "total_hugs": total_row["total"] if total_row and total_row["total"] else 0,
                "hug_counts": rows
            }
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Extension hugs error for channel_id {channel_id}: {e}")
        raise HTTPException(status_code=500, detail="Error retrieving hugs")

@app.get("/extension/kisses", tags=["Extension"], summary="Get kiss counts for a channel")
async def extension_kisses(channel_id: str = Query(..., description="Broadcaster's Twitch user ID")):
    username = await get_username_by_channel_id(channel_id)
    if not username:
        raise HTTPException(status_code=404, detail="Channel not found")
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT SUM(kiss_count) AS total FROM kiss_counts")
                total_row = await cur.fetchone()
                await cur.execute("SELECT username, kiss_count FROM kiss_counts ORDER BY kiss_count DESC")
                rows = await cur.fetchall()
            return {
                "channel": username,
                "total_kisses": total_row["total"] if total_row and total_row["total"] else 0,
                "kiss_counts": rows
            }
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Extension kisses error for channel_id {channel_id}: {e}")
        raise HTTPException(status_code=500, detail="Error retrieving kisses")

@app.get("/extension/highfives", tags=["Extension"], summary="Get high-five counts for a channel")
async def extension_highfives(channel_id: str = Query(..., description="Broadcaster's Twitch user ID")):
    username = await get_username_by_channel_id(channel_id)
    if not username:
        raise HTTPException(status_code=404, detail="Channel not found")
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT username, highfive_count FROM highfive_counts ORDER BY highfive_count DESC")
                rows = await cur.fetchall()
            return {"channel": username, "highfive_counts": rows}
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Extension highfives error for channel_id {channel_id}: {e}")
        raise HTTPException(status_code=500, detail="Error retrieving highfives")

@app.get("/extension/custom-counts", tags=["Extension"], summary="Get custom counts for a channel")
async def extension_custom_counts(channel_id: str = Query(..., description="Broadcaster's Twitch user ID")):
    username = await get_username_by_channel_id(channel_id)
    if not username:
        raise HTTPException(status_code=404, detail="Channel not found")
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT command, count FROM custom_counts ORDER BY count DESC")
                rows = await cur.fetchall()
            return {"channel": username, "custom_counts": rows}
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Extension custom-counts error for channel_id {channel_id}: {e}")
        raise HTTPException(status_code=500, detail="Error retrieving custom counts")

@app.get("/extension/user-counts", tags=["Extension"], summary="Get per-user counts for a channel")
async def extension_user_counts(channel_id: str = Query(..., description="Broadcaster's Twitch user ID")):
    username = await get_username_by_channel_id(channel_id)
    if not username:
        raise HTTPException(status_code=404, detail="Channel not found")
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT command, user, count FROM user_counts ORDER BY count DESC")
                rows = await cur.fetchall()
            return {"channel": username, "user_counts": rows}
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Extension user-counts error for channel_id {channel_id}: {e}")
        raise HTTPException(status_code=500, detail="Error retrieving user counts")

@app.get("/extension/reward-counts", tags=["Extension"], summary="Get reward redemption counts for a channel")
async def extension_reward_counts(channel_id: str = Query(..., description="Broadcaster's Twitch user ID")):
    username = await get_username_by_channel_id(channel_id)
    if not username:
        raise HTTPException(status_code=404, detail="Channel not found")
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute(
                    "SELECT rc.reward_id, rc.user, rc.count, c.reward_title "
                    "FROM reward_counts AS rc "
                    "LEFT JOIN channel_point_rewards AS c ON rc.reward_id = c.reward_id "
                    "ORDER BY rc.count DESC"
                )
                rows = await cur.fetchall()
            return {"channel": username, "reward_counts": rows}
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Extension reward-counts error for channel_id {channel_id}: {e}")
        raise HTTPException(status_code=500, detail="Error retrieving reward counts")

@app.get("/extension/watch-time", tags=["Extension"], summary="Get watch time leaderboard for a channel")
async def extension_watch_time(channel_id: str = Query(..., description="Broadcaster's Twitch user ID")):
    username = await get_username_by_channel_id(channel_id)
    if not username:
        raise HTTPException(status_code=404, detail="Channel not found")
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute(
                    "SELECT username, total_watch_time_live, total_watch_time_offline "
                    "FROM watch_time ORDER BY total_watch_time_live DESC LIMIT 50"
                )
                rows = await cur.fetchall()
            return {"channel": username, "watch_time": rows}
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Extension watch-time error for channel_id {channel_id}: {e}")
        raise HTTPException(status_code=500, detail="Error retrieving watch time")

@app.get("/extension/todos", tags=["Extension"], summary="Get to-do items for a channel")
async def extension_todos(channel_id: str = Query(..., description="Broadcaster's Twitch user ID")):
    username = await get_username_by_channel_id(channel_id)
    if not username:
        raise HTTPException(status_code=404, detail="Channel not found")
    try:
        conn = await get_mysql_connection_user(username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT id, task, status FROM todos ORDER BY id DESC")
                rows = await cur.fetchall()
            return {"channel": username, "todos": rows}
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Extension todos error for channel_id {channel_id}: {e}")
        raise HTTPException(status_code=500, detail="Error retrieving todos")

# Twitch Raid Endpoints
class RaidStartDataItem(BaseModel):
    created_at: str = Field(..., example="2026-04-25T07:20:50.52Z", description="UTC timestamp (RFC3339) of when the raid was requested.")
    is_mature: bool = Field(False, description="Deprecated by Twitch. Always false.")

class RaidStartResponse(BaseModel):
    status: str = Field(..., example="success")
    from_broadcaster_id: str = Field(..., example="12345678", description="Twitch user ID of the raiding broadcaster.")
    to_broadcaster_id: str = Field(..., example="87654321", description="Twitch user ID of the raided channel.")
    to_broadcaster_login: str = Field(..., example="somestreamer", description="Twitch username of the raided channel.")
    data: List[RaidStartDataItem] = Field(default_factory=list, description="Twitch response payload describing the pending raid.")
    class Config:
        json_schema_extra = {
            "example": {
                "status": "success",
                "from_broadcaster_id": "12345678",
                "to_broadcaster_id": "87654321",
                "to_broadcaster_login": "somestreamer",
                "data": [{"created_at": "2026-04-25T07:20:50.52Z", "is_mature": False}],
            }
        }

class RaidCancelResponse(BaseModel):
    status: str = Field(..., example="success")
    broadcaster_id: str = Field(..., example="12345678", description="Twitch user ID of the broadcaster whose pending raid was cancelled.")
    message: str = Field(..., example="Pending raid cancelled")
    class Config:
        json_schema_extra = {
            "example": {
                "status": "success",
                "broadcaster_id": "12345678",
                "message": "Pending raid cancelled",
            }
        }

class ErrorDetail(BaseModel):
    detail: str = Field(..., example="Error message describing what went wrong")

_RAID_START_ERROR_RESPONSES = {
    400: {"model": ErrorDetail, "description": "Invalid target (self-raid, blocked channel, or bad input)."},
    401: {"model": ErrorDetail, "description": "Invalid API key or the Twitch access token has expired."},
    404: {"model": ErrorDetail, "description": "Authenticated user has no Twitch credentials on file, or the target Twitch user was not found."},
    409: {"model": ErrorDetail, "description": "The broadcaster is already raiding another channel."},
    429: {"model": ErrorDetail, "description": "Rate limit exceeded. Twitch allows 10 raid requests per 10 minutes."},
    500: {"model": ErrorDetail, "description": "Server misconfiguration (missing Twitch client ID or app credentials)."},
    502: {"model": ErrorDetail, "description": "Upstream failure contacting Twitch."},
}

_RAID_CANCEL_ERROR_RESPONSES = {
    401: {"model": ErrorDetail, "description": "Invalid API key or the Twitch access token has expired."},
    404: {"model": ErrorDetail, "description": "Authenticated user has no Twitch credentials on file, or there is no pending raid to cancel."},
    429: {"model": ErrorDetail, "description": "Rate limit exceeded. Twitch allows 10 raid requests per 10 minutes."},
    500: {"model": ErrorDetail, "description": "Server misconfiguration (missing Twitch client ID)."},
    502: {"model": ErrorDetail, "description": "Upstream failure contacting Twitch."},
}

class ShoutoutResponse(BaseModel):
    status: str = Field(..., example="success")
    from_broadcaster_id: str = Field(..., example="12345678", description="Twitch user ID of the broadcaster sending the shoutout.")
    to_broadcaster_id: str = Field(..., example="87654321", description="Twitch user ID of the broadcaster receiving the shoutout.")
    to_broadcaster_login: str = Field(..., example="somestreamer", description="Twitch username of the broadcaster receiving the shoutout.")
    moderator_id: str = Field(..., example="12345678", description="Twitch user ID of the moderator that sent the shoutout (matches the access token user).")
    message: str = Field(..., example="Shoutout sent")
    class Config:
        json_schema_extra = {
            "example": {
                "status": "success",
                "from_broadcaster_id": "12345678",
                "to_broadcaster_id": "87654321",
                "to_broadcaster_login": "somestreamer",
                "moderator_id": "12345678",
                "message": "Shoutout sent",
            }
        }

_SHOUTOUT_ERROR_RESPONSES = {
    400: {"model": ErrorDetail, "description": "Invalid target (self-shoutout, broadcaster not live, no viewers, or bad input)."},
    401: {"model": ErrorDetail, "description": "Invalid API key, missing moderator:manage:shoutouts scope, or the Twitch access token has expired."},
    403: {"model": ErrorDetail, "description": "The authenticated user is not a moderator of the broadcaster, or the broadcaster may not shout out the target."},
    404: {"model": ErrorDetail, "description": "Authenticated user has no Twitch credentials on file, or the target Twitch user was not found."},
    429: {"model": ErrorDetail, "description": "Rate limit exceeded. Twitch allows one shoutout every 2 minutes, and one to the same target every 60 minutes."},
    500: {"model": ErrorDetail, "description": "Server misconfiguration (missing Twitch client ID or app credentials)."},
    502: {"model": ErrorDetail, "description": "Upstream failure contacting Twitch."},
}

@app.post(
    "/channel/twitch/raids/start",
    summary="Start a Twitch raid",
    description="Raid another channel by sending the broadcaster's viewers to the targeted channel. Rate limited by Twitch to 10 requests per 10 minutes.",
    tags=["Channel"],
    operation_id="start_twitch_raid",
    response_model=RaidStartResponse,
    responses=_RAID_START_ERROR_RESPONSES,
)
async def start_twitch_raid(
    api_key: str = Query(...),
    to_broadcaster_login: str = Query(..., description="The Twitch username of the channel to raid."),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    auth = await _get_user_twitch_auth(username)
    if not auth:
        raise HTTPException(status_code=404, detail="No Twitch credentials on file for this user")
    if _twitch_token_is_stale(auth.get("updated_at")):
        auth = await _get_user_twitch_auth(username)
        if not auth or _twitch_token_is_stale(auth.get("updated_at")):
            raise HTTPException(status_code=401, detail="Twitch access token is expired")
    if not CLIENT_ID:
        raise HTTPException(status_code=500, detail="Twitch client ID is not configured")
    target_login = to_broadcaster_login.strip().lower()
    if not target_login:
        raise HTTPException(status_code=400, detail="to_broadcaster_login is required")
    app_creds = await get_twitch_app_credentials()
    if not app_creds:
        raise HTTPException(status_code=500, detail="Twitch app credentials are unavailable")
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(
                "https://api.twitch.tv/helix/users",
                headers={"Client-ID": app_creds["client_id"], "Authorization": f"Bearer {app_creds['access_token']}"},
                params={"login": target_login},
            ) as resp:
                lookup_status = resp.status
                lookup_body = await resp.json(content_type=None)
    except Exception as e:
        logging.error(f"Twitch user lookup failed for '{target_login}': {e}")
        raise HTTPException(status_code=502, detail="Failed to resolve target broadcaster")
    if lookup_status != 200:
        raise HTTPException(status_code=502, detail="Failed to resolve target broadcaster")
    users_data = (lookup_body or {}).get("data", [])
    if not users_data:
        raise HTTPException(status_code=404, detail=f"Twitch user '{target_login}' not found")
    to_broadcaster_id = str(users_data[0].get("id") or "")
    if not to_broadcaster_id:
        raise HTTPException(status_code=502, detail="Failed to resolve target broadcaster")
    if auth["twitch_user_id"] == to_broadcaster_id:
        raise HTTPException(status_code=400, detail="You cannot raid your own channel")
    helix_url = "https://api.twitch.tv/helix/raids"
    params = {"from_broadcaster_id": auth["twitch_user_id"], "to_broadcaster_id": to_broadcaster_id}
    headers = {"Authorization": f"Bearer {auth['access_token']}", "Client-Id": CLIENT_ID}
    try:
        async with aiohttp.ClientSession() as session:
            async with session.post(helix_url, headers=headers, params=params) as resp:
                status_code = resp.status
                body = await resp.text()
    except Exception as e:
        logging.error(f"Twitch raid start failed for '{username}': {e}")
        raise HTTPException(status_code=502, detail="Failed to contact Twitch")
    if status_code == 200:
        try:
            data = json.loads(body)
        except Exception:
            data = {"raw": body}
        return {"status": "success", "from_broadcaster_id": auth["twitch_user_id"], "to_broadcaster_id": to_broadcaster_id, "to_broadcaster_login": target_login, "data": data.get("data", [])}
    try:
        err_json = json.loads(body)
        detail = err_json.get("message") or err_json
    except Exception:
        detail = body or f"Twitch returned HTTP {status_code}"
    raise HTTPException(status_code=status_code, detail=detail)

@app.delete(
    "/channel/twitch/raids/cancel",
    summary="Cancel a pending Twitch raid",
    description="Cancel a pending raid for the authenticated broadcaster. Rate limited by Twitch to 10 requests per 10 minutes.",
    tags=["Channel"],
    operation_id="cancel_twitch_raid",
    response_model=RaidCancelResponse,
    responses=_RAID_CANCEL_ERROR_RESPONSES,
)
async def cancel_twitch_raid(
    api_key: str = Query(...),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    auth = await _get_user_twitch_auth(username)
    if not auth:
        raise HTTPException(status_code=404, detail="No Twitch credentials on file for this user")
    if _twitch_token_is_stale(auth.get("updated_at")):
        auth = await _get_user_twitch_auth(username)
        if not auth or _twitch_token_is_stale(auth.get("updated_at")):
            raise HTTPException(status_code=401, detail="Twitch access token is expired")
    if not CLIENT_ID:
        raise HTTPException(status_code=500, detail="Twitch client ID is not configured")
    helix_url = "https://api.twitch.tv/helix/raids"
    params = {"broadcaster_id": auth["twitch_user_id"]}
    headers = {"Authorization": f"Bearer {auth['access_token']}", "Client-Id": CLIENT_ID}
    try:
        async with aiohttp.ClientSession() as session:
            async with session.delete(helix_url, headers=headers, params=params) as resp:
                status_code = resp.status
                body = await resp.text()
    except Exception as e:
        logging.error(f"Twitch raid cancel failed for '{username}': {e}")
        raise HTTPException(status_code=502, detail="Failed to contact Twitch")
    if status_code == 204:
        return {"status": "success", "broadcaster_id": auth["twitch_user_id"], "message": "Pending raid cancelled"}
    try:
        err_json = json.loads(body)
        detail = err_json.get("message") or err_json
    except Exception:
        detail = body or f"Twitch returned HTTP {status_code}"
    raise HTTPException(status_code=status_code, detail=detail)

@app.post(
    "/channel/twitch/shoutout",
    summary="Send a Twitch shoutout",
    description="Send a shoutout from the authenticated broadcaster's channel to another broadcaster. Rate limited by Twitch: one shoutout every 2 minutes, one to the same target every 60 minutes. The target broadcaster must be live with at least one viewer.",
    tags=["Channel"],
    operation_id="send_twitch_shoutout",
    response_model=ShoutoutResponse,
    responses=_SHOUTOUT_ERROR_RESPONSES,
)
async def send_twitch_shoutout(
    api_key: str = Query(...),
    to_broadcaster_login: str = Query(..., description="The Twitch username of the channel to shout out."),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    username = resolve_username(key_info, channel)
    auth = await _get_user_twitch_auth(username)
    if not auth:
        raise HTTPException(status_code=404, detail="No Twitch credentials on file for this user")
    if _twitch_token_is_stale(auth.get("updated_at")):
        auth = await _get_user_twitch_auth(username)
        if not auth or _twitch_token_is_stale(auth.get("updated_at")):
            raise HTTPException(status_code=401, detail="Twitch access token is expired")
    if not CLIENT_ID:
        raise HTTPException(status_code=500, detail="Twitch client ID is not configured")
    target_login = to_broadcaster_login.strip().lower()
    if not target_login:
        raise HTTPException(status_code=400, detail="to_broadcaster_login is required")
    app_creds = await get_twitch_app_credentials()
    if not app_creds:
        raise HTTPException(status_code=500, detail="Twitch app credentials are unavailable")
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(
                "https://api.twitch.tv/helix/users",
                headers={"Client-ID": app_creds["client_id"], "Authorization": f"Bearer {app_creds['access_token']}"},
                params={"login": target_login},
            ) as resp:
                lookup_status = resp.status
                lookup_body = await resp.json(content_type=None)
    except Exception as e:
        logging.error(f"Twitch user lookup failed for '{target_login}': {e}")
        raise HTTPException(status_code=502, detail="Failed to resolve target broadcaster")
    if lookup_status != 200:
        raise HTTPException(status_code=502, detail="Failed to resolve target broadcaster")
    users_data = (lookup_body or {}).get("data", [])
    if not users_data:
        raise HTTPException(status_code=404, detail=f"Twitch user '{target_login}' not found")
    to_broadcaster_id = str(users_data[0].get("id") or "")
    if not to_broadcaster_id:
        raise HTTPException(status_code=502, detail="Failed to resolve target broadcaster")
    if auth["twitch_user_id"] == to_broadcaster_id:
        raise HTTPException(status_code=400, detail="You cannot shout out your own channel")
    helix_url = "https://api.twitch.tv/helix/chat/shoutouts"
    params = {
        "from_broadcaster_id": auth["twitch_user_id"],
        "to_broadcaster_id": to_broadcaster_id,
        "moderator_id": auth["twitch_user_id"],
    }
    headers = {"Authorization": f"Bearer {auth['access_token']}", "Client-Id": CLIENT_ID}
    try:
        async with aiohttp.ClientSession() as session:
            async with session.post(helix_url, headers=headers, params=params) as resp:
                status_code = resp.status
                body = await resp.text()
    except Exception as e:
        logging.error(f"Twitch shoutout failed for '{username}': {e}")
        raise HTTPException(status_code=502, detail="Failed to contact Twitch")
    if status_code == 204:
        return {
            "status": "success",
            "from_broadcaster_id": auth["twitch_user_id"],
            "to_broadcaster_id": to_broadcaster_id,
            "to_broadcaster_login": target_login,
            "moderator_id": auth["twitch_user_id"],
            "message": "Shoutout sent",
        }
    try:
        err_json = json.loads(body)
        detail = err_json.get("message") or err_json
    except Exception:
        detail = body or f"Twitch returned HTTP {status_code}"
    raise HTTPException(status_code=status_code, detail=detail)

@app.get(
    "/channel/twitch/redeems",
    summary="Get tracked reward redemption counts",
    description="Returns all tracked reward redemption counts from the reward_counts table for the authenticated channel, showing how many times each user has redeemed each reward.",
    tags=["Channel"],
    operation_id="get_tracked_redeems",
)
async def get_tracked_redeems(
    api_key: str = Query(...),
    reward_id: str = Query(None, description="Filter by a specific reward ID."),
    username: str = Query(None, description="Filter by a specific username."),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    auth_username = resolve_username(key_info, channel)
    try:
        conn = await get_mysql_connection_user(auth_username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                query = (
                    "SELECT rc.id, rc.reward_id, rc.user, rc.count, c.reward_title "
                    "FROM reward_counts AS rc "
                    "LEFT JOIN channel_point_rewards AS c ON rc.reward_id = c.reward_id"
                )
                conditions = []
                params = []
                if reward_id:
                    conditions.append("rc.reward_id = %s")
                    params.append(reward_id)
                if username:
                    conditions.append("rc.user = %s")
                    params.append(username)
                if conditions:
                    query += " WHERE " + " AND ".join(conditions)
                query += " ORDER BY rc.count DESC"
                await cur.execute(query, params)
                rows = await cur.fetchall()
            return {"channel": auth_username, "count": len(rows), "redeems": rows}
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Error fetching reward_counts for '{auth_username}': {e}")
        raise HTTPException(status_code=500, detail="Error retrieving tracked redeems")

@app.get(
    "/channel/twitch/redeems/store",
    summary="Get stored reward redemptions",
    description="Returns all redemptions recorded in the stored_redeems table for the authenticated channel. Each row contains the reward ID, the username who redeemed it, and the timestamp.",
    tags=["Channel"],
    operation_id="get_stored_redeems",
)
async def get_stored_redeems(
    api_key: str = Query(...),
    reward_id: str = Query(None, description="Filter by a specific reward ID."),
    username: str = Query(None, description="Filter by a specific username."),
    page: int = Query(1, ge=1, description="Page number (1-based)."),
    limit: int = Query(100, ge=1, le=1000, description="Rows per page."),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    auth_username = resolve_username(key_info, channel)
    try:
        conn = await get_mysql_connection_user(auth_username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                conditions = []
                params = []
                if reward_id:
                    conditions.append("reward_id = %s")
                    params.append(reward_id)
                if username:
                    conditions.append("username = %s")
                    params.append(username)
                where = (" WHERE " + " AND ".join(conditions)) if conditions else ""
                await cur.execute(f"SELECT COUNT(*) AS total FROM stored_redeems{where}", params)
                total = (await cur.fetchone())["total"]
                offset = (page - 1) * limit
                await cur.execute(
                    f"SELECT id, reward_id, username, redeemed_at FROM stored_redeems{where} ORDER BY redeemed_at DESC LIMIT %s OFFSET %s",
                    params + [limit, offset]
                )
                rows = await cur.fetchall()
                for row in rows:
                    if row.get("redeemed_at") and hasattr(row["redeemed_at"], "isoformat"):
                        row["redeemed_at"] = row["redeemed_at"].isoformat()
            return {
                "channel": auth_username,
                "total": total,
                "page": page,
                "limit": limit,
                "pages": math.ceil(total / limit) if total else 1,
                "count": len(rows),
                "redeems": rows,
            }
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Error fetching stored_redeems for '{auth_username}': {e}")
        raise HTTPException(status_code=500, detail="Error retrieving stored redeems")

@app.delete(
    "/channel/twitch/redeems/store/{id}",
    summary="Delete a stored reward redemption",
    description="Removes a single entry from the stored_redeems table by its row ID.",
    tags=["Channel"],
    operation_id="delete_stored_redeem",
)
async def delete_stored_redeem(
    id: int,
    api_key: str = Query(...),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    auth_username = resolve_username(key_info, channel)
    try:
        conn = await get_mysql_connection_user(auth_username)
        try:
            async with conn.cursor() as cur:
                await cur.execute("DELETE FROM stored_redeems WHERE id = %s", (id,))
                await conn.commit()
                if cur.rowcount == 0:
                    raise HTTPException(status_code=404, detail=f"No stored redeem found with id {id}")
            return {"status": "success", "deleted_id": id}
        finally:
            conn.close()
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Error deleting stored_redeem id={id} for '{auth_username}': {e}")
        raise HTTPException(status_code=500, detail="Error deleting stored redeem")

@app.get(
    "/channel/twitch/bits",
    summary="Get bits leaderboard",
    description="Returns total bits cheered per user for the authenticated channel, aggregated from the bits_data table and ordered by total bits descending.",
    tags=["Channel"],
    operation_id="get_bits_leaderboard",
)
async def get_bits_leaderboard(
    api_key: str = Query(...),
    username: str = Query(None, description="Filter to a specific username."),
    channel: str = Query(None),
):
    key_info = await verify_key(api_key)
    if not key_info:
        raise HTTPException(status_code=401, detail="Invalid API Key")
    auth_username = resolve_username(key_info, channel)
    try:
        conn = await get_mysql_connection_user(auth_username)
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                if username:
                    await cur.execute(
                        "SELECT user_id, user_name, SUM(bits) AS total_bits, COUNT(*) AS cheer_count "
                        "FROM bits_data WHERE user_name = %s GROUP BY user_id, user_name",
                        (username,)
                    )
                else:
                    await cur.execute(
                        "SELECT user_id, user_name, SUM(bits) AS total_bits, COUNT(*) AS cheer_count "
                        "FROM bits_data GROUP BY user_id, user_name ORDER BY total_bits DESC"
                    )
                rows = await cur.fetchall()
            return {"channel": auth_username, "count": len(rows), "bits": rows}
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Error fetching bits leaderboard for '{auth_username}': {e}")
        raise HTTPException(status_code=500, detail="Error retrieving bits data")

# Any root request go to the docs page
@app.get("/", include_in_schema=False)
async def read_root():
    return RedirectResponse(url="/v2/docs")

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