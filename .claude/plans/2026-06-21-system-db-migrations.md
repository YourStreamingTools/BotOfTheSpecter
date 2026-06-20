# System-DB Migrations — Implementation Plan (Phase 3, v1)

**Goal:** Build a reviewable, versioned migration system for the shared/system databases — a runner library + admin page (`migrations.php`) — bring the `website` DB under it, and remove the scattered defensive `CREATE TABLE` code.

**Architecture:** Ordered migration files per DB (`migrations/{db}/*.php` returning `up`/`down` SQL or a procedural callable); a per-DB `schema_migrations` ledger; a runner library (`migration_runner.php`) that scans/applies/rolls-back/adopts on explicit admin action only; an `is_admin`-gated `migrations.php` page to review + apply. v1 captures the `website` baseline + the `users.is_deceased` migration and retires the website defensive CREATEs.

**Tech Stack:** PHP 8 / mysqli (runner, page, migrations), Python/FastAPI (the api.py removals). No new libraries.

**Spec:** `.claude/specs/2026-06-20-system-db-migrations.md`

## Global Constraints

- **No test framework.** Verify PHP with `php -l`, Python with `python -m py_compile`, plus the described functional checks. Do NOT add pytest/PHPUnit.
- **PHP never reads `.env`** — config via `./config/*.php` (server: `/var/www/config/`). The runner reads creds from `config/database.php` (`$db_servername` / `$db_username` / `$db_password`).
- **DB:** ledger reads/writes are parameterized (prepared statements). Migration DDL is trusted committed code. Managed db names validated against `^[a-z0-9_]{1,64}$`.
- **Admin gate `is_admin`** (via `admin_access.php`); every apply/rollback/adopt is `admin_audit_log`-ed.
- **Scope = system DBs only** (`website`, `specterdiscordbot`, `roadmap`, `spam_pattern`). Per-user DBs stay with `usr_database.php` — do NOT touch it.
- **Two bootstrap tables stay ensured-on-demand:** `schema_migrations` (created by the runner) and `admin_audit_log` (stays in `admin_access.php`). Everything else becomes a migration.
- **Removing defensive CREATEs (Task 4) requires the website baseline to be adopted in every environment at deploy** — Task 4 is last and carries that warning.
- Repo paths use `./`; server runtime config is `/var/www/config/` (the runner handles both).

---

### Task 1: Migration registry + runner library

**Files:**
- Create: `./config/migrations.php`
- Create: `./dashboard/includes/migration_runner.php`

**Interfaces:**
- Produces (consumed by Task 2 page and Task 3 migrations):
  - `migration_registry(): array`
  - `migration_status(string $db): array` → `['applied'=>[], 'pending'=>[], 'missing'=>[]]`; each migration has `id, description, preview, up, down, procedural, destructive, checksum` and (if applied) `applied_at, applied_by, drift`.
  - `migration_apply(string $db, string $id, bool $confirmDestructive, ?string $appliedBy): void`
  - `migration_rollback(string $db, string $id, ?string $appliedBy): void`
  - `migration_adopt_baseline(string $db, ?string $appliedBy): void`
  - Procedural helpers for migration files: `migration_table_exists($conn,$t)`, `migration_column_exists($conn,$t,$c)`, `migration_index_exists($conn,$t,$i)`.

- [ ] **Step 1: Create the managed-DB registry**

Create `./config/migrations.php`:

```php
<?php
// Managed system/shared databases (NOT per-user — those stay in usr_database.php).
// Add a key + a ./migrations/{key}/ folder to bring a new system DB under migrations.
return [
    'website'      => ['label' => 'Website'],
    'specterdiscordbot'   => ['label' => 'Discord Bot'],
    'roadmap'      => ['label' => 'Roadmap'],
    'spam_pattern' => ['label' => 'Spam Patterns'],
];
```

- [ ] **Step 2: Create the runner library**

Create `./dashboard/includes/migration_runner.php` with exactly this content:

```php
<?php
// System-DB migration runner. Pure logic — nothing executes on include.
// Migrations live OUTSIDE the public web root, in /var/www/migrations/{db}/ on the
// server (repo: ./migrations/{db}/{YYYYMMDD}_{NNNN}_{slug}.php), and
// return ['description'=>..., 'up'=>[sql,...]|callable, 'down'=>[sql,...]|callable, 'preview'=>..., 'destructive'=>bool].
// Ledger is a per-DB schema_migrations table.

function migration_registry() {
    $path = file_exists('/var/www/config/migrations.php')
        ? '/var/www/config/migrations.php'
        : __DIR__ . '/../../config/migrations.php';
    return include $path;
}

function migration_connect($db) {
    $registry = migration_registry();
    if (!isset($registry[$db]) || !preg_match('/^[a-z0-9_]{1,64}$/', $db)) {
        throw new Exception("Unknown or invalid managed database: " . $db);
    }
    $cfg = file_exists('/var/www/config/database.php')
        ? '/var/www/config/database.php'
        : __DIR__ . '/../../config/database.php';
    require $cfg; // sets $db_servername, $db_username, $db_password
    $conn = new mysqli($db_servername, $db_username, $db_password, $db);
    if ($conn->connect_error) {
        throw new Exception("Connection to '" . $db . "' failed: " . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function migration_ensure_ledger(mysqli $conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT NOT NULL AUTO_INCREMENT,
        migration_id VARCHAR(191) NOT NULL,
        name VARCHAR(255) NULL,
        checksum CHAR(32) NOT NULL,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        applied_by VARCHAR(50) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_migration (migration_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function migration_is_destructive($up) {
    if (is_callable($up)) return false;
    foreach ((array) $up as $sql) {
        if (preg_match('/\b(DROP|TRUNCATE|DELETE)\b/i', (string) $sql)) return true;
    }
    return false;
}

function migration_dir($db) {
    // Migrations live OUTSIDE the public web root (dashboard/). On the server that is
    // /var/www/migrations; in dev it is the repo-root ./migrations (a sibling of dashboard/).
    $base = is_dir('/var/www/migrations') ? '/var/www/migrations' : __DIR__ . '/../../migrations';
    return $base . '/' . $db;
}

function migration_scan($db) {
    $dir = migration_dir($db);
    $out = [];
    if (!is_dir($dir)) return $out;
    $files = glob($dir . '/*.php');
    sort($files); // filename order = apply order
    foreach ($files as $f) {
        $def = include $f;
        if (!is_array($def)) continue;
        $up = $def['up'] ?? [];
        $down = $def['down'] ?? [];
        $out[] = [
            'id'          => basename($f, '.php'),
            'file'        => $f,
            'description' => $def['description'] ?? '',
            'preview'     => $def['preview'] ?? '',
            'up'          => $up,
            'down'        => $down,
            'procedural'  => is_callable($up),
            'destructive' => $def['destructive'] ?? migration_is_destructive($up),
            'checksum'    => md5_file($f),
        ];
    }
    return $out;
}

function migration_status($db) {
    $conn = migration_connect($db);
    migration_ensure_ledger($conn);
    $applied = [];
    if ($res = $conn->query("SELECT migration_id, name, checksum, applied_at, applied_by FROM schema_migrations")) {
        while ($row = $res->fetch_assoc()) $applied[$row['migration_id']] = $row;
        $res->free();
    }
    $conn->close();
    $scan = migration_scan($db);
    $seen = [];
    $appliedList = []; $pending = [];
    foreach ($scan as $m) {
        $seen[$m['id']] = true;
        if (isset($applied[$m['id']])) {
            $row = $applied[$m['id']];
            $m['applied_at'] = $row['applied_at'];
            $m['applied_by'] = $row['applied_by'];
            $m['drift'] = ($row['checksum'] !== $m['checksum']);
            $appliedList[] = $m;
        } else {
            $pending[] = $m;
        }
    }
    $missing = [];
    foreach ($applied as $id => $row) if (!isset($seen[$id])) $missing[] = $row;
    return ['applied' => $appliedList, 'pending' => $pending, 'missing' => $missing];
}

function migration_find($db, $id) {
    foreach (migration_scan($db) as $m) if ($m['id'] === $id) return $m;
    return null;
}

function migration_apply($db, $id, $confirmDestructive, $appliedBy) {
    $target = migration_find($db, $id);
    if (!$target) throw new Exception("Migration not found: " . $id);
    if ($target['destructive'] && !$confirmDestructive) {
        throw new Exception("Destructive migration requires confirmation");
    }
    $conn = migration_connect($db);
    migration_ensure_ledger($conn);
    $stmt = $conn->prepare("SELECT 1 FROM schema_migrations WHERE migration_id = ?");
    $stmt->bind_param('s', $id); $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0; $stmt->close();
    if ($exists) { $conn->close(); throw new Exception("Already applied: " . $id); }
    try {
        if (is_callable($target['up'])) {
            ($target['up'])($conn);
        } else {
            foreach ((array) $target['up'] as $sql) {
                if (trim((string) $sql) === '') continue;
                if (!$conn->query($sql)) throw new Exception($conn->error);
            }
        }
    } catch (Throwable $e) {
        $conn->close();
        throw new Exception("Apply failed for " . $id . ": " . $e->getMessage());
    }
    $ins = $conn->prepare("INSERT INTO schema_migrations (migration_id, name, checksum, applied_by) VALUES (?, ?, ?, ?)");
    $name = $target['description']; $sum = $target['checksum'];
    $ins->bind_param('ssss', $id, $name, $sum, $appliedBy);
    $ins->execute(); $ins->close();
    $conn->close();
}

function migration_rollback($db, $id, $appliedBy) {
    $status = migration_status($db);
    if (empty($status['applied'])) throw new Exception("Nothing to roll back");
    $last = end($status['applied']);
    if ($last['id'] !== $id) throw new Exception("Only the most recently applied migration can be rolled back");
    $conn = migration_connect($db);
    try {
        if (is_callable($last['down'])) {
            ($last['down'])($conn);
        } else {
            foreach ((array) $last['down'] as $sql) {
                if (trim((string) $sql) === '') continue;
                if (!$conn->query($sql)) throw new Exception($conn->error);
            }
        }
    } catch (Throwable $e) {
        $conn->close();
        throw new Exception("Rollback failed for " . $id . ": " . $e->getMessage());
    }
    $del = $conn->prepare("DELETE FROM schema_migrations WHERE migration_id = ?");
    $del->bind_param('s', $id); $del->execute(); $del->close();
    $conn->close();
}

function migration_adopt_baseline($db, $appliedBy) {
    // Record the first (lowest-ordered) migration as applied WITHOUT running it,
    // for an existing environment whose tables already exist.
    $scan = migration_scan($db);
    if (empty($scan)) throw new Exception("No migrations to adopt for " . $db);
    $baseline = $scan[0];
    $conn = migration_connect($db);
    migration_ensure_ledger($conn);
    $stmt = $conn->prepare("SELECT 1 FROM schema_migrations WHERE migration_id = ?");
    $stmt->bind_param('s', $baseline['id']); $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0; $stmt->close();
    if ($exists) { $conn->close(); throw new Exception("Baseline already recorded for " . $db); }
    $ins = $conn->prepare("INSERT INTO schema_migrations (migration_id, name, checksum, applied_by) VALUES (?, ?, ?, ?)");
    $name = $baseline['description'] . ' (adopted)'; $sum = $baseline['checksum'];
    $ins->bind_param('ssss', $baseline['id'], $name, $sum, $appliedBy);
    $ins->execute(); $ins->close();
    $conn->close();
}

// ---- Helpers available to procedural migrations (callable up/down) ----
function migration_table_exists(mysqli $conn, $table) {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table); $stmt->execute();
    $r = $stmt->get_result()->num_rows > 0; $stmt->close(); return $r;
}
function migration_column_exists(mysqli $conn, $table, $col) {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $col); $stmt->execute();
    $r = $stmt->get_result()->num_rows > 0; $stmt->close(); return $r;
}
function migration_index_exists(mysqli $conn, $table, $index) {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->bind_param('ss', $table, $index); $stmt->execute();
    $r = $stmt->get_result()->num_rows > 0; $stmt->close(); return $r;
}
```

- [ ] **Step 3: Verify both files parse**

```bash
php -l config/migrations.php
php -l dashboard/includes/migration_runner.php
```
Expected: `No syntax errors detected` for each.

---

### Task 2: Admin page `migrations.php` + menu + i18n + CSS

**Files:**
- Create: `./dashboard/admin/migrations.php`
- Modify: `./dashboard/menu.php` (add `$admin` entry after `spam_patterns.php`)
- Modify: `./dashboard/lang/en.php`, `de.php`, `fr.php` (i18n keys)
- Modify: `./dashboard/css/dashboard.css` (page styles)

**Interfaces:**
- Consumes: Task 1's `migration_status` / `migration_apply` / `migration_rollback` / `migration_adopt_baseline` / `migration_registry`; `admin_access.php` gate + `admin_audit_log()`; `t()`; `$username` (from userdata.php).

- [ ] **Step 1: Create the page**

Create `./dashboard/admin/migrations.php`:

```php
<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = t('admin_migrations_page_title');
require_once "/var/www/config/db_connect.php";
include "../includes/userdata.php";
require_once __DIR__ . '/../includes/migration_runner.php';
session_write_close();

function mig_json($success, $message, $extra = []) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

$registry = migration_registry();

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $db = $_POST['db'] ?? '';
    $appliedBy = isset($username) ? $username : 'admin';
    if (!isset($registry[$db])) mig_json(false, t('admin_migrations_err_unknown_db'));
    try {
        if ($action === 'apply') {
            $id = $_POST['id'] ?? '';
            $confirm = !empty($_POST['confirm_destructive']);
            migration_apply($db, $id, $confirm, $appliedBy);
            admin_audit_log('migration_apply', 'success', ['db' => $db, 'id' => $id], 'migration', $db . '/' . $id);
            mig_json(true, t('admin_migrations_msg_applied'));
        } elseif ($action === 'rollback') {
            $id = $_POST['id'] ?? '';
            migration_rollback($db, $id, $appliedBy);
            admin_audit_log('migration_rollback', 'success', ['db' => $db, 'id' => $id], 'migration', $db . '/' . $id);
            mig_json(true, t('admin_migrations_msg_rolled_back'));
        } elseif ($action === 'adopt_baseline') {
            migration_adopt_baseline($db, $appliedBy);
            admin_audit_log('migration_adopt_baseline', 'success', ['db' => $db], 'migration', $db);
            mig_json(true, t('admin_migrations_msg_adopted'));
        } elseif ($action === 'apply_all') {
            $status = migration_status($db);
            $applied = [];
            foreach ($status['pending'] as $m) {
                if ($m['destructive']) continue; // skip destructive in bulk apply
                migration_apply($db, $m['id'], false, $appliedBy);
                admin_audit_log('migration_apply', 'success', ['db' => $db, 'id' => $m['id'], 'bulk' => true], 'migration', $db . '/' . $m['id']);
                $applied[] = $m['id'];
            }
            mig_json(true, t('admin_migrations_msg_applied_all', [count($applied)]), ['applied' => $applied]);
        } else {
            mig_json(false, t('admin_migrations_err_unknown_action'));
        }
    } catch (Throwable $e) {
        mig_json(false, $e->getMessage());
    }
}

// Build view model (catch per-DB connection errors so one bad DB doesn't break the page)
$view = [];
foreach ($registry as $db => $meta) {
    try {
        $view[$db] = ['label' => $meta['label'], 'status' => migration_status($db), 'error' => null];
    } catch (Throwable $e) {
        $view[$db] = ['label' => $meta['label'], 'status' => null, 'error' => $e->getMessage()];
    }
}

ob_end_clean();
ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header"><h1 class="sp-card-title"><i class="fas fa-database"></i> <?php echo t('admin_migrations_page_title'); ?></h1></div>
    <div class="sp-card-body"><p style="color:var(--text-secondary);"><?php echo t('admin_migrations_intro'); ?></p></div>
</div>
<?php foreach ($view as $db => $info): ?>
<div class="sp-card" data-db="<?php echo htmlspecialchars($db); ?>">
    <div class="sp-card-header">
        <h2 class="sp-card-title"><?php echo htmlspecialchars($info['label']); ?> <span class="mig-dbname">(<?php echo htmlspecialchars($db); ?>)</span></h2>
    </div>
    <div class="sp-card-body">
    <?php if ($info['error']): ?>
        <div class="sp-alert sp-alert-danger"><?php echo htmlspecialchars($info['error']); ?></div>
    <?php else: $s = $info['status']; ?>
        <p class="mig-counts">
            <?php echo t('admin_migrations_counts', [count($s['applied']), count($s['pending'])]); ?>
            <?php if (!empty($s['missing'])): ?><span class="mig-warn"><?php echo t('admin_migrations_missing_warn', [count($s['missing'])]); ?></span><?php endif; ?>
        </p>
        <?php
        $hasBaselineApplied = false; foreach ($s['applied'] as $am) { $hasBaselineApplied = true; break; }
        if (!$hasBaselineApplied && !empty($s['pending'])): ?>
            <button class="sp-btn sp-btn-info mig-adopt" data-db="<?php echo htmlspecialchars($db); ?>"><i class="fas fa-check-double"></i> <?php echo t('admin_migrations_adopt_baseline'); ?></button>
        <?php endif; ?>
        <?php if (!empty($s['pending'])): ?>
            <button class="sp-btn sp-btn-primary mig-apply-all" data-db="<?php echo htmlspecialchars($db); ?>"><i class="fas fa-forward"></i> <?php echo t('admin_migrations_apply_all'); ?></button>
        <?php endif; ?>
        <div class="sp-table-wrap">
            <table class="sp-table">
                <thead><tr>
                    <th><?php echo t('admin_migrations_th_status'); ?></th>
                    <th><?php echo t('admin_migrations_th_id'); ?></th>
                    <th><?php echo t('admin_migrations_th_desc'); ?></th>
                    <th><?php echo t('admin_migrations_th_actions'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($s['applied'] as $i => $m): $isLast = ($i === count($s['applied']) - 1); ?>
                    <tr>
                        <td><span class="mig-pill mig-applied"><?php echo t('admin_migrations_status_applied'); ?></span><?php if (!empty($m['drift'])): ?> <span class="mig-pill mig-drift"><?php echo t('admin_migrations_status_drift'); ?></span><?php endif; ?></td>
                        <td><code><?php echo htmlspecialchars($m['id']); ?></code></td>
                        <td><?php echo htmlspecialchars($m['description']); ?><br><small><?php echo htmlspecialchars(($m['applied_at'] ?? '') . ' · ' . ($m['applied_by'] ?? '')); ?></small></td>
                        <td><?php if ($isLast): ?><button class="sp-btn sp-btn-warning sp-btn-sm mig-rollback" data-db="<?php echo htmlspecialchars($db); ?>" data-id="<?php echo htmlspecialchars($m['id']); ?>"><?php echo t('admin_migrations_rollback'); ?></button><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($s['pending'] as $m): ?>
                    <tr>
                        <td><span class="mig-pill mig-pending"><?php echo t('admin_migrations_status_pending'); ?></span><?php if ($m['destructive']): ?> <span class="mig-pill mig-destructive"><?php echo t('admin_migrations_status_destructive'); ?></span><?php endif; ?><?php if ($m['procedural']): ?> <span class="mig-pill mig-procedural"><?php echo t('admin_migrations_status_procedural'); ?></span><?php endif; ?></td>
                        <td><code><?php echo htmlspecialchars($m['id']); ?></code></td>
                        <td>
                            <?php echo htmlspecialchars($m['description']); ?>
                            <details class="mig-review"><summary><?php echo t('admin_migrations_review_sql'); ?></summary>
                                <?php if ($m['procedural']): ?>
                                    <pre class="mig-sql"><?php echo htmlspecialchars($m['preview'] ?: t('admin_migrations_procedural_note')); ?></pre>
                                <?php else: ?>
                                    <pre class="mig-sql"><?php echo htmlspecialchars(implode(";\n", array_map('trim', (array) $m['up']))); ?></pre>
                                <?php endif; ?>
                            </details>
                        </td>
                        <td><button class="sp-btn sp-btn-success sp-btn-sm mig-apply" data-db="<?php echo htmlspecialchars($db); ?>" data-id="<?php echo htmlspecialchars($m['id']); ?>" data-destructive="<?php echo $m['destructive'] ? '1' : '0'; ?>"><?php echo t('admin_migrations_apply'); ?></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const I18N = {
        errorTitle: <?php echo json_encode(t('admin_migrations_js_error_title')); ?>,
        confirmApplyTitle: <?php echo json_encode(t('admin_migrations_js_apply_confirm_title')); ?>,
        destructiveText: <?php echo json_encode(t('admin_migrations_js_destructive_text')); ?>,
        rollbackConfirmTitle: <?php echo json_encode(t('admin_migrations_js_rollback_confirm_title')); ?>,
        rollbackConfirmText: <?php echo json_encode(t('admin_migrations_js_rollback_confirm_text')); ?>,
        confirmBtn: <?php echo json_encode(t('admin_migrations_js_confirm_btn')); ?>,
        cancelBtn: <?php echo json_encode(t('admin_migrations_js_cancel_btn')); ?>
    };
    async function post(fields) {
        const fd = new FormData();
        for (const k in fields) fd.append(k, fields[k]);
        const res = await fetch('migrations.php', { method: 'POST', body: fd });
        return res.json();
    }
    function reloadSoon() { setTimeout(function () { window.location.reload(); }, 700); }
    function fail(msg) { Swal.fire({ icon: 'error', title: I18N.errorTitle, text: msg }); }

    document.querySelectorAll('.mig-apply').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const db = this.dataset.db, id = this.dataset.id, destructive = this.dataset.destructive === '1';
            let confirmDestructive = false;
            if (destructive) {
                const r = await Swal.fire({ icon: 'warning', title: I18N.confirmApplyTitle, text: I18N.destructiveText.replace('%s', id),
                    showCancelButton: true, confirmButtonText: I18N.confirmBtn, cancelButtonText: I18N.cancelBtn, confirmButtonColor: '#f14668' });
                if (!r.isConfirmed) return;
                confirmDestructive = true;
            }
            const data = await post({ action: 'apply', db: db, id: id, confirm_destructive: confirmDestructive ? '1' : '' });
            if (data.success) reloadSoon(); else fail(data.message);
        });
    });
    document.querySelectorAll('.mig-apply-all').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const data = await post({ action: 'apply_all', db: this.dataset.db });
            if (data.success) reloadSoon(); else fail(data.message);
        });
    });
    document.querySelectorAll('.mig-adopt').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const data = await post({ action: 'adopt_baseline', db: this.dataset.db });
            if (data.success) reloadSoon(); else fail(data.message);
        });
    });
    document.querySelectorAll('.mig-rollback').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const db = this.dataset.db, id = this.dataset.id;
            const r = await Swal.fire({ icon: 'warning', title: I18N.rollbackConfirmTitle, text: I18N.rollbackConfirmText.replace('%s', id),
                showCancelButton: true, confirmButtonText: I18N.confirmBtn, cancelButtonText: I18N.cancelBtn, confirmButtonColor: '#f39c12' });
            if (!r.isConfirmed) return;
            const data = await post({ action: 'rollback', db: db, id: id });
            if (data.success) reloadSoon(); else fail(data.message);
        });
    });
});
</script>
<?php
$content = ob_get_clean();
include_once __DIR__ . '/../layout.php';
?>
```

- [ ] **Step 2: Register the menu item**

In `./dashboard/menu.php`, in the `$admin` array, immediately after the `spam_patterns.php` entry, add:

```php
        [ 'label' => t('menu_admin_migrations'), 'icon' => 'fas fa-database', 'href' => 'migrations.php' ],
```

- [ ] **Step 3: Add English i18n keys**

In `./dashboard/lang/en.php`, alongside the other `admin_*` keys, add:

```php
    'menu_admin_migrations' => 'DB Migrations',
    'admin_migrations_page_title' => 'Database Migrations',
    'admin_migrations_intro' => 'Review and apply schema changes to the system databases. Migrations are committed files; nothing runs automatically — you apply each one here.',
    'admin_migrations_counts' => '%d applied · %d pending',
    'admin_migrations_missing_warn' => '· %d applied migration(s) no longer have a file',
    'admin_migrations_adopt_baseline' => 'Adopt baseline (mark as already applied)',
    'admin_migrations_apply_all' => 'Apply all pending',
    'admin_migrations_apply' => 'Apply',
    'admin_migrations_rollback' => 'Roll back',
    'admin_migrations_review_sql' => 'Review SQL',
    'admin_migrations_procedural_note' => 'Procedural migration (runs PHP, not previewable as SQL).',
    'admin_migrations_th_status' => 'Status',
    'admin_migrations_th_id' => 'Migration',
    'admin_migrations_th_desc' => 'Description',
    'admin_migrations_th_actions' => 'Actions',
    'admin_migrations_status_applied' => 'Applied',
    'admin_migrations_status_pending' => 'Pending',
    'admin_migrations_status_drift' => 'Drift',
    'admin_migrations_status_destructive' => 'Destructive',
    'admin_migrations_status_procedural' => 'Procedural',
    'admin_migrations_msg_applied' => 'Migration applied.',
    'admin_migrations_msg_rolled_back' => 'Migration rolled back.',
    'admin_migrations_msg_adopted' => 'Baseline marked as applied.',
    'admin_migrations_msg_applied_all' => 'Applied %d pending migration(s).',
    'admin_migrations_err_unknown_db' => 'Unknown managed database.',
    'admin_migrations_err_unknown_action' => 'Unknown action.',
    'admin_migrations_js_error_title' => 'Error',
    'admin_migrations_js_apply_confirm_title' => 'Apply destructive migration?',
    'admin_migrations_js_destructive_text' => 'Migration "%s" contains a destructive statement (DROP/TRUNCATE/DELETE). Apply it?',
    'admin_migrations_js_rollback_confirm_title' => 'Roll back migration?',
    'admin_migrations_js_rollback_confirm_text' => 'Run the down migration for "%s"?',
    'admin_migrations_js_confirm_btn' => 'Confirm',
    'admin_migrations_js_cancel_btn' => 'Cancel',
```

- [ ] **Step 4: Add German i18n keys**

In `./dashboard/lang/de.php`, add:

```php
    'menu_admin_migrations' => 'DB-Migrationen',
    'admin_migrations_page_title' => 'Datenbank-Migrationen',
    'admin_migrations_intro' => 'Schemaänderungen der System-Datenbanken prüfen und anwenden. Migrationen sind committete Dateien; nichts läuft automatisch — du wendest jede hier an.',
    'admin_migrations_counts' => '%d angewendet · %d ausstehend',
    'admin_migrations_missing_warn' => '· %d angewendete Migration(en) haben keine Datei mehr',
    'admin_migrations_adopt_baseline' => 'Baseline übernehmen (als angewendet markieren)',
    'admin_migrations_apply_all' => 'Alle ausstehenden anwenden',
    'admin_migrations_apply' => 'Anwenden',
    'admin_migrations_rollback' => 'Zurückrollen',
    'admin_migrations_review_sql' => 'SQL ansehen',
    'admin_migrations_procedural_note' => 'Prozedurale Migration (führt PHP aus, nicht als SQL anzeigbar).',
    'admin_migrations_th_status' => 'Status',
    'admin_migrations_th_id' => 'Migration',
    'admin_migrations_th_desc' => 'Beschreibung',
    'admin_migrations_th_actions' => 'Aktionen',
    'admin_migrations_status_applied' => 'Angewendet',
    'admin_migrations_status_pending' => 'Ausstehend',
    'admin_migrations_status_drift' => 'Abweichung',
    'admin_migrations_status_destructive' => 'Destruktiv',
    'admin_migrations_status_procedural' => 'Prozedural',
    'admin_migrations_msg_applied' => 'Migration angewendet.',
    'admin_migrations_msg_rolled_back' => 'Migration zurückgerollt.',
    'admin_migrations_msg_adopted' => 'Baseline als angewendet markiert.',
    'admin_migrations_msg_applied_all' => '%d ausstehende Migration(en) angewendet.',
    'admin_migrations_err_unknown_db' => 'Unbekannte verwaltete Datenbank.',
    'admin_migrations_err_unknown_action' => 'Unbekannte Aktion.',
    'admin_migrations_js_error_title' => 'Fehler',
    'admin_migrations_js_apply_confirm_title' => 'Destruktive Migration anwenden?',
    'admin_migrations_js_destructive_text' => 'Migration "%s" enthält eine destruktive Anweisung (DROP/TRUNCATE/DELETE). Anwenden?',
    'admin_migrations_js_rollback_confirm_title' => 'Migration zurückrollen?',
    'admin_migrations_js_rollback_confirm_text' => 'Die Down-Migration für "%s" ausführen?',
    'admin_migrations_js_confirm_btn' => 'Bestätigen',
    'admin_migrations_js_cancel_btn' => 'Abbrechen',
```

- [ ] **Step 5: Add French i18n keys** (note escaped apostrophes `\'`)

In `./dashboard/lang/fr.php`, add:

```php
    'menu_admin_migrations' => 'Migrations BD',
    'admin_migrations_page_title' => 'Migrations de base de données',
    'admin_migrations_intro' => 'Examinez et appliquez les changements de schéma des bases système. Les migrations sont des fichiers versionnés ; rien ne s\'exécute automatiquement — vous appliquez chacune ici.',
    'admin_migrations_counts' => '%d appliquée(s) · %d en attente',
    'admin_migrations_missing_warn' => '· %d migration(s) appliquée(s) n\'ont plus de fichier',
    'admin_migrations_adopt_baseline' => 'Adopter la base (marquer comme appliquée)',
    'admin_migrations_apply_all' => 'Appliquer toutes les migrations en attente',
    'admin_migrations_apply' => 'Appliquer',
    'admin_migrations_rollback' => 'Annuler',
    'admin_migrations_review_sql' => 'Voir le SQL',
    'admin_migrations_procedural_note' => 'Migration procédurale (exécute du PHP, non affichable en SQL).',
    'admin_migrations_th_status' => 'Statut',
    'admin_migrations_th_id' => 'Migration',
    'admin_migrations_th_desc' => 'Description',
    'admin_migrations_th_actions' => 'Actions',
    'admin_migrations_status_applied' => 'Appliquée',
    'admin_migrations_status_pending' => 'En attente',
    'admin_migrations_status_drift' => 'Dérive',
    'admin_migrations_status_destructive' => 'Destructive',
    'admin_migrations_status_procedural' => 'Procédurale',
    'admin_migrations_msg_applied' => 'Migration appliquée.',
    'admin_migrations_msg_rolled_back' => 'Migration annulée.',
    'admin_migrations_msg_adopted' => 'Base marquée comme appliquée.',
    'admin_migrations_msg_applied_all' => '%d migration(s) en attente appliquée(s).',
    'admin_migrations_err_unknown_db' => 'Base de données gérée inconnue.',
    'admin_migrations_err_unknown_action' => 'Action inconnue.',
    'admin_migrations_js_error_title' => 'Erreur',
    'admin_migrations_js_apply_confirm_title' => 'Appliquer une migration destructive ?',
    'admin_migrations_js_destructive_text' => 'La migration « %s » contient une instruction destructive (DROP/TRUNCATE/DELETE). L\'appliquer ?',
    'admin_migrations_js_rollback_confirm_title' => 'Annuler la migration ?',
    'admin_migrations_js_rollback_confirm_text' => 'Exécuter la migration down pour « %s » ?',
    'admin_migrations_js_confirm_btn' => 'Confirmer',
    'admin_migrations_js_cancel_btn' => 'Annuler',
```

- [ ] **Step 6: Add CSS**

In `./dashboard/css/dashboard.css`, append:

```css
/* DB migrations admin page */
.mig-dbname { color: var(--text-muted); font-weight: 400; font-size: 0.85em; }
.mig-counts { color: var(--text-secondary); margin-bottom: 0.75rem; }
.mig-warn { color: var(--amber); }
.mig-pill { display: inline-block; padding: 0.1rem 0.5rem; border-radius: var(--radius-pill); font-size: 0.75rem; font-weight: 600; }
.mig-applied { background: var(--green-bg); color: var(--green); }
.mig-pending { background: var(--grey-bg); color: var(--text-muted); }
.mig-drift, .mig-destructive { background: color-mix(in srgb, var(--amber) 18%, transparent); color: var(--amber); }
.mig-procedural { background: var(--grey-bg); color: var(--text-secondary); }
.mig-review { margin-top: 0.35rem; }
.mig-sql { background: var(--bg-elev, rgba(0,0,0,.2)); padding: 0.6rem; border-radius: var(--radius); overflow-x: auto; font-size: 0.8rem; white-space: pre; }
```
> Before saving, grep `dashboard.css` to confirm `--green-bg`, `--grey-bg`, `--amber`, `--radius-pill`, `--radius` exist (Phase 1/2 used the first four). If `color-mix` isn't used elsewhere, replace the `.mig-drift,.mig-destructive` background with an existing amber-bg token or a plain `rgba(...)` matching a sibling badge.

- [ ] **Step 7: Verify**

```bash
php -l dashboard/admin/migrations.php
php -l dashboard/menu.php
php -l dashboard/lang/en.php
php -l dashboard/lang/de.php
php -l dashboard/lang/fr.php
```
Expected: `No syntax errors detected` for each (French most at risk for apostrophe escaping).

---

### Task 3: `website` migration files (baseline + is_deceased)

**Files:**
- Create: `./migrations/website/20260621_0001_baseline.php`
- Create: `./migrations/website/20260621_0002_add_users_is_deceased.php`

**Interfaces:**
- Consumes: Task 1's procedural helper `migration_column_exists($conn, $table, $col)` (in `0002`).
- Produces: the `website` baseline + the users column migration that Task 4's removals depend on.

- [ ] **Step 1: Create the baseline migration**

Create `./migrations/website/20260621_0001_baseline.php`. The five `CREATE TABLE` statements are the **verbatim current DDL** (from api.py / the PHP pages); `system_metrics` gains the `ENGINE`/charset the others have. `known_bots` is seeded with the 41 default logins via `INSERT IGNORE` so a fresh DB matches prior behaviour. `down` is intentionally empty (we do not drop core tables when rolling back a baseline).

```php
<?php
return [
    'description' => 'Baseline: website system tables (known_bots, custom_webhooks, feedback, system_metrics, freestuff_games)',
    'up' => [
        "CREATE TABLE IF NOT EXISTS known_bots (
            id INT NOT NULL AUTO_INCREMENT,
            bot_login VARCHAR(50) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            added_by VARCHAR(50) DEFAULT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_bot_login (bot_login)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "INSERT IGNORE INTO known_bots (bot_login, added_by) VALUES
            ('0_applejuice_0','system'),('8hvdes','system'),('anotherttvviewer','system'),
            ('blerp','system'),('botofthespecter','system'),('coebot','system'),
            ('commanderroot','system'),('communityshowcase','system'),('creatisbot','system'),
            ('deepbot','system'),('electricallongboard','system'),('feardn','system'),
            ('fossabot','system'),('host_giveaway','system'),('icewaslit','system'),
            ('kofistreambot','system'),('lattemotte','system'),('logviewer','system'),
            ('lurxx','system'),('moobot','system'),('n3td3v','system'),('nightbot','system'),
            ('own3d','system'),('p0lizei_','system'),('phantombot','system'),('pretzelrocks','system'),
            ('restreambot','system'),('sery_bot','system'),('songlistbot','system'),
            ('soundalerts','system'),('streamelements','system'),('streamfahrer','system'),
            ('streamlabs','system'),('streamlootsbot','system'),('streamstickers','system'),
            ('tangiabot','system'),('the_marlchurch','system'),('twitchprimereminder','system'),
            ('v_and_k','system'),('vivbot','system'),('wizebot','system')",
        "CREATE TABLE IF NOT EXISTS custom_webhooks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(64) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            service VARCHAR(64) NOT NULL,
            event_name VARCHAR(64) NOT NULL,
            scope ENUM('channel','global') NOT NULL DEFAULT 'channel',
            target_username VARCHAR(255) NULL,
            verify_mode ENUM('none','secret','hmac') NOT NULL DEFAULT 'secret',
            secret VARCHAR(255) NULL,
            secret_header VARCHAR(64) NOT NULL DEFAULT 'X-Webhook-Secret',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_received_at TIMESTAMP NULL DEFAULT NULL,
            received_count INT NOT NULL DEFAULT 0,
            INDEX idx_enabled (enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            twitch_user_id VARCHAR(64),
            display_name VARCHAR(255),
            message TEXT,
            is_bug_report TINYINT(1) DEFAULT 0,
            bug_category VARCHAR(100),
            severity VARCHAR(50),
            steps_to_reproduce TEXT,
            expected_behavior TEXT,
            actual_behavior TEXT,
            browser_info VARCHAR(500),
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS system_metrics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            server_name VARCHAR(255) NOT NULL,
            cpu_percent FLOAT,
            ram_percent FLOAT,
            ram_used FLOAT,
            ram_total FLOAT,
            disk_percent FLOAT,
            disk_used FLOAT,
            disk_total FLOAT,
            net_sent FLOAT,
            net_recv FLOAT,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_server (server_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS freestuff_games (
            id INT AUTO_INCREMENT PRIMARY KEY,
            game_id VARCHAR(255),
            game_title VARCHAR(500),
            game_org VARCHAR(255),
            game_thumbnail TEXT,
            game_url TEXT,
            game_description TEXT,
            game_price VARCHAR(50),
            received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_received_at (received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
    'down' => [],
];
```

- [ ] **Step 2: Create the is_deceased migration (procedural, idempotent)**

Create `./migrations/website/20260621_0002_add_users_is_deceased.php` — replaces the hand-comment in `users.php`. Uses `migration_column_exists()` so it's safe even where the columns were already added by hand:

```php
<?php
return [
    'description' => 'Add users.is_deceased + users.deceased_date',
    'preview' => "ALTER TABLE users ADD COLUMN is_deceased TINYINT(1) NOT NULL DEFAULT 0 (if missing);\nALTER TABLE users ADD COLUMN deceased_date DATE NULL DEFAULT NULL (if missing)",
    'up' => function (mysqli $conn) {
        if (!migration_column_exists($conn, 'users', 'is_deceased')) {
            if (!$conn->query("ALTER TABLE users ADD COLUMN is_deceased TINYINT(1) NOT NULL DEFAULT 0")) {
                throw new Exception($conn->error);
            }
        }
        if (!migration_column_exists($conn, 'users', 'deceased_date')) {
            if (!$conn->query("ALTER TABLE users ADD COLUMN deceased_date DATE NULL DEFAULT NULL")) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_column_exists($conn, 'users', 'deceased_date')) {
            $conn->query("ALTER TABLE users DROP COLUMN deceased_date");
        }
        if (migration_column_exists($conn, 'users', 'is_deceased')) {
            $conn->query("ALTER TABLE users DROP COLUMN is_deceased");
        }
    },
];
```

> `down` uses `DROP COLUMN` — `migration_is_destructive` will flag `0002` as **destructive** (because its `preview`/`down` reference DROP)? No — destructiveness is computed from `up` only, and this `up` is a callable (procedural) → not auto-flagged. That's correct: applying `0002` only ADDs columns. (Rollback still runs the DROPs, gated by the rollback confirm.)

- [ ] **Step 3: Verify the migration files parse**

```bash
php -l migrations/website/20260621_0001_baseline.php
php -l migrations/website/20260621_0002_add_users_is_deceased.php
```
Expected: `No syntax errors detected` for each.

---

### Task 4: Retire the `website` defensive CREATEs

**⚠️ Deploy ordering:** this removes the code that auto-creates these tables. It is safe **only once the website baseline (`0001`) is adopted/applied in the target environment** (one-click "Adopt baseline" on existing DBs, or it runs on a fresh DB). Do this task last; the deploy runbook must adopt the baseline before/with shipping this.

**Files:**
- Modify: `./api/api.py`
- Modify: `./dashboard/admin/known_bots.php`, `./dashboard/admin/webhooks.php`, `./dashboard/admin/feedback.php`
- Modify: `./specterbotsystems/index.php`

**Interfaces:**
- Consumes: Task 3's baseline (the tables it removes the creators for are now created by the migration).

- [ ] **Step 1: Remove `api.py` ensure_* functions, DDL constants, and lifespan calls**

In `./api/api.py`:

1. Delete `CUSTOM_WEBHOOKS_TABLE_DDL` (the constant block, ~2247-2266) and `ensure_custom_webhooks_table()` (~2268-2280).
2. Delete `KNOWN_BOTS_TABLE_DDL` (~5596-5607) and `ensure_known_bots_table()` (~5639-5660).
3. **Keep** `DEFAULT_KNOWN_BOTS_SEED` (~5585-5594) — `get_known_bots_list()` still uses it as its fallback. Do NOT remove it.
4. In `lifespan` (~614-633), remove the two lines and their comments:

```python
    # Ensure the admin-managed custom webhooks table exists
    await ensure_custom_webhooks_table()
    # Ensure the admin-managed global known-bots registry exists (seeded on first run)
    await ensure_known_bots_table()
```
so the handler goes straight from `midnight_task = asyncio.create_task(midnight())` to the `yield` block.

- [ ] **Step 2: Remove the freestuff_games defensive CREATE**

In `./api/api.py` `save_freestuff_game()` (~1806+), remove only the comment + CREATE statement (keep the function, the cursor, and everything after):

```python
                # Create table if it doesn't exist
                await cur.execute("""
                    CREATE TABLE IF NOT EXISTS freestuff_games (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        game_id VARCHAR(255),
                        game_title VARCHAR(500),
                        game_org VARCHAR(255),
                        game_thumbnail TEXT,
                        game_url TEXT,
                        game_description TEXT,
                        game_price VARCHAR(50),
                        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_received_at (received_at)
                    )
                """)
```
The following `await conn.commit()` and the INSERT below it stay (read the function to confirm placement).

- [ ] **Step 3: Remove the PHP defensive CREATE blocks**

- `./dashboard/admin/known_bots.php`: delete the defensive `$conn->query("CREATE TABLE IF NOT EXISTS known_bots ( ... )")` block (the comment + the `CREATE TABLE` call near the top, added in Phase 1).
- `./dashboard/admin/webhooks.php`: delete the `CREATE TABLE IF NOT EXISTS custom_webhooks ( ... )` defensive block.
- `./dashboard/admin/feedback.php`: delete the `CREATE TABLE IF NOT EXISTS feedback ( ... )` defensive block.
- `./specterbotsystems/index.php`: delete the `CREATE TABLE IF NOT EXISTS system_metrics ( ... )` defensive block.

In each, read the file first and remove only the table-creation statement (and its immediate explanatory comment); leave the rest of the page untouched. Do NOT touch `admin_access.php`'s `admin_audit_ensure_table()` — that bootstrap stays.

- [ ] **Step 4: Verify**

```bash
python -m py_compile api/api.py
grep -c "ensure_custom_webhooks_table\|ensure_known_bots_table\|CUSTOM_WEBHOOKS_TABLE_DDL\|KNOWN_BOTS_TABLE_DDL" api/api.py   # expect 0
grep -c "DEFAULT_KNOWN_BOTS_SEED" api/api.py   # expect >=1 (kept for the fallback)
php -l dashboard/admin/known_bots.php
php -l dashboard/admin/webhooks.php
php -l dashboard/admin/feedback.php
php -l specterbotsystems/index.php
```
Expected: `py_compile` exits 0; first grep = 0; second grep ≥ 1; all `php -l` clean.

---

## Out of scope for v1 (follow-ups)

- **`admin_api_keys` + `bot_chat_token` baselines:** these are assumed-to-exist with no DDL in the repo. Capture their real DDL via `SHOW CREATE TABLE admin_api_keys` / `bot_chat_token` on production, then add as `website/20260621_0003_*` migrations. v1 deliberately does not guess prod schema.
- **`specterdiscordbot` / `roadmap` / `spam_pattern` baselines:** these DBs are **registered** (they appear in `migrations.php` with zero migrations + the page handles a per-DB connection error gracefully). Author their baselines once their live DDL is captured (`roadmap`'s is liftable from `roadmap/admin/database.php`; the others from prod). Their existing creators are retired in that follow-up, not v1.

## Functional verification (staging, after all tasks)

1. Open `migrations.php` as an admin → each managed DB shows; `website` lists `0001` + `0002` as pending; a non-admin is denied.
2. Existing prod-like `website` DB (tables already present): click **Adopt baseline** → `0001` recorded applied, no schema change. Then **Apply** `0002` → columns added; re-running is a no-op (idempotent).
3. Fresh empty DB: **Apply** `0001` → all five tables created + `known_bots` seeded (41 rows); **Apply** `0002`.
4. **Roll back** `0002` → columns dropped, ledger row removed. Re-apply works.
5. After Task 4 + baseline adopted: restart the API and load the admin pages → nothing breaks (tables exist via the migration, not the removed defensive code).
6. Drift: edit an applied migration file → page shows the **Drift** pill.

## Deploy / runbook

Deploy the dashboard + `api.py` changes together. **Before** shipping Task 4's removal of defensive CREATEs, adopt/apply the `website` baseline (`0001`) in every environment (one-click "Adopt baseline" on existing DBs, or it runs automatically on a fresh DB). Then restart the API. Per-user DBs and the other system DBs' creators are unaffected.
