# Specter Point Store - Scope & Design

**Date:** 2026-07-09  
**Status:** Scoped (not implemented)  
**Product name:** **Point Store** (UI may show `{point_name} Store` from `bot_settings.point_name`)  
**Related:** Bot points (`bot_points`, `bot_settings`), sound/video alerts, WebSocket notify, members portal, `!store`

---

## Summary

Add a streamer-managed **loyalty store** so viewers can spend **bot points** (not Twitch channel points) on streamer-approved items - primarily sound alerts, video alerts, and TTS / chat moments. Viewers buy via:

1. **Members:** `https://members.botofthespecter.com/{channel}/store`
2. **Chat:** `!store` (list) and `!store <item>` (buy)

Checkout **debits** the viewer’s balance in the streamer’s per-user DB, logs the purchase, then notifies WebSocket with event **`STORE`**. The bot announces in chat; overlays play media via existing alert pipeline (and/or `STORE` payload).

Streamer **catalog admin** lives on the **dashboard**. Members and chat are shopfronts only.

---

## Problem

Bot points already support earning (chat/follow/sub/cheer/raid rates), games (`!slots`, `!gamble`, etc.), and mod credit/debit. There is **no product sink** comparable to StreamElements / StreamLabs loyalty stores. Channel-point sound/video alerts only work for affiliate/partner channels and use Twitch’s currency, not Specter points.

---

## Goals

| ID | Goal |
| -- | ---- |
| G1 | Streamers can create, price, enable/disable store items bound to existing media or simple actions |
| G2 | Viewers can see their balance and buy items on members `/{channel}/store` |
| G3 | Viewers can list and buy via **`!store`** in chat |
| G4 | Successful purchase atomically debits points and is audit-logged |
| G5 | WebSocket **`STORE`** fans out; bot posts chat; overlays trigger sound/video/TTS |
| G6 | Store is independent of Twitch channel points (parallel currencies) |
| G7 | Abuse controls: cooldowns, pause, stream-online gate, rate limits |

### Non-goals (this scope)

- Full SE/SL feature parity (bundles, sales calendars, timeout-other, etc.)
- Putting catalog admin on members
- A separate currency (reuse `bot_points`)
- Changing stable `bot.py` (implement on **beta** first; port to beta-v6 as needed)
- New overlay PHP file unless required (prefer existing sound/video/TTS/all overlays)
- Extension store panel (future)

---

## Product surfaces

```text
┌─────────────────────┐     ┌──────────────────────────────┐
│ Dashboard           │     │ Members                      │
│ Catalog CRUD        │     │ /{channel}/store             │
│ Pause store         │     │ Balance + buy UI             │
│ Prices / cooldowns  │     └──────────────┬───────────────┘
└──────────┬──────────┘                    │ debit + log
           │                               │ then /notify
           ▼                               ▼
    per-user MySQL  ◄──────────────  atomic checkout
    point_store_*                          │
    bot_points                             │ STORE (+ optional SOUND/VIDEO/TTS)
           ▲                               ▼
           │                    WebSocket (channel code)
    !store buy                  ├── bot → chat announcement
           │                    └── overlays → media
           └──────────────────── bot debit path (chat buy)
```

| Surface | Role | Auth |
| ------- | ---- | ---- |
| **Dashboard** | Create/edit/delete items; map media; enable store; pause; view purchase history | Streamer / mod (existing dashboard auth) |
| **Members** `/{channel}/store` | Browse catalog; show balance; buy | Twitch session (members login) |
| **Chat** `!store` | List / buy without browser | Twitch chat identity |
| **Bot** | Handle `!store`; listen for `STORE`; announce chat | Channel bot instance |
| **WebSocket** | Register/broadcast `STORE`; existing media events | Streamer API key on `/notify` |
| **Overlays** | Play sound/video/TTS (existing handlers preferred) | Browser source + code |

---

## Chat command: `!store`

**Single command name:** `!store` (no required `!buy` / `!shop`; optional aliases later).

| Input | Behaviour |
| ----- | ---------- |
| `!store` | List enabled items (name, cost). Paginate or truncate to fit chat length. Include usage hint: `Buy: !store <name>` |
| `!store <item>` | Resolve by id or case-insensitive title/slug; run checkout; chat confirm (or error) |
| `!store help` | Optional; same as bare `!store` usage line if cheap |

**Copy rules:**

- Use `bot_settings.point_name` in all messages (never hardcode “points” only).
- Success example: `@Viewer spent 500 Specter Points on Airhorn! (balance: 1500)`
- Errors: not enough balance, item not found, store disabled/paused, cooldown, stream offline (if gated), insufficient permission.

**Builtin command registration:**

- Add `store` to beta builtin command list + `builtin_commands` default row (status, permission, cooldowns).
- Permission default: everyone (configurable like other builtins).
- Command cooldown separate from **per-item** and **global store** cooldowns.

**Bot version policy:** Implement in `./bot/beta.py` first. Port to `./bot/beta-v6.py` when stable enough. Do **not** add to stable `./bot/bot.py` unless explicitly requested as critical.

---

## Members: `/{channel}/store`

### Routing

- URL: `https://members.botofthespecter.com/{channel}/store`
- Implementation options (pick one at build time):
  - Rewrite → `store.php?user={channel}`, or
  - Path parse in a dedicated entry that accepts second segment `store`
- Channel resolution: same as members `index.php` (DB exists, not restricted, not memorial).

### Page content

1. Channel header (display name / avatar if available)
2. **Your balance:** `{n} {point_name}` for the **logged-in** Twitch user in that channel’s `bot_points`
3. Grid/list of **enabled** catalog items: title, cost, type icon, affordability state
4. Buy control → POST buy endpoint
5. Empty / disabled / paused states
6. Optional: recent purchases by this viewer (last N)

### Buy endpoint (members server-side)

1. Require session (Twitch identity)
2. Resolve channel + streamer DB
3. CSRF + rate limit
4. Load item; must be enabled; store not paused; optional stream-online check
5. **Atomic debit** (see Checkout)
6. Insert purchase log
7. Server-side WebSocket notify (`code` = **streamer’s** API key from `website.users` - never expose to browser)
8. Return JSON: success, new balance, item summary

### Identity mapping

- Prefer match on **Twitch user id** in `bot_points` where present; fall back to login/name normalized lowercase.
- Buyer identity always from session, never from client-supplied “username” for debit authority.

### Theme

- Use `./members/style.css` only (portal CSS rule). Layout via members `layout.php` / sp-* components.

---

## Dashboard: catalog admin

New page or section (suggested: **Point Store** next to Bot Points), e.g. `./dashboard/point_store.php` (name flexible).

### Capabilities

- Toggle **store enabled** / **store paused** (global flags)
- Optional: require stream online for purchases
- CRUD items:
  - Title, description, cost
  - Type: `sound_alert` | `video_alert` | `tts` | `chat_message` (MVP types)
  - Payload: sound file / video file / TTS template / chat template
  - Enabled, sort order
  - Cooldown seconds (global per item and/or per user)
  - Optional: max per stream, stock
- Pick media from the **unified Media library** (`./dashboard/media.php`, `$media_path` / `media.botofthespecter.com`) - MP3 for sound items, MP4 for video items. Same source as alerts; **not** legacy `soundalerts`/`videoalerts` paths. **Do not** require Twitch reward id
- Test trigger (optional): fire media without debit (streamer only)
- Purchase history table (filter by user/item/date)

### Not on dashboard

- Viewer-facing “my balance” shop (that’s members)

---

## Data model (per-user / channel DB)

### `point_store_settings` (singleton `id = 1`)

| Column | Type | Purpose |
| ------ | ---- | ------- |
| `id` | INT PK | Always 1 |
| `enabled` | TINYINT | Master switch |
| `paused` | TINYINT | Temp pause (mod/streamer) without wiping catalog |
| `stream_online_only` | TINYINT | Reject buys when channel offline |
| `global_cooldown_seconds` | INT | Min seconds between any store buy per user |
| `max_purchases_per_user_per_stream` | INT NULL | Optional cap |
| `updated_at` | DATETIME | |

### `point_store_items`

| Column | Type | Purpose |
| ------ | ---- | ------- |
| `id` | INT PK AI | Stable id for `!store 12` |
| `title` | VARCHAR | Display name |
| `slug` | VARCHAR NULL | Optional unique slug for `!store airhorn` |
| `description` | TEXT NULL | Members UI |
| `cost` | INT | Points price (≥ 1) |
| `item_type` | ENUM/VARCHAR | `sound_alert`, `video_alert`, `tts`, `chat_message` |
| `payload` | JSON | Type-specific: `{ "sound": "file.mp3" }`, `{ "video": "..." }`, `{ "text": "..." }` |
| `enabled` | TINYINT | |
| `cooldown_seconds` | INT | Per-user per-item cooldown |
| `max_per_stream` | INT NULL | |
| `stock` | INT NULL | NULL = unlimited |
| `sort_order` | INT | |
| `created_at` / `updated_at` | DATETIME | |

### `point_store_purchases`

| Column | Type | Purpose |
| ------ | ---- | ------- |
| `id` | BIGINT PK AI | |
| `item_id` | INT | FK soft |
| `item_title` | VARCHAR | Snapshot at purchase |
| `item_type` | VARCHAR | Snapshot |
| `cost` | INT | Snapshot |
| `user_id` | VARCHAR NULL | Twitch id |
| `user_name` | VARCHAR | Login |
| `balance_after` | INT | |
| `source` | ENUM | `members` \| `chat` |
| `status` | ENUM | `completed` \| `fulfilled` \| `failed_notify` (as needed) |
| `created_at` | DATETIME | |

### Existing tables (reuse)

| Table | Use |
| ----- | --- |
| `bot_points` | Balances; debit on buy |
| `bot_settings` | `point_name` for UI/chat |
| Sound/video file storage | Same paths as current alerts (`soundalerts` / `videoalerts` URLs) |

### Migrations

- Per-user DB provisioning in dashboard user-db setup (same pattern as other feature tables)
- Migration script/iteration for existing channel DBs

---

## Checkout (shared rules)

Used by **members buy** and **bot `!store <item>`**.

1. Store `enabled` and not `paused`
2. Item exists and `enabled`
3. Optional: stream online
4. Optional: stock / max_per_stream / cooldowns
5. Atomic debit:

```sql
UPDATE bot_points
SET points = points - ?
WHERE (user_id = ? OR user_name = ?)
  AND points >= ?;
-- require affected_rows = 1
```

6. Insert `point_store_purchases`
7. Notify WebSocket `STORE` (+ companion media events as below)
8. Return success + `balance_after`

**Never** fulfill media before debit commits.  
**Never** debit twice (members vs bot each own their entry path; both write the same tables once per purchase).

If notify fails after commit: mark purchase for retry / `failed_notify`; do not auto-refund without streamer action (MVP: log + optional admin refund later).

---

## WebSocket: `STORE`

### Registration

- Add `STORE` to websocket server event handlers (broadcast to channel `code` + globals, same pattern as other channel events).
- HTTP `/notify?code={streamer_api_key}&event=STORE&...` must accept payload fields (query and/or documented JSON param if already used for complex events).

### Payload (canonical)

```json
{
  "event": "STORE",
  "username": "viewerlogin",
  "display_name": "ViewerDisplay",
  "user_id": "123456",
  "item_id": 42,
  "item_title": "Airhorn",
  "item_type": "sound_alert",
  "cost": 500,
  "point_name": "Specter Points",
  "balance_after": 1500,
  "source": "members",
  "sound": "airhorn.mp3",
  "video": null,
  "text": null
}
```

### Consumers

| Client | On `STORE` |
| ------ | ---------- |
| **Bot** | Post chat announcement (if not already announced by chat buy path - avoid double message: chat buy posts once; members buy relies on bot listener **or** members-only path always uses bot announce) |
| **Overlays** | Prefer **also** emitting existing `SOUND_ALERT` / `VIDEO_ALERT` / `TTS` from checkout so day-one overlays work without new overlay JS |

**Recommended hybrid fulfillment:**

1. Always emit `STORE` (bot chat + analytics-minded clients)
2. If `item_type == sound_alert` → also `SOUND_ALERT` with sound file URL/name as today  
3. If `video_alert` → `VIDEO_ALERT`  
4. If `tts` → `TTS` with text  
5. If `chat_message` → bot sends configured message (from `STORE` or bot-side template)

### Chat double-post guard

- **Chat buy:** bot performs checkout + chat success message; still emit `STORE` for overlays; bot’s `STORE` handler **skips** announce when `source == chat` (or when purchase already announced).
- **Members buy:** emit `STORE` with `source == members`; bot announces.

---

## Bot behaviour detail

### `!store` list

- Query enabled items ordered by `sort_order`, `cost`, `title`
- Format compact list; if too long, first page + “more items on members …/store”

### `!store <item>`

- Parse arg as id (numeric) or title/slug
- Run shared checkout with `source=chat`
- Success/error reply in chat
- Emit WebSocket events as above

### `STORE` socket handler

```text
@specterSocket.event
async def STORE(data):
    if data.get("source") == "chat":
        return  # already announced
    # send chat announcement using payload
```

---

## Security & abuse

| Control | Detail |
| ------- | ------ |
| AuthZ debit | Session (members) or chat author (bot) only |
| Streamer API key | Server-side only for `/notify` |
| CSRF | Members buy POST |
| Rate limit | Per-session / per-IP buy attempts |
| Cooldowns | Global + per-item (+ builtin command cooldown) |
| Pause | `paused` rejects buys without deleting catalog |
| Restricted/memorial | No store page / no buys |
| Input | TTS/chat text length caps; sanitize display strings |
| SQL | Parameterized queries only |

---

## MVP item types

| Type | Fulfillment |
| ---- | ----------- |
| `sound_alert` | Existing sound overlay event |
| `video_alert` | Existing video overlay event |
| `tts` | Existing TTS pipeline |
| `chat_message` | Bot sends fixed/template message |

**Deferred (post-MVP):** funny full-screen custom alerts, timeout-self, song-request free slot, stock UI polish, sales/bundles, extension catalog, timeout-other.

---

## Implementation phases

### Phase 1 - Foundation

- [x] Schema + per-user provisioning (`point_store_settings`, `point_store_items`, `point_store_purchases` in `usr_database.php`)
- [x] Dashboard: settings + item CRUD - `./dashboard/point_store.php` (menu: Settings → Point Store)
- [ ] Shared checkout helper logic (PHP for members; Python for bot - same rules documented)
- [ ] WebSocket: register `STORE` + `/notify` support
- [ ] Purchase log table UI / dashboard history (table exists; history UI pending)

### Phase 2 - Members shopfront

- [x] Route `/{channel}/store` (`members/index.php` dispatch + `members/store.php`)
- [x] Balance + catalog UI
- [x] Buy POST (debit → log → notify)
- [x] Companion `SOUND_ALERT` / `VIDEO_ALERT` / `TTS` emits
- [x] Bot `STORE` listener → chat announce

### Phase 3 - Chat `!store`

- [x] Builtin `store` command in beta
- [x] List + buy paths; double-announce guard (`source=chat`)
- [x] Cooldown / pause / offline gates aligned with members
- [x] WebSocket `STORE` handler + `/notify` path
- [ ] Help text / command reference docs (optional polish)

### Phase 4 - Harden & polish

- [ ] TTS + chat_message item types if not in P1
- [ ] Cooldown/stock/max-per-stream enforcement polish
- [ ] Failed-notify handling / retry
- [ ] Port to beta-v6
- [ ] Members empty states, affordability, mobile layout
- [ ] Optional `!shop` alias

### Out of scope until later

- Twitch Extension store
- Bundles, discounts, seasonal sales
- Timeout / “funny” interactive punishments
- Cross-channel points
- Refund UI (manual DB/mod tools acceptable initially)

---

## API notes (optional stretch)

Existing:

- `GET /user-points`, `POST /user-points/credit`, `POST /user-points/debit`

Optional later:

- `GET /point-store/items` (public or key-scoped catalog for extension)
- `POST /point-store/purchase` (centralize checkout for members + extension)

MVP may keep checkout in members PHP + bot only without new public purchase API.

---

## Acceptance criteria (MVP)

1. Streamer can create an enabled sound item costing N points on dashboard.
2. Viewer with ≥ N points on members `/{channel}/store` sees balance and item; buy succeeds; balance decreases by N.
3. Overlay plays the sound; bot posts a chat line for members buys.
4. Viewer with insufficient points gets a clear error; balance unchanged.
5. `!store` lists items; `!store <item>` buys with same debit rules; one chat success message (no double post).
6. Paused or disabled store rejects buys on both surfaces.
7. Purchase appears in `point_store_purchases` / dashboard history.
8. Twitch channel point rewards remain unchanged and independent.

---

## Open decisions (resolve at implement start)

| Topic | Recommendation |
| ----- | -------------- |
| Exact dashboard filename/nav label | “Point Store” or “{PointName} Store” |
| Slug required vs title-only match | Optional slug; title match case-insensitive |
| Stream online detection source | Same signal bot/API already uses for live state |
| Members path rewrite vs PHP router | Prefer clean `/{channel}/store` URL |
| Checkout code share | Documented parity PHP/Python first; extract shared API only if drift hurts |
| Whether overlays must handle `STORE` natively | No for MVP if companion media events fire |

---

## File touch map (expected)

| Area | Likely paths |
| ---- | ------------ |
| Spec (this doc) | `./.grok/specs/2026-07-09-point-store-scope.md` |
| Dashboard | `./dashboard/point_store.php` (or similar), menu entry, user DB provision |
| Members | `./members/store.php` (+ rewrite), buy endpoint, `style.css` as needed |
| Bot | `./bot/beta.py` (`!store`, `STORE` handler); later `./bot/beta-v6.py` |
| WebSocket | `./websocket/server.py`, `./websocket/event_handler.py` |
| Notify helper | Members curl to `/notify`; optional extend dashboard `notify_event.php` pattern |
| Docs/help | Command reference when chat ships |
| Builtin list | beta command registration / DB defaults |

---

## Success metric (product)

Bot points have a clear **spend path** beyond gambling; streamers can run a loyalty store without affiliate status; members URL is shareable (`…/{channel}/store`); chat uses one memorable command: **`!store`**.

---

**Last updated:** 2026-07-09  
**Authors:** Product scoping from design discussion (members store + `!store` + WebSocket `STORE`)
