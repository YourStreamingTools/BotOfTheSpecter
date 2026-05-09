---
name: BotOfTheSpecter API Server
description: FastAPI-based HTTP server providing data access, webhook processing, and command management for all systems
type: project
---

# BotOfTheSpecter API Server - Complete Architecture

## Overview
FastAPI-based HTTPS server (port 443) serving as the central data backbone for BotOfTheSpecter. Handles authentication, user data access, webhook processing, command management, and triggering real-time events on the WebSocket server.

**Key Features**: Dual-auth system (v1 query param, v2 header), per-user databases, IP whitelisting, rate limiting, token caching, API counting with daily/monthly resets

## FastAPI Setup

**Configuration**:
- Title: BotOfTheSpecter
- Version: 1.0.0
- Port: 443 (HTTPS only)
- SSL: Let's Encrypt certificates at `/etc/letsencrypt/live/api.botofthespecter.com/`
- Docs: `/v2/docs`, `/v2/redoc`, `/v2/openapi.json` (V1 endpoints also available)

**Middleware Stack**:
- **CORS**: Fully permissive (all origins, methods, headers, credentials)
- **V2 HTTP Middleware**: Converts `/v2/<path>` + `X-API-KEY` header to legacy query auth
- Rejects v2 paths with query-based api_key (must use header)
- Routes public/webhook paths appropriately

**Lifespan Management**:
- Startup: Launches midnight task for API count resets
- Shutdown: Cancels midnight task cleanly

**Logging**:
- File: `/home/botofthespecter/log.txt` (fallback: `/home/fastapi/log.txt`)
- Rotation: 100MB per file, keeps 10 backups
- Format: `%(asctime)s - %(levelname)s - %(message)s`

## Database Integration

**Connection Types**:

1. **`get_mysql_connection()`** - Central website database
   - Host, user, password from environment variables
   - Database: `website` (global user data, API keys, admin settings)

2. **`get_mysql_connection_user(username)`** - Per-user database
   - Same credentials, database name = username
   - Each user gets isolated MySQL database for commands, points, settings

**Central Tables** (website DB):
- `users` - User accounts, API keys, auth tokens
- `admin_api_keys` - Admin keys with service restrictions
- `api_counts` - Weather, Shazam, ExchangeRate request counts with reset day tracking
- `system_metrics` - CPU, RAM, disk, network from all servers
- `freestuff_games` - Free game offerings from webhooks
- `bot_chat_token` - Twitch bot OAuth credentials
- `twitch_bot_access` - Per-user Twitch token cache
- `handoff_tokens` - SSO tokens for stream server

**Per-User Tables** (username DB):
- `custom_commands` - User-defined commands
- `builtin_commands` - Enabled/disabled status for built-in commands
- `custom_user_commands` - Commands for other users
- `bot_points` - User points/currency system
- `game_deaths`, `per_stream_deaths` - Death count tracking
- `stored_redeems` - Channel reward redemptions
- `bits_data` - Cheered bits leaderboard
- `typos`, `lurkers`, `hugs`, `kisses`, `highfives`, `counts`, `watch_time`, `todos` - Various tracking

## Authentication & Authorization

**API Key System**:

**User API Keys**:
- Stored in `website.users.api_key`
- Verified via `verify_api_key()` → returns username or None
- Used in requests via query param `api_key=...` or header `X-API-KEY: ...`

**Admin API Keys**:
- Stored in `website.admin_api_keys` table
- Each has `service` field ('admin' for super, or specific service name)
- Super admin keys access everything; service-specific keys limited to their service

**Verification Flow**:
1. `verify_key(api_key, service=None)` checks both user and admin keys
2. Returns dict: `{"type": "user"|"admin", "username": str, "service": str}`
3. `resolve_username(key_info, channel_param)` determines actual username
   - Admin keys MUST provide `channel` parameter
   - User keys: channel param must match authenticated user (or be None)

**V2 API Authentication** (preferred):
- Header-based: `X-API-KEY` header (required for v2 endpoints)
- Legacy query param: `api_key=...` still works for v1 paths
- Middleware converts v2 requests internally
- V2 requests with query-param api_key are rejected

**Permission Levels**:
Valid permissions: `everyone`, `vip`, `all-subs`, `t1-sub`, `t2-sub`, `t3-sub`, `mod`, `broadcaster`

## SSH Integration

**Remote Hosts**:
- BOTS_SSH_HOST: Bot server
- WEB1_SSH_HOST: Website server
- SQL_SSH_HOST: SQL server
- WEBSOCKET_SSH_HOST: WebSocket server

**Credentials**: SSH_USERNAME, SSH_PASSWORD from environment
**Connection Timeout**: 8 seconds (configurable)

**Key Operations**:

1. **Uptime Marker Reading** (`_read_uptime_marker_via_ssh()`):
   - Reads modification time of marker files
   - Calculates uptime from marker mtime to now
   - Returns dict with `uptime` (formatted string), `started_at` (timestamp)
   - Marker files: `/home/botofthespecter/{websocket|web1|sql|bots}_uptime`

2. **Walkon File Listing**:
   - SSH exec: Lists files in `/var/www/walkons/{username}`
   - Filters for `.mp3` and `.mp4` files
   - Returns array with filename, URL, viewer username

## API Endpoints

### Public Endpoints (No auth required)
- `GET /versions` - Bot version numbers
- `GET /commands/info` - Builtin commands info
- `GET /heartbeat/websocket`, `/heartbeat/api`, `/heartbeat/database` - Health checks
- `GET /system/uptime` - API/WebSocket/external server uptime (rate-limited)
- `GET /chat-instructions` - Chat instructions for AI
- `GET /api/song` - Current song info (Spotify)
- `GET /api/exchangerate` - Currency exchange rates
- `GET /api/weather` - Weather requests remaining
- `GET /api/steamapplist` - Steam game app list cache
- `GET /freestuff/games` - Free games database
- `GET /freestuff/latest` - Latest free game

### Webhook Endpoints (External service auth)
- `POST /fourthwall` - Fourthwall donation webhook
- `POST /kofi` - Ko-fi donation webhook
- `POST /patreon` - Patreon webhook
- `POST /github` - GitHub webhook with signature verification
- `POST /freestuff` - FreeStuff game availability webhook
- `POST /kick/{username}` - Kick.com webhook with signature verification

### User Account Endpoints (Requires user API key)
- `GET /account` - Full account info (tokens, settings)
- `POST /account/app-login` - Verify app password
- `GET /checkkey` - Validate API key
- `GET /streamonline` - Check stream status
- `GET /deaths` - Get death counts
- `GET /authorizedusers` - (Admin) List beta access users

### Command Management
**Custom Commands**:
- `GET /custom-commands` - List all
- `POST /custom-commands/add` - Add new
- `PUT /custom-commands/update` - Update
- `DELETE /custom-commands/delete` - Remove

**Builtin Commands**:
- `GET /builtin-commands` - List with status
- `PUT /builtin-commands/update` - Toggle enable/disable

**User-Managed Commands**:
- `GET /user-commands/get` - Get commands for user
- `POST /user-commands/add` - Add command for another user
- `PUT /user-commands/update` - Update
- `DELETE /user-commands/remove` - Delete

**Retrieval**:
- `GET /kill-responses` - Kill responses
- `GET /joke` - Random joke
- `GET /quotes` - Random quote
- `GET /fortune` - Random fortune
- `GET /sound-alerts` - Sound alert list
- `GET /walkons` - Walkon files for user

### Points System
- `GET /user-points` - Get user's points
- `POST /user-points/credit` - Add points
- `POST /user-points/debit` - Subtract points

### WebSocket Trigger Endpoints
- `GET /websocket/tts` - Trigger TTS
- `GET /websocket/walkon` - Trigger walkon
- `GET /websocket/deaths` - Trigger death sound
- `GET /websocket/sound-alert` - Trigger sound
- `GET /websocket/custom-command` - Trigger custom command
- `GET /websocket/stream-online` - Stream online event
- `GET /websocket/raffle-winner` - Announce raffle winner
- `POST /SEND_OBS_EVENT` - Send OBS event data

### Weather Endpoints
- `GET /api/weather` - Get weather (API counts tracking)
- `GET /weather/location` - Validate location

### Channel Reward/Redemption
- `GET /channel/twitch/redeems/store` - List stored redeems
- `POST /channel/twitch/redeems/store` - Store new redemption
- `DELETE /channel/twitch/redeems/store/{id}` - Delete redemption

### Channel Bits
- `GET /channel/twitch/bits` - Bits leaderboard

### Extension Endpoints (No auth, uses Twitch channel ID)
- `GET /extension/commands` - Custom commands
- `GET /extension/deaths`, `/extension/quotes`, `/extension/typos`
- `GET /extension/lurkers`, `/extension/hugs`, `/extension/kisses`, `/extension/highfives`
- `GET /extension/custom-counts`, `/extension/user-counts`
- `GET /extension/reward-counts`, `/extension/watch-time`, `/extension/todos`

## Response Handling & Validation

**Pydantic Models**: Define request/response validation
- Examples: TTSRequest, DeathsRequest, WeatherRequest, StatusResponse, UptimeResponse, etc.

**Error Handling**:
- Custom exception handler for RequestValidationError
- General catch-all for unhandled exceptions
- Returns JSON: `{"detail": "error message", "error": "exception string"}`
- Logs full traceback to file

**Status Codes**:
- 200: Success
- 400: Bad request (validation)
- 401: Unauthorized (invalid API key)
- 403: Forbidden (insufficient permissions)
- 404: Not found
- 409: Conflict (duplicate command)
- 429: Rate limited
- 500: Server error

## Security Features

**IP Whitelisting**:
- File: `/home/botofthespecter/ips.txt` (CIDR notation)
- `_is_ip_allowed(ip: str) -> bool` checks whitelist
- Reloads only if file modification time changes
- `/system/uptime` exempt from rate limiting if whitelisted
- Comments (lines starting with `#`) ignored

**Rate Limiting**:
- Per-IP throttling on `/system/uptime` (5 min default)
- In-memory store: `_uptime_requests` dict
- Protected by `_uptime_lock` for concurrency
- Whitelisted IPs bypass entirely

**Password Security**:
- bcrypt via passlib.context.CryptContext
- `pwd_context.verify()` for authentication

**Twitch Token Management**:
- In-memory cache for app credentials (60-second TTL)
- Automatic refresh when stale (>4 hours old)
- Dual sources: `bot_chat_token` table or environment variables

## API Count Management

**Daily/Monthly Resets** (via midnight task):
- **Weather**: 1000 requests/day (UTC midnight reset)
- **Shazam**: 500 requests/month (reset on specific day)
- **ExchangeRate**: 1500 requests/month (reset on specific day)

**Tracking**: `api_counts` table with `updated` timestamp

## FreeStuff Webhook Processing

**Flexible Payload Support**: Multiple webhook shapes supported
**Fields**: game_id, title, store, thumbnail, URL, description, price
**Storage**: `freestuff_games` table with indexes on received_at
**Timestamp**: Extracted from webhook or uses current time

## External Integrations

**Weather API** (OpenWeatherMap):
- Rate limit: 1000 requests/day
- Coordinates via geocoding
- Response: metric/imperial temps, wind, humidity

**Steam App List**:
- Source: Steam Web API
- Cache: Disk file at `/home/botofthespecter/steamapplist.json`
- TTL: 3600 seconds

**Hetrixtools Uptime Monitoring**:
- API: `https://api.hetrixtools.com/v3/uptime-monitors/{monitor_id}/report`
- Auth: Bearer token
- Provides external uptime data for all servers

**Song Information** (Spotify Integration):
- Endpoint: `/api/song`
- Integration: Bot tracks current playing track

## OpenAPI/Swagger Documentation

**V1 & V2 Docs Available**:
- V1: `/v1/docs`, `/v1/openapi.json`, `/v1/redoc`
- V2: `/v2/docs`, `/v2/openapi.json`, `/v2/redoc`
- Custom schemas: ValidationError, HTTPValidationError
- Tags: 8 categories (Public, Commands, User Account, Webhooks, WebSocket, Admin, Extension, Channel)

## Key Configuration

**Environment Variables**:
```
SQL_HOST, SQL_USER, SQL_PASSWORD, SQL_PORT - MySQL
BOT-SRV-HOST, WEB-HOST, SQL-HOST, WEBSOCKET-HOST - SSH hosts
SSH_USERNAME, SSH_PASSWORD
ADMIN_KEY - Master admin API key
WEATHER_API, STEAM_API, TWITCH_OAUTH_API_TOKEN, TWITCH_OAUTH_API_CLIENT_ID
HETRIXTOOLS_API_KEY, HETRIX_MONITOR_* - Monitoring
*_UPTIME_MARKER_PATH - SSH paths for uptime markers
SYSTEM_UPTIME_RATE_LIMIT_SECONDS - Default 300
STEAM_APP_LIST_CACHE_TTL_SECONDS - Default 3600
```

## Architectural Patterns

1. **Dual-layer auth**: V1 (query) + V2 (header) for backwards compatibility
2. **User databases**: Each user gets isolated MySQL database
3. **Central website DB**: Stores global user accounts, tokens, admin keys
4. **Async-first**: All I/O via aiomysql, aiohttp, asyncssh
5. **Error transparency**: Logs everything with full tracebacks
6. **Rate limiting**: Per-IP for public endpoints, whitelist bypass
7. **Token caching**: App credentials cached in-memory with TTL
8. **File-based config**: IP whitelist with mtime-based reload
9. **Scheduled tasks**: Midnight task for API count resets
10. **Extension support**: Public read-only endpoints for Twitch Extension

## Why:** The API server is the central data backbone providing fast, on-demand data access (faster than WebSocket for non-real-time queries). It handles authentication, user database isolation, webhook processing, command management, and integration with external services.

## How to apply:** When adding new endpoints, consider whether data is real-time (use WebSocket) or on-demand (use API). Use per-user databases for command/settings isolation. Handle API counting for rate-limited external services. Implement proper authentication (user key or admin key with service check).
