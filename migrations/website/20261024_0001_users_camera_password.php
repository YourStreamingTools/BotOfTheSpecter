<?php
return [
    'description' => 'Add users.camera_password_hash (phone camera relay viewer password)',
    'preview' => "ALTER TABLE users ADD COLUMN camera_password_hash VARCHAR(255) NULL DEFAULT NULL (if missing)",
    'up' => function (mysqli $conn) {
        if (!migration_column_exists($conn, 'users', 'camera_password_hash')) {
            if (!$conn->query(
                "ALTER TABLE users ADD COLUMN camera_password_hash VARCHAR(255) NULL DEFAULT NULL "
                . "COMMENT 'password_hash() of the camera relay viewer password; NULL = only the API key can view'"
            )) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_column_exists($conn, 'users', 'camera_password_hash')) {
            if (!$conn->query("ALTER TABLE users DROP COLUMN camera_password_hash")) {
                throw new Exception($conn->error);
            }
        }
    },
];
