<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
$api_key = $user['api_key'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
$statusOutput = 'Bot Status: Unknown';
$betaStatusOutput = 'Bot Status: Unknown';
$pid = '';
$versionRunning = '';
$betaVersionRunning = '';
include 'bot_control.php';
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
  $BotModMessage = "<p class='has-text-success'>BotOfTheSpecter is a mod on your channel, there is nothing more you need to do.</p>";
} else {
  $BotModMessage = "<p class='has-text-danger'>BotOfTheSpecter is not a mod on your channel, please mod the bot on your channel before moving forward.</p>";
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <!-- Header -->
    <?php include('header.php'); ?>
    <!-- /Header -->
  </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
  <h1 class="title"><?php echo "$greeting, $twitchDisplayName <img id='profile-image' class='round-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
  <br>
  <?php echo $BotModMessage; ?>
  <?php if ($betaAccess) { echo "<p class='has-text-danger'>If you wish to start the Beta Version of the bot, please ensure that the Stable Bot is stopped first as this will cause two sets of data and will cause issues.</p><br>"; } ?>
  <div class="box-container">
    <div class="bot-box" id="bot-status">
      <h4 class="title is-4">Stable Bot:</h4>
      <?php echo $statusOutput; ?>
      <?php echo $versionRunning; ?>
      <br>
      <div class="buttons">
        <form action="" method="post">
          <button class="button is-danger" type="submit" name="killBot">Stop Bot</button>
        </form>
        <form action="" method="post">
          <button class="button is-success" type="submit" name="runBot">Run Bot</button>
        </form>
        <form action="" method="post">
          <button class="button is-warning" type="submit" name="restartBot">Restart Bot</button>
        </form>
        <br>
      </div>
    </div>
    <?php if ($betaAccess) { ?>
    <div class="bot-box" id="beta-bot-status">
      <h4 class="title is-4">Beta Bot: (<?php echo "V" . $betaNewVersion; ?>)</h4>
      <?php echo $betaStatusOutput; ?>
      <?php echo $betaVersionRunning; ?>
      <br>
      <div class="buttons">
        <form action="" method="post">
          <button class="button is-danger" type="submit" name="killBetaBot">Stop Beta Bot</button>
        </form>
        <form action="" method="post">
          <button class="button is-success" type="submit" name="runBetaBot">Run Beta Bot</button>
        </form>
        <form action="" method="post">
          <button class="button is-warning" type="submit" name="restartBetaBot">Restart Beta Bot</button>
        </form>
        <br>
      </div>
    </div>
    <?php } ?>
    <div class="bot-box" id="discord-bot-status">
      <h4 class="title is-4">Discord Bot:</h4>
      <?php echo $discordStatusOutput; ?>
      <?php echo $discordVersionRunning; ?>
      <br>
      <div class="buttons">
        <form action="" method="post">
          <button class="button is-danger" type="submit" name="killDiscordBot">Stop Discord Bot</button>
        </form>
        <form action="" method="post">
          <button class="button is-success" type="submit" name="runDiscordBot">Run Discord Bot</button>
        </form>
        <form action="" method="post">
          <button class="button is-warning" type="submit" name="restartDiscordBot">Restart Discord Bot</button>
        </form>
        <br>
      </div>
    </div>
  </div>
</div>

<script>
  window.addEventListener('error', function(event) {
    console.error('Error message:', event.message);
    console.error('Script error:', event.filename, 'line:', event.lineno, 'column:', event.colno);
  });
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>