<?php
// Check Twitch subscription tier for current user
// This page queries the Twitch API using the broadcaster's token to check if a user is subscribed

ob_start();
session_start();
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/twitch.php";
require_once "userdata.php";
// Note: session_write_close() deferred - this file writes $_SESSION['tier'] at the end

function cs_json_response($status, $payload) {
    if (ob_get_length() !== false) { ob_clean(); }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
}

// Ensure user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['twitchUserId'])) {
    cs_json_response(401, ['error' => 'Not authenticated']);
    exit;
}
$userTwitchId = $_SESSION['twitchUserId'];
$broadcasterId = '140296994'; // Your Twitch ID
// Get the broadcaster's access token from twitch_bot_access database
$stmt = $conn->prepare("SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = ?");
if (!$stmt) {
    cs_json_response(500, ['error' => 'Database query preparation failed']);
    exit;
}
$stmt->bind_param("s", $broadcasterId);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    cs_json_response(500, ['error' => 'Broadcaster token not found']);
    $stmt->close();
    exit;
}
$row = $result->fetch_assoc();
$broadcasterToken = $row['twitch_access_token'];
$stmt->close();
// Call Twitch API to check subscription
$url = "https://api.twitch.tv/helix/subscriptions?broadcaster_id=" . urlencode($broadcasterId) . "&user_id=" . urlencode($userTwitchId);
$headers = [
    'Authorization: Bearer ' . $broadcasterToken,
    'Client-ID: ' . $clientID
];
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($response === false || $httpCode !== 200) {
    error_log("Twitch subscription API failed. HTTP Code: $httpCode, Response: $response");
    cs_json_response($httpCode ?: 500, ['error' => 'Twitch API request failed', 'http_code' => $httpCode]);
    exit;
}
$data = json_decode($response, true);
// Check if user has a subscription
if (isset($data['data']) && count($data['data']) > 0) {
    $subscription = $data['data'][0];
    $tier = $subscription['tier'] ?? 'None';
    $_SESSION['tier'] = $tier;
    cs_json_response(200, [
        'subscribed' => true,
        'tier' => $tier,
        'tier_name' => $subscription['tier_name'] ?? 'Unknown',
        'is_gift' => $subscription['is_gift'] ?? false
    ]);
} else {
    $_SESSION['tier'] = 'None';
    cs_json_response(200, [
        'subscribed' => false,
        'tier' => 'None'
    ]);
}
?>
