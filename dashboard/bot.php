<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL); ?>
<?php
// Initialize the session
session_start();

// check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

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
$webhookPort = $user['webhook_port'];
$websocketPort = $user['websocket_port'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
$statusOutput = 'Bot Status: Unknown';
$pid = '';
include 'bot_control.php';

// Twitch API URL
$modurl = "https://api.twitch.tv/helix/moderation/moderators?broadcaster_id={$broadcasterID}";
$clientID = ''; // CHANGE TO MAKE THIS WORK

$ch = curl_init($modurl);
$headers = [
    "Client-ID: {$clientID}",
    "Authorization: Bearer {$authToken}"
];

curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);

$BotIsMod = false; // Default to false until we know for sure

if ($response === false) {
    // Handle error - you might want to log this or take other action
    $error = 'Curl error: ' . curl_error($ch);
} else {
    // Decode the response
    $responseData = json_decode($response, true);
    if (isset($responseData['data'])) {
        // Check if the user is in the list of moderators
        foreach ($responseData['data'] as $mod) {
            if ($mod['user_login'] === 'botofthespecter') {
                $BotIsMod = true;
                break;
            }
        }
    } else {
        // Handle unexpected response format
        $error = 'Unexpected response format.';
    }
}
curl_close($ch);
$ModStatusOutput = $BotIsMod;
$BotModMessage = "";
if ($ModStatusOutput) {
  $BotModMessage = "<p style='color: green;'>BotOfTheSpecter is a mod on your channel, there is nothing more you need to do.</p>";
} else {
  $BotModMessage = "<p style='color: red;'>BotOfTheSpecter is not a mod on your channel, please mod the bot on your channel before moving forward.</p>";
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BotOfTheSpecter - Dashboard</title>
    <link rel="stylesheet" href="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.min.css">
    <link rel="stylesheet" href="https://cdn.yourstreaming.tools/css/custom.css">
    <link rel="stylesheet" href="pagination.css">
    <script src="about.js"></script>
  	<link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
  	<link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <!-- <?php echo "User: $username | $twitchUserId | $authToken | Webhook Port: $webhookPort | WebShocket Port: $websocketPort"; ?> -->
	<!-- <?php echo "python $botScriptPath -channel $username -channelid $twitchUserId -token $authToken -port $webhookPort"; ?> -->
  </head>
<body>
<!-- Navigation -->
<?php include('header.php'); ?>
<!-- /Navigation -->

<div class="row column">
<br>
<h1><?php echo "$greeting, $twitchDisplayName <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
<br>
<?php echo $BotModMessage; ?>
<?php echo $statusOutput; ?>
<br>
<table class="bot-table">
  <tr>
    <td><form action="" method="post"><button class="bot-button" type="submit" name="runBot">Run Bot</button></form></td>
    <td><form action="" method="post"><button class="bot-button" type="submit" name="botStatus">Check Bot Status</button></form></td>
    <td><form action="" method="post"><button class="bot-button" type="submit" name="killBot">Stop Bot</button></form></td>
    <td><form action="" method="post"><button class="bot-button" type="submit" name="restartBot">Restart Bot</button></form></td>
  </tr>
</table>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
</body>
</html>