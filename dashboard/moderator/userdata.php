<?php
$access_token = $_SESSION['access_token'];

// No Access Token, login
if (!isset($access_token)) {
    // Handle the case where the access token is not set
    error_log("Access token not found in session."); 
    header("Location: login.php"); // Redirect to login page
    exit();
}

$userSTMT = $conn->prepare("SELECT * FROM users WHERE access_token = ?");
$userSTMT->bind_param("s", $access_token);
if (!$userSTMT->execute()) {
    // Handle database query error
    error_log("Database query failed: " . $userSTMT->error);
    die("An error occurred."); 
}
$userResult = $userSTMT->get_result();
if ($userResult->num_rows === 0) {
    // Handle case where user is not found
    error_log("User not found with access token: " . $access_token);
    header("Location: login.php"); // Redirect to login page
    exit(); 
}

$user = $userResult->fetch_assoc();
$user_id = $user['id'];
$username = $user['username'];
$twitchDisplayName = $user['twitch_display_name'];
$twitch_profile_image_url = $user['profile_image'];
$email = $user['email'];
$is_admin = ($user['is_admin'] == 1);
$betaAccess = ($user['beta_access'] == 1);
$twitchUserId = $user['twitch_user_id'];
$refreshToken = $user['refresh_token'];
$api_key = $user['api_key'];
$timezone = 'Australia/Sydney';
$broadcasterID = $twitchUserId;
$authToken = $access_token;

// Cache user data in session
$_SESSION['user_id'] = $user_id;
$_SESSION['twitchUserId'] = $twitchUserId;
$_SESSION['user_data'] = $user;
?>
