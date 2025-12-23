<?php
// Prevent accidental use if user_db.php was included first
if (isset($db)) {
    unset($db);
}

require_once "/var/www/config/db_connect.php";

// Bot ID check - always give the bot access to all users
$botId = "971436498";
if (isset($twitchUserId) && $twitchUserId === $botId) {
    $query = "SELECT u.twitch_display_name, u.profile_image, u.twitch_user_id FROM users u";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $modChannels = $result->fetch_all(MYSQLI_ASSOC);
} else {
    $query = "SELECT COUNT(*) AS access_count FROM moderator_access WHERE moderator_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $twitchUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $query = "SELECT u.twitch_display_name, u.profile_image, u.twitch_user_id FROM moderator_access ma JOIN users u ON ma.broadcaster_id = u.twitch_user_id WHERE ma.moderator_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $twitchUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $modChannels = $result->fetch_all(MYSQLI_ASSOC);
}
?>
