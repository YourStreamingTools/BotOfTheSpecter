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
$setupMessage = "";
if ($ModStatusOutput) {
  $BotModMessage = "<p class='has-text-success'>BotOfTheSpecter is a mod on your channel, there is nothing more you need to do.</p>";
} else {
  $BotModMessage = "<p class='has-text-danger'>BotOfTheSpecter is not a mod on your channel, please mod the bot on your channel before moving forward.</p><br>
  <form method='post'>
      <button class='button is-success bot-button' type='submit' name='setupBot'>Run Setup</button>
  </form>";
}

// When the setup button is clicked
if (isset($_POST['setupBot'])) {
  // Escape shell arguments to ensure they are safely passed to the command
  $escapedUsername = escapeshellarg($username);
  $escapedTwitchUserId = escapeshellarg($twitchUserId);
  $escapedAuthToken = escapeshellarg($authToken);
  // Run the setup script with shell_exec and pass the escaped arguments
  shell_exec("python3 /var/www/bot/setup.py -channel $escapedUsername -channelid $escapedTwitchUserId -token $escapedAuthToken 2>&1");
  // Add a message or feedback to the user while processing the request
  $setupMessage = "<p>Running setup, please wait...</p>";
  // Add a delay before refreshing the page
  sleep(3);
  // Refresh the page after the delay
  echo '<script type="text/javascript">
            setTimeout(function(){
                window.location.reload(1);
            }, 500); // Refresh after 0.5 seconds
        </script>';
}
$today = new DateTime();
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
  <?php echo $setupMessage; ?>
  <?php if ($betaAccess) { echo "<p class='has-text-danger'>If you wish to start the Beta Version of the bot, please ensure that the Stable Bot is stopped first as this will cause two sets of data and will cause issues.</p><br>"; } ?>
  <br>
  <div class="columns is-desktop is-multiline box-container">
    <!-- Stable Bot Section -->
    <div class="column is-5 bot-box" id="bot-status">
      <h4 class="title is-4">Stable Bot: (<?php echo "V" . $newVersion; ?>)</h4>
      <?php echo $statusOutput; ?>
      <?php echo $versionRunning; ?><br>
      <div class="buttons">
        <form action="" method="post">
          <button class="button is-danger bot-button" type="submit" name="killBot">Stop Bot</button>
        </form>
        <form action="" method="post">
          <button class="button is-success bot-button" type="submit" name="runBot">Run Bot</button>
        </form>
        <form action="" method="post">
          <button class="button is-warning bot-button" type="submit" name="restartBot">Restart Bot</button>
        </form>
      </div>
    </div>
    <!-- Beta Bot Section -->
    <?php if ($betaAccess) { ?>
    <div class="column is-5 bot-box" id="beta-bot-status">
      <h4 class="title is-4">Beta Bot: (<?php echo "V" . $betaNewVersion . "B"; ?>)</h4>
      <?php echo $betaStatusOutput; ?>
      <?php echo $betaVersionRunning; ?><br>
      <div class="buttons">
        <form action="" method="post">
          <button class="button is-danger bot-button" type="submit" name="killBetaBot">Stop Beta Bot</button>
        </form>
        <form action="" method="post">
          <button class="button is-success bot-button" type="submit" name="runBetaBot">Run Beta Bot</button>
        </form>
        <form action="" method="post">
          <button class="button is-warning bot-button" type="submit" name="restartBetaBot">Restart Beta Bot</button>
        </form>
      </div>
    </div>
    <?php } ?>
    <!-- Discord Bot Section -->
    <?php if ($guild_id && $live_channel_id) { ?>
    <div class="column is-5 bot-box" id="discord-bot-status">
      <h4 class="title is-4">Discord Bot:</h4>
      <?php echo $discordStatusOutput; ?><br>
      <div class="buttons">
        <form action="" method="post">
          <button class="button is-danger bot-button" type="submit" name="killDiscordBot">Stop Discord Bot</button>
        </form>
        <form action="" method="post">
          <button class="button is-success bot-button" type="submit" name="runDiscordBot">Run Discord Bot</button>
        </form>
        <form action="" method="post">
          <button class="button is-warning bot-button" type="submit" name="restartDiscordBot">Restart Discord Bot</button>
        </form>
      </div>
    </div>
    <?php } ?>
    <!-- Websocket Notices Section -->
    <div class="column is-5 bot-box">
      <h4 class="title is-4" style="text-align: center;">Websocket Notices
        <span id="heartbeatIcon" style="margin-left: 10px;">
          <i id="heartbeat" class="fas fa-heartbeat" style="color: green;"></i>
        </span>
      </h4>
      <div style="display: flex; align-items: center; margin-bottom: 10px;">
        <div class="buttons" style="position: relative; display: inline-block; cursor: pointer;">
          <button class="button is-primary bot-button" onclick="sendStreamEvent('STREAM_ONLINE')">Mark Stream as Online</button>
          <span id="onlineTooltip" style="visibility: hidden; width: 120px; background-color: #555; color: #fff; text-align: center; border-radius: 6px; padding: 5px 0; position: absolute; z-index: 1; bottom: 125%; left: 50%; margin-left: -60px; opacity: 0; transition: opacity 0.3s;">Online Event Sent!</span>
        </div>
      </div>
      <div style="display: flex; align-items: center;">
        <div class="buttons" style="position: relative; display: inline-block; cursor: pointer;">
          <button class="button is-danger bot-button" onclick="sendStreamEvent('STREAM_OFFLINE')">Mark Stream as Offline</button>
          <span id="offlineTooltip" style="visibility: hidden; width: 120px; background-color: #555; color: #fff; text-align: center; border-radius: 6px; padding: 5px 0; position: absolute; z-index: 1; bottom: 125%; left: 50%; margin-left: -60px; opacity: 0; transition: opacity 0.3s;">Offline Event Sent!</span>
        </div>
      </div>
    </div>
    <!-- API System -->
    <div class="column is-5 bot-box">
      <h4 class="title is-4 has-text-centered">API Limits</h4>
      <div class="status-message" style="font-size: 18px; padding: 15px; background-color: #2c3e50; color: #ecf0f1; border-radius: 8px;">
        <!-- Song Identification Section -->
        <div class="api-section" style="padding-bottom: 15px; border-bottom: 1px solid #7f8c8d; margin-bottom: 15px;">
          <?php
          $shazamFile = "/var/www/api/shazam.txt";
          $shazam_reset_day = 23;
          $today = new DateTime(); // Initialize today's date
          if ($today->format('d') >= $shazam_reset_day) {
            $shazam_next_reset = new DateTime('first day of next month');
            $shazam_next_reset->setDate($shazam_next_reset->format('Y'), $shazam_next_reset->format('m'), $shazam_reset_day);
          } else {
            $shazam_next_reset = new DateTime($today->format('Y-m') . "-$shazam_reset_day");
          }
          $days_until_reset = $today->diff($shazam_next_reset)->days;
          $reset_date_shazam = $shazam_next_reset->format('F j, Y');
          if (file_exists($shazamFile)) {
            $shazam_requests_remaining = file_get_contents($shazamFile);
            $last_modified_shazam = date("F j, Y, g:i A T", filemtime($shazamFile));
            if (is_numeric($shazam_requests_remaining)) {
              echo "<p style='color: #1abc9c;'>Song Identifications Left: <span style='color: #e74c3c;'>" . $shazam_requests_remaining . "</span> 
              (<span title='Next reset date: $reset_date_shazam'>" . $days_until_reset . " days until reset</span>)</p>";
            } else {
              echo "<p style='color: #e74c3c;'>Sorry, I can't seem to find how many requests are left.</p>";
            }
            echo "<p>Last checked: <span style='color: #f39c12;'>$last_modified_shazam</span></p>";
          } else {
            echo "<p style='color: #e74c3c;'>No song identification data available.</p>";
          }
          ?>
        </div>
        <!-- Exchange Rate Section -->
        <div class="api-section" style="padding-bottom: 15px; border-bottom: 1px solid #7f8c8d; margin-bottom: 15px;">
          <?php
          $exchangerateFile = "/var/www/api/exchangerate.txt";
          $exchangerate_reset_day = 14;
          if ($today->format('d') >= $exchangerate_reset_day) {
            $exchangerate_next_reset = new DateTime('first day of next month');
            $exchangerate_next_reset->setDate($exchangerate_next_reset->format('Y'), $exchangerate_next_reset->format('m'), $exchangerate_reset_day);
          } else {
            $exchangerate_next_reset = new DateTime($today->format('Y-m') . "-$exchangerate_reset_day");
          }
          $days_until_reset = $today->diff($exchangerate_next_reset)->days;
          $reset_date_exchangerate = $exchangerate_next_reset->format('F j, Y');
          if (file_exists($exchangerateFile)) {
            $exchangerate_requests_remaining = file_get_contents($exchangerateFile);
            $last_modified_exchangerate = date("F j, Y, g:i A T", filemtime($exchangerateFile));
            if (is_numeric($exchangerate_requests_remaining)) {
              echo "<p style='color: #1abc9c;'>Exchange Rate Checks Left: <span style='color: #e74c3c;'>" . $exchangerate_requests_remaining . "</span> 
              (<span title='Next reset date: $reset_date_exchangerate'>" . $days_until_reset . " days until reset</span>)</p>";
            } else {
              echo "<p style='color: #e74c3c;'>Sorry, I can't seem to find how many requests are left.</p>";
            }
            echo "<p>Last checked: <span style='color: #f39c12;'>$last_modified_exchangerate</span></p>";
          } else {
            echo "<p style='color: #e74c3c;'>No exchange rate data available.</p>";
          }
          ?>
        </div>
        <!-- Weather Usage Section -->
        <div class="api-section">
          <?php
          $weatherFile = "/var/www/api/weather.txt";
          // Calculate midnight of the next day
          $midnight = new DateTime('tomorrow midnight');
          // Calculate the time remaining until midnight
          $time_until_midnight = $today->diff($midnight);
          $hours_until_midnight = $time_until_midnight->h;
          $minutes_until_midnight = $time_until_midnight->i;
          $seconds_until_midnight = $time_until_midnight->s;
          if (file_exists($weatherFile)) {
            $weather_requests_remaining = file_get_contents($weatherFile);
            $last_modified_weather = date("F j, Y, g:i A T", filemtime($weatherFile));
            if (is_numeric($weather_requests_remaining)) {
              echo "<p style='color: #1abc9c;'>Weather Requests Left: <span style='color: #e74c3c;'>" . $weather_requests_remaining . "</span><br> 
              (<span title='Resets at midnight'>" . $hours_until_midnight . " hours, " . $minutes_until_midnight . " minutes, and " . $seconds_until_midnight . " seconds until reset</span>)</p>";
            } else {
              echo "<p style='color: #e74c3c;'>Sorry, I can't seem to find how many requests are left.</p>";
            }
            echo "<p>Last checked: <span style='color: #f39c12;'>$last_modified_weather</span></p>";
          } else {
            echo "<p style='color: #e74c3c;'>No weather requests data available.</p>";
          }
          ?>
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