<?php
return [
    'description' => 'Add discord_users.time_now_channel_id for Time Now voice channel updates',
    'preview' => "ALTER TABLE discord_users ADD COLUMN time_now_channel_id VARCHAR(255) NULL DEFAULT NULL (if missing)",
    'up' => function (mysqli $conn) {
        if (!migration_column_exists($conn, 'discord_users', 'time_now_channel_id')) {
            if (!$conn->query("ALTER TABLE discord_users ADD COLUMN time_now_channel_id VARCHAR(255) NULL DEFAULT NULL AFTER live_channel_id")) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_column_exists($conn, 'discord_users', 'time_now_channel_id')) {
            if (!$conn->query("ALTER TABLE discord_users DROP COLUMN time_now_channel_id")) {
                throw new Exception($conn->error);
            }
        }
    },
];