# Fresh install: product API host (`api.botofthespecter.com`)

Greenfield move of the **public product API** onto a new server. Target layout matches production conventions used by bots-api / sql-api:

```text
Internet → Caddy :80/:443 (TLS) → 127.0.0.1:8080 → uvicorn (HTTP only)
```

| Piece | Value |
| ----- | ----- |
| Public hostname | `api.botofthespecter.com` |
| App user | `botofthespecter` |
| Code dir | `/home/botofthespecter` (flat deploy of repo `./api/*`) |
| Env file | `/home/botofthespecter/.env` |
| Python venv | `/home/botofthespecter/venvs/api` |
| App bind | `127.0.0.1:8080` |
| systemd unit | `fastapi.service` (name is fixed — dashboard admin targets it) |
| Caddyfile | repo `./api/Caddyfile` → `/etc/caddy/Caddyfile` |
| OS tested against | Ubuntu LTS (incl. 26.04) |

**Do not** dual-publish the same hostname on old + new. Cutover is single flip of DNS after the new box is proven.

---

## 0. Before you start

### From the old API host (copy these)

SSH onto the **current** API server and collect:

| Item | Typical path | Notes |
| ---- | ------------ | ----- |
| Secrets | `/home/botofthespecter/.env` | Copy off-box securely (not git). Scrub later if it still has legacy keys you no longer need. |
| IP whitelist | `/home/botofthespecter/ips.txt` | Rate-limit / whitelist networks |
| Runtime JSON | `/home/botofthespecter/{quotes,fortunes,builtin_commands,killCommand,ai,versions}.json` | Prefer **live** server copies; repo has seeds under `./api/` |
| Optional caches | `/home/botofthespecter/steamapplist.json` | Regenerates if missing |
| Logs (optional) | `/home/botofthespecter/log.txt*` | Not required to start |
| Media mounts | `/var/www/soundalerts`, `/var/www/walkons` | Only if those list endpoints must work; or set `SOUNDALERTS_ROOT` / `WALKONS_ROOT` |

Also note:

- **Old public IP** of `api.botofthespecter.com` (rollback).
- **New public IP** (A record target).
- MySQL is **remote** (`SQL_HOST` in `.env`) — you will allow the **new** API IP on the SQL firewall / `bind-address` / cloud security group.
- Twitch / Ko-fi / Patreon / Fourthwall / GitHub webhooks hit **hostname** `api.botofthespecter.com` — DNS flip is enough if URLs already use that host.

### Access you need

- Root (or sudo) on the **new** server.
- Cloudflare DNS for `botofthespecter.com` (A record only — **DNS-only / grey cloud**, not proxied).
- Ability to open ports **22**, **80**, **443** to the world (or restricted SSH as you prefer).
- SQL host: allow MySQL from the new API IP (usually 3306).

---

## 1. Base OS (Ubuntu)

As root on the **new** host:

```bash
apt update && apt upgrade -y
apt install -y \
  ca-certificates curl gnupg git \
  build-essential python3 python3-venv python3-dev \
  ufw
```

Optional but useful:

```bash
apt install -y htop jq rsync fail2ban
```

Set hostname (optional):

```bash
hostnamectl set-hostname api-bots   # or whatever you use
```

Timezone / NTP (pick yours):

```bash
timedatectl set-timezone Australia/Sydney
timedatectl set-ntp true
```

---

## 2. System user and layout

```bash
# If the user does not exist:
id botofthespecter 2>/dev/null || useradd -m -s /bin/bash botofthespecter

mkdir -p /home/botofthespecter/{venvs,logs}
mkdir -p /var/log/caddy
chown -R botofthespecter:botofthespecter /home/botofthespecter
```

SSH: put your key in `/home/botofthespecter/.ssh/authorized_keys` (mode `600`) and/or keep root key auth. Prefer key-only, disable password auth when you are comfortable.

---

## 3. Firewall

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
ufw status verbose
```

Do **not** expose `8080` publicly. The app stays on loopback.

---

## 4. Deploy application code

From your workstation (or pull on the server):

```bash
# Example: rsync from git checkout (run where the repo lives)
rsync -av --delete \
  --exclude '__pycache__' \
  --exclude '*.pyc' \
  --exclude '.env' \
  ./api/  botofthespecter@NEW_HOST_IP:/home/botofthespecter/
```

Or clone the monorepo and copy only `api/`:

```bash
# on server as botofthespecter
cd /home/botofthespecter
# git clone … then:
# rsync -a --delete /path/to/repo/api/ /home/botofthespecter/
```

**Must exist under** `/home/botofthespecter/` (contents of repo `./api/`):

- `api.py`
- `requirements.txt`
- `docs_ui/` (themed explorer)
- seed JSON files (same directory as `api.py`)

Ownership:

```bash
chown -R botofthespecter:botofthespecter /home/botofthespecter
```

---

## 5. Python venv

As `botofthespecter` (or root with `sudo -u botofthespecter`):

```bash
sudo -u botofthespecter python3 -m venv /home/botofthespecter/venvs/api
sudo -u botofthespecter /home/botofthespecter/venvs/api/bin/pip install --upgrade pip wheel
sudo -u botofthespecter /home/botofthespecter/venvs/api/bin/pip install -r /home/botofthespecter/requirements.txt
```

Smoke-import (will fail without `.env` — that is OK if it only complains about missing SQL_*):

```bash
sudo -u botofthespecter /home/botofthespecter/venvs/api/bin/python -c "import fastapi, uvicorn, aiomysql; print('ok')"
```

The tracked unit expects:

```text
ExecStart=/home/botofthespecter/venvs/api/bin/python /home/botofthespecter/api.py
```

If your venv path differs, edit the unit — do **not** rename the unit away from `fastapi.service`.

---

## 6. Secrets and data files

### `.env`

```bash
# Prefer scp from old host (never commit):
# scp old:/home/botofthespecter/.env /home/botofthespecter/.env
chown botofthespecter:botofthespecter /home/botofthespecter/.env
chmod 600 /home/botofthespecter/.env
```

`api.py` loads **only** `/home/botofthespecter/.env` (via `python-dotenv`).

Minimum required for process start:

| Variable | Purpose |
| -------- | ------- |
| `SQL_HOST` | MySQL host (remote SQL server) |
| `SQL_USER` | MySQL user |
| `SQL_PASSWORD` | MySQL password |
| `SQL_PORT` | MySQL port (usually `3306`) |

Strongly recommended (features break without them):

| Variable | Purpose |
| -------- | ------- |
| `ADMIN_KEY` | Admin routes |
| `CLIENT_ID` / `CLIENT_SECRET` | Twitch app |
| `TWITCH_OAUTH_API_TOKEN` / `TWITCH_OAUTH_API_CLIENT_ID` | Helix as bot |
| `WEATHER_API` / `STEAM_API` | Proxied services |
| `BOTS_API_BASE` | default `https://bots.botofthespecter.com` |
| `BOT-SRV-HOST`, `WEB-HOST`, `SQL-HOST`, `WEBSOCKET-HOST` | Legacy SSH ops (hostnames/IPs) |
| `SSH_USERNAME` / `SSH_PASSWORD` | Those SSH ops only; bot start/stop is HTTP bots-api |
| `HETRIXTOOLS_API_KEY` + monitor IDs | Optional uptime enrichment |

**Force Caddy mode** (set in unit or `.env` — unit already sets host/port):

```env
API_HOST=127.0.0.1
API_PORT=8080
# Leave API_SSL_CERTFILE / API_SSL_KEYFILE unset
API_FORWARDED_ALLOW_IPS=127.0.0.1
```

Optional health probes:

```env
WEBSOCKET_HEALTH_URL=https://websocket.botofthespecter.com/health
BOTS_HEALTH_URL=https://bots.botofthespecter.com/health
# WEB1_HEALTH_URL=
# SQL_HEALTH_URL=
```

### `ips.txt`

```bash
# scp old:/home/botofthespecter/ips.txt /home/botofthespecter/ips.txt
# Or create empty + comments; one CIDR/IP per line
touch /home/botofthespecter/ips.txt
chown botofthespecter:botofthespecter /home/botofthespecter/ips.txt
chmod 644 /home/botofthespecter/ips.txt
```

### Home-dir JSON (prefer live from old host)

```bash
for f in quotes.json fortunes.json builtin_commands.json killCommand.json ai.json versions.json; do
  # scp old:/home/botofthespecter/$f /home/botofthespecter/$f
  # fallback: cp from repo deploy
  # seeds already live here after flat deploy of repo ./api/
done
chown botofthespecter:botofthespecter /home/botofthespecter/*.json 2>/dev/null || true
```

Touch log so permissions are right:

```bash
touch /home/botofthespecter/log.txt
chown botofthespecter:botofthespecter /home/botofthespecter/log.txt
```

---

## 7. MySQL access from the new IP

On the **SQL** host / security group:

1. Allow inbound **3306** (or your `SQL_PORT`) from the **new API public IP** (and remove the old API IP after cutover).
2. Confirm `SQL_USER` is allowed from that host (`user@'%'` or specific host).

From the new API host:

```bash
# If mysql client installed:
# mysql -h "$SQL_HOST" -P "$SQL_PORT" -u "$SQL_USER" -p -e 'SELECT 1'
sudo -u botofthespecter /home/botofthespecter/venvs/api/bin/python - <<'PY'
import os, asyncio, aiomysql
from dotenv import load_dotenv
load_dotenv("/home/botofthespecter/.env")
async def main():
    c = await aiomysql.connect(
        host=os.environ["SQL_HOST"],
        port=int(os.environ.get("SQL_PORT", "3306")),
        user=os.environ["SQL_USER"],
        password=os.environ["SQL_PASSWORD"],
        db="website",
    )
    async with c.cursor() as cur:
        await cur.execute("SELECT 1")
        print("mysql ok", await cur.fetchone())
    c.close()
asyncio.run(main())
PY
```

Do **not** cut DNS until this works.

---

## 8. systemd unit (`fastapi.service`)

```bash
cp /home/botofthespecter/fastapi.service /etc/systemd/system/fastapi.service
# Confirm paths in the unit match this host (WorkingDirectory, ExecStart, EnvironmentFile)
systemctl daemon-reload
systemctl enable fastapi.service
systemctl start fastapi.service
systemctl status fastapi.service --no-pager
journalctl -u fastapi.service -n 50 --no-pager
```

Local-only health (before Caddy):

```bash
curl -sS http://127.0.0.1:8080/health | jq .
# expect: ok true, service "api", started_at, uptime_seconds
```

If it fails: check `.env` SQL_*, file permissions, and that nothing else binds 8080.

---

## 9. Caddy (TLS + reverse proxy)

### Install (Ubuntu apt)

```bash
apt install -y caddy
caddy version
```

If the distro package is missing or ancient, use [Caddy’s official apt repo](https://caddyserver.com/docs/install#debian-ubuntu-raspbian) then `apt install caddy`.

### Config

```bash
cp /home/botofthespecter/Caddyfile /etc/caddy/Caddyfile
# Ensure log directory exists and is writable by caddy
mkdir -p /var/log/caddy
chown caddy:caddy /var/log/caddy   # if user `caddy` exists; else leave root-owned and adjust Caddyfile log path
```

Tracked Caddyfile expects:

- site: `api.botofthespecter.com`
- `reverse_proxy 127.0.0.1:8080`
- HTTP-01 automatic HTTPS (`email admin@botofthespecter.com` in the global block)

**Important:** Caddy will only issue a cert when the public DNS for `api.botofthespecter.com` points at **this** host (or you test with a temporary name). For pre-cutover testing without moving production DNS, either:

1. **Staging hostname** (recommended): add `api-new.botofthespecter.com` A → new IP, add a second site block (or temporarily change the site name), obtain cert, test, then switch the production name; or  
2. **Hosts-file test** after cutover only (production downtime window).

### Start Caddy (after DNS points here, or using a staging name)

```bash
caddy validate --config /etc/caddy/Caddyfile
systemctl enable caddy
systemctl restart caddy
systemctl status caddy --no-pager
journalctl -u caddy -n 50 --no-pager
```

If port 80 is blocked or DNS is still on the old host, ACME fails — fix DNS/firewall, then `systemctl reload caddy`.

---

## 10. Cloudflare DNS

1. Record: **A** `api` → **new public IP**.
2. Proxy status: **DNS only** (grey cloud). Do not orange-cloud.
3. TTL: 60–300s during cutover if you want a shorter hang window.
4. Do **not** leave both old and new serving the same hostname intentionally (split-brain webhooks).

### Cutover order (mandatory)

1. New host: app healthy on `127.0.0.1:8080`.
2. MySQL allowed from new IP.
3. **Lower TTL** ahead of time if possible.
4. **Stop accepting traffic on old** (stop `fastapi` / old process on old host) **or** accept a short dual-run only if you are sure nothing mutates twice — prefer **stop old first**, then flip DNS.
5. Point Cloudflare A record to **new IP**.
6. Start/reload Caddy on new (cert issue/renew).
7. Verify (section 11).
8. Leave old host up briefly for emergency rollback only; then decommission.

Single cutover — same idea as the bot-host migration: flip once, do not leave two controllers on the same name.

---

## 11. Verification checklist

From your laptop / any external host:

```bash
# Health (public)
curl -sS https://api.botofthespecter.com/health | jq .

# Docs SPA
curl -sS -o /dev/null -w "%{http_code}\n" https://api.botofthespecter.com/docs
curl -sS -o /dev/null -w "%{http_code}\n" https://api.botofthespecter.com/v2/docs
curl -sS -o /dev/null -w "%{http_code}\n" https://api.botofthespecter.com/v2/openapi.json

# Auth route (use a real user key, do not commit it)
curl -sS -H "X-API-KEY: YOUR_USER_KEY" https://api.botofthespecter.com/v2/account | jq .

# Bot proxy (user key)
curl -sS -H "X-API-KEY: YOUR_USER_KEY" "https://api.botofthespecter.com/v2/bot/status" | head

# Uptime must not be world-403 in the new design
curl -sS -o /dev/null -w "%{http_code}\n" https://api.botofthespecter.com/system/uptime
```

Also:

- [ ] Dashboard loads (calls API with user keys).
- [ ] Webhooks: fire a test Ko-fi/Patreon/etc. if you have a sandbox, or watch `journalctl -u fastapi` during a real event.
- [ ] Admin dashboard: `fastapi.service` status for the **API SSH host** (update SSH host IP in production `/var/www/config/ssh.php` on the web host).
- [ ] `BOTS_API_BASE` reachable from new host: `curl -sS https://bots.botofthespecter.com/health`.

---

## 12. Post-cutover housekeeping

1. **Web host** production config: update `api_server_host` (and any hardcoded old API IP) in `/var/www/config/ssh.php` (and related admin maps).
2. **SQL firewall**: drop old API IP after a day of clean runs.
3. **Old API host**: stop and disable `fastapi.service` (or legacy process); keep disk snapshot until you are sure.
4. **Hetrix / monitoring**: point HTTP checks at the same hostname (auto) or new IP.
5. Confirm **no** leftover A/AAAA records for old IP on `api`.

---

## 13. Rollback

If the new host is bad **and** DNS TTL is short:

1. Cloudflare A → **old IP**.
2. Start old API again (legacy in-process TLS on 443 if that is what old still has).
3. Fix new host offline.

If DNS is already long-TTL and old is decommissioned, restore from snapshot / re-point and fix forward.

Emergency on **new** host without Caddy (not preferred):

```bash
# only if you must
# set API_HOST=0.0.0.0 API_PORT=443 + API_SSL_CERTFILE/KEYFILE to LE paths
# and free 443 from Caddy first
```

---

## 14. Quick reference — file map

| Role | Path on server | Source in repo |
| ---- | -------------- | -------------- |
| App | `/home/botofthespecter/api.py` | `./api/api.py` |
| Docs UI | `/home/botofthespecter/docs_ui/` | `./api/docs_ui/` |
| Deps | `/home/botofthespecter/requirements.txt` | `./api/requirements.txt` |
| Unit | `/etc/systemd/system/fastapi.service` | `./api/fastapi.service` |
| Caddy | `/etc/caddy/Caddyfile` | `./api/Caddyfile` |
| Env | `/home/botofthespecter/.env` | **server only** |
| Whitelist | `/home/botofthespecter/ips.txt` | server only |
| JSON data | `/home/botofthespecter/*.json` | live copy or `./api/*.json` seeds |
| App log | `/home/botofthespecter/log.txt` | created at runtime |
| Caddy log | `/var/log/caddy/api.log` | Caddyfile |

---

## 15. Common failures

| Symptom | Likely cause |
| ------- | ------------ |
| `Missing required database environment variables` | `.env` missing or wrong path |
| MySQL timeout / access denied | New IP not allowed on SQL host |
| Caddy ACME errors | DNS still on old IP, orange-cloud CF, or port 80 closed |
| `curl https://…/health` fails, loopback works | Caddy down or wrong reverse_proxy |
| 502 from Caddy | App not listening on 8080 |
| Docs 404 | `docs_ui/` not deployed next to `api.py` |
| Bot start/stop fails | `BOTS_API_BASE` / bots admin key / network to bots host |
| Admin cannot control service | SSH still points at old API host, or unit not named `fastapi.service` |

---

## 16. Order summary (print this)

1. Provision Ubuntu → user → firewall 22/80/443.  
2. Deploy `./api` → venv → pip install.  
3. Copy `.env`, `ips.txt`, JSON from old host.  
4. Allow MySQL from new IP → verify.  
5. `systemctl enable --now fastapi` → `curl 127.0.0.1:8080/health`.  
6. Install Caddy + Caddyfile.  
7. Stop old API → DNS A to new IP (grey cloud) → start/reload Caddy.  
8. Public `/health`, `/docs`, keyed routes, webhooks.  
9. Update web-host SSH config; decommission old after soak.

When you have the **new host IP** and SSH access ready, we can walk this live section-by-section and tick each verify step.
