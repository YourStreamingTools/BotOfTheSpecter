# Bots API — Process Control (No SSH)

Bot start / stop / status / inventory is **HTTP**, not SSH. Do not reintroduce `pgrep`, `screen` via SSH, or `status.py` for lifecycle.

## Control plane

| Piece | Location |
| ----- | -------- |
| Service code | `./bot/bots_api/` (`server.py`, `manager.py`, `bots-api.service`, `docs_ui/`) |
| Public URL | `https://bots.botofthespecter.com` (Caddy → `127.0.0.1:8090`) |
| Operator docs | `https://bots.botofthespecter.com/docs` (themed explorer; not for end users) |
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
| GET | `/health` | No auth — `ok`, `started_at`, `uptime_seconds` (uptime contract) |
| GET | `/docs` | Themed operator docs UI (`docs_ui/`) |
| GET | `/api/running_bots` | Full local inventory + durable **snapshot** (`last_seen_at`, `snapshot.missing` for crash/OOM recovery) |
| GET | `/api/running_bots/snapshot` | Snapshot view only (refresh + expected / missing) |
| GET | `/api/bot/status?channel=&bot_type=` | One channel; omit `bot_type` to find any. Also returns `script_mtime`, `last_run_mtime`, `code_update_available` (update notice; no SSH), and `custom_module_available` (whether `/home/botofthespecter/custom_channel_modules/{channel}.py` exists). |
| GET | `/api/online/{channel}` | Stream online marker (`True`/`False` from `logs/online/`) |
| POST | `/api/bot/start` | JSON body: channel, bot_type, channel_id, token, refresh, apitoken, custom?, botusername?, self?, version?, `load_custom_module`? (if true and module file exists, pass `-load-custom-module` to beta/v6) |
| POST | `/api/bot/stop` | JSON: `{ "channel", "bot_type" }` |
| POST | `/api/bot/restart` | Same body as start |
| POST | `/api/ops/run_script` | Allowlisted ops only (`refresh_spotify`, `refresh_streamelements`, `refresh_discord`, `refresh_custom_bot`) |

`bot_type`: `stable` | `beta` | `v6` (+ `custom` for status/stop matching). Channel = Twitch **login** (lowercased).

## PHP helpers (prefer these)

```php
bots_api_running_bots();           // includes snapshot for crash recovery
bots_api_running_bots_snapshot();  // snapshot-only endpoint if needed
bots_api_bot_status($channel, $botType = null);
bots_api_start_bot($body);
bots_api_stop_bot($channel, $botType = 'stable');
bots_api_stop_all_for_channel($channel); // all variants — use on username rename
```

## Rules

1. **Start/stop/status go through bots API only.** SSH remains for logs, token scripts, admin systemctl — **not** process lifecycle and **not** bot update-notice mtimes (script / version-control file times come from `GET /api/bot/status` as `script_mtime` / `last_run_mtime`).
2. **Processes are keyed by Twitch login** (`-channel`), not `twitch_user_id`. If login renames, stop the **old** channel name first (`bots_api_stop_all_for_channel`).
3. **Do not auto-start** after a rename unless product explicitly asks; user restarts from dashboard under the new login.
4. **systemd**: `bots-api.service` must use `KillMode=process` so restarting the API does not kill the fleet (bots spawn under `screen` in the same cgroup).
5. **PHP never reads `.env`** for this — use `./config/bots_api.php` + DB admin key ([php-config.md](./php-config.md)).
6. **Crash recovery snapshot** (server): `/home/botofthespecter/logs/bots_running_snapshot.json` — refreshed ~15s and on inventory GET. Intentional stop removes a row; process death does not. Admin Start Bots shows amber “Was running · {time_ago}” from `snapshot.missing`.
7. **Custom channel modules** are opt-in: dashboard `users.use_custom_module` + file on bot host. Status exposes availability; start only loads when both are true. Filename must match Twitch login (`[a-z0-9_]+.py`). Not the same as beta “custom bot name” (`use_custom` / `-custom`).
