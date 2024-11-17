<?php
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Spotify Link"; 

// Include all the information
require_once "db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'sqlite.php';
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);

// Set variables
$client_secret = ''; // CHANGE TO MAKE THIS WORK
$client_id = ''; // CHANGE TO MAKE THIS WORK
$redirect_uri = 'https://dashboard.botofthespecter.com/spotifylink.php';
$authURL = '';
$message = '';
$messageType = '';
$spotifyUserInfo = [];

// Check if we received a code from Spotify (callback handling)
if (isset($_GET['code'])) {
    $auth_code = $_GET['code'];
    // Exchange the authorization code for an access token and refresh token
    $token_url = 'https://accounts.spotify.com/api/token';
    $data = [
        'grant_type' => 'authorization_code',
        'code' => $auth_code,
        'redirect_uri' => $redirect_uri,
        'client_id' => $client_id,
        'client_secret' => $client_secret
    ];
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded",
            'content' => http_build_query($data),
            'ignore_errors' => true
        ]
    ];
    $response = file_get_contents($token_url, false, stream_context_create($options));
    if ($response === FALSE) {
        die("Failed to contact Spotify. Please check your API credentials and network connection.");
    }
    $tokens = json_decode($response, true);
    if (isset($tokens['access_token'], $tokens['refresh_token'])) {
        $access_token = $tokens['access_token'];
        $refresh_token = $tokens['refresh_token'];
        // Check if the spotify_tokens entry exists for this user
        $checkStmt = $conn->prepare("SELECT 1 FROM spotify_tokens WHERE user_id = ?");
        $checkStmt->bind_param("i", $user_id);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()->num_rows > 0;
        if ($exists) {
            // Update existing tokens for the user
            $updateStmt = $conn->prepare("UPDATE spotify_tokens SET access_token = ?, refresh_token = ? WHERE user_id = ?");
            $updateStmt->bind_param("ssi", $access_token, $refresh_token, $user_id);
            $updateStmt->execute();
        } else {
            // Insert new tokens if none exist for this user
            $insertStmt = $conn->prepare("INSERT INTO spotify_tokens (user_id, access_token, refresh_token) VALUES (?, ?, ?)");
            $insertStmt->bind_param("iss", $user_id, $access_token, $refresh_token);
            $insertStmt->execute();
        }
        $message = "Your Spotify account has been successfully linked!";
        $messageType = "is-success";
    } else {
        $message = "Failed to retrieve tokens from Spotify. Please try again.";
        $messageType = "is-danger";
    }
}

// Fetch Spotify Profile Information if linked
$spotifySTMT = $conn->prepare("SELECT access_token FROM spotify_tokens WHERE user_id = ?");
$spotifySTMT->bind_param("i", $user_id);
$spotifySTMT->execute();
$spotifyResult = $spotifySTMT->get_result();

if ($spotifyResult->num_rows > 0) {
    // User is already linked to Spotify
    $spotifyRow = $spotifyResult->fetch_assoc();
    $spotifyAccessToken = $spotifyRow['access_token'];
    // Get Spotify profile data
    $profileUrl = 'https://api.spotify.com/v1/me';
    $profileOptions = [
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer $spotifyAccessToken",
            'ignore_errors' => true
        ]
    ];
    $profileResponse = file_get_contents($profileUrl, false, stream_context_create($profileOptions));
    $spotifyUserInfo = json_decode($profileResponse, true);
    if (!isset($spotifyUserInfo['id'])) {
        $message = "Failed to fetch Spotify user data. Please relink your account.";
        $messageType = "is-danger";
        // Set authorization URL to trigger reauthorization if profile data fetch fails
        $scopes = 'user-read-playback-state user-modify-playback-state user-read-currently-playing';
        $authURL = "https://accounts.spotify.com/authorize?response_type=code&client_id=$client_id&scope=$scopes&redirect_uri=$redirect_uri";
    }
} else {
    // User not linked, set authorization URL
    $scopes = 'user-read-playback-state user-modify-playback-state user-read-currently-playing';
    $authURL = "https://accounts.spotify.com/authorize?response_type=code&client_id=$client_id&scope=$scopes&redirect_uri=$redirect_uri";
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <!-- Headder -->
        <?php include('header.php'); ?>
        <!-- /Headder -->
    </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
    <br>
    <!-- Info Box for Beta Feature -->
    <div class="notification is-warning">
        Beta Feature: This Spotify linking feature is currently in beta and is available from Version 5.1+. Your feedback is appreciated as we improve this functionality.
    </div>
    <?php if ($message): ?>
        <div class="notification <?php echo $messageType; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($spotifyUserInfo) && isset($spotifyUserInfo['display_name'])): ?>
        <h2 class="subtitle">Spotify Profile Information:</h2>
        <p>Spotify Username: <?php echo htmlspecialchars($spotifyUserInfo['display_name']); ?></p>
        <p>Spotify ID: <?php echo htmlspecialchars($spotifyUserInfo['id']); ?></p>
        <?php if (!empty($spotifyUserInfo['images'][0]['url'])): ?>
            <img id='profile-image' class='round-image' src="<?php echo $spotifyUserInfo['images'][0]['url']; ?>" width="100" height="100" alt="<?php echo htmlspecialchars($spotifyUserInfo['id']); ?> spotify profile picture">
        <?php else: ?>
            <p><em>No profile image available.</em></p>
        <?php endif; ?>
    <?php else: ?>
        <!-- Relink Spotify Account Button -->
        <div class="notification is-info">
            <p>It looks like your Spotify account needs to be linked. Click the button below to start the linking process.</p>
            <a href="<?php echo $authURL; ?>" class="button is-primary">Relink Spotify Account</a>
        </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>