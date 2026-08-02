---
name: project_custom_inbound_webhooks
description: Admin-defined inbound webhooks feature + the /notify code-auth gotcha and global service-key routing
metadata: 
  node_type: memory
  type: project
  originSessionId: 60566c19-8e4c-457e-9458-269d58585b9c
---

Built 2026-06-14 (uncommitted): admin-managed generic inbound webhooks so new integrations go live without editing/restarting api.py.

- **Receiver:** `POST /webhook/{slug}` in `./api/api.py` (`tags=["Admin Only"]`, auth = per-webhook secret, NOT an api_key). Looks up `website.custom_webhooks` by slug → verifies (none/secret/hmac, constant-time) → forwards to WebSocket `/notify` as the configured event. Table auto-created on api.py startup (lifespan) and defensively in the PHP page.
- **Management:** `./dashboard/admin/webhooks.php` — full CRUD via `$conn` (mysqli) directly on the `website` DB (no api.py calls), modeled on `api_keys.php`. Nav in `dashboard/menu.php`; 77 `admin_webhooks_*` lang keys in en/de/fr.

**Key gotcha (cost real rework):** the WebSocket `/notify` HTTP endpoint AUTHENTICATES the `code` query param (`server.py` ~1129-1153 via `verify_admin_key`/`verify_user_key`) — for custom/fallthrough events the code must be a **DB super-admin key (`service='admin'`) or a valid user api_key**, else 403. The env `ADMIN_KEY` is NOT checked there (it's only a fallback for global-listener *registration*). `/notify` also echoes `code` back to global-listeners as `channel_code` and puts all query params in `data` — so forwarding a secret as `code` LEAKS it. Event NAMES, however, are unrestricted (no whitelist) and uppercased+`_`-normalized.

**Global-scope routing** therefore uses a **service-scoped admin key** (looked up by the webhook's `service` via `_get_admin_key_for_service`; admin creates it on the API Keys page = "the secret key is the service it knows"), NOT the master key. `server.py notify_http` was extended: service-scoped admin keys are accepted for custom events, broadcast to global-listeners ONLY, tagged with the service name, with `code` stripped so the key is never echoed. **Deploying the global feature needs a WebSocket server restart.** `verify_mode='none'` is blocked for global. Channel-scope routes to the streamer's api_key and needs no WS change.

Related: [[project_bot_websocket_signaling]], [[project_websocket_wildcard]], [[feedback_no_commits]].
