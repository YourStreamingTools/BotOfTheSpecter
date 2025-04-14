<?php
require_once "/var/www/config/db_connect.php";

// Check if user is authenticated
if (!isset($_SESSION['access_token'])) {
    header('Location: ../../login.php');
    exit();
}
$mod_user = $_SESSION['twitchUserId'];

// Check if broadcaster_id is provided in the query
if (!isset($_GET['broadcaster_id'])) {
    die("Error: Broadcaster ID not provided.");
}

$broadcasterId = $_GET['broadcaster_id'];

// Fetch broadcaster information from the database
$query = "SELECT twitch_display_name, profile_image FROM users WHERE twitch_user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $broadcasterId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Error: Broadcaster not found.");
}

$broadcasterInfo = $result->fetch_assoc();

// Example: Use the fetched data
$broadcasterName = $broadcasterInfo['twitch_display_name'];
$broadcasterImage = $broadcasterInfo['profile_image'];
$twitchDisplayName = $broadcasterName;
$twitch_profile_image_url = $broadcasterImage;

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
?>