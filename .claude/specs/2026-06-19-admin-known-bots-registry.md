# Admin-Editable Global Known-Bots Registry

**Date:** 2026-06-19
**Status:** Approved (Phase 1)

## Problem

The dashboard leaderboards (`dashboard.php` → API `GET /dashboard/leaderboards`) hide bots
from the watch/chat boards using a hardcoded Python set, `KNOWN_BOT_LOGINS`, in `./api/api.py`
(the current 41 third-party bot logins). Editing that list today means a code change and an
API deploy. I want admins to be able to manage the list from the dashboard admin pages, and I
want it to grow into a canonical, global "these accounts are bots" registry that other systems
can reuse over time.

## Decisions (locked)

| Decision | Choice |
| --- | --- |
| Scope of the list | **Full global bot registry** — one shared list, designed for reuse (API now, bot later, future features). |
| Sequencing | **Phased.** Phase 1 = registry + admin UI + API read (this spec). Phase 2 = bot consumers, separate spec. |
| Board coverage | **All person-keyed boards:** Watch, Chat, Streaks, and the Hugs/Kisses/Highfives interaction leaders. |
| Who can edit | **Any admin** (`users.is_admin = 1`), same gate as the existing API-keys / webhooks admin pages. |
| Storage shape | **One row per bot** (like `admin_api_keys`), not a CSV blob. |

## Goals

- Move `KNOWN_BOT_LOGINS` out of code into a `known_bots` table in the central `website` DB.
- Provide an admin page to add / disable / delete bot logins, audit-logged.
- The API reads the list through a short-lived cache and **falls back to the hardcoded seed**
  if the table is empty or unreachable, so leaderboards never break.
- Behaviour is identical to today the moment it ships (the table is seeded with the current 41 logins).

## Non-goals (Phase 1)

- **No bot changes.** `beta.py` / `beta-v6.py` consumption is Phase 2; stable `bot.py` is excluded by
  the bot-version policy (critical fixes only).
- **No new write API endpoint.** The PHP admin page writes the table directly (the same way
  `api_keys.php` writes `admin_api_keys`); the Python API only *reads* it.
- **No change to `message_counts` writes** or any other bot-side counting. We filter at *display*
  time only, so nothing else that reads those tables is affected.
- The per-channel `watch_time_excluded_users` (and `bot_settings.excluded_users`) lists are
  **unchanged** and still unioned in at the API. The global registry deliberately does **not**
  contain the streamer/owner — per-channel lists handle that.

## Architecture

```
Admin (browser)
  └─ dashboard/admin/known_bots.php   ── writes ──▶  website.known_bots
        (is_admin gate, AJAX CRUD, audit log)            ▲
                                                         │ reads (cached, 5 min)
  API  /dashboard/leaderboards  ──▶ get_known_bots_list()┘
        unions with per-channel watch_time_excluded_users,
        filters Watch / Chat / Streaks / interaction boards
```

### 1. Data model — `known_bots` (central `website` DB)

One row per bot login. The schema:

```sql
CREATE TABLE IF NOT EXISTS known_bots (
    id          INT NOT NULL AUTO_INCREMENT,
    bot_login   VARCHAR(50) NOT NULL,          -- Twitch login, stored lowercased (<=25 chars)
    is_active   TINYINT(1) NOT NULL DEFAULT 1, -- disable without deleting
    added_by    VARCHAR(50) DEFAULT NULL,      -- admin username, or 'system' for seeds
    notes       VARCHAR(255) DEFAULT NULL,     -- optional reason
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_bot_login (bot_login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

The table is **created at runtime**, the same way `freestuff_games`, `custom_webhooks`, and
`admin_api_keys` already come into existence — there is no canonical-schema-file entry, and the
deprecated `./help` folder must not be touched.

A defensive `ensure_known_bots_table()` helper in `./api/api.py` handles this. It runs from the
`lifespan` startup handler, right after the existing custom-webhooks table is ensured. It issues
the `CREATE TABLE IF NOT EXISTS`, and then, if the table is empty, seeds it with the current
hardcoded set (`DEFAULT_KNOWN_BOTS_SEED`) using `added_by='system'`, `is_active=1`.

**Empty-table semantics:** seeding happens only on an *empty* table (at startup), and the read
fallback (below) also triggers only on an *empty* result. So an admin removing a few entries
works as expected — the remaining rows are authoritative — but a table emptied all the way to
zero rows re-applies the seed on the next read or restart. In other words, "filter nothing" is
not a reachable state. That degenerate case is acceptable and intentional.

### 2. API read path — `./api/api.py`

The existing `KNOWN_BOT_LOGINS` set is renamed to `DEFAULT_KNOWN_BOTS_SEED` (same 41 entries).
It serves a dual purpose now: the seed source for `ensure_known_bots_table()` and the fallback
for reads.

A small read-through cache sits in front of the table, modelled on the existing Twitch-credentials
cache: a module-level holder with a loaded-at timestamp and a frozenset of logins, governed by a
300-second (5-minute) TTL. That TTL is the window within which an admin edit takes effect on the
leaderboards.

An async `get_known_bots_list()` returns a lowercased set of the active bot logins. It reads
`SELECT bot_login FROM known_bots WHERE is_active = 1` through the `website` DB helper
(`get_mysql_connection()`, **not** the per-user `get_mysql_connection_user()`), and falls back to
`DEFAULT_KNOWN_BOTS_SEED` whenever the result is empty or the query errors.

Inside `get_dashboard_leaderboards`, the line that builds `excluded_logins` from the hardcoded set
is swapped to build it from `await get_known_bots_list()` instead. The existing union with the
per-channel `watch_time_excluded_users` row stays exactly as it is.

### 3. Board coverage

The same `LOWER(<col>) NOT IN (<placeholders>)` filter — built once from `excluded_logins` — is
applied to every person-keyed board in the endpoint:

| Board | Table | Name column |
| --- | --- | --- |
| Watch time | `watch_time` | `username` (already filtered) |
| Chat leaders | `message_counts` | `username` (already filtered) |
| Streaks | `analytic_stream_watch_streak` | `user_name` |
| Hugs | `hug_counts` | `username` |
| Kisses | `kiss_counts` | `username` |
| Highfives | `highfive_counts` | `username` |

Non-person boards (commands, rewards, deaths-by-game, songs) are untouched. Filtering happens in
SQL **before `LIMIT`**, so each board still returns a full set of real users.

### 4. Admin UI — `./dashboard/admin/known_bots.php` (new)

This page clones the proven structure of `./dashboard/admin/api_keys.php`.

- **Boot sequence:** `ob_start()` → `session_bootstrap.php` → `admin_access.php` (gates
  `is_admin = 1`) → i18n → `db_connect.php` (`$conn`, website DB) → `userdata.php` →
  `session_write_close()`.
- **AJAX POST handlers**, dispatched on the request method and the posted action:
  - `add_bot` — normalize the login (`strtolower`, strip a leading `@`, `trim`), validate it
    against `^[a-z0-9_]{1,25}$`, then a prepared `INSERT`. A UNIQUE-key collision is surfaced as a
    friendly duplicate error rather than a raw SQL failure. `notes` is optional. `added_by` is set
    to the current admin's username.
  - `delete_bot` — prepared `DELETE` by `id`.
  - `toggle_bot` — prepared `UPDATE ... SET is_active = ?` by `id`.
  - Every handler validates first, then clears any buffered output, sets the JSON content type,
    echoes a `{success, message}` payload, and exits.
  - Each successful action writes an audit entry via `admin_audit_log` under the
    `known_bot_add` / `known_bot_delete` / `known_bot_toggle` actions, with the affected
    `bot_login` as the target.
- **Render:** a table of existing bots (login, active toggle, added_by, created_at, delete button)
  plus an add form. The JS uses `fetch` + `FormData`, updates the table on success, and shows
  errors through the existing alert mechanism (e.g. `Swal`). Seed rows (`added_by='system'`) are
  deletable like any other row — admins own the list.
- **No CSRF token**, consistent with all current admin pages (session + `is_admin` gate).

### 5. Navigation, i18n, CSS

- Register the page in the `$admin` array in `./dashboard/menu.php`, with a `t('menu_admin_known_bots')`
  label, a `fas fa-robot` icon, and `known_bots.php` as the href.
- Add i18n keys to `./dashboard/lang/en.php` (base) **and** `de.php` + `fr.php`:
  `menu_admin_known_bots`, `known_bots_title`, `known_bots_intro`, `known_bots_add`,
  `known_bots_login_label`, `known_bots_notes_label`, `known_bots_col_added_by`,
  `known_bots_col_active`, `known_bots_col_created`, `known_bots_delete`,
  `known_bots_confirm_delete`, plus the success/error message strings the handlers return.
  (French apostrophes need escaping; edited PHP should pass `php -l`.)
- Component styles live in `./dashboard/css/dashboard.css` using the existing theme tokens — no
  inline styles, no page `<style>` block.

## Error handling

- `get_known_bots_list()` returns `DEFAULT_KNOWN_BOTS_SEED` on an empty result or any DB error,
  and logs a warning — leaderboards always have a working filter.
- `ensure_known_bots_table()` failures are logged but must not crash startup (wrap it; the seed
  fallback covers reads regardless).
- Admin handlers return `{success:false, message:...}` for invalid or duplicate logins; no raw SQL
  errors leak to the client.

## How we'll know it's right

Each touched file should pass its language's syntax check (`api.py` for Python, and the new PHP
page, `menu.php`, and the three lang files for PHP). Beyond that, the behaviour to confirm:

- On the API's first startup the table is created and seeded, and a count of the rows matches the
  size of the seed set.
- Adding a bot in the admin page makes it disappear from every leaderboard within the cache TTL
  (≤ 5 minutes); toggling `is_active` off brings it back.
- Emptying the table entirely still leaves leaderboards filtering against the seed — proving the
  fallback path.
- Per-channel `watch_time_excluded_users` additions continue to take effect, confirming the union
  with the global registry is preserved.

## File change list (Phase 1)

| File | Change |
| --- | --- |
| `./api/api.py` | Rename the set to `DEFAULT_KNOWN_BOTS_SEED`; add the cache holder, TTL, `get_known_bots_list()`, and `ensure_known_bots_table()`; call the latter from `lifespan`; use the cached list and extend filtering to Streaks + the 3 interaction boards in `get_dashboard_leaderboards`. |
| `./dashboard/admin/known_bots.php` | **New** admin page (CRUD + audit). |
| `./dashboard/menu.php` | Add the `$admin` menu entry. |
| `./dashboard/lang/en.php`, `de.php`, `fr.php` | Add the i18n keys. |
| `./dashboard/css/dashboard.css` | Add component styles for the page. |

## Phase 2 (outline — separate spec)

`beta.py` and `beta-v6.py` gain a cached loader for `website.known_bots` (the bot reads `website`
via `mysql_connection("website")`), consumed — **unioned with** the existing per-channel lists,
never replacing them — at:

- **Watch-time tracking** (`track_watch_time`) — union with `watch_time_excluded_users`.
- **Points** award path — union with `bot_settings.excluded_users`.
- **Welcome / shoutout** — union with `IGNORED_WELCOME_USERNAMES`.

That phase needs a bot deploy and wider testing. Stable `bot.py` is excluded by policy. Discord
(`specterdiscord.py`) and Kick (`kick.py`) can read the same table later if we decide it's useful.

## Notes / constraints honored

- PHP config rule: the page uses `./config/` includes (`db_connect.php`), never `.env`.
- DB rule: parameterized queries throughout; `website` scope via `get_mysql_connection()` /
  `db_connect.php`, never per-user.
- Secrets rule: no credentials touched.
