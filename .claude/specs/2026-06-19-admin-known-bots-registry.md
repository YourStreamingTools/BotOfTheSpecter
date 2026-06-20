# Admin-Editable Global Known-Bots Registry

**Date:** 2026-06-19
**Status:** Approved (Phase 1)

## Problem

The dashboard leaderboards (`dashboard.php` → API `GET /dashboard/leaderboards`) hide
bots from the watch/chat boards using a **hardcoded** Python set `KNOWN_BOT_LOGINS`
(the current 41 third-party bot logins) in `./api/api.py`. Editing that list means a code change
and an API deploy. We want admins to manage the list from the dashboard admin pages, and
we want it to become a **canonical global "these accounts are bots" registry** that other
systems can reuse over time.

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
- Behaviour is identical to today the moment it ships (table is seeded with the current 41 logins).

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

**Created at runtime** (matching how `freestuff_games` / `custom_webhooks` / `admin_api_keys` already exist — i.e. no canonical-schema-file entry; the `./help` folder is deprecated and must not be edited):

1. **Defensive runtime** `ensure_known_bots_table()` in `./api/api.py`, called from the
   `lifespan` startup handler immediately after `ensure_custom_webhooks_table()` (api.py:621).
   It runs `CREATE TABLE IF NOT EXISTS`, then if the table is empty, **seeds the current
   hardcoded set** (`DEFAULT_KNOWN_BOTS_SEED`) with `added_by='system'`, `is_active=1`.

   **Empty-table semantics:** seeding happens only on an *empty* table (at startup), and the
   read fallback (below) also triggers only on an *empty* result. So an admin removing a few
   entries works as expected (the remaining rows are authoritative), but a table emptied to
   zero rows re-applies the seed on the next read/restart — i.e. "filter nothing" is not a
   reachable state. That degenerate case is acceptable and intentional.

### 2. API read path — `./api/api.py`

- Rename the existing `KNOWN_BOT_LOGINS` set to `DEFAULT_KNOWN_BOTS_SEED` (same 41 entries).
  It becomes the seed source for `ensure_known_bots_table()` **and** the fallback for reads.
- Add the cached read-through, mirroring the Twitch-creds cache (api.py:180-219):

  ```python
  _known_bots_cache = {"loaded_at": 0.0, "bots": frozenset()}
  _KNOWN_BOTS_TTL = 300  # 5 min — admin edits take effect within this window

  async def get_known_bots_list() -> set:
      # returns a lowercased set of active bot logins;
      # falls back to DEFAULT_KNOWN_BOTS_SEED on empty result or any error
  ```

  Reads `SELECT bot_login FROM known_bots WHERE is_active = 1` via `get_mysql_connection()`
  (the `website` DB helper — **not** `get_mysql_connection_user()`).

- In `get_dashboard_leaderboards`: replace `excluded_logins = set(KNOWN_BOT_LOGINS)` with
  `excluded_logins = set(await get_known_bots_list())`, then keep the existing union with the
  per-channel `watch_time_excluded_users` row exactly as-is.

### 3. Board coverage

Apply the existing `LOWER(<col>) NOT IN (<placeholders>)` filter (built once from
`excluded_logins`) to every person-keyed board in the endpoint:

| Board | Table | Name column |
| --- | --- | --- |
| Watch time | `watch_time` | `username` (already filtered) |
| Chat leaders | `message_counts` | `username` (already filtered) |
| Streaks | `analytic_stream_watch_streak` | `user_name` |
| Hugs | `hug_counts` | `username` |
| Kisses | `kiss_counts` | `username` |
| Highfives | `highfive_counts` | `username` |

Non-person boards (commands, rewards, deaths-by-game, songs) are untouched. Filtering happens
in SQL **before `LIMIT`**, so each board still returns a full set of real users.

### 4. Admin UI — `./dashboard/admin/known_bots.php` (new)

Clone the proven `./dashboard/admin/api_keys.php` structure:

- **Boot sequence:** `ob_start()` → `session_bootstrap.php` → `admin_access.php` (gates
  `is_admin = 1`) → i18n → `db_connect.php` (`$conn`, website DB) → `userdata.php` →
  `session_write_close()`.
- **AJAX POST handlers** (`if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST[...]))`):
  - `add_bot` — normalize login (`strtolower`, strip leading `@`, `trim`), validate against
    `^[a-z0-9_]{1,25}$`, prepared `INSERT`, handle UNIQUE duplicate as a friendly error.
    Optional `notes`. Sets `added_by` = current admin username.
  - `delete_bot` — prepared `DELETE` by `id`.
  - `toggle_bot` — prepared `UPDATE ... SET is_active = ?` by `id`.
  - All: validate first, `ob_end_clean()`, `header('Content-Type: application/json')`,
    `echo json_encode(['success'=>..., 'message'=>...])`, `exit`.
  - Each successful action calls `admin_audit_log('known_bot_add'|'known_bot_delete'|
    'known_bot_toggle', 'success', [...], 'known_bot', $bot_login)`.
- **Render:** a table of existing bots (login, active toggle, added_by, created_at, delete
  button) + an add form. JS uses `fetch` + `FormData`, updates the table on success, shows
  errors via the existing alert mechanism (e.g. `Swal`). Seed rows (`added_by='system'`) are
  deletable like any other (admins own the list).
- **No CSRF token** — consistent with all current admin pages (session + `is_admin` gate).

### 5. Navigation, i18n, CSS

- Register in `./dashboard/menu.php` `$admin` array: `['label'=>t('menu_admin_known_bots'),
  'icon'=>'fas fa-robot', 'href'=>'known_bots.php']`.
- Add i18n keys to `./dashboard/lang/en.php` (base) **and** `de.php` + `fr.php`:
  `menu_admin_known_bots`, `known_bots_title`, `known_bots_intro`, `known_bots_add`,
  `known_bots_login_label`, `known_bots_notes_label`, `known_bots_col_added_by`,
  `known_bots_col_active`, `known_bots_col_created`, `known_bots_delete`,
  `known_bots_confirm_delete`, and the success/error message strings the handlers return.
  (Escape French apostrophes; gate edited PHP with `php -l`.)
- Component styles go in `./dashboard/css/dashboard.css` using existing theme tokens — no
  inline styles, no page `<style>` block.

## Error handling

- `get_known_bots_list()` returns `DEFAULT_KNOWN_BOTS_SEED` on empty result or any DB error,
  and logs a warning — leaderboards always have a working filter.
- `ensure_known_bots_table()` failures are logged but must not crash startup (wrap; the seed
  fallback covers reads anyway).
- Admin handlers return `{success:false, message:...}` for invalid/duplicate logins; no raw
  SQL errors leak to the client.

## Verification

- `python -m py_compile api/api.py` passes.
- `php -l` passes on `known_bots.php`, `menu.php`, and the three lang files.
- First API startup creates + seeds the table; `SELECT COUNT(*)` matches the seed-set size.
- Add a bot in the admin page → it appears on no leaderboard within ≤5 min (cache TTL);
  toggling `is_active` off restores it.
- Drop/empty the table → leaderboards still filter using the seed (fallback works).
- Per-channel `watch_time_excluded_users` additions still take effect (union preserved).

## File change list (Phase 1)

| File | Change |
| --- | --- |
| `./api/api.py` | Rename set → `DEFAULT_KNOWN_BOTS_SEED`; add `_known_bots_cache`, `_KNOWN_BOTS_TTL`, `get_known_bots_list()`, `ensure_known_bots_table()`; call it in `lifespan`; use cached list + filter Streaks + 3 interaction boards in `get_dashboard_leaderboards`. |
| `./dashboard/admin/known_bots.php` | **New** admin page (CRUD + audit). |
| `./dashboard/menu.php` | Add `$admin` menu entry. |
| `./dashboard/lang/en.php`, `de.php`, `fr.php` | Add i18n keys. |
| `./dashboard/css/dashboard.css` | Add component styles for the page. |

## Phase 2 (outline — separate spec)

`beta.py` + `beta-v6.py` gain a cached loader for `website.known_bots` (bot reads `website`
via `mysql_connection("website")`), consumed — **unioned with** the existing per-channel lists,
not replacing them — at:

- **Watch-time tracking** (`track_watch_time`) — union with `watch_time_excluded_users`.
- **Points** award path — union with `bot_settings.excluded_users`.
- **Welcome / shoutout** — union with `IGNORED_WELCOME_USERNAMES`.

Requires a bot deploy and wider testing. Stable `bot.py` excluded by policy. Discord
(`specterdiscord.py`) / Kick (`kick.py`) can read the same table later if desired.

## Notes / constraints honored

- PHP config rule: page uses `./config/` includes (`db_connect.php`), never `.env`.
- DB rule: parameterized queries throughout; `website` scope via `get_mysql_connection()` /
  `db_connect.php`, never per-user.
- Secrets rule: no credentials touched.
