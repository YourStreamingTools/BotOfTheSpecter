<?php
$custom_webhooks_status_columns = [
    'last_attempt_at' => 'TIMESTAMP NULL DEFAULT NULL',
    'last_status'     => 'VARCHAR(32) NULL DEFAULT NULL',
    'last_error'      => 'VARCHAR(255) NULL DEFAULT NULL',
];
return [
    'description' => 'Custom webhooks: record the outcome of the last delivery attempt',
    'preview' => 'ALTER TABLE custom_webhooks ADD last_attempt_at, last_status, last_error',
    'up' => function (mysqli $conn) use ($custom_webhooks_status_columns) {
        if (!migration_table_exists($conn, 'custom_webhooks')) {
            return;
        }
        foreach ($custom_webhooks_status_columns as $column => $definition) {
            if (!migration_column_exists($conn, 'custom_webhooks', $column)) {
                if (!$conn->query("ALTER TABLE custom_webhooks ADD COLUMN $column $definition")) {
                    throw new Exception($conn->error);
                }
            }
        }
    },
    'down' => function (mysqli $conn) use ($custom_webhooks_status_columns) {
        if (!migration_table_exists($conn, 'custom_webhooks')) {
            return;
        }
        foreach (array_keys($custom_webhooks_status_columns) as $column) {
            if (migration_column_exists($conn, 'custom_webhooks', $column)) {
                if (!$conn->query("ALTER TABLE custom_webhooks DROP COLUMN $column")) {
                    throw new Exception($conn->error);
                }
            }
        }
    },
];
