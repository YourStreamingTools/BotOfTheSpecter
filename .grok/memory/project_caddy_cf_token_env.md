---
name: caddy-cf-token-env
description: "Caddy's Cloudflare DNS-01 token lives in /etc/caddy/caddy.env; changing it needs `systemctl restart caddy` (NOT reload) or you get CF 403 \"Invalid access token\"."
metadata: 
  node_type: memory
  type: project
  originSessionId: d99dd68e-3f11-4ffe-ad72-953c73dd7003
---

The web-host Caddy (web1, the origin web server — see [[network-architecture]],
[[admin-caddy-page]]) issues all `*.botofthespecter.com` certs via Cloudflare
**DNS-01** (`acme_dns cloudflare {env.CF_API_TOKEN}` in `web/Caddyfile`).

**Where the token lives (set up 2026-06-26):**
- `/etc/caddy/caddy.env` → `CF_API_TOKEN=<scoped token>` (no quotes, no braces —
  Caddy rejects a wrapped value).
- Wired via a systemd drop-in `/etc/systemd/system/caddy.service.d/override.conf`
  with `EnvironmentFile=/etc/caddy/caddy.env` (created via `systemctl edit caddy`).
- The token is NOT in `/home/botofthespecter/.env`; it's Caddy-only here.
- Must be a Cloudflare **scoped API Token** with **Zone → DNS → Edit** on the
  `botofthespecter.com` zone — NOT the Global API Key. A bad/empty/wrong-kind
  token shows as CF `HTTP 403: Code 9109 "Invalid access token"`.

**THE GOTCHA (cost us a dashboard outage):** `{env.CF_API_TOKEN}` is resolved
from the **running process's** environment, which systemd only loads at
`ExecStart`. So after editing `/etc/caddy/caddy.env` you MUST
`sudo systemctl restart caddy` — `systemctl reload caddy` keeps the *old* token
and the new value silently has no effect. The admin Caddy page's "Reload"
button (runs `systemctl reload`) likewise won't pick up a token change.
- Reload IS fine for Caddyfile/config changes (cached certs keep serving even if
  the token is bad — a failed obtain/renew is async and doesn't drop live certs).

**Other operational facts learned:**
- New per-site log files in `/var/log/caddy/` must be **pre-created** — the dir
  isn't writable by the `caddy` user, so a new `log { output file ... }` fails
  with "permission denied" on reload. Create it matching an existing log:
  `touch` + `chown/chmod --reference=/var/log/caddy/dashboard.log`.
- Wildcards REQUIRE DNS-01 (can't use HTTP-01). The emergency fallback if the CF
  token is dead: drop the wildcard block + the global `acme_dns` line so named
  subdomains fall back to HTTP-01 (port 80, no token).
- `specterbot.systems` / `www.` / `mybot.specterbot.systems` are NOT on this
  Cloudflare account/token → DNS-01 `SERVFAIL "could not determine zone"`; their
  certs fail independently. Unresolved as of 2026-06-26 — needs that zone added
  to Cloudflare (or a different challenge/provider for it).

**Deploy flow:** edit repo `web/Caddyfile` → copy to `/etc/caddy/Caddyfile` →
`caddy validate` (needs the token: `set -a; . /etc/caddy/caddy.env; set +a`) →
`systemctl reload caddy` (config) or `restart` (env/token change).
