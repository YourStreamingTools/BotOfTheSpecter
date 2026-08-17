# SQL data API (tenant-scoped)

HTTPS data API for SpecterBotApp modules and similar clients.

**Public URL:** `https://sql.botofthespecter.com`

Authenticate with the streamer's user API key in the `X-API-KEY` header. Each key can only access that account's data.

## Why this exists

Modules load their data through this HTTPS API with the streamer's key. They should not hold shared database passwords.

## Scopes

| Scope | Data |
|-------|------|
| `user` | That streamer's channel data |
| `modules` | That streamer's custom-module data |

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | Liveness (no auth) |
| GET | `/api/v1/me` | Who am I + which scopes exist |
| GET | `/api/v1/{scope}/tables` | List tables |
| GET | `/api/v1/{scope}/rows?table=&…` | Select (optional single filter) |
| POST | `/api/v1/{scope}/query` | Select with multi-filter JSON body |
| POST | `/api/v1/{scope}/rows` | Insert |
| PATCH | `/api/v1/{scope}/rows` | Update (filters required) |
| DELETE | `/api/v1/{scope}/rows` | Delete (filters required) |
| POST | `/api/v1/modules/ensure` | Create modules storage if missing |

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

## SpecterBotApp integration

- PHP config: `./config/sql_api.php` (never read `.env` from PHP)
- Client: `./specterbotapp/sql_api_client.php`
- Keys are stored server-side and must never be web-served
- Bootstrap: `./specterbotapp/database.php` loads the key + client helpers

Migrate modules from ad-hoc SQL to `sql_api_select` / `sql_api_insert` / `sql_api_update` / `sql_api_delete`.

## Security

1. Do not put database passwords in SpecterBotApp code or per-user module folders.
2. Full-table UPDATE/DELETE without filters are rejected by the API.

Operator host layout, admin impersonation, and migrate-off-legacy flags are not documented here.
