<?php
return [
    'description' => 'Record other Twitch channels: each user lists streamers to record into their own stream storage (3 by default, admins can change it per user)',
    'preview' => 'CREATE TABLE watched_record_channels (user_id, channel_login, channel_id, display_name, enabled) and watched_record_limits (user_id, max_channels)',
    'up' => function (mysqli $conn) {
        if (!migration_table_exists($conn, 'watched_record_channels')) {
            if (!$conn->query(
                "CREATE TABLE watched_record_channels (
                    id INT NOT NULL AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    channel_login VARCHAR(25) NOT NULL,
                    channel_id VARCHAR(32) NOT NULL DEFAULT '',
                    display_name VARCHAR(64) NOT NULL DEFAULT '',
                    enabled TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_watched_user_channel (user_id, channel_login),
                    KEY idx_watched_enabled (enabled)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            )) {
                throw new Exception($conn->error);
            }
        }
        if (!migration_table_exists($conn, 'watched_record_limits')) {
            if (!$conn->query(
                "CREATE TABLE watched_record_limits (
                    user_id INT NOT NULL,
                    max_channels INT UNSIGNED NOT NULL,
                    updated_by INT DEFAULT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            )) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        foreach (['watched_record_limits', 'watched_record_channels'] as $table) {
            if (migration_table_exists($conn, $table)) {
                if (!$conn->query("DROP TABLE $table")) {
                    throw new Exception($conn->error);
                }
            }
        }
    },
];
