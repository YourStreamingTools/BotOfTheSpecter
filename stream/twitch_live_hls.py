"""Twitch live HLS lookup and playlist parsing shared by the recorder and its capture helper."""
from __future__ import annotations

import datetime
import logging
import os
import re
import time
from typing import Dict, List, Optional, Tuple
from urllib.parse import urljoin

import aiohttp

logger = logging.getLogger("twitch_live_hls")

TWITCH_GQL = "https://gql.twitch.tv/gql"
TWITCH_LIVE_USHER = "https://usher.ttvnw.net/api/channel/hls/{login}.m3u8"
LIVE_PLAYBACK_QUERY = """
query StreamAccess($login: String!, $playerType: String!) {
  user(login: $login) {
    stream {
      id
      title
      game {
        name
      }
      playbackAccessToken(params: {platform: "web", playerBackend: "mediaplayer", playerType: $playerType}) {
        value
        signature
      }
    }
  }
}
"""


def twitch_web_client_id() -> str:
    return os.getenv("TWITCH_WEB_CLIENT_ID", "kimne78kx3ncx6brgo4mv6wki5h1ko")


def stream_meta_from_gql(stream: dict) -> dict:
    title = (stream.get("title") or "").strip() or "Untitled"
    game = stream.get("game") if isinstance(stream.get("game"), dict) else {}
    game_name = (game.get("name") or "").strip() or "Unknown Game"
    return {"title": title, "game_name": game_name}


def _pick_hls_variant(master_text: str) -> Optional[str]:
    best_url = None
    best_bw = -1
    chunked_url = None
    lines = master_text.splitlines()
    for i, line in enumerate(lines):
        if not line.startswith("#EXT-X-STREAM-INF"):
            continue
        url = lines[i + 1].strip() if i + 1 < len(lines) else ""
        if not url or url.startswith("#"):
            continue
        bw = -1
        match = re.search(r"BANDWIDTH=(\d+)", line)
        if match:
            bw = int(match.group(1))
        if 'VIDEO="chunked"' in line or 'NAME="chunked"' in line:
            chunked_url = url
        if bw > best_bw:
            best_bw = bw
            best_url = url
    return chunked_url or best_url


def twitch_cookies_path() -> Optional[str]:
    """Path of the ad-free account's Twitch cookies.txt (Netscape format), if present.

    Setting: TWITCH_COOKIES_FILE in the stream server's .env (falls back to the
    older YT_DLP_COOKIES_FILE, then twitch-cookies.txt). A relative name is looked
    for next to this script, in the working directory and in /home/botofthespecter.
    """
    name = (os.getenv("TWITCH_COOKIES_FILE") or os.getenv("YT_DLP_COOKIES_FILE") or "twitch-cookies.txt").strip()
    if not name:
        return None
    if os.path.isabs(name):
        return name if os.path.isfile(name) else None
    for folder in (os.path.dirname(os.path.abspath(__file__)), os.getcwd(), "/home/botofthespecter"):
        candidate = os.path.join(folder, name)
        if os.path.isfile(candidate):
            return candidate
    return None


def twitch_login_cookies() -> Tuple[str, str]:
    """(auth-token, unique_id) for twitch.tv from the cookies file, or empty strings.

    The file is read on every lookup, so replacing it needs no restart. Its
    contents are never logged.
    """
    path = twitch_cookies_path()
    if not path:
        return "", ""
    try:
        mode = os.stat(path).st_mode
        if mode & 0o077 and "perms" not in _auth_warned:
            _auth_warned.add("perms")
            logger.warning(f"Twitch cookies file {path} is readable by other users; chmod 600 it")
        with open(path, encoding="utf-8", errors="replace") as handle:
            lines = handle.read().splitlines()
    except OSError:
        _warn_auth_once("cookies file could not be read")
        return "", ""
    found = {}
    now = time.time()
    for line in lines:
        if line.startswith("#HttpOnly_"):
            line = line[len("#HttpOnly_"):]
        elif not line.strip() or line.startswith("#"):
            continue
        parts = line.split("\t")
        if len(parts) < 7:
            continue
        domain, _flag, _path, _secure, expires, name, value = parts[:7]
        if not domain.lstrip(".").endswith("twitch.tv") or name not in ("auth-token", "unique_id"):
            continue
        try:
            if int(expires or 0) and int(expires) < now:
                if name == "auth-token":
                    _warn_auth_once("auth-token cookie has expired; export a fresh cookies file")
                continue
        except ValueError:
            pass
        found[name] = value.strip()
    if not found.get("auth-token"):
        _warn_auth_once("no twitch.tv auth-token cookie in the cookies file")
        return "", ""
    return found["auth-token"], found.get("unique_id", "")


_auth_warned = set()


def _warn_auth_once(reason: str) -> None:
    if reason in _auth_warned:
        return
    _auth_warned.add(reason)
    logger.warning(f"Twitch ad-free login not used ({reason}); using a logged-out playlist, ads are cut")


async def _gql_stream(session: aiohttp.ClientSession, login: str, auth_token: str, device_id: str = ""):
    headers = {
        "Client-ID": twitch_web_client_id(),
        "Content-Type": "application/json",
        "User-Agent": "Mozilla/5.0",
    }
    if auth_token:
        headers["Authorization"] = f"OAuth {auth_token}"
        if device_id:
            headers["X-Device-Id"] = device_id
    payload = {
        "query": LIVE_PLAYBACK_QUERY,
        "variables": {"login": login, "playerType": "site"},
    }
    async with session.post(TWITCH_GQL, headers=headers, json=payload) as resp:
        try:
            body = await resp.json(content_type=None)
        except Exception:
            return resp.status, None
        return resp.status, body


async def twitch_live_hls(session: aiohttp.ClientSession, login: str):
    """GQL playback token + Usher HLS.

    Uses the ad-free login from the Twitch cookies file when present, and falls back to a
    logged-out request if Twitch refuses it. meta["ad_free_login"] says which.
    Returns (is_live, media_playlist_url, error, meta).
    """
    auth_token, device_id = twitch_login_cookies()
    attempts = [auth_token, ""] if auth_token else [""]
    value = signature = None
    stream = None
    used_auth = False
    for token in attempts:
        status, body = await _gql_stream(session, login, token, device_id if token else "")
        if status != 200 or not isinstance(body, dict):
            if token:
                _warn_auth_once(f"gql_http_{status}")
                continue
            return None, None, f"gql_http_{status}", None
        data = body.get("data") if isinstance(body, dict) else None
        user = data.get("user") if isinstance(data, dict) else None
        stream = user.get("stream") if isinstance(user, dict) else None
        if not stream:
            if token and body.get("errors"):
                _warn_auth_once("gql_errors")
                continue
            return False, None, None, None
        row = stream.get("playbackAccessToken") if isinstance(stream, dict) else None
        value = row.get("value") if isinstance(row, dict) else None
        signature = row.get("signature") if isinstance(row, dict) else None
        if value and signature:
            used_auth = bool(token)
            break
        if token:
            _warn_auth_once("no_playback_token")
    if not stream:
        return False, None, None, None
    meta = stream_meta_from_gql(stream)
    meta["ad_free_login"] = used_auth
    if not value or not signature:
        return True, None, "gql_no_token", meta
    params = {
        "player": "twitchweb",
        "allow_source": "true",
        "allow_audio_only": "false",
        "allow_spectre": "false",
        "playlist_include_framerate": "true",
        "sig": signature,
        "token": value,
    }
    usher = TWITCH_LIVE_USHER.format(login=login)
    async with session.get(usher, params=params, headers={"User-Agent": "Mozilla/5.0"}) as resp:
        playlist = await resp.text()
        playlist_url = str(resp.url)
        if resp.status != 200 or not playlist:
            return True, None, f"usher_http_{resp.status}", meta
    if "#EXT-X-STREAM-INF" in playlist:
        variant = _pick_hls_variant(playlist)
        if not variant:
            return True, None, "no_hls_variant", meta
        if not variant.startswith("http"):
            variant = urljoin(playlist_url, variant)
        return True, variant, None, meta
    if "#EXTINF" in playlist:
        return True, playlist_url, None, meta
    return True, None, "unrecognised_playlist", meta


# --- Media playlist parsing with Twitch ad detection ---
#
# Twitch stitches ads (and the "commercial break" image) into the live playlist
# for viewers without an ad-free account. Ad segments are marked the same way
# streamlink's --twitch-disable-ads finds them: an EXT-X-DATERANGE whose CLASS is
# "twitch-stitched-ad", whose ID starts "stitched-ad-", or that carries
# X-TV-TWITCH-AD-* attributes, covering the segment's program date time; or an
# EXTINF title containing "Amazon". Real live segments are titled "live".

_ATTR_RE = re.compile(r'([A-Z0-9-]+)=("[^"]*"|[^",]*)')


def parse_attributes(text: str) -> Dict[str, str]:
    attrs = {}
    for key, value in _ATTR_RE.findall(text or ""):
        if len(value) >= 2 and value[0] == '"' and value[-1] == '"':
            value = value[1:-1]
        attrs[key] = value
    return attrs


def parse_iso_datetime(value: str) -> Optional[datetime.datetime]:
    if not value:
        return None
    text = value.strip()
    if text.endswith("Z"):
        text = text[:-1] + "+00:00"
    try:
        parsed = datetime.datetime.fromisoformat(text)
    except ValueError:
        # Python < 3.11 cannot read some fraction lengths; trim to microseconds
        match = re.match(r"^(.*T\d\d:\d\d:\d\d)(\.\d+)?(.*)$", text)
        if not match:
            return None
        frac = (match.group(2) or "")[:7]
        try:
            parsed = datetime.datetime.fromisoformat(match.group(1) + frac + match.group(3))
        except ValueError:
            return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=datetime.timezone.utc)
    return parsed


class AdRange:
    def __init__(self, attrs: Dict[str, str]):
        self.id = attrs.get("ID", "")
        self.start = parse_iso_datetime(attrs.get("START-DATE", ""))
        self.end = parse_iso_datetime(attrs.get("END-DATE", ""))
        duration = attrs.get("DURATION") or attrs.get("PLANNED-DURATION")
        if self.end is None and self.start is not None and duration:
            try:
                self.end = self.start + datetime.timedelta(seconds=float(duration))
            except ValueError:
                pass

    def covers(self, when: Optional[datetime.datetime]) -> bool:
        if when is None or self.start is None:
            return False
        if self.end is None:
            return self.start <= when
        return self.start <= when < self.end


def is_ad_daterange(attrs: Dict[str, str]) -> bool:
    return (
        attrs.get("CLASS") == "twitch-stitched-ad"
        or attrs.get("ID", "").startswith("stitched-ad-")
        or any(key.startswith("X-TV-TWITCH-AD-") for key in attrs)
    )


class Segment:
    __slots__ = ("sequence", "url", "duration", "title", "date", "discontinuity", "ad")

    def __init__(self, sequence, url, duration, title, date, discontinuity):
        self.sequence = sequence
        self.url = url
        self.duration = duration
        self.title = title
        self.date = date
        self.discontinuity = discontinuity
        self.ad = False


class MediaPlaylist:
    def __init__(self):
        self.media_sequence = 0
        self.target_duration = 2.0
        self.ended = False
        self.segments: List[Segment] = []
        self.ad_ranges: List[AdRange] = []


def parse_media_playlist(text: str, base_url: str) -> MediaPlaylist:
    playlist = MediaPlaylist()
    pending_duration = None
    pending_title = ""
    pending_date = None
    pending_discontinuity = False
    next_date = None
    sequence = None
    for raw in (text or "").splitlines():
        line = raw.strip()
        if not line:
            continue
        if line.startswith("#EXT-X-MEDIA-SEQUENCE:"):
            try:
                playlist.media_sequence = int(line.split(":", 1)[1])
            except ValueError:
                pass
        elif line.startswith("#EXT-X-TARGETDURATION:"):
            try:
                playlist.target_duration = float(line.split(":", 1)[1])
            except ValueError:
                pass
        elif line.startswith("#EXT-X-ENDLIST"):
            playlist.ended = True
        elif line.startswith("#EXT-X-DATERANGE:"):
            attrs = parse_attributes(line.split(":", 1)[1])
            if is_ad_daterange(attrs):
                playlist.ad_ranges.append(AdRange(attrs))
        elif line.startswith("#EXT-X-PROGRAM-DATE-TIME:"):
            pending_date = parse_iso_datetime(line.split(":", 1)[1])
        elif line.startswith("#EXT-X-DISCONTINUITY") and not line.startswith("#EXT-X-DISCONTINUITY-SEQUENCE"):
            pending_discontinuity = True
        elif line.startswith("#EXTINF:"):
            value = line.split(":", 1)[1]
            duration_text, _, title = value.partition(",")
            try:
                pending_duration = float(duration_text)
            except ValueError:
                pending_duration = playlist.target_duration
            pending_title = title.strip()
        elif line.startswith("#"):
            # Includes #EXT-X-TWITCH-PREFETCH low-latency hints, which we skip
            continue
        elif pending_duration is not None:
            if sequence is None:
                sequence = playlist.media_sequence
            date = pending_date or next_date
            playlist.segments.append(
                Segment(sequence, urljoin(base_url, line), pending_duration, pending_title, date, pending_discontinuity)
            )
            next_date = date + datetime.timedelta(seconds=pending_duration) if date else None
            sequence += 1
            pending_duration = None
            pending_title = ""
            pending_date = None
            pending_discontinuity = False
    for segment in playlist.segments:
        segment.ad = "Amazon" in segment.title or any(r.covers(segment.date) for r in playlist.ad_ranges)
    return playlist
