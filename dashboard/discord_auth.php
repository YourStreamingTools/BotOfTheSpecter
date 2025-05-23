<?php
// Start session
session_start();

// Connect to database
require_once "/var/www/config/db_connect.php";
include "/var/www/config/discord.php";

// Get the authorization code from Discord redirect
$code = $_GET['code'];

// Exchange the authorization code for an access token
$token_url = 'https://discord.com/api/oauth2/token';
$data = array(
    'client_id' => $client_id,
    'client_secret' => $client_secret,
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => 'https://dashboard.botofthespecter.com/discord_auth.php',
    'scope' => 'identify'
);

$options = array(
    'http' => array(
        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
        'method' => 'POST',
        'content' => http_build_query($data)
    )
);

$context = stream_context_create($options);
$response = file_get_contents($token_url, false, $context);
$params = json_decode($response, true);

// Check if access token was received successfully
if (isset($params['access_token'])) {
    // Get user information using the access token
    $user_url = 'https://discord.com/api/users/@me';
    $token = $params['access_token'];
    $user_options = array(
        'http' => array(
            'header' => "Authorization: Bearer $token\r\n",
            'method' => 'GET'
        )
    );
    $user_context = stream_context_create($user_options);
    $user_response = file_get_contents($user_url, false, $user_context);
    $user_data = json_decode($user_response, true);
    // Save user information to the database
    if (isset($user_data['id'])) {
        $access_token = $_SESSION['access_token'];
        $userSTMT = $conn->prepare("SELECT * FROM users WHERE access_token = ?");
        $userSTMT->bind_param("s", $access_token);
        $userSTMT->execute();
        $userResult = $userSTMT->get_result();
        $user = $userResult->fetch_assoc();
        $twitchUserId = $user['id'];
        $discord_id = $user_data['id'];
        $sql = "INSERT INTO discord_users (user_id, discord_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE discord_id = VALUES(discord_id)";
        $insertStmt = $conn->prepare($sql);
        $insertStmt->bind_param("is", $twitchUserId, $discord_id);
        if ($insertStmt->execute()) {
            // Redirect back to discordbot.php
            header('Location: discordbot.php');
        } else {
            echo "Error inserting or updating data: " . $conn->error;
        }
    } else {
        echo "Error: Failed to retrieve user information from Discord API.";
    }
} else {
    echo "Error: Failed to retrieve access token from Discord API.";
}

// Close MySQL connection
$conn->close();
?>