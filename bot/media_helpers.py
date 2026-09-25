# ./bot/media_helpers.py
import re

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

def artist_limit_reply(artist: str, limit: int, period: str) -> str:
    window = {"stream": "this stream", "week": "this week", "month": "this month"}.get(period, "this stream")
    shown = clean_request_artist(artist) or "That artist"
    return f"{shown} has already been requested {int(limit)} times {window}. Try another artist."

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
