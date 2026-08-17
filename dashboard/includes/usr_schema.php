<?php
// Inspect helpers for the per-user schema in usr_database.php.
// Nothing here connects or mutates a database on include.

function usr_schema_valid_dbname($dbname)
{
    return is_string($dbname) && preg_match('/^[a-zA-Z0-9_]{1,64}$/', $dbname);
}

function usr_schema_parse_columns(array $tables)
{
    $columns = [];
    foreach ($tables as $tbl => $create_sql) {
        $columns[$tbl] = [];
        $start = strpos($create_sql, '(');
        $end = strrpos($create_sql, ')');
        if ($start === false || $end === false || $end <= $start) {
            continue;
        }
        $inner = substr($create_sql, $start + 1, $end - $start - 1);
        $parts = preg_split('/,\s*\n/', $inner);
        foreach ($parts as $part) {
            $line = trim($part);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN|CHECK)\b/i', $line)) {
                continue;
            }
            if (preg_match('/^(`?)([A-Za-z0-9_]+)\1\s+(.*)$/s', $line, $m)) {
                $col = $m[2];
                $def = trim(preg_replace('/\s+$/', '', $m[3]));
                $def = preg_replace('/\s+/', ' ', $def);
                $columns[$tbl][$col] = $def;
            }
        }
    }
    return $columns;
}

function usr_schema_managed_table_names()
{
    static $names = null;
    if (is_array($names)) {
        return $names;
    }
    $file = __DIR__ . '/usr_database.php';
    $src = @file_get_contents($file);
    if (!is_string($src) || $src === '') {
        $names = [];
        return $names;
    }
    preg_match_all('/[\'"]([A-Za-z0-9_]+)[\'"]\s*=>\s*"\s*CREATE TABLE/i', $src, $m);
    $names = array_values(array_unique($m[1] ?? []));
    return $names;
}

function usr_schema_change($kind, $message, $sql = '', $table = null, $column = null, $destructive = false)
{
    return [
        'kind' => (string) $kind,
        'table' => $table,
        'column' => $column,
        'message' => (string) $message,
        'sql' => is_string($sql) ? trim($sql) : '',
        'destructive' => (bool) $destructive,
    ];
}

function usr_schema_missing_database_result($dbname)
{
    $pending = [
        usr_schema_change('create_database', "Database `$dbname` does not exist", "CREATE DATABASE `$dbname`"),
    ];
    foreach (usr_schema_managed_table_names() as $table) {
        $pending[] = usr_schema_change('create_table', "Table $table will be created", '', $table);
    }
    return [
        'ok' => true,
        'username' => $dbname,
        'db_exists' => false,
        'expected_tables' => count(usr_schema_managed_table_names()),
        'existing_tables' => 0,
        'pending' => $pending,
        'skipped' => [],
        'current' => false,
    ];
}

function usr_schema_error_result($dbname, $error)
{
    return [
        'ok' => false,
        'error' => (string) $error,
        'username' => (string) $dbname,
        'db_exists' => false,
        'expected_tables' => count(usr_schema_managed_table_names()),
        'existing_tables' => 0,
        'pending' => [],
        'skipped' => [],
        'current' => false,
    ];
}

function usr_schema_collect_diff(mysqli $conn, $dbname, array $tables, array $columns)
{
    $pending = [];
    $skipped = [];
    $existingTables = [];

    $tablesRes = $conn->query('SHOW TABLES');
    if ($tablesRes) {
        while ($row = $tablesRes->fetch_array(MYSQLI_NUM)) {
            $existingTables[(string) $row[0]] = true;
        }
        $tablesRes->free();
    }

    $pomosExists = isset($existingTables['user_pomos']);
    $timersExists = isset($existingTables['user_timers']);

    foreach ($tables as $tableName => $createSql) {
        if (!isset($existingTables[$tableName])) {
            if ($tableName === 'user_timers' && $pomosExists && !$timersExists) {
                continue;
            }
            $pending[] = usr_schema_change(
                'create_table',
                "Table $tableName does not exist",
                $createSql,
                $tableName
            );
            continue;
        }
        if (!isset($columns[$tableName]) || !is_array($columns[$tableName])) {
            continue;
        }

        $existingCols = [];
        $colRes = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '$dbname' AND TABLE_NAME = '$tableName'");
        if ($colRes) {
            while ($colRow = $colRes->fetch_assoc()) {
                $existingCols[] = $colRow['COLUMN_NAME'];
            }
            $colRes->free();
        }

        foreach ($columns[$tableName] as $columnName => $columnDefinition) {
            if (!in_array($columnName, $existingCols, true)) {
                $pending[] = usr_schema_change(
                    'add_column',
                    "Column $columnName is missing from $tableName",
                    "ALTER TABLE `$tableName` ADD `$columnName` $columnDefinition",
                    $tableName,
                    $columnName
                );
            }
        }

        $expectedCols = array_keys($columns[$tableName]);
        foreach (array_diff($existingCols, $expectedCols) as $extraCol) {
            $pkCheck = $conn->query("SHOW INDEX FROM `$tableName` WHERE Key_name = 'PRIMARY' AND Column_name = '$extraCol'");
            if ($pkCheck && $pkCheck->num_rows > 0) {
                $skipped[] = usr_schema_change('skip_column', "Skipping drop of primary key column $extraCol on $tableName", '', $tableName, $extraCol);
                continue;
            }
            $fkCheck = $conn->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '$dbname' AND TABLE_NAME = '$tableName' AND COLUMN_NAME = '$extraCol' AND REFERENCED_TABLE_NAME IS NOT NULL");
            if ($fkCheck && $fkCheck->num_rows > 0) {
                $skipped[] = usr_schema_change('skip_column', "Skipping drop of FK column $extraCol on $tableName", '', $tableName, $extraCol);
                continue;
            }
            $reserved = ['created_at', 'updated_at', 'timestamp', 'source'];
            if (in_array($extraCol, $reserved, true)) {
                $skipped[] = usr_schema_change('skip_column', "Skipping drop of reserved column $extraCol on $tableName", '', $tableName, $extraCol);
                continue;
            }
            $pending[] = usr_schema_change(
                'drop_column',
                "Extra column $extraCol on $tableName will be dropped",
                "ALTER TABLE `$tableName` DROP COLUMN `$extraCol`",
                $tableName,
                $extraCol,
                true
            );
        }
    }

    $managed = array_keys($tables);
    foreach (array_keys($existingTables) as $existingTable) {
        if ($existingTable === 'user_pomos' && $pomosExists && !$timersExists) {
            continue;
        }
        if (!in_array($existingTable, $managed, true)) {
            $pending[] = usr_schema_change(
                'drop_table',
                "Unmanaged table $existingTable will be dropped",
                "DROP TABLE IF EXISTS `$existingTable`",
                $existingTable,
                null,
                true
            );
        }
    }

    if ($pomosExists && !$timersExists) {
        $pending[] = usr_schema_change(
            'rename_table',
            'Table user_pomos will be renamed to user_timers',
            'RENAME TABLE user_pomos TO user_timers',
            'user_pomos'
        );
    }

    if (isset($existingTables['chat_history'])) {
        $pk = $conn->query("SHOW INDEX FROM chat_history WHERE Key_name = 'PRIMARY'");
        if ($pk && $pk->num_rows > 0) {
            $pending[] = usr_schema_change(
                'drop_primary_key',
                'Primary key on chat_history will be removed',
                'ALTER TABLE chat_history DROP PRIMARY KEY',
                'chat_history',
                null,
                true
            );
        }
    }

    if (isset($existingTables['user_tasks'])) {
        $idxBacklog = $conn->query("SHOW INDEX FROM user_tasks WHERE Key_name = 'idx_backlog'");
        if ($idxBacklog !== false && $idxBacklog->num_rows === 0) {
            $pending[] = usr_schema_change(
                'add_index',
                'Index idx_backlog is missing on user_tasks',
                'ALTER TABLE user_tasks ADD INDEX idx_backlog (user_id, backlog_position)',
                'user_tasks'
            );
        }
        $idxProject = $conn->query("SHOW INDEX FROM user_tasks WHERE Key_name = 'idx_user_project'");
        if ($idxProject !== false && $idxProject->num_rows === 0) {
            $pending[] = usr_schema_change(
                'add_index',
                'Index idx_user_project is missing on user_tasks',
                'ALTER TABLE user_tasks ADD INDEX idx_user_project (user_id, project)',
                'user_tasks'
            );
        }
    }

    if (isset($existingTables['streamer_tasks'])) {
        $stStatus = $conn->query("SHOW COLUMNS FROM streamer_tasks LIKE 'status'");
        if ($stStatus && $stStatus->num_rows > 0) {
            $stRow = $stStatus->fetch_assoc();
            if (isset($stRow['Type']) && strpos($stRow['Type'], "'pending'") === false) {
                $pending[] = usr_schema_change(
                    'modify_column',
                    "streamer_tasks.status is missing 'pending'",
                    "ALTER TABLE streamer_tasks MODIFY COLUMN status ENUM('pending','active','completed','rejected','hidden') DEFAULT 'active'",
                    'streamer_tasks',
                    'status'
                );
            }
        }
    }

    if (isset($existingTables['timed_messages'])) {
        $triggerCol = $conn->query("SHOW COLUMNS FROM timed_messages LIKE 'trigger_type'");
        if ($triggerCol && $triggerCol->num_rows > 0) {
            $triggerRow = $triggerCol->fetch_assoc();
            if (isset($triggerRow['Type']) && strpos($triggerRow['Type'], "'scheduled'") === false) {
                $pending[] = usr_schema_change(
                    'modify_column',
                    "timed_messages.trigger_type is missing 'scheduled'",
                    "ALTER TABLE timed_messages MODIFY trigger_type ENUM('timer', 'chat_lines', 'both', 'scheduled') NOT NULL DEFAULT 'timer'",
                    'timed_messages',
                    'trigger_type'
                );
            }
        }
        $chatTrigger = $conn->query("SHOW COLUMNS FROM timed_messages LIKE 'chat_line_trigger'");
        if ($chatTrigger && ($chatRow = $chatTrigger->fetch_assoc())) {
            if (strtoupper((string) ($chatRow['Null'] ?? '')) === 'NO') {
                $pending[] = usr_schema_change(
                    'modify_column',
                    'timed_messages.chat_line_trigger should allow NULL',
                    'ALTER TABLE timed_messages MODIFY chat_line_trigger INT NULL',
                    'timed_messages',
                    'chat_line_trigger'
                );
            }
        }
        $intervalCol = $conn->query("SHOW COLUMNS FROM timed_messages LIKE 'interval_count'");
        if ($intervalCol && ($intervalRow = $intervalCol->fetch_assoc())) {
            if (strtoupper((string) ($intervalRow['Null'] ?? '')) === 'NO') {
                $pending[] = usr_schema_change(
                    'modify_column',
                    'timed_messages.interval_count should allow NULL',
                    'ALTER TABLE timed_messages MODIFY interval_count INT NULL',
                    'timed_messages',
                    'interval_count'
                );
            }
        }
    }

    if (isset($existingTables['analytic_stream_watch_streak'])) {
        $unique = $conn->query("SHOW INDEX FROM analytic_stream_watch_streak WHERE Key_name = 'uq_user_name'");
        if ($unique !== false && $unique->num_rows === 0) {
            $pending[] = usr_schema_change(
                'add_index',
                'Unique key uq_user_name is missing on analytic_stream_watch_streak',
                'ALTER TABLE analytic_stream_watch_streak ADD UNIQUE KEY uq_user_name (user_name)',
                'analytic_stream_watch_streak'
            );
        }
    }

    return [
        'ok' => true,
        'username' => $dbname,
        'db_exists' => true,
        'expected_tables' => count($tables),
        'existing_tables' => count($existingTables),
        'pending' => $pending,
        'skipped' => $skipped,
        'current' => count($pending) === 0,
    ];
}

function usr_schema_load_tables()
{
    static $tables = null;
    if (is_array($tables)) {
        return $tables;
    }
    $src = @file_get_contents(__DIR__ . '/usr_database.php');
    if (!is_string($src) || $src === '') {
        throw new Exception('Could not read usr_database.php');
    }
    $start = strpos($src, '    $tables = [');
    $end = strpos($src, '    // Build $columns mapping');
    if ($start === false || $end === false || $end <= $start) {
        throw new Exception('Could not locate $tables in usr_database.php');
    }
    $chunk = trim(substr($src, $start, $end - $start));
    if (!preg_match('/^\$tables\s*=\s*(\[.*\]);$/s', $chunk, $m)) {
        throw new Exception('Could not parse $tables from usr_database.php');
    }
    $tables = eval('return ' . $m[1] . ';');
    if (!is_array($tables)) {
        throw new Exception('Parsed $tables was not an array');
    }
    return $tables;
}

function usr_schema_db_config()
{
    $path = file_exists('/var/www/config/database.php')
        ? '/var/www/config/database.php'
        : __DIR__ . '/../../config/database.php';
    require $path;
    return [$db_servername, $db_username, $db_password];
}

function usr_schema_inspect($dbname)
{
    if (!usr_schema_valid_dbname($dbname)) {
        return usr_schema_error_result($dbname, 'Invalid username/database name.');
    }
    try {
        $tables = usr_schema_load_tables();
        $columns = usr_schema_parse_columns($tables);
        list($host, $user, $pass) = usr_schema_db_config();
        $conn = new mysqli($host, $user, $pass);
        if ($conn->connect_error) {
            return usr_schema_error_result($dbname, $conn->connect_error);
        }
        $escaped = $conn->real_escape_string($dbname);
        $exists = $conn->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$escaped'");
        if (!$exists || $exists->num_rows === 0) {
            $conn->close();
            return usr_schema_missing_database_result($dbname);
        }
        $conn->select_db($dbname);
        $conn->set_charset('utf8mb4');
        $result = usr_schema_collect_diff($conn, $dbname, $tables, $columns);
        $conn->close();
        return $result;
    } catch (Throwable $e) {
        return usr_schema_error_result($dbname, $e->getMessage());
    }
}

function usr_schema_apply_for_user($dbname)
{
    if (!usr_schema_valid_dbname($dbname)) {
        throw new Exception('Invalid username/database name.');
    }
    $sessionWasClosed = (session_status() !== PHP_SESSION_ACTIVE);
    if ($sessionWasClosed) {
        @session_start();
    }
    $saved = [
        'username' => $_SESSION['username'] ?? null,
        'ok' => $_SESSION['usr_schema_ok'] ?? null,
        'console' => $_SESSION['usr_schema_console'] ?? null,
    ];
    $_SESSION['username'] = $dbname;
    unset($_SESSION['usr_schema_ok'], $_SESSION['usr_schema_console']);
    $GLOBALS['usr_schema_logs'] = [];

    include __DIR__ . '/usr_database.php';

    $logs = $GLOBALS['usr_schema_logs'] ?? [];

    if ($saved['username'] !== null) {
        $_SESSION['username'] = $saved['username'];
    } else {
        unset($_SESSION['username']);
    }
    if ($saved['ok'] !== null) {
        $_SESSION['usr_schema_ok'] = $saved['ok'];
    } else {
        unset($_SESSION['usr_schema_ok']);
    }
    if ($saved['console'] !== null) {
        $_SESSION['usr_schema_console'] = $saved['console'];
    } else {
        unset($_SESSION['usr_schema_console']);
    }
    if ($sessionWasClosed && session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
    return $logs;
}
