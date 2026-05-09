# BotOfTheSpecter - Project Documentation

## Quick Start

This is a **multi-system streaming operations platform** with a Twitch bot, API server, WebSocket real-time hub, web dashboard, stream recording server, and overlays.

**Before making any changes, read the relevant memory file below.** They contain the complete architecture, integration points, and how systems work together.

## Project Rules (Read Before Editing!)

Short, enforceable rules live in `.claude/rules/`. Check the relevant one before making changes:

- **[bot-versions.md](./.claude/rules/bot-versions.md)** — Stable/beta/v6 policy, when to edit which file
- **[paths.md](./.claude/rules/paths.md)** — `./` for repo paths, server paths labeled separately
- **[data-flow.md](./.claude/rules/data-flow.md)** — WebSocket vs API vs direct DB decision
- **[database.md](./.claude/rules/database.md)** — `website` vs per-user DB scoping, async aiomysql
- **[secrets.md](./.claude/rules/secrets.md)** — No hardcoded credentials, signature verification
- **[php-config.md](./.claude/rules/php-config.md)** — **HARD RULE:** PHP never reads `.env`; always uses `./config/{service}.php`
- **[overlays.md](./.claude/rules/overlays.md)** — Browser-source constraints, queueing, auto-reconnect

## Memory Files (Architecture Reference)

Detailed system documentation lives in `.claude/memory/`:

- **[system_bot.md](./.claude/memory/system_bot.md)** - Twitch chat bot (3 versions: stable/beta/v6)
  - Use this when: adding bot commands, modifying event handling, integrating external services
  
- **[system_api.md](./.claude/memory/system_api.md)** - FastAPI data server (HTTPS, port 443)
  - Use this when: adding/modifying endpoints, handling authentication, managing databases
  
- **[system_websocket.md](./.claude/memory/system_websocket.md)** - Real-time event hub (SocketIO, port 443)
  - Use this when: adding new event types, modifying broadcasters, working with handlers
  
- **[system_secondary.md](./.claude/memory/system_secondary.md)** - Dashboard, overlays, stream server, extension
  - Use this when: modifying UI, adding overlays, working with stream recording

## System Architecture Overview

```text
                        ┌─────────────────┐
                        │  TWITCH BOT     │ (bot.py / beta.py / beta-v6.py)
                        │  - Chat commands│
                        │  - EventSub     │
                        │  - Integrations │
                        └────────┬────────┘
                                 │
        ┌────────────────────────┼────────────────────────┐
        │                        │                        │
        ▼                        ▼                        ▼
  ┌──────────────┐        ┌─────────────┐        ┌──────────────┐
  │  WEBSOCKET   │        │ API SERVER  │        │ DASHBOARD    │
  │ PORT 443     │        │ PORT 443    │        │ (PHP Web UI) │
  │              │        │             │        │              │
  │ Real-time    │        │ On-demand   │        │ User config  │
  │ events       │        │ data access │        │ Bot control  │
  │ 177 events   │        │ 50+ endpoints        │ Media mgmt   │
  └───────┬──────┘        └─────────────┘        └──────────────┘
          │
          └─────────────────┬──────────────────────┐
                            │                      │
                     ┌──────▼──────┐      ┌────────▼────────┐
                     │  OVERLAYS   │      │ STREAM SERVER   │
                     │  20 variants│      │ Custom RTMPS    │
                     │ (PHP/JS)    │      │ Multi-region    │
                     │ WebSocket   │      │ Recording       │
                     │ client      │      │ Forwarding      │
                     └─────────────┘      └─────────────────┘
                     
                           + TWITCH EXTENSION (in progress)
```

## Key Facts to Remember

### Bot System

- **Three versions**: stable (never changes except critical bugs), beta (testing), v6 (rewrite with TwitchIO 3.2.2)
- **Commands**: 65+ builtin, 14 mod-only, 13 aliases, custom commands from database
- **Integrations**: OpenAI, Spotify, StreamElements, Discord, YouTube, Shazam, Steam, Weather, HypeRate, Stream Bingo, etc.
- **Token Refresh**: Automatic via background tasks for Twitch, Spotify, Discord, StreamElements
- **Logging**: 7 separate loggers (bot, chat, twitch, api, chat_history, event_log, websocket)

### API Server

- **Port**: 443 (HTTPS only)
- **Auth**: Dual system - V1 (query param `api_key=`) + V2 (header `X-API-KEY:`)
- **Databases**: Central `website` DB + per-user database (username = DB name)
- **Endpoints**: 50+ total covering commands, points, webhooks, WebSocket triggers, extensions
- **Rate Limiting**: IP-based with whitelist bypass, API counts for external services

### WebSocket Server

- **Port**: 443 (HTTPS, SocketIO)
- **Clients**: Regular clients (dashboards, overlays, bots) organized by `channel_code` + global listeners (admin auth)
- **Events**: 177 total across Twitch, OBS, music, TTS, donations, tasks, timer, stream bingo
- **Handlers**: 8 specialized modules (EventHandler, MusicHandler, TTSHandler, ObsHandler, DonationEventHandler, SettingsManager, DatabaseManager, SecurityManager)
- **Broadcasting**: Three patterns - code-specific, timer-specific, task-specific

### Dashboard

- **Tech**: PHP + JavaScript + Bulma CSS
- **Purpose**: Central UI for users to configure bot, manage media, set overlays, monitor integrations
- **Auth**: Twitch OAuth scoped to `.botofthespecter.com` (shared across subdomains)
- **Integration**: Calls API server endpoints via JavaScript

### Overlays

- **Purpose**: Browser sources for OBS showing live stream events
- **Tech**: PHP (auth/prefs) + JavaScript (Socket.io client) + HTML/CSS
- **20 Variants**: all.php (master), music.php, tts.php, deaths.php, weather.php, chat.php, walkons.php, credits.php, working-or-study.php, plus others
- **Real-time**: All receive events from WebSocket server

### Stream Server

- **Purpose**: Custom RTMPS ingest to record streams + forward to Twitch
- **Tech**: Python (pyrtmp + FFmpeg)
- **Multi-Region**: Sydney, US-East, US-West, EU-Central
- **Features**: TLS encryption, automatic Twitch forwarding, FLV→MP4 conversion, operator web UI (port 8080)

### Twitch Extension

- **Status**: In progress (v1.0.0 ready for submission)
- **Purpose**: Twitch panel displaying bot stats (commands, lurkers, deaths, watch time, rewards, etc.)
- **Tech**: JavaScript + Twitch Extension API
- **Data**: Calls `/api/extension/*` endpoints

## Common Tasks

### Adding a New Bot Command

1. Read [system_bot.md](./.claude/memory/system_bot.md) - Command System section
2. Add to builtin_commands dict or create custom command in database
3. Implement handler in bot.py event message processing
4. Add help text to builtin_commands info

### Adding a New API Endpoint

1. Read [system_api.md](./.claude/memory/system_api.md) - API Endpoints section
2. Determine if public, authenticated (user/admin key), or webhook
3. Create Pydantic model for request/response validation
4. Implement endpoint with proper error handling
5. Update OpenAPI docs if needed

### Adding a New WebSocket Event

1. Read [system_websocket.md](./.claude/memory/system_websocket.md) - Event Types section
2. Decide: code-specific, timer-specific, or task-specific broadcast
3. Create handler in appropriate module (EventHandler, etc.)
4. Register in setup_event_handlers()
5. Implement broadcast logic using appropriate broadcast_* method

### Adding a New Overlay

1. Read [system_secondary.md](./.claude/memory/system_secondary.md) - Overlay System section
2. Create new PHP file in /overlay/ directory
3. Connect to WebSocket with Socket.io client
4. Handle relevant events and render visually
5. Add configuration option in dashboard if needed

### Modifying Stream Recording

1. Read [system_secondary.md](./.claude/memory/system_secondary.md) - Stream System section
2. Changes likely needed in stream.py, FFmpeg forwarding, or storage paths
3. Test with operator web UI (port 8080) first
4. Remember: FLV is temporary, MP4 is final storage

## Database Structure

### Central Website Database

- `users` - User accounts, API keys, OAuth tokens
- `admin_api_keys` - Admin keys with service restrictions
- `api_counts` - Request counting for rate-limited external services
- `twitch_bot_access` - Bot's Twitch token
- `system_metrics` - Server health data
- `freestuff_games` - Free game listings from webhooks

### Per-User Databases (one per username)

- `custom_commands` - User's custom commands
- `builtin_commands` - Enable/disable status for built-in commands
- `bot_points` - User points/currency
- `game_deaths`, `per_stream_deaths` - Death tracking
- `stored_redeems` - Channel reward redemptions
- `typos`, `lurkers`, `hugs`, `kisses`, `highfives`, `counts`, `watch_time`, `todos` - Various tracking tables

## Key Files & Directories

### Bot System

- Main: `./bot/bot.py` (stable), `./bot/beta.py` (testing), `./bot/beta-v6.py` (v6 rewrite)
- Discord: `./bot/specterdiscord.py`
- Kick.com: `./bot/kick.py`
- Token refresh: `./bot/refresh_*.py` (custom bot, Spotify, Discord, StreamElements — Twitch refreshes in-process via bot.py background task)
- Logs (server): `/home/botofthespecter/logs/logs/{log_type}/{channel}.txt`
- AI history (server): `/home/botofthespecter/ai/chat-history/{user_id}.json`

### API Server

- Main: `./api/api.py` (~5,922 lines)
- Config (server): `/home/botofthespecter/.env`

### WebSocket Server

- Main: `./websocket/server.py`
- Handlers: `./websocket/{event_handler,music_handler,tts_handler,obs_handler,donation_handler,settings_manager,database_manager,security_manager}.py`
- Config (server): `/home/botofthespecter/.env`, `/home/botofthespecter/websocket_tts_config.json`
- IP whitelist (server): `/home/botofthespecter/ips.txt`

### Dashboard

- Main: `./dashboard/dashboard.php`, `./dashboard/index.php`
- Config: `./config/` (dev) / `/var/www/config/` (server)
- Cache (server): `/dashboard/cache/` (per-user follower/ban lists)

### Overlays

- All variants: `./overlay/*.php` (20 files)

### Stream Server

- Main: `./stream/stream.py`
- Service: `./stream/stream.service`
- Config: `./stream/ssl/`

### Twitch Extension

- Main: `./extension/manifest.json`
- Views: `./extension/{panel,config}.html` and `.js`

## SSH Hosts (for reference)

Defined in bot.py and used throughout for remote operations:

- API, WEBSOCKET, BOT-SRV, SQL, STREAM-US-EAST-1, STREAM-US-WEST-1, STREAM-AU-EAST-1, WEB, BILLING

## Environment Variables

Key ones to know:

- `SQL_HOST`, `SQL_USER`, `SQL_PASSWORD` - MySQL connection
- `OPENAI_KEY` - ChatGPT API
- `TWITCH_OAUTH_API_TOKEN`, `CLIENT_ID`, `CLIENT_SECRET` - Twitch OAuth
- `ADMIN_KEY` - Master admin API key
- `SHAZAM_API`, `STEAM_API`, `WEATHER_API`, `EXCHANGE_RATE_API` - External services

## Communication Flow

```text
User (Browser) 
  ↓
Dashboard (PHP) → API Server (FastAPI) → MySQL
  ↓
WebSocket Server (SocketIO)
  ├→ Overlays (Browser Sources)
  ├→ Bot (Python bot instance)
  └→ External (Discord, etc.)

Bot
  ├→ Twitch EventSub (events)
  ├→ API Server (data queries)
  ├→ WebSocket Server (real-time events)
  └→ External APIs (OpenAI, Spotify, etc.)

Stream Server
  ├→ Receives RTMPS stream from user
  ├→ Records to FLV (temporary)
  ├→ Forwards to Twitch via FFmpeg
  └→ Converts to MP4 (final storage)
```

## Tips for Working with This Codebase

1. **Always check the memory files first** - They have complete context
2. **Understand the version difference** - Stable never changes; beta is for testing; v6 is the future
3. **Real-time vs on-demand** - WebSocket for live events, API for data queries
4. **Per-user isolation** - Each user has their own database; commands/settings don't leak
5. **Multi-region aware** - Stream server runs in 4 regions; changes might need regional config
6. **Token expiry** - Many integrations auto-refresh tokens; check the background task logic
7. **Database transactions** - MySQL operations are async via aiomysql; always use await
8. **Error logging** - All systems log to files; check logs when debugging
9. **Admin keys** - Some endpoints require service-specific or super-admin keys; check auth
10. **File paths** - Many paths are Linux-based (/home/botofthespecter/, /var/www/); adjust if needed

## When You Need Help

1. If a system isn't working: Check the relevant memory file (architecture section)
2. If adding a feature: Check the "Common Tasks" section above
3. If modifying integrations: Check the bot memory file (Major Integrations section)
4. If confused about data flow: Check the Communication Flow diagram above
5. If debugging: Check the logging section in the relevant memory file

---

**Last Updated**: 2026-05-09 (memory verification pass: corrected overlay count, walkons.php naming, removed nonexistent refresh_twitch_tokens.py reference, fixed bot.py line counts, normalized Windows path formatting)  
**Created By**: Multi-agent code analysis (bot-analyzer, api-analyzer, websocket-analyzer, secondary-analyzer)  
**Memory Files**: See `.claude/memory/MEMORY.md`
