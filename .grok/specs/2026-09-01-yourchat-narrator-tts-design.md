# YourChat narrator — browser-proof TTS

**Date:** 2026-09-01 (revised 2026-09-02)  
**Scope:** YourChat operator window (`yourchat/index.php` + `./config/yourchat.php` on web1). Piper service in `websocket/tts-engine/` on the websocket host. Overlay TTS, WebSocket `TTS` events, OpenAI Speech, and anything the streamer must install stay out of scope.  
**Status:** Design.

## Problem

YourChat’s narrator uses the browser **Web Speech API**. Chrome cheats with a built-in Google voice pack. Firefox and Safari expose the same objects, then fail.

On **Linux** this is worse, not better. Firefox does not ship voices. It asks the OS. That means `speech-dispatcher`, espeak, and a working Pulse/PipeWire setup. A streamer who opened a website should never be told to `apt install` anything. Specter is for non-technical people. Windows-with-SAPI vs Linux-without-dispatcher is exactly the trap we are in today.

Two other ideas were considered and dropped:

- **`gpt-4o-mini-tts` on every chat line** — headphones-only is the right *playback* model, but it is a per-character bill at chat volume. Rejected.
- **Piper WASM in the tab** — no OS install, works on Linux, $0 API. It still makes the user wait on a ~70 MB “voice pack” download and puts a loading bar on a consumer feature. That is install-shaped. Rejected for this product.

## Goal

The streamer turns Narrator on and hears chat. **No install, no voice pack, no OS setup.** Windows, Mac, and Linux; Firefox, Safari, Edge, and Chrome; Chromebooks included. Skip lists and “say the name” stay as they are. Nothing is spoken on stream. **No cloud speech invoice.**

## Chosen approach: we run Piper, the browser only plays sound

Specter already works this way for overlay TTS (we generate audio, the client plays it). Narrator uses the same *shape*, a different *engine*, and a different *audience*.

```text
YourChat tab  --POST-->  PHP on web1  --private LAN-->  Piper service on websocket
       ^                   (session)     10.240.0.5         (systemd, not Socket.IO)
       `---------------- WAV bytes --------------------'
                    HTMLAudioElement
```

- The user installs **nothing**. They have a browser. That is the whole client requirement.
- Linux vs Windows is our problem (one Piper binary on the websocket VM), not theirs.
- First click does not download 60 MB of ONNX into the tab. The voices already live on the server.
- Cost is a short CPU burst on websocket, not OpenAI, not a sixth VM.

**Host choice (2026-09-02):** do **not** run Piper on web1 (1 vCPU, PHP/Caddy), bots (4 GB already ~73% used and swapping), api (1 GB), or sql (InnoDB RAM). Websocket sits at ~1% CPU, ~24% used RAM of 2 GB, **swap 0%**, ~1.4 GB free (cache will yield). Overlay OpenAI TTS on that box is HTTP-bound, not a local model. Piper is a **separate systemd unit**, not in-process with Socket.IO. Bind it to `10.240.0.5` only — not the public websocket hostname.

Overlay TTS stays on OpenAI/ElevenLabs and the WebSocket `TTS` event (rare, on-stream). Narrator must not call that path.

## Design

### What the streamer sees

Same Narrator card: toggle, voice, rate, volume, skip/allow lists, Test. No “loading voice pack”, no “your browser does not support speech”, no Linux help text.

Pitch goes away (Piper’s knob is speed). Old `narrator_pitch` in JSON is ignored.

Voice dropdown is four names we chose, identical on every machine. Default **Lessac (US)**. A saved Chrome name like “Google US English” becomes Lessac on the next load.

Turning the toggle on, or clicking Test, is the autoplay gesture. If a browser still mutes the tab, Test is the recovery — one sentence in the card, not a setup guide.

### What we keep in the page

Skip usernames, skip phrases, allow phrases, regex mode, “say the name”, emote wording, queue of 3 (drop newest). Volume on the `Audio` element. Rate 0.5–2.0 sent to Piper as `length_scale = 1 / rate`.

`narratorSupported()` must not disable the toggle. `speechSynthesis` is removed entirely. Never fall back to Web Speech, including on Linux.

### PHP action `narrate`

Session required, same gate as `save_settings`. POST JSON `{ text, voice, speed }`.

- Empty / non-string / over **300 characters** → 400.
- Unknown voice (including legacy OS names) → Lessac, do not 400.
- Speed clamped 0.5–2.0.
- One in-flight synthesis per user (lock). Busy → 429, client skips that line.
- Cap **30 successful clips per user per minute** so a broken tab cannot fork-bomb Piper.
- On success: `Content-Type: audio/wav` (or mpeg if we transcode) and the bytes. **No file** under `/var/www/tts`, no public URL, no WebSocket.
- Piper missing or failed → 503 JSON. Card shows a short “Narrator is unavailable” on Test only. Live chat stays quiet.
- Do not log the spoken text.

Web1 PHP does **not** `proc_open` Piper. It validates the session and body, then POSTs to the Piper service on `10.240.0.5` (timeout ~10 s) and streams the WAV back. A shared secret in `./config/yourchat.php` authenticates that hop so nothing on the public internet can hit Piper.

The websocket-side service runs Piper with argv + stdin, **no shell**, `OMP_NUM_THREADS=1`, one synthesis at a time. Never interpolate the chat line into a command string.

Config is `./config/yourchat.php` (production `/var/www/config/yourchat.php`). PHP does not read `.env`. Live values: Piper base URL on the private LAN, hop secret, default voice id. No OpenAI keys.

### Voices (ours, not the user’s)

| Id | Label |
| --- | --- |
| `en_US-lessac-medium` | Lessac (US, neutral) |
| `en_US-hfc_female-medium` | HFC Female (US) |
| `en_US-hfc_male-medium` | HFC Male (US) |
| `en_GB-alba-medium` | Alba (UK) |

MIT Piper voices from `rhasspy/piper-voices`. Stored on the **websocket** VM next to the binary, **not** in git, **not** under the YourChat docroot, **not** on the S3 mounts.

A one-off operator script installs the official Linux x86_64 Piper release plus those four voice pairs on websocket. Streamers never run it.

### Why websocket CPU is acceptable here

Measured idle: ~1% CPU, ~24% used RAM, swap 0. Piper medium on a short chat line is a fraction of a second to a couple of seconds of **one** core. The client already drops at queue 3. The service is one synthesis at a time. Socket.IO stays a different process so a speak cannot block the event loop in-process; they still share the VM’s CPU, which is why we keep `OMP_NUM_THREADS=1` and a short queue.

We do **not** push that load onto the streamer’s laptop, and we do **not** ask a Linux user to make their Firefox talk to speech-dispatcher.

### What we will not do

- Ask the user to install Piper, espeak, speech-dispatcher, or a “voice pack”
- Web Speech API (including as a Linux/Windows fallback)
- OpenAI / ElevenLabs for narrator
- Piper WASM download in the tab
- COOP/COEP or a 70 MB first-load bar
- Speaking through overlay TTS / OBS
- Sharing dashboard `tts_settings` (that voice is the paid on-stream one)

## Risks

- **Shared core with Socket.IO** — a speak pins CPU briefly. Idle ~1% today; watch overlay event latency if narrator use grows. Do not raise thread count.
- **Piper not installed / service down** — 503, no silent Web Speech fallback.
- **Quality** is “good assistant”, not Alloy. Overlay keeps the expensive voices for on-stream TTS.
- **Autoplay** — enable/Test gesture; one line of copy, not a sysadmin guide.
- **Piper bound to public :443 by mistake** — must listen on `10.240.0.5` only.

## How we will know it is right

- Firefox on **Linux** (no extra packages on the laptop): enable Narrator, Test, hear the sample, hear a live chat line. Same on Windows Firefox and Safari.
- The streamer never downloaded a model and never installed a voice.
- DevTools: `narrate` returns audio. No `speechSynthesis`, no OpenAI speech URL, no `tts.botofthespecter.com`.
- OBS overlay TTS does not play the line.
- `"narrator_voice": "Google US English"` in settings shows Lessac.
- Logged-out POST is 401. Over-long text is 400.
