<?php
// AJAX endpoint to fetch automated shoutout cooldown data
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Include database connection
require_once "/var/www/config/db_connect.php";
include 'user_db.php';

// Get timezone
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();

// Load automated shoutout cooldown setting
$automated_shoutout_cooldown = 60; // Default
$stmt = $db->prepare("SELECT cooldown_minutes FROM automated_shoutout_settings LIMIT 1");
$stmt->execute();
$stmt->bind_result($automated_shoutout_cooldown);
$stmt->fetch();
$stmt->close();

// Load automated shoutout tracking from the database
$automated_shoutout_tracking = [];
$stmt = $db->prepare("SELECT user_id, user_name, shoutout_time FROM automated_shoutout_tracking ORDER BY shoutout_time DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $shoutout_time = new DateTime($row['shoutout_time'], new DateTimeZone($timezone));
    $now = new DateTime('now', new DateTimeZone($timezone));
    $diff = $now->getTimestamp() - $shoutout_time->getTimestamp();
    $cooldown_seconds = $automated_shoutout_cooldown * 60;
    $remaining_seconds = max(0, $cooldown_seconds - $diff);
    $remaining_minutes = ceil($remaining_seconds / 60);
    $is_expired = $remaining_seconds <= 0;
    $automated_shoutout_tracking[] = [
        'user_id' => $row['user_id'],
        'user_name' => $row['user_name'],
        'shoutout_time' => $shoutout_time->format('Y-m-d H:i:s'),
        'remaining_seconds' => $remaining_seconds,
        'remaining_minutes' => $remaining_minutes,
        'is_expired' => $is_expired
    ];
}
$stmt->close();

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'cooldown_minutes' => $automated_shoutout_cooldown,
    'tracking' => $automated_shoutout_tracking,
    'timezone' => $timezone
]);
