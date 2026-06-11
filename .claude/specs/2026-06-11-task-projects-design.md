# Task Projects — Design Spec (Approach B: Project Registry)

**Date:** 2026-06-11
**Scope approved by user:** move/attach existing tasks, first-class project management, dashboard project UI, overlay project display — plus 4 high-severity bug fixes from the 2026-06-11 five-surface audit.

## Background

The Working & Study system lets each chat viewer manage personal tasks via chat commands. Projects currently exist only as a per-chatter context switch: a free-text `project` VARCHAR(100) on `user_tasks` (set only at INSERT) plus one active-project pointer per chatter in `user_active_project`. There is no way to move an existing task between projects, no rename/delete, no counts, and the dashboard/overlay/API are entirely project-blind.

## Design

### 1. Schema — `user_projects` registry (per-user DB)

Added to the central schema manager `./dashboard/includes/usr_database.php` (`$tables`), never inline elsewhere:

```sql
CREATE TABLE IF NOT EXISTS user_projects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(50) NOT NULL,
    user_name VARCHAR(100) NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_project (user_id, name),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

Tasks keep their `project` **name string** — no FK migration, no rewrite of the NULL-safe `project <=> %s` scoping queries in `bot/beta.py`. The registry makes projects exist independently of tasks (a freshly created project with zero tasks is listable), carries `created_at`, and is the anchor for rename/delete cascades.

**Backfill:** lazy. `!project <name>` upserts the registry (`INSERT IGNORE`). `!projects` first runs `INSERT IGNORE ... SELECT DISTINCT` of the chatter's non-NULL task projects into the registry, so pre-registry projects self-heal on first listing.

### 2. Chat commands (`bot/beta.py`, beta only — no stable/v6 port in this pass)

`!project` grows subcommands. Reserved first tokens: `clear`, `move`, `rename`, `delete` — `validate_project_name()` additionally rejects names whose first word is reserved.

| Command | Behaviour |
| ------- | --------- |
| `!project` | Unchanged: report current project. |
| `!project <name>` | Validate, `INSERT IGNORE` into registry, upsert active pointer. |
| `!project clear` | Unchanged: pointer → NULL (default context). |
| `!project move now <name>` | Move the **active** task of the current project to `<name>`; source project promotes its backlog head (same invariant flow as `!done next`). |
| `!project move <n> <name>` | Move backlog item #n of the current project to `<name>`; renumber source backlog contiguously. |
| `!project rename <old> \| <new>` | Pipe separator (not in the name charset, so unambiguous). Rejects if `<new>` already exists (no implicit merge). Transaction: registry row + `user_tasks.project` + `user_active_project.project`. |
| `!project delete <name>` | Tasks are **never deleted** — they fall back to the default project, invariant-safe (see below). Registry row removed; any active pointers at the name → NULL. |
| `!projects` | Lists from the registry with open-task counts and an active marker, e.g. `Alpha (2 open, active), Beta (0 open)`. |

**Move target placement rule** (mirrors `!task` creation): if the target project has no active task, the moved task becomes its active task; otherwise it is appended to the end of the target's backlog as `pending`.

**Delete invariant safety:** the deleted project's active task becomes the default project's active task only if the default has none, else it is appended to the default backlog; pending items append to the end of the default backlog in order; completed/rejected rows just get `project = NULL`.

All mutating subcommands emit a `PROJECT_UPDATE` websocket event: `{channel_code, user_id, user_name, change: 'create'|'switch'|'clear'|'move'|'rename'|'delete', name, old_name?, task_id?}` so the dashboard/overlay can live-refresh. Task moves additionally emit `TASK_UPDATE` for the affected task(s), which already carry `project`.

Helpers are module-level functions below the `# Functions for all the commands` marker, per project convention.

### 3. WebSocket (`websocket/server.py`)

- Explicit `PROJECT_UPDATE` relay handler using `broadcast_to_task_clients_only` (channel-scoped, same pattern as the eight TASK_* relays) — **not** the `*` catch-all, which would leak to global listeners.
- `/notify` whitelist gains `PROJECT_UPDATE` so the bot's existing HTTP fan-out path works.

### 4. Dashboard (`dashboard/working-or-study.php`)

- `ch_get_tasks` viewer SELECT adds `project` and `backlog_position`.
- Viewer task table gains a **Project** column (tag, or em-dash for default).
- Client-side **project filter** dropdown above the viewer table (All / Default / each project, with counts), populated from loaded data.
- `PROJECT_UPDATE` socket handler → refetch tasks.
- New strings via `t()` keys in en/de/fr.

### 5. Overlay (`overlay/working-or-study.php` + `overlay/index.css`)

- `get_channel_tasks` viewer query adds `project`.
- Viewer rows render a small project chip (split and unified views) from initial load and from `TASK_CREATE`/`TASK_UPDATE` payloads (the bot already sends `task.project`; the overlay currently discards it).
- `PROJECT_UPDATE` handler → refetch task lists.
- Chip styles in `overlay/index.css` under the `study-overlay-page` namespace (no new CSS files, resolution-independent).

### 6. Help text (`api/builtin_commands.json`)

`!project` entry documents move/rename/delete and reserved words; `!projects` entry documents counts + active marker.

## High-severity bug fixes (bundled, same files)

1. **Pomo ticker load** (`websocket/server.py`): tick only DBs with known active pomos. Event-driven: `USER_POMO_START` adds the DB to the active set; a tick that finds zero active pomos drops it; one full scan at startup recovers state after restart. A missing `user_pomos` table drops the DB from the set instead of logging every second.
2. **Dashboard echo loop** (`dashboard/working-or-study.php`): inbound `TASK_CREATE`/`TASK_UPDATE` socket handlers render with `emit=false` so received events are never re-emitted (kills the two-tab ping-pong).
3. **Timer settings clobber** (`dashboard/working-or-study.php`): the timer `save_settings` action stops writing `reward_enabled`/`reward_points_per_task` — no UI control exists for them and `bot/beta.py` reads those columns to decide `!done` awards.
4. **Empty TASK_LIST_SYNC wipe** (`websocket/server.py` + both clients): the server stops emitting the hardcoded-empty `TASK_LIST_SYNC` on REGISTER (nothing else ever sends a populated one); dashboard and overlay handlers additionally guard against contentless sync payloads.

## Out of scope (noted for later)

- Pomo↔project attribution (`user_pomos` has no project column).
- API endpoints for tasks/projects (all surfaces currently go direct to DB).
- v6 (`beta-v6.py`) port of the project subcommands.
- Reward-settings UI for `working_study_overlay_settings.reward_enabled`.
- The 13 confirmed medium-severity audit findings.

## Deploy notes

Changes touch the bot **and** the websocket server — both processes need a restart on deploy. The `builtin_commands.json` server copy (`/home/botofthespecter/builtin_commands.json`) needs re-syncing for `/commands/info` to show the new help text.

## Testing

No automated test suite exists for these surfaces. Verification: `php -l` on every touched PHP file, `python -m py_compile` on touched Python, plus a multi-agent adversarial review of the full diff before handoff. Manual smoke flow for the user: `!project Alpha` → `!task a` → `!later b` → `!project move 1 Beta` → `!projects` → `!project rename Beta | Gamma` → `!project delete Gamma` — watching dashboard + overlay update live.
