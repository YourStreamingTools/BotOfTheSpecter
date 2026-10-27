<?php
// Global sources: one source (settings, position, size, crop) shared by any number of scenes.
// A scene uses one through a sources row with global_id set; the row keeps only its scene and
// its place in the scene's order, everything else comes from global_sources. Deleting a global
// source removes it from every scene (FK cascade).
// user_id takes sources.user_id's exact type (read at run time) so the FK to website.users matches.
$globalSourcesUserType = function (mysqli $conn): string {
    $res = $conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sources' AND COLUMN_NAME = 'user_id'");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) throw new Exception('sources.user_id is missing; run cloud_studio 0002 first');
    $type = strtolower((string) $row['COLUMN_TYPE']);
    if (!preg_match('/^(tiny|small|medium|big)?int(\(\d+\))?( unsigned)?$/', $type)) throw new Exception('Unexpected sources.user_id type: ' . $type);
    return $type;
};

$globalSourcesQuery = function (mysqli $conn, string $sql): void {
    if (!$conn->query($sql)) throw new Exception($conn->error . ' in: ' . $sql);
};

return [
    'description' => 'Cloud Studio: global sources shared by scenes',
    'preview' => 'CREATE TABLE global_sources (FK user_id to website.users, ON DELETE CASCADE); ALTER TABLE sources ADD global_id INT UNSIGNED NULL + FK to global_sources ON DELETE CASCADE. No existing data is changed.',
    'up' => function (mysqli $conn) use ($globalSourcesUserType, $globalSourcesQuery) {
        $type = $globalSourcesUserType($conn);
        $globalSourcesQuery($conn, "CREATE TABLE IF NOT EXISTS global_sources (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id $type NOT NULL,
            type VARCHAR(16) NOT NULL,
            name VARCHAR(64) NOT NULL,
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
            KEY idx_global_sources_user (user_id),
            CONSTRAINT fk_global_sources_user FOREIGN KEY (user_id) REFERENCES website.users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!migration_column_exists($conn, 'sources', 'global_id')) {
            $globalSourcesQuery($conn, "ALTER TABLE sources ADD COLUMN global_id INT UNSIGNED NULL AFTER scene_id,
                ADD KEY idx_sources_global (global_id),
                ADD CONSTRAINT fk_sources_global FOREIGN KEY (global_id) REFERENCES global_sources (id) ON DELETE CASCADE");
        }
    },
    'down' => function (mysqli $conn) use ($globalSourcesQuery) {
        // Scenes keep their own copy of each global source's settings, so dropping the link only
        // removes the sharing. Rows are left as they are.
        if (migration_column_exists($conn, 'sources', 'global_id')) {
            $globalSourcesQuery($conn, "ALTER TABLE sources DROP FOREIGN KEY fk_sources_global, DROP KEY idx_sources_global, DROP COLUMN global_id");
        }
        $globalSourcesQuery($conn, "DROP TABLE IF EXISTS global_sources");
    },
];
