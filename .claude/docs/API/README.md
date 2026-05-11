# API Documentation

API documentation split by direction: third-party services the project calls out to (External) and BotOfTheSpecter's own APIs that other systems call into (Internal).

## Folders

| Folder | Purpose |
| ------ | ------- |
| [External/](./External/) | Local copies of upstream docs for every third-party API and library this project integrates with — Twitch, Discord, OpenAI, Spotify, StreamElements, and 25+ others. |
| [Internal/](./Internal/) | Documentation for BotOfTheSpecter's own API surface — FastAPI server endpoints, WebSocket event catalogue, extension API. |

## Finding the right file

The master index at [../INDEX.md](../INDEX.md) lists all docs (both External and Internal) with one-line summaries. Check there first rather than browsing the folders directly.

## Adding new docs

**New third-party integration:**
1. Create `External/{service}.md` following the structure of existing files (auth → endpoints → schemas → rate limits → BotOfTheSpecter callsites).
2. Add a row to `../INDEX.md`.

**New internal endpoint or event:**
1. Create or update `Internal/{subsystem}.md`.
2. Add a row to `../INDEX.md`.
