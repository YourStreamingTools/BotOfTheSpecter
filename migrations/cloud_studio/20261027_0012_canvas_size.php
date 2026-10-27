<?php
// The scene canvas each user's layout is saved at. It follows the plan's highest output
// resolution (1280x720 on 720p-only plans, else 1920x1080); the studio rescales a user's
// sources once when it changes. NULL means the original 1920x1080.
return [
    'description' => 'Cloud Studio: per-user scene canvas size',
    'preview' => 'ALTER TABLE studio_state ADD canvas_width SMALLINT UNSIGNED NULL, ADD canvas_height SMALLINT UNSIGNED NULL. No existing data is changed (NULL = 1920x1080).',
    'up' => function (mysqli $conn) {
        $has = $conn->query("SHOW COLUMNS FROM studio_state LIKE 'canvas_width'");
        if ($has && $has->num_rows > 0) return;
        if (!$conn->query("ALTER TABLE studio_state ADD COLUMN canvas_width SMALLINT UNSIGNED NULL, ADD COLUMN canvas_height SMALLINT UNSIGNED NULL")) throw new Exception($conn->error);
    },
    'down' => function (mysqli $conn) {
        if (!$conn->query("ALTER TABLE studio_state DROP COLUMN canvas_width, DROP COLUMN canvas_height")) throw new Exception($conn->error);
    },
];
