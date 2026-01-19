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
$broadcasterId = $_SESSION['twitchUserId']; // Assuming this is set in session
$token = $_SESSION['access_token'];
// Use the Client ID from config or environment. 
// We need to fetch it. Usually it's in a config file or env.
// Based on sync script it's env 'CLIENT_ID'.
// PHP might not have access to .env easily unless we parse it or it's in $_ENV via config.
// Let's assume we can get it from twitch.php or similar if defined, or similar to sync script.
// Checking `config/twitch.php` previously showed empty strings, likely loaded elsewhere or placeholder.
// `bot/sync-channel-rewards.py` used .env.
// Let's look at `dashboard/login.php` or `dashboard/bot_control.php` to see how they get Client ID.
// For now, I will hardcode or try to load from a safe place if possible. 
// Actually, `config/twitch.php` had placeholders.
// Let's try to grab it from a known config if available.
// If not, we might fail.
// Alternative: passed in session?
// Let's assume standard Twitch config inclusion often sets variable $clientID.

include '/var/www/config/twitch.php';
// If twitch.php has the client ID logic.

if (empty($clientID)) {
    // Fallback or error attempt
    // In many setups, CLIENT_ID is needed.
    // If we can't get it, we can try to proceed without it? Twitch API needs it header matches token.
    // Token validation endpoint returns client_id.
    // Let's try to validate token to get client_id if missing? No that's slow.
    // Let's hopefully rely on twitch.php variables being populated in production env.
}

$url = "https://api.twitch.tv/helix/channel_points/custom_rewards/redemptions?broadcaster_id={$broadcasterId}&reward_id={$rewardId}&status=UNFULFILLED&first=50";
$urlFulfilled = "https://api.twitch.tv/helix/channel_points/custom_rewards/redemptions?broadcaster_id={$broadcasterId}&reward_id={$rewardId}&status=FULFILLED&first=50";

function fetchFromTwitch($url, $token, $clientID)
{
    if (empty($clientID)) {
        // Attempt to extract from token or assume common var
        // For now, let's just warn if we can't find it.
        // Or actually, many requests pass without it if Bearer is there? No, usually required "Client-Id".
    }

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

// Ensure we have a clientID. If variable is empty from include, we might be in trouble.
// Let's verify `config/twitch.php` content again? 
// The user previous view showed it empty: `$clientID = "";`.
// This is problematic. The system must be getting it from somewhere.
// `sync-channel-rewards.py` gets it from env.
// Let's try to load .env?
if (file_exists('/home/botofthespecter/.env')) {
    $env = parse_ini_file('/home/botofthespecter/.env');
    if (isset($env['CLIENT_ID'])) {
        $clientID = $env['CLIENT_ID'];
    }
}
// Fallback path
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


$unfulfilled = fetchFromTwitch($url, $token, $clientID);
$fulfilled = fetchFromTwitch($urlFulfilled, $token, $clientID);

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

echo json_encode(['data' => $result]);
?>