<?php
// Stream servers the studio creates itself. billing_orders keeps who builds the order's
// server (provider) and the provider's server id (provider_ref), so the Portal's own status, addresses
// and build delays never overwrite the studio's, and a restart carries on where the build was.
$providerColumns = [
    'provider'     => "VARCHAR(16) NULL AFTER region_slug",
    'provider_ref' => "VARCHAR(64) NULL AFTER provider",
];

return [
    'description' => 'Cloud Studio: provider and server id for orders the studio builds itself',
    'preview' => 'billing_orders: ADD provider VARCHAR(16) NULL, provider_ref VARCHAR(64) NULL. No existing data is changed.',
    'up' => function (mysqli $conn) use ($providerColumns) {
        foreach ($providerColumns as $col => $def) {
            if (!migration_column_exists($conn, 'billing_orders', $col)) {
                if (!$conn->query("ALTER TABLE billing_orders ADD COLUMN $col $def")) throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) use ($providerColumns) {
        foreach (array_reverse(array_keys($providerColumns)) as $col) {
            if (migration_column_exists($conn, 'billing_orders', $col)) {
                if (!$conn->query("ALTER TABLE billing_orders DROP COLUMN $col")) throw new Exception($conn->error);
            }
        }
    },
];
