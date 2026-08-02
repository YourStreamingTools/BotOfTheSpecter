---
name: project-db-migrations-admin-page
description: "Future Phase 3 — admin migrations.php page to review/apply DB schema changes, retiring scattered defensive CREATE TABLE / dead code"
metadata: 
  node_type: memory
  type: project
  originSessionId: 02934e5f-cdea-4a4e-8372-ba9f86cde601
---

Planned future work (captured 2026-06-20, build later — not started): an admin **`migrations.php`** page that lets admins **review and apply "admin-level" DB schema changes** through a proper migrations system, instead of the current pattern of hardcoded defensive `CREATE TABLE IF NOT EXISTS` / "make the table if it doesn't exist" code scattered across Python scripts and PHP pages (which the user considers dead code). The page should surface a pending schema change, let an admin review it, then apply it — so schema evolution is centralized and auditable rather than embedded in app code.

**Why:** the user dislikes the scattered self-creating-table pattern; wants one reviewable place for DB changes across the whole system ("pull all the systems together to work with each other").

**How to apply:** this is **Phase 3** of the known-bots work and a broader infra refactor. It would retire the Phase 1 `ensure_known_bots_table()` + defensive `CREATE TABLE IF NOT EXISTS` approach (see [[project_per_user_schema]] for the existing per-user `usr_database.php` auto-creator and the central `website` tables created ad-hoc). Needs its own brainstorm → spec (`.grok/specs/`) → plan (`.grok/plans/`). Lives in the admin dashboard (`dashboard/admin/`, `is_admin` gate), follows the `api_keys.php`/`known_bots.php` admin-page pattern. Relates to the known-bots registry phases (Phase 1 dashboard+API done; Phase 2 bot consumers spec+plan written, uncommitted).
