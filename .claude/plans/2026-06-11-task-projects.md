# Task Projects (Approach B) + High-Severity Fixes — Implementation Plan

**Goal:** Make Working & Study projects first-class — a real registry, with move/rename/delete and open-task counts — and surface them on the dashboard and overlay. While I'm in these files anyway, I want to clear out the four high-severity audit bugs that live in the same code.

**Spec:** `.claude/specs/2026-06-11-task-projects-design.md`

## Architecture

The design keeps project identity lightweight. A per-user `user_projects` registry table anchors the canonical name of each project, but tasks continue to store their `project` as a plain name string. That matters because the existing NULL-safe scoping in `bot/beta.py` (`project <=> %s`) already works against that string column, and I don't want to disturb it — a project rename is a string update across rows, not a foreign-key migration.

For real-time updates, the bot emits a new `PROJECT_UPDATE` event over the existing `/notify` HTTP fan-out. The websocket server relays it — and the existing `TASK_*` events — channel-scoped, with the JSON-encoded fields decoded back into real objects. The dashboard and overlay then render the `project` field they're already receiving on task payloads.

**Tech stack involved:** Python (aiomysql, the TwitchIO 2.10 bot; aiohttp + python-socketio on the server), PHP 8 + mysqli (dashboard and overlay), and vanilla JS with Socket.io 4 clients.

## How we'll know it's right

These surfaces have no automated test harness, so verification is mostly static and behavioural. Every PHP file I touch should still pass `php -l`, and the bot and server should both still compile. Beyond that, correctness is a reasoning exercise: the event payload contract (event names and field names) has to line up across `beta.py`, `server.py`, the dashboard, and the overlay; the project invariants must hold (at most one active task per project, and a contiguous 1..n backlog); and none of the four bug fixes should introduce a regression in the behaviour they touch. The spec includes a manual smoke flow to walk at the end.

---

## Work items

### 1. The `user_projects` registry table

This is the foundation, so it lands first. The per-user schema is managed centrally in `dashboard/includes/usr_database.php`, which auto-creates tables and missing columns across every tenant DB — so the new table definition belongs there, slotted in next to the existing `user_active_project` and `user_pomos` entries.

The table is intentionally small: an auto-increment id, the `user_id` and `user_name`, the project `name`, and a `created_at` timestamp. The important constraints are a unique key on `(user_id, name)` so a chatter can't hold two projects with the same name, and an index on `user_id` for the listing queries. Standard InnoDB / `utf8mb4_unicode_ci` to match the rest of the schema:

```sql
UNIQUE KEY uniq_user_project (user_id, name),
INDEX idx_user_id (user_id)
```

The `utf8mb4_unicode_ci` collation has a knock-on effect the rename logic has to respect (case-insensitive matching), noted below.

### 2. Bot — reserved words and the project helper functions

All of these go in `bot/beta.py` as module-level functions below the `# Functions for all the commands` marker, per the file's convention — not as methods wedged between commands.

First, `validate_project_name` gets stricter. It already trims and requires letters, numbers, spaces and dashes, 1–24 characters after trimming. I'm adding a reserved-word check: the first word of a name can't be `clear`, `move`, `rename`, or `delete`. `clear` is already a reserved keyword handled by the caller; reserving the three subcommand words as a *first* word means the `!project` parser can never confuse a project name with a subcommand.

Then a set of helpers next to the existing task helpers (after `emit_task_complete`, before `promote_backlog_head`):

- **`register_user_project`** — idempotent `INSERT IGNORE` into the registry. Called whenever a project name is first used so the registry stays authoritative.
- **`user_project_exists`** — checks the registry first, then falls back to checking for any task row carrying that project name. The fallback covers projects that predate the registry.
- **`file_task_into_project`** — files a task into a target project. If that project's active slot is free, the task becomes active; otherwise it goes to the end of that project's pending backlog. Returns the resulting `(status, position)` so the caller can phrase the chat reply.
- **`renumber_project_backlog`** — renumbers a project's pending backlog contiguously from 1 so there are no gaps after a task leaves. Returns nothing; callers should `await` it without binding a result.
- **`emit_project_update`** — builds the `PROJECT_UPDATE` payload (channel code, user, the `change` kind, and whichever of `name` / `old_name` / `task_id` apply) and fires it via `websocket_notice`. Optional fields are only included when set.

Three subcommand handlers round it out, each returning a chat reply string (without the `@user` prefix, which the dispatcher adds):

- **`project_move_subcommand`** handles `!project move <n|now> <name>`. `now` moves your active task; a number moves backlog item #n. It resolves the source (active) project, validates the target name, short-circuits if the target equals the source, then registers the target, files the task in via `file_task_into_project`, and tidies the source — promoting the source's backlog head when the active task left (`now`), or renumbering the source backlog when a backlog item left (numbered move). It emits a `PROJECT_UPDATE` plus the relevant `TASK_UPDATE`(s), including for any task promoted into the now-empty active slot.
- **`project_rename_subcommand`** handles `!project rename <old> | <new>` (pipe-separated). It validates both names, rejects a no-op, and confirms the old project exists. The collision check is collation-aware: because `utf8mb4_unicode_ci` is case-insensitive, a case-only rename (e.g. `studying` → `Studying`) matches the same row, so we skip the "name already taken" check in that case. The rename then updates `user_projects`, every matching `user_tasks` row, and any `user_active_project` pointer, and emits `PROJECT_UPDATE` with both names.
- **`project_delete_subcommand`** handles `!project delete <name>`. The guiding rule: open tasks are *never* deleted — they fall back to the default (NULL) project. The default's active slot wins, so a deleted project's active task only becomes the default's active task if that slot is free; otherwise it queues at the end of the default backlog. Completed and rejected history just merges into the default scope (a bare project clear). It then clears any active-project pointer, deletes the registry row, emits `PROJECT_UPDATE`, and reports how many open tasks were relocated.

### 3. Bot — `!project` subcommand dispatch

With the helpers in place, `project_command` in `bot/beta.py` becomes a dispatcher. After the cooldown check it parses the argument and branches:

- **No argument** — report the current project, or note that the chatter is in the default project.
- **`clear`** — reset the active-project pointer to NULL (upsert into `user_active_project`), emit a `PROJECT_UPDATE` with change `clear`, and confirm.
- **`move` / `rename` / `delete`** — the reserved first words route to the three subcommand helpers; the reply is prefixed with `@user` and sent.
- **`<name>`** — validate, register, upsert the active-project pointer, emit `PROJECT_UPDATE` with change `switch`, and confirm.

### 4. Bot — `!projects` listing with counts and an active marker

`projects_command` gets a real listing. First a self-heal step folds any pre-registry task projects into `user_projects` (`INSERT IGNORE ... SELECT DISTINCT ...`) so older data shows up. Then it joins the registry to `user_tasks`, counting open (active + pending) tasks per project, ordered by name. The reply lists each project as `name (N open)`, marking the one the chatter is currently in, and is truncated to the max chat-message length. If there are no projects, it nudges the chatter toward `!project <name>`.

### 5. Bot — `websocket_notice` carries `PROJECT_UPDATE` and drops `None`

The bot's `websocket_notice` already has a transport branch for the `TASK_*` events that JSON-encodes nested values so dicts survive URL-encoding. `PROJECT_UPDATE` joins that branch. One addition matters: top-level `None` values must be dropped rather than passed through, because `urlencode` would stringify a `None` into the literal string `"None"` on the wire. So the branch skips keys whose value is `None` and JSON-encodes only dict/list values.

### 6. Server — `PROJECT_UPDATE` relay and decoded, channel-scoped `/notify`

`websocket/server.py` needs to both accept `PROJECT_UPDATE` over a socket and relay it (and the bot's `TASK_*` events) correctly from `/notify`.

- A small `handle_project_update` socket handler broadcasts the payload to task clients only (channel-scoped), registered in the event-handler table alongside the other task handlers.
- In `notify_http`, an explicit branch for the task/project events decodes the JSON-encoded `task` field back into an object and relays via `broadcast_to_task_clients_only`. This is deliberate: it keeps these events channel-scoped exactly like the socket-path `TASK_*` handlers, and it stops bot task events from leaking to global listeners through the generic catch-all branch. Reward behaviour is unchanged — bot-side completions award points in-process, so this branch must *not* call `emit_task_reward_trigger`.

### 7. Server — pomo ticker only ticks DBs with active pomos (audit fix)

Today the pomo ticker refreshes its active-DB set every 60 seconds by scanning every user database — wasteful and slow at scale. I'm making it event-driven instead.

- A one-time `scan_active_pomo_dbs` runs at startup purely for recovery: it finds tenants that have a running pomo so the ticker can resume them after a restart. Steady-state tracking is then driven by events — `USER_POMO_START` adds a DB to the set, and a tick that finds no active pomos removes it.
- `_process_pomo_db` collapses to a single query per tick and returns a liveness boolean. A DB with no active pomos returns `False`, which tells the ticker to untrack it (the next `USER_POMO_START` re-adds it). A failed query — missing tenant DB or missing `user_pomos` table — also untracks, rather than logging the same error every second. When a periodic update is due and phases advanced this tick, it re-reads the rows so the broadcast carries fresh state.
- The ticker loop drops the 60-second refresh entirely, iterates a snapshot of the active set (so a concurrent `USER_POMO_START` can't mutate mid-loop), and untracks any DB that reports no active pomos. A bad tenant is logged and skipped so it can't take down the whole loop.
- The stale comment in `handle_user_pomo_start_http` that mentions "the 60s refresh" gets corrected to describe the new event-driven tracking, and there should be no remaining references to the old `refresh_pomo_active_dbs` name.

### 8. Server — remove the stub `TASK_LIST_SYNC` on REGISTER (audit fix)

On register, the server currently pushes an *empty* `TASK_LIST_SYNC` to task-aware dashboard/overlay clients. Nothing in the repo ever sends a populated one — both clients fetch their real data themselves on connect — so this empty push only races those fetches and can wipe freshly rendered lists. Deleting it is the fix.

### 9. Dashboard PHP — stop `save_settings` clobbering reward columns (audit fix)

In `dashboard/working-or-study.php`, the timer `save_settings` path writes `reward_enabled` and `reward_points_per_task` even though the timer UI has no controls for them. That silently overwrites them with defaults — and the bot reads those columns to decide `!done` point awards, so saving timer settings could quietly disable rewards. The fix is to drop both columns from that UPDATE (and its bind list), leaving them untouched, with a comment explaining why they're deliberately not written here.

### 10. Dashboard PHP — `ch_get_tasks` returns project and backlog position

The `ch_get_tasks` query needs to select `project` and `backlog_position` alongside the existing columns so the front end can render the project cell and the filter.

### 11. Dashboard JS — echo loop, payload parsing, owner routing, sync guard, `PROJECT_UPDATE` (audit fix)

The socket handlers in `working-or-study.php` need several related fixes:

- **Echo loop:** inbound events must render with re-emit disabled. Re-emitting an event we just received would ping-pong it between connected dashboards forever. A shared `emit=false` flag on the append calls closes this.
- **Payload parsing:** a small `chParseTask` helper decodes the `task` field, which arrives JSON-encoded over the `/notify` transport, and returns null on bad input.
- **Owner routing:** `TASK_CREATE` / `TASK_UPDATE` route to the streamer row or the viewer row based on `owner` (falling back to the absence of a `user_name` to infer a streamer task).
- **Sync guard:** `TASK_LIST_SYNC` ignores an empty payload so it can't wipe the lists that `chLoadTasks()` just rendered.
- **`PROJECT_UPDATE`:** simply triggers a `chLoadTasks()` refresh — project changes are infrequent and a reload keeps the counts and filter honest without bespoke diffing.

### 12. Dashboard — project column, filter UI, and i18n

The viewer table grows a Project column (placed after Task, making it a 7-column table; the empty-row colspans move to 7 in both the PHP and the JS). Above the table sits a small project filter dropdown.

`chAppendUserRow` tags each row with a `data-project-key` — `__default` for tasks with no project, otherwise the project name — and renders a tag in the project cell (or an em-dash for the default). After rows render, `chRefreshProjectFilter` rebuilds the dropdown from the rows currently in the table, counting tasks per project, with an "All projects" and a "Default project" option, preserving the current selection when it still exists. `chApplyProjectFilter` shows/hides rows by the selected key. The filter is driven entirely off rendered rows, so it stays correct as tasks come and go.

New i18n keys cover the column header and the two filter options, added to `en.php` as the base and mirrored into `de.php` and `fr.php`:

| Key | en | de | fr |
| --- | --- | --- | --- |
| `working_or_study_th_project` | Project | Projekt | Projet |
| `working_or_study_filter_all_projects` | All projects | Alle Projekte | Tous les projets |
| `working_or_study_filter_default_project` | Default project | Standardprojekt | Projet par défaut |

The two filter labels are also exposed to the JS via `wsLang` so the dropdown can be built client-side.

### 13. Overlay — project chips, payload parsing, sync guard, `PROJECT_UPDATE`

`overlay/working-or-study.php` mirrors the dashboard work for the OBS browser source:

- The `get_channel_tasks` query selects `project`.
- A `parseTaskPayload` helper decodes the JSON-encoded `task` field, and a `projectChipHtml` helper renders a small chip for a viewer task that has a project (empty string for none).
- Both renderers — `newViewerUpsert` and the unified-list row in `renderUnifiedList` — append the chip after the task title. In the unified list the chip only shows for viewer-owned rows.
- The socket handlers get the same treatment as the dashboard: a sync guard on `TASK_LIST_SYNC` that ignores empty payloads, a `PROJECT_UPDATE` handler that reloads channel tasks, and owner-routed `TASK_CREATE` / `TASK_UPDATE` that parse the payload first (and fall back to a full reload in unified view).

The chip styling lives in `overlay/index.css`, in the study-overlay section after the `.is-backlog` rules. It reuses the existing study-overlay theme variables (`--task-text-muted`), is a pill with ellipsis overflow, and uses relative units so it stays resolution-independent across 1080p/1440p/4K browser sources.

### 14. Help text — `builtin_commands.json`

The `project` and `projects` entries in `api/builtin_commands.json` get rewritten to describe the full surface: `!project` to show the current project, `!project <name>` to switch (created on first use), `!project clear` to return to default, `!project move <n|now> <name>`, `!project rename <old> | <new>`, and `!project delete <name>` (tasks return to the default project). The description notes the naming rules — letters, numbers, spaces, dashes, up to 24 characters, can't start with a reserved word — and the syntax examples cover each form. `!projects` describes the per-project open counts and the active marker. The file must remain valid JSON.

### 15. Final cross-surface review

Once everything's in place, the last pass is a review rather than a checklist: confirm the payload contract lines up end to end (event names and fields identical across `beta.py`, `server.py`, the dashboard, and the overlay), confirm the project invariants hold (≤1 active task per project, contiguous backlog after moves and deletes), and re-examine each of the four bug fixes for regression risk. Anything that surfaces gets fixed and re-checked.

## Affected files

| Area | File | Change |
| --- | --- | --- |
| Schema | `dashboard/includes/usr_database.php` | New `user_projects` table definition |
| Bot | `bot/beta.py` | Reserved-word validation, project helpers, `!project` dispatch, `!projects` listing, `PROJECT_UPDATE` transport |
| Server | `websocket/server.py` | `PROJECT_UPDATE` relay, decoded channel-scoped `/notify`, event-driven pomo ticker, remove stub `TASK_LIST_SYNC` |
| Dashboard | `dashboard/working-or-study.php` | Reward-column fix, project in `ch_get_tasks`, socket-handler fixes, project column + filter |
| Dashboard i18n | `dashboard/lang/en.php`, `de.php`, `fr.php` | Project column + filter strings |
| Overlay | `overlay/working-or-study.php`, `overlay/index.css` | Project chips, payload parsing, sync guard, `PROJECT_UPDATE`, chip style |
| Help | `api/builtin_commands.json` | `project` / `projects` help text |

## Deploy notes

Deploying this restarts **both** the bot and the websocket server (the pomo-ticker and relay changes need the server bounced; the command changes need the bot bounced), plus a re-sync of `builtin_commands.json` to `/home/botofthespecter/builtin_commands.json` (server) so the updated help text is live.
