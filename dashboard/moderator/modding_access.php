<?php
require_once "/var/www/config/db_connect.php";

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

// ...additional logic for settings or mod panel...
?>