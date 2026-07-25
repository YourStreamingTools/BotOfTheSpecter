# Bot-host control API

Private FastAPI that runs **on the bot server** and owns process start/stop/status.

Public clients (mobile, dashboard UI) keep using `api.botofthespecter.com/bot/*` with the user’s API key. The public API and server-side dashboard then call this service with a **service key** — no SSH.

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | Liveness (no auth) |
| GET | `/api/running_bots` | Full local inventory (admin) |
| GET | `/api/bot/status?channel=&bot_type=` | One channel |
| POST | `/api/bot/start` | Start (body has tokens) |
| POST | `/api/bot/stop` | Stop |
| POST | `/api/bot/restart` | Stop then start |

Auth: `X-API-KEY` or `X-BOTS-CONTROL-KEY` = `BOTS_CONTROL_KEY` from bot host `.env`.

## Deploy (server)

```bash
# code lives under bot home (same tree as bot.py)
mkdir -p /home/botofthespecter/bots_api
# copy bots_api/* here, or git pull whole bot tree

# install deps into stable venv (or a dedicated tools venv)
/home/botofthespecter/venvs/stable/bin/pip install -r /home/botofthespecter/bots_api/requirements.txt

# .env must include:
#   BOTS_CONTROL_KEY=<long random secret>
#   BOT_HOME=/home/botofthespecter

sudo cp bots-api.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now bots-api
```

### Reverse proxy (Caddy example)

```caddy
bots.botofthespecter.com {
    reverse_proxy 127.0.0.1:8090
}
```

Bind the app to `127.0.0.1` only; TLS on the edge.

## Callers

- **Public API** (`api/api.py`): `BOTS_API_BASE` + `BOTS_CONTROL_KEY` — replaces SSH for `/bot/status|start|stop`.
- **Dashboard** (`config/bots_api.php`): `base_url` + `control_key` — `bot_control_functions.php` uses HTTP instead of SSH for start/stop/status.
- **Admin running list**: `GET {base_url}/api/running_bots` with the control key.

## Security

- Do not put user API keys in this service’s auth.
- Do not commit real `control_key` values.
- Prefer firewall / Cloudflare so only trusted origins hit the host if possible; key is still mandatory.
