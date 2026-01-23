<?php
session_start();
require_once '/var/www/config/database.php';
require_once "/var/www/config/db_connect.php";

header('Content-Type: application/json');

if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['reward_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing reward_id']);
    exit();
}

$rewardId = $_GET['reward_id'];
$broadcasterId = $_SESSION['twitchUserId'];
$token = $_SESSION['access_token'];

include '/var/www/config/twitch.php';

$urlUnfulfilled = "https://api.twitch.tv/helix/channel_points/custom_rewards/redemptions?broadcaster_id={$broadcasterId}&reward_id={$rewardId}&status=UNFULFILLED&first=50";
$urlFulfilled = "https://api.twitch.tv/helix/channel_points/custom_rewards/redemptions?broadcaster_id={$broadcasterId}&reward_id={$rewardId}&status=FULFILLED&first=50";
$urlCanceled = "https://api.twitch.tv/helix/channel_points/custom_rewards/redemptions?broadcaster_id={$broadcasterId}&reward_id={$rewardId}&status=CANCELED&first=50";

function fetchFromTwitch($url, $token, $clientID)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "Client-Id: $clientID"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $httpCode, 'response' => $response];
}

if (file_exists('/home/botofthespecter/.env')) {
    $env = parse_ini_file('/home/botofthespecter/.env');
    if (isset($env['CLIENT_ID'])) {
        $clientID = $env['CLIENT_ID'];
    }
}
if (empty($clientID) && file_exists('/var/www/.env')) {
    $lines = file('/var/www/.env');
    foreach ($lines as $line) {
        if (strpos(trim($line), 'CLIENT_ID') === 0) {
            $parts = explode('=', $line);
            $clientID = trim($parts[1]);
            break;
        }
    }
}

$unfulfilled = fetchFromTwitch($urlUnfulfilled, $token, $clientID);
$fulfilled = fetchFromTwitch($urlFulfilled, $token, $clientID);
$canceled = fetchFromTwitch($urlCanceled, $token, $clientID);

$result = [];

if ($unfulfilled['code'] == 200) {
    $data = json_decode($unfulfilled['response'], true);
    if (isset($data['data'])) {
        $result = array_merge($result, $data['data']);
    }
}

if ($fulfilled['code'] == 200) {
    $data = json_decode($fulfilled['response'], true);
    if (isset($data['data'])) {
        $result = array_merge($result, $data['data']);
    }
}

if ($canceled['code'] == 200) {
    $data = json_decode($canceled['response'], true);
    if (isset($data['data'])) {
        $result = array_merge($result, $data['data']);
    }
}

echo json_encode(['data' => $result]);
?>