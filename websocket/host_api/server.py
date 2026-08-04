#!/usr/bin/env python3
"""
WebSocket host control API (private) — bots-api style lifecycle without SSH.

Runs on the WebSocket server. Trusted backends (dashboard admin) call it with
an admin API key (Admin → API Keys) with service name:  websocket
(super-admin service "admin" is also accepted).

Surface (behind Caddy path /control → 127.0.0.1:8093):
  GET  /health
  GET  /api/services
  GET  /api/service/status?unit=websocket|caddy
  POST /api/service/start|stop|restart   JSON { "unit": "websocket" }

Auth: header X-API-KEY (or X-WS-CONTROL-KEY).
"""
from __future__ import annotations

import logging
import os
import secrets
from contextlib import asynccontextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import aiomysql
from dotenv import load_dotenv
from fastapi import Depends, FastAPI, Header, HTTPException, Query, status
from fastapi.responses import FileResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel, Field

from manager import ALLOWED_UNITS, control_unit, list_services, unit_status

load_dotenv("/home/botofthespecter/.env")
load_dotenv()

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s [ws-host-api] %(message)s",
)
log = logging.getLogger("ws_host_api")

_HOST_API_DIR = Path(__file__).resolve().parent
_DOCS_UI_DIR = _HOST_API_DIR / "docs_ui"

WS_ADMIN_SERVICE = (os.getenv("WS_ADMIN_SERVICE") or "websocket").strip().lower()
ENV_FALLBACK_KEY = (os.getenv("WS_CONTROL_KEY") or "").strip()

SQL_HOST = os.getenv("SQL_HOST")
SQL_USER = os.getenv("SQL_USER")
SQL_PASSWORD = os.getenv("SQL_PASSWORD")
SQL_PORT = int(os.getenv("SQL_PORT") or "3306")

HOST = os.getenv("WS_HOST_API_HOST", "127.0.0.1")
PORT = int(os.getenv("WS_HOST_API_PORT", "8093"))
# Public path prefix (Caddy handle_path /control/*) so OpenAPI servers + try-it hit /control/api/...
ROOT_PATH = (os.getenv("WS_HOST_API_ROOT_PATH") or "/control").rstrip("/") or ""

_pool: aiomysql.Pool | None = None
_process_started_at = datetime.now(timezone.utc)


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
            "ws host control API ready (auth: admin_api_keys service=%r or admin; env fallback=%s)",
            WS_ADMIN_SERVICE,
            "yes" if ENV_FALLBACK_KEY else "no",
        )
    yield
    if _pool is not None:
        _pool.close()
        await _pool.wait_closed()
        _pool = None


app = FastAPI(
    title="BotOfTheSpecter WebSocket Host Control API",
    description=(
        "Private process-control API for the WebSocket host. Not for end users. "
        f"Authenticate with an admin API key (service={WS_ADMIN_SERVICE!r} or admin)."
    ),
    version="1.0.0",
    lifespan=lifespan,
    docs_url=None,
    redoc_url=None,
    root_path=ROOT_PATH,
)


@app.get("/", include_in_schema=False)
async def root_to_docs():
    # Relative so public /control/ → /control/docs and local :8093/ → /docs
    return RedirectResponse(url="docs")


@app.get("/docs", include_in_schema=False)
async def themed_docs():
    index = _DOCS_UI_DIR / "index.html"
    if not index.is_file():
        raise HTTPException(status_code=500, detail="Docs UI not installed")
    return FileResponse(index)


if _DOCS_UI_DIR.is_dir():
    app.mount("/docs-static", StaticFiles(directory=str(_DOCS_UI_DIR)), name="docs_static")


async def _admin_key_allowed(api_key: str) -> bool:
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
        return key_service == WS_ADMIN_SERVICE
    except Exception as e:
        log.error("admin_api_keys lookup failed: %s", e)
        return False


async def require_control_key(
    x_api_key: str | None = Header(None, alias="X-API-KEY"),
    x_ws_key: str | None = Header(None, alias="X-WS-CONTROL-KEY"),
) -> None:
    provided = (x_ws_key or x_api_key or "").strip()
    if not provided:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail=f"Missing X-API-KEY (use admin key with service={WS_ADMIN_SERVICE})",
        )
    if await _admin_key_allowed(provided):
        return
    if ENV_FALLBACK_KEY and secrets.compare_digest(provided, ENV_FALLBACK_KEY):
        return
    raise HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail=f"Invalid key — create service '{WS_ADMIN_SERVICE}' on Admin → API Keys",
    )


class UnitBody(BaseModel):
    unit: str = Field(..., description="Allowlisted unit basename: websocket | caddy | websocket-control")


@app.get("/health")
async def health() -> dict[str, Any]:
    now = datetime.now(timezone.utc)
    return {
        "ok": True,
        "service": "websocket-host-control",
        "started_at": _process_started_at.strftime("%Y-%m-%d %H:%M:%S"),
        "started_at_utc": _process_started_at.isoformat(),
        "uptime_seconds": int((now - _process_started_at).total_seconds()),
        "allowlisted": list(ALLOWED_UNITS.keys()),
    }


@app.get("/api/services", dependencies=[Depends(require_control_key)])
async def api_list_services() -> dict[str, Any]:
    return list_services()


@app.get("/api/service/status", dependencies=[Depends(require_control_key)])
async def api_service_status(unit: str = Query(..., description="websocket | caddy | …")) -> dict[str, Any]:
    result = unit_status(unit)
    if not result.get("ok") and result.get("error", "").startswith("Unit not allowlisted"):
        raise HTTPException(status_code=400, detail=result["error"])
    return result


@app.post("/api/service/start", dependencies=[Depends(require_control_key)])
async def api_service_start(body: UnitBody) -> dict[str, Any]:
    result = control_unit(body.unit, "start")
    if not result.get("ok"):
        raise HTTPException(status_code=400, detail=result.get("error") or "start failed")
    return result


@app.post("/api/service/stop", dependencies=[Depends(require_control_key)])
async def api_service_stop(body: UnitBody) -> dict[str, Any]:
    result = control_unit(body.unit, "stop")
    if not result.get("ok"):
        raise HTTPException(status_code=400, detail=result.get("error") or "stop failed")
    return result


@app.post("/api/service/restart", dependencies=[Depends(require_control_key)])
async def api_service_restart(body: UnitBody) -> dict[str, Any]:
    result = control_unit(body.unit, "restart")
    if not result.get("ok"):
        raise HTTPException(status_code=400, detail=result.get("error") or "restart failed")
    return result


if __name__ == "__main__":
    import uvicorn

    log.info("Starting on %s:%s", HOST, PORT)
    uvicorn.run(app, host=HOST, port=PORT, proxy_headers=True, forwarded_allow_ips="127.0.0.1")
