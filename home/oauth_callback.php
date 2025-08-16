<?php
session_start();
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/twitch.php";

// Basic safety checks
if (!isset($_GET['code']) || !isset($_GET['state']) || ($_GET['state'] ?? '') !== ($_SESSION['oauth_state'] ?? '')) {
    http_response_code(400);
    echo 'Invalid OAuth response.';
    exit;
}

$code = $_GET['code'];

// Exchange code for token
$token_url = 'https://id.twitch.tv/oauth2/token';
$post_fields = http_build_query([
    'client_id' => $clientID,
    'client_secret' => $clientSecret,
    'code' => $code,
    'grant_type' => 'authorization_code',
    'redirect_uri' => 'https://botofthespecter.com/oauth_callback.php',
]);
// Use cURL for the POST to Twitch token endpoint for better error handling
$ch = curl_init($token_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
// verify TLS; set to true in production
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$resp = curl_exec($ch);
if ($resp === false) {
    $err = curl_error($ch);
    error_log("OAuth token request failed: $err");
    curl_close($ch);
    echo 'Failed to contact Twitch token endpoint: ' . h($err);
    exit;
}

$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true);
if (empty($data['access_token'])) {
    // include any returned error for debugging
    $err_msg = isset($data['message']) ? $data['message'] : (is_string($resp) ? $resp : 'no response');
    error_log("Failed to obtain access token. HTTP={$http_code} response=" . substr($resp, 0, 1000));
    echo 'Failed to obtain access token: ' . h($err_msg);
    exit;
}

$access_token = $data['access_token'];
$refresh_token = $data['refresh_token'] ?? null;

// Fetch user info
$user_api = 'https://api.twitch.tv/helix/users';
// Use cURL for user info request as well
$ch = curl_init($user_api);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Client-ID: ' . $clientID,
    'Authorization: Bearer ' . $access_token,
]);

$u = curl_exec($ch);
if ($u === false) {
    $err = curl_error($ch);
    error_log("Failed to fetch Twitch user: $err");
    curl_close($ch);
    echo 'Failed to fetch Twitch user: ' . h($err);
    exit;
}
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$ud = json_decode($u, true);
if (empty($ud['data'][0]['id'])) {
    error_log("Failed to read Twitch user data. HTTP={$http_code} response=" . substr($u, 0, 1000));
    echo 'Failed to read Twitch user data.';
    exit;
}

$twitch_user_id = $ud['data'][0]['id'];
$display_name = $ud['data'][0]['display_name'] ?? $ud['data'][0]['login'];

// Set session for feedback page
$_SESSION['twitch_user_id'] = $twitch_user_id;
$_SESSION['twitch_display_name'] = $display_name;

// Redirect back to feedback page
header('Location: feedback.php');
exit;

?>
