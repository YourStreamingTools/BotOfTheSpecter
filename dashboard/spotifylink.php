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

// Connect to database
require_once "db_connect.php";

// Fetch the user's data from the database based on the access_token
$access_token = $_SESSION['access_token'];
$userSTMT = $conn->prepare("SELECT * FROM users WHERE access_token = ?");
$userSTMT->bind_param("s", $access_token);
$userSTMT->execute();
$userResult = $userSTMT->get_result();
$user = $userResult->fetch_assoc();
$user_id = $user['id'];
$username = $user['username'];
$twitchDisplayName = $user['twitch_display_name'];
$twitch_profile_image_url = $user['profile_image'];
$is_admin = ($user['is_admin'] == 1);
$twitchUserId = $user['twitch_user_id'];
$broadcasterID = $twitchUserId;
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$api_key = $user['api_key'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';

// Set variables
$client_secret = ''; // CHANGE TO MAKE THIS WORK
$client_id = ''; // CHANGE TO MAKE THIS WORK
$redirect_uri = 'https://dashboard.botofthespecter.com/spotifylink.php';
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
        // Save the tokens in the database linked to the user
        $insertSTMT = $conn->prepare("INSERT INTO spotify_tokens (user_id, access_token, refresh_token) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), refresh_token = VALUES(refresh_token)");
        $insertSTMT->bind_param("iss", $user_id, $access_token, $refresh_token);
        $insertSTMT->execute();
        $message = "Your Spotify account has been successfully linked!";
        $messageType = "is-success";
    } else {
        $message = "Failed to retrieve tokens from Spotify. Please try again.";
        $messageType = "is-danger";
    }
}

// Check if user is already linked to Spotify
$spotifySTMT = $conn->prepare("SELECT access_token, refresh_token FROM spotify_tokens WHERE user_id = ?");
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
    }
} else {
    // User is not linked, proceed with authorization flow
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
    <h1 class="title"><?php echo "$greeting, $twitchDisplayName <img id='profile-image' class='round-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
    <br>
    <?php if ($message): ?>
        <div class="notification <?php echo $messageType; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($spotifyUserInfo) && isset($spotifyUserInfo['display_name'])): ?>
        <h2 class="subtitle">Spotify Profile Information:</h2>
        <p><strong>Spotify Username:</strong> <?php echo htmlspecialchars($spotifyUserInfo['display_name']); ?></p>
        <p><strong>Spotify ID:</strong> <?php echo htmlspecialchars($spotifyUserInfo['id']); ?></p>
        <?php if (!empty($spotifyUserInfo['images'][0]['url'])): ?>
            <img src="<?php echo $spotifyUserInfo['images'][0]['url']; ?>" width="100" height="100" alt="Spotify Profile Picture">
        <?php else: ?>
            <p><em>No profile image available.</em></p>
        <?php endif; ?>
    <?php else: ?>
        <!-- Relink Spotify Account Button -->
        <div class="notification is-info">
            <p><strong>It looks like your Spotify account needs to be linked.</strong></p>
            <a href="<?php echo $authURL; ?>" class="button is-primary">Relink Spotify Account</a>
        </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>