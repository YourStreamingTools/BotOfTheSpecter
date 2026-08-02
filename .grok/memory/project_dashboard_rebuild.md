---
name: project-dashboard-rebuild
description: "The dashboard.php landing rebuild (operational dashboard) — files, API endpoints, data model, deploy steps; SHIPPED (built 2026-06-16)"
metadata: 
  node_type: memory
  type: project
  originSessionId: cdd20416-8aab-4f50-b032-9cd0bc13d6a4
---

The logged-in branch of `dashboard/dashboard.php` was rebuilt from a welcome+link page into an **operational dashboard** (SHIPPED; built 2026-06-16). Spec: `.grok/specs/2026-06-16-dashboard-rebuild-design.md`.

**Four zones:** (1) live ribbon — `STREAM_ONLINE`/`OFFLINE` + milestone ticker + `CHAT_MESSAGE` chat-pulse, seeded by API then kept live over WebSocket; (2) "what your bot did" lifetime totals; (3) "what's new" windowed deltas + sparklines; (4) community leaderboards. Plus a slim quick-link grid. The logged-out landing branch was left byte-identical.

**Files touched:** `api/api.py` (+5 read-only endpoints at `/dashboard/{live,summary,trends,leaderboards,activity}`, tag `Dashboard`, `verify_key`+`resolve_username`; the dashboard calls them via V2 `X-API-KEY`, see CORS preflight fix below), `dashboard/dashboard.php` (logged-in rebuild + inline JS: fetch/render + Socket.io 4.8.3 client), `dashboard/css/dashboard.css` (Section 32, `db-*` classes, tokens only), `dashboard/lang/{en,de,fr}.php` (+62 keys: `dashboard_js_*` + a few `dashboard_*`). No `menu.php` change.

**Data model = Hybrid:** real deltas/sparklines from timestamped tables (followers_data, subscription_data, bits_data, tipping, raid_data, seen_users.first_seen, quotes.added, chat_history); all-time totals (tagged) for cumulative tables (custom/user_counts, reward_counts, game_deaths, hug/kiss/highfive, message_counts, bot_points). **No DB schema change** (usr_database.php prunes unmanaged tables/columns) and **no bot change**. "Since last visit" rides a `dbLastVisit` cookie → `?since=` (epoch).

**Dropped as hollow (no writer):** timed-messages-sent, alerts-fired. "Welcome messages" reframed to "viewers welcomed" (count of seen_users).

**Deploy:** upload `api/api.py` (**restart the API service**), `dashboard/dashboard.php`, `dashboard/css/dashboard.css`, `dashboard/lang/{en,de,fr}.php`. **CORS preflight fix:** `v2_api_key_header_middleware` now bypasses auth for `OPTIONS` (returns `call_next` early) so CORSMiddleware answers the preflight — this makes browser→FastAPI **V2 `X-API-KEY`** work. Before the fix the OPTIONS preflight (which carries no header) was 401'd "Missing X-API-KEY header" before CORS replied → worked in curl, failed in browser. The dashboard apiGet uses V2 `X-API-KEY`. This middleware change is shared infra (helps any browser→/v2 header call, e.g. profile.php weather) and **needs the API restarted**.

**Caveats to remember:** ticker fully populates only on **beta/v6** bots — stable `bot.py` emits gift subs as `TWITCH_SUB` and has no hype/charity (do NOT add to stable per [[feedback... bot-versions rule]]). Channel-points payload is a JSON string under `rewards` (parse it; beta-add also has top-level username/reward_title). `watch_time` leaderboard shows the raw `total_watch_time_live` integer (unit unconfirmed). Socket.io loaded from cdn.socket.io without SRI (matches overlays). **Collation gotcha:** per-user tables have inconsistent collations (stored_redeems is explicitly utf8mb4_unicode_ci, others use the DB default), so any cross-table `UNION`/`JOIN` (e.g. `/dashboard/activity`) must wrap text columns + join comparisons in `CONVERT(... USING utf8mb4)` or it 500s with "Illegal mix of collations". Verified locally with `php -l` + `ast.parse` only; runtime testing is on the server. (Note: beta.py has a leading UTF-8 BOM — parse it with `encoding='utf-8-sig'`, CPython strips it at runtime.)

**Shoutouts-given (5.8+ feature, 2026-06):** the "Shoutouts given" tile is backed by a NEW persistent per-user table **`shoutout_history`** (id, user_id, user_name, via, source, given_at) added to `usr_database.php` `$tables`. `beta.py` logs each *delivered* shoutout via the module-level helper `log_shoutout_history()`, called in `shoutout_worker` right after `record_automated_shoutout` (cooldown skips are excluded). `/dashboard/summary` counts `shoutout_history`. The old `automated_shoutout_tracking` is ONLY the live cooldown queue (UPSERT per-user, pruned to the cooldown window, wiped on stream-offline) — never a historical count. The dashboard tile shows a `db-beta-tag` "5.8 beta" badge because the count only accrues on beta 5.8+ from deploy forward (no backfill). beta.py only — NOT ported to beta-v6.py yet. Deploy needs bot restart + API restart + the usr_database.php/dashboard files.
