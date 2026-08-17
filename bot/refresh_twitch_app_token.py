#!/usr/bin/env python3
# Validates website.bot_chat_token and remints via client_credentials if invalid or within 24h.
import os
import re
import sys
import asyncio
import logging
from datetime import datetime, timedelta
from logging.handlers import RotatingFileHandler

import aiomysql
import aiohttp
from dotenv import load_dotenv

load_dotenv()

SQL_HOST = os.getenv("SQL_HOST")
SQL_USER = os.getenv("SQL_USER")
SQL_PASSWORD = os.getenv("SQL_PASSWORD")
CLIENT_ID = os.getenv("CLIENT_ID")
CLIENT_SECRET = os.getenv("CLIENT_SECRET")

RENEW_IF_EXPIRES_WITHIN_SECONDS = 86400  # matches twitch_tokens.php auto_renew_if_24h

TOKEN_COLUMN_CANDIDATES = (
    "twitch_oauth_api_token",
    "oauth",
    "chat_oauth_token",
    "twitch_oauth_token",
    "twitch_access_token",
    "bot_oauth_token",
)
EXPIRES_COLUMN_CANDIDATES = (
    "twitch_oauth_api_expires_at",
    "oauth_expires_at",
    "chat_token_expires_at",
    "token_expires",
    "token_expires_at",
)

VALIDATE_URL = "https://id.twitch.tv/oauth2/validate"
TOKEN_URL = "https://id.twitch.tv/oauth2/token"

LOG_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "logs", "refresh_twitch_app_token.log")

logger = logging.getLogger("TwitchAppTokenRefresh")
logger.setLevel(logging.INFO)


def _setup_logging():
    if logger.handlers:
        return
    os.makedirs(os.path.dirname(LOG_FILE), exist_ok=True)
    formatter = logging.Formatter("%(asctime)s - %(levelname)s - %(message)s", datefmt="%Y-%m-%d %H:%M:%S")
    file_handler = RotatingFileHandler(LOG_FILE, maxBytes=10485760, backupCount=5, encoding="utf-8")
    file_handler.setFormatter(formatter)
    logger.addHandler(file_handler)
    console_handler = logging.StreamHandler(sys.stdout)
    console_handler.setFormatter(formatter)
    logger.addHandler(console_handler)


def _safe_column(name):
    if isinstance(name, str) and re.match(r"^[A-Za-z0-9_]+$", name):
        return name
    return None


def _first_present_key(row, candidates):
    for candidate in candidates:
        if candidate in row:
            return candidate
    return None


def _token_last4(token):
    token = (token or "").strip()
    if not token:
        return "(empty)"
    if len(token) <= 4:
        return "****"
    return f"...{token[-4:]}"


def _format_remaining(expires_in):
    remaining = max(0, int(expires_in))
    days, rem = divmod(remaining, 86400)
    hours, rem = divmod(rem, 3600)
    minutes, seconds = divmod(rem, 60)
    parts = []
    if days:
        parts.append(f"{days}d")
    if hours:
        parts.append(f"{hours}h")
    if minutes:
        parts.append(f"{minutes}m")
    if seconds or not parts:
        parts.append(f"{seconds}s")
    return " ".join(parts)


async def get_database_connection():
    return await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db="website",
        cursorclass=aiomysql.DictCursor,
        autocommit=False,
    )


async def load_chat_token_row(connection):
    async with connection.cursor() as cursor:
        await cursor.execute("SELECT * FROM bot_chat_token ORDER BY id ASC LIMIT 1")
        return await cursor.fetchone()


def read_stored_token(row):
    if not row:
        return "", None
    token_key = _first_present_key(row, TOKEN_COLUMN_CANDIDATES)
    token = str(row.get(token_key, "")).strip() if token_key else ""
    return token, token_key


async def persist_chat_token(connection, access_token, expires_in, existing_row):
    expires_at = datetime.now() + timedelta(seconds=int(expires_in))
    expires_at_str = expires_at.strftime("%Y-%m-%d %H:%M:%S")
    if not existing_row:
        async with connection.cursor() as cursor:
            await cursor.execute(
                "INSERT INTO bot_chat_token (oauth, twitch_oauth_api_token, twitch_oauth_api_expires_at) VALUES (%s, %s, %s)",
                (access_token, access_token, expires_at_str),
            )
        await connection.commit()
        return expires_at_str

    token_columns = []
    for candidate in TOKEN_COLUMN_CANDIDATES:
        if candidate in existing_row and _safe_column(candidate):
            token_columns.append(candidate)
    if not token_columns:
        raise RuntimeError("bot_chat_token has no recognised token column to update")

    expires_key = _first_present_key(existing_row, EXPIRES_COLUMN_CANDIDATES)
    expires_key = _safe_column(expires_key) if expires_key else None

    set_parts = [f"`{col}` = %s" for col in token_columns]
    values = [access_token] * len(token_columns)
    if expires_key:
        set_parts.append(f"`{expires_key}` = %s")
        values.append(expires_at_str)

    row_id = int(existing_row.get("id") or 0)
    async with connection.cursor() as cursor:
        if row_id > 0:
            values.append(row_id)
            sql = f"UPDATE bot_chat_token SET {', '.join(set_parts)} WHERE id = %s LIMIT 1"
        else:
            sql = f"UPDATE bot_chat_token SET {', '.join(set_parts)} ORDER BY id ASC LIMIT 1"
        await cursor.execute(sql, tuple(values))
    await connection.commit()
    return expires_at_str


async def verify_persisted_token(connection, expected_token):
    row = await load_chat_token_row(connection)
    stored, _ = read_stored_token(row)
    expected = (expected_token or "").strip()
    if stored != expected:
        raise RuntimeError(
            f"Persisted token mismatch (stored_len={len(stored)}, expected_len={len(expected)})"
        )


async def validate_token(session, token):
    headers = {"Authorization": f"OAuth {token}"}
    async with session.get(VALIDATE_URL, headers=headers, timeout=aiohttp.ClientTimeout(total=10)) as resp:
        body_text = await resp.text()
        try:
            body = await resp.json(content_type=None)
        except Exception:
            body = None
        return resp.status, body, body_text


async def mint_app_token(session):
    data = {
        "client_id": CLIENT_ID,
        "client_secret": CLIENT_SECRET,
        "grant_type": "client_credentials",
    }
    async with session.post(TOKEN_URL, data=data, timeout=aiohttp.ClientTimeout(total=10)) as resp:
        body_text = await resp.text()
        if resp.status != 200:
            raise RuntimeError(f"client_credentials failed: HTTP {resp.status} - {body_text[:300]}")
        try:
            body = await resp.json(content_type=None)
        except Exception as e:
            raise RuntimeError(f"client_credentials returned non-JSON: {e}") from e
        access_token = (body or {}).get("access_token")
        expires_in = int((body or {}).get("expires_in") or 0)
        if not access_token:
            raise RuntimeError("client_credentials response missing access_token")
        if expires_in <= 0:
            raise RuntimeError("client_credentials response missing expires_in")
        return access_token, expires_in


async def remint_and_persist(session, connection, existing_row, reason):
    logger.info(f"Minting a new Twitch app token ({reason})")
    access_token, expires_in = await mint_app_token(session)
    expires_at = await persist_chat_token(connection, access_token, expires_in, existing_row)
    await verify_persisted_token(connection, access_token)
    logger.info(
        f"Renewed Twitch app token {_token_last4(access_token)}; "
        f"expires_in={expires_in}s ({_format_remaining(expires_in)}) at {expires_at}"
    )


async def ensure_twitch_app_token():
    missing = [name for name, value in (
        ("SQL_HOST", SQL_HOST),
        ("SQL_USER", SQL_USER),
        ("SQL_PASSWORD", SQL_PASSWORD),
        ("CLIENT_ID", CLIENT_ID),
        ("CLIENT_SECRET", CLIENT_SECRET),
    ) if not value]
    if missing:
        raise RuntimeError(f"Missing required environment variables: {', '.join(missing)}")

    connection = await get_database_connection()
    try:
        row = await load_chat_token_row(connection)
        stored_token, token_key = read_stored_token(row)
        async with aiohttp.ClientSession() as session:
            if not stored_token:
                await remint_and_persist(session, connection, row, "no token stored")
                return

            logger.info(f"Validating stored Twitch app token {_token_last4(stored_token)} (column={token_key})")
            try:
                status, body, body_text = await validate_token(session, stored_token)
            except (aiohttp.ClientError, asyncio.TimeoutError) as e:
                raise RuntimeError(f"oauth2/validate request failed: {e}") from e

            if status == 200:
                expires_in = int((body or {}).get("expires_in") or 0)
                if expires_in <= RENEW_IF_EXPIRES_WITHIN_SECONDS:
                    await remint_and_persist(
                        session,
                        connection,
                        row,
                        f"expires_in={expires_in}s within {RENEW_IF_EXPIRES_WITHIN_SECONDS}s window",
                    )
                    return
                try:
                    await persist_chat_token(connection, stored_token, expires_in, row)
                    await verify_persisted_token(connection, stored_token)
                except Exception as e:
                    logger.warning(f"Token valid but expiry sync failed: {e}")
                logger.info(
                    f"Twitch app token valid; {_format_remaining(expires_in)} remaining "
                    f"(expires_in={expires_in})"
                )
                return

            if status in (401, 403):
                logger.warning(
                    f"Stored Twitch app token rejected by validate: HTTP {status} - {body_text[:300]}"
                )
                await remint_and_persist(session, connection, row, f"validate HTTP {status}")
                return

            raise RuntimeError(f"oauth2/validate unexpected status {status}: {body_text[:300]}")
    finally:
        connection.close()


async def main():
    logger.info("Starting Twitch app token check")
    await ensure_twitch_app_token()
    logger.info("Twitch app token check completed")


if __name__ == "__main__":
    _setup_logging()
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        logger.info("Twitch app token check stopped by user")
        sys.exit(0)
    except Exception as e:
        logger.error(f"Twitch app token check failed: {e}")
        sys.exit(1)
else:
    _setup_logging()
