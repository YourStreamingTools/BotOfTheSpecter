<?php
// Admin API keys: an Admin access switch (every channel), Mobile app and
// Dashboard access tied to one channel, and a password for both sign-ins.
// users.is_demo marks the demo accounts a key's channel can point at.
return [
    'description' => 'Admin API keys: admin/app/dashboard access, channel and password; users.is_demo',
    'preview' => 'ALTER TABLE admin_api_keys ADD admin_access, app_access, dashboard_access, channel, password; ALTER TABLE users ADD is_demo',
    'up' => function (mysqli $conn) {
        if (migration_table_exists($conn, 'admin_api_keys')) {
            $cols = [
                // Existing keys keep their every-channel admin powers.
                'admin_access' => "TINYINT(1) NOT NULL DEFAULT 1",
                'app_access' => "TINYINT(1) NOT NULL DEFAULT 0",
                'dashboard_access' => "TINYINT(1) NOT NULL DEFAULT 0",
                'channel' => "VARCHAR(64) NULL DEFAULT NULL COMMENT 'users.username the app and dashboard sign-ins act as'",
                'password' => "VARCHAR(255) NULL DEFAULT NULL COMMENT 'password_hash() of the sign-in password'",
            ];
            foreach ($cols as $name => $def) {
                if (!migration_column_exists($conn, 'admin_api_keys', $name)) {
                    if (!$conn->query("ALTER TABLE admin_api_keys ADD COLUMN `$name` $def")) {
                        throw new Exception($conn->error);
                    }
                }
            }
        }
        if (migration_table_exists($conn, 'users') && !migration_column_exists($conn, 'users', 'is_demo')) {
            if (!$conn->query("ALTER TABLE users ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0")) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_table_exists($conn, 'users') && migration_column_exists($conn, 'users', 'is_demo')) {
            if (!$conn->query("ALTER TABLE users DROP COLUMN is_demo")) {
                throw new Exception($conn->error);
            }
        }
        if (!migration_table_exists($conn, 'admin_api_keys')) {
            return;
        }
        foreach (['password', 'channel', 'dashboard_access', 'app_access', 'admin_access'] as $name) {
            if (migration_column_exists($conn, 'admin_api_keys', $name)) {
                if (!$conn->query("ALTER TABLE admin_api_keys DROP COLUMN `$name`")) {
                    throw new Exception($conn->error);
                }
            }
        }
    },
];
