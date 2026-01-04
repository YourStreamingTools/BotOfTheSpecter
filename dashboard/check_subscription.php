<?php
// Check Twitch subscription tier for current user
// This page queries the Twitch API using the broadcaster's token to check if a user is subscribed

session_start();
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/twitch.php";

// Ensure user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['twitchUserId'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
$userTwitchId = $_SESSION['twitchUserId'];
$broadcasterId = '140296994'; // Your Twitch ID
// Get the broadcaster's access token from twitch_bot_access database
$stmt = $conn->prepare("SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Database query preparation failed']);
    exit;
}
$stmt->bind_param("s", $broadcasterId);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    http_response_code(500);
    echo json_encode(['error' => 'Broadcaster token not found']);
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
    http_response_code($httpCode ?: 500);
    echo json_encode(['error' => 'Twitch API request failed', 'http_code' => $httpCode]);
    exit;
}
$data = json_decode($response, true);
// Check if user has a subscription
if (isset($data['data']) && count($data['data']) > 0) {
    $subscription = $data['data'][0];
    $tier = $subscription['tier'] ?? 'None';
    // Update session tier
    $_SESSION['tier'] = $tier;
    // Return subscription info
    echo json_encode([
        'subscribed' => true,
        'tier' => $tier,
        'tier_name' => $subscription['tier_name'] ?? 'Unknown',
        'is_gift' => $subscription['is_gift'] ?? false
    ]);
} else {
    // No subscription found
    $_SESSION['tier'] = 'None';
    echo json_encode([
        'subscribed' => false,
        'tier' => 'None'
    ]);
}
?>
