<?php 
// Initialize the session
session_start();
$today = new DateTime();

// Check if the user is logged in
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

// Twitch API to check if the bot is modded
$checkMod = "https://api.twitch.tv/helix/moderation/moderators?broadcaster_id={$broadcasterID}";
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
$BotModMessage = "";
$setupMessage = "";
$showButtons = false;

if ($response === false) {
  // Handle error - you might want to log this or take other action
  $error = 'Curl error: ' . curl_error($checkModConnect);
} else {
  // Decode the response
  $responseData = json_decode($response, true);
  // Check if we received a 401 Unauthorized error with an invalid OAuth token message
  if (isset($responseData['status']) && $responseData['status'] === 401 && isset($responseData['message']) && $responseData['message'] === "Invalid OAuth token") {
    // Display a notification for session expiration and require re-login
    $BotModMessage = '<div class="notification is-danger has-text-black has-text-weight-bold">Your Twitch login session has expired. Please log in again to continue.
                        <form action="relink.php" method="get"><button class="button is-danger bot-button" type="submit">Re-log in</button></form>
                      </div>';
  } elseif (isset($responseData['data'])) {
    // Check if the bot is in the list of moderators
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

// Handle the bot mod status
if ($username === 'botofthespecter') {
  $showButtons = true;
} else {
  // If the user is not BotOfTheSpecter, check mod status
  if ($ModStatusOutput) {
    $showButtons = true;
  } else {
    $BotModMessage = '<div class="notification is-danger has-text-black has-text-weight-bold">BotOfTheSpecter is not currently a moderator on your channel. To continue, please add BotOfTheSpecter as a mod on your Twitch channel.<br>You can do this by navigating to your Twitch Streamer Dashboard, then going to Community > Roles Manager.<br>After you have made BotOfTheSpecter a mod, refresh this page to access your controls.</div>';
    $showButtons = false;
  }
}

$betaAccess = false; // Default to false until we know
if ($user['beta_access'] == 1) {
  $betaAccess = true;
} else {
  $twitch_subscriptions_url = "https://api.twitch.tv/helix/subscriptions/user?broadcaster_id=140296994&user_id=$twitchUserId";
  $headers = [
    "Client-ID: {$clientID}",
    "Authorization: Bearer {$authToken}"
  ];
  $ch = curl_init($twitch_subscriptions_url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  $response = curl_exec($ch);
  curl_close($ch);
  $data = json_decode($response, true);
  if (isset($data['data'][0]));
    $tier = $data['data'][0]['tier'];
    if (in_array($tier, ["1000", "2000", "3000"]));
    $betaAccess = true;
};

$lastModifiedOutput = '';
$lastRestartOutput = '';

// Last Changed Time
$betaFile = '/var/www/bot/beta.py';
if (file_exists($betaFile)) {
    $betaFileModifiedTime = filemtime($betaFile);
    $timeAgo = time() - $betaFileModifiedTime;
    if ($timeAgo < 60) {
        $lastModifiedOutput = $timeAgo . ' seconds ago';
    } elseif ($timeAgo < 3600) {
        $lastModifiedOutput = floor($timeAgo / 60) . ' minutes ago';
    } elseif ($timeAgo < 86400) {
        $lastModifiedOutput = floor($timeAgo / 3600) . ' hours ago';
    } else {
        $lastModifiedOutput = floor($timeAgo / 86400) . ' days ago';
    }
} else {
    $lastModifiedOutput = 'File not found'; // Optional error handling
}

// Last Restarted Time
$restartLog = '/var/www/logs/version/' . $username . '_beta_version_control.txt';
if (file_exists($restartLog)) {
    $restartFileTime = filemtime($restartLog);
    $restartTimeAgo = time() - $restartFileTime;
    if ($restartTimeAgo < 60) {
        $lastRestartOutput = $restartTimeAgo . ' seconds ago';
    } elseif ($restartTimeAgo < 3600) {
        $lastRestartOutput = floor($restartTimeAgo / 60) . ' minutes ago';
    } elseif ($restartTimeAgo < 86400) {
        $lastRestartOutput = floor($restartTimeAgo / 3600) . ' hours ago';
    } else {
        $lastRestartOutput = floor($restartTimeAgo / 86400) . ' days ago';
    }
} else {
    $lastRestartOutput = 'Never'; // Message if restart log file does not exist
}
?>
<!doctype html>
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
  <?php if ($betaAccess && $showButtons): ?><div class="notification is-danger has-text-black has-text-weight-bold">Before starting the Beta version, ensure the Stable version is stopped to avoid data conflicts.</div><?php endif; ?>
  <br>
  <div class="columns is-desktop is-multiline box-container">
    <!-- Stable Bot Section -->
    <?php if ($showButtons): ?>
      <div class="column is-5 bot-box" id="stable-bot-status" style="position: relative;">
        <i class="fas fa-question-circle" id="stable-bot-modal-open" style="position: absolute; top: 10px; right: 10px; cursor: pointer;"></i>
        <h4 class="title is-4">Stable Bot: (<?php echo "V" . $newVersion; ?>)</h4>
        <div id="stableStatus"><?php echo $statusOutput; ?></div>
        <div id="stableVersion"><?php echo $versionRunning; ?></div>
        <br>
        <div class="buttons">
          <form action="" method="post">
            <button class="button is-danger bot-button button-size" type="submit" name="killBot">Stop Bot</button>
          </form>
          <form action="" method="post">
            <button class="button is-success bot-button button-size" type="submit" name="runBot">Run Bot</button>
          </form>
          <form action="" method="post">
            <button class="button is-warning bot-button button-size" type="submit" name="restartBot">Restart Bot</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
    <!-- Beta Bot Section -->
    <?php if ($betaAccess && $showButtons): ?>
      <div class="column is-5 bot-box" id="beta-bot-status" style="position: relative;">
        <i class="fas fa-question-circle" id="beta-bot-modal-open" style="position: absolute; top: 10px; right: 10px; cursor: pointer;"></i>
        <h4 class="title is-4">Beta Bot: (<?php echo "V" . $betaNewVersion . "B"; ?>)</h4>
        <div id="betaStatus"><?php echo $betaStatusOutput; ?></div>
        <div id="betaVersion"><?php echo $betaVersionRunning; ?></div>
        <br>
        <div class="buttons">
          <form action="" method="post">
            <button class="button is-danger bot-button button-size" type="submit" name="killBetaBot">Stop Beta Bot</button>
          </form>
          <form action="" method="post">
            <button class="button is-success bot-button button-size" type="submit" name="runBetaBot">Run Beta Bot</button>
          </form>
          <form action="" method="post">
            <button class="button is-warning bot-button button-size" type="submit" name="restartBetaBot">Restart Beta Bot</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
    <!-- Discord Bot Section -->
    <?php if ($guild_id && $live_channel_id && $showButtons): ?>
      <div class="column is-5 bot-box" id="discord-bot-status" style="position: relative;">
        <i class="fas fa-question-circle" id="discord-bot-modal-open" style="position: absolute; top: 10px; right: 10px; cursor: pointer;"></i>
        <h4 class="title is-4">Discord Bot:</h4>
        <div id="discordStatus"><?php echo $discordStatusOutput; ?></div>
        <div class="buttons">
          <form action="" method="post">
            <button class="button is-danger bot-button button-size" type="submit" name="killDiscordBot">Stop Discord Bot</button>
          </form>
          <form action="" method="post">
            <button class="button is-success bot-button button-size" type="submit" name="runDiscordBot">Run Discord Bot</button>
          </form>
          <form action="" method="post">
            <button class="button is-warning bot-button button-size" type="submit" name="restartDiscordBot">Restart Discord Bot</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
    <?php if ($showButtons): ?>
    <!-- Websocket Notices Section -->
    <div class="column is-5 bot-box" style="position: relative;">
      <i class="fas fa-question-circle" id="websocket-service-modal-open" style="position: absolute; top: 10px; right: 10px; cursor: pointer;"></i>
      <h4 class="title is-4" style="text-align: center;">Websocket Service
        <span id="heartbeatIcon" style="margin-left: 10px;">
          <i id="heartbeat" class="fas fa-heartbeat" style="color: green;"></i>
        </span>
      </h4>
      <div style="display: flex; align-items: center; margin-bottom: 10px;">
        <div class="buttons" style="position: relative; display: inline-block; cursor: pointer;">
          <button class="button is-primary bot-button" onclick="sendStreamEvent('STREAM_ONLINE')" title="Clicking this button will force the entire system to show you as online.">Force Online Status</button>
          <span id="onlineTooltip" style="visibility: hidden; width: 120px; background-color: #555; color: #fff; text-align: center; border-radius: 6px; padding: 5px 0; position: absolute; z-index: 1; bottom: 125%; left: 50%; margin-left: -60px; opacity: 0; transition: opacity 0.3s;">Online Event Sent!</span>
        </div>
      </div>
      <div style="display: flex; align-items: center;">
        <div class="buttons" style="position: relative; display: inline-block; cursor: pointer;">
          <button class="button is-danger bot-button" onclick="sendStreamEvent('STREAM_OFFLINE')" title="Clicking this button will force the entire system to show you as offline.">Force Offline Status</button>
          <span id="offlineTooltip" style="visibility: hidden; width: 120px; background-color: #555; color: #fff; text-align: center; border-radius: 6px; padding: 5px 0; position: absolute; z-index: 1; bottom: 125%; left: 50%; margin-left: -60px; opacity: 0; transition: opacity 0.3s;">Offline Event Sent!</span>
        </div>
      </div>
    </div>
    <!-- API System -->
    <div class="column is-5 bot-box" style="position: relative;">
      <i class="fas fa-question-circle" id="api-limits-modal-open" style="position: absolute; top: 10px; right: 10px; cursor: pointer;"></i>
      <h4 class="title is-4 has-text-centered">API Limits</h4>
      <div class="status-message" style="font-size: 18px; padding: 15px; background-color: #2c3e50; color: #ecf0f1; border-radius: 8px;">
        <!-- Song Identification Section -->
        <div class="api-section" id="shazam-section" style="padding-bottom: 15px; border-bottom: 1px solid #7f8c8d; margin-bottom: 15px;">
          <p>Loading Shazam data...</p>
        </div>
        <!-- Exchange Rate Section -->
        <div class="api-section" id="exchangerate-section" style="padding-bottom: 15px; border-bottom: 1px solid #7f8c8d; margin-bottom: 15px;">
          <p>Loading Exchange Rate data...</p>
        </div>
        <!-- Weather Usage Section -->
        <div class="api-section" id="weather-section">
          <p>Loading Weather data...</p>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal" id="stable-bot-modal">
  <div class="modal-background"></div>
  <div class="modal-card">
    <header class="modal-card-head has-background-dark">
      <p class="modal-card-title has-text-white">Stable Bot Information</p>
      <button class="delete" aria-label="close" id="stable-bot-modal-close"></button>
    </header>
    <section class="modal-card-body has-background-dark has-text-white">
      <p>
        <span class="has-text-weight-bold variable-title">Run Bot</span>:
        This button runs the stable version of the bot.
      </p>
      <p>
        <span class="has-text-weight-bold variable-title">Stop Bot</span>:
        This button stops the stable version of the bot from running.
      </p>
      <p>
        <span class="has-text-weight-bold variable-title">Restart Bot</span>:
        This button restarts the stable version of the bot, refreshing its connection and settings.
      </p>
    </section>
  </div>
</div>

<div class="modal" id="beta-bot-modal">
  <div class="modal-background"></div>
  <div class="modal-card">
    <header class="modal-card-head has-background-dark">
      <p class="modal-card-title has-text-white">Beta Bot Information</p>
      <button class="delete" aria-label="close" id="beta-bot-modal-close"></button>
    </header>
    <section class="modal-card-body has-background-dark has-text-white">
      <p>
        <span class="has-text-weight-bold variable-title">Run Beta Bot</span>:
        This button runs the Beta version of the Twitch bot "BotOfTheSpecter". <span class="has-text-weight-light">Last Changed: <span id="last-modified-time"><?php echo $lastModifiedOutput; ?></span></span>
      </p>
      <p>
        <span class="has-text-weight-bold variable-title">Restart Beta Bot</span>:<br>
        This button restarts the Beta version of the Twitch bot, refreshing its connection and settings. <span class="has-text-weight-light">You are running a version that you started, <span id="last-restart-time"><?php echo $lastRestartOutput; ?></span></span>
      </p>
      <p>
        <span class="has-text-weight-bold variable-title">Stop Beta Bot</span>:
        This button stops the Beta version of the Twitch bot from running.<br>
      </p>
    </section>
  </div>
</div>

<div class="modal" id="discord-bot-modal">
  <div class="modal-background"></div>
  <div class="modal-card">
    <header class="modal-card-head has-background-dark">
      <p class="modal-card-title has-text-white">Discord Bot Information</p>
      <button class="delete" aria-label="close" id="discord-bot-modal-close"></button>
    </header>
    <section class="modal-card-body has-background-dark has-text-white">
      <p>
        <span class="has-text-weight-bold variable-title">Run Discord Bot</span>:
        This button runs the bot for your Discord server using the shared Discord bot "BotOfTheSpecter". 
      </p>
      <p>
        <span class="has-text-weight-bold variable-title">Stop Discord Bot</span>:
        This button stops the bot from running in your server.
      </p>
      <p>
        <span class="has-text-weight-bold variable-title">Restart Discord Bot</span>:
        This button restarts the Discord bot, allowing it to refresh its connection and settings.
      </p>
    </section>
  </div>
</div>

<div class="modal" id="websocket-service-modal">
  <div class="modal-background"></div>
  <div class="modal-card">
    <header class="modal-card-head has-background-dark">
      <p class="modal-card-title has-text-white">Websocket Service Information</p>
      <button class="delete" aria-label="close" id="websocket-service-modal-close"></button>
    </header>
    <section class="modal-card-body has-background-dark has-text-white">
      <p><span class="has-text-weight-bold variable-title">Force Online Status</span>:<br>
        Clicking this button will set your status to online across the entire system, even if your stream is currently offline. By doing so, both the Twitch Chat Bot and Discord Bot will be notified of your desire to appear as online.
      </p>
      <br>
      <p><span class="has-text-weight-bold variable-title">Force Offline Status</span>:<br>
        This button will mark you as offline in the system, even if you are online. When clicked, it will notify both the Twitch Chat Bot and Discord Bot that you wish to be displayed as offline.<br>
        Additionally, after 5 minutes, if you remain offline, this action will clear the "Credits" overlay data and the "Seen Users" list for welcome messages.
      </p>
      <br>
      <a href="https://wiki.botofthespecter.com/tiki-index.php?page=Websocket%20Service" target="_blank" class="button is-info">View More Information</a>
    </section>
  </div>
</div>

<div class="modal" id="api-limits-modal">
  <div class="modal-background"></div>
  <div class="modal-card">
    <header class="modal-card-head has-background-dark">
      <p class="modal-card-title has-text-white">API Limits</p>
      <button class="delete" aria-label="close" id="api-limits-modal-close"></button>
    </header>
    <section class="modal-card-body has-background-dark has-text-white">
      <p>
        <span class="has-text-weight-bold variable-title">Song Identifications Left:</span>
        Indicates the number of song identification requests we have remaining.
      </p>
      <p>
        <span class="has-text-weight-bold variable-title">Exchange Rate Checks Left:</span>
        Represents the number of exchange rate checks available.
      </p>
      <p>
        <span class="has-text-weight-bold variable-title">Weather Requests Left:</span>
        Shows how many weather requests we can make.
      </p>
    </section>
  </div>
</div>

<script>
const modalIds = [
  { open: "stable-bot-modal-open", close: "stable-bot-modal-close" },
  { open: "beta-bot-modal-open", close: "beta-bot-modal-close" },
  { open: "discord-bot-modal-open", close: "discord-bot-modal-close" },
  { open: "websocket-service-modal-open", close: "websocket-service-modal-close" },
  { open: "api-limits-modal-open", close: "api-limits-modal-close" }
];

modalIds.forEach(modal => {
  const openButton = document.getElementById(modal.open);
  const closeButton = document.getElementById(modal.close);
  
  if (openButton) {
    openButton.addEventListener("click", function() {
      document.getElementById(modal.close.replace('-close', '')).classList.add("is-active");
    });
  }
  if (closeButton) {
    closeButton.addEventListener("click", function() {
      document.getElementById(modal.close.replace('-close', '')).classList.remove("is-active");
    });
  }
});

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

function updateApiLimits() {
  fetch('/api_limits.php')
  .then(response => response.json())
  .then(data => {
    // Update Shazam Section
    document.getElementById('shazam-section').innerHTML = `
      <p style='color: #1abc9c;'>Song Identifications Left: <span style='color: #e74c3c;'>${data.shazam.requests_remaining}</span> 
      (<span title='Next reset date: ${data.shazam.reset_date}'>${data.shazam.days_until_reset} days until reset</span>)</p>
      <p>Last checked: <span style='color: #f39c12;'>${data.shazam.last_modified}</span></p>
    `;
    // Update Exchange Rate Section
    document.getElementById('exchangerate-section').innerHTML = `
      <p style='color: #1abc9c;'>Exchange Rate Checks Left: <span style='color: #e74c3c;'>${data.exchangerate.requests_remaining}</span> 
      (<span title='Next reset date: ${data.exchangerate.reset_date}'>${data.exchangerate.days_until_reset} days until reset</span>)</p>
      <p>Last checked: <span style='color: #f39c12;'>${data.exchangerate.last_modified}</span></p>
    `;
    // Update Weather Section
    document.getElementById('weather-section').innerHTML = `
      <p style='color: #1abc9c;'>Weather Requests Left: <span style='color: #e74c3c;'>${data.weather.requests_remaining}</span><br> 
      (<span title='Resets at midnight'>${data.weather.hours_until_midnight} hours, ${data.weather.minutes_until_midnight} minutes, and ${data.weather.seconds_until_midnight} seconds until reset</span>)</p>
      <p>Last checked: <span style='color: #f39c12;'>${data.weather.last_modified}</span></p>
    `;
  })
  .catch(error => {
    console.error('Error fetching API limits:', error);
  });
}

function checkLastModified() {
  var xhr = new XMLHttpRequest();
  xhr.open("GET", "", true);
  xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
  xhr.onreadystatechange = function () {
    if (xhr.readyState === 4 && xhr.status === 200) {
      var response = xhr.responseText.trim();
      var lastModifiedStart = response.indexOf('<span id="last-modified-time">') + '<span id="last-modified-time">'.length;
      var lastModifiedEnd = response.indexOf('</span>', lastModifiedStart);
      var lastModifiedTime = response.substring(lastModifiedStart, lastModifiedEnd).trim();
      if (lastModifiedTime) {
        document.getElementById("last-modified-time").innerText = lastModifiedTime;
      }
    }
  };
  xhr.send();
}

setInterval(checkHeartbeat, 5000);
setInterval(updateApiLimits, 60000);
setInterval(checkLastModified, 300000);
checkHeartbeat();
updateApiLimits();
checkLastModified();
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<?php include 'usr_database.php'; ?>
</body>
</html>