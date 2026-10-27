<?php
// Build delays and failures from the LochStudios Portal (server.delayed and server.failed webhooks).
// billing_orders keeps the latest one so the profile page can explain the wait and the admin page
// can show the raw reason. Cleared when the build moves on (server.provisioning or server.ready).
$buildIssueColumns = [
    'build_issue'           => "VARCHAR(16) NULL AFTER last_reboot_at",
    'build_reason'          => "VARCHAR(32) NULL AFTER build_issue",
    'build_message'         => "VARCHAR(255) NULL AFTER build_reason",
    'build_retrying'        => "TINYINT(1) NULL AFTER build_message",
    'build_attempt'         => "INT UNSIGNED NULL AFTER build_retrying",
    'build_next_attempt_at' => "DATETIME NULL AFTER build_attempt",
    'build_issue_at'        => "DATETIME NULL AFTER build_next_attempt_at",
];

return [
    'description' => 'Cloud Studio: stream server build delays and failures from the Portal',
    'preview' => 'billing_orders: ADD build_issue, build_reason, build_message, build_retrying, build_attempt, build_next_attempt_at, build_issue_at (all NULL). No existing data is changed.',
    'up' => function (mysqli $conn) use ($buildIssueColumns) {
        foreach ($buildIssueColumns as $col => $def) {
            if (!migration_column_exists($conn, 'billing_orders', $col)) {
                if (!$conn->query("ALTER TABLE billing_orders ADD COLUMN $col $def")) throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) use ($buildIssueColumns) {
        foreach (array_reverse(array_keys($buildIssueColumns)) as $col) {
            if (migration_column_exists($conn, 'billing_orders', $col)) {
                if (!$conn->query("ALTER TABLE billing_orders DROP COLUMN $col")) throw new Exception($conn->error);
            }
        }
    },
];
