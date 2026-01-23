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

include 'config/twitch.php';

if (empty($clientID)) {
    echo json_encode(['success' => false, 'error' => 'Client ID not configured']);
    exit();
}

// ---------------------------------------------------------
// Helper Function
// ---------------------------------------------------------
function twitchApiCall($method, $url, $token, $clientID, $body = null, $isMultipart = false)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    $headers = [
        "Authorization: Bearer $token",
        "Client-Id: $clientID"
    ];

    if ($isMultipart) {
        $headers[] = "Content-Type: multipart/form-data";
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } else {
        $headers[] = "Content-Type: application/json";
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $httpCode, 'response' => $response];
}

// ---------------------------------------------------------
// Validate Required Fields
// ---------------------------------------------------------
if (!isset($_POST['title']) || !isset($_POST['cost'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields (title, cost)']);
    exit();
}

// Prepare Data for Creation
$rewardData = [
    'title' => $_POST['title'],
    'cost' => (int) $_POST['cost'],
    'prompt' => $_POST['prompt'] ?? '',
    'is_enabled' => true,
    'background_color' => $_POST['background_color'] ?? '#00E5CB',
    'is_user_input_required' => isset($_POST['is_user_input_required']) ? filter_var($_POST['is_user_input_required'], FILTER_VALIDATE_BOOLEAN) : false,
    'is_max_per_stream_enabled' => isset($_POST['is_max_per_stream_enabled']) ? filter_var($_POST['is_max_per_stream_enabled'], FILTER_VALIDATE_BOOLEAN) : false,
    'max_per_stream' => (int) ($_POST['max_per_stream'] ?? 0),
    'is_max_per_user_per_stream_enabled' => isset($_POST['is_max_per_user_per_stream_enabled']) ? filter_var($_POST['is_max_per_user_per_stream_enabled'], FILTER_VALIDATE_BOOLEAN) : false,
    'max_per_user_per_stream' => (int) ($_POST['max_per_user_per_stream'] ?? 0),
    'is_global_cooldown_enabled' => isset($_POST['is_global_cooldown_enabled']) ? filter_var($_POST['is_global_cooldown_enabled'], FILTER_VALIDATE_BOOLEAN) : false,
    'global_cooldown_seconds' => (int) ($_POST['global_cooldown_seconds'] ?? 0),
    'should_redemptions_skip_request_queue' => isset($_POST['should_redemptions_skip_request_queue']) ? filter_var($_POST['should_redemptions_skip_request_queue'], FILTER_VALIDATE_BOOLEAN) : false
];

// ---------------------------------------------------------
// Step 1: Create Reward on Twitch
// ---------------------------------------------------------
$createUrl = "https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={$broadcasterId}";
$createResult = twitchApiCall('POST', $createUrl, $token, $clientID, $rewardData);

if ($createResult['code'] !== 200) {
    echo json_encode([
        'success' => false,
        'error' => 'Twitch API Error: ' . $createResult['response'],
        'http_code' => $createResult['code']
    ]);
    exit();
}

$newRewardData = json_decode($createResult['response'], true);
$newRewardId = $newRewardData['data'][0]['id'] ?? null;

if (!$newRewardId) {
    echo json_encode(['success' => false, 'error' => 'Created but no ID returned']);
    exit();
}

// ---------------------------------------------------------
// Step 2: Upload Images (Skipped)
// ---------------------------------------------------------
// Twitch API 'Create Custom Reward' does not support image upload in the same call.
// Users must upload images via the Twitch Dashboard.

// ---------------------------------------------------------
// Step 3: Insert into DB
// ---------------------------------------------------------
require_once 'config/database.php';
$dbname = $_SESSION['username'];
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);

if ($db->connect_error) {
    // Reward created but DB failed
    echo json_encode(['success' => true, 'message' => 'Created on Twitch (DB Sync Failed)', 'new_reward_id' => $newRewardId]);
    exit();
}

$stmt = $db->prepare("INSERT INTO channel_point_rewards (reward_id, reward_title, reward_cost, custom_message, is_enabled, managed_by) VALUES (?, ?, ?, ?, 1, 'specter')");
$customMsg = $_POST['title']; // Default custom message same as title? or prompt? or empty?
// existing table structure usage: usually empty or title.
// We'll set it to title for now.
$stmt->bind_param('ssis', $newRewardId, $_POST['title'], $_POST['cost'], $customMsg);
$stmt->execute();
$stmt->close();
$db->close();

echo json_encode(['success' => true, 'new_reward_id' => $newRewardId]);
