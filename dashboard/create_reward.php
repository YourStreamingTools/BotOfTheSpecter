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

if (!isset($_POST['title']) || !isset($_POST['cost'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields (title, cost)']);
    exit();
}

// Validate & Prepare Data for Creation
$title = isset($_POST['title']) ? trim($_POST['title']) : '';
$cost = isset($_POST['cost']) ? (int) $_POST['cost'] : 0;
$prompt = isset($_POST['prompt']) ? trim($_POST['prompt']) : '';
$bgColor = $_POST['background_color'] ?? '#00E5CB';
$isUserInputRequired = isset($_POST['is_user_input_required']) ? filter_var($_POST['is_user_input_required'], FILTER_VALIDATE_BOOLEAN) : false;
$isMaxPerStreamEnabled = isset($_POST['is_max_per_stream_enabled']) ? filter_var($_POST['is_max_per_stream_enabled'], FILTER_VALIDATE_BOOLEAN) : false;
$maxPerStream = (int) ($_POST['max_per_stream'] ?? 0);
$isMaxPerUserPerStreamEnabled = isset($_POST['is_max_per_user_per_stream_enabled']) ? filter_var($_POST['is_max_per_user_per_stream_enabled'], FILTER_VALIDATE_BOOLEAN) : false;
$maxPerUserPerStream = (int) ($_POST['max_per_user_per_stream'] ?? 0);
$isGlobalCooldownEnabled = isset($_POST['is_global_cooldown_enabled']) ? filter_var($_POST['is_global_cooldown_enabled'], FILTER_VALIDATE_BOOLEAN) : false;
$globalCooldownSeconds = (int) ($_POST['global_cooldown_seconds'] ?? 0);
$shouldSkipQueue = isset($_POST['should_redemptions_skip_request_queue']) ? filter_var($_POST['should_redemptions_skip_request_queue'], FILTER_VALIDATE_BOOLEAN) : false;

// Basic validation per Twitch API
if ($title === '' || mb_strlen($title) > 45) {
    echo json_encode(['success' => false, 'error' => 'Invalid title: required and max 45 characters']);
    exit();
}
if ($cost < 1) {
    echo json_encode(['success' => false, 'error' => 'Invalid cost: must be at least 1']);
    exit();
}
if ($prompt !== '' && mb_strlen($prompt) > 200) {
    echo json_encode(['success' => false, 'error' => 'Invalid prompt: maximum 200 characters']);
    exit();
}
if ($isMaxPerStreamEnabled && $maxPerStream < 1) {
    echo json_encode(['success' => false, 'error' => 'Invalid max_per_stream: must be at least 1 when enabled']);
    exit();
}
if ($isMaxPerUserPerStreamEnabled && $maxPerUserPerStream < 1) {
    echo json_encode(['success' => false, 'error' => 'Invalid max_per_user_per_stream: must be at least 1 when enabled']);
    exit();
}
if ($isGlobalCooldownEnabled && $globalCooldownSeconds < 1) {
    echo json_encode(['success' => false, 'error' => 'Invalid global_cooldown_seconds: must be at least 1 when enabled']);
    exit();
}

$rewardData = [
    'title' => $title,
    'cost' => $cost,
    'prompt' => $prompt,
    'is_enabled' => true,
    'background_color' => $bgColor,
    'is_user_input_required' => $isUserInputRequired,
    'is_max_per_stream_enabled' => $isMaxPerStreamEnabled,
    'max_per_stream' => $maxPerStream,
    'is_max_per_user_per_stream_enabled' => $isMaxPerUserPerStreamEnabled,
    'max_per_user_per_stream' => $maxPerUserPerStream,
    'is_global_cooldown_enabled' => $isGlobalCooldownEnabled,
    'global_cooldown_seconds' => $globalCooldownSeconds,
    'should_redemptions_skip_request_queue' => $shouldSkipQueue
];

// Check for existing reward with same title to avoid duplicate error
$existingUrl = "https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={$broadcasterId}&only_manageable_rewards=true";
$existingResult = twitchApiCall('GET', $existingUrl, $token, $clientID);
if ($existingResult['code'] >= 200 && $existingResult['code'] < 300) {
    $existingData = json_decode($existingResult['response'], true);
    if (isset($existingData['data']) && is_array($existingData['data'])) {
        foreach ($existingData['data'] as $r) {
            if (isset($r['title']) && strcasecmp(trim($r['title']), $title) === 0) {
                // Reward already exists — ensure it is synced into the local DB so the view shows it
                require_once '/var/www/config/database.php';
                $dbname = $_SESSION['username'];
                $db = new mysqli($db_servername, $db_username, $db_password, $dbname);
                $rewardId = $r['id'];
                $rewardTitle = $r['title'];
                $rewardCost = isset($r['cost']) ? (int)$r['cost'] : $cost;
                $customMsg = $title; // default to the title
                if (!$db->connect_error) {
                    // Upsert without is_enabled column (not present in schema)
                    $upsertSql = "INSERT INTO channel_point_rewards (reward_id, reward_title, reward_cost, custom_message, managed_by) VALUES (?, ?, ?, ?, 'specter') ON DUPLICATE KEY UPDATE reward_title=VALUES(reward_title), reward_cost=VALUES(reward_cost), custom_message=VALUES(custom_message), managed_by=VALUES(managed_by)";
                    $stmtUp = $db->prepare($upsertSql);
                    if ($stmtUp) {
                        $stmtUp->bind_param('ssis', $rewardId, $rewardTitle, $rewardCost, $customMsg);
                        $stmtUp->execute();
                        $stmtUp->close();
                    } else {
                        error_log('Reward upsert prepare failed: ' . $db->error);
                    }
                    $db->close();
                    echo json_encode(['success' => true, 'message' => 'Reward already exists', 'existing_reward_id' => $rewardId, 'db_synced' => true]);
                    exit();
                }
                // DB failed — still return the existing reward id but note sync failed
                echo json_encode(['success' => true, 'message' => 'Reward already exists', 'existing_reward_id' => $rewardId, 'db_synced' => false, 'db_error' => $db->connect_error]);
                exit();
            }
        }
    }
}

// Proceed to create the reward
$createUrl = "https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={$broadcasterId}";
$createResult = twitchApiCall('POST', $createUrl, $token, $clientID, $rewardData);

// Accept any 2xx as success
if ($createResult['code'] < 200 || $createResult['code'] >= 300) {
    $errMsgRaw = $createResult['response'];
    $decoded = json_decode($errMsgRaw, true);
    $errMsg = is_array($decoded) && isset($decoded['message']) ? $decoded['message'] : $errMsgRaw;
    // Handle duplicate reward error specially: fetch and return existing reward ID
    if ($createResult['code'] === 400 && (stripos($errMsg, 'DUPLICATE') !== false || stripos($errMsg, 'CREATE_CUSTOM_REWARD_DUPLICATE_REWARD') !== false)) {
        // Try to find existing reward by title
        $searchUrl = "https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id={$broadcasterId}&only_manageable_rewards=true";
        $searchResult = twitchApiCall('GET', $searchUrl, $token, $clientID);
        if ($searchResult['code'] >= 200 && $searchResult['code'] < 300) {
            $searchData = json_decode($searchResult['response'], true);
            if (isset($searchData['data']) && is_array($searchData['data'])) {
                foreach ($searchData['data'] as $r) {
                    if (isset($r['title']) && strcasecmp(trim($r['title']), $title) === 0) {
                        // Upsert the existing reward into local DB before returning
                        require_once '/var/www/config/database.php';
                        $dbname = $_SESSION['username'];
                        $db = new mysqli($db_servername, $db_username, $db_password, $dbname);
                        $rewardId = $r['id'];
                        $rewardTitle = $r['title'];
                        $rewardCost = isset($r['cost']) ? (int)$r['cost'] : $cost;
                        $customMsg = $title;

                        if (!$db->connect_error) {
                            // Upsert without is_enabled column (not present in schema)
                            $upsertSql = "INSERT INTO channel_point_rewards (reward_id, reward_title, reward_cost, custom_message, managed_by) VALUES (?, ?, ?, ?, 'specter') ON DUPLICATE KEY UPDATE reward_title=VALUES(reward_title), reward_cost=VALUES(reward_cost), custom_message=VALUES(custom_message), managed_by=VALUES(managed_by)";
                            $stmtUp = $db->prepare($upsertSql);
                            if ($stmtUp) {
                                $stmtUp->bind_param('ssis', $rewardId, $rewardTitle, $rewardCost, $customMsg);
                                $stmtUp->execute();
                                $stmtUp->close();
                            } else {
                                error_log('Reward upsert prepare failed: ' . $db->error);
                            }
                            $db->close();
                            echo json_encode(['success' => true, 'message' => 'Reward already exists', 'existing_reward_id' => $rewardId, 'db_synced' => true]);
                            exit();
                        }

                        echo json_encode(['success' => true, 'message' => 'Reward already exists', 'existing_reward_id' => $rewardId, 'db_synced' => false, 'db_error' => $db->connect_error]);
                        exit();
                    }
                }
            }
        }
        // If not found, return the raw error
        echo json_encode(['success' => false, 'error' => 'Twitch API Error (duplicate): ' . $errMsg, 'http_code' => $createResult['code']]);
        exit();
    }
    // Handle max rewards reached
    if ($createResult['code'] === 400 && stripos($errMsg, 'maximum number of rewards') !== false) {
        echo json_encode(['success' => false, 'error' => 'Twitch API Error: channel has reached maximum number of custom rewards', 'http_code' => $createResult['code']]);
        exit();
    }
    echo json_encode([
        'success' => false,
        'error' => 'Twitch API Error: ' . $errMsg,
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

require_once '/var/www/config/database.php';
$dbname = $_SESSION['username'];
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    // Reward created on Twitch but DB connection failed
    echo json_encode(['success' => true, 'message' => 'Created on Twitch (DB Sync Failed)', 'new_reward_id' => $newRewardId]);
    exit();
}

$stmt = $db->prepare("INSERT INTO channel_point_rewards (reward_id, reward_title, reward_cost, custom_message, managed_by) VALUES (?, ?, ?, ?, 'specter')");
$customMsg = $title; // Default custom message to the title
if ($stmt) {
    $stmt->bind_param('ssis', $newRewardId, $title, $cost, $customMsg);
    $stmt->execute();
    $stmt->close();
} else {
    error_log('DB prepare failed: ' . $db->error);
}
$db->close();

echo json_encode(['success' => true, 'new_reward_id' => $newRewardId]);