<?php
// Camera fallback: a user marks one scene as their Fallback scene, and any scene can switch the
// stream to it while one of its stream cameras has lost its signal (IRL on weak WiFi/LTE).
// studio_state.fallback_return_json remembers the scene (and Studio Mode program) to go back to.
$sceneFallbackColumns = [
    ['scenes', 'is_fallback', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER position'],
    ['scenes', 'fallback_on_camera_loss', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_fallback'],
    ['studio_state', 'fallback_return_json', 'MEDIUMTEXT NULL AFTER program_json'],
];

return [
    'description' => 'Cloud Studio: Fallback scene, switched to when a scene\'s camera signal is lost',
    'preview' => 'scenes: ADD is_fallback, fallback_on_camera_loss (TINYINT, default 0); studio_state: ADD fallback_return_json (MEDIUMTEXT NULL). No data is changed.',
    'up' => function (mysqli $conn) use ($sceneFallbackColumns) {
        foreach ($sceneFallbackColumns as [$table, $col, $def]) {
            if (!migration_column_exists($conn, $table, $col)) {
                if (!$conn->query("ALTER TABLE `$table` ADD COLUMN `$col` $def")) throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) use ($sceneFallbackColumns) {
        foreach (array_reverse($sceneFallbackColumns) as [$table, $col]) {
            if (migration_column_exists($conn, $table, $col)) {
                if (!$conn->query("ALTER TABLE `$table` DROP COLUMN `$col`")) throw new Exception($conn->error);
            }
        }
    },
];
