# ./bot/modules/pet.py
# Virtual pet: trigger cache, lazy stat decay, stat/xp effects, and the chat/event/redemption/interaction triggers.
# Shared by beta.py and beta-v6.py. The bots inject their own helpers once at startup through configure().
import asyncio
import random
import time
from datetime import datetime, timezone
from types import SimpleNamespace
from aiomysql import DictCursor

_bot = SimpleNamespace(
    logger=None,
    open_connection=None,
    close_connection=None,
    send_chat_message=None,
    websocket_notice=None,
    safe_create_task=None,
    check_cooldown=None,
    add_usage=None,
    get_api_token=None,
    get_channel_name=None,
    is_stream_online=None,
)

# Function to hand the module the bot's own helpers (called once by each bot before it starts)
def configure(**deps):
    unknown = set(deps) - set(vars(_bot))
    if unknown:
        raise TypeError(f"pet.configure() got unexpected dependencies: {sorted(unknown)}")
    vars(_bot).update(deps)

# Pet overlay trigger cache + apply path. In-memory dict keyed by API_TOKEN; refreshed on PET_SETTINGS_UPDATE.
_pet_cache = {}
_pet_state_lock = asyncio.Lock()
# Low-stat chat alerts. Messages use (pet) for the pet name; one message per line, one is picked at random.
PET_ALERT_STATS = ("energy", "hunger", "happiness")
PET_ALERT_DEFAULT_THRESHOLD = 10
PET_ALERT_DEFAULT_COOLDOWN_MINUTES = 30
PET_ALERT_CHECK_SECONDS = 60
PET_ALERT_DEFAULT_MESSAGES = {
    "energy": [
        "(pet) is running low on energy.",
        "(pet) is exhausted, let them rest. Don't forget me!",
    ],
    "hunger": [
        "(pet) is hungry, consider feeding the pet.",
        "(pet) is starving. Don't forget me!",
    ],
    "happiness": [
        "(pet) is not very happy right now.",
        "(pet) is feeling down. Don't forget me!",
    ],
}
# stat -> time of the last alert while that stat stays low (cleared once it recovers)
_pet_alert_state = {}
_PET_TOKEN_STRIP = ".,!?;:\"'`~()[]{}<>*_-\x01"
_PET_INTERACTION_FALLBACKS = {
    "feed": {"id": None, "animation": "eat", "bubble_text": "", "effect_happiness": 0, "effect_hunger": 15, "effect_energy": 0, "xp": 0, "cooldown_seconds": 0},
    "play": {"id": None, "animation": "happy", "bubble_text": "", "effect_happiness": 10, "effect_hunger": 0, "effect_energy": -5, "xp": 0, "cooldown_seconds": 0},
    "sad": {"id": None, "animation": "sad", "bubble_text": "", "effect_happiness": 0, "effect_hunger": 0, "effect_energy": 0, "xp": 0, "cooldown_seconds": 0},
    "sleep": {"id": None, "animation": "sleep", "bubble_text": "", "effect_happiness": 0, "effect_hunger": 0, "effect_energy": 15, "xp": 0, "cooldown_seconds": 0},
}

# Function to drop the in-memory pet trigger cache so the next lookup reloads from MySQL
def pet_invalidate_cache():
    _pet_cache.pop(_bot.get_api_token(), None)

# Function to clamp a pet stat to the 0-100 range
def pet_clamp_stat(value):
    try:
        n = int(round(float(value)))
    except (TypeError, ValueError):
        n = 0
    if n < 0:
        return 0
    if n > 100:
        return 100
    return n

PET_XP_PER_LEVEL = 100
PET_XP_MAX_LEVEL = 99

# Function to derive level and remaining XP from total XP (100 XP per level, cap 99)
def pet_xp_progress(xp):
    try:
        xp = max(0, int(xp or 0))
    except (TypeError, ValueError):
        xp = 0
    level = min(PET_XP_MAX_LEVEL, 1 + xp // PET_XP_PER_LEVEL)
    if level >= PET_XP_MAX_LEVEL:
        return {"xp": xp, "level": level, "into": PET_XP_PER_LEVEL, "to_next": 0}
    into = xp % PET_XP_PER_LEVEL
    return {"xp": xp, "level": level, "into": into, "to_next": PET_XP_PER_LEVEL - into}

# Function to post a chat line when a pet trigger awards XP
async def pet_announce_xp_gain(cache, xp_gained, old_level, progress):
    if xp_gained <= 0 or not progress:
        return
    pet_name = (cache or {}).get("pet_name") or "Pet"
    new_level = progress["level"]
    to_next = progress["to_next"]
    try:
        if new_level > old_level:
            await _bot.send_chat_message(f"{pet_name} gained {xp_gained} XP and reached level {new_level}!")
        elif to_next <= 0:
            await _bot.send_chat_message(f"{pet_name} gained {xp_gained} XP and is at max level!")
        else:
            await _bot.send_chat_message(f"{pet_name} gained {xp_gained} XP! {to_next} XP until level {new_level + 1}.")
    except Exception as e:
        _bot.logger.error(f"[PET] Failed to announce XP gain: {e}")

# Function to parse pet_state.last_interaction_at into an aware UTC datetime
def pet_parse_interaction_time(value):
    if value is None or value == "":
        return None
    if isinstance(value, datetime):
        if value.tzinfo is None:
            return value.replace(tzinfo=timezone.utc)
        return value
    text = str(value).strip()
    if not text:
        return None
    try:
        if text.endswith("Z"):
            text = text[:-1] + "+00:00"
        dt = datetime.fromisoformat(text.replace(" ", "T"))
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=timezone.utc)
        return dt
    except Exception:
        return None

# Function to split a chat line into lowercase whole tokens (punctuation stripped at the ends)
def pet_message_tokens(text):
    tokens = set()
    for raw in str(text or "").lower().split():
        token = raw.strip(_PET_TOKEN_STRIP)
        if token:
            tokens.add(token)
    return tokens

# Function to build a trigger dict from a pet_triggers row
def pet_trigger_from_row(row):
    return {
        "id": row.get("id"),
        "animation": row.get("animation") or "",
        "bubble_text": row.get("bubble_text") or "",
        "effect_happiness": int(row.get("effect_happiness") or 0),
        "effect_hunger": int(row.get("effect_hunger") or 0),
        "effect_energy": int(row.get("effect_energy") or 0),
        "xp": int(row.get("xp") or 0),
        "cooldown_seconds": int(row.get("cooldown_seconds") or 0),
    }

# Function to load pet_settings + pet_triggers into the per-channel cache
async def pet_load_cache():
    empty = {
        "configured": False,
        "enabled": False,
        "pet_name": "Pet",
        "decay_happiness": 2.0,
        "decay_hunger": 3.0,
        "decay_energy": 1.0,
        "start_happiness": 80,
        "start_hunger": 80,
        "start_energy": 80,
        "alert_enabled": False,
        "alert_threshold": PET_ALERT_DEFAULT_THRESHOLD,
        "alert_cooldown_minutes": PET_ALERT_DEFAULT_COOLDOWN_MINUTES,
        "alert_messages": {},
        "chat_keyword": {},
        "command": {},
        "redemption": {},
        "event": {},
        "interaction": {},
    }
    connection = None
    try:
        connection = await _bot.open_connection()
        async with connection.cursor(DictCursor) as cursor:
            try:
                await cursor.execute(
                    "SELECT enabled, pet_name, decay_happiness, decay_hunger, decay_energy, start_happiness, start_hunger, start_energy FROM pet_settings WHERE id = 1"
                )
            except Exception:
                await cursor.execute("SELECT enabled, pet_name, decay_happiness, decay_hunger, decay_energy FROM pet_settings WHERE id = 1")
            settings = await cursor.fetchone()
            if not settings:
                return empty
            cache = dict(empty)
            cache["configured"] = True
            cache["enabled"] = bool(int(settings.get("enabled") or 0))
            cache["pet_name"] = settings.get("pet_name") or "Pet"
            cache["decay_happiness"] = float(settings.get("decay_happiness") or 2)
            cache["decay_hunger"] = float(settings.get("decay_hunger") or 3)
            cache["decay_energy"] = float(settings.get("decay_energy") or 1)
            cache["start_happiness"] = pet_clamp_stat(settings.get("start_happiness") if settings.get("start_happiness") is not None else 80)
            cache["start_hunger"] = pet_clamp_stat(settings.get("start_hunger") if settings.get("start_hunger") is not None else 80)
            cache["start_energy"] = pet_clamp_stat(settings.get("start_energy") if settings.get("start_energy") is not None else 80)
            try:
                await cursor.execute(
                    "SELECT alert_enabled, alert_threshold, alert_cooldown_minutes, alert_msg_energy, alert_msg_hunger, alert_msg_happiness FROM pet_settings WHERE id = 1"
                )
                alert_row = await cursor.fetchone() or {}
            except Exception:
                alert_row = {}
            cache["alert_enabled"] = bool(int(alert_row.get("alert_enabled") or 0))
            cache["alert_threshold"] = pet_clamp_stat(alert_row.get("alert_threshold") if alert_row.get("alert_threshold") is not None else PET_ALERT_DEFAULT_THRESHOLD)
            cache["alert_cooldown_minutes"] = max(0, int(alert_row.get("alert_cooldown_minutes") if alert_row.get("alert_cooldown_minutes") is not None else PET_ALERT_DEFAULT_COOLDOWN_MINUTES))
            cache["alert_messages"] = {stat: pet_alert_message_lines(alert_row.get(f"alert_msg_{stat}")) for stat in PET_ALERT_STATS}
            try:
                await cursor.execute(
                    "SELECT id, trigger_type, trigger_value, animation, bubble_text, effect_happiness, effect_hunger, effect_energy, xp, cooldown_seconds FROM pet_triggers WHERE enabled = 1"
                )
                rows = await cursor.fetchall() or []
            except Exception as trigger_err:
                _bot.logger.error(f"[PET] Failed to load pet triggers: {trigger_err}")
                rows = []
            for row in rows:
                ttype = str(row.get("trigger_type") or "").strip().lower()
                value = str(row.get("trigger_value") or "").strip()
                if ttype == "command":
                    value = value.lstrip("!").lower()
                elif ttype in ("chat_keyword", "event", "interaction", "redemption"):
                    value = value.lower()
                if not value or ttype not in ("chat_keyword", "command", "redemption", "event", "interaction"):
                    continue
                cache[ttype][value] = pet_trigger_from_row(row)
            return cache
    except Exception as e:
        _bot.logger.error(f"[PET] Failed to load pet cache: {e}")
        return empty
    finally:
        if connection:
            await _bot.close_connection(connection)

# Function to return the cached pet config, loading it lazily on first use
async def pet_get_cache():
    cached = _pet_cache.get(_bot.get_api_token())
    if cached is not None:
        return cached
    loaded = await pet_load_cache()
    _pet_cache[_bot.get_api_token()] = loaded
    return loaded

# Function to apply lazy decay to stored pet stats (NULL last_interaction_at means no decay). Offline streams never decay.
def pet_decay_stats(happiness, hunger, energy, last_interaction_at, cache, allow_decay=None):
    if allow_decay is None:
        allow_decay = bool(_bot.is_stream_online())
    last_dt = pet_parse_interaction_time(last_interaction_at)
    hours = 0.0
    if allow_decay and last_dt is not None:
        hours = max(0.0, (datetime.now(timezone.utc) - last_dt).total_seconds() / 3600.0)
    return {
        "happiness": pet_clamp_stat(float(happiness) - float(cache.get("decay_happiness") or 0) * hours),
        "hunger": pet_clamp_stat(float(hunger) - float(cache.get("decay_hunger") or 0) * hours),
        "energy": pet_clamp_stat(float(energy) - float(cache.get("decay_energy") or 0) * hours),
    }

def pet_state_notice_data(happiness, hunger, energy, level, xp, last_iso, cache, stream_online_override=None):
    decay_happiness = float(cache.get("decay_happiness") or 2)
    decay_hunger = float(cache.get("decay_hunger") or 3)
    decay_energy = float(cache.get("decay_energy") or 1)
    online = _bot.is_stream_online() if stream_online_override is None else stream_online_override
    return {
        "happiness": happiness,
        "hunger": hunger,
        "energy": energy,
        "level": level,
        "xp": xp,
        "last_interaction_at": last_iso,
        "decay_happiness": decay_happiness,
        "decay_hunger": decay_hunger,
        "decay_energy": decay_energy,
        "decay_rates": {"happiness": decay_happiness, "hunger": decay_hunger, "energy": decay_energy},
        "stream_online": 1 if online else 0,
    }

# Function to read current (decayed) pet stats without writing
async def pet_current_stats():
    cache = await pet_get_cache()
    result = {
        "configured": bool(cache.get("configured")),
        "enabled": bool(cache.get("enabled")),
        "pet_name": cache.get("pet_name") or "Pet",
        "happiness": 80,
        "hunger": 80,
        "energy": 80,
        "level": 1,
        "xp": 0,
        "last_interaction_at": None,
        "decay_happiness": float(cache.get("decay_happiness") or 2),
        "decay_hunger": float(cache.get("decay_hunger") or 3),
        "decay_energy": float(cache.get("decay_energy") or 1),
    }
    if not cache.get("configured"):
        return result
    connection = None
    try:
        connection = await _bot.open_connection()
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute("SELECT happiness, hunger, energy, level, xp, last_interaction_at FROM pet_state WHERE id = 1")
            row = await cursor.fetchone()
        if row:
            decayed = pet_decay_stats(
                row.get("happiness") if row.get("happiness") is not None else 80,
                row.get("hunger") if row.get("hunger") is not None else 80,
                row.get("energy") if row.get("energy") is not None else 80,
                row.get("last_interaction_at"),
                cache,
            )
            result["happiness"] = decayed["happiness"]
            result["hunger"] = decayed["hunger"]
            result["energy"] = decayed["energy"]
            result["xp"] = max(0, int(row.get("xp") or 0))
            result["level"] = min(99, 1 + result["xp"] // 100)
            result["last_interaction_at"] = row.get("last_interaction_at")
        return result
    except Exception as e:
        _bot.logger.error(f"[PET] Failed to read pet state: {e}")
        return None
    finally:
        if connection:
            await _bot.close_connection(connection)

# Function to apply trigger stat/xp effects, persist pet_state, and emit PET_STATE
async def pet_apply_state_effects(trigger, cache):
    now = datetime.now(timezone.utc)
    now_naive = now.replace(tzinfo=None)
    now_iso = now.strftime("%Y-%m-%dT%H:%M:%SZ")
    xp_gained = 0
    old_level = 1
    progress = None
    applied = False
    async with _pet_state_lock:
        connection = None
        try:
            connection = await _bot.open_connection()
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT happiness, hunger, energy, level, xp, last_interaction_at FROM pet_state WHERE id = 1")
                row = await cursor.fetchone()
                if row:
                    happiness = row.get("happiness") if row.get("happiness") is not None else 80
                    hunger = row.get("hunger") if row.get("hunger") is not None else 80
                    energy = row.get("energy") if row.get("energy") is not None else 80
                    xp = max(0, int(row.get("xp") or 0))
                    last_at = row.get("last_interaction_at")
                else:
                    happiness, hunger, energy, xp, last_at = 80, 80, 80, 0, None
                decayed = pet_decay_stats(happiness, hunger, energy, last_at, cache)
                happiness = pet_clamp_stat(decayed["happiness"] + int((trigger or {}).get("effect_happiness") or 0))
                hunger = pet_clamp_stat(decayed["hunger"] + int((trigger or {}).get("effect_hunger") or 0))
                energy = pet_clamp_stat(decayed["energy"] + int((trigger or {}).get("effect_energy") or 0))
                xp_gained = max(0, int((trigger or {}).get("xp") or 0))
                old_level = min(PET_XP_MAX_LEVEL, 1 + xp // PET_XP_PER_LEVEL)
                xp = max(0, xp + xp_gained)
                progress = pet_xp_progress(xp)
                level = progress["level"]
                await cursor.execute(
                    "INSERT INTO pet_state (id, happiness, hunger, energy, level, xp, last_interaction_at) VALUES (1, %s, %s, %s, %s, %s, %s) "
                    "ON DUPLICATE KEY UPDATE happiness=%s, hunger=%s, energy=%s, level=%s, xp=%s, last_interaction_at=%s",
                    (happiness, hunger, energy, level, xp, now_naive, happiness, hunger, energy, level, xp, now_naive)
                )
                await connection.commit()
            _bot.safe_create_task(_bot.websocket_notice(event="PET_STATE", additional_data=pet_state_notice_data(
                happiness, hunger, energy, level, xp, now_iso, cache
            )))
            applied = True
        except Exception as e:
            _bot.logger.error(f"[PET] Failed to apply pet state effects: {e}")
        finally:
            if connection:
                await _bot.close_connection(connection)
    if applied and xp_gained > 0:
        await pet_announce_xp_gain(cache, xp_gained, old_level, progress)
    if applied:
        await pet_check_low_stats({"happiness": happiness, "hunger": hunger, "energy": energy})

# Function to commit decayed stats before going offline so they stay frozen until the next stream
async def pet_freeze_for_stream_offline():
    cache = await pet_get_cache()
    if not cache.get("configured"):
        return
    now = datetime.now(timezone.utc)
    now_naive = now.replace(tzinfo=None)
    now_iso = now.strftime("%Y-%m-%dT%H:%M:%SZ")
    async with _pet_state_lock:
        connection = None
        try:
            connection = await _bot.open_connection()
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT happiness, hunger, energy, level, xp, last_interaction_at FROM pet_state WHERE id = 1")
                row = await cursor.fetchone()
                if row:
                    happiness = row.get("happiness") if row.get("happiness") is not None else 80
                    hunger = row.get("hunger") if row.get("hunger") is not None else 80
                    energy = row.get("energy") if row.get("energy") is not None else 80
                    xp = max(0, int(row.get("xp") or 0))
                    last_at = row.get("last_interaction_at")
                else:
                    happiness, hunger, energy, xp, last_at = 80, 80, 80, 0, None
                decayed = pet_decay_stats(happiness, hunger, energy, last_at, cache, allow_decay=True)
                happiness = decayed["happiness"]
                hunger = decayed["hunger"]
                energy = decayed["energy"]
                level = min(99, 1 + xp // 100)
                await cursor.execute(
                    "INSERT INTO pet_state (id, happiness, hunger, energy, level, xp, last_interaction_at) VALUES (1, %s, %s, %s, %s, %s, %s) "
                    "ON DUPLICATE KEY UPDATE happiness=%s, hunger=%s, energy=%s, last_interaction_at=%s",
                    (happiness, hunger, energy, level, xp, now_naive, happiness, hunger, energy, now_naive)
                )
                await connection.commit()
            _bot.safe_create_task(_bot.websocket_notice(event="PET_STATE", additional_data=pet_state_notice_data(
                happiness, hunger, energy, level, xp, now_iso, cache, stream_online_override=False
            )))
        except Exception as e:
            _bot.logger.error(f"[PET] Failed to freeze pet stats for stream offline: {e}")
        finally:
            if connection:
                await _bot.close_connection(connection)

# Function to reset happiness/hunger/energy to dashboard start values at the beginning of a new stream
async def pet_reset_for_new_stream():
    cache = await pet_get_cache()
    if not cache.get("configured"):
        return
    now = datetime.now(timezone.utc)
    now_naive = now.replace(tzinfo=None)
    now_iso = now.strftime("%Y-%m-%dT%H:%M:%SZ")
    happiness = pet_clamp_stat(cache.get("start_happiness") if cache.get("start_happiness") is not None else 80)
    hunger = pet_clamp_stat(cache.get("start_hunger") if cache.get("start_hunger") is not None else 80)
    energy = pet_clamp_stat(cache.get("start_energy") if cache.get("start_energy") is not None else 80)
    async with _pet_state_lock:
        connection = None
        try:
            connection = await _bot.open_connection()
            async with connection.cursor(DictCursor) as cursor:
                await cursor.execute("SELECT level, xp FROM pet_state WHERE id = 1")
                row = await cursor.fetchone()
                xp = max(0, int((row or {}).get("xp") or 0))
                level = min(99, 1 + xp // 100)
                await cursor.execute(
                    "INSERT INTO pet_state (id, happiness, hunger, energy, level, xp, last_interaction_at) VALUES (1, %s, %s, %s, %s, %s, %s) "
                    "ON DUPLICATE KEY UPDATE happiness=%s, hunger=%s, energy=%s, last_interaction_at=%s",
                    (happiness, hunger, energy, level, xp, now_naive, happiness, hunger, energy, now_naive)
                )
                await connection.commit()
            _bot.safe_create_task(_bot.websocket_notice(event="PET_STATE", additional_data=pet_state_notice_data(
                happiness, hunger, energy, level, xp, now_iso, cache
            )))
        except Exception as e:
            _bot.logger.error(f"[PET] Failed to reset pet stats for new stream: {e}")
        finally:
            if connection:
                await _bot.close_connection(connection)

# Function to cooldown-gate a pet trigger, emit PET_REACT, and apply stat effects
async def pet_apply_trigger(trigger, user_display_name, personalized=False):
    cache = await pet_get_cache()
    if not cache.get("enabled") or not trigger:
        return False
    trigger_id = trigger.get("id")
    cooldown_seconds = int(trigger.get("cooldown_seconds") or 0)
    cooldown_user = _bot.get_channel_name() or "global"
    if trigger_id is not None:
        cooldown_name = f"pet_trigger_{trigger_id}"
        if not await _bot.check_cooldown(cooldown_name, cooldown_user, "default", 1, cooldown_seconds, send_message=False):
            return False
        _bot.add_usage(cooldown_name, cooldown_user, "default")
    bubble_text = str(trigger.get("bubble_text") or "")
    had_user = "{user}" in bubble_text
    if had_user:
        bubble_text = bubble_text.replace("{user}", str(user_display_name or ""))
    animation = str(trigger.get("animation") or "").strip()
    is_personalized = bool(personalized or had_user)
    if animation:
        react_data = {"animation": animation, "personalized": is_personalized}
        if bubble_text:
            react_data["bubble_text"] = bubble_text
        _bot.safe_create_task(_bot.websocket_notice(event="PET_REACT", additional_data=react_data))
    await pet_apply_state_effects(trigger, cache)
    return True

# Function to match whole-token chat keywords against the cached pet triggers
async def pet_try_chat_keywords(message_text, user_display_name):
    try:
        cache = await pet_get_cache()
        if not cache.get("enabled"):
            return
        keywords = cache.get("chat_keyword") or {}
        if not keywords:
            return
        for token in pet_message_tokens(message_text):
            trigger = keywords.get(token)
            if trigger:
                await pet_apply_trigger(trigger, user_display_name)
    except Exception as e:
        _bot.logger.error(f"[PET] Chat keyword match failed: {e}")

# Function to match a !command against cached command-type pet triggers (including builtin names)
async def pet_try_command_trigger(command, user_display_name):
    try:
        cache = await pet_get_cache()
        if not cache.get("enabled"):
            return
        key = str(command or "").lstrip("!").lower()
        if key in ("pet", "feed", "play", "sad", "sleep"):
            return
        trigger = (cache.get("command") or {}).get(key)
        if trigger:
            await pet_apply_trigger(trigger, user_display_name)
    except Exception as e:
        _bot.logger.error(f"[PET] Command trigger match failed: {e}")

# Function to fire a cached event-type pet trigger (follow/sub/raid/cheer/first_chat/gift_sub)
async def pet_try_event_trigger(event_name, user_display_name, personalized=False):
    try:
        cache = await pet_get_cache()
        if not cache.get("enabled"):
            return
        key = str(event_name or "").strip().lower()
        trigger = (cache.get("event") or {}).get(key)
        if trigger:
            await pet_apply_trigger(trigger, user_display_name, personalized=personalized or key == "first_chat")
    except Exception as e:
        _bot.logger.error(f"[PET] Event trigger '{event_name}' failed: {e}")

# Function to match a channel-point reward_id against cached redemption pet triggers
async def pet_try_redemption_trigger(reward_id, user_display_name):
    try:
        cache = await pet_get_cache()
        if not cache.get("enabled"):
            return
        key = str(reward_id or "").strip().lower()
        trigger = (cache.get("redemption") or {}).get(key)
        if trigger:
            await pet_apply_trigger(trigger, user_display_name)
    except Exception as e:
        _bot.logger.error(f"[PET] Redemption trigger match failed: {e}")

# Function to fire an interaction trigger (feed/play/sad/sleep), using baked-in fallbacks if no row exists
async def pet_try_interaction(interaction_name, user_display_name):
    try:
        cache = await pet_get_cache()
        if not cache.get("enabled"):
            return False
        key = str(interaction_name or "").strip().lower()
        trigger = (cache.get("interaction") or {}).get(key)
        if not trigger:
            trigger = _PET_INTERACTION_FALLBACKS.get(key)
        if not trigger:
            return False
        return await pet_apply_trigger(trigger, user_display_name)
    except Exception as e:
        _bot.logger.error(f"[PET] Interaction '{interaction_name}' failed: {e}")
        return False

# Function to split a saved alert message field into its non-empty lines
def pet_alert_message_lines(raw):
    return [line.strip() for line in str(raw or "").splitlines() if line.strip()]

# Function to post a chat alert for each pet stat that has dropped to the configured threshold
async def pet_check_low_stats(stats=None):
    try:
        cache = await pet_get_cache()
        if not (cache.get("enabled") and cache.get("alert_enabled")) or not _bot.is_stream_online():
            _pet_alert_state.clear()
            return
        if stats is None:
            stats = await pet_current_stats()
        if not stats:
            return
        threshold = int(cache.get("alert_threshold") or 0)
        cooldown = int(cache.get("alert_cooldown_minutes") or 0) * 60
        pet_name = cache.get("pet_name") or "Pet"
        now = time.time()
        for stat in PET_ALERT_STATS:
            if int(stats.get(stat, 100)) > threshold:
                _pet_alert_state.pop(stat, None)
                continue
            last_alert = _pet_alert_state.get(stat)
            if last_alert is not None and (cooldown <= 0 or now - last_alert < cooldown):
                continue
            _pet_alert_state[stat] = now
            lines = (cache.get("alert_messages") or {}).get(stat) or PET_ALERT_DEFAULT_MESSAGES[stat]
            await _bot.send_chat_message(random.choice(lines).replace("(pet)", pet_name))
    except Exception as e:
        _bot.logger.error(f"[PET] Low stat check failed: {e}")

# Function to keep checking the pet stats while the bot runs (stats decay lazily, so nothing else notices them dropping)
async def pet_low_stat_watch():
    while True:
        await asyncio.sleep(PET_ALERT_CHECK_SECONDS)
        await pet_check_low_stats()
