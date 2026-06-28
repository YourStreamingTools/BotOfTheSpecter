# Custom Inbound Webhooks — Design

**Date:** 2026-06-14
**Status:** Approved

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
| Receiver route | `POST /webhook/{slug}`, OpenAPI `tags=["Admin Only"]` | Confirmed no collision; a plain non-`/v2/` route so the V2 header-auth middleware doesn't touch it (same as `/kick/{username}`). |
| Management layer | **PHP-direct CRUD** via `$conn` on the `website` DB | Matches every existing admin page (direct DB + SSH, never calls `api.py`); no admin-key plumbing into PHP. `api.py` only **reads** the config when a webhook lands. |
| Slug | **Admin-chosen + uniqueness check** | Security rests on the secret key. An optional random suffix is available as a hardening toggle. |
| Secret at rest | **Plaintext** (masked in UI) | Required for HMAC recompute; consistent with existing OAuth-token storage. Never logged in full. |
| Global behavior | Forward via a **service-scoped admin key** (looked up by the webhook's `service`) to **admin global-listeners**; the WS server identifies the service by the key and tags the event with the service name | Forwarding the master `ADMIN_KEY` is both unreliable and a key-leak: `/notify` authenticates `code` (super-admin or user key) and echoes it back to listeners. So global routing instead uses a service-scoped admin key (created on the API Keys page — literally "the secret key is the service it knows"), and `server.py` needs to accept service-scoped keys for custom events (globals only; key never echoed). No "every overlay" broadcast exists and none is added. |

## 4. Data Model — `website.custom_webhooks`

The table is created with `CREATE TABLE IF NOT EXISTS` on `api.py` startup (same pattern as `freestuff_games`). The PHP admin page also issues the idempotent `CREATE TABLE IF NOT EXISTS` defensively, but `api.py` is the schema source of truth.

| Column | Type | Notes |
|---|---|---|
| `id` | INT AUTO_INCREMENT PK | |
| `slug` | VARCHAR(64) NOT NULL, UNIQUE | URL segment → `/webhook/{slug}`. Validated `^[a-z0-9-]+$`. |
| `name` | VARCHAR(100) NOT NULL | Human label in the admin list. |
| `service` | VARCHAR(64) NOT NULL | Source/service label. Tags the forwarded event; for `global` it's "the service it knows". |
| `event_name` | VARCHAR(64) NOT NULL | WebSocket event to emit. Stored UPPER_SNAKE (`/notify` normalizes by uppercasing and replacing spaces with underscores). |
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

This is a public route; auth is the per-webhook secret (not an `api_key`), and it's subject to the existing IP rate limiting.

The request handling flow, in order:

1. Read the raw request body (needed intact for HMAC).
2. Look up the config by slug in the `website` DB. If the slug is missing **or** the webhook is disabled, return the same **404** for both — we don't leak which slugs exist.
3. Verify according to `verify_mode`:
   - `none` → skip (relies on the unguessable slug / secret framing).
   - `secret` → read the configured `secret_header` and constant-time compare it to the stored secret; **403** on mismatch or missing header.
   - `hmac` → recompute an HMAC-SHA256 of the raw body keyed by the secret, accept an optional GitHub-style `sha256=` prefix, and constant-time compare; **403** on mismatch.
4. Parse JSON from the raw body; **400** on invalid JSON.
5. Resolve the routing `code`:
   - `channel` → look up the `target_username`'s `api_key` (reusing `_get_api_key_for_username`). If that channel isn't registered, return **200** with a note and don't forward — this mirrors Kick's "channel not registered" behavior and avoids sender retries.
   - `global` → look up the service-scoped admin key for the webhook's `service` (via a new `_get_admin_key_for_service` helper); that key becomes the `code`. This reaches admin global-listeners only; the WS server tags the event with the service name and never echoes the key. If no admin key exists for the service, return **200** with a note (an admin must create one on the API Keys page first). `verify_mode='none'` is blocked for global webhooks.
6. Forward to the WebSocket server by issuing a `GET` to its `/notify` endpoint with the existing webhook query-param shape — `code`, `event` (the configured `event_name`), `data` (the JSON-encoded payload), and `service`. If the WebSocket server responds with anything other than 200, surface a **502** to the sender so it can retry.
7. Best-effort bump `last_received_at = now()` and increment `received_count`.
8. Return `{"status": "success"}`.

Security notes:
- Secret and HMAC checks use constant-time comparison.
- The secret is never logged — only the slug plus the secret's length/last-4.
- The route sits under `tags=["Admin Only"]` for docs grouping, but its runtime auth is the per-webhook secret; external senders can't hold an admin key.

WebSocket note: `/notify` has **no event whitelist** — unknown event names fall through the dispatch path and are emitted as-is to clients on that `code` and to global-listeners. So any configured `event_name` reaches its intended audience.

## 6. Management UI — `dashboard/admin/webhooks.php`

- Guarded by `admin_access.php` (`is_admin=1`); registered in the admin navigation alongside `api_keys.php` / `users.php`, using `t()` lang keys (en base + de/fr). Styled via the admin stylesheet — no inline styles.
- **CRUD directly via `$conn`** prepared statements against `website.custom_webhooks` (no `api.py` calls).
- **List view:** name, full receiver URL (`https://api.botofthespecter.com/webhook/{slug}`) with a copy button, service, scope/target, verify mode, enabled toggle, last-received, received count, and actions (edit / delete / regenerate secret).
- **Create/edit form:** name, service, event name, scope (`channel` → pick a username / `global`), verify mode, and an auto-generated secret (random bytes rendered as hex), shown once on create/regen then masked (bullet style, matching the recent token-masking work), plus the secret header.
- Write an `admin_audit_log()` entry on create / edit / delete / regen-secret.

## 7. Data Flow

```
External service ──POST──▶ api.py  /webhook/{slug}
                            │ 1. lookup config by slug (website DB)
                            │ 2. verify (none/secret/hmac, constant-time)
                            │ 3. parse JSON
                            │ 4. resolve code: channel→user api_key | global→service-scoped admin key
                            └─5. GET websocket /notify?code=&event=&data=&service=
                                        │
                                        ├─ channel clients on that code (overlays/bot/dashboard)
                                        └─ admin global-listeners (tagged with channel_code/service)

Admin panel ──$conn (direct MySQL)──▶ website.custom_webhooks  (create/list/edit/delete/regen)
```

## 8. Edge Cases & Notes

- **Disabled webhook:** 404, indistinguishable from missing.
- **`channel` target not registered / no api_key:** 200 with a note, no forward (avoids sender retries; mirrors Kick).
- **WebSocket unreachable:** 502 to the sender, so it retries.
- **Slug collision:** uniqueness is enforced at the DB (`UNIQUE`) and validated in the form before insert.
- **Schema ownership:** `api.py` startup creates the table; the PHP `CREATE TABLE IF NOT EXISTS` is defensive and harmless. This is a **central** `website` table, so it does **not** go through `dashboard/usr_database.php` (that path is per-user only).
- **No secret in logs:** length/last-4 only.

## 9. Files Touched

- `./api/api.py` — new `POST /webhook/{slug}` route, plus the `CREATE TABLE IF NOT EXISTS custom_webhooks` on startup. Reuses `_get_api_key_for_username`, adds a new `_get_admin_key_for_service` helper, and uses the existing aiohttp `/notify` forward pattern.
- `./websocket/server.py` — extend `notify_http` to accept **service-scoped admin keys** for custom (fallthrough) events: deliver to global-listeners only, tag `channel_code` with the service name, and strip the `code` so the key is never echoed. **Requires a WebSocket server restart on deploy.**
- `./dashboard/admin/webhooks.php` — new admin CRUD page.
- Admin navigation file + `lang/en.php` (+ `de.php`, `fr.php`) — link and lang keys.
- (Optional) `./config/*.php` — only if the API base URL isn't already available to the admin page; otherwise reuse existing config.

Each touched file should pass its language's syntax check (Python, PHP) before deploy.

## 10. Open Questions (none blocking)

- Confirm the API base domain shown in the UI is `api.botofthespecter.com`.
- Whether to enable the optional random slug suffix by default (currently: admin-chosen, no suffix).
