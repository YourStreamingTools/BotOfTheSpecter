# SQL Data API — Tenant-Scoped MySQL Gateway

SpecterBotApp (and similar multi-tenant PHP) must **not** hold shared MySQL credentials. Data access goes through the SQL data API with the **user's** API key.

## Control plane

| Piece | Location |
| ----- | -------- |
| Service code | `./sql_api/` (`server.py`, `db.py`, `query_builder.py`, `sql-api.service`) |
| Public URL | `https://sql.botofthespecter.com` (Caddy → `127.0.0.1:8091`) |
| PHP client | `./specterbotapp/sql_api_client.php` |
| PHP bootstrap | `./specterbotapp/database.php` |
| PHP config | `./config/sql_api.php` → production `/var/www/config/sql_api.php` |
| Per-tenant keys | `/var/www/specterbotapp/keys/{username}.key` (one line = `users.api_key`; **not** web-served) |

## Auth

- **User keys** (`website.users.api_key`) via header `X-API-KEY` — tenant is always that user.
- **Admin** keys with `service = sql` or `admin` may pass `channel=` / `X-Channel` to act as a streamer (support only).
- User keys **cannot** open another streamer's DB via Host spoofing or `channel=`.

## Scopes

| Scope | Database |
| ----- | -------- |
| `user` | `{username}` |
| `modules` | `{username}_custom_modules` |

## Rules

1. **SpecterBotApp modules use `sql_api_*` helpers**, not ad-hoc mysqli with shared passwords.
2. **Never put MySQL passwords** in SpecterBotApp code or per-user module folders.
3. **Key files** live only under `keys_dir`; Apache/Caddy must deny HTTP access to that directory.
4. **No raw SQL** from clients — structured filters only (see `sql_api/query_builder.py`).
5. **`allow_legacy_mysql`** in `sql_api.php` is migration-only. Set `false` after modules are converted and firewall MySQL off the SpecterBotApp host.
6. **PHP never reads `.env`** for this — use `./config/sql_api.php` ([php-config.md](./php-config.md)).
7. Main product API (`api.botofthespecter.com`), bot, dashboard may keep direct MySQL until separately migrated — this gateway is specifically for multi-tenant untrusted PHP (SpecterBotApp).

## Deploy reminder

See `./sql_api/README.md`. systemd unit binds `127.0.0.1:8091`; Caddy terminates TLS.
