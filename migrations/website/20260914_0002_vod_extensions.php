<?php
return [
    'description' => 'Extended VOD copies on Mega S4 (3 extra days)',
    'preview' => 'CREATE TABLE vod_extensions (username+filename, s4_key, expires_at)',
    'up' => function (mysqli $conn) {
        if (!migration_table_exists($conn, 'vod_extensions')) {
            if (!$conn->query(
                "CREATE TABLE vod_extensions (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    username VARCHAR(64) NOT NULL,
                    filename VARCHAR(255) NOT NULL,
                    s4_key VARCHAR(512) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_vod_user_file (username, filename),
                    KEY idx_vod_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            )) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_table_exists($conn, 'vod_extensions')) {
            if (!$conn->query('DROP TABLE vod_extensions')) {
                throw new Exception($conn->error);
            }
        }
    },
];
