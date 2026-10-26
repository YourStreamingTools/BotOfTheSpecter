<?php
// Key Cloud Studio rows by website.users.id instead of username, so a Twitch rename keeps the user's studio.
// user_id takes website.users.id's exact type (read at run time) so the cross-database FK is accepted.
// Rows whose username has no users row are deleted. The FKs cascade, so deleting a user (whose id
// callback.php can later hand to a new account) also removes their studio.
$cloudStudioTables = [
    // table => [column placement, old username indexes to drop, new user_id index]
    'scenes'       => ['AFTER id', ['idx_scenes_user'], 'ADD KEY idx_scenes_user (user_id, position)', 'ADD KEY idx_scenes_user (username, position)'],
    'sources'      => ['AFTER id', ['idx_sources_user'], 'ADD KEY idx_sources_user (user_id)', 'ADD KEY idx_sources_user (username)'],
    'uploads'      => ['AFTER id', ['idx_uploads_user'], 'ADD KEY idx_uploads_user (user_id)', 'ADD KEY idx_uploads_user (username)'],
    'studio_state' => ['FIRST', [], 'ADD PRIMARY KEY (user_id)', 'ADD PRIMARY KEY (username)'],
    'slices'       => ['AFTER id', ['uq_slices_user'], 'ADD UNIQUE KEY uq_slices_user (user_id)', 'ADD UNIQUE KEY uq_slices_user (username)'],
];

// Closures, not functions: the runner includes every migration file on each scan.
$usersIdType = function (mysqli $conn): string {
    $res = $conn->query("SELECT c.COLUMN_TYPE, t.ENGINE FROM information_schema.COLUMNS c
        JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
        WHERE c.TABLE_SCHEMA = 'website' AND c.TABLE_NAME = 'users' AND c.COLUMN_NAME = 'id'");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) throw new Exception('Cannot read website.users.id (the migration user needs SELECT on website.users)');
    if (strcasecmp((string) $row['ENGINE'], 'InnoDB') !== 0) throw new Exception('website.users must be InnoDB for a foreign key, it is ' . $row['ENGINE']);
    $type = strtolower((string) $row['COLUMN_TYPE']);
    if (!preg_match('/^(tiny|small|medium|big)?int(\(\d+\))?( unsigned)?$/', $type)) throw new Exception('Unexpected website.users.id type: ' . $type);
    return $type;
};

$fkExists = function (mysqli $conn, string $table, string $name): bool {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
    $stmt->bind_param('ss', $table, $name); $stmt->execute();
    $r = $stmt->get_result()->num_rows > 0; $stmt->close(); return $r;
};

$q = function (mysqli $conn, string $sql): void {
    if (!$conn->query($sql)) throw new Exception($conn->error . ' in: ' . $sql);
};

return [
    'description' => 'Cloud Studio: key every table by website.users.id (user_id + FK, ON DELETE CASCADE) instead of username',
    'preview' => "scenes, sources, uploads, studio_state, slices: ADD user_id (website.users.id type), fill it from website.users by username, DELETE rows with no matching user, ADD FK fk_<table>_user ON DELETE CASCADE, DROP username (indexes rebuilt on user_id; studio_state PK becomes user_id)",
    'destructive' => true,
    'up' => function (mysqli $conn) use ($cloudStudioTables, $usersIdType, $fkExists, $q) {
        $type = $usersIdType($conn);
        foreach ($cloudStudioTables as $table => [$place, $oldIndexes, $newIndex]) {
            if (!migration_column_exists($conn, $table, 'user_id')) {
                $q($conn, "ALTER TABLE `$table` ADD COLUMN user_id $type NULL $place");
            }
            if (migration_column_exists($conn, $table, 'username')) {
                // website.users may use another collation than cloud_studio, so compare in one collation.
                $q($conn, "UPDATE `$table` t JOIN website.users u
                    ON CONVERT(u.username USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(t.username USING utf8mb4) COLLATE utf8mb4_unicode_ci
                    SET t.user_id = u.id WHERE t.user_id IS NULL");
                $q($conn, "DELETE FROM `$table` WHERE user_id IS NULL");
                // One row per user where the key is unique (two spellings of one name would both match).
                if ($table === 'slices') $q($conn, "DELETE a FROM slices a JOIN slices b ON a.user_id = b.user_id AND a.id < b.id");
                if ($table === 'studio_state') $q($conn, "DELETE a FROM studio_state a JOIN studio_state b ON a.user_id = b.user_id AND a.username < b.username");
                foreach ($oldIndexes as $index) {
                    if (migration_index_exists($conn, $table, $index)) $q($conn, "ALTER TABLE `$table` DROP INDEX `$index`");
                }
                $q($conn, "ALTER TABLE `$table` MODIFY user_id $type NOT NULL" . ($table === 'studio_state' ? ', DROP PRIMARY KEY' : ''));
                $q($conn, "ALTER TABLE `$table` DROP COLUMN username");
            }
            $q($conn, "ALTER TABLE `$table` MODIFY user_id $type NOT NULL");
            $indexName = $table === 'studio_state' ? 'PRIMARY' : preg_replace('/^ADD (UNIQUE )?KEY (\w+).*$/', '$2', $newIndex);
            if (!migration_index_exists($conn, $table, $indexName)) $q($conn, "ALTER TABLE `$table` $newIndex");
            if (!$fkExists($conn, $table, "fk_{$table}_user")) {
                $q($conn, "ALTER TABLE `$table` ADD CONSTRAINT fk_{$table}_user FOREIGN KEY (user_id) REFERENCES website.users (id) ON DELETE CASCADE");
            }
        }
    },
    'down' => function (mysqli $conn) use ($cloudStudioTables, $usersIdType, $fkExists, $q) {
        foreach ($cloudStudioTables as $table => [$place, $oldIndexes, $newIndex, $oldIndex]) {
            if (!migration_column_exists($conn, $table, 'user_id')) continue;
            if (!migration_column_exists($conn, $table, 'username')) {
                $q($conn, "ALTER TABLE `$table` ADD COLUMN username VARCHAR(64) NULL AFTER user_id");
            }
            $q($conn, "UPDATE `$table` t JOIN website.users u ON u.id = t.user_id SET t.username = u.username");
            $q($conn, "DELETE FROM `$table` WHERE username IS NULL");
            if ($fkExists($conn, $table, "fk_{$table}_user")) $q($conn, "ALTER TABLE `$table` DROP FOREIGN KEY fk_{$table}_user");
            if ($table === 'studio_state') {
                $q($conn, "ALTER TABLE studio_state MODIFY username VARCHAR(64) NOT NULL, DROP PRIMARY KEY, ADD PRIMARY KEY (username)");
            } else {
                $indexName = preg_replace('/^ADD (UNIQUE )?KEY (\w+).*$/', '$2', $newIndex);
                if (migration_index_exists($conn, $table, $indexName)) $q($conn, "ALTER TABLE `$table` DROP INDEX `$indexName`");
                $q($conn, "ALTER TABLE `$table` MODIFY username VARCHAR(64) NOT NULL, $oldIndex");
            }
            $q($conn, "ALTER TABLE `$table` DROP COLUMN user_id");
        }
    },
];
