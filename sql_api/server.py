#!/usr/bin/env python3
"""
Tenant-scoped SQL data API for BotOfTheSpecter.

Runs on the SQL host. SpecterBotApp (and similar multi-tenant PHP) call it with
a *user* API key so they never hold MySQL credentials and cannot open another
streamer's database.

Public surface (behind TLS at sql.botofthespecter.com):
  GET  /health
  GET  /health/metrics  (live host CPU/RAM/disk/net — no auth)
  GET  /api/v1/me
  GET  /api/v1/{scope}/tables
  GET  /api/v1/{scope}/rows
  POST /api/v1/{scope}/rows
  PATCH /api/v1/{scope}/rows
  DELETE /api/v1/{scope}/rows
  POST /api/v1/modules/ensure

Auth: header X-API-KEY = website.users.api_key (tenant = that user).
Admin keys with service "sql" or "admin" may pass channel= (or X-Channel) to
act as a streamer for support tooling.

Scopes:
  user    → MySQL database named after the tenant username
  modules → MySQL database {username}_custom_modules

No raw SQL from clients — structured filters + allowlisted identifier shapes only.
"""
from __future__ import annotations

import logging
import os
from contextlib import asynccontextmanager
from typing import Any, Literal

import aiomysql
from dotenv import load_dotenv
from fastapi import Depends, FastAPI, Header, HTTPException, Query, status
from pydantic import BaseModel, Field

import db as dbmod
from identifiers import validate_ident
from query_builder import (
    FilterError,
    build_delete,
    build_insert,
    build_select,
    build_update,
)

load_dotenv()

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s [sql-api] %(message)s",
)
log = logging.getLogger("sql_api")

HOST = os.getenv("SQL_API_HOST", "127.0.0.1")
PORT = int(os.getenv("SQL_API_PORT", "8091"))

Scope = Literal["user", "modules"]


@asynccontextmanager
async def lifespan(_app: FastAPI):
    try:
        await dbmod.init_pools()
    except Exception as e:
        log.error("Failed to init DB pools: %s", e)
        raise
    yield
    await dbmod.close_pools()


app = FastAPI(
    title="BotOfTheSpecter SQL Data API",
    description=(
        "Tenant-scoped data plane for SpecterBotApp and similar clients. "
        "Authenticate with a user API key (X-API-KEY). "
        "The key determines which MySQL database may be accessed — never trust Host."
    ),
    version="1.0.0",
    lifespan=lifespan,
    docs_url="/docs",
    redoc_url=None,
)


class AuthContext(BaseModel):
    type: Literal["user", "admin"]
    username: str  # resolved tenant
    service: str | None = None


async def require_tenant(
    x_api_key: str | None = Header(None, alias="X-API-KEY"),
    x_channel: str | None = Header(None, alias="X-Channel"),
    channel: str | None = Query(
        None,
        description="Admin only: act as this Twitch login / tenant DB name",
    ),
) -> AuthContext:
    provided = (x_api_key or "").strip()
    if not provided:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Missing X-API-KEY (user API key from website.users)",
        )
    principal = await dbmod.resolve_api_key(provided)
    if not principal:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid API key",
        )
    if principal["type"] == "user":
        # User keys cannot impersonate another tenant.
        requested = (channel or x_channel or "").strip().lower()
        if requested and requested != principal["username"]:
            raise HTTPException(
                status_code=status.HTTP_403_FORBIDDEN,
                detail="channel is only allowed for admin API keys",
            )
        return AuthContext(type="user", username=principal["username"])

    # Admin: must specify which tenant
    tenant = (channel or x_channel or "").strip().lower()
    if not tenant:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Admin keys require channel= or X-Channel for tenant selection",
        )
    try:
        validate_ident(tenant, kind="username")
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e)) from e
    return AuthContext(
        type="admin",
        username=tenant,
        service=principal.get("service"),
    )


def _db_for_scope(auth: AuthContext, scope: Scope) -> str:
    if scope == "user":
        return dbmod.tenant_db_name(auth.username)
    if scope == "modules":
        return dbmod.modules_db_name(auth.username)
    raise HTTPException(status_code=400, detail="Invalid scope")


async def _run_on_tenant(
    database: str,
    sql: str,
    params: list[Any],
    *,
    fetch: bool,
) -> dict[str, Any]:
    try:
        conn = await dbmod.acquire_user_connection(database)
    except Exception as e:
        log.warning("connect failed db=%s: %s", database, e)
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail=f"Database not available for tenant: {database}",
        ) from e
    try:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(sql, params)
            if fetch:
                rows = await cur.fetchall()
                # Convert non-JSON-friendly values
                cleaned = []
                for row in rows or []:
                    cleaned.append({k: _jsonable(v) for k, v in row.items()})
                return {"ok": True, "rows": cleaned, "row_count": len(cleaned)}
            affected = cur.rowcount if cur.rowcount is not None else 0
            last_id = cur.lastrowid
            return {
                "ok": True,
                "affected": affected,
                "last_insert_id": last_id,
            }
    except HTTPException:
        raise
    except Exception as e:
        log.error("query failed db=%s: %s | sql=%s", database, e, sql[:200])
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"Query failed: {e}",
        ) from e
    finally:
        conn.close()


def _jsonable(v: Any) -> Any:
    if v is None or isinstance(v, (str, int, float, bool)):
        return v
    if isinstance(v, (bytes, bytearray)):
        try:
            return v.decode("utf-8")
        except Exception:
            return v.hex()
    # datetime, Decimal, etc.
    return str(v)


# ---------- models ----------


class FilterItem(BaseModel):
    column: str
    op: str = "eq"
    value: Any = None


class SelectBody(BaseModel):
    """Optional JSON body for complex selects (prefer query params for simple GETs)."""
    table: str
    columns: list[str] | None = None
    filters: list[FilterItem] | None = None
    order_by: str | None = None
    order_dir: str = "asc"
    limit: int = Field(100, ge=1, le=500)
    offset: int = Field(0, ge=0, le=100_000)


class InsertBody(BaseModel):
    table: str
    data: dict[str, Any]


class UpdateBody(BaseModel):
    table: str
    data: dict[str, Any]
    filters: list[FilterItem] = Field(..., min_length=1)


class DeleteBody(BaseModel):
    table: str
    filters: list[FilterItem] = Field(..., min_length=1)


# ---------- routes ----------


@app.get("/health")
async def health() -> dict[str, Any]:
    return {"ok": True, "service": "sql-data-api"}


@app.get("/health/metrics")
async def health_metrics() -> dict[str, Any]:
    # Live host metrics for the public status page (no auth; same fields as status UI).
    from host_metrics import collect_host_metrics

    return collect_host_metrics(
        os.getenv("METRICS_SERVER_NAME", "sql"),
        service="sql-data-api",
    )


@app.get("/api/v1/me")
async def api_me(auth: AuthContext = Depends(require_tenant)) -> dict[str, Any]:
    user_db = dbmod.tenant_db_name(auth.username)
    modules_db = dbmod.modules_db_name(auth.username)
    modules_exists = await dbmod.schema_exists(modules_db)
    user_exists = await dbmod.schema_exists(user_db)
    return {
        "ok": True,
        "type": auth.type,
        "username": auth.username,
        "databases": {
            "user": {"name": user_db, "exists": user_exists},
            "modules": {"name": modules_db, "exists": modules_exists},
        },
    }


@app.get("/api/v1/{scope}/tables")
async def api_list_tables(
    scope: Scope,
    auth: AuthContext = Depends(require_tenant),
) -> dict[str, Any]:
    database = _db_for_scope(auth, scope)
    if not await dbmod.schema_exists(database):
        raise HTTPException(status_code=404, detail=f"Schema not found: {database}")
    sql = (
        "SELECT TABLE_NAME AS name FROM information_schema.TABLES "
        "WHERE TABLE_SCHEMA = %s ORDER BY TABLE_NAME"
    )
    return await _run_on_tenant(database, sql, [database], fetch=True)


@app.get("/api/v1/{scope}/rows")
async def api_select_rows(
    scope: Scope,
    table: str = Query(..., min_length=1, max_length=64),
    limit: int = Query(100, ge=1, le=500),
    offset: int = Query(0, ge=0, le=100_000),
    order_by: str | None = Query(None),
    order_dir: str = Query("asc"),
    # Simple single-column filter: filter_column + filter_op + filter_value
    filter_column: str | None = Query(None),
    filter_op: str = Query("eq"),
    filter_value: str | None = Query(None),
    auth: AuthContext = Depends(require_tenant),
) -> dict[str, Any]:
    database = _db_for_scope(auth, scope)
    filters = None
    if filter_column:
        filters = [
            {
                "column": filter_column,
                "op": filter_op,
                "value": filter_value,
            }
        ]
    try:
        sql, params = build_select(
            table,
            filters=filters,
            order_by=order_by,
            order_dir=order_dir,
            limit=limit,
            offset=offset,
        )
    except (FilterError, ValueError) as e:
        raise HTTPException(status_code=400, detail=str(e)) from e
    return await _run_on_tenant(database, sql, params, fetch=True)


@app.post("/api/v1/{scope}/query")
async def api_select_body(
    scope: Scope,
    body: SelectBody,
    auth: AuthContext = Depends(require_tenant),
) -> dict[str, Any]:
    """POST select with multi-filter body (when GET query params are not enough)."""
    database = _db_for_scope(auth, scope)
    filters = [f.model_dump() for f in body.filters] if body.filters else None
    try:
        sql, params = build_select(
            body.table,
            columns=body.columns,
            filters=filters,
            order_by=body.order_by,
            order_dir=body.order_dir,
            limit=body.limit,
            offset=body.offset,
        )
    except (FilterError, ValueError) as e:
        raise HTTPException(status_code=400, detail=str(e)) from e
    return await _run_on_tenant(database, sql, params, fetch=True)


@app.post("/api/v1/{scope}/rows")
async def api_insert_rows(
    scope: Scope,
    body: InsertBody,
    auth: AuthContext = Depends(require_tenant),
) -> dict[str, Any]:
    database = _db_for_scope(auth, scope)
    try:
        sql, params = build_insert(body.table, body.data)
    except (FilterError, ValueError) as e:
        raise HTTPException(status_code=400, detail=str(e)) from e
    return await _run_on_tenant(database, sql, params, fetch=False)


@app.patch("/api/v1/{scope}/rows")
async def api_update_rows(
    scope: Scope,
    body: UpdateBody,
    auth: AuthContext = Depends(require_tenant),
) -> dict[str, Any]:
    database = _db_for_scope(auth, scope)
    filters = [f.model_dump() for f in body.filters]
    try:
        sql, params = build_update(body.table, body.data, filters)
    except (FilterError, ValueError) as e:
        raise HTTPException(status_code=400, detail=str(e)) from e
    return await _run_on_tenant(database, sql, params, fetch=False)


@app.delete("/api/v1/{scope}/rows")
async def api_delete_rows(
    scope: Scope,
    body: DeleteBody,
    auth: AuthContext = Depends(require_tenant),
) -> dict[str, Any]:
    database = _db_for_scope(auth, scope)
    filters = [f.model_dump() for f in body.filters]
    try:
        sql, params = build_delete(body.table, filters)
    except (FilterError, ValueError) as e:
        raise HTTPException(status_code=400, detail=str(e)) from e
    return await _run_on_tenant(database, sql, params, fetch=False)


@app.post("/api/v1/modules/ensure")
async def api_ensure_modules_db(
    auth: AuthContext = Depends(require_tenant),
) -> dict[str, Any]:
    """Create {username}_custom_modules if it does not exist."""
    schema = dbmod.modules_db_name(auth.username)
    try:
        validate_ident(auth.username, kind="username")
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e)) from e
    # modules name is username + fixed suffix; validate full schema ident shape
    if not schema.replace("_", "").isalnum():
        raise HTTPException(status_code=400, detail="Invalid modules schema name")
    await dbmod.ensure_schema(schema)
    return {"ok": True, "database": schema, "exists": True}


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
