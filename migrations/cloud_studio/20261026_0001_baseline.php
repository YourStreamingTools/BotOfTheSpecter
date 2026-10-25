<?php
// Cloud Studio (studio.botofthespecter.com): scenes, sources, on-air state, uploads and streaming slices.
// Same schema as the Cloud Studio repo's old server/sql/cloud_studio.sql plus its three upgrade files,
// so an older hand-made copy is brought up to date instead of being recreated.
return [
    'description' => 'Baseline: Cloud Studio tables (scenes, sources, studio_state, uploads, slices)',
    'preview' => "CREATE TABLE scenes, sources (FK to scenes, ON DELETE CASCADE), studio_state, uploads, slices; on an older copy, add the missing studio_state transition/Studio Mode columns and the slices installer columns + uq_slices_install",
    'up' => function (mysqli $conn) {
        $tables = [
            'scenes' => "CREATE TABLE scenes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(64) NOT NULL,
                name VARCHAR(64) NOT NULL,
                position INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_scenes_user (username, position)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // Sources are drawn back to front by ascending position.
            'sources' => "CREATE TABLE sources (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                scene_id INT UNSIGNED NOT NULL,
                username VARCHAR(64) NOT NULL,
                type VARCHAR(16) NOT NULL,
                name VARCHAR(64) NOT NULL,
                position INT NOT NULL DEFAULT 0,
                visible TINYINT(1) NOT NULL DEFAULT 1,
                locked TINYINT(1) NOT NULL DEFAULT 0,
                x INT NOT NULL DEFAULT 0,
                y INT NOT NULL DEFAULT 0,
                width INT NOT NULL DEFAULT 1920,
                height INT NOT NULL DEFAULT 1080,
                crop_left INT NOT NULL DEFAULT 0,
                crop_top INT NOT NULL DEFAULT 0,
                crop_right INT NOT NULL DEFAULT 0,
                crop_bottom INT NOT NULL DEFAULT 0,
                settings JSON NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sources_scene (scene_id, position),
                KEY idx_sources_user (username),
                CONSTRAINT fk_sources_scene FOREIGN KEY (scene_id) REFERENCES scenes (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // Which scene is on air for each user, and how scene switches look.
            // Studio Mode: the output shows program_json (a frozen copy of a scene) until the next take.
            'studio_state' => "CREATE TABLE studio_state (
                username VARCHAR(64) PRIMARY KEY,
                active_scene_id INT UNSIGNED NULL,
                transition VARCHAR(8) NOT NULL DEFAULT 'fade',
                transition_ms INT NOT NULL DEFAULT 300,
                studio_mode TINYINT(1) NOT NULL DEFAULT 0,
                program_json MEDIUMTEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'uploads' => "CREATE TABLE uploads (
                id CHAR(32) PRIMARY KEY,
                username VARCHAR(64) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime VARCHAR(32) NOT NULL,
                size INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_uploads_user (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // Streaming slices: one dedicated server per paying user, registered in the admin area.
            // secret is shared with the slice agent (SLICE_SECRET) to sign requests to it.
            // install_token_hash: one-hour install command (sha256 of its token), cleared on the first good check-in.
            'slices' => "CREATE TABLE slices (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(64) NOT NULL,
                hostname VARCHAR(255) NOT NULL,
                region VARCHAR(16) NOT NULL,
                ip VARCHAR(45) NULL,
                notes VARCHAR(255) NOT NULL DEFAULT '',
                secret CHAR(64) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                install_token_hash CHAR(64) NULL,
                install_expires_at DATETIME NULL,
                last_checkin_at DATETIME NULL,
                last_checkin_ok TINYINT(1) NULL,
                last_checkin_error VARCHAR(64) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_slices_user (username),
                UNIQUE KEY uq_slices_host (hostname),
                UNIQUE KEY uq_slices_install (install_token_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($tables as $table => $sql) {
            if (!migration_table_exists($conn, $table)) {
                if (!$conn->query($sql)) throw new Exception($conn->error);
            }
        }
        // Columns added to Cloud Studio after its first release (its old upgrade-*.sql files).
        $columns = [
            ['studio_state', 'transition', "VARCHAR(8) NOT NULL DEFAULT 'fade' AFTER active_scene_id"],
            ['studio_state', 'transition_ms', "INT NOT NULL DEFAULT 300 AFTER transition"],
            ['studio_state', 'studio_mode', "TINYINT(1) NOT NULL DEFAULT 0 AFTER transition_ms"],
            ['studio_state', 'program_json', "MEDIUMTEXT NULL AFTER studio_mode"],
            ['slices', 'install_token_hash', "CHAR(64) NULL AFTER enabled"],
            ['slices', 'install_expires_at', "DATETIME NULL AFTER install_token_hash"],
            ['slices', 'last_checkin_at', "DATETIME NULL AFTER install_expires_at"],
            ['slices', 'last_checkin_ok', "TINYINT(1) NULL AFTER last_checkin_at"],
            ['slices', 'last_checkin_error', "VARCHAR(64) NULL AFTER last_checkin_ok"],
        ];
        foreach ($columns as [$table, $col, $def]) {
            if (!migration_column_exists($conn, $table, $col)) {
                if (!$conn->query("ALTER TABLE `$table` ADD COLUMN `$col` $def")) throw new Exception($conn->error);
            }
        }
        if (!migration_index_exists($conn, 'slices', 'uq_slices_install')) {
            if (!$conn->query("ALTER TABLE slices ADD UNIQUE KEY uq_slices_install (install_token_hash)")) throw new Exception($conn->error);
        }
    },
    'down' => function (mysqli $conn) {
        foreach (['sources', 'scenes', 'studio_state', 'uploads', 'slices'] as $table) {
            if (migration_table_exists($conn, $table)) {
                if (!$conn->query("DROP TABLE `$table`")) throw new Exception($conn->error);
            }
        }
    },
];
