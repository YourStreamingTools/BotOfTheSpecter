# Bot-host control API

Private FastAPI that runs **on the bot server** and owns process start/stop/status.

Public clients (mobile, dashboard UI) keep using `api.botofthespecter.com/bot/*` with the user’s API key. The public API and server-side dashboard then call this service with an **admin API key** — no SSH.

## Auth (Admin → API Keys)

1. Open **Dashboard → Admin → API Keys**.
2. Create a key with service name: **`bots`** (case-insensitive).
3. That key is stored in `website.admin_api_keys`.

Callers send it as `X-API-KEY` (or `X-BOTS-CONTROL-KEY`).

| Key service | Access |
|-------------|--------|
| `bots` | Full bots control API |
| `admin` | Super-admin — also accepted |
| anything else | Rejected |

Optional env break-glass only: `BOTS_CONTROL_KEY` on the bot host (prefer the DB key).

The public API and dashboard **load the `bots` key from MySQL automatically** — you do not paste it into `.env` for normal operation.

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | Liveness + `started_at` / `uptime_seconds` (no auth) |
| GET | `/api/running_bots` | Full local inventory (admin) + durable **snapshot** (`last_seen_at`, `missing` after crash/OOM) |
| GET | `/api/running_bots/snapshot` | Snapshot view only (refresh + return expected / missing) |
| GET | `/api/bot/status?channel=&bot_type=` | One channel (+ `script_mtime`, `last_run_mtime`, `code_update_available` for update notice) |
| GET | `/api/online/{channel}` | Stream online marker (`True`/`False` from `logs/online/`) |
| POST | `/api/bot/start` | Start (body has tokens) |
| POST | `/api/bot/stop` | Stop |
| POST | `/api/bot/restart` | Stop then start |
| POST | `/api/ops/run_script` | Allowlisted ops only (`refresh_spotify`, `refresh_streamelements`, `refresh_discord`, `refresh_custom_bot`) |

### Crash / OOM recovery snapshot

The control API writes a durable JSON inventory on the bot host:

- Path (server): `/home/botofthespecter/logs/bots_running_snapshot.json` (override with `BOTS_RUNNING_SNAPSHOT`)
- Refreshed every ~15s (`BOTS_SNAPSHOT_INTERVAL_SECONDS`) and on every `GET /api/running_bots`
- Live processes refresh `last_seen_at`; intentional stop removes the row
- After crash/OOM/reboot, processes are gone but the file still lists who was expected — response field `snapshot.missing` with `last_seen_ago_seconds` for admin Start Bots UI

## Deploy (server)

```bash
# code under bot home (same tree as bot.py)
# install deps into stable venv
/home/botofthespecter/venvs/stable/bin/pip install -r /home/botofthespecter/bots_api/requirements.txt

# .env on bot host needs SQL (to verify admin_api_keys) +:
#   SQL_HOST=...
#   SQL_USER=...
#   SQL_PASSWORD=...
#   SQL_PORT=3306
#   BOT_HOME=/home/botofthespecter
#   BOTS_API_HOST=127.0.0.1
#   BOTS_API_PORT=8090
# Optional: BOTS_ADMIN_SERVICE=bots  (default)

sudo cp bots_api/bots-api.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now bots-api
```

### Reverse proxy (Caddy)

`../Caddyfile` (i.e. `bot/Caddyfile`) is the tracked template — copy it to `/etc/caddy/Caddyfile`
on the bots host. Unlike `web/Caddyfile` (which needs a custom xcaddy build with the
Cloudflare DNS plugin for its wildcard certs), this one is a single exact host and uses
plain HTTP-01, so a stock `apt install caddy` binary works — no plugin, no API token,
just port 80 reachable for the ACME challenge.

Bind the app to `127.0.0.1` only; TLS on the edge.

## Docs UI

Themed dark explorer (same design system as the product API docs):

| URL | Purpose |
|-----|---------|
| `/docs` | Operator docs UI (`./bots_api/docs_ui/`) |
| `/openapi.json` | Raw OpenAPI schema |
| `/docs-static/*` | CSS/JS assets |

Keep `docs_ui` CSS/JS in sync with `./api/docs_ui/` when changing the explorer.

## Callers

- **Public API** (`api/api.py`): `BOTS_API_BASE` + key from `admin_api_keys` service `bots`.
- **Dashboard** (`config/bots_api.php`): `base_url` + auto-load key for `admin_service` (`bots`).
- **Admin running list**: `GET {base_url}/api/running_bots` with that key.

## Security

- Do not put user API keys in this service’s auth.
- Create a dedicated `bots` service key (do not reuse FreeStuff/GitHub keys).
- Prefer firewall / Cloudflare so only trusted origins hit the host if possible; key is still mandatory.
