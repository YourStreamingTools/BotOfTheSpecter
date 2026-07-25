#!/usr/bin/env python3
"""
Private bot-host control API.

Runs on the bot server only. Trusted backends (public API, dashboard) call it
with BOTS_CONTROL_KEY — never expose user API keys here.

Public surface (behind TLS at bots.botofthespecter.com):
  GET  /health
  GET  /api/running_bots
  GET  /api/bot/status?channel=&bot_type=
  POST /api/bot/start
  POST /api/bot/stop
  POST /api/bot/restart

Auth: header X-API-KEY or X-BOTS-CONTROL-KEY must equal BOTS_CONTROL_KEY from env.
"""
from __future__ import annotations

import logging
import os
import secrets
from contextlib import asynccontextmanager
from typing import Any

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

CONTROL_KEY = (os.getenv("BOTS_CONTROL_KEY") or os.getenv("ADMIN_KEY") or "").strip()
HOST = os.getenv("BOTS_API_HOST", "0.0.0.0")
PORT = int(os.getenv("BOTS_API_PORT", "8090"))


@asynccontextmanager
async def lifespan(_app: FastAPI):
    if not CONTROL_KEY:
        log.warning("BOTS_CONTROL_KEY (or ADMIN_KEY) is empty — all requests will be rejected")
    else:
        log.info("bots control API ready (key configured, len=%s)", len(CONTROL_KEY))
    yield


app = FastAPI(
    title="BotOfTheSpecter Bot Host Control API",
    description="Private process-control API for the bot server. Not for end users.",
    version="1.0.0",
    lifespan=lifespan,
    docs_url="/docs",
    redoc_url=None,
)


def require_control_key(
    x_api_key: str | None = Header(None, alias="X-API-KEY"),
    x_bots_key: str | None = Header(None, alias="X-BOTS-CONTROL-KEY"),
) -> None:
    if not CONTROL_KEY:
        raise HTTPException(status_code=503, detail="BOTS_CONTROL_KEY not configured on bot host")
    provided = (x_bots_key or x_api_key or "").strip()
    if not provided or not secrets.compare_digest(provided, CONTROL_KEY):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid control key")


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
