# StreamElements API Reference

Local copy of the StreamElements API scoped to what this project uses and could use.
Source: official OpenAPI spec at `github.com/StreamElements/api-docs` (api.yaml, 34k lines) plus
the docs/ markdown files in the same repo, last verified 2026-05-11.

---

## Contents

1. [Authentication](#1-authentication)
2. [REST Endpoints — Channels & Users](#2-rest-endpoints--channels--users)
3. [REST Endpoints — Tips](#3-rest-endpoints--tips)
4. [REST Endpoints — Activities](#4-rest-endpoints--activities)
5. [REST Endpoints — Sessions](#5-rest-endpoints--sessions)
6. [REST Endpoints — Points & Loyalty](#6-rest-endpoints--points--loyalty)
7. [REST Endpoints — Bot Control](#7-rest-endpoints--bot-control)
8. [REST Endpoints — Store & Redemptions](#8-rest-endpoints--store--redemptions)
9. [REST Endpoints — Overlays](#9-rest-endpoints--overlays)
10. [WebSocket / Socket.IO — Realtime Events](#10-websocket--socketio--realtime-events)
11. [Rate Limits](#11-rate-limits)
12. [Error Codes](#12-error-codes)
13. [BotOfTheSpecter Callsites](#13-botofthespecter-callsites)

---

## 1. Authentication

### 1.1 Base URLs

| Version | Base URL |
| ------- | -------- |
| V2 (primary) | `https://api.streamelements.com/kappa/v2` |
| V3 (giveaways only) | `https://api.streamelements.com/kappa/v3` |

All REST endpoints below are relative to the V2 base unless noted otherwise.

### 1.2 Token types

StreamElements has three distinct credential types. Use the right one per endpoint.

#### JWT Token (Bearer)

A long-lived secret token tied to a channel. Obtain it from the StreamElements dashboard at
`https://streamelements.com/dashboard/account/channels` → "Show Secrets". Can also be obtained
programmatically from `GET /channels/me` (field `apiToken`) or `GET /users/current`
(field `channels[].lastJWTToken`).

**Header:**
```
Authorization: Bearer {jwt_token}
```

Use for: `/channels/me`, `/users/current`, `/tips/{channel}`, most channel management endpoints.

**Security warning:** Never expose this token in front-end code or logs.

#### OAuth2 Access Token (oAuth)

A short-lived token obtained via the OAuth2 Authorization Code flow. Note the non-standard
header casing — lowercase 'o', uppercase 'A'.

**Header:**
```
Authorization: oAuth {access_token}
```

Use for: `/oauth2/validate`, `/channels/me` (when user just completed OAuth flow),
WebSocket `authenticate` payload.

**Header gotcha:** The `oAuth` casing is intentional and historical. Sending `Bearer` for
OAuth-token endpoints returns 401.

#### API Key (Bearer, alternate)

A per-channel API key (different from the JWT; visible as `apiToken` in channel details).
Used by some widget/overlay SDK calls. Header format is identical to JWT Bearer — the token
value is what differs.

### 1.3 OAuth2 Authorization Code Flow

**Endpoints:**

| Step | Method | URL |
| ---- | ------ | --- |
| Authorize | GET redirect | `https://api.streamelements.com/oauth2/authorize` |
| Exchange code | POST | `https://api.streamelements.com/oauth2/token` |
| Refresh | POST | `https://api.streamelements.com/oauth2/token` |
| Validate | GET | `https://api.streamelements.com/oauth2/validate` |
| Revoke | POST | `https://api.streamelements.com/oauth2/revoke` |

**Step 1 — Build the authorization URL:**

```
GET https://api.streamelements.com/oauth2/authorize
  ?client_id={client_id}
  &redirect_uri={redirect_uri}
  &response_type=code
  &scope={space-separated-scopes}
  &state={csrf_token}
```

User approves → redirected to `redirect_uri?code={code}&state={state}`.

**Step 2 — Exchange the code:**

```
POST https://api.streamelements.com/oauth2/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
&client_id={client_id}
&client_secret={client_secret}
&code={code}
&redirect_uri={redirect_uri}
```

Response:
```json
{
  "access_token": "...",
  "token_type": "Bearer",
  "expires_in": 2592000,
  "refresh_token": "...",
  "scope": "tips:read channel:read"
}
```

**Step 3 — Refresh an expired token:**

```
POST https://api.streamelements.com/oauth2/token
Content-Type: application/x-www-form-urlencoded

grant_type=refresh_token
&client_id={client_id}
&client_secret={client_secret}
&refresh_token={refresh_token}
```

Response: same shape as Step 2. SE may or may not rotate the `refresh_token`; fall back to the
existing one if the new response omits it.

### 1.4 OAuth2 Scopes

| Scope | Grants |
| ----- | ------ |
| `channel:read` | Read channel metadata |
| `tips:read` | Read tip history |
| `tips:write` | Create/modify/delete tips |
| `activities:read` | Read activity feed |
| `activities:write` | Create activities |
| `loyalty:read` | Read loyalty settings and leaderboard |
| `loyalty:write` | Update loyalty settings and leaderboard |
| `overlays:read` | Read overlays |
| `overlays:write` | Create/update/delete overlays |
| `store:read` | Read redemptions and store items |
| `store:write` | Create store items and complete redemptions |
| `bot:read` | Read timers, commands, spam filters, modules |
| `bot:write` | Create and update timers, commands, spam filters, modules |
| `session:read` | Read session data |
| `contest:read` | Read contests |
| `contest:write` | Create and update contests |
| `giveaway:read` | Read giveaways |
| `giveaway:write` | Create and update giveaways |

This project uses: `channel:read tips:read` (see `./dashboard/streamelements.php:137`).

### 1.5 Validate Token

```
GET /oauth2/validate
Authorization: oAuth {access_token}
```

Response:
```json
{
  "channel_id": "5b2e2007760aeb7729487dab",
  "client_id": "...",
  "expires_in": 2592000,
  "scopes": ["tips:read", "channel:read"]
}
```

`expires_in` is in **seconds**, not milliseconds, and is often weeks long.

### 1.6 Revoke Token

```
POST https://api.streamelements.com/oauth2/revoke

client_id={client_id}&token={access_token}
```

No response body on success (HTTP 200).

---

## 2. REST Endpoints — Channels & Users

### GET /channels/me

Get the full channel record for the authenticated user. Returns the `apiToken` (JWT) field.

**Auth:** `Authorization: Bearer {jwt_token}` or `Authorization: oAuth {access_token}`

**Response 200:**
```json
{
  "profile": {
    "headerImage": "https://cdn.streamelements.com/static/user/profile_header_default.png",
    "title": "leeeeex's profile"
  },
  "provider": "twitch",
  "suspended": false,
  "nullChannel": false,
  "providerEmails": [],
  "lastJWTToken": null,
  "_id": "5b2e2007760aeb7729487dab",
  "email": "email@streamelements.com",
  "avatar": "https://...",
  "verified": false,
  "username": "leeeeex",
  "alias": "leeeeex",
  "displayName": "leeeeex",
  "providerId": "85827806",
  "accessToken": "ixfd2ussul9naws1agx16n8ufwny5a",
  "apiToken": "7JkHvfuVsd5I1f1A0TRF",
  "isPartner": false,
  "broadcasterType": "",
  "users": [{"user": "5b2e...", "providerId": "85827806", "role": "owner"}],
  "ab": [],
  "createdAt": "2018-06-23T10:25:11.733Z",
  "updatedAt": "2018-12-07T09:29:01.582Z",
  "lastLogin": "2018-12-07T09:29:01.58Z",
  "country": "US",
  "providerTotals": {"follower-total": 6},
  "features": [],
  "geo": "US"
}
```

Key fields: `_id` (channel GUID used in all other endpoints), `apiToken` (JWT), `isPartner`,
`suspended`, `inactive`.

### GET /channels/{channel}

Get public channel details by channel GUID or lowercase channel name.

**Auth:** None required for public data; JWT/oAuth for full details.

**Path params:**
- `channel` — channel GUID or lowercase username

**Response 200:**
```json
{
  "profile": {"headerImage": "...", "title": "..."},
  "provider": "twitch",
  "_id": "5b2e2007760aeb7729487dab",
  "avatar": "https://...",
  "username": "leeeeex",
  "alias": "leeeeex",
  "displayName": "leeeeex",
  "providerId": "85827806",
  "isPartner": false,
  "broadcasterType": "",
  "inactive": false
}
```

### GET /channels/{channel}/details

Full channel detail including `accessToken`, `apiToken`, `createdAt`, `country`, moderator list.
Requires channel GUID (not name). Used when you have a GUID and need the full record.

**Auth:** `Authorization: Bearer {jwt_token}`

**Response 200:** same as `/channels/me` response shape.

### GET /users/current

Returns the authenticated user's account with all linked channels. The `channels[].lastJWTToken`
field contains a fresh JWT for each linked channel.

**Auth:** `Authorization: Bearer {jwt_token}` or `oAuth {access_token}`

**Response 200:**
```json
{
  "suspended": false,
  "teams": [],
  "channels": [
    {
      "profile": {...},
      "provider": "twitch",
      "suspended": false,
      "nullChannel": false,
      "providerEmails": [],
      "lastJWTToken": null,
      "_id": "5b2e2007760aeb7729487dab",
      "email": "email@streamelements.com",
      "avatar": "https://...",
      "verified": false,
      "username": "leeeeex",
      "alias": "leeeeex",
      "displayName": "leeeeex",
      "providerId": "85827806",
      "isPartner": false,
      "broadcasterType": "",
      "ab": [],
      "createdAt": "2018-06-23T10:25:11.733Z",
      "updatedAt": "2018-12-07T17:26:23.792Z",
      "lastLogin": "2018-12-07T17:26:23.79Z",
      "country": "US",
      "role": "owner",
      "moderators": [...]
    }
  ],
  "_id": "5b2e2007760aeb6677487daa",
  "lastLogin": "2018-06-23T10:25:11.728Z",
  "ab": [],
  "primaryChannel": "5b2e2007760aeb7729487dab",
  "username": "leeeeex",
  "avatar": "https://...",
  "createdAt": "2018-06-23T10:25:11.732Z",
  "updatedAt": "2018-12-07T08:43:29.014Z"
}
```

Key fields: `channels[0]._id` (channel GUID), `channels[0].lastJWTToken` (fresh JWT),
`primaryChannel`.

---

## 3. REST Endpoints — Tips

All tips endpoints require channel GUID (not username) and JWT Bearer auth unless noted.

### GET /tips/{channel}

List tips with filtering and sorting.

**Auth:** `Authorization: Bearer {jwt_token}` (scope: `tips:read`)

**Path params:**
- `channel` — channel GUID

**Query params:**

| Param | Type | Description |
| ----- | ---- | ----------- |
| `offset` | integer | Starting element (for pagination). Default 0. |
| `limit` | integer | Items per page, 1–100. |
| `sort` | string | Sort field. Values: `createdAt`, `-createdAt`, `donation.amount`, `-donation.amount`, `donation.provider`, `-donation.provider`. Prefix `-` = descending. |
| `tz` | integer | Timezone index number. |
| `username` | string | Filter by username. |
| `email` | string | Filter by donor email. |
| `after` | datetime | Start date (unix ms or ISO 8601). |
| `before` | datetime | End date (unix ms or ISO 8601). |
| `message` | string | Filter by message text. |

**Response 200 — `TipData` (paginated list):**
```json
{
  "docs": [
    {
      "donation": {
        "user": {
          "username": "StreamElements",
          "geo": null,
          "email": "streamelements@streamelements.com"
        },
        "message": "This is a test",
        "amount": 100,
        "currency": "USD"
      },
      "provider": "paypal",
      "status": "success",
      "deleted": false,
      "_id": "5c0aab85de9a4c6756a14e0d",
      "channel": "5b2e2007760aeb7729487dab",
      "transactionId": "IMPORTED",
      "createdAt": "2018-12-07T17:19:01.957Z",
      "approved": "allowed",
      "updatedAt": "2018-12-07T17:19:01.957Z"
    }
  ],
  "total": 1,
  "limit": 25,
  "offset": 0
}
```

**Field notes:**
- `docs[].donation.amount` — dollars (e.g. `100`), NOT cents. Do not divide by 100.
- `docs[].donation.currency` — ISO 4217 code. Valid values: `AUD BRL CAD CZK DKK EUR HKD HUF ILS JPY MYR MXN NOK NZD PHP PLN GBP RUB SGD SEK CHF TWD THB TRY USD`
- `docs[].provider` — payment provider, e.g. `paypal`, `twitch_subscription`
- `docs[].approved` — moderation status: `allowed`, `pending`, `denied`
- `docs[].status` — transaction status: `success`, `refunded`, `chargeback`

**Note:** The dashboard uses a simplified form of this endpoint: `GET /kappa/v2/tips/{channelId}?limit=100`
which is `offset=0&limit=100&sort=-createdAt` with defaults for the rest.

**Response 400:**
```json
{
  "statusCode": 400,
  "error": "Bad Request",
  "message": "child \"sort\" fails because [\"sort\" must be one of [createdAt, -createdAt, ...]]",
  "details": [
    {"path": ["sort"], "message": "\"sort\" must be one of [...]"}
  ]
}
```

### POST /tips/{channel}

Create (import) a tip manually.

**Auth:** `Authorization: Bearer {jwt_token}` (scope: `tips:write`)

**Request body (`NewTip`):**
```json
{
  "user": {
    "userId": "",
    "username": "donorname",
    "email": "donor@example.com"
  },
  "provider": "paypal",
  "message": "Test message",
  "amount": 5.00,
  "currency": "USD",
  "imported": true
}
```

**Response 200:** Single tip object (same shape as `docs[]` entry above).

### GET /tips/{channel}/top

Get top tippers for the channel (all-time).

**Auth:** `Authorization: Bearer {jwt_token}`

**Response 200:**
```json
[
  {
    "username": "StreamElements",
    "first": "2018-12-07T17:19:01.957Z",
    "last": "2018-12-07T17:19:01.957Z",
    "count": 1,
    "total": 100,
    "email": "streamelements@streamelements.com",
    "average": 100
  }
]
```

### GET /tips/{channel}/leaderboard

Get the tip leaderboard. Response schema not documented in the spec (200 with empty headers).

**Auth:** `Authorization: Bearer {jwt_token}`

### GET /tips/{channel}/moderation

List tips pending moderation review plus recently processed tips.

**Auth:** `Authorization: Bearer {jwt_token}`

**Response 200:**
```json
{
  "recent": [ /* tip objects */ ],
  "pending": []
}
```

### GET /tips/{channel}/{tipId}

Get a single tip by ID.

**Auth:** `Authorization: Bearer {jwt_token}`

**Path params:**
- `channel` — channel GUID
- `tipId` — tip GUID (24-char hex)

**Response 200:** Single tip object (same shape as `docs[]` above).

**Response 400:** GUID validation failure.

**Response 404:**
```json
{"statusCode": 404, "error": "Not Found"}
```

---

## 4. REST Endpoints — Activities

Activities are a unified feed of follows, tips, hosts, cheers, redemptions, raids, superchats,
sponsors, merch orders. All require JWT Bearer auth.

### GET /activities/{channel}

List channel activities with filtering.

**Auth:** JWT Bearer (scope: `activities:read`) or OAuth2 with `activities:read`

**Path params:**
- `channel` — channel GUID

**Query params:**

| Param | Type | Description |
| ----- | ---- | ----------- |
| `after` | datetime | Start date (unix ms). |
| `before` | datetime | End date (unix ms). Example: `1544178274000`. |
| `limit` | integer | Events per page, 1–100. |
| `mincheer` | integer | Minimum bits in cheer. |
| `minhost` | integer | Minimum viewers in host. |
| `minsub` | integer | Minimum subscription months. |
| `mintip` | integer | Minimum tip amount. |
| `origin` | string | Activity origin filter. |
| `types` | string | Comma/array of types: `follow`, `tip`, `host`, `cheer`, `redemption`, `raid`, `superchat`, `sponsor`, `merch`, `subscriber`. |

**Response 200:**
```json
[
  {
    "data": {
      "username": "Toy Bosco",
      "amount": 33,
      "message": "",
      "tier": "3000",
      "avatar": "https://cdn.streamelements.com/static/default-avatar.png"
    },
    "provider": "twitch",
    "_id": "5c09463bde9a4cebcca14be8",
    "type": "merch",
    "channel": "5b2e2007760aeb7729487dab",
    "createdAt": "2018-12-06T01:28:27.648Z"
  }
]
```

Activity `type` values: `follow`, `tip`, `host`, `cheer`, `redemption`, `raid`, `superchat`,
`sponsor`, `merch`, `subscriber`.

`data.tier` values for subscribers: `"1000"` (Tier 1), `"2000"` (Tier 2), `"3000"` (Tier 3),
`"prime"` (Twitch Prime).

### GET /activities/{channel}/top

Get top activities by type and period.

**Path params:**
- `channel` — channel GUID

**Query params:**

| Param | Type | Description |
| ----- | ---- | ----------- |
| `offset` | integer | Starting element. |
| `limit` | integer | Number of events. |
| `type` | string | `cheer` or `tip`. |
| `period` | string | `alltime`, `months`, `weeks`, `session`. |

**Response 200:**
```json
[
  {"total": 500, "username": "TopDonator"}
]
```

### GET /activities/{channel}/{activityId}

Get a single activity by ID.

**Response 200:** Single activity object (same shape as list item above).

### POST /activities/{channel}/{activityId}

Update an activity.

### POST /activities/{channel}/{activityId}/replay

Trigger an activity replay (re-fires the overlay alert for that activity).

**Response 200:**
```json
{"success": true}
```

**Response 404:** Activity not found.

---

## 5. REST Endpoints — Sessions

Session data tracks running totals for the current broadcast. Resets on stream start (if
`autoReset: true`) or manually.

### GET /sessions/{channel}

Get all current session data.

**Auth:** `Authorization: Bearer {jwt_token}` (scope: `session:read`)

**Path params:**
- `channel` — channel GUID

**Response 200 — `SessionDataInfo`:**

The `data` object contains named keys for every session metric. All key names follow the pattern
`{event-type}-{metric}`. Full list of keys (with example values):

```json
{
  "data": {
    "follower-latest":              {"name": "Emile Morissette"},
    "follower-session":             {"count": 11},
    "follower-week":                {"count": 11},
    "follower-month":               {"count": 11},
    "follower-goal":                {"amount": 11},
    "follower-total":               {"count": 13},
    "follower-recent":              [{"name": "...", "createdAt": "..."}],
    "subscriber-latest":            {"name": "...", "amount": 17, "tier": "2000", "message": "", "sender": null, "gifted": null},
    "subscriber-new-latest":        {"name": "", "amount": 0, "message": ""},
    "subscriber-resub-latest":      {"name": "...", "amount": 17, "message": ""},
    "subscriber-gifted-latest":     {"name": "", "amount": 0, "message": ""},
    "subscriber-session":           {"count": 0},
    "subscriber-new-session":       {"count": 0},
    "subscriber-resub-session":     {"count": 0},
    "subscriber-gifted-session":    {"count": 0},
    "subscriber-week":              {"count": 0},
    "subscriber-month":             {"count": 0},
    "subscriber-goal":              {"amount": 68},
    "subscriber-total":             {"count": 0},
    "subscriber-points":            {"amount": 0},
    "subscriber-alltime-gifter":    {"name": "", "amount": 0},
    "subscriber-recent":            [{"name": "...", "tier": "2000", "amount": 17, "createdAt": "..."}],
    "host-latest":                  {"name": "Cathryn Wehner", "amount": 2},
    "host-recent":                  [{"name": "...", "amount": 2, "createdAt": "..."}],
    "raid-latest":                  {"name": "", "amount": 0},
    "raid-recent":                  [],
    "cheer-session":                {"amount": 0},
    "cheer-week":                   {"amount": 0},
    "cheer-month":                  {"amount": 0},
    "cheer-total":                  {"amount": 2759},
    "cheer-count":                  {"count": 0},
    "cheer-goal":                   {"amount": 246},
    "cheer-latest":                 {"name": "Halle Koelpin", "amount": 24, "message": ""},
    "cheer-session-top-donation":   {"name": "", "amount": 0},
    "cheer-weekly-top-donation":    {"name": "", "amount": 0},
    "cheer-monthly-top-donation":   {"name": "", "amount": 0},
    "cheer-alltime-top-donation":   {"name": "", "amount": 0},
    "cheer-session-top-donator":    {"name": "", "amount": 0},
    "cheer-weekly-top-donator":     {"name": "", "amount": 0},
    "cheer-monthly-top-donator":    {"name": "", "amount": 0},
    "cheer-alltime-top-donator":    {"name": "", "amount": 0},
    "cheer-recent":                 [{"name": "...", "amount": 39, "createdAt": "..."}],
    "tip-latest":                   {"name": "...", "amount": 41, "message": ""},
    "tip-session":                  {"amount": 0},
    "tip-week":                     {"amount": 0},
    "tip-month":                    {"amount": 0},
    "tip-total":                    {"amount": 3415},
    "tip-count":                    {"count": 0},
    "tip-goal":                     {"amount": 279},
    "tip-session-top-donation":     {"name": "", "amount": 0},
    "tip-weekly-top-donation":      {"name": "", "amount": 0},
    "tip-monthly-top-donation":     {"name": "", "amount": 0},
    "tip-alltime-top-donation":     {"name": "", "amount": 0},
    "tip-session-top-donator":      {"name": "", "amount": 0},
    "tip-weekly-top-donator":       {"name": "", "amount": 0},
    "tip-monthly-top-donator":      {"name": "", "amount": 0},
    "tip-alltime-top-donator":      {"name": "", "amount": 0},
    "tip-recent":                   [{"name": "...", "amount": 22, "createdAt": "..."}],
    "merch-goal-orders":            {"amount": 0},
    "merch-goal-items":             {"amount": 0},
    "merch-goal-total":             {"amount": 0},
    "merch-recent":                 []
  },
  "settings": {
    "autoReset": true,
    "calendar": false
  },
  "_id": "5c0aa930de9a4cf194a14e05",
  "provider": "twitch",
  "lastReset": "2018-12-07T17:09:04.037Z",
  "channel": "5b2e2007760aeb7729487dab",
  "createdAt": "...",
  "updatedAt": "..."
}
```

### GET /sessions/{channel}/settings

Get session settings only.

**Response 200:**
```json
{"autoReset": true, "calendar": false}
```

### PUT /sessions/{channel}/settings

Update session settings. Request body is a JSON object with `autoReset` and/or `calendar` fields.

### PUT /sessions/{channel}/reset

Reset all session counters (tip-session, follower-session, etc.) to zero. All-time totals are
preserved. Response is the full session data object after reset.

### GET /sessions/{channel}/top

Get top events from session aggregates.

**Query params:**

| Param | Type | Description |
| ----- | ---- | ----------- |
| `limit` | integer | Max events returned. |
| `offset` | integer | Starting element. |
| `interval` | string | `alltime`, `monthly`, `weekly`, `session`. |

---

## 6. REST Endpoints — Points & Loyalty

### GET /points/{channel}/{user}

Get a user's current points balance.

**Auth:** `Authorization: Bearer {jwt_token}` (scope: `loyalty:read`)

**Path params:**
- `channel` — channel GUID
- `user` — username string

Response schema not fully detailed in spec (200 with empty headers). Returns a user points object.

### GET /points/{channel}/{user}/rank

Get a user's current rank on the leaderboard.

### PUT /points/{channel}/{user}/{amount}

Add or remove points from a user. Pass negative `amount` to deduct.

**Auth:** scope `loyalty:write`

### DELETE /points/{channel}/{user}

Reset a user's points (removes their row).

### PUT /points/{channel}/alltime/{user}/{amount}

Update a user's all-time points.

### DELETE /points/{channel}/alltime/{user}

Delete a user's all-time points record.

### GET /points/{channel}/alltime

List all-time user points (paginated).

**Query params:** `limit` (integer), `offset` (integer)

### GET /points/{channel}/top

List top users by current points.

**Query params:** `limit` (integer), `offset` (integer)

### GET /points/{channel}/watchtime

List users by watch time.

**Query params:** `limit` (integer), `offset` (integer)

### PUT /points/{channel}

Bulk add or set points for multiple users.

**Request body:**
```json
{
  "users": [
    {"username": "testuser123", "current": 200},
    {"username": "testuser456", "current": 150}
  ],
  "mode": "add"
}
```

`mode`: `"add"` to add to existing points, `"set"` to overwrite.

### DELETE /points/{channel}/reset/{context}

Reset points for a context (all users, or a subset).

### GET /loyalty/{channel}

Get loyalty program settings.

**Auth:** `Authorization: Bearer {jwt_token}`

**Response 200:**
```json
{
  "loyalty": {
    "bonuses": {
      "follow": 0,
      "tip": 0,
      "subscriber": 0,
      "cheer": 0,
      "host": 0
    },
    "name": "points",
    "enabled": false,
    "amount": 5,
    "subscriberMultiplier": 3,
    "ignored": ["streamelements"]
  },
  "_id": "5b2e2007760aeb7230487dc1",
  "channel": "5b2e2007760aeb7729487dab",
  "createdAt": "2018-06-23T10:25:11.768Z",
  "updatedAt": "2018-06-23T10:25:11.768Z"
}
```

Key fields: `loyalty.name` (points currency name shown in chat), `loyalty.enabled`,
`loyalty.amount` (points earned per interval), `loyalty.subscriberMultiplier`.

### PUT /loyalty/{channel}

Update loyalty settings. Same response shape as GET.

---

## 7. REST Endpoints — Bot Control

### GET /bot/commands/{channel}

List all custom bot commands.

**Auth:** `Authorization: Bearer {jwt_token}` (scope: `bot:read`)

**Response 200 — array of `BotCommand`:**
```json
[
  {
    "cooldown": {"user": 0, "global": 0},
    "aliases": [],
    "keywords": [],
    "enabled": true,
    "enabledOnline": true,
    "enabledOffline": false,
    "hidden": false,
    "cost": 0,
    "type": "say",
    "accessLevel": 100,
    "regex": "",
    "reply": "Hello world",
    "command": "!hello",
    "channel": "5b2e2007760aeb7729487dab",
    "_id": "...",
    "createdAt": "...",
    "updatedAt": "..."
  }
]
```

`type` values: `say`, `reply`, `whisper`.

`accessLevel` values: `100` (everyone), `250` (subscriber), `500` (moderator), `1000` (channel editor/broadcaster).

### POST /bot/commands/{channel}

Create a new custom command.

**Auth:** scope `bot:write`

**Request body:** same shape as `BotCommand` (omit `_id`, `createdAt`, `updatedAt`).

**Response 201:** Created `BotCommand` object.

**Response 400:** Validation error.

**Response 409:** Command name already exists.

### GET /bot/commands/{channel}/public

List only commands with `hidden: false`.

### GET /bot/commands/{channel}/default

List built-in default commands for a given language.

**Query params:** `language` — ISO 639-1 language code (e.g. `en`)

**Response 200 — array of `DefaultCommand`:**
```json
[
  {
    "commandId": "points",
    "command": "!points",
    "subCommands": [],
    "accessLevel": 100,
    "enabled": true,
    "enabledOnline": true,
    "enabledOffline": true,
    "moduleId": "loyalty",
    "cost": 0,
    "cooldown": {"user": 0, "global": 0},
    "aliases": [],
    "regex": "",
    "description": "Check your points balance"
  }
]
```

### GET /bot/commands/{channel}/default/{commandId}

Get a single default command by its `commandId`.

### GET/PUT/DELETE /bot/commands/{channel}/{commandId}

Get, update, or delete a specific custom command by its GUID.

### GET /bot/timers/{channel}

List bot chat timers (scheduled messages).

**Auth:** `Authorization: Bearer {jwt_token}` (scope: `bot:read`)

### POST /bot/timers/{channel}

Create a new timer.

### GET/PUT/DELETE /bot/timers/{channel}/{timerId}

Get, update, or delete a specific timer.

### GET /bot/modules/{channel}

List bot module states (which built-in modules are enabled/disabled).

**Auth:** `Authorization: Bearer {jwt_token}` (scope: `bot:read`)

### GET /bot/filters/{channel}

Get spam filter settings.

### GET /bot/filters/{channel}/banphrases

List ban phrases.

### POST /bot/filters/{channel}/test

Test if a message would be caught by the spam filter.

### GET /bot/{channel}

Get bot info for the channel (join status, language, etc.).

### POST /bot/{channel}/join

Make the SE bot join the channel.

### POST /bot/{channel}/part

Make the SE bot leave the channel.

### POST /bot/{channel}/mute

Mute the SE bot (stops it from sending messages).

### POST /bot/{channel}/unmute

Unmute the SE bot.

### POST /bot/{channel}/say

Send a message to the channel as the SE bot.

**Auth:** `Authorization: Bearer {jwt_token}` (scope: `bot:write`)

**Request body:**
```json
{"message": "Hello chat!"}
```

**Response 200:**
```json
{
  "status": 200,
  "channel": "5b2e2007760aeb7729487dab",
  "message": "Hello chat!"
}
```

### POST /bot/{channel}/language

Set the bot's response language.

**Request body:**
```json
{"language": "en"}
```

Language must be a 2-character ISO 639-1 code.

### GET /bot/{channel}/levels

Get user access level configuration.

### GET/POST /bot/{channel}/levels/{username}

Get or set a specific user's access level.

### GET /bot/{channel}/counters/{counter}

Get the current value of a named counter.

---

## 8. REST Endpoints — Store & Redemptions

### GET /store/{channel}/redemptions

List channel point redemptions.

**Auth:** `Authorization: Bearer {jwt_token}` (scope: `store:read`)

**Query params:**

| Param | Type | Description |
| ----- | ---- | ----------- |
| `offset` | integer | Items to skip. |
| `limit` | integer | Items per page. |
| `pending` | boolean | Filter pending-only if `true`. |

### GET /store/{channel}/redemptions/search

Search redemptions.

**Query params:** `offset`, `limit`, `search`, `searchBy`, `from`, `to`, `pending`, `sort`

### GET /store/{channel}/redemptions/me

Get the authenticated user's own redemptions.

**Query params:** `offset`, `limit`

### GET /store/{channel}/redemptions/{redemptionId}

Get a single redemption.

### PUT /store/{channel}/redemptions/{redemptionId}

Update a redemption (e.g. mark as completed).

**Auth:** scope `store:write`

### DELETE /store/{channel}/redemptions/{redemptionId}

Delete a redemption.

### GET /store/{channel}/items

List store items (redeemable rewards).

**Auth:** `Authorization: Bearer {jwt_token}` (scope: `store:read`)

### POST /store/{channel}/items

Create a store item.

**Auth:** scope `store:write`

### GET/PUT/DELETE /store/{channel}/items/{itemId}

Get, update, or delete a specific store item.

---

## 9. REST Endpoints — Overlays

### GET /overlays/{channel}

List overlays for the channel.

**Auth:** `Authorization: Bearer {jwt_token}` (scope: `overlays:read`)

### GET/PUT/DELETE /overlays/{channel}/{overlayId}

Get, update, or delete a specific overlay.

### POST /overlays/{channel}/reload

Reload all overlays for the channel (re-fetches settings in browser sources).

### POST /overlays/{channel}/action/{action}

Send an action to all overlays.

---

## 10. WebSocket / Socket.IO — Realtime Events

### 10.1 Connection

**URL:** `https://realtime.streamelements.com`
**Transport:** `websocket` only (do not use polling).
**Library:** Socket.IO (any version that supports `transports` option).

Python example (used by bot.py):
```python
import socketio
socket = socketio.AsyncClient()
await socket.connect("https://realtime.streamelements.com", transports=["websocket"])
```

### 10.2 Authentication

Immediately after the `connect` event fires, emit the `authenticate` event with one of:

**OAuth2 access token:**
```json
{"method": "oauth2", "token": "{access_token}"}
```

**JWT token:**
```json
{"method": "jwt", "token": "{jwt_token}"}
```

OAuth2 is preferred for bot integrations (token is stored in DB and refreshable).
JWT is preferred for server-side integrations where a static token is acceptable.

### 10.3 Connection lifecycle events

| Event | Direction | When fired |
| ----- | --------- | ---------- |
| `connect` | server → client | Socket connected; emit `authenticate` here |
| `disconnect` | server → client | Socket dropped; re-connect with backoff |
| `authenticated` | server → client | Auth accepted; `data.channelId` available |
| `unauthorized` | server → client | Auth rejected; disconnect and check token |
| `event` | server → client | A live activity event fired |
| `event:test` | server → client | A test event triggered from SE dashboard |
| `event:update` | server → client | Session totals updated |
| `event:reset` | server → client | Session totals reset |

**`authenticated` payload:**
```json
{"channelId": "5b2e2007760aeb7729487dab"}
```

**`unauthorized` payload:**
```json
{"message": "Token is not valid"}
```

### 10.4 Event payload structure

All `event` and `event:test` payloads share a common outer envelope:

```json
{
  "type": "tip",
  "_id": "5c09463bde9a4c...",
  "channel": "5b2e2007760aeb7729487dab",
  "provider": "twitch",
  "createdAt": "2018-12-07T17:19:01.957Z",
  "data": {
    /* event-type specific fields, see below */
  }
}
```

`type` values: `tip`, `follow`, `subscriber`, `host`, `raid`, `cheer`, `redemption`,
`superchat`, `sponsor`, `merch`.

`provider` values: `twitch`, `youtube`, `facebook`.

### 10.5 Per-type `data` payloads

**tip:**
```json
{
  "tipId": "5c0aab85de9a4c...",
  "displayName": "Username",
  "username": "username",
  "amount": 5.00,
  "currency": "USD",
  "message": "Keep up the great work",
  "avatar": "https://...",
  "createdAt": "2026-01-15T12:34:56.000Z"
}
```

`amount` is in dollars (NOT cents).

**follow:**
```json
{
  "displayName": "FollowerName",
  "username": "followername",
  "avatar": "https://..."
}
```

**subscriber:**
```json
{
  "displayName": "SubName",
  "username": "subname",
  "amount": 3,
  "tier": "1000",
  "message": "Loving the stream",
  "gifted": false,
  "sender": null,
  "avatar": "https://..."
}
```

`tier` values: `"1000"` (Tier 1), `"2000"` (Tier 2), `"3000"` (Tier 3), `"prime"`.
`gifted: true` means this was a gift sub; `sender` is the gifter's username.

**cheer:**
```json
{
  "displayName": "CheerName",
  "username": "cheername",
  "amount": 100,
  "message": "Cheer100 PogChamp",
  "avatar": "https://..."
}
```

**host:**
```json
{
  "displayName": "HostChannel",
  "username": "hostchannel",
  "amount": 42,
  "avatar": "https://..."
}
```

`amount` = viewer count.

**raid:**
```json
{
  "displayName": "RaidChannel",
  "username": "raidchannel",
  "amount": 150,
  "avatar": "https://..."
}
```

`amount` = raiding viewer count.

**redemption:**
```json
{
  "displayName": "RedeemUser",
  "username": "redeemuser",
  "message": "redemption message",
  "itemId": "5b2e...",
  "avatar": "https://..."
}
```

### 10.6 `event:update` and `event:reset`

These fire when session totals change. The payload matches the `GET /sessions/{channel}` `data`
object format. Both are informational — most integrations safely ignore them in favour of
subscribing to individual `event` payloads.

### 10.7 Reconnection strategy

The SE realtime socket will drop connections. The bot implements:

- Up to 5 retries per cycle.
- Exponential backoff: `delay = min(60, 2 ** attempt)` → 1, 2, 4, 8, 16 seconds.
- After all 5 attempts fail: sleep 300 seconds, then begin a new cycle.
- The token is read once from the DB at startup. If an `unauthorized` event fires mid-session,
  the process must restart (or wait for the next cycle after `refresh_streamelements_tokens.py`
  has refreshed the DB token).

---

## 11. Rate Limits

StreamElements does not publish hard rate-limit numbers. Known behaviour:

- The realtime socket will emit `rate_limit_exceeded` if you fire too many events or reconnect
  too aggressively.
- Kappa v2 REST does not return explicit `Retry-After` headers. If you receive 429, implement
  exponential backoff.
- There is no documented per-minute or per-hour request cap for REST endpoints.

**Socket error events:**

| Error string | Meaning |
| ------------ | ------- |
| `err_internal_error` | Server-side error |
| `err_bad_request` | Malformed authenticate payload |
| `err_unauthorized` | Token invalid or expired |
| `rate_limit_exceeded` | Too many connections or events |
| `invalid_message` | Unrecognised socket message format |

These arrive as data payloads on the `event` channel. Check `data.error` in the event handler.

---

## 12. Error Codes

### HTTP error response shapes

**400 Bad Request:**
```json
{
  "statusCode": 400,
  "error": "Bad Request",
  "message": "Human-readable description of all validation failures",
  "details": [
    {"path": ["fieldName"], "message": "\"fieldName\" specific error"}
  ]
}
```

**404 Not Found:**
```json
{
  "statusCode": 404,
  "error": "Not Found"
}
```

**409 Conflict (POST creating a resource that already exists):**
```json
{
  "statusCode": 409,
  "error": "Conflict",
  "message": "Command already exists"
}
```

**401 Unauthorized:** Returned when the auth header is missing, the wrong type
(`oAuth` vs `Bearer`), or the token is expired/invalid. Response body varies.

### Common validation errors from the spec

- `"sort" must be one of [createdAt, -createdAt, donation.amount, -donation.amount, donation.provider, -donation.provider]`
- `"after" must be a number of milliseconds or valid date string`
- `"email" must be a valid email`
- `"currency" must be one of [AUD, BRL, CAD, CZK, DKK, EUR, HKD, HUF, ILS, JPY, MYR, MXN, NOK, NZD, PHP, PLN, GBP, RUB, SGD, SEK, CHF, TWD, THB, TRY, USD]`
- `"tipId" with value "x" fails to match the required pattern: /^[0-9a-fA-F]{24}$/`
- `"language" length must be 2 characters long`
- `"limit" must be a number`
- GUID fields require: `/^[0-9a-fA-F]{24}$/` (24 hex characters)

---

## 13. BotOfTheSpecter Callsites

These are the StreamElements API calls actually made by the project, with file and approximate
line references.

### REST API calls

| Endpoint | Auth type | File | Lines | Purpose |
| -------- | --------- | ---- | ----- | ------- |
| `POST /oauth2/token` (code exchange) | form body | `./dashboard/streamelements.php` | 157 | Initial OAuth code → token exchange |
| `POST /oauth2/token` (refresh) | form body | `./bot/refresh_streamelements_tokens.py` | 47–72 | Rotate access+refresh tokens for all users |
| `GET /oauth2/validate` | `oAuth {token}` | `./dashboard/streamelements.php` | 39, 183 | Validate token after link + on page load |
| `GET /kappa/v2/channels/me` | `oAuth {token}` | `./dashboard/streamelements.php` | 63–72 | Fetch channel profile (partner status, inactive, suspended) |
| `GET /kappa/v2/users/current` | `Bearer {jwt}` | `./dashboard/streamelements.php` | 77–119, 194–232, 305–326 | Get channel GUID and fresh `lastJWTToken` |
| `GET /kappa/v2/tips/{channelId}?limit=100` | `Bearer {jwt}` | `./dashboard/streamelements.php` | 330–345 | Fetch recent tips for dashboard display |

### OAuth flow

| Step | File | Lines |
| ---- | ---- | ----- |
| Build authorize URL | `./dashboard/streamelements.php` | 288–295 |
| Store CSRF state | `./dashboard/streamelements.php` | 289 |
| Validate state on callback | `./dashboard/streamelements.php` | 151 |
| Redirect URI | `./dashboard/streamelements.php` | 136 |
| Scopes requested | `./dashboard/streamelements.php` | 137 (`channel:read tips:read`) |

### WebSocket (realtime)

| Concern | File | Lines |
| ------- | ---- | ----- |
| Singleton `AsyncClient` | `./bot/bot.py` | 190 |
| Token read from DB | `./bot/bot.py` | 494–505 |
| Connection manager (retry loop) | `./bot/bot.py` | 539–562 |
| Connect + register handlers | `./bot/bot.py` | 565–645 |
| `authenticate` emit | `./bot/bot.py` | 577 |
| `event` handler (tip filter) | `./bot/bot.py` | 592–608 |
| `event:test` handler (tip filter) | `./bot/bot.py` | 609–623 |
| `event:update` handler (ignored) | `./bot/bot.py` | 624–632 |
| `event:reset` handler (ignored) | `./bot/bot.py` | 634–642 |
| Socket error handler | `./bot/bot.py` | 694–705 |
| Tip data extraction + DB write | `./bot/bot.py` | 707–761 |

### Token storage

| Table | DB scope | Columns | Notes |
| ----- | -------- | ------- | ----- |
| `streamelements_tokens` | central `website` DB | `twitch_user_id` (PK), `access_token`, `refresh_token`, `jwt_token` | JWT is refreshed only when user visits the dashboard. Refresh script only rotates `access_token`/`refresh_token`. |
| `tipping` | per-user DB | `tip_id`, `username`, `amount`, `currency`, `message`, `created_at`, `source` | `source='StreamElements'` distinguishes SE tips from StreamLabs. |

### Config

| Item | File |
| ---- | ---- |
| PHP credentials | `./config/streamelements.php` (dev) / `/var/www/config/streamelements.php` (server) |
| PHP variables | `$streamelements_client_id`, `$streamelements_client_secret` |
| Python env vars | `STREAMELEMENTS_CLIENT_ID`, `STREAMELEMENTS_SECRET_KEY` |
| Token refresh script | `./bot/refresh_streamelements_tokens.py` |
| Refresh log | (server) `./bot/logs/refresh_streamelements_tokens.log` (50 KB × 5 backups) |
