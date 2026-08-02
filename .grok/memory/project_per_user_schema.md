---
name: project_per_user_schema
description: "dashboard/usr_database.php is the CENTRAL per-user DB schema manager — add new per-user tables there, never inline"
metadata: 
  node_type: memory
  type: project
  originSessionId: d4b44555-49eb-42df-9fc2-ea389e3a05cb
---

**`dashboard/usr_database.php` is the single source of truth for per-user database schema.** (DB name = the Twitch username.) It is NOT "no migration runner" — an earlier memory claimed that and it was flat wrong.

How it works:
- Holds one big `$tables` array: `'table_name' => "CREATE TABLE IF NOT EXISTS ... ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"`.
- On run it: creates the per-user DB if missing, then for each table creates it if missing, AND auto-derives each column from the CREATE statement and `ALTER TABLE ... ADD` any missing column (automatic lightweight migrations).
- Runs per user via `$_SESSION['username']` when dashboard pages include it — so existing users get new tables/columns on their next dashboard load.
- `song_request_analytics` is defined here (~line 857), as is every other per-user table (`builtin_commands`, `bot_points`, `media_queue`, etc.).

**To add a new per-user table:** add ONE entry to the `$tables` array in `usr_database.php`, matching the house style. That's it. Do **NOT**:
- scatter `CREATE TABLE IF NOT EXISTS` inline in a dashboard page, the bot (`beta.py`/`beta-v6.py`), or a standalone migration script — that duplicates the schema and is the exact thing the user called out. Pages and the bot ASSUME tables exist (created centrally).
- For single-row settings tables (`id TINYINT PRIMARY KEY DEFAULT 1`), don't seed a row separately; let the first write self-seed via `INSERT ... ON DUPLICATE KEY UPDATE`.

**New overlays** must also be registered in `dashboard/overlays.php` (an `sp-card` listing the `overlay.botofthespecter.com/<file>.php?code=API_KEY_HERE` URL, with `t('overlays_*')` lang keys in en/de/fr) so users actually get the OBS URL.

Relates to [[feedback_dashboard_page_menu_registration]], [[feedback_dashboard_css_in_stylesheet]], and the database rule (`.grok/rules/database.md`).
