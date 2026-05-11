# External API Documentation

Comprehensive local copies of upstream API documentation for every third-party service and library BotOfTheSpecter integrates with. Read these instead of fetching upstream docs during development.

Each file covers the **full API surface** scoped to what this project uses (and could use), structured as:
- Authentication — credentials, env var names, token storage location
- Endpoints / methods — complete request/response schemas
- Rate limits and quotas
- Error codes and handling
- BotOfTheSpecter callsites — exact `file:line` references

## Service index

| File | Service | Used by |
| ---- | ------- | ------- |
| [twitch.md](./twitch.md) | Twitch Helix REST, OAuth, EventSub, GraphQL | All bot versions, API server, dashboard |
| [TwitchIO-Historical.md](./TwitchIO-Historical.md) | TwitchIO 2.10.0 | bot.py, beta.py |
| [TwitchIO-Stable.md](./TwitchIO-Stable.md) | TwitchIO 3.x stable (v6 rewrite) | beta-v6.py |
| [discord.md](./discord.md) | discord.py, Discord REST API, OAuth2 | specterdiscord.py, dashboard |
| [openai.md](./openai.md) | OpenAI Chat Completions, TTS, Usage API | All bots, WebSocket TTS handler, dashboard |
| [spotify.md](./spotify.md) | Spotify Web API — playback, queue, search | All Twitch bots, dashboard |
| [streamelements.md](./streamelements.md) | StreamElements Socket.IO + Kappa v2 REST | All Twitch bots |
| [streamlabs.md](./streamlabs.md) | StreamLabs WebSocket donation events | All Twitch bots |
| [hyperate.md](./hyperate.md) | HypeRate heart rate WebSocket (Phoenix) | All Twitch bots |
| [kick.md](./kick.md) | Kick.com REST API + webhook receiver | kick.py, api.py |
| [github.md](./github.md) | GitHub inbound webhooks | api.py, specterdiscord.py |
| [patreon.md](./patreon.md) | Patreon Webhooks v2 | api.py, overlay |
| [kofi.md](./kofi.md) | Ko-fi webhooks | api.py, overlay |
| [fourthwall.md](./fourthwall.md) | Fourthwall webhooks | api.py, overlay |
| [freestuff.md](./freestuff.md) | FreeStuff admin webhook | api.py, specterdiscord.py |
| [cloudflare.md](./cloudflare.md) | Cloudflare R2 object storage (S3-compatible) | bot.py (boto3), dashboard (PHP SDK) |
| [hetrixtools.md](./hetrixtools.md) | HetrixTools uptime monitoring API | dashboard status page |
| [steam.md](./steam.md) | Steam Web API — app list, store search | All bots |
| [shazam.md](./shazam.md) | Shazam via RapidAPI — audio fingerprint | All bots (Shazam failover in !song) |
| [weather.md](./weather.md) | OpenWeatherMap + WeatherAPI.com | api.py (proxy), kick.py (direct) |
| [nominatim.md](./nominatim.md) | Nominatim / geopy — geocoding | All bots (!time command) |
| [timezonedb.md](./timezonedb.md) | TimezoneDB timezone lookup by lat/lng | All bots (!time command) |
| [exchangerate.md](./exchangerate.md) | ExchangeRate-API v6 — currency conversion | api.py (!convert proxy) |
| [iplocate.md](./iplocate.md) | IPLocate — IP geolocation | dashboard (Active Sessions page) |
| [tanggle.md](./tanggle.md) | Tanggle community puzzle platform | beta.py |
| [stream-bingo.md](./stream-bingo.md) | Stream Bingo WebSocket events | beta.py |
| [pronouns.md](./pronouns.md) | Pronouns.alejo.io — chat pronoun lookup | beta.py |
| [jokeapi.md](./jokeapi.md) | JokeAPI v2 — joke generation | All bots (!joke) |
| [deep-translator.md](./deep-translator.md) | deep_translator GoogleTranslator | All bots (!translate) |
| [brandfetch.md](./brandfetch.md) | Brandfetch brand logo CDN | dashboard |
| [yt-dlp.md](./yt-dlp.md) | yt-dlp — YouTube video/audio extraction | All bots (!songrequest), specterdiscord.py |

## File naming convention

`{service-name}.md` — lowercase, hyphens for spaces, matches the primary service or library name.

## Related

- Master index with line counts: [../../INDEX.md](../../INDEX.md)
- Internal API docs: [../Internal/](../Internal/)
- Project rules: [../../../rules/](../../../rules/)
