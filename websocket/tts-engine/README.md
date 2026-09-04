# YourChat narrator — Piper on the websocket VM

Repo path: `./websocket/tts-engine/` (this folder). Deploy with the rest of `./websocket/` onto the websocket host (`/home/botofthespecter/tts-engine/`).

The chat page stays in `./yourchat/` and goes to **web1** only. Do not copy this folder to web1.

Headphones-only chat readback. Not overlay TTS. Not OpenAI.

Piper runs here as its own systemd unit. YourChat PHP on web1 checks the login and POSTs to `http://10.240.0.5:8094/speak`. The browser only plays the WAV.

Do **not** reverse-proxy port 8094 through Caddy / `websocket.botofthespecter.com`.

## Layout (websocket)

| Path | What |
| --- | --- |
| `/home/botofthespecter/tts-engine/server.py` | HTTP wrapper (this folder, deployed) |
| `/etc/systemd/system/yourchat-piper.service` | unit |
| `/var/lib/yourchat-piper/piper/piper` | binary (not in git) |
| `/var/lib/yourchat-piper/voices/*.onnx` | four English voices (not in git) |

## Install

```bash
# on websocket, from this directory
sudo bash install-piper.sh
sudo cp yourchat-piper.service /etc/systemd/system/
# add NARRATOR_HOP_SECRET=... to /home/botofthespecter/.env
sudo systemctl daemon-reload
sudo systemctl enable --now yourchat-piper
```

Same secret on web1 in `/var/www/config/yourchat.php` (`hop_secret`).

`OMP_NUM_THREADS=1` is set in the unit so a speak cannot fan out across cores.

## Check

```bash
# on websocket
curl -sS http://10.240.0.5:8094/health
# from web1
curl -sS -H "X-Narrator-Key: $SECRET" -H "Content-Type: application/json" \
  -d '{"text":"hello","voice":"en_US-lessac-medium","speed":1}' \
  -o /tmp/n.wav http://10.240.0.5:8094/speak
# must fail from the public internet:
curl -sS -o /dev/null -w "%{http_code}\n" https://websocket.botofthespecter.com:8094/health
```

## Tests (no Piper binary)

```bash
python3 test_server.py
```
