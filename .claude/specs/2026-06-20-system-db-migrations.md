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

## Decisions (settled)

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

Migrations live at `./migrations/{db}/{YYYYMMDD}_{NNNN}_{slug}.php` (one folder per managed DB),
deliberately **outside the public web root** (`dashboard/`). On the server that resolves to
`/var/www/migrations/{db}/`; the runner resolves it absolutely (with a repo-root dev fallback) so the
DDL files are never web-servable. Each file `return`s a small definition array — a `description`, an
ordered `up` list of SQL strings, and a matching `down` list. The shape is intentionally tiny so the
page can render it verbatim before anything runs:

```php
return [
    'description' => 'Create known_bots registry',
    'up'   => [ "CREATE TABLE IF NOT EXISTS known_bots ( ... ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" ],
    'down' => [ "DROP TABLE IF EXISTS known_bots" ],
];
```

A few rules fall out of that format:

- `up` / `down` as **ordered arrays of SQL strings** is the common, fully-reviewable case — the page
  shows them exactly as written before applying.
- For data backfills, renames, or conditional logic, `up` (and `down`) may instead be a
  `callable(mysqli $conn): void`. Such a migration is flagged **"procedural"** in the UI, because its
  effect can't be shown as plain SQL; the file should also carry a `'preview'` string describing in
  human terms what it does.
- Migrations are ordered by filename, and the `migration_id` is the filename stem (e.g.
  `20260620_0001_create_known_bots`).
- Procedural migrations are handed guard helpers by the runner — `column_exists($conn,$t,$c)`,
  `index_exists($conn,$t,$i)`, `table_exists($conn,$t)` — so guarded `ALTER`s (for example the
  `users.is_deceased` migration) stay safe to re-run and safe to adopt.

### 2. Managed-DB registry

`./config/migrations.php` returns the list of system DBs under management and their connection target.
It's a flat map of db name to a small descriptor:

```php
return [
    'website'           => ['label' => 'Website'],
    'specterdiscordbot' => ['label' => 'Discord Bot'],
    'roadmap'           => ['label' => 'Roadmap'],
    'spam_pattern'      => ['label' => 'Spam Patterns'],
];
```

These are all the same MySQL server, different db name; credentials come from the existing
`config/database.php` (PHP rule: config via `./config/*.php`, never `.env`). Per-user DBs are **not**
listed here. Adding a future system DB means adding a key plus a `migrations/{db}/` folder — nothing
else.

### 3. Tracking table (per DB) + foundational bootstrap

Each managed DB gets its own ledger, which the runner creates on first access. This is the one CREATE
the migration system still has to do defensively, because it can't migrate its own bookkeeping into
existence. The ledger records what's been applied, when, by whom, and the checksum of the file at
apply time:

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

**Two tables stay ensured-on-demand on purpose, and this is not "dead code":** `schema_migrations`
(the ledger itself) and `admin_audit_log` (the audit ledger the whole admin panel and this page depend
on, created in `admin_access.php`). Everything else moves into migrations. I'm stating this boundary
explicitly so the line is principled rather than accidental.

### 4. Runner library

`./dashboard/includes/migration_runner.php` is pure logic — nothing executes just because it's
included. Its surface:

- `migration_connect($db)` — mysqli to a managed DB, after validating `$db` is in the registry and the
  db name matches `^[a-z0-9_]{1,64}$` (same pattern as `usr_database.php`).
- `migration_ensure_ledger($conn)` — create `schema_migrations` if missing.
- `migration_scan($db)` — read and parse the files in `migrations/{db}/`, returning an ordered list
  with id, description, up/down, procedural flag, and checksum.
- `migration_status($db)` — join the scanned files against the ledger into `applied[]`, `pending[]`,
  `drifted[]` (applied but the file's checksum changed), and `missing[]` (in the ledger but the file
  is gone).
- `migration_apply($db, $migration_id, $confirmDestructive)` — run `up` in order; on any error, abort
  and do **not** record it; on success, insert the ledger row and write an
  `admin_audit_log('migration_apply', …)` entry.
- `migration_rollback($db, $migration_id)` — only the **last applied** migration per DB (LIFO); run
  `down`; on success delete the ledger row and audit.
- `migration_adopt_baseline($db)` — for an existing environment: if the ledger is empty and the
  baseline's tables already exist, record the baseline as applied **without running it**, so prod isn't
  disrupted when the defensive CREATEs are removed. Surfaced in the UI as "Adopt baseline".
- **Destructive guard:** an `up` that contains `DROP` / `TRUNCATE` / `DELETE` (word-boundary,
  case-insensitive) requires `$confirmDestructive = true`, which the UI backs with a double-confirm.

Nothing in here runs unless the page (or an opt-in CLI) calls it.

### 5. Admin page — `./dashboard/admin/migrations.php`

The page clones the `known_bots.php` boot pattern exactly: `ob_start` → `session_bootstrap.php` →
`admin_access.php` (is_admin) → i18n → `db_connect.php` → `userdata.php` → `session_write_close`.

The UI has one section per managed DB:

- Header: DB label plus counts (`N applied · M pending · drift?`).
- Pending migrations: id + description, a **Review SQL** control (expands up/down, or shows the
  procedural preview plus a "procedural" badge), and **Apply**. Destructive ones show a warning and
  require a typed/extra confirm.
- Applied migrations: ✓ id + applied_at + applied_by; the most recent one offers **Roll back**.
- Drift / missing warnings are called out and never auto-fixed.
- An **Apply all pending** action runs pending migrations in order and stops at the first failure.
  Destructive migrations are **skipped** by Apply-all and must be applied individually with their
  confirm step, so a bulk apply can never silently run a `DROP`/`TRUNCATE`/`DELETE`.

The state changes go through AJAX POST handlers (`apply` / `rollback` / `apply_all` /
`adopt_baseline`), each returning JSON then `exit`, and each written to `admin_audit_log`. The page is
registered in `menu.php` under `$admin`, with i18n keys in en/de/fr and styles in `dashboard.css`.

### 6. v1 content — `website` DB

The first real content brings the `website` DB fully under migrations:

- `migrations/website/20260620_0001_baseline.php` — idempotent `CREATE TABLE IF NOT EXISTS` for the
  current website tables: `known_bots`, `custom_webhooks`, `feedback`, `system_metrics`,
  `freestuff_games`, **and the previously-assumed** `admin_api_keys`, `bot_chat_token`. Their DDL
  finally gets written down here, and that DDL has to be confirmed against live production before this
  ships (see the open question below). `down` is intentionally empty/guarded — we don't drop core
  tables on rollback of a baseline.
- `migrations/website/20260620_0002_add_users_is_deceased.php` — **procedural**, using
  `column_exists()` to add `users.is_deceased` and `users.deceased_date` only if they're missing. This
  replaces the hand-comment in `users.php`.
- **Retiring the website defensive CREATEs** is part of the same change: drop
  `ensure_custom_webhooks_table()` and `ensure_known_bots_table()`, their `lifespan` calls, the
  `*_TABLE_DDL` constants, and the on-demand `freestuff_games` CREATE inside `save_freestuff_game()`
  from `api.py`; and remove the defensive `CREATE TABLE IF NOT EXISTS` blocks from `known_bots.php`,
  `webhooks.php`, `feedback.php`, and the `system_metrics` creator. (`admin_audit_log` bootstrap stays
  — see §3.)
- The other system DBs each get a `migrations/{specterdiscordbot,roadmap,spam_pattern}/20260620_0001_baseline.php`
  capturing their current schema, registered and adoptable — but their existing creators (e.g.
  roadmap's `initializeRoadmapDatabase()`) are **not** removed in v1.

### 7. Deployment / adoption flow

The rollout is meant to be boring and safe:

1. Deploy the code (runner + page + migration files + the api.py/PHP removals).
2. In `migrations.php`, each managed DB shows its baseline as **pending**; an existing prod DB instead
   shows **Adopt baseline** (tables already present) → one click records it applied without running.
3. `website/0002` (is_deceased) shows pending → review → Apply.
4. From then on, every schema change is a new migration file → appears pending → reviewed → applied.

Because the defensive CREATEs are removed, **the baseline must be adopted/applied in every environment**
as part of deploy. `migration_adopt_baseline` makes that a safe one-click no-op on existing DBs, while a
fresh DB runs the baseline to create everything.

## Error handling

- Apply aborts on the first failing statement and does **not** record the migration, so a partial apply
  is re-runnable after a fix (authors keep `up` idempotent where possible).
- All managed-DB connections validate the db name; failures are reported in the UI, never silent.
- Destructive migrations require explicit confirmation; checksum drift and ledger/file mismatches are
  surfaced as warnings and never auto-resolved.
- Every apply / rollback / adopt is audited with actor + DB + migration_id via `admin_audit_log`.

## Verification

Before this ships, every new or changed PHP file — the page, the runner, `config/migrations.php`, each
migration file, the edited admin pages, the menu, and the language files — should pass a PHP lint check,
and `api/api.py` should still compile cleanly once the ensure_*/DDL/freestuff CREATE code is pulled out.

The behaviour I want to see on staging confirms the design holds together:

- A fresh, empty DB: applying the baseline creates every website table.
- An existing DB: "Adopt baseline" records it as applied with no schema change.
- `0002` adds the `is_deceased` / `deceased_date` columns idempotently — re-applying is a no-op.
- The destructive-change confirmation actually blocks until it's confirmed.
- Rolling back the last migration runs its `down`.
- With the defensive CREATEs gone, the bot, API, and dashboard all keep working once the baseline is
  adopted.

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

The baseline DDL for the **assumed-to-exist** tables (`admin_api_keys`, `bot_chat_token`) has to be
authored to match live production exactly. That means capturing their real `SHOW CREATE TABLE` from
prod before finalizing the baseline rather than guessing at it. Flagging it here so it stays a conscious
step instead of an assumption.
