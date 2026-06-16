# Dashboard Rebuild — Design Spec

**Date:** 2026-06-16
**Scope:** Rebuild the **logged-in** branch of `./dashboard/dashboard.php` into a real operational dashboard. The logged-out landing page is left as-is. Bot control/runtime/health stays on `./dashboard/bot.php` — the dashboard must not duplicate it.

## Identity

> *"Your channel since you last looked"* — a calm, glanceable command center that opens with what's happening / what changed, proves what the bot did, then lets the streamer dig into community health.

## Data approach (decision: **Hybrid B**)

- Real deltas + sparklines wherever a **timestamp column already exists**.
- All-time totals (tagged "all-time") for **cumulative-only** tables.
- **No bot change, no schema change** for v1. "Since you last visited" rides a **cookie** timestamp passed to the API as `?since=`; everything else uses rolling windows (Today / 7d / 30d).

## Zones

### Zone 1 — Live ribbon ("Right now" / "Last stream")  *(all-in-one, WebSocket)*
- **Live state** seeded from API (`/streamonline` + uptime/viewers), then flipped by `STREAM_ONLINE` / `STREAM_OFFLINE`.
- **Milestone ticker:** `TWITCH_FOLLOW`, `TWITCH_SUB`, `TWITCH_GIFT_SUB`, `TWITCH_CHEER`, `TWITCH_RAID` (+ cheap adds `TWITCH_CHANNELPOINTS`, `TWITCH_HYPE_TRAIN`, `TWITCH_CHARITY`, `KOFI`/`PATREON`/`FOURTHWALL` via `onAny`).
- **Chat pulse:** `CHAT_MESSAGE` → msgs/min + active chatters (aggregated, NOT per-line in the ticker).
- Offline → "since your last visit" summary + recent-activity feed.

### Zone 2 — "What your bot did for you" (lifetime totals, tagged all-time)
Commands answered (`custom_counts`+`user_counts`) · rewards fulfilled (`reward_counts`) · deaths tracked (`total_deaths`) · song requests (`song_request_analytics`) · viewers welcomed (`seen_users`) · creators shouted out (`automated_shoutout_tracking`) · quotes saved (`quotes`) · points in circulation (`bot_points`) · interactions (`hug`+`kiss`+`highfive_counts`).

### Zone 3 — "What's new / what changed" (real deltas + sparklines; all timestamped)
New followers (`followers_data.followed_at`) · new subs by tier (`subscription_data.timestamp`) · bits (`bits_data.timestamp`) · tips (`tipping.created_at`) · raids received (`raid_data.timestamp`) · new viewers (`seen_users.first_seen`) · new quotes (`quotes.added`) · chat volume (`chat_history.timestamp`). Window switch Today/7d/30d + "since last visit" marker.

### Zone 4 — Community & channel health (leaderboards)
Follower-growth chart · top commands · most-redeemed rewards (`reward_counts` + titles) · watch-time leaders (`watch_time`) · loyalty/watch streaks (`analytic_stream_watch_streak`) · deaths by game (`game_deaths`) · chat leaders (`message_counts`) · interaction leaders · top requested songs (`song_request_analytics`) · raid history in/out (`raid_data` + `analytic_raids.source`).

### Slimmed launchpad
A few **contextual** quick links (e.g. "bot offline → start it", "N tasks pending") — not the flat 8-card grid.

## Dropped (no writer — confirmed)
- ❌ Timed messages sent (`timed_messages` is config only)
- ❌ Alerts fired (`twitch_alerts` is overlay config; not persisted)
- ⚠️ "Welcome messages sent" → reframed "Viewers welcomed"

## API contract (new endpoints on `./api/api.py`)

Auth: every endpoint takes `api_key: str = Query(...)`, `channel: str = Query(None)`; uses `verify_key()` + `resolve_username(key_info, channel)`. V2 (`/v2/...` + `X-API-KEY`) is automatic — do NOT add to `_V2_PUBLIC_PATHS`. Per-user reads via `get_mysql_connection_user(username)`. Pydantic response models inline, `tags=["Dashboard"]`, read-only (no commit). All SQL parameterized.

**Reuse (no new code):** `/streamonline` (online/title/game seed), `/account`, `/user-points`, `/quotes`.

| Path | Params | Returns (shape) |
|---|---|---|
| `GET /dashboard/live` | `api_key`,`channel` | `{channel, online, started_at, uptime_seconds, viewer_count, title, game}` (extends streamonline seed w/ uptime+viewers via Helix) |
| `GET /dashboard/summary` | `api_key`,`channel`,`window`(today\|7d\|30d),`since`(unix, optional) | `{channel, lifetime:{commands,rewards,deaths,songs,welcomed,shoutouts,quotes,points,interactions}, window:{followers,subs:{t1,t2,t3,prime},bits,tips:{amount,count},raids:{count,viewers},new_viewers,new_quotes,chat_messages}, since_visit:{...same keys...}|null}` |
| `GET /dashboard/trends` | `api_key`,`channel`,`days`(default 30) | `{channel, days, series:{followers:[{date,count}], subs:[...], bits:[...], chat:[...]}}` (daily buckets) |
| `GET /dashboard/leaderboards` | `api_key`,`channel`,`limit`(default 10) | `{channel, top_commands:[], top_rewards:[], watch_time:[], streaks:[], deaths_by_game:[], chat_leaders:[], interactions:{hugs,kisses,highfives}, top_songs:[], raids:{received:[],sent:[]}}` |
| `GET /dashboard/activity` | `api_key`,`channel`,`limit`(default 25) | `{channel, items:[{type,actor,detail,at}]}` (recent rows merged across `followers_data`,`subscription_data`,`bits_data`,`tipping`,`raid_data`,`stored_redeems`,`quotes`, ordered by time DESC) |

> If extending `/streamonline` is cleaner than a new `/dashboard/live`, do that instead and document it — goal is max reuse per the data-flow rule.

## WebSocket client (in dashboard.php)
- Socket.io **4.8.3**, `io('wss://websocket.botofthespecter.com', { reconnection:false })`, emit `REGISTER {code, channel:'Dashboard', name:'Dashboard'}` on `connect`, listen `WELCOME`.
- **Manual reconnect** backoff (5s × attempts, cap 30s); re-`REGISTER` on reconnect.
- **`escapeHtml` every payload field** before render (XSS — usernames/messages are attacker-controlled).
- `code` = user's `api_key` (already available server-side as `$api_key`).
- Listen for the curated set above; use `socket.onAny` for wildcard-relayed events. Note server name is `TWITCH_CHANNELPOINTS` (no underscore).
- Live state seed from API first (socket replays nothing on mid-stream connect).

## Rendering
Fast shell + progressive widgets (the `notifications.php` pattern): PHP server-renders the zone skeleton with "Loading…" rows; JS fetches each `/dashboard/*` endpoint on `DOMContentLoaded` and swaps `innerHTML`; validate `response.ok` then payload. **Remove** the synchronous Twitch subscriber cURL + the heavy `includes/user_db.php` load from the logged-in path (move to API) to kill the ~16s worst-case blocking.

## CSS (in `./dashboard/css/dashboard.css`, via theme tokens — no inline/`<style>`)
New `db-`-namespaced classes: `db-live-ribbon`, `db-live-state`, `db-ticker`, `db-ticker-item`, `db-chat-pulse`, `db-zone`, `db-zone-title`, `db-trend-tile`, `db-trend-delta` (up/down/flat), `db-sparkline`, `db-board`, `db-board-row`, `db-board-rank`, `db-window-switch`. Reuse `--bg-card`, `--accent`, `--green/-bg`, `--blue/-bg`, `--amber/-bg`, `--red/-bg`, `--text-*`, `--radius*`, `.sp-card`, `.sp-stat*`, `.sp-badge*`, `.sp-btn*`, `.sp-table*`.

## i18n (`./dashboard/lang/{en,de,fr}.php`, all three mirrored)
`dashboard_*` keys; JS strings via `json_encode(t('dashboard_js_*'))` into an inline `DASH_I18N` object. Escape French apostrophes; `php -l` gate.

## Files touched
- `./api/api.py` — +4–5 endpoints + Pydantic models (mind the existing uncommitted diff).
- `./dashboard/dashboard.php` — rebuild logged-in branch (shell + JS fetch/render + Socket.io client). Landing page untouched.
- `./dashboard/css/dashboard.css` — new `db-` classes.
- `./dashboard/lang/{en,de,fr}.php` — new keys.
- No `menu.php` change (dashboard is already the first item; same filename auto-highlights).

## Non-goals / deploy notes
- No bot edit (echo/`BOT_CHAT_MESSAGE` deferred). No DB schema change.
- Leave everything **uncommitted**; user uploads to the server and tests.
- Local testing limited to `php -l`; runtime verification is on the server.
