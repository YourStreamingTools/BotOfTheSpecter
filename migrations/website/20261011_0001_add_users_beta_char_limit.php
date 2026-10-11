<?php
return [
    'description' => 'Add users.beta_char_limit (dashboard 500-character editor toggle)',
    'preview' => "ALTER TABLE users ADD COLUMN beta_char_limit TINYINT(1) NOT NULL DEFAULT 0 (if missing)",
    'up' => function (mysqli $conn) {
        if (!migration_column_exists($conn, 'users', 'beta_char_limit')) {
            if (!$conn->query(
                "ALTER TABLE users ADD COLUMN beta_char_limit TINYINT(1) NOT NULL DEFAULT 0 "
                . "COMMENT 'Dashboard editor: 1 = 500 character limit, 0 = 255'"
            )) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_column_exists($conn, 'users', 'beta_char_limit')) {
            if (!$conn->query("ALTER TABLE users DROP COLUMN beta_char_limit")) {
                throw new Exception($conn->error);
            }
        }
    },
];
