<?php
// Automatic recovery for new stream servers. When a server's SSH never answers during setup, the
// studio asks the LochStudios Portal to reboot it. billing_orders keeps how many reboots were
// requested for the order and when the last one was, so the studio caps them at two per order.
$rebootColumns = [
    'reboot_count'   => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER installed_at",
    'last_reboot_at' => "DATETIME NULL AFTER reboot_count",
];

return [
    'description' => 'Cloud Studio: automatic reboot requests for stream servers during setup',
    'preview' => 'billing_orders: ADD reboot_count INT UNSIGNED NOT NULL DEFAULT 0, last_reboot_at DATETIME NULL. No existing data is changed.',
    'up' => function (mysqli $conn) use ($rebootColumns) {
        foreach ($rebootColumns as $col => $def) {
            if (!migration_column_exists($conn, 'billing_orders', $col)) {
                if (!$conn->query("ALTER TABLE billing_orders ADD COLUMN $col $def")) throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) use ($rebootColumns) {
        foreach (array_reverse(array_keys($rebootColumns)) as $col) {
            if (migration_column_exists($conn, 'billing_orders', $col)) {
                if (!$conn->query("ALTER TABLE billing_orders DROP COLUMN $col")) throw new Exception($conn->error);
            }
        }
    },
];
