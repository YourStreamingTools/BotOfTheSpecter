<?php
return [
    'description' => 'Account deletion requests from the Profile page: deleted automatically 30 days after the request unless cancelled',
    'preview' => "CREATE TABLE account_deletion_requests (user_id, twitch_user_id, username, requested_at, delete_after, cancelled_at, completed_at)",
    'up' => function (mysqli $conn) {
        if (!migration_table_exists($conn, 'account_deletion_requests')) {
            if (!$conn->query(
                "CREATE TABLE account_deletion_requests (
                    id INT NOT NULL AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    twitch_user_id VARCHAR(64) NOT NULL,
                    username VARCHAR(64) NOT NULL,
                    requested_at DATETIME NOT NULL COMMENT 'UTC',
                    delete_after DATETIME NOT NULL COMMENT 'UTC',
                    cancelled_at DATETIME DEFAULT NULL COMMENT 'UTC',
                    completed_at DATETIME DEFAULT NULL COMMENT 'UTC',
                    PRIMARY KEY (id),
                    KEY idx_account_deletion_user (user_id),
                    KEY idx_account_deletion_due (delete_after)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            )) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_table_exists($conn, 'account_deletion_requests')) {
            if (!$conn->query("DROP TABLE account_deletion_requests")) {
                throw new Exception($conn->error);
            }
        }
    },
];
