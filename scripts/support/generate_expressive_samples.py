# Generate Express TTS voice samples into ./cdn/help/tts/expressive/
# Requires ELEVENLABS_API_KEY in the environment. Does not print the key.
import json
import os
import re
import sys
import urllib.error
import urllib.request

VOICES_URL = "https://api.elevenlabs.io/v1/voices"
TTS_URL = "https://api.elevenlabs.io/v1/text-to-speech/{voice_id}?output_format=mp3_44100_128"
MODEL_ID = "eleven_v3"
SAMPLE_TEXT = "[cheerfully] Hello I'm Bot Of The Specter, this is the TTS voice you'd hear if you select me"
REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUT_DIR = os.path.join(REPO_ROOT, "cdn", "help", "tts", "expressive")
SLUG_RE = re.compile(r"[^a-z0-9]+")


def slugify(name):
    slug = SLUG_RE.sub("-", (name or "").strip().lower()).strip("-")
    return slug or "voice"


def api_key():
    return (os.getenv("ELEVENLABS_API_KEY") or "").strip()


def request_json(url, key, data=None):
    headers = {"xi-api-key": key, "Accept": "application/json"}
    body = None
    if data is not None:
        headers["Content-Type"] = "application/json"
        body = json.dumps(data).encode("utf-8")
    req = urllib.request.Request(url, data=body, headers=headers, method="POST" if body else "GET")
    with urllib.request.urlopen(req, timeout=60) as resp:
        return json.loads(resp.read().decode("utf-8"))


def request_bytes(url, key, data):
    headers = {
        "xi-api-key": key,
        "Accept": "audio/mpeg",
        "Content-Type": "application/json",
    }
    req = urllib.request.Request(url, data=json.dumps(data).encode("utf-8"), headers=headers, method="POST")
    with urllib.request.urlopen(req, timeout=60) as resp:
        return resp.read()


def main():
    key = api_key()
    if not key:
        print("ELEVENLABS_API_KEY is not set", file=sys.stderr)
        return 1
    os.makedirs(OUT_DIR, exist_ok=True)
    payload = request_json(VOICES_URL, key)
    voices = payload.get("voices") or []
    catalog = []
    used = {}
    for voice in voices:
        voice_id = (voice.get("voice_id") or "").strip()
        name = (voice.get("name") or "").strip()
        if not voice_id or not name:
            continue
        slug = slugify(name)
        if slug in used:
            slug = f"{slug}-{voice_id[:6].lower()}"
        used[slug] = True
        filename = f"{slug}_sample.mp3"
        dest = os.path.join(OUT_DIR, filename)
        print(f"Generating {name} -> {filename}")
        audio = request_bytes(
            TTS_URL.format(voice_id=voice_id),
            key,
            {"text": SAMPLE_TEXT, "model_id": MODEL_ID},
        )
        with open(dest, "wb") as handle:
            handle.write(audio)
        catalog.append({"slug": slug, "name": name, "file": filename})
    catalog.sort(key=lambda row: row["name"].lower())
    with open(os.path.join(OUT_DIR, "voices.json"), "w", encoding="utf-8") as handle:
        json.dump(catalog, handle, indent=2)
        handle.write("\n")
    print(f"Wrote {len(catalog)} samples to {OUT_DIR}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except urllib.error.HTTPError as exc:
        print(f"ElevenLabs HTTP {exc.code}", file=sys.stderr)
        raise SystemExit(1)
