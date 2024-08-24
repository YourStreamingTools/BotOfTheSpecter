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

// Fetch Discord user data
$discordUserSTMT = $conn->prepare("SELECT guild_id, live_channel_id FROM discord_users WHERE user_id = ?");
$discordUserSTMT->bind_param("i", $user_id);
$discordUserSTMT->execute();
$discordUserResult = $discordUserSTMT->get_result();
$discordUser = $discordUserResult->fetch_assoc();
$guild_id = $discordUser['guild_id'] ?? null;
$live_channel_id = $discordUser['live_channel_id'] ?? null;

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
  <br>
  <div class="columns is-desktop is-multiline box-container">
    <div class="column is-5 bot-box" id="bot-status">
      <h4 class="title is-4">Stable Bot: (<?php echo "V" . $newVersion; ?>)</h4>
      <?php echo $statusOutput; ?>
      <?php echo $versionRunning; ?><br>
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
    <div class="column is-5 bot-box" id="beta-bot-status">
      <h4 class="title is-4">Beta Bot: (<?php echo "V" . $betaNewVersion . "B"; ?>)</h4>
      <?php echo $betaStatusOutput; ?>
      <?php echo $betaVersionRunning; ?><br>
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
    <?php if ($guild_id && $live_channel_id) { ?>
    <div class="column is-5 bot-box" id="discord-bot-status">
      <h4 class="title is-4">Discord Bot:</h4>
      <?php echo $discordStatusOutput; ?><br>
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
    <?php } ?>
    <div class="column is-5 bot-box">
      <h4 class="title is-4" style="text-align: center;">Websocket Notices
        <span id="heartbeatIcon" style="margin-left: 10px;">
          <i id="heartbeat" class="fas fa-heartbeat" style="color: green;"></i>
        </span>
      </h4>
      <div style="display: flex; align-items: center; margin-bottom: 10px;">
        <div class="buttons" style="position: relative; display: inline-block; cursor: pointer;">
          <button class="button is-primary" onclick="sendStreamEvent('STREAM_ONLINE')">Mark Stream as Online</button>
          <span id="onlineTooltip" style="visibility: hidden; width: 120px; background-color: #555; color: #fff; text-align: center; border-radius: 6px; padding: 5px 0; position: absolute; z-index: 1; bottom: 125%; left: 50%; margin-left: -60px; opacity: 0; transition: opacity 0.3s;">Online Event Sent!</span>
        </div>
      </div>
      <div style="display: flex; align-items: center;">
        <div class="buttons" style="position: relative; display: inline-block; cursor: pointer;">
          <button class="button is-danger" onclick="sendStreamEvent('STREAM_OFFLINE')">Mark Stream as Offline</button>
          <span id="offlineTooltip" style="visibility: hidden; width: 120px; background-color: #555; color: #fff; text-align: center; border-radius: 6px; padding: 5px 0; position: absolute; z-index: 1; bottom: 125%; left: 50%; margin-left: -60px; opacity: 0; transition: opacity 0.3s;">Offline Event Sent!</span>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
window.addEventListener('error', function(event) {
  console.error('Error message:', event.message);
  console.error('Script error:', event.filename, 'line:', event.lineno, 'column:', event.colno);
});

function sendStreamOnlineEvent() {
  sendStreamEvent('STREAM_ONLINE');
}

function sendStreamOfflineEvent() {
  sendStreamEvent('STREAM_OFFLINE');
}

function sendStreamEvent(eventType) {
  const xhr = new XMLHttpRequest();
  const url = 'notify_event.php';
  const params = `event=${eventType}&api_key=<?php echo $api_key; ?>`;
  xhr.open("POST", url, true);
  xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
  xhr.onreadystatechange = function () {
    if (xhr.readyState === 4 && xhr.status === 200) {
      try {
        const response = JSON.parse(xhr.responseText);
        if (response.success) {
          console.log(`${eventType} event sent successfully.`);
          showTooltip(eventType);
        } else {
          console.error(`Error sending ${eventType} event: ${response.message}`);
        }
      } catch (e) {
        console.error('Error parsing JSON response:', e);
        console.error('Response:', xhr.responseText);
      }
    } else if (xhr.readyState === 4) {
      console.error(`Error sending ${eventType} event: ${xhr.responseText}`);
    }
  };
  xhr.send(params);
}

function showTooltip(eventType) {
  const tooltipId = eventType === 'STREAM_ONLINE' ? 'onlineTooltip' : 'offlineTooltip';
  const tooltip = document.getElementById(tooltipId);
  tooltip.style.visibility = 'visible';
  tooltip.style.opacity = '1';
  setTimeout(() => {
    tooltip.style.visibility = 'hidden';
    tooltip.style.opacity = '0';
  }, 2000);
}

function checkHeartbeat() {
  fetch('https://api.botofthespecter.com/websocket/heartbeat')
    .then(response => response.json())
    .then(data => {
      const heartbeatIcon = document.getElementById('heartbeat');
      if (data.status === 'OK') {
        heartbeatIcon.className = 'fas fa-heartbeat beating';
        heartbeatIcon.style.color = 'green';
      } else {
        heartbeatIcon.className = 'fas fa-heart-broken';
        heartbeatIcon.style.color = 'red';
      }
    })
    .catch(error => {
      const heartbeatIcon = document.getElementById('heartbeat');
      heartbeatIcon.className = 'fas fa-heart-broken';
      heartbeatIcon.style.color = 'red';
    });
}
// Check heartbeat every 5 seconds
setInterval(checkHeartbeat, 5000);
// Initial check
checkHeartbeat();
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>