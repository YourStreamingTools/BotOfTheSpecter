<?php
// Automatic stream server setup for LochStudios orders. billing_orders tracks the install
// (pending, installing, installed, failed, with the last error and attempt count) and slices keeps
// the server's SSH host key, recorded on first connect and required to match afterwards.
$provisionOrderColumns = [
    'install_state'      => "VARCHAR(16) NULL AFTER private_ip",
    'install_error'      => "VARCHAR(255) NULL AFTER install_state",
    'install_attempts'   => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER install_error",
    'install_started_at' => "DATETIME NULL AFTER install_attempts",
    'installed_at'       => "DATETIME NULL AFTER install_started_at",
];

return [
    'description' => 'Cloud Studio: automatic stream server install state and SSH host keys',
    'preview' => 'billing_orders: ADD install_state, install_error, install_attempts, install_started_at, installed_at. slices: ADD ssh_host_key TEXT NULL. No existing data is changed.',
    'up' => function (mysqli $conn) use ($provisionOrderColumns) {
        foreach ($provisionOrderColumns as $col => $def) {
            if (!migration_column_exists($conn, 'billing_orders', $col)) {
                if (!$conn->query("ALTER TABLE billing_orders ADD COLUMN $col $def")) throw new Exception($conn->error);
            }
        }
        if (!migration_column_exists($conn, 'slices', 'ssh_host_key')) {
            if (!$conn->query("ALTER TABLE slices ADD COLUMN ssh_host_key TEXT NULL")) throw new Exception($conn->error);
        }
    },
    'down' => function (mysqli $conn) use ($provisionOrderColumns) {
        foreach (array_reverse(array_keys($provisionOrderColumns)) as $col) {
            if (migration_column_exists($conn, 'billing_orders', $col)) {
                if (!$conn->query("ALTER TABLE billing_orders DROP COLUMN $col")) throw new Exception($conn->error);
            }
        }
        if (migration_column_exists($conn, 'slices', 'ssh_host_key')) {
            if (!$conn->query("ALTER TABLE slices DROP COLUMN ssh_host_key")) throw new Exception($conn->error);
        }
    },
];
