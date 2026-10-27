<?php
// Stream settings per user: the resolution (1920x1080 or 1280x720) and frame rate (30 or 60)
// the cloud mixer streams at. Scenes stay on a 1920x1080 canvas and are scaled to fit.
$streamSettingsColumns = [
    ['studio_state', 'stream_resolution', "VARCHAR(9) NOT NULL DEFAULT '1920x1080' AFTER transition_ms"],
    ['studio_state', 'stream_fps', 'TINYINT UNSIGNED NOT NULL DEFAULT 30 AFTER stream_resolution'],
];

return [
    'description' => 'Cloud Studio: stream settings (resolution and frame rate)',
    'preview' => "studio_state: ADD stream_resolution (VARCHAR(9), default '1920x1080'), stream_fps (TINYINT, default 30). No data is changed.",
    'up' => function (mysqli $conn) use ($streamSettingsColumns) {
        foreach ($streamSettingsColumns as [$table, $col, $def]) {
            if (!migration_column_exists($conn, $table, $col)) {
                if (!$conn->query("ALTER TABLE `$table` ADD COLUMN `$col` $def")) throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) use ($streamSettingsColumns) {
        foreach (array_reverse($streamSettingsColumns) as [$table, $col]) {
            if (migration_column_exists($conn, $table, $col)) {
                if (!$conn->query("ALTER TABLE `$table` DROP COLUMN `$col`")) throw new Exception($conn->error);
            }
        }
    },
];
