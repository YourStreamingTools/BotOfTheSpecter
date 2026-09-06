#!/usr/bin/env python3
"""Private Piper HTTP wrapper for YourChat narrator.

Listens on the websocket VM private address only. YourChat PHP on web1
proxies here. Not part of Socket.IO and not on the public websocket vhost.
"""
from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any, Optional
from urllib.parse import urlparse

VOICES = {
    "en_US-lessac-medium": "en_US-lessac-medium.onnx",
    "en_US-hfc_female-medium": "en_US-hfc_female-medium.onnx",
    "en_US-hfc_male-medium": "en_US-hfc_male-medium.onnx",
    "en_GB-alba-medium": "en_GB-alba-medium.onnx",
}
DEFAULT_VOICE = "en_US-lessac-medium"
MAX_CHARS = 500
DEFAULT_BIND = "10.240.0.5"
DEFAULT_PORT = 8094

_synth_lock = threading.Lock()


def length_scale_from_speed(speed: Any, default: float = 1.0) -> float:
    try:
        rate = float(speed)
    except (TypeError, ValueError):
        rate = default
    if rate < 0.5:
        rate = 0.5
    if rate > 2.0:
        rate = 2.0
    return 1.0 / rate


def resolve_voice(voice_id: Any) -> str:
    if isinstance(voice_id, str) and voice_id in VOICES:
        return voice_id
    return DEFAULT_VOICE


def clamp_text(text: Any) -> Optional[str]:
    if not isinstance(text, str):
        return None
    trimmed = text.strip()
    if not trimmed or len(trimmed) > MAX_CHARS:
        return None
    return trimmed


class PiperConfig:
    def __init__(
        self,
        piper_bin: str,
        voices_dir: str,
        hop_secret: str,
        timeout: float = 10.0,
    ) -> None:
        self.piper_bin = piper_bin
        self.voices_dir = Path(voices_dir)
        self.hop_secret = hop_secret
        self.timeout = timeout


def config_from_env() -> PiperConfig:
    return PiperConfig(
        piper_bin=os.environ.get("NARRATOR_PIPER_BIN", "/var/lib/yourchat-piper/piper/piper"),
        voices_dir=os.environ.get("NARRATOR_VOICES_DIR", "/var/lib/yourchat-piper/voices"),
        hop_secret=os.environ.get("NARRATOR_HOP_SECRET", ""),
        timeout=float(os.environ.get("NARRATOR_TIMEOUT", "10")),
    )


def run_piper(cfg: PiperConfig, text: str, voice_id: str, speed: Any) -> bytes:
    model_name = VOICES[voice_id]
    model_path = cfg.voices_dir / model_name
    if not os.path.isfile(cfg.piper_bin):
        raise FileNotFoundError("piper binary missing")
    if not model_path.is_file():
        raise FileNotFoundError("voice model missing")
    scale = length_scale_from_speed(speed)
    fd, wav_path = tempfile.mkstemp(suffix=".wav", prefix="yourchat-piper-")
    os.close(fd)
    try:
        piper_cmd = [cfg.piper_bin]
        if cfg.piper_bin.endswith(".py"):
            piper_cmd = [sys.executable, cfg.piper_bin]
        cmd = piper_cmd + [
            "--model",
            str(model_path),
            "--output_file",
            wav_path,
            "--length-scale",
            f"{scale:.4f}",
        ]
        # Chat text on stdin only — never interpolated into the command.
        proc = subprocess.run(
            cmd,
            input=text.encode("utf-8"),
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
            timeout=cfg.timeout,
            check=False,
        )
        if proc.returncode != 0:
            raise RuntimeError("piper failed")
        data = Path(wav_path).read_bytes()
        if len(data) < 44 or data[:4] != b"RIFF":
            raise RuntimeError("piper produced no wav")
        return data
    finally:
        try:
            os.unlink(wav_path)
        except OSError:
            pass


def make_handler(cfg: PiperConfig):
    class Handler(BaseHTTPRequestHandler):
        server_version = "YourChatPiper/1.0"

        def log_message(self, fmt: str, *args: Any) -> None:
            # Path and status only — never the spoken text.
            sys_stderr = __import__("sys").stderr
            sys_stderr.write("%s - %s\n" % (self.address_string(), fmt % args))

        def _json(self, code: int, payload: dict) -> None:
            body = json.dumps(payload).encode("utf-8")
            self.send_response(code)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def _check_secret(self) -> bool:
            got = self.headers.get("X-Narrator-Key", "")
            return bool(cfg.hop_secret) and got == cfg.hop_secret

        def do_GET(self) -> None:
            path = urlparse(self.path).path
            if path == "/health":
                self._json(200, {"ok": True})
                return
            self._json(404, {"error": "not found"})

        def do_POST(self) -> None:
            path = urlparse(self.path).path
            if path != "/speak":
                self._json(404, {"error": "not found"})
                return
            if not self._check_secret():
                self._json(401, {"error": "unauthorized"})
                return
            try:
                length = int(self.headers.get("Content-Length", "0"))
            except ValueError:
                self._json(400, {"error": "bad content-length"})
                return
            if length < 0 or length > 8192:
                self._json(400, {"error": "bad body"})
                return
            raw = self.rfile.read(length) if length else b""
            try:
                payload = json.loads(raw.decode("utf-8") or "{}")
            except (UnicodeDecodeError, json.JSONDecodeError):
                self._json(400, {"error": "invalid json"})
                return
            if not isinstance(payload, dict):
                self._json(400, {"error": "invalid json"})
                return
            text = clamp_text(payload.get("text"))
            if text is None:
                self._json(400, {"error": "invalid text"})
                return
            voice = resolve_voice(payload.get("voice"))
            if not _synth_lock.acquire(blocking=False):
                self._json(429, {"error": "busy"})
                return
            started = time.monotonic()
            try:
                wav = run_piper(cfg, text, voice, payload.get("speed", 1.0))
            except FileNotFoundError:
                self._json(503, {"error": "piper unavailable"})
                return
            except subprocess.TimeoutExpired:
                self._json(504, {"error": "timeout"})
                return
            except Exception:
                self._json(502, {"error": "synthesis failed"})
                return
            finally:
                _synth_lock.release()
            elapsed_ms = int((time.monotonic() - started) * 1000)
            self.log_message(
                "speak voice=%s bytes=%s ms=%s",
                voice,
                len(wav),
                elapsed_ms,
            )
            self.send_response(200)
            self.send_header("Content-Type", "audio/wav")
            self.send_header("Content-Length", str(len(wav)))
            self.end_headers()
            self.wfile.write(wav)

    return Handler


def serve(cfg: Optional[PiperConfig] = None) -> None:
    cfg = cfg or config_from_env()
    bind = os.environ.get("NARRATOR_BIND", DEFAULT_BIND)
    port = int(os.environ.get("NARRATOR_PORT", str(DEFAULT_PORT)))
    if not cfg.hop_secret:
        raise SystemExit("NARRATOR_HOP_SECRET is required")
    httpd = ThreadingHTTPServer((bind, port), make_handler(cfg))
    print("yourchat-piper listening on %s:%s" % (bind, port), flush=True)
    httpd.serve_forever()


if __name__ == "__main__":
    serve()
