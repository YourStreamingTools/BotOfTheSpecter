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
