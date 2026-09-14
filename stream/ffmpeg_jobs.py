"""Persist ffmpeg PIDs so a Python restart does not kill recordings or VOD pulls."""
from __future__ import annotations

import json
import os
import signal
import time
from typing import Any, Optional

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
