---
name: sql-new-host
description: Specter SQL host at 150.107.75.252 — data restored 2026-09-11; chat bots still need a restart to drop old pooled connections.
metadata:
  node_type: memory
  type: project
---

Production SQL is **`150.107.75.252`** (`ssh sql-new`). The previous box **`194.195.122.234`** (`ssh sql`) still has a copy of the data but apps are pointed at the new IP.

## Cutover (2026-09-11)

- 88 application databases dumped from the old host and restored (including per-user DBs). Counts matched: `website.users` 79, website 31 tables, `gfaundead` 118 tables.
- MySQL 8.4 rejected a legacy FK (`moderator_access.broadcaster_id` → `users.twitch_user_id` with no unique key). Foreign keys were stripped on restore; data is intact.
- App user `specter@%` unchanged.
- `SQL_HOST` / `SQL-HOST` on bots, API, websocket (and local `.env` copies) set to `150.107.75.252`. New SQL box stays `SQL_HOST=127.0.0.1`.
- Web PHP `/var/www/config/database.php` and `db_connect.php` use the new IP. `ssh.php` `$sql_server_host` updated.
- fastapi and websocket restarted. **Chat bots were not restarted** (operator doing that).
- sql-api is up on `127.0.0.1:8091`. Caddy has a cert for `sql.botofthespecter.com`. Status-monitor cron is enabled.

## Still on the operator

Restart chat bots (and Discord/Kick if they hold MySQL pools) so they drop connections to `194.195.122.234`.
