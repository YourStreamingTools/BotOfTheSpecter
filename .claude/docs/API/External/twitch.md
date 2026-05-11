# Twitch API Reference (project-local)

Self-contained reference for the Twitch surfaces BotOfTheSpecter actually uses. Anything not used in this repo is intentionally excluded.

- Bot stable: `./bot/bot.py` — TwitchIO 2.10.0 (`./bot/requirements.txt`)
- Bot beta: `./bot/beta.py` — TwitchIO 2.10.0 (`./bot/requirements.txt`)
- Bot v6: `./bot/beta-v6.py` — TwitchIO 3.1.0 (`./bot/beta_requirements.txt`), native EventSub via `twitchio.ext.eventsub` and `commands.AutoBot`
- API server: `./api/api.py` — FastAPI/aiohttp, app + user tokens
- Dashboard: `./dashboard/*.php` — server-side cURL with the user's session access token
- Other PHP entry points using OAuth: `./home/login.php`, `./home/oauth_callback.php`, `./roadmap/login.php`, `./members/login.php`, `./support/includes/session.php`, `./specterbotsystems/mybot/custombot.php`

---

## 1. Overview — Twitch surfaces touched

| Surface | Host | Used for |
| --- | --- | --- |
| Helix REST | `api.twitch.tv/helix/*` | Stream/channel/user/sub/mod/VIP queries, chat send, clips, markers, ads, raids, shoutouts, schedule, channel points, EventSub mgmt |
| OAuth | `id.twitch.tv/oauth2/{authorize,token,validate}` | Authorization Code flow, refresh tokens, token validation |
| EventSub WebSocket | `wss://eventsub.wss.twitch.tv/ws` | Bot's real-time event ingest (stable + beta + v6) |
| EventSub Conduit | Helix `/eventsub/conduits` | v6 only — created/looked up but transport remains WebSocket |
| Twitch GraphQL | `gql.twitch.tv/gql` | One use only: validating a captured `TWITCH_GQL` OAuth token (used by Streamlink for the song/recording feature) |
| IRC | `irc.chat.twitch.tv` | Legacy chat presence in v6 (`twitch_irc_presence`) — superseded for events by EventSub |
| Custom site `dev.twitch.tv/extensions` | n/a | Twitch Extension v1.0.0 (`./extension/`) — not covered here |

Outbound webhook receivers from Twitch: **none on the API server** (the bot subscribes via WebSocket, not webhook). Searching `./api/api.py` for `eventsub` confirms no `webhook_callback_verification` handler exists. If a webhook receiver is added later, see [Section 4.2](#42-webhook-transport-not-currently-used).

---

## 2. Authentication

### 2.1 Client credentials (the app)

- `CLIENT_ID` and `CLIENT_SECRET` are loaded from environment (`.env` on server) for Python services and from `./config/twitch.php` (server: `/var/www/config/twitch.php`) for PHP. Per project rule, **PHP never reads `.env`**.
- Bot user IDs:
  - **`971436498`** — official BotOfTheSpecter bot account. Hardcoded in several places (search the codebase before changing). Examples:
    - `./bot/bot.py:7583` (`bot_id = "971436498"`)
    - `./bot/beta-v6.py:651` (App token bot user fallback)
    - `./bot/bot.py:8890` (default ban moderator)
    - `./bot/bot.py:10559` (chat send `sender_id`)
  - **`140296994`** — channel queried for the BotOfTheSpecter Twitch sub (used to gate "premium" features). See `./bot/bot.py:10011`, `./dashboard/api/twitch_beta_access.php:10`.

### 2.2 Authorization Code flow (user login)

Two parallel entry points:

#### Streamers Connect SSO (preferred for `*.botofthespecter.com`)
- `./home/login.php`, `./dashboard/login.php`, `./members/login.php` redirect to `https://streamersconnect.com/?service=twitch&login=<host>&scopes=<...>&return_url=<...>`.
- Streamers Connect runs the Twitch OAuth dance and posts back signed `auth_data` / `auth_data_sig` / `server_token` parameters that get verified against `https://streamersconnect.com/verify_auth_sig.php` and `https://streamersconnect.com/token_exchange.php` (`./dashboard/login.php:50–73`).
- Cookie scope `.botofthespecter.com` so the session is shared across subdomains. Backed by `website.web_sessions` (`/var/www/lib/session_bootstrap.php`).

#### Direct Twitch OAuth (used by isolated entry points)
Used where Streamers Connect isn't wired in:
- `./home/oauth_callback.php` — `redirect_uri=https://botofthespecter.com/oauth_callback.php`, exchanges code at `https://id.twitch.tv/oauth2/token`, then `GET https://api.twitch.tv/helix/users` to capture `id`/`display_name`.
- `./roadmap/login.php` — minimal scope (`openid user:read:email`).

#### Authorize URL form
```
https://id.twitch.tv/oauth2/authorize
  ?client_id={CLIENT_ID}
  &redirect_uri={url-encoded redirect}
  &response_type=code
  &scope={url-encoded space-separated scopes}
  [&state={csrf-state}]
  [&force_verify=true]
```

#### Token exchange (Authorization Code → access/refresh)
```
POST https://id.twitch.tv/oauth2/token
Content-Type: application/x-www-form-urlencoded

client_id={CLIENT_ID}
&client_secret={CLIENT_SECRET}
&code={authorization_code}
&grant_type=authorization_code
&redirect_uri={same redirect_uri used in /authorize}
```
Response:
```json
{
  "access_token": "...",
  "refresh_token": "...",
  "expires_in": 14400,
  "scope": ["channel:read:subscriptions", "..."],
  "token_type": "bearer"
}
```
Callsite: `./home/oauth_callback.php:16`, `./dashboard/login.php:253`, `./members/login.php:206`.

### 2.3 Refresh flow (long-lived tokens)
```
POST https://id.twitch.tv/oauth2/token
Content-Type: application/x-www-form-urlencoded

grant_type=refresh_token
&refresh_token={refresh_token}
&client_id={CLIENT_ID}
&client_secret={CLIENT_SECRET}
```
Same response shape as code exchange.

**Where refresh runs:**
- **In-process for the broadcaster's token in the running bot** — `twitch_token_refresh()` background task in `./bot/bot.py` (and `./bot/beta.py`, `./bot/beta-v6.py`). Calls `refresh_twitch_token()` (`./bot/bot.py:325–368`), persists the new access token to `website.twitch_bot_access` keyed by `twitch_user_id = CHANNEL_ID`. There is **no `refresh_twitch_tokens.py`**.
- **API server** — `_refresh_twitch_user_token()` (`./api/api.py:328`) refreshes the user-access token stored in `users.access_token`/`users.refresh_token` when validation fails or before launching a bot.
- **Standalone refreshers** (other token types, not the broadcaster's main token):
  - `./bot/refresh_custom_bot_tokens.py` — bot account tokens for users running a custom bot
  - `./bot/refresh_spotify_tokens.py`, `./bot/refresh_streamelements_tokens.py`, `./bot/refresh_discord_tokens.py`
- **PHP refresh callsites**: `./dashboard/admin/twitch_tokens.php:78`, `./dashboard/admin/start_bots.php:526`, `./dashboard/notifications.php:39`, `./dashboard/notifications_api.php:101`, `./support/includes/session.php:32`, `./specterbotsystems/mybot/custombot.php:218`.

### 2.4 Token validation
```
GET https://id.twitch.tv/oauth2/validate
Authorization: OAuth {access_token}
```
**Note the prefix is `OAuth`, not `Bearer`.** Helix calls use `Bearer`; only `/oauth2/validate` uses `OAuth`.

Callsites: `./api/api.py:372`, `./dashboard/api/twitch_token_validate.php:8`, `./dashboard/admin/twitch_tokens.php:223`, `./dashboard/admin/start_bots.php:408`, `./dashboard/channel_rewards.php:111`, `./lib/web_session.php:185`, `./specterbotsystems/mybot/custombot.php:152`, `./support/includes/session.php:17`.

Response (200):
```json
{
  "client_id": "...",
  "login": "...",
  "user_id": "...",
  "scopes": ["channel:read:subscriptions", "..."],
  "expires_in": 13837
}
```
401 = expired/invalid → trigger refresh.

### 2.5 Client-credentials (App Access Token)
Used for unauthenticated lookups (e.g., `helix/users` by login, public stream metadata) where a user token isn't available.
```
POST https://id.twitch.tv/oauth2/token
client_id={CLIENT_ID}&client_secret={CLIENT_SECRET}&grant_type=client_credentials
```
Callsite: `./dashboard/admin/twitch_tokens.php:1864`. The API server's `get_twitch_app_credentials()` (referenced at `./api/api.py:5670` etc.) reads the cached app token from the DB.

### 2.6 Scopes requested

The full project SSO scope list lives in two places that **MUST stay in sync** (comment in `./home/login.php:287`):

- `./home/login.php:296` — `$BOTS_SSO_SCOPES`
- `./dashboard/login.php:4` — `$IDScope`

Current set (verbatim, space-separated):
```
openid
channel:bot                                 # required for the bot's chat subs
moderator:manage:chat_messages
user:read:moderated_channels
moderator:read:blocked_terms
moderator:read:chat_settings
moderator:read:vips
moderator:read:moderators                    # dashboard mod-status checks
moderator:read:unban_requests
moderator:read:banned_users
moderator:read:chat_messages
moderator:read:warnings
user:bot                                     # bot identity
channel:read:goals
channel:moderate
channel:manage:moderators
user:edit:broadcast
channel:manage:redemptions                   # rewards CRUD
channel:manage:polls
moderator:manage:automod
moderator:read:suspicious_users
channel:read:hype_train
channel:manage:broadcast                     # title/game updates
channel:manage:raids
channel:read:charity
user:read:email
user:read:chat
user:write:chat                              # send chat messages
user:read:follows
chat:read
chat:edit
moderation:read
moderator:read:followers                     # /channels/followers
channel:read:redemptions
channel:read:vips
channel:manage:vips
user:read:subscriptions
channel:read:subscriptions                   # /subscriptions queries
moderator:read:chatters                      # /chat/chatters
bits:read                                    # /bits/leaderboard
channel:manage:ads
channel:read:ads                             # /channels/ads
channel:manage:schedule                      # schedule CRUD
channel:manage:clips
editor:manage:clips
clips:edit                                   # POST /clips
moderator:manage:announcements
moderator:manage:banned_users                # POST /moderation/bans
moderator:manage:chat_messages
moderator:read:shoutouts
moderator:manage:shoutouts                   # POST /chat/shoutouts
user:read:blocked_users
user:manage:blocked_users
```

Smaller scope sets used elsewhere:
- `./roadmap/login.php` — `openid user:read:email`
- `./specterbotsystems/mybot/custombot.php:310` — `user:read:email chat:read chat:edit user:write:chat user:bot moderator:read:chatters` (custom bot's own login)

---

## 3. Helix endpoints used

All requests need:
```
Client-Id: {CLIENT_ID}
Authorization: Bearer {access_token}
```
Some Python files set `Client-ID` (mixed casing) — Helix accepts either. The newer `send_chat_message` path uses `Client-Id`.

### 3.1 Users

#### GET /helix/users
**Purpose**: resolve login ↔ user_id ↔ display_name; capture profile image.

**Params**: `login=` (up to 100, repeatable) and/or `id=` (up to 100, repeatable). Bot/dashboard typically passes one.

**Sample response**:
```json
{ "data": [{
  "id": "971436498",
  "login": "botofthespecter",
  "display_name": "BotOfTheSpecter",
  "type": "",
  "broadcaster_type": "affiliate",
  "description": "...",
  "profile_image_url": "https://...",
  "offline_image_url": "https://...",
  "created_at": "2020-...T..."
}]}
```

**Repo callsites**:
- `./bot/bot.py:7159` — `is_valid_twitch_user(login)`
- `./bot/bot.py:7180` — `get_display_name(user_id)`
- `./bot/bot.py:9408` — `channel_point_rewards()` (gates on `broadcaster_type ∈ {affiliate, partner}`)
- `./bot/beta-v6.py:12736` — `_fetch_custom_bot_user_id()` (custom bot's user ID from its own token)
- `./api/api.py:273` — bulk profile-image lookup (`fetch_twitch_profile_images`)
- `./api/api.py:5551`, `./api/api.py:5676` — resolve target login → user ID for raids and shoutouts
- `./dashboard/dashboard.php:60`, `./dashboard/channel_rewards.php:57`, `./dashboard/counters.php:676` — broadcaster lookup by ID
- `./dashboard/login.php:292`, `./dashboard/profile.php:57`, `./dashboard/modules.php:51` — login → ID
- `./dashboard/fetch_banned_status.php:26`, `./dashboard/followers.php:117`, `./dashboard/mods.php:305`, `./dashboard/subscribers.php:129`, `./dashboard/vips.php:73` — bulk ID → user record
- `./dashboard/admin/index.php:811,1077`, `./dashboard/send_welcome_message.php:166`, `./home/oauth_callback.php:59`

### 3.2 Channels

#### GET /helix/channels
**Params**: `broadcaster_id=` (up to 100, repeatable).

**Returns**: title, game, language, content classification, last categories.

**Callsites**:
- `./bot/bot.py:7619` — `get_latest_stream_game()` (used by shoutout)
- `./bot/bot.py:9169`, `./bot/bot.py:9194` — fetch current game/title when offline
- `./dashboard/send_welcome_message.php:203`

#### PATCH /helix/channels?broadcaster_id={id}
**Purpose**: update title or game.

**Body**: `{"title": "..."}` or `{"game_id": "..."}` (must be game ID, not name — look up first via `/games`).

**Required scope**: `channel:manage:broadcast`.

**Callsites**:
- `./bot/bot.py:7483` — `trigger_twitch_title_update()`
- `./bot/bot.py:7504` — `update_twitch_game()` (does the `/games?name=` lookup first)

### 3.3 Streams

#### GET /helix/streams
**Params**: `user_login=` and/or `user_id=` (repeatable), `type=live`.

**Returns**: array; empty when offline. Item fields used: `started_at`, `game_name`, `title`, `viewer_count`.

**Callsites**:
- `./bot/bot.py:5091` — `!uptime`
- `./bot/bot.py:7881` — stream-online handler
- `./bot/bot.py:9155` — bot-start liveness probe
- `./api/api.py:4366`, `./api/api.py:4440` — `_get_current_game_from_twitch()`
- `./dashboard/api/twitch_stream_status.php:12`, `./dashboard/admin/index.php:906,924`, `./dashboard/send_welcome_message.php:183`

### 3.4 Subscriptions

#### GET /helix/subscriptions
**Params**: `broadcaster_id=`, `user_id=` (optional, repeatable). Without `user_id` returns paginated full list.

**Token**: broadcaster's user token (scope `channel:read:subscriptions`).

**Returns**: `tier` ("1000" / "2000" / "3000"), `is_gift`, `gifter_name`.

**Callsites**:
- `./bot/bot.py:5023` — `!subscription` / `!mysub`
- `./bot/bot.py:7247` — `command_permissions()` checks `t2-sub`/`t3-sub`
- `./bot/bot.py:7327` — `is_user_subscribed()`
- `./dashboard/check_subscription.php:44`, `./dashboard/dashboard.php:80`, `./dashboard/subscribers.php:35,102`, `./dashboard/admin/index.php:1194`, `./dashboard/admin/users.php:61`

#### GET /helix/subscriptions/user?broadcaster_id={X}&user_id={Y}
**Purpose**: check if user Y is subscribed to broadcaster X. Token: user Y's token (scope `user:read:subscriptions`).

**Used to gate "premium" features by checking if the streamer is subscribed to BotOfTheSpecter (`broadcaster_id=140296994`).**

**Callsites**:
- `./bot/bot.py:10011` — `check_premium_feature()` returns tier as int (1000/2000/3000) or 0
- `./dashboard/api/twitch_beta_access.php:10`

### 3.5 Followers

#### GET /helix/channels/followers
**Params**: `broadcaster_id=` (required), `user_id=` (optional), `first=` (page size, max 100), `after=` (cursor).

**Token**: broadcaster's user token, scope `moderator:read:followers`.

**Returns**: `total` (total followers), `data[].followed_at`.

**Callsites**:
- `./bot/bot.py:5680` — `!followage`
- `./dashboard/followers.php:49,89` — paginated full list

### 3.6 Moderation

#### GET /helix/moderation/moderators
**Params**: `broadcaster_id=`, optional `user_id=` repeatable.

**Token**: broadcaster's, scope `moderation:read` or `channel:manage:moderators`.

**Callsites**:
- `./bot/bot.py:7276` — `is_user_mod()`
- `./bot/bot.py:9955` — `known_users()` populates `everyone` table
- `./dashboard/bot.php:2264` (JS fetch from frontend), `./dashboard/bot_action.php:82`, `./dashboard/api/twitch_bot_status.php:8`, `./dashboard/mods.php:164,192`, `./dashboard/admin/start_bots.php:423,614,706`

#### GET /helix/moderation/banned
**Params**: `broadcaster_id=`, `user_id=` repeatable.

**Callsites**:
- `./dashboard/bot_action.php:107`, `./dashboard/api/twitch_bot_status.php:49`, `./dashboard/admin/start_bots.php:443,634`, `./dashboard/fetch_banned_status.php:42`

#### POST /helix/moderation/bans?broadcaster_id={id}&moderator_id={mod_id}
**Body**:
```json
{ "data": { "user_id": "...", "reason": "Spam/Bot Account", "duration": 600 } }
```
`duration` omitted = permanent ban.

**Token**: moderator's, scope `moderator:manage:banned_users`.

**Callsites**:
- `./bot/bot.py:8897` — `ban_user()` (uses bot account `971436498` as moderator by default; falls back to streamer when `use_streamer=True`)
- `./dashboard/bot.php:2219` (JS fetch — bans the bot itself for self-test scenario)

#### GET /helix/moderation/channels?user_id={id}
**Purpose**: list channels where the authenticated user is a moderator.

**Token**: user's, scope `user:read:moderated_channels`.

**Callsite**: `./dashboard/switch_channel.php:12`.

### 3.7 VIPs

#### GET /helix/channels/vips
**Params**: `broadcaster_id=`, optional `user_id=` repeatable, `first=`, `after=`.

**Callsites**:
- `./bot/bot.py:7299` — `is_user_vip()`
- `./bot/bot.py:9965` — populate `everyone` table
- `./dashboard/vips.php:34,65,134,148`

#### POST /helix/channels/vips?broadcaster_id={X}&user_id={Y}
**Token**: broadcaster's, scope `channel:manage:vips`.

**Callsites**: `./bot/beta.py:10479,11552`, `./dashboard/vips.php:104`.

### 3.8 Chat

#### GET /helix/chat/chatters?broadcaster_id={X}&moderator_id={X}
**Returns**: list of users in chat (logged-in viewers). Used for watch-time tracking.

**Token**: moderator's, scope `moderator:read:chatters`.

**Callsite**: `./bot/bot.py:10100` — `fetch_active_users()` (called every 60s by `periodic_watch_time_update()`).

#### POST /helix/chat/messages
**Body**:
```json
{
  "broadcaster_id": "...",
  "sender_id": "971436498",
  "message": "...",
  "reply_parent_message_id": "..."   // optional
}
```
**Token**: sender's, scope `user:write:chat` (and the sender must have IRC join rights to that channel).

**Returns**:
```json
{ "data": [{ "message_id": "...", "is_sent": true, "drop_reason": null }] }
```
Note: HTTP 200 does NOT mean delivered. Always check `data[0].is_sent` and surface `drop_reason` (e.g. `msg_rejected_mandatory`).

**Max message**: 255 chars (the bot rejects locally before posting).

**Callsites**:
- `./bot/bot.py:10551` — `send_chat_message()` (the canonical sender)
- `./dashboard/admin/index.php:711`, `./dashboard/send_welcome_message.php:95,224`

#### POST /helix/chat/shoutouts?from_broadcaster_id={X}&to_broadcaster_id={Y}&moderator_id={M}
**Token**: moderator's, scope `moderator:manage:shoutouts`.

**Twitch limits**: 1 shoutout per 2 minutes globally; 1 per 60 minutes to the same target. Target must be currently live.

**Callsites**:
- `./bot/bot.py:7588` — `trigger_twitch_shoutout()` (worker behind `add_shoutout()` queue with cooldown tracking)
- `./api/api.py:5695` — `/channel/twitch/shoutout` endpoint
- `./dashboard/admin/index.php:851`

### 3.9 Bits

#### GET /helix/bits/leaderboard
**Params**: `count=` (1–100), optionally `period=`, `started_at=`, `user_id=`.

**Token**: broadcaster's, scope `bits:read`.

**Returns**: `data[].user_id`, `user_name`, `rank`, `score`, `total`.

**Callsites**:
- `./bot/bot.py:4477` — `!cheerleader` (top-1)
- `./bot/bot.py:4540` — `!mybits` (filtered by user_id)

### 3.10 Clips

#### POST /helix/clips?broadcaster_id={id}
**Token**: broadcaster's, scope `clips:edit`.

**Returns**: `data[].id`, `data[].edit_url`. **Status 202** indicates queued; the clip URL becomes `https://clips.twitch.tv/{id}`.

**Callsites**:
- `./bot/bot.py:4917` — `!clip` (also creates a stream marker on success)

#### GET /helix/clips/downloads
**Used in**: `./dashboard/videos.php:145` for clip download URL retrieval.

#### DELETE /helix/videos?id={id}
Used in `./dashboard/videos.php:438`.

### 3.11 Stream markers

#### POST /helix/streams/markers
**Body**:
```json
{ "user_id": "{CHANNEL_ID}", "description": "..." }
```
`description` ≤ 140 chars.

**Token**: broadcaster's or editor's, scope `channel:manage:broadcast`.

**Callsite**: `./bot/bot.py:10048` — `make_stream_marker()` (called by `!clip`, follow events, etc.).

### 3.12 Ads

#### GET /helix/channels/ads?broadcaster_id={id}
**Returns**: `data[0]` with `next_ad_at`, `last_ad_at`, `duration`, `preroll_free_time`, `snooze_count`, `snooze_refresh_at`.

**Token**: broadcaster's, scope `channel:read:ads`.

**Callsites**:
- `./bot/bot.py:10317` — schedule next-ad check after current ad ends
- `./bot/bot.py:10347` — `check_and_handle_ads()` (called every 60s by `handle_upcoming_ads()` while live)

### 3.13 Raids

#### POST /helix/raids?from_broadcaster_id={X}&to_broadcaster_id={Y}
**Token**: from-broadcaster's, scope `channel:manage:raids`.

**Twitch rate limit**: 10 raids per 10 minutes per channel.

**Callsite**: `./api/api.py:5570` — `/channel/twitch/raid/start`.

#### DELETE /helix/raids?broadcaster_id={X}
**Callsite**: `./api/api.py:5620` — `/channel/twitch/raids/cancel`. Twitch returns 204 on success.

### 3.14 Schedule

#### GET /helix/schedule?broadcaster_id={X}&first=3
**Returns**: `data.segments[]` with `start_time`, `end_time`, `title`, `category`, `canceled_until`. `data.vacation` block if vacation set.

**Callsite**: `./bot/bot.py:5766` — `!schedule` (next-3 segments + vacation handling).

#### POST /helix/schedule/segment?broadcaster_id={X}
#### PATCH /helix/schedule/segment?broadcaster_id={X}&id={seg}
#### DELETE /helix/schedule/segment?broadcaster_id={X}&id={seg}
**Token**: broadcaster's, scope `channel:manage:schedule`.

**Callsites**: `./dashboard/schedule.php:286,351,418` (create / update / delete segment), `./dashboard/schedule.php:127,387` (cancel via PATCH `is_canceled=true`).

#### PATCH /helix/schedule/settings
Used in `./dashboard/schedule.php:442,557` to enable/disable vacation and toggle auto-cancel.

### 3.15 Channel Points

#### GET /helix/channel_points/custom_rewards?broadcaster_id={id}
Optional filters: `id=` (one or more), `only_manageable_rewards=true` (returns rewards created by the calling client_id only — these are the ones the app can edit/delete), `first=`, `after=`.

**Token**: broadcaster's, scope `channel:read:redemptions` (or `channel:manage:redemptions` for manageable).

**Callsites**:
- `./bot/bot.py:9427` — initial sync into `channel_point_rewards` table on bot startup
- `./bot/sync-channel-rewards.py:70,92` — full sync utility (CLI: `-channel`, `-channelid`, `-token`)
- `./dashboard/channel_rewards.php:265`, `./dashboard/manage_reward.php:168`, `./dashboard/create_reward.php:110,160`, `./dashboard/admin/users.php:478` — rewards listing

#### POST /helix/channel_points/custom_rewards?broadcaster_id={id}
Create reward. Body fields: `title`, `cost`, `prompt`, `is_enabled`, `background_color`, `is_user_input_required`, `should_redemptions_skip_request_queue`, `is_max_per_stream_enabled`+`max_per_stream`, `is_max_per_user_per_stream_enabled`+`max_per_user_per_stream`, `is_global_cooldown_enabled`+`global_cooldown_seconds`.

**Scope**: `channel:manage:redemptions`. The created reward is "manageable" only by the same client_id that created it.

**Callsites**: `./dashboard/create_reward.php:149`, `./dashboard/manage_reward.php:72,259`.

#### PATCH /helix/channel_points/custom_rewards?broadcaster_id={id}&id={reward}
Update reward (same body fields as POST, all optional).

**Callsites**: `./dashboard/manage_reward.php:97,272`, `./dashboard/edit_reward.php:91`.

#### DELETE /helix/channel_points/custom_rewards?broadcaster_id={id}&id={reward}
**Callsites**: `./dashboard/channel_rewards.php:159`, `./dashboard/manage_reward.php:228`.

#### GET /helix/channel_points/custom_rewards/redemptions
Params: `broadcaster_id=`, `reward_id=`, `status=UNFULFILLED|FULFILLED|CANCELED`, `id=` (specific redemption), `first=`, `after=`.

**Callsites**: `./dashboard/get_redemptions.php:27,28,29` (one call per status).

#### PATCH /helix/channel_points/custom_rewards/redemptions
Mark a redemption fulfilled or canceled. Body: `{"status":"FULFILLED"}` or `CANCELED`.

**Callsite**: `./dashboard/manage_redemption.php:68`.

### 3.16 Categories / Games

#### GET /helix/games?id={id} or ?name={name}
Look up a game by Twitch category ID or name. Used by `update_twitch_game()` to convert a name string into an ID before PATCH /channels.

**Callsites**: `./bot/bot.py:7503`, `./dashboard/schedule.php:72`.

#### GET /helix/search/categories?query={q}&first=10
Type-ahead category search.

**Callsites**: `./bot/beta.py:10836`, `./dashboard/schedule.php:41`.

### 3.17 EventSub management

#### POST /helix/eventsub/subscriptions
Used to register subscriptions over WebSocket transport. See [Section 4](#4-eventsub) for the full topic list and subscription matrix.

**Body shape**:
```json
{
  "type": "channel.follow",
  "version": "2",
  "condition": { "broadcaster_user_id": "...", "moderator_user_id": "..." },
  "transport": { "method": "websocket", "session_id": "{from welcome message}" }
}
```
Status codes: `200`/`202` = success (Twitch returns 200 if persisted instantly, 202 if processing).

**Callsites**:
- `./bot/bot.py:398` (`subscribe_to_events`)
- `./bot/beta.py:741` (with bot/broadcaster header split)
- `./bot/beta-v6.py:620` (with conduit + chat token split)
- `./dashboard/notifications_api.php:120`, `./dashboard/notifications.php:64` (admin-only registration UI)

#### GET /helix/eventsub/subscriptions[?first=100]
List all subscriptions for an app or status filter.

**Callsites**: `./dashboard/admin/event_sub.php:19`, `./dashboard/notifications.php:54`.

#### DELETE /helix/eventsub/subscriptions?id={sub_id}
Delete a subscription.

**Callsites**: `./dashboard/notifications_api.php:206,234`.

#### Conduit endpoints (v6 only)
##### GET /helix/eventsub/conduits
##### POST /helix/eventsub/conduits
**Body** for create: `{"shard_count": 1}`.

**Callsite**: `./bot/beta-v6.py:551,571` — `get_or_create_conduit()` runs in `event_ready()` before `subscribe_to_events()`. The conduit ID is stored in the global `CONDUIT_ID` but the actual transport is still `{"method":"websocket","session_id":...}`. The conduit is currently created/looked up but not yet used as the transport — leaves the door open for shifting to conduit-shard transport later.

---

## 4. EventSub

### 4.1 WebSocket transport (used everywhere)

**WS URI**: `wss://eventsub.wss.twitch.tv/ws?keepalive_timeout_seconds=600`

The bot opens this connection in `twitch_eventsub()` and reconnects on closure.

#### Message lifecycle
1. Server sends `session_welcome`. The payload contains `payload.session.id` and `payload.session.keepalive_timeout_seconds`.
2. Client has **10 seconds** to register subscriptions via Helix POST `/eventsub/subscriptions` with `transport.session_id` set.
3. Server sends `session_keepalive` if no notification has been delivered within `keepalive_timeout_seconds`.
4. Server sends `notification` for each subscribed event (payload format depends on `subscription.type`).
5. Server sends `session_reconnect` with a new `reconnect_url` when migrating shards. Client must connect to the new URL within 30s; old connection will close with code 4004.
6. Server sends `revocation` when a subscription is dropped (user revoked auth, version retired, etc.) — handle with re-subscription or alerting.

**Per-connection limits** (Twitch):
- Max **3 active WebSocket connections** per client_id + user_id
- Max **300 enabled subscriptions per connection**
- Combined total subscription "cost" ≤ 10

#### Stable / beta subscription matrix

`subscribe_to_events(session_id)` in `./bot/bot.py:396` and `./bot/beta.py:739`:

| Topic | Version | Condition | Notes |
| --- | --- | --- | --- |
| `stream.online` | 1 | `broadcaster_user_id` | |
| `stream.offline` | 1 | `broadcaster_user_id` | |
| `channel.subscribe` | 1 | `broadcaster_user_id` | stable only |
| `channel.subscription.gift` | 1 | `broadcaster_user_id` | stable only |
| `channel.subscription.message` | 1 | `broadcaster_user_id` | stable only |
| `channel.bits.use` | 1 | `broadcaster_user_id` | |
| `channel.raid` | 1 | `to_broadcaster_user_id` | incoming raids |
| `channel.raid` | 1 | `from_broadcaster_user_id` | outgoing raids — beta + v6 only |
| `channel.ad_break.begin` | 1 | `broadcaster_user_id` | |
| `channel.charity_campaign.donate` | 1 | `broadcaster_user_id` | |
| `channel.channel_points_custom_reward_redemption.add` | 1 | `broadcaster_user_id` | |
| `channel.channel_points_automatic_reward_redemption.add` | 2 | `broadcaster_user_id` | |
| `channel.poll.begin` | 1 | `broadcaster_user_id` | |
| `channel.poll.end` | 1 | `broadcaster_user_id` | |
| `channel.suspicious_user.message` | 1 | `broadcaster_user_id`, `moderator_user_id` | |
| `channel.shoutout.create` | 1 | `broadcaster_user_id`, `moderator_user_id` | |
| `channel.shoutout.receive` | 1 | `broadcaster_user_id`, `moderator_user_id` | |
| `channel.chat.user_message_hold` | 1 | `broadcaster_user_id`, `user_id` | |
| `automod.message.hold` | 2 | `broadcaster_user_id`, `moderator_user_id` | |
| `channel.follow` | 2 | `broadcaster_user_id`, `moderator_user_id` | v2 mandates moderator condition |
| `channel.update` | 2 | `broadcaster_user_id` | |
| `channel.hype_train.begin` | 2 | `broadcaster_user_id` | |
| `channel.hype_train.end` | 2 | `broadcaster_user_id` | |
| `channel.moderate` | 2 | `broadcaster_user_id`, `moderator_user_id` | |
| `channel.goal.begin` | 1 | `broadcaster_user_id` | beta only |
| `channel.goal.progress` | 1 | `broadcaster_user_id` | beta only |
| `channel.goal.end` | 1 | `broadcaster_user_id` | beta only |
| `channel.chat.message` | 1 | `broadcaster_user_id`, `user_id` | beta + v6 only — chat ingest via EventSub instead of IRC |
| `channel.chat.notification` | 1 | `broadcaster_user_id`, `user_id` | v6 only |

**Token strategy in beta + v6** (`./bot/beta.py:743`, `./bot/beta-v6.py:621`):
- Broadcaster topics → broadcaster's user access token (`CHANNEL_AUTH`).
- Chat topics (`channel.chat.*`) → **app access token** with the bot user ID (`971436498` for the official bot or the custom bot's resolved user ID).  
  Stable bot subscribes to chat via classic IRC instead, hence no `channel.chat.message` on stable.
- v6 has a 403-fallback path (`./bot/beta-v6.py:721`): if the app-token chat sub fails (broadcaster hasn't granted `channel:bot`), it retries with the broadcaster's token and `user_id = broadcaster_id`.

#### Deduplication
v6 keeps a `_seen_eventsub_message_ids` dict keyed by `metadata.message_id` with a TTL purge — Twitch can redeliver the same notification on reconnect, and we don't want to fire the same alert twice. (`./bot/beta.py:835`, equivalent in `./bot/beta-v6.py`.) Stable does **not** dedupe.

#### Reconnect handling
- Welcome / keepalive timeout violation → reset `twitch_websocket_uri` to default and `await sleep(10)`.
- `session_reconnect` → raise `EventSubReconnect(reconnect_url)` (beta + v6 only) so the outer loop swaps URI without sleeping.

### 4.2 Webhook transport (not currently used)

The API server does **not** receive Twitch EventSub webhooks. If we add it later, the rules:

- Endpoint must respond within a few seconds; offload to background workers.
- Verify HMAC-SHA256 signature: secret + concat of `Twitch-Eventsub-Message-Id` + `Twitch-Eventsub-Message-Timestamp` + raw body, compare time-safe to `Twitch-Eventsub-Message-Signature` (`sha256=...`).
- Three message types via `Twitch-Eventsub-Message-Type` header:
  - `webhook_callback_verification` — respond 2xx with the raw `challenge` string in body, `Content-Type: text/plain`.
  - `notification` — respond 2xx, then process.
  - `revocation` — respond 2xx, alert / re-subscribe.
- Replay window: reject messages older than 10 minutes by `Twitch-Eventsub-Message-Timestamp`.
- Dedupe via `Twitch-Eventsub-Message-Id` (Twitch retries on non-2xx).
- Secret: 10–100 ASCII chars, generated cryptographically. Stored per-subscription in `transport.secret` at creation time.

Existing webhook patterns in the project (Ko-fi, Kick, GitHub, etc.) are at `./api/api.py` — use one of those as a template if/when we add Twitch webhooks.

---

## 5. GraphQL (`gql.twitch.tv/gql`)

Used in **exactly one place** across all three bot files: `twitch_gql_token_valid()`.

```python
url = "https://gql.twitch.tv/gql"
headers = {
    "Client-Id": CLIENT_ID,                  # NOTE: not the Helix client; can be the public web client
    "Content-Type": "text/plain",
    "Authorization": f"OAuth {TWITCH_GQL}"   # OAuth prefix, not Bearer
}
data = [{
    "operationName": "SyncedSettingsEmoteAnimations",
    "variables": {},
    "extensions": {"persistedQuery": {"version": 1, "sha256Hash": "64ac5d385b316fd889f8c46942a7c7463a1429452ef20ffc5d0cd23fcc4ecf30"}}
}]
```
Callsites: `./bot/bot.py:8337`, `./bot/beta.py:11963`, `./bot/beta-v6.py:9670`.

**What the token is for**: `TWITCH_GQL` is a Twitch web-session OAuth token captured manually and stored as an env var. Streamlink consumes it to fetch the live HLS playlist for the song-detection / recording feature (`./bot/bot.py` `record_stream()`, `record_stream_audio()`). Twitch's HLS gating requires this to access the broadcaster's own stream segments.

**Why GQL and not Helix**: Helix has no equivalent for "is this OAuth web-session token still valid for HLS access". We hit the cheapest persisted query (`SyncedSettingsEmoteAnimations`) — a 200 response means the token is still authenticated; non-200 means re-capture.

**Caveat**: This is an unofficial / undocumented Twitch surface. The persisted-query SHA can change without notice. If `twitch_gql_token_valid()` starts returning 4xx for everyone, the SHA likely rotated — check the Twitch web client for the new hash. **Do not extend GQL usage**: any new feature should go through Helix.

---

## 6. TwitchIO library notes

### 6.1 Stable and beta — TwitchIO 2.10.0

Pin: `./bot/requirements.txt` line 3. Uses `commands.Bot`, IRC chat, and `routines.routine` for periodic tasks. EventSub is hand-rolled — there's no `twitchio.ext.eventsub` import in `./bot/bot.py` or `./bot/beta.py`; the WebSocket loop is implemented directly using the `websockets` library.

```python
from twitchio.ext import commands, routines
from twitchio.ext.commands import Context

class TwitchBot(commands.Bot):
    def __init__(self, token, prefix, channel_name):
        super().__init__(token=token, prefix=prefix,
                         initial_channels=[channel_name],
                         case_insensitive=True)
```

Key behaviours:
- `event_ready()` fires after IRC join.
- `event_message(message)` for every chat line — includes `message.tags` with `source-room-id` for shared-chat filtering.
- `event_command_error(ctx, error)` for cooldown / not-found.
- `commands.CommandOnCooldown.retry_after` (float seconds).
- IRC connection means TMI rate limits apply: 20 messages / 30s for non-mods, 100 / 30s for mods. Sending uses Helix `chat/messages` instead so this is mostly avoided, but join/part still count.

### 6.2 v6 — TwitchIO 3.1.0 (rewrite)

Pin: `./bot/beta_requirements.txt` lines 3–4 (`twitchio==3.1.0` plus `twitchio[starlette]==3.1.0` for the optional ASGI bridge — currently unused in v6).

```python
import twitchio
from twitchio.ext.commands import Context
from twitchio.ext import commands, routines, eventsub

class TwitchBot(commands.AutoBot):
    def __init__(self, prefix, client_id, client_secret, bot_id, owner_id,
                 subscriptions, force_subscribe):
        super().__init__(prefix=prefix, case_insensitive=True,
                         client_id=client_id, client_secret=client_secret,
                         bot_id=bot_id, owner_id=owner_id,
                         subscriptions=subscriptions, force_subscribe=force_subscribe)

# main():
subs = [eventsub.ChatMessageSubscription(broadcaster_user_id=CHANNEL_ID, user_id=bot_user_id)]
async with TwitchBot(prefix='!', client_id=CLIENT_ID, client_secret=CLIENT_SECRET,
                     bot_id=BOT_ID, owner_id=OWNER_ID,
                     subscriptions=subs, force_subscribe=True) as bot:
    await bot.add_token(BOT_OAUTH_TOKEN, REFRESH_TOKEN)
    await bot.start(load_tokens=False, save_tokens=False, with_adapter=False)
```

Differences that matter for porting from beta to v6:

| Concern | TwitchIO 2.10 (stable/beta) | TwitchIO 3.1 (v6) |
| --- | --- | --- |
| Base class | `commands.Bot` | `commands.AutoBot` |
| Bot identity | `token=...`, `nick`, `initial_channels=[...]` | `bot_id=`, `owner_id=`, `client_id`+`client_secret` mandatory |
| Token mgmt | App passes one token at construction | `bot.add_token(access, refresh)` per user; `event_token_refreshed(payload)` fires automatically |
| EventSub | Hand-roll WS + Helix POSTs | Native: `eventsub.ChatMessageSubscription(...)`, `subscriptions=[...]` arg; library still subscribes via Helix under the hood |
| Chat ingest | IRC (`event_message`) | EventSub `channel.chat.message` (the stack still implements `event_message` for compatibility) |
| Error context | `event_command_error(ctx, error)` | `event_command_error(payload: commands.CommandErrorPayload)` — read `payload.context` and `payload.exception` |
| Cooldown attr | `error.retry_after` | `error.remaining` (still float seconds) |
| Routines | `routines.routine(seconds=N)` | Same API |
| ASGI server | n/a | `twitchio[starlette]` extra adds Starlette adapter for webhook transport (we don't use it) |

Both versions still hand-roll the EventSub WebSocket loop in `twitch_eventsub()` rather than relying on TwitchIO's built-in client — this is intentional so we control reconnect/backoff/dedup behavior across all three versions identically.

---

## 7. Rate limits and points budget

### 7.1 Helix global

Twitch uses a token-bucket per client_id (app token) and per (client_id, user_id) pair (user token). Refill is continuous toward the per-minute cap.

Headers on every Helix response:
- `Ratelimit-Limit` — bucket size
- `Ratelimit-Remaining` — points left
- `Ratelimit-Reset` — Unix timestamp of next refill

Most endpoints cost 1 point. Some (e.g. `/users` with many `id=`/`login=` params batched) still cost 1. The default app-token bucket is 800 points/min — comfortably above what this project consumes — but the `is_user_mod`, `is_user_vip`, `is_user_subscribed`, `command_permissions` triple-call pattern in `./bot/bot.py` runs once per command invocation, so high-traffic chats can chew through user-token budget. **Cache mod/VIP/sub status in MySQL when feasible.**

429 responses must back off until `Ratelimit-Reset`. None of the bot files currently inspect `Ratelimit-Remaining`; if we add high-volume calls this is the first thing to instrument.

### 7.2 Endpoint-specific limits (Twitch-enforced)

| Endpoint | Limit | Behaviour on breach |
| --- | --- | --- |
| `POST /chat/shoutouts` | 1 per 2 min globally; 1 per 60 min same target | 429 |
| `POST /raids` | 10 per 10 min per channel | 429 |
| `POST /chat/messages` | Twitch IRC-equivalent caps; ~100/30s for mods | 200 with `is_sent: false` and `drop_reason` |
| `POST /clips` | ~600/60s per app | 429 |
| EventSub WS | 3 connections per (client_id, user_id); 300 subs per WS | 4xx on subscribe; closure 4001/4002 on overflow |

The bot enforces its own cooldowns on top of Twitch's where it matters:
- Shoutouts: `TWITCH_SHOUTOUT_GLOBAL_COOLDOWN` and `TWITCH_SHOUTOUT_USER_COOLDOWN` checked in `shoutout_worker` (`./bot/bot.py:7546`).
- Most user commands: `cooldown_rate` / `cooldown_time` per row in `builtin_commands` table.

---

## 8. Common error codes and gotchas

### 8.1 Helix errors

| Status | Cause | Fix |
| --- | --- | --- |
| 400 | Missing/invalid param, malformed body | Read response body — Twitch returns `{"error":"Bad Request","status":400,"message":"..."}` |
| 401 | Token expired/invalid, or wrong token type (e.g. user vs app) | Hit `/oauth2/validate`; if 401, refresh; if still 401, re-auth |
| 403 | Token valid but lacks required scope, or trying to act on a channel where the user isn't broadcaster/mod | Check the scope list in §2.6 against the endpoint's required scope |
| 404 | Resource (user, reward, segment) doesn't exist | Validate IDs upstream of the call |
| 409 | Conflict (e.g. EventSub sub already exists for that condition) | Treat as success for idempotent registration; otherwise dedupe before posting |
| 422 | Affiliate/Partner-only feature on a basic broadcaster (subs, channel points, ads) | Gate with `users.broadcaster_type ∈ {affiliate, partner}` first (already done for channel points in `./bot/bot.py:9419`) |
| 429 | Rate limit | Sleep until `Ratelimit-Reset` |

### 8.2 Project-specific gotchas

1. **`/oauth2/validate` uses `Authorization: OAuth {token}`, not `Bearer`.** Mixing them up returns 401. This is the only place in the project where `OAuth` is the right prefix (plus the GQL call in §5).
2. **Helix headers are case-insensitive but inconsistent in our code** — some files send `Client-ID`, others `Client-Id`. Don't "fix" one without checking the rest still parse.
3. **`POST /chat/messages` returns 200 on Twitch contact but the message can be silently dropped.** Always inspect `data[0].is_sent`. `drop_reason` examples: `"banned_user"`, `"duplicate_message"`, `"channel_settings"`, `"msg_rejected_mandatory"`.
4. **Channel point rewards POSTed by client_id A are not editable by client_id B.** `only_manageable_rewards=true` filters down to those owned by the calling app. The dashboard's reward CRUD only works on rewards Specter created.
5. **Subscriptions endpoint with no `user_id` requires broadcaster's own token + scope `channel:read:subscriptions`.** Trying it with the bot's app token returns 403 even with `bits:read` etc.
6. **`channel.follow` v2 requires `moderator_user_id` in the condition** even if it equals broadcaster_user_id. v1 is deprecated.
7. **EventSub session_id expires fast** — subscribe within 10 seconds of receiving `session_welcome` or the connection is closed.
8. **Bot user `971436498` is hardcoded** as the moderator/sender in many places. When implementing custom bots (`use_custom=1`), v6's `_fetch_custom_bot_user_id()` resolves the actual bot user ID at startup; stable/beta still hardcode.
9. **Stream marker description max 140 chars** — the bot validates before POSTing (`./bot/bot.py:10040`).
10. **Twitch's `Type=live` stream filter is required** when checking liveness — without it `data` includes scheduled/playlist items.
11. **`broadcaster_id=140296994` is the BotOfTheSpecter Twitch channel.** Used to gate "premium" features by checking if the streamer subscribes to BotOfTheSpecter via `/subscriptions/user`. Don't change it without coordinating with the premium tier logic in `check_premium_feature()`.
12. **Custom bot mode requires app access token for chat subs** — when running as a third-party bot, EventSub's `channel.chat.message` won't accept the bot's user token; needs the website-app credentials (`get_website_twitch_app_credentials()` in v6) to subscribe with App Access Token + bot's user_id.
13. **Token storage location must match the refresh script that owns it.** Broadcaster tokens live in `users.access_token`/`users.refresh_token` (refreshed by `./api/api.py` `_refresh_twitch_user_token`) and `website.twitch_bot_access` (refreshed in-process by the running bot). Custom bots in `custom_bots` table refreshed by `./bot/refresh_custom_bot_tokens.py`. Don't move them without updating every refresher.
14. **Helix returns ISO timestamps with trailing `Z`** — Python's `datetime.strptime` chokes on `Z` directly. Code uses `.replace('Z', '+00:00')` before parsing.

---

## Appendix: callsite quick index

For each Helix path, the canonical implementation file:

```
GET    /helix/users                                  → ./bot/bot.py:7159
GET    /helix/channels                               → ./bot/bot.py:9194
PATCH  /helix/channels                               → ./bot/bot.py:7483
GET    /helix/streams                                → ./bot/bot.py:5091
GET    /helix/subscriptions                          → ./bot/bot.py:5023
GET    /helix/subscriptions/user                     → ./bot/bot.py:10011
GET    /helix/channels/followers                     → ./bot/bot.py:5680
GET    /helix/moderation/moderators                  → ./bot/bot.py:7276
POST   /helix/moderation/bans                        → ./bot/bot.py:8897
GET    /helix/channels/vips                          → ./bot/bot.py:7299
GET    /helix/chat/chatters                          → ./bot/bot.py:10100
POST   /helix/chat/messages                          → ./bot/bot.py:10551
POST   /helix/chat/shoutouts                         → ./bot/bot.py:7588
GET    /helix/bits/leaderboard                       → ./bot/bot.py:4477
POST   /helix/clips                                  → ./bot/bot.py:4917
POST   /helix/streams/markers                        → ./bot/bot.py:10048
GET    /helix/channels/ads                           → ./bot/bot.py:10347
POST   /helix/raids                                  → ./api/api.py:5570
DELETE /helix/raids                                  → ./api/api.py:5620
GET    /helix/schedule                               → ./bot/bot.py:5766
*      /helix/schedule/segment                       → ./dashboard/schedule.php:286
GET    /helix/channel_points/custom_rewards          → ./bot/sync-channel-rewards.py:92
POST   /helix/channel_points/custom_rewards          → ./dashboard/manage_reward.php:259
PATCH  /helix/channel_points/custom_rewards          → ./dashboard/manage_reward.php:272
DELETE /helix/channel_points/custom_rewards          → ./dashboard/manage_reward.php:228
*      /helix/channel_points/.../redemptions         → ./dashboard/get_redemptions.php:27
GET    /helix/games                                  → ./bot/bot.py:7503
GET    /helix/search/categories                      → ./bot/beta.py:10836
POST   /helix/eventsub/subscriptions                 → ./bot/bot.py:398
GET    /helix/eventsub/subscriptions                 → ./dashboard/admin/event_sub.php:19
DELETE /helix/eventsub/subscriptions                 → ./dashboard/notifications_api.php:206
*      /helix/eventsub/conduits                      → ./bot/beta-v6.py:551
POST   https://id.twitch.tv/oauth2/token             → ./bot/bot.py:328 (refresh) | ./home/oauth_callback.php:16 (code)
GET    https://id.twitch.tv/oauth2/validate          → ./api/api.py:372
GET    https://id.twitch.tv/oauth2/authorize         → ./roadmap/login.php:13 (direct) | StreamersConnect SSO via ./home/login.php:302
POST   https://gql.twitch.tv/gql                     → ./bot/bot.py:8337
WS     wss://eventsub.wss.twitch.tv/ws               → ./bot/bot.py:372
```
