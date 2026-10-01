---
name: admin-api-key-websocket-flags
description: "admin_api_keys.websocket_access and websocket_global gate WebSocket connect and global fan-out"
metadata:
  node_type: memory
  type: project
---

Admin → API Keys (`./dashboard/admin/api_keys.php`) stores two flags on `website.admin_api_keys`:

- `websocket_access` — the key may connect to the WebSocket and call `/notify`
- `websocket_global` — requires access. The key may register as a global listener, and events it sends are delivered across every channel. Without it, events only reach clients registered with that same key.

FreeStuff, GitHub, and global/discord_logs custom webhooks require both flags. `./api/api.py` checks them before forwarding. `./websocket/server.py` enforces them on `REGISTER` and `/notify`.

When the columns are first added (migration `20261001_0001_admin_api_keys_websocket`, or the admin page on first load), `admin`, `freestuff`, `github`, and services used by global custom webhooks are turned on. Other keys stay off. Env `ADMIN_KEY` can still register as a global listener. Until the columns exist, both servers keep the previous rules.

User API keys are unchanged. Restart the API and the WebSocket process after deploy.
