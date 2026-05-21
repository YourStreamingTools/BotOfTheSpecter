# Working & Study System — Plan

> Status: **draft**, awaiting decisions (see end of doc).  
> Owner: TBD. Last revised 2026-05-21.

## 1. Scope

Expand the existing working/study overlay into a full co-working / focus-session system that streamers can offer their chat. Today the overlay surfaces a streamer pomo timer plus separate streamer/viewer task panels. The plan below adds:

- A chat command layer so viewers and the streamer can interact with the task list directly from chat.
- A per-viewer backlog model (active task + queued tasks per user).
- Personal pomo timers per viewer.
- Per-viewer project scoping.
- Switchable visual themes for the overlay.
- Pomo cycle numbering on the streamer timer.
- An optional unified task-list view mode.

**Affected systems**
- `./bot/beta.py` — **primary implementation target.** All new chat commands and websocket emits land here first, per [bot-versions.md](../rules/bot-versions.md).
- `./bot/beta-v6.py` — port from beta once features are stable.
- `./bot/bot.py` (stable) — **not changed** unless a critical fix is needed.
- `./websocket/server.py` + handlers — new `USER_POMO_*` events for Phase 3 (existing TASK_* + SPECTER_TIMER_* handlers reused as-is for Phases 1, 2, 4, 5, 6, 7). See §6 for the detailed gap list.
- `./api/api.py` — **no changes needed** for any phase. Overlay and dashboard self-serve via PHP; the bot writes MySQL directly per [data-flow.md](../rules/data-flow.md). See §6.
- `./overlay/working-or-study.php` — render layer, theme switch, per-viewer pomo display.
- `./overlay/index.css` — theme tokens, new pomo set badge styling.
- `./dashboard/working-or-study.php` — config UI for themes, command toggles, approval defaults.
- `./dashboard/usr_database.php` — schema migrations.
- Per-user MySQL DB — table additions/migrations.

**Explicitly out of scope** (separable, future work):
- Migration tooling for existing in-flight tasks (existing user_tasks rows just keep working).
- Statistics page / historical analytics for completed tasks.
- Email/Discord webhook notifications when a task is approved or completed.

## 2. Current state inventory

What already exists today, so the plan layers on top instead of replacing.

### Database (per-user DB)

| Table | Purpose |
|---|---|
| `streamer_tasks` | Streamer-owned tasks. Fields: id, title, description, category, status[active/completed/hidden], reward_points, timestamps. |
| `user_tasks` | Viewer-submitted tasks. Fields: id, streamer_task_id, user_id, user_name, title, description, category, status[pending/active/completed/rejected], approval_status[auto/pending_approval/approved/rejected], reward_points, completed_at, timestamps. |
| `task_reward_log` | Audit trail of points awarded per completed user_task. Unique on `user_task_id`. |
| `task_settings` | require_approval, default_reward_points, allow_user_tasks, task_visible_overlay. |
| `working_study_overlay_settings` | focus_minutes, micro_break_minutes, recharge_break_minutes, reward_enabled, reward_points_per_task. |

### Websocket events (already broadcast)

- `TASK_LIST_SYNC` (full snapshot — streamer_tasks + user_tasks)
- `TASK_CREATE`, `TASK_UPDATE`, `TASK_COMPLETE`, `TASK_APPROVE`, `TASK_REJECT`, `TASK_DELETE`
- `TASK_REWARD_CONFIRM` (fires the reward popup on the overlay)
- `SPECTER_PHASE`, `SPECTER_TIMER_CONTROL`, `SPECTER_TIMER_COMMAND`, `SPECTER_TIMER_STATE`, `SPECTER_TIMER_UPDATE`, `SPECTER_SETTINGS_UPDATE`, `SPECTER_SESSION_STATS`, `SPECTER_STATS_REQUEST`

### Overlay (`./overlay/working-or-study.php`)

- Timer card with focus / micro / recharge phases, ring progress, session-count stats.
- Separate streamer-tasks panel + viewer-tasks panel (toggleable via `?tasklist`, `?streamer=true`, `?streamer=false`).
- Reward popups (queue-capped, fade-out — landed in #15).
- Connection-status pill top-right, hidden when connected (landed in #15).
- Auto-scroll for long task lists.

### Bot (`./bot/beta.py`)

- **No chat commands yet for the task system.** Tasks are created/updated via the dashboard and via direct websocket emit from the operator UI. The chat command surface for tasks is the main gap this plan fills.

## 3. Feature phases

Phases are ordered by dependency, not user-visible priority. Each phase is independently shippable.

### Phase 1 — Simple-mode chat commands (no schema changes)

The minimal command surface that gets viewers interacting with the task list. Uses the existing `user_tasks` table as-is. Assumes "one active task per viewer", which the current schema already implies (status='active').

| Command | Behaviour |
|---|---|
| `!task <title>` | Create a new user_task for the chatter with status='active'. If they already have an active task, the new one is queued (or refused depending on the decision in §5). |
| `!done` | Mark the chatter's current active task as completed. Awards reward_points if `task_settings.reward_enabled = 1`. |
| `!rename <title>` | Rename the chatter's current active task. |
| `!remove` | Delete the chatter's current active task (status='rejected', preserves audit trail). |
| `!mytasks` | Bot replies in chat with the chatter's active task + count of items in backlog. |

**Where it lives**
- New handlers in `beta.py` event message processing, registered via the existing `builtin_commands` dict.
- Each command emits the corresponding existing websocket event (`TASK_CREATE`, `TASK_UPDATE`, `TASK_COMPLETE`, `TASK_DELETE`) so the overlay updates in real time without polling.
- Bot replies use `send_chat_message()` and are concise, single-line — chat clutter is the main risk.

**Dashboard side**
- Add per-command enable/disable toggles to the existing working-or-study config page so streamers can opt out of the chat surface entirely.

### Phase 2 — Backlog + multi-task model

Lets viewers queue tasks instead of being stuck with one slot. Adds the concept of an active task vs queued backlog.

**Schema change** (one column added to existing table):

```sql
ALTER TABLE user_tasks ADD COLUMN backlog_position INT DEFAULT NULL;
ALTER TABLE user_tasks ADD INDEX idx_backlog (user_id, backlog_position);
```

Status semantics:
- `status='active'` + `backlog_position IS NULL` → currently on the task list
- `status='pending'` + `backlog_position` = integer → queued in backlog (ordered by position)
- `status='completed'` / `status='rejected'` → terminal

Only one row per user can be `status='active'` at a time (enforced in the bot logic, not as a DB constraint — keep flexibility for project scoping in Phase 4).

| Command | Behaviour |
|---|---|
| `!now <title>` | Create + set as active. If chatter already has an active task, push current active to backlog position 1 and bump existing positions. |
| `!later <title>` | Create with status='pending', appended at the end of backlog. |
| `!soon <title>` | Create with status='pending', prepended at backlog position 1. |
| `!backlog` | Bot replies in chat with the chatter's backlog (numbered). |
| `!now <n>` | Promote backlog item #n to active; demote current active to backlog. |
| `!now skip` | Mark current active as completed and promote backlog item #1. |
| `!done <n>` | Mark backlog item #n as completed. |
| `!done next` | Mark active as completed and promote backlog item #1. |
| `!later <a>; <b>; <c>` | Semicolon-separated multi-add (same for `!now` / `!soon`). |

**Reward semantics**: `!done` and any `!done <n>` award reward_points; `!remove` does not.

### Phase 3 — Personal pomo timers per viewer

Each viewer can run their own focus timer, displayed inline next to their task on the overlay.

**Schema change** (new table):

```sql
CREATE TABLE user_pomos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(50) NOT NULL,
    user_name VARCHAR(100) NOT NULL,
    label VARCHAR(255) DEFAULT NULL,
    work_minutes INT NOT NULL,
    break_minutes INT DEFAULT 0,
    total_cycles INT DEFAULT 1,
    current_cycle INT DEFAULT 1,
    current_phase ENUM('work','break','completed','cancelled') DEFAULT 'work',
    phase_started_at DATETIME NOT NULL,
    phase_ends_at DATETIME NOT NULL,
    status ENUM('active','completed','cancelled') DEFAULT 'active',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

| Command | Behaviour |
|---|---|
| `!pomo <minutes> <label>` | Single-cycle work timer. Example: `!pomo 30 Study`. |
| `!pomo <work>/<break>/<cycles> <label>` | Multi-cycle. Example: `!pomo 30/10/3 Study session` = 3 cycles of 30-min work / 10-min break. |
| `!pomo` (no args) | Show remaining time for the chatter's active pomo. |
| `!pomo cancel` | Cancel chatter's active pomo. |

**Phase transitions**: handled server-side by a websocket-server background task that ticks once per second, transitions phases when `phase_ends_at` passes, emits `USER_POMO_UPDATE` events. This avoids depending on the bot being online for individual viewer timers to keep running.

**Websocket events to add**:
- `USER_POMO_START` — broadcast when a pomo begins (overlay shows the inline timer).
- `USER_POMO_UPDATE` — periodic countdown (overlay updates the inline label every 5s or 10s; per-second updates would be wasteful across hundreds of viewers).
- `USER_POMO_PHASE` — fired on work→break or break→work transition (overlay can chime / animate).
- `USER_POMO_COMPLETE` — pomo finished all cycles (overlay clears the badge).
- `USER_POMO_CANCEL` — chatter cancelled.

**Overlay rendering**: a small badge next to the chatter's task row showing remaining minutes + a phase-coloured ring (work/break). Caps at one visible pomo per user.

### Phase 4 — Per-viewer project scoping

Lets viewers partition their backlog into projects ("Housework", "Studying", "Coding"). Default project is null.

**Schema changes**:

```sql
ALTER TABLE user_tasks ADD COLUMN project VARCHAR(100) DEFAULT NULL;
ALTER TABLE user_tasks ADD INDEX idx_user_project (user_id, project);

CREATE TABLE user_active_project (
    user_id VARCHAR(50) PRIMARY KEY,
    user_name VARCHAR(100) NOT NULL,
    project VARCHAR(100) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

| Command | Behaviour |
|---|---|
| `!project <name>` | Switch chatter's active project. If new, created on first use. All subsequent `!now` / `!later` / `!task` commands write under this project. |
| `!projects` | Bot replies with the chatter's distinct project list (from user_tasks DISTINCT project). |
| `!project clear` | Clear chatter's project context (back to default). |

**Backlog/active are project-scoped**: When project is set, only tasks under that project are visible to `!backlog`, `!mytasks`, `!now <n>`, etc. The active task for a user is scoped to (user_id, project) — switching projects pauses the previous project's active task implicitly.

### Phase 5 — Theme system

Lets streamers pick the overlay's visual style. Themes are CSS-variable swaps applied via a `data-theme` attribute on the overlay root.

**Schema change**:
```sql
ALTER TABLE working_study_overlay_settings ADD COLUMN theme VARCHAR(50) DEFAULT 'dark';
```

**Initial theme set** (room to add more later):
- `dark` (current) — the existing dark palette
- `peachy` — warm coral/peach gradients
- `ocean` — cool blue gradients
- `forest` — green/earth tones
- `midnight` — deep purple with high contrast

**Implementation**:
- Each theme is a `[data-theme="..."]` block in `./overlay/index.css` overriding the working-study CSS variables (background, accent, ring colours, task row hover, etc.).
- `working-or-study.php` reads `theme` from the settings table and sets `data-theme` on `#overlayRoot`.
- Dashboard adds a theme picker (preview tiles) to `./dashboard/working-or-study.php`.
- `SPECTER_SETTINGS_UPDATE` carries the new theme so the overlay can hot-swap without reload.

### Phase 6 — Streamer pomo cycle numbering

Today the streamer timer shows the current phase (Focus / Micro / Recharge) but doesn't show position in a planned cycle set. Add cycle numbering for streamers who want a structured session.

**Schema change**:
```sql
ALTER TABLE working_study_overlay_settings ADD COLUMN cycle_count INT DEFAULT 4;
ALTER TABLE working_study_overlay_settings ADD COLUMN show_cycle_badge TINYINT(1) DEFAULT 0;
```

When `show_cycle_badge = 1`, the timer card shows a small `N / M` badge next to the phase label. State (current_cycle) is tracked in the overlay's existing timerState localStorage object, advanced when a focus phase completes.

Optional dashboard control to reset the cycle counter mid-stream.

### Phase 7 — Unified task list view (optional)

A view mode that merges streamer + viewer tasks into one chronological list with `username: task` rows instead of two separate panels. Useful for streamers running pure co-working sessions where the distinction doesn't matter.

**Schema change**:
```sql
ALTER TABLE working_study_overlay_settings ADD COLUMN list_view_mode ENUM('split','unified') DEFAULT 'split';
```

When `list_view_mode = 'unified'`, the overlay renders a single `.study-overlay-page-task-sys-card--unified` panel instead of the split streamer/viewer panels. The two existing panels become hidden via `[data-visible="false"]`.

Sort order in unified mode: active first (with strikethrough for completed), backlog tasks omitted (or shown muted — decision in §5).

## 4. API server (`./api/api.py`) — gap analysis

**Current state**: api.py has **no** working-study, task, pomo, or timer endpoints. Grepping for any of (`streamer_tasks`, `user_tasks`, `task_settings`, `task_reward`, `working_study`, `pomo`) returns zero matches in the file.

**Why this works today**:
- The overlay self-serves config and task data via its own PHP endpoints inside `./overlay/working-or-study.php` (`?action=get_settings`, `?action=get_channel_tasks`).
- The dashboard reads/writes the per-user MySQL DB directly through PHP.
- The bot writes per-user MySQL directly via `mysql_connection()`, per the rule in [data-flow.md](../rules/data-flow.md) that the bot uses direct MySQL for per-channel state.

**Phases 1–7 require no api.py changes.** Every phase's read/write path is covered by the existing PHP + bot + websocket stack.

**Future (out of scope) consideration**: if the Twitch Extension panel ever wants to display the task list to viewers, that would need new `/api/extension/tasks` endpoints. Not part of this plan.

## 5. WebSocket server (`./websocket/server.py`) — gap analysis

**What already exists** (no changes needed in Phases 1, 2, 4, 5, 6, 7):

| Event | Direction | Handler |
|---|---|---|
| `TASK_CREATE` | client → broadcast | `handle_task_create` (line ~322) |
| `TASK_UPDATE` | client → broadcast | `handle_task_update` (line ~359) |
| `TASK_COMPLETE` | client → broadcast + bot reward trigger | `handle_task_complete` (line ~327) |
| `TASK_APPROVE` | client → broadcast + bot reward trigger | `handle_task_approve` (line ~364) |
| `TASK_REJECT` | client → broadcast | `handle_task_reject` (line ~370) |
| `TASK_DELETE` | client → broadcast | `handle_task_delete` (line ~375) |
| `TASK_SETTINGS_UPDATE` | client → broadcast | `handle_task_settings_update` (line ~378) |
| `TASK_REWARD_CONFIRM` | client → broadcast | `handle_task_reward_confirm` (line ~383) |
| `TASK_REWARD_TRIGGER` | server → bot only | `emit_task_reward_trigger` (line ~336) |
| `TASK_LIST_SYNC` | server → client on register | inline at line ~592 (empty payload — overlay self-hydrates via PHP) |
| `SPECTER_TIMER_CONTROL` / `_COMMAND` / `_STATE` / `_UPDATE` | client → broadcast | `_route_timer_event` at lines ~264–276 |
| `SPECTER_SETTINGS_UPDATE` | client → broadcast | (existing, used for theme + timer settings) |
| `SPECTER_PHASE` / `SESSION_STATS` / `STATS_REQUEST` | client → broadcast | (existing) |

**Existing broadcast scoping**:
- `broadcast_to_task_clients_only()` — routes to clients whose `name` contains "task".
- `broadcast_to_timer_clients_only()` — routes to clients whose `name` contains "timer".
- Both filter by `channel_code` so a streamer's events don't leak to another streamer's overlay.

**What's NOT in the websocket server**:
- No personal pomo events (Phase 3).
- No server-side ticker for personal pomos (Phase 3).

### 5.1 Phase 3 additions to websocket/server.py

**New event handlers** (register in `setup_event_handlers()` table around line ~217):

| Event | Direction | Purpose |
|---|---|---|
| `USER_POMO_START` | bot → server → broadcast | Chatter started a personal pomo. Server creates the `user_pomos` row, then broadcasts so the overlay shows the inline badge next to that user's task. |
| `USER_POMO_UPDATE` | server → broadcast | Periodic countdown payload (every ~10s, not per-second). Fired by the server ticker. |
| `USER_POMO_PHASE` | server → broadcast | Work→break or break→work transition. Drives a chime/animation on the overlay. |
| `USER_POMO_COMPLETE` | server → broadcast | All cycles finished. Overlay clears the badge. |
| `USER_POMO_CANCEL` | bot → server → broadcast | Chatter ran `!pomo cancel`. Server marks the row cancelled. |

**New broadcast scope** (decision in §7):
- Either reuse `broadcast_to_task_clients_only()` (simplest — task-aware overlays already get the events) **or** add `broadcast_to_pomo_clients_only()` for finer-grained subscription.

**New server-side background task** (the meaningful engineering work in Phase 3):
- Implement a `pomo_ticker()` coroutine launched at server start.
- Wakes once per second.
- Queries `user_pomos` table for `status='active'` rows where `phase_ends_at <= NOW()`.
- For each: transition phase (work→break or break→work), update row, emit `USER_POMO_PHASE`. If all cycles complete, emit `USER_POMO_COMPLETE`.
- Every ~10s emits `USER_POMO_UPDATE` for each active pomo so overlays can update countdown labels without polling.
- Multi-tenant: ticker iterates across **all** per-user DBs that have a `user_pomos` table. Use the existing username→DB pattern; cache the active DB list with a 60s refresh.
- Uses the existing `./websocket/database_manager.py` (`get_connection(database_name=...)`, `execute_query()`) — no new DB infrastructure needed.

**Failure mode to handle**: if the websocket server restarts mid-pomo, `phase_ends_at` is stored as an absolute DATETIME so ticks resume correctly without losing state.

## 6. Bot → server → DB write path (clarification)

For every chat command in Phases 1, 2, 4:

1. Bot receives chat command in `beta.py`.
2. Bot writes directly to the per-user MySQL DB (`mysql_connection()` defaults to the channel's own DB).
3. Bot calls `websocket_notice(event="TASK_CREATE", ...)` (or `TASK_UPDATE` etc.) — already implemented in `beta.py` around line 12754 — to push the event to the websocket server.
4. Websocket server broadcasts to task-aware overlays/dashboards via `broadcast_to_task_clients_only()`.
5. Overlay re-renders.

For Phase 3 (personal pomos):
1. Bot receives `!pomo` chat command.
2. Bot calls `websocket_notice(event="USER_POMO_START", additional_data={...})`.
3. Websocket server creates the `user_pomos` row itself (centralised state — avoids two writers).
4. Server-side ticker manages countdown + phase transitions from that point.
5. Bot's only further involvement is `!pomo cancel`, which emits `USER_POMO_CANCEL`.

This split keeps the bot stateless about ongoing pomos — the websocket server is the single source of truth — which means viewer pomos survive a bot restart cleanly.

## 7. Implementation order recommendation

Stage shipping like this so each stage delivers user value standalone:

1. **Phase 1** — chat commands (no schema, immediate visible value).
2. **Phase 6** — cycle numbering (cosmetic, low risk, isolates well).
3. **Phase 5** — themes (cosmetic, parallel to phase 6).
4. **Phase 2** — backlog model (schema + new commands, larger).
5. **Phase 4** — projects (depends on phase 2 being live).
6. **Phase 3** — personal pomos (biggest engineering — needs the ticker, new events).
7. **Phase 7** — unified view (last, easy revert if streamers don't like it).

## 8. Open decisions

1. **`!task` collision behaviour when a user already has an active task** — refuse with a chat reply ("you already have an active task: X — use `!later` to queue"), or auto-push to backlog?
2. **Reward awarding via chat commands** — should `!done` issued by a viewer always award points (assuming reward is enabled), or only when an honour-system flag is set in `task_settings`?
3. **Backlog cap per viewer** — uncapped (any number) or capped (e.g. 20) to prevent abuse?
4. **Personal pomo concurrency** — one active pomo per viewer (cancel + replace), or queue them?
5. **Project name validation** — free text up to 100 chars, or restricted character set (alphanumeric + space + dash)? Free text is more flexible but can produce gnarly display strings.
6. **Theme set** — five themes proposed; do we ship that many at launch or start with just `dark` + one alternative and add over time?
7. **Cycle badge default** — default `show_cycle_badge` to 0 (off) so existing streamers don't see UI change without opt-in, or default to 1 (visible) for the better default behaviour?
8. **Personal pomo broadcast scope** — reuse `broadcast_to_task_clients_only()` (simplest, task-aware overlays automatically get pomo events) or add a new `broadcast_to_pomo_clients_only()` so non-task overlays can subscribe to pomo events independently?
