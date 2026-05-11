# External API Docs — Index

All third-party API and library references for BotOfTheSpecter. For full descriptions and line counts see [../../INDEX.md](../../INDEX.md).

| Doc | Covers | Lines |
| --- | ------ | ----: |
| [twitch.md](./twitch.md) | Twitch Helix REST, OAuth (id.twitch.tv), EventSub WebSocket, GraphQL (gql.twitch.tv). Per-bot-version subscription matrix. | 884 |
| [TwitchIO-Historical.md](./TwitchIO-Historical.md) | TwitchIO **2.10.0** — bot.py (stable) + beta.py (beta). IRC-driven `commands.Bot`, hand-rolled EventSub WebSocket, full event/context/type reference. | 315 |
| [TwitchIO-Stable.md](./TwitchIO-Stable.md) | TwitchIO **3.x stable** — beta-v6.py. Native EventSub, `commands.AutoBot`, Components, Guards, 15-section 2.10→3.x migration map + exception hierarchy. | 625 |
| [discord.md](./discord.md) | discord.py bot + OAuth Authorization Code flow + StreamersConnect proxy. Gateway, intents, slash commands, embeds, voice, rate limits. | ~1550 |
| [openai.md](./openai.md) | Chat Completions (all bot variants + Discord + Kick), TTS (`gpt-4o-mini-tts`), Organization Usage API. Env: `OPENAI_KEY`. | 642 |
| [spotify.md](./spotify.md) | 8 Web API endpoints — currently-playing, queue, devices, search. Auth Code flow, dual-mode credentials (`own_client`). | 546 |
| [streamelements.md](./streamelements.md) | Socket.IO realtime + Kappa v2 REST. Three auth types (`Bearer`/`oAuth`/API Key). Points, tips, sessions, bot chat. | 1433 |
| [streamlabs.md](./streamlabs.md) | WebSocket donation/follow/sub/bits events (11 types). OAuth2 + `socket_token`. No auto-reconnect. | 1052 |
| [hyperate.md](./hyperate.md) | Phoenix WebSocket heart-rate feed. 6 message types. `HYPERATE_API_KEY` = per-app key; per-user = `users.heartrate_code`. | 422 |
| [kick.md](./kick.md) | Kick.com REST (10 endpoints) + webhook receiver with RSA-SHA256 verification. OAuth 2.1 PKCE token refresh. | 449 |
| [github.md](./github.md) | Inbound GitHub webhook payloads (push, release, issues, PR, etc.). No outbound REST calls in repo. | 419 |
| [patreon.md](./patreon.md) | Patreon Webhooks v2 — JSON:API payload, HMAC-MD5 verification (not yet enforced), overlay wiring. | 562 |
| [kofi.md](./kofi.md) | Ko-fi webhooks — Donation/Subscription/Commission/Shop Order schemas, `verification_token` check (not yet enforced). | 338 |
| [fourthwall.md](./fourthwall.md) | Fourthwall webhooks — 11 event types, HMAC-SHA256 verification (not yet enforced). | 647 |
| [freestuff.md](./freestuff.md) | FreeStuff admin webhook — Standard Webhooks spec, Ed25519 signature (not yet enforced), Discord-only fan-out. | 229 |
| [cloudflare.md](./cloudflare.md) | Cloudflare R2 — S3-compatible via boto3 (Python) / aws-sdk-php (PHP). Presigned URLs, multipart upload, CORS. | 960 |
| [hetrixtools.md](./hetrixtools.md) | HetrixTools v3 uptime API — 5 monitors, Bearer auth, 300 s per-IP throttle. v1/v2/v3 compared. | 813 |
| [steam.md](./steam.md) | Steam Web API — `ISteamApps/GetAppList/v2`, `IStoreService/GetAppList/v1` (paginated), `storesearch`, `appdetails`. | 644 |
| [shazam.md](./shazam.md) | Shazam via RapidAPI — `POST /songs/v2/detect`, base64 PCM body, quota headers, premium-tier gating. | 163 |
| [weather.md](./weather.md) | OpenWeatherMap Geocoding 1.0 + One Call 3.0 (proxied via api.py) + WeatherAPI.com (kick.py only). | 338 |
| [nominatim.md](./nominatim.md) | Nominatim / geopy — `geocode()`, `reverse()`, full `address` dict, HTTP API, OSM place types, 1 req/s limit. | 460 |
| [timezonedb.md](./timezonedb.md) | TimezoneDB v2.1 — `get-time-zone` by position, `list-time-zone`, `convert-time-zone`. Free = 1 req/s. | 461 |
| [exchangerate.md](./exchangerate.md) | ExchangeRate-API v6 — Pair Conversion for `!convert`. 1,500 req/month free. Key in URL path. | 523 |
| [iplocate.md](./iplocate.md) | IPLocate — IP geolocation + privacy flags. PHP-only, header auth, 1,000 req/day free. | 367 |
| [tanggle.md](./tanggle.md) | Tanggle community puzzle platform — WebSocket `room.complete`, DB persistence, `!puzzles`. Beta-only. | ~200 |
| [stream-bingo.md](./stream-bingo.md) | Stream Bingo — 9 WebSocket event types, game/player/winner DB. API key in URL path. Beta-only. | ~220 |
| [pronouns.md](./pronouns.md) | Pronouns.alejo.io — `/v1/pronouns` catalog + `/v1/users/{username}`. No auth. Beta-only. | 248 |
| [jokeapi.md](./jokeapi.md) | JokeAPI v2 — 7 categories, 6 blacklist flags, 4 formats, rate-limit headers, error codes 100–114. | 736 |
| [deep-translator.md](./deep-translator.md) | `deep_translator` — 13 translator classes, 15 exceptions, character limits, async wrapper pattern. | 759 |
| [brandfetch.md](./brandfetch.md) | Brandfetch CDN grammar + Brand API v2 response schema. `client_id` in URL; `api_key` in config. | 442 |
| [yt-dlp.md](./yt-dlp.md) | yt-dlp `YoutubeDL` — complete options reference, `extract_info()`, info dict, format selectors, outtmpl, FFmpegExtractAudioPP, cookies, error hierarchy. | ~560 |
