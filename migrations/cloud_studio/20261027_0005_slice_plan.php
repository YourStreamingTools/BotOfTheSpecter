<?php
// The stream server plan each slice is on (starter, standard or pro), set by an admin on the
// Cloud Studio admin page and shown to the user on their profile. NULL until one is set.
return [
    'description' => 'Cloud Studio: stream server plan per slice',
    'preview' => 'slices: ADD plan (VARCHAR(16), NULL). No data is changed.',
    'up' => function (mysqli $conn) {
        if (!migration_column_exists($conn, 'slices', 'plan')) {
            if (!$conn->query("ALTER TABLE `slices` ADD COLUMN `plan` VARCHAR(16) NULL DEFAULT NULL AFTER `region`")) throw new Exception($conn->error);
        }
    },
    'down' => function (mysqli $conn) {
        if (migration_column_exists($conn, 'slices', 'plan')) {
            if (!$conn->query("ALTER TABLE `slices` DROP COLUMN `plan`")) throw new Exception($conn->error);
        }
    },
];
