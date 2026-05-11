# Discord API Reference (BotOfTheSpecter)

Self-contained reference for every Discord surface this project touches: the gateway-connected companion bot (discord.py), the OAuth2 user-link flow on the dashboard, REST calls made by both PHP and Python, the OAuth refresh job, and the Bot-token-authed guild reads. Cite repo paths with `./` prefix; server-only paths labelled `(server)`.

Last verified against Discord docs (post-Mar-2025 host migration to `docs.discord.com`) and the in-repo code. Expanded 2026-05-11 with Gateway, Events, Interactions, Components, Embeds, Voice, Permissions, Guild/Role API from live docs.

---

## 1. Overview — surfaces in use

This project uses **three** distinct Discord integrations, each with its own credentials and lifecycle:

| Surface | Auth | Where | Purpose |
| ------- | ---- | ----- | ------- |
| **Bot user (gateway + REST)** | Bot token (`Authorization: Bot <token>`) | `./bot/specterdiscord.py` | Long-lived gateway WebSocket; receives messages, member joins, voice events; sends embeds, manages roles, plays voice. |
| **OAuth2 (Authorization Code)** | Per-user `Bearer <access_token>` | `./dashboard/discordbot.php` | Lets a streamer link their Discord account to their BotOfTheSpecter dashboard so we can read their guild/channel/role lists for configuration UIs. |
| **Bot REST against guild data** | Same Bot token, called from PHP | `./dashboard/discordbot.php` (`fetchGuildChannels`, `fetchGuildRoles`, `fetchGuildVoiceChannels`) | Reads channels and roles from a guild the bot is in — uses the bot token (not the user token) because user OAuth is rate-limited and lacks role visibility for non-owners. |

There is **no Discord interactions webhook (HTTP slash-command POSTs)** in use. Slash commands are delivered over the gateway connection that `specterdiscord.py` opens via `discord.py`. `DISCORD_PUBLIC_KEY` exists in `./bot/.env.example` and `./config/discord.php` but is currently unused by code (reserved for future interactions endpoint).

There are also **no Discord webhooks (incoming `https://discord.com/api/webhooks/...`)** posted to from this codebase. Outbound notifications go via gateway / channel-message REST.

---

## 2. Authentication

### 2.1 Bot token

- **Env var:** `DISCORD_TOKEN` (loaded in `./bot/specterdiscord.py:45` via `python-dotenv`).
- **Header format:** `Authorization: Bot <token>` (note the literal word `Bot ` prefix, distinct from `Bearer`).
- **Used by:** the gateway `IDENTIFY` payload (handled internally by discord.py) and any PHP REST helper that sets `$bot_token` (e.g. `fetchGuildChannels` in `./dashboard/discordbot.php:1540`).
- **Application ID:** `1170683250797187132` (hard-coded in the bot-invite URL at `./dashboard/discordbot.php:4333`). Also available as env var `DISCORD_APPLICATION_ID` (`./bot/specterdiscord.py:54`).
- **Bot invite URL** (built client-side at `./dashboard/discordbot.php:4332-4336`):

  ```text
  https://discord.com/oauth2/authorize
    ?client_id=1170683250797187132
    &permissions=581651049737302
    &integration_type=0
    &scope=bot
  ```

  `permissions=581651049737302` is the bitmask of permissions the bot requests when added to a guild. Decoded, this includes channel/message/role management, voice connect/speak, and member view permissions. Do **not** widen this without checking what cogs need.

### 2.2 OAuth2 application credentials

- **Env vars (Python):** `DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET` (`./bot/refresh_discord_tokens.py:13-14`).
- **PHP config (per [php-config.md](../../../rules/php-config.md) — never `.env`):** `./config/discord.php` exposes:

  ```php
  $public_key       // future use (interactions endpoint signature verify)
  $application_id   // == client_id
  $client_secret
  $bot_token        // same DISCORD_TOKEN as the bot uses
  $client_id        // alias of $application_id
  ```

  Real values live in `/var/www/config/discord.php` (server). The repo file is a stub.

### 2.3 OAuth scopes requested

Decided per-flow at `./dashboard/discordbot.php:1418-1424`:

| Flow | Scope string |
| ---- | ------------ |
| First-time link (also brings the bot into the user's guild) | `identify guilds guilds.members.read connections bot` |
| Relink (user already linked, reauth) | `identify guilds guilds.members.read connections` |

Per the official docs:

- `identify` — `GET /users/@me` without `email`.
- `guilds` — `GET /users/@me/guilds`.
- `guilds.members.read` — `GET /users/@me/guilds/{guild.id}/member`.
- `connections` — third-party connections (currently fetched? code path exists for token validation but no UI consumer noted).
- `bot` — bypasses code-exchange step for the bot install; combined with `permissions=...` adds the bot to the guild the user picks. The dashboard does **both** in one consent screen (user link + bot install) on first-time flow.

### 2.4 Redirect URI

- **Registered:** `https://dashboard.botofthespecter.com/discordbot.php`
- **Hard-coded in:** `./dashboard/discordbot.php:236` (token-exchange call).
- **State param:** generated via `bin2hex(random_bytes(16))` and stored in `$_SESSION['discord_oauth_state']` (`./dashboard/discordbot.php:1414, 226-230`); validated on callback to prevent CSRF.

### 2.5 Token storage — `discord_users` table (central `website` DB)

Single row per BotOfTheSpecter `user_id`. Fields used:

| Column | Purpose |
| ------ | ------- |
| `user_id` | BotOfTheSpecter internal user ID (FK to `users.id`) — primary lookup. |
| `discord_id` | Discord snowflake (string). |
| `access_token` | OAuth2 access token (used as `Bearer ...`). |
| `refresh_token` | OAuth2 refresh token (long-lived). |
| `discord_username`, `discord_discriminator`, `discord_avatar` | Cached profile bits for the dashboard. |
| `expires_at` | Cached expiry (display-only — actual expiry is fetched live from `/oauth2/@me`). |
| `reauth` | `1` when scope set was widened; forces user back through consent. |
| `guild_id` | The streamer's selected Discord server. Used by the bot to scope all server-side features. |
| `manual_ids` | `1` if user typed channel/role IDs manually (skip API auto-discovery). |
| `live_channel_id`, `online_text`, `offline_text` | Live-channel rename/announce settings. |
| `stream_alert_channel_id`, `stream_alert_everyone`, `stream_alert_custom_role` | Stream-online alert post target + ping settings. |
| `moderation_channel_id`, `alert_channel_id` | Moderation log + general alert channels. |
| `member_streams_id` | Channel for member-of-server stream announcements. |

Rules:

- **One row per user.** Upserts use `INSERT ... ON DUPLICATE KEY UPDATE` (`./dashboard/discordbot.php:198, 272`).
- **Never log the full `access_token` or `refresh_token`.** See [secrets.md](../../../rules/secrets.md).
- **`bot_token` lives in `./config/discord.php` and `DISCORD_TOKEN` env, not in `discord_users`.** That table holds per-user OAuth tokens only.

### 2.6 Companion table — `channel_mappings` (DB: `specterdiscordbot`)

Maps a BotOfTheSpecter `channel_code` to a guild + announcement channel. Distinct from `discord_users` because one streamer can map multiple guilds, and the bot needs lookup-by-code for WebSocket events.

Fields: `channel_code`, `user_id`, `username`, `twitch_display_name`, `twitch_user_id`, `guild_id`, `guild_name`, `channel_id`, `channel_name`, `stream_alert_channel_id`, `moderation_channel_id`, `alert_channel_id`, `online_text`, `offline_text`, `is_active`, `event_count`, `last_event_type`, `last_seen_at`, `created_at`, `updated_at`. See `./bot/specterdiscord.py:800-820`.

---

## 3. Bot — discord.py setup

### 3.1 Library

- `discord.py` (latest stable; pinned only as `discord.py` in `./bot/requirements.txt:40`).
- Extensions:
  - `discord.ext.commands` — prefix-command framework (`!quote`, `!play`, etc.).
  - `discord.ext.voice_recv` (`discord-ext-voice-recv` package) — receive incoming voice for AI-realtime feature.
  - `discord.app_commands` — slash-command tree.
- Audio deps: `PyNaCl` (voice encryption), `yt_dlp` (YouTube extraction for `!play`).

Internal `VERSION = "6.1"` in `./bot/specterdiscord.py:37`.

### 3.2 Intents

Defined at `./bot/specterdiscord.py:2057-2060`:

```python
intents = discord.Intents.default()
intents.message_content = True   # PRIVILEGED — gates Message.content access
intents.voice_states = True      # voice channel presence
intents.members = True           # PRIVILEGED — on_member_join, member cache
```

Privileged intents (`message_content`, `members`) require the toggle in the **Discord Developer Portal → Bot → Privileged Gateway Intents** *and* the code line above. Without `message_content`, prefix commands break silently because `Message.content` returns empty. Without `members`, `on_member_join` never fires.

`intents.presences` is **not** enabled (and isn't needed; no presence-tracking features).

### 3.3 Bot construction

```python
class BotOfTheSpecter(commands.Bot):
    def __init__(self, discord_token, ...):
        super().__init__(command_prefix="!", intents=intents, **kwargs)
```

`./bot/specterdiscord.py:2055-2061`. Single global prefix `!`. No mention prefix.

### 3.4 Lifecycle hooks

| Hook | File:line | Notes |
| ---- | --------- | ----- |
| `setup_hook()` | `:2168-2177` | Calls `await self.tree.sync()` to register all global slash commands; assigns `self.tree.on_error`. Runs **before** the bot connects to gateway. |
| `on_ready()` | `:2131-2166` | Loads cogs, starts `WebsocketListener`, kicks off `LiveChannelManager.sync_all_channels_on_boot()`, writes version to `/var/www/logs/version/discord_version_control.txt` (server). |
| `on_app_command_error()` | `:2179-2184` | Swallows `CommandNotFound` (other bots' commands), logs the rest. |
| `on_interaction()` | `:2186-2259` | Handles persistent message-component clicks (`role_*` and `rules_accept_*` custom IDs). Calls `interaction.response.defer(ephemeral=True)` first to dodge the 3-second token expiry, then mutates roles. |
| `on_member_join()` | `:2261-2287` | Looks up `channel_code` from `channel_mappings`, fires a `DISCORD_JOIN` event to the WebSocket server via `GET https://websocket.botofthespecter.com/notify`. |

### 3.5 Cogs (loaded in `on_ready`)

`./bot/specterdiscord.py:2147-2160`:

- `QuoteCog` — registers `!quote` (prefix) **and** `/quote` (slash) via `bot.tree.add_command(app_commands.Command(...))` at `:4084-4090`. This is the only slash command in the codebase.
- `UtilityCog` — `!linktwitch`, `!unlinktwitch`, `!timestamp`/`!ts`/`!discordtime`, `!epochnow`/`!now`/`!currenttime`.
- `TicketCog` — `!ticket`, `!setuptickets`.
- `VoiceCog` — `!connect`/`!join`/`!summon`, `!disconnect`/`!leave`/`!dc`, `!voice_status`/`!vstatus`, `!play`, `!skip`/`!s`/`!next`, `!stop`/`!pause`, `!queue`/`!q`/`!playlist`, `!song`/`!np`/`!nowplaying`, `!volume`, `!realtime`/`!talk` (currently disabled), `!stoprealtime`.
- `StreamerPostingCog` — `!settings`.
- `ServerManagement` — receives WebSocket-driven posts (reaction roles, rules, schedule, custom embed).
- `RoleHistoryCog` — `!rolehistoryinfo`, `!rolehistorycleanup`, `!rolehistoryscan`, `!rolehistorystatus` (all admin-only).
- `RoleTrackingCog` — `!refreshreactionroles` (admin).
- `ServerRoleManagementCog` — internal role utilities.
- `MessageTrackingCog` — message edit/delete logging.
- `ModerationCog` — `!purge`/`!clear`/`!delete`.
- `AdminCog` — `!checklinked`, `!online_streams`, `!live_status` (bot-owner only, `bot_owner_id = 127783626917150720`).
- `UserTrackingCog` — internal user activity.

**Internal command names** (never overridable by user-defined custom commands): see set at `./bot/specterdiscord.py:2078-2093`.

### 3.6 Per-Twitch-channel state

Discord events route to Twitch channels via the `channel_mappings` table (DB `specterdiscordbot`). Key flows:

- **WebSocket → Discord:** `WebsocketListener` (`:625-772`) connects as a **global listener** with the admin key. Receives Twitch events keyed by `channel_code`, looks up the matching guild, posts to the configured channels.

  Events handled: `TWITCH_FOLLOW`, `TWITCH_SUB`, `TWITCH_CHEER`, `TWITCH_RAID`, `STREAM_ONLINE`, `STREAM_OFFLINE`, `POST_REACTION_ROLES_MESSAGE`, `POST_RULES_MESSAGE`, `POST_STREAM_SCHEDULE_MESSAGE`, `POST_CUSTOM_EMBED`, `FREESTUFF_ANNOUNCEMENT`. Plus a wildcard `*` handler that ignores `OBS_*` and `CHAT_MESSAGE` events.

- **Discord → WebSocket:** `on_member_join` posts to `https://websocket.botofthespecter.com/notify?code={channel_code}&event=DISCORD_JOIN&member={name}` (`:2279`).

- **Discord → Twitch link:** `!linktwitch` and `!unlinktwitch` call BotOfTheSpecter API endpoints with the `ADMIN_KEY` to associate the Discord user's `discord_id` with a Twitch user.

### 3.7 Rate-limit handling in the bot

Custom decorator `@handle_rate_limit(max_retries=3)` at `./bot/specterdiscord.py:109-159` catches `discord.RateLimited` and `discord.HTTPException` with status 429, reads `Retry-After`, `X-RateLimit-Global`, and `X-RateLimit-Bucket` from the response, records to a global `RateLimitTracker` (`:78-106`), and exponentially backs off (capped at 60 s).

discord.py already has its own internal rate-limit handler — this decorator layers on top for resilience and is applied to specific high-risk routes (DM sends, role mutations).

---

## 4. REST endpoints we call

### 4.1 OAuth2 endpoints (PHP, user-facing)

All are `application/x-www-form-urlencoded` and authenticate via HTTP Basic with `client_id:client_secret`.

| Method | URL | Purpose | Repo callsite |
| ------ | --- | ------- | ------------- |
| `GET` | `https://discord.com/oauth2/authorize` | Consent screen redirect. Built by StreamersConnect proxy in this repo. | `./dashboard/discordbot.php:1430-1435`, `4332-4336` |
| `POST` | `https://discord.com/api/oauth2/token` | Exchange `code` for tokens. Body: `grant_type=authorization_code&code=<code>&redirect_uri=<uri>`. | `./dashboard/discordbot.php:232-249` |
| `POST` | `https://discord.com/api/oauth2/token` | Refresh. Body: `grant_type=refresh_token&refresh_token=<token>`. | `./bot/refresh_discord_tokens.py:55-59` |
| `POST` | `https://discord.com/api/oauth2/token/revoke` | Revoke a token (called on user-initiated unlink). Body: `token=<token>&token_type_hint=access_token` *or* `refresh_token`. | `./dashboard/discordbot.php:1441-1457` |
| `GET` | `https://discord.com/api/v10/oauth2/@me` | Validate access token + return current authorization (user, scopes, expires). | `./dashboard/discordbot.php:99-108` |

### 4.2 User endpoints (Bearer auth)

| Method | URL | Scope required | Purpose | Repo callsite |
| ------ | --- | -------------- | ------- | ------------- |
| `GET` | `https://discord.com/api/users/@me` | `identify` | Fetch linked user's profile (id, username, discriminator, avatar). Called on first-link to capture `discord_id`. | `./dashboard/discordbot.php:254` |
| `GET` | `https://discord.com/api/v10/users/@me/guilds` | `guilds` | List guilds the user is in. Used to find guilds where user is **owner** (filtered at `:1517-1521`). Returns up to 200 partial guild objects (no pagination needed in practice). | `./dashboard/discordbot.php:1463` (helper `fetchUserGuilds`) |
| `GET` | `https://discord.com/api/v10/users/@me/guilds/{guild_id}/member` | `guilds.members.read` | Get the linked user's member object in a specific guild (used for permission checks). | `./dashboard/discordbot.php:1488` (helper `checkGuildPermissions`) |

### 4.3 Guild endpoints (Bot token auth — PHP)

These hit `Authorization: Bot <bot_token>` because (a) the user's Bearer scope can't see all roles, and (b) the bot is already in the guild so it has the privileges. Falls back to `Authorization: Bearer <access_token>` if `$bot_token` is empty (`:1539-1540`).

| Method | URL | Purpose | Repo callsite |
| ------ | --- | ------- | ------------- |
| `GET` | `https://discord.com/api/v10/guilds/{guild_id}/channels` | List guild channels — filtered to type `0` (`GUILD_TEXT`) + `5` (`GUILD_ANNOUNCEMENT`) for text-channel pickers, or type `2` (`GUILD_VOICE`) for voice pickers. | `./dashboard/discordbot.php:1541` (`fetchGuildChannels`), `:1586` (`fetchGuildVoiceChannels`) |
| `GET` | `https://discord.com/api/v10/guilds/{guild_id}/roles` | List roles, filtered to drop `@everyone`, `managed` (bot/integration), and `tags`-bearing roles. Sorted by `position` desc. | `./dashboard/discordbot.php:1630` (`fetchGuildRoles`) |

### 4.4 Channel-type integers

From the official spec, used in PHP filters at `:1554-1557` and `:1599-1601`:

| Type | ID | Notes |
| ---- | -- | ----- |
| `GUILD_TEXT` | 0 | Standard text channel |
| `DM` | 1 | Direct message |
| `GUILD_VOICE` | 2 | Voice channel |
| `GROUP_DM` | 3 | Group DM |
| `GUILD_CATEGORY` | 4 | Category container |
| `GUILD_ANNOUNCEMENT` | 5 | News/announcement channel (treated as text in this codebase) |
| `PUBLIC_THREAD` | 11 | |
| `PRIVATE_THREAD` | 12 | |

### 4.5 REST endpoints called via discord.py (gateway)

All channel-message sends, role mutations, embed posts, voice connects, etc. issued from `./bot/specterdiscord.py` go through the `discord.py` REST client, which uses the same `https://discord.com/api/v10/...` base. discord.py handles auth, version, rate-limit buckets, and retries internally — we don't construct these URLs by hand. Notable cases:

- `Channel.send(content=, embed=)` → `POST /channels/{channel_id}/messages`
- `Member.add_roles(role)` / `Member.remove_roles(role)` → `PUT/DELETE /guilds/{guild_id}/members/{user_id}/roles/{role_id}`
- `Guild.fetch_member(user_id)` → `GET /guilds/{guild_id}/members/{user_id}`
- `Channel.set_permissions(target, **overwrites)` → `PUT /channels/{channel_id}/permissions/{overwrite_id}`
- `Interaction.response.defer()` / `.send_message()` → `POST /interactions/{id}/{token}/callback`

---

## 5. OAuth flow (as implemented)

```text
┌──────────────┐                                              ┌─────────────────┐
│  Browser     │                                              │ discord.com     │
│ (streamer)   │                                              │ (OAuth2 auth)   │
└──────┬───────┘                                              └────────┬────────┘
       │   1. Click "Link Discord" on dashboard                       │
       │                                                              │
       │   ----> dashboard/discordbot.php builds state, redirects --->│
       │   GET /oauth2/authorize?client_id=...&scope=identify+guilds  │
       │       +guilds.members.read+connections+bot                   │
       │       &redirect_uri=https://dashboard.botofthespecter.com/   │
       │       discordbot.php&state=<hex>&response_type=code          │
       │                                                              │
       │   2. User consents (and picks guild for `bot` scope)         │
       │                                                              │
       │   <---- 302 with ?code=<code>&state=<hex> ----               │
       │                                                              │
       │   3. dashboard/discordbot.php callback handler:              │
       │      - validates session state matches query state (CSRF)    │
       │      - POST https://discord.com/api/oauth2/token             │
       │           Authorization: Basic base64(client_id:secret)      │
       │           grant_type=authorization_code&code=<code>          │
       │           &redirect_uri=...                                  │
       │      - receives { access_token, refresh_token, expires_in }  │
       │      - GET https://discord.com/api/users/@me                 │
       │           Authorization: Bearer <access_token>               │
      v│      - INSERT/UPSERT discord_users (user_id, discord_id,     │v
       │           access_token, refresh_token, reauth=0)             │
       │      - redirect to discordbot.php (clean URL)                │
       │                                                              │
       │   4. Subsequent loads validate liveness via                  │
       │      GET /api/v10/oauth2/@me — if response includes user{},  │
       │      mark $is_linked=true and surface expiry from the        │
       │      response's `expires` field.                             │
       │                                                              │
       │   5. Background job ./bot/refresh_discord_tokens.py runs     │
       │      periodically (cron, server-side); for each row          │
       │      with a refresh_token, POST /api/v10/oauth2/token        │
       │      with grant_type=refresh_token, updates                  │
       │      access_token and (if returned) refresh_token in DB.     │
       │                                                              │
       │   6. Unlink path (POST disconnect_discord=1):                │
       │      - revoke refresh_token via                              │
       │        POST /api/oauth2/token/revoke (token_type_hint=       │
       │        refresh_token), revokes all linked tokens             │
       │      - revoke access_token (defensive second call)           │
       │      - DELETE FROM discord_users WHERE user_id = ?           │
       └──────────────────────────────────────────────────────────────┘
```

### 5.1 StreamersConnect proxy

The dashboard's "Link Discord" button does **not** point at `discord.com/oauth2/authorize` directly. It points at `https://streamersconnect.com/?service=discord&login=<host>&scopes=<scopes>&return_url=<url>` (`./dashboard/discordbot.php:1429-1435`). StreamersConnect proxies the OAuth flow and posts the result back as `?auth_data=<base64-json>` on `discordbot.php`. Both code paths (direct `?code=` and proxied `?auth_data=`) are handled in the same callback handler (`:185-307`).

### 5.2 Reauth flag

When scope set is widened (e.g. adding `bot` to an existing user, or rolling out `guilds.members.read`), set `discord_users.reauth = 1`. The dashboard sees `reauth=1` and forces the user back through the consent screen on next visit (`:88-91`). After a successful relink the column is reset to `0` (`:198, 272`).

---

## 6. Rate limits

### 6.1 Global

50 requests/sec across the entire bot/application. Hitting this returns `429` with `X-RateLimit-Global: true` and a `Retry-After` (seconds, float). discord.py handles the global limit transparently for in-library calls; the custom decorator at `./bot/specterdiscord.py:109` records the global state separately for diagnostics.

### 6.2 Per-route buckets

Discord groups endpoints into **buckets**; the same `bucket` string can span multiple routes. Headers on every response:

| Header | Meaning |
| ------ | ------- |
| `X-RateLimit-Limit` | Max requests in the window |
| `X-RateLimit-Remaining` | Requests left in the window |
| `X-RateLimit-Reset` | Unix timestamp (float, seconds) when the bucket resets |
| `X-RateLimit-Reset-After` | Seconds until reset (float) |
| `X-RateLimit-Bucket` | Bucket identifier — **use this** as the per-bucket key, not the URL |
| `X-RateLimit-Scope` | `user` \| `global` \| `shared` |
| `X-RateLimit-Global` | `true` if the 429 was the global cap |
| `Retry-After` | Seconds to wait before retrying on a 429 |

**Do not hard-code limits.** Always parse headers — Discord adjusts buckets server-side without notice.

### 6.3 Invalid request limit

If an IP returns 10 000+ invalid responses (`401`, `403`, `429`) in 10 minutes, Cloudflare temporarily bans the IP. Avoid by:

- Removing rows from `discord_users` instead of leaving stale tokens that 401.
- Catching `discord.Forbidden` (`403`) and not retrying.
- Letting `Retry-After` elapse before retrying 429s.

### 6.4 PHP REST helpers

The PHP helpers in `./dashboard/discordbot.php` use plain `file_get_contents` + `stream_context_create` and **don't** parse rate-limit headers. They rely on low call volume (one user clicking the dashboard at a time) staying well under bucket limits. If usage scales, port these to `cURL` and respect `Retry-After`.

---

## 7. discord.py library notes

### 7.1 Version

Pinned only as `discord.py` in `./bot/requirements.txt:40` (no version pin). Latest stable at writing: 2.7.x. Code uses APIs introduced in 2.x (`app_commands`, `commands.Bot.tree`, `Interaction.response.defer`).

### 7.2 Privileged intent gotchas

- `intents.message_content = True` — required for **prefix** commands (`!play`, `!quote`). Without it, `Message.content` returns empty on guild messages and **all `commands.command(...)` handlers silently stop firing**. Bots in 100+ servers must request approval; below that, just toggle in the Developer Portal.
- `intents.members = True` — required for `on_member_join`, accurate `Guild.members` cache, and role-add by-ID lookups. Same approval rules.
- `intents.presences` — **not enabled here**, and don't enable it casually: it produces high event volume and gates verification.
- Failure mode: gateway closes with code `4014` (`Disallowed Intent(s)`) if you flip an intent in code without flipping it in the portal.

### 7.3 Slash commands via `CommandTree`

- One global tree per bot (`bot.tree`).
- `bot.tree.add_command(app_commands.Command(name=, description=, callback=))` registers — see `./bot/specterdiscord.py:4084-4090` for `/quote`.
- `await bot.tree.sync()` in `setup_hook` pushes the registered commands to Discord's global slash-command list. **Global syncs propagate over up to an hour**; for fast iteration, use guild-scoped sync (`bot.tree.sync(guild=discord.Object(id=...))`).
- Slash callbacks receive `discord.Interaction`. **You have 3 seconds to respond** before the token expires; if work will take longer, call `await interaction.response.defer(ephemeral=True)` first, then `interaction.followup.send(...)`.
- `app_commands.AppCommandError` is the parent of all slash errors. Hook `tree.on_error` (`:2176`) to log; swallow `app_commands.CommandNotFound` to avoid noise from other bots.

### 7.4 Interactions / persistent components

`on_interaction(interaction)` (`:2186-2259`) handles **message-component** interactions (button clicks). The `custom_id` namespacing convention here:

- `role_<role_id>` — toggle a self-assignable role.
- `rules_accept_<role_id>` — accept rules and gain the entry role.

Both call `interaction.response.defer(ephemeral=True)` first (avoids the 3-second timeout while role mutations happen), then `interaction.followup.send(...)` via the helper `send_interaction_response`.

### 7.5 Voice

- `discord.ext.voice_recv` (separate package) for incoming voice — used by `!realtime` (currently disabled).
- `PyNaCl` is **mandatory** for any voice features; missing it fails at connect with a cryptic libsodium error.
- `yt_dlp` cookies file at `/home/botofthespecter/ytdl-cookies.txt` (server) — required for age-gated YouTube content in `!play`.

### 7.6 Logging

The bot uses 7 loggers (see CLAUDE.md). Discord-related ones:

- `discord` — discord.py library output (set per-instance to `INFO`).
- `RateLimitTracker` — custom 429 tracker.
- File output: `/home/botofthespecter/logs/specterdiscord/discordbot.txt` (server).

Never log full tokens. The refresh script logs only username + last-4 on success/failure (`./bot/refresh_discord_tokens.py:79-81`).

### 7.7 Cog discovery

Cogs are added in `on_ready` (not `setup_hook`). This is unconventional (the modern recommendation is `setup_hook`) but works because `tree.sync` already ran in `setup_hook` and the cogs in this codebase don't define new app commands — they only add prefix commands which don't need a re-sync.

---

## 8. Token refresh flow

`./bot/refresh_discord_tokens.py` — standalone script run on a schedule (cron on the bot host).

### 8.1 Sequence

1. Load `DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET` from `./bot/.env` (`./bot/refresh_discord_tokens.py:13-14`).
2. Open an `aiomysql` pool against the central `website` DB.
3. `SELECT user_id, refresh_token FROM discord_users WHERE refresh_token IS NOT NULL AND refresh_token != ''` (`:113`).
4. For each row, in parallel via `asyncio.gather`:
   - Build HTTP Basic header: `base64(client_id:client_secret)` (`:57`).
   - `POST https://discord.com/api/v10/oauth2/token` with `grant_type=refresh_token&refresh_token=<token>`, header `Content-Type: application/x-www-form-urlencoded` + `Authorization: Basic <b64>`.
   - On `200 + access_token in body`: `UPDATE discord_users SET access_token=%s, refresh_token=%s WHERE user_id=%s`. Discord *may or may not* return a new `refresh_token` — fall back to the old one if absent (`:64`).
   - On error: log the `error` + `error_description` and the username for ops visibility (`:79-81`). Common failure: `invalid_grant` (refresh token revoked because user removed the app or didn't reauth after a scope change → mark for relink).
5. Print summary counts and close the pool.

### 8.2 Logging

Rotating file handler at `<script_dir>/logs/refresh_discord_tokens.log`, 50 KB × 5 backups (`:24-29`). Username (not user_id) is shown for human-readable failures.

### 8.3 Cadence

Discord access tokens last 7 days (`expires_in: 604800`). Run the refresh script **at least daily** to stay well clear of expiry, and ideally every 6–12 hours to absorb partial failures.

### 8.4 Failure handling

A failed refresh **does not** delete the row or null out the tokens — the user remains "linked" in the dashboard until the next page load, which calls `GET /oauth2/@me` and discovers the access token is dead, then surfaces a relink button. `reauth=1` is **not** set automatically on refresh failure; that flag is reserved for scope-set changes.

---

## 9. Quick-reference cheat sheet

| Need to... | Use this |
| ---------- | -------- |
| Send a message as the bot | `discord.py` `channel.send(...)` (don't hand-roll REST) |
| Read a guild's channel list from the dashboard | `fetchGuildChannels($access_token, $guild_id)` in `./dashboard/discordbot.php` (uses bot token internally) |
| Read a guild's role list | `fetchGuildRoles($guild_id, $access_token)` |
| Validate a user's access token is still alive | `GET https://discord.com/api/v10/oauth2/@me` with `Bearer` |
| Refresh a user's access token | Let `./bot/refresh_discord_tokens.py` cron handle it; don't refresh ad-hoc |
| Add a new slash command | `bot.tree.add_command(app_commands.Command(...))` in a cog's `__init__`; `tree.sync()` already runs in `setup_hook` |
| Add a new prefix command | `@commands.command(name="...")` inside a cog, then add cog in `on_ready` |
| React to a Twitch event in Discord | Add an `@self.specterSocket.event` handler in `WebsocketListener.start()` (`./bot/specterdiscord.py:625`) |
| Map a new guild to a Twitch channel | INSERT into `channel_mappings` (DB `specterdiscordbot`); the bot picks it up on the 5-minute refresh cycle (`:792-794`) |
| Trigger a Discord message from the dashboard | Emit a WebSocket event (`POST_REACTION_ROLES_MESSAGE`, `POST_RULES_MESSAGE`, `POST_STREAM_SCHEDULE_MESSAGE`, `POST_CUSTOM_EMBED`) — the bot's `WebsocketListener` forwards to `ServerManagement` cog |

---

## 11. Gateway (raw WebSocket)

discord.py manages the gateway internally, but knowing the protocol helps when debugging connection issues or implementing low-level features.

### 11.1 Connection lifecycle

1. **GET `/gateway/bot`** — fetch the WSS URL + `shards` + `max_concurrency` recommendation. discord.py calls this automatically.
2. **Connect to `wss://gateway.discord.gg/?v=10&encoding=json`** (or etf/zlib-stream).
3. **Receive Opcode 10 Hello** — contains `heartbeat_interval` (ms).
4. **Send Opcode 2 Identify** with bot token + intents bitmask.
5. **Receive Opcode 0 READY** — contains `session_id`, `resume_gateway_url`, partial guild list.
6. **Heartbeat loop** — send Opcode 1 every `heartbeat_interval` ms (first beat after `interval × random_jitter`). `d` = last received sequence number (or `null`). Expect Opcode 11 ACK back.
7. On disconnect: if resumable close code, send **Opcode 6 Resume** to `resume_gateway_url`. Otherwise re-Identify.

### 11.2 Gateway opcodes

| Code | Name | Direction | Purpose |
| ---- | ---- | --------- | ------- |
| 0 | Dispatch | Receive | Event payload (MESSAGE_CREATE, etc.) |
| 1 | Heartbeat | Send/Recv | Keepalive |
| 2 | Identify | Send | Initial handshake — sends token + intents |
| 6 | Resume | Send | Resume a dropped session |
| 7 | Reconnect | Receive | Server requests clean reconnect |
| 9 | Invalid Session | Receive | Session terminated (re-Identify or wait + re-Identify) |
| 10 | Hello | Receive | Sends `heartbeat_interval` |
| 11 | Heartbeat ACK | Receive | Confirms heartbeat received |

### 11.3 Intents bitmask

Intents gate which events the gateway delivers. Send them as a bitwise OR in the Identify payload.

**Privileged — require portal toggle AND code:**

| Intent | Bit | Value | Events gated |
| ------ | --- | ----- | ------------ |
| `GUILD_MEMBERS` | 1 | 2 | `GUILD_MEMBER_ADD/UPDATE/REMOVE`, member cache |
| `GUILD_PRESENCES` | 8 | 256 | `PRESENCE_UPDATE` |
| `MESSAGE_CONTENT` | 15 | 32768 | `Message.content`, `Message.attachments`, `Message.embeds`, `Message.components` in guild messages |

**Non-privileged (enabled freely):**

| Intent | Bit | Value | Events gated |
| ------ | --- | ----- | ------------ |
| `GUILDS` | 0 | 1 | `GUILD_CREATE/UPDATE/DELETE`, `CHANNEL_*`, `ROLE_*`, `THREAD_*` |
| `GUILD_MODERATION` | 2 | 4 | Bans, audit log |
| `GUILD_VOICE_STATES` | 7 | 128 | `VOICE_STATE_UPDATE` |
| `GUILD_MESSAGES` | 9 | 512 | `MESSAGE_CREATE/UPDATE/DELETE` in guilds |
| `GUILD_MESSAGE_REACTIONS` | 10 | 1024 | `MESSAGE_REACTION_ADD/REMOVE` |
| `DIRECT_MESSAGES` | 12 | 4096 | DM `MESSAGE_CREATE` |

**What this bot requests** (`./bot/specterdiscord.py:2057-2060`):

```python
intents.default()          # GUILDS | GUILD_MODERATION | GUILD_VOICE_STATES | GUILD_MESSAGES | ...
intents.message_content = True   # bit 15 — prefix commands need Message.content
intents.voice_states = True      # bit 7  — already in default(), but explicit
intents.members = True           # bit 1  — on_member_join + member cache
```

Calculated bitmask for Identify: `default()` + 2 (GUILD_MEMBERS) + 32768 (MESSAGE_CONTENT). If `MESSAGE_CONTENT` is off, `Message.content` is `""` in guilds and all `!prefix` commands silently stop working.

### 11.4 Gateway rate limits

- **Outgoing payloads:** 120 events per 60 seconds per connection (~2/s). Exceeding this disconnects the bot.
- **Identify:** Limited by `max_concurrency` (from `/gateway/bot`) per 5 seconds. Default: 1 per 5 s for unsharded bots.
- **Payload size:** Max 4096 bytes per payload.

---

## 12. Gateway Dispatch Events

Events received as Opcode 0 Dispatch packets. The `t` field is the event name.

### 12.1 Lifecycle events

| Event | Fires when | Key payload fields |
| ----- | ---------- | ------------------ |
| `READY` | Successful Identify | `user`, `guilds` (unavailable guild stubs), `session_id`, `resume_gateway_url`, `application.id` |
| `RESUMED` | Resume successful | (empty `d`) |

### 12.2 Message events

**Requires `GUILD_MESSAGES` (9) + `MESSAGE_CONTENT` (15) for guild messages.**

| Event | Fires when | Notes |
| ----- | ---------- | ----- |
| `MESSAGE_CREATE` | Message posted | Full message object + `guild_id` + partial `member`. `content` is `""` without `MESSAGE_CONTENT` intent. |
| `MESSAGE_UPDATE` | Message edited | Same structure; `tts` always false in update. Not all fields guaranteed present. |
| `MESSAGE_DELETE` | Message deleted | Only `id`, `channel_id`, `guild_id` — content is gone. |
| `MESSAGE_DELETE_BULK` | Bulk delete | `ids` (array), `channel_id`, `guild_id`. |

### 12.3 Member events

**Requires `GUILD_MEMBERS` (1) privileged intent.**

| Event | Fires when | Key fields |
| ----- | ---------- | ---------- |
| `GUILD_MEMBER_ADD` | User joins guild | Full member object + `guild_id`. Used by `on_member_join` in this bot (`:2261`). |
| `GUILD_MEMBER_UPDATE` | Nickname, roles, timeout, avatar change | Updated member + `guild_id`. |
| `GUILD_MEMBER_REMOVE` | User leaves, kicked, or banned | `guild_id`, `user` object. |
| `GUILD_MEMBERS_CHUNK` | Response to a member chunk request | `guild_id`, `members` array, `chunk_index`, `chunk_count`. |

### 12.4 Voice events

| Event | Fires when | Key fields |
| ----- | ---------- | ---------- |
| `VOICE_STATE_UPDATE` | User joins/leaves/moves voice channel, mute/deafen | `guild_id`, `channel_id` (null = left), `user_id`, `session_id`, `deaf`, `mute`, `self_mute`, `self_deaf` |
| `VOICE_SERVER_UPDATE` | Voice server assigned / changed | `guild_id`, `token`, `endpoint`. Required to complete a voice connection (see §17). |

### 12.5 Interaction event

| Event | Fires when | Key fields |
| ----- | ---------- | ---------- |
| `INTERACTION_CREATE` | User invokes a slash command, clicks a button, or submits a modal | Full interaction object: `id`, `application_id`, `type`, `data`, `guild_id`, `channel_id`, `member`, `token`, `version` |

### 12.6 Guild / channel events

| Event | Fires when | Notes |
| ----- | ---------- | ----- |
| `GUILD_CREATE` | Bot joins guild, or guild becomes available | Full guild object: channels, roles, members (if GUILD_MEMBERS), voice states. Also fires for all guilds on initial ready. |
| `GUILD_UPDATE` | Guild properties change | Updated guild object. |
| `GUILD_DELETE` | Bot removed from guild, or guild unavailable | `id`, `unavailable`. |
| `CHANNEL_CREATE/UPDATE/DELETE` | Channel added, changed, removed | Full channel object. `CHANNEL_UPDATE` does **not** fire on `last_message_id` changes. |
| `GUILD_ROLE_CREATE/UPDATE/DELETE` | Role created, edited, deleted | Role object + `guild_id`. |

---

## 13. Application Commands (Slash Commands)

### 13.1 Command types

| Type | ID | Trigger |
| ---- | -- | ------- |
| `CHAT_INPUT` | 1 | User types `/name` |
| `USER` | 2 | Right-click → Apps → command name on a user |
| `MESSAGE` | 3 | Right-click → Apps → command name on a message |

This codebase uses **type 1 only** (`/quote` in `QuoteCog`, `./bot/specterdiscord.py:4084`).

### 13.2 Command option types

| Type | ID | Notes |
| ---- | -- | ----- |
| `SUB_COMMAND` | 1 | |
| `SUB_COMMAND_GROUP` | 2 | |
| `STRING` | 3 | |
| `INTEGER` | 4 | |
| `BOOLEAN` | 5 | |
| `USER` | 6 | Resolves to user + member objects |
| `CHANNEL` | 7 | Can be filtered to specific channel types |
| `ROLE` | 8 | |
| `MENTIONABLE` | 9 | User or role |
| `NUMBER` | 10 | Float |
| `ATTACHMENT` | 11 | |

Constraints: max **25 options** per command; required options must precede optional options; combined name + description + value chars must be ≤ 8000.

### 13.3 Registration

| Scope | Endpoint | Propagation |
| ----- | -------- | ----------- |
| Global | `POST /applications/{app_id}/commands` | Up to **1 hour** to appear across all guilds |
| Guild | `POST /applications/{app_id}/guilds/{guild_id}/commands` | **Instant** — use for testing |

Rate limit: 200 application command creates per day, per guild.

This bot registers globally via `await bot.tree.sync()` in `setup_hook` (`:2168-2177`). Use `bot.tree.sync(guild=discord.Object(id=<id>))` for fast iteration during development.

### 13.4 Interaction response requirement

**You have 3 seconds to call `interaction.response` before the token expires.** If processing will take longer:

```python
await interaction.response.defer(ephemeral=True)   # acknowledges within 3 s
# ... do slow work ...
await interaction.followup.send("Done!")           # send the real response
```

`ephemeral=True` makes the acknowledgement visible only to the invoker. Used throughout `on_interaction` in this bot (`:2186-2259`).

---

## 14. Message Components

### 14.1 Component types

| Type | ID | Container? | Notes |
| ---- | -- | ---------- | ----- |
| `ACTION_ROW` | 1 | Yes | Holds up to 5 buttons **or** 1 select menu |
| `BUTTON` | 2 | No | 5 styles: Primary, Secondary, Success, Danger, Link |
| `STRING_SELECT` | 3 | No | Up to 25 options |
| `TEXT_INPUT` | 4 | No | Modal-only |
| `USER_SELECT` | 5 | No | |
| `ROLE_SELECT` | 6 | No | |
| `MENTIONABLE_SELECT` | 7 | No | |
| `CHANNEL_SELECT` | 8 | No | |

Layout components (Section, Container, Separator, etc.) are only available with Components V2 flag (`message_flags |= 1 << 15`).

### 14.2 `custom_id`

- **Length:** 1–100 characters.
- **Must be unique** per component within a message.
- Returned verbatim in the interaction payload — use it to encode state.
- This bot's namespacing convention (`./bot/specterdiscord.py:2186`):
  - `role_<role_id>` — toggle self-assignable role
  - `rules_accept_<role_id>` — grant the entry role on rules acceptance

### 14.3 Limits

| Constraint | Limit |
| ---------- | ----- |
| Total components per message | 40 |
| Buttons per Action Row | 5 |
| Select menu options | 25 |
| Button label (with icon) | 34 chars |
| Button label (no icon) | 38 chars |
| Placeholder text | 150 chars |

### 14.4 Interaction response flow for components

Same 3-second rule as slash commands (§13.4). This bot calls `interaction.response.defer(ephemeral=True)` in `on_interaction` then `interaction.followup.send(...)` via `send_interaction_response` helper.

---

## 15. Embeds & Message Structure

### 15.1 Embed object limits

| Field | Limit |
| ----- | ----- |
| `title` | 256 characters |
| `description` | 4096 characters |
| `fields` | 25 field objects maximum |
| `field.name` | 256 characters |
| `field.value` | 1024 characters |
| `footer.text` | 2048 characters |
| `author.name` | 256 characters |
| **Combined total** | **6000 characters** across all title + description + field.name + field.value + footer.text + author.name on all embeds in one message |

`color` is an integer RGB value (e.g. `0xFF0000` for red).

### 15.2 `allowed_mentions`

Controls which `@mentions` in a message actually ping:

```json
{
  "parse": ["users", "roles", "everyone"],
  "users": ["snowflake1", ...],   // max 100 — override parse for specific users
  "roles": ["snowflake1", ...],   // max 100
  "replied_user": true
}
```

Default for **regular messages**: all types are parsed. Default for **interaction followups / webhooks**: only `users` are parsed — roles and `@everyone` are inert unless you add them to `parse`.

### 15.3 Message flags (bitfield)

| Flag | Bit | Value | Meaning |
| ---- | --- | ----- | ------- |
| `SUPPRESS_EMBEDS` | 2 | 4 | Strip link previews |
| `HAS_THREAD` | 5 | 32 | A thread is attached |
| `EPHEMERAL` | 6 | 64 | Visible only to invoker (interaction responses only) |
| `SUPPRESS_NOTIFICATIONS` | 12 | 4096 | No push notification |
| `IS_COMPONENTS_V2` | 15 | 32768 | Enable advanced layout components (disables `content` and `embeds`) |

### 15.4 `content` vs `embed`

`Message.content` is `""` in guild messages unless the `MESSAGE_CONTENT` (bit 15) privileged intent is enabled **and** the bot has `message_content` intent set in code. In DMs, content is always readable. This is the silent failure mode that breaks prefix commands (§7.2).

---

## 16. Permissions Reference

### 16.1 Key permission bits

All permissions are stored as a string representation of a 64-bit integer. discord.py wraps these as `discord.Permissions`.

| Permission | Bit | Hex | Notes |
| ---------- | --- | --- | ----- |
| `CREATE_INSTANT_INVITE` | 0 | `0x1` | |
| `KICK_MEMBERS` | 1 | `0x2` | |
| `BAN_MEMBERS` | 2 | `0x4` | |
| `ADMINISTRATOR` | 3 | `0x8` | Bypasses all channel overwrites |
| `MANAGE_CHANNELS` | 4 | `0x10` | |
| `MANAGE_GUILD` | 5 | `0x20` | |
| `ADD_REACTIONS` | 6 | `0x40` | |
| `VIEW_AUDIT_LOG` | 7 | `0x80` | |
| `VIEW_CHANNEL` | 10 | `0x400` | Denying this implicitly blocks all other channel perms |
| `SEND_MESSAGES` | 11 | `0x800` | |
| `MANAGE_MESSAGES` | 13 | `0x2000` | Requires 2FA on 2FA-enforced servers |
| `EMBED_LINKS` | 14 | `0x4000` | Suppressed when `SEND_MESSAGES` is denied |
| `ATTACH_FILES` | 15 | `0x8000` | Suppressed when `SEND_MESSAGES` is denied |
| `MENTION_EVERYONE` | 17 | `0x20000` | |
| `CONNECT` | 20 | `0x100000` | Required to join voice/stage channels |
| `SPEAK` | 21 | `0x200000` | |
| `MOVE_MEMBERS` | 24 | `0x1000000` | Also lets bot bypass voice channel user limit |
| `MANAGE_ROLES` | 28 | `0x10000000` | Restricted by role hierarchy (§16.2) |
| `MANAGE_THREADS` | 34 | `0x400000000` | |

The bot invite URL uses `permissions=581651049737302` (§2.1) — this is the bitmask sum of all permissions the bot requests on install.

### 16.2 Role hierarchy rules

The bot can only:
- Grant roles to users whose **highest role is lower** than the bot's highest role.
- Edit / delete roles that are **lower** in position than its own highest role.
- Grant only permissions the bot itself possesses.
- Kick / ban / edit nicknames of members whose highest role is **lower** than the bot's.

`ADMINISTRATOR` bypasses channel-level overwrites entirely but not hierarchy rules.

### 16.3 Permission overwrite resolution (per-channel)

Applied in this order (later stages override earlier):

1. `@everyone` guild-level permissions
2. All role guild-level permissions (ORed together)
3. `@everyone` channel deny overwrite
4. `@everyone` channel allow overwrite
5. Role-specific channel deny overwrites
6. Role-specific channel allow overwrites
7. Member-specific channel deny/allow overwrites (highest priority)

---

## 17. Voice Connections

Used by `VoiceCog` in `./bot/specterdiscord.py` for `!play`, `!connect`, etc.

### 17.1 Connection flow

1. **Send Gateway Opcode 4 (Voice State Update):** `{"guild_id": "...", "channel_id": "...", "self_mute": false, "self_deaf": false}`.
2. **Receive two events from the main gateway:**
   - `VOICE_STATE_UPDATE` — contains your `session_id`.
   - `VOICE_SERVER_UPDATE` — contains `token`, `guild_id`, `endpoint` (WSS URL).
3. **Connect to the voice gateway** (`wss://<endpoint>?v=8`). Send **Voice Opcode 0 Identify**: `{"server_id": guild_id, "user_id": ..., "session_id": ..., "token": ...}`.
4. **Receive Voice Opcode 2 Ready:** `ssrc`, `ip`, `port`, `modes` (supported encryption).
5. **UDP handshake:** Connect UDP to `ip:port`, run IP discovery (optional but needed through NAT), send **Voice Opcode 1 Select Protocol** with discovered `address`/`port` and chosen `mode`.
6. **Receive Voice Opcode 4 Session Description:** `secret_key` (32-byte array for encryption) and confirmed `mode`.
7. **Transmit audio:** Send **Voice Opcode 5 Speaking** first, then RTP packets with Opus audio encrypted using the `secret_key`.

### 17.2 Voice gateway version

**Always use version 8** (`?v=8` in the WSS URL). Connections without a version or below v4 have been rejected since November 18, 2024. discord.py handles this automatically.

### 17.3 Encryption modes (v8)

| Mode | Notes |
| ---- | ----- |
| `aead_aes256_gcm_rtpsize` | Preferred if available |
| `aead_xchacha20_poly1305_rtpsize` | **Required support** — always implement this |
| Legacy modes | Removed November 18, 2024 — do not use |

### 17.4 DAVE end-to-end encryption

Discord is migrating to mandatory E2EE via the **DAVE protocol**. As of **March 1, 2026**, only E2EE calls are supported for DMs, group DMs, voice channels, and Go Live. DAVE uses AES-128-GCM at the Opus frame level with MLS group key management.

discord.py must support DAVE for ongoing voice functionality. Include `max_dave_protocol_version` in Voice Opcode 0 Identify. If discord.py doesn't yet support DAVE, voice connections may fail in those channel types after March 2026.

### 17.5 Voice gotchas

- Bot users **respect voice channel user limits** unless they have `MOVE_MEMBERS` permission.
- **Token changes** when the bot switches channels — don't reuse a previous session.
- Send **five frames of silence** (Opus silence = `0xF8, 0xFF, 0xFE`) before stopping transmission to avoid Opus interpolation artifacts at the end of audio.
- `PyNaCl` is **mandatory** for any voice — missing it fails at connect with a libsodium error (no helpful error message).
- The `yt_dlp` cookies file at `/home/botofthespecter/ytdl-cookies.txt` (server) is required for age-gated YouTube content in `!play`.
- `!realtime` / `!talk` (voice receive via `discord-ext-voice-recv`) is **currently disabled** in this codebase.

---

## 18. Guild, Member & Role API

### 18.1 Guild object (key fields)

| Field | Type | Notes |
| ----- | ---- | ----- |
| `id` | snowflake | |
| `name` | string | 2–100 chars |
| `owner_id` | snowflake | Used in `fetchUserGuilds` to filter owner-only guilds (`:1517-1521`) |
| `features` | string[] | e.g. `COMMUNITY`, `VERIFIED`, `DISCOVERABLE` |
| `premium_tier` | int | 0–3 (boost levels) |
| `approximate_member_count` | int | Only present when fetched with `with_counts=true` |
| `system_channel_id` | snowflake | Default channel for system messages |

### 18.2 Guild member object

| Field | Type | Notes |
| ----- | ---- | ----- |
| `user` | User object | May be absent in some contexts |
| `nick` | string? | Null if not set |
| `roles` | snowflake[] | List of role IDs |
| `joined_at` | ISO8601 | |
| `premium_since` | ISO8601? | Set if user is boosting |
| `deaf` | bool | Server-muted |
| `mute` | bool | Server-deafened |
| `pending` | bool | Membership screening not yet complete |
| `permissions` | string | Only present in interaction data (channel-specific computed permissions) |
| `communication_disabled_until` | ISO8601? | Timeout expiry |

### 18.3 Role object

| Field | Type | Notes |
| ----- | ---- | ----- |
| `id` | snowflake | |
| `name` | string | |
| `color` | int | RGB integer (`0` = no colour, displays as grey) |
| `hoist` | bool | Display separately in member list |
| `position` | int | Hierarchy position (higher = more powerful); `@everyone` is always 0 |
| `permissions` | string | Bitmask string |
| `managed` | bool | Controlled by an integration (bot's own role, Twitch sub role, etc.) — **cannot be assigned manually** |
| `mentionable` | bool | |

The dashboard's `fetchGuildRoles` helper (`:1630`) filters out `@everyone`, `managed=true`, and `tags`-bearing roles, then sorts descending by `position`.

### 18.4 List Guild Members endpoint

```
GET /guilds/{guild.id}/members?limit=1000&after={last_user_id}
```

- **Requires:** `GUILD_MEMBERS` privileged intent (both in Developer Portal and in `intents.members = True`).
- `limit`: 1–1000 (default 1 — always specify 1000 for bulk fetches).
- `after`: snowflake cursor for pagination (use highest user ID from previous page).
- Results ordered by user ID ascending.
- No total-count field — paginate until result count < limit.

### 18.5 Get Guild Roles endpoint

```
GET /guilds/{guild.id}/roles
```

Returns **all roles** at once — no pagination. Used by `fetchGuildRoles` at `./dashboard/discordbot.php:1630`.

### 18.6 Get Guild Channels endpoint

```
GET /guilds/{guild.id}/channels
```

Returns all channels in one response (no pagination). Channel types relevant to dashboard filters:

| Type ID | Name | Used for |
| ------- | ---- | -------- |
| 0 | `GUILD_TEXT` | Text channel pickers |
| 2 | `GUILD_VOICE` | Voice channel pickers |
| 4 | `GUILD_CATEGORY` | Ignored in pickers |
| 5 | `GUILD_ANNOUNCEMENT` | Treated as text in this codebase |
| 11 | `PUBLIC_THREAD` | Ignored in pickers |
| 12 | `PRIVATE_THREAD` | Ignored in pickers |

---

## 19. OAuth2 Scopes (complete reference)

Expanded from §2.3. All scopes used or likely-needed for this project:

| Scope | Purpose | Currently used? |
| ----- | ------- | --------------- |
| `identify` | `GET /users/@me` (no email) | Yes — captures `discord_id`, username, avatar |
| `email` | Adds `email` field to `/users/@me` | No |
| `guilds` | `GET /users/@me/guilds` | Yes — owner-guild filter for server picker |
| `guilds.members.read` | `GET /users/@me/guilds/{id}/member` | Yes — permission checks |
| `guilds.join` | Add user to a guild programmatically | No |
| `connections` | Third-party connections (`/users/@me/connections`) | Yes — in scope string, no active consumer in UI |
| `bot` | Add bot to guild during consent (combined flow) | Yes — first-time link only |
| `applications.commands` | Register app commands in guilds | No (handled via gateway/tree.sync) |
| `webhook.incoming` | Returns a webhook object after auth | No |
| `role_connections.write` | Update linked-role metadata | No |

**Token lifetime:** Access tokens last **7 days** (`expires_in: 604800`). Refresh tokens do not expire unless the user revokes authorization. Discord may or may not return a new refresh token on refresh — always fall back to the old one if absent (handled at `./bot/refresh_discord_tokens.py:64`).

**Content-Type gotcha:** The token and revocation endpoints only accept `application/x-www-form-urlencoded`. JSON bodies return `400 Bad Request`.

**Revocation cascades:** Revoking either the access or refresh token invalidates **both** tokens associated with that authorization. The dashboard revokes both defensively (`:1441-1457`), but one call is sufficient.

---

## 20. Channel Resource

### 20.1 Channel object fields

| Field | Type | Notes |
| ----- | ---- | ----- |
| `id` | snowflake | |
| `type` | int | See channel types table (§18.6 / §20.2) |
| `guild_id` | snowflake? | Absent for DMs |
| `name` | string | 1–100 chars |
| `topic` | string? | 0–1024 chars; 0–4096 for forum/media |
| `position` | int | Sort order in sidebar |
| `nsfw` | bool | Age-restricted flag |
| `permission_overwrites` | array | Overwrite objects (§20.3) |
| `parent_id` | snowflake? | Category ID, or source channel for threads |
| `rate_limit_per_user` | int | Slowmode seconds (0–21600; 0 = disabled) |
| `bitrate` | int | Voice: bits per second |
| `user_limit` | int | Voice: max concurrent users (0 = unlimited for voice, up to 10 000 for stage) |
| `rtc_region` | string? | Voice region; null = automatic |
| `last_message_id` | snowflake? | Last message sent — **does not update via CHANNEL_UPDATE event** |

### 20.2 Channel type IDs (complete)

| ID | Name | Used in this project |
| -- | ---- | -------------------- |
| 0 | `GUILD_TEXT` | Text channel pickers in dashboard |
| 1 | `DM` | — |
| 2 | `GUILD_VOICE` | Voice channel pickers in dashboard |
| 3 | `GROUP_DM` | — |
| 4 | `GUILD_CATEGORY` | Filtered out of pickers |
| 5 | `GUILD_ANNOUNCEMENT` | Treated as text in this codebase |
| 10 | `ANNOUNCEMENT_THREAD` | Filtered out |
| 11 | `PUBLIC_THREAD` | Filtered out |
| 12 | `PRIVATE_THREAD` | Filtered out |
| 13 | `GUILD_STAGE_VOICE` | — |
| 14 | `GUILD_DIRECTORY` | — |
| 15 | `GUILD_FORUM` | — |
| 16 | `GUILD_MEDIA` | — |

### 20.3 Permission overwrite object

Used in `permission_overwrites` and in `PATCH /channels/{id}/permissions/{overwrite_id}`:

| Field | Type | Notes |
| ----- | ---- | ----- |
| `id` | snowflake | Role ID or user ID |
| `type` | int | `0` = role, `1` = member |
| `allow` | string | Bitmask of allowed permissions |
| `deny` | string | Bitmask of denied permissions |

Used in `specterdiscord.py` via `Channel.set_permissions(target, **overwrites)` → `PUT /channels/{id}/permissions/{overwrite_id}`. Requires `MANAGE_ROLES` permission.

### 20.4 POST /channels/{channel_id}/messages

```
POST /channels/{channel.id}/messages
Authorization: Bot <token>
Content-Type: application/json
```

| Parameter | Type | Notes |
| --------- | ---- | ----- |
| `content` | string | **Max 2000 characters.** At least one of content / embeds / sticker_ids / components / files required. |
| `tts` | bool | Text-to-speech |
| `embeds` | array | Up to **10 embed objects** per message; 6000 char combined limit (§15.1) |
| `allowed_mentions` | object | Controls which mentions actually ping (§15.2) |
| `message_reference` | object | Reply target: `{ "message_id": "...", "type": 0 }` (0=DEFAULT, 1=FORWARD) |
| `components` | array | Buttons, selects (§14) |
| `sticker_ids` | array | Up to 3 sticker snowflakes |
| `files[n]` | multipart | File uploads — requires `multipart/form-data` |
| `attachments` | array | Attachment metadata when uploading files |
| `flags` | int | Bitfield (§15.3) — e.g. `64` for ephemeral (interactions only) |
| `nonce` | int/string | Idempotency key — appears in MESSAGE_CREATE if set |
| `poll` | object | Creates a poll |

Via discord.py: `channel.send(content=, embed=, embeds=, file=, components=, allowed_mentions=, reference=)`.

### 20.5 GET /channels/{channel_id}/messages

```
GET /channels/{channel.id}/messages?limit=50&before={snowflake}
```

| Param | Notes |
| ----- | ----- |
| `limit` | 1–100 (default 50) |
| `before` | Snowflake cursor — messages with lower IDs |
| `after` | Snowflake cursor — messages with higher IDs |
| `around` | Return messages around this ID (cannot combine with before/after) |

Requires `VIEW_CHANNEL`. Requires `READ_MESSAGE_HISTORY` for history. Returns array of message objects ordered newest-first when using `before`.

### 20.6 DELETE /channels/{channel_id}/messages/bulk

```
POST /channels/{channel.id}/messages/bulk-delete
Body: { "messages": ["id1", "id2", ...] }
```

- 2–100 message IDs per call.
- Messages must be **< 14 days old** — older messages silently fail or error depending on mix.
- Requires `MANAGE_MESSAGES`.
- Used by `ModerationCog` `!purge`/`!clear` commands in `./bot/specterdiscord.py`.

---

## 21. User Resource

### 21.1 User object fields

| Field | Type | Notes |
| ----- | ---- | ----- |
| `id` | snowflake | |
| `username` | string | 2–32 chars; not platform-unique (display name may differ) |
| `discriminator` | string | Legacy `#XXXX` tag; `"0"` for new username system users |
| `global_name` | string? | Display name if set (newer accounts; supersedes `username` in UI) |
| `avatar` | string? | Avatar hash — see §21.2 |
| `bot` | bool? | `true` for bot/application users |
| `system` | bool? | `true` for Discord official system account |
| `mfa_enabled` | bool? | Only present via `/users/@me` with own token |
| `banner` | string? | Banner hash |
| `accent_color` | int? | RGB integer (profile accent if no banner) |
| `locale` | string? | Language preference — only via `/users/@me` |
| `verified` | bool? | Email verified — only via `/users/@me` with `email` scope |
| `email` | string? | Only via `/users/@me` with `email` scope |
| `flags` | int? | Account flags bitmask (§21.3) |
| `premium_type` | int? | 0 = None, 1 = Nitro Classic, 2 = Nitro, 3 = Nitro Basic |
| `public_flags` | int? | Publicly visible subset of `flags` |

### 21.2 Avatar URL format

```
https://cdn.discordapp.com/avatars/{user_id}/{avatar_hash}.png
```

- Append `?size=256` (or 512, 1024, 2048) for specific size.
- If `avatar_hash` starts with `a_` it is an animated GIF — replace `.png` with `.gif`.
- Default avatar (when `avatar` is null): `https://cdn.discordapp.com/embed/avatars/{(user_id >> 22) % 6}.png` (new system) or `discriminator % 5`.

In this codebase, `discord_avatar` is stored as the raw hash in `discord_users` and used to construct the CDN URL in the dashboard.

### 21.3 User flags (public_flags bits)

| Bit | Flag | Meaning |
| --- | ---- | ------- |
| 0 | `STAFF` | Discord employee |
| 1 | `PARTNER` | Discord partner |
| 3 | `BUG_HUNTER_LEVEL_1` | Bug hunter |
| 14 | `BUG_HUNTER_LEVEL_2` | Gold bug hunter |
| 16 | `VERIFIED_BOT` | Verified bot |
| 19 | `BOT_HTTP_INTERACTIONS` | HTTP-only interactions bot |

### 21.4 GET /users/@me endpoints

| Endpoint | Scope required | Returns |
| -------- | -------------- | ------- |
| `GET /users/@me` | `identify` | User object (no email) |
| `GET /users/@me` | `email` | User object + `email` + `verified` |
| `GET /users/@me/guilds` | `guilds` | Array of partial guild objects (max 200 per page, cursor paginated) |
| `GET /users/@me/guilds/{guild_id}/member` | `guilds.members.read` | Guild member object for the authed user |
| `GET /users/@me/connections` | `connections` | Array of connection objects (Twitch, Steam, Spotify, etc.) |

`GET /users/@me/guilds` params: `before` (snowflake), `after` (snowflake), `limit` (1–200, default 200), `with_counts` (bool — adds `approximate_member_count` and `approximate_presence_count`).

### 21.5 Connection object fields

Returned by `GET /users/@me/connections` (requires `connections` scope):

| Field | Type | Notes |
| ----- | ---- | ----- |
| `id` | string | Account ID on the connected service |
| `name` | string | Username on the connected service |
| `type` | string | Service name: `"twitch"`, `"steam"`, `"spotify"`, `"youtube"`, etc. |
| `verified` | bool | Whether the connection is verified |
| `revoked` | bool? | Authorization has been revoked |
| `show_activity` | bool | Visible in presence |
| `visibility` | int | 0 = nobody, 1 = everyone |

This scope is included in the first-time auth flow (§2.3) but no active consumer reads the response in the current dashboard code.

---

## 22. Audit Log

### 22.1 GET /guilds/{guild_id}/audit-logs

```
GET /guilds/{guild.id}/audit-logs
Authorization: Bot <token>
```

Requires `VIEW_AUDIT_LOG` permission. Returns newest-first by default.

| Parameter | Type | Notes |
| --------- | ---- | ----- |
| `user_id` | snowflake | Filter to actions by a specific moderator |
| `action_type` | int | Filter to a specific event type (§22.3) |
| `before` | snowflake | Entries older than this ID (cursor, descending) |
| `after` | snowflake | Entries newer than this ID (cursor, ascending) |
| `limit` | int | 1–100 (default 50) |

### 22.2 Audit log entry object

| Field | Type | Notes |
| ----- | ---- | ----- |
| `id` | snowflake | Entry ID (used for cursor pagination) |
| `user_id` | snowflake? | Moderator who performed the action |
| `target_id` | string? | ID of the affected entity (user, channel, role, etc.) |
| `action_type` | int | Event type (§22.3) |
| `changes` | array? | Array of `{ key, old_value, new_value }` change objects |
| `options` | object? | Extra context (e.g. channel_id for MESSAGE_DELETE, count for bulk) |
| `reason` | string? | Mod reason (1–512 chars); set via `X-Audit-Log-Reason` header |

Entries are retained for **45 days** maximum.

### 22.3 Audit log event types (relevant subset)

| Code | Event | Notes |
| ---- | ----- | ----- |
| 1 | `GUILD_UPDATE` | |
| 10 | `CHANNEL_CREATE` | |
| 11 | `CHANNEL_UPDATE` | |
| 12 | `CHANNEL_DELETE` | |
| 13 | `CHANNEL_OVERWRITE_CREATE` | |
| 14 | `CHANNEL_OVERWRITE_UPDATE` | |
| 15 | `CHANNEL_OVERWRITE_DELETE` | |
| 20 | `MEMBER_KICK` | `options.count` present |
| 21 | `MEMBER_PRUNE` | |
| 22 | `MEMBER_BAN_ADD` | |
| 23 | `MEMBER_BAN_REMOVE` | |
| 24 | `MEMBER_UPDATE` | Nickname changes, timeout changes |
| 25 | `MEMBER_ROLE_UPDATE` | Role add/remove |
| 26 | `MEMBER_MOVE` | Voice channel move |
| 27 | `MEMBER_DISCONNECT` | Force-disconnected from voice |
| 40 | `ROLE_CREATE` | |
| 41 | `ROLE_UPDATE` | |
| 42 | `ROLE_DELETE` | |
| 72 | `MESSAGE_DELETE` | `options.channel_id`, `options.count` |
| 73 | `MESSAGE_BULK_DELETE` | `options.count` — fires from `!purge` / bulk-delete endpoint |
| 74 | `MESSAGE_PIN` | |
| 75 | `MESSAGE_UNPIN` | |

### 22.4 Setting a reason

Pass `X-Audit-Log-Reason: <url-encoded string>` as an HTTP header on any modifying API call. discord.py: `await member.ban(reason="Spam")` appends this header automatically.

---

## 23. Rate Limits (complete reference)

### 23.1 Rate limit headers

Present on **every** API response:

| Header | Meaning |
| ------ | ------- |
| `X-RateLimit-Limit` | Max requests in this bucket window |
| `X-RateLimit-Remaining` | Requests left before hitting the limit |
| `X-RateLimit-Reset` | Unix timestamp (float) when the bucket resets |
| `X-RateLimit-Reset-After` | Seconds until reset (float) |
| `X-RateLimit-Bucket` | Opaque bucket ID — use this as the cache key, NOT the URL |
| `X-RateLimit-Global` | `true` only on 429 responses that hit the global cap |
| `X-RateLimit-Scope` | `user` \| `global` \| `shared` — present on 429 only |

**Do not hardcode limits.** Discord adjusts bucket windows server-side without notice. Always parse `X-RateLimit-*`.

### 23.2 Global rate limit

**50 requests per second** across all endpoints, per bot token. Hitting it returns `429` with `X-RateLimit-Global: true`. Interaction endpoints (responding to slash commands / components) are **exempt** from the global limit.

### 23.3 Per-route buckets

- Bucket IDs are **per top-level resource** — `/channels/111/messages` and `/channels/222/messages` are separate buckets even though they share the same route template.
- The `X-RateLimit-Bucket` header is the authoritative key. Two different URL templates can share a bucket; two same-template URLs with different IDs have separate buckets.
- **Emoji routes** use per-guild bucketing rather than standard per-route bucketing.

### 23.4 429 response body

```json
{
  "message": "You are being rate limited.",
  "retry_after": 0.65,
  "global": false,
  "code": 20016
}
```

Always use `retry_after` (or the equivalent `Retry-After` header) — do not hardcode backoff values.

### 23.5 Shared rate limits

Some endpoints are in a **shared bucket** across multiple routes. `X-RateLimit-Scope: shared` on a 429 means another endpoint exhausted the same bucket. Shared-scope 429s **do not count** toward the Cloudflare invalid-request threshold.

### 23.6 Invalid request / Cloudflare ban threshold

IP addresses that return **10 000 invalid responses (401, 403, 429) within 10 minutes** are temporarily banned at the Cloudflare layer. Shared-scope 429s are excluded from this count. Mitigate by:

- Removing stale `discord_users` rows rather than letting them 401.
- Catching `discord.Forbidden` (403) and not retrying — fix the permission issue instead.
- Respecting `Retry-After` before retrying any 429.
- Not hammering the API with validation calls during bot startup.

### 23.7 Webhook and interaction token rate limits

- **Webhook execute:** 30 requests per minute, globally, per webhook.
- **Interaction response token:** Rate limits apply per interaction token (§24); the 15-minute window is generous but the 3-second initial response window is hard.
- **404 on webhook:** Indicates the webhook has been deleted — do not retry. Remove the stored webhook URL.

### 23.8 How discord.py handles rate limits

discord.py's `HTTPClient` parses rate-limit headers automatically and sleeps until `Reset-After` before retrying. The custom `@handle_rate_limit` decorator at `./bot/specterdiscord.py:109-159` layers on top, specifically catching `discord.RateLimited` and `discord.HTTPException(status=429)`, logging the bucket ID, and applying exponential back-off capped at 60 s for high-risk routes (DM sends, role mutations).

---

## 24. Interactions: Receiving and Responding

Expands §13 (slash commands) and §14 (components) with the full interaction model.

### 24.1 Interaction object

| Field | Type | Notes |
| ----- | ---- | ----- |
| `id` | snowflake | Unique per interaction |
| `application_id` | snowflake | Your app's ID |
| `type` | int | Interaction type (§24.2) |
| `data` | object? | Command / component / modal data (structure varies by type) |
| `guild_id` | snowflake? | Absent for DM interactions |
| `channel_id` | snowflake? | |
| `member` | Member? | Present in guilds (includes `permissions` — channel-specific computed value) |
| `user` | User? | Present in DMs / user-install contexts |
| `token` | string | **Valid 15 minutes.** Use for responses and followups. |
| `version` | int | Always `1` |
| `app_permissions` | string | Bot's permission bitfield in this channel |
| `locale` | string | User's language |
| `guild_locale` | string? | Guild's preferred language |

### 24.2 Interaction types

| Value | Name | When it fires |
| ----- | ---- | ------------- |
| 1 | `PING` | Discord handshake verification (HTTP interactions endpoint only) |
| 2 | `APPLICATION_COMMAND` | Slash command, user command, or message command invoked |
| 3 | `MESSAGE_COMPONENT` | Button clicked or select menu used |
| 4 | `APPLICATION_COMMAND_AUTOCOMPLETE` | User is typing in an autocomplete option |
| 5 | `MODAL_SUBMIT` | User submitted a modal form |

### 24.3 Interaction response types

| Value | Name | Use case |
| ----- | ---- | -------- |
| 1 | `PONG` | Reply to Discord's PING verification |
| 4 | `CHANNEL_MESSAGE_WITH_SOURCE` | Send an immediate message (visible to all or ephemeral) |
| 5 | `DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE` | Show "thinking…" loading state; send real content later via followup |
| 6 | `DEFERRED_UPDATE_MESSAGE` | Acknowledge a component interaction silently; edit the message later |
| 7 | `UPDATE_MESSAGE` | Immediately edit the original component message |
| 8 | `APPLICATION_COMMAND_AUTOCOMPLETE_RESULT` | Return up to 25 autocomplete choices |
| 9 | `MODAL` | Open a modal popup (only valid for APPLICATION_COMMAND or MESSAGE_COMPONENT) |
| 12 | `LAUNCH_ACTIVITY` | Launch an Activity (Embedded App) |

### 24.4 Timing rules

- **3 seconds**: Must call `interaction.response.*` within 3 seconds or the token is invalidated.
- **15 minutes**: Full token lifetime for followups and edits via `interaction.followup` / webhook endpoints.
- If work takes > 3 s: call `await interaction.response.defer()` first (type 5 or 6), then `await interaction.followup.send(...)` when done.

This bot pattern (from `./bot/specterdiscord.py:2186`):
```python
await interaction.response.defer(ephemeral=True)   # type 5, buys 15 min
# ... role mutation work ...
await send_interaction_response(interaction, "Done!")  # followup
```

### 24.5 Responding via REST (without discord.py)

| Action | Method + Path |
| ------ | ------------- |
| Initial response (within 3 s) | `POST /interactions/{id}/{token}/callback` |
| Edit original response | `PATCH /webhooks/{app_id}/{token}/messages/@original` |
| Delete original | `DELETE /webhooks/{app_id}/{token}/messages/@original` |
| Send followup | `POST /webhooks/{app_id}/{token}` |
| Edit followup | `PATCH /webhooks/{app_id}/{token}/messages/{message_id}` |
| Delete followup | `DELETE /webhooks/{app_id}/{token}/messages/{message_id}` |

The `callback` POST body: `{ "type": <response_type>, "data": { ... } }`. `data` is a message payload for types 4, 5, 7; choices array for type 8; modal object for type 9.

### 24.6 Modal structure

```json
{
  "custom_id": "my_modal",
  "title": "Enter details",
  "components": [
    {
      "type": 1,
      "components": [
        {
          "type": 4,
          "custom_id": "field_name",
          "label": "Your name",
          "style": 1,
          "min_length": 1,
          "max_length": 100,
          "placeholder": "John",
          "required": true
        }
      ]
    }
  ]
}
```

- `title`: Max **45 characters**.
- `custom_id`: Max 100 characters.
- Components: 1–5 `ACTION_ROW`s, each containing one `TEXT_INPUT` (type 4).
- `TEXT_INPUT` styles: `1` = short (single line), `2` = paragraph (multi-line).
- On submission, fires `MODAL_SUBMIT` interaction (type 5) with `data.custom_id` = modal's `custom_id` and `data.components` = submitted values.

### 24.7 Autocomplete

For `STRING`, `INTEGER`, or `NUMBER` options with `autocomplete: true`, Discord fires `APPLICATION_COMMAND_AUTOCOMPLETE` (type 4) interactions as the user types. Respond with type 8:

```json
{
  "type": 8,
  "data": {
    "choices": [
      { "name": "Display label", "value": "actual_value" }
    ]
  }
}
```

- Max **25 choices** returned.
- Must respond within **3 seconds** — no defer available for autocomplete.
- Not currently used in this codebase but relevant if options are ever added to `/quote` or future commands.

---

## 10. References

- Discord docs (canonical, post-migration host: `docs.discord.com`):
  - OAuth2: `https://docs.discord.com/developers/topics/oauth2`
  - Gateway: `https://docs.discord.com/developers/topics/gateway`
  - Gateway Events: `https://docs.discord.com/developers/topics/gateway-events`
  - Voice Connections: `https://docs.discord.com/developers/topics/voice-connections`
  - Rate limits: `https://docs.discord.com/developers/topics/rate-limits`
  - Permissions: `https://docs.discord.com/developers/topics/permissions`
  - Application Commands: `https://docs.discord.com/developers/interactions/application-commands`
  - Message Components: `https://docs.discord.com/developers/interactions/message-components`
  - Interactions receiving/responding: `https://docs.discord.com/developers/interactions/receiving-and-responding`
  - User resource: `https://docs.discord.com/developers/resources/user`
  - Guild resource: `https://docs.discord.com/developers/resources/guild`
  - Channel resource: `https://docs.discord.com/developers/resources/channel`
  - Message resource: `https://docs.discord.com/developers/resources/message`
  - Audit log: `https://docs.discord.com/developers/resources/audit-log`
- discord.py: `https://discordpy.readthedocs.io/en/stable/`
  - Intents: `https://discordpy.readthedocs.io/en/stable/intents.html`
  - `app_commands` reference: `https://discordpy.readthedocs.io/en/stable/interactions/api.html`
- In-repo:
  - `./bot/specterdiscord.py` — Discord companion bot (10053 lines, v6.1)
  - `./bot/refresh_discord_tokens.py` — OAuth refresh job
  - `./bot/.env.example` — env-var skeleton
  - `./bot/requirements.txt` — Python deps
  - `./config/discord.php` — PHP config stub (real values in `/var/www/config/discord.php` on server)
  - `./dashboard/discordbot.php` — OAuth callback + linking UI + REST helpers
  - `./dashboard/admin/discordbot_overview.php` — admin view of every linked user's Discord config
  - Project rules: [php-config.md](../../../rules/php-config.md), [secrets.md](../../../rules/secrets.md), [database.md](../../../rules/database.md), [paths.md](../../../rules/paths.md)
