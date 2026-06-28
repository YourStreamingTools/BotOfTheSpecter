# Admin-Editable Known-Bots Registry — Phase 1 Plan

**Goal:** Move the hardcoded `KNOWN_BOT_LOGINS` set out of the API code and into an admin-editable `known_bots` table in the central `website` DB, so admins manage the bot-exclusion list from the dashboard instead of via a code change. The dashboard leaderboards read this list (cached) when filtering bots out of person-keyed boards.

**Architecture:** A new `website.known_bots` table (one row per login) is created and seeded once at API startup. The PHP admin page `dashboard/admin/known_bots.php` does CRUD against it directly (the same shape as `api_keys.php`). The FastAPI server only *reads* it, through a short-TTL cached helper that falls back to the original hardcoded seed if the table is empty or unreachable. The `/dashboard/leaderboards` endpoint uses that helper and applies the bot filter to all boards that are keyed by a person's username.

**Tech stack:** Python 3 / FastAPI / aiomysql (API), PHP 8 / mysqli (dashboard), MySQL, vanilla JS + SweetAlert (`Swal`) on the admin page.

**Spec:** `.claude/specs/2026-06-19-admin-known-bots-registry.md`

## Constraints and ground rules

- **No test framework in this repo.** Correctness is confirmed by each touched file passing its language's syntax check (Python compile, PHP lint) plus the functional checks at the end. Don't introduce pytest/PHPUnit.
- **PHP never reads `.env`.** Config comes from `./config/{service}.php` (dev) → `/var/www/config/` (server). The admin page gets its DB handle from `db_connect.php`.
- **Parameterized queries only**, no string-interpolated values. The `known_bots` table lives in the central `website` DB, so Python uses `get_mysql_connection()` (not the per-user `get_mysql_connection_user()`), and PHP uses the `$conn` from `db_connect.php`.
- **Paths:** repo paths use `./`; server runtime paths live under `/var/www/...`. The admin page's `require_once` lines use the server absolute paths, matching the sibling admin pages.
- **Bot files are untouched in Phase 1** (`bot.py` / `beta.py` / `beta-v6.py`). Bot-side consumption of this list is Phase 2.
- **Login validation:** a Twitch login is normalized to lowercase with a leading `@` stripped, and must match `^[a-z0-9_]{1,25}$`.
- **Cache:** 300-second TTL; on an empty result or any DB error, fall back to the seed so the filter always has data.
- **Admin gate:** `is_admin` via `admin_access.php` — not `super_admin`.

A note on where the schema "lives": the `./help` folder is deprecated, so the table's durable definition is the startup routine in `api.py` rather than a canonical schema file. This matches how `admin_api_keys` and `bot_chat_token` already exist — created at runtime, with no canonical-schema-file entry. The admin PHP page also issues a defensive `CREATE TABLE IF NOT EXISTS` so it never depends on the API having run first.

---

## Files affected

| Area | File | Change |
| ---- | ---- | ------ |
| API | `./api/api.py` | Rename the hardcoded set to a seed; add the table DDL, the cache, the read-through helper, and the startup ensure/seed; call the ensure routine in `lifespan`; point the leaderboards filter at the DB-backed list; extend the filter to the streaks and three interaction boards. |
| Dashboard | `./dashboard/admin/known_bots.php` (new) | Admin CRUD page: add / enable-disable / delete bot logins, audit-logged. |
| Dashboard | `./dashboard/menu.php` | Register the page in the admin menu. |
| Dashboard | `./dashboard/lang/en.php`, `de.php`, `fr.php` | Add the i18n key block. |
| Dashboard | `./dashboard/css/dashboard.css` | Add the status-badge styles. |

---

## Work item 1 — API: DB-backed known-bots infrastructure

The first job is to replace the hardcoded `KNOWN_BOT_LOGINS` set in `api.py` with the table, the seed, the cache, and the read/ensure helpers, then wire those into startup and the leaderboards endpoint. After this work item the leaderboards still behave exactly as before — only the *source* of the exclusion list moves from a constant to the DB. Coverage of additional boards comes in the next work item.

**Rename the set to a seed.** The existing `KNOWN_BOT_LOGINS = { ... }` block becomes `DEFAULT_KNOWN_BOTS_SEED` with byte-for-byte the same logins (botofthespecter, nightbot, streamelements, streamlabs, moobot, fossabot, wizebot, and the rest — roughly 41 entries). The seed serves two purposes: it populates an empty table on first deploy, and it's the fallback when the table is empty or unreachable. A comment should point readers at the admin page as the place the live list is actually edited.

**Define the table.** One row per login in the central `website` DB. The shape:

```sql
CREATE TABLE IF NOT EXISTS known_bots (
    id INT NOT NULL AUTO_INCREMENT,
    bot_login VARCHAR(50) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    added_by VARCHAR(50) DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_bot_login (bot_login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

The unique key on `bot_login` is what gives us duplicate detection (the admin page keys off the resulting MySQL error 1062). `is_active` lets an admin disable a login without deleting it.

**Add the read-through cache.** A small module-level cache holds the loaded-at timestamp and a frozenset of active logins, with a 300-second TTL — so admin edits take effect within that window without hammering the DB on every leaderboards request. The async read helper (`get_known_bots_list`) returns a lowercased set of active logins: it serves the cache while fresh, otherwise selects `bot_login` from `known_bots WHERE is_active = 1`, lowercases and strips each row, and refreshes the cache. If the query yields nothing, or anything throws, it logs a warning and returns the seed instead. This is the single function the leaderboards endpoint (and, later, Phase 2) consumes.

**Add the startup ensure/seed.** An idempotent async routine (`ensure_known_bots_table`) runs the `CREATE TABLE IF NOT EXISTS`, counts the rows, and — only if the table is empty — bulk-inserts the seed logins with `added_by = 'system'`. Seeding an empty table on first deploy is what keeps leaderboard filtering identical to the old hardcoded behaviour. Any failure here is logged rather than fatal.

**Wire it into startup.** Call the ensure routine inside the `lifespan` handler, right alongside the other table-ensure calls (next to the existing `ensure_custom_webhooks_table()` call), so the table is guaranteed to exist and be seeded before any request is served.

**Point the leaderboards filter at the DB.** In `get_dashboard_leaderboards`, the line that built the exclusion set from the hardcoded constant now awaits `get_known_bots_list()` instead. Everything else in the endpoint — the union with the per-channel `watch_time_excluded_users`, the `bot_ph` placeholder string, and the pre-existing watch-time and chat filters — stays as it is. That keeps current behaviour intact while the broader coverage lands separately.

When this is done, the old `KNOWN_BOT_LOGINS` name should no longer appear anywhere in `api.py` (its only two uses were the definition and the leaderboards line, both replaced), and the file should still compile.

---

## Work item 2 — API: extend the bot filter to every person-keyed board

The leaderboards endpoint already builds the exclusion filter once as a `bot_ph` placeholder string plus an `excluded_params` tuple, but only the watch-time and chat boards use it. Bots can still show up on the Streaks board and the three interaction-leader boards. This work item applies the same `LOWER(<col>) NOT IN (<placeholders>)` filter to those four queries so a bot is hidden everywhere a username appears.

- **Streaks board** (`analytic_stream_watch_streak`): add the filter to the existing `ORDER BY highest_streak DESC` query. The column here is `user_name` (not `username`) — easy to get wrong, so the filter must target `LOWER(user_name)`.
- **Interaction boards** (`hug_counts`, `kiss_counts`, `highfive_counts`): each of the three queries keys on `username`, so each gets `WHERE LOWER(username) NOT IN (<placeholders>)`. They reuse the same `bot_ph` / `excluded_params` already computed earlier in the function, combined with the interaction limit (`ilim`).

A representative filtered query looks like:

```sql
SELECT username, hug_count AS c FROM hug_counts
WHERE LOWER(username) NOT IN (<bot placeholders>)
ORDER BY hug_count DESC LIMIT <limit>
```

After this, all five person-keyed boards (watch time, chat/message counts, streaks, and the three interaction boards) carry the filter, and the file should still compile.

---

## Work item 3 — Dashboard admin page (`known_bots.php`)

Create the admin CRUD page, modelled closely on `dashboard/admin/api_keys.php` so it inherits the established admin-page conventions. It lets an admin add a bot login, enable/disable one, and delete one, with every mutation audit-logged, and it renders with the existing `sp-*` component classes.

**Bootstrap and gate.** The page bootstraps the session, requires `admin_access.php` (which enforces the `is_admin` gate and provides `admin_audit_log(...)`), resolves the user's language, includes the i18n layer, and connects via `db_connect.php`. As a safety net it runs the same `CREATE TABLE IF NOT EXISTS` as the API, so the page works even on a box where the API hasn't created the table yet.

**POST actions.** The page handles three POST actions and replies as JSON (a small helper clears the output buffer, sets the JSON content type, and exits):

- `add_bot` — normalizes the login (trim, strip leading `@`, lowercase), validates it against `^[a-z0-9_]{1,25}$`, and inserts via a prepared statement with `added_by` set to the current admin's username and optional notes. A unique-key violation (MySQL errno 1062) returns the "already in the list" message; other failures return a generic error carrying the errno. On success it returns the new row's id, login, and `added_by` so the client can append a row without reloading.
- `toggle_bot` — flips `is_active` for a given id (coerced to 0/1) via a prepared `UPDATE`.
- `delete_bot` — deletes by id via a prepared `DELETE`; a zero affected-row count is treated as "that entry no longer exists."

Each successful action writes an audit row through `admin_audit_log` with a `known_bot_*` action name, the relevant detail payload, and the login or id as the target.

**Initial render.** On a normal GET the page selects all rows ordered by `bot_login` and renders them into an `sp-table`: login, a status pill (active/disabled), who added it, and an actions cell with enable/disable and delete buttons. When the table is empty it shows an `sp-alert` empty-state instead, and the JS reveals the table the moment the first bot is added.

**Client behaviour.** A `DOMContentLoaded` script wires up the add form and the per-row buttons. All user-facing strings are passed in from PHP via `t(...)` into a JS `I18N` object (so the JS holds no hardcoded English), and a small `esc()` helper guards against HTML injection when building rows client-side. Add submits the form over `fetch`, appends the returned row, and resets the form. Toggle flips the row's status pill, button label, and icon in place. Delete asks for confirmation through `Swal` (the confirm text interpolates the login), then removes the row on success. Failures surface as a `Swal` error dialog.

The page closes by capturing its markup into `$content` and including the shared `layout.php`, the same as the other admin pages. It should pass `php -l`.

---

## Work item 4 — Navigation, i18n, and styling

The final pieces register the page, give it translated strings, and add the one small style it needs.

**Menu entry.** Add a single entry to the `$admin` array in `dashboard/menu.php`, placed just after the `spam_patterns.php` entry, labelled via `t('menu_admin_known_bots')` with a `fas fa-robot` icon and `known_bots.php` as the href.

**i18n keys.** Add a block of `admin_known_bots_*` keys (plus `menu_admin_known_bots`) to `en.php`, `de.php`, and `fr.php`, sitting alongside the other `admin_*` keys. The block covers, in each language:

- the page title and intro paragraph (explaining that these accounts are hidden from every channel's leaderboards — watch time, chat, streaks, interactions);
- the add-form pieces (login label/placeholder/help text, notes label/placeholder, add button);
- the existing-list heading, empty state, and table headers (login, status, added by, actions);
- the status labels (active/disabled), and the enable/disable/delete button labels;
- the server-side messages (login empty, login invalid, duplicate, not found, generic `%s` error, and the added/deleted/toggled success messages);
- the JS dialog strings (error title, delete confirm title/text/button, cancel, deleted title, and the add/delete/toggle failure messages).

The English copy is the source of truth; German and French are full translations. The French file needs its apostrophes escaped (`\'`) since the strings are single-quoted PHP — that file is the one most likely to fail a lint if an apostrophe slips through. All four edited PHP files should pass `php -l`.

**Status-badge styles.** Append a small `.kb-status` pill style to `dashboard.css` using existing theme tokens — a green-tinted variant for active and a muted variant for disabled — rather than a hardcoded palette. If the expected tokens (`--green`, `--text-muted`, `--radius-pill`, or `color-mix`) aren't present, fall back to the nearest tokens the sibling badge/alert components already use; confirm by grepping `dashboard.css` before editing.

---

## Functional verification

These checks need a running API, dashboard, and DB, so they happen on a deploy/staging box rather than the dev machine. We'll know Phase 1 is correct when:

1. After an API restart, `website.known_bots` exists and is seeded — `SELECT COUNT(*) FROM website.known_bots;` returns roughly the seed-set size (~41).
2. `dashboard/admin/known_bots.php` renders the seeded list for an admin, and `admin_access.php` denies a non-admin.
3. Adding a test login (e.g. `mytestbot`) makes it disappear from every leaderboard board within the cache TTL (≤5 min); disabling it brings it back; deleting it removes it for good.
4. A per-channel `watch_time_excluded_users` entry still takes effect — confirming the union with the per-channel list is preserved, not replaced.
5. Audit rows are written for each action — `SELECT action, target_value FROM website.admin_audit_log WHERE action LIKE 'known_bot_%' ORDER BY id DESC LIMIT 5;` shows the add/toggle/delete entries.

## Phase 2 (separate plan — out of scope here)

Wire `beta.py` and `beta-v6.py` to read `website.known_bots` (cached) and union it into watch-time tracking, points, and welcome/shoutout handling. Stable `bot.py` stays excluded by policy. That phase requires a bot deploy and is planned separately.
