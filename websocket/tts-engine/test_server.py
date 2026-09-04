#!/usr/bin/env python3
"""Unit tests for the YourChat Piper wrapper (no real Piper binary)."""
from __future__ import annotations

import json
import tempfile
import threading
import time
import unittest
from http.client import HTTPConnection
from http.server import ThreadingHTTPServer
from pathlib import Path

import server as piper_server

MIN_WAV = (
    b"RIFF$\x00\x00\x00WAVEfmt "
    b"\x10\x00\x00\x00\x01\x00\x01\x00"
    b"\x22\x56\x00\x00\x44\xac\x00\x00"
    b"\x02\x00\x10\x00data\x00\x00\x00\x00"
)


def _write_fake_piper(dir_path: Path) -> Path:
    script = dir_path / "fake_piper.py"
    # Writes MIN_WAV to --output_file. Ignores stdin. Records argv for asserts.
    script.write_text(
        "#!/usr/bin/env python3\n"
        "import sys\n"
        "argv_path = sys.argv[0] + '.argv'\n"
        "open(argv_path, 'w', encoding='utf-8').write('\\n'.join(sys.argv[1:]))\n"
        "stdin_path = sys.argv[0] + '.stdin'\n"
        "open(stdin_path, 'wb').write(sys.stdin.buffer.read())\n"
        "out = None\n"
        "args = sys.argv[1:]\n"
        "for i, a in enumerate(args):\n"
        "    if a in ('--output_file', '-f') and i + 1 < len(args):\n"
        "        out = args[i + 1]\n"
        "        break\n"
        "if not out:\n"
        "    sys.exit(2)\n"
        "wav = ("
        "b'RIFF$\\x00\\x00\\x00WAVEfmt '"
        "b'\\x10\\x00\\x00\\x00\\x01\\x00\\x01\\x00'"
        "b'\\x22\\x56\\x00\\x00\\x44\\xac\\x00\\x00'"
        "b'\\x02\\x00\\x10\\x00data\\x00\\x00\\x00\\x00'"
        ")\n"
        "open(out, 'wb').write(wav)\n",
        encoding="utf-8",
    )
    return script


class LengthScaleTests(unittest.TestCase):
    def test_speed_one_is_unity(self):
        self.assertAlmostEqual(piper_server.length_scale_from_speed(1), 1.0)

    def test_faster_shortens_phonemes(self):
        self.assertAlmostEqual(piper_server.length_scale_from_speed(2), 0.5)

    def test_slower_lengthens_phonemes(self):
        self.assertAlmostEqual(piper_server.length_scale_from_speed(0.5), 2.0)

    def test_clamps_and_junk(self):
        self.assertAlmostEqual(piper_server.length_scale_from_speed(99), 0.5)
        self.assertAlmostEqual(piper_server.length_scale_from_speed(0.01), 2.0)
        self.assertAlmostEqual(piper_server.length_scale_from_speed("nope"), 1.0)


class VoiceAndTextTests(unittest.TestCase):
    def test_known_voice_kept(self):
        self.assertEqual(
            piper_server.resolve_voice("en_GB-alba-medium"),
            "en_GB-alba-medium",
        )

    def test_legacy_browser_name_becomes_default(self):
        self.assertEqual(
            piper_server.resolve_voice("Google US English"),
            piper_server.DEFAULT_VOICE,
        )

    def test_text_rules(self):
        self.assertIsNone(piper_server.clamp_text(""))
        self.assertIsNone(piper_server.clamp_text("   "))
        self.assertIsNone(piper_server.clamp_text("x" * 301))
        self.assertIsNone(piper_server.clamp_text(None))
        self.assertEqual(piper_server.clamp_text("  hi  "), "hi")


class HttpTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        voices = root / "voices"
        voices.mkdir()
        for name in piper_server.VOICES.values():
            (voices / name).write_bytes(b"onnx-placeholder")
        self.piper = _write_fake_piper(root)
        self.cfg = piper_server.PiperConfig(
            piper_bin=str(self.piper),
            voices_dir=str(voices),
            hop_secret="test-secret",
            timeout=5.0,
        )
        handler = piper_server.make_handler(self.cfg)
        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), handler)
        self.port = self.httpd.server_address[1]
        self.thread = threading.Thread(target=self.httpd.serve_forever, daemon=True)
        self.thread.start()
        time.sleep(0.05)

    def tearDown(self):
        self.httpd.shutdown()
        self.httpd.server_close()
        self.tmp.cleanup()

    def _conn(self) -> HTTPConnection:
        return HTTPConnection("127.0.0.1", self.port, timeout=5)

    def _speak(self, body, key="test-secret"):
        raw = json.dumps(body).encode("utf-8")
        headers = {"Content-Type": "application/json", "Content-Length": str(len(raw))}
        if key is not None:
            headers["X-Narrator-Key"] = key
        conn = self._conn()
        conn.request("POST", "/speak", body=raw, headers=headers)
        resp = conn.getresponse()
        data = resp.read()
        conn.close()
        return resp.status, resp.getheader("Content-Type"), data

    def test_health_no_secret(self):
        conn = self._conn()
        conn.request("GET", "/health")
        resp = conn.getresponse()
        body = json.loads(resp.read().decode("utf-8"))
        conn.close()
        self.assertEqual(resp.status, 200)
        self.assertTrue(body.get("ok"))

    def test_speak_requires_secret(self):
        status, _, data = self._speak({"text": "hello"}, key="wrong")
        self.assertEqual(status, 401)
        self.assertIn(b"unauthorized", data)

    def test_speak_returns_wav_and_uses_stdin(self):
        status, ctype, data = self._speak({"text": "hello chat", "voice": "en_US-lessac-medium", "speed": 1.2})
        self.assertEqual(status, 200)
        self.assertEqual(ctype, "audio/wav")
        self.assertTrue(data.startswith(b"RIFF"))
        stdin = Path(str(self.piper) + ".stdin").read_bytes()
        self.assertEqual(stdin, b"hello chat")
        argv = Path(str(self.piper) + ".argv").read_text(encoding="utf-8")
        self.assertNotIn("hello chat", argv)
        self.assertIn("--length-scale", argv)

    def test_overlong_text_rejected(self):
        status, _, _ = self._speak({"text": "x" * 301})
        self.assertEqual(status, 400)

    def test_unknown_voice_still_speaks(self):
        status, _, data = self._speak({"text": "hi", "voice": "Google US English"})
        self.assertEqual(status, 200)
        self.assertTrue(data.startswith(b"RIFF"))


if __name__ == "__main__":
    unittest.main()
