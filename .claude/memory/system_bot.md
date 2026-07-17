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

Plus companion bots (separate platforms, share databases/integrations):
- **specterdiscord.py** - Discord bot using discord.py
- **kick.py** - Kick.com bot (companion to the Twitch bot for streamers active on Kick)

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

**Command Categories** (sets in `beta.py` / `beta-v6.py`):
1. **Builtin Commands (80+)**: Hardcoded system commands in `builtin_commands` set
   - Core: commands, bot, quote, rps, story, roulette, songrequest, songqueue, watchtime, points, slots, game, joke, ping, weather, time, song, translate, steam, schedule, lurk, uptime, gamble, clip, convert, subathon, …
   - **Working & Study / task list**: `task`, `done`, `rename`, `remove`, `taskclear`, `mytasks`, `now`, `later`, `soon`, `backlog`, `project`, `projects`, `taskhelp`
   - **Personal timers**: `personaltimer` (primary), `checktimer`, `tasktimer`, `timerhelp` (no `!pomo` alias)
2. **Mod Commands**: Moderation + channel control
   - Examples: addcommand, removecommand, editcommand, addpoints, removepoints, permit, shoutout, marker, settitle, setgame, deathadd, createraffle, startraffle, obs, forceoffline, …
3. **Aliases**: Shortcuts mapped onto builtins/mods
   - Examples: cmds, back, so, typocount, death+, death-, mysub, sr, lurkleader, skip, rafflejoin, **ttimer/stimer/ptimer/mytimer/timer/focus/ctimer/thelp** → personal timer / timerhelp
4. **Custom Commands**: User-created commands stored in DB with response templating
5. **Custom User Commands**: Per-user commands with custom responses

**Command Processing**:
- `event_message()` intercepts all chat messages
- Validates prefix (`!`), checks against sets
- Fetches custom commands from database
- Supports dynamic response templating: `(count)`, `(user)`, `(random.*)`, `(math.*)`, `(customapi.*)`, `(game)`

**Cooldown System** (always loaded from `builtin_commands` DB rows):

| Function | Role |
| -------- | ---- |
| `load_builtin_command_settings(cursor, command_name)` | SELECT status, permission, cooldown_rate, cooldown_time, cooldown_bucket |
| `parse_builtin_cooldown_row(result)` | Normalize rate/time/bucket; map legacy `mods` → `mod` |
| `resolve_cooldown_bucket_key(cooldown_bucket, author)` | `default` → key `"global"`; `mod` → `"mod"` if author is mod; else `str(author.id)` |
| `check_cooldown(command, user_id, bucket_type, rate, time_window, …)` | Key `(command, bucket_type, user_id)`; rate/time ≤0 always allow; remaining time announced as `max(1, ceil(…))` (never "0 seconds") |
| `add_usage(command, user_id, bucket_type)` | Append timestamp for the same key shape |

**Bucket policy** (enforced in `builtin_commands_creation()` on bot ready):
- **`per_user_cooldown_commands`**: social/self (`hug`, `highfive`, `kiss`, lurk family, `points`, `watchtime`, gamble/slots/rps/roulette, task cmds, …) → migrate `cooldown_bucket='user'`
- **Global channel-wide** stay/restore `default`: `clip`, `joke`, `ping` (one-at-a-time / shared limit)
- **Task/timer cmds forced to zero cooldown** every ready: `task`, `done`, `rename`, `remove`, `taskclear`, `mytasks`, `now`, `later`, `soon`, `backlog`, `project`, `projects`, `personaltimer`, `checktimer`, `tasktimer`, `taskhelp`, `timerhelp` (`UPDATE … SET cooldown_rate=0, cooldown_time=0`). Dashboard can show non-zero; bot wipes on reboot. Intentional.

**Permissions**:
- Command-level permissions from database: `everyone`, `mod`, `vip`, `sub`, `broadcaster`
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
- `builtin_commands` - Enable/disable + **cooldown_rate / cooldown_time / cooldown_bucket** for every builtin
- `custom_commands`, `custom_user_commands` - Command management
- `bot_points` - User point balances
- `chat_history` - Raw chat messages
- `tipping` - Donation records
- `seen_today` - First-seen tracking
- `user_tasks`, `streamer_tasks`, `user_timers`, project tables - Working & Study
- `spotify_tokens`, `streamelements_tokens`, `discord_users` - OAuth credentials
- `twitch_bot_access` - Bot's Twitch token (website DB for shared bot token)
- `link_blacklist`, `link_whitelist` - URL protection
- `protection` - Channel settings
- `twitch_sound_alerts`, `twitch_chat_alerts` - Alert mappings

## WebSocket Integration

**Internal Specter WebSocket**:
- Socket.io AsyncClient to `https://websocket.botofthespecter.com`
- Registration with API_TOKEN and channel name
- `specter_websocket()` background task with 60-second reconnection delay
- Events emitted include: STREAM_ONLINE, STREAM_OFFLINE, WEATHER_DATA, FOURTHWALL, KOFI, PATREON, SYSTEM_UPDATE, OBS_EVENT_RECEIVED, SOUND_ALERT, **TASK_***, **USER_POMO_***, PROJECT_UPDATE

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

### Working & Study - Task List + Personal Timers (beta + beta-v6)

**Ownership**: Bot is source of truth for chat-driven tasks/timers. Overlay/dashboard consume WebSocket fan-out.

**DB (per-user channel DB)**:
- `user_tasks` / `streamer_tasks` - rows with `status` (`active`/`pending`/…), `task_type` (`task`|`timer`), **`backlog_position`** (per-user 1…N display id, not global autoincrement)
- `user_timers` - active personal timer state (phase, cycles, linked `task_id`)
- `user_active_project` / project registry for `!project`

**Task commands** (handlers on Bot cog):
- `!task` - set/replace active task (assigns next `backlog_position`)
- `!done` / `!rename` / `!remove` / `!taskclear`
- `!later` / `!soon` / `!now` - backlog queue ops
- `!backlog` - list queue
- `!mytasks` - active + packed backlog in **one** chat line (`format_mytasks_chat_message()`, ≤ `MAX_CHAT_MESSAGE_LENGTH` 500) with `+ N more`
- `!project` / `!projects` - project context
- `!taskhelp`

**Important `!mytasks` query**: `status='active' AND task_type='task'` for active; pending backlog ordered by `backlog_position`. Do **not** require `backlog_position IS NULL` (create always sets a position).

**Personal timer** - `personaltimer_command` (`!timer` / `!ptimer` / `!mytimer` / `!focus` / …):
1. **General**: `!timer <mins> <title>` - countdown only, **no** task-list row (`link_task=False`)
2. **Focus**: `!timer <mins> "title" focus` - single focus block + task/overlay row (`link_task=True`, `task_type='timer'`)
3. **Cycles**: `!timer <work>/<break>/<cycles> [label]` - multi-cycle focus/break on list/overlay
- `!timer` / `!checktimer` - remaining time; `!timer stop` - cancel
- `!timerhelp` / `!thelp` - usage text
- Core: `start_user_pomo(..., link_task=)`, cancel/replace single active timer, assign next `backlog_position` for timer tasks
- Emits WebSocket: `USER_POMO_START|CANCEL|UPDATE|PHASE|COMPLETE`, plus `TASK_*` when linked

**Helpers**: `format_mytasks_chat_message`, `promote_backlog_head`, `emit_pomo_event`, `cancel_pomo_routine`, `websocket_notice`

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

**bot.py (stable) vs beta.py**:
- Stable: critical bug fixes only; do not add features
- Beta: custom channel modules (`./bot/custom_channel_modules/`), `-custom` / `-self` modes
- Beta: `MAX_CHAT_MESSAGE_LENGTH` = 500 (stable lower)
- Beta: full Working & Study task list + personal timers, cooldown normalization above

**beta.py vs beta-v6.py**:
- v6 uses TwitchIO 3.2.2 with native EventSub (API differs - do not paste 2.x handlers blindly)
- Task list + personal timer + cooldown helpers are ported for parity; re-check TwitchIO message/ctx APIs when changing either file
- Raffle/puzzle and other v6-only surfaces as implemented in that file

## Logging

**Logger types** (RotatingFileHandler; dirs under `log_types`):
`bot`, `chat`, `twitch`, `api`, `chat_history`, `event_log`, `websocket`, plus `system`, `integrations`

**Path (server)**: `/home/botofthespecter/logs/logs/{log_type}/{CHANNEL_NAME}.txt`

## Configuration Files

- **Main Entry**: `./bot/bot.py` (~10.4k lines, STABLE)
- **Beta**: `./bot/beta.py` (~18.7k lines), `./bot/beta-v6.py` (~16.9k lines)
- **Custom Modules**: `./bot/custom_channel_modules/*.py`
- **Token Refresh Scripts**: `./bot/refresh_custom_bot_tokens.py`, `./bot/refresh_spotify_tokens.py`, `./bot/refresh_streamelements_tokens.py`, `./bot/refresh_discord_tokens.py` (Twitch refresh is **in-process** `twitch_token_refresh()` - there is no `refresh_twitch_tokens.py`)
- **Auxiliary Scripts**: `./bot/setup.py`, `./bot/status.py`, `./bot/status_monitor.py`, `./bot/running_bots.py`, `./bot/export_queue_worker.py`, `./bot/export_user_data.py`, `./bot/sync-channel-rewards.py`, `./bot/system_boot_marker.py`
- **Discord Bot**: `./bot/specterdiscord.py`
- **Kick.com Bot**: `./bot/kick.py`
- **AI History Storage (server)**: `/home/botofthespecter/ai/chat-history/{user_id}.json`
- **Logs (server)**: `/home/botofthespecter/logs/logs/{log_type}/{channel}.txt`

## Key Environment Variables

- `SQL_HOST`, `SQL_USER`, `SQL_PASSWORD` - MySQL
- `OPENAI_KEY` - ChatGPT API key
- `TWITCH_OAUTH_API_TOKEN`, `CLIENT_ID`, `CLIENT_SECRET` - Twitch OAuth
- `SHAZAM_API`, `STEAM_API`, `EXCHANGE_RATE_API` - Third-party APIs
- `HYPERATE_API_KEY` - Heart rate integration
- SSH_* - SSH credentials for remote operations

## Why:** The bot is the core streaming companion: chat, EventSub, cooldowns, Working & Study tasks/timers, tokens, and 13+ external integrations. Three versions keep production stable while beta/v6 take features.

## How to apply:** Target **beta.py** (or beta-v6 for TwitchIO 3.x) for features; stable only for critical bugs. Cooldowns must go through `load_builtin_command_settings` + `check_cooldown`/`add_usage`. Task/timer work must keep `backlog_position` per-user and WebSocket `TASK_*` / `USER_POMO_*` in sync with overlays. Port carefully across TwitchIO versions.

**Last verified**: 2026-07-17 (cooldown helpers, task/timer parity, !mytasks packing, force-zero task cooldowns)
