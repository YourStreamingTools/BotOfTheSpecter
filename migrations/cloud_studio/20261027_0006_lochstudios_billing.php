<?php
// LochStudios Portal billing for stream servers. billing_orders keeps each user's orders (synced from
// the Portal API and its webhooks), keyed by website.users.id like the other studio tables.
// billing_events remembers every webhook event id so a resent event is only applied once.
// user_id takes website.users.id's exact type (read at run time) so the cross-database FK is accepted.
$billingUsersIdType = function (mysqli $conn): string {
    $res = $conn->query("SELECT c.COLUMN_TYPE FROM information_schema.COLUMNS c
        WHERE c.TABLE_SCHEMA = 'website' AND c.TABLE_NAME = 'users' AND c.COLUMN_NAME = 'id'");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) throw new Exception('Cannot read website.users.id (the migration user needs SELECT on website.users)');
    $type = strtolower((string) $row['COLUMN_TYPE']);
    if (!preg_match('/^(tiny|small|medium|big)?int(\(\d+\))?( unsigned)?$/', $type)) throw new Exception('Unexpected website.users.id type: ' . $type);
    return $type;
};

return [
    'description' => 'Cloud Studio: LochStudios billing orders and webhook events',
    'preview' => 'CREATE TABLE billing_orders (user_id FK to website.users ON DELETE CASCADE, order_ref unique, plan, region, status, client_id, IPs, next due date) and billing_events (webhook event ids). No existing data is changed.',
    'up' => function (mysqli $conn) use ($billingUsersIdType) {
        $type = $billingUsersIdType($conn);
        $tables = [
            'billing_orders' => "CREATE TABLE billing_orders (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id $type NOT NULL,
                order_ref VARCHAR(64) NOT NULL,
                plan_slug VARCHAR(32) NOT NULL,
                region_slug VARCHAR(16) NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'pending_payment',
                client_id VARCHAR(64) NULL,
                public_ip VARCHAR(45) NULL,
                private_ip VARCHAR(45) NULL,
                next_due_date DATE NULL,
                due_soon_at DATETIME NULL,
                synced_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_billing_orders_ref (order_ref),
                KEY idx_billing_orders_user (user_id, created_at),
                CONSTRAINT fk_billing_orders_user FOREIGN KEY (user_id) REFERENCES website.users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'billing_events' => "CREATE TABLE billing_events (
                event_id VARCHAR(64) NOT NULL PRIMARY KEY,
                event_type VARCHAR(48) NOT NULL,
                order_ref VARCHAR(64) NULL,
                received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_billing_events_ref (order_ref)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($tables as $name => $sql) {
            if (!migration_table_exists($conn, $name)) {
                if (!$conn->query($sql)) throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        foreach (['billing_events', 'billing_orders'] as $name) {
            if (!$conn->query("DROP TABLE IF EXISTS `$name`")) throw new Exception($conn->error);
        }
    },
];
