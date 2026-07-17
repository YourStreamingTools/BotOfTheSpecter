<?php
// System-DB migration runner. Pure logic - nothing executes on include.
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
    // Migrations live outside the public web root (dashboard/). On the server that is
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
    if (!$stmt) { $conn->close(); throw new Exception($conn->error); }
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
    if (!$ins) { $conn->close(); throw new Exception($conn->error); }
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
    if (!$del) { $conn->close(); throw new Exception($conn->error); }
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
    if (!$stmt) { $conn->close(); throw new Exception($conn->error); }
    $stmt->bind_param('s', $baseline['id']); $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0; $stmt->close();
    if ($exists) { $conn->close(); throw new Exception("Baseline already recorded for " . $db); }
    $ins = $conn->prepare("INSERT INTO schema_migrations (migration_id, name, checksum, applied_by) VALUES (?, ?, ?, ?)");
    if (!$ins) { $conn->close(); throw new Exception($conn->error); }
    $name = $baseline['description'] . ' (adopted)'; $sum = $baseline['checksum'];
    $ins->bind_param('ssss', $baseline['id'], $name, $sum, $appliedBy);
    $ins->execute(); $ins->close();
    $conn->close();
}

// Helpers available to procedural migrations (callable up/down)
function migration_table_exists(mysqli $conn, $table) {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param('s', $table); $stmt->execute();
    $r = $stmt->get_result()->num_rows > 0; $stmt->close(); return $r;
}
function migration_column_exists(mysqli $conn, $table, $col) {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param('ss', $table, $col); $stmt->execute();
    $r = $stmt->get_result()->num_rows > 0; $stmt->close(); return $r;
}
function migration_index_exists(mysqli $conn, $table, $index) {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param('ss', $table, $index); $stmt->execute();
    $r = $stmt->get_result()->num_rows > 0; $stmt->close(); return $r;
}
