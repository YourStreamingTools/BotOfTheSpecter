<?php
ini_set('display_errors', 1); 
ini_set('display_startup_errors', 1); 
error_reporting(E_ALL);

// Connect to database
require_once "db_connect.php";

// Get the authorization code from Discord redirect
$code = $_GET['code'];

// Exchange the authorization code for an access token
$token_url = 'https://discord.com/api/oauth2/token';
$data = array(
    'client_id' => '', // CHANGE TO MAKE THIS WORK
    'client_secret' => '', // CHANGE TO MAKE THIS WORK
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
$discord_id = $user_data['id'];
$username = $user_data['username'];
$discriminator = $user_data['discriminator'];
$avatar = $user_data['avatar'];

$sql = "INSERT INTO users (discord_id, username, discriminator, avatar) VALUES ('$discord_id', '$username', '$discriminator', '$avatar')";

if ($conn->query($sql) === TRUE) {
    // Redirect back to discordbot.php
    header('Location: discordbot.php');
} else {
    echo "Error: " . $sql . "<br>" . $conn->error;
}

// Close MySQL connection
$conn->close();
?>