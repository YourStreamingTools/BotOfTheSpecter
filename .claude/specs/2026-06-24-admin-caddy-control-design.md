# Admin Caddy Control Page — Design

**Date:** 2026-06-24
**Status:** Approved (design); not yet planned/implemented
**Surface:** Dashboard admin panel (`dashboard/admin/`)

## Summary

I want a new admin page (`dashboard/admin/caddy.php`) that lets admins observe and
(for super admins) control the Caddy web server through Caddy's
[admin API](https://caddyserver.com/docs/api) on `localhost:2019`.

- **Normal admins (`is_admin`)** get **read-only** monitoring.
- **Super admins (`super_admin`)** get **full control** — arbitrary config
  changes via a raw API console, a Load-config tab, and reload/restart.

Caddy runs **only on the web host**, as the origin web server for all 13
surfaces (the `web/Caddyfile`). It is not a reverse proxy in front of the API,
WebSocket, or stream servers — those face the internet directly (Cloudflare is
DNS-only). So this page manages exactly one Caddy instance: the web host's.

## Goals

1. Give admins an at-a-glance view of Caddy's health and configured sites.
2. Give super admins true full control over the running Caddy config, with
   guard rails on the one irrecoverable action.
3. Reuse existing dashboard patterns (admin gate, audit log, SSH manager, menu,
   layout, i18n, theme CSS) rather than inventing new mechanisms.

## Non-goals (YAGNI for v1)

- Per-certificate expiry dashboard. The admin API has no clean cert-listing
  endpoint; it would require parsing CertMagic storage. Out of scope.
- Managing the other servers (API/WS/stream) — they run no Caddy.
- A visual/point-and-click config builder. The raw console + Load tab already
  provide full control.
- Exposing `POST /stop` (see Guard rails).

## Architecture

### File and placement

The new file is `dashboard/admin/caddy.php`, and it follows the same shape as
`dashboard/admin/migrations.php`. It bootstraps the session, then includes
`admin_access.php` (which enforces `is_admin`), then loads i18n, the DB
connection, and the SSH config. POST requests carrying an `action=…` parameter
are handled as AJAX, returning JSON and exiting early; a plain GET renders the
page by setting the page title, building the content, and including the shared
`layout.php`. In other words, the same file serves both the page and its AJAX
actions, exactly the way `admin/index.php` already does.

### Menu registration

Add one entry to the `$admin` array in `dashboard/menu.php`, in the
infrastructure group near the `terminal.php` entry. The new item points at
`caddy.php`, uses a server icon, and labels itself via the `menu_admin_caddy`
translation key.

## Role model

`admin_access.php` already guarantees the visitor is `is_admin`. On top of that,
the page loads the super-admin flag using the same approach `users.php` uses: a
prepared `SELECT super_admin FROM users WHERE id = ?` keyed on the session's
`user_id`, treating a value of `1` as super admin.

- Normal admin → control UI hidden/disabled; read-only panels only.
- Super admin → full control UI shown.
- **Server-side enforcement is authoritative.** Every mutating AJAX action
  re-checks the super-admin flag before doing anything and returns a
  `403` JSON error (`{"success":false,"error":…}`) if it's false. The UI
  disabling is cosmetic — never trusted for authorization.

## Transport

### Caddy admin API (reads + API writes) — direct, no SSH

The dashboard PHP runs on the web host alongside Caddy, so it can curl
`http://localhost:2019` directly. A single helper centralises this — a function
that takes a method, a path, an optional body, and a content type, and returns a
structured result (an `ok` flag, the HTTP status, the body, and any error
string). Its design rules:

- **Path allowlist** — only these prefixes are permitted:
  `/config`, `/id`, `/adapt`, `/load`, `/reverse_proxy`, `/pki`.
- `/stop` is **rejected at this layer** even if explicitly requested.
- A reasonable curl timeout, with the structured error return described above.
- Reads (`GET`) are allowed for any admin; mutating methods require the
  super-admin flag, which the caller checks before invoking the helper.

### reload / restart — existing SSH infrastructure

Reuse `SSHConnectionManager` against the web host and run
`sudo -n systemctl reload caddy` or `sudo -n systemctl restart caddy`. This
mirrors the service-control handler in `admin/index.php`, which runs
`sudo -n systemctl $action $service`.

That path needs web-host SSH credentials in `config/ssh.php` — a web SSH host,
plus username and password reusing the shared server credentials. Production
(`/var/www/config/ssh.php`) already has SSH access to every server; the only
change needed is adding blank placeholders for the web host to the dev stub
(`config/ssh.php`). If the web SSH host is empty, the reload/restart buttons
render disabled with a "web host SSH not configured" note — graceful
degradation in the same spirit as the existing SSH failure handling.

> Note: a `restart` only helps when Caddy is **running-but-wedged**. A fully
> dead Caddy also takes down the dashboard (Caddy serves it), so that case is
> only recoverable from the server CLI. This is acceptable and documented in the
> UI.

## Read-only panels (all admins)

1. **Status** — `GET /config/` returning 200 ⇒ "Running". Caddy version is
   best-effort via SSH `caddy version` (omitted/"unknown" if SSH unavailable).
2. **Sites / hosts table** — parsed from `GET /config/apps/http/servers`:
   each server → listen addresses → host matchers → handler type(s). Gives a
   single overview of all configured surfaces.
3. **Reverse-proxy upstreams** — `GET /reverse_proxy/upstreams`. Renders
   "none configured" gracefully (the current Caddyfile defines no upstreams).
4. **TLS / ACME** — issuer and DNS-challenge provider summarised from config,
   with the Cloudflare API token **redacted**.
5. **Redacted full-config viewer** — `GET /config/` piped through a key-based
   redactor (strips values whose key contains `token`, `secret`, `password`,
   `key`, `api_token`, `oauth`, etc.), pretty-printed, collapsible.

## Super-admin control

1. **Raw API console** — method dropdown (`GET/POST/PUT/PATCH/DELETE`), path
   input (prefilled `/config/`), JSON body textarea, Send. Destructive methods
   (`POST/PUT/PATCH/DELETE`, and any `/load`) require a typed confirmation.
   Responses are passed through the same redactor before display.
2. **Load config tab** — a large textarea with an `application/json` ⇄
   `text/caddyfile` toggle. For `text/caddyfile`, the input is validated via
   `POST /adapt` first; on success it is applied via `POST /load`.
3. **Reload from on-disk Caddyfile** — SSH `sudo -n systemctl reload caddy`.
4. **Restart service** — SSH `sudo -n systemctl restart caddy`; typed
   confirmation required.

## Guard rails

- **`/stop` excluded everywhere** — request-layer rejection in the Caddy admin
  helper; not offered in any UI.
- **Secret redaction** applied to every config payload before it reaches the
  browser or the audit log (the `GET /config/` response contains the resolved
  `CF_API_TOKEN`).
- **`admin_audit_log()`** called on every mutating action with action name,
  target (method + path), redacted body/result. The existing audit function
  already redacts sensitive keys as a second layer.
- **Config-drift notice** in the control section: API writes diverge from the
  on-disk `Caddyfile` until a reload. The on-disk Caddyfile is the recovery
  anchor — "Reload from on-disk Caddyfile" re-applies known-good config while
  Caddy is still running, which is the undo path for a bad API edit.

## Conventions

- **i18n:** all new strings via `t()`; keys added to `dashboard/lang/en.php`
  (base) **and** `de.php` + `fr.php`. New keys include at least:
  `menu_admin_caddy`, plus page title, panel headings, button labels,
  confirmation prompts, and error/status messages.
- **CSS:** use the dashboard stylesheet and theme tokens (the `sp-*` component
  classes already used by other admin pages); no page `<style>` blocks or inline
  styles for component styling.

## Caddy admin API reference (used here)

| Endpoint | Method | Use | Who |
| --- | --- | --- | --- |
| `/config/[path]` | GET | read config / status / sites / TLS | any admin |
| `/reverse_proxy/upstreams` | GET | upstream health | any admin |
| `/pki/ca/<id>` | GET | (available; not core to v1) | any admin |
| `/adapt` | POST | validate a Caddyfile before load | super admin |
| `/load` | POST | replace full config | super admin |
| `/config/[path]` | POST/PUT/PATCH/DELETE | scoped edits (raw console) | super admin |
| `/stop` | POST | **blocked** | nobody |

## Error handling

- Caddy API unreachable (curl fails / connection refused) → Status panel shows
  "Stopped / unreachable"; control actions return a clear JSON error.
- SSH failures for reload/restart reuse the existing manager's error semantics
  (timeout, auth failure) and surface a friendly message.
- Invalid JSON in the raw console / Load tab → 400-style JSON error before any
  request is sent to Caddy.

## How we know it's right

The behaviour to confirm once this is built:

- A normal admin sees the read-only panels and no control UI.
- A normal admin who POSTs a mutating `action` anyway gets a `403` from the
  server-side super-admin re-check — the security does not depend on the hidden
  button.
- A super admin can view the redacted config, run a `GET` through the console,
  run a scoped `PATCH`, load a Caddyfile (adapt → load), reload, and restart
  (behind the typed confirmation).
- `/stop` is refused both in the UI and at the request layer.
- The Cloudflare API token never appears in any browser response or audit row.
- An `admin_audit_log` row is written for every mutating action.

Each touched file should also pass its language's syntax check, and the German
and French language files in particular need their apostrophes escaped correctly
so they still parse.

## Files touched

- `dashboard/admin/caddy.php` (new)
- `dashboard/menu.php` (one `$admin` entry)
- `config/ssh.php` (web-host SSH placeholders)
- `dashboard/lang/en.php`, `de.php`, `fr.php` (new `t()` keys)
- Possibly `dashboard/css/*` only if an existing `sp-*` class doesn't cover a
  needed element (prefer reuse).
