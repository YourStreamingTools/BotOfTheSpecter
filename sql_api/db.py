"""MySQL pools and tenant resolution for the SQL data API."""
from __future__ import annotations

import logging
import os
from typing import Any

import aiomysql

log = logging.getLogger("sql_api")

SQL_HOST = os.getenv("SQL_HOST", "127.0.0.1")
SQL_USER = os.getenv("SQL_USER")
SQL_PASSWORD = os.getenv("SQL_PASSWORD")
SQL_PORT = int(os.getenv("SQL_PORT") or "3306")

# Admin keys with this service (or super-admin "admin") may act as any channel.
SQL_ADMIN_SERVICE = (os.getenv("SQL_ADMIN_SERVICE") or "sql").strip().lower()

_website_pool: aiomysql.Pool | None = None


async def init_pools() -> None:
    global _website_pool
    if not all([SQL_HOST, SQL_USER, SQL_PASSWORD]):
        raise RuntimeError("SQL_HOST, SQL_USER, SQL_PASSWORD must be set")
    _website_pool = await aiomysql.create_pool(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db="website",
        port=SQL_PORT,
        autocommit=True,
        minsize=1,
        maxsize=10,
    )
    log.info(
        "sql_api pools ready (host=%s port=%s admin_service=%r)",
        SQL_HOST,
        SQL_PORT,
        SQL_ADMIN_SERVICE,
    )


async def close_pools() -> None:
    global _website_pool
    if _website_pool is not None:
        _website_pool.close()
        await _website_pool.wait_closed()
        _website_pool = None


def website_pool() -> aiomysql.Pool:
    if _website_pool is None:
        raise RuntimeError("Database pool not initialized")
    return _website_pool


async def acquire_user_connection(database: str) -> aiomysql.Connection:
    """Open a one-shot connection to a tenant database (not pooled per user)."""
    return await aiomysql.connect(
        host=SQL_HOST,
        user=SQL_USER,
        password=SQL_PASSWORD,
        db=database,
        port=SQL_PORT,
        autocommit=True,
    )


async def schema_exists(schema: str) -> bool:
    pool = website_pool()
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA "
                "WHERE SCHEMA_NAME = %s LIMIT 1",
                (schema,),
            )
            row = await cur.fetchone()
            return row is not None


async def ensure_schema(schema: str) -> None:
    """Create empty schema if missing. Identifier must already be validated."""
    # Schema names are validated by caller; still only use parameterized path where possible.
    # CREATE DATABASE cannot bind identifiers — schema is validated as MySQL ident.
    pool = website_pool()
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(f"CREATE DATABASE IF NOT EXISTS `{schema}`")


async def resolve_api_key(api_key: str) -> dict[str, Any] | None:
    """
    Resolve X-API-KEY to a principal.

    Returns:
      {"type": "user", "username": str}
      {"type": "admin", "service": str}
      None if invalid
    """
    if not api_key:
        return None
    pool = website_pool()
    async with pool.acquire() as conn:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(
                "SELECT username FROM users WHERE api_key = %s LIMIT 1",
                (api_key,),
            )
            user_row = await cur.fetchone()
            if user_row and user_row.get("username"):
                return {
                    "type": "user",
                    "username": str(user_row["username"]).strip().lower(),
                }

            await cur.execute(
                "SELECT service FROM admin_api_keys WHERE api_key = %s LIMIT 1",
                (api_key,),
            )
            admin_row = await cur.fetchone()
            if not admin_row:
                return None
            service = (admin_row.get("service") or "").strip().lower()
            if service == "admin" or service == SQL_ADMIN_SERVICE:
                return {"type": "admin", "service": service}
    return None


def tenant_db_name(username: str) -> str:
    return username.strip().lower()


def modules_db_name(username: str) -> str:
    return f"{username.strip().lower()}_custom_modules"
