# Admin Caddy Control Page — Design

**Date:** 2026-06-24
**Status:** Approved (design); not yet planned/implemented
**Surface:** Dashboard admin panel (`dashboard/admin/`)

## Summary

A new admin page (`dashboard/admin/caddy.php`) that lets admins observe and
(for super admins) control the Caddy web server through Caddy's
[admin API](https://caddyserver.com/docs/api) (`localhost:2019`).

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

### File & placement

- New file: `dashboard/admin/caddy.php`.
- Structure follows `dashboard/admin/migrations.php`:
  1. `require_once '/var/www/lib/session_bootstrap.php';`
  2. `require_once __DIR__ . '/admin_access.php';` (enforces `is_admin`)
  3. load i18n, `db_connect.php`, `config/ssh.php`
  4. handle POST `action=…` AJAX requests → return JSON, `exit`
  5. set `$pageTitle`, build page content
  6. `include_once __DIR__ . '/../layout.php';`
- The same file serves the page (GET) and its AJAX actions (POST), exactly like
  `admin/index.php`.

### Menu registration

Add one entry to the `$admin` array in `dashboard/menu.php` (currently lines
82–104), in the infrastructure group near `terminal.php`:

```php
[ 'label' => t('menu_admin_caddy'), 'icon' => 'fas fa-server', 'href' => 'caddy.php' ],
```

## Role model

`admin_access.php` already guarantees the visitor is `is_admin`. The page then
loads the super-admin flag using the same query `users.php` uses:

```php
$isSuperAdmin = false;
if (($uid = (int)($_SESSION['user_id'] ?? 0)) > 0) {
    $stmt = $conn->prepare("SELECT super_admin FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $stmt->bind_result($saFlag);
    if ($stmt->fetch()) { $isSuperAdmin = ((int)$saFlag === 1); }
    $stmt->close();
}
```

- Normal admin → control UI hidden/disabled; read-only panels only.
- Super admin → full control UI shown.
- **Server-side enforcement is authoritative.** Every mutating AJAX action
  re-checks `$isSuperAdmin` before doing anything and returns
  `403 {"success":false,"error":...}` if false. The UI disabling is cosmetic —
  never trusted for authorization.

## Transport

### Caddy admin API (reads + API writes) — direct, no SSH

The dashboard PHP runs on the web host alongside Caddy, so it curls
`http://localhost:2019` directly. A single helper centralises this:

```php
function caddy_admin_request($method, $path, $body = null, $contentType = 'application/json')
```

- **Path allowlist** — only these prefixes are permitted:
  `/config`, `/id`, `/adapt`, `/load`, `/reverse_proxy`, `/pki`.
- `/stop` is **rejected at this layer** even if explicitly requested.
- Reasonable curl timeout; structured error return (`['ok'=>bool, 'status'=>int,
  'body'=>..., 'error'=>...]`).
- Reads (`GET`) are allowed for any admin; mutating methods require
  `$isSuperAdmin` (checked by the caller before invoking the helper).

### reload / restart — existing SSH infrastructure

Reuse `SSHConnectionManager` against the web host and run:

- `sudo -n systemctl reload caddy`
- `sudo -n systemctl restart caddy`

This mirrors `admin/index.php`'s service-control handler (`sudo -n systemctl
$action $service`). Requires web-host SSH variables in `config/ssh.php`:

```php
// Web Server Information
$web_ssh_host = '';
$web_ssh_username = $server_username;
$web_ssh_password = $server_password;
```

Add the blank placeholders to the dev stub (`config/ssh.php`); production
(`/var/www/config/ssh.php`) already has SSH access to all servers. If
`$web_ssh_host` is empty, the reload/restart buttons render disabled with a
"web host SSH not configured" note (graceful degradation, same spirit as the
existing SSH failure handling).

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

- **`/stop` excluded everywhere** — request-layer rejection in
  `caddy_admin_request()`; not offered in any UI.
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

## Verification

- Read-only panels render for a normal admin; control UI absent.
- A normal admin POSTing a mutating `action` receives 403 (server-side check),
  not just a hidden button.
- Super admin can: view redacted config; run a `GET` via console; run a scoped
  `PATCH`; load a Caddyfile (adapt → load); reload; restart (with confirm).
- `/stop` is refused both in UI and at the request layer.
- The CF API token never appears in any browser response or audit row.
- `admin_audit_log` rows exist for each mutating action.
- `php -l` passes on `caddy.php`; `de.php`/`fr.php` parse (no apostrophe-escape
  breakage).

## Files touched

- `dashboard/admin/caddy.php` (new)
- `dashboard/menu.php` (one `$admin` entry)
- `config/ssh.php` (web-host SSH placeholders)
- `dashboard/lang/en.php`, `de.php`, `fr.php` (new `t()` keys)
- Possibly `dashboard/css/*` only if an existing `sp-*` class doesn't cover a
  needed element (prefer reuse).
