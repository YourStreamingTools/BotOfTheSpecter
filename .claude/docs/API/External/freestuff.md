# FreeStuff Webhooks Reference (BotOfTheSpecter)

FreeStuff is the third-party FreeStuffBot service that announces free games (Steam, Epic, GOG, etc.) when they go free-to-keep. This integration is **operationally different** from the per-user donation webhooks: it is a single shared **admin webhook** consumed centrally, not per-streamer. Game announcements are saved to the central `freestuff_games` table and broadcast to global WebSocket listeners (the SpecterDiscord bot, which posts to Discord channels) — not to per-user overlays.

- **Ingest endpoint:** `POST /freestuff?api_key=<ADMIN_API_KEY>` → `./api/api.py:~1971` (`handle_freestuff_webhook`)
- **WebSocket event:** `FREESTUFF` → global listeners only → `FREESTUFF_ANNOUNCEMENT` in SpecterDiscord
- **Auth scope:** admin API key with `service='FreeStuff'` (or super-admin `service='admin'`)
- **No overlay:** there is no `./overlay/freestuff.php` — display is exclusively via Discord

---

## 1. Event types

Follows the **Standard Webhooks Spec** (`https://www.standardwebhooks.com/`):

| `type` | Meaning |
| ------ | ------- |
| `fsb:event:ping` | Validation poke. `data.manual: true` if sent manually from FreeStuff dashboard; `false` for automated health checks. Bot returns `204 No Content` with `X-Client-Library: BotOfTheSpecter/1.0`. |
| `fsb:event:announcement_created` | New batch of free games published. `data` is a `ResolvedAnnouncement` containing `resolvedProducts: [...]`. |
| `fsb:event:product_updated` | An existing product changes (price end-time, store URL, etc.). `data` is a full `Product` snapshot, not a diff. |

Source: `https://docs.freestuffbot.xyz/api-v2/webhooks/`

---

## 2. Setup on FreeStuff

FreeStuff only allows a small number of partner integrations to receive their webhook directly — **this is not a per-streamer setup**.

1. The BotOfTheSpecter operator registers an account at `https://freestuffbot.xyz/` and goes to the partner/dashboard area.
2. Creates an "App" in the FreeStuff dashboard — receives a webhook signing public key (Ed25519).
3. Webhook URL entered: `https://api.botofthespecter.com/freestuff?api_key=<FREESTUFF_ADMIN_KEY>`
   - `<FREESTUFF_ADMIN_KEY>` is an admin API key with `service='FreeStuff'` in the `admin_api_keys` table.
4. Subscribes to: `fsb:event:ping`, `fsb:event:announcement_created`, `fsb:event:product_updated`.

**This URL is secret operator infrastructure.** Never share it and never expose it in the per-user dashboard.

---

## 3. Authentication & signature verification

**Layer 1 — BotOfTheSpecter side (enforced):**
`?api_key=<key>` must satisfy `verify_key(api_key, service="FreeStuff")` with `key_info["type"] == "admin"`. Accepts super-admin (`service='admin'`) or FreeStuff-scoped (`service='FreeStuff'`) keys. Anything else → HTTP 401.

**Layer 2 — FreeStuff Ed25519 signature (NOT currently enforced — see §6):**

Standard Webhooks Spec headers:

| Header | Value |
| ------ | ----- |
| `Webhook-Id` | Unique per delivery — use for idempotency. |
| `Webhook-Timestamp` | Unix seconds from **2025-01-01** (custom epoch — not standard Unix epoch from 1970). |
| `Webhook-Signature` | Ed25519 signature, base64-encoded. |
| `X-Compatibility-Date` | Schema version of the payload. |

Verification algorithm: Ed25519 over the message `"<webhook_id>.<webhook_timestamp>.<raw_body>"`, using the partner public key from the FreeStuff dashboard. **Use the raw HTTP body before JSON parsing.**

The bot currently reads `Webhook-Id` and `X-Compatibility-Date` only for logging — no signature verification is performed.

---

## 4. Payload schemas

### Top-level Standard Webhooks shape

```json
{
  "type": "fsb:event:announcement_created",
  "timestamp": "2022-11-03T20:26:10.344522Z",
  "data": { }
}
```

Pydantic model (`./api/api.py:1241`):
```python
class FreeStuffWebhookPayload(BaseModel):
    type: str       # "fsb:event:ping" | "fsb:event:announcement_created" | "fsb:event:product_updated"
    timestamp: str  # ISO 8601
    data: dict
```

### `fsb:event:ping`

```json
{
  "type": "fsb:event:ping",
  "timestamp": "2026-05-10T12:00:00.000Z",
  "data": { "manual": true }
}
```

`data.manual: true` = human clicked "Send test webhook"; `false` = automated health check. Bot returns `204 No Content` with `X-Client-Library: BotOfTheSpecter/1.0`. **This header is required** — FreeStuff uses it to fingerprint the consumer for analytics. Returning it without a body is correct; do not forward ping events to the WebSocket.

### `fsb:event:announcement_created`

`data` is a `ResolvedAnnouncement`:

```json
{
  "id": 12345,
  "createdAt": "2026-05-10T15:00:00.000Z",
  "products": [67890, 67891],
  "resolvedProducts": [
    {
      "id": 67890,
      "title": "Some Free Game",
      "store": "Steam",
      "type": "game",
      "thumbnails": {
        "steam_library_600x900": "https://cdn.akamai.steamstatic.com/.../600x900.jpg",
        "thumbnail": "https://...",
        "org_logo": "https://..."
      },
      "images": [
        { "url": "https://...", "width": 460, "height": 215 }
      ],
      "urls": [
        { "url": "https://store.steampowered.com/app/...", "type": "default" }
      ],
      "description": [
        { "lang": "en-US", "text": "Get this game free until..." }
      ],
      "prices": [
        { "currency": "USD", "oldValue": 1999, "newValue": 0 }
      ],
      "platforms": ["windows", "mac", "linux"],
      "rating": 0.87,
      "tags": ["action", "rpg"]
    }
  ]
}
```

`prices[*].oldValue` / `newValue` are integers in **cents** (USD `1999` = $19.99). Free-tier consumers see a subset of `images` and `urls` — don't rely on full data.

### `fsb:event:product_updated`

```json
{
  "type": "fsb:event:product_updated",
  "timestamp": "2026-05-10T16:00:00.000Z",
  "data": { /* full Product, same shape as resolvedProducts[i] */ }
}
```

Treat as a full snapshot, not a diff.

---

## 5. Database persistence

`save_freestuff_game()` (`./api/api.py:1786`) extracts a normalized row from each product:

| Column | Source |
| ------ | ------ |
| `game_id` | `product.id` or `product.gameId` |
| `game_title` | `product.title` or `product.name` |
| `game_org` | `product.store` or `product.org.name` (fallback: `"FreeStuff"`) |
| `game_thumbnail` | `images[0].url` → `thumbnails.steam_library_600x900` → `org_logo` → `thumbnail` |
| `game_url` | `urls[0].url` (list shape) or `urls.default` / `urls.org` (dict shape) |
| `game_description` | `description[*]` where `lang in ("en-US", "en")`, else `description[0]` |
| `game_price` | `f"Was ${prices[*].oldValue/100:.2f}"` for first price with `oldValue` |
| `received_at` | Webhook `timestamp` if parseable, else `CURRENT_TIMESTAMP` |

Upsert key: `(game_id)` first, fallback `(game_title, game_org)`. After every insert, the table is truncated to the last **5 rows** by `received_at DESC`. Older entries are deleted.

---

## 6. WebSocket forwarding

For announcements and product updates only (ping returns `204` immediately):

```text
GET https://websocket.botofthespecter.com/notify
    ?code=<admin_api_key>
    &event=FREESTUFF
    &data=<URL-encoded JSON-stringified body>
```

**Note:** uses `json.dumps(webhook_data)` here — unlike Patreon/Ko-fi/Fourthwall which pass the dict directly to `urlencode`. The WebSocket server therefore receives a proper JSON string, not a Python-dict-literal.

Server-side (`./websocket/server.py:964-999`):
- `handle_freestuff_event(code, data)` parses JSON / falls back to `ast.literal_eval`.
- Iterates `self.global_listeners` and emits `FREESTUFF_ANNOUNCEMENT` with `{ type, timestamp, data }` to each.
- **Does NOT broadcast to per-code clients.** No overlays are subscribed to `FREESTUFF`.

Read-only API endpoints:
- `GET /freestuff/games` — last 5 games (`FreeStuffGamesResponse` model, `./api/api.py:2203`)
- `GET /freestuff/latest` — most recent game (`FreeStuffGame` model, `./api/api.py:2245`)

---

## 7. Idempotency, retries & gotchas

- **Idempotency key:** `Webhook-Id` header is the canonical dedupe key. The bot reads it but only logs it. **TODO:** add a dedup cache (e.g. an LRU dict keyed by `Webhook-Id` with 1-hour TTL, or a `freestuff_seen_ids` table) to drop replays.
- **Retries:** FreeStuff retries on non-2xx per Standard Webhooks schedule. Webhooks marked "dead" after multiple consecutive failures over days are silently removed — re-register the URL to resume.
- **Custom epoch on `Webhook-Timestamp`.** It's Unix seconds from **2025-01-01**, not 1970. Don't compare directly with `time.time()`. The bot doesn't parse this currently — flag it if implementing replay protection.
- **`X-Set-Compatibility-Date` response header.** The bot can pin a schema version by returning this header. Currently unused. If FreeStuff bumps the compatibility date and the parser breaks, return `X-Set-Compatibility-Date: <YYYY-MM-DD>` to pin an older schema.
- **Signature not verified.** Add Ed25519 verify with the partner public key (cached from FreeStuff API) before trusting events. Store the public key as `FREESTUFF_PUBKEY_B64` in `/home/botofthespecter/.env` (server) — one global value, not per-user.
- **Per-product errors are tolerated.** Inside `save_freestuff_game`, each product is wrapped in its own try/except — one malformed product doesn't break the rest of the batch.
- **Description language is hardcoded to `en-US`/`en`.** Non-English-primary creators get whichever entry comes first.
- **Price stored as formatted string, not numeric.** `"Was $19.99"` is fine for display but unusable for filters/sorting. If filtering by price is ever needed, store `old_value_cents INT` and `currency VARCHAR(3)`.
- **5-game cap is hard-coded.** The `DELETE ... LIMIT 5` cleanup at the bottom of `save_freestuff_game()` is the place to change if more history is needed.
- **Free-tier `images`/`urls` are subsets.** The preference order (`images[0]` → `thumbnails.steam_library_600x900` → others) handles this gracefully.
- **No per-user routing.** Every announcement goes to all global listeners. Discord channel routing happens entirely inside SpecterDiscord, not BotOfTheSpecter.
- **Ping must return `X-Client-Library`.** FreeStuff uses it for analytics. Don't drop this header.

---

## 8. Sources

- FreeStuff webhooks: `https://docs.freestuffbot.xyz/api-v2/webhooks/`
- FreeStuff concepts (compatibility-date, etc.): `https://docs.freestuffbot.xyz/api-v2/concepts/`
- Standard Webhooks Spec: `https://www.standardwebhooks.com/`

---

## 9. In-repo callsites

| Concern | File |
| ------- | ---- |
| HTTP route handler | `./api/api.py:~1971` (`handle_freestuff_webhook`) |
| Admin key validation | `./api/api.py:1180` (`verify_key`) |
| DB persistence | `./api/api.py:1786` (`save_freestuff_game`) |
| Read endpoints | `./api/api.py:2203` (`/freestuff/games`), `:2245` (`/freestuff/latest`) |
| Pydantic models | `./api/api.py:1241` (`FreeStuffWebhookPayload`), `:1624`, `:1649` |
| WebSocket router | `./websocket/server.py:964` (`handle_freestuff_event`) |
| Discord consumer | `./bot/specterdiscord.py` (`WebsocketListener` — global listener) |
