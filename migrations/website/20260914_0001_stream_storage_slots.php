<?php
return [
    'description' => 'Sydney stream host: 5 opted-in users, 100GB each, bonus GB later',
    'preview' => 'CREATE TABLE stream_storage_slots (max 5 rows; quota_bytes 100GB + bonus_bytes)',
    'up' => function (mysqli $conn) {
        if (!migration_table_exists($conn, 'stream_storage_slots')) {
            if (!$conn->query(
                "CREATE TABLE stream_storage_slots (
                    user_id INT NOT NULL,
                    quota_bytes BIGINT UNSIGNED NOT NULL DEFAULT 107374182400,
                    bonus_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            )) {
                throw new Exception($conn->error);
            }
        }
        $trig = $conn->query("SHOW TRIGGERS WHERE `Trigger` = 'stream_storage_slots_cap'");
        if ($trig && $trig->num_rows === 0) {
            if (!$conn->query(
                "CREATE TRIGGER stream_storage_slots_cap
                 BEFORE INSERT ON stream_storage_slots
                 FOR EACH ROW
                 BEGIN
                     IF (SELECT COUNT(*) FROM stream_storage_slots) >= 5 THEN
                         SIGNAL SQLSTATE '45000'
                             SET MESSAGE_TEXT = 'Stream storage is limited to 5 users due to limited storage space';
                     END IF;
                 END"
            )) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        $conn->query("DROP TRIGGER IF EXISTS stream_storage_slots_cap");
        if (migration_table_exists($conn, 'stream_storage_slots')) {
            if (!$conn->query('DROP TABLE stream_storage_slots')) {
                throw new Exception($conn->error);
            }
        }
    },
];
