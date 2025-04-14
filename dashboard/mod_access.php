<?php
require_once "/var/www/config/db_connect.php";

// Check if $twitchUserId is set
if (!isset($twitchUserId)) {
    die("Access Denied: User ID not provided.");
}

// Prepare and execute the query to check access
$query = "SELECT COUNT(*) AS access_count FROM moderator_access WHERE moderator_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $twitchUserId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

// Fetch channels the user can moderate
$query = "SELECT u.twitch_display_name, u.profile_image, u.twitch_user_id FROM moderator_access ma JOIN users u ON ma.broadcaster_id = u.twitch_user_id WHERE ma.moderator_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $twitchUserId);
$stmt->execute();
$result = $stmt->get_result();
$modChannels = $result->fetch_all(MYSQLI_ASSOC);

// Only display dropdown if there are channels to moderate
$showModDropdown = !empty($modChannels);
?>