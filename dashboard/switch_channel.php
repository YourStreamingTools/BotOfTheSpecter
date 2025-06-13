<?php
session_start();
require_once "/var/www/config/db_connect.php";

if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

if (!isset($_GET['user_id'])) {
    header('Location: bot.php');
    exit();
}

$targetUserId = $_GET['user_id'];

// Fetch user info for the selected channel
$stmt = $conn->prepare("SELECT username, twitch_display_name, profile_image, twitch_user_id, access_token, refresh_token, api_key FROM users WHERE twitch_user_id = ?");
$stmt->bind_param("s", $targetUserId);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    // Set session variables for mod context (for moderator dashboard compatibility)
    $_SESSION['editing_user'] = $row['twitch_user_id'];
    $_SESSION['editing_username'] = $row['username'];
    $_SESSION['editing_display_name'] = $row['twitch_display_name'];
    $_SESSION['editing_profile_image'] = $row['profile_image'];
    $_SESSION['editing_access_token'] = $row['access_token'];
    $_SESSION['editing_refresh_token'] = $row['refresh_token'];
    $_SESSION['editing_api_key'] = $row['api_key'];

    // Redirect to dashboard (bot.php or any page)
    header('Location: moderator/index.php');
    exit();
} else {
    // Invalid user/channel
    header('Location: bot.php');
    exit();
}
?>
