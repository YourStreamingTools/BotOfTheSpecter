# Custom Inbound Webhooks — Design

**Date:** 2026-06-14
**Status:** Approved design (pre-implementation)
**Author:** Brainstorm with user (GFAUnDead)

## 1. Problem & Goal

Inbound webhooks today (Ko-fi, Patreon, Fourthwall, GitHub, Kick) are each **hardcoded** as their own route in `./api/api.py`. Adding any new integration means editing `api.py` and restarting the API server.

**Goal:** Let admins define new inbound webhook receivers from the admin panel — each with its own secret key — so a new integration can go live **without editing `api.py` or restarting the API server**. When such a webhook receives a `POST`, the API verifies it and forwards the payload to the WebSocket server as a configured event.

## 2. Scope

### In scope
- A single generic receiver route in `api.py`: `POST /webhook/{slug}`.
- A new central table `website.custom_webhooks` holding per-webhook config.
- An admin management page (`dashboard/admin/webhooks.php`) for full CRUD over that table.
- Per-webhook verification: `none` / `secret` / `hmac` (selectable).
- Per-webhook routing: `channel` (to one streamer's WebSocket code) or `global` (to admin global-listeners).

### Out of scope (YAGNI)
- Storing received payloads in the DB (action is forward-only).
- Outbound relay / re-POST to other URLs.
- Payload transformation / templating (payload forwarded as-is).
- Non-admin (streamer self-service) creation — admin-only.

## 3. Decisions (locked)

| Decision | Choice | Rationale |
|---|---|---|
| Direction | **Inbound** receiver | External services POST in. |
| Action on receive | **Forward to WebSocket** only | No DB storage, no relay. |
| Routing scope | **Configurable per webhook** (`channel` / `global`) | — |
| Verification | **Selectable per webhook** (`none` / `secret` / `hmac`) | Fits whatever the sender supports. |
| Receiver route | `POST /webhook/{slug}`, OpenAPI `tags=["Admin Only"]` | Confirmed no collision; plain non-`/v2/` route so the V2 header-auth middleware doesn't touch it (same as `/kick/{username}`). |
| Management layer | **PHP-direct CRUD** via `$conn` on the `website` DB | Matches every existing admin page (direct DB + SSH, never calls `api.py`); no admin-key plumbing into PHP. `api.py` only **reads** the config when a webhook lands. |
| Slug | **Admin-chosen + uniqueness check** | Security rests on the secret key (user's framing). Optional random suffix available as a hardening toggle. |
| Secret at rest | **Plaintext** (masked in UI) | Required for HMAC recompute; consistent with existing OAuth-token storage. Never logged in full. |
| Global behavior | Forward via a **service-scoped admin key** (looked up by the webhook's `service`) to **admin global-listeners**; the WS server identifies the service by the key and tags the event with the service name | **Revised during implementation:** `/notify` authenticates `code` (super-admin or user key) and echoes it back to listeners, so forwarding the master `ADMIN_KEY` was both unreliable and a key-leak. Instead, global uses a service-scoped admin key (created on the API Keys page — literally "the secret key is the service it knows"), and `server.py` was extended to accept service-scoped keys for custom events (globals only; key never echoed). No "every overlay" broadcast exists and none is added. |

## 4. Data Model — `website.custom_webhooks`

Created via `CREATE TABLE IF NOT EXISTS` on `api.py` startup (same pattern as `freestuff_games`). The PHP admin page also issues the idempotent `CREATE TABLE IF NOT EXISTS` defensively; `api.py` is the schema source of truth.

| Column | Type | Notes |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | |
| `slug` | VARCHAR(64) NOT NULL, UNIQUE | URL segment → `/webhook/{slug}`. Validated `^[a-z0-9-]+$`. |
| `name` | VARCHAR(100) NOT NULL | Human label in the admin list. |
| `service` | VARCHAR(64) NOT NULL | Source/service label. Tags the forwarded event; for `global` it's "the service it knows". |
| `event_name` | VARCHAR(64) NOT NULL | WebSocket event to emit. Stored UPPER_SNAKE (`/notify` normalizes via `.upper().replace(" ","_")`). |
| `scope` | ENUM('channel','global') NOT NULL | Routing mode. |
| `target_username` | VARCHAR(255) NULL | For `channel`: whose `api_key` becomes the `/notify` `code`. NULL for `global`. |
| `verify_mode` | ENUM('none','secret','hmac') NOT NULL DEFAULT 'secret' | Per-webhook verification. |
| `secret` | VARCHAR(255) NULL | Shared secret / HMAC key (plaintext). Auto-generated. |
| `secret_header` | VARCHAR(64) NOT NULL DEFAULT 'X-Webhook-Secret' | Header to read. Default `X-Webhook-Signature` when `verify_mode='hmac'`. |
| `enabled` | TINYINT(1) NOT NULL DEFAULT 1 | On/off without deleting. |
| `created_by` | VARCHAR(255) NULL | Admin username (audit). |
| `created_at` | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |
| `last_received_at` | TIMESTAMP NULL | Lightweight observability. |
| `received_count` | INT NOT NULL DEFAULT 0 | Lightweight observability. |

Indexes: `UNIQUE(slug)`, `INDEX(enabled)`.

## 5. Receiver Endpoint — `POST /webhook/{slug}` (api.py)

Public route; auth is the per-webhook secret (not an api_key). Subject to the existing IP rate limiting.

Flow:
1. `raw_body = await request.body()`.
2. `SELECT * FROM custom_webhooks WHERE slug=%s` (website DB). **404** if missing **or** disabled (same response for both → don't leak which slugs exist).
3. **Verify** per `verify_mode`:
   - `none` → skip (relies on unguessable slug / secret framing).
   - `secret` → read `request.headers[secret_header]`; `hmac.compare_digest(provided, secret)`; **403** on mismatch/missing.
   - `hmac` → recompute `hmac.new(secret.encode(), raw_body, sha256).hexdigest()`; accept optional `sha256=` prefix (GitHub-style); constant-time compare; **403** on mismatch.
4. Parse JSON from `raw_body` → **400** on invalid.
5. **Resolve routing `code`:**
   - `channel` → look up `target_username`'s `api_key` (reuse `_get_api_key_for_username`); if not registered → return **200** with a note (mirrors Kick's "channel not registered" behavior).
   - `global` → look up the service-scoped admin key for the webhook's `service` (`_get_admin_key_for_service`); `code` = that key. Reaches admin global-listeners only; the WS server tags the event with the service name and never echoes the key. If no admin key exists for the service → 200 with a note (admin must create one on the API Keys page). `verify_mode='none'` is blocked for global webhooks.
6. **Forward to WebSocket** using the exact existing webhook pattern:
   ```python
   params = {"code": code, "event": event_name, "data": json.dumps(payload), "service": service}
   url = f"https://websocket.botofthespecter.com/notify?{urlencode(params)}"
   async with aiohttp.ClientSession() as session:
       async with session.get(url, timeout=10) as response:
           if response.status != 200:
               raise HTTPException(status_code=502, detail="Error forwarding to WebSocket server")
   ```
7. Best-effort update `last_received_at = now()`, `received_count = received_count + 1`.
8. Return `{"status": "success"}`.

Security:
- Constant-time comparison for secret/HMAC.
- Secret never logged (log slug + secret length/last-4 only).
- The route is registered under `tags=["Admin Only"]` for docs grouping, but its runtime auth is the per-webhook secret (external senders cannot hold an admin key).

WebSocket note: `/notify` has **no event whitelist** — unknown event names fall through (`server.py:1213-1226`) and are emitted as-is to clients on that `code` and to global-listeners. So an arbitrary configured `event_name` reaches its audience.

## 6. Management UI — `dashboard/admin/webhooks.php`

- Guarded by `admin_access.php` (`is_admin=1`); registered in the admin navigation alongside `api_keys.php` / `users.php`, using `t()` lang keys (en base + de/fr). Styled via the admin stylesheet — no inline styles.
- **CRUD directly via `$conn`** prepared statements against `website.custom_webhooks` (no `api.py` calls).
- **List view:** name, full receiver URL (`https://api.botofthespecter.com/webhook/{slug}`) with copy button, service, scope/target, verify mode, enabled toggle, last-received, received count, actions (edit / delete / regenerate secret).
- **Create/edit form:** name, service, event name, scope (`channel` → pick username / `global`), verify mode → **secret auto-generated** (`random_bytes` → hex), shown once on create/regen then masked (bullet style, matching the recent token-masking commit), secret header.
- `admin_audit_log()` on create / edit / delete / regen-secret.

## 7. Data Flow

```
External service ──POST──▶ api.py  /webhook/{slug}
                            │ 1. lookup config by slug (website DB)
                            │ 2. verify (none/secret/hmac, constant-time)
                            │ 3. parse JSON
                            │ 4. resolve code: channel→user api_key | global→ADMIN_KEY
                            └─5. GET websocket /notify?code=&event=&data=&service=
                                        │
                                        ├─ channel clients on that code (overlays/bot/dashboard)
                                        └─ admin global-listeners (tagged with channel_code/service)

Admin panel ──$conn (direct MySQL)──▶ website.custom_webhooks  (create/list/edit/delete/regen)
```

## 8. Edge Cases & Notes

- **Disabled webhook:** 404 (indistinguishable from missing).
- **`channel` target not registered / no api_key:** 200 with note, no forward (avoids sender retries; mirrors Kick).
- **WebSocket unreachable:** 502 to the sender (lets it retry).
- **Slug collision:** uniqueness enforced at DB (`UNIQUE`) and validated in the form before insert.
- **Schema ownership:** `api.py` startup creates the table; PHP `CREATE TABLE IF NOT EXISTS` is defensive and harmless. This is a **central** `website` table, so it does **not** go through `dashboard/usr_database.php` (that's per-user only).
- **No secret in logs:** length/last-4 only.

## 9. Files Touched

- `./api/api.py` — new `POST /webhook/{slug}` route + `CREATE TABLE IF NOT EXISTS custom_webhooks` on startup. Reuse `_get_api_key_for_username`, new `_get_admin_key_for_service`, and the aiohttp `/notify` forward pattern.
- `./websocket/server.py` — `notify_http` extended to accept **service-scoped admin keys** for custom (fallthrough) events: delivers to global-listeners only, tags `channel_code` with the service name, strips the `code` so the key is never echoed. **Requires a WebSocket server restart on deploy.**
- `./dashboard/admin/webhooks.php` — new admin CRUD page.
- Admin navigation file + `lang/en.php` (+ `de.php`, `fr.php`) — link + lang keys.
- (Optional) `./config/*.php` — only if the API base URL isn't already available to the admin page; otherwise reuse existing config.

## 10. Open Questions (none blocking)

- Confirm the API base domain shown in the UI is `api.botofthespecter.com`.
- Whether to enable the optional random slug suffix by default (currently: admin-chosen, no suffix).
