---
name: BotOfTheSpecter Secondary Systems
description: Dashboard, Overlays, Stream Server (RTMPS), Twitch Extension, and supporting features
type: project
---

# BotOfTheSpecter Secondary Systems - Complete Architecture

## Overview
Collection of user-facing and integration systems that depend on the core BOT/API/WebSocket systems.

## DASHBOARD SYSTEM

**Location**: `./dashboard/`

**Purpose**: Central web UI for managing all BotOfTheSpecter features, settings, stream management, and integrations

**Technology Stack**:
- PHP (server-side rendering, AJAX backends)
- JavaScript (client-side interactivity, Bulma modals)
- MySQL (data persistence via API)
- HTML/CSS (Bulma framework for styling)
- Curl (external API integration)

**Architecture** - Modular PHP application organized by feature:
- **Authentication**: login.php, logout.php, relink.php, session_bootstrap.php (shared domain auth across .botofthespecter.com)
- **Core Pages**: dashboard.php (landing), index.php (redirects to login)
- **Feature Pages**: overlays.php, media.php, bot.php, custom_commands.php, notifications.php, recordings.php, etc.
- **API Layer**: /admin/, /api/ subdirectories for webhooks and internal APIs
- **Libraries**: userdata.php, bot_control.php, mod_access.php, user_db.php, storage_used.php, file_paths.php
- **Config**: `./config/` (dev) / `/var/www/config/` (server) for database.php, db_connect.php, twitch.php (OAuth tokens)

**Integration Points**:
- **Twitch OAuth**: User login via Twitch, OAuth tokens in sessions
- **Twitch EventSub**: Manages subscriptions to Twitch events (webhooks & WebSocket)
- **WebSocket Server**: Sends notifications/commands to overlay clients
- **Bot Backend**: Manages commands, modules, settings
- **RTMP Stream Server**: Stream settings, forwarding config
- **External Services**: Discord, Streamlabs, StreamElements, Hyperchat integrations
- **User Databases**: Per-user MySQL DBs for customization

**Data Storage**:
- **Website DB**: Central database with users, API keys, admin settings, cached followers/bans
- **Per-User DBs**: Individual MySQL database per user (username = DB name)
  - Stores: Profile, commands, modules, settings, rewards, media mappings
- **File Storage**: `/var/www/cdn/` for system music, `/var/www/usermusic/` for user uploads
- **Cache**: `/dashboard/cache/` with per-user follower/banned user JSON files

**Key Features**:
1. User Management (profile settings, API key regen, admin access control)
2. Bot Control (enable/disable, connection status, action commands)
3. Command System (create/edit custom commands with aliases and responses)
4. Rewards System (Twitch channel point rewards, redemption tracking)
5. Notifications/EventSub (configure Twitch event subscriptions, webhooks)
6. Media Management (upload/organize sound alerts, video alerts, walkons, music)
7. Overlays (configure stream overlays, per-overlay settings)
8. Integrations (Discord bot, Streamlabs/StreamElements webhooks, Hyperchat sync)
9. Modules (enable/disable bot modules, module-specific config)
10. Moderator Access (role-based control for mods/managers)
11. Logs & Monitoring (server metrics, WebSocket client monitoring, activity logs)
12. Admin Panel (/admin/ for service administration, API key management, system control)

**Configuration**:
- Session cookies scoped to `.botofthespecter.com` (shared login across subdomains)
- Settings stored in user's personal database
- API keys in website.users table
- Admin credentials in website.admin_api_keys table

**Current Status**: COMPLETE and actively maintained, multiple language support (EN, DE, FR)

---

## OVERLAY SYSTEM

**Location**: `./overlay/`

**Purpose**: Browser-source compatible overlays for OBS/streaming software with real-time WebSocket events

**Technology Stack**:
- PHP (API layer for auth & user preferences)
- HTML/CSS (responsive design)
- JavaScript (WebSocket client via Socket.io 4.8.3)
- Socket.io 4.8.3 (real-time event streaming)

**Architecture** - 20 individual PHP files serve as separate overlay pages, each handling WebSocket events:
- **index.php / all.php** - Master overlay (combines all elements: deaths, weather, discord, alerts)
- **alert.php** - Focused visual alerts (redemptions, donations)
- **sound-alert.php** - Audio-only overlay
- **tts.php** - Text-to-speech audio playback
- **music.php** - Background music player (system or user tracks)
- **walkons.php** - Entrance music + visual
- **chat.php** - Live chat display
- **video-alert.php** - Video playback alerts
- **deaths.php** - Death counter/display
- **weather.php** - Real-time weather
- **discord.php** - Discord join notifications
- **credits.php** - End-of-stream credits roll
- **subathon.php** - Subscription countdown timer
- **todolist.php** - Task list display
- **twitch.php** - General Twitch events
- **working-or-study.php** - Pomodoro / focus session overlay
- **patreon.php, kofi.php, fourthwall.php** - Platform-specific alerts

**Integration Points**:
- **WebSocket Server**: Connects to `wss://websocket.botofthespecter.com` via Socket.io
- **Dashboard**: Users configure overlay URLs with API key
- **User Database**: Fetches per-user settings (timezone, preferences, media sources)
- **Media Servers**:
  - `https://walkons.botofthespecter.com/` for walkon audio
  - `https://music.botspecter.com/{username}/` for user music
  - `/cdn/` for system music
  - TTS audio from bot-generated cache
- **Authentication**: API key as `?code=API_KEY` URL parameter

**Data Storage**: Stateless (all preferences fetched on page load from user DB)

**Key Features**:
1. Real-time event streaming via WebSocket with auto-reconnection
2. Audio queueing (multiple events queued and played sequentially)
3. Visual elements (deaths counter, weather widget, discord joins, donation alerts)
4. Music integration (system DMCA-free + user uploads)
5. TTS support (receives TTS audio URLs)
6. Chat display (real-time chat streaming)
7. Responsive design (works at any resolution as browser source)
8. Timezone awareness (displays in streamer's timezone)
9. Modal/banner animations (slide animations for alerts)

**Configuration**:
- Per-overlay customization via dashboard UI
- Credits: scroll speed, text color, font family, loop toggle
- Music: system vs user source toggle, volume control
- TTS: volume level (30% default)
- Each overlay requires unique browser source with API key

**Current Status**: ACTIVE with ongoing improvements, recommended primary overlay: all.php (20 total .php variants in /overlay/)

---

## STREAM SYSTEM (Custom RTMPS Server)

**Location**: `./stream/`

**Purpose**: Custom RTMP(S) ingest server with multi-region support, live Twitch forwarding, recording, and operator web UI

**Technology Stack**:
- Python 3 (Quart async web framework, pyrtmp for RTMP protocol)
- pyrtmp (RTMP protocol)
- FFmpeg (media transcoding, Twitch streaming)
- Quart (async HTTP for operator UI)
- aiomysql (async MySQL)
- SSL/TLS (Let's Encrypt)

**Architecture** - Multi-process system with 3 components:

1. **RTMPS Server** (port 1935):
   - `SimpleRTMPServer` base class with `RTMP2FLVController`
   - Accepts RTMP(S) streams from OBS/broadcasting software
   - `SessionRegistry` tracks active connections and forwarding state
   - Records incoming stream to FLV format immediately

2. **FFmpeg Forwarder** (spawned per stream):
   - Pipes FLV data to FFmpeg stdin
   - Forwards to Twitch ingest servers with stream key
   - Health monitoring every 5 minutes
   - Graceful shutdown: stdin close → SIGTERM → SIGKILL
   - Real-time stderr logging

3. **Operator Web UI** (Quart app, default port 8080):
   - Dashboard tab: Live sessions with connection details, FLV size, FFmpeg PID
   - Recordings tab: Directory listing from storage with file sizes/timestamps
   - API endpoints for programmatic access

**Integration Points**:
- **Database**: Validates API keys, user DB for streaming settings
- **Twitch**: Sends streams to regional Twitch ingest servers
- **Storage**: FLV files to `/mnt/s3/bots-stream` (US/EU) or local (Sydney)
- **User Databases**: Fetches `streaming_settings` for Twitch stream key & forward flag
- **SSO System**: Uses handoff tokens from `website.handoff_tokens`
- **Admin API**: Accepts admin key for session disconnect

**Data Storage**:
- **FLV Files**: Temporary while streaming (auto-converted to MP4 after)
- **Storage Path**: `{STREAM_ROOT_PATH}/{username}/{date}.mp4`
- **Session Registry**: In-memory tracking of active connections
- **Database**: API key validation, streaming settings, handoff tokens

**Key Features**:
1. Multi-Region Support (Sydney, US-East, US-West, EU-Central with regional Twitch ingest)
2. RTMPS Security (TLS encryption, Let's Encrypt certs)
3. Automatic Forwarding (sends to Twitch if enabled per-user)
4. Stream Recording (captures all streams as FLV, converts to MP4)
5. FFmpeg Health Monitoring (5-minute checks on forwarder health)
6. Session Duration Limit (auto-disconnect after 48 hours)
7. Operator Dashboard (real-time active streams, file sizes, forwarding status)
8. SSO Integration (cross-eTLD cookie-less login via handoff tokens)
9. Admin API (list sessions, disconnect, list recordings, server health)
10. FLV Tee Architecture (simultaneous recording + Twitch forwarding)

**Configuration**:
- Command-line args: `-server {sydney|us-west|us-east|eu-central}`, `--web-host`, `--web-port`, `--recorder-path`
- Environment variables: `SQL_HOST`, `SQL_USER`, `SQL_PASSWORD`, `WEB_SECRET_KEY`, `STREAM_ROOT_PATH`
- Systemd service: `/stream/stream.service` runs `python3 stream.py -server sydney`
- SSL: `/etc/letsencrypt/live/{domain}/` or fallback `/stream/ssl/`

**Auxiliary Scripts** (in `/stream/`):
- `check_videos.py` - Validates/audits recorded MP4 files
- `upload_to_persistent_storage.py` - Pushes recordings to long-term storage
- `twitch-recorder.py` - Standalone Twitch stream recorder (separate from RTMPS ingest)
- `setup.sh` - Server provisioning script

**Current Status**: COMPLETE and actively running in production (4+ regional instances)

---

## TWITCH EXTENSION SYSTEM

**Location**: `./extension/`

**Purpose**: Twitch panel extension displaying streamer stats and counters directly in Twitch dashboard

**Technology Stack**:
- JavaScript (Twitch Extension API)
- HTML/CSS (Panel layout, Bulma)
- Twitch Extension SDK (client-side)
- JSON API calls to BotOfTheSpecter API

**Architecture** - Standard Twitch Extension manifest v2 with two views:

1. **Panel View** (panel.html / panel.js):
   - 13 different stat category buttons
   - Each button fetches data from API endpoint
   - Dynamic table rendering with formatted data
   - Shows totals (deaths, hugs, kisses, etc.)

2. **Config View** (config.html / config.js):
   - Streamer-only configuration page
   - Toggle for enabling/disabling commands display
   - Saves config to Twitch extension storage

**Integration Points**:
- **Twitch Extension API**: OAuth via `onAuthorized`, config via `onChanged`
- **BotOfTheSpecter API**: Calls `/api/extension/{endpoint}?channel_id={channelId}`
- **Channel ID**: Retrieved from Twitch OAuth

**API Endpoints** called by extension:
- `/api/extension/commands` - Custom commands
- `/api/extension/lurkers` - Active lurkers
- `/api/extension/typos` - Typo counts
- `/api/extension/deaths` - Death counters
- `/api/extension/hugs`, `/kisses`, `/highfives` - User interactions
- `/api/extension/custom-counts` - Custom counters
- `/api/extension/user-counts` - User-specific counts
- `/api/extension/reward-counts` - Channel point rewards
- `/api/extension/watch-time` - Viewer watch time
- `/api/extension/quotes` - Saved quotes
- `/api/extension/todos` - To-do list items

**Data Storage**: Stateless (all data fetched on-demand from API)

**Key Features**:
1. 13 Stat Categories (commands, lurkers, typos, deaths, interactions, custom counts, rewards, watch time, quotes, todos)
2. Dynamic Table Rendering (formatted tables with headers)
3. Total Summaries (aggregated counts)
4. Interactive UI (button-based navigation)
5. Loading States (shows "Loading..." during fetch)
6. Error Handling ("Could not load data" on API failure)
7. Formatted Data (duration formatting, human-readable timestamps)
8. Responsive Layout (Bulma CSS, mobile-friendly)

**Configuration**:
- Manifest version: 2
- Author ID: 971436498 (YourStreamingTools)
- Panel height: 300px
- Config height: 300px
- Storage: Broadcaster-scoped config with JSON serialization

**Current Status**: IN PROGRESS / BASIC IMPLEMENTATION (v1.0.0 ready for submission)

---

## Supporting Systems

**TTS, WALKONS, SOUND ALERTS, VIDEO ALERTS, USER MUSIC**:

**Current Status**: Implemented as core features triggered through API/WebSocket, not separate Python services
- **TTS**: Generated by OpenAI in WebSocket TTSHandler, served from `/var/www/html/tts/`
- **Walkons**: Audio/video files served from `walkons.botofthespecter.com/`
- **Sound Alerts**: Triggered via API `/websocket/sound-alert` endpoint
- **Video Alerts**: Triggered via API `/websocket/video-alert` endpoint
- **User Music**: Served from `https://music.botspecter.com/{username}/`

These are managed via the Dashboard UI (`media.php`) and configured through overlays, with core logic in the main bot system and WebSocket server handlers.

---

## YOURLINKS.CLICK SYSTEM

**Location**: `./yourlinks.click/` (sibling of `./dashboard/` at the repo root)

**Purpose**: Per-user URL shortener with wildcard subdomains (`username.yourlinks.click/linkname`) and optional custom domains. Used inside the dashboard to shorten URLs typed into chat-command responses and timed messages.

**Technology Stack**:
- PHP 8.1+ with mysqli (procedural OO mix)
- MySQL: two databases — `yourlinks` (links, categories, expirations) and the shared `website` database (used for API key → twitch_user_id lookup)
- Twitch OAuth for end-user login on the YourLinks site itself
- Apache/Nginx wildcard subdomain virtual host

**Auth model — the important bit**:
- The YourLinks API at `./yourlinks.click/yourlinks.click/services/api.php` accepts the **BotOfTheSpecter user API key** (not a separate YourLinks key) as the `api=` query param.
- It validates that key against `website.users.api_key`, resolves to a `twitch_user_id`, then maps to a record in `yourlinks.users` (creating one on first use from `website.users` data).
- This means: the dashboard does not need a YourLinks-specific token. Whatever is in `$_SESSION['api_key']` (the user's main BotOfTheSpecter key) IS their YourLinks credential.

**API endpoint** (GET, despite "create" semantics):
- `https://yourlinks.click/services/api.php?api=<botofthespecter_api_key>&link_name=<slug>&destination=<url>&title=<optional>`
- Other optional params: `category_id`, `expires_at`, `expired_redirect_url`, `is_active`
- Returns: `{ success: bool, message, data: { link_id, link_name, original_url, title, is_active, created_at } }`
- Error codes: 400 (validation), 401 (bad key), 404 (user/category missing), 409 (link_name already used by this user)
- `link_name` regex enforced upstream: `^[a-zA-Z0-9_-]+$`

**Dashboard integration**:
- Server-side proxy: `./dashboard/api/yourlinks_create.php` — locked-down proxy that reads the API key from `$_SESSION['api_key']`, validates `link_name`/`destination`/`title`, caps body at 4 KB, requires same-origin Origin/Referer, sanitizes upstream errors. Do NOT accept the `api` field from the request body — that was the original (now-fixed) open-proxy hole.
- Client-side widget: `./dashboard/js/yourlinks-shortener.js` — auto-detects URLs in textareas, prompts via SweetAlert, posts to the proxy. Reads the username (only) from a hidden `<input id="yourlinks_username">` for building the preview URL.
- Used on: `./dashboard/custom_commands.php` and `./dashboard/timed_messages.php`. The hidden `yourlinks_api_key` input that used to render the user's main API key into the DOM has been removed — auth happens server-side from session.

**The bot does NOT call this proxy or YourLinks directly.** It is a dashboard-only convenience for shortening URLs in command/timed-message text before they are saved.

**Rules when touching this integration**:
1. Read `./yourlinks.click/yourlinks.click/services/api.php` first — it defines the contract (param names, validation, response shape).
2. The proxy must keep auth server-side: never reintroduce the `api` field in the request body.
3. Same-origin guard plus session check is the auth model — there is no CSRF token infrastructure to lean on yet.

---

## System Relationships

```
┌─────────────────────────────────────────────────────────────┐
│                    DASHBOARD (PHP)                          │
│  - User authentication & configuration                      │
│  - Settings storage in per-user MySQL DBs                   │
│  - Media upload management                                  │
│  - Overlay URL generation                                   │
│  - Stream server settings                                   │
│  - Integration management                                   │
└───────────┬─────────────┬──────────────┬────────────────────┘
            │             │              │
            ▼             ▼              ▼
   ┌──────────────┐  ┌──────────────┐  ┌─────────────────┐
   │  OVERLAYS    │  │ STREAM SERVER│  │  EXTENSION      │
   │  (JS/PHP)    │  │  (Python)    │  │  (JS)           │
   │              │  │              │  │                 │
   │ WebSocket    │  │ RTMPS Port   │  │ Twitch Panel    │
   │ 21 variants  │  │ 1935         │  │ API calls       │
   │              │  │              │  │                 │
   │ Port: 443    │  │ Web UI: 8080 │  │ Auth: OAuth     │
   └──────────────┘  └──────────────┘  └─────────────────┘
        │                   │                   │
        └───────────────────┼───────────────────┘
                            │
                    ┌───────▼────────┐
                    │  BOT API Server│
                    │  WebSocket     │
                    │  MySQL DBs     │
                    └────────────────┘
```

---

## Deployment & Scaling

- **Dashboard**: Multi-user SaaS platform serving thousands of streamers
- **Overlays**: CDN-served PHP pages, scale via browser source instances per user
- **Stream Server**: Multi-region deployment (4 instances minimum: Sydney, US-East, US-West, EU)
- **Extension**: Twitch-hosted, no infrastructure beyond API endpoints
- **Storage**: Persistent block storage for recordings, CDN for media distribution

---

## Why:** Secondary systems provide user-facing interfaces (dashboard, overlays, extension) and specialized services (RTMPS streaming, recording). They all depend on the core BOT/API/WebSocket architecture for real-time events, data storage, and communication.

## How to apply:** Dashboard changes affect user configuration options; overlay changes affect visual display; Stream Server changes affect recording/forwarding; Extension changes affect Twitch panel display. All integrate through API endpoints and WebSocket events. New features typically need changes across multiple systems.
