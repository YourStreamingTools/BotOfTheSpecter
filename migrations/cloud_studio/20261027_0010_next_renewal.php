<?php
// The renewal date is anchored to the day the stream server was built. The Portal sends it as
// next_renewal_at (UTC) in server.ready and the order fetch; billing_orders keeps it in UTC.
return [
    'description' => 'Cloud Studio: renewal time from the Portal, in UTC',
    'preview' => 'billing_orders: ADD next_renewal_at DATETIME NULL (UTC). No existing data is changed.',
    'up' => function (mysqli $conn) {
        if (!migration_column_exists($conn, 'billing_orders', 'next_renewal_at')) {
            if (!$conn->query("ALTER TABLE billing_orders ADD COLUMN next_renewal_at DATETIME NULL COMMENT 'UTC' AFTER next_due_date")) throw new Exception($conn->error);
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_column_exists($conn, 'billing_orders', 'next_renewal_at')) {
            if (!$conn->query("ALTER TABLE billing_orders DROP COLUMN next_renewal_at")) throw new Exception($conn->error);
        }
    },
];
