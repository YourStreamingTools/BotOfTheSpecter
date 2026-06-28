# System-DB Migrations — Plan (Phase 3, v1)

**Goal:** Build a reviewable, versioned migration system for the shared/system databases — a runner library plus an admin page (`migrations.php`) — bring the `website` DB under it, and retire the scattered defensive `CREATE TABLE` code that currently bootstraps those tables at runtime.

**Architecture:** Ordered migration files per DB (`migrations/{db}/*.php`, each returning `up`/`down` SQL or a procedural callable); a per-DB `schema_migrations` ledger; a runner library (`migration_runner.php`) that scans, applies, rolls back, and adopts — but only on explicit admin action; and an `is_admin`-gated `migrations.php` page to review and apply. v1 captures the `website` baseline plus the `users.is_deceased` migration and removes the website-side defensive CREATEs.

**Tech stack:** PHP 8 / mysqli (runner, page, migration files) and Python/FastAPI (the `api.py` removals). No new libraries.

**Spec:** `.claude/specs/2026-06-20-system-db-migrations.md`

## Global constraints

- **No test framework.** There's no pytest/PHPUnit here and I'm not adding one. Correctness is established by each touched file passing its language's syntax check and by the functional checks described at the end.
- **PHP never reads `.env`** — config comes from `./config/*.php` (server: `/var/www/config/`). The runner pulls DB credentials from `config/database.php` (`$db_servername` / `$db_username` / `$db_password`).
- **Ledger reads/writes are parameterized** (prepared statements). Migration DDL itself is trusted, committed code. Managed DB names are validated against `^[a-z0-9_]{1,64}$` before any connection is opened.
- **Admin gate is `is_admin`** (via `admin_access.php`); every apply/rollback/adopt is written to `admin_audit_log`.
- **Scope is system DBs only** — `website`, `specterdiscordbot`, `roadmap`, `spam_pattern`. Per-user DBs stay with `usr_database.php`; that file is not touched.
- **Two bootstrap tables stay ensured-on-demand:** `schema_migrations` (created by the runner itself) and `admin_audit_log` (stays in `admin_access.php`). Everything else becomes a migration.
- **Removing the defensive CREATEs depends on the website baseline being adopted in every environment at deploy time.** That removal is the last piece of work and carries the warning explicitly.
- Repo paths use `./`; server runtime config lives at `/var/www/config/`. The runner resolves both.

---

## 1. Migration registry and runner library

**Files:** create `./config/migrations.php` (the registry) and `./dashboard/includes/migration_runner.php` (the runner).

These two files are the foundation everything else builds on. The runner exposes the surface the page and the migration files consume:

- `migration_registry(): array`
- `migration_status(string $db): array` returning `['applied' => [], 'pending' => [], 'missing' => []]`. Each migration entry carries `id, description, preview, up, down, procedural, destructive, checksum`, and — when applied — `applied_at, applied_by, drift`.
- `migration_apply(string $db, string $id, bool $confirmDestructive, ?string $appliedBy): void`
- `migration_rollback(string $db, string $id, ?string $appliedBy): void`
- `migration_adopt_baseline(string $db, ?string $appliedBy): void`
- Procedural helpers for migration files to use inside callable `up`/`down`: `migration_table_exists($conn, $t)`, `migration_column_exists($conn, $t, $c)`, `migration_index_exists($conn, $t, $i)` — each a parameterized `information_schema` lookup scoped to the current `DATABASE()`.

**The registry** is a small associative array mapping each managed DB key to a display label — `website`, `specterdiscordbot`, `roadmap`, `spam_pattern`. Bringing a new system DB under migrations means adding a key here and creating a matching `./migrations/{key}/` folder; nothing else. The runner prefers the server copy at `/var/www/config/migrations.php` and falls back to the repo copy.

**The runner is pure logic** — nothing runs on include. The design decisions that matter:

- **Migration files live outside the public web root.** On the server that's `/var/www/migrations/{db}/`; in dev it's the repo-root `./migrations/{db}/` (a sibling of `dashboard/`). Filenames follow `{YYYYMMDD}_{NNNN}_{slug}.php`, and the runner applies them in filename sort order — the numeric ordinal is the apply order.
- **Each migration file returns an array** of the shape `['description' => ..., 'up' => [sql, ...] | callable, 'down' => [sql, ...] | callable, 'preview' => ..., 'destructive' => bool]`. A list of SQL strings is the simple case; a callable receiving the `mysqli` connection is the procedural case for conditional/idempotent logic.
- **Connecting** first checks the requested DB is in the registry and matches the name regex, then loads credentials from `database.php` (server path preferred), opens a `mysqli` connection scoped to that DB, and sets the `utf8mb4` charset. An unknown/invalid name or a failed connection raises.
- **The ledger** is a per-DB `schema_migrations` table the runner creates on demand. Its essential columns:

  ```sql
  migration_id VARCHAR(191) NOT NULL UNIQUE,
  name         VARCHAR(255) NULL,
  checksum     CHAR(32)     NOT NULL,   -- md5 of the migration file
  applied_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  applied_by   VARCHAR(50)  NULL
  ```

- **Destructiveness** is inferred from `up` only: a procedural (callable) `up` is never auto-flagged; a SQL-list `up` is flagged destructive if any statement matches `DROP`/`TRUNCATE`/`DELETE`. A migration file may also set `destructive` explicitly.
- **`migration_scan`** globs the DB's migration folder, sorts by filename, includes each file to read its definition, and builds the metadata list — including the `md5_file` checksum used for drift detection.
- **`migration_status`** connects, ensures the ledger, reads the applied rows, scans the files, and partitions them: a file present in the ledger is *applied* (with `drift` set when the stored checksum no longer matches the file), a file absent from the ledger is *pending*, and a ledger row with no corresponding file is *missing* (a migration whose file was deleted after being applied).
- **`migration_apply`** locates the target, refuses a destructive migration unless `confirmDestructive` is set, refuses one already in the ledger, runs the `up` (procedural callable or each SQL statement in turn, stopping on error), then records the ledger row with the description, checksum, and `applied_by`.
- **`migration_rollback`** only permits rolling back the most recently applied migration — anything else raises. It runs the `down` and deletes the ledger row.
- **`migration_adopt_baseline`** records the first (lowest-ordered) migration as applied *without running it*. This is for existing environments whose tables already physically exist; it makes the ledger reflect reality so later migrations layer on cleanly. It raises if the baseline is already recorded.

Both new files should parse cleanly under `php -l`.

---

## 2. Admin page `migrations.php` (plus menu, i18n, CSS)

**Files:**

| File | Change |
| ---- | ------ |
| `./dashboard/admin/migrations.php` | create |
| `./dashboard/menu.php` | add an `$admin` entry after `spam_patterns.php` |
| `./dashboard/lang/en.php`, `de.php`, `fr.php` | add i18n keys |
| `./dashboard/css/dashboard.css` | add page styles |

The page consumes the runner's `migration_status` / `migration_apply` / `migration_rollback` / `migration_adopt_baseline` / `migration_registry`, the `admin_access.php` gate and `admin_audit_log()`, the `t()` translator, and `$username` (from `userdata.php`).

**Page behaviour.** It boots through the standard admin path: session bootstrap, the `admin_access.php` gate, i18n, the DB connect, `userdata.php`, then the runner include. A POST handler returns JSON and covers four actions:

- `apply` — apply one pending migration (passing the destructive-confirmation flag through), audit-log it.
- `rollback` — roll back the most recently applied migration, audit-log it.
- `adopt_baseline` — mark the baseline applied without running it, audit-log it.
- `apply_all` — apply every pending migration for a DB in order, **skipping destructive ones** (those must be applied individually with confirmation). Each one is audit-logged.

The view model is built by iterating the registry and calling `migration_status` per DB, wrapped so a single bad/unreachable DB surfaces as an inline error on its own card rather than breaking the whole page. Each DB renders as a card showing applied and pending counts (plus a warning when there are *missing* ledger rows), an **Adopt baseline** button when no migration is yet applied but pending ones exist, an **Apply all pending** button, and a table of migrations. Status is shown with pills — applied, pending, drift, destructive, procedural — and each pending row has a collapsible **Review SQL** panel that shows the literal `up` statements, or the `preview` text for procedural migrations. Apply/rollback buttons fire `fetch` POSTs; destructive applies and all rollbacks go through a SweetAlert confirm first, and the page reloads shortly after a successful action.

**Menu.** Add one `$admin` entry immediately after the `spam_patterns.php` entry, labelled with `t('menu_admin_migrations')` and the `fas fa-database` icon, pointing at `migrations.php`.

**i18n.** Add a coherent set of keys to `en.php` — the menu label, page title and intro, the applied/pending count line and missing-file warning, the adopt-baseline / apply-all / apply / rollback / review-SQL labels, the procedural note, the table headers, the five status-pill labels, the success and error messages, and the JavaScript confirm-dialog strings (titles, destructive/rollback bodies, confirm/cancel buttons). Mirror the same keys in `de.php` and `fr.php`. The French copy needs its apostrophes escaped (`\'`), so French is the one most likely to trip a syntax check — gate all three lang files (and the page and menu) on `php -l`.

**CSS.** Add the `.mig-*` classes for the page (DB-name subtitle, count line, warning text, the pills for applied/pending/drift/destructive/procedural, the review panel, and the monospaced SQL block) to `dashboard.css`, using the existing theme tokens rather than hardcoded colours. Before committing, confirm the tokens used (`--green-bg`, `--grey-bg`, `--amber`, `--radius-pill`, `--radius`) already exist in the stylesheet from earlier phases; if `color-mix` isn't used elsewhere in the file, fall back to an existing amber background token or a plain `rgba(...)` matching a sibling badge for the drift/destructive pills.

---

## 3. The `website` migration files (baseline + `is_deceased`)

**Files:** create `./migrations/website/20260621_0001_baseline.php` and `./migrations/website/20260621_0002_add_users_is_deceased.php`.

`0002` consumes the runner's `migration_column_exists()` helper. Together these produce the `website` baseline plus the `users` column migration that the removals in section 4 depend on.

**The baseline (`0001`)** captures the current DDL — verbatim from `api.py` and the PHP pages — for the five website system tables, so a fresh DB ends up identical to what the defensive code used to produce. The five tables:

- `known_bots` — the global known-bot registry, unique on `bot_login`. Seeded in the same migration via `INSERT IGNORE` with the 41 default bot logins (added_by `system`), so a fresh DB matches prior behaviour.
- `custom_webhooks` — admin-defined inbound webhook receivers, unique on `slug`, with the channel/global scope, verify-mode, and counters.
- `feedback` — user feedback / bug reports.
- `system_metrics` — per-server health rows, unique on `server_name`. This one gains the `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` that the other four already carry, normalising it.
- `freestuff_games` — free-game listings from the webhook, indexed on `received_at`.

The baseline's `down` is intentionally empty — rolling back a baseline must not drop core tables.

**The `is_deceased` migration (`0002`)** replaces the hand-written comment in `users.php`. It's procedural and idempotent: using `migration_column_exists()`, it adds the columns only if they're missing, so it's safe in environments where someone already added them by hand. The columns:

```sql
ALTER TABLE users ADD COLUMN is_deceased   TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN deceased_date DATE       NULL DEFAULT NULL;
```

Its `down` drops both columns (also guarded by `migration_column_exists`). A subtlety worth recording: because destructiveness is computed from `up` only and this `up` is a procedural callable, `0002` is **not** auto-flagged destructive — which is correct, since applying it only *adds* columns. The `down` still runs `DROP COLUMN`, but that path is reached only through the explicit rollback confirmation.

Both files should parse under `php -l`.

---

## 4. Retire the `website` defensive CREATEs

> **Deploy ordering — read first.** This step removes the code that auto-creates these tables at runtime. It is safe **only once the website baseline (`0001`) has been adopted or applied in the target environment** (one-click "Adopt baseline" on existing DBs, or `0001` applied on a fresh DB). This is the last piece of work, and the deploy runbook must adopt the baseline before — or together with — shipping it.

**Files:** `./api/api.py`; `./dashboard/admin/known_bots.php`, `webhooks.php`, `feedback.php`; `./specterbotsystems/index.php`. All of these depend on section 3's baseline now owning the tables they used to create.

**In `api.py`:**

- Remove the `CUSTOM_WEBHOOKS_TABLE_DDL` constant and `ensure_custom_webhooks_table()`.
- Remove the `KNOWN_BOTS_TABLE_DDL` constant and `ensure_known_bots_table()`.
- **Keep `DEFAULT_KNOWN_BOTS_SEED`** — `get_known_bots_list()` still uses it as its in-code fallback, so it stays.
- In the `lifespan` startup handler, drop the two `await ensure_custom_webhooks_table()` / `await ensure_known_bots_table()` calls (and their comments), so startup flows straight from creating the midnight task into the `yield`.
- In `save_freestuff_game()`, remove only the defensive `CREATE TABLE IF NOT EXISTS freestuff_games (...)` block (and its comment); the surrounding cursor, the `commit`, and the `INSERT` all stay — read the function to confirm placement before cutting.

**In the PHP pages**, delete the defensive table-creation block (and its immediate explanatory comment) from each, leaving the rest of the page untouched:

- `known_bots.php` — the `CREATE TABLE IF NOT EXISTS known_bots (...)` added in Phase 1.
- `webhooks.php` — the `CREATE TABLE IF NOT EXISTS custom_webhooks (...)` block.
- `feedback.php` — the `CREATE TABLE IF NOT EXISTS feedback (...)` block.
- `specterbotsystems/index.php` — the `CREATE TABLE IF NOT EXISTS system_metrics (...)` block.

Do **not** touch `admin_access.php`'s `admin_audit_ensure_table()` — that bootstrap is one of the two deliberate exceptions and stays.

**How we'll know this is right:** `api.py` should still compile, with no remaining references to the removed `ensure_*` functions or DDL constants, while `DEFAULT_KNOWN_BOTS_SEED` is still present for the fallback; and all four PHP files should pass `php -l`.

---

## Out of scope for v1 (follow-ups)

- **`admin_api_keys` + `bot_chat_token` baselines.** These tables are assumed to exist but have no DDL anywhere in the repo. Capture their real DDL from production (`SHOW CREATE TABLE`) and add them as `website/20260621_0003_*` migrations. v1 deliberately does not guess production schema.
- **`specterdiscordbot` / `roadmap` / `spam_pattern` baselines.** These DBs are *registered* — they appear in `migrations.php` with zero migrations, and the page already handles a per-DB connection error gracefully — but their baselines aren't authored yet. Write them once their live DDL is captured (`roadmap`'s is liftable from `roadmap/admin/database.php`; the other two from production), and retire their existing creators at the same time. Not part of v1.

## Functional verification (staging, after all four sections)

1. Open `migrations.php` as an admin: every managed DB shows, `website` lists `0001` and `0002` as pending, and a non-admin is denied.
2. On an existing prod-like `website` DB (tables already present): **Adopt baseline** records `0001` as applied with no schema change, then **Apply** `0002` adds the columns and re-running is a no-op (idempotent).
3. On a fresh empty DB: **Apply** `0001` creates all five tables and seeds `known_bots` (41 rows), then **Apply** `0002`.
4. **Roll back** `0002`: the columns are dropped and the ledger row removed; re-applying works.
5. After section 4 ships and the baseline is adopted: restart the API and load the admin pages — nothing breaks, because the tables now exist via the migration rather than the removed defensive code.
6. Drift check: edit an applied migration file and confirm the page shows the **Drift** pill.

## Deploy / runbook

Ship the dashboard and `api.py` changes together. **Before** shipping section 4's removal of the defensive CREATEs, adopt or apply the `website` baseline (`0001`) in every environment — one-click "Adopt baseline" on existing DBs, or it runs on a fresh DB — then restart the API. Per-user DBs and the other system DBs' creators are unaffected by this deploy.
