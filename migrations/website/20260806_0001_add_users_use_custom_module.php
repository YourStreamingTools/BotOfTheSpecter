<?php
return [
    'description' => 'Add users.use_custom_module (opt-in custom channel module load)',
    'preview' => "ALTER TABLE users ADD COLUMN use_custom_module TINYINT(1) NOT NULL DEFAULT 0 (if missing)",
    'up' => function (mysqli $conn) {
        if (!migration_column_exists($conn, 'users', 'use_custom_module')) {
            if (!$conn->query(
                "ALTER TABLE users ADD COLUMN use_custom_module TINYINT(1) NOT NULL DEFAULT 0 "
                . "COMMENT 'Opt-in: load custom_channel_modules/{username}.py when bot starts'"
            )) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_column_exists($conn, 'users', 'use_custom_module')) {
            if (!$conn->query("ALTER TABLE users DROP COLUMN use_custom_module")) {
                throw new Exception($conn->error);
            }
        }
    },
];
