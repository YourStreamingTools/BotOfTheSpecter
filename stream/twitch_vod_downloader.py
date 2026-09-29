#!/usr/bin/env python3
"""
Shared Twitch VOD pull for the stream server.

One worker process per (user, VOD) claims a row in website.twitch_vod_pulls,
downloads the HLS segments over many parallel connections (Twitch throttles
each connection to a few MB/s), then remuxes the local copy to MP4 with ffmpeg.

The Store button (stream.py), the YouTube uploader and the S3 uploader all go
through request_pull() / pull_gate(): whoever asks first starts the worker,
everyone else waits for the row to reach 'stored'.

Run as a worker:
  twitch_vod_downloader.py --user-id 1 --username name --vod-id 123 --dest /root/name/twitch-123.mp4
"""
import os
import re
import sys
import json
import time
import shutil
import socket
import asyncio
import argparse
import subprocess
from urllib.parse import urljoin, urlparse
import aiohttp
import aiomysql
import logging
from logging.handlers import RotatingFileHandler
from dotenv import load_dotenv
from ffmpeg_jobs import pull_ffmpeg_log_path, update_media_meta, read_media_meta

load_dotenv()

DB_HOST = os.getenv("SQL_HOST")
DB_USER = os.getenv("SQL_USER")
DB_PASS = os.getenv("SQL_PASSWORD")
DB_NAME = "website"
STREAM_DIR = os.path.dirname(os.path.abspath(__file__))
HOSTNAME = socket.gethostname()
PULL_CONCURRENCY = max(1, int(os.getenv("TWITCH_PULL_CONCURRENCY") or "24"))
PULL_LIVE_SECONDS = 120
HEARTBEAT_SECONDS = 10
SEGMENT_RETRIES = 5
DOWNLOAD_SHARE = 85.0
STREAM_STORAGE_QUOTA_BYTES = int(os.getenv("STREAM_STORAGE_QUOTA_BYTES") or str(100 * 1024 * 1024 * 1024))
USER_AGENT = "Mozilla/5.0"

TWITCH_WEB_CLIENT_ID = os.getenv("TWITCH_WEB_CLIENT_ID", "kimne78kx3ncx6brgo4mv6wki5h1ko")
TWITCH_GQL = "https://gql.twitch.tv/gql"
TWITCH_USHER = "https://usher.ttvnw.net/vod/{vod_id}.m3u8"
PLAYBACK_QUERY = """
query PlaybackAccessToken($id: ID!, $playerType: String!) {
  video(id: $id) {
    playbackAccessToken(params: {platform: "web", playerBackend: "mediaplayer", playerType: $playerType}) {
      value
      signature
    }
  }
}
"""

logger = logging.getLogger("twitch_vod_downloader")
_SPAWNED = []


def _setup_worker_logging():
    log_dir = os.path.join(STREAM_DIR, "logs")
    os.makedirs(log_dir, exist_ok=True)
    handler = RotatingFileHandler(os.path.join(log_dir, "twitch_vod_downloader.log"), maxBytes=500 * 1024, backupCount=5)
    handler.setFormatter(logging.Formatter("%(asctime)s %(message)s", "%Y-%m-%d %H:%M:%S"))
    logger.addHandler(handler)
    console = logging.StreamHandler()
    console.setFormatter(logging.Formatter("%(message)s"))
    logger.addHandler(console)
    logger.setLevel(logging.INFO)
    logger.propagate = False


def default_dest(root, username, vod_id):
    return os.path.join(root, username, f"twitch-{vod_id}.mp4")


def segments_dir(dest):
    return dest + ".part.d"


def _safe_username(name):
    return isinstance(name, str) and bool(re.match(r"^[a-zA-Z0-9_]{1,64}$", name))


def _safe_vod_id(vod_id):
    return isinstance(vod_id, str) and bool(re.match(r"^[0-9]{1,20}$", vod_id))


# Twitch HLS resolution

async def _read_json(resp):
    text = await resp.text()
    if not text:
        return {}
    try:
        decoded = json.loads(text)
        return decoded if isinstance(decoded, dict) else {"raw": text[:300]}
    except Exception:
        return {"raw": text[:300]}


def _pick_hls_variant(master_text):
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
        m = re.search(r"BANDWIDTH=(\d+)", line)
        if m:
            bw = int(m.group(1))
        if 'VIDEO="chunked"' in line or 'NAME="chunked"' in line:
            chunked_url = url
        if bw > best_bw:
            best_bw = bw
            best_url = url
    return chunked_url or best_url


def _gql_playback_headers(twitch_oauth):
    headers = {
        "Client-ID": TWITCH_WEB_CLIENT_ID,
        "Content-Type": "application/json",
        "User-Agent": USER_AGENT,
    }
    oauth = (twitch_oauth or "").strip()
    if oauth.lower().startswith("oauth:"):
        oauth = oauth.split(":", 1)[1]
    if oauth:
        headers["Authorization"] = f"OAuth {oauth}"
    return headers


def _playback_token_from_gql(body):
    data = body.get("data") if isinstance(body, dict) else None
    if not isinstance(data, dict):
        return None, None
    token_row = data.get("videoPlaybackAccessToken")
    if not isinstance(token_row, dict):
        video = data.get("video") if isinstance(data.get("video"), dict) else {}
        token_row = video.get("playbackAccessToken") if isinstance(video.get("playbackAccessToken"), dict) else {}
    return token_row.get("value"), token_row.get("signature")


async def twitch_vod_hls_url(session, vod_id, twitch_oauth):
    payload = {
        "operationName": "PlaybackAccessToken",
        "query": PLAYBACK_QUERY,
        "variables": {
            "id": str(vod_id),
            "playerType": "site",
        },
    }
    attempts = [twitch_oauth, ""] if (twitch_oauth or "").strip() else [""]
    body = {}
    last_status = None
    for oauth in attempts:
        headers = _gql_playback_headers(oauth)
        async with session.post(TWITCH_GQL, headers=headers, json=payload) as resp:
            last_status = resp.status
            body = await _read_json(resp)
        if last_status != 200:
            continue
        value, signature = _playback_token_from_gql(body)
        if value and signature:
            break
    else:
        value, signature = None, None
    if last_status != 200 and not value:
        return None, f"gql_http_{last_status}"
    if not value or not signature:
        return None, "gql_no_token"
    params = {
        "player": "twitchweb",
        "allow_source": "true",
        "allow_audio_only": "false",
        "allow_spectre": "false",
        "playlist_include_framerate": "true",
        "sig": signature,
        "token": value,
    }
    usher = TWITCH_USHER.format(vod_id=vod_id)
    async with session.get(usher, params=params, headers={"User-Agent": USER_AGENT}) as resp:
        playlist = await resp.text()
        playlist_url = str(resp.url)
        if resp.status != 200 or not playlist:
            return None, f"usher_http_{resp.status}"
    if "#EXT-X-STREAM-INF" in playlist:
        variant = _pick_hls_variant(playlist)
        if not variant:
            return None, "no_hls_variant"
        if not variant.startswith("http"):
            variant = urljoin(playlist_url, variant)
        return variant, None
    if "#EXTINF" in playlist:
        return playlist_url, None
    return None, "unrecognised_playlist"


# Duration

def probe_duration_seconds(path, timeout=60):
    try:
        out = subprocess.run(
            ["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "default=noprint_wrappers=1:nokey=1", path],
            capture_output=True,
            timeout=timeout,
        )
        raw = (out.stdout or b"").decode("utf-8", "replace").strip()
        value = float(raw) if raw else None
    except Exception:
        return None
    if value is None or value < 0:
        return None
    return int(round(value))


def cache_duration(path, vod_id="", title=""):
    """Probe once and store duration_seconds (plus vod_id/title when known) in the sidecar."""
    duration = probe_duration_seconds(path)
    fields = {}
    if duration is not None:
        fields["duration_seconds"] = duration
    if vod_id:
        fields["vod_id"] = str(vod_id)
    if title:
        fields["title"] = title
    if fields:
        update_media_meta(path, **fields)
    return duration


# Shared pull state (website.twitch_vod_pulls)

async def pull_state(conn, user_id, vod_id):
    async with conn.cursor(aiomysql.DictCursor) as cur:
        await cur.execute(
            """
            SELECT id, user_id, twitch_video_id, username, filename, title, status, error_message,
                   bytes_sent, bytes_total, progress_percent, owner_host, owner_pid,
                   UNIX_TIMESTAMP(updated_at) AS updated_unix,
                   (status IN ('queued', 'pulling') AND updated_at >= NOW() - INTERVAL %s SECOND) AS live
            FROM twitch_vod_pulls
            WHERE user_id = %s AND twitch_video_id = %s
            LIMIT 1
            """,
            (PULL_LIVE_SECONDS, int(user_id), str(vod_id)),
        )
        row = await cur.fetchone()
    if row:
        row["live"] = bool(row.get("live"))
    return row


async def pulls_for_user(conn, user_id, recent_seconds=6 * 3600):
    async with conn.cursor(aiomysql.DictCursor) as cur:
        await cur.execute(
            """
            SELECT twitch_video_id, username, filename, title, status, error_message,
                   bytes_sent, bytes_total, progress_percent,
                   (status IN ('queued', 'pulling') AND updated_at >= NOW() - INTERVAL %s SECOND) AS live
            FROM twitch_vod_pulls
            WHERE user_id = %s AND status IN ('queued', 'pulling', 'failed')
              AND updated_at >= NOW() - INTERVAL %s SECOND
            ORDER BY updated_at DESC
            """,
            (PULL_LIVE_SECONDS, int(user_id), int(recent_seconds)),
        )
        rows = await cur.fetchall() or []
    for row in rows:
        row["live"] = bool(row.get("live"))
    return rows


def spawn_worker(user_id, username, vod_id, dest, title=""):
    """Start a detached worker that outlives the caller (stream restarts, oneshot uploaders)."""
    script = os.path.join(STREAM_DIR, "twitch_vod_downloader.py")
    args = [
        sys.executable, script,
        f"--user-id={int(user_id)}",
        f"--username={username}",
        f"--vod-id={vod_id}",
        f"--dest={dest}",
    ]
    if title:
        args.append(f"--title={title}")
    systemd_run = shutil.which("systemd-run")
    if systemd_run and hasattr(os, "geteuid") and os.geteuid() == 0:
        cmd = [
            systemd_run, "--quiet", "--collect",
            f"--unit=specter-vod-pull-{int(user_id)}-{vod_id}",
            f"--property=WorkingDirectory={STREAM_DIR}",
            "--property=Nice=5",
        ]
        env_file = os.path.join(STREAM_DIR, ".env")
        if os.path.isfile(env_file):
            cmd.append(f"--property=EnvironmentFile={env_file}")
        try:
            res = subprocess.run(cmd + args, capture_output=True, timeout=15)
        except Exception as e:
            logger.error(f"systemd-run failed for VOD {vod_id}: {e}")
            return False
        if res.returncode != 0:
            err = (res.stderr or b"").decode("utf-8", "replace").strip()
            # A unit with this name still exists: a worker for this VOD is already running.
            logger.warning(f"systemd-run for VOD {vod_id} exited {res.returncode}: {err[:200]}")
            return "already exists" in err.lower()
        return True
    for proc in list(_SPAWNED):
        if proc.poll() is not None:
            _SPAWNED.remove(proc)
    try:
        proc = subprocess.Popen(
            args,
            cwd=STREAM_DIR,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            start_new_session=True,
            close_fds=True,
        )
    except OSError as e:
        logger.error(f"Could not spawn VOD worker for {vod_id}: {e}")
        return False
    _SPAWNED.append(proc)
    return True


async def request_pull(conn, user_id, username, vod_id, title, dest):
    """Queue a pull and start a worker unless one is already live. Returns 'pulling' or 'started'."""
    filename = os.path.basename(dest)
    title = (title or "").strip()[:255]
    async with conn.cursor() as cur:
        await cur.execute(
            """
            INSERT INTO twitch_vod_pulls
                (user_id, twitch_video_id, username, filename, title, status, owner_host)
            VALUES (%s, %s, %s, %s, %s, 'queued', %s)
            ON DUPLICATE KEY UPDATE id = id
            """,
            (int(user_id), str(vod_id), username, filename, title or None, HOSTNAME),
        )
        start = cur.rowcount == 1
        if not start:
            await cur.execute(
                """
                UPDATE twitch_vod_pulls
                SET status = 'queued', username = %s, filename = %s,
                    title = COALESCE(NULLIF(%s, ''), title), error_message = NULL,
                    owner_host = %s, owner_pid = NULL, updated_at = NOW()
                WHERE user_id = %s AND twitch_video_id = %s
                  AND NOT (status IN ('queued', 'pulling') AND updated_at >= NOW() - INTERVAL %s SECOND)
                """,
                (username, filename, title, HOSTNAME, int(user_id), str(vod_id), PULL_LIVE_SECONDS),
            )
            start = cur.rowcount == 1
    await conn.commit()
    if not start:
        return "pulling"
    if not spawn_worker(user_id, username, vod_id, dest, title):
        async with conn.cursor() as cur:
            await cur.execute(
                "UPDATE twitch_vod_pulls SET status = 'failed', error_message = 'could_not_start' "
                "WHERE user_id = %s AND twitch_video_id = %s AND status = 'queued'",
                (int(user_id), str(vod_id)),
            )
        await conn.commit()
        return "failed"
    return "started"


async def pull_gate(pool, user_id, username, vod_id, title, dest, waiting):
    """
    For an upload job that needs a Twitch VOD on disk.
    Returns ('ready', row) when the file is complete, ('waiting', row) while a pull
    runs (starting one if needed), or ('failed', error) when the pull this job was
    waiting on failed.
    """
    async with pool.acquire() as conn:
        state = await pull_state(conn, user_id, vod_id)
    live = bool(state and state["live"])
    if not live and os.path.isfile(dest) and not os.path.isdir(segments_dir(dest)):
        return "ready", state
    if live:
        return "waiting", state
    if waiting and state and state.get("status") == "failed":
        return "failed", state.get("error_message") or "pull_failed"
    async with pool.acquire() as conn:
        result = await request_pull(conn, user_id, username, vod_id, title, dest)
        if result == "failed":
            return "failed", "could_not_start"
        state = await pull_state(conn, user_id, vod_id)
    return "waiting", state


# Worker

class LostClaim(Exception):
    pass


class PullWorker:
    def __init__(self, pool, user_id, username, vod_id, dest, title):
        self.pool = pool
        self.user_id = int(user_id)
        self.username = username
        self.vod_id = str(vod_id)
        self.dest = dest
        self.title = (title or "").strip()
        self.pid = os.getpid()
        self.lost = False
        self._last_progress = 0.0

    async def _exec(self, sql, params):
        async with self.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(sql, params)
                return cur.rowcount

    async def claim(self):
        await self._exec(
            """
            INSERT INTO twitch_vod_pulls
                (user_id, twitch_video_id, username, filename, title, status, owner_host)
            VALUES (%s, %s, %s, %s, %s, 'queued', %s)
            ON DUPLICATE KEY UPDATE id = id
            """,
            (self.user_id, self.vod_id, self.username, os.path.basename(self.dest), self.title or None, HOSTNAME),
        )
        n = await self._exec(
            """
            UPDATE twitch_vod_pulls
            SET status = 'pulling', phase = 'downloading', owner_host = %s, owner_pid = %s, error_message = NULL,
                bytes_sent = 0, bytes_total = 0, progress_percent = 0,
                username = %s, filename = %s, title = COALESCE(NULLIF(%s, ''), title), updated_at = NOW()
            WHERE user_id = %s AND twitch_video_id = %s
              AND NOT (status = 'pulling' AND updated_at >= NOW() - INTERVAL %s SECOND)
            """,
            (HOSTNAME, self.pid, self.username, os.path.basename(self.dest), self.title,
             self.user_id, self.vod_id, PULL_LIVE_SECONDS),
        )
        return n == 1

    async def _owned(self):
        async with self.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "SELECT owner_host, owner_pid, status FROM twitch_vod_pulls WHERE user_id = %s AND twitch_video_id = %s",
                    (self.user_id, self.vod_id),
                )
                row = await cur.fetchone()
        return bool(row) and row[0] == HOSTNAME and int(row[1] or 0) == self.pid and row[2] == "pulling"

    async def heartbeat(self):
        while True:
            await asyncio.sleep(HEARTBEAT_SECONDS)
            try:
                if not await self._owned():
                    self.lost = True
                    logger.warning(f"Lost claim on VOD {self.vod_id}; stopping")
                    return
                await self._exec(
                    "UPDATE twitch_vod_pulls SET updated_at = NOW() WHERE user_id = %s AND twitch_video_id = %s AND owner_pid = %s",
                    (self.user_id, self.vod_id, self.pid),
                )
            except Exception as e:
                logger.warning(f"Heartbeat failed for VOD {self.vod_id}: {e}")

    async def progress(self, percent, sent, total, force=False, phase="downloading"):
        now = time.monotonic()
        if not force and now - self._last_progress < 2:
            return
        self._last_progress = now
        if self.lost:
            raise LostClaim()
        pct = round(max(0.0, min(100.0, float(percent))), 1)
        try:
            await self._exec(
                """
                UPDATE twitch_vod_pulls
                SET progress_percent = %s, bytes_sent = %s, bytes_total = %s, phase = %s, updated_at = NOW()
                WHERE user_id = %s AND twitch_video_id = %s AND owner_pid = %s
                """,
                (pct, int(sent), int(total), phase, self.user_id, self.vod_id, self.pid),
            )
        except Exception as e:
            logger.warning(f"Could not store pull progress for {self.vod_id}: {e}")

    async def finish(self, status, error=None):
        size = os.path.getsize(self.dest) if status == "stored" and os.path.isfile(self.dest) else 0
        sql = (
            "UPDATE twitch_vod_pulls SET status = %s, error_message = %s, updated_at = NOW()"
            + (", progress_percent = 100, bytes_sent = %s, bytes_total = %s" if status == "stored" else "")
            + " WHERE user_id = %s AND twitch_video_id = %s AND owner_pid = %s"
        )
        params = [status, (error or None) and str(error)[:500]]
        if status == "stored":
            params += [size, size]
        params += [self.user_id, self.vod_id, self.pid]
        await self._exec(sql, tuple(params))

    async def storage_full(self):
        async with self.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "SELECT quota_bytes, bonus_bytes FROM stream_storage_slots WHERE user_id = %s LIMIT 1",
                    (self.user_id,),
                )
                row = await cur.fetchone()
        if not row:
            quota = STREAM_STORAGE_QUOTA_BYTES
        else:
            quota = STREAM_STORAGE_QUOTA_BYTES if row[0] is None else int(row[0])
            if quota > 0:
                quota += int(row[1] or 0)
        if quota == 0:
            return False
        used = 0
        user_dir = os.path.dirname(self.dest)
        for root, _dirs, files in os.walk(user_dir):
            for name in files:
                try:
                    used += os.path.getsize(os.path.join(root, name))
                except OSError:
                    continue
        # Segments already on disk for this VOD are part of the pull being resumed.
        work = segments_dir(self.dest)
        if os.path.isdir(work):
            for name in os.listdir(work):
                try:
                    used -= os.path.getsize(os.path.join(work, name))
                except OSError:
                    continue
        return used >= quota

    async def twitch_oauth(self):
        async with self.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute("SELECT access_token FROM users WHERE id = %s LIMIT 1", (self.user_id,))
                row = await cur.fetchone()
        return (row[0] or "").strip() if row else ""


def parse_media_playlist(text, playlist_url):
    """Map every remote segment / init map to a local name and build a local playlist."""
    entries = []
    out = []
    total_s = 0.0
    seg_index = 0
    map_index = 0
    for line in text.splitlines():
        s = line.strip()
        if not s:
            continue
        if s.startswith("#EXT-X-KEY") and "METHOD=NONE" not in s.upper():
            raise ValueError("encrypted_playlist")
        if s.startswith("#EXT-X-MAP"):
            m = re.search(r'URI="([^"]+)"', s)
            if m:
                url = urljoin(playlist_url, m.group(1))
                ext = os.path.splitext(urlparse(url).path)[1] or ".mp4"
                local = f"map{map_index:04d}{ext}"
                map_index += 1
                entries.append((url, local))
                s = s.replace(m.group(0), f'URI="{local}"')
        elif s.startswith("#EXTINF:"):
            try:
                total_s += float(s[8:].split(",", 1)[0])
            except ValueError:
                pass
        elif not s.startswith("#"):
            url = urljoin(playlist_url, s)
            ext = os.path.splitext(urlparse(url).path)[1] or ".ts"
            local = f"{seg_index:06d}{ext}"
            seg_index += 1
            entries.append((url, local))
            s = local
        out.append(s)
    if not entries:
        raise ValueError("empty_playlist")
    # A snapshot of a still-live archive must not make ffmpeg wait for more segments.
    if "#EXT-X-ENDLIST" not in out:
        out.append("#EXT-X-ENDLIST")
    return entries, "\n".join(out) + "\n", total_s


async def _fetch_segment(session, url, path):
    tmp = path + ".tmp"
    candidates = [url]
    if "-unmuted." in url:
        candidates.append(url.replace("-unmuted.", "-muted."))
    last_err = None
    for attempt in range(SEGMENT_RETRIES):
        for candidate in candidates:
            try:
                async with session.get(candidate) as resp:
                    if resp.status == 403 and candidate != candidates[-1]:
                        continue
                    if resp.status != 200:
                        last_err = f"http_{resp.status}"
                        continue
                    written = 0
                    with open(tmp, "wb") as fh:
                        async for chunk in resp.content.iter_chunked(1 << 20):
                            fh.write(chunk)
                            written += len(chunk)
                os.replace(tmp, path)
                return written
            except (aiohttp.ClientError, asyncio.TimeoutError, OSError) as e:
                last_err = f"{type(e).__name__}:{e}"[:160]
        await asyncio.sleep(min(10, 1.5 * (attempt + 1)))
    try:
        os.remove(tmp)
    except OSError:
        pass
    raise RuntimeError(f"segment_failed:{last_err}")


async def download_hls(session, playlist_url, dest, on_progress):
    """Download every segment in parallel, then remux the local playlist to dest."""
    work = segments_dir(dest)
    os.makedirs(work, exist_ok=True)
    async with session.get(playlist_url) as resp:
        if resp.status != 200:
            raise RuntimeError(f"playlist_http_{resp.status}")
        text = await resp.text()
    entries, local_playlist, total_s = parse_media_playlist(text, playlist_url)
    playlist_path = os.path.join(work, "local.m3u8")
    with open(playlist_path, "w", encoding="utf-8") as fh:
        fh.write(local_playlist)

    total = len(entries)
    state = {"done": 0, "bytes": 0}
    queue = asyncio.Queue()
    for url, local in entries:
        path = os.path.join(work, local)
        if os.path.isfile(path):
            state["done"] += 1
            state["bytes"] += os.path.getsize(path)
        else:
            queue.put_nowait((url, path))
    if state["done"]:
        logger.info(f"Resuming with {state['done']}/{total} segments already on disk")

    async def report(force=False):
        done = state["done"]
        est_total = int(state["bytes"] / done * total) if done else 0
        await on_progress(DOWNLOAD_SHARE * done / total, state["bytes"], est_total, force)

    async def worker():
        while True:
            try:
                url, path = queue.get_nowait()
            except asyncio.QueueEmpty:
                return
            written = await _fetch_segment(session, url, path)
            state["bytes"] += written
            state["done"] += 1
            await report()

    started = time.monotonic()
    tasks = [asyncio.create_task(worker()) for _ in range(min(PULL_CONCURRENCY, max(1, queue.qsize())))]
    try:
        await asyncio.gather(*tasks)
    except BaseException:
        for t in tasks:
            t.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)
        raise
    elapsed = max(0.001, time.monotonic() - started)
    logger.info(f"Downloaded {total} segments ({state['bytes'] / 1e9:.2f} GB) in {elapsed:.0f}s")
    await report(force=True)

    part = dest + ".part"
    log_path = pull_ffmpeg_log_path(dest)
    await on_progress(DOWNLOAD_SHARE, 0, state["bytes"], True, phase="saving")
    cmd = [
        "ffmpeg", "-y", "-hide_banner", "-loglevel", "error", "-nostats",
        "-progress", "pipe:1",
        "-allowed_extensions", "ALL",
        "-i", playlist_path,
        "-c", "copy",
        "-bsf:a", "aac_adtstoasc",
        # No +faststart: it rewrites the whole file a second time (doubles remux time on
        # multi-GB VODs) and nothing here streams these inline; downloads/YouTube/S3 don't need it.
        "-f", "mp4",
        part,
    ]
    with open(log_path, "ab") as log_fh:
        proc = await asyncio.create_subprocess_exec(
            *cmd, stdin=asyncio.subprocess.DEVNULL, stdout=asyncio.subprocess.PIPE, stderr=log_fh,
        )
        try:
            while True:
                line = await proc.stdout.readline()
                if not line:
                    break
                key, _, value = line.decode("utf-8", "replace").strip().partition("=")
                if key in ("out_time_us", "out_time_ms") and total_s > 0:
                    try:
                        frac = min(1.0, int(value) / 1e6 / total_s)
                    except ValueError:
                        continue
                    # Saving phase: report bytes written to the MP4 so the dashboard
                    # shows real progress/speed instead of a frozen download total.
                    try:
                        written = os.path.getsize(part)
                    except OSError:
                        written = 0
                    await on_progress(
                        DOWNLOAD_SHARE + (100.0 - DOWNLOAD_SHARE) * frac, written, state["bytes"], False,
                        phase="saving",
                    )
            rc = await proc.wait()
        except BaseException:
            if proc.returncode is None:
                proc.kill()
                await proc.wait()
            raise
    if rc != 0 or not os.path.isfile(part):
        try:
            with open(log_path, "rb") as fh:
                tail = fh.read()[-600:].decode("utf-8", "replace").strip().splitlines()
        except OSError:
            tail = []
        raise RuntimeError("remux_failed:" + (" | ".join(tail[-3:]) or f"exit_{rc}"))
    os.replace(part, dest)
    shutil.rmtree(work, ignore_errors=True)
    try:
        os.remove(log_path)
    except OSError:
        pass


async def run_worker(user_id, username, vod_id, dest, title):
    if not _safe_username(username) or not _safe_vod_id(vod_id):
        logger.error("Refusing unsafe username / VOD id")
        return 2
    if os.path.basename(dest) != f"twitch-{vod_id}.mp4" or os.path.basename(os.path.dirname(dest)) != username:
        logger.error(f"Refusing unexpected destination {dest}")
        return 2
    if not DB_HOST or not DB_USER or not DB_PASS:
        logger.error("Missing SQL_HOST / SQL_USER / SQL_PASSWORD")
        return 2
    pool = await aiomysql.create_pool(
        host=DB_HOST, user=DB_USER, password=DB_PASS, db=DB_NAME, autocommit=True, maxsize=4,
    )
    job = PullWorker(pool, user_id, username, vod_id, dest, title)
    beat = None
    try:
        if not await job.claim():
            logger.info(f"VOD {vod_id} for {username} is already being pulled; exiting")
            return 0
        logger.info(f"Pulling Twitch VOD {vod_id} for {username} -> {dest} (concurrency {PULL_CONCURRENCY})")
        beat = asyncio.create_task(job.heartbeat())
        os.makedirs(os.path.dirname(dest), exist_ok=True)
        if job.title:
            update_media_meta(dest, vod_id=job.vod_id, title=job.title)
        if os.path.isfile(dest) and not os.path.isdir(segments_dir(dest)):
            if read_media_meta(dest).get("duration_seconds") is None:
                await asyncio.to_thread(cache_duration, dest, job.vod_id)
            await job.finish("stored")
            logger.info(f"VOD {vod_id} already on disk")
            return 0
        if await job.storage_full():
            await job.finish("failed", "stream_storage_full")
            logger.warning(f"{username} stream storage is full; not pulling {vod_id}")
            return 0
        oauth = await job.twitch_oauth()
        timeout = aiohttp.ClientTimeout(total=None, sock_connect=20, sock_read=60)
        connector = aiohttp.TCPConnector(limit=PULL_CONCURRENCY, limit_per_host=PULL_CONCURRENCY)
        async with aiohttp.ClientSession(timeout=timeout, connector=connector, headers={"User-Agent": USER_AGENT}) as session:
            hls_url, hls_err = await twitch_vod_hls_url(session, vod_id, oauth)
            if hls_err or not hls_url:
                await job.finish("failed", f"twitch_hls:{hls_err}")
                logger.error(f"Could not resolve HLS for VOD {vod_id}: {hls_err}")
                return 0
            await download_hls(session, hls_url, dest, job.progress)
        duration = await asyncio.to_thread(cache_duration, dest, job.vod_id, job.title)
        await job.finish("stored")
        logger.info(f"Stored VOD {vod_id} for {username} ({os.path.getsize(dest) / 1e9:.2f} GB, {duration}s)")
        return 0
    except LostClaim:
        return 0
    except Exception as e:
        err = str(e) or type(e).__name__
        logger.error(f"Pull failed for VOD {vod_id} ({username}): {err}")
        shutil.rmtree(segments_dir(dest), ignore_errors=True)
        for leftover in (dest + ".part",):
            try:
                os.remove(leftover)
            except OSError:
                pass
        try:
            await job.finish("failed", err)
        except Exception:
            pass
        return 1
    finally:
        if beat is not None:
            beat.cancel()
        pool.close()
        await pool.wait_closed()


def main():
    parser = argparse.ArgumentParser(description="Pull one Twitch VOD into stream storage")
    parser.add_argument("--user-id", type=int, required=True)
    parser.add_argument("--username", required=True)
    parser.add_argument("--vod-id", required=True)
    parser.add_argument("--dest", required=True)
    parser.add_argument("--title", default="")
    args = parser.parse_args()
    _setup_worker_logging()
    sys.exit(asyncio.run(run_worker(args.user_id, args.username, args.vod_id, os.path.abspath(args.dest), args.title)))


if __name__ == "__main__":
    main()
