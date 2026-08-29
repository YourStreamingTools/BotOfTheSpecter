"""
Stop Twitch chat bots that are no longer a moderator of their channel.

Helix Get Moderators needs a *user* token for the broadcaster (or a remaining
mod). The streamer's row in website.twitch_bot_access is that token; the
shared app token in bot_chat_token cannot list mods.

A 200 with Specter absent is the only stop signal — including custom-bot
channels, where the custom account is chat-only and Specter still does
moderation (ban, timeout, etc.). 401/403 is a dead token, not an unmod.
Kick and -self processes are skipped. The bot's own channel
(botofthespecter / 971436498) is skipped because the broadcaster is not
in the moderator list.
"""
from __future__ import annotations

import asyncio
import logging
import os
from datetime import datetime, timezone
from typing import Any, Callable, Awaitable

import aiohttp
import aiomysql

from manager import list_live_twitch_bots, stop_bot

log = logging.getLogger("bots_api.mod_check")

SPECTER_BOT_USER_ID = (os.getenv("SPECTER_BOT_USER_ID") or "971436498").strip()
SPECTER_OWN_CHANNEL = "botofthespecter"
HELIX_MODERATORS_URL = "https://api.twitch.tv/helix/moderation/moderators"

OUTCOME_IS_MOD = "is_mod"
OUTCOME_NOT_MOD = "not_mod"
OUTCOME_AUTH_ERROR = "auth_error"
OUTCOME_RATE_LIMITED = "rate_limited"
OUTCOME_UNKNOWN = "unknown"

StopBotFn = Callable[[str, str], Awaitable[dict[str, Any]]]
ListBotsFn = Callable[[], list[dict[str, Any]]]

_state: dict[str, Any] = {
    "enabled": False,
    "interval_seconds": 0,
    "last_started_at": None,
    "last_finished_at": None,
    "last_checked": 0,
    "last_stopped": 0,
    "last_skipped": 0,
    "last_error": None,
}


def _env_bool(name: str, default: bool = True) -> bool:
    raw = (os.getenv(name) or "").strip().lower()
    if not raw:
        return default
    return raw not in ("0", "false", "no", "off")


def _env_float(name: str, default: float) -> float:
    raw = (os.getenv(name) or "").strip()
    if not raw:
        return default
    try:
        return float(raw)
    except ValueError:
        return default


def _utc_now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def mod_check_enabled() -> bool:
    return _env_bool("BOTS_MOD_CHECK_ENABLED", True)


def mod_check_interval_seconds() -> float:
    return max(60.0, _env_float("BOTS_MOD_CHECK_INTERVAL_SECONDS", 300.0))


def mod_check_health() -> dict[str, Any]:
    snapshot = dict(_state)
    snapshot["enabled"] = mod_check_enabled()
    snapshot["interval_seconds"] = int(mod_check_interval_seconds())
    snapshot["specter_bot_user_id"] = SPECTER_BOT_USER_ID
    return snapshot


def interpret_moderator_response(
    http_status: int,
    payload: Any,
    bot_user_id: str,
) -> str:
    """Map a Helix Get Moderators result to a sweep action. Never treat errors as unmod."""
    bot_user_id = str(bot_user_id or "").strip()
    if http_status == 429:
        return OUTCOME_RATE_LIMITED
    if http_status in (401, 403):
        return OUTCOME_AUTH_ERROR
    if http_status != 200:
        return OUTCOME_UNKNOWN
    if not isinstance(payload, dict):
        return OUTCOME_UNKNOWN
    data = payload.get("data")
    if not isinstance(data, list):
        return OUTCOME_UNKNOWN
    if not bot_user_id:
        return OUTCOME_UNKNOWN
    for row in data:
        if not isinstance(row, dict):
            continue
        if str(row.get("user_id") or "").strip() == bot_user_id:
            return OUTCOME_IS_MOD
    return OUTCOME_NOT_MOD


def _skip_own_channel(channel: str, channel_id: str | None) -> bool:
    if (channel or "").strip().lower() == SPECTER_OWN_CHANNEL:
        return True
    if channel_id and str(channel_id).strip() == SPECTER_BOT_USER_ID:
        return True
    return False


async def _fetchone(
    pool: aiomysql.Pool,
    sql: str,
    args: tuple[Any, ...],
) -> dict[str, Any] | None:
    async with pool.acquire() as conn:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(sql, args)
            row = await cur.fetchone()
    return row if isinstance(row, dict) else None


async def _resolve_channel_id(
    pool: aiomysql.Pool,
    channel: str,
    channel_id: str | None,
) -> str | None:
    if channel_id:
        return str(channel_id).strip() or None
    row = await _fetchone(
        pool,
        "SELECT twitch_user_id FROM users WHERE username = %s LIMIT 1",
        (channel.lower(),),
    )
    if not row:
        return None
    value = str(row.get("twitch_user_id") or "").strip()
    return value or None


async def _load_helix_tokens(
    pool: aiomysql.Pool,
    twitch_user_id: str,
) -> list[str]:
    """Streamer Helix user tokens: twitch_bot_access first, users.access_token fallback."""
    row = await _fetchone(
        pool,
        """
        SELECT u.access_token AS login_access, tba.twitch_access_token AS bot_access
        FROM users u
        LEFT JOIN twitch_bot_access tba ON tba.twitch_user_id = u.twitch_user_id
        WHERE u.twitch_user_id = %s
        LIMIT 1
        """,
        (twitch_user_id,),
    )
    bot_access = ""
    login_access = ""
    if row:
        bot_access = str(row.get("bot_access") or "").strip()
        login_access = str(row.get("login_access") or "").strip()
    if not bot_access:
        tba = await _fetchone(
            pool,
            "SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = %s LIMIT 1",
            (twitch_user_id,),
        )
        if tba:
            bot_access = str(tba.get("twitch_access_token") or "").strip()
    tokens: list[str] = []
    if bot_access:
        tokens.append(bot_access)
    if login_access and login_access not in tokens:
        tokens.append(login_access)
    return tokens


def _expected_bot_user_id(row: dict[str, Any]) -> tuple[str | None, str | None]:
    """Helix user_id to look up: always Specter except -self (skip). Custom chat bots still need Specter as a mod."""
    if row.get("self"):
        return None, "self_mode"
    return SPECTER_BOT_USER_ID, None


async def _helix_get_moderators(
    session: aiohttp.ClientSession,
    *,
    token: str,
    client_id: str,
    broadcaster_id: str,
    bot_user_id: str,
) -> tuple[int, Any, float]:
    params = {"broadcaster_id": broadcaster_id, "user_id": bot_user_id}
    headers = {
        "Authorization": f"Bearer {token}",
        "Client-Id": client_id,
    }
    try:
        async with session.get(HELIX_MODERATORS_URL, params=params, headers=headers) as resp:
            retry_after = 0.0
            raw_retry = resp.headers.get("Retry-After")
            if raw_retry:
                try:
                    retry_after = float(raw_retry)
                except ValueError:
                    retry_after = 0.0
            try:
                payload = await resp.json(content_type=None)
            except Exception:
                payload = None
            return int(resp.status), payload, retry_after
    except asyncio.CancelledError:
        raise
    except Exception as e:
        log.warning("helix Get Moderators failed for broadcaster %s: %s", broadcaster_id, e)
        return 0, None, 0.0


async def _check_one(
    *,
    pool: aiomysql.Pool,
    session: aiohttp.ClientSession,
    client_id: str,
    row: dict[str, Any],
    stop_bot_fn: StopBotFn,
) -> str:
    """
    Returns 'stopped' | 'mod' | 'skipped' | 'rate_limited'.
    """
    channel = str(row.get("channel") or "").strip().lower()
    bot_type = str(row.get("bot_type") or "stable").strip().lower() or "stable"
    if not channel:
        return "skipped"

    channel_id = await _resolve_channel_id(pool, channel, row.get("channel_id"))
    if _skip_own_channel(channel, channel_id):
        return "skipped"
    if not channel_id:
        log.debug("mod check skip %s: no twitch user id", channel)
        return "skipped"

    expected_id, skip_reason = _expected_bot_user_id(row)
    if skip_reason == "self_mode":
        return "skipped"
    if not expected_id:
        return "skipped"

    tokens = await _load_helix_tokens(pool, channel_id)
    if not tokens:
        log.debug("mod check skip %s: no streamer Helix token", channel)
        return "skipped"

    outcome = OUTCOME_UNKNOWN
    retry_after = 0.0
    for index, token in enumerate(tokens):
        status, payload, retry_after = await _helix_get_moderators(
            session,
            token=token,
            client_id=client_id,
            broadcaster_id=channel_id,
            bot_user_id=expected_id,
        )
        outcome = interpret_moderator_response(status, payload, expected_id)
        if outcome == OUTCOME_AUTH_ERROR and index + 1 < len(tokens):
            continue
        break

    if outcome == OUTCOME_IS_MOD:
        return "mod"
    if outcome == OUTCOME_RATE_LIMITED:
        wait = max(15.0, retry_after or 15.0)
        log.warning("mod check rate-limited on %s; backing off %.0fs", channel, wait)
        await asyncio.sleep(wait)
        return "rate_limited"
    if outcome != OUTCOME_NOT_MOD:
        log.warning(
            "mod check skip %s (%s): helix outcome %s (not treating as unmod)",
            channel,
            bot_type,
            outcome,
        )
        return "skipped"

    log.warning(
        "mod check: %s (%s) is not a moderator (bot user %s); stopping",
        channel,
        bot_type,
        expected_id,
    )
    result = await stop_bot_fn(channel, bot_type)
    if not result.get("success"):
        log.error(
            "mod check failed to stop %s (%s): %s",
            channel,
            bot_type,
            result.get("message") or result.get("state"),
        )
        return "skipped"
    return "stopped"


async def run_mod_check_once(
    pool: aiomysql.Pool,
    *,
    client_id: str,
    list_bots_fn: ListBotsFn = list_live_twitch_bots,
    stop_bot_fn: StopBotFn = stop_bot,
    session: aiohttp.ClientSession | None = None,
) -> dict[str, int]:
    rows = await asyncio.to_thread(list_bots_fn)
    seen: set[tuple[str, str]] = set()
    counts = {"checked": 0, "stopped": 0, "skipped": 0, "mod": 0}
    stagger = max(0.0, _env_float("BOTS_MOD_CHECK_STAGGER_SECONDS", 0.25))
    timeout = max(3.0, _env_float("BOTS_MOD_CHECK_TIMEOUT_SECONDS", 10.0))

    own_session = session is None
    if session is None:
        session = aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=timeout))
    try:
        for row in rows:
            if not isinstance(row, dict):
                continue
            channel = str(row.get("channel") or "").strip().lower()
            bot_type = str(row.get("bot_type") or "stable").strip().lower() or "stable"
            key = (channel, bot_type)
            if not channel or key in seen:
                continue
            seen.add(key)
            counts["checked"] += 1
            action = await _check_one(
                pool=pool,
                session=session,
                client_id=client_id,
                row=row,
                stop_bot_fn=stop_bot_fn,
            )
            if action == "stopped":
                counts["stopped"] += 1
            elif action == "mod":
                counts["mod"] += 1
            else:
                counts["skipped"] += 1
            if stagger:
                await asyncio.sleep(stagger)
    finally:
        if own_session:
            await session.close()
    return counts


async def run_mod_check_loop(get_pool: Callable[[], aiomysql.Pool | None]) -> None:
    start_delay = max(0.0, _env_float("BOTS_MOD_CHECK_START_DELAY_SECONDS", 60.0))
    log.info(
        "mod check loop armed (delay=%.0fs interval=%.0fs enabled=%s)",
        start_delay,
        mod_check_interval_seconds(),
        mod_check_enabled(),
    )
    if start_delay:
        await asyncio.sleep(start_delay)
    while True:
        interval = mod_check_interval_seconds()
        _state["interval_seconds"] = int(interval)
        if not mod_check_enabled():
            _state["enabled"] = False
            _state["last_error"] = None
            await asyncio.sleep(interval)
            continue
        client_id = (os.getenv("CLIENT_ID") or "").strip()
        pool = get_pool()
        if not client_id:
            _state["enabled"] = True
            _state["last_error"] = "CLIENT_ID missing"
            log.error("mod check skipped this round: CLIENT_ID is not set")
            await asyncio.sleep(interval)
            continue
        if pool is None:
            _state["enabled"] = True
            _state["last_error"] = "database pool unavailable"
            log.error("mod check skipped this round: website DB pool is not ready")
            await asyncio.sleep(interval)
            continue
        _state["enabled"] = True
        _state["last_started_at"] = _utc_now_iso()
        _state["last_error"] = None
        try:
            counts = await run_mod_check_once(pool, client_id=client_id)
            _state["last_checked"] = counts["checked"]
            _state["last_stopped"] = counts["stopped"]
            _state["last_skipped"] = counts["skipped"]
            _state["last_finished_at"] = _utc_now_iso()
            if counts["stopped"]:
                log.warning(
                    "mod check sweep: checked=%s still_mod=%s stopped=%s skipped=%s",
                    counts["checked"],
                    counts["mod"],
                    counts["stopped"],
                    counts["skipped"],
                )
            else:
                log.info(
                    "mod check sweep: checked=%s still_mod=%s skipped=%s",
                    counts["checked"],
                    counts["mod"],
                    counts["skipped"],
                )
        except asyncio.CancelledError:
            raise
        except Exception as e:
            _state["last_error"] = str(e)[:200]
            log.exception("mod check sweep failed: %s", e)
        await asyncio.sleep(interval)
