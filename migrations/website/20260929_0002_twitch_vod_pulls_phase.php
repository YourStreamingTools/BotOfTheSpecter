<?php
return [
    'description' => 'Twitch VOD pulls: phase (downloading / saving)',
    'preview' => 'ALTER TABLE twitch_vod_pulls ADD phase',
    'up' => function (mysqli $conn) {
        if (migration_table_exists($conn, 'twitch_vod_pulls') && !migration_column_exists($conn, 'twitch_vod_pulls', 'phase')) {
            if (!$conn->query("ALTER TABLE twitch_vod_pulls ADD COLUMN phase VARCHAR(16) DEFAULT NULL AFTER status")) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_table_exists($conn, 'twitch_vod_pulls') && migration_column_exists($conn, 'twitch_vod_pulls', 'phase')) {
            if (!$conn->query("ALTER TABLE twitch_vod_pulls DROP COLUMN phase")) {
                throw new Exception($conn->error);
            }
        }
    },
];
