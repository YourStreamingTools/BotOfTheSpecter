# Task Projects (Approach B) + High-Severity Fixes — Implementation Plan

**Goal:** Make Working & Study projects first-class (registry table, move/rename/delete, counts) and visible on the dashboard + overlay, while fixing the four high-severity audit bugs in the same files.

**Architecture:** A per-user `user_projects` registry anchors project identity; tasks keep their `project` name string so the existing NULL-safe `project <=> %s` scoping in `bot/beta.py` is untouched. The bot emits a new `PROJECT_UPDATE` event over the existing `/notify` HTTP fan-out; the websocket server relays it (and the existing `TASK_*` events) channel-scoped with JSON fields decoded; dashboard and overlay render the `project` field they already receive.

**Tech Stack:** Python (aiomysql, TwitchIO 2.10 bot; aiohttp + python-socketio server), PHP 8 + mysqli (dashboard/overlay), vanilla JS + Socket.io 4 clients.

**Verification approach:** These surfaces have no test harness. Verification = `php -l`, `python -m py_compile`, and the final review pass (Task 15), plus the manual smoke flow in the spec.

**Spec:** `.claude/specs/2026-06-11-task-projects-design.md`

---

### Task 1: `user_projects` table in the central per-user schema

**Files:**
- Modify: `dashboard/includes/usr_database.php` (insert between the `'user_active_project'` entry ending line 865 and `'user_pomos'` at line 866)

- [ ] **Step 1: Add the table definition**

Insert after the `'user_active_project' => "..."` entry:

```php
        'user_projects' => "
            CREATE TABLE IF NOT EXISTS user_projects (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id VARCHAR(50) NOT NULL,
                user_name VARCHAR(100) NOT NULL,
                name VARCHAR(100) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_project (user_id, name),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
```

- [ ] **Step 2: Lint** — `php -l dashboard/includes/usr_database.php` → "No syntax errors detected"

---

### Task 2: Bot — reserved words + module-level project helpers

**Files:**
- Modify: `bot/beta.py` — `validate_project_name` (lines 11030-11040) and new helpers next to the existing task helpers (after `emit_task_complete`, ~line 11077). All helpers are module-level functions below the `# Functions for all the commands` marker, per project convention.

- [ ] **Step 1: Reserve subcommand words in `validate_project_name`**

Replace the function body:

```python
# Function to validate a project name (returns cleaned name or None when invalid)
def validate_project_name(raw):
    # §8.5: trim, then require letters/numbers/space/dash, 1-24 chars after trim.
    # 'clear' is a reserved keyword handled by the caller, not a project name.
    # Subcommand words (move/rename/delete) are reserved as a FIRST word so the
    # !project parser can never confuse a name with a subcommand.
    name = (raw or '').strip()
    if not name:
        return None
    if not re.fullmatch(r'[A-Za-z0-9 -]{1,24}', name):
        return None
    if name.split()[0].lower() in ('clear', 'move', 'rename', 'delete'):
        return None
    return name
```

- [ ] **Step 2: Add registry/move/rename/delete helpers** (insert after `emit_task_complete`, before `promote_backlog_head`)

```python
# Function to register a project name in the user_projects registry (idempotent)
async def register_user_project(cursor, user_id, user_name, name):
    await cursor.execute(
        "INSERT IGNORE INTO user_projects (user_id, user_name, name) VALUES (%s, %s, %s)",
        (user_id, user_name, name)
    )

# Function to check whether a project exists for a chatter (registry or task rows)
async def user_project_exists(cursor, user_id, name):
    await cursor.execute(
        "SELECT 1 FROM user_projects WHERE user_id = %s AND name = %s LIMIT 1",
        (user_id, name)
    )
    if await cursor.fetchone():
        return True
    await cursor.execute(
        "SELECT 1 FROM user_tasks WHERE user_id = %s AND project = %s LIMIT 1",
        (user_id, name)
    )
    return bool(await cursor.fetchone())

# Function to file a task into a target project: active slot if free, else backlog end
async def file_task_into_project(cursor, user_id, task_id, target_project):
    await cursor.execute(
        "SELECT id FROM user_tasks WHERE user_id = %s AND status = 'active' AND backlog_position IS NULL AND project <=> %s LIMIT 1",
        (user_id, target_project)
    )
    target_active = await cursor.fetchone()
    if target_active:
        await cursor.execute(
            "SELECT COALESCE(MAX(backlog_position), 0) AS max_pos FROM user_tasks WHERE user_id = %s AND status = 'pending' AND project <=> %s",
            (user_id, target_project)
        )
        row = await cursor.fetchone()
        pos = int((row.get('max_pos') if row else 0) or 0) + 1
        await cursor.execute(
            "UPDATE user_tasks SET project = %s, status = 'pending', backlog_position = %s WHERE id = %s",
            (target_project, pos, task_id)
        )
        return ('pending', pos)
    await cursor.execute(
        "UPDATE user_tasks SET project = %s, status = 'active', backlog_position = NULL WHERE id = %s",
        (target_project, task_id)
    )
    return ('active', None)

# Function to renumber a project's pending backlog contiguously (1..n)
async def renumber_project_backlog(cursor, user_id, project):
    await cursor.execute(
        "SELECT id FROM user_tasks WHERE user_id = %s AND status = 'pending' AND project <=> %s ORDER BY backlog_position ASC, id ASC",
        (user_id, project)
    )
    rows = await cursor.fetchall()
    for new_pos, row in enumerate(rows, start=1):
        await cursor.execute(
            "UPDATE user_tasks SET backlog_position = %s WHERE id = %s",
            (new_pos, row.get('id'))
        )

# Function to emit the PROJECT_UPDATE websocket event
def emit_project_update(user_id, user_name, change, name=None, old_name=None, task_id=None):
    payload = {
        "channel_code": API_TOKEN, "user_id": user_id, "user_name": user_name,
        "change": change,
    }
    if name is not None:
        payload["name"] = name
    if old_name is not None:
        payload["old_name"] = old_name
    if task_id is not None:
        payload["task_id"] = task_id
    safe_create_task(websocket_notice(event="PROJECT_UPDATE", additional_data=payload))

# Function handling !project move <n|now> <name>; returns the chat reply (no @user prefix)
async def project_move_subcommand(cursor, user_id, user_name, rest):
    parts = rest.split(' ', 1)
    if len(parts) < 2 or not parts[0]:
        return "usage: !project move <n|now> <project name>"
    selector = parts[0].lower()
    target = validate_project_name(parts[1])
    if not target:
        return "invalid project name. Use letters, numbers, spaces and dashes, max 24 characters."
    source_project = await resolve_active_project(cursor, user_id)
    if source_project is not None and target == source_project:
        return f"that task is already in \"{target}\"."
    if selector == 'now':
        await cursor.execute(
            "SELECT id, title FROM user_tasks WHERE user_id = %s AND status = 'active' AND backlog_position IS NULL AND project <=> %s LIMIT 1",
            (user_id, source_project)
        )
        task = await cursor.fetchone()
        if not task:
            return "you have no active task to move. Use !project move <n> <name> for backlog items."
        task_id, title = task.get('id'), task.get('title')
        await register_user_project(cursor, user_id, user_name, target)
        new_status, new_pos = await file_task_into_project(cursor, user_id, task_id, target)
        promoted = await promote_backlog_head(cursor, user_id, source_project)
        emit_project_update(user_id, user_name, 'move', name=target, task_id=task_id)
        emit_task_update({
            "id": task_id, "user_id": user_id, "user_name": user_name, "title": title,
            "status": new_status, "backlog_position": new_pos, "project": target, "owner": "user",
        })
        if promoted:
            emit_task_update({
                "id": promoted.get('id'), "user_id": user_id, "user_name": user_name,
                "title": promoted.get('title'), "status": "active", "project": source_project, "owner": "user",
            })
        placed = f"is now active in \"{target}\"" if new_status == 'active' else f"queued at #{new_pos} in \"{target}\""
        follow_up = f" Now working on \"{promoted.get('title')}\"." if promoted else ""
        return f"moved \"{title}\" — it {placed}.{follow_up}"
    if selector.isdigit():
        n = int(selector)
        await cursor.execute(
            "SELECT id, title FROM user_tasks WHERE user_id = %s AND status = 'pending' AND backlog_position = %s AND project <=> %s LIMIT 1",
            (user_id, n, source_project)
        )
        task = await cursor.fetchone()
        if not task:
            return f"no backlog item #{n} in your current project."
        task_id, title = task.get('id'), task.get('title')
        await register_user_project(cursor, user_id, user_name, target)
        new_status, new_pos = await file_task_into_project(cursor, user_id, task_id, target)
        renumbered = await renumber_project_backlog(cursor, user_id, source_project)
        emit_project_update(user_id, user_name, 'move', name=target, task_id=task_id)
        emit_task_update({
            "id": task_id, "user_id": user_id, "user_name": user_name, "title": title,
            "status": new_status, "backlog_position": new_pos, "project": target, "owner": "user",
        })
        placed = f"is now active in \"{target}\"" if new_status == 'active' else f"queued at #{new_pos} in \"{target}\""
        return f"moved \"{title}\" — it {placed}."
    return "usage: !project move <n|now> <project name>"

# Function handling !project rename <old> | <new>; returns the chat reply (no @user prefix)
async def project_rename_subcommand(cursor, user_id, user_name, rest):
    if '|' not in rest:
        return "usage: !project rename <old name> | <new name>"
    old_raw, new_raw = rest.split('|', 1)
    old_name = validate_project_name(old_raw)
    new_name = validate_project_name(new_raw)
    if not old_name or not new_name:
        return "invalid project name. Use letters, numbers, spaces and dashes, max 24 characters."
    if old_name == new_name:
        return "those are the same name."
    if not await user_project_exists(cursor, user_id, old_name):
        return f"you have no project named \"{old_name}\"."
    # utf8mb4_unicode_ci: a case-only rename matches the same row, so skip the
    # collision check that would otherwise see the project as already taken.
    same_ci = old_name.lower() == new_name.lower()
    if not same_ci and await user_project_exists(cursor, user_id, new_name):
        return f"you already have a project named \"{new_name}\" — use !project move to combine tasks instead."
    await register_user_project(cursor, user_id, user_name, old_name)
    await cursor.execute(
        "UPDATE user_projects SET name = %s WHERE user_id = %s AND name = %s",
        (new_name, user_id, old_name)
    )
    await cursor.execute(
        "UPDATE user_tasks SET project = %s WHERE user_id = %s AND project <=> %s",
        (new_name, user_id, old_name)
    )
    await cursor.execute(
        "UPDATE user_active_project SET project = %s WHERE user_id = %s AND project <=> %s",
        (new_name, user_id, old_name)
    )
    emit_project_update(user_id, user_name, 'rename', name=new_name, old_name=old_name)
    return f"project \"{old_name}\" renamed to \"{new_name}\"."

# Function handling !project delete <name>; returns the chat reply (no @user prefix)
async def project_delete_subcommand(cursor, user_id, user_name, rest):
    name = validate_project_name(rest)
    if not name:
        return "usage: !project delete <project name>"
    if not await user_project_exists(cursor, user_id, name):
        return f"you have no project named \"{name}\"."
    # Open tasks are NEVER deleted — they fall back to the default project. The
    # default's active slot wins: the deleted project's active task only becomes
    # the default active task when that slot is free, otherwise it queues.
    await cursor.execute(
        "SELECT id FROM user_tasks WHERE user_id = %s AND status = 'active' AND backlog_position IS NULL AND project IS NULL LIMIT 1",
        (user_id,)
    )
    default_has_active = bool(await cursor.fetchone())
    await cursor.execute(
        "SELECT id, status FROM user_tasks WHERE user_id = %s AND project = %s AND status IN ('active','pending') "
        "ORDER BY (status = 'active') DESC, backlog_position ASC, id ASC",
        (user_id, name)
    )
    open_tasks = await cursor.fetchall()
    await cursor.execute(
        "SELECT COALESCE(MAX(backlog_position), 0) AS max_pos FROM user_tasks WHERE user_id = %s AND status = 'pending' AND project IS NULL",
        (user_id,)
    )
    row = await cursor.fetchone()
    next_pos = int((row.get('max_pos') if row else 0) or 0) + 1
    moved = 0
    for task in open_tasks:
        if task.get('status') == 'active' and not default_has_active:
            await cursor.execute(
                "UPDATE user_tasks SET project = NULL, backlog_position = NULL WHERE id = %s",
                (task.get('id'),)
            )
            default_has_active = True
        else:
            await cursor.execute(
                "UPDATE user_tasks SET project = NULL, status = 'pending', backlog_position = %s WHERE id = %s",
                (next_pos, task.get('id'))
            )
            next_pos += 1
        moved += 1
    # Completed/rejected history just merges into the default scope.
    await cursor.execute(
        "UPDATE user_tasks SET project = NULL WHERE user_id = %s AND project = %s",
        (user_id, name)
    )
    await cursor.execute(
        "UPDATE user_active_project SET project = NULL WHERE user_id = %s AND project <=> %s",
        (user_id, name)
    )
    await cursor.execute(
        "DELETE FROM user_projects WHERE user_id = %s AND name = %s",
        (user_id, name)
    )
    emit_project_update(user_id, user_name, 'delete', name=name)
    if moved:
        return f"project \"{name}\" deleted — {moved} open task(s) moved to your default project."
    return f"project \"{name}\" deleted."
```

Note: `project_move_subcommand` assigns `renumbered = await renumber_project_backlog(...)` — the helper returns None; assign nothing instead (`await renumber_project_backlog(...)`).

- [ ] **Step 3: Compile check** — `python -m py_compile bot/beta.py` → exit 0

---

### Task 3: Bot — `!project` subcommand dispatch (+ emits on switch/clear)

**Files:**
- Modify: `bot/beta.py` `project_command` (lines 8580-8615)

- [ ] **Step 1: Replace the body after the cooldown check**

Replace everything from `content = ctx.message.content.strip()` (line 8580) through `add_usage('project', bucket_key, cooldown_bucket)` (line 8615) with:

```python
                    content = ctx.message.content.strip()
                    parts = content.split(' ', 1)
                    arg = parts[1].strip() if len(parts) > 1 else ''
                    user_id = str(ctx.author.id)
                    user_name = ctx.author.name
                    # !project (no arg) — report the current project.
                    if not arg:
                        project = await resolve_active_project(cursor, user_id)
                        if project:
                            await send_chat_message(f"@{user_name} your current project is \"{project}\". Use !project clear to go back to the default.")
                        else:
                            await send_chat_message(f"@{user_name} you are in the default project (no project). Use !project <name> to switch.")
                        add_usage('project', bucket_key, cooldown_bucket)
                        return
                    # !project clear — reset to the default (NULL) context. 'clear' is reserved.
                    if arg.lower() == 'clear':
                        await cursor.execute(
                            "INSERT INTO user_active_project (user_id, user_name, project) VALUES (%s, %s, NULL) "
                            "ON DUPLICATE KEY UPDATE user_name = VALUES(user_name), project = NULL",
                            (user_id, user_name)
                        )
                        emit_project_update(user_id, user_name, 'clear')
                        await send_chat_message(f"@{user_name} project cleared — you are back in the default project.")
                        add_usage('project', bucket_key, cooldown_bucket)
                        return
                    # Subcommands (reserved first words): move / rename / delete.
                    first_word = arg.split(' ', 1)[0].lower()
                    rest = arg.split(' ', 1)[1].strip() if ' ' in arg else ''
                    if first_word == 'move':
                        msg = await project_move_subcommand(cursor, user_id, user_name, rest)
                        await send_chat_message(f"@{user_name} {msg}")
                        add_usage('project', bucket_key, cooldown_bucket)
                        return
                    if first_word == 'rename':
                        msg = await project_rename_subcommand(cursor, user_id, user_name, rest)
                        await send_chat_message(f"@{user_name} {msg}")
                        add_usage('project', bucket_key, cooldown_bucket)
                        return
                    if first_word == 'delete':
                        msg = await project_delete_subcommand(cursor, user_id, user_name, rest)
                        await send_chat_message(f"@{user_name} {msg}")
                        add_usage('project', bucket_key, cooldown_bucket)
                        return
                    # !project <name> — validate (§8.5), register, and switch.
                    name = validate_project_name(arg)
                    if not name:
                        await send_chat_message(f"@{user_name} invalid project name. Use letters, numbers, spaces and dashes, max 24 characters.")
                        return
                    await register_user_project(cursor, user_id, user_name, name)
                    await cursor.execute(
                        "INSERT INTO user_active_project (user_id, user_name, project) VALUES (%s, %s, %s) "
                        "ON DUPLICATE KEY UPDATE user_name = VALUES(user_name), project = VALUES(project)",
                        (user_id, user_name, name)
                    )
                    emit_project_update(user_id, user_name, 'switch', name=name)
                    await send_chat_message(f"@{user_name} switched to project \"{name}\". Your tasks now scope to this project.")
                    add_usage('project', bucket_key, cooldown_bucket)
```

- [ ] **Step 2: Compile check** — `python -m py_compile bot/beta.py` → exit 0

---

### Task 4: Bot — `!projects` with registry, counts, active marker

**Files:**
- Modify: `bot/beta.py` `projects_command` (lines 8648-8661)

- [ ] **Step 1: Replace the listing block**

Replace from `user_id = str(ctx.author.id)` (line 8648) through the `add_usage` (line 8661) with:

```python
                    user_id = str(ctx.author.id)
                    user_name = ctx.author.name
                    # Self-heal: fold any pre-registry task projects into user_projects.
                    await cursor.execute(
                        "INSERT IGNORE INTO user_projects (user_id, user_name, name) "
                        "SELECT DISTINCT user_id, %s, project FROM user_tasks "
                        "WHERE user_id = %s AND project IS NOT NULL",
                        (user_name, user_id)
                    )
                    await cursor.execute(
                        "SELECT p.name, COALESCE(SUM(CASE WHEN t.status IN ('active','pending') THEN 1 ELSE 0 END), 0) AS open_count "
                        "FROM user_projects p "
                        "LEFT JOIN user_tasks t ON t.user_id = p.user_id AND t.project = p.name "
                        "WHERE p.user_id = %s GROUP BY p.name ORDER BY p.name ASC",
                        (user_id,)
                    )
                    rows = await cursor.fetchall()
                    if not rows:
                        await send_chat_message(f"@{user_name} you have no projects yet. Use !project <name> to start one.")
                    else:
                        active = await resolve_active_project(cursor, user_id)
                        listing = []
                        for r in rows:
                            pname = r.get('name')
                            open_count = int(r.get('open_count') or 0)
                            marker = ", active" if active is not None and pname == active else ""
                            listing.append(f"{pname} ({open_count} open{marker})")
                        message = f"@{user_name} your projects: {', '.join(listing)}"
                        await send_chat_message(message[:MAX_CHAT_MESSAGE_LENGTH])
                    add_usage('projects', bucket_key, cooldown_bucket)
```

- [ ] **Step 2: Compile check** — `python -m py_compile bot/beta.py` → exit 0

---

### Task 5: Bot — `websocket_notice` carries PROJECT_UPDATE and drops None values

**Files:**
- Modify: `bot/beta.py` lines 14483-14488

- [ ] **Step 1: Extend the TASK_* transport branch**

```python
                elif event in ["TASK_CREATE", "TASK_UPDATE", "TASK_COMPLETE", "TASK_DELETE", "TASK_REWARD_CONFIRM", "PROJECT_UPDATE"]:
                    # Working & Study task events. The task payload is JSON-encoded so the
                    # nested dict survives URL-encoding; the overlay/dashboard decode it.
                    # Top-level None values are dropped — urlencode would stringify them
                    # to the literal string 'None'.
                    if additional_data:
                        for _task_key, _task_val in additional_data.items():
                            if _task_val is None:
                                continue
                            params[_task_key] = json.dumps(_task_val) if isinstance(_task_val, (dict, list)) else _task_val
```

- [ ] **Step 2: Compile check** — `python -m py_compile bot/beta.py` → exit 0

---

### Task 6: Server — PROJECT_UPDATE relay + decoded, channel-scoped `/notify` task events

**Files:**
- Modify: `websocket/server.py` — handler table (~line 221), new handler after `handle_task_reward_confirm` (line 415), `notify_http` (insert before the `USER_POMO_START` elif at line 1171)

- [ ] **Step 1: Add the socket relay handler** (after `handle_task_reward_confirm`)

```python
    async def handle_project_update(self, sid, data):
        payload = data if isinstance(data, dict) else {}
        self.logger.info(f"PROJECT_UPDATE from [{sid}]: {payload}")
        await self.broadcast_to_task_clients_only("PROJECT_UPDATE", payload, source_sid=sid)
```

- [ ] **Step 2: Register it** — in the `event_handlers` list after `("TASK_SETTINGS_UPDATE", self.handle_task_settings_update),`:

```python
            ("PROJECT_UPDATE",       self.handle_project_update),
```

- [ ] **Step 3: Explicit `/notify` branch** — insert immediately before `elif event == "USER_POMO_START":` in `notify_http`:

```python
        elif event in ["TASK_CREATE", "TASK_UPDATE", "TASK_COMPLETE", "TASK_DELETE", "TASK_REWARD_CONFIRM", "PROJECT_UPDATE"]:
            # Working & Study events from the bot. The bot JSON-encodes nested values
            # (the 'task' dict) for URL transport — decode them so the dashboard and
            # overlay receive real objects, then relay channel-scoped exactly like the
            # socket-path TASK_* handlers (no global listeners).
            raw_task = data.get("task")
            if isinstance(raw_task, str):
                try:
                    data["task"] = json.loads(raw_task)
                except (ValueError, TypeError):
                    pass
            count = await self.broadcast_to_task_clients_only(event, data)
```

(`data` carries the `code` query param, which `broadcast_to_task_clients_only` resolves; this also stops bot task events leaking to global listeners via the generic branch. Reward behaviour is unchanged: bot-side completions award in-process, so this branch must NOT call `emit_task_reward_trigger`.)

- [ ] **Step 4: Compile check** — `python -m py_compile websocket/server.py` → exit 0

---

### Task 7: Server — pomo ticker only ticks DBs with active pomos

**Files:**
- Modify: `websocket/server.py` — replace `refresh_pomo_active_dbs` (lines 601-622), `_process_pomo_db` (lines 696-718), and the ticker body inside `start_pomo_ticker_task` (lines 720-754). Update the stale comment at line 540.

- [ ] **Step 1: Replace `refresh_pomo_active_dbs` with a startup-only scan**

```python
    async def scan_active_pomo_dbs(self):
        # One-time startup recovery: find tenants with a running pomo so the ticker
        # can resume them after a restart. Steady-state tracking is event-driven —
        # USER_POMO_START adds a DB, a tick that finds no active pomos removes it —
        # so the ticker never polls every user database.
        try:
            rows = await self.execute_query(
                "SELECT username, api_key FROM users WHERE username IS NOT NULL AND api_key IS NOT NULL",
                database_name='website'
            )
            if not rows:
                self.logger.warning("scan_active_pomo_dbs: users query returned no rows")
                return
            found = 0
            for r in rows:
                username = r.get('username')
                api_key = r.get('api_key')
                if not username or not api_key:
                    continue
                active = await self.execute_query(
                    "SELECT COUNT(*) AS cnt FROM user_pomos WHERE status = 'active'",
                    database_name=username
                )
                if active and int(active[0].get('cnt') or 0) > 0:
                    self._pomo_active_dbs[username] = api_key
                    found += 1
            self.logger.info(f"scan_active_pomo_dbs: resuming pomo ticking for {found} database(s)")
        except Exception as e:
            self.logger.error(f"scan_active_pomo_dbs error: {e}")
```

- [ ] **Step 2: Replace `_process_pomo_db`** (single query per tick; returns liveness)

```python
    async def _process_pomo_db(self, db_name, code, do_update):
        # Returns True while this DB still has an active pomo; False tells the ticker
        # to stop tracking it (the next USER_POMO_START re-adds it). A failed query
        # (tenant DB or user_pomos table missing) also untracks instead of logging
        # the same error every second.
        actives = await self.execute_query(
            "SELECT id, user_id, user_name, label, work_minutes, break_minutes, total_cycles, "
            "current_cycle, current_phase, "
            "DATE_FORMAT(phase_started_at, '%%Y-%%m-%%dT%%H:%%i:%%sZ') AS phase_started_at, "
            "DATE_FORMAT(phase_ends_at, '%%Y-%%m-%%dT%%H:%%i:%%sZ') AS phase_ends_at, "
            "TIMESTAMPDIFF(SECOND, NOW(), phase_ends_at) AS remaining_seconds, "
            "(phase_ends_at <= NOW()) AS expired, status "
            "FROM user_pomos WHERE status = 'active'",
            (), database_name=db_name
        )
        if not actives:
            return False
        expired_rows = [r for r in actives if r.get('expired')]
        for row in expired_rows:
            await self._advance_pomo_row(db_name, code, row)
        if do_update:
            fresh = actives
            if expired_rows:
                # Phases just advanced — re-read so UPDATE broadcasts carry new state.
                fresh = await self.execute_query(
                    "SELECT id, user_id, user_name, label, work_minutes, break_minutes, total_cycles, "
                    "current_cycle, current_phase, "
                    "DATE_FORMAT(phase_started_at, '%%Y-%%m-%%dT%%H:%%i:%%sZ') AS phase_started_at, "
                    "DATE_FORMAT(phase_ends_at, '%%Y-%%m-%%dT%%H:%%i:%%sZ') AS phase_ends_at, "
                    "TIMESTAMPDIFF(SECOND, NOW(), phase_ends_at) AS remaining_seconds, status "
                    "FROM user_pomos WHERE status = 'active'",
                    (), database_name=db_name
                )
            for row in (fresh or []):
                payload = self._pomo_row_to_payload(row, code)
                await self.broadcast_to_task_clients_only("USER_POMO_UPDATE", payload)
        return True
```

- [ ] **Step 3: Replace the ticker loop** (drop the 60s refresh, untrack empty DBs)

```python
    async def start_pomo_ticker_task(self):
        # Launch the once-per-second pomo ticker. Initialises the active-DB set with a
        # one-time recovery scan, then loops. The set only ever contains tenants with
        # a running pomo. Stored as self.pomo_ticker_task so on_shutdown can cancel it.
        if not hasattr(self, '_pomo_active_dbs'):
            self._pomo_active_dbs = {}
        await self.scan_active_pomo_dbs()

        async def pomo_ticker():
            last_global_update = time.time()
            while True:
                try:
                    await asyncio.sleep(1)
                    now = time.time()
                    # Decide whether this tick should also emit periodic UPDATE broadcasts.
                    do_update = (now - last_global_update) >= self.POMO_UPDATE_INTERVAL
                    if do_update:
                        last_global_update = now
                    # Iterate a snapshot so a concurrent USER_POMO_START can't mutate mid-loop.
                    for db_name, code in list(self._pomo_active_dbs.items()):
                        try:
                            still_active = await self._process_pomo_db(db_name, code, do_update)
                            if not still_active:
                                self._pomo_active_dbs.pop(db_name, None)
                                self.logger.info(f"pomo_ticker: no active pomos in {db_name}, untracking")
                        except Exception as e:
                            # Multi-tenant isolation: log and continue past a bad tenant.
                            self.logger.error(f"pomo_ticker: error processing DB {db_name}: {e}")
                except asyncio.CancelledError:
                    break
                except Exception as e:
                    self.logger.error(f"Error in pomo ticker: {e}")
        self.pomo_ticker_task = asyncio.create_task(pomo_ticker())
        self.logger.info("Pomo ticker task started")
```

- [ ] **Step 4: Update the stale comment** at `handle_user_pomo_start_http` line 540 from `# Make sure the ticker watches this DB immediately (don't wait for the 60s refresh).` to `# Event-driven tracking: this is what puts the DB on the ticker's active set.`

- [ ] **Step 5: Compile check** — `python -m py_compile websocket/server.py` → exit 0. Also `Grep refresh_pomo_active_dbs websocket/` → no remaining references.

---

### Task 8: Server — remove the stub TASK_LIST_SYNC on REGISTER

**Files:**
- Modify: `websocket/server.py` lines 914-917

- [ ] **Step 1: Delete the stub push**

Remove:

```python
            # Push TASK_LIST_SYNC to task-aware clients on connect so they can hydrate state
            if channel.lower() in ['dashboard', 'overlay'] and 'task' in sid_name.lower():
                await self.sio.emit("TASK_LIST_SYNC", {"channel_code": code, "streamer_tasks": [], "user_tasks": []}, to=sid)
                self.logger.info(f"Sent TASK_LIST_SYNC to newly registered task client [{sid}]")
```

(Nothing in the repo ever sends a populated TASK_LIST_SYNC; both clients fetch real data themselves on connect. The empty push races those fetches and can wipe them.)

- [ ] **Step 2: Compile check** — `python -m py_compile websocket/server.py` → exit 0

---

### Task 9: Dashboard PHP — stop `save_settings` clobbering reward columns

**Files:**
- Modify: `dashboard/working-or-study.php` lines 168-198

- [ ] **Step 1: Drop `reward_enabled`/`reward_points_per_task` from the timer save**

Remove lines 173-174 (`$rewardEnabled = ...` and `$rewardPoints = ...`) and change the UPDATE/bind (lines 189/194) to:

```php
        $stmt = $db->prepare("UPDATE working_study_overlay_settings SET focus_minutes = ?, micro_break_minutes = ?, recharge_break_minutes = ?, cycle_count = ?, show_cycle_badge = ?, theme = ?, list_view_mode = ? WHERE id = 1");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $db->error]);
            exit;
        }
        $stmt->bind_param("iiiiiss", $focus, $micro, $recharge, $cycleCount, $showCycleBadge, $theme, $listViewMode);
```

Add above the prepare, replacing the two removed lines:

```php
        // reward_enabled / reward_points_per_task are deliberately NOT written here:
        // the timer UI has no controls for them and the bot reads them to decide
        // !done point awards — writing defaults would silently disable rewards.
```

- [ ] **Step 2: Lint** — `php -l dashboard/working-or-study.php`

---

### Task 10: Dashboard PHP — `ch_get_tasks` returns project + backlog_position

**Files:**
- Modify: `dashboard/working-or-study.php` line 242

- [ ] **Step 1: Extend the SELECT**

```php
                $ut = $db->prepare("SELECT id, streamer_task_id, user_id, user_name, title, description, category, status, approval_status, reward_points, backlog_position, project, completed_at, created_at FROM user_tasks ORDER BY created_at DESC");
```

- [ ] **Step 2: Lint** — `php -l dashboard/working-or-study.php`

---

### Task 11: Dashboard JS — echo-loop fix, payload parsing, owner routing, sync guard, PROJECT_UPDATE

**Files:**
- Modify: `dashboard/working-or-study.php` socket handlers (lines 1684-1700)

- [ ] **Step 1: Replace the TASK_CREATE / TASK_UPDATE / TASK_LIST_SYNC handlers and add helpers**

Replace lines 1684-1700 with:

```js
    // /notify transport JSON-encodes the nested task dict — decode if needed.
    function chParseTask(raw) {
        if (typeof raw === 'string') {
            try { return JSON.parse(raw); } catch (e) { return null; }
        }
        return raw || null;
    }
    // Received events render with emit=false — re-emitting an inbound event would
    // ping-pong it between connected dashboards forever.
    chSocket.on('TASK_CREATE',          (d) => {
        const task = chParseTask(d?.task);
        if (!task) return;
        const owner = String(d?.owner || task.owner || '').toLowerCase();
        if (owner === 'streamer' || (!owner && !task.user_name)) {
            chAppendStreamerRow(task, false);
        } else {
            chAppendUserRow(task, false);
        }
        chShowToast(wsLang.taskCreated.replace(':title', task.title || ''));
    });
    chSocket.on('TASK_UPDATE',          (d) => {
        const task = chParseTask(d?.task);
        if (!task) return;
        const owner = String(d?.owner || task.owner || '').toLowerCase();
        if (owner === 'streamer' || (!owner && !task.user_name)) {
            chAppendStreamerRow(task, false);
            return;
        }
        chAppendUserRow(task, false);
    });
    chSocket.on('TASK_COMPLETE',        (d) => { chMarkStatus(d.task_id, d.owner || 'user', 'completed'); });
    chSocket.on('TASK_APPROVE',         (d) => { chUpdateApproval(d.task_id, 'approved'); });
    chSocket.on('TASK_REJECT',          (d) => { chUpdateApproval(d.task_id, 'rejected'); });
    chSocket.on('TASK_DELETE',          (d) => { chRemoveRow(d.task_id, d.owner || 'streamer'); });
    chSocket.on('TASK_LIST_SYNC',       (d) => {
        const hasContent = ((d?.streamer_tasks || []).length + (d?.user_tasks || []).length) > 0;
        if (!hasContent) return; // an empty sync would wipe the lists chLoadTasks() just rendered
        chRenderStreamer(d.streamer_tasks || []);
        chRenderUser(d.user_tasks || []);
    });
    chSocket.on('PROJECT_UPDATE',       () => { chLoadTasks(); });
```

- [ ] **Step 2: Lint** — `php -l dashboard/working-or-study.php`

---

### Task 12: Dashboard — Project column + filter UI + i18n

**Files:**
- Modify: `dashboard/working-or-study.php` — viewer table head (~line 963), empty-row colspans (lines ~967 and JS line ~1727), `chAppendUserRow`, new filter markup + JS, `wsLang`
- Modify: `dashboard/lang/en.php`, `dashboard/lang/de.php`, `dashboard/lang/fr.php`

- [ ] **Step 1: New lang keys** (en, after `working_or_study_th_user`-style keys in the working-or-study block; mirror placement in de/fr)

```php
    // en.php
    'working_or_study_th_project' => 'Project',
    'working_or_study_filter_all_projects' => 'All projects',
    'working_or_study_filter_default_project' => 'Default project',
    // de.php
    'working_or_study_th_project' => 'Projekt',
    'working_or_study_filter_all_projects' => 'Alle Projekte',
    'working_or_study_filter_default_project' => 'Standardprojekt',
    // fr.php
    'working_or_study_th_project' => 'Projet',
    'working_or_study_filter_all_projects' => 'Tous les projets',
    'working_or_study_filter_default_project' => 'Projet par défaut',
```

- [ ] **Step 2: Table head + filter dropdown**

Replace the viewer table `<thead>` row (line 963) with a 7-column version (PROJECT after TASK):

```php
<tr><th><?= t('working_or_study_th_user') ?></th><th><?= t('working_or_study_th_task') ?></th><th><?= t('working_or_study_th_project') ?></th><th><?= t('working_or_study_th_status') ?></th><th><?= t('working_or_study_th_approval') ?></th><th><?= t('working_or_study_th_pts') ?></th><th><?= t('working_or_study_th_actions') ?></th></tr>
```

Update the PHP empty row (line ~967) to `colspan="7"`. Insert the filter between the `<h3>` (line 956-959) and `.table-container`:

```php
                    <div class="field mb-2">
                        <div class="control">
                            <div class="select is-small">
                                <select id="chProjectFilter">
                                    <option value="__all"><?= t('working_or_study_filter_all_projects') ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
```

- [ ] **Step 3: JS — wsLang entries, row rendering, filter logic**

Add to `wsLang` (after `networkError`): `filterAllProjects: <?php echo json_encode(t('working_or_study_filter_all_projects')); ?>, filterDefaultProject: <?php echo json_encode(t('working_or_study_filter_default_project')); ?>`.

In `chRenderUser`, change the empty-row `colspan` to 7 and call `chRefreshProjectFilter()` after the forEach. In `chAppendUserRow`, set the project data-key + cell and refresh the filter:

```js
    function chAppendUserRow(task, emit = true) {
        if (!task) return;
        document.getElementById('chUserEmpty')?.remove();
        const tbody = document.getElementById('chUserTaskBody');
        if (!tbody) return;
        let row = document.getElementById('ch-ut-' + task.id) || document.createElement('tr');
        row.id = 'ch-ut-' + task.id;
        const canApprove = task.approval_status === 'pending_approval';
        const projectKey = (task.project === null || task.project === undefined || task.project === '') ? '__default' : String(task.project);
        row.setAttribute('data-project-key', projectKey);
        const projectCell = projectKey === '__default'
            ? '<span class="has-text-grey">&mdash;</span>'
            : `<span class="tag is-light">${chEsc(task.project)}</span>`;
        row.innerHTML = `
            <td>${chEsc(task.user_name)}</td>
            <td><strong>${chEsc(task.title)}</strong></td>
            <td>${projectCell}</td>
            <td>${chStatusTag(task.status)}</td>
            <td>${chApprovalTag(task.approval_status)}</td>
            <td>${task.reward_points ?? 0}</td>
            <td>
                <div class="buttons are-small">
                    ${task.status === 'active' ? `<button class="button is-success is-light" onclick="chCompleteUser(${task.id})">${chEsc(wsLang.btnDone)}</button>` : ''}
                    ${canApprove ? `<button class="button is-link is-light" onclick="chAwardUser(${task.id})">${chEsc(wsLang.btnAward)}</button>` : ''}
                    ${canApprove ? `<button class="button is-warning is-light" onclick="chRejectUser(${task.id})">${chEsc(wsLang.btnReject)}</button>` : ''}
                </div>
            </td>`;
        if (!document.getElementById('ch-ut-' + task.id)) tbody.appendChild(row);
        chRefreshProjectFilter();
    }
    function chRefreshProjectFilter() {
        const sel = document.getElementById('chProjectFilter');
        if (!sel) return;
        const current = sel.value || '__all';
        const counts = new Map();
        document.querySelectorAll('#chUserTaskBody tr[data-project-key]').forEach((row) => {
            const key = row.getAttribute('data-project-key');
            counts.set(key, (counts.get(key) || 0) + 1);
        });
        const total = [...counts.values()].reduce((a, b) => a + b, 0);
        let html = `<option value="__all">${chEsc(wsLang.filterAllProjects)} (${total})</option>`;
        html += `<option value="__default">${chEsc(wsLang.filterDefaultProject)} (${counts.get('__default') || 0})</option>`;
        [...counts.keys()].filter(k => k !== '__default').sort((a, b) => a.localeCompare(b)).forEach((k) => {
            html += `<option value="${chEsc(k)}">${chEsc(k)} (${counts.get(k)})</option>`;
        });
        sel.innerHTML = html;
        sel.value = [...sel.options].some(o => o.value === current) ? current : '__all';
        chApplyProjectFilter();
    }
    function chApplyProjectFilter() {
        const filter = document.getElementById('chProjectFilter')?.value || '__all';
        document.querySelectorAll('#chUserTaskBody tr[data-project-key]').forEach((row) => {
            row.style.display = (filter === '__all' || row.getAttribute('data-project-key') === filter) ? '' : 'none';
        });
    }
    document.getElementById('chProjectFilter')?.addEventListener('change', chApplyProjectFilter);
```

- [ ] **Step 4: Lint** — `php -l` on `working-or-study.php`, `en.php`, `de.php`, `fr.php`

---

### Task 13: Overlay — project chips, payload parsing, sync guard, PROJECT_UPDATE

**Files:**
- Modify: `overlay/working-or-study.php` — `get_channel_tasks` (line 179), renderers (`newViewerUpsert` ~1239, `renderUnifiedList` ~1097), socket handlers (1596-1613)
- Modify: `overlay/index.css` — new class in the study overlay section (~line 1356, after the `.is-backlog` rules)

- [ ] **Step 1: PHP — select `project`** (line 179)

```php
            $u = $user_db->prepare("SELECT id, user_id, user_name, title, description, status, reward_points, completed_at, project FROM user_tasks WHERE status != 'rejected' ORDER BY created_at DESC");
```

- [ ] **Step 2: JS — parse helper + chip helper** (insert above `newStreamerUpsert`, near `getTaskDescription`)

```js
            // /notify transport JSON-encodes the nested task dict — decode if needed.
            const parseTaskPayload = (raw) => {
                if (typeof raw === 'string') {
                    try { return JSON.parse(raw); } catch (e) { return null; }
                }
                return raw || null;
            };
            const projectChipHtml = (task) => {
                const project = String(task?.project || '').trim();
                if (!project) return '';
                return `<span class="study-overlay-page-task-sys-item-project">${escapeHtml(project)}</span>`;
            };
```

- [ ] **Step 3: JS — render the chip**

`newViewerUpsert` body line (1256) becomes:

```js
                li.innerHTML = `<div class="study-overlay-page-task-sys-item-check"></div><div class="study-overlay-page-task-sys-item-body"><div class="study-overlay-page-task-sys-item-title">${escapeHtml(userName)}: ${escapeHtml(taskDescription)}</div>${projectChipHtml(task)}</div>`;
```

`renderUnifiedList` row line (1097) becomes:

```js
                    li.innerHTML = `<div class="study-overlay-page-task-sys-item-check"></div><div class="study-overlay-page-task-sys-item-body"><div class="study-overlay-page-task-sys-item-title">${escapeHtml(r.userName)}: ${escapeHtml(taskDescription)}</div>${r.owner === 'viewer' ? projectChipHtml(t) : ''}</div>`;
```

- [ ] **Step 4: JS — handlers** (replace lines 1596-1613)

```js
                socket.on('TASK_LIST_SYNC', (d) => {
                    const hasContent = ((d?.streamer_tasks || []).length + (d?.user_tasks || []).length) > 0;
                    if (!hasContent) return; // an empty sync would wipe lists loadChannelTasks() just rendered
                    latestStreamerTasks = d.streamer_tasks || [];
                    latestViewerTasks = d.user_tasks || [];
                    renderTaskLists();
                });
                socket.on('PROJECT_UPDATE', () => {
                    loadChannelTasks();
                });
                socket.on('TASK_CREATE', (d) => {
                    const task = parseTaskPayload(d?.task);
                    if (!task) return;
                    if (getListViewMode() === 'unified') { loadChannelTasks(); return; }
                    const owner = String(d?.owner || task?.owner || '').toLowerCase();
                    if (owner === 'streamer' || (!owner && !task?.user_name)) newStreamerUpsert(task);
                    else newViewerUpsert(task);
                });
                socket.on('TASK_UPDATE', (d) => {
                    const task = parseTaskPayload(d?.task);
                    if (!task) return;
                    if (getListViewMode() === 'unified') { loadChannelTasks(); return; }
                    const owner = String(d?.owner || task?.owner || '').toLowerCase();
                    if (owner === 'streamer' || (!owner && !task?.user_name)) newStreamerUpsert(task);
                    else newViewerUpsert(task);
                });
```

- [ ] **Step 5: CSS — chip style** (in `overlay/index.css`, after the `.is-backlog` rules ~line 1355; uses the existing study-overlay theme vars, resolution-independent)

```css
.study-overlay-page-task-sys-item-project {
    display: inline-block;
    font-size: .6rem;
    font-weight: 700;
    line-height: 1.4;
    color: var(--task-text-muted);
    border: 1px solid var(--task-text-muted);
    border-radius: 999px;
    padding: 0 6px;
    margin-top: 2px;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
```

- [ ] **Step 6: Lint** — `php -l overlay/working-or-study.php`

---

### Task 14: Help text — `builtin_commands.json`

**Files:**
- Modify: `api/builtin_commands.json` lines 163-170

- [ ] **Step 1: Replace the `project` and `projects` entries**

```json
		"project": {
			"description": "Manages your Working & Study projects. !project shows your current project, !project <name> switches to it (created on first use), and !project clear returns to the default. !project move <n|now> <name> attaches backlog item #n (or your active task) to another project, !project rename <old> | <new> renames one, and !project delete <name> removes one — its tasks return to your default project. Names allow letters, numbers, spaces and dashes, up to 24 characters; they can't start with clear, move, rename or delete.",
			"syntax": ["!project", "!project Studying", "!project clear", "!project move 2 Studying", "!project move now Studying", "!project rename Studying | Uni Work", "!project delete Uni Work"]
		},
		"projects": {
			"description": "Lists your Working & Study projects with how many open tasks each one has, marking the project you're currently in.",
			"syntax": "!projects"
		},
```

- [ ] **Step 2: Validate JSON** — `python -c "import json; json.load(open('api/builtin_commands.json', encoding='utf-8'))"` → exit 0

---

### Task 15: Verification

- [ ] **Step 1: Lints** — `php -l` on: `dashboard/includes/usr_database.php`, `dashboard/working-or-study.php`, `dashboard/lang/en.php`, `dashboard/lang/de.php`, `dashboard/lang/fr.php`, `overlay/working-or-study.php`. All "No syntax errors detected".
- [ ] **Step 2: Compiles** — `python -m py_compile bot/beta.py websocket/server.py` → exit 0.
- [ ] **Step 3: Cross-surface review** — verify payload contract (event names/fields match across beta.py, server.py, dashboard, overlay), project invariants (≤1 active task per project, contiguous backlog), regression risk on the four bug fixes. Fix confirmed findings, re-lint.
- [ ] **Step 4: Deploy notes** — deploy restarts BOTH the bot and the websocket server, plus a re-sync of `/home/botofthespecter/builtin_commands.json` (server).
