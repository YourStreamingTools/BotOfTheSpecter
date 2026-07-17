---
name: BotOfTheSpecter WebSocket Server
description: Real-time event hub using SocketIO/asyncio, central nervous system for all systems
type: project
---

# BotOfTheSpecter WebSocket Server - Complete Architecture

## Overview
Real-time event distribution backbone using asyncio + aiohttp + python-socketio (port 443 HTTPS). Acts as the nervous system, centralizing communication between all systems (dashboards, overlays, bots, OBS, TTS, music, donations, tasks, timers, etc.).

**Core Class**: `BotOfTheSpecter_WebsocketServer` - Manages clients, routes events, coordinates handlers

## Client Management

**Registration Models**:

1. **Regular Clients** - Organized by `channel_code`
   - Dashboards, overlays, bots
   - Multiple clients per code allowed (e.g., "dashboard - music", "overlay - dmca", "bot - twitch")
   - Enforces uniqueness by name (duplicate names disconnect old sessions)

2. **Global Listeners** - Authenticated with admin key
   - Discord bots, monitoring services, system-wide event consumers
   - Access to all events across all channels
   - Must authenticate with ADMIN_KEY from env or database `admin_api_keys` table

**Client Storage Structure**:
```
registered_clients = {
  "code123": [
    {"sid": "abc123", "name": "dashboard - music", "channel": "dashboard", ...},
    {"sid": "def456", "name": "overlay - dmca", "channel": "overlay", ...},
    ...
  ]
}
global_listeners = [
  {"sid": "gh789", "name": "discord-bot", "admin_authenticated": True}
]
```

**Registration Flow** (`register()` handler):
1. Client sends: `{"code": "xxx", "channel": "dashboard|overlay|bot", "name": "...", "global_listener": false}`
2. Server validates code, enforces name uniqueness (disconnects duplicates)
3. Stores under `code` key or global_listeners
4. Returns `SUCCESS` event with registration details

**SID Tracking**: `get_code_by_sid(sid)` reverse lookup for broadcasting

## Event Broadcasting Patterns

**Three Broadcasting Mechanisms**:

1. **`broadcast_event_with_globals(event_name, data, code, source_sid)`**
   - Sends to all clients registered under specific `code`
   - ALSO sends to all `global_listeners`
   - Source client excluded
   - Used for: TWITCH_*, DEATHS, WEATHER, DONATIONS, WALKON, CUSTOM_COMMAND, etc.

2. **`broadcast_to_timer_clients_only(event_name, data, source_sid)`**
   - Sends only to timer-specific clients (Dashboard + Overlay with "timer" in name)
   - Events: SPECTER_TIMER_CONTROL, SPECTER_TIMER_COMMAND, SPECTER_TIMER_STATE, SPECTER_TIMER_UPDATE, SPECTER_SETTINGS_UPDATE, SPECTER_PHASE, SPECTER_TASKLIST*, SPECTER_STATS_REQUEST

3. **`broadcast_to_task_clients_only(event_name, data, source_sid)`**
   - Sends to task-aware clients under the channel code (Working & Study dashboard/overlay + bot-registered clients on that code)
   - Events: `TASK_CREATE`, `TASK_UPDATE`, `TASK_COMPLETE`, `TASK_APPROVE`, `TASK_REJECT`, `TASK_DELETE`, `TASK_SETTINGS_UPDATE`, `TASK_REWARD_CONFIRM`, **`PROJECT_UPDATE`**, and personal timer fan-out **`USER_POMO_START` / `USER_POMO_CANCEL` / `USER_POMO_UPDATE` / `USER_POMO_PHASE` / `USER_POMO_COMPLETE`**
   - Bot is authoritative for chat-started timers: `/notify` only fans out `USER_POMO_*` (does not own timer schedule)

## Complete Event Types (177 total)

**Connection Events**:
- `connect` - Client connects, receives WELCOME
- `disconnect` - Client disconnects, cleanup
- `REGISTER` - Client registration
- `LIST_CLIENTS` - Request client list

**Twitch Events**:
- `TWITCH_FOLLOW` - Follower notification
- `TWITCH_CHEER` - Bits/cheer
- `TWITCH_RAID` - Raid with viewer count
- `TWITCH_SUB` - Subscription (tier, gift status)
- `TWITCH_CHANNELPOINTS` - Channel points redemption

**Stream Status**:
- `STREAM_ONLINE` - Stream went live
- `STREAM_OFFLINE` - Stream ended

**TTS & Audio**:
- `TTS` - Text-to-speech request (text, language_code, gender, voice_name)
- `SOUND_ALERT` - Sound effect trigger
- `WALKON` - User entry audio/video

**Music**:
- `MUSIC_COMMAND` - Play/pause/next/prev/shuffle/repeat/volume
- `NOW_PLAYING` - Current track info
- `MUSIC_SETTINGS` - Persist/broadcast music settings

**OBS Integration**:
- `OBS_EVENT` - Incoming OBS events (scene, recording, streaming, filters, transitions)
- `OBS_REQUEST` - Outgoing OBS commands
- `OBS_EVENT_RECEIVED` - Acknowledgment

**Donations & Monetization**:
- `FOURTHWALL`, `KOFI`, `PATREON` - Platform donations
- `FREESTUFF_ANNOUNCEMENT` - Free game notifications (admin-only)
- `GITHUB_EVENT` - GitHub webhook events (admin-only)

**Game/Event Tracking**:
- `DEATHS` - Player death with text/game
- `WEATHER`, `WEATHER_DATA` - Weather updates
- `DISCORD_JOIN` - Discord voice join
- `RAFFLE_WINNER` - Raffle announcement
- `CUSTOM_COMMAND` - Custom command execution
- `SYSTEM_UPDATE` - System-wide update
- `NOTIFY` - Generic notification

**Specter Timer System**:
- `SPECTER_TIMER_CONTROL` - Start/stop/reset
- `SPECTER_TIMER_COMMAND` - Timer commands
- `SPECTER_TIMER_STATE` - Timer state sync
- `SPECTER_TIMER_UPDATE` - Timer value update
- `SPECTER_SESSION_STATS` - Session statistics
- `SPECTER_SETTINGS_UPDATE` - Settings change
- `SPECTER_PHASE` - Phase/stage change
- `SPECTER_TASKLIST`, `SPECTER_TASKLIST_UPDATE` - Task list
- `SPECTER_STATS_REQUEST` - Request stats

**Task System**:
- `TASK_CREATE` - New task (payload may include `backlog_position`, `task_type`, `project`, owner)
- `TASK_UPDATE` - Property update
- `TASK_COMPLETE` - Mark done (triggers reward if require_approval=false)
- `TASK_APPROVE` - Approve completed task (triggers reward)
- `TASK_REJECT` - Reject, back to incomplete
- `TASK_DELETE` - Remove
- `TASK_REWARD_TRIGGER` - Server → bot only (points)
- `TASK_REWARD_CONFIRM` - Confirm reward applied
- `TASK_SETTINGS_UPDATE` - Settings change
- `PROJECT_UPDATE` - Project registry change

**Personal timers (Working & Study)**:
- `USER_POMO_START` - Start/replace timer (handlers: `handle_user_pomo_start`)
- `USER_POMO_CANCEL` - Cancel / replaced
- `USER_POMO_UPDATE` - Countdown tick (~10s interval when server-assisted)
- `USER_POMO_PHASE` - Focus ↔ break phase change
- `USER_POMO_COMPLETE` - Finished
- Handlers: `handle_user_pomo_start`, `handle_user_pomo_cancel`, `handle_user_pomo_outbound` → `broadcast_to_task_clients_only`

**Chat/Overlay**:
- `CHAT_MESSAGE` - Chat relay to overlay
- `CHAT_CLEAR` - Clear chat
- `CHAT_MESSAGE_DELETE` - Delete specific message
- **`OVERLAY_REFRESH`** - Dashboard requests full browser-source reload (no dedicated handler; falls through generic `/notify` emit to registered clients). Consumed by `overlay/index.php` (meta refresh). Trigger: `dashboard/api/notify_event.php` event `OVERLAY_REFRESH`.

**Stream Bingo** (game):
- `STREAM_BINGO_STARTED`, `_ENDED`, `_EVENT_CALLED`, `_WINNER`, `_EXTRA_CARD`, `_VOTE_STARTED`, `_VOTE_ENDED`, `_ALL_CALLED`

**Special Routes**:
- `/notify?code=X&event=Y&…` - HTTP API to trigger/broadcast events (IP whitelist; used by dashboard `notify_event.php` and API server)
- `/system-update` - Admin-only full system broadcast (`SYSTEM_UPDATE`)
- `/clients` - List all clients (IP-restricted)
- `/heartbeat` - Health check

## Handler Modules

**1. EventHandler** (`event_handler.py`):
- Routes Twitch, stream, game, generic events
- Methods: `handle_twitch_*()`, `handle_stream_online/offline()`, `handle_deaths()`, `handle_weather*()`, `handle_walkon()`, `handle_discord_join()`, `handle_sound_alert()`, `handle_video_alert()`, `handle_custom_command()`, `handle_generic_notify()`
- All validate code from sid, extract required fields, call `broadcast_with_globals()`

**2. MusicHandler** (`music_handler.py`):
- Music player control and settings persistence
- Saves settings to disk: `/home/botofthespecter/music-settings/{code}.json`
- Commands: repeat, shuffle, volume (0-100), next
- Broadcasts MUSIC_SETTINGS to all code clients
- WHAT_IS_PLAYING queries "overlay - dmca" for current track
- NOW_PLAYING relays info to music controller dashboard

**3. TTSHandler** (`tts_handler.py`):
- Text-to-speech generation via OpenAI gpt-4o-mini-tts API
- Async queue with batch processing (3 concurrent requests)
- Flow: Queue → Process batch → OpenAI API → SSH transfer to `/var/www/html/tts/` → Broadcast → Cleanup
- 10 OpenAI voices: alloy, ash, ballad, coral, echo, fable, nova, onyx, sage, shimmer
- Config loaded from `/home/botofthespecter/websocket_tts_config.json`
- Estimates audio duration via ffprobe or word-count
- Auto-deletes local and remote copies after playback

**4. ObsHandler** (`obs_handler.py`):
- OBS WebSocket event routing and state tracking
- Maintains `obs_state[code]`: current_scene, is_recording, is_streaming, is_vcam_active
- Dual-direction:
  - `handle_obs_event()` - Incoming from OBS (broadcasts as OBS_*)
  - `handle_obs_request()` - Commands to OBS (forwards to "obs" client)
- Event categories: scene, source, recording, streaming, filter, virtual camera, transition

**5. DonationEventHandler** (`donation_handler.py`):
- Handles Fourthwall, Ko-Fi, Patreon, StreamLabs, StreamElements donations
- Validates required fields (amount, username)
- Currency formatting ($X.XX)
- Sanitizes messages (max 500 chars, removes harmful)
- Logs donation events

**6. SettingsManager** (`settings_manager.py`):
- Persistent settings storage
- Music settings: `/home/botofthespecter/music-settings/{code}.json`
- General settings: `/home/botofthespecter/general_settings.json`
- Methods: save_music_settings(), load_music_settings(), backup_settings(), restore_settings()
- Validates: repeat/shuffle as bool, volume 0-100

**7. DatabaseManager** (`database_manager.py`):
- MySQL connection pooling and async query execution
- Uses aiomysql with autocommit, supports multi-DB operations
- Methods: get_connection(), execute_query(), insert_record(), update_record(), delete_record(), test_connection()

**8. SecurityManager** (`security_manager.py`):
- IP whitelist enforcement for restricted endpoints
- Whitelist: `/home/botofthespecter/ips.txt` (CIDR networks)
- Allows localhost (127.0.0.1, ::1)
- **Rejects requests carrying X-Forwarded-For or X-Real-IP** (server faces public internet directly, no reverse proxy - these headers are attacker-controlled and trigger a 403 with a WARN log entry)
- Applied to `/clients` and `/notify` endpoints

## SSH Connection Management

**SSHConnectionManager**:
- Pooled SSH connections for TTS file transfer and cleanup
- Timeout: 2 minutes (configurable) before auto-closure
- Connection reuse per hostname
- Periodic cleanup task (60-second interval)
- Supports password or key-based auth
- SFTP for file transfer

**Integration**: TTS uses SSH for MP3 transfer, ownership setting, remote deletion

## Task System Architecture

**Task Workflow**:
1. **Create**: Dashboard or bot → `TASK_CREATE` → `broadcast_to_task_clients_only`
2. **Update**: `TASK_UPDATE` (title/status/project/backlog_position)
3. **Complete**: `TASK_COMPLETE` with `require_approval` flag
   - If false: `emit_task_reward_trigger()` to bot
   - If true: wait for manual approval
4. **Approve**: `TASK_APPROVE` → reward trigger
5. **Reject**: `TASK_REJECT`
6. **Projects**: `PROJECT_UPDATE` for rename/delete/switch

**Chat path**: Bot writes MySQL (`user_tasks` / `streamer_tasks`, `user_timers`) then notifies via HTTP `/notify` with `TASK_*` / `USER_POMO_*`. Overlay badges must show **`backlog_position`** (per-user sequence), not DB autoincrement `id`.

**Reward Trigger** (`emit_task_reward_trigger()`):
- Finds bot client (channel != dashboard/overlay)
- Emits `TASK_REWARD_TRIGGER` with user_id, user_name, task_id, points, task_title
- Bot applies points and may emit `TASK_REWARD_CONFIRM`

**Initial Sync**: Task-aware clients receive `TASK_LIST_SYNC` with empty arrays on connect

## Server Startup & Shutdown

**Startup** (`on_startup()`):
1. Start SSH cleanup task (60-second interval)
2. Start TTS queue processor
3. Write websocket uptime file (UTC timestamp)

**Shutdown** (`on_shutdown()`):
1. Cancel SSH cleanup task
2. Stop TTS processing
3. Cleanup all SSH connections
4. Gracefully disconnect all registered clients
5. Log final status

**Signal Handling**: SIGTERM/SIGINT caught, triggers graceful shutdown sequence

## Authentication & Admin Keys

**Two-Level Auth**:
1. **Environment Variable**: `ADMIN_KEY` from `.env` (legacy fallback)
2. **Database**: `website.admin_api_keys` table with `service` column
   - `service='admin'` → super admin (access all)
   - `service='FreeStuff'`, `service='GitHub'` → service-specific

**Use Cases**:
- Global listener registration requires admin key
- `/system-update` requires admin key
- `/admin/disconnect` requires admin key
- Service-specific events require service-level or super-admin key

## Logging & Monitoring

**Configuration**:
- Default file: `noti_server.log` (configurable via `-f` flag)
- Rotation: 10 MB per file, 10 backups
- Format: `YYYY-MM-DD HH:MM:SS - LEVEL - MESSAGE`
- Levels: DEBUG, INFO, WARNING, ERROR, CRITICAL (via `-l` flag)
- Console + File handlers enabled

**Special Loggers**:
- `socketio` logger - Normal level
- `engineio` logger - Silenced to WARNING (reduce noise)

**Uptime Marker**: `/home/botofthespecter/websocket_uptime` with UTC timestamp (for external monitoring)

## Data Flow Patterns

**Incoming Event Flow**:
```
Client → SocketIO Handler → Extract code/validate → 
Handler Method → broadcast_event_with_globals() → 
All code clients + Global listeners
```

**Timer Event Flow** (specialized):
```
Dashboard Timer Control → handle_specter_timer_control() → 
broadcast_to_timer_clients_only() → Only timer clients
```

**Task Event Flow** (specialized):
```
Dashboard → handle_task_create/update/complete() → 
broadcast_to_task_clients_only() → Dashboard + Overlay task clients
```

**TTS Flow**:
```
Client/HTTP → add_tts_request() → Queue → 
Process batch (3 concurrent) → OpenAI API → 
SSH transfer → emit_tts_event() → Cleanup
```

**OBS Flow**:
```
OBS Plugin → OBS_EVENT → handle_obs_event() → 
broadcast_with_globals() → All clients

Dashboard → OBS_REQUEST → handle_obs_request() → 
Forward to "obs" client → Plugin executes
```

## Configuration Files

- `/home/botofthespecter/.env` - Database, SSH, OpenAI credentials
- `/home/botofthespecter/websocket_tts_config.json` - TTS SSH config
- `/home/botofthespecter/ips.txt` - IP whitelist (CIDR)
- `/home/botofthespecter/music-settings/{code}.json` - Per-channel music prefs
- `/etc/letsencrypt/live/websocket.botofthespecter.com/` - SSL certs

## Why:** Real-time nervous system for dashboards, overlays, bots, OBS, TTS, music, donations, tasks, and timers. Dual registration (per-code + global listeners) covers channel-specific and system-wide consumers.

## How to apply:** Register handlers in `setup_event_handlers()`. Pick broadcast method carefully (`broadcast_event_with_globals` vs timer-only vs task-only). Unknown `/notify` events still fan out to registered clients (used by **`OVERLAY_REFRESH`**). Task/timer events go through `broadcast_to_task_clients_only`. TTS/OBS keep their specialized bidirectional flows.

**Last verified**: 2026-07-17 (USER_POMO_*, PROJECT_UPDATE, OVERLAY_REFRESH, backlog_position on tasks)
