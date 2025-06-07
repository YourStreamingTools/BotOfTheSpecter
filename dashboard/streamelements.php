<?php
session_start();
include "/var/www/config/streamelements.php";
include "/var/www/config/db_connect.php";

// Set up StreamElements OAuth2 parameters
$client_id = $streamelements_client_id;
$client_secret = $streamelements_client_secret;
$redirect_uri = 'https://dashboard.botofthespecter.com/streamelements.php';
$scope = 'channel:read tips:read';

// Handle user denial (error=true in query string)
if (isset($_GET['error']) && $_GET['error'] === 'true') {
    echo "Authorization was denied. Please allow access to link your StreamElements account.<br>";
    echo '<a href="streamelements.php">Try again</a>';
    exit();
}

// If neither code nor error in URL, redirect to StreamElements OAuth2
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['streamelements_oauth_state'] = $state;
    $auth_url = "https://api.streamelements.com/oauth2/authorize"
        . "?client_id={$client_id}"
        . "&response_type=code"
        . "&scope=" . urlencode($scope)
        . "&state={$state}"
        . "&redirect_uri=" . $redirect_uri;
    header("Location: $auth_url");
    exit();
}

// Exchange code for token
if (isset($_GET['code'])) {
    // Optional: Validate state parameter
    if (!isset($_GET['state']) || !isset($_SESSION['streamelements_oauth_state']) || $_GET['state'] !== $_SESSION['streamelements_oauth_state']) {
        echo "Invalid state parameter. Please try again.";
        exit();
    }
    unset($_SESSION['streamelements_oauth_state']);
    $code = $_GET['code'];
    $token_url = "https://api.streamelements.com/oauth2/token";
    $post_fields = http_build_query([
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirect_uri
    ]);
    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $token_data = json_decode($response, true);
    if ($httpcode === 200 && isset($token_data['access_token'])) {
        $access_token = $token_data['access_token'];
        $refresh_token = $token_data['refresh_token'];
        // Store refresh_token and expires_in if present
        if (isset($token_data['refresh_token'])) { $_SESSION['streamelements_refresh_token'] = $token_data['refresh_token']; }
        if (isset($token_data['expires_in'])) { $_SESSION['streamelements_expires_in'] = $token_data['expires_in']; }
        // Validate the token
        $validate_url = "https://api.streamelements.com/oauth2/validate";
        $ch = curl_init($validate_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$access_token}"]);
        $validate_response = curl_exec($ch);
        $validate_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $validate_data = json_decode($validate_response, true);
        if ($validate_code === 200 && isset($validate_data['channel_id'])) {
            $_SESSION['streamelements_token'] = $access_token;
            if (isset($_SESSION['twitchUserId']) && $refresh_token) {
                $twitchUserId = $_SESSION['twitchUserId'];
                $query = "INSERT INTO streamelements_tokens (twitch_user_id, access_token, refresh_token) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token)";
                if ($stmt = $conn->prepare($query)) {
                    $stmt->bind_param('sss', $twitchUserId, $access_token, $refresh_token);
                    if ($stmt->execute()) {
                        echo "StreamElements account linked and tokens saved.<br>";
                    } else { echo "Linked, but failed to save tokens.<br>"; }
                    $stmt->close();
                } else { echo "Linked, but failed to prepare statement.<br>"; }
            } else { echo "Linked, but missing Twitch user ID or refresh token.<br>"; }
        } else {
            echo "Token validation failed.<br>";
            if (isset($validate_data['message'])) { echo "Error: " . htmlspecialchars($validate_data['message']) . "<br>"; }
        }
    } else {
        echo "Failed to link StreamElements account.<br>";
        if (isset($token_data['error'])) { echo "Error: " . htmlspecialchars($token_data['error']) . "<br>"; }
        if (isset($token_data['error_description'])) { echo "Description: " . htmlspecialchars($token_data['error_description']) . "<br>"; }
    }
    exit();
}
?>