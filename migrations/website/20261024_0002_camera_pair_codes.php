<?php
return [
    'description' => 'Camera pairing: one-time codes Cloud Studio shows as a QR for the Specter Camera app',
    'preview' => "CREATE TABLE camera_pair_codes (sha256 of the code, owner username, 5 minute expiry, redeemed_at)",
    'up' => function (mysqli $conn) {
        if (!migration_table_exists($conn, 'camera_pair_codes')) {
            if (!$conn->query(
                "CREATE TABLE camera_pair_codes (
                    id INT NOT NULL AUTO_INCREMENT,
                    code_hash CHAR(64) NOT NULL COMMENT 'sha256 hex of the 8 character code',
                    username VARCHAR(64) NOT NULL,
                    camera_name VARCHAR(50) DEFAULT NULL,
                    device_name VARCHAR(50) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME NOT NULL COMMENT 'UTC',
                    redeemed_at DATETIME DEFAULT NULL COMMENT 'UTC',
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_camera_pair_code (code_hash),
                    KEY idx_camera_pair_user (username)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            )) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_table_exists($conn, 'camera_pair_codes')) {
            if (!$conn->query("DROP TABLE camera_pair_codes")) {
                throw new Exception($conn->error);
            }
        }
    },
];
