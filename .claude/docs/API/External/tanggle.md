# Tanggle Reference (BotOfTheSpecter)

Tanggle (`tanggle.io`) is a collaborative jigsaw puzzle platform. The bot subscribes to a community's WebSocket feed and listens for `room.complete` events, persists the result to the per-user DB, and exposes total completions via `!puzzles`. The bot does **not** currently create rooms — room creation is handled by the streamer or a moderator directly on the Tanggle site.

- **WebSocket URL:** `wss://api.tanggle.io/ws/communities/{tanggle_community_uuid}?events=queue+rooms`
- **REST API base:** `https://api.tanggle.io` (room creation endpoint documented below; not yet called)
- **Auth:** Bearer token in WebSocket `Authorization` header; same token for REST.
- **DB fields (per-user `profile` table):** `tanggle_api_token`, `tanggle_community_uuid`

---

## 1. Authentication

| Field | Value | Source |
| ----- | ----- | ------ |
| `tanggle_api_token` | Bearer token issued by Tanggle | Per-user `profile` table |
| `tanggle_community_uuid` | UUID of the streamer's Tanggle community | Per-user `profile` table |

WebSocket connection header:

```
Authorization: Bearer {tanggle_api_token}
```

If either credential is missing or empty the bot logs once, sleeps 300 seconds, and checks again — it does **not** exit the task entirely (unlike HypeRate's `hr: null` behaviour).

---

## 2. WebSocket

**Connection URL:**

```
wss://api.tanggle.io/ws/communities/{tanggle_community_uuid}?events=queue+rooms
```

`events=queue+rooms` subscribes to both the community queue and room lifecycle events. Only `room.complete` is currently processed; all other event types are logged and discarded.

**Connection lifecycle (`connect_to_tanggle()` — `./bot/beta.py:2768–2836`):**

1. Read `tanggle_api_token` and `tanggle_community_uuid` from the per-user `profile` table. If missing, sleep 300 s and retry.
2. Open WebSocket with `Authorization: Bearer` header.
3. Inner receive loop — parse JSON, dispatch on `type`.
4. On `WebSocketConnectionClosed` or any exception: log, break inner loop, sleep 10 s, re-enter outer loop (reconnects indefinitely).

**Received event types:**

| `type` | Handled | Notes |
| ------ | ------- | ----- |
| `room.complete` | Yes — `process_tanggle_room_complete()` | Persisted to DB, announced in chat |
| All others | Logged only | No processing; logged to `integrations_logger` |

### `room.complete` payload

```json
{
  "type": "room.complete",
  "data": {
    "room": {
      "uuid": "...",
      "redirectUrl": "https://tanggle.io/...",
      "title": "Optional room title",
      "pieces": {
        "count": 500,
        "completed": 500,
        "x": 25,
        "y": 20
      },
      "playerCount": 8,
      "playerLimit": 20,
      "image": {
        "uuid": "...",
        "slug": "my-image",
        "publicId": "..."
      },
      "community": {
        "uuid": "...",
        "name": "CommunityName"
      },
      "completedAt": "2026-05-10T14:09:27.000Z",
      "createdAt": "2026-05-10T13:30:00.000Z"
    },
    "participants": [
      {
        "person": {
          "user": { "username": "tanggle_user" },
          "connections": {
            "twitch": { "username": "twitch_user" }
          }
        },
        "score": 1234,
        "timer": 2345
      }
    ]
  }
}
```

`participants[0]` is the top-scorer (winner). `timer` is in seconds. `twitch.username` may be null if the participant hasn't linked their Twitch account.

**DB INSERT** (`tanggle_room_completions` table, per-user DB):

| Column | Source field |
| ------ | ------------ |
| `room_uuid` | `data.room.uuid` |
| `redirect_url` | `data.room.redirectUrl` |
| `room_title` | `data.room.title` |
| `piece_count` | `data.room.pieces.count` |
| `piece_completed` | `data.room.pieces.completed` |
| `piece_x` | `data.room.pieces.x` |
| `piece_y` | `data.room.pieces.y` |
| `player_count` | `data.room.playerCount` |
| `player_limit` | `data.room.playerLimit` |
| `image_uuid` | `data.room.image.uuid` |
| `image_slug` | `data.room.image.slug` |
| `image_public_id` | `data.room.image.publicId` |
| `community_uuid` | `data.room.community.uuid` |
| `community_name` | `data.room.community.name` |
| `winner_username` | `data.participants[0].person.user.username` |
| `winner_twitch_username` | `data.participants[0].person.connections.twitch.username` |
| `winner_score` | `data.participants[0].score` |
| `winner_timer_seconds` | `data.participants[0].timer` |
| `created_at` | `data.room.createdAt` |
| `completed_at` | `data.room.completedAt` |
| `participants_json` | Full `participants` array as JSON |
| `raw_payload` | Full event payload as JSON |

Uses `INSERT IGNORE` — duplicate `room_uuid` events are silently skipped.

---

## 3. REST endpoints

### POST `/puzzles/rooms` — Create a puzzle room (not currently called)

The bot receives room completion events but does **not** create rooms. Documented here for future implementation.

**URL:** `https://api.tanggle.io/puzzles/rooms`  
**Auth:** `Authorization: Bearer {tanggle_api_token}`  
**Rate limit:** 3 rooms per 30 seconds (sliding window) → HTTP 429  
**Tier limit:** Community subscription tier caps concurrent rooms → HTTP 400

**Request body — community type** (used for starting a puzzle directly):

```json
{
  "type": "community",
  "community": "<community_uuid>",
  "isHardmode": false,
  "components": [
    {
      "image": "<image_uuid>",
      "imageCrop": { "x": 0, "y": 0, "width": 1920, "height": 1080 },
      "pieces": 500
    }
  ],
  "playerLimit": 20,
  "isMystery": false,
  "password": null,
  "title": null
}
```

**Request body — community queue moderator** (used for queueing a puzzle):

```json
{
  "type": "community_queue_moderator",
  "community": "<community_uuid>",
  "isHardmode": false,
  "components": [...],
  "playerLimit": 20,
  "isMystery": false,
  "password": null,
  "title": null
}
```

**`pieces` field:** Either an integer (1–10099 total pieces) or `{"x": 25, "y": 20}` for explicit grid dimensions (3–500 per axis).

**`imageCrop`:** `{"x": 0, "y": 0, "width": W, "height": H}` — pixel crop on the source image. `null` to use the full image (min 100×100, max 16384×16384). Set to `null` unless cropping is needed.

**Response (success):**

```json
{ "success": true, "uuid": "<room_uuid>" }
```

**Response (logical failure — still HTTP 200):**

```json
{ "success": false, "reason": "mulltiple_forbidden" }
```

| `reason` | Meaning |
| -------- | ------- |
| `mulltiple_forbidden` | Can't add more than 1 puzzle to the community queue (note: upstream typo — double `l`) |
| `queue_size_exceeded` | Community queue size cap reached |

**Other payload types** (not expected to be needed):

| `type` | Use case |
| ------ | -------- |
| `public` | Open public room, no community required |
| `private` | Private room with optional password |
| `community_queue_member` | Member-initiated queue submission |

---

## 4. Bot command

### `!puzzles`

Reads the total completed puzzle count from `tanggle_room_completions` and sends it to chat:

> `We've completed 42 Tanggle puzzles so far.`

Pluralises "puzzle"/"puzzles" based on count.

Callsite: `./bot/beta.py:9157–9189` (`puzzles_command`). Uses `get_tanggle_completed_count()` (`./bot/beta.py:2851–2860`) which queries `SELECT COUNT(*) FROM tanggle_room_completions`.

---

## 5. Repo callsites

| Concern | File |
| ------- | ---- |
| WebSocket connection + reconnect | `./bot/beta.py:2768–2836` (`connect_to_tanggle`) |
| Datetime parsing helper | `./bot/beta.py:2838–2849` (`parse_tanggle_datetime`) |
| Completed count query | `./bot/beta.py:2851–2860` (`get_tanggle_completed_count`) |
| Room complete handler + DB insert | `./bot/beta.py:2862–2974` (`process_tanggle_room_complete`) |
| `!puzzles` command | `./bot/beta.py:9157–9189` |
| Task launch | `./bot/beta.py:888` (`looped_tasks["tanggle_websocket"]`) |
| v6 equivalent | `./bot/beta-v6.py:2247–2410` |
| DB tables | `tanggle_room_completions`, `tanggle_puzzle_stats` (per-user DB) |
| Credentials | `profile.tanggle_api_token`, `profile.tanggle_community_uuid` (per-user DB) |

---

## 6. Reconnect behaviour

- **On `WebSocketConnectionClosed` or any exception:** break inner loop, sleep 10 s, reconnect.
- **On missing credentials:** sleep 300 s, re-check — does not exit the task.
- **No cap on reconnect attempts** — retries indefinitely.
- **Global flag `_tanggle_no_creds_logged`** prevents log spam when credentials are absent.

---

## 7. Gotchas

- **REST room creation is not implemented.** Rooms must be started manually from the Tanggle site or dashboard. If a `!puzzle create` command is ever added, use `type: 'community'` to start immediately or `type: 'community_queue_moderator'` to queue.
- **`success: false` is still HTTP 200.** Check the `success` field, not just the status code, when implementing room creation.
- **`mulltiple_forbidden` has a typo** (double `l`) in the upstream API spec. Match the upstream string exactly when checking `reason`.
- **`twitch.username` may be null** — a participant who hasn't linked Twitch returns `null` in the connections object. `winner_twitch_username` will be stored as null.
- **`INSERT IGNORE` on `room_uuid`** — the WebSocket may deliver `room.complete` more than once (e.g. after reconnect). The ignore clause prevents duplicate rows.
- **Beta-only feature as of writing.** `bot.py` (stable) does not have Tanggle integration. Per [bot-versions.md](../../../rules/bot-versions.md), it stays beta until stable enough to backport.
- **Tier limits apply to room creation** — the community subscription tier on `tanggle.io/subscription` caps how many rooms can run concurrently. A 400 on `POST /puzzles/rooms` means the tier cap was hit.
