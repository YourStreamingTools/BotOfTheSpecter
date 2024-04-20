<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL); ?>
<?php
// Initialize the session
session_start();

// check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Dashboard";

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
$betaAccess = ($user['beta_access'] == 1);
$twitchUserId = $user['twitch_user_id'];
$broadcasterID = $twitchUserId;
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$webhookPort = $user['webhook_port'];
$websocketPort = $user['websocket_port'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
$statusOutput = 'Bot Status: Unknown';
$betaStatusOutput = 'Bot Status: Unknown';
$pid = '';
$versionRunning = '';
$betaVersionRunning = '';
include 'bot_control.php';
include 'beta_bot_control.php';
include 'sqlite.php';

// Twitch API URL
$checkMod = "https://api.twitch.tv/helix/moderation/moderators?broadcaster_id={$broadcasterID}";
$addMod = "https://api.twitch.tv/helix/moderation/moderators?broadcaster_id={$broadcasterID}&user_id=971436498";
$clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';

$checkModConnect = curl_init($checkMod);
$headers = [
    "Client-ID: {$clientID}",
    "Authorization: Bearer {$authToken}"
];

curl_setopt($checkModConnect, CURLOPT_HTTPHEADER, $headers);
curl_setopt($checkModConnect, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($checkModConnect);

$BotIsMod = false; // Default to false until we know for sure

if ($response === false) {
    // Handle error - you might want to log this or take other action
    $error = 'Curl error: ' . curl_error($checkModConnect);
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
curl_close($checkModConnect);
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
    <!-- Headder -->
    <?php include('header.php'); ?>
    <!-- /Headder -->
  </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="row column">
<br>
<h1><?php echo "$greeting, $twitchDisplayName <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
<br>
<?php echo $BotModMessage; ?>
<?php if ($betaAccess) { echo "<p style='color: red;'>If you wish to start the Beta Version of the bot, please ensure that the Stable Bot is stopped first as this will cause two sets of data and will cause issues.</p>"; } ?>
<div class="dashboard-container">
  <!-- Bot Status -->
  <div class="dashboard-section" id="bot-status">
    <h4>Stable Bot:</h4>
    <?php echo $statusOutput; ?>

    <?php echo $versionRunning; ?>

    <div class="bot-actions">
      <form action="" method="post"><button class="bot-button" type="submit" name="killBot">Stop Bot</button></form>
      <form action="" method="post"><button class="bot-button" type="submit" name="runBot">Run Bot</button></form>
      <form action="" method="post"><button class="bot-button" type="submit" name="restartBot">Restart Bot</button></form>
    </div>
  </div>
</div>
<?php if ($betaAccess) { ?>
<div class="dashboard-container">
  <!-- Beta Bot Status -->
  <div class="dashboard-section" id="beta-bot-status">
    <h4>Beta Bot:</h4>
    <?php echo $betaStatusOutput; ?>

    <?php echo $betaVersionRunning; ?>

    <div class="bot-actions">
      <form action="" method="post"><button class="bot-button" type="submit" name="killBetaBot">Stop Beta Bot</button></form>
      <form action="" method="post"><button class="bot-button" type="submit" name="runBetaBot">Run Beta Bot</button></form>
      <form action="" method="post"><button class="bot-button" type="submit" name="restartBetaBot">Restart Beta Bot</button></form>
    </div>
  </div>
</div>
<?php } ?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
</body>
</html>