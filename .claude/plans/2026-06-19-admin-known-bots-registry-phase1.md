# Admin-Editable Known-Bots Registry — Phase 1 Implementation Plan

**Goal:** Move the hardcoded `KNOWN_BOT_LOGINS` set into an admin-editable `known_bots` table in the central `website` DB, and have the dashboard leaderboards read it (cached) so admins manage the bot-exclusion list from the dashboard.

**Architecture:** A new `website.known_bots` table (one row per login) is created + seeded at API startup. The PHP admin page `dashboard/admin/known_bots.php` does CRUD against it directly (like `api_keys.php`); the FastAPI server only *reads* it via a 5-minute cached helper that falls back to the original hardcoded seed. The `/dashboard/leaderboards` endpoint uses that helper and applies the bot filter to all person-keyed boards.

**Tech Stack:** Python 3 / FastAPI / aiomysql (API), PHP 8 / mysqli (dashboard), MySQL, vanilla JS + SweetAlert (`Swal`) on the admin page.

**Spec:** `.claude/specs/2026-06-19-admin-known-bots-registry.md`

## Global Constraints

- **No test framework exists in this repo.** Verify Python with `python -m py_compile`, PHP with `php -l`, plus the functional checks each task describes. Do NOT add pytest/PHPUnit.
- **PHP never reads `.env`.** Config comes from `./config/{service}.php` (dev) → `/var/www/config/` (server). The admin page uses `db_connect.php`.
- **DB:** parameterized queries only — no string-interpolated values. The `known_bots` table is in the central `website` DB: Python uses `get_mysql_connection()` (NOT `get_mysql_connection_user()`); PHP uses the `$conn` from `db_connect.php`.
- **Paths:** repo paths use `./`; server runtime paths live under `/var/www/...` (the admin page's `require_once` lines use the server absolute paths, exactly as sibling admin pages do).
- **Bot files are untouched** in Phase 1 (`bot.py`/`beta.py`/`beta-v6.py`). Bot consumption is Phase 2.
- **Login validation:** Twitch login normalized to lowercase, leading `@` stripped, must match `^[a-z0-9_]{1,25}$`.
- **Cache:** TTL = 300s; on empty result or any DB error, fall back to `DEFAULT_KNOWN_BOTS_SEED`.
- **Admin gate:** `is_admin` via `admin_access.php` (NOT `super_admin`).

---

### Task 1: API — DB-backed known-bots registry infrastructure

Introduce the table DDL, rename the hardcoded set to a seed, add the cached read-through and the startup ensure/seed, wire the lifespan call, mirror the schema into the canonical setup file, and switch the leaderboards endpoint's existing filter source to the DB (no behaviour change yet — coverage is extended in Task 2).

**Files:**
- Modify: `./api/api.py` — replace the `KNOWN_BOT_LOGINS` block (lines 5580-5589); add DDL + cache + two async functions in the same region; add one call inside `lifespan` (after `./api/api.py:621`); change the endpoint's `excluded_logins` source (line 5622).

> **Note:** the `./help` folder is deprecated — do NOT add the schema there. The table's durable definition is `ensure_known_bots_table()` in `api.py` (created + seeded at startup), matching how `admin_api_keys`/`bot_chat_token` already exist with no canonical-schema-file entry.

**Interfaces:**
- Produces:
  - `DEFAULT_KNOWN_BOTS_SEED: set[str]` — the original hardcoded logins (renamed).
  - `KNOWN_BOTS_TABLE_DDL: str` — `CREATE TABLE IF NOT EXISTS known_bots (...)`.
  - `async def get_known_bots_list() -> set` — lowercased active bot logins, seed fallback. **Task 2 consumes this.**
  - `async def ensure_known_bots_table() -> None` — idempotent create + seed-if-empty.

- [ ] **Step 1: Rename the hardcoded set and add the DDL + cache + helpers**

In `./api/api.py`, replace the existing block at lines 5580-5589 (currently `KNOWN_BOT_LOGINS = { ... }`) with the following. The login set is byte-for-byte the current contents, just renamed:

```python
# Seed for the admin-managed global known-bots registry (website.known_bots).
# Used to populate an empty table and as the fallback when the table is empty
# or unreachable. Admins edit the live list from dashboard/admin/known_bots.php.
DEFAULT_KNOWN_BOTS_SEED = {
    "botofthespecter", "nightbot", "streamelements", "streamlabs", "moobot",
    "fossabot", "wizebot", "soundalerts", "pretzelrocks", "streamstickers",
    "tangiabot", "kofistreambot", "blerp", "own3d", "streamlootsbot",
    "restreambot", "songlistbot", "deepbot", "vivbot", "phantombot", "coebot",
    "sery_bot", "lattemotte", "creatisbot", "commanderroot", "anotherttvviewer",
    "communityshowcase", "lurxx", "0_applejuice_0", "electricallongboard",
    "feardn", "icewaslit", "p0lizei_", "n3td3v", "8hvdes", "host_giveaway",
    "twitchprimereminder", "streamfahrer", "logviewer", "v_and_k", "the_marlchurch",
}

KNOWN_BOTS_TABLE_DDL = """
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
"""

# In-memory cache for the global known-bots list (mirrors the Twitch-creds cache).
_known_bots_cache = {"loaded_at": 0.0, "bots": frozenset()}
_KNOWN_BOTS_TTL = 300  # seconds; admin edits take effect within this window

async def get_known_bots_list() -> set:
    # Returns a lowercased set of active bot logins from website.known_bots.
    # Falls back to DEFAULT_KNOWN_BOTS_SEED on empty result or any error so the
    # leaderboard bot filter always has data.
    global _known_bots_cache
    import time as _time
    now = _time.time()
    if (now - _known_bots_cache["loaded_at"]) < _KNOWN_BOTS_TTL and _known_bots_cache["bots"]:
        return set(_known_bots_cache["bots"])
    bots = set()
    try:
        conn = await get_mysql_connection()
        try:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute("SELECT bot_login FROM known_bots WHERE is_active = 1")
                rows = await cur.fetchall()
                bots = {str(r["bot_login"]).strip().lower() for r in rows if r.get("bot_login")}
        finally:
            conn.close()
    except Exception as e:
        logging.warning(f"Failed to load known_bots list; using seed: {e}")
    if not bots:
        bots = set(DEFAULT_KNOWN_BOTS_SEED)
    _known_bots_cache = {"loaded_at": now, "bots": frozenset(bots)}
    return set(bots)

async def ensure_known_bots_table():
    # Idempotent; api.py owns the schema. The admin PHP page also issues
    # CREATE TABLE IF NOT EXISTS defensively. Seeds DEFAULT_KNOWN_BOTS_SEED on an
    # empty table so leaderboard filtering matches prior behaviour on first deploy.
    try:
        conn = await get_mysql_connection()
        try:
            async with conn.cursor() as cur:
                await cur.execute(KNOWN_BOTS_TABLE_DDL)
                await cur.execute("SELECT COUNT(*) FROM known_bots")
                row = await cur.fetchone()
                count = row[0] if row else 0
                if not count:
                    await cur.executemany(
                        "INSERT INTO known_bots (bot_login, added_by) VALUES (%s, %s)",
                        [(login, "system") for login in sorted(DEFAULT_KNOWN_BOTS_SEED)]
                    )
                await conn.commit()
        finally:
            conn.close()
    except Exception as e:
        logging.error(f"Failed to ensure known_bots table: {e}")
```

- [ ] **Step 2: Call the ensure function at startup**

In `./api/api.py`, in the `lifespan` handler, immediately after the existing `await ensure_custom_webhooks_table()` line (currently `./api/api.py:621`), add:

```python
    # Ensure the admin-managed global known-bots registry exists (seeded on first run)
    await ensure_known_bots_table()
```

- [ ] **Step 3: Point the leaderboards filter at the DB-backed list**

In `./api/api.py`, inside `get_dashboard_leaderboards`, change the single line that currently reads (line 5622):

```python
                excluded_logins = set(KNOWN_BOT_LOGINS)
```

to:

```python
                excluded_logins = set(await get_known_bots_list())
```

Leave the rest of the endpoint (the `watch_time_excluded_users` union, `bot_ph`, and the existing watch/chat filters) unchanged — Task 2 extends coverage.

- [ ] **Step 4: (removed)** — The canonical-schema entry was originally planned for `./help/run_yourself.php`, but that folder is deprecated and must not be edited. The table is guaranteed at runtime by `ensure_known_bots_table()` (Step 1 + Step 2). No action.

- [ ] **Step 5: Verify Python compiles and no stale references remain**

Run:
```bash
python -m py_compile api/api.py
```
Expected: exits 0, no output.

Then confirm the old name is fully gone:
```bash
grep -rn "KNOWN_BOT_LOGINS" api/api.py
```
Expected: **no matches** (the only references were the definition and line 5622, both renamed/replaced).

---

### Task 2: API — extend the bot filter to all person-keyed boards

Apply the existing `LOWER(<col>) NOT IN (<placeholders>)` filter (already built once as `bot_ph` / `excluded_params`) to the Streaks board and the three interaction-leader boards, so bots are hidden everywhere a username appears.

**Files:**
- Modify: `./api/api.py` — four queries inside `get_dashboard_leaderboards` (streaks ~line 5647; hugs/kisses/highfives ~lines 5660-5664).

**Interfaces:**
- Consumes: `bot_ph` (placeholder string) and `excluded_params` (tuple) already computed earlier in the same function; `ilim` (interaction limit).

- [ ] **Step 1: Filter the Streaks board**

In `./api/api.py`, replace the streaks query (currently line 5647):

```python
                await cur.execute("SELECT user_name, streak_value, highest_streak, total_streams_watched FROM analytic_stream_watch_streak ORDER BY highest_streak DESC LIMIT %s", (limit,))
```

with (note the column here is `user_name`, not `username`):

```python
                await cur.execute(
                    f"SELECT user_name, streak_value, highest_streak, total_streams_watched FROM analytic_stream_watch_streak "
                    f"WHERE LOWER(user_name) NOT IN ({bot_ph}) ORDER BY highest_streak DESC LIMIT %s",
                    excluded_params + (limit,)
                )
```

- [ ] **Step 2: Filter the three interaction boards**

In `./api/api.py`, replace the three interaction queries (currently lines 5660, 5662, 5664) with their filtered forms:

```python
                await cur.execute(
                    f"SELECT username, hug_count AS c FROM hug_counts "
                    f"WHERE LOWER(username) NOT IN ({bot_ph}) ORDER BY hug_count DESC LIMIT %s",
                    excluded_params + (ilim,)
                )
                interactions["hugs"] = [{"username": r["username"], "count": int(r["c"] or 0)} for r in await cur.fetchall()]
                await cur.execute(
                    f"SELECT username, kiss_count AS c FROM kiss_counts "
                    f"WHERE LOWER(username) NOT IN ({bot_ph}) ORDER BY kiss_count DESC LIMIT %s",
                    excluded_params + (ilim,)
                )
                interactions["kisses"] = [{"username": r["username"], "count": int(r["c"] or 0)} for r in await cur.fetchall()]
                await cur.execute(
                    f"SELECT username, highfive_count AS c FROM highfive_counts "
                    f"WHERE LOWER(username) NOT IN ({bot_ph}) ORDER BY highfive_count DESC LIMIT %s",
                    excluded_params + (ilim,)
                )
                interactions["highfives"] = [{"username": r["username"], "count": int(r["c"] or 0)} for r in await cur.fetchall()]
```

- [ ] **Step 3: Verify Python compiles**

Run:
```bash
python -m py_compile api/api.py
```
Expected: exits 0, no output.

Sanity-check that all five person boards now carry the filter:
```bash
grep -n "NOT IN ({bot_ph})" api/api.py
```
Expected: **5 matches** (watch_time, message_counts, analytic_stream_watch_streak, hug_counts, kiss_counts, highfive_counts → note watch+chat were pre-existing, plus the 4 added here = 6 total `NOT IN` lines; confirm streaks + 3 interactions are present).

---

### Task 3: Dashboard admin page — `known_bots.php`

Create the admin CRUD page modelled on `dashboard/admin/api_keys.php`: add / enable-disable / delete bot logins, audit-logged, rendered with the existing `sp-*` component classes.

**Files:**
- Create: `./dashboard/admin/known_bots.php`

**Interfaces:**
- Consumes: `$conn` (global mysqli from `db_connect.php`), `t()` (i18n — keys added in Task 4; until then `t()` returns the key string, which is harmless), `admin_audit_log($action, $status, $details, $targetType, $targetValue)` (from `admin_access.php`), `known_bots` table (from Task 1).
- Produces: the page itself; POST actions `add_bot`, `toggle_bot`, `delete_bot`.

- [ ] **Step 1: Create the page**

Create `./dashboard/admin/known_bots.php` with exactly this content:

```php
<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_known_bots_page_title');
require_once "/var/www/config/db_connect.php";
include "../includes/userdata.php";
session_write_close();

// Defensive: ensure the table exists even if the API hasn't created it yet.
$conn->query("CREATE TABLE IF NOT EXISTS known_bots (
    id INT NOT NULL AUTO_INCREMENT,
    bot_login VARCHAR(50) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    added_by VARCHAR(50) DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_bot_login (bot_login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function kb_json($success, $message, $extra = []) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

// Add a bot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bot'])) {
    $login = strtolower(ltrim(trim($_POST['bot_login'] ?? ''), '@'));
    $notes = trim($_POST['notes'] ?? '');
    if ($notes === '') { $notes = null; }
    if ($login === '') {
        kb_json(false, t('admin_known_bots_error_login_empty'));
    }
    if (!preg_match('/^[a-z0-9_]{1,25}$/', $login)) {
        kb_json(false, t('admin_known_bots_error_login_invalid'));
    }
    try {
        $stmt = $conn->prepare("INSERT INTO known_bots (bot_login, added_by, notes) VALUES (?, ?, ?)");
        $addedBy = isset($username) ? $username : 'admin';
        $stmt->bind_param("sss", $login, $addedBy, $notes);
        if ($stmt->execute()) {
            $newId = $stmt->insert_id;
            $stmt->close();
            admin_audit_log('known_bot_add', 'success', ['login' => $login, 'notes' => $notes], 'known_bot', $login);
            kb_json(true, t('admin_known_bots_msg_added'), ['id' => $newId, 'login' => $login, 'added_by' => $addedBy]);
        } else {
            $err = $stmt->errno;
            $stmt->close();
            if ($err === 1062) { // duplicate key
                kb_json(false, t('admin_known_bots_error_duplicate'));
            }
            kb_json(false, t('admin_known_bots_error_generic', [(string) $err]));
        }
    } catch (Exception $e) {
        kb_json(false, t('admin_known_bots_error_generic', [$e->getMessage()]));
    }
}

// Toggle active state
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_bot'])) {
    $id = intval($_POST['id'] ?? 0);
    $active = (intval($_POST['is_active'] ?? 0) === 1) ? 1 : 0;
    if ($id <= 0) {
        kb_json(false, t('admin_known_bots_error_not_found'));
    }
    try {
        $stmt = $conn->prepare("UPDATE known_bots SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $active, $id);
        $ok = $stmt->execute() && $stmt->affected_rows >= 0;
        $stmt->close();
        if ($ok) {
            admin_audit_log('known_bot_toggle', 'success', ['id' => $id, 'is_active' => $active], 'known_bot', (string) $id);
            kb_json(true, t('admin_known_bots_msg_toggled'), ['id' => $id, 'is_active' => $active]);
        }
        kb_json(false, t('admin_known_bots_error_not_found'));
    } catch (Exception $e) {
        kb_json(false, t('admin_known_bots_error_generic', [$e->getMessage()]));
    }
}

// Delete a bot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_bot'])) {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        kb_json(false, t('admin_known_bots_error_not_found'));
    }
    try {
        $stmt = $conn->prepare("DELETE FROM known_bots WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            admin_audit_log('known_bot_delete', 'success', ['id' => $id], 'known_bot', (string) $id);
            kb_json(true, t('admin_known_bots_msg_deleted'), ['id' => $id]);
        }
        $stmt->close();
        kb_json(false, t('admin_known_bots_error_not_found'));
    } catch (Exception $e) {
        kb_json(false, t('admin_known_bots_error_generic', [$e->getMessage()]));
    }
}

// Fetch all known bots for display
$known_bots = [];
if ($conn) {
    $result = $conn->query("SELECT id, bot_login, is_active, added_by, created_at FROM known_bots ORDER BY bot_login");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $known_bots[] = $row;
        }
        $result->free();
    }
}

ob_end_clean();
ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <h1 class="sp-card-title"><i class="fas fa-robot"></i> <?php echo t('admin_known_bots_page_title'); ?></h1>
    </div>
    <div class="sp-card-body">
        <p style="color:var(--text-secondary);"><?php echo t('admin_known_bots_intro'); ?></p>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><?php echo t('admin_known_bots_add_heading'); ?></h2>
    </div>
    <div class="sp-card-body">
    <form id="addBotForm">
        <div class="sp-form-group">
            <label class="sp-label"><?php echo t('admin_known_bots_login_label'); ?></label>
            <input class="sp-input" type="text" name="bot_login" placeholder="<?php echo htmlspecialchars(t('admin_known_bots_login_placeholder')); ?>" required>
            <p class="sp-help"><?php echo t('admin_known_bots_login_help'); ?></p>
        </div>
        <div class="sp-form-group">
            <label class="sp-label"><?php echo t('admin_known_bots_notes_label'); ?></label>
            <input class="sp-input" type="text" name="notes" placeholder="<?php echo htmlspecialchars(t('admin_known_bots_notes_placeholder')); ?>">
        </div>
        <div class="sp-form-group">
            <button type="submit" class="sp-btn sp-btn-primary">
                <span class="icon"><i class="fas fa-plus"></i></span>
                <span><?php echo t('admin_known_bots_add_button'); ?></span>
            </button>
        </div>
    </form>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><?php echo t('admin_known_bots_existing_heading'); ?></h2>
    </div>
    <div class="sp-card-body">
    <?php if (empty($known_bots)): ?>
        <div class="sp-alert sp-alert-info" id="knownBotsEmpty"><?php echo t('admin_known_bots_empty_state'); ?></div>
    <?php endif; ?>
        <div class="sp-table-wrap"<?php if (empty($known_bots)) echo ' style="display:none;"'; ?> id="knownBotsWrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th><?php echo t('admin_known_bots_th_login'); ?></th>
                        <th><?php echo t('admin_known_bots_th_status'); ?></th>
                        <th><?php echo t('admin_known_bots_th_added_by'); ?></th>
                        <th><?php echo t('admin_known_bots_th_actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="knownBotsTable">
                    <?php foreach ($known_bots as $b): ?>
                        <tr data-id="<?php echo (int) $b['id']; ?>" data-login="<?php echo htmlspecialchars($b['bot_login']); ?>">
                            <td><strong><?php echo htmlspecialchars($b['bot_login']); ?></strong></td>
                            <td>
                                <span class="kb-status <?php echo $b['is_active'] ? 'kb-status-active' : 'kb-status-disabled'; ?>">
                                    <?php echo $b['is_active'] ? t('admin_known_bots_status_active') : t('admin_known_bots_status_disabled'); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($b['added_by'] ?? ''); ?></td>
                            <td>
                                <div class="sp-btn-group">
                                    <button class="sp-btn sp-btn-warning sp-btn-sm toggle-bot" data-id="<?php echo (int) $b['id']; ?>" data-active="<?php echo (int) $b['is_active']; ?>">
                                        <span class="icon"><i class="fas <?php echo $b['is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i></span>
                                        <span><?php echo $b['is_active'] ? t('admin_known_bots_disable_button') : t('admin_known_bots_enable_button'); ?></span>
                                    </button>
                                    <button class="sp-btn sp-btn-danger sp-btn-sm delete-bot" data-id="<?php echo (int) $b['id']; ?>">
                                        <span class="icon"><i class="fas fa-trash"></i></span>
                                        <span><?php echo t('admin_known_bots_delete_button'); ?></span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const I18N = {
        errorTitle: <?php echo json_encode(t('admin_known_bots_js_error_title')); ?>,
        deleteConfirmTitle: <?php echo json_encode(t('admin_known_bots_js_delete_confirm_title')); ?>,
        deleteConfirmText: <?php echo json_encode(t('admin_known_bots_js_delete_confirm_text')); ?>,
        deleteConfirmBtn: <?php echo json_encode(t('admin_known_bots_js_delete_confirm_btn')); ?>,
        cancelBtn: <?php echo json_encode(t('admin_known_bots_js_cancel_btn')); ?>,
        deletedTitle: <?php echo json_encode(t('admin_known_bots_js_deleted_title')); ?>,
        addError: <?php echo json_encode(t('admin_known_bots_js_add_error')); ?>,
        deleteError: <?php echo json_encode(t('admin_known_bots_js_delete_error')); ?>,
        toggleError: <?php echo json_encode(t('admin_known_bots_js_toggle_error')); ?>,
        statusActive: <?php echo json_encode(t('admin_known_bots_status_active')); ?>,
        statusDisabled: <?php echo json_encode(t('admin_known_bots_status_disabled')); ?>,
        enableBtn: <?php echo json_encode(t('admin_known_bots_enable_button')); ?>,
        disableBtn: <?php echo json_encode(t('admin_known_bots_disable_button')); ?>,
        thLogin: <?php echo json_encode(t('admin_known_bots_th_login')); ?>,
        addedByYou: <?php echo json_encode($username ?? 'admin'); ?>
    };
    function esc(text) { const d = document.createElement('div'); d.textContent = text == null ? '' : text; return d.innerHTML; }
    const tableBody = document.getElementById('knownBotsTable');
    const wrap = document.getElementById('knownBotsWrap');
    const empty = document.getElementById('knownBotsEmpty');

    function showTable() {
        if (wrap) wrap.style.display = '';
        if (empty) empty.style.display = 'none';
    }

    function buildRow(id, login, addedBy) {
        const tr = document.createElement('tr');
        tr.setAttribute('data-id', id);
        tr.setAttribute('data-login', login);
        tr.innerHTML =
            '<td><strong>' + esc(login) + '</strong></td>' +
            '<td><span class="kb-status kb-status-active">' + esc(I18N.statusActive) + '</span></td>' +
            '<td>' + esc(addedBy) + '</td>' +
            '<td><div class="sp-btn-group">' +
                '<button class="sp-btn sp-btn-warning sp-btn-sm toggle-bot" data-id="' + id + '" data-active="1">' +
                    '<span class="icon"><i class="fas fa-toggle-off"></i></span><span>' + esc(I18N.disableBtn) + '</span></button>' +
                '<button class="sp-btn sp-btn-danger sp-btn-sm delete-bot" data-id="' + id + '">' +
                    '<span class="icon"><i class="fas fa-trash"></i></span><span>' + esc(<?php echo json_encode(t('admin_known_bots_delete_button')); ?>) + '</span></button>' +
            '</div></td>';
        attachRow(tr);
        return tr;
    }

    async function handleToggle() {
        const id = this.dataset.id;
        const active = parseInt(this.dataset.active, 10) === 1 ? 0 : 1; // flip
        const fd = new FormData();
        fd.append('toggle_bot', '1');
        fd.append('id', id);
        fd.append('is_active', String(active));
        try {
            const res = await fetch('known_bots.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                const row = tableBody.querySelector('tr[data-id="' + id + '"]');
                if (row) {
                    this.dataset.active = String(active);
                    const badge = row.querySelector('.kb-status');
                    badge.className = 'kb-status ' + (active ? 'kb-status-active' : 'kb-status-disabled');
                    badge.textContent = active ? I18N.statusActive : I18N.statusDisabled;
                    const label = this.querySelector('span:last-child');
                    label.textContent = active ? I18N.disableBtn : I18N.enableBtn;
                    const icon = this.querySelector('i');
                    icon.className = 'fas ' + (active ? 'fa-toggle-off' : 'fa-toggle-on');
                }
            } else {
                Swal.fire({ icon: 'error', title: I18N.errorTitle, text: data.message });
            }
        } catch (e) {
            Swal.fire({ icon: 'error', title: I18N.errorTitle, text: I18N.toggleError });
        }
    }

    async function handleDelete() {
        const id = this.dataset.id;
        const row = tableBody.querySelector('tr[data-id="' + id + '"]');
        const login = row ? row.getAttribute('data-login') : '';
        const confirm = await Swal.fire({
            icon: 'warning',
            title: I18N.deleteConfirmTitle,
            text: I18N.deleteConfirmText.replace('%s', login),
            showCancelButton: true,
            confirmButtonText: I18N.deleteConfirmBtn,
            cancelButtonText: I18N.cancelBtn,
            confirmButtonColor: '#f14668',
            cancelButtonColor: '#3085d6'
        });
        if (!confirm.isConfirmed) return;
        const fd = new FormData();
        fd.append('delete_bot', '1');
        fd.append('id', id);
        try {
            const res = await fetch('known_bots.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                if (row) row.remove();
                Swal.fire({ icon: 'success', title: I18N.deletedTitle, text: data.message, timer: 1500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: I18N.errorTitle, text: data.message });
            }
        } catch (e) {
            Swal.fire({ icon: 'error', title: I18N.errorTitle, text: I18N.deleteError });
        }
    }

    function attachRow(row) {
        const t = row.querySelector('.toggle-bot');
        if (t) t.addEventListener('click', handleToggle);
        const d = row.querySelector('.delete-bot');
        if (d) d.addEventListener('click', handleDelete);
    }

    tableBody && tableBody.querySelectorAll('tr').forEach(attachRow);

    const form = document.getElementById('addBotForm');
    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const fd = new FormData();
        fd.append('add_bot', '1');
        fd.append('bot_login', form.querySelector('[name="bot_login"]').value);
        fd.append('notes', form.querySelector('[name="notes"]').value);
        try {
            const res = await fetch('known_bots.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                showTable();
                tableBody.appendChild(buildRow(data.id, data.login, data.added_by || I18N.addedByYou));
                form.reset();
            } else {
                Swal.fire({ icon: 'error', title: I18N.errorTitle, text: data.message });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: I18N.errorTitle, text: I18N.addError });
        }
    });
});
</script>
<?php
$content = ob_get_clean();
include_once __DIR__ . '/../layout.php';
?>
```

- [ ] **Step 2: Verify the page parses**

Run:
```bash
php -l dashboard/admin/known_bots.php
```
Expected: `No syntax errors detected in dashboard/admin/known_bots.php`.

---

### Task 4: Navigation, i18n, and styling

Register the page in the admin menu, add all i18n keys to the three language files, and add the small status-badge styles to the dashboard stylesheet.

**Files:**
- Modify: `./dashboard/menu.php` — add one entry to the `$admin` array (after `spam_patterns.php`, line 99).
- Modify: `./dashboard/lang/en.php`, `./dashboard/lang/de.php`, `./dashboard/lang/fr.php` — add the key block.
- Modify: `./dashboard/css/dashboard.css` — add `.kb-status` styles.

**Interfaces:**
- Produces: `t('admin_known_bots_*')` keys and `menu_admin_known_bots`, consumed by Task 3's page. The `.kb-status` / `.kb-status-active` / `.kb-status-disabled` classes used by that page's markup.

- [ ] **Step 1: Register the menu item**

In `./dashboard/menu.php`, in the `$admin` array, immediately after the `spam_patterns.php` entry (line 99), add:

```php
        [ 'label' => t('menu_admin_known_bots'), 'icon' => 'fas fa-robot', 'href' => 'known_bots.php' ],
```

- [ ] **Step 2: Add English strings**

In `./dashboard/lang/en.php`, add this block alongside the other `admin_*` keys (e.g. near `menu_admin_spam_patterns`):

```php
    'menu_admin_known_bots' => 'Known Bots',
    'admin_known_bots_page_title' => 'Known Bots',
    'admin_known_bots_intro' => 'Global list of Twitch accounts treated as bots. These accounts are hidden from the dashboard leaderboards (watch time, chat, streaks and interactions) for every channel.',
    'admin_known_bots_add_heading' => 'Add a Bot',
    'admin_known_bots_login_label' => 'Twitch Login',
    'admin_known_bots_login_placeholder' => 'e.g. nightbot',
    'admin_known_bots_login_help' => 'Lowercase Twitch login — letters, numbers and underscores (max 25 characters).',
    'admin_known_bots_notes_label' => 'Notes (optional)',
    'admin_known_bots_notes_placeholder' => 'Why this account is excluded',
    'admin_known_bots_add_button' => 'Add Bot',
    'admin_known_bots_existing_heading' => 'Known Bots',
    'admin_known_bots_empty_state' => 'No bots configured yet.',
    'admin_known_bots_th_login' => 'Login',
    'admin_known_bots_th_status' => 'Status',
    'admin_known_bots_th_added_by' => 'Added By',
    'admin_known_bots_th_actions' => 'Actions',
    'admin_known_bots_status_active' => 'Active',
    'admin_known_bots_status_disabled' => 'Disabled',
    'admin_known_bots_enable_button' => 'Enable',
    'admin_known_bots_disable_button' => 'Disable',
    'admin_known_bots_delete_button' => 'Delete',
    'admin_known_bots_error_login_empty' => 'Please enter a Twitch login.',
    'admin_known_bots_error_login_invalid' => 'Invalid login. Use 1–25 lowercase letters, numbers or underscores.',
    'admin_known_bots_error_duplicate' => 'That login is already in the list.',
    'admin_known_bots_error_not_found' => 'That entry no longer exists.',
    'admin_known_bots_error_generic' => 'Something went wrong: %s',
    'admin_known_bots_msg_added' => 'Bot added.',
    'admin_known_bots_msg_deleted' => 'Bot removed.',
    'admin_known_bots_msg_toggled' => 'Bot updated.',
    'admin_known_bots_js_error_title' => 'Error',
    'admin_known_bots_js_delete_confirm_title' => 'Remove bot?',
    'admin_known_bots_js_delete_confirm_text' => 'Remove "%s" from the known-bots list?',
    'admin_known_bots_js_delete_confirm_btn' => 'Remove',
    'admin_known_bots_js_cancel_btn' => 'Cancel',
    'admin_known_bots_js_deleted_title' => 'Removed',
    'admin_known_bots_js_add_error' => 'Failed to add the bot. Please try again.',
    'admin_known_bots_js_delete_error' => 'Failed to remove the bot. Please try again.',
    'admin_known_bots_js_toggle_error' => 'Failed to update the bot. Please try again.',
```

- [ ] **Step 3: Add German strings**

In `./dashboard/lang/de.php`, add:

```php
    'menu_admin_known_bots' => 'Bekannte Bots',
    'admin_known_bots_page_title' => 'Bekannte Bots',
    'admin_known_bots_intro' => 'Globale Liste von Twitch-Konten, die als Bots behandelt werden. Diese Konten werden in den Dashboard-Bestenlisten (Wiedergabezeit, Chat, Serien und Interaktionen) für jeden Kanal ausgeblendet.',
    'admin_known_bots_add_heading' => 'Bot hinzufügen',
    'admin_known_bots_login_label' => 'Twitch-Login',
    'admin_known_bots_login_placeholder' => 'z. B. nightbot',
    'admin_known_bots_login_help' => 'Twitch-Login in Kleinbuchstaben — Buchstaben, Zahlen und Unterstriche (max. 25 Zeichen).',
    'admin_known_bots_notes_label' => 'Notizen (optional)',
    'admin_known_bots_notes_placeholder' => 'Warum dieses Konto ausgeschlossen ist',
    'admin_known_bots_add_button' => 'Bot hinzufügen',
    'admin_known_bots_existing_heading' => 'Bekannte Bots',
    'admin_known_bots_empty_state' => 'Noch keine Bots konfiguriert.',
    'admin_known_bots_th_login' => 'Login',
    'admin_known_bots_th_status' => 'Status',
    'admin_known_bots_th_added_by' => 'Hinzugefügt von',
    'admin_known_bots_th_actions' => 'Aktionen',
    'admin_known_bots_status_active' => 'Aktiv',
    'admin_known_bots_status_disabled' => 'Deaktiviert',
    'admin_known_bots_enable_button' => 'Aktivieren',
    'admin_known_bots_disable_button' => 'Deaktivieren',
    'admin_known_bots_delete_button' => 'Löschen',
    'admin_known_bots_error_login_empty' => 'Bitte gib einen Twitch-Login ein.',
    'admin_known_bots_error_login_invalid' => 'Ungültiger Login. Verwende 1–25 Kleinbuchstaben, Zahlen oder Unterstriche.',
    'admin_known_bots_error_duplicate' => 'Dieser Login ist bereits in der Liste.',
    'admin_known_bots_error_not_found' => 'Dieser Eintrag existiert nicht mehr.',
    'admin_known_bots_error_generic' => 'Etwas ist schiefgelaufen: %s',
    'admin_known_bots_msg_added' => 'Bot hinzugefügt.',
    'admin_known_bots_msg_deleted' => 'Bot entfernt.',
    'admin_known_bots_msg_toggled' => 'Bot aktualisiert.',
    'admin_known_bots_js_error_title' => 'Fehler',
    'admin_known_bots_js_delete_confirm_title' => 'Bot entfernen?',
    'admin_known_bots_js_delete_confirm_text' => '"%s" aus der Bot-Liste entfernen?',
    'admin_known_bots_js_delete_confirm_btn' => 'Entfernen',
    'admin_known_bots_js_cancel_btn' => 'Abbrechen',
    'admin_known_bots_js_deleted_title' => 'Entfernt',
    'admin_known_bots_js_add_error' => 'Bot konnte nicht hinzugefügt werden. Bitte versuche es erneut.',
    'admin_known_bots_js_delete_error' => 'Bot konnte nicht entfernt werden. Bitte versuche es erneut.',
    'admin_known_bots_js_toggle_error' => 'Bot konnte nicht aktualisiert werden. Bitte versuche es erneut.',
```

- [ ] **Step 4: Add French strings**

In `./dashboard/lang/fr.php`, add this block (note the escaped apostrophes `\'` required inside single-quoted PHP strings):

```php
    'menu_admin_known_bots' => 'Bots connus',
    'admin_known_bots_page_title' => 'Bots connus',
    'admin_known_bots_intro' => 'Liste globale des comptes Twitch considérés comme des bots. Ces comptes sont masqués des classements du tableau de bord (temps de visionnage, chat, séries et interactions) pour chaque chaîne.',
    'admin_known_bots_add_heading' => 'Ajouter un bot',
    'admin_known_bots_login_label' => 'Identifiant Twitch',
    'admin_known_bots_login_placeholder' => 'ex. nightbot',
    'admin_known_bots_login_help' => 'Identifiant Twitch en minuscules — lettres, chiffres et traits de soulignement (25 caractères max).',
    'admin_known_bots_notes_label' => 'Notes (facultatif)',
    'admin_known_bots_notes_placeholder' => 'Pourquoi ce compte est exclu',
    'admin_known_bots_add_button' => 'Ajouter le bot',
    'admin_known_bots_existing_heading' => 'Bots connus',
    'admin_known_bots_empty_state' => 'Aucun bot configuré pour le moment.',
    'admin_known_bots_th_login' => 'Identifiant',
    'admin_known_bots_th_status' => 'Statut',
    'admin_known_bots_th_added_by' => 'Ajouté par',
    'admin_known_bots_th_actions' => 'Actions',
    'admin_known_bots_status_active' => 'Actif',
    'admin_known_bots_status_disabled' => 'Désactivé',
    'admin_known_bots_enable_button' => 'Activer',
    'admin_known_bots_disable_button' => 'Désactiver',
    'admin_known_bots_delete_button' => 'Supprimer',
    'admin_known_bots_error_login_empty' => 'Veuillez saisir un identifiant Twitch.',
    'admin_known_bots_error_login_invalid' => 'Identifiant invalide. Utilisez 1 à 25 minuscules, chiffres ou traits de soulignement.',
    'admin_known_bots_error_duplicate' => 'Cet identifiant est déjà dans la liste.',
    'admin_known_bots_error_not_found' => 'Cette entrée n\'existe plus.',
    'admin_known_bots_error_generic' => 'Une erreur s\'est produite : %s',
    'admin_known_bots_msg_added' => 'Bot ajouté.',
    'admin_known_bots_msg_deleted' => 'Bot supprimé.',
    'admin_known_bots_msg_toggled' => 'Bot mis à jour.',
    'admin_known_bots_js_error_title' => 'Erreur',
    'admin_known_bots_js_delete_confirm_title' => 'Supprimer le bot ?',
    'admin_known_bots_js_delete_confirm_text' => 'Supprimer « %s » de la liste des bots connus ?',
    'admin_known_bots_js_delete_confirm_btn' => 'Supprimer',
    'admin_known_bots_js_cancel_btn' => 'Annuler',
    'admin_known_bots_js_deleted_title' => 'Supprimé',
    'admin_known_bots_js_add_error' => 'Échec de l\'ajout du bot. Veuillez réessayer.',
    'admin_known_bots_js_delete_error' => 'Échec de la suppression du bot. Veuillez réessayer.',
    'admin_known_bots_js_toggle_error' => 'Échec de la mise à jour du bot. Veuillez réessayer.',
```

- [ ] **Step 5: Add the status-badge styles**

In `./dashboard/css/dashboard.css`, append (using existing theme tokens, no hardcoded palette):

```css
/* Known-bots admin status pill */
.kb-status {
    display: inline-block;
    padding: 0.15rem 0.6rem;
    border-radius: var(--radius-pill);
    font-size: 0.8rem;
    font-weight: 600;
}
.kb-status-active {
    background: color-mix(in srgb, var(--green) 18%, transparent);
    color: var(--green);
}
.kb-status-disabled {
    background: color-mix(in srgb, var(--text-muted) 18%, transparent);
    color: var(--text-muted);
}
```

> If `color-mix` or the `--green` / `--text-muted` / `--radius-pill` tokens are not present in `dashboard.css`, fall back to the nearest existing tokens used by sibling badge/alert components (grep `dashboard.css` for `--radius-pill` and `--green` to confirm before editing).

- [ ] **Step 6: Verify all edited files parse**

Run:
```bash
php -l dashboard/menu.php
php -l dashboard/lang/en.php
php -l dashboard/lang/de.php
php -l dashboard/lang/fr.php
```
Expected: `No syntax errors detected` for each. (The French file is the one most likely to fail if an apostrophe wasn't escaped.)

---

## Functional verification (after all tasks, on a deploy/staging box)

These require a running API + dashboard + DB and cannot be done from the dev box alone:

1. Restart the API. Confirm `known_bots` exists and is seeded: `SELECT COUNT(*) FROM website.known_bots;` ≈ the seed-set size (~41).
2. Open `dashboard/admin/known_bots.php` as an admin — the seeded list renders; a non-admin is denied by `admin_access.php`.
3. Add a test login (e.g. `mytestbot`), then check `/dashboard/leaderboards` no longer lists it on any board within ≤5 min (cache TTL). Disable it → it returns; delete it → gone.
4. Confirm a per-channel `watch_time_excluded_users` addition still takes effect (union preserved).
5. Confirm audit rows are written: `SELECT action, target_value FROM website.admin_audit_log WHERE action LIKE 'known_bot_%' ORDER BY id DESC LIMIT 5;`

## Phase 2 (separate plan — out of scope for this phase)

Wire `beta.py` + `beta-v6.py` to read `website.known_bots` (cached) and union it into watch-time tracking, points, and welcome/shoutout. Stable `bot.py` excluded by policy. Requires a bot deploy.
