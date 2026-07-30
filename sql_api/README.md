# SQL data API (tenant-scoped)

Private FastAPI that runs **on the SQL server** and is the only MySQL path for SpecterBotApp (and similar multi-tenant PHP).

Clients authenticate with a **user** API key (`website.users.api_key`). The key determines which database may be opened. SpecterBotApp never holds MySQL passwords and cannot open another streamer's schema.

## Why this exists

Old SpecterBotApp used `database.php` + shared MySQL credentials and picked the DB from the HTTP Host subdomain. That allowed cross-tenant abuse if credentials or include paths leaked.

**Fix:** no MySQL on the SpecterBotApp host for module pages — only HTTPS to this API with the streamer's user key.

## Auth

| Key type | Header | Tenant |
|----------|--------|--------|
| User key (`website.users.api_key`) | `X-API-KEY` | Always that user |
| Admin `service=sql` or `admin` | `X-API-KEY` | Must pass `channel=` or `X-Channel` |

User keys **cannot** pass a different `channel`.

## Scopes

| Scope | MySQL database |
|-------|----------------|
| `user` | `{username}` |
| `modules` | `{username}_custom_modules` |

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | Liveness (no auth) |
| GET | `/api/v1/me` | Who am I + which DBs exist |
| GET | `/api/v1/{scope}/tables` | List tables |
| GET | `/api/v1/{scope}/rows?table=&…` | Select (optional single filter) |
| POST | `/api/v1/{scope}/query` | Select with multi-filter JSON body |
| POST | `/api/v1/{scope}/rows` | Insert |
| PATCH | `/api/v1/{scope}/rows` | Update (filters required) |
| DELETE | `/api/v1/{scope}/rows` | Delete (filters required) |
| POST | `/api/v1/modules/ensure` | Create modules DB if missing |

No raw SQL from clients. Filters are structured (`column` / `op` / `value`); identifiers are validated.

### Filter ops

`eq`, `ne`, `lt`, `lte`, `gt`, `gte`, `like`, `in`, `is_null`, `is_not_null`

### Example (select)

```bash
curl -sS -H "X-API-KEY: $USER_API_KEY" \
  "https://sql.botofthespecter.com/api/v1/user/rows?table=custom_commands&limit=20"
```

### Example (insert)

```bash
curl -sS -X POST -H "X-API-KEY: $USER_API_KEY" -H "Content-Type: application/json" \
  -d '{"table":"custom_command_random_pick_options","data":{"command":"snack","many_options_enabled":1,"options":"[]"}}' \
  "https://sql.botofthespecter.com/api/v1/user/rows"
```

### Example (update with filter)

```bash
curl -sS -X PATCH -H "X-API-KEY: $USER_API_KEY" -H "Content-Type: application/json" \
  -d '{"table":"custom_command_random_pick_options","data":{"options":"[\"chips\"]"},"filters":[{"column":"command","op":"eq","value":"snack"}]}' \
  "https://sql.botofthespecter.com/api/v1/user/rows"
```

## Deploy (SQL host)

```bash
# Code path (example)
# /home/botofthespecter/sql_api/

python3 -m venv /home/botofthespecter/venvs/sql_api
/home/botofthespecter/venvs/sql_api/bin/pip install -r /home/botofthespecter/sql_api/requirements.txt

# .env on SQL host:
#   SQL_HOST=127.0.0.1
#   SQL_USER=...
#   SQL_PASSWORD=...
#   SQL_PORT=3306
#   SQL_API_HOST=127.0.0.1
#   SQL_API_PORT=8091
# Optional: SQL_ADMIN_SERVICE=sql

sudo cp sql_api/sql-api.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now sql-api

# Caddy
sudo cp sql_api/Caddyfile /etc/caddy/Caddyfile
sudo systemctl reload caddy
```

DNS: `sql.botofthespecter.com` → SQL host (grey-cloud for HTTP-01, same pattern as bots).

## SpecterBotApp integration

1. **Config** (PHP never reads `.env`): `./config/sql_api.php` → production `/var/www/config/sql_api.php`
2. **Client**: `./specterbotapp/sql_api_client.php`
3. **Per-subdomain user key** (server-only file, not web-served):  
   `/var/www/specterbotapp/keys/{username}.key` containing the streamer's `users.api_key`
4. **Bootstrap**: `./specterbotapp/database.php` loads the key + client helpers

```bash
# Example: provision key for gfaundead (server only)
mkdir -p /var/www/specterbotapp/keys
echo -n 'THEIR_USERS_API_KEY' > /var/www/specterbotapp/keys/gfaundead.key
chmod 640 /var/www/specterbotapp/keys/gfaundead.key
chown www-data:www-data /var/www/specterbotapp/keys/gfaundead.key
```

Migrate modules from `$conn->prepare(...)` to `sql_api_select` / `sql_api_insert` / `sql_api_update` / `sql_api_delete`.

**Migration flag:** `allow_legacy_mysql` in `sql_api.php` (default `true` in repo template) still allows old mysqli when no key file is present. Set to **`false`** after modules are converted and remove MySQL grants from the SpecterBotApp host.

## Security

1. Bind the app to `127.0.0.1` only; TLS at Caddy.
2. Do not put MySQL passwords on the SpecterBotApp host.
3. Prefer firewall: SpecterBotApp host may reach `sql.botofthespecter.com:443` only — not MySQL `3306`.
4. Trusted backends (main API, bot, dashboard) may keep direct MySQL until you choose to move them.
5. Full-table UPDATE/DELETE without filters are rejected by the API.

## Relation to other services

| Service | Role |
|---------|------|
| `api.botofthespecter.com` | Product API, webhooks, bot proxy |
| `bots.botofthespecter.com` | Process control (admin key `bots`) |
| `sql.botofthespecter.com` | Tenant data plane (user keys) for SpecterBotApp |
