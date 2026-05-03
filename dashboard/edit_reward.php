<?php
require_once '/var/www/lib/session_bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['access_token'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit();
}

require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
session_write_close();

$rewardId = $_POST['reward_id'] ?? '';
if (empty($rewardId)) {
    echo json_encode(['success' => false, 'error' => 'Missing reward_id.']);
    exit();
}

require_once '/var/www/config/database.php';
$dbname = $_SESSION['username'];
$userDb = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($userDb->connect_error) {
    echo json_encode(['success' => false, 'error' => 'DB connection failed.']);
    exit();
}

// Confirm reward is managed by Specter in user DB
$check = $userDb->prepare("SELECT managed_by FROM channel_point_rewards WHERE reward_id = ?");
$check->bind_param('s', $rewardId);
$check->execute();
$res = $check->get_result();
$row = $res->fetch_assoc();
$check->close();

if (!$row || $row['managed_by'] !== 'specter') {
    $userDb->close();
    echo json_encode(['success' => false, 'error' => 'Reward is not managed by Specter.']);
    exit();
}

// Build PATCH body from submitted fields
$body = [];

if (isset($_POST['title']) && trim($_POST['title']) !== '') {
    $body['title'] = trim($_POST['title']);
}
if (isset($_POST['cost']) && $_POST['cost'] !== '') {
    $body['cost'] = (int)$_POST['cost'];
}
if (isset($_POST['prompt'])) {
    $body['prompt'] = $_POST['prompt'];
}
if (isset($_POST['background_color'])) {
    $body['background_color'] = $_POST['background_color'];
}

$boolFields = [
    'is_user_input_required',
    'is_max_per_stream_enabled',
    'is_max_per_user_per_stream_enabled',
    'is_global_cooldown_enabled',
    'is_paused',
    'should_redemptions_skip_request_queue',
];
foreach ($boolFields as $f) {
    if (isset($_POST[$f])) {
        $body[$f] = filter_var($_POST[$f], FILTER_VALIDATE_BOOLEAN);
    }
}

if (!empty($_POST['max_per_stream'])) {
    $body['max_per_stream'] = (int)$_POST['max_per_stream'];
}
if (!empty($_POST['max_per_user_per_stream'])) {
    $body['max_per_user_per_stream'] = (int)$_POST['max_per_user_per_stream'];
}
if (!empty($_POST['global_cooldown_seconds'])) {
    $body['global_cooldown_seconds'] = (int)$_POST['global_cooldown_seconds'];
}

if (empty($body)) {
    $userDb->close();
    echo json_encode(['success' => false, 'error' => 'No fields to update.']);
    exit();
}

$patchUrl = "https://api.twitch.tv/helix/channel_points/custom_rewards?broadcaster_id=" . urlencode($twitchUserId) . "&id=" . urlencode($rewardId);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $patchUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$authToken}",
    "Client-Id: {$clientID}",
    "Content-Type: application/json"
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true);
if ($httpCode === 200 && !empty($data['data'])) {
    $updated = $data['data'][0];
    $newTitle = $updated['title'] ?? null;
    $newCost = isset($updated['cost']) ? (int)$updated['cost'] : null;
    if ($newTitle !== null && $newCost !== null) {
        $upd = $userDb->prepare("UPDATE channel_point_rewards SET reward_title = ?, reward_cost = ? WHERE reward_id = ?");
        $upd->bind_param('sis', $newTitle, $newCost, $rewardId);
        $upd->execute();
        $upd->close();
    }
    $userDb->close();
    echo json_encode(['success' => true, 'data' => $updated]);
} else {
    $userDb->close();
    $errMsg = $data['message'] ?? ($data['error'] ?? ('HTTP ' . $httpCode));
    echo json_encode(['success' => false, 'error' => $errMsg, 'http_code' => $httpCode, 'twitch_response' => $data]);
}
