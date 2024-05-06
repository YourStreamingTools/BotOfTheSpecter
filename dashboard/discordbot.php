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
$title = "Discord Bot";

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
include 'sqlite.php';
include 'bot_control.php';
include 'beta_bot_control.php';

// Check if the user is already linked with Discord
$discord_userSTMT = $conn->prepare("SELECT * FROM discord_users WHERE user_id = ?");
$discord_userSTMT->bind_param("i", $user_id);
$discord_userSTMT->execute();
$discord_userResult = $discord_userSTMT->get_result();
$is_linked = ($discord_userResult->num_rows > 0);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  // Get the option and webhook URL from the form
  $option = $_POST['option'];
  $webhook = $_POST['webhook'];

  // Define the profile key based on the selected option
  $profile_key = "";
  switch ($option) {
      case '1':
          $profile_key = "discord_alert";
          break;
      case '2':
          $profile_key = "discord_mod";
          break;
      case '3':
          $profile_key = "discord_alert_online";
          break;
      default:
        $buildStatus = "Invalid option";
          exit;
  }

  // Insert the webhook URL into the database
  $stmt = $db->prepare("UPDATE profile SET $profile_key = :webhook");
  $stmt->bindParam(':webhook', $webhook);
  if ($stmt->execute()) {
    $buildStatus = "Webhook URL added successfully";
  } else {
    $buildStatus = "Error adding webhook URL: " . $stmt->errorInfo()[2];
  }

  $stmt->closeCursor();
}
$buildStatus = "";
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

<div class="row column">
<br>
<h1><?php echo "$greeting, $twitchDisplayName <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
<br>
<?php if (!$is_linked) {
  echo '<h3>Linking your discord account to Specter will allow you to do some really cool stuff.</h3>';
  echo '<button class="defult-button" onclick="linkDiscord()">Link Discord</button>';
} else { ?>
  <h4>Thanks for linking your account, this is not required but if you'd like to add the bot itself to your discord server, you can by clicking the button below.</h4>
  <button class="defult-button" onclick="discordBotInvite()">BotOfTheSpecter Discord Bot Invite</button>
  <br><br><br>
  <div class="container">
  <h2>Add Discord Webhook URL</h2>
    <form action="add_webhook.php" method="post">
        <label for="option">Select an option:</label>
        <select id="option" name="option">
            <option value="1">Discord Alert (For Twitch Logs)</option>
            <option value="2">Discord Mod Alert (For Mod logs from Twitch)</option>
            <option value="3">Discord Alert Online (For posting in discord when the stream is online)</option>
        </select>
        <label for="webhook">Discord Webhook URL:</label>
        <input type="text" id="webhook" name="webhook" required>
        <input type="submit" value="Submit"><br><br><br>
    </form>
    <?php echo $buildStatus; ?>
  </div>
<?php } ?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
<?php if (!$is_linked) {
  echo '<script>function linkDiscord() { window.location.href = "https://discord.com/oauth2/authorize?client_id=1170683250797187132&response_type=code&redirect_uri=https%3A%2F%2Fdashboard.botofthespecter.com%2Fdiscord_auth.php&scope=identify+openid+guilds"; } </script>';
  } else { echo '<script>function discordBotInvite() { window.open("https://discord.com/oauth2/authorize?client_id=1170683250797187132&scope=applications.commands%20bot&permissions=8", "_blank"); } </script>'; } ?>

</body>
</html>