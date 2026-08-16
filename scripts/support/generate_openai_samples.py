# Generate Normal TTS voice samples into ./cdn/help/tts/
# Requires OPENAI_KEY in the environment. Does not print the key.
import json
import os
import sys
import urllib.error
import urllib.request

TTS_URL = "https://api.openai.com/v1/audio/speech"
MODEL_NAME = "gpt-4o-mini-tts"
SAMPLE_TEXT = "Hello I'm Bot Of The Specter, this is the TTS voice you'd hear if you select me"
VOICES = [
    "alloy", "ash", "ballad", "coral", "echo", "fable",
    "nova", "onyx", "sage", "shimmer", "verse",
]
REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUT_DIR = os.path.join(REPO_ROOT, "cdn", "help", "tts")


def api_key():
    return (os.getenv("OPENAI_KEY") or "").strip()


def request_bytes(url, key, data):
    headers = {
        "Authorization": f"Bearer {key}",
        "Content-Type": "application/json",
    }
    req = urllib.request.Request(url, data=json.dumps(data).encode("utf-8"), headers=headers, method="POST")
    with urllib.request.urlopen(req, timeout=60) as resp:
        return resp.read()


def main():
    key = api_key()
    if not key:
        print("OPENAI_KEY is not set", file=sys.stderr)
        return 1
    os.makedirs(OUT_DIR, exist_ok=True)
    for voice in VOICES:
        filename = f"{voice}_sample.mp3"
        dest = os.path.join(OUT_DIR, filename)
        print(f"Generating {voice} -> {filename}")
        audio = request_bytes(
            TTS_URL,
            key,
            {
                "model": MODEL_NAME,
                "voice": voice,
                "input": SAMPLE_TEXT,
                "response_format": "mp3",
            },
        )
        with open(dest, "wb") as handle:
            handle.write(audio)
    print(f"Wrote {len(VOICES)} samples to {OUT_DIR}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except urllib.error.HTTPError as exc:
        print(f"OpenAI HTTP {exc.code}", file=sys.stderr)
        raise SystemExit(1)
