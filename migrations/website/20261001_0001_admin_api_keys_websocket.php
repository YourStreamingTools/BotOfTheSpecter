<?php
return [
    'description' => 'Admin API keys: WebSocket access and global-listener flags',
    'preview' => 'ALTER TABLE admin_api_keys ADD websocket_access, websocket_global',
    'up' => function (mysqli $conn) {
        if (!migration_table_exists($conn, 'admin_api_keys')) {
            return;
        }
        $added = false;
        if (!migration_column_exists($conn, 'admin_api_keys', 'websocket_access')) {
            if (!$conn->query("ALTER TABLE admin_api_keys ADD COLUMN websocket_access TINYINT(1) NOT NULL DEFAULT 0")) {
                throw new Exception($conn->error);
            }
            $added = true;
        }
        if (!migration_column_exists($conn, 'admin_api_keys', 'websocket_global')) {
            if (!$conn->query("ALTER TABLE admin_api_keys ADD COLUMN websocket_global TINYINT(1) NOT NULL DEFAULT 0")) {
                throw new Exception($conn->error);
            }
            $added = true;
        }
        if (!$added) {
            return;
        }
        // Keep current live paths working: super-admin, FreeStuff, GitHub, and global custom webhooks.
        if (!$conn->query("UPDATE admin_api_keys SET websocket_access = 1, websocket_global = 1 WHERE LOWER(service) IN ('admin', 'freestuff', 'github')")) {
            throw new Exception($conn->error);
        }
        if (migration_table_exists($conn, 'custom_webhooks')) {
            $sql = "UPDATE admin_api_keys k INNER JOIN custom_webhooks w ON LOWER(w.service) = LOWER(k.service) "
                . "SET k.websocket_access = 1, k.websocket_global = 1 WHERE w.scope IN ('global', 'discord_logs')";
            if (!$conn->query($sql)) {
                throw new Exception($conn->error);
            }
        }
    },
    'down' => function (mysqli $conn) {
        if (!migration_table_exists($conn, 'admin_api_keys')) {
            return;
        }
        if (migration_column_exists($conn, 'admin_api_keys', 'websocket_global')) {
            if (!$conn->query("ALTER TABLE admin_api_keys DROP COLUMN websocket_global")) {
                throw new Exception($conn->error);
            }
        }
        if (migration_column_exists($conn, 'admin_api_keys', 'websocket_access')) {
            if (!$conn->query("ALTER TABLE admin_api_keys DROP COLUMN websocket_access")) {
                throw new Exception($conn->error);
            }
        }
    },
];
