# Standard library imports
import os, re, sys, signal, argparse, traceback, math, time, random, json
from asyncio import Queue, CancelledError as asyncioCancelledError
from asyncio import TimeoutError as asyncioTimeoutError
from asyncio import sleep, gather, create_task, get_event_loop
from datetime import datetime, timezone, timedelta
from urllib.parse import urlencode, quote
from logging import getLogger, StreamHandler as LoggingStreamHandler
from logging.handlers import RotatingFileHandler as LoggerFileHandler
from logging import Formatter as loggingFormatter
from logging import INFO as LoggingLevel
from pathlib import Path

# Third-party imports
import pytz as set_timezone
from aiohttp import ClientSession as httpClientSession
from aiohttp import ClientError as aiohttpClientError
from aiohttp import ClientTimeout
from socketio import AsyncClient
from socketio.exceptions import ConnectionError as ConnectionExceptionError
from aiomysql import connect as sql_connect
from aiomysql import IntegrityError as MySQLIntegrityError
from aiomysql import DictCursor, MySQLError
from aiomysql import Error as MySQLOtherErrors
from deep_translator import GoogleTranslator as translator
from pytz import timezone as pytz_timezone
from geopy.geocoders import Nominatim
from jokeapi import Jokes
from pint import UnitRegistry as ureg
from openai import AsyncOpenAI
from dotenv import load_dotenv

load_dotenv()

parser = argparse.ArgumentParser(description="BotOfTheSpecter Kick Chat Bot")
parser.add_argument("-channel",      dest="target_channel",    required=True,  help="Kick channel slug (username)")
parser.add_argument("-channelid",    dest="channel_id",        required=True,  help="Kick broadcaster user ID")
parser.add_argument("-chatroomid",   dest="chatroom_id",       required=True,  help="Kick chatroom ID")
parser.add_argument("-token",        dest="channel_auth_token", required=True, help="Kick OAuth access token")
parser.add_argument("-refresh",      dest="refresh_token",     required=True,  help="Kick OAuth refresh token")
parser.add_argument("-clientid",     dest="client_id",         required=True,  help="Kick app client ID")
parser.add_argument("-clientsecret", dest="client_secret",     required=True,  help="Kick app client secret")
parser.add_argument("-apitoken",     dest="api_token",         required=False, help="BotOfTheSpecter internal API token")
args = parser.parse_args()

CHANNEL_NAME        = args.target_channel.lower()
CHANNEL_ID          = int(args.channel_id)
CHATROOM_ID         = int(args.chatroom_id)
CHANNEL_AUTH        = args.channel_auth_token
REFRESH_TOKEN       = args.refresh_token
CLIENT_ID           = args.client_id
CLIENT_SECRET       = args.client_secret
API_TOKEN           = args.api_token
VERSION             = "1.0"
SYSTEM              = "KICK"
BOT_USERNAME        = "botofthespecter"
BOT_OWNER           = "gfaundead"
MAX_CHAT_MESSAGE_LENGTH = 500

# Kick REST API
KICK_API_BASE  = "https://api.kick.com/public/v1"
KICK_AUTH_BASE = "https://id.kick.com"

# Environment / shared secrets
SQL_HOST              = os.getenv('SQL_HOST')
SQL_USER              = os.getenv('SQL_USER')
SQL_PASSWORD          = os.getenv('SQL_PASSWORD')
ADMIN_API_KEY         = os.getenv('ADMIN_KEY')
OPENAI_API_KEY        = os.getenv('OPENAI_KEY')
WEATHER_API_KEY       = os.getenv('WEATHER_API')
STEAM_API_KEY         = os.getenv('STEAM_API')
EXCHANGE_RATE_API_KEY = os.getenv('EXCHANGE_RATE_API')
HYPERATE_API_KEY      = os.getenv('HYPERATE_API_KEY')

# Built-in command registry (used for DB seeding)
builtin_commands = {
    "commands", "bot", "roadmap", "quote", "rps", "story", "roulette",
    "songrequest", "songqueue", "watchtime", "stoptimer", "checktimer",
    "version", "convert", "todo", "todolist", "kill", "points", "slots",
    "timer", "game", "joke", "ping", "weather", "time", "song", "translate",
    "lurk", "unlurk", "lurking", "lurklead", "userslurking", "uptime",
    "typo", "typos", "followage", "deaths", "heartrate", "gamble",
    "joinraffle", "leaveraffle", "puzzles", "schedule", "steam",
    "hug", "highfive", "kiss", "skipsong", "kicks",
}
mod_commands = {
    "addcommand", "removecommand", "disablecommand", "enablecommand",
    "editcommand", "removetypos", "addpoints", "removepoints", "permit",
    "removequote", "quoteadd", "settitle", "setgame", "edittypos",
    "deathadd", "deathremove", "shoutout", "checkupdate",
    "startlotto", "drawlotto", "skipsong", "createraffle", "startraffle",
    "stopraffle", "drawraffle",
}

logs_directory = "/home/botofthespecter/logs/logs"
log_types = ["bot", "chat", "api", "chat_history", "event_log", "websocket", "system"]

for log_type in log_types:
    os.makedirs(os.path.join(logs_directory, log_type), mode=0o755, exist_ok=True)

def setup_logger(name, log_file, level=LoggingLevel):
    logger = getLogger(name)
    logger.setLevel(level)
    if logger.hasHandlers():
        logger.handlers.clear()
    fmt = loggingFormatter('%(asctime)s - %(levelname)s - %(message)s', datefmt='%Y-%m-%d %H:%M:%S')
    fh = LoggerFileHandler(log_file, maxBytes=10485760, backupCount=5, encoding='utf-8')
    fh.setFormatter(fmt)
    logger.addHandler(fh)
    ch = LoggingStreamHandler(sys.stdout)
    ch.setFormatter(fmt)
    logger.addHandler(ch)
    return logger

loggers = {}
for log_type in log_types:
    log_file = os.path.join(logs_directory, log_type, f"{CHANNEL_NAME}_kick.txt")
    loggers[log_type] = setup_logger(f"kick.{log_type}", log_file)

bot_logger          = loggers['bot']
chat_logger         = loggers['chat']
api_logger          = loggers['api']
chat_history_logger = loggers['chat_history']
event_logger        = loggers['event_log']
websocket_logger    = loggers['websocket']
system_logger       = loggers['system']

bot_logger.info(f"[STARTUP] Kick bot v{VERSION} starting for channel: {CHANNEL_NAME}")

_background_tasks       = set()
scheduled_tasks         = set()
looped_tasks            = {}
command_usage           = {}
permitted_users         = {}
song_requests           = {}
active_timed_messages   = {}
message_tasks           = {}
active_timer_routines   = {}
chat_trigger_tasks      = {}
chat_line_count         = 0
last_message_time       = 0
stream_online           = False
bot_started             = datetime.now()
_shared_http_session    = None
HEARTRATE               = None
MYSQL_QUERY_TIMEOUT     = float(os.getenv('MYSQL_QUERY_TIMEOUT', '5'))
openai_client           = AsyncOpenAI(api_key=OPENAI_API_KEY)

def signal_handler(*_):
    bot_logger.info("[SHUTDOWN] Termination signal received.")
    loop = get_event_loop()
    if loop.is_running():
        loop.create_task(_async_shutdown())
    else:
        sys.exit(0)

async def _async_shutdown():
    import asyncio
    tasks = [t for t in asyncio.all_tasks() if t is not asyncio.current_task()]
    for t in tasks:
        t.cancel()
    await asyncio.gather(*tasks, return_exceptions=True)
    global _shared_http_session
    if _shared_http_session and not _shared_http_session.closed:
        await _shared_http_session.close()
    sys.exit(0)

signal.signal(signal.SIGTERM, signal_handler)
signal.signal(signal.SIGINT,  signal_handler)

def safe_create_task(coro):
    task = create_task(coro)
    _background_tasks.add(task)
    task.add_done_callback(_background_tasks.discard)
    return task

def time_right_now(tz=None):
    if tz:
        return datetime.now(tz)
    return datetime.now()

async def get_http_session() -> httpClientSession:
    global _shared_http_session
    if _shared_http_session is None or _shared_http_session.closed:
        _shared_http_session = httpClientSession()
    return _shared_http_session

class DirectConnection:
    def __init__(self, conn):
        self._conn   = conn
        self._closed = False
    def cursor(self, *a, **kw):
        return self._conn.cursor(*a, **kw)
    async def commit(self):
        if not self._closed:
            await self._conn.commit()
    async def rollback(self):
        if not self._closed:
            await self._conn.rollback()
    async def close(self):
        if not self._closed:
            self._closed = True
            try:
                self._conn.close()
            except Exception as e:
                bot_logger.error(f"[DB] Close error: {e}")
    async def __aenter__(self):
        return self
    async def __aexit__(self, *_):
        await self.close()

async def mysql_connection(db_name=None):
    if db_name is None:
        db_name = CHANNEL_NAME
    conn = await sql_connect(
        host=SQL_HOST, user=SQL_USER, password=SQL_PASSWORD,
        db=db_name, autocommit=True, connect_timeout=10
    )
    return DirectConnection(conn)

async def check_cooldown(command, user_id, bucket_type, rate, time_window, send_message=True):
    global command_usage
    now = time.time()
    key = (command, bucket_type, user_id)
    command_usage.setdefault(key, [])
    command_usage[key] = [t for t in command_usage[key] if now - t < time_window]
    if len(command_usage[key]) < rate:
        return True
    if send_message:
        oldest    = min(command_usage[key])
        remaining = int(time_window - (now - oldest))
        await send_chat_message(f"{command} is on cooldown. Please wait {remaining} seconds.")
    return False

def add_usage(command, user_id, bucket_type='default'):
    key = (command, bucket_type, user_id)
    command_usage.setdefault(key, [])
    command_usage[key].append(time.time())

async def refresh_kick_token():
    global CHANNEL_AUTH, REFRESH_TOKEN
    url  = f"{KICK_AUTH_BASE}/oauth/token"
    data = {
        "grant_type":    "refresh_token",
        "refresh_token": REFRESH_TOKEN,
        "client_id":     CLIENT_ID,
        "client_secret": CLIENT_SECRET,
    }
    try:
        session = await get_http_session()
        async with session.post(url, data=data, timeout=ClientTimeout(total=15)) as r:
            if r.status == 200:
                payload       = await r.json()
                CHANNEL_AUTH  = payload["access_token"]
                REFRESH_TOKEN = payload.get("refresh_token", REFRESH_TOKEN)
                bot_logger.info("[AUTH] Kick token refreshed successfully.")
                await _persist_kick_tokens(CHANNEL_AUTH, REFRESH_TOKEN)
                return True
            bot_logger.error(f"[AUTH] Token refresh failed ({r.status}): {await r.text()}")
            return False
    except Exception as e:
        bot_logger.error(f"[AUTH] Token refresh exception: {e}")
        return False

async def _persist_kick_tokens(access_token, refresh_token):
    try:
        async with await mysql_connection(db_name="website") as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "UPDATE kick_bot_tokens SET access_token=%s, refresh_token=%s WHERE channel_name=%s",
                    (access_token, refresh_token, CHANNEL_NAME)
                )
    except Exception as e:
        bot_logger.error(f"[AUTH] Failed to persist tokens: {e}")

async def kick_token_refresh_loop():
    while True:
        await sleep(10800)
        await refresh_kick_token()

def _kick_headers() -> dict:
    return {
        "Authorization": f"Bearer {CHANNEL_AUTH}",
        "Content-Type":  "application/json",
        "Accept":        "application/json",
    }

async def kick_get(path: str, params: dict = None):
    url = f"{KICK_API_BASE}{path}"
    try:
        session = await get_http_session()
        async with session.get(url, headers=_kick_headers(), params=params, timeout=ClientTimeout(total=10)) as r:
            if r.status == 200:
                return await r.json()
            api_logger.error(f"[KICK API] GET {path} → {r.status}: {await r.text()}")
            return None
    except Exception as e:
        api_logger.error(f"[KICK API] GET {path} exception: {e}")
        return None

async def kick_post(path: str, payload: dict):
    url = f"{KICK_API_BASE}{path}"
    try:
        session = await get_http_session()
        async with session.post(url, headers=_kick_headers(), json=payload, timeout=ClientTimeout(total=10)) as r:
            try:
                body = await r.json()
            except Exception:
                body = {"raw": await r.text()}
            return r.status, body
    except Exception as e:
        api_logger.error(f"[KICK API] POST {path} exception: {e}")
        return 0, {}

async def kick_delete(path: str, params: dict = None):
    url = f"{KICK_API_BASE}{path}"
    try:
        session = await get_http_session()
        async with session.delete(url, headers=_kick_headers(), params=params, timeout=ClientTimeout(total=10)) as r:
            return r.status
    except Exception as e:
        api_logger.error(f"[KICK API] DELETE {path} exception: {e}")
        return 0

async def kick_patch(path: str, payload: dict):
    url = f"{KICK_API_BASE}{path}"
    try:
        session = await get_http_session()
        async with session.patch(url, headers=_kick_headers(), json=payload, timeout=ClientTimeout(total=10)) as r:
            try:
                body = await r.json()
            except Exception:
                body = {"raw": await r.text()}
            return r.status, body
    except Exception as e:
        api_logger.error(f"[KICK API] PATCH {path} exception: {e}")
        return 0, {}

async def send_chat_message(message: str, reply_to_message_id: str = None) -> bool:
    if not message:
        return False
    if len(message) > MAX_CHAT_MESSAGE_LENGTH:
        message = message[:MAX_CHAT_MESSAGE_LENGTH - 3] + "..."
    payload = {
        "content":             message,
        "type":                "bot",
        "broadcaster_user_id": CHANNEL_ID,
    }
    if reply_to_message_id:
        payload["reply_to_message_id"] = reply_to_message_id
    status, body = await kick_post("/chat", payload)
    if status in (200, 201):
        chat_logger.info(f"[SEND] {message[:80]}")
        return True
    if status == 401:
        bot_logger.warning("[SEND] 401 — refreshing token and retrying")
        if await refresh_kick_token():
            status, body = await kick_post("/chat", payload)
            return status in (200, 201)
    if status == 429:
        bot_logger.warning("[SEND] Rate limited — sleeping 2 s")
        await sleep(2)
        return False
    bot_logger.error(f"[SEND] Failed ({status}): {body}")
    return False

async def delete_chat_message(message_id: str) -> bool:
    status = await kick_delete(f"/chat/{message_id}")
    return status in (200, 204)

async def get_channel_info():
    data = await kick_get("/channels", params={"broadcaster_user_id": str(CHANNEL_ID)})
    if data and data.get("data"):
        return data["data"][0]
    return None

async def get_livestream_info():
    data = await kick_get("/livestreams", params={"broadcaster_user_id": str(CHANNEL_ID)})
    if data and data.get("data"):
        return data["data"][0]
    return None

async def update_channel_info(title: str = None, category_id: int = None) -> bool:
    payload = {"broadcaster_user_id": CHANNEL_ID}
    if title:
        payload["stream_title"] = title
    if category_id:
        payload["category_id"] = category_id
    status, _ = await kick_patch("/channels", payload)
    return status in (200, 204)

async def ban_user(user_id: int, duration_minutes: int = None, reason: str = "") -> bool:
    payload: dict = {"broadcaster_user_id": CHANNEL_ID, "user_id": user_id}
    if duration_minutes:
        payload["duration"] = duration_minutes
    if reason:
        payload["reason"] = reason[:100]
    status, _ = await kick_post("/moderation/bans", payload)
    return status in (200, 201)

async def unban_user(user_id: int) -> bool:
    status = await kick_delete("/moderation/bans", params={
        "broadcaster_user_id": str(CHANNEL_ID),
        "user_id": str(user_id),
    })
    return status in (200, 204)

specterSocket    = AsyncClient()
websocket_connected = False

async def specter_websocket():
    global websocket_connected
    specter_uri      = "https://websocket.botofthespecter.com"
    reconnect_delay  = 60
    consecutive_failures = 0
    while True:
        try:
            websocket_connected = False
            if specterSocket.connected:
                try:
                    await specterSocket.disconnect()
                except Exception:
                    pass
            if consecutive_failures > 0:
                jitter = random.uniform(0, 5)
                websocket_logger.info(
                    f"[WS] Reconnect attempt {consecutive_failures}, "
                    f"waiting {reconnect_delay + jitter:.1f}s …"
                )
                await sleep(reconnect_delay + jitter)
            bot_logger.info(f"[WS] Connecting to {specter_uri} (attempt {consecutive_failures + 1})")
            await specterSocket.connect(specter_uri, transports=['websocket'])
            # Wait up to 30 s for registration to complete
            start = time_right_now()
            while not websocket_connected:
                if (time_right_now() - start).total_seconds() > 30:
                    raise asyncioTimeoutError("Connection/registration timeout")
                await sleep(0.5)
            consecutive_failures = 0
            websocket_logger.info("[WS] Connected and registered with internal WebSocket server.")
            await specterSocket.wait()
        except (ConnectionExceptionError, asyncioTimeoutError) as e:
            consecutive_failures += 1
            websocket_connected = False
            websocket_logger.error(f"[WS] Connection failed (attempt {consecutive_failures}): {e}")
        except asyncioCancelledError:
            websocket_logger.info("[WS] Loop cancelled — exiting.")
            break
        except Exception as e:
            consecutive_failures += 1
            websocket_connected = False
            websocket_logger.error(f"[WS] Unexpected error (attempt {consecutive_failures}): {e}")
        websocket_connected = False
        await sleep(1)

@specterSocket.event
async def connect():
    global websocket_connected
    websocket_logger.info("[WS] Socket.IO connection established, registering …")
    registration_data = {
        'code':    API_TOKEN,
        'channel': CHANNEL_NAME,
        'name':    f'Kick Bot V{VERSION}',
    }
    try:
        await specterSocket.emit('REGISTER', registration_data)
        websocket_connected = True
        websocket_logger.info("[WS] Registered with internal WebSocket server.")
    except Exception as e:
        websocket_logger.error(f"[WS] Registration failed: {e}")
        websocket_connected = False
        try:
            await specterSocket.disconnect()
        except Exception:
            pass

@specterSocket.event
async def connect_error(data):
    global websocket_connected
    websocket_connected = False
    websocket_logger.error(f"[WS] Connection error: {data}")

@specterSocket.event
async def disconnect():
    global websocket_connected
    websocket_connected = False
    websocket_logger.error("[WS] Disconnected from internal WebSocket server.")

def _inner(payload: dict) -> dict:
    if not isinstance(payload, dict):
        return {}
    raw = payload.get("data", payload)
    # The WS server passes query params verbatim; "data" arrives as a JSON string
    if isinstance(raw, str):
        try:
            raw = json.loads(raw)
        except (json.JSONDecodeError, ValueError):
            return {}
    # Kick webhook body: outer dict has "broadcaster_user_id" + "data" (the real fields)
    if isinstance(raw, dict):
        return raw.get("data", raw)
    return {}

@specterSocket.event
async def KICK_CHAT(payload):
    event_logger.info(f"[KICK_CHAT] received")
    safe_create_task(on_chat_message(_inner(payload)))

@specterSocket.event
async def KICK_FOLLOW(payload):
    event_logger.info(f"[KICK_FOLLOW] received")
    safe_create_task(on_follow(_inner(payload)))

@specterSocket.event
async def KICK_SUB(payload):
    event_logger.info(f"[KICK_SUB] received")
    safe_create_task(on_subscription(_inner(payload)))

@specterSocket.event
async def KICK_RESUB(payload):
    event_logger.info(f"[KICK_RESUB] received")
    safe_create_task(on_subscription(_inner(payload)))

@specterSocket.event
async def KICK_GIFTSUB(payload):
    event_logger.info(f"[KICK_GIFTSUB] received")
    safe_create_task(on_gift_subscriptions(_inner(payload)))

@specterSocket.event
async def KICK_REDEMPTION(payload):
    event_logger.info(f"[KICK_REDEMPTION] received")
    safe_create_task(on_reward_redemption(_inner(payload)))

@specterSocket.event
async def KICK_STREAM_STATUS(payload):
    event_logger.info(f"[KICK_STREAM_STATUS] received")
    safe_create_task(on_livestream_status(_inner(payload)))

@specterSocket.event
async def KICK_STREAM_METADATA(payload):
    event_logger.info(f"[KICK_STREAM_METADATA] received: {_inner(payload)}")

@specterSocket.event
async def KICK_BAN(payload):
    event_logger.info(f"[KICK_BAN] received")
    safe_create_task(on_user_banned(_inner(payload)))

@specterSocket.event
async def KICK_KICKS_GIFTED(payload):
    event_logger.info(f"[KICK_KICKS_GIFTED] received")
    safe_create_task(on_kicks_gifted(_inner(payload)))

async def on_chat_message(data: dict):
    # Kick webhook chat.message.sent → data has: id, content, sender, chatroom_id
    sender  = data.get("sender", {})
    content = data.get("content", "")
    msg_id  = data.get("id", "")
    user_id   = str(sender.get("id", ""))
    user_name = (sender.get("slug") or sender.get("username", "")).lower()
    badges      = sender.get("identity", {}).get("badges", [])
    badge_types = {b.get("type", "") for b in badges}
    is_mod        = "moderator" in badge_types or "broadcaster" in badge_types
    is_broadcaster= "broadcaster" in badge_types
    chat_logger.info(f"[CHAT] {user_name}: {content}")
    safe_create_task(
        process_incoming_chat(user_id, user_name, content, msg_id, is_mod, is_broadcaster)
    )

async def on_user_banned(data: dict):
    # Kick webhook moderation.banned → data has: banned_user, moderator
    banned = (data.get("banned_user") or {}).get("slug", "someone")
    mod    = (data.get("moderator")   or {}).get("slug", "a mod")
    event_logger.info(f"[BAN] {banned} was banned by {mod}")

async def on_follow(data: dict):
    # Kick webhook channel.followed → data has: user_id, username, slug
    user = data.get("slug") or data.get("username", "someone")
    event_logger.info(f"[FOLLOW] {user} followed {CHANNEL_NAME}")
    await send_chat_message(f"Welcome {user}! Thanks for the follow!")
    safe_create_task(record_follow_event(user))

async def on_subscription(data: dict):
    # Kick webhook channel.subscription.new / renewal → data has: subscriber, plan, months
    subscriber = (data.get("subscriber") or {})
    username   = subscriber.get("slug") or subscriber.get("username", "someone")
    months     = data.get("months", 1)
    event_logger.info(f"[SUB] {username} subscribed ({months} month(s))")
    if months > 1:
        await send_chat_message(f"Thank you {username} for resubscribing for {months} months!")
    else:
        await send_chat_message(f"Thank you {username} for subscribing!")
    safe_create_task(record_subscription_event(username, months))

async def on_gift_subscriptions(data: dict):
    # Kick webhook channel.subscription.gifts → data has: gifter, gifts_count
    gifter = (data.get("gifter") or {}).get("slug", "Anonymous")
    count  = data.get("gifts_count", 1)
    event_logger.info(f"[GIFT SUB] {gifter} gifted {count} sub(s)")
    await send_chat_message(f"Huge thanks to {gifter} for gifting {count} subscription(s)!")

async def on_reward_redemption(data: dict):
    # Kick webhook channel.reward.redemption.updated → data has: redeemer, reward
    redeemer    = (data.get("redeemer") or {}).get("slug", "someone")
    reward_name = (data.get("reward")   or {}).get("title", "a reward")
    event_logger.info(f"[REDEMPTION] {redeemer} redeemed: {reward_name}")
    await send_chat_message(f"{redeemer} just redeemed {reward_name}!")

async def on_kicks_gifted(data: dict):
    # Kick webhook kicks.gifted → data has: gifter, amount
    gifter = (data.get("gifter") or {}).get("slug", "Anonymous")
    amount = data.get("amount", 0)
    event_logger.info(f"[KICKS] {gifter} gifted {amount} KICKS")
    await send_chat_message(f"Thank you {gifter} for the {amount} KICKS!")

async def on_livestream_status(data: dict):
    # Kick webhook livestream.status.updated → data has: is_live
    is_live = data.get("is_live", False)
    if is_live:
        await on_stream_online()
    else:
        await on_stream_offline()

async def on_stream_online():
    global stream_online
    was_online   = stream_online
    stream_online = True
    if not was_online:
        bot_logger.info(f"[STREAM] {CHANNEL_NAME} went live!")
        await load_timed_messages()
        safe_create_task(periodic_watch_time_update())

async def on_stream_offline():
    global stream_online
    was_online   = stream_online
    stream_online = False
    if was_online:
        bot_logger.info(f"[STREAM] {CHANNEL_NAME} went offline.")
        for task in list(message_tasks.values()):
            if not task.done():
                task.cancel()
        message_tasks.clear()

async def record_follow_event(username: str):
    try:
        async with await mysql_connection() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "INSERT IGNORE INTO followers (user_name, followed_at) VALUES (%s, %s)",
                    (username.lower(), datetime.now())
                )
    except Exception as e:
        bot_logger.error(f"[DB FOLLOW] {e}")

async def record_subscription_event(username: str, months: int):
    try:
        async with await mysql_connection() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "INSERT INTO subscribers (user_name, months, subscribed_at) VALUES (%s, %s, %s) "
                    "ON DUPLICATE KEY UPDATE months=%s, subscribed_at=%s",
                    (username.lower(), months, datetime.now(), months, datetime.now())
                )
    except Exception as e:
        bot_logger.error(f"[DB SUB] {e}")

async def process_incoming_chat(
    user_id: str, user_name: str, content: str, msg_id: str, is_mod: bool, is_broadcaster: bool
):
    global chat_line_count
    # Ignore the bot's own messages
    if user_name == BOT_USERNAME.lower():
        return
    if not content.startswith("!"):
        # Count lines for timed-message triggers
        chat_line_count += 1
        for mid, info in list(chat_trigger_tasks.items()):
            if chat_line_count - info["last_trigger_count"] >= info["chat_line_trigger"]:
                info["last_trigger_count"] = chat_line_count
                safe_create_task(send_timed_message(mid, info["message"], 0))
        safe_create_task(award_chat_points(user_id, user_name))
        return
    parts   = content.split(maxsplit=1)
    raw_cmd = parts[0][1:].lower()
    args_str= parts[1].strip() if len(parts) > 1 else ""
    chat_logger.info(f"[CMD] {user_name}: !{raw_cmd} {args_str}")
    if raw_cmd in builtin_commands or raw_cmd in mod_commands:
        safe_create_task(
            dispatch_builtin(raw_cmd, args_str, user_id, user_name, msg_id, is_mod, is_broadcaster)
        )
    else:
        safe_create_task(dispatch_custom_command(raw_cmd, user_name))

async def is_mod_or_above(user_name: str, user_id: str, is_mod: bool, is_broadcaster: bool) -> bool:
    if is_broadcaster or is_mod or user_name.lower() == BOT_OWNER.lower():
        return True
    if user_id in permitted_users:
        if time.time() < permitted_users[user_id]:
            return True
        del permitted_users[user_id]
    return False

async def dispatch_builtin(
    cmd: str, args_str: str, user_id: str, user_name: str, msg_id: str, is_mod: bool, is_broadcaster: bool
):
    try:
        async with await mysql_connection() as conn:
            async with conn.cursor(DictCursor) as cur:
                await cur.execute(
                    "SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket "
                    "FROM builtin_commands WHERE command=%s",
                    (cmd,)
                )
                row = await cur.fetchone()
        if not row:
            return
        status     = row.get("status", "Enabled")
        permission = row.get("permission", "everyone")
        cd_rate    = row.get("cooldown_rate",   1) or 1
        cd_time    = row.get("cooldown_time",   5) or 5
        cd_bucket  = row.get("cooldown_bucket", "default") or "default"
        if status == "Disabled" and user_name.lower() != BOT_OWNER:
            return
        mod_ok = await is_mod_or_above(user_name, user_id, is_mod, is_broadcaster)
        if permission == "mod" and not mod_ok:
            await send_chat_message(f"@{user_name}, you don't have permission to use that command.")
            return
        bucket_key = "global" if cd_bucket == "default" else user_id
        if not await check_cooldown(cmd, bucket_key, cd_bucket, cd_rate, cd_time):
            return
        args = args_str.split() if args_str else []
        ctx  = _Ctx(
            user_id=user_id, user_name=user_name, msg_id=msg_id,
            is_mod=is_mod, is_broadcaster=is_broadcaster,
            args=args, args_str=args_str
        )
        await _run_command(cmd, ctx)
        add_usage(cmd, bucket_key, cd_bucket)
    except Exception as e:
        bot_logger.error(f"[CMD DISPATCH] {cmd}: {e}\n{traceback.format_exc()}")

async def dispatch_custom_command(cmd: str, user_name: str):
    try:
        async with await mysql_connection() as conn:
            async with conn.cursor(DictCursor) as cur:
                await cur.execute(
                    "SELECT response, status FROM custom_commands WHERE command=%s", (cmd,)
                )
                row = await cur.fetchone()
        if row and row.get("status", "Enabled") == "Enabled":
            response = row["response"].replace("(user)", f"@{user_name}")
            await send_chat_message(response)
            return
        # Fall through to per-user custom commands
        async with await mysql_connection() as conn:
            async with conn.cursor(DictCursor) as cur:
                await cur.execute(
                    "SELECT response, status FROM custom_user_commands WHERE command=%s", (cmd,)
                )
                row = await cur.fetchone()
        if row and row.get("status", "Enabled") == "Enabled":
            response = row["response"].replace("(user)", f"@{user_name}")
            await send_chat_message(response)
    except Exception as e:
        bot_logger.error(f"[CUSTOM CMD] {cmd}: {e}")

class _Ctx:
    def __init__(self, user_id, user_name, msg_id, is_mod, is_broadcaster, args, args_str):
        self.user_id        = user_id
        self.user_name      = user_name
        self.msg_id         = msg_id
        self.is_mod         = is_mod
        self.is_broadcaster = is_broadcaster
        self.args           = args
        self.args_str       = args_str
    def arg(self, index, default=None):
        try:
            return self.args[index]
        except IndexError:
            return default

async def _run_command(cmd: str, ctx: _Ctx):
    dispatch = {
        "commands":       cmd_commands,
        "bot":            cmd_bot,
        "version":        cmd_version,
        "ping":           cmd_ping,
        "roadmap":        cmd_roadmap,
        "uptime":         cmd_uptime,
        "game":           cmd_game,
        "points":         cmd_points,
        "addpoints":      cmd_addpoints,
        "removepoints":   cmd_removepoints,
        "quote":          cmd_quote,
        "quoteadd":       cmd_quoteadd,
        "removequote":    cmd_removequote,
        "joke":           cmd_joke,
        "weather":        cmd_weather,
        "time":           cmd_time,
        "translate":      cmd_translate,
        "hug":            cmd_hug,
        "highfive":       cmd_highfive,
        "kiss":           cmd_kiss,
        "lurk":           cmd_lurk,
        "unlurk":         cmd_unlurk,
        "lurking":        cmd_lurking,
        "lurklead":       cmd_lurklead,
        "userslurking":   cmd_userslurking,
        "slots":          cmd_slots,
        "roulette":       cmd_roulette,
        "rps":            cmd_rps,
        "gamble":         cmd_gamble,
        "deaths":         cmd_deaths,
        "deathadd":       cmd_deathadd,
        "deathremove":    cmd_deathremove,
        "kill":           cmd_kill,
        "timer":          cmd_timer,
        "stoptimer":      cmd_stoptimer,
        "checktimer":     cmd_checktimer,
        "watchtime":      cmd_watchtime,
        "followage":      cmd_followage,
        "shoutout":       cmd_shoutout,
        "so":             cmd_shoutout,
        "settitle":       cmd_settitle,
        "setgame":        cmd_setgame,
        "addcommand":     cmd_addcommand,
        "editcommand":    cmd_editcommand,
        "removecommand":  cmd_removecommand,
        "enablecommand":  cmd_enablecommand,
        "disablecommand": cmd_disablecommand,
        "permit":         cmd_permit,
        "typo":           cmd_typo,
        "typos":          cmd_typos,
        "edittypos":      cmd_edittypos,
        "removetypos":    cmd_removetypos,
        "song":           cmd_song,
        "songrequest":    cmd_songrequest,
        "skipsong":       cmd_skipsong,
        "songqueue":      cmd_songqueue,
        "steam":          cmd_steam,
        "todo":           cmd_todo,
        "todolist":       cmd_todolist,
        "schedule":       cmd_schedule,
        "story":          cmd_story,
        "convert":        cmd_convert,
        "joinraffle":     cmd_joinraffle,
        "leaveraffle":    cmd_leaveraffle,
        "createraffle":   cmd_createraffle,
        "startraffle":    cmd_startraffle,
        "stopraffle":     cmd_stopraffle,
        "drawraffle":     cmd_drawraffle,
        "startlotto":     cmd_startlotto,
        "drawlotto":      cmd_drawlotto,
        "heartrate":      cmd_heartrate,
        "puzzles":        cmd_puzzles,
        "kicks":          cmd_kicks,
        "checkupdate":    cmd_checkupdate,
    }
    handler = dispatch.get(cmd)
    if handler:
        await handler(ctx)

async def cmd_commands(_ctx: _Ctx):
    await send_chat_message("Available commands: https://botofthespecter.com/commands")

async def cmd_bot(_ctx: _Ctx):
    await send_chat_message(f"BotOfTheSpecter Kick Bot v{VERSION} — https://botofthespecter.com/")

async def cmd_version(_ctx: _Ctx):
    await send_chat_message(f"Running BotOfTheSpecter Kick Bot v{VERSION} ({SYSTEM}).")

async def cmd_roadmap(_ctx: _Ctx):
    await send_chat_message("Roadmap: https://trello.com/b/jEMJSwgb/botofthespecter")

async def cmd_ping(_ctx: _Ctx):
    uptime     = datetime.now() - bot_started
    hours, rem = divmod(int(uptime.total_seconds()), 3600)
    mins, secs = divmod(rem, 60)
    await send_chat_message(f"Pong! Bot has been running for {hours}h {mins}m {secs}s.")

async def cmd_uptime(_ctx: _Ctx):
    info = await get_livestream_info()
    if info and info.get("created_at"):
        started = datetime.fromisoformat(info["created_at"].rstrip("Z")).replace(tzinfo=timezone.utc)
        diff    = datetime.now(timezone.utc) - started
        hours, rem = divmod(int(diff.total_seconds()), 3600)
        mins, secs = divmod(rem, 60)
        await send_chat_message(f"{CHANNEL_NAME} has been live for {hours}h {mins}m {secs}s.")
    else:
        await send_chat_message(f"{CHANNEL_NAME} is not currently live.")

async def cmd_game(_ctx: _Ctx):
    info = await get_channel_info()
    if info:
        category = (info.get("category") or {}).get("name", "No game set")
        await send_chat_message(f"Currently playing: {category}")
    else:
        await send_chat_message("Couldn't retrieve channel info.")

async def cmd_points(ctx: _Ctx):
    result = await manage_user_points(ctx.user_id, ctx.user_name, "get")
    if result["success"]:
        await send_chat_message(f"@{ctx.user_name}, you have {result['points']} points.")
    else:
        await send_chat_message(f"Error retrieving points: {result['error']}")

async def cmd_addpoints(ctx: _Ctx):
    if len(ctx.args) < 2:
        await send_chat_message("Usage: !addpoints <username> <amount>")
        return
    target = ctx.args[0].lstrip("@").lower()
    try:
        amount = int(ctx.args[1])
    except ValueError:
        await send_chat_message("Amount must be a number.")
        return
    target_id = await get_kick_user_id(target)
    result    = await manage_user_points(str(target_id or target), target, "credit", amount)
    if result["success"]:
        await send_chat_message(f"Added {amount} points to {target}. They now have {result['points']} points.")

async def cmd_removepoints(ctx: _Ctx):
    if len(ctx.args) < 2:
        await send_chat_message("Usage: !removepoints <username> <amount>")
        return
    target = ctx.args[0].lstrip("@").lower()
    try:
        amount = int(ctx.args[1])
    except ValueError:
        await send_chat_message("Amount must be a number.")
        return
    target_id = await get_kick_user_id(target)
    result    = await manage_user_points(str(target_id or target), target, "debit", amount)
    if result["success"]:
        await send_chat_message(
            f"Removed {result['amount_changed']} points from {target}. They now have {result['points']} points."
        )

async def cmd_quote(ctx: _Ctx):
    try:
        number = int(ctx.args[0]) if ctx.args else None
    except ValueError:
        number = None
    async with await mysql_connection() as conn:
        async with conn.cursor(DictCursor) as cur:
            if number is None:
                await cur.execute("SELECT id, quote FROM quotes ORDER BY RAND() LIMIT 1")
            else:
                await cur.execute("SELECT id, quote FROM quotes WHERE id=%s", (number,))
            row = await cur.fetchone()
    if row:
        await send_chat_message(f"Quote #{row['id']}: {row['quote']}")
    else:
        await send_chat_message("No quote found.")

async def cmd_quoteadd(ctx: _Ctx):
    if not ctx.args_str:
        await send_chat_message("Usage: !quoteadd <quote text>")
        return
    async with await mysql_connection() as conn:
        async with conn.cursor(DictCursor) as cur:
            await cur.execute(
                "INSERT INTO quotes (quote, added_by) VALUES (%s, %s)", (ctx.args_str, ctx.user_name)
            )
            quote_id = cur.lastrowid
    await send_chat_message(f"Quote #{quote_id} added.")

async def cmd_removequote(ctx: _Ctx):
    try:
        number = int(ctx.args[0])
    except (ValueError, IndexError):
        await send_chat_message("Usage: !removequote <number>")
        return
    async with await mysql_connection() as conn:
        async with conn.cursor() as cur:
            await cur.execute("DELETE FROM quotes WHERE id=%s", (number,))
    await send_chat_message(f"Quote #{number} removed.")

async def cmd_joke(_ctx: _Ctx):
    try:
        joke     = Jokes()
        get_joke = await get_event_loop().run_in_executor(None, joke.get_joke)
        if isinstance(get_joke, list):
            get_joke = get_joke[0] if get_joke else {}
        if not isinstance(get_joke, dict):
            get_joke = {}
        if get_joke.get("type") == "single":
            await send_chat_message(f"[{get_joke.get('category','')}] {get_joke.get('joke','')}")
        else:
            await send_chat_message(
                f"[{get_joke.get('category','')}] {get_joke.get('setup','')} | {get_joke.get('delivery','')}"
            )
    except Exception as e:
        bot_logger.error(f"[JOKE] {e}")
        await send_chat_message("Couldn't fetch a joke right now.")

async def cmd_weather(ctx: _Ctx):
    location = ctx.args_str
    if not location:
        async with await mysql_connection() as conn:
            async with conn.cursor(DictCursor) as cur:
                await cur.execute("SELECT weather_location FROM profile LIMIT 1")
                row = await cur.fetchone()
        location = (row or {}).get("weather_location")
    if not location:
        await send_chat_message("Usage: !weather <location>")
        return
    if not WEATHER_API_KEY:
        await send_chat_message("Weather API not configured.")
        return
    try:
        session = await get_http_session()
        async with session.get(
            "https://api.weatherapi.com/v1/current.json",
            params={"key": WEATHER_API_KEY, "q": location, "aqi": "no"},
            timeout=ClientTimeout(total=10)
        ) as r:
            if r.status == 200:
                d = await r.json()
                loc = d["location"]
                cur = d["current"]
                await send_chat_message(
                    f"Weather in {loc['name']}, {loc['country']}: {cur['condition']['text']}, "
                    f"{cur['temp_c']}°C / {cur['temp_f']}°F, "
                    f"Humidity: {cur['humidity']}%, Wind: {cur['wind_kph']} km/h"
                )
            else:
                await send_chat_message("Location not found or weather service unavailable.")
    except Exception as e:
        bot_logger.error(f"[WEATHER] {e}")
        await send_chat_message("Weather lookup failed.")

async def cmd_time(ctx: _Ctx):
    tz_name = ctx.args_str.strip() if ctx.args_str else None
    if not tz_name:
        async with await mysql_connection() as conn:
            async with conn.cursor(DictCursor) as cur:
                await cur.execute("SELECT timezone FROM profile LIMIT 1")
                row = await cur.fetchone()
        tz_name = (row or {}).get("timezone", "UTC") or "UTC"
    try:
        tz  = pytz_timezone(tz_name)
        now = datetime.now(tz)
        await send_chat_message(f"Current time ({tz_name}): {now.strftime('%Y-%m-%d %H:%M:%S %Z')}")
    except Exception:
        await send_chat_message(f"Unknown timezone: {tz_name}")

async def cmd_translate(ctx: _Ctx):
    if not ctx.args_str:
        await send_chat_message("Usage: !translate <text>  (auto-detects language, translates to English)")
        return
    try:
        result = await get_event_loop().run_in_executor(
            None, lambda: translator(source="auto", target="en").translate(ctx.args_str)
        )
        await send_chat_message(f"Translation: {result}")
    except Exception as e:
        bot_logger.error(f"[TRANSLATE] {e}")
        await send_chat_message("Translation failed.")

async def cmd_hug(ctx: _Ctx):
    target = ctx.args[0].lstrip("@") if ctx.args else None
    if target:
        await send_chat_message(f"@{ctx.user_name} hugs @{target}! PogChamp")
    else:
        await send_chat_message(f"@{ctx.user_name} spreads hugs to everyone!")

async def cmd_highfive(ctx: _Ctx):
    target = ctx.args[0].lstrip("@") if ctx.args else None
    if target:
        await send_chat_message(f"@{ctx.user_name} high-fives @{target}! o/\\o")
    else:
        await send_chat_message(f"@{ctx.user_name} wants to high-five someone!")

async def cmd_kiss(ctx: _Ctx):
    target = ctx.args[0].lstrip("@") if ctx.args else None
    if target:
        await send_chat_message(f"@{ctx.user_name} kisses @{target}!")
    else:
        await send_chat_message(f"@{ctx.user_name} is looking for someone to kiss!")

async def cmd_lurk(ctx: _Ctx):
    async with await mysql_connection() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "INSERT INTO lurk_times (user_id, user_name, start_time) VALUES (%s, %s, %s) "
                "ON DUPLICATE KEY UPDATE start_time=VALUES(start_time)",
                (ctx.user_id, ctx.user_name, datetime.now())
            )
    await send_chat_message(f"@{ctx.user_name} is now lurking! They'll be back soon.")

async def cmd_unlurk(ctx: _Ctx):
    async with await mysql_connection() as conn:
        async with conn.cursor(DictCursor) as cur:
            await cur.execute("SELECT start_time FROM lurk_times WHERE user_id=%s", (ctx.user_id,))
            row = await cur.fetchone()
            if row:
                duration   = datetime.now() - row["start_time"]
                hours, rem = divmod(int(duration.total_seconds()), 3600)
                mins, secs = divmod(rem, 60)
                await cur.execute("DELETE FROM lurk_times WHERE user_id=%s", (ctx.user_id,))
                await send_chat_message(
                    f"Welcome back @{ctx.user_name}! You lurked for {hours}h {mins}m {secs}s."
                )
            else:
                await send_chat_message(f"Welcome back @{ctx.user_name}!")

async def cmd_lurking(_ctx: _Ctx):
    async with await mysql_connection() as conn:
        async with conn.cursor(DictCursor) as cur:
            await cur.execute("SELECT COUNT(*) as cnt FROM lurk_times")
            row = await cur.fetchone()
    count = (row or {}).get("cnt", 0)
    await send_chat_message(f"There are currently {count} lurkers in chat.")

async def cmd_lurklead(_ctx: _Ctx):
    async with await mysql_connection() as conn:
        async with conn.cursor(DictCursor) as cur:
            await cur.execute("SELECT user_name, start_time FROM lurk_times ORDER BY start_time ASC LIMIT 1")
            row = await cur.fetchone()
    if row:
        duration   = datetime.now() - row["start_time"]
        hours, rem = divmod(int(duration.total_seconds()), 3600)
        mins, secs = divmod(rem, 60)
        await send_chat_message(f"{row['user_name']} is the lurk leader with {hours}h {mins}m {secs}s!")
    else:
        await send_chat_message("Nobody is lurking right now.")

async def cmd_userslurking(_ctx: _Ctx):
    async with await mysql_connection() as conn:
        async with conn.cursor(DictCursor) as cur:
            await cur.execute("SELECT user_name FROM lurk_times ORDER BY start_time ASC")
            rows = await cur.fetchall()
    if rows:
        names = ", ".join(r["user_name"] for r in rows[:10])
        await send_chat_message(f"Lurkers: {names}" + (" …" if len(rows) > 10 else ""))
    else:
        await send_chat_message("Nobody is lurking right now.")

async def cmd_slots(ctx: _Ctx):
    symbols = ["🍒", "🍋", "🍊", "🍇", "⭐", "💎"]
    spin    = [random.choice(symbols) for _ in range(3)]
    display = " | ".join(spin)
    if spin[0] == spin[1] == spin[2]:
        msg    = "JACKPOT! You win big!"; payout = 100
    elif len(set(spin)) < 3:
        msg    = "Two of a kind! Small win!"; payout = 20
    else:
        msg    = "No match. Better luck next time!"; payout = 0
    await send_chat_message(f"@{ctx.user_name} — {display} — {msg}")
    if payout:
        await manage_user_points(ctx.user_id, ctx.user_name, "credit", payout)

async def cmd_roulette(ctx: _Ctx):
    outcome = random.choice(["survives!", "is eliminated!"])
    await send_chat_message(f"@{ctx.user_name} pulls the trigger and… {outcome}")

async def cmd_rps(ctx: _Ctx):
    choices    = ["rock", "paper", "scissors"]
    bot_choice = random.choice(choices)
    user_choice= ctx.args[0].lower() if ctx.args else ""
    if user_choice not in choices:
        await send_chat_message("Usage: !rps <rock|paper|scissors>")
        return
    if user_choice == bot_choice:
        result = "It's a tie!"
    elif (user_choice, bot_choice) in [("rock","scissors"),("scissors","paper"),("paper","rock")]:
        result = "You win!"
    else:
        result = "You lose!"
    await send_chat_message(f"@{ctx.user_name} chose {user_choice}, I chose {bot_choice}. {result}")

async def cmd_gamble(ctx: _Ctx):
    if not ctx.args:
        await send_chat_message("Usage: !gamble <amount>")
        return
    try:
        amount = int(ctx.args[0])
    except ValueError:
        await send_chat_message("Amount must be a number.")
        return
    info = await manage_user_points(ctx.user_id, ctx.user_name, "get")
    if not info["success"] or info["points"] < amount:
        await send_chat_message(f"@{ctx.user_name}, you don't have enough points.")
        return
    if random.random() < 0.5:
        r = await manage_user_points(ctx.user_id, ctx.user_name, "credit", amount)
        await send_chat_message(f"@{ctx.user_name} won {amount} points! Now has {r['points']}.")
    else:
        r = await manage_user_points(ctx.user_id, ctx.user_name, "debit", amount)
        await send_chat_message(f"@{ctx.user_name} lost {amount} points. Now has {r['points']}.")

async def cmd_deaths(_ctx: _Ctx):
    async with await mysql_connection() as conn:
        async with conn.cursor(DictCursor) as cur:
            await cur.execute("SELECT death_count, game_name FROM deaths ORDER BY id DESC LIMIT 1")
            row = await cur.fetchone()
    if row:
        await send_chat_message(f"Deaths in {row['game_name']}: {row['death_count']}")
    else:
        await send_chat_message("No death counter found.")

async def cmd_deathadd(_ctx: _Ctx):
    async with await mysql_connection() as conn:
        async with conn.cursor(DictCursor) as cur:
            await cur.execute("SELECT id, death_count, game_name FROM deaths ORDER BY id DESC LIMIT 1")
            row = await cur.fetchone()
            if row:
                new = row["death_count"] + 1
                await cur.execute("UPDATE deaths SET death_count=%s WHERE id=%s", (new, row["id"]))
                await send_chat_message(f"Death added. {row['game_name']}: {new} deaths.")
            else:
                await send_chat_message("No death counter found.")

async def cmd_deathremove(_ctx: _Ctx):
    async with await mysql_connection() as conn:
        async with conn.cursor(DictCursor) as cur:
            await cur.execute("SELECT id, death_count, game_name FROM deaths ORDER BY id DESC LIMIT 1")
            row = await cur.fetchone()
            if row and row["death_count"] > 0:
                new = row["death_count"] - 1
                await cur.execute("UPDATE deaths SET death_count=%s WHERE id=%s", (new, row["id"]))
                await send_chat_message(f"Death removed. {row['game_name']}: {new} deaths.")
            else:
                await send_chat_message("Death count is already 0 or no counter found.")

async def cmd_kill(ctx: _Ctx):
    target = ctx.args[0].lstrip("@") if ctx.args else None
    if target:
        msg = random.choice([
            f"@{ctx.user_name} obliterates @{target}!",
            f"@{ctx.user_name} defeats @{target} in an epic duel!",
            f"@{target} was no match for @{ctx.user_name}!",
        ])
        await send_chat_message(msg)
    else:
        await send_chat_message(f"@{ctx.user_name} swings their sword at thin air.")

async def cmd_timer(ctx: _Ctx):
    if not ctx.args:
        await send_chat_message("Usage: !timer <seconds>")
        return
    try:
        seconds = int(ctx.args[0])
    except ValueError:
        await send_chat_message("Seconds must be a number.")
        return
    if not (1 <= seconds <= 3600):
        await send_chat_message("Timer must be between 1 and 3600 seconds.")
        return
    key = f"timer_{ctx.user_id}"
    if key in active_timer_routines and not active_timer_routines[key].done():
        await send_chat_message(f"@{ctx.user_name}, you already have an active timer.")
        return
    await send_chat_message(f"@{ctx.user_name}, timer set for {seconds} seconds!")

    async def _run():
        await sleep(seconds)
        await send_chat_message(f"@{ctx.user_name}, your {seconds}s timer is done!")
        active_timer_routines.pop(key, None)
    active_timer_routines[key] = safe_create_task(_run())

async def cmd_stoptimer(ctx: _Ctx):
    key  = f"timer_{ctx.user_id}"
    task = active_timer_routines.pop(key, None)
    if task and not task.done():
        task.cancel()
        await send_chat_message(f"@{ctx.user_name}, your timer has been stopped.")
    else:
        await send_chat_message(f"@{ctx.user_name}, you don't have an active timer.")

async def cmd_checktimer(ctx: _Ctx):
    key  = f"timer_{ctx.user_id}"
    task = active_timer_routines.get(key)
    if task and not task.done():
        await send_chat_message(f"@{ctx.user_name}, your timer is still running.")
    else:
        await send_chat_message(f"@{ctx.user_name}, you don't have an active timer.")

async def cmd_watchtime(ctx: _Ctx):
    target = ctx.args[0].lstrip("@").lower() if ctx.args else ctx.user_name
    async with await mysql_connection() as conn:
        async with conn.cursor(DictCursor) as cur:
            await cur.execute("SELECT watch_time FROM watch_time WHERE user_name=%s", (target,))
            row = await cur.fetchone()
    if row:
        hours, mins = divmod(row["watch_time"], 60)
        await send_chat_message(f"@{target} has watched {CHANNEL_NAME} for {hours}h {mins}m.")
    else:
        await send_chat_message(f"No watch time found for @{target}.")

async def cmd_followage(ctx: _Ctx):
    target = ctx.args[0].lstrip("@").lower() if ctx.args else ctx.user_name
    async with await mysql_connection() as conn:
        async with conn.cursor(DictCursor) as cur:
            await cur.execute("SELECT followed_at FROM followers WHERE user_name=%s", (target,))
            row = await cur.fetchone()
    if row:
        days = (datetime.now() - row["followed_at"]).days
        await send_chat_message(f"@{target} has been following for {days} day(s).")
    else:
        await send_chat_message(f"No follow data found for @{target}.")

async def cmd_shoutout(ctx: _Ctx):
    target = ctx.args[0].lstrip("@").lower() if ctx.args else None
    if not target:
        await send_chat_message("Usage: !shoutout <username>")
        return
    data = await kick_get("/channels", params={"slug": target})
    if data and data.get("data"):
        ch  = data["data"][0]
        cat = (ch.get("category") or {}).get("name", "something awesome")
        await send_chat_message(
            f"Check out @{target}! They were last playing {cat}. "
            f"Give them a follow at https://kick.com/{target}"
        )
    else:
        await send_chat_message(f"Go give @{target} a follow at https://kick.com/{target}!")

async def cmd_settitle(ctx: _Ctx):
    if not ctx.args_str:
        await send_chat_message("Usage: !settitle <new title>")
        return
    if await update_channel_info(title=ctx.args_str):
        await send_chat_message(f"Stream title updated to: {ctx.args_str}")
    else:
        await send_chat_message("Failed to update stream title.")

async def cmd_setgame(ctx: _Ctx):
    if not ctx.args_str:
        await send_chat_message("Usage: !setgame <category name>")
        return
    data = await kick_get("/v2/categories", params={"q": ctx.args_str, "limit": 5})
    if data and data.get("data"):
        cat = data["data"][0]
        if await update_channel_info(category_id=cat.get("id")):
            await send_chat_message(f"Game/category set to: {cat.get('name', ctx.args_str)}")
        else:
            await send_chat_message("Failed to update category.")
    else:
        await send_chat_message(f"Category '{ctx.args_str}' not found.")

async def cmd_addcommand(ctx: _Ctx):
    parts = ctx.args_str.split(maxsplit=1) if ctx.args_str else []
    if len(parts) < 2:
        await send_chat_message("Usage: !addcommand <command> <response>")
        return
    name, response = parts[0].lstrip("!").lower(), parts[1]
    async with await mysql_connection() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "INSERT INTO custom_commands (command, response, status) VALUES (%s, %s, 'Enabled') "
                "ON DUPLICATE KEY UPDATE response=VALUES(response)",
                (name, response)
            )
    await send_chat_message(f"Command !{name} added/updated.")

async def cmd_editcommand(ctx: _Ctx):
    parts = ctx.args_str.split(maxsplit=1) if ctx.args_str else []
    if len(parts) < 2:
        await send_chat_message("Usage: !editcommand <command> <new response>")
        return
    name, response = parts[0].lstrip("!").lower(), parts[1]
    async with await mysql_connection() as conn:
        async with conn.cursor() as cur:
            await cur.execute("UPDATE custom_commands SET response=%s WHERE command=%s", (response, name))
    await send_chat_message(f"Command !{name} updated.")

async def cmd_removecommand(ctx: _Ctx):
    name = ctx.args[0].lstrip("!").lower() if ctx.args else None
    if not name:
        await send_chat_message("Usage: !removecommand <command>")
        return
    async with await mysql_connection() as conn:
        async with conn.cursor() as cur:
            await cur.execute("DELETE FROM custom_commands WHERE command=%s", (name,))
    await send_chat_message(f"Command !{name} removed.")

async def cmd_enablecommand(ctx: _Ctx):
    name = ctx.args[0].lstrip("!").lower() if ctx.args else None
    if not name:
        await send_chat_message("Usage: !enablecommand <command>")
        return
    async with await mysql_connection() as conn:
        async with conn.cursor() as cur:
            await cur.execute("UPDATE builtin_commands SET status='Enabled' WHERE command=%s", (name,))
    await send_chat_message(f"Command !{name} enabled.")

async def cmd_disablecommand(ctx: _Ctx):
    name = ctx.args[0].lstrip("!").lower() if ctx.args else None
    if not name:
        await send_chat_message("Usage: !disablecommand <command>")
        return
    async with await mysql_connection() as conn:
        async with conn.cursor() as cur:
            await cur.execute("UPDATE builtin_commands SET status='Disabled' WHERE command=%s", (name,))
    await send_chat_message(f"Command !{name} disabled.")

async def cmd_permit(ctx: _Ctx):
    target = ctx.args[0].lstrip("@").lower() if ctx.args else None
    if not target:
        await send_chat_message("Usage: !permit <username>")
        return
    target_id = await get_kick_user_id(target)
    if target_id:
        permitted_users[str(target_id)] = time.time() + 60
        await send_chat_message(f"@{target} has been permitted for 60 seconds.")

async def cmd_typo(ctx: _Ctx):
    target = ctx.args[0].lstrip("@").lower() if ctx.args else ctx.user_name
    async with await mysql_connection() as conn:
        async with conn.cursor(DictCursor) as cur:
            await cur.execute("SELECT typo_count FROM typos WHERE user_name=%s", (target,))
            row = await cur.fetchone()
    count = (row or {}).get("typo_count", 0)
    await send_chat_message(f"@{target} has made {count} typo(s).")

async def cmd_typos(ctx: _Ctx):
    await cmd_typo(ctx)

async def cmd_edittypos(ctx: _Ctx):
    if len(ctx.args) < 2:
        await send_chat_message("Usage: !edittypos <username> <count>")
        return
    target = ctx.args[0].lstrip("@").lower()
    try:
        count = int(ctx.args[1])
    except ValueError:
        await send_chat_message("Count must be a number.")
        return
    async with await mysql_connection() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "INSERT INTO typos (user_name, typo_count) VALUES (%s, %s) "
                "ON DUPLICATE KEY UPDATE typo_count=%s",
                (target, count, count)
            )
    await send_chat_message(f"Typo count for @{target} set to {count}.")

async def cmd_removetypos(ctx: _Ctx):
    target = ctx.args[0].lstrip("@").lower() if ctx.args else None
    if not target:
        await send_chat_message("Usage: !removetypos <username>")
        return
    async with await mysql_connection() as conn:
        async with conn.cursor() as cur:
            await cur.execute("DELETE FROM typos WHERE user_name=%s", (target,))
    await send_chat_message(f"Typos for @{target} removed.")

async def cmd_song(_ctx: _Ctx):
    song = await get_spotify_current_song()
    if song:
        await send_chat_message(f"Currently playing: {song}")
    else:
        await send_chat_message("No song is currently playing or Spotify not connected.")

async def cmd_songrequest(ctx: _Ctx):
    if not ctx.args_str:
        await send_chat_message("Usage: !songrequest <song name or URL>")
        return
    await send_chat_message(f"Song request from @{ctx.user_name}: {ctx.args_str} — queued.")

async def cmd_skipsong(_ctx: _Ctx):
    if await skip_spotify_song():
        await send_chat_message("Skipped to the next song.")
    else:
        await send_chat_message("Couldn't skip the song.")

async def cmd_songqueue(_ctx: _Ctx):
    if song_requests:
        q = ", ".join(v.get("title", "?") for v in list(song_requests.values())[:5])
        await send_chat_message(f"Song queue: {q}")
    else:
        await send_chat_message("The song queue is empty.")

async def cmd_steam(ctx: _Ctx):
    if not ctx.args_str:
        await send_chat_message("Usage: !steam <game name>")
        return
    try:
        session = await get_http_session()
        async with session.get(
            "https://store.steampowered.com/api/storesearch/",
            params={"term": ctx.args_str, "cc": "us", "l": "en"},
            timeout=ClientTimeout(total=10)
        ) as r:
            data = await r.json()
        items = data.get("items", [])
        if items:
            item = items[0]
            price = (item.get("price") or {}).get("final_formatted", "Free/N/A")
            await send_chat_message(
                f"{item['name']}: https://store.steampowered.com/app/{item['id']}/ — {price}"
            )
        else:
            await send_chat_message(f"No Steam game found for '{ctx.args_str}'.")
    except Exception as e:
        bot_logger.error(f"[STEAM] {e}")
        await send_chat_message("Steam lookup failed.")

async def cmd_todo(_ctx: _Ctx):
    async with await mysql_connection() as conn:
        async with conn.cursor(DictCursor) as cur:
            await cur.execute("SELECT id, task FROM todolist WHERE completed=0 ORDER BY id LIMIT 5")
            rows = await cur.fetchall()
    if rows:
        items = " | ".join(f"#{r['id']}: {r['task']}" for r in rows)
        await send_chat_message(f"TODO: {items}")
    else:
        await send_chat_message("No pending items in the TODO list.")

async def cmd_todolist(ctx: _Ctx):
    await cmd_todo(ctx)

async def cmd_schedule(_ctx: _Ctx):
    async with await mysql_connection() as conn:
        async with conn.cursor(DictCursor) as cur:
            await cur.execute(
                "SELECT day, time_start, time_end FROM streaming_schedule ORDER BY id LIMIT 7"
            )
            rows = await cur.fetchall()
    if rows:
        entries = " | ".join(f"{r['day']}: {r['time_start']}–{r['time_end']}" for r in rows)
        await send_chat_message(f"Stream schedule: {entries}")
    else:
        await send_chat_message("No schedule set.")

async def cmd_story(ctx: _Ctx):
    prompt = ctx.args_str or "Tell me a short funny story."
    try:
        response = await openai_client.chat.completions.create(
            model="gpt-4o-mini",
            messages=[{"role": "user", "content": prompt}],
            max_tokens=200,
        )
        text = response.choices[0].message.content.strip()
        await send_chat_message(text[:MAX_CHAT_MESSAGE_LENGTH])
    except Exception as e:
        bot_logger.error(f"[STORY] {e}")
        await send_chat_message("Couldn't generate a story right now.")

async def cmd_convert(ctx: _Ctx):
    if not ctx.args_str or " to " not in ctx.args_str:
        await send_chat_message("Usage: !convert <amount> <from_unit> to <to_unit>")
        return
    try:
        registry = ureg()
        parts    = ctx.args_str.split(" to ", 1)
        q        = registry.parse_expression(parts[0].strip())
        result   = q.to(parts[1].strip())
        await send_chat_message(f"{ctx.args_str} = {result:.4g~P}")
    except Exception as e:
        bot_logger.error(f"[CONVERT] {e}")
        await send_chat_message("Conversion failed. Check the units and try again.")

_raffle_active   = False
_raffle_entries: set = set()

async def cmd_createraffle(_ctx: _Ctx):
    global _raffle_active, _raffle_entries
    _raffle_active  = False
    _raffle_entries = set()
    await send_chat_message("Raffle created! Use !startraffle to open entries.")

async def cmd_startraffle(_ctx: _Ctx):
    global _raffle_active
    _raffle_active = True
    await send_chat_message("Raffle is now open! Type !joinraffle to enter!")

async def cmd_stopraffle(_ctx: _Ctx):
    global _raffle_active
    _raffle_active = False
    await send_chat_message(f"Raffle closed with {len(_raffle_entries)} entries. Use !drawraffle to pick a winner.")

async def cmd_joinraffle(ctx: _Ctx):
    if not _raffle_active:
        await send_chat_message("No raffle is open right now.")
        return
    _raffle_entries.add(ctx.user_name)
    await send_chat_message(f"@{ctx.user_name} entered the raffle!")

async def cmd_leaveraffle(ctx: _Ctx):
    _raffle_entries.discard(ctx.user_name)
    await send_chat_message(f"@{ctx.user_name} left the raffle.")

async def cmd_drawraffle(_ctx: _Ctx):
    if not _raffle_entries:
        await send_chat_message("No entries in the raffle!")
        return
    winner = random.choice(list(_raffle_entries))
    _raffle_entries.discard(winner)
    await send_chat_message(f"Congratulations @{winner}! You won the raffle!")

_lotto_active  = False
_lotto_entries: dict = {}   # {username: number}

async def cmd_startlotto(_ctx: _Ctx):
    global _lotto_active, _lotto_entries
    _lotto_active  = True
    _lotto_entries = {}
    await send_chat_message("Lottery started! Type !joinraffle <1-100> to pick your number!")

async def cmd_drawlotto(_ctx: _Ctx):
    global _lotto_active
    _lotto_active = False
    if not _lotto_entries:
        await send_chat_message("No entries in the lottery!")
        return
    winning = random.randint(1, 100)
    winners = [u for u, n in _lotto_entries.items() if n == winning]
    if winners:
        await send_chat_message(f"Winning number: {winning}! Winners: {', '.join(winners)}!")
    else:
        closest = min(_lotto_entries.items(), key=lambda x: abs(x[1] - winning))
        await send_chat_message(
            f"Winning number: {winning}! No exact match. Closest: @{closest[0]} ({closest[1]})!"
        )

async def cmd_heartrate(_ctx: _Ctx):
    if HEARTRATE is not None:
        await send_chat_message(f"Current heart rate: {HEARTRATE} BPM")
    else:
        await send_chat_message("Heart rate monitor not connected.")

async def cmd_puzzles(_ctx: _Ctx):
    await send_chat_message("Puzzles: https://botofthespecter.com/puzzles")

async def cmd_kicks(_ctx: _Ctx):
    data = await kick_get("/kicks/leaderboard", params={"top": 5})
    if data and data.get("data"):
        board = " | ".join(
            f"{e.get('user',{}).get('slug','?')}: {e.get('amount',0)}" for e in data["data"]
        )
        await send_chat_message(f"KICKS Leaderboard: {board}")
    else:
        await send_chat_message("Couldn't fetch KICKS leaderboard.")

async def cmd_checkupdate(_ctx: _Ctx):
    await send_chat_message(f"Running Kick Bot v{VERSION}. Check https://botofthespecter.com/ for updates.")

async def manage_user_points(user_id: str, user_name: str, action: str, amount: int = 0) -> dict:
    try:
        async with await mysql_connection() as conn:
            async with conn.cursor(DictCursor) as cur:
                await cur.execute("SELECT points FROM bot_points WHERE user_id=%s", (user_id,))
                row = await cur.fetchone()
                if row:
                    current = row["points"]
                else:
                    await cur.execute(
                        "INSERT INTO bot_points (user_id, user_name, points) VALUES (%s, %s, 0)",
                        (user_id, user_name)
                    )
                    current = 0
                if action == "get":
                    return {"success": True, "points": current, "previous_points": current, "amount_changed": 0, "error": None}
                elif action == "credit":
                    new = current + amount
                    await cur.execute("UPDATE bot_points SET points=%s WHERE user_id=%s", (new, user_id))
                    return {"success": True, "points": new, "previous_points": current, "amount_changed": amount, "error": None}
                elif action == "debit":
                    actual = min(amount, current)
                    new    = max(0, current - amount)
                    await cur.execute("UPDATE bot_points SET points=%s WHERE user_id=%s", (new, user_id))
                    return {"success": True, "points": new, "previous_points": current, "amount_changed": actual, "error": None}
                return {"success": False, "points": current, "previous_points": current, "amount_changed": 0, "error": "Invalid action"}
    except Exception as e:
        bot_logger.error(f"[POINTS] {e}")
        return {"success": False, "points": 0, "previous_points": 0, "amount_changed": 0, "error": str(e)}

async def get_kick_user_id(slug: str) -> int | None:
    data = await kick_get("/channels", params={"slug": slug})
    if data and data.get("data"):
        return data["data"][0].get("broadcaster_user_id")
    return None

async def get_spotify_access_token() -> str | None:
    try:
        async with await mysql_connection(db_name="website") as conn:
            async with conn.cursor(DictCursor) as cur:
                await cur.execute("SELECT id FROM users WHERE username=%s", (CHANNEL_NAME,))
                user_row = await cur.fetchone()
                if user_row:
                    await cur.execute("SELECT access_token FROM spotify_tokens WHERE user_id=%s", (user_row["id"],))
                    row = await cur.fetchone()
                    return row["access_token"] if row else None
    except Exception as e:
        api_logger.error(f"[SPOTIFY] {e}")
    return None

async def get_spotify_current_song() -> str | None:
    token = await get_spotify_access_token()
    if not token:
        return None
    try:
        session = await get_http_session()
        async with session.get(
            "https://api.spotify.com/v1/me/player/currently-playing",
            headers={"Authorization": f"Bearer {token}"},
            timeout=ClientTimeout(total=10)
        ) as r:
            if r.status == 200:
                data    = await r.json()
                item    = data.get("item", {})
                artists = ", ".join(a["name"] for a in item.get("artists", []))
                return f"{item.get('name','Unknown')} by {artists}"
    except Exception as e:
        api_logger.error(f"[SPOTIFY] {e}")
    return None

async def skip_spotify_song() -> bool:
    token = await get_spotify_access_token()
    if not token:
        return False
    try:
        session = await get_http_session()
        async with session.post(
            "https://api.spotify.com/v1/me/player/next",
            headers={"Authorization": f"Bearer {token}"},
            timeout=ClientTimeout(total=10)
        ) as r:
            return r.status in (200, 204)
    except Exception:
        return False

async def check_stream_online_loop():
    global stream_online
    while True:
        try:
            info    = await get_livestream_info()
            is_live = bool(info and info.get("is_live"))
            if is_live != stream_online:
                stream_online = is_live
                if is_live:
                    await on_stream_online()
                else:
                    await on_stream_offline()
        except Exception as e:
            bot_logger.error(f"[STREAM CHECK] {e}")
        await sleep(60)

async def periodic_watch_time_update():
    while stream_online:
        await sleep(300)
        if not stream_online:
            break
        try:
            async with await mysql_connection() as conn:
                async with conn.cursor() as cur:
                    # Increment watch time for all known lurkers/active chatters
                    await cur.execute(
                        "UPDATE watch_time SET watch_time = watch_time + 5 "
                        "WHERE user_name IN (SELECT user_name FROM lurk_times)"
                    )
        except Exception as e:
            bot_logger.error(f"[WATCH TIME] {e}")

async def award_chat_points(user_id: str, user_name: str):
    try:
        await manage_user_points(user_id, user_name, "credit", 1)
    except Exception as e:
        bot_logger.error(f"[CHAT POINTS] {e}")

async def load_timed_messages():
    global message_tasks, chat_trigger_tasks
    for task in list(message_tasks.values()):
        if not task.done():
            task.cancel()
    message_tasks.clear()
    chat_trigger_tasks.clear()
    try:
        async with await mysql_connection() as conn:
            async with conn.cursor(DictCursor) as cur:
                await cur.execute(
                    "SELECT id, message, interval_seconds, chat_line_trigger "
                    "FROM timed_messages WHERE status='Enabled'"
                )
                rows = await cur.fetchall()
        for row in rows:
            mid      = row["id"]
            msg      = row["message"]
            interval = row.get("interval_seconds", 0) or 0
            trigger  = row.get("chat_line_trigger",  0) or 0
            if trigger > 0:
                chat_trigger_tasks[mid] = {
                    "message":          msg,
                    "chat_line_trigger": trigger,
                    "last_trigger_count": chat_line_count,
                }
            elif interval > 0:
                message_tasks[mid] = safe_create_task(send_interval_message(msg, interval))
    except Exception as e:
        bot_logger.error(f"[TIMED MSG] Load error: {e}")

async def send_interval_message(message, interval_seconds):
    while stream_online:
        await sleep(interval_seconds)
        if stream_online:
            await send_chat_message(message)

async def send_timed_message(message_id, message, delay):
    global last_message_time
    if delay > 0:
        await sleep(delay)
    if stream_online:
        await send_chat_message(message)
        last_message_time = get_event_loop().time()

async def midnight_reset():
    while True:
        try:
            async with await mysql_connection() as conn:
                async with conn.cursor(DictCursor) as cur:
                    await cur.execute("SELECT timezone FROM profile LIMIT 1")
                    row = await cur.fetchone()
            tz_name = (row or {}).get("timezone", "UTC") or "UTC"
            tz      = pytz_timezone(tz_name)
            now     = datetime.now(tz)
            tomorrow = (now + timedelta(days=1)).replace(hour=0, minute=0, second=0, microsecond=0)
            await sleep((tomorrow - now).total_seconds())
            bot_logger.info("[MIDNIGHT] Daily reset triggered.")
        except Exception as e:
            bot_logger.error(f"[MIDNIGHT] {e}")
            await sleep(3600)

async def builtin_commands_creation():
    all_cmds = list(mod_commands) + list(builtin_commands)
    try:
        async with await mysql_connection() as conn:
            async with conn.cursor(DictCursor) as cur:
                placeholders = ", ".join(["%s"] * len(all_cmds))
                await cur.execute(
                    f"SELECT command FROM builtin_commands WHERE command IN ({placeholders})",
                    tuple(all_cmds)
                )
                existing = {r["command"] for r in await cur.fetchall()}
                new_cmds = [c for c in all_cmds if c not in existing]
                if new_cmds:
                    values = [
                        (c, "Enabled", "mod" if c in mod_commands else "everyone")
                        for c in new_cmds
                    ]
                    await cur.executemany(
                        "INSERT INTO builtin_commands (command, status, permission) VALUES (%s, %s, %s)",
                        values
                    )
                    bot_logger.info(f"[STARTUP] Added {len(new_cmds)} new builtin command(s) to DB.")
    except Exception as e:
        bot_logger.error(f"[STARTUP] builtin_commands_creation: {e}")

async def update_version_control():
    directory = "/home/botofthespecter/logs/version/kick/"
    os.makedirs(directory, exist_ok=True)
    path = os.path.join(directory, f"{CHANNEL_NAME}_kick_version_control.txt")
    try:
        with open(path, "w") as f:
            f.write(VERSION)
    except Exception as e:
        bot_logger.error(f"[VERSION] {e}")

async def main():
    global _shared_http_session
    bot_logger.info(f"[BOT] Starting Kick bot — channel: {CHANNEL_NAME} | channel_id: {CHANNEL_ID} | chatroom_id: {CHATROOM_ID}")
    _shared_http_session = httpClientSession()
    await update_version_control()
    await builtin_commands_creation()
    # Check whether the stream is already live before the WS connects
    info = await get_livestream_info()
    global stream_online
    if info and info.get("is_live"):
        stream_online = True
        await on_stream_online()
    # Long-running background loops
    looped_tasks["token_refresh"] = create_task(kick_token_refresh_loop())
    looped_tasks["stream_check"]  = create_task(check_stream_online_loop())
    looped_tasks["midnight"]      = create_task(midnight_reset())
    looped_tasks["websocket"]     = create_task(specter_websocket())
    await send_chat_message(f"SpecterSystems connected and ready! Kick Bot V{VERSION}")
    bot_logger.info("[BOT] Fully started. Listening on WebSocket.")
    try:
        while True:
            await sleep(3600)
    except (asyncioCancelledError, KeyboardInterrupt):
        bot_logger.info("[BOT] Shutting down.")
        await _async_shutdown()

if __name__ == "__main__":
    import asyncio
    asyncio.run(main())