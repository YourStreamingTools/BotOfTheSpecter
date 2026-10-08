<?php
return [
    'description' => 'Scheduled VOD reruns: stream stored VODs to the streamer\'s Twitch channel at a set time',
    'preview' => "CREATE TABLE vod_reruns (one scheduled rerun) and vod_rerun_items (the VODs it plays, in order)",
    'up' => function (mysqli $conn) {
        if (!migration_table_exists($conn, 'vod_reruns')) {
            if (!$conn->query(
                "CREATE TABLE vod_reruns (
                    id INT NOT NULL AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    username VARCHAR(64) NOT NULL,
                    scheduled_at DATETIME NOT NULL COMMENT 'UTC',
                    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
                    status ENUM('scheduled','live','done','failed','cancelled','skipped') NOT NULL DEFAULT 'scheduled',
                    cancel_requested TINYINT(1) NOT NULL DEFAULT 0,
                    current_position INT DEFAULT NULL,
                    error_message VARCHAR(255) DEFAULT NULL,
                    started_at DATETIME DEFAULT NULL,
                    finished_at DATETIME DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_vod_reruns_user (user_id),
                    KEY idx_vod_reruns_due (status, scheduled_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            )) {
                throw new Exception($conn->error);
            }
        }
        if (!migration_table_exists($conn, 'vod_rerun_items')) {
            if (!$conn->query(
                "CREATE TABLE vod_rerun_items (
                    id INT NOT NULL AUTO_INCREMENT,
                    rerun_id INT NOT NULL,
                    user_id INT NOT NULL,
                    position INT NOT NULL,
                    filename VARCHAR(255) NOT NULL,
                    storage ENUM('local','s4','user_s3') NOT NULL DEFAULT 'local',
                    source_key VARCHAR(1024) DEFAULT NULL,
                    title VARCHAR(255) NOT NULL,
                    game_id VARCHAR(32) DEFAULT NULL,
                    game_name VARCHAR(255) DEFAULT NULL,
                    duration_seconds INT DEFAULT NULL,
                    status ENUM('pending','staging','staged','playing','done','failed','skipped','cancelled') NOT NULL DEFAULT 'pending',
                    staged_path VARCHAR(1024) DEFAULT NULL,
                    ffmpeg_pid INT DEFAULT NULL,
                    error_message VARCHAR(255) DEFAULT NULL,
                    started_at DATETIME DEFAULT NULL,
                    finished_at DATETIME DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY idx_vod_rerun_items_rerun (rerun_id, position),
                    KEY idx_vod_rerun_items_file (user_id, filename)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            )) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_table_exists($conn, 'vod_rerun_items')) {
            if (!$conn->query('DROP TABLE vod_rerun_items')) {
                throw new Exception($conn->error);
            }
        }
        if (migration_table_exists($conn, 'vod_reruns')) {
            if (!$conn->query('DROP TABLE vod_reruns')) {
                throw new Exception($conn->error);
            }
        }
    },
];
