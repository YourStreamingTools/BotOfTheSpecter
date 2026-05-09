---
name: BotOfTheSpecter BOT System
description: Complete architecture of the Twitch chat bot with three versions (stable, beta, v6 rewrite) and integrations
type: project
---

# BotOfTheSpecter BOT System - Complete Architecture

## Overview
Three-version Twitch chat bot system based on TwitchIO:
- **bot.py (v5.7.7 STABLE)**: Production bot, TwitchIO 2.10.0, never changed except critical bugs
- **beta.py (v5.8 BETA)**: Testing ground for features before stable release, TwitchIO 2.10.0
- **beta-v6.py (v6.0 REWRITE)**: Major rewrite using TwitchIO 3.2.2 with native EventSub support

Plus: **specterdiscord.py (v6.1)** - Separate Discord bot using discord.py

## Core Architecture

**Bot Class Structure**: TwitchIO commands.Bot extending cog-based design
- Single-channel operation per bot instance
- Async event-driven using asyncio
- Command-line args: channel name, channel ID, auth token, refresh token, optional API token

**Key Global State**:
- `scheduled_tasks` - Background async tasks
- `shoutout_queue` - Queue for processing shoutouts
- `command_usage` - Cooldown tracking per command/user
- `connected` - Set of connected users
- Logging: 7 separate loggers (bot, chat, twitch, api, chat_history, event_log, websocket)

## Command System

**Command Categories**:
1. **Builtin Commands (65+)**: Hardcoded system commands
   - Examples: commands, bot, quote, rps, story, roulette, songrequest, songqueue, watchtime, timer, points, slots, game, joke, ping, weather, time, song, translate, steam, schedule, lurk, uptime, gamble
2. **Mod Commands (14)**: Moderation only
   - Examples: addcommand, removecommand, editcommand, addpoints, removepoints, permit, shoutout, marker, checkupdate, startlotto, drawlotto, skipsong, wsstatus, obs
3. **Aliases (13)**: Command shortcuts
   - Examples: cmds, back, so, typocount, death+, death-, mysub, sr, lurkleader, skip
4. **Custom Commands**: User-created commands stored in database with response templating
5. **Custom User Commands**: Per-user commands with custom responses

**Command Processing**:
- `event_message()` intercepts all chat messages
- Validates prefix ('!'), checks against sets
- Fetches custom commands from database
- Supports dynamic response templating with variables: (count), (user), (random.*), (math.*), (customapi.*), (game)

**Cooldown System**:
- Per-command tracking by bucket type: 'default' (global), 'mods', 'user' (individual)
- `check_cooldown()` validates rate/time_window
- `command_usage` dict stores timestamps for clean-up and enforcement

**Permissions**:
- Command-level permissions from database: 'everyone', 'mod', 'vip', 'sub', 'broadcaster'
- Premium features gated by user tier (2000/3000/4000)

## Event Handling

**Twitch EventSub WebSocket**:
- Connection to `wss://eventsub.wss.twitch.tv/ws`
- 600-second keepalive timeout
- `twitch_eventsub()` background task manages connection and event subscriptions
- `subscribe_to_events()` registers event subscriptions using session ID

**Event Types**:
- Channel: follow, subscribe, subscription.message, subscription.gift, bits.use, raid
- Stream: channel.update (title/game), channel.ad_break.begin, hype_train.begin/end
- Moderation: moderate (timeout/ban/unmod)
- Charity: charity_campaign.donate

**Message Processing**:
- `event_message()` handler records to chat_history table
- Checks spam patterns (regex from spam_pattern database)
- Validates source-room-id for cross-channel filtering
- Handles AI mentions (@botname)
- Processes custom commands, link protection, blacklist/whitelist

**Welcome/Lurk System**:
- `message_counting_and_welcome_messages()` tracks first-time users
- Lurk tracking with /lurk and /unlurk commands
- Shoutout triggering on first message (controlled by permit system)

## Major Integrations

**1. OpenAI/ChatGPT**:
- AsyncOpenAI client with API key
- System instructions fetched from `https://api.botofthespecter.com/chat-instructions` (5-min cache)
- Per-user chat history: `/home/botofthespecter/ai/chat-history/{user_id}.json` (max 200 entries)
- Message chunking to respect 255-char Twitch limit
- Used in: @mention AI responses, !story command, premium AI chat

**2. Spotify Token Management**:
- `refresh_spotify_tokens.py` standalone script
- Refreshes via OAuth2 endpoint
- Supports per-user credentials or global fallback
- Updates `spotify_tokens` table

**3. StreamElements & StreamLabs**:
- Dual socket.io connections: `specterSocket` (internal), `streamelements_socket`
- StreamElements tip events → `process_tipping_message()`
- Stored in `tipping` table (username, amount, message, source, tip_id, currency, created_at)

**4. Discord Bot** (specterdiscord.py):
- Separate discord.py application
- Features: voice/music playback, quote system, tickets, role history, streamer posting
- Channel mapping between Twitch and Discord
- MySQL integration for server data

**5. YouTube/yt-dlp**: Song request extraction (URLs, titles, metadata)

**6. Shazam API**: Song detection from raw audio via `shazam_detect_song()`

**7. Steam API**: User profile/game info via !steam command

**8. Twitch GQL**: Advanced Twitch queries beyond REST API

**9. Weather/Geolocation**: Nominatim + Weather API for !weather command

**10. HypeRate**: Heart rate WebSocket connection with periodic broadcasts

**11. Stream Bingo**: Socket.io connection for bingo game events

**12. Exchange Rate API**: Currency conversion via !convert command

**13. Joke API**: !joke command via JokeAPI

## Token Management

**Twitch Token Refresh**:
- `twitch_token_refresh()` background task (starts 5-min delay)
- OAuth2 refresh via `https://id.twitch.tv/oauth2/token`
- Updates global `CHANNEL_AUTH` and database `twitch_bot_access` table
- Adaptive sleep intervals: 1hr if >3600s remaining, 5min if >300s, 1min otherwise

**Spotify, StreamElements, Discord Tokens**:
- Standalone refresh scripts in bot directory
- Each handles OAuth2 refresh with service-specific endpoints

## Database Integration

**Connection Method**: `mysql_connection(db_name=None)` async function via aiomysql
- Default database: CHANNEL_NAME (per-channel tables)
- Special databases: "website" (global), "spam_pattern" (spam rules)

**Key Tables**:
- `builtin_commands`, `custom_commands`, `custom_user_commands` - Command management
- `bot_points` - User point balances
- `chat_history` - Raw chat messages
- `tipping` - Donation records
- `seen_today` - First-seen tracking
- `spotify_tokens`, `streamelements_tokens`, `discord_users` - OAuth credentials
- `twitch_bot_access` - Bot's Twitch token
- `link_blacklist`, `link_whitelist` - URL protection
- `protection` - Channel settings
- `twitch_sound_alerts`, `twitch_chat_alerts` - Alert mappings

## WebSocket Integration

**Internal Specter WebSocket**:
- Socket.io AsyncClient to `https://websocket.botofthespecter.com`
- Registration with API_TOKEN and channel name
- `specter_websocket()` background task with 60-second reconnection delay
- Events emitted: STREAM_ONLINE, STREAM_OFFLINE, WEATHER_DATA, FOURTHWALL, KOFI, PATREON, SYSTEM_UPDATE, OBS_EVENT_RECEIVED, SOUND_ALERT

## Background Tasks (11+ on ready)

1. `check_stream_online()` - Monitor stream status
2. `twitch_token_refresh()` - Keep OAuth tokens fresh
3. `twitch_eventsub()` - Listen to Twitch EventSub events
4. `specter_websocket()` - Connect to internal WebSocket
5. `connect_to_tipping_services()` - StreamElements/StreamLabs
6. `stream_bingo_websocket()` - Bingo game events
7. `midnight()` - Daily reset/cleanup
8. `shoutout_worker()` - Process shoutout queue
9. `periodic_watch_time_update()` - Track watch time
10. `check_song_requests()` - Monitor song request queue
11. `channel_point_rewards()` - Process channel points events
12. `known_users()` - Track regular viewers

## Special Features

**Shoutout System**:
- 2-min global cooldown, 60-min per-user cooldown
- Deduplication via `shoutout_tracker` dict
- Queue-based processing with Worker pattern
- Uses Twitch Shoutout API

**Ad Break Handling**:
- EventSub trigger: `channel.ad_break.begin`
- Suspends chat notifications during ads
- AI instructions cache for ad-specific responses

**URL Protection**:
- Blacklist/whitelist system with regex patterns
- Permission bypass for mods/permitted users
- Auto-delete with notification

**Watch Time Tracking**:
- `periodic_watch_time_update()` polls active users
- Accumulates minutes per user in database

**SSH Tunnel Management**:
- `SSHConnectionManager` class for multiple SSH connections
- Hosts: API, WEBSOCKET, BOT-SRV, SQL, STREAM (3 regions), WEB, BILLING

## Version Differences

**bot.py vs beta.py**:
- Beta adds custom channel module support (dynamically imported)
- Beta supports `-custom` mode for non-botofthespecter channels
- Beta supports `-self` mode (broadcaster uses their own account)
- Beta increases MAX_CHAT_MESSAGE_LENGTH from 240 to 500 chars
- Beta has expanded AI instructions caching

**beta.py vs beta-v6.py**:
- v6.0 uses TwitchIO 3.2.2 with native EventSub support
- v6.0 adds raffle/puzzle commands
- v6.0 has improved moderation tracking and error handling

## Logging

**7 Logger Types** (RotatingFileHandler, 10MB max, 5 backups):
1. bot_logger - General operations, startup, errors
2. chat_logger - Chat processing, commands
3. twitch_logger - Twitch API operations, token refresh
4. api_logger - API responses, AI calls, external service errors
5. chat_history_logger - Raw chat message recording
6. event_logger - EventSub events, subscriptions, moderation
7. websocket_logger - WebSocket connection status, messages

**Path**: `/home/botofthespecter/logs/logs/{log_type}/{CHANNEL_NAME}.txt`
**Format**: `[timestamp] - [level] - [message]`

## Configuration Files

- **Main Entry**: `/P:\GitHub\BotOfTheSpecter\bot\bot.py` (3000+ lines)
- **Custom Modules**: `/P:\GitHub\BotOfTheSpecter\bot\custom_channel_modules/*.py`
- **Token Refresh Scripts**: `refresh_twitch_tokens.py`, `refresh_spotify_tokens.py`, `refresh_streamelements_tokens.py`, `refresh_discord_tokens.py`
- **Discord Bot**: `/P:\GitHub\BotOfTheSpecter\bot\specterdiscord.py`
- **AI History Storage**: `/home/botofthespecter/ai/chat-history/{user_id}.json`
- **Logs**: `/home/botofthespecter/logs/logs/{log_type}/{channel}.txt`

## Key Environment Variables

- `SQL_HOST`, `SQL_USER`, `SQL_PASSWORD` - MySQL
- `OPENAI_KEY` - ChatGPT API key
- `TWITCH_OAUTH_API_TOKEN`, `CLIENT_ID`, `CLIENT_SECRET` - Twitch OAuth
- `SHAZAM_API`, `STEAM_API`, `EXCHANGE_RATE_API` - Third-party APIs
- `HYPERATE_API_KEY` - Heart rate integration
- SSH_* - SSH credentials for remote operations

## Why:** The bot is the core streaming companion, handling chat interactions, event processing, token management, and integration with 13+ external services. Three versions allow stable production operation while developing and testing new features safely.

## How to apply:** When modifying bot behavior, understand which version you're targeting (stable gets only critical bug fixes; beta is testing; v6 is the future). Check if your change needs database schema updates or token refresh modifications. Remember the bot talks to the WebSocket server for real-time events and the API server for data queries.
