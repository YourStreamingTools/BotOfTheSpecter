# PHP to React — Implementation Plan

**Date:** 2026-08-26
**Spec:** `.grok/specs/2026-08-26-php-to-react-design.md`

Strangler migration of the streamer dashboard to React, plus moving the operator panel to `admin.botofthespecter.com` through the existing SSO issuer. Overlays, SpecterBotApp tenant PHP, YourLinks redirects, StreamersConnect, and Python services stay where they are.

## Sequence

1. **Admin host split (PHP).** Exact-host Caddy block for `admin.botofthespecter.com` **before** DNS. SSO target `admin` on `./home/sso.php` (mint only if `is_admin`). Admin `login.php` consumes handoff like support. 308 `dashboard…/admin/*` → admin host (strip `/admin`). Dashboard “Admin panel” → SSO URL (new tab is fine). Admin chrome links back to the user dashboard. Same admin features as today, including pick-user impersonation (act-as applies on `dashboard.`, admin tools always run as the admin). Serve CSS/JS on the admin host; do not hotlink `dashboard.` stylesheets. Keep PHP files on disk under `./dashboard/admin/` so includes still resolve.

2. **Dashboard React foundations.** Vite + React + TypeScript in `./dashboard-ui/`. Build to a staging dir, copy hashed assets in. `GET /api/session.php` and `GET /api/i18n.php`. Same-origin cookie client. No Node runtime on web1.

3. **First island.** One read-mostly body inside existing `layout.php` (logged-in `dashboard.php` or logs). Feature flag + `?legacy=1`.

4. **Bot page.** Still `bot_action.php` (never expose the bots control key to the browser).

5. **Commands / points / rewards.** Extract JSON save endpoints from self-POSTing PHP pages first, then React forms.

6. **Media / uploads.** `FormData` to existing PHP upload helpers.

7. **Alerts and overlay config on the dashboard.** Overlay PHP on `overlay.botofthespecter.com` unchanged.

8. **Streamer React chrome.** Shared menu source. Act-as banner stays on dashboard.

9. **Admin React** on the admin host once the PHP split is boring. Terminal, Caddy control, migrations can remain PHP.

10. **Optional** Caddy `file_server` for `/app/assets/` on each host that needs hashed assets.

11. **Other portals** (members / support / roadmap / home) only as separate apps later.

## Live box

Caddy live file is `/etc/caddy/Caddyfile`. Backup, copy from `./web/Caddyfile`, validate with `caddy.env` sourced, restart if env changed. Do not add the `admin.` DNS record until the exact-host block is up (wildcard catch-all is `/var/www/html`). No git pull. Config stays skip-worktree.

**Cutover:** never replace a public entrypoint (`index.php`, `tickets.php`, …) while the React app or its APIs are still being written. Build and verify on a side path (`/app/`, new `/api/*` that nothing public uses yet), then swap the live file in one step. Support was taken down on 2026-08-26 by hanging SPA shells on those PHP names before the bundle existed.

## Rollback

- Admin split: remove the dashboard `/admin` redirect; old PHP still on disk.
- React island: drop the page from the React flag list or restore the previous PHP body. No Caddy restart.
