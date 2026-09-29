<?php
return [
    'description' => 'Shared Twitch VOD pull state, S3 copies from Twitch VODs, extended VOD length',
    'preview' => "CREATE TABLE twitch_vod_pulls; user_s3_uploads ADD source, twitch_video_id + status 'pulling'; vod_extensions ADD duration_seconds",
    'up' => function (mysqli $conn) {
        if (!migration_table_exists($conn, 'twitch_vod_pulls')) {
            if (!$conn->query(
                "CREATE TABLE twitch_vod_pulls (
                    id INT NOT NULL AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    twitch_video_id VARCHAR(32) NOT NULL,
                    username VARCHAR(64) NOT NULL,
                    filename VARCHAR(255) NOT NULL,
                    title VARCHAR(255) DEFAULT NULL,
                    status ENUM('queued','pulling','stored','failed') NOT NULL DEFAULT 'queued',
                    error_message TEXT DEFAULT NULL,
                    bytes_sent BIGINT NOT NULL DEFAULT 0,
                    bytes_total BIGINT NOT NULL DEFAULT 0,
                    progress_percent DECIMAL(5,1) NOT NULL DEFAULT 0,
                    owner_host VARCHAR(128) DEFAULT NULL,
                    owner_pid INT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_twitch_vod_pull (user_id, twitch_video_id),
                    KEY idx_twitch_vod_pull_status (status, updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            )) {
                throw new Exception($conn->error);
            }
        }
        if (migration_table_exists($conn, 'user_s3_uploads')) {
            if (!$conn->query(
                "ALTER TABLE user_s3_uploads
                 MODIFY status ENUM('queued','pulling','uploading','done','failed','cancelled') NOT NULL DEFAULT 'queued'"
            )) {
                throw new Exception($conn->error);
            }
            if (!migration_column_exists($conn, 'user_s3_uploads', 'source')) {
                if (!$conn->query(
                    "ALTER TABLE user_s3_uploads
                     ADD COLUMN source ENUM('stream','twitch_vod') NOT NULL DEFAULT 'stream'"
                )) {
                    throw new Exception($conn->error);
                }
            }
            if (!migration_column_exists($conn, 'user_s3_uploads', 'twitch_video_id')) {
                if (!$conn->query(
                    "ALTER TABLE user_s3_uploads
                     ADD COLUMN twitch_video_id VARCHAR(32) DEFAULT NULL,
                     ADD KEY idx_user_s3_twitch (user_id, twitch_video_id)"
                )) {
                    throw new Exception($conn->error);
                }
            }
        }
        if (migration_table_exists($conn, 'vod_extensions') && !migration_column_exists($conn, 'vod_extensions', 'duration_seconds')) {
            if (!$conn->query("ALTER TABLE vod_extensions ADD COLUMN duration_seconds INT UNSIGNED DEFAULT NULL")) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_table_exists($conn, 'vod_extensions') && migration_column_exists($conn, 'vod_extensions', 'duration_seconds')) {
            if (!$conn->query("ALTER TABLE vod_extensions DROP COLUMN duration_seconds")) {
                throw new Exception($conn->error);
            }
        }
        if (migration_table_exists($conn, 'user_s3_uploads')) {
            if (!$conn->query("UPDATE user_s3_uploads SET status = 'queued' WHERE status = 'pulling'")) {
                throw new Exception($conn->error);
            }
            if (migration_column_exists($conn, 'user_s3_uploads', 'twitch_video_id')) {
                if (!$conn->query("ALTER TABLE user_s3_uploads DROP KEY idx_user_s3_twitch, DROP COLUMN twitch_video_id")) {
                    throw new Exception($conn->error);
                }
            }
            if (migration_column_exists($conn, 'user_s3_uploads', 'source')) {
                if (!$conn->query("ALTER TABLE user_s3_uploads DROP COLUMN source")) {
                    throw new Exception($conn->error);
                }
            }
            if (!$conn->query(
                "ALTER TABLE user_s3_uploads
                 MODIFY status ENUM('queued','uploading','done','failed','cancelled') NOT NULL DEFAULT 'queued'"
            )) {
                throw new Exception($conn->error);
            }
        }
        if (migration_table_exists($conn, 'twitch_vod_pulls')) {
            if (!$conn->query('DROP TABLE twitch_vod_pulls')) {
                throw new Exception($conn->error);
            }
        }
    },
];
