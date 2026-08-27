# PHP to React — Strangler Design Spec

**Date:** 2026-08-26
**Host:** live web1 (`/var/www`)
**Companion plan:** `.grok/plans/2026-08-26-php-to-react.md`

**Goal:** move the **logged-in product UI** from PHP-rendered pages to React without taking the site down, without invalidating bookmarks or OBS sources, and without changing how people sign in. Operator tools move off the dashboard host onto **`admin.botofthespecter.com`**, entered through the existing SSO handoff (same pattern as support / members / roadmap).

This is not a rewrite of “the PHP site.” Web1 serves many PHP apps on many hostnames. A big-bang swap of `php_fastcgi` for a Node/React app would break production. The way through is a **strangler**: React grows page by page behind the existing URLs, PHP keeps the doors that must stay PHP, and every migrated page can be flipped back in minutes.

---

## What is actually on this box

Caddy (`/etc/caddy/Caddyfile`, repo copy `./web/Caddyfile`) runs PHP 8.5 FPM over `unix//run/php/php8.5-fpm.sock` for each frontend. Exact-host blocks win; a `*.botofthespecter.com` catch-all exists for unmatched names.

| Host | Docroot | Role |
| ---- | ------- | ---- |
| `dashboard.botofthespecter.com` | `./dashboard/` | Main product. ~60 user pages, 9 todo pages, 55 session AJAX endpoints. Admin lives here **today** under `/admin/` and will move off this host |
| `admin.botofthespecter.com` | *(new)* | Operator panel. Not live yet; today this name would hit the `*.botofthespecter.com` catch-all (`/var/www/html`) |
| `overlay.botofthespecter.com` | `./overlay/` | OBS browser sources (~29 PHP files). Auth is `?code=` (user API key). No login cookie |
| `botofthespecter.com` | `./home/` | Marketing, legal, SSO issuer (`sso.php`) |
| `members.botofthespecter.com` | `./members/` | Members portal |
| `support.botofthespecter.com` | `./support/` | Tickets + docs CMS |
| `roadmap.botofthespecter.com` | `./roadmap/` | Roadmap |
| `specterbot.app` + `*.specterbot.app` | `./specterbotapp/` | Landing + **per-user untrusted PHP modules** |
| `specterbot.systems` / `mybot.specterbot.systems` | `./specterbotsystems/` | Status + custom-bot verification |
| `yourchat.botofthespecter.com` | `./yourchat/` | Chat window (PHP shell, mostly JS) |
| `yourlinks.click` + `*.yourlinks.click` | `./yourlinks.click/` | Link dashboard + Host-based short-link engine (`redirect.php`) |
| `streamersconnect.com` | `./StreamersConnect/` | **Twitch OAuth broker** that dashboard login redirects through |
| `yourstreamingtools.com` | `./YourStreamingTools/` | Separate small site |
| CDN / media / TTS / walkons / alerts hosts | mounts or S4 proxy | Static files, not a UI to convert |

Shared login cookie for the `*.botofthespecter.com` apps:

- Name: `bots_session`
- Domain: `.botofthespecter.com`
- Flags: `Secure`, `HttpOnly`, `SameSite=Lax`
- Store: `website.web_sessions` via `./lib/session_bootstrap.php` + `web_session.php`
- Gate: `./lib/require_auth.php` (HTML → `/login.php`) and `./lib/require_auth_ajax.php` (JSON 401)
- Browser already has a global `window.fetch` patch in `./dashboard/js/dashboard.js` that sends 401 same-origin calls to `/login.php?return_to=…`

Login is **not** a direct Twitch redirect anymore. `./dashboard/login.php` sends the user to StreamersConnect with a return URL, then writes the session. Overlay URLs that streamers paste into OBS look like `https://overlay.botofthespecter.com/index.php?code=…` (and `all.php`, `pet.php`, …). Those URLs are load-bearing.

`./help/` was removed (2026-08-26). Docs are the support portal. Do not recreate it.

---

## What we convert, and what we never convert in this programme

### Convert (in this order)

1. **Admin host split (PHP first)** — lift `./dashboard/admin/` onto `admin.botofthespecter.com` with SSO entry and redirects from the old `/admin/` URLs. Still PHP. This is the clean place for a later React admin app (own origin, own chrome, no `layoutMode=admin` hacks).
2. **Dashboard user UI** — the streamer sidebar app, React islands on `dashboard.botofthespecter.com`.
3. **Admin React** — islands or a small SPA **on the admin host**, after the hostname and SSO are proven.
4. **Later, optionally:** members, support, roadmap, home marketing — each is already its own host. Separate apps, not one mega-bundle.

### Do not convert (treat as out of scope)

- **Overlays.** OBS browser sources, transparent CSS, alert queues, WebSocket-only live data, API key in the query string, URLs bookmarked inside OBS. A React SPA here is a product risk, not a win. Overlay config UI on the dashboard *can* move to React; the overlay pages themselves stay PHP until a dedicated overlay project says otherwise.
- **SpecterBotApp tenant folders.** Untrusted per-user PHP. Data goes through the SQL data API. React does not belong inside `gfaundead/`-style module trees.
- **YourLinks wildcard `redirect.php`.** That file *is* the short-link router. Replacing it with a client SPA would break every `username.yourlinks.click/name` hop.
- **StreamersConnect.** Login for the dashboard depends on it. Leave the broker alone.
- **Webhook receivers, bots API, FastAPI, WebSocket server, Python bots.** Wrong layer.
- **File bytes on rclone/S4.** React never writes `/var/www/media` itself.
- **`/var/www/config/*.php`.** Real secrets, skip-worktree. PHP keeps reading them. The React bundle must not.

If someone says “move the PHP site to React,” the honest translation is: **move the streamer dashboard, and move operator tools to their own host. Keep the edge PHP that does auth, uploads, redirects, tenant modules, and overlays.**

---

## Target architecture (strangler, two product hosts)

```text
Browser  ──HTTPS──►  Caddy on web1
                       │
                       ├─ dashboard.botofthespecter.com
                       │    ├─ /login.php /logout.php /relink.php     PHP forever
                       │    ├─ /api/*.php                             PHP session BFF
                       │    ├─ /admin/*                               301 → admin host (after split)
                       │    ├─ /app/assets/*                          hashed Vite build
                       │    ├─ migrated *.php                         thin PHP gate → React
                       │    └─ not-yet-migrated *.php                 existing pages
                       │
                       ├─ admin.botofthespecter.com   (NEW exact-host block)
                       │    ├─ /login.php                             SSO consumer (handoff)
                       │    ├─ existing admin PHP                     until React islands
                       │    └─ later /app/assets/*                    admin React build
                       │
                       └─ botofthespecter.com/sso.php                 token issuer (already live)

Streamer React (dashboard origin)
  ├─ credentials: 'same-origin'  →  dashboard/api/*.php
  ├─ X-API-KEY from session JSON →  https://api.botofthespecter.com/v2/...
  └─ Socket.io 4.8.3             →  wss://websocket.botofthespecter.com

Operator React (admin origin, later)
  └─ credentials: 'same-origin'  →  admin PHP JSON / later admin BFF
```

React is a **static build**. There is no Node process on web1 at runtime. PHP-FPM stays. Vite is a build tool only.

Streamer UI stays **same-origin** on `dashboard.botofthespecter.com` because the session cookie is HttpOnly. Operator UI lives on its **own origin** so dashboard XSS, streamer bookmarks, and `layout.php` chrome are not mixed with terminal / Caddy / act-as / user export.

---

## Admin host (`admin.botofthespecter.com`) via SSO

**Yes — this is the right split, and the SSO we already have is the entry door.** It should happen as PHP first, before any React admin work.

### How SSO works today

`./home/sso.php` is the only token issuer. A signed-in browser (cookie `bots_session` on `.botofthespecter.com`) hits:

`https://botofthespecter.com/sso.php?target=support&return=/tickets.php`

It mints a one-time row in `website.handoff_tokens` (5 minutes, `used=0`, target bound) and 302s to the consumer’s `login.php?handoff=…`. Support / members / roadmap consume that token and write the **same** session fields. Stream recording boxes on `*.botofthespecter.video` need this because they **cannot** share the cookie (different registrable domain). Support/members/roadmap technically share the cookie already; SSO is still the documented deep-link (dashboard “Visit support” is exactly this).

Admin today is **not** an SSO target. It is `dashboard.botofthespecter.com/admin/*`, gated by `./dashboard/admin/admin_access.php` (`is_admin = 1`). `./dashboard/admin/login.php` is a stub that sends you to `../login.php`. The dashboard chrome link is `../admin/`.

### Cookie vs handoff — use both, for different jobs

The domain cookie **will** be sent to `admin.botofthespecter.com` the moment DNS and Caddy exist. A logged-in admin who types the new host would already have `$_SESSION`. That is fine as a fallback (same as support).

SSO is still required as the **product entry**:

- Dashboard “Admin panel” becomes `https://botofthespecter.com/sso.php?target=admin` (mirrors support/roadmap).
- We can **refuse to mint** a token when `is_admin` is not 1, so a streamer clicking a leaked bookmark never gets an admin handoff.
- If we later isolate admin with a **host-only** cookie (no `Domain=.botofthespecter.com`), SSO is the only way to mint that cookie. Designing the login consumer now means we do not re-do auth later.

Recommended v1: **shared `bots_session` + SSO entry + `is_admin` gate on every admin request** (current gate, new host). Host-only admin cookie is a hardening pass after React, not a blocker.

### Caddy and DNS (order matters)

The wildcard `*.botofthespecter.com` block roots at `/var/www/html`. If we point DNS at web1 **before** an exact-host block exists, `admin.` serves the catch-all, not the panel.

Order:

1. Add `admin.botofthespecter.com` to `./web/Caddyfile` (exact host wins over the wildcard).
2. Copy → `/etc/caddy/Caddyfile`, validate with `caddy.env` sourced, restart Caddy.
3. Then DNS (Cloudflare DNS-only A/AAAA to web1). TLS is already DNS-01 for the wildcard, so the cert side is fine once the site block exists.

Do not use `redir` from the wildcard as a substitute for an exact-host block.

### How to serve the existing PHP without rewriting every include

Keep files on disk at `./dashboard/admin/` for the PHP phase. Caddy on the new host should make `/users.php` execute `./dashboard/admin/users.php`. Filesystem `require` of `../includes/userdata.php` and `admin_access.php` stays valid.

Browser CSS is the trap: pages that `<link href="../css/dashboard.css">` would request `https://admin.botofthespecter.com/css/dashboard.css`. **Do not** `<link>` across to `https://dashboard.botofthespecter.com/css/…` (theme rule: no cross-subdomain stylesheets). Serve those files **on the admin host** — either Caddy `handle /css/*` and `/js/*` from `./dashboard/css` and `./dashboard/js`, or copy `dashboard.css` + `admin.css` into an admin-local `css/` (preferred once React starts; copy is how every other portal already works).

`admin/login.php` stops bouncing to dashboard login. It becomes a support-style consumer:

- Path A: `?handoff=` → verify `handoff_tokens` where `target = 'admin'`, `used = 0`, unexpired, then check `is_admin` **again** from `website.users` before writing the session.
- Path B: already have `bots_session` and `is_admin` → continue.
- Else: 302 to `https://botofthespecter.com/sso.php?target=admin&return=…` (issuer sends unknown users through home login / StreamersConnect, then back). Non-admins: 403, no token, no panel.

Issuer change in `./home/sso.php`: add `'admin' => 'https://admin.botofthespecter.com/login.php'`. For this target only, require `$_SESSION['is_admin'] == 1` before INSERT. Streamer sessions never mint `target=admin`.

### Old URLs must not 404

`https://dashboard.botofthespecter.com/admin/users.php` is bookmarked. After cutover, Caddy on the dashboard host:

- `redir /admin /admin/ 308` and `redir /admin/* https://admin.botofthespecter.com{uri} 308` with the `/admin` prefix stripped, **or** `redir /admin/* https://admin.botofthespecter.com/{http.request.uri.path.dir}` — pick one rewrite and test every current script name (`index.php`, `start_bots.php`, `caddy.php`, `terminal.php`, `act_as_user.php`, …).

Dashboard layout link (`../admin/` / “Admin panel”) becomes the SSO URL, not a same-host relative path.

`generate_handoff.php` is already a shim to `sso.php?target=support`. Admin does not need a second shim; link SSO directly.

### Two tabs, same operator: dashboard ↔ admin, impersonation kept

Product intent (2026-08-27): admin is a **new host / new tab**, not a mode of the streamer chrome. Features stay the same. You can jump to the user dashboard and back. You can still impersonate a streamer. You can still do everything the current `/admin/` panel does (users, start bots, Caddy, terminal, tokens, export, …).

How that sits on two origins:

- Dashboard “Admin panel” opens `https://botofthespecter.com/sso.php?target=admin` (new tab is fine). Shared `bots_session` means the admin tab is already signed in.
- Admin chrome always has a **User dashboard** (or “Open dashboard”) link to `https://dashboard.botofthespecter.com/dashboard.php` so going back is one click, not hunting bookmarks.
- **Act-as still exists.** Pick the user from admin (same control as today). That writes the existing `admin_act_as_*` session fields. Opening the user dashboard then shows that streamer’s account and the act-as banner. Stop-acting-as works from that banner as it does now.
- **Admin pages themselves do not run as the impersonated user.** `admin_access.php` already restores the admin identity (`ADMIN_PANEL_CONTEXT`) so terminal / Caddy / start-bots / export cannot fire as the streamer. “Start bots for user X” still takes an explicit username. Impersonation is for *viewing and using the user dashboard*, not for operator tools.

Same session cookie, two tabs, no feature dropped. Host-only admin cookie (later hardening) must keep a path that can still set act-as for the dashboard origin — do not “isolate” in a way that kills impersonation.

### What this does for the React plan

Admin as a separate origin means we do **not** build React admin inside `layout.php`’s `layoutMode=admin`. Two Vite apps (or one monorepo with two `outDir`s):

- `dashboard-ui` → `dashboard.botofthespecter.com`
- `admin-ui` → `admin.botofthespecter.com`

Privileged PHP (Caddy control via localhost:2019, web terminal, user export, migrations) can stay PHP on the admin host for a long time. Those pages are not the reason to adopt React.

### What we will not do on this host

- No public streamer login on `admin.` — SSO or 403.
- No overlay, media, or bots-control UI here (bots start-all for operators is already an admin page; that one **does** move with the panel).
- No `git pull` deploy; Caddy copy/validate/restart is the risky step.

---

## Key decisions

### 1. Islands first, full SPA later

First migrated pages keep **PHP chrome** (`layout.php` sidebar/topbar/menu) and mount React only in the page body. Users still get `bot.php` in the address bar. Auth still runs in PHP before any HTML. Menu registration stays in `menu.php` until the chrome itself is replaced.

A client-side React Router shell comes **after** several pages are proven. Doing the shell first duplicates the entire nav/i18n/act-as/maintenance banner and creates two sources of truth while half the site is still PHP.

### 2. Old URLs never die

`bot.php`, `alerts.php`, `/admin/users.php`, etc. remain the public URLs. Bookmarks, `redirect_after_login`, StreamersConnect `return_url`, and mod links keep working.

A migrated PHP file becomes a short gate: session bootstrap, `require_auth`, then include the React shell and the page’s mount name. Rollback is: restore the old PHP file (or a `use_react_pages` list) — **no Caddy restart**.

### 3. One small Caddy change, deferred

Needed eventually: serve hashed files under `/app/assets/` as static (`file_server`) so they are not pushed through php_fastcgi. Do that as its own, validated Caddy step (backup live file, copy repo Caddyfile, `caddy validate`, restart because env is involved). Until then, Vite can emit into `./dashboard/js/react/` and PHP can `<script>` those files — uglier cache headers, zero Caddy risk. Prefer that for the first page.

Live Caddy is `/etc/caddy/Caddyfile`, not `./web/Caddyfile`.

### 4. PHP remains the BFF for privileged actions

The browser must never hold:

- bots control key (`service=bots` admin key)
- MySQL credentials
- Caddy admin / SSH / S3 credentials
- StreamersConnect server key

Today `./dashboard/api/bot_action.php` already proxies start/stop through the PHP bots client. React calls that same endpoint with the session cookie. Same for `notify_event.php`, uploads, act-as, admin terminal.

FastAPI (`api.botofthespecter.com`) is already used from the browser on the rebuilt `dashboard.php` via V2 `X-API-KEY`. CORS OPTIONS is already fixed for that. React reuses it for **user-scoped reads/writes that already exist**. We do not invent a second auth for FastAPI, and we do not dump every PHP AJAX file into FastAPI on day one.

### 5. Session JSON bootstrap

Add one PHP endpoint, e.g. `GET /api/session.php`, behind `require_auth_ajax.php`, returning JSON the React tree needs:

- username, display name, twitch user id
- language
- user API key (already injected into dashboard JS today for WebSocket `REGISTER` and V2 calls)
- is_admin, act-as flags and labels
- maintenance flag from `config/main.php`
- dashboard version
- which channel/mod context is active

Keep the API key in **memory** after that fetch (status quo is already “key in JS”). Do not write it to `localStorage`.

401 handling: keep the existing fetch patch or reimplement it once in the React HTTP client. Same redirect to `/login.php?return_to=`.

### 6. Stack

- **Vite + React 18 + TypeScript.** Predictable static output, no SSR host.
- **Not Next.js** on web1. SSR would mean a Node server next to Caddy/PHP-FPM, a second process to babysit, and no benefit for an authenticated dashboard.
- **No Tailwind, no Bulma, no new CSS framework.** Port `:root` tokens from `./dashboard/css/dashboard.css` into the React app and reuse `sp-*` class names (or a thin wrapper). The theme skill forbids inventing a parallel palette and forbids cross-host CSS links.
- **TanStack Query** (or equivalent) for FastAPI + PHP JSON.
- **Socket.io 4.8.3** — same client overlays and the rebuilt dashboard already use. One reconnection helper, not a second one.
- **i18n:** do not bake 5,000 PHP keys into a JS rebuild. Serve the active language from a PHP JSON endpoint (`/api/i18n.php`) that wraps the existing `t()` dictionaries. English is the base; de/fr/es/zh overlay. Translators keep editing `./dashboard/lang/*.php` until we later export JSON as a build artefact if we want.

### 7. Theme

Canonical tokens live in `dashboard.css`. React pages that still sit inside `layout.php` **keep loading `dashboard.css`** so the chrome matches. When a page is fully React including chrome, copy the token block into the React global CSS that ships with that app — do not `<link>` overlay CSS or another portal’s sheet.

Admin pages additionally load `admin.css`. Alerts builder additionally loads `alerts.css` (Twitch purple on purpose). Do not flatten those into one file on the first pass.

### 8. Feature flag / rollback

A single PHP list (config or a small include), e.g. pages whose body is React. Missing from the list → old PHP. That is the kill switch. Do not feature-flag in Caddy (restarting Caddy reloads every site on the box).

### 9. Admin is its own host, entered through SSO

Operator UI does not stay under `dashboard.botofthespecter.com/admin/`. New exact-host `admin.botofthespecter.com`, SSO target `admin` on `./home/sso.php` (mint only if `is_admin`), login consumer like support, 308 from old `/admin/*` paths. PHP files can stay in `./dashboard/admin/` for the first cut so includes keep working. React admin is a second app on that origin later, not an island inside the streamer shell.

---

## Page migration order (dashboard)

Order is “risk × how API-ready the page already is,” not alphabetical. The **admin hostname split is not a dashboard page slice** — it is work package 1 and should land (still PHP) before we invest in React admin.

**Slice 0 — foundations (no user-visible change)**

- Vite app in a new folder such as `./dashboard-ui/` (source) with build output under `./dashboard/` so Caddy’s existing root still sees it.
- `GET /api/session.php` and `GET /api/i18n.php`.
- Shared React HTTP client (cookie, 401 → login, optional X-API-KEY).
- Tokenized CSS entry so islands look like `sp-card` / `sp-btn`.
- Dev build path that does not touch production files until an explicit copy into `./dashboard/`.

**Slice 1 — first island (prove the pipe)**

Pick a **read-mostly** page that already talks JSON. Best candidates:

- `dashboard.php` logged-in branch (already fetches `/v2/dashboard/*` and WebSocket). Replacing its inline JS with a React mount is the lowest conceptual jump.
- or `logs.php` / a simple list page if the home dashboard feels too visible for v1.

Ship: PHP file still does auth + `layout.php`; React renders the content card. Compare side-by-side with `?legacy=1` if we keep the old body behind the flag.

**Slice 2 — list/settings pages with existing AJAX**

Bot status/control (`bot.php`) is high value but privileged: it must keep using `bot_action.php`, never the bots host from the browser. Then: custom commands, builtin + cooldowns, timed messages, points, point store, known users, channel rewards.

Each page: inventory its PHP AJAX and FastAPI coverage *before* writing UI. If a save path only exists as a full form POST to the same PHP page, extract a JSON endpoint first (still PHP), then point React at it. Do not make React POST huge HTML forms.

**Slice 3 — media and uploads**

`media.php`, sound/video/walkon pages. Multipart stays on the existing PHP upload helpers (`upload_helpers.php`, rclone mounts, perms). React = `FormData` to those URLs. Do not stream files through FastAPI unless we deliberately move that later.

**Slice 4 — complex builders**

`alerts.php` (three-column builder, preview, test via `notify_event.php`, `OVERLAY_REFRESH`). `working-or-study.php`. `pet.php` / `avatar.php`. These are the pages most likely to regress visually; they get their own bake time and the existing overlay still PHP-renders the result.

**Slice 5 — chrome replacement (streamer only)**

Once enough body pages are React, replace `layout.php`+`menu.php` with a React shell for migrated **streamer** routes only. PHP `menu.php` remains the source of truth for not-yet-migrated pages. Avoid two menus drifting: generate the React nav from the same structure (JSON dump of `getMenuItems()` or a shared menu config both sides read). Admin chrome is not this slice — it lives on the admin host.

**Not in the dashboard slices**

Overlays, SpecterBotApp, YourLinks redirects, StreamersConnect. The SSO issuer stays PHP on home; we only add a target row. Admin pages are a separate host programme (see above), not islands inside `layoutMode=admin`.

---

## Data-flow rules the React app must obey

- Real-time events: WebSocket only. Overlays and dashboard already do this. Do not poll FastAPI for follows/subs/deaths.
- On-demand config: FastAPI when the route exists; otherwise dashboard PHP AJAX.
- Bot start/stop/status: PHP → bots API. Never from the browser to `bots.botofthespecter.com`.
- SpecterBotApp/SQL API: irrelevant to the dashboard SPA.
- Parameterized SQL stays server-side. React never sees a connection string.

New JSON the SPA needs should be added as **small PHP endpoints or FastAPI routes**, not as “render this HTML fragment.” If we add FastAPI routes, they follow existing `verify_key()` / `resolve_username()` / Pydantic patterns.

---

## Live web1 constraints (non-negotiable)

- Files under `/var/www/dashboard/` are **live**. A broken React island is a broken page for everyone on that URL. Land complete, linted, flagged work — not half mounts.
- Do not commit `/var/www/config/`. Do not put secrets in the Vite bundle. Public values only (WebSocket URL, API origin, asset CDN).
- Do not `git pull` as deploy. User handles git and, when they want, commit as themselves with no co-author trailer.
- PHP that remains: `php -l` before calling it done. `t()` never runs before the i18n loader (`userdata.php` / `layout.php`).
- Node: install for **build** if we build on web1, or build elsewhere and upload `dist`. No systemd unit for Node.
- First Caddy edit is optional and late. When it happens: backup `/etc/caddy/Caddyfile`, copy from repo, validate with `caddy.env` sourced, restart (not reload) if env changed. Caddy serves all ~13 sites.
- Cloudflare is DNS-only. Cache bugs are origin or browser, not CF “purge the dashboard.”
- Overlay OBS refresh is acceptable for overlay work; this plan should not require overlay refreshes at all if overlays stay PHP.

---

## How we know it is not breaking

For each island:

- Logged-out visit to the old URL still 302s to login and returns to that URL after StreamersConnect.
- Logged-in load matches the PHP page’s main actions (read, save, error, empty).
- 401 mid-session still lands on `/login.php`.
- Act-as: admin sees the banner and bot start stays disabled where PHP disabled it.
- Mobile widths already used by the dashboard (768 / 480).
- WebSocket pages still `REGISTER` with the user code and reconnect with the existing backoff idea (do not set `reconnection: false` without a manual retry).
- Overlay URLs on `overlay.botofthespecter.com` unchanged (grep overlay links on `overlays.php` after dashboard work — they must still be the same hosts and query shape).
- Bots control key never appears in the JS bundle or in a response we did not already give (session JSON may include the **user** API key, never the admin bots key).

Rollback drill before slice 1 ships: turn the page flag off (or restore the previous PHP file) and confirm the old page is back without a Caddy restart.

---

## Work packages (implementation later, not now)

These are ordered slices, each independently shippable on web1.

1. **Admin host split (PHP)** — Caddy exact-host, DNS after Caddy, SSO target `admin` (mint only if `is_admin`), admin `login.php` consumer, 308 from `dashboard…/admin/*`, layout link → SSO URL, CSS/JS served on the admin host. Prove terminal, Caddy page, start-bots, act-as-from-dashboard still work. Rollback: remove the dashboard redir, keep the new host or not.
2. **Dashboard React foundations** — Vite+React+TS, session JSON, i18n JSON, HTTP client, tokens. No user-facing route yet.
3. **First island** — one dashboard body (recommended: logged-in `dashboard.php` widgets, or a quieter list page). Flag + `?legacy=1`.
4. **Bot page island** — still PHP-proxied `bot_action.php`.
5. **Commands / points / rewards cluster** — extract JSON saves from current form posts as needed.
6. **Media/upload cluster** — FormData to existing PHP.
7. **Alerts + overlay-config cluster** — dashboard only; overlay PHP untouched.
8. **Streamer React chrome** — shared menu source, act-as banner, maintenance, mobile sidebar.
9. **Admin React islands** — on `admin.botofthespecter.com`, after the PHP host split is boring. Terminal / Caddy control / migrations can stay PHP.
10. **Optional Caddy static `/app/assets/`** on each host that needs hashed assets.
11. **Other portals** — new apps, not dumped into the dashboard bundle.

No git-branch/PR machinery is assumed. Slices land as live files the same way other dashboard work does.

---

## Risks

- **Two UIs for a long time.** Menu, i18n, and act-as will drift if we copy them into React too early. Mitigate: islands inside `layout.php` until chrome replacement; one menu structure.
- **Hidden POST endpoints.** Many PHP pages save by posting to themselves. Those must become JSON before the React form can work. That extraction is most of the work, not the JSX.
- **API key in JS.** Already true. Session JSON makes it obvious; keep it out of storage and logs.
- **Caddy restart blast radius.** The admin host block is the one Caddy change we should take early; hashed `/app/assets/` can wait. Never add DNS for `admin.` until the exact-host block is live (wildcard catch-all would serve `/var/www/html`).
- **Admin CSS 404.** Relative `../css/` links break when the host root is the admin folder. Serve or copy styles on `admin.` — never hotlink dashboard’s CSS host.
- **SSO mint without `is_admin`.** If we add the target but forget the issuer check, any logged-in streamer can mint an admin handoff (the panel would still 403, but do not issue the token).
- **Building on the live tree.** Vite `outDir` pointed at production too early will wipe files. Build to a staging dir, copy hashed assets in.
- **Admin terminal / Caddy admin / migrations.** Leave PHP. React does not make them safer.
- **Scope creep into overlays.** Resist. Broken OBS is worse than an old PHP overlay.

---

## Open questions (need a decision before slice 0)

1. **First island.** Start with the logged-in home dashboard (most visible, already JSON/WebSocket) or a quieter page such as logs?
2. **Chrome timing.** Stick to PHP `layout.php` until many pages are React (recommended), or build the React shell immediately and iframe/link out to remaining PHP?
3. **Other portals.** Dashboard-only programme, or members/support/roadmap in the same repo as later packages?
4. **TypeScript.** Recommended yes. If you want plain JS to match the current dashboard files, say so.
5. **Admin cookie isolation.** v1 recommended: keep shared `bots_session` and rely on `is_admin` + SSO mint check. Hardening later: host-only cookie on `admin.` so dashboard XSS cannot CSRF admin mutations without an Origin check. Say if you want host-only from day one (SSO becomes mandatory, not just the nice entry).

Overlays staying PHP, Vite-not-Next, dashboard SPA same-origin, PHP BFF for bots/uploads, and **admin on `admin.botofthespecter.com` via SSO** are treated as settled unless you override them.
