<?php
return [
    'description' => 'Add users.is_deceased + users.deceased_date',
    'preview' => "ALTER TABLE users ADD COLUMN is_deceased TINYINT(1) NOT NULL DEFAULT 0 (if missing);\nALTER TABLE users ADD COLUMN deceased_date DATE NULL DEFAULT NULL (if missing)",
    'up' => function (mysqli $conn) {
        if (!migration_column_exists($conn, 'users', 'is_deceased')) {
            if (!$conn->query("ALTER TABLE users ADD COLUMN is_deceased TINYINT(1) NOT NULL DEFAULT 0")) {
                throw new Exception($conn->error);
            }
        }
        if (!migration_column_exists($conn, 'users', 'deceased_date')) {
            if (!$conn->query("ALTER TABLE users ADD COLUMN deceased_date DATE NULL DEFAULT NULL")) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_column_exists($conn, 'users', 'deceased_date')) {
            if (!$conn->query("ALTER TABLE users DROP COLUMN deceased_date")) {
                throw new Exception($conn->error);
            }
        }
        if (migration_column_exists($conn, 'users', 'is_deceased')) {
            if (!$conn->query("ALTER TABLE users DROP COLUMN is_deceased")) {
                throw new Exception($conn->error);
            }
        }
    },
];
