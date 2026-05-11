# Shazam (RapidAPI) Reference (BotOfTheSpecter)

Shazam powers the audio-fingerprint failover path of the `!song` command. When Spotify cannot report what is currently playing (offline device, ad break, no Spotify account linked, expired refresh token, etc.) **and** the streamer has a premium tier ≥1000, the bot records ~200 KB of stream audio, transcodes to mono PCM, base64-encodes it, and POSTs that to Shazam's `/songs/v2/detect` endpoint.

- **Env var:** `SHAZAM_API` (a RapidAPI key — subscribe to "Shazam by apidojo" to bind it)
- **Base URL:** `https://shazam.p.rapidapi.com`
- **Premium gating:** only invoked if streamer's premium tier is 1000, 2000, 3000, or 4000 (`./bot/bot.py:3634`)

---

## 1. Audio capture pipeline

End-to-end flow (stable bot — beta and v6 are functionally identical):

1. **`shazam_song_info()`** validates the Twitch GQL OAuth token (used by Streamlink to pull the stream). `./bot/bot.py:8298`
2. **`record_stream(outfile)`** opens the streamer's worst-quality HLS variant via Streamlink, reads ~200 KB into a `.acc` file. `./bot/bot.py:8415`
3. **`convert_to_raw_audio(in_file, out_file)`** shells out to:
   ```
   /usr/bin/ffmpeg -i in.acc -vn -ar 44100 -ac 1 -c:a pcm_s16le -f s16le out.raw
   ```
   `./bot/bot.py:8401`
4. Read the `.raw` file, `base64.b64encode()` it.
5. **`shazam_detect_song(songb64)`** POSTs the base64 string to `/songs/v2/detect`. `./bot/bot.py:8368`
6. On a hit, the response carries `{"track": {"title": "...", "subtitle": "..."}}`. `subtitle` = artist, `title` = track name. Caller wraps as `{"artist": ..., "song": ...}`.
7. **`delete_recorded_files()`** removes both `.acc` and `.raw` files. Global paths `stream_recording_file_global` / `raw_recording_file_global` ensure cleanup runs even if detection failed.

---

## 2. Authentication

- **Env var:** `SHAZAM_API`
- **Where loaded:**
  - `./bot/bot.py:75`, `./bot/beta.py:103`, `./bot/beta-v6.py:97`
  - Reloaded by `reload_env_vars()` at `./bot/bot.py:9871`, `./bot/beta.py:13923`, `./bot/beta-v6.py:11353`
- **Headers required:**

  ```
  X-RapidAPI-Key: <SHAZAM_API>
  X-RapidAPI-Host: shazam.p.rapidapi.com
  ```

  Both headers are required. RapidAPI's gateway uses `X-RapidAPI-Host` to route to the underlying provider.

---

## 3. Endpoint

### POST `/songs/v2/detect`

```
POST https://shazam.p.rapidapi.com/songs/v2/detect?timezone=Australia/Sydney&locale=en-US
Content-Type: text/plain
X-RapidAPI-Key: <SHAZAM_API>
X-RapidAPI-Host: shazam.p.rapidapi.com
```

**Query params:**
- `timezone` — IANA TZ string (bot sends `Australia/Sydney`; Shazam uses it to localise relative date fields). Any IANA TZ accepted.
- `locale` — locale string (bot sends `en-US`; drives genre name display strings).

**Body:** the **base64 encoding** of a raw PCM stream — signed-16-bit, little-endian, 44.1 kHz, mono. Sent as a literal base64 string with `Content-Type: text/plain`.

Audio constraints:
- **Mono only** — stereo doubles body size; the matcher ignores the second channel.
- **3–10 seconds of audio is enough.** The bot reads ~200 KB (~1 second of 16-bit 44.1 kHz mono after demuxing) — empirically enough for clean matches.
- The matcher is robust to stream chat overlays / TTS / background noise, but fails on heavily spoken content.

**Response (match):**
```json
{
  "matches": [
    { "id": "...", "offset": 8.46, "channel": null, "timeskew": -0.0001, "frequencyskew": 0.0006 }
  ],
  "timestamp": 1700000000000,
  "timezone": "Australia/Sydney",
  "tagid": "...",
  "track": {
    "layout": "5",
    "type": "MUSIC",
    "key": "...",
    "title": "Take On Me",
    "subtitle": "a-ha",
    "images": {
      "background": "https://...",
      "coverart": "https://...",
      "coverarthq": "https://..."
    },
    "hub": {
      "type": "APPLEMUSIC",
      "providers": [
        { "type": "SPOTIFY", "actions": [{ "name": "hub:spotify:searchdeeplink", "type": "uri", "uri": "spotify:search:..." }] }
      ]
    },
    "sections": [
      { "type": "SONG", "tabname": "Song", "metadata": [...] },
      { "type": "LYRICS", "text": ["..."], "footer": "...", "tabname": "Lyrics" }
    ],
    "url": "https://www.shazam.com/track/..."
  }
}
```

The bot only reads `track.title` and `track.subtitle`. Cover art, Apple Music / Spotify deep links, and lyrics are available in the response if a future feature needs them.

**Response (no match):** HTTP 200 OK with no `track` key — often `{"matches": [], "tagid": "..."}` or `{}`. The bot detects this with `if "track" in matches.keys()` (the variable name `matches` is the full parsed JSON object, not just the `matches` array).

**Callsites:**
- `./bot/bot.py:8371` (stable)
- `./bot/beta.py:11996` (beta)
- `./bot/beta-v6.py:9703` (v6)

All three are functionally identical; the only delta is how the MySQL update at the end is wrapped.

---

## 4. Rate limits

**RapidAPI `apidojo/shazam` plan tiers** (check RapidAPI dashboard — subject to change):

- **Basic (free):** ~500 requests / month, hard cap. After exceeded, every request returns HTTP 429 until the billing cycle resets.
- **Pro / Ultra / Mega:** higher monthly caps + per-second concurrency limits.

**The bot reads remaining quota from response headers on every successful detect call:**

- `x-ratelimit-requests-remaining` (integer string) — remaining calls in the current billing window.

When that header is present, the bot:
1. Writes the value to `/var/www/api/shazam.txt` (server path) for the dashboard quota widget.
2. Updates `api_counts` in the central `website` DB: `UPDATE api_counts SET count=%s WHERE type=%s` with `type='shazam'`.
3. If remaining count is `0`, returns: `"Sorry, no more requests for song info are available for the rest of the month. Requests reset each month on the 23rd."`

The "23rd of the month" date reflects the project's current RapidAPI billing cycle rollover date — not an API-published date. Update the user-facing string at `./bot/bot.py:8395` (and matching beta/v6 lines) if the plan changes.

---

## 5. Gotchas

- **`content-type: text/plain` is mandatory.** Sending `application/json` causes the RapidAPI gateway to return a 400 and `track` will be missing.
- **Body must be base64-encoded raw PCM bytes, not a JSON-wrapped string.** `base64.b64encode(songBytes)` returns `bytes`; aiohttp serialises that as the literal ASCII base64 — that is what Shazam expects.
- **No-match is silent.** HTTP 200 OK with no `track` field is the normal "couldn't identify" outcome. Do not retry — the match either exists in Shazam's index or it doesn't.
- **Twitch GQL token is a hard prerequisite.** `record_stream()` uses a Streamlink session authenticated with the `TWITCH_GQL` OAuth token. If that token has expired, every Shazam call short-circuits with `{"error": "Twitch GQL Token Expired"}` before audio is captured. The bot logs this in `api_logger`.
- **FFmpeg path is hard-coded** to `/usr/bin/ffmpeg`. On any non-server environment this path won't exist — this is a server-only feature.
- **Recording artefacts must be cleaned up.** Files live under `/home/botofthespecter/logs/songs/` (server path). `delete_recorded_files()` is invoked from inside `song_command` after a Shazam call. If you add a new code path that calls `shazam_song_info()`, call `delete_recorded_files()` in the `finally` branch — leaving files around fills the disk quickly.
- **`x-ratelimit-requests-remaining` is sometimes absent** on cached / 429 responses. The bot guards with `if "x-ratelimit-requests-remaining" in response.headers:` — keep that guard. Updating `api_counts` to "0" because the header was missing would incorrectly flag the API as exhausted.
- **One key, three callsites.** Stable / beta / v6 each have their own copy of `shazam_detect_song`. Per [bot-versions.md](../../../rules/bot-versions.md), fixes to Shazam handling should land in **all three** unless the change is a v6-only refactor.
- **Endpoint path history.** `apidojo/shazam` has had multiple endpoint paths over the years (`/songs/detect`, `/songs/v2/detect`, etc.). The bot uses **`/songs/v2/detect`**. If RapidAPI deprecates this, the entire request shape (especially body encoding) may change — re-read the RapidAPI dashboard before porting.
- **Shazam is not cached.** Every `!song` Shazam-failover invocation burns one quota unit. Steam responses are cached; Shazam responses are not.

---

## 6. In-repo callsites

| Concern | File |
| ------- | ---- |
| `!song` command | `./bot/bot.py:3582–3653` |
| Shazam HTTP call (stable) | `./bot/bot.py:8368–8399` (`shazam_detect_song`) |
| Shazam orchestration (stable) | `./bot/bot.py:8298–8333` (`shazam_song_info`) |
| Shazam HTTP call (beta) | `./bot/beta.py:11994–12022` |
| Shazam HTTP call (v6) | `./bot/beta-v6.py:9701–9729` |
| Shazam orchestration (v6) | `./bot/beta-v6.py:9631–9666` |
| Audio conversion | `./bot/bot.py:8401–8413` (`convert_to_raw_audio`) |
| Stream capture | `./bot/bot.py:8415–8437` (`record_stream`) |
| Quota tracking | `./api/api.py` (`api_counts` table, `type='shazam'`) |
