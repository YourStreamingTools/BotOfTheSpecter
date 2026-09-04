# YourChat narrator — browser-proof TTS

**Spec:** `.grok/specs/2026-09-01-yourchat-narrator-tts-design.md`  
**Goal:** Streamers turn Narrator on and hear chat in Firefox/Safari/Edge/Chrome on Windows, Mac, or Linux, with nothing to install. Piper runs on the **websocket** VM; YourChat PHP on web1 only checks the session and plays the WAV.

**Approach in one paragraph:** install Piper and four English voices on websocket as a private systemd service (`10.240.0.5` only), add `?action=narrate` on YourChat that proxies to it, and replace the Web Speech speak/queue/Test/voice-list code with `fetch` + `Audio`. Skip lists stay in the page. No OpenAI, no in-tab WASM pack, no overlay `TTS` event, not on web1/bots/sql/api.

**Touch points:** `yourchat/index.php` and `./config/yourchat.php` (web1); Piper service under `websocket/tts-engine/` (websocket host). Overlay, bots, dashboard TTS, `ai.php`, Socket.IO server — do not fold Piper into `websocket/server.py`.

## Constraints

- **Users install nothing.** No OS voices, no speech-dispatcher, no 70 MB browser download.
- **$0 speech API.** Piper on the websocket VM only (`10.240.0.5`). Not web1, bots, sql, or api.
- **Headphones only.** Do not write `/var/www/tts`, do not emit WS `TTS`.
- **PHP never reads `.env`.** Paths live in `./config/yourchat.php` → production `/var/www/config/yourchat.php`.
- **No shell interpolation of chat text.** Piper argv + stdin on websocket; web1 only HTTP-proxies.
- **Do not log spoken text.**
- **Do not commit Piper binaries or `.onnx` files.**
- **Leave git to the user.**

## File map

| Area | Path |
| --- | --- |
| Speak action + markup + narrator JS | `yourchat/index.php` (web1) |
| Piper URL + hop secret | `config/yourchat.php` (repo stub) and `/var/www/config/yourchat.php` (live on web1) |
| Piper service | `websocket/tts-engine/` (install script, systemd unit, HTTP wrapper) |
| Ignore blobs | `.gitignore` |

Voices and the Piper binary live on **websocket**, outside any web root (e.g. `/var/lib/yourchat-piper/`). Bind the HTTP wrapper to `10.240.0.5` only. Browsers must not be able to fetch models or hit Piper.

## Work items

### 1. Operator install (websocket VM)

`websocket/tts-engine/install-piper.sh` (run on **websocket** once):

- Download the official Piper **linux x86_64** release to `/var/lib/yourchat-piper`.
- Pull four MIT voice pairs from `rhasspy/piper-voices`: `en_US-lessac-medium` (default), `en_US-hfc_female-medium`, `en_US-hfc_male-medium`, `en_GB-alba-medium`.
- Rerunnable; checksum skip if already present.
- systemd unit: tiny HTTP wrapper, `OMP_NUM_THREADS=1`, listen `10.240.0.5` only (not public 443). One in-flight synthesis. Shared secret header matching `yourchat.php`.
- Smoke test from **web1**: POST a short string to `http://10.240.0.5:<port>`, expect WAV.

Repo `config/yourchat.php` stub: private base URL (`http://10.240.0.5:<port>`), hop secret, default voice id. Empty/placeholder in git; live values only on web1.

### 2. `?action=narrate`

POST JSON `{ text, voice, speed }`, session required.

- 401 if not logged in.
- 400 if text empty or longer than 300 characters.
- Voice allow-list = the four ids; anything else → Lessac.
- Speed 0.5–2.0 → Piper `--length-scale` of `1/speed`.
- flock `/tmp/yourchat-narrate-{digits}.lock` (user id sanitised to digits). Busy → 429.
- 30 successes / user / minute → 429.
- curl POST to the Piper service on `10.240.0.5` with the hop secret. Time out ~10s. Do not `proc_open` on web1.
- 200 + `audio/wav` on success. 503 if the hop fails or Piper is down. Close curl handles.
- Load config via `require` of `/var/www/config/yourchat.php` (same pattern as other PHP).

### 3. Narrator card

- Voice `<select>`: the four labels, Lessac default. Not `getVoices()`.
- Remove the pitch row.
- Copy: chat is read in this tab; works in Firefox, Safari, and Edge; Test (or the toggle) unlocks sound if the tab was muted. No mention of downloads, installs, or Linux packages.

### 4. Replace the speak path

Keep skip/allow/regex, emote wording, queue of 3, settings persistence.

- Delete Web Speech: `narratorSupported` must not disable the toggle; strip every `speechSynthesis` / `SpeechSynthesisUtterance` (including the first-gesture unlock). Keep the ding `AudioContext` unlock.
- Queue worker: `POST ?action=narrate` with live voice/rate; play the blob on an `Audio` at `narratorVolume`; revoke URLs; `stopNarration` pauses and clears. Live 429/502/503 skip the line (no toast). Test toasts on error.
- Unknown saved `narrator_voice` → Lessac in the select; persist on the next normal save.
- Enable toggle is the autoplay gesture.

### 5. CSS

Only if the card needs a one-line status. Existing narrator styles in `yourchat/style.css`. No new file.

## Verification

- **Firefox on Linux** with a normal desktop (no speech-dispatcher work): enable, Test, live line. Same on Windows Firefox and Safari.
- Streamer machine: no new packages, no 60 MB download in the network panel after page load (only the small `narrate` POSTs).
- DevTools: `narrate` is `audio/wav`. No OpenAI, no `tts.botofthespecter.com`, no `speechSynthesis`.
- Overlay TTS does not speak the line.
- `"Google US English"` in settings shows Lessac.
- Logged-out POST 401; 400-character body 400.
- Host with Piper not installed: Test says unavailable, no Web Speech fallback.

## Deploy

1. Run `install-piper.sh` on **websocket**; enable the systemd unit; confirm it is not reachable on the public websocket hostname.
2. Put live URL + hop secret in `/var/www/config/yourchat.php` on **web1**.
3. Pull `index.php` (and CSS if changed). php-fpm restart if opcache still holds PHP.
4. No Caddy change on either host (Piper is not a public vhost).

## Later, not this pass

- Extra languages
- Overlay/OBS readback (different product, would be on-stream)
- Wiring dashboard `tts_settings` (that is the paid on-stream voice)
