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

include '/var/www/config/twitch.php';

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

// ---------------------------------------------------------
// STEP 2: COMPLETE MANUAL DELETE FLOW
// ---------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'complete_manual') {
    if (!isset($_POST['reward_id'])) {
        echo json_encode(['success' => false, 'error' => 'Missing reward_id']);
        exit();
    }
    $rewardId = $_POST['reward_id'];

    if (!isset($_SESSION['specter_manage_reward_queue'])) {
        echo json_encode(['success' => false, 'error' => 'Session expired or invalid data. Please try again.']);
        exit();
    }

    $queueData = $_SESSION['specter_manage_reward_queue'];

    // Validate we are working on the same reward
    if ($queueData['old_reward_id'] !== $rewardId) {
        echo json_encode(['success' => false, 'error' => 'ID mismatch. Please refresh and try again.']);
        exit();
    }

    $newRewardBody = $queueData['body'];
    $imageBase64 = $queueData['image_base64'];

    // Proceed to creation
    $createUrl = "https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={$broadcasterId}";
    $createResult = twitchApiCall('POST', $createUrl, $token, $clientID, $newRewardBody);

    if ($createResult['code'] !== 200) {
        // If it fails here, maybe they didn't actually delete it? 
        // Or name conflict if they didn't delete it.
        echo json_encode([
            'success' => false,
            'error' => 'Failed to create new reward. Please ensure you have DELETED the old reward on Twitch first!',
            'http_code' => $createResult['code'],
            'response' => $createResult['response']
        ]);
        exit();
    }

    $newRewardData = json_decode($createResult['response'], true);
    if (!isset($newRewardData['data'][0]['id'])) {
        echo json_encode(['success' => false, 'error' => 'New reward created but ID not returned']);
        exit();
    }

    $newRewardId = $newRewardData['data'][0]['id'];

    // Upload the image to the new reward if we downloaded one
    if ($imageBase64 !== null) {
        $patchUrl = "https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={$broadcasterId}&id={$newRewardId}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $patchUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $token",
            "Client-Id: $clientID",
            "Content-Type: multipart/form-data"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['image' => $imageBase64]);

        $patchResponse = curl_exec($ch);
        $patchCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    // Clear session
    unset($_SESSION['specter_manage_reward_queue']);

    // DB Update Logic (Shared)
    require_once '/var/www/config/database.php';
    $dbname = $_SESSION['username'];
    $db = new mysqli($db_servername, $db_username, $db_password, $dbname);

    if ($db->connect_error) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed', 'new_reward_id' => $newRewardId]);
        exit();
    }

    // Update the main channel_point_rewards table
    $updateQuery = $db->prepare("UPDATE channel_point_rewards SET reward_id = ?, managed_by = 'specter' WHERE reward_id = ?");
    $updateQuery->bind_param('ss', $newRewardId, $rewardId);
    $updateSuccess = $updateQuery->execute();
    $updateQuery->close();

    if (!$updateSuccess) {
        $db->close();
        echo json_encode(['success' => false, 'error' => 'Database update failed', 'new_reward_id' => $newRewardId]);
        exit();
    }

    // Update related tables
    $tables = ['reward_counts', 'reward_streaks', 'sound_alerts', 'video_alerts'];
    foreach ($tables as $table) {
        $stmt = $db->prepare("UPDATE $table SET reward_id = ? WHERE reward_id = ?");
        if ($stmt) {
            $stmt->bind_param('ss', $newRewardId, $rewardId);
            $stmt->execute();
            $stmt->close();
        }
    }

    $db->close();
    echo json_encode(['success' => true, 'message' => 'Reward managed successfully', 'new_reward_id' => $newRewardId]);
    exit();
}

// ---------------------------------------------------------
// STEP 1: INITIAL REQUEST (Fetch -> Try Delete -> Pause if 403)
// ---------------------------------------------------------

if (!isset($_POST['reward_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing reward_id']);
    exit();
}
$rewardId = $_POST['reward_id'];

// Get Reward Details
$getUrl = "https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={$broadcasterId}&id={$rewardId}";
$getResult = twitchApiCall('GET', $getUrl, $token, $clientID);

if ($getResult['code'] !== 200) {
    echo json_encode(['success' => false, 'error' => 'Failed to fetch reward details', 'http_code' => $getResult['code']]);
    exit();
}

$rewardData = json_decode($getResult['response'], true);
if (!isset($rewardData['data'][0])) {
    echo json_encode(['success' => false, 'error' => 'Reward not found on Twitch']);
    exit();
}

$reward = $rewardData['data'][0];

// Prepare New Reward Body
$newRewardBody = [
    'title' => $reward['title'],
    'cost' => $reward['cost']
];

$optionalFields = [
    'prompt',
    'is_enabled',
    'background_color',
    'is_user_input_required',
    'is_max_per_stream_enabled',
    'max_per_stream',
    'is_max_per_user_per_stream_enabled',
    'max_per_user_per_stream',
    'is_global_cooldown_enabled',
    'global_cooldown_seconds',
    'should_redemptions_skip_request_queue'
];

foreach ($optionalFields as $field) {
    if (isset($reward[$field])) {
        // Handle conditional fields logic
        if ($field === 'max_per_stream' && empty($reward['is_max_per_stream_enabled']))
            continue;
        if ($field === 'max_per_user_per_stream' && empty($reward['is_max_per_user_per_stream_enabled']))
            continue;
        if ($field === 'global_cooldown_seconds' && empty($reward['is_global_cooldown_enabled']))
            continue;

        $newRewardBody[$field] = $reward[$field];
    }
}

// Download and preserve the reward image if it exists
$imageBase64 = null;
if (isset($reward['image']['url_4x']) && !empty($reward['image']['url_4x'])) {
    $imageData = @file_get_contents($reward['image']['url_4x']);
    if ($imageData !== false) {
        $imageBase64 = base64_encode($imageData);
    }
}

// Attempt DELETE
$deleteUrl = "https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={$broadcasterId}&id={$rewardId}";
$deleteResult = twitchApiCall('DELETE', $deleteUrl, $token, $clientID);

// HANDLE 403 FORBIDDEN - MANUAL FLOW TRIGGER
if ($deleteResult['code'] === 403) {
    // Save state to session
    $_SESSION['specter_manage_reward_queue'] = [
        'old_reward_id' => $rewardId,
        'body' => $newRewardBody,
        'image_base64' => $imageBase64,
        'title' => $reward['title']
    ];

    echo json_encode([
        'success' => false,
        'error' => 'manual_delete_required',
        'title' => $reward['title'],
        'http_code' => 403
    ]);
    exit();
}

if ($deleteResult['code'] !== 204 && $deleteResult['code'] !== 200) {
    echo json_encode(['success' => false, 'error' => 'Failed to delete reward', 'http_code' => $deleteResult['code']]);
    exit();
}

// IF DELETE SUCCEEDED (Rare if not app-owned, but possible) -> PROCEED TO CREATE
// We duplicate the creation logic here to handle the "Auto Success" path

$createUrl = "https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={$broadcasterId}";
$createResult = twitchApiCall('POST', $createUrl, $token, $clientID, $newRewardBody);

if ($createResult['code'] !== 200) {
    echo json_encode(['success' => false, 'error' => 'Failed to create new reward', 'http_code' => $createResult['code'], 'response' => $createResult['response']]);
    exit();
}

$newRewardData = json_decode($createResult['response'], true);
$newRewardId = $newRewardData['data'][0]['id'];

// Upload Image
if ($imageBase64 !== null) {
    $patchUrl = "https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={$broadcasterId}&id={$newRewardId}";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $patchUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "Client-Id: $clientID",
        "Content-Type: multipart/form-data"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['image' => $imageBase64]);
    curl_exec($ch);
    curl_close($ch);
}

// DB Update Logic
require_once '/var/www/config/database.php';
$dbname = $_SESSION['username'];
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);

if ($db->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed', 'new_reward_id' => $newRewardId]);
    exit();
}

$updateQuery = $db->prepare("UPDATE channel_point_rewards SET reward_id = ?, managed_by = 'specter' WHERE reward_id = ?");
$updateQuery->bind_param('ss', $newRewardId, $rewardId);
$updateSuccess = $updateQuery->execute();
$updateQuery->close();

if (!$updateSuccess) {
    $db->close();
    echo json_encode(['success' => false, 'error' => 'Database update failed', 'new_reward_id' => $newRewardId]);
    exit();
}

$tables = ['reward_counts', 'reward_streaks', 'sound_alerts', 'video_alerts'];
foreach ($tables as $table) {
    $stmt = $db->prepare("UPDATE $table SET reward_id = ? WHERE reward_id = ?");
    if ($stmt) {
        $stmt->bind_param('ss', $newRewardId, $rewardId);
        $stmt->execute();
        $stmt->close();
    }
}

$db->close();

echo json_encode(['success' => true, 'message' => 'Reward managed successfully', 'new_reward_id' => $newRewardId]);
?>