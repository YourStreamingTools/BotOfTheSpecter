#!/usr/bin/env python3
"""
Private bot-host control API.

Runs on the bot server only. Trusted backends (public API, dashboard) call it
with an admin API key created on the dashboard Admin → API Keys page.

Create a key with service name:  bots
(super-admin service "admin" is also accepted)

Public surface (behind TLS at bots.botofthespecter.com):
  GET  /health
  GET  /api/running_bots
  GET  /api/bot/status?channel=&bot_type=  (includes script_mtime / last_run_mtime)
  POST /api/bot/start
  POST /api/bot/stop
  POST /api/bot/restart

Auth: header X-API-KEY (or X-BOTS-CONTROL-KEY) must be a row in
website.admin_api_keys with service "bots" or "admin".
"""
from __future__ import annotations

import logging
import os
import secrets
from contextlib import asynccontextmanager
from typing import Any

import aiomysql
from dotenv import load_dotenv
from fastapi import Depends, FastAPI, Header, HTTPException, Query, status
from pydantic import BaseModel, Field

from manager import list_running_bots, restart_bot, start_bot, status_for_channel, stop_bot

load_dotenv()

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s [bots-api] %(message)s",
)
log = logging.getLogger("bots_api")

# Canonical admin_api_keys.service name (create this on Admin → API Keys)
BOTS_ADMIN_SERVICE = (os.getenv("BOTS_ADMIN_SERVICE") or "bots").strip().lower()
# Optional env fallback while migrating; prefer DB admin key with service=bots
ENV_FALLBACK_KEY = (os.getenv("BOTS_CONTROL_KEY") or "").strip()

SQL_HOST = os.getenv("SQL_HOST")
SQL_USER = os.getenv("SQL_USER")
SQL_PASSWORD = os.getenv("SQL_PASSWORD")
SQL_PORT = int(os.getenv("SQL_PORT") or "3306")

HOST = os.getenv("BOTS_API_HOST", "0.0.0.0")
PORT = int(os.getenv("BOTS_API_PORT", "8090"))

_pool: aiomysql.Pool | None = None


@asynccontextmanager
async def lifespan(_app: FastAPI):
    global _pool
    if not all([SQL_HOST, SQL_USER, SQL_PASSWORD]):
        log.warning("SQL_* not fully set — admin_api_keys lookup will fail (env fallback only)")
    else:
        _pool = await aiomysql.create_pool(
            host=SQL_HOST,
            user=SQL_USER,
            password=SQL_PASSWORD,
            db="website",
            port=SQL_PORT,
            autocommit=True,
            minsize=1,
            maxsize=5,
        )
        log.info(
            "bots control API ready (auth: admin_api_keys service=%r or admin; env fallback=%s)",
            BOTS_ADMIN_SERVICE,
            "yes" if ENV_FALLBACK_KEY else "no",
        )
    yield
    if _pool is not None:
        _pool.close()
        await _pool.wait_closed()
        _pool = None


app = FastAPI(
    title="BotOfTheSpecter Bot Host Control API",
    description=(
        "Private process-control API for the bot server. Not for end users. "
        f"Authenticate with an admin API key (service={BOTS_ADMIN_SERVICE!r} or admin)."
    ),
    version="1.0.0",
    lifespan=lifespan,
    docs_url="/docs",
    redoc_url=None,
)


async def _admin_key_allowed(api_key: str) -> bool:
    """True if key is super-admin or scoped to the bots service."""
    if not api_key or _pool is None:
        return False
    try:
        async with _pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute(
                    "SELECT service FROM admin_api_keys WHERE api_key = %s LIMIT 1",
                    (api_key,),
                )
                row = await cur.fetchone()
        if not row:
            return False
        key_service = (row.get("service") or "").strip().lower()
        if key_service == "admin":
            return True
        return key_service == BOTS_ADMIN_SERVICE
    except Exception as e:
        log.error("admin_api_keys lookup failed: %s", e)
        return False


async def require_control_key(
    x_api_key: str | None = Header(None, alias="X-API-KEY"),
    x_bots_key: str | None = Header(None, alias="X-BOTS-CONTROL-KEY"),
) -> None:
    provided = (x_bots_key or x_api_key or "").strip()
    if not provided:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Missing X-API-KEY (use admin key with service=bots)",
        )
    if await _admin_key_allowed(provided):
        return
    # Optional env fallback (legacy / break-glass)
    if ENV_FALLBACK_KEY and secrets.compare_digest(provided, ENV_FALLBACK_KEY):
        return
    raise HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail=f"Invalid key — create service '{BOTS_ADMIN_SERVICE}' on Admin → API Keys",
    )


class StartBody(BaseModel):
    channel: str = Field(..., description="Twitch login / channel name")
    bot_type: str = Field("stable", description="stable | beta | v6")
    channel_id: str = Field(..., description="Twitch user id")
    token: str = Field(..., description="Twitch user access token")
    refresh: str = Field(..., description="Twitch user refresh token")
    apitoken: str = Field(..., description="Specter per-user API key (bot -apitoken)")
    custom: bool = False
    botusername: str | None = None
    self_mode: bool = Field(False, alias="self")
    version: str | None = Field(None, description="Optional version string to write after start")

    model_config = {"populate_by_name": True}


class StopBody(BaseModel):
    channel: str
    bot_type: str = "stable"


@app.get("/health")
async def health() -> dict[str, Any]:
    return {"ok": True, "service": "bots-control-api"}


@app.get("/api/running_bots", dependencies=[Depends(require_control_key)])
async def api_running_bots() -> dict[str, Any]:
    """Full inventory of local bot processes (admin / ops)."""
    return list_running_bots()


@app.get("/api/bot/status", dependencies=[Depends(require_control_key)])
async def api_bot_status(
    channel: str = Query(..., min_length=1),
    bot_type: str | None = Query(None, description="Optional: stable|beta|v6|custom"),
) -> dict[str, Any]:
    return status_for_channel(channel, bot_type)


@app.post("/api/bot/start", dependencies=[Depends(require_control_key)])
async def api_bot_start(body: StartBody) -> dict[str, Any]:
    result = await start_bot(
        channel=body.channel,
        bot_type=body.bot_type,
        channel_id=body.channel_id,
        token=body.token,
        refresh=body.refresh,
        apitoken=body.apitoken,
        custom=body.custom,
        botusername=body.botusername,
        self_mode=body.self_mode,
        version=body.version,
    )
    if not result.get("success") and result.get("state") == "error":
        raise HTTPException(status_code=400, detail=result.get("message") or "start failed")
    return result


@app.post("/api/bot/stop", dependencies=[Depends(require_control_key)])
async def api_bot_stop(body: StopBody) -> dict[str, Any]:
    result = await stop_bot(body.channel, body.bot_type)
    if not result.get("success") and result.get("state") == "error":
        raise HTTPException(status_code=400, detail=result.get("message") or "stop failed")
    return result


@app.post("/api/bot/restart", dependencies=[Depends(require_control_key)])
async def api_bot_restart(body: StartBody) -> dict[str, Any]:
    result = await restart_bot(
        channel=body.channel,
        bot_type=body.bot_type,
        channel_id=body.channel_id,
        token=body.token,
        refresh=body.refresh,
        apitoken=body.apitoken,
        custom=body.custom,
        botusername=body.botusername,
        self_mode=body.self_mode,
        version=body.version,
    )
    if not result.get("success") and result.get("state") == "error":
        raise HTTPException(status_code=400, detail=result.get("message") or "restart failed")
    return result


def main() -> None:
    import uvicorn

    uvicorn.run(
        "server:app",
        host=HOST,
        port=PORT,
        log_level="info",
        proxy_headers=True,
        forwarded_allow_ips="*",
    )


if __name__ == "__main__":
    main()
