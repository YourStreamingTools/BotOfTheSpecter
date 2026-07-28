# Bots API — Process Control (No SSH)

Bot start / stop / status / inventory is **HTTP**, not SSH. Do not reintroduce `pgrep`, `screen` via SSH, or `status.py` for lifecycle.

## Control plane

| Piece | Location |
| ----- | -------- |
| Service code | `./bot/bots_api/` (`server.py`, `manager.py`, `bots-api.service`) |
| Public URL | `https://bots.botofthespecter.com` (Caddy → `127.0.0.1:8090`) |
| PHP client | `./dashboard/includes/bots_api_client.php` |
| PHP config | `./config/bots_api.php` → production `/var/www/config/bots_api.php` |
| Dashboard wrappers | `./dashboard/includes/bot_control_functions.php` (`checkBotRunning`, `performBotAction`) |
| Public API proxy | `./api/api.py` → `_bots_api_request` + user routes `/bot/status`, `/bot/start`, `/bot/stop` |

## Auth

- Admin key in `website.admin_api_keys` with **`service = bots`** (case-insensitive).
- Super-admin `service = admin` also accepted by the bots host.
- Headers: `X-API-KEY` and/or `X-BOTS-CONTROL-KEY`.
- Callers **load the key from MySQL** (PHP: `bots_api_resolve_control_key()`; Python: `_get_bots_control_key()`). Optional env `BOTS_CONTROL_KEY` is break-glass on the bot host only.
- Never expose the control key to browsers or user sessions.

## Endpoints (bots host)

| Method | Path | Notes |
| ------ | ---- | ----- |
| GET | `/health` | No auth |
| GET | `/api/running_bots` | Full local inventory |
| GET | `/api/bot/status?channel=&bot_type=` | One channel; omit `bot_type` to find any |
| POST | `/api/bot/start` | JSON body: channel, bot_type, channel_id, token, refresh, apitoken, custom?, botusername?, self?, version? |
| POST | `/api/bot/stop` | JSON: `{ "channel", "bot_type" }` |
| POST | `/api/bot/restart` | Same body as start |

`bot_type`: `stable` | `beta` | `v6` (+ `custom` for status/stop matching). Channel = Twitch **login** (lowercased).

## PHP helpers (prefer these)

```php
bots_api_running_bots();
bots_api_bot_status($channel, $botType = null);
bots_api_start_bot($body);
bots_api_stop_bot($channel, $botType = 'stable');
bots_api_stop_all_for_channel($channel); // all variants — use on username rename
```

## Rules

1. **Start/stop/status go through bots API only.** SSH remains for logs, token scripts, admin systemctl, file checks — not process lifecycle.
2. **Processes are keyed by Twitch login** (`-channel`), not `twitch_user_id`. If login renames, stop the **old** channel name first (`bots_api_stop_all_for_channel`).
3. **Do not auto-start** after a rename unless product explicitly asks; user restarts from dashboard under the new login.
4. **systemd**: `bots-api.service` must use `KillMode=process` so restarting the API does not kill the fleet (bots spawn under `screen` in the same cgroup).
5. **PHP never reads `.env`** for this — use `./config/bots_api.php` + DB admin key ([php-config.md](./php-config.md)).
