<?php
return [
    'description' => 'Linked phones for the Specter Camera app: one row per pairing, holding the hashed device token Cloud Studio can unlink',
    'preview' => "CREATE TABLE camera_devices (owner username, sha256 of the scd_ token, name, camera slug, last_seen_at, revoked_at)",
    'up' => function (mysqli $conn) {
        if (!migration_table_exists($conn, 'camera_devices')) {
            if (!$conn->query(
                "CREATE TABLE camera_devices (
                    id INT NOT NULL AUTO_INCREMENT,
                    username VARCHAR(64) NOT NULL,
                    token_hash CHAR(64) NOT NULL COMMENT 'sha256 hex of the scd_ device token',
                    name VARCHAR(50) DEFAULT NULL,
                    camera VARCHAR(32) NOT NULL,
                    created_at DATETIME NOT NULL COMMENT 'UTC',
                    last_seen_at DATETIME DEFAULT NULL COMMENT 'UTC',
                    revoked_at DATETIME DEFAULT NULL COMMENT 'UTC',
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_camera_device_token (token_hash),
                    KEY idx_camera_device_user (username, revoked_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            )) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_table_exists($conn, 'camera_devices')) {
            if (!$conn->query("DROP TABLE camera_devices")) {
                throw new Exception($conn->error);
            }
        }
    },
];
