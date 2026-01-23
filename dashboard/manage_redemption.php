<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$broadcasterId = $_SESSION['twitchUserId'];
$token = $_SESSION['access_token'];

// Include Config
if (file_exists('../config/twitch.php')) {
    include '../config/twitch.php';
} elseif (file_exists('/var/www/config/twitch.php')) {
    include '/var/www/config/twitch.php';
} elseif (file_exists('config/twitch.php')) {
    include 'config/twitch.php';
}

if (empty($clientID)) {
    echo json_encode(['success' => false, 'error' => 'Client ID not configured']);
    exit();
}

function twitchApiCall($method, $url, $token, $clientID, $body = null)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "Client-Id: $clientID",
        "Content-Type: application/json"
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $httpCode, 'response' => $response];
}

if (!isset($_POST['redemption_id']) || !isset($_POST['reward_id']) || !isset($_POST['status'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields (redemption_id, reward_id, status)']);
    exit();
}

$redemptionId = $_POST['redemption_id'];
$rewardId = $_POST['reward_id'];
$status = $_POST['status'];

// Validate Status
if (!in_array($status, ['FULFILLED', 'CANCELED'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid status. Must be FULFILLED or CANCELED.']);
    exit();
}

// Build URL
$url = "https://api.twitch.tv/helix/channel_points/custom_rewards/redemptions?broadcaster_id={$broadcasterId}&reward_id={$rewardId}&id={$redemptionId}";

// Prepare Body
$body = ['status' => $status];

// Execute Request
$result = twitchApiCall('PATCH', $url, $token, $clientID, $body);

if ($result['code'] == 200) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Twitch API Error',
        'http_code' => $result['code'],
        'response' => $result['response']
    ]);
}
?>