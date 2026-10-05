<?php
return [
    'description' => 'Users: stop last_login auto-updating on every row change (only login.php sets it)',
    'preview' => 'ALTER TABLE users MODIFY last_login TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP (drops ON UPDATE CURRENT_TIMESTAMP)',
    'up' => function (mysqli $conn) {
        if (!migration_column_exists($conn, 'users', 'last_login')) {
            return;
        }
        if (!$conn->query("ALTER TABLE users MODIFY last_login TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP")) {
            throw new Exception($conn->error);
        }
    },
    'down' => function (mysqli $conn) {
        if (!migration_column_exists($conn, 'users', 'last_login')) {
            return;
        }
        if (!$conn->query("ALTER TABLE users MODIFY last_login TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP")) {
            throw new Exception($conn->error);
        }
    },
];
