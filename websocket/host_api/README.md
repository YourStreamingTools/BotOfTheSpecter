# WebSocket host control API

Private lifecycle control for units on the **WebSocket host** (same idea as `bots_api`, thinner).

| Piece | Value |
| ----- | ----- |
| Code | `./websocket/host_api/` |
| Bind | `127.0.0.1:8093` |
| Public | `https://websocket.botofthespecter.com/control/…` (Caddy path strip) |
| Auth | Admin key `service=websocket` (or `admin`) via `X-API-KEY` |
| PHP | `./dashboard/includes/websocket_control_client.php` + `./config/websocket_control.php` |

## Endpoints

| Method | Path | Auth |
| ------ | ---- | ---- |
| GET | `/` | no — redirects to `/docs` |
| GET | `/docs` | no — themed operator explorer |
| GET | `/openapi.json` | no |
| GET | `/health` | no |
| GET | `/api/services` | yes |
| GET | `/api/service/status?unit=` | yes |
| POST | `/api/service/start` | yes — JSON `{ "unit": "websocket" }` |
| POST | `/api/service/stop` | yes |
| POST | `/api/service/restart` | yes |

Allowlisted units: `websocket`, `caddy`, `websocket-control`.

## Deploy

1. Create Admin → API Keys key with **service = `websocket`**.
2. Copy `host_api/` to `/home/botofthespecter/host_api/` on the WS host.
3. `pip install -r host_api/requirements.txt` into `venvs/websocket` (or shared venv).
4. Install `websocket-control.service` → systemd enable/start.
5. Update Caddyfile (path `/control/*`) and reload Caddy.
6. Passwordless sudo for `systemctl` if the service runs as `botofthespecter`.
7. Deploy PHP config + client; admin UI uses HTTP for `websocket.service`.

## Config (PHP)

```php
// /var/www/config/websocket_control.php
return [
    'base_url' => 'https://websocket.botofthespecter.com/control',
    'admin_service' => 'websocket',
    'control_key' => '',
    'timeout' => 15,
];
```
