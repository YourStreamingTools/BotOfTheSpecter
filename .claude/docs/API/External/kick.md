# Kick.com API — BotOfTheSpecter Reference

Self-contained reference for how this project integrates with Kick.com. Combines the official Kick public API surface (verified at `https://docs.kick.com/`, May 2026) with the actual call sites in this repo.

Repo touch points:

- `./bot/kick.py` — Kick chat bot (~1,894 lines). Owns all Kick REST calls and OAuth refresh.
- `./api/api.py` — `POST /kick/{username}` webhook receiver, signature verification, public-key cache, and event-name mapping.
- `./dashboard/profile.php`, `./dashboard/recording.php` — UI surfaces (recording forwarder destination + profile badge). No Kick API calls happen in PHP.
- `./dashboard/lang/{en,fr,de}.php` — translation strings only.

There is **no `./config/kick.php`** today. Kick credentials are passed to `./bot/kick.py` as CLI args at process spawn time and persisted in MySQL (`website.kick_bot_tokens`). The bot reads no Kick env vars.

---

## 1. Overview — what this project actually uses

| Surface | Used? | Where |
| --- | --- | --- |
| OAuth 2.1 token refresh (`grant_type=refresh_token`) | Yes | `./bot/kick.py` `refresh_kick_token()` |
| OAuth 2.1 authorize / PKCE / app access token / introspect / revoke | No (bot starts with already-issued tokens) | — |
| `GET /public/v1/channels` (lookup by `broadcaster_user_id` or `slug`) | Yes | `./bot/kick.py` `get_channel_info()`, `get_kick_user_id()` |
| `PATCH /public/v1/channels` (set title / category) | Yes | `./bot/kick.py` `update_channel_info()` (used by `!settitle`, `!setgame`) |
| `GET /public/v1/livestreams` | Yes | `./bot/kick.py` `get_livestream_info()` (uptime / live check) |
| `GET /public/v1/livestreams/stats` | No | — |
| `GET /public/v2/categories` (search) | Yes | `./bot/kick.py` (game/category search for `!setgame`) |
| `GET /public/v1/categories` / `/{id}` (deprecated v1) | No | — |
| `POST /public/v1/chat` (send as bot) | Yes | `./bot/kick.py` `send_chat_message()` |
| `DELETE /public/v1/chat/{message_id}` | Yes | `./bot/kick.py` `delete_chat_message()` |
| `POST /public/v1/moderation/bans` | Yes | `./bot/kick.py` `ban_user()` |
| `DELETE /public/v1/moderation/bans` | Yes | `./bot/kick.py` `unban_user()` |
| `GET /public/v1/public-key` | Yes | `./api/api.py` `_get_kick_public_key()` (cached 1h) |
| `POST /public/v1/events/subscriptions` (subscribe to webhooks) | **Not in repo** — see flag below | — |
| Pusher / undocumented chat WebSocket | **No** — bot does not connect to Kick directly | — |

**Real-time data path:** Kick → HTTPS webhook → `./api/api.py` `POST /kick/{username}` → forwards to internal WebSocket server (`https://websocket.botofthespecter.com/notify?...`) → `./bot/kick.py` (Socket.IO client) receives the remapped event. The bot does **not** speak Pusher / Kick's chat WebSocket directly. All inbound events are webhooks.

**Flag — webhook subscription is not provisioned in code.** Kick's official API requires a `POST /public/v1/events/subscriptions` call (with `events:subscribe` scope) per channel before it will deliver webhooks. No code in this repo currently calls that endpoint, so subscriptions are presumably created out-of-band (manually via the Kick developer portal, or via a script not present in the repo). If a new streamer onboards, this is the missing step.

---

## 2. Authentication

### 2.1 OAuth 2.1 (PKCE)

Kick uses OAuth 2.1 with **mandatory PKCE** (`code_challenge_method=S256`). This project does **not** implement the authorize step; the bot is launched with already-issued tokens passed via CLI.

| Step | Method/URL | Notes |
| --- | --- | --- |
| Authorize | `GET https://id.kick.com/oauth/authorize` | Required params: `client_id`, `response_type=code`, `redirect_uri`, `scope`, `state`, `code_challenge`, `code_challenge_method=S256`. **Not used in repo.** |
| Token exchange | `POST https://id.kick.com/oauth/token` | `grant_type=authorization_code`, `code`, `client_id`, `client_secret`, `redirect_uri`, `code_verifier`. **Not used in repo.** |
| Token refresh | `POST https://id.kick.com/oauth/token` | `grant_type=refresh_token`, `refresh_token`, `client_id`, `client_secret`. **Used.** See `./bot/kick.py:246` `refresh_kick_token()`. |
| App token (server-to-server) | `POST https://id.kick.com/oauth/token` | `grant_type=client_credentials`. **Not used.** |
| Introspect | `POST https://id.kick.com/oauth/token/introspect` | Not used. |
| Revoke | `POST https://id.kick.com/oauth/revoke` | Not used. |

### 2.2 Token storage

- **In-process:** `CHANNEL_AUTH` (access token) and `REFRESH_TOKEN` are module-level globals in `./bot/kick.py`. Updated in place by `refresh_kick_token()`.
- **Persistent:** `website.kick_bot_tokens (channel_name, access_token, refresh_token)`. Updated by `_persist_kick_tokens()` after every successful refresh.
- **Refresh cadence:** Background loop `kick_token_refresh_loop()` sleeps 10,800 s (3 hours) between refreshes. Additionally, on a `401` response from `POST /chat` the bot refreshes once and retries (`./bot/kick.py:362`).
- **Token lifetime:** Kick's docs do not pin an exact `expires_in`; the 3 h preemptive refresh is conservative.

### 2.3 CLI-supplied credentials

`./bot/kick.py` requires these arguments at launch (none come from `.env`):

```text
-twitchusername  <slug>   # internal MySQL DB name + WebSocket channel code
-kickusername    <slug>   # the streamer's Kick channel slug (may differ)
-channelid       <int>    # Kick broadcaster_user_id
-chatroomid      <int>    # Kick chatroom ID (kept for parity; not used for current REST calls)
-token           <jwt>    # Kick OAuth access_token
-refresh         <jwt>    # Kick OAuth refresh_token
-clientid        <str>    # Kick app client_id
-clientsecret    <str>    # Kick app client_secret
-apitoken        <str>    # Internal BotOfTheSpecter API token (used to REGISTER on the WS)
```

Per [secrets.md](../../../rules/secrets.md): never log these values, never commit them. They live on the server side only.

### 2.4 Required scopes (Kick OAuth)

Granted by the streamer when they authorize the BotOfTheSpecter Kick app:

| Scope | Why this project needs it |
| --- | --- |
| `user:read` | Resolve user/channel info. |
| `channel:read` | `GET /channels`, `GET /livestreams`. |
| `channel:write` | `PATCH /channels` (set title / category from `!settitle`, `!setgame`). |
| `chat:write` | `POST /chat` (send messages). |
| `events:subscribe` | Required to register webhook subscriptions (see flag in §1). |
| `moderation:ban` | `POST/DELETE /moderation/bans`. |
| `moderation:chat_message:manage` | `DELETE /chat/{message_id}`. |

Scopes the project does **not** currently request (skip unless the feature is added):

- `channel:rewards:read` / `channel:rewards:write` — channel-points reward management.
- `streamkey:read` — only needed if we automate stream-key ingestion.
- `kicks:read` — KICKs leaderboards.

### 2.5 Request headers

Every authenticated REST call uses:

```http
Authorization: Bearer <CHANNEL_AUTH>
Content-Type: application/json
Accept: application/json
```

See `./bot/kick.py:287` `_kick_headers()`.

---

## 3. REST endpoints used

Base URLs:

```text
KICK_API_BASE  = https://api.kick.com/public/v1
KICK_AUTH_BASE = https://id.kick.com
```

Defined in `./bot/kick.py:67-68`. Exception: `GET /public/v2/categories` is on **v2**, called by passing `/v2/categories` to `kick_get()`, which produces `https://api.kick.com/public/v1/v2/categories` — **see §3.10 for the bug this likely is.**

The bot's HTTP helpers (`./bot/kick.py:294-343`):

- `kick_get(path, params=None)` — GET, returns parsed JSON or `None`.
- `kick_post(path, payload)` — POST JSON, returns `(status, body)`.
- `kick_delete(path, params=None)` — DELETE, returns status code.
- `kick_patch(path, payload)` — PATCH JSON, returns `(status, body)`.

All four use a shared aiohttp session (`get_http_session()`), 10-second timeout.

### 3.1 `GET /public/v1/channels`

Get channel info for a broadcaster.

- **Scope:** `channel:read`
- **Params:** `broadcaster_user_id` (array, ≤50) **OR** `slug` (array, ≤50)
- **Repo callsites:**
  - `./bot/kick.py:377` `get_channel_info()` — lookup by `broadcaster_user_id`.
  - `./bot/kick.py:1677` `get_kick_user_id(slug)` — lookup by `slug`. **Returns `data[0].broadcaster_user_id`** — used to resolve another user's ID for moderation, shoutouts, etc.
- **Response shape used:** `data[0].category.name`, `data[0].stream_title`, `data[0].broadcaster_user_id`.

### 3.2 `PATCH /public/v1/channels`

Update livestream metadata.

- **Scope:** `channel:write`
- **Body:** `broadcaster_user_id` (int, required by repo convention), `stream_title` (string, optional), `category_id` (int, optional), `custom_tags[]` (max 10, not used here).
- **Repo callsite:** `./bot/kick.py:389` `update_channel_info(title, category_id)` — invoked by `!settitle <text>` and `!setgame <name>` mod commands.

### 3.3 `GET /public/v1/livestreams`

List active streams.

- **Scope:** none.
- **Params:** `broadcaster_user_id[]` (≤50), `category_id`, `language`, `limit` (1–100), `sort` (`viewer_count`|`started_at`).
- **Repo callsite:** `./bot/kick.py:383` `get_livestream_info()` — single-broadcaster filter; returns `data[0]` with fields `is_live`, `started_at`, `category`. Used by `!uptime`, the startup live-check, and the periodic stream-status loop.

### 3.4 `GET /public/v2/categories` (search)

Search game/category by name.

- **Scope:** none.
- **Params:** `cursor` (pagination), `limit` (1–1000), `name[]`, `tag[]`, `id[]`.
- **Repo callsite:** `./bot/kick.py:1339` — invoked by `!setgame <query>`. Uses query-style `q` and `limit` params, which **looks like leftover v1 syntax mistakenly hitting v2** (see §3.10).

### 3.5 `POST /public/v1/chat`

Send a chat message as the bot.

- **Scope:** `chat:write`
- **Body:**
  - `content` (string, ≤500 chars; bot truncates to 497 + `...`).
  - `type`: `"user"` or `"bot"`. Repo always sends `"bot"` (`./bot/kick.py:352`).
  - `broadcaster_user_id` (int): required for `type=user`, ignored by Kick when `type=bot`. Repo includes it anyway.
  - `reply_to_message_id` (UUID, optional): threaded reply.
- **Success:** 200/201, body `{ is_sent: bool, message_id: uuid }`.
- **401 handling:** auto-refresh token + retry once.
- **429 handling:** sleep 2 s and return `False`.

### 3.6 `DELETE /public/v1/chat/{message_id}`

Remove a chat message.

- **Scope:** `moderation:chat_message:manage`
- **Success:** 204.
- **Repo callsite:** `./bot/kick.py:373` `delete_chat_message(message_id)`. Wired but no command currently triggers it as of writing — kept for moderation tooling.

### 3.7 `POST /public/v1/moderation/bans`

Ban or timeout a user.

- **Scope:** `moderation:ban`
- **Body:** `broadcaster_user_id` (int), `user_id` (int), `duration` (int, 1–10080 minutes; **omit for permanent ban**), `reason` (string, ≤100 chars; repo enforces the 100-char cap).
- **Repo callsite:** `./bot/kick.py:398` `ban_user(user_id, duration_minutes, reason)`.

### 3.8 `DELETE /public/v1/moderation/bans`

Unban / lift timeout.

- **Scope:** `moderation:ban`
- **Params:** `broadcaster_user_id`, `user_id` (both as query strings).
- **Repo callsite:** `./bot/kick.py:407` `unban_user(user_id)`.

### 3.9 `GET /public/v1/public-key`

Fetch the RSA public key used to sign webhooks.

- **Scope:** none (public).
- **Response:** `{ "public_key": "<base64-DER>" }` — repo also accepts `key` field as a fallback.
- **Repo callsite:** `./api/api.py:2078` `_get_kick_public_key()`.
- **Caching:** 1-hour in-process cache (`_KICK_PUBKEY_CACHE_TTL = 3600`).
- **Failure mode (current):** if the fetch fails, `_verify_kick_signature()` returns `True` (**fail-open**) so events still flow. There's a TODO comment to tighten this.

### 3.10 Known bugs / quirks in current REST usage

1. **`v2/categories` URL building:** `kick_get("/v2/categories", ...)` resolves to `https://api.kick.com/public/v1/v2/categories`. To call v2 cleanly, either build the URL from a separate `KICK_API_BASE_V2 = "https://api.kick.com/public/v2"` or call `aiohttp` directly bypassing `kick_get`. Verify in production traces whether the current `!setgame` actually returns results.
2. **Category search params:** repo passes `q` and `limit`, but the v2 endpoint's documented filter is `name[]`. If `!setgame` returns empty, this is the cause.
3. **Webhook timestamp header name:** repo reads `Kick-Event-Timestamp` (`./api/api.py:2159`). Official docs spell it `Kick-Event-Message-Timestamp`. If signature verification ever flips from "no signature header → skip" to "signature present → verify", a wrong timestamp value will fail every check. **See §5 for the full breakdown.**

Flag any of these for follow-up; they're current-as-of-this-doc, not historical.

---

## 4. Real-time events (no direct Kick WebSocket)

This project does **not** open a connection to any Kick-hosted WebSocket / Pusher endpoint. All real-time events arrive as HTTPS webhooks, are remapped, and are forwarded internally:

```text
Kick (Webhook) ──HTTPS──> ./api/api.py POST /kick/{username}
                              │
                              │ (verify RSA-SHA256 signature, map event name)
                              ▼
                    https://websocket.botofthespecter.com/notify
                              │
                              ▼
                    Internal WebSocket server (Socket.IO)
                              │
                              ▼
                    ./bot/kick.py (Socket.IO client, REGISTER channel)
```

The bot's Socket.IO event handlers (`./bot/kick.py:510-557`) listen for the **internal** event names listed in §5.4, not the raw Kick event strings.

Heartbeat/poll loops still exist on the bot side because webhooks aren't perfectly reliable:

- `check_stream_online_loop()` — periodically calls `GET /livestreams` to re-sync `stream_online` if the `livestream.status.updated` webhook is missed.

---

## 5. Webhooks — what we accept

### 5.1 Receiver

`./api/api.py:2144` — `POST /kick/{username}`.

`{username}` is the streamer's **internal** identifier (= their Twitch username, also their per-user MySQL DB name). It's **not** the Kick slug. The route:

1. Reads the raw body (`await request.body()`).
2. Reads four headers (see §5.2).
3. If `Kick-Event-Signature` is present, verifies it. **If absent, the request is accepted unverified** — webhooks without a signature header will pass through. Tighten if Kick guarantees the header.
4. Parses JSON.
5. Maps the event-type header to an internal event name via `_KICK_EVENT_MAP`.
6. Looks up the user's `api_key` in `users` (central DB) by `username`.
7. Forwards to the WebSocket server's `/notify` HTTP endpoint with `code=<api_key>`, `event=<internal_name>`, `channel=<username>`, `data=<json string>`.
8. Returns `200 {"status": "ok", "event": ...}` or `200 {"status": "ok", "note": "channel not registered"}` for unknown channels (so Kick stops retrying).

### 5.2 Headers Kick sends (and what we read)

| Kick header (per docs) | Header read in repo | Notes |
| --- | --- | --- |
| `Kick-Event-Message-Id` (ULID) | `Kick-Event-Message-Id` ✓ | Idempotency key. |
| `Kick-Event-Subscription-Id` (ULID) | not read | Subscription identifier; safe to ignore. |
| `Kick-Event-Signature` (base64) | `Kick-Event-Signature` ✓ | RSA-SHA256 / PKCS1v15. |
| `Kick-Event-Message-Timestamp` (RFC3339) | **`Kick-Event-Timestamp`** ✗ | **Header name mismatch — see §5.5 bug note.** |
| `Kick-Event-Type` (string) | `Kick-Event-Type` ✓ | Event name. |
| `Kick-Event-Version` (string) | not read | Always `"1"` today. |

### 5.3 Signature verification

Algorithm (Kick docs):

1. Concatenate: `<message_id> + "." + <timestamp> + "." + <raw_body_bytes>` — note: the dot-separators stay as ASCII; the body is appended verbatim.
2. Hash with SHA-256.
3. Verify with RSA-PKCS#1 v1.5 against the public key from `GET /public/v1/public-key`.

Repo implementation: `./api/api.py:2097` `_verify_kick_signature()` — uses `cryptography.hazmat.primitives.asymmetric.padding.PKCS1v15()` and `hashes.SHA256()`. The public key is loaded as DER (`serialization.load_der_public_key`).

### 5.4 Event-type mapping

`./api/api.py:2131` — every Kick event name is rewritten to a `KICK_*` internal name before being forwarded over the WebSocket:

| Kick event type (header) | Internal event name | Bot handler (`./bot/kick.py`) |
| --- | --- | --- |
| `chat.message.sent` | `KICK_CHAT` | `on_chat_message()` |
| `channel.followed` | `KICK_FOLLOW` | `on_follow()` |
| `channel.subscription.new` | `KICK_SUB` | `on_subscription()` |
| `channel.subscription.renewal` | `KICK_RESUB` | `on_subscription()` |
| `channel.subscription.gifts` | `KICK_GIFTSUB` | `on_gift_subscriptions()` |
| `channel.reward.redemption.updated` | `KICK_REDEMPTION` | `on_reward_redemption()` |
| `livestream.status.updated` | `KICK_STREAM_STATUS` | `on_livestream_status()` |
| `livestream.metadata.updated` | `KICK_STREAM_METADATA` | (logged only) |
| `moderation.banned` | `KICK_BAN` | `on_user_banned()` |
| `kicks.gifted` | `KICK_KICKS_GIFTED` | `on_kicks_gifted()` |
| (unknown) | `KICK_UNKNOWN` | (no handler) |

### 5.5 Payload shape & the `data → data` unwrap

Inside the bot, every Socket.IO payload goes through `_inner()` (`./bot/kick.py:495`) which:

1. Pulls `payload["data"]` if it's there (the WS server passes URL query strings, so `data` arrives as a JSON **string** that's then `json.loads`'d).
2. Then pulls **`raw["data"]`** again, because Kick wraps the actual fields one level deeper than top-level.

So the practical fields-per-event the bot reads are:

- **chat.message.sent**: `id`, `content`, `sender { id, slug, username, identity.badges[].type }`, `chatroom_id`.
- **channel.followed**: `user_id`, `username`, `slug`.
- **channel.subscription.new / renewal**: `subscriber { id, slug, username }`, `months`.
- **channel.subscription.gifts**: `gifter { slug }`, `gifts_count`.
- **channel.reward.redemption.updated**: `redeemer { slug }`, `reward { title }`.
- **livestream.status.updated**: `is_live` (bool).
- **livestream.metadata.updated**: full `metadata` (title, language, has_mature_content, category) — currently only logged.
- **moderation.banned**: `banned_user { slug }`, `moderator { slug }`.
- **kicks.gifted**: `gifter { slug }`, `amount`.

Note: Kick's official payload schema uses `broadcaster`, `subscriber`, `follower`, etc. as top-level objects under `data`. Repo code uses some variant field names (`user_id`/`username` flat for follow events; `subscriber.id`/`subscriber.slug` for subs). If a payload arrives in a different shape than expected, the helpers fall back to `"someone"`/`"Anonymous"`/`0` — they will not crash, but messages may be generic.

### 5.6 Bug: header name discrepancy

`./api/api.py:2159` reads `request.headers.get("Kick-Event-Timestamp", "")`. Kick's documented header is `Kick-Event-Message-Timestamp`. Today this is masked because:

- The current verifier is **fail-open** when the public key fetch fails (§3.9), and
- HTTP headers are case-insensitive but **not name-insensitive** — `Kick-Event-Timestamp` and `Kick-Event-Message-Timestamp` are distinct strings.

In practice this means the timestamp passed into `signed_bytes` is empty, so any signed verification attempt fails — but `_verify_kick_signature()` returns `False` on failure, which → `403 Invalid Kick webhook signature`. If signatures are currently being verified successfully, it's worth confirming Kick is also sending the legacy `Kick-Event-Timestamp` form. **Action item:** read both header names and prefer `Kick-Event-Message-Timestamp`.

### 5.7 Subscribing to webhooks (out of band today)

Kick does not auto-deliver events; subscriptions are explicit:

- **Endpoint:** `POST https://api.kick.com/public/v1/events/subscriptions`
- **Auth:** user access token with `events:subscribe` scope, **or** an app access token (in which case `broadcaster_user_id` is required in the body).
- **Body:**

  ```json
  {
    "method": "webhook",
    "broadcaster_user_id": 12345,
    "events": [
      { "name": "chat.message.sent",                   "version": 1 },
      { "name": "channel.followed",                    "version": 1 },
      { "name": "channel.subscription.new",            "version": 1 },
      { "name": "channel.subscription.renewal",        "version": 1 },
      { "name": "channel.subscription.gifts",          "version": 1 },
      { "name": "channel.reward.redemption.updated",   "version": 1 },
      { "name": "livestream.status.updated",           "version": 1 },
      { "name": "livestream.metadata.updated",         "version": 1 },
      { "name": "moderation.banned",                   "version": 1 },
      { "name": "kicks.gifted",                        "version": 1 }
    ]
  }
  ```

- **Webhook destination:** must be `https://api.botofthespecter.com/kick/{twitch_username}` (i.e. the user's internal username, matching the `_get_api_key_for_username()` lookup).

This is **not in the repo**. It's done manually or by a separate provisioning script. If you onboard a new Kick channel and webhooks aren't firing, this is the thing that's missing.

---

## 6. Rate limits

Documented (Kick):

- **Webhook subscriptions:** 10,000 active subscriptions per event type per app.
- **`chat.message.sent` subscriptions:** 1,000 per app for unverified apps. Verify the app to lift this.
- **REST endpoints:** Kick's docs do not publish per-endpoint per-second budgets. The bot treats `429` as "sleep 2 s and skip" (`./bot/kick.py:367`).

Unofficial / observed:

- Sending more than ~1 chat msg/sec from a single bot reliably triggers `429`. The 2-second backoff is tuned for this.

---

## 7. Differences from Twitch that matter for the bot

| Concern | Twitch | Kick |
| --- | --- | --- |
| Real-time channel | EventSub (WebSocket *or* webhooks) + IRC | **Webhooks only** (the chat WebSocket is unofficial; we don't use it). |
| Auth | OAuth 2.0 | OAuth 2.1 with **mandatory PKCE (S256)**. |
| Token endpoint | `https://id.twitch.tv/oauth2/token` | `https://id.kick.com/oauth/token`. |
| Refresh frequency | Bot.py background task; access tokens ~4 h | Bot.py background task; **3 h preemptive refresh** (`kick_token_refresh_loop`). |
| Channel IDs | Numeric `broadcaster_user_id` | Numeric `broadcaster_user_id` **and** human-readable `slug`. The bot tracks `TWITCH_USERNAME` (slug) ≠ `KICK_CHANNEL` (slug) ≠ `CHANNEL_ID` (numeric). |
| Send chat | `POST /helix/chat/messages` | `POST /public/v1/chat` with `type: "bot"` (the bot's identity is implicit in the OAuth token). |
| Bans | `POST /helix/moderation/bans` | `POST /public/v1/moderation/bans` — same shape, different field names. |
| Webhook signing | HMAC-SHA256 (shared secret) | **RSA-SHA256 (PKCS#1 v1.5) public-key**, signed bytes are `<msg_id>.<ts>.<raw_body>`. |
| Webhook verification key | shared secret per subscription | **single global key** at `GET /public/v1/public-key`. Repo caches 1 h. |
| Subscription model | One subscription per event per channel; auto-renewing | Same shape, but **must be created via the REST API** — there is no "subscribe at install time" UX yet, and the repo does not provision them. |
| Replies | `reply_parent_message_id` on `POST /helix/chat/messages` | `reply_to_message_id` on `POST /public/v1/chat`. |
| Categories ID | `game_id` | `category_id` (search via `GET /public/v2/categories`). |
| Free chat events | mod actions surfaced via EventSub | `moderation.banned` only — no `moderation.unbanned` event today, no `chat.message.deleted`, no raid/host equivalents. |
| Moderator badge detection | scopes / role flag | `sender.identity.badges[].type` — bot reads `"moderator"` and `"broadcaster"` strings. |
| Chat command surface | Same builtin set | The **same** builtin command list as Twitch (see `builtin_commands` in `./bot/kick.py:82`), minus features without a Kick equivalent (e.g., raids). |
| OBS / overlays | Existing overlays use `channel_code` from the user's API key | **Same WebSocket bus** — Kick events join the same WS channel via the `KICK_*` event names, so overlays can branch on event prefix to render Kick-specific assets. |
| MySQL scope | Per-user DB = Twitch username | **Same per-user DB** — Kick events write to `followers_data`, `subscription_data` with `source='Kick'` to disambiguate. |

---

## 8. Quick reference — file/line index

| Concern | Path:Line |
| --- | --- |
| CLI args / env loading | `./bot/kick.py:35-79` |
| API base URLs | `./bot/kick.py:67-68` |
| `_kick_headers()` (auth header) | `./bot/kick.py:287-292` |
| `refresh_kick_token()` | `./bot/kick.py:246-269` |
| `_persist_kick_tokens()` (`website.kick_bot_tokens`) | `./bot/kick.py:271-280` |
| `kick_token_refresh_loop()` (3 h) | `./bot/kick.py:282-285` |
| `kick_get/post/delete/patch` helpers | `./bot/kick.py:294-343` |
| `send_chat_message()` (with 401/429 retry) | `./bot/kick.py:345-371` |
| `delete_chat_message()` | `./bot/kick.py:373-375` |
| `get_channel_info()` | `./bot/kick.py:377-381` |
| `get_livestream_info()` | `./bot/kick.py:383-387` |
| `update_channel_info()` | `./bot/kick.py:389-396` |
| `ban_user()` / `unban_user()` | `./bot/kick.py:398-412` |
| Internal Socket.IO REGISTER | `./bot/kick.py:462-481` |
| Event handlers (`KICK_CHAT`, …) | `./bot/kick.py:510-557` |
| Webhook payload unwrap (`_inner`) | `./bot/kick.py:495-508` |
| `get_kick_user_id(slug)` | `./bot/kick.py:1677-1681` |
| Webhook receiver `POST /kick/{username}` | `./api/api.py:2144-2199` |
| Public-key cache + fetch | `./api/api.py:2073-2095` |
| Signature verifier | `./api/api.py:2097-2115` |
| Username → API key lookup | `./api/api.py:2117-2128` |
| Kick → internal event-name map | `./api/api.py:2131-2142` |
| Recording forwarder ("kick" service) | `./dashboard/recording.php:147-159` |
| Profile badge | `./dashboard/profile.php:1027-1029` |

---

## 9. Open follow-ups

1. **Provision webhook subscriptions in code.** Add a helper that POSTs `/public/v1/events/subscriptions` for every event in `_KICK_EVENT_MAP` when a streamer connects their Kick account. Without this, no events arrive.
2. **Fix the timestamp header name.** Read `Kick-Event-Message-Timestamp` (with `Kick-Event-Timestamp` as fallback for safety). See §5.6.
3. **Tighten signature failure mode.** Currently fail-open when the public key fetch errors (§3.9). Once the key endpoint is confirmed reliable, switch to fail-closed.
4. **Resolve the `v2/categories` URL bug.** Either introduce `KICK_API_BASE_V2` or call the v2 endpoint directly. Re-validate the search params (`name[]` vs `q`). See §3.10.
5. **Consider creating `./config/kick.php`** if/when any PHP page needs Kick credentials. Per [php-config.md](../../../rules/php-config.md), PHP must not read `.env`. Today no PHP code touches the Kick API, so this isn't yet needed.
