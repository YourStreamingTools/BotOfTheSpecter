# StreamLabs API — Comprehensive Reference

**Base URL (REST v2.0):** `https://streamlabs.com/api/v2.0`  
**Socket URL:** `https://sockets.streamlabs.com`  
**Developer portal:** `https://dev.streamlabs.com`  
**Last verified:** 2026-05-11

---

## Table of Contents

1. [Authentication (OAuth 2.0)](#1-authentication-oauth-20)
2. [REST Endpoints](#2-rest-endpoints)
   - [User](#21-user)
   - [Donations — GET](#22-donations--get)
   - [Donations — POST](#23-donations--post)
   - [Alerts — POST](#24-alerts--post)
   - [Socket Token](#25-socket-token)
   - [Points](#26-points)
   - [Credits](#27-credits)
   - [Jar](#28-jar)
   - [Wheel](#29-wheel)
   - [Alert Profiles](#210-alert-profiles)
   - [Media Share](#211-media-share)
3. [Pagination](#3-pagination)
4. [Currency Codes](#4-currency-codes)
5. [Socket.IO Real-time API](#5-socketio-real-time-api)
   - [Connection](#51-connection)
   - [Event envelope](#52-event-envelope)
   - [Donations (Streamlabs)](#53-donations-streamlabs)
   - [Twitch — Follow](#54-twitch--follow)
   - [Twitch — Subscription](#55-twitch--subscription)
   - [Twitch — Host](#56-twitch--host)
   - [Twitch — Bits](#57-twitch--bits)
   - [Twitch — Raid](#58-twitch--raid)
   - [YouTube — Follow](#59-youtube--follow)
   - [YouTube — Subscription](#510-youtube--subscription)
   - [YouTube — Superchat](#511-youtube--superchat)
   - [Mixer — Follow](#512-mixer--follow)
   - [Mixer — Subscription](#513-mixer--subscription)
   - [Mixer — Host](#514-mixer--host)
6. [Rate Limits](#6-rate-limits)
7. [Error Codes](#7-error-codes)
8. [BotOfTheSpecter Callsites](#8-botofthespecter-callsites)

---

## 1. Authentication (OAuth 2.0)

StreamLabs uses the **Authorization Code** flow (OAuth 2.0 RFC 6749 §4.1).

### 1.1 Register an application

Visit `https://streamlabs.com/dashboard#/oauth-clients/register` while logged in. Provide application name, description, redirect URI, and requested scopes. Apps start in an unapproved state; only up to 10 whitelisted users can authorize an unapproved app. Submit the app for review to lift this restriction.

After registration you receive a `client_id` and `client_secret`.

### 1.2 Authorization URL

```
GET https://streamlabs.com/api/v2.0/authorize
```

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `response_type` | string | Yes | Always `code` |
| `client_id` | string | Yes | Your application's client ID |
| `redirect_uri` | string | Yes | Must match a registered redirect URI |
| `scope` | string | Yes | Space-separated list of scopes (see §1.4) |
| `state` | string | Recommended | Opaque CSRF token; validated on callback |

The user is redirected to `redirect_uri?code={authorization_code}&state={state}` on approval, or `redirect_uri?error={error}&error_description={description}` on denial.

### 1.3 Token Exchange

```
POST https://streamlabs.com/api/v2.0/token
Content-Type: application/x-www-form-urlencoded
X-Requested-With: XMLHttpRequest
```

**Request body — authorization code grant:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `grant_type` | string | Yes | `authorization_code` |
| `client_id` | string | Yes | Your client ID |
| `client_secret` | string | Yes | Your client secret |
| `redirect_uri` | string | Yes | Same URI used in the authorization request |
| `code` | string | Yes | The authorization code from the callback |

**Request body — refresh token grant:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `grant_type` | string | Yes | `refresh_token` |
| `client_id` | string | Yes | Your client ID |
| `client_secret` | string | Yes | Your client secret |
| `redirect_uri` | string | Yes | StreamLabs requires the redirect URI on refresh (differs from many other providers) |
| `refresh_token` | string | Yes | The refresh token from the previous token response |

**Token response (200):**

```json
{
  "access_token": "eyJ...",
  "refresh_token": "def...",
  "token_type": "Bearer",
  "expires_in": 3600
}
```

> **Note:** StreamLabs has historically issued tokens described as "never expiring," but the `expires_in` field is returned and should be respected. Always persist `refresh_token` and implement refresh logic as a precaution.

### 1.4 Using the Access Token

All REST requests must include the bearer token in the Authorization header:

```
Authorization: Bearer {access_token}
```

> **Breaking change in v2.0:** The access token **cannot** be passed as a query parameter. Header delivery is required.

### 1.5 OAuth Scopes

| Scope | Description |
|-------|-------------|
| `donations.read` | Read donation history via `GET /donations` |
| `donations.create` | Post donations via `POST /donations` |
| `alerts.create` | Trigger new alerts via `POST /alerts` |
| `alerts.write` | Control alert queue: skip, mute/unmute, pause/unpause, send test alerts |
| `socket.token` | Obtain a dedicated socket token via `GET /socket/token` |
| `points.read` | Read loyalty point balances via `GET /points` |
| `points.write` | Modify points: add, subtract, import |
| `credits.write` | Roll the credits overlay |
| `profiles.write` | Retrieve and activate alert profiles |
| `jar.write` | Empty the tip jar widget |
| `wheel.write` | Spin the wheel widget |
| `mediashare.control` | Full media share control: play, pause, volume, skip, enable/disable requests and autoplay, manage moderation and backup |
| `legacy.token` | Access to legacy API token endpoint |

---

## 2. REST Endpoints

All endpoints use base URL `https://streamlabs.com/api/v2.0`. All require `Authorization: Bearer {access_token}` unless noted otherwise.

---

### 2.1 User

**`GET /user`**

Returns the authenticated user's Streamlabs profile.

**Parameters:** None.

**Response (200):**

```json
{
  "streamlabs": {
    "id": 12345678,
    "display_name": "ExampleStreamer",
    "primary": "twitch"
  },
  "twitch": {
    "id": "987654321",
    "login": "examplestreamer",
    "display_name": "ExampleStreamer",
    "broadcaster_type": "partner",
    "description": "...",
    "profile_image_url": "https://...",
    "view_count": 1000000
  },
  "youtube": null,
  "mixer": null,
  "facebook": null,
  "partnered": false,
  "prime": false,
  "socket_token": null
}
```

> Only the platforms connected by the user will have non-null objects. The `socket_token` field here is not the dedicated socket token; use `GET /socket/token` instead.

---

### 2.2 Donations — GET

**`GET /donations`**

Retrieve the authenticated user's donation history in reverse chronological order.

**Query parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `limit` | int32 | No | Number of results to return. Each endpoint has an internal maximum threshold; values above it are clamped. |
| `before` | int32 | No | Return donations with an ID strictly less than this value (cursor for older records). |
| `after` | int32 | No | Return donations with an ID strictly greater than this value (cursor for newer records). |
| `currency` | string | No | ISO 4217 3-letter currency code. When set, all amounts are converted to this currency. If omitted, each record retains its originating currency. |
| `verified` | boolean | No | `true` — return only verified donations (PayPal, credit card, Skrill, Unitpay). `false` — return only unverified (streamer-added). Omit to return both. |

**Response (200):**

```json
{
  "data": [
    {
      "id": 79530994,
      "name": "DonorName",
      "message": "Keep up the great content!",
      "amount": "10.00",
      "currency": "USD",
      "created_at": 1715000000
    }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Donation ID (used as cursor for pagination) |
| `name` | string | Donor display name |
| `message` | string | Donor message (may be empty string) |
| `amount` | string or number | Donation amount. Type varies by currency; coerce to float before use. |
| `currency` | string | ISO 4217 code of the donation currency |
| `created_at` | integer | Unix epoch timestamp (seconds) |

---

### 2.3 Donations — POST

**`POST /donations`**

Create a donation entry (e.g., for manual entry or integration bridging). Requires scope `donations.create`.

**Request body (application/x-www-form-urlencoded or JSON):**

| Parameter | Type | Required | Constraints | Description |
|-----------|------|----------|-------------|-------------|
| `name` | string | Yes | 2–25 characters, UTF-8 | Donor display name |
| `identifier` | string | Yes | — | Unique identifier for the donor, used to group repeat donors (e.g., email address or a stable hash) |
| `amount` | double | Yes | — | Donation amount |
| `currency` | string | Yes | Must be one of the supported currency codes (see §4) | ISO 4217 currency code |
| `message` | string | No | Max 255 characters | Message from the donor |
| `created_at` | string | No | — | Timestamp for the donation; defaults to current server time if omitted |
| `skip_alert` | string | No | `"yes"` or `"no"` | Defaults to `"no"`. Set to `"yes"` to suppress the Streamlabs alert for this donation. |

**Response (200):**

```json
{
  "donation_id": 79530995
}
```

---

### 2.4 Alerts — POST

**`POST /alerts`**

Trigger a visual/audio alert in the streamer's Streamlabs alert box. Requires scope `alerts.create`.

**Request body:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `type` | string | Yes | Alert box target. One of: `follow`, `subscription`, `donation`, `host` |
| `image_href` | string | No | URL to an image asset. Pass empty string to use the streamer's default image. |
| `sound_href` | string | No | URL to an audio asset. Pass empty string to play the streamer's default sound. |
| `message` | string | No | Alert message text. Wrap special token names in asterisks (e.g., `*name*`) for dynamic substitution. |
| `user_message` | string | No | Secondary heading text displayed below the main message. |
| `duration` | string | No | Display duration in milliseconds, as a string (e.g., `"5000"` for 5 seconds). |
| `special_text_color` | string | No | CSS color string (e.g., `"#FF0000"`) applied to special token text. |

**Response (200):**

```json
{
  "success": true
}
```

**Alert control endpoints** (require scope `alerts.write`):

| Method | Path | Description |
|--------|------|-------------|
| POST | `/alerts/skip` | Skip the currently playing alert |
| POST | `/alerts/mute_volume` | Mute alert audio |
| POST | `/alerts/unmute_volume` | Unmute alert audio |
| POST | `/alerts/pause_queue` | Pause the alert queue |
| POST | `/alerts/unpause_queue` | Resume the alert queue |
| POST | `/alerts/send_test_alert` | Fire a test alert |

These control endpoints take no body parameters.

---

### 2.5 Socket Token

**`GET /socket/token`**

Obtain a dedicated, opaque WebSocket authentication token for the real-time Socket.IO API. Requires scope `socket.token`.

**Parameters:** None.

**Response (200):**

```json
{
  "socket_token": "eydFe..."
}
```

| Field | Type | Description |
|-------|------|-------------|
| `socket_token` | string | Opaque token used as the `token` query parameter when connecting to `wss://sockets.streamlabs.com`. This token is distinct from and unrelated to the OAuth `access_token`. |

> The OAuth `access_token` with the `socket.token` scope can also be used directly in place of `socket_token` when connecting to the WebSocket — but the dedicated socket token is preferred.

> Do not open the same `socket_token` from two concurrent processes. StreamLabs enforces a per-token concurrent connection limit and will revoke the token if it is exceeded.

---

### 2.6 Points

**`GET /points`**

Retrieve a viewer's loyalty point balance for a channel. Requires scope `points.read`.

**Query parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `username` | string | Yes | The viewer's username to look up |
| `channel` | string | Yes | The channel name (e.g., `iddqd`) |

**Response (200):**

```json
{
  "id": 1234,
  "platform": "twitch",
  "channel": "examplestreamer",
  "username": "viewername",
  "exp": 500,
  "points": 12500,
  "ta_id": null,
  "status": "vip",
  "time_watched": 36000,
  "created_at": null,
  "updated_at": "2026-05-10T12:00:00.000000Z"
}
```

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Internal record ID |
| `platform` | string | Platform identifier (e.g., `"twitch"`) |
| `channel` | string | Channel name |
| `username` | string | Viewer's username |
| `exp` | integer | Experience value (usage varies by streamer config) |
| `points` | integer | Current loyalty point balance |
| `ta_id` | string or null | Third-party account ID, if linked |
| `status` | string | Viewer status label (e.g., `"vip"`, `"regular"`) |
| `time_watched` | integer | Cumulative watch time in seconds |
| `created_at` | string or null | ISO 8601 timestamp or null |
| `updated_at` | string | ISO 8601 timestamp of last update |

**Points write endpoints** (require scope `points.write`):

| Method | Path | Description |
|--------|------|-------------|
| POST | `/points/subtract` | Deduct points from a viewer |
| POST | `/points/import` | Bulk-import points for multiple viewers |
| POST | `/points/add` | Add points to all viewers or a specific viewer |

---

### 2.7 Credits

**`POST /credits/roll`**

Roll the end-of-stream credits overlay. Requires scope `credits.write`.

**Parameters:** None.

**Response (200):**

```json
{
  "success": true
}
```

---

### 2.8 Jar

**`POST /jar/empty`**

Empty (reset) the tip jar widget. Requires scope `jar.write`.

**Parameters:** None.

**Response (200):**

```json
{
  "success": true
}
```

---

### 2.9 Wheel

**`POST /wheel/spin`**

Spin the wheel widget. Requires scope `wheel.write`.

**Parameters:** None.

**Response (200):**

```json
{
  "success": true
}
```

---

### 2.10 Alert Profiles

Requires scope `profiles.write`.

**`GET /alert-profiles`**

Return all configured alert profiles.

**Response (200):**

```json
{
  "data": [
    {
      "id": 1,
      "name": "Default",
      "active": true
    }
  ]
}
```

**`POST /alert-profiles/{id}/activate`**

Activate the alert profile with the given `id`.

**Response (200):**

```json
{
  "success": true
}
```

---

### 2.11 Media Share

All media share control actions require scope `mediashare.control`.

| Method | Path | Description |
|--------|------|-------------|
| GET | `/mediashare/queue` | Retrieve the current media queue |
| DELETE | `/mediashare/queue/{id}` | Remove a specific item from the queue |
| POST | `/mediashare/play` | Start playback |
| POST | `/mediashare/pause` | Pause playback |
| POST | `/mediashare/skip` | Skip the current item |
| POST | `/mediashare/volume` | Set playback volume |
| POST | `/mediashare/requests/enable` | Enable media request submissions |
| POST | `/mediashare/requests/disable` | Disable media request submissions |
| POST | `/mediashare/autoplay/enable` | Enable autoplay |
| POST | `/mediashare/autoplay/disable` | Disable autoplay |

---

## 3. Pagination

The StreamLabs REST API uses **cursor-based pagination** on list endpoints (e.g., `GET /donations`).

| Parameter | Description |
|-----------|-------------|
| `limit` | Maximum number of records per page. Each endpoint defines its own maximum; requests above it are clamped silently. |
| `before` | Cursor — return records with an ID less than this value (paginating towards older records). |
| `after` | Cursor — return records with an ID greater than this value (paginating towards newer records). |

Typical pattern:

```
# Page 1
GET /donations?limit=50

# Page 2 — pass the smallest ID from page 1 as 'before'
GET /donations?limit=50&before=79530994
```

All list responses return an object with a top-level `data` array. Each item in the array has an `id` field usable as a cursor.

---

## 4. Currency Codes

Supported ISO 4217 currency codes for donation amounts:

| Code | Currency |
|------|----------|
| AUD | Australian Dollar |
| BRL | Brazilian Real |
| CAD | Canadian Dollar |
| CHF | Swiss Franc |
| CZK | Czech Koruna |
| DKK | Danish Krone |
| EUR | Euro |
| GBP | British Pound Sterling |
| HKD | Hong Kong Dollar |
| ILS | Israeli New Shekel |
| JPY | Japanese Yen |
| MXN | Mexican Peso |
| MYR | Malaysian Ringgit |
| NOK | Norwegian Krone |
| NZD | New Zealand Dollar |
| PHP | Philippine Peso |
| PLN | Polish Zloty |
| RUB | Russian Ruble |
| SEK | Swedish Krona |
| SGD | Singapore Dollar |
| THB | Thai Baht |
| TRY | Turkish Lira |
| USD | US Dollar |

---

## 5. Socket.IO Real-time API

The Socket.IO API provides push-based real-time events without polling. It is the preferred integration method for receiving live donation, follow, subscription, and other stream events.

### 5.1 Connection

**WebSocket URL:**

```
wss://sockets.streamlabs.com/socket.io/?token={token}&transport=websocket
```

| Parameter | Description |
|-----------|-------------|
| `token` | The `socket_token` from `GET /socket/token`, or the OAuth `access_token` (which also works with `socket.token` scope). |
| `transport` | Must be `websocket`. |

**Engine.IO protocol version:** The Streamlabs socket gateway uses Engine.IO protocol v3. When connecting with a raw WebSocket client (not the Socket.IO SDK), append `&EIO=3` to the URL. When using the Socket.IO JavaScript SDK or `python-socketio`, let the library negotiate this automatically.

**JavaScript SDK example (recommended):**

```javascript
const io = require('socket.io-client');
const streamlabs = io('https://sockets.streamlabs.com', {
  transports: ['websocket'],
  query: { token: socketToken }
});

streamlabs.on('event', (data) => {
  console.log(data.type, data.for, data.message);
});
```

**Raw WebSocket example (Python `websockets`):**

```python
uri = f"wss://sockets.streamlabs.com/socket.io/?token={token}&EIO=3&transport=websocket"
async with websockets.connect(uri) as ws:
    async for raw in ws:
        # Strip Engine.IO frame prefix ("42") before JSON-parsing
        if raw.startswith("42"):
            payload = json.loads(raw[2:])  # ["event", {...}]
            event_data = payload[1]
```

**Engine.IO control frames** emitted by the server (ignore or handle as shown):

| Frame | Meaning | Response required |
|-------|---------|-------------------|
| `0{...}` | Handshake (server sends session info) | None |
| `40` | Socket.IO namespace connect confirmation | None |
| `2` | Ping | Send `3` (pong) |
| `3` | Pong | None |

### 5.2 Event Envelope

All real-time events arrive on the `event` socket event name. The payload is an object with the following top-level fields:

| Field | Type | Description |
|-------|------|-------------|
| `type` | string | Event category (e.g., `donation`, `follow`, `subscription`, `host`, `bits`, `raid`, `superchat`) |
| `for` | string or absent | Platform identifier: `twitch_account`, `youtube_account`, `mixer_account`. Absent for Streamlabs-native donations. |
| `message` | array | Array of one or more event payload objects. Always iterate — multiple events can arrive in a single frame. |
| `event_id` | string | Opaque unique identifier for deduplication. Present on some event types. |

### 5.3 Donations (Streamlabs)

**`type: "donation"` — no `for` field**

```json
{
  "type": "donation",
  "message": [
    {
      "id": 79530994,
      "_id": "abc123",
      "event_id": "evt_xyz",
      "name": "DonorName",
      "from": "DonorName",
      "from_user_id": "98765",
      "amount": "5.00",
      "formatted_amount": "$5.00",
      "currency": "USD",
      "message": "Cheers!",
      "emotes": null,
      "iconClassName": "fas fa-heart",
      "to": {
        "name": "StreamerName"
      }
    }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Donation record ID |
| `_id` | string | Secondary internal identifier |
| `event_id` | string | Unique event ID for deduplication |
| `name` | string | Donor display name |
| `from` | string | Alias for `name` |
| `from_user_id` | string | Streamlabs internal user ID of the donor |
| `amount` | string | Donation amount as a string (e.g., `"5.00"`). **Must be coerced to float before arithmetic.** |
| `formatted_amount` | string | Currency-formatted amount string (e.g., `"$5.00"`) |
| `currency` | string | ISO 4217 currency code |
| `message` | string | Donor message (may be empty string) |
| `emotes` | null or object | Emote data if applicable |
| `iconClassName` | string | CSS icon class for UI rendering |
| `to.name` | string | The channel/streamer receiving the donation |

### 5.4 Twitch — Follow

**`type: "follow"`, `for: "twitch_account"`**

```json
{
  "type": "follow",
  "for": "twitch_account",
  "message": [
    {
      "_id": "follow_123",
      "id": "987654321",
      "name": "NewFollower",
      "created_at": "2026-05-11T10:00:00.000Z"
    }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `_id` | string | Internal event ID |
| `id` | string | Twitch user ID of the follower |
| `name` | string | Twitch username of the follower |
| `created_at` | string | ISO 8601 timestamp of the follow |

### 5.5 Twitch — Subscription

**`type: "subscription"`, `for: "twitch_account"`**

```json
{
  "type": "subscription",
  "for": "twitch_account",
  "message": [
    {
      "_id": "sub_456",
      "name": "SubscriberName",
      "months": 3,
      "message": "Love the streams!",
      "emotes": null,
      "sub_plan": "1000",
      "sub_plan_name": "Channel Subscription",
      "sub_type": "resub"
    }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `_id` | string | Internal event ID |
| `name` | string | Subscriber's Twitch username |
| `months` | integer | Cumulative months subscribed |
| `message` | string | Re-sub message (may be empty) |
| `emotes` | null or object | Emote data in the message |
| `sub_plan` | string | Subscription tier: `"Prime"`, `"1000"`, `"2000"`, `"3000"` |
| `sub_plan_name` | string | Human-readable tier name |
| `sub_type` | string | `"sub"` (new), `"resub"` (renewal), `"subgift"` (gifted) |

### 5.6 Twitch — Host

**`type: "host"`, `for: "twitch_account"`**

```json
{
  "type": "host",
  "for": "twitch_account",
  "message": [
    {
      "_id": "host_789",
      "name": "HostingChannel",
      "viewers": 150,
      "type": "manual"
    }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `_id` | string | Internal event ID |
| `name` | string | Username of the hosting channel |
| `viewers` | integer | Number of viewers brought by the host |
| `type` | string | `"manual"` or `"auto"` |

### 5.7 Twitch — Bits

**`type: "bits"`, `for: "twitch_account"`**

```json
{
  "type": "bits",
  "for": "twitch_account",
  "message": [
    {
      "_id": "bits_001",
      "id": "12345678",
      "name": "CheerUser",
      "amount": 500,
      "message": "Cheer500 great stream!",
      "emotes": null
    }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `_id` | string | Internal event ID |
| `id` | string | Twitch user ID of the cheering viewer |
| `name` | string | Twitch username of the cheering viewer |
| `amount` | integer | Number of bits cheered |
| `message` | string | Cheer message including emote strings |
| `emotes` | null or object | Parsed emote positions |

### 5.8 Twitch — Raid

**`type: "raid"`, `for: "twitch_account"`**

```json
{
  "type": "raid",
  "for": "twitch_account",
  "event_id": "raid_evt_002",
  "message": [
    {
      "_id": "raid_002",
      "name": "RaidingChannel",
      "raiders": 200
    }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `_id` | string | Internal event ID |
| `event_id` | string | Top-level unique event ID |
| `name` | string | Username of the raiding channel |
| `raiders` | integer | Number of raiders |

### 5.9 YouTube — Follow

**`type: "follow"`, `for: "youtube_account"`**

```json
{
  "type": "follow",
  "for": "youtube_account",
  "message": [
    {
      "_id": "yt_follow_001",
      "id": "UCxxxxxx",
      "name": "YouTubeUser",
      "publishedAt": "2026-05-11T10:00:00.000Z"
    }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `_id` | string | Internal event ID |
| `id` | string | YouTube channel ID |
| `name` | string | YouTube display name |
| `publishedAt` | string | ISO 8601 timestamp |

### 5.10 YouTube — Subscription

**`type: "subscription"`, `for: "youtube_account"`**

```json
{
  "type": "subscription",
  "for": "youtube_account",
  "message": [
    {
      "_id": "yt_sub_001",
      "id": "UCxxxxxx",
      "name": "MemberName",
      "channelUrl": "https://youtube.com/channel/UCxxxxxx",
      "months": 2,
      "sponsorSince": "2026-04-11T10:00:00.000Z"
    }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `_id` | string | Internal event ID |
| `id` | string | YouTube channel ID |
| `name` | string | Member's display name |
| `channelUrl` | string | Full YouTube channel URL |
| `months` | integer | Months as a member |
| `sponsorSince` | string | ISO 8601 start date of membership |

### 5.11 YouTube — Superchat

**`type: "superchat"`, `for: "youtube_account"`**

```json
{
  "type": "superchat",
  "for": "youtube_account",
  "message": [
    {
      "_id": "yt_sc_001",
      "id": "superchat_id_abc",
      "channelId": "UCxxxxxx",
      "channelUrl": "https://youtube.com/channel/UCxxxxxx",
      "name": "SuperchatSender",
      "comment": "Amazing stream, keep it up!",
      "amount": "20.00",
      "currency": "USD",
      "displayString": "$20.00",
      "messageType": 4,
      "createdAt": "2026-05-11T10:00:00.000Z"
    }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `_id` | string | Internal event ID |
| `id` | string | Superchat event ID |
| `channelId` | string | Sender's YouTube channel ID |
| `channelUrl` | string | Sender's YouTube channel URL |
| `name` | string | Sender's display name |
| `comment` | string | The Superchat message text |
| `amount` | string | Amount as decimal string |
| `currency` | string | ISO 4217 currency code |
| `displayString` | string | Formatted amount (e.g., `"$20.00"`) |
| `messageType` | integer | YouTube Superchat tier type (1–7) |
| `createdAt` | string | ISO 8601 timestamp |

### 5.12 Mixer — Follow

**`type: "follow"`, `for: "mixer_account"`**

| Field | Type | Description |
|-------|------|-------------|
| `_id` | string | Internal event ID |
| `id` | string | Mixer user ID |
| `name` | string | Mixer username |
| `created_at` | string | ISO 8601 timestamp |

### 5.13 Mixer — Subscription

**`type: "subscription"`, `for: "mixer_account"`**

| Field | Type | Description |
|-------|------|-------------|
| `_id` | string | Internal event ID |
| `id` | string | Mixer user ID |
| `name` | string | Mixer username |
| `months` | integer | Cumulative months subscribed |
| `message` | string | Sub message (may be empty) |
| `emotes` | null or object | Emote data |
| `since` | string | ISO 8601 subscription start timestamp |

### 5.14 Mixer — Host

**`type: "host"`, `for: "mixer_account"`**

| Field | Type | Description |
|-------|------|-------------|
| `_id` | string | Internal event ID |
| `name` | string | Hosting channel name |
| `viewers` | integer | Number of viewers brought |
| `type` | string | `"manual"` or `"auto"` |

> **Note:** Mixer shut down in July 2020. These events are documented because the Streamlabs socket API still defines the schema; in practice they will never fire.

---

## 6. Rate Limits

StreamLabs does not publish exact numeric rate limits in its public documentation.

**REST endpoints:**
- A tier-based rate limit exists. The specific ceiling is not surfaced for free-tier applications.
- On limit breach, the server returns HTTP `429 Too Many Requests` with a `Retry-After` header (value in seconds). Implement exponential backoff respecting this header.
- The `GET /donations` and `GET /user` endpoints on the dashboard are uncached — add a response cache or backoff if they are called in a hot path.

**Socket.IO:**
- No documented per-event rate limit.
- A **per-token concurrent connection limit** exists in practice. Opening the same `socket_token` from more than one process simultaneously will cause StreamLabs to revoke the token. Keep socket connections to one process per token.

---

## 7. Error Codes

**HTTP status codes returned by the REST API:**

| Status | Meaning |
|--------|---------|
| `200 OK` | Request succeeded |
| `400 Bad Request` | Invalid or missing parameters. Response body contains error details. |
| `401 Unauthorized` | Missing or invalid `Authorization` header. Token may be expired or revoked. |
| `403 Forbidden` | Valid token but insufficient scope for this endpoint. |
| `404 Not Found` | Resource does not exist. |
| `422 Unprocessable Entity` | Request is well-formed but contains semantic errors (e.g., unsupported currency code). |
| `429 Too Many Requests` | Rate limit exceeded. Respect `Retry-After` header. |
| `500 Internal Server Error` | Server-side error. Retry with backoff. |

**Error response body (400/422):**

```json
{
  "error": "validation_error",
  "message": "The name field must be between 2 and 25 characters."
}
```

**OAuth errors (delivered as query parameters on redirect):**

| Parameter | Example value | Description |
|-----------|---------------|-------------|
| `error` | `access_denied` | User denied authorization |
| `error_description` | `The user denied access` | Human-readable error description |

---

## 8. BotOfTheSpecter Callsites

This section documents how BotOfTheSpecter uses the StreamLabs API specifically. The rest of this file is a general API reference.

### What this project uses

| Feature | API surface |
|---------|-------------|
| Realtime donation events | Socket.IO `donation` event (§5.3) |
| Recent donations feed on dashboard | `GET /donations?limit=100&currency=USD` (§2.2) |
| Dedicated socket token fetch | `GET /socket/token` (§2.5) |
| User profile display | `GET /user` (§2.1) |
| OAuth token exchange and storage | `POST /token` (§1.3) |

**Scopes requested:** `donations.read socket.token`

### What this project does NOT use

Alerts (`POST /alerts`), points, media share, credits, jar, wheel, alert profiles.

### Database schema

**Table `streamlabs_tokens`** (central `website` DB):

| Column | Type | Description |
|--------|------|-------------|
| `twitch_user_id` | VARCHAR | Primary key; Twitch user ID of the linked account |
| `access_token` | VARCHAR | OAuth access token |
| `refresh_token` | VARCHAR | OAuth refresh token |
| `expires_in` | INT | Token TTL in seconds from the token response |
| `created_at` | INT | Unix epoch timestamp of token issuance |
| `socket_token` | VARCHAR NULL | Dedicated socket token from `GET /socket/token`; added at runtime via `information_schema` check if column is missing |

**Table `tipping`** (per-user DB):

StreamLabs donations are written with `source = 'StreamLabs'`. Columns populated: `username`, `amount`, `message`, `source`. Columns **not** populated (StreamLabs realtime does not provide them): `tip_id`, `currency`, `created_at`.

### File locations

| Concern | File |
|---------|------|
| Dashboard OAuth + REST calls | `./dashboard/streamlabs.php` |
| PHP config stub | `./config/streamlabs.php` (server: `/var/www/config/streamlabs.php`) |
| Token selection at bot startup | `./bot/bot.py:507–522`, `./bot/beta.py:909–921` |
| Socket connection | `./bot/bot.py:654–670`, `./bot/beta.py:1052–1083` |
| Tipping message handler | `./bot/bot.py:707–761`, `./bot/beta.py:1141–1193` |

### Socket connection details

The bot connects using a raw `websockets` WebSocket (not `python-socketio`) with `EIO=3` in the URL to speak Engine.IO v3:

```
wss://sockets.streamlabs.com/socket.io/?token={socket_token}&EIO=3&transport=websocket
```

`beta.py` handles Engine.IO pings explicitly (frame `"2"` → respond with `"3"`). `bot.py` (stable) does not; it relies on `try/except` to absorb non-JSON control frames silently.

Token selection priority (both versions):
1. `streamlabs_tokens.socket_token` if non-null
2. `streamlabs_tokens.access_token` as fallback (works because the gateway accepts an access token with `socket.token` scope)
3. Skip the connection if neither is present

### Known limitations in the current implementation

- **No reconnection loop.** If the WebSocket drops, `connect_to_streamlabs()` logs and returns. The connection stays dead until the bot process restarts. A retry wrapper like the one used for StreamElements (`streamelements_connection_manager()`) would fix this.
- **No token refresh.** `expires_in` is stored but never acted on. There is no `refresh_streamlabs_tokens.py`. If tokens start expiring, model a new script after `./bot/refresh_streamelements_tokens.py` using the refresh grant (§1.3), noting that StreamLabs requires `redirect_uri` on refresh.
- **`amount` type mismatch.** The realtime socket delivers `amount` as a string (`"5.00"`); the REST `GET /donations` endpoint may deliver it as a number. Coerce to `float` before arithmetic or storage in both paths.
- **Tipping row less rich than StreamElements.** The StreamLabs handler omits `tip_id`, `currency`, and `created_at` because the realtime payload does not supply them directly.
