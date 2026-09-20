<?php
return [
    'description' => 'User-owned S3-compatible bucket settings and VOD copy jobs',
    'preview' => 'CREATE TABLE user_s3_settings, user_s3_uploads',
    'up' => function (mysqli $conn) {
        if (!migration_table_exists($conn, 'user_s3_settings')) {
            if (!$conn->query(
                "CREATE TABLE user_s3_settings (
                    id INT NOT NULL AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    endpoint VARCHAR(512) NOT NULL,
                    region VARCHAR(64) NOT NULL DEFAULT '',
                    bucket VARCHAR(255) NOT NULL,
                    prefix VARCHAR(255) NOT NULL DEFAULT '',
                    access_key VARCHAR(255) NOT NULL,
                    secret_key TEXT NOT NULL,
                    path_style TINYINT(1) NOT NULL DEFAULT 1,
                    auto_copy TINYINT(1) NOT NULL DEFAULT 0,
                    last_error TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_user_s3_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            )) {
                throw new Exception($conn->error);
            }
        }
        if (!migration_table_exists($conn, 'user_s3_uploads')) {
            if (!$conn->query(
                "CREATE TABLE user_s3_uploads (
                    id INT NOT NULL AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    filename VARCHAR(255) NOT NULL,
                    title VARCHAR(255) DEFAULT NULL,
                    object_key VARCHAR(512) DEFAULT NULL,
                    status ENUM('queued','uploading','done','failed','cancelled') NOT NULL DEFAULT 'queued',
                    error_message TEXT DEFAULT NULL,
                    bytes_sent BIGINT NOT NULL DEFAULT 0,
                    bytes_total BIGINT NOT NULL DEFAULT 0,
                    progress_percent DECIMAL(5,1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_user_s3_user (user_id),
                    KEY idx_user_s3_status (status),
                    KEY idx_user_s3_file (user_id, filename)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            )) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_table_exists($conn, 'user_s3_uploads')) {
            if (!$conn->query('DROP TABLE user_s3_uploads')) {
                throw new Exception($conn->error);
            }
        }
        if (migration_table_exists($conn, 'user_s3_settings')) {
            if (!$conn->query('DROP TABLE user_s3_settings')) {
                throw new Exception($conn->error);
            }
        }
    },
];
