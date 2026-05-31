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
