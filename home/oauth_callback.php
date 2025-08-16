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
    'clientID' => $clientID,
    'client_secret' => $clientSecret,
    'code' => $code,
    'grant_type' => 'authorization_code',
    'redirect_uri' => 'https://botofthespecter.com/oauth_callback.php',
]);

$opts = [
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $post_fields,
        'timeout' => 10,
    ]
];
$context = stream_context_create($opts);
$resp = @file_get_contents($token_url, false, $context);
if ($resp === false) {
    echo 'Failed to contact Twitch token endpoint.';
    exit;
}

$data = json_decode($resp, true);
if (empty($data['access_token'])) {
    echo 'Failed to obtain access token.';
    exit;
}

$access_token = $data['access_token'];
$refresh_token = $data['refresh_token'] ?? null;

// Fetch user info
$user_api = 'https://api.twitch.tv/helix/users';
$opts = [
    'http' => [
        'method' => 'GET',
        'header' => "Client-ID: " . $clientID . "\r\nAuthorization: Bearer " . $access_token . "\r\n",
        'timeout' => 10,
    ]
];
$ctx = stream_context_create($opts);
$u = @file_get_contents($user_api, false, $ctx);
if ($u === false) {
    echo 'Failed to fetch Twitch user.';
    exit;
}
$ud = json_decode($u, true);
if (empty($ud['data'][0]['id'])) {
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
