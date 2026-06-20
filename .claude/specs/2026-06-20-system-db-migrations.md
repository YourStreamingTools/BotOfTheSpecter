# System-DB Migrations — Design Spec (Phase 3)

**Date:** 2026-06-20
**Status:** Draft
**Context:** Phase 3 of the known-bots/infra work. See [[project_db_migrations_admin_page]].

## Problem

Schema for the **system / shared databases** (`website`, `specterdiscordbot`, `roadmap`, `spam_pattern`, …)
is created and altered ad-hoc, scattered across the codebase:

- Defensive `CREATE TABLE IF NOT EXISTS` duplicated in both `api.py` (startup `ensure_*_table()`)
  **and** PHP admin pages (`known_bots.php`, `webhooks.php`, `feedback.php`, `admin_access.php`,
  `system_metrics`) — run silently on startup / every page load.
- Tables that are simply **assumed to exist** (`admin_api_keys`, `bot_chat_token`) with no DDL in repo.
- At least one migration written as a **code comment** in `users.php`
  (`ALTER TABLE users ADD COLUMN is_deceased …`) that a human must run by hand.
- **No version tracking, no runner, no rollback, no review** anywhere.

The result is "dead code that makes a table if it doesn't exist" sprinkled through scripts and pages,
with no single place to review or apply schema changes.

> The **per-user** databases are explicitly out of scope — they remain managed by
> `dashboard/includes/usr_database.php` (107-table declarative auto-migrator). Phase 3 governs only
> the shared/system databases.

## Decisions (locked with the user)

| Decision | Choice |
| --- | --- |
| Scope | System/shared DBs only (`website`, `specterdiscordbot`, `roadmap`, `spam_pattern`, extensible). Per-user DBs untouched. |
| Migration model | **Versioned migration files** (ordered, named, with up + down), reviewed and applied via an admin page. |
| Tracking | **Per-DB** `schema_migrations` table (each managed DB carries its own ledger). |
| v1 retirement scope | **`website` DB fully** — bring its tables under migrations and remove its defensive CREATEs. Other system DBs are **registered with a baseline** (tracked) but their existing creators are retired in a later pass. |
| Apply model | **Manual review-then-apply** via the page. Nothing auto-runs on page load or deploy. |
| Edit access | `is_admin` (same gate as `known_bots.php` / `api_keys.php`), audited. |

## Goals

- One reviewable place (`dashboard/admin/migrations.php`) to see, review, and apply/roll back schema
  changes per managed DB.
- Schema changes become **committed migration files**, not scattered defensive CREATEs.
- The `website` DB's current schema is captured as a baseline migration; existing environments
  **adopt** it without disruption; the redundant defensive CREATEs are removed.
- Safe on production: idempotent baseline, destructive-change confirmation, checksum drift detection,
  full audit trail, ordered application.

## Non-goals

- **Per-user DB schema** (stays in `usr_database.php`).
- **Auto-apply on deploy / page load.** Migrations apply only on explicit admin action (review first).
- **A free-form SQL console.** Migrations are committed files, not hand-typed SQL in the UI.
- Retiring the existing creators of `specterdiscordbot`/`roadmap`/`spam_pattern` (later phase — v1 only
  registers + baselines them).
- Migrating the two **foundational bootstrap tables** away from on-demand creation (see Architecture §3).

## Architecture

### 1. Migration files

Location: `./migrations/{db}/{YYYYMMDD}_{NNNN}_{slug}.php` (one folder per managed DB) — **outside the
public web root** (`dashboard/`). On the server this is `/var/www/migrations/{db}/`; the runner
resolves it absolutely (with a repo-root dev fallback) so the DDL files are never web-servable.
Each file `return`s a definition:

```php
<?php
return [
    'description' => 'Create known_bots registry',
    'up'   => [
        "CREATE TABLE IF NOT EXISTS known_bots ( ... ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    'down' => [
        "DROP TABLE IF EXISTS known_bots",
    ],
];
```

- `up` / `down` are **ordered arrays of SQL strings** — the common, fully-reviewable case (the page
  renders them verbatim before applying).
- For data backfills / renames / conditional logic, `up` (and `down`) may instead be a
  `callable(mysqli $conn): void`. Such a migration is flagged **"procedural"** in the UI (its effect
  can't be shown as plain SQL); the file should also set a `'preview' => 'human description'`.
- Migrations are ordered by filename. `migration_id` = the filename stem (e.g.
  `20260620_0001_create_known_bots`).
- Procedural migrations get helper functions from the runner: `column_exists($conn,$t,$c)`,
  `index_exists($conn,$t,$i)`, `table_exists($conn,$t)` — so guarded `ALTER`s (e.g. the
  `users.is_deceased` migration) are safe to re-run / adopt.

### 2. Managed-DB registry

`./config/migrations.php` returns the list of system DBs under management and their connection target:

```php
<?php
return [
    'website'      => ['label' => 'Website'],
    'specterdiscordbot'   => ['label' => 'Discord Bot'],
    'roadmap'      => ['label' => 'Roadmap'],
    'spam_pattern' => ['label' => 'Spam Patterns'],
];
```

All are the same MySQL server, different db name; credentials come from the existing
`config/database.php` (PHP rule: config via `./config/*.php`, never `.env`). Per-user DBs are **not**
listed here. Adding a future system DB = add a key + a `migrations/{db}/` folder.

### 3. Tracking table (per DB) + foundational bootstrap

Each managed DB gets its own ledger, created by the runner on first access (the migration system's
own bookkeeping — the one CREATE it must still do defensively):

```sql
CREATE TABLE IF NOT EXISTS schema_migrations (
    id           INT NOT NULL AUTO_INCREMENT,
    migration_id VARCHAR(191) NOT NULL,
    name         VARCHAR(255) NULL,
    checksum     CHAR(32) NOT NULL,          -- md5 of the migration file at apply time
    applied_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    applied_by   VARCHAR(50) NULL,           -- admin username
    PRIMARY KEY (id),
    UNIQUE KEY uniq_migration (migration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Two tables remain ensured-on-demand (intentionally, not "dead code"):** `schema_migrations`
(the migration ledger itself — can't migrate its own bookkeeping into existence) and
`admin_audit_log` (the audit ledger the whole admin panel + this page depend on, created in
`admin_access.php`). Everything else moves into migrations. This boundary is stated so the line is
principled, not accidental.

### 4. Runner library

`./dashboard/includes/migration_runner.php` — pure logic, no auto-execution on include:

- `migration_connect($db)` → mysqli to a managed DB (validates `$db` is in the registry; db name
  validated against `^[a-z0-9_]{1,64}$` like `usr_database.php`).
- `migration_ensure_ledger($conn)` → create `schema_migrations` if missing.
- `migration_scan($db)` → read + parse files in `migrations/{db}/`, return ordered list with id,
  description, up/down, procedural flag, checksum.
- `migration_status($db)` → join scanned files against the ledger → `applied[]`, `pending[]`,
  `drifted[]` (applied but file checksum changed), `missing[]` (in ledger but file gone).
- `migration_apply($db, $migration_id, $confirmDestructive)` → run `up` in order; on any error abort
  (do **not** record); on success insert ledger row + `admin_audit_log('migration_apply', …)`.
- `migration_rollback($db, $migration_id)` → only the **last applied** migration per DB (LIFO); run
  `down`; on success delete ledger row + audit.
- `migration_adopt_baseline($db)` → for an existing environment: if the ledger is empty and the
  baseline's tables already exist, record the baseline as applied **without running it** (so prod
  isn't disrupted when the defensive CREATEs are removed). Surfaced in the UI as "Adopt baseline".
- **Destructive guard:** `up` containing `DROP` / `TRUNCATE` / `DELETE` (word-boundary, case-insensitive)
  requires `$confirmDestructive` = true (UI double-confirm).
- Nothing here runs unless the page (or an opt-in CLI) calls it.

### 5. Admin page — `./dashboard/admin/migrations.php`

Clones the `known_bots.php` boot/pattern exactly: `ob_start` → `session_bootstrap.php` →
`admin_access.php` (is_admin) → i18n → `db_connect.php` → `userdata.php` → `session_write_close`.

UI (one section per managed DB):
- Header: DB label + counts (`N applied · M pending · drift?`).
- Pending migrations: id + description, **Review SQL** (expands up/down, or shows the procedural
  preview + a "procedural" badge), **Apply**. Destructive ones show a warning + require a typed/extra
  confirm.
- Applied migrations: ✓ id + applied_at + applied_by; the most recent shows **Roll back**.
- Drift / missing warnings called out (never auto-fixed).
- **Apply all pending** (runs pending in order, stops at first failure). Destructive migrations are
  **skipped** by Apply-all and must be applied individually with their confirm step, so a bulk apply
  can never silently run a `DROP`/`TRUNCATE`/`DELETE`.
- AJAX POST handlers (`apply` / `rollback` / `apply_all` / `adopt_baseline`) → JSON + `exit`, each
  `admin_audit_log`-ed. Registered in `menu.php` `$admin`; i18n keys in en/de/fr; styles in
  `dashboard.css`.

### 6. v1 content — `website` DB

- `migrations/website/20260620_0001_baseline.php` — idempotent `CREATE TABLE IF NOT EXISTS` for the
  current website tables: `known_bots`, `custom_webhooks`, `feedback`, `system_metrics`,
  `freestuff_games`, **and the previously-assumed** `admin_api_keys`, `bot_chat_token` (their DDL is
  finally written here; the implementer must confirm it matches live production before this ships).
  `down` is intentionally empty/guarded (we don't drop core tables on rollback of a baseline).
- `migrations/website/20260620_0002_add_users_is_deceased.php` — **procedural**, uses
  `column_exists()` to add `users.is_deceased` + `users.deceased_date` only if missing (replaces the
  hand-comment in `users.php`).
- **Retire the website defensive CREATEs:** remove `ensure_custom_webhooks_table()`,
  `ensure_known_bots_table()`, their `lifespan` calls, the `*_TABLE_DDL` constants, and the on-demand
  `freestuff_games` CREATE in `save_freestuff_game()` from `api.py`; remove the defensive
  `CREATE TABLE IF NOT EXISTS` blocks from `known_bots.php`, `webhooks.php`, `feedback.php`, and the
  `system_metrics` creator. (`admin_audit_log` bootstrap stays — see §3.)
- Other system DBs: create `migrations/{specterdiscordbot,roadmap,spam_pattern}/20260620_0001_baseline.php`
  capturing their current schema, registered + adoptable, but their existing creators (e.g. roadmap's
  `initializeRoadmapDatabase()`) are **not** removed in v1.

### 7. Deployment / adoption flow

1. Deploy the code (runner + page + migration files + the api.py/PHP removals).
2. In `migrations.php`, each managed DB shows its baseline as **pending**; an existing prod DB shows
   **Adopt baseline** (tables already present) → one click records it applied without running.
3. `website/0002` (is_deceased) shows pending → review → Apply.
4. Thereafter, every schema change = a new migration file → appears pending → reviewed → applied.

Because the defensive CREATEs are removed, **the baseline must be adopted/applied in every
environment** as part of deploy. `migration_adopt_baseline` makes that a safe one-click no-op on
existing DBs; a fresh DB runs the baseline to create everything.

## Error handling

- Apply aborts on the first failing statement and does **not** record the migration (so a partial
  apply is re-runnable after a fix; authors keep `up` idempotent where possible).
- All managed-DB connections validate the db name; failures are reported in the UI, never silent.
- Destructive migrations require explicit confirmation; checksum drift and ledger/file mismatches are
  surfaced as warnings and never auto-resolved.
- Audit every apply/rollback/adopt with actor + DB + migration_id via `admin_audit_log`.

## Verification

- `php -l` on the new/changed PHP (`migrations.php`, `migration_runner.php`, `config/migrations.php`,
  each migration file, edited admin pages, menu, lang).
- `python -m py_compile api/api.py` after removing the ensure_*/DDL/freestuff CREATE code.
- Functional (staging): fresh empty DB → baseline applies → all website tables exist. Existing DB →
  "Adopt baseline" records applied with no schema change. `0002` adds the columns idempotently
  (re-apply safe). Destructive confirm works. Rollback of last migration runs `down`. Removing the
  defensive CREATEs does not break the bot/API/dashboard once the baseline is adopted.

## File change list (v1)

| File | Change |
| --- | --- |
| `./config/migrations.php` | **New** managed-DB registry. |
| `./dashboard/includes/migration_runner.php` | **New** runner library (scan/status/apply/rollback/adopt + helpers + guards). |
| `./dashboard/admin/migrations.php` | **New** admin page (review/apply/rollback/adopt UI). |
| `./migrations/website/20260620_0001_baseline.php` | **New** website baseline. |
| `./migrations/website/20260620_0002_add_users_is_deceased.php` | **New** procedural ALTER migration. |
| `./migrations/{specterdiscordbot,roadmap,spam_pattern}/20260620_0001_baseline.php` | **New** baselines (tracked, adoptable). |
| `./dashboard/menu.php` | Add `migrations.php` to the `$admin` menu. |
| `./dashboard/lang/{en,de,fr}.php` | i18n keys. |
| `./dashboard/css/dashboard.css` | Page styles. |
| `./api/api.py` | Remove `ensure_custom_webhooks_table`/`ensure_known_bots_table` + lifespan calls + `*_TABLE_DDL` + the `freestuff_games` defensive CREATE. |
| `./dashboard/admin/known_bots.php`, `webhooks.php`, `feedback.php` | Remove defensive `CREATE TABLE IF NOT EXISTS` blocks. |
| `system_metrics` creator (`specterbotsystems/index.php`) | Remove defensive CREATE (now in baseline). |

## Constraints honoured

- PHP config via `./config/*.php`, never `.env`.
- DB: parameterized queries for ledger reads/writes; migration DDL is trusted committed code (not user
  input); db names validated.
- Admin gate `is_admin`; every state change audited.
- Per-user DBs untouched (`usr_database.php`).

## Open question for review

The baseline DDL for the **assumed-to-exist** tables (`admin_api_keys`, `bot_chat_token`) must be
authored to match live production exactly. The plan will require capturing their real `SHOW CREATE
TABLE` from prod before finalizing the baseline, rather than guessing. Flagging so it's a conscious
step, not an assumption.
