<?php
/**
 * Kick bot token row lives in the website DB (kick.py persists here).
 */

function kick_bot_ensure_table($conn): void {
    if (!$conn) {
        return;
    }
    $conn->query(
        "CREATE TABLE IF NOT EXISTS kick_bot_tokens (
            channel_name VARCHAR(64) NOT NULL,
            kick_username VARCHAR(255) NOT NULL DEFAULT '',
            kick_user_id VARCHAR(64) NOT NULL DEFAULT '',
            chatroom_id VARCHAR(64) NOT NULL DEFAULT '',
            access_token TEXT NOT NULL,
            refresh_token TEXT NOT NULL,
            PRIMARY KEY (channel_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $needed = [
        'kick_username' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'kick_user_id' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'chatroom_id' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'access_token' => "TEXT NOT NULL",
        'refresh_token' => "TEXT NOT NULL",
    ];
    $existing = [];
    $cols = $conn->query("SHOW COLUMNS FROM kick_bot_tokens");
    if ($cols) {
        while ($col = $cols->fetch_assoc()) {
            $existing[strtolower((string)($col['Field'] ?? ''))] = true;
        }
    }
    foreach ($needed as $name => $definition) {
        if (empty($existing[$name])) {
            $conn->query("ALTER TABLE kick_bot_tokens ADD COLUMN `$name` $definition");
        }
    }
}

function kick_bot_is_linked($conn, string $channel): bool {
    $channel = strtolower(trim($channel));
    if ($channel === '' || !$conn) {
        return false;
    }
    kick_bot_ensure_table($conn);
    $stmt = $conn->prepare(
        "SELECT 1 FROM kick_bot_tokens WHERE channel_name = ? AND access_token <> '' LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $channel);
    $stmt->execute();
    $result = $stmt->get_result();
    $linked = $result && $result->fetch_row();
    $stmt->close();
    return (bool)$linked;
}

function kick_bot_pkce_verifier(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function kick_bot_pkce_challenge(string $verifier): string {
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

function kick_bot_save_tokens($conn, string $channel, array $row): bool {
    $channel = strtolower(trim($channel));
    if ($channel === '' || !$conn) {
        return false;
    }
    kick_bot_ensure_table($conn);
    $kickUsername = strtolower(trim((string)($row['kick_username'] ?? '')));
    $kickUserId = trim((string)($row['kick_user_id'] ?? ''));
    $chatroomId = trim((string)($row['chatroom_id'] ?? ''));
    $access = trim((string)($row['access_token'] ?? ''));
    $refresh = trim((string)($row['refresh_token'] ?? ''));
    if ($kickUsername === '' || $kickUserId === '' || $chatroomId === '' || $access === '' || $refresh === '') {
        return false;
    }
    $stmt = $conn->prepare(
        "INSERT INTO kick_bot_tokens (channel_name, kick_username, kick_user_id, chatroom_id, access_token, refresh_token)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            kick_username = VALUES(kick_username),
            kick_user_id = VALUES(kick_user_id),
            chatroom_id = VALUES(chatroom_id),
            access_token = VALUES(access_token),
            refresh_token = VALUES(refresh_token)"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ssssss', $channel, $kickUsername, $kickUserId, $chatroomId, $access, $refresh);
    $ok = $stmt->execute();
    if (!$ok) {
        error_log('[kick_bot] save failed: ' . $stmt->error);
    }
    $stmt->close();
    return $ok;
}

function kick_bot_delete_tokens($conn, string $channel): bool {
    $channel = strtolower(trim($channel));
    if ($channel === '' || !$conn) {
        return false;
    }
    kick_bot_ensure_table($conn);
    $stmt = $conn->prepare("DELETE FROM kick_bot_tokens WHERE channel_name = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $channel);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function kick_bot_get_tokens($conn, string $channel): ?array {
    $channel = strtolower(trim($channel));
    if ($channel === '' || !$conn) {
        return null;
    }
    kick_bot_ensure_table($conn);
    $stmt = $conn->prepare(
        "SELECT kick_username, kick_user_id, chatroom_id, access_token, refresh_token
         FROM kick_bot_tokens WHERE channel_name = ? LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $channel);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return null;
    }
    $kickUsername = strtolower(trim((string)($row['kick_username'] ?? '')));
    $kickUserId = trim((string)($row['kick_user_id'] ?? ''));
    $chatroomId = trim((string)($row['chatroom_id'] ?? ''));
    $access = trim((string)($row['access_token'] ?? ''));
    $refresh = trim((string)($row['refresh_token'] ?? ''));
    if ($kickUsername === '' || $kickUserId === '' || $chatroomId === '' || $access === '' || $refresh === '') {
        return null;
    }
    return [
        'kick_username' => $kickUsername,
        'kick_user_id' => $kickUserId,
        'chatroom_id' => $chatroomId,
        'access_token' => $access,
        'refresh_token' => $refresh,
    ];
}
?>
