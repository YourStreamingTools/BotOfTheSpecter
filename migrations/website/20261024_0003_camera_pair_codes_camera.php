<?php
return [
    'description' => 'Add camera_pair_codes.camera (camera slug used in the stream key ?cam=, for multiple cameras per user)',
    'preview' => "ALTER TABLE camera_pair_codes ADD COLUMN camera VARCHAR(32) NULL DEFAULT NULL AFTER camera_name (if missing)",
    'up' => function (mysqli $conn) {
        if (!migration_column_exists($conn, 'camera_pair_codes', 'camera')) {
            if (!$conn->query(
                "ALTER TABLE camera_pair_codes ADD COLUMN camera VARCHAR(32) NULL DEFAULT NULL "
                . "COMMENT 'Stream key camera slug; NULL until redeem picks it from the device name' AFTER camera_name"
            )) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_column_exists($conn, 'camera_pair_codes', 'camera')) {
            if (!$conn->query("ALTER TABLE camera_pair_codes DROP COLUMN camera")) {
                throw new Exception($conn->error);
            }
        }
    },
];
