"""Persist ffmpeg PIDs so a Python restart does not kill recordings or VOD pulls."""
from __future__ import annotations

import json
import os
import re
import signal
import time
import unicodedata
from typing import Any, Optional
from urllib.parse import quote

import subprocess


def atomic_write_json(path: str, data: Any) -> None:
    directory = os.path.dirname(path) or "."
    os.makedirs(directory, exist_ok=True)
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as handle:
        json.dump(data, handle, indent=2)
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(tmp, path)


def load_json(path: str, default: Any) -> Any:
    try:
        with open(path, encoding="utf-8") as handle:
            data = json.load(handle)
        return data if data is not None else default
    except (OSError, ValueError):
        return default


def pid_alive(pid: Optional[int]) -> bool:
    if not pid:
        return False
    try:
        os.kill(int(pid), 0)
        return True
    except OSError:
        return False


def proc_cmdline(pid: int) -> str:
    try:
        raw = open(f"/proc/{int(pid)}/cmdline", "rb").read()
    except OSError:
        return ""
    return raw.replace(b"\x00", b" ").decode("utf-8", "replace")


def sidecar_paths_for_media(media_path: str) -> list:
    base = media_path or ""
    if base.endswith(".part"):
        base = base[:-5]
    if base.lower().endswith(".mp4"):
        stem = base[:-4]
    else:
        stem = os.path.splitext(base)[0]
    return [
        stem + ".ffmpeg.log",
        stem + ".ytdlp.log",
        stem + ".json",
        stem + ".mp4.part",
    ]


def remove_media_and_sidecars(media_path: str) -> int:
    removed = 0
    seen = set()
    for path in [media_path, *sidecar_paths_for_media(media_path)]:
        if not path or path in seen:
            continue
        seen.add(path)
        try:
            if os.path.isfile(path):
                os.remove(path)
                removed += 1
        except OSError:
            continue
    return removed


def is_sidecar_name(filename: str) -> bool:
    name = filename or ""
    return name.endswith((".ffmpeg.log", ".ytdlp.log", ".fwd.log", ".json"))


def cmdline_has(pid: int, needle: str) -> bool:
    if not needle:
        return False
    cmd = proc_cmdline(pid)
    return needle in cmd


class AttachedProcess:
    """Stand-in for subprocess.Popen after we reattach to a surviving ffmpeg."""

    def __init__(self, pid: int):
        self.pid = int(pid)
        self.returncode = None

    def poll(self):
        if self.returncode is not None:
            return self.returncode
        if pid_alive(self.pid):
            return None
        self.returncode = 0
        return self.returncode

    def terminate(self):
        if pid_alive(self.pid):
            os.kill(self.pid, signal.SIGTERM)

    def kill(self):
        if pid_alive(self.pid):
            os.kill(self.pid, signal.SIGKILL)

    def wait(self, timeout=None):
        deadline = time.monotonic() + (timeout if timeout is not None else 1e9)
        while time.monotonic() < deadline:
            if self.poll() is not None:
                return self.returncode
            time.sleep(0.2)
        raise subprocess.TimeoutExpired("ffmpeg", timeout)

    def send_signal(self, sig):
        if pid_alive(self.pid):
            os.kill(self.pid, sig)


def sanitize_download_basename(name: str) -> str:
    text = unicodedata.normalize("NFC", name or "")
    text = re.sub(r'[\x00-\x1f\x7f<>:"/\\|?*]', "-", text)
    text = text.replace("\n", " ").replace("\r", " ").replace("\t", " ")
    text = re.sub(r"\s+", " ", text).strip().strip(".")
    if len(text) > 180:
        text = text[:180].rstrip(" .")
    return text or "video"


def vod_title_from_sidecar(media_path: str) -> str:
    if media_path.lower().endswith(".mp4"):
        sidecar = media_path[:-4] + ".json"
    else:
        sidecar = media_path + ".json"
    try:
        with open(sidecar, encoding="utf-8") as handle:
            meta = json.load(handle)
        if isinstance(meta, dict):
            return str(meta.get("title") or "").strip()
    except (OSError, ValueError):
        pass
    return ""


def download_mp4_name(media_path: str, fallback: str = "") -> str:
    title = vod_title_from_sidecar(media_path)
    base = sanitize_download_basename(title or fallback or os.path.basename(media_path))
    if base.lower().endswith(".mp4"):
        return base
    return base + ".mp4"


def content_disposition_attachment(download_name: str) -> str:
    ascii_name = (
        download_name.encode("ascii", "replace")
        .decode("ascii")
        .replace("?", "-")
        .replace('"', "")
    )
    encoded = quote(download_name.encode("utf-8"), safe="")
    return f"attachment; filename=\"{ascii_name}\"; filename*=UTF-8''{encoded}"
