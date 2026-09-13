---
name: sql-host
description: Production Specter SQL host is 150.107.75.252. There is no backup SQL server.
metadata:
  node_type: memory
  type: project
---

Production SQL is **`150.107.75.252`** (`ssh sql`), private **`10.240.0.7`**. There is **no** second/backup SQL box. The previous address `194.195.122.234` is gone.

## Host

- Ubuntu 26.04 LTS, hostname `sql`, timezone Australia/Sydney
- 4096 MB RAM, 50 GB disk
- MySQL **8.4.11** on `0.0.0.0:3306` (`skip-name-resolve`, utf8mb4, `max_allowed_packet=64M`)
- App user `specter@%` (`caching_sha2_password`)
- Local services use `SQL_HOST=127.0.0.1`; other hosts use `150.107.75.252`
- sql-api on `127.0.0.1:8091`; Caddy TLS for `sql.botofthespecter.com` (themed docs at `/` and `/docs`)

## Cutover notes (2026-09-11)

- 88 application databases restored here (including per-user DBs).
- MySQL 8.4 rejected a legacy FK (`moderator_access.broadcaster_id` → `users.twitch_user_id` with no unique key). Foreign keys were stripped on restore; data is intact.
