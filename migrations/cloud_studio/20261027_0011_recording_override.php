<?php
// Per-account overrides set by an admin. recording_disabled stops the stream server from recording
// that account's streams at all (for accounts that stream 24/7). Keyed by website.users.id like the
// other studio tables; user_id takes website.users.id's exact type so the cross-database FK is accepted.
$overrideUsersIdType = function (mysqli $conn): string {
    $res = $conn->query("SELECT c.COLUMN_TYPE FROM information_schema.COLUMNS c
        WHERE c.TABLE_SCHEMA = 'website' AND c.TABLE_NAME = 'users' AND c.COLUMN_NAME = 'id'");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) throw new Exception('Cannot read website.users.id (the migration user needs SELECT on website.users)');
    $type = strtolower((string) $row['COLUMN_TYPE']);
    if (!preg_match('/^(tiny|small|medium|big)?int(\(\d+\))?( unsigned)?$/', $type)) throw new Exception('Unexpected website.users.id type: ' . $type);
    return $type;
};

return [
    'description' => 'Cloud Studio: admin account overrides (recording off)',
    'preview' => 'CREATE TABLE account_overrides (user_id PK, FK to website.users ON DELETE CASCADE, recording_disabled TINYINT(1) NOT NULL DEFAULT 0). No existing data is changed.',
    'up' => function (mysqli $conn) use ($overrideUsersIdType) {
        $type = $overrideUsersIdType($conn);
        $exists = $conn->query("SHOW TABLES LIKE 'account_overrides'");
        if ($exists && $exists->num_rows > 0) return;
        $sql = "CREATE TABLE account_overrides (
            user_id $type NOT NULL PRIMARY KEY,
            recording_disabled TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_account_overrides_user FOREIGN KEY (user_id) REFERENCES website.users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$conn->query($sql)) throw new Exception($conn->error);
    },
    'down' => function (mysqli $conn) {
        if (!$conn->query("DROP TABLE IF EXISTS account_overrides")) throw new Exception($conn->error);
    },
];
