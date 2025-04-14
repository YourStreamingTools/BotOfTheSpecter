<?php
require_once "/var/www/config/db_connect.php";

// Check if user is authenticated
if (!isset($_SESSION['access_token'])) {
    header('Location: ../../logout.php');
    exit();
}
$mod_user = $_SESSION['twitchUserId'];

// Check if broadcaster_id is provided in the query
if (!isset($_GET['broadcaster_id'])) {
    die("Error: Broadcaster ID not provided.");
}
$broadcasterId = $_GET['broadcaster_id'];

// Validate the broadcaster_id
$query = "SELECT COUNT(*) AS valid_count FROM users WHERE twitch_user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $broadcasterId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
if ($row['valid_count'] > 0) {
    // Store the broadcaster_id in the session
    $_SESSION['broadcaster_id'] = $broadcasterId;
} else {
    die("Access Denied: Invalid broadcaster ID.");
}

// Bot ID check - always give the bot access to all broadcasters
$botId = "971436498";
if ($mod_user === $botId) {
    // Bot always has access, no need to check moderator access
} else {
    // Validate if the user is a moderator for the broadcaster
    $query = "SELECT COUNT(*) AS mod_count FROM moderator_access WHERE moderator_id = ? AND broadcaster_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $mod_user, $broadcasterId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if ($row['mod_count'] === 0) {
        die("Access Denied: You do not have moderator access for this broadcaster.");
    }
}

// Fetch broadcaster information from the database
$query = "SELECT twitch_display_name, profile_image, username FROM users WHERE twitch_user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $broadcasterId);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) { die("Error: Broadcaster not found."); }
$broadcasterInfo = $result->fetch_assoc();

$broadcasterName = $broadcasterInfo['twitch_display_name'];
$broadcasterImage = $broadcasterInfo['profile_image'];
$broadcasterUsername = $broadcasterInfo['username'];
$_SESSION['editing_user'] = $broadcasterId;
$_SESSION['editing_username'] = $broadcasterUsername;
$_SESSION['editing_display_name'] = $broadcasterName;
$twitchDisplayName = $broadcasterName;
$twitch_profile_image_url = $broadcasterImage;
?>