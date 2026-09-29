# ./bot/modules/media_helpers.py
import re

from aiomysql import DictCursor

_TITLE_CLEANUP_PATTERNS = [
    r'\s*\[.*?\]\s*',
    r'\s*\(.*?\)\s*',
    r'\s*-\s*(Official|Music|Lyric|Audio).*$',
    r'\s*\|\s*.*$',
    r'\s*(HD|4K|1080p|720p).*$',
]

def clean_youtube_title(title: str) -> str:
    cleaned = title or ""
    for pattern in _TITLE_CLEANUP_PATTERNS:
        cleaned = re.sub(pattern, '', cleaned, flags=re.IGNORECASE)
    return cleaned.strip()

def evaluate_guardrails(*, duration, queue_count, viewer_count, settings, video_id, title, banlist):
    """Return (ok: bool, reason: str|None). reason in {too_long,queue_full,viewer_limit,banned}."""
    if duration is not None and duration > int(settings["max_song_seconds"]):
        return False, "too_long"
    if queue_count >= int(settings["max_queue_length"]):
        return False, "queue_full"
    if viewer_count >= int(settings["per_viewer_limit"]):
        return False, "viewer_limit"
    title_l = (title or "").lower()
    for entry in banlist:
        if entry["type"] == "video_id" and entry["value"] == video_id:
            return False, "banned"
        if entry["type"] == "keyword" and entry["value"].lower() in title_l:
            return False, "banned"
    return True, None

def format_queue_line(position: int, title: str, requested_by: str) -> str:
    return f"{position}. {title} (requested by {requested_by})"

def clean_request_artist(name: str) -> str:
    text = " ".join((name or "").split())
    if text.lower().endswith(" - topic"):
        text = text[: -len(" - topic")].strip()
    return text

def artist_match_keys(name: str) -> list:
    cleaned = clean_request_artist(name)
    key = cleaned.lower()
    if not key:
        return []
    topic = f"{key} - topic"
    return [key, topic] if topic != key else [key]

def artist_list_key(name: str) -> str:
    return clean_request_artist(name).lower()

def artist_limit_applies(scope: str, is_listed: bool) -> bool:
    if scope == "listed":
        return is_listed
    if scope == "except_listed":
        return not is_listed
    return True

def resolve_artist_limit(global_limit, scope, listed, custom_count):
    """Cap for this artist, or None when no cap applies.
    custom_count is None when the artist has no per-artist number.
    A custom count of 0 means that artist is unlimited."""
    if custom_count is not None:
        cap = int(custom_count)
        return cap if cap > 0 else None
    if not artist_limit_applies(str(scope or "all"), bool(listed)):
        return None
    cap = int(global_limit or 0)
    return cap if cap > 0 else None

def artist_limit_reply(artist: str, limit: int, period: str) -> str:
    window = {"stream": "this stream", "week": "this week", "month": "this month"}.get(period, "this stream")
    shown = clean_request_artist(artist) or "That artist"
    return f"{shown} has already been requested {int(limit)} times {window}. Try another artist."

def same_requester(requested_by: str, author_name: str) -> bool:
    return bool(requested_by) and bool(author_name) and requested_by.lower() == author_name.lower()

async def record_open_song_request(connection, song_id, song_name, artist_name, requested_by):
    # Same song_request_analytics log the media player page shows. in_queue marks rows still waiting.
    try:
        async with connection.cursor() as cursor:
            await cursor.execute(
                "INSERT INTO song_request_analytics (song_name, artist_name, requested_by, song_id, in_queue) VALUES (%s, %s, %s, %s, 1)",
                (song_name, artist_name, requested_by, song_id),
            )
    except Exception as e:
        if getattr(e, "args", [None])[0] != 1054 and "Unknown column" not in str(e):
            raise
        async with connection.cursor() as cursor:
            await cursor.execute(
                "INSERT INTO song_request_analytics (song_name, artist_name, requested_by) VALUES (%s, %s, %s)",
                (song_name, artist_name, requested_by),
            )

async def load_open_song_requests(connection):
    async with connection.cursor(DictCursor) as cursor:
        await cursor.execute(
            "SELECT song_id, song_name, artist_name, requested_by FROM song_request_analytics "
            "WHERE in_queue=1 AND song_id IS NOT NULL AND song_id<>'' ORDER BY id ASC"
        )
        rows = await cursor.fetchall()
    loaded = {}
    for row in rows or []:
        song_id = row.get("song_id")
        if not song_id:
            continue
        loaded[song_id] = {
            "user": row.get("requested_by") or "",
            "song_name": row.get("song_name") or "",
            "artist_name": row.get("artist_name") or "",
            "timestamp": None,
        }
    return loaded

async def close_open_song_requests(connection, song_ids):
    ids = [song_id for song_id in song_ids if song_id]
    if not ids:
        return
    placeholders = ",".join(["%s"] * len(ids))
    async with connection.cursor() as cursor:
        await cursor.execute(
            f"UPDATE song_request_analytics SET in_queue=0 WHERE in_queue=1 AND song_id IN ({placeholders})",
            tuple(ids),
        )

async def sync_song_request_cache(connection, song_requests, queue_ids):
    loaded = await load_open_song_requests(connection)
    for song_id, info in loaded.items():
        song_requests.setdefault(song_id, info)
    removed = []
    for song_id in list(song_requests):
        if song_id not in queue_ids:
            removed.append((song_id, song_requests.pop(song_id, {})))
    await close_open_song_requests(connection, [song_id for song_id, _info in removed])
    return removed

def artist_limit_query(period: str, key_count: int):
    placeholders = ",".join(["%s"] * key_count)
    where = f"LOWER(artist_name) IN ({placeholders})"
    if period == "week":
        sql = f"SELECT COUNT(*) AS c FROM song_request_analytics WHERE {where} AND requested_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)"
        return sql, False
    if period == "month":
        sql = f"SELECT COUNT(*) AS c FROM song_request_analytics WHERE {where} AND requested_at >= DATE_FORMAT(CURDATE(), '%%Y-%%m-01')"
        return sql, False
    sql = f"SELECT COUNT(*) AS c FROM song_request_analytics WHERE {where} AND requested_at >= FROM_UNIXTIME(%s)"
    return sql, True
