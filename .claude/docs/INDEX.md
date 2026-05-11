# Local API Documentation

Self-contained reference for every external API and library this project integrates with. Read these instead of fetching upstream docs — they're scoped to what BotOfTheSpecter actually uses, with repo callsites cited.

Each doc covers: overview, authentication (env var names + token storage), endpoints/methods used (with `file:line` callsites), webhook payloads where applicable, rate limits, and project-specific gotchas.

## Index

| Doc | Covers | Lines |
| --- | ------ | ----: |
| [twitch.md](./API/External/twitch.md) | Twitch Helix REST, OAuth (id.twitch.tv), EventSub WebSocket, GraphQL (gql.twitch.tv). Per-bot-version subscription matrix. | 884 |
| [TwitchIO-Historical.md](./API/External/TwitchIO-Historical.md) | TwitchIO **2.10.0** — `./bot/bot.py` (stable) + `./bot/beta.py` (beta). IRC-driven `commands.Bot`, hand-rolled EventSub WebSocket, full event/context/type reference. | 315 |
| [TwitchIO-Stable.md](./API/External/TwitchIO-Stable.md) | TwitchIO **3.x stable** — `./bot/beta-v6.py`. Native EventSub, `commands.AutoBot`, Components, Guards, 15-section 2.10→3.x migration map + exception hierarchy. | 625 |
| [patreon.md](./API/External/patreon.md) | Patreon Webhooks v2 — event types, JSON:API payload schema, HMAC-MD5 verification (not yet enforced), overlay wiring. | 562 |
| [kofi.md](./API/External/kofi.md) | Ko-fi webhooks — Donation/Subscription/Commission/Shop Order schemas, form-encoded body, `verification_token` in-body check (not yet enforced), overlay alert logic. | 338 |
| [fourthwall.md](./API/External/fourthwall.md) | Fourthwall webhooks — ORDER_PLACED/DONATION/SUBSCRIPTION_PURCHASED/GIFT_PURCHASE schemas, HMAC-SHA256 verification (not yet enforced), overlay alert logic. | 647 |
| [freestuff.md](./API/External/freestuff.md) | FreeStuff admin webhook — announcement_created/product_updated schemas, Ed25519 verification (not yet enforced), DB persistence, Discord-only fan-out (no overlay). | 229 |
| [openai.md](./API/External/openai.md) | Chat Completions across all bot variants + Discord + Kick + custom modules. TTS via `gpt-4o-mini-tts`. Per-user history file format and 8/12-turn windowing. Env: `OPENAI_KEY` (not `OPENAI_API_KEY`). | 642 |
| [streamelements.md](./API/External/streamelements.md) | StreamElements — Socket.IO realtime + Kappa v2 REST. OAuth2 (`oAuth` vs `Bearer` header distinction). JWT token vs OAuth token. `streamelements_connection_manager()` with exponential backoff. | 1433 |
| [streamlabs.md](./API/External/streamlabs.md) | StreamLabs — raw WebSocket (`EIO=3`, not socketio). OAuth2 + `socket_token` preferred. **No reconnect loop.** No refresh script in repo. | 1052 |
| [hyperate.md](./API/External/hyperate.md) | HypeRate — Phoenix WebSocket (not Socket.IO). `HYPERATE_API_KEY` = per-app dev key; per-user = `users.heartrate_code`. 10-second heartbeat. `hr: null` ends the task. | 422 |
| [steam.md](./API/External/steam.md) | Steam Web API — `ISteamApps/GetAppList/v2`, `IStoreService/GetAppList/v1` (paginated), `storesearch` (Kick), `appdetails` (reference). 1-hour file cache. | 644 |
| [shazam.md](./API/External/shazam.md) | Shazam via RapidAPI — audio fingerprint failover for `!song`. Streamlink → FFmpeg → base64 PCM → `/songs/v2/detect`. Premium-tier gated. Quota tracked in `api_counts`. | 163 |
| [spotify.md](./API/External/spotify.md) | All 8 Web API endpoints used (currently-playing, queue GET/POST, devices, search, etc.). Auth Code flow, dual-mode credentials (platform vs `own_client = 1`). Refresh script details. | 546 |
| [hetrixtools.md](./API/External/hetrixtools.md) | HetrixTools v3 uptime API — 5 monitors (API, WEBSOCKET, WEB1, SQL, BOTS). Bearer auth. Sequential fetches. 300s per-IP throttle. | 813 |
| [cloudflare.md](./API/External/cloudflare.md) | Cloudflare R2 — 3 buckets, S3-compatible via boto3 (Python) / aws-sdk-php (PHP). Presigned URLs (SigV4, 7-day max). **Flagged: `getObjectUrl()` returns unsigned URLs.** | 960 |
| [discord.md](./API/External/discord.md) | discord.py bot (intents, cog, slash commands) + OAuth Authorization Code flow via `./dashboard/discordbot.php` + StreamersConnect proxy. Gateway protocol, intents bitmask, dispatch events, slash commands, message components, embeds, voice (DAVE E2EE), permissions, guild/member/role API, OAuth2 scopes, channel resource, user resource, audit log, rate limits, full interactions model. | ~1550 |
| [stream-bingo.md](./API/External/stream-bingo.md) | Stream Bingo — WebSocket notification feed (9 event types), game/player/winner DB persistence, bits tracking. REST endpoints documented but not yet called. API key in URL path. **Beta-only.** | ~220 |
| [tanggle.md](./API/External/tanggle.md) | Tanggle community puzzle platform — WebSocket `room.complete` events, DB persistence, `!puzzles` command. REST room-creation endpoint documented but not yet called. Bearer auth from DB. | ~200 |
| [kick.md](./API/External/kick.md) | Kick.com REST (10 endpoints) + webhook receiver at `./api/api.py:2144` with RSA-SHA256 verification. **Flagged: header name mismatch, broken `/v2/categories` path, no webhook subscription code in repo.** | 449 |
| [github.md](./API/External/github.md) | Inbound `/github` webhook payload schemas (push, release, issues, PR, etc.). **Flagged: no HMAC signature verification — relies on admin-key auth.** No active outbound API calls. | 419 |
| [nominatim.md](./API/External/nominatim.md) | Nominatim / geopy — forward geocoding for `!time`. `Nominatim` class constructor, `geocode()` / `reverse()` methods, `Location` object, full `address` breakdown dict, HTTP API reference, rate limits (1 req/s), OSM place types, address fields, callsites in bot.py / beta.py / beta-v6.py (kick.py imports but bypasses Nominatim). | 460 |
| [timezonedb.md](./API/External/timezonedb.md) | TimezoneDB v2.1 — `by=position` lat/lng lookup for `!time`. 1 req/sec free tier. Env: `TIMEZONE_API`. Nominatim two-stage geocode flow. | 461 |
| [exchangerate.md](./API/External/exchangerate.md) | ExchangeRate-API v6 — Pair Conversion for `!convert`. 1,500 req/month free. Key in URL path (scrub from logs). Quota tracked in `api_counts`. | 523 |
| [pronouns.md](./API/External/pronouns.md) | Pronouns.alejo.io — `/v1/pronouns` catalog + `/v1/users/{username}` per-user lookup. Beta-only. No auth. Cache aggressively. | 248 |
| [iplocate.md](./API/External/iplocate.md) | IPLocate — IP geolocation + privacy flags for Active Sessions page. PHP-only, header auth, 1,000 req/day free. | 367 |
| [weather.md](./API/External/weather.md) | OpenWeatherMap (Geocoding 1.0 + One Call 3.0, proxied via `./api/api.py`, quota counted in `api_counts`) + WeatherAPI.com (used only by `./bot/kick.py`). Nominatim is for `!time`, not `!weather`. | 338 |
| [jokeapi.md](./API/External/jokeapi.md) | JokeAPI v2 — `!joke` command. Keyless. `jokeapi` wrapper (sync in stable/beta, async in v6). Per-channel blacklist loop. | 736 |
| [deep-translator.md](./API/External/deep-translator.md) | `deep_translator` GoogleTranslator — `!translate` command. Scrapes Google Translate (not official API). Blocking call in Twitch bots (known issue). | 759 |
| [brandfetch.md](./API/External/brandfetch.md) | Brandfetch CDN — 8 brand logos in the dashboard. Static image CDN only; JSON API not yet wired. `client_id` in URL (not a secret); `api_key` in config (unused). | 442 |
| [yt-dlp.md](./API/External/yt-dlp.md) | yt-dlp Python library — `YoutubeDL` constructor options (complete), `extract_info()` signature, info dict fields, format selector syntax, outtmpl placeholders, FFmpegExtractAudioPP post-processor, cookie handling, error hierarchy. Two callsite patterns: title-only extraction (`!songrequest` in all 3 bot versions) and full audio download (Discord music). Installed v2025.1.26. | ~560 |

## Project rules to remember when editing integrations

- **PHP never reads `.env`** — credentials always live in `./config/{service}.php` (`./.claude/rules/php-config.md`).
- **Webhook signatures are mandatory** where upstream supports them (`./.claude/rules/secrets.md`). Several inbound webhook handlers currently violate this; see [patreon.md](./API/External/patreon.md), [kofi.md](./API/External/kofi.md), [fourthwall.md](./API/External/fourthwall.md), [freestuff.md](./API/External/freestuff.md), and [github.md](./API/External/github.md).
- **Per-user vs central database** — pick the right scope (`./.claude/rules/database.md`). Most bot integrations write to per-user DBs; OAuth tokens live in the central `website` DB.
- **Bot version policy** — features go into `./bot/beta.py` first; `./bot/bot.py` is critical-fix-only; `./bot/beta-v6.py` is the TwitchIO 3.x rewrite (`./.claude/rules/bot-versions.md`).

## Hardening backlog surfaced by this docs pass

These were noted by the docs agents while reading the integration code. Not urgent enough to fix in this session, but worth tracking:

1. **Patreon / Ko-fi / Fourthwall / FreeStuff webhook handlers** don't verify signatures (see [patreon.md](./API/External/patreon.md), [kofi.md](./API/External/kofi.md), [fourthwall.md](./API/External/fourthwall.md), [freestuff.md](./API/External/freestuff.md) §gotchas).
2. **GitHub `/github` webhook** doesn't verify HMAC; relies on admin-key only (github.md).
3. **Kick webhook** verifies the wrong header name (`Kick-Event-Timestamp` vs documented `Kick-Event-Message-Timestamp`) — likely fail-open today (kick.md §9).
4. **Kick `/v2/categories` path** is broken — `kick_get()` helper hardcodes a base that double-prefixes the path (kick.md §9).
5. **Cloudflare R2 `getObjectUrl()`** in `./dashboard/persistent_storage.php` returns unsigned URLs (cloudflare.md §7).
6. **StreamLabs WebSocket** has no auto-reconnect and no token refresh script (streamlabs.md §3–4).
7. **CLAUDE.md correction**: `HYPERATE_API_KEY` is the per-application developer key, not per-user. The per-user value is `users.heartrate_code` in the DB.

## Maintenance

These docs are point-in-time snapshots based on the code as of 2026-05-11. When integrations change:

- **Endpoint added or removed** → update the relevant doc.
- **Auth flow changed** → update the auth section AND any cross-references in CLAUDE.md.
- **New API integrated** → write a new file, add a row to this index. Use the same section structure as the existing docs.
- **Credential source moved** → check both `./.env` (Python/Node) and `./config/*.php` (PHP) callouts in the doc.

When in doubt about whether a doc is stale, grep the repo for the relevant import or URL and compare to the doc's callsite list.
