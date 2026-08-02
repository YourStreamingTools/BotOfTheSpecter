---
name: admin-caddy-page
description: "Admin Caddy control page (dashboard/admin/caddy.php) — manage the web-host Caddy via its admin API; built 2026-06-25, SHIPPED."
metadata: 
  node_type: memory
  type: project
  originSessionId: d99dd68e-3f11-4ffe-ad72-953c73dd7003
---

New admin page `dashboard/admin/caddy.php` reports on and controls the web-host
Caddy server through Caddy's admin API. Built 2026-06-25 (**SHIPPED**).

**Key facts / gotchas:**
- Caddy is the web-host **origin** web server (`web/Caddyfile`, serves all 13
  surfaces), not a proxy — consistent with [[network-architecture]]. Its admin
  API is on the default `localhost:2019`. The dashboard PHP runs on the same
  host, so reads/writes go **direct via curl to localhost:2019, no SSH**.
- **Role split:** `is_admin` = read-only; `super_admin` = full control. Every
  mutating AJAX action **re-checks `$isSuperAdmin` server-side (403)** — UI
  hiding is cosmetic. `super_admin` is the existing `users.super_admin` column
  (same query `admin/users.php` uses).
- **`/stop` is blocked** at the request layer (allowlist in
  `caddy_admin_request()`) AND absent from the UI — stopping Caddy kills the
  dashboard too.
- **Secret redaction is mandatory:** `GET /config/` returns the resolved
  `CF_API_TOKEN`. `caddy_redact_secrets()` strips it before any config reaches
  the browser or audit log.
- **reload/restart** use `SSHConnectionManager` → `sudo -n systemctl
  reload|restart caddy` on `$web_ssh_host` (NEW vars added to `config/ssh.php`;
  prod must populate them — dev stub is blank, buttons disable gracefully).
- Pure helpers live in `dashboard/includes/caddy_admin.php`
  (`caddy_redact_secrets`, `caddy_path_allowed`, `caddy_admin_request`,
  `caddy_parse_sites`, `caddy_summarize_tls`).
- Tests in `dashboard/tests/caddy_*_test.php` are **gitignored** (`.gitignore`
  `*test*`, like `bot/tests/`) — run with `php`, never committed.
- 48 `t()` keys added to en/de/fr; menu entry in `dashboard/menu.php` `$admin`.
- Spec: `.grok/specs/2026-06-24-admin-caddy-control-design.md`.
