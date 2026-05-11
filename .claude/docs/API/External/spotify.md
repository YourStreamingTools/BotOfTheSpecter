# Spotify Web API — BotOfTheSpecter Reference

Self-contained reference for how this project integrates with the Spotify Web API. Covers OAuth, the endpoints actually called, the token-refresh job, and the failure modes the bot maps to chat-friendly messages.

Official docs:
- API root: https://developer.spotify.com/documentation/web-api
- Auth: https://developer.spotify.com/documentation/web-api/concepts/authorization
- Currently playing: https://developer.spotify.com/documentation/web-api/reference/get-the-users-currently-playing-track
- Recently played: https://developer.spotify.com/documentation/web-api/reference/get-recently-played
- Rate limits: https://developer.spotify.com/documentation/web-api/concepts/rate-limits

---

## 1. Overview

BotOfTheSpecter uses the Spotify Web API to power three streamer-facing features:

1. `!song` — read the streamer's currently-playing track and announce it in chat (with Shazam failover when nothing is returned and the user has Premium tier).
2. `!songrequest` / `!sr` — search Spotify (or resolve a `track:` URI / spotify.link / open.spotify.com link, plus YouTube link via yt-dlp title extraction) and add to the streamer's playback queue.
3. `!skipsong` / `!skip` — skip to the next track on the streamer's active device.
4. `!songqueue` / `!sq` / `!queue` — read the live queue plus what's currently playing.

Token storage and refresh are decoupled from the bot process: a standalone script (`./bot/refresh_spotify_tokens.py`) walks every user with a stored refresh token and rotates the access token in MySQL.

The Spotify integration is **per-streamer**. Each user (`spotify_tokens.user_id` ↔ `users.id`) has their own access/refresh token pair. The bot reads the right token for the channel it is running in by `CHANNEL_NAME → users.id → spotify_tokens.access_token`.

### Two client-credentials modes

`spotify_tokens.own_client` is a flag that toggles per-user behaviour:

- `own_client = 0` — the user authorized via the platform's BotOfTheSpecter Spotify app (Development Mode, capped at 5 users since Spotify's March 9, 2026 policy change).
- `own_client = 1` — the user pasted their own `client_id` / `client_secret` into `./dashboard/spotifylink.php` and authorized through their own Spotify app. Required for new linkings now that the platform app is full.

The refresh script picks per-user creds when `own_client = 1` and the row has both fields, otherwise falls back to the global `SPOTIFY_CLIENT_ID` / `SPOTIFY_CLIENT_SECRET` env vars.

---

## 2. Authentication — Authorization Code Flow

This project uses the **Authorization Code** grant. **PKCE is not used.** The client secret is stored server-side (PHP config or env) so the standard server-side flow is appropriate.

### Credentials and where they live

| Where | Purpose | Names |
| ----- | ------- | ----- |
| Bot env (`/home/botofthespecter/.env`) | Used by `refresh_spotify_tokens.py` | `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET` |
| PHP config (`./config/spotify.php`, server: `/var/www/config/spotify.php`) | Used by the dashboard OAuth grant page | `$client_id`, `$client_secret`, `$redirect_uri` |
| Per-user override | Stored in MySQL when user enables "own client" | `spotify_tokens.client_id`, `spotify_tokens.client_secret` |

PHP must NEVER read `.env` (project rule, see `./.claude/rules/php-config.md`). The Spotify config in PHP lives at `./config/spotify.php`:

```php
$client_secret = '';                                                // server-side only
$client_id = '';                                                    // server-side only
$redirect_uri = 'https://dashboard.botofthespecter.com/spotifylink.php';
```

### Redirect URI

`https://dashboard.botofthespecter.com/spotifylink.php`

This must be registered exactly (scheme + host + path, no trailing slash variations) in the Spotify app's Developer Dashboard. When a user uses their own Spotify app (`own_client = 1`), they need to add this same redirect URI to their app's settings — the dashboard page is the redirect target regardless of which client_id is used.

### Scopes requested

```
user-read-playback-state user-modify-playback-state user-read-currently-playing
```

Set in `./dashboard/spotifylink.php` when constructing `$authURL`. These cover:

- `user-read-playback-state` — read active devices, queue (used by `!skipsong`, `!songqueue`).
- `user-modify-playback-state` — add to queue, skip (used by `!songrequest`, `!skipsong`).
- `user-read-currently-playing` — read the currently-playing track (used by `!song`, current-song lookup inside `!songqueue`).

`user-read-recently-played` is **not** requested — the bot does not use the Recently Played endpoint.

### Authorize URL (Step 1 — user grant)

Built in `./dashboard/spotifylink.php:183` and `:197`:

```
https://accounts.spotify.com/authorize
  ?response_type=code
  &client_id={CLIENT_ID}
  &scope=user-read-playback-state user-modify-playback-state user-read-currently-playing
  &redirect_uri={REDIRECT_URI}
```

The user clicks the "Link Spotify" button, grants permissions, Spotify redirects back with `?code=...`.

### Token exchange (Step 2 — code → tokens)

`./dashboard/spotifylink.php:69-118`. POST to `https://accounts.spotify.com/api/token` with form-encoded body:

```
grant_type=authorization_code
code={code from query string}
redirect_uri={same redirect_uri}
client_id={effective client_id}
client_secret={effective client_secret}
```

> Note: the dashboard sends client_id and client_secret in the **request body** rather than the Basic auth header. Both are accepted by Spotify for this grant. The refresh script (below) uses the Basic auth header form instead.

Successful response returns JSON: `access_token`, `refresh_token`, `expires_in` (seconds, typically 3600), `scope`, `token_type` (`Bearer`).

### Token storage — MySQL

**Database:** `website` (central, not per-user).
**Table:** `spotify_tokens`.

| Column | Purpose |
| ------ | ------- |
| `user_id` | FK to `website.users.id` |
| `access_token` | Current bearer token |
| `refresh_token` | Long-lived refresh token from initial grant |
| `auth` | `1` after successful token exchange, `0` if reset |
| `has_access` | `1` once the platform app has approved the user (legacy/manual gate) |
| `own_client` | `1` if user supplies their own Spotify app credentials |
| `client_id` | User's own Spotify app client ID (when `own_client = 1`) |
| `client_secret` | User's own Spotify app client secret (when `own_client = 1`) |

### Refresh cadence

The refresh job is invoked by `./bot/refresh_spotify_tokens.py`. It is triggered three ways:

1. **Admin dashboard "Refresh Tokens" SSE stream** at `./dashboard/admin/stream_command.php` (mapped key `'spotify' => 'refresh_spotify_tokens.py'`).
2. **Admin dashboard one-shot button** at `./dashboard/admin/index.php:473-493` — POSTs `refresh_spotify_tokens=1`, server runs `cd /home/botofthespecter && python3 refresh_spotify_tokens.py`.
3. **Cron / scheduled task on the bot host** (server-side, not in repo). Spotify access tokens expire every 3600 seconds, so this should run no less often than hourly; ~every 30–50 minutes is the practical window.

The bot processes (`bot.py`, `beta.py`, `beta-v6.py`) all carry a `next_spotify_refresh_time` timer (`./bot/bot.py:194`, `./bot/beta.py:258`, `./bot/beta-v6.py:237`) but they read tokens straight from MySQL on every Spotify call rather than caching — so as long as the standalone refresh script keeps the DB row fresh, the bot will always pick up a valid token.

---

## 3. Endpoints used

All requests use:

```
Authorization: Bearer {access_token from spotify_tokens.access_token}
```

The bot uses `aiohttp.ClientSession` (aliased `httpClientSession`). The dashboard uses PHP `file_get_contents` with stream context. Base URL is always `https://api.spotify.com/v1`.

### 3.1 GET /v1/me/player/currently-playing

**Purpose:** `!song` reads what's playing now.

**Method/URL:** `GET https://api.spotify.com/v1/me/player/currently-playing`

**Required scope:** `user-read-currently-playing`

**Headers:**
```
Authorization: Bearer {access_token}
```

**Query params (none used by the bot):**
- `market` — ISO 3166-1 alpha-2 country code. Not sent. Spotify falls back to the user's account country.
- `additional_types` — `track,episode`. Not sent. Bot only handles tracks.

**Response shape (200) — fields the bot reads:**

```json
{
  "is_playing": true,
  "item": {
    "uri": "spotify:track:...",
    "name": "Track Name",
    "artists": [{ "name": "Artist 1" }, { "name": "Artist 2" }]
  }
}
```

**Status codes the bot handles:**
- `200` — parse `is_playing`, `item.uri`, `item.name`, `item.artists[].name`.
- `204` — no playback state available; treated as "nothing playing", no error to chat.
- Anything else — looked up in `SPOTIFY_ERROR_MESSAGES` (see §6).

**Callsites:**
- `./bot/bot.py:8258` — `get_spotify_current_song()`
- `./bot/beta.py:11884` — `get_spotify_current_song()`
- `./bot/beta-v6.py:9591` — `get_spotify_current_song()`
- `./bot/kick.py:1704` — Kick bot's lighter variant (returns formatted string only)
- Re-called inside `!songqueue` (e.g. `./bot/bot.py:3945`) to print "Now Playing" alongside the queue.
- Re-called inside `!songrequest` to confirm what got added (`./bot/beta.py:5827`).

### 3.2 GET /v1/tracks/{id}

**Purpose:** Resolve a Spotify track link / URI to a track object so the bot can extract `name`, `artists`, and the canonical `uri`.

**Method/URL:** `GET https://api.spotify.com/v1/tracks/{track_id}`

**Required scope:** None (public endpoint, but bearer token still required).

**Headers:**
```
Authorization: Bearer {access_token}
```

**Used when** the message matches one of:
- `https://open.spotify.com/track/{id}`
- `https://open.spotify.com/intl-{xx}/track/{id}`
- `https://spotify.link/{id}` (short link — note: this may not always resolve as a track ID via this endpoint; treat short links as best-effort)
- `spotify:track:{id}`

**Response shape — fields the bot reads:**
```json
{
  "uri": "spotify:track:...",
  "name": "...",
  "artists": [{ "name": "..." }, ...]
}
```

The bot then filters out `name` or `artist_name` containing `instrumental` or `karaoke version`.

**Callsites:**
- `./bot/bot.py:3771` (inside `songrequest_command`)
- `./bot/beta.py:5729`
- `./bot/beta-v6.py:4806`

### 3.3 GET /v1/search

**Purpose:** Used by `!songrequest` when the input is plain text (or a YouTube link whose title was extracted via yt-dlp) — find the best matching track.

**Method/URL:** `GET https://api.spotify.com/v1/search`

**Query params used:**
- `q` — search string (URL-encoded; the bot does a simple `replace(" ", "%20")`).
- `type=track`
- `limit=1`

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response shape — fields the bot reads:**
```json
{
  "tracks": {
    "items": [
      {
        "uri": "spotify:track:...",
        "name": "...",
        "artists": [{ "name": "..." }]
      }
    ]
  }
}
```

If `tracks.items` is empty, the bot replies "No song found: {input}". Otherwise it takes `items[0]` and proceeds to the queue add.

**Callsites:**
- `./bot/bot.py:3793`
- `./bot/beta.py:5751`
- `./bot/beta-v6.py:4828`

### 3.4 POST /v1/me/player/queue

**Purpose:** Add a track to the streamer's Spotify playback queue.

**Method/URL:** `POST https://api.spotify.com/v1/me/player/queue?uri={spotify_uri}`

**Required scope:** `user-modify-playback-state`

**Headers:**
```
Authorization: Bearer {access_token}
```

**Body:** none — the URI goes in the query string.

**Response codes:**
- `200` — added (treated as success). Spotify also commonly returns `204`; the bot's success branch only checks `200`. If you ever see a track silently fail to queue but the user got a "queued" message, normalise to `if response.status in (200, 204)`.
- `403` — usually device restriction (no Premium / no active device).
- `404` — no active device.
- `429` — rate limited.

**Callsites:**
- `./bot/bot.py:3818`
- `./bot/beta.py:5776`
- `./bot/beta-v6.py:4853`

### 3.5 GET /v1/me/player/devices

**Purpose:** Find the active device ID before issuing a skip.

**Method/URL:** `GET https://api.spotify.com/v1/me/player/devices`

**Required scope:** `user-read-playback-state`

**Response shape — fields the bot reads:**
```json
{
  "devices": [
    { "id": "...", "is_active": true, "name": "...", "type": "...", "volume_percent": 80 }
  ]
}
```

The bot iterates `devices[]`, picks the first with `is_active: true`. If none active or list empty: chat reply "No active Spotify devices found."

**Callsites:**
- `./bot/bot.py:3861`
- `./bot/beta.py:5845`
- `./bot/beta-v6.py:4898`

### 3.6 POST /v1/me/player/next

**Purpose:** Skip to the next track.

**Method/URL:** `POST https://api.spotify.com/v1/me/player/next?device_id={device_id}`

**Required scope:** `user-modify-playback-state`

**Body:** none.

**Response codes:**
- `200` or `204` — both treated as success (`if response.status in (200, 204)`).
- Anything else — error message via `SPOTIFY_ERROR_MESSAGES`.

**Callsites:**
- `./bot/bot.py:3889`
- `./bot/beta.py:5873`
- `./bot/beta-v6.py:4926`
- `./bot/kick.py:1724` (no `device_id` query param — relies on Spotify's last-active heuristic).

### 3.7 GET /v1/me/player/queue

**Purpose:** Read the live playback queue (used by `!songqueue` and a 180-second background sweep that prunes finished song-request entries from the bot's `song_requests` dict).

**Method/URL:** `GET https://api.spotify.com/v1/me/player/queue`

**Required scope:** `user-read-playback-state`

**Response shape — fields the bot reads:**
```json
{
  "currently_playing": { ... },
  "queue": [
    { "uri": "...", "name": "...", "artists": [{ "name": "..." }] },
    ...
  ]
}
```

The bot truncates to the first 3 entries when reporting to chat, and adds "...and N more songs in the queue." if more exist.

**Callsites:**
- `./bot/bot.py:3936`, `./bot/bot.py:10170` (background sweep `check_song_requests`)
- `./bot/beta.py:5922`, `./bot/beta.py:14231`
- `./bot/beta-v6.py:4975`, `./bot/beta-v6.py:11656`

### 3.8 GET /v1/me

**Purpose:** Fetch the connected Spotify profile to display on the dashboard "Spotify Linked" page.

**Method/URL:** `GET https://api.spotify.com/v1/me`

**Caller:** `./dashboard/spotifylink.php:165-174`. Reads `id` (and the rest of the profile object) and uses presence of `id` as the "actually linked" check. If `id` is missing, the dashboard shows the reconnect button.

**Required scope:** None beyond a valid bearer token.

### 3.9 POST https://accounts.spotify.com/api/token

Two contexts use this URL — covered fully in §2 (initial code exchange) and §5 (refresh flow). It is not part of `api.spotify.com`; it lives on `accounts.spotify.com`.

---

## 4. Rate limits

Spotify enforces a **rolling 30-second window** rate limit. Apps in **Development Mode** have a low ceiling; **Extended Quota Mode** raises it substantially but requires a request via the Developer Dashboard.

### What this project does about it

- The bot does not implement explicit retry-after handling. A `429` response is logged and turned into the chat message `"Spotify is saying we're sending too many requests. Let's wait a moment and try again."` (from `SPOTIFY_ERROR_MESSAGES`). The user/streamer waits, the request is dropped.
- The token-refresh script issues at most one POST to `accounts.spotify.com/api/token` per user (concurrent via `asyncio.gather`) — accounts.spotify.com is not subject to the standard api.spotify.com rate limits but heavy fan-out is still a bad idea.
- The platform Spotify app is in **Development Mode** and capped at 5 authorised users (Spotify's March 9, 2026 policy change). New users must self-host their own app — see the dashboard banner in `./dashboard/spotifylink.php:240-243`.

### When you hit a 429

The response includes a `Retry-After` header (value in seconds). The current code does not read this header. If you add retry logic, read `response.headers.get('Retry-After')` and back off accordingly. Do **not** retry inside the chat-command path without a hard cap — bot commands are user-triggered and must respond quickly.

### Best practices already in place / worth adding

- **In place:** `!songqueue`'s in-bot pruning is rate-limited to once every 180 seconds (`await sleep(180)` in `check_song_requests`).
- **In place:** Each chat command has a cooldown configured in `builtin_commands` (per-user/global/mods bucket) — this naturally throttles Spotify call volume.
- **Worth adding (not currently done):** read `Retry-After` on 429s; lazy-fetch device list only when needed (already the pattern); cache currently-playing for a few seconds across `!song` and `!songqueue` to avoid double-calling the same endpoint within one command flow. (Today, `!songqueue` calls both `/queue` and `/currently-playing` per invocation.)

---

## 5. Token refresh flow

### Script: `./bot/refresh_spotify_tokens.py`

Run as a standalone Python process, NOT inside the bot. It:

1. `load_dotenv()` reads `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET`, `SQL_HOST`, `SQL_USER`, `SQL_PASSWORD` from `/home/botofthespecter/.env`.
2. Connects to MySQL `website` database via `aiomysql.create_pool` (autocommit on).
3. Selects all rows from `spotify_tokens WHERE refresh_token IS NOT NULL AND refresh_token != ''`. Each row yields `(user_id, refresh_token, own_client, client_id, client_secret)`.
4. For each user, builds a Basic auth header: `base64(client_id:client_secret)`. Picks the per-user creds when `own_client = 1` and both fields are non-empty, otherwise the global env creds.
5. POSTs to `https://accounts.spotify.com/api/token` with:

   ```
   Content-Type: application/x-www-form-urlencoded
   Authorization: Basic {base64}

   grant_type=refresh_token&refresh_token={refresh_token}
   ```

6. On success (`200` and `access_token` in JSON), `UPDATE spotify_tokens SET access_token=%s, refresh_token=%s WHERE user_id=%s`. Note: the new `refresh_token` is preserved if Spotify returned one, otherwise the existing one is reused (`result.get('refresh_token', refresh_token)`).
7. Logs to `/home/botofthespecter/logs/spotify_refresh.log` (rotating, 50KB × 5 backups).

The script runs **all users concurrently** via `asyncio.gather`. With many users this can spike requests; consider per-app fan-out limits if the user count grows.

### What triggers the refresh

| Trigger | Source |
| ------- | ------ |
| Admin "Refresh Tokens" panel (one-shot button) | `./dashboard/admin/index.php:473-493` (POST → exec) |
| Admin token-refresh SSE stream | `./dashboard/admin/stream_command.php:35` (`'spotify'` script key) |
| Scheduled cron on the bot host | server-side cron, NOT in repo. Recommended interval: 30–50 min (Spotify access tokens last 3600s). |

### How the bot loads the token

Per Spotify call, the bot:

1. `get_spotify_access_token()` opens a connection to `website` DB.
2. `SELECT id FROM users WHERE username = %s` with `CHANNEL_NAME` (the streamer's Twitch login).
3. `SELECT access_token FROM spotify_tokens WHERE user_id = %s`.
4. Returns the string for an `Authorization: Bearer {token}` header.

There is no in-process caching, so every command re-reads the row. If the row's `access_token` is stale, the request will get a `401` (see §6 — chat will show "I couldn't connect to Spotify..."). The fix is to ensure the refresh job ran — restarting the bot does NOT refresh the token; the bot doesn't refresh tokens itself.

### Why the bot doesn't refresh in-process

Historic decision: the bot is per-channel and per-user. The refresh script is a single process that walks all users in one pass and runs out-of-band. Centralising the refresh keeps the per-bot startup cheap and avoids N concurrent refresh calls per Spotify-credential pair. It also means the dashboard's "refresh now" button works without needing the bot to be running.

---

## 6. Error codes and gotchas

### Bot's error message map (`SPOTIFY_ERROR_MESSAGES`)

Defined in each bot version (`./bot/bot.py:209-216`, `./bot/beta.py:330-337`, `./bot/beta-v6.py:260-267`):

| Code | Chat message |
| ---- | ------------ |
| `401` | I couldn't connect to Spotify. Looks like the authentication failed. Please check the bot's credentials. |
| `403` | Spotify says I don't have permission to do that. Check your Spotify account settings. |
| `404` | (mapped — see source; typically "Spotify couldn't find that...") |
| `429` | Spotify is saying we're sending too many requests. Let's wait a moment and try again. |
| `500` | Spotify is having server issues. Please try again in a bit. |
| `502` | Spotify is having a temporary issue. Please try again in a bit. |
| `503` | Spotify's service is currently down. We'll need to wait until it's back online. |

Anything not in the map: `"Spotify gave me an unknown error. Try again in a moment."`

### Special status: 204 on currently-playing

`/me/player/currently-playing` returns `204 No Content` when there is no playback state. The bot treats this as "nothing playing" — not an error — and falls through to its Shazam failover (premium-only) for `!song`.

### Premium-only endpoints

These require the **Spotify user** (the streamer) to have Spotify Premium. If they don't, expect `403` with body code `PREMIUM_REQUIRED`:
- `POST /v1/me/player/queue` (`!songrequest`)
- `POST /v1/me/player/next` (`!skipsong`)
- `GET /v1/me/player/devices` returns devices but device control will still 403 without Premium.

The bot does NOT pre-check Premium — it issues the request and shows the generic 403 error. If many users are non-Premium, consider reading the streamer's profile `product` field (from `/v1/me`) at link time and storing it.

### Market restrictions

Tracks may be unavailable in the user's market. `/v1/tracks/{id}` and `/v1/search` results include `available_markets` (not currently inspected). A request that adds an unavailable track to the queue will queue successfully but fail to play. No chat-side warning today.

### Expired access token

A `401` from any endpoint usually means the access_token expired and the refresh job hasn't run yet. The bot does not retry. Operator action: trigger the admin-panel refresh button or wait for the next cron. The refresh job log is at `/home/botofthespecter/logs/spotify_refresh.log` (and `dashboard/admin/logs.php` exposes it under the `spotify_refresh` key).

### Refresh token rotation

Spotify may return a **new** `refresh_token` in a refresh response. The script preserves the new one when present (`result.get('refresh_token', refresh_token)`). Don't change this to always reuse the old one — rotation is silent and breaking it eventually invalidates the user's link.

### Short links (`spotify.link/...`)

The bot's regex catches these as "track URLs" and tries `GET /v1/tracks/{id}` with the short-link slug. This will fail (the slug is not a real track ID). Currently rare in chat; if it becomes a problem, add an HTTP HEAD to follow the redirect first.

### Album links

Album links (`open.spotify.com/album/...`) are explicitly rejected: "That looks like a Spotify album link. Please provide a Spotify track link instead."

### YouTube links inside `!songrequest`

If the input is a YouTube link, the bot uses `yt-dlp` (with cookies at `/home/botofthespecter/ytdl-cookies.txt`) to extract the video title, runs cleanup regexes (strip `[Official Video]`, `(Lyrics)`, quality tags, everything after `|`), and feeds the cleaned title into `/v1/search`. Results are best-effort; covers/karaoke versions are filtered out by the `instrumental` / `karaoke version` keyword check.

### Karaoke / instrumental filter

`!songrequest` rejects matches whose `name` or `artists[0].name` contains `instrumental` or `karaoke version` (case-insensitive). For Spotify-link requests this returns "Sorry, I don't accept karaoke or instrumental versions."; for search results it pretends "No song found".

### Concurrent device control

If the streamer has multiple active devices, the bot picks the **first** with `is_active = true` from the list. If the streamer just paused on phone and resumed on PC, ordering is Spotify's choice. There's no UI to pick a preferred device today.

### When the platform app is full

Since Spotify's March 2026 policy change, the project's own Spotify app cannot accept new users. New streamers must:
1. Create their own Spotify app at developer.spotify.com.
2. Add `https://dashboard.botofthespecter.com/spotifylink.php` as a redirect URI.
3. Enable "Use Your Own Spotify Client" on `./dashboard/spotifylink.php`, paste their `client_id` and `client_secret`.
4. Click "Link Spotify".

The dashboard surfaces this as `is-warning` and `is-danger` alerts when the platform slot count is at capacity.

### `auth` vs `has_access` columns

- `auth = 1` means the token exchange completed (the user clicked through OAuth at least once).
- `has_access = 1` is a manually-toggled "approved on the platform app" gate, used to count against the 5-user dev-mode cap. It's irrelevant when `own_client = 1`.

Both gates are inspected in `./dashboard/spotifylink.php:163-198`.

---

## 7. Quick file map

| Concern | File |
| ------- | ---- |
| OAuth grant + dashboard linking UI | `./dashboard/spotifylink.php` |
| PHP credentials | `./config/spotify.php` (dev) / `/var/www/config/spotify.php` (server) |
| Token refresh script | `./bot/refresh_spotify_tokens.py` |
| Bot — read access token | `./bot/bot.py:8221`, `./bot/beta.py:11846`, `./bot/beta-v6.py:9553`, `./bot/kick.py:1683` |
| Bot — `!song` | `./bot/bot.py:3608` (and equivalents in beta / beta-v6) |
| Bot — `!songrequest` | `./bot/bot.py:3655` |
| Bot — `!skipsong` | `./bot/bot.py:3835` |
| Bot — `!songqueue` | `./bot/bot.py:3906` |
| Bot — background queue prune | `./bot/bot.py:10162` |
| Admin one-shot refresh button | `./dashboard/admin/index.php:473` |
| Admin SSE refresh stream | `./dashboard/admin/stream_command.php:35` |
| Admin log viewer | `./dashboard/admin/logs.php:729` (`spotify_refresh` key) |
| Profile disconnect (delete `spotify_tokens` row) | `./dashboard/profile.php:296` |
| API server (read tokens for export only) | `./api/api.py:2306-2342` |
| User data export | `./bot/export_user_data.py:163` |
| User-facing setup help | `./help/spotify_setup.php` |
