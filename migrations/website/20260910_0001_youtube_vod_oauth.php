<?php
return [
    'description' => 'YouTube user OAuth tokens and VOD upload queue (website)',
    'preview' => "CREATE TABLE youtube_tokens (one channel per Specter user) and youtube_vod_uploads (upload jobs)",
    'up' => function (mysqli $conn) {
        if (!migration_table_exists($conn, 'youtube_tokens')) {
            if (!$conn->query(
                "CREATE TABLE youtube_tokens (
                    id INT NOT NULL AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    channel_id VARCHAR(64) NOT NULL,
                    channel_title VARCHAR(255) DEFAULT NULL,
                    channel_custom_url VARCHAR(255) DEFAULT NULL,
                    channel_thumbnail TEXT DEFAULT NULL,
                    access_token TEXT NOT NULL,
                    refresh_token TEXT NOT NULL,
                    token_expiry DATETIME DEFAULT NULL,
                    granted_scopes TEXT DEFAULT NULL,
                    can_upload TINYINT(1) NOT NULL DEFAULT 0,
                    auto_upload TINYINT(1) NOT NULL DEFAULT 0,
                    privacy_status ENUM('private','unlisted','public') NOT NULL DEFAULT 'private',
                    needs_reauth TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_youtube_user (user_id),
                    KEY idx_youtube_channel (channel_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            )) {
                throw new Exception($conn->error);
            }
        }
        if (!migration_table_exists($conn, 'youtube_vod_uploads')) {
            if (!$conn->query(
                "CREATE TABLE youtube_vod_uploads (
                    id INT NOT NULL AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    filename VARCHAR(255) NOT NULL,
                    title VARCHAR(255) DEFAULT NULL,
                    privacy_status VARCHAR(16) NOT NULL DEFAULT 'private',
                    youtube_video_id VARCHAR(32) DEFAULT NULL,
                    status ENUM('queued','uploading','done','failed','cancelled') NOT NULL DEFAULT 'queued',
                    error_message TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_youtube_upload_user (user_id),
                    KEY idx_youtube_upload_status (status),
                    KEY idx_youtube_upload_file (user_id, filename)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            )) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_table_exists($conn, 'youtube_vod_uploads')) {
            if (!$conn->query('DROP TABLE youtube_vod_uploads')) {
                throw new Exception($conn->error);
            }
        }
        if (migration_table_exists($conn, 'youtube_tokens')) {
            if (!$conn->query('DROP TABLE youtube_tokens')) {
                throw new Exception($conn->error);
            }
        }
    },
];
