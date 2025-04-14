<?php 
// Initialize the session
session_start();
$today = new DateTime();
$backup_system = false;

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
  header('Location: login.php');
  exit();
}

// Page Title and Initial Variables
$title = "Dashboard";
$statusOutput = 'Bot Status: Unknown';
$betaStatusOutput = 'Bot Status: Unknown';
$alphaStatusOutput = 'Bot Status: Unknown';
$pid = '';
$versionRunning = '';
$betaVersionRunning = '';
$alphaVersionRunning = '';
$BotIsMod = false; // Default to false until we know for sure
$BotModMessage = "";
$setupMessage = "";
$showButtons = false;
$lastModifiedOutput = '';
$lastRestartOutput = '';

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'user_db.php';
include "mod_access.php";
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);

// Fetch Discord user data
$discordUserSTMT = $conn->prepare("SELECT guild_id, live_channel_id FROM discord_users WHERE user_id = ?");
$discordUserSTMT->bind_param("i", $user_id);
$discordUserSTMT->execute();
$discordUserResult = $discordUserSTMT->get_result();
$discordUser = $discordUserResult->fetch_assoc();
$guild_id = $discordUser['guild_id'] ?? null;
$live_channel_id = $discordUser['live_channel_id'] ?? null;

// Twitch API to check bot mod status
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
if ($response !== false) {
  $httpStatus = curl_getinfo($checkModConnect, CURLINFO_HTTP_CODE);
  if ($httpStatus === 200) {
    $responseData = json_decode($response, true);
    if (isset($responseData['data'])) {
      foreach ($responseData['data'] as $mod) {
        if ($mod['user_login'] === 'botofthespecter') {
          $BotIsMod = true;
          break;
        }
      }
    }
  } else {
    // Set re-login message if authentication fails
    if ($backup_system == True) {} else {
    $BotModMessage = '<div class="notification is-danger has-text-black has-text-weight-bold">Your Twitch login session has expired. Please log in again to continue.
                      <form action="relink.php" method="get"><button class="button is-danger bot-button" type="submit">Re-log in</button></form>
                    </div>';
    }
  }
} else {
  $error = 'Curl error: ' . curl_error($checkModConnect);
}
curl_close($checkModConnect);

// Only set mod warning if no authentication message is needed
if (empty($BotModMessage) && !$BotIsMod && $username !== 'botofthespecter' && $backup_system == False) {
  $BotModMessage = '<div class="notification is-danger has-text-black has-text-weight-bold">BotOfTheSpecter is not currently a moderator on your channel. To continue, please add BotOfTheSpecter as a mod on your Twitch channel.<br>You can do this by navigating to your Twitch Streamer Dashboard, then going to Community > Roles Manager.<br>After you have made BotOfTheSpecter a mod, refresh this page to access your controls.</div>';
}

$ModStatusOutput = $BotIsMod;
if ($username === 'botofthespecter' || $ModStatusOutput) {
  $showButtons = true;
} else {
  $showButtons = false;
}

// Check Beta Access
$betaAccess = false;
if ($user['beta_access'] == 1) {
  $betaAccess = true;
  $_SESSION['tier'] = "4000";
} else {
  $twitch_subscriptions_url = "https://api.twitch.tv/helix/subscriptions/user?broadcaster_id=140296994&user_id=$twitchUserId";
  $ch = curl_init($twitch_subscriptions_url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  $response = curl_exec($ch);
  curl_close($ch);
  $data = json_decode($response, true);
  if (isset($data['data'][0])) {
    $tier = $data['data'][0]['tier'];
    $_SESSION['tier'] = $tier;
    if (in_array($tier, ["1000", "2000", "3000"])) {
      $betaAccess = true;
    }
  } else {
    $_SESSION['tier'] = "None";
  }
}

// Last Changed Time
$betaFile = '/var/www/bot/beta.py';
if (file_exists($betaFile)) {
  $betaFileModifiedTime = filemtime($betaFile);
  $timeAgo = time() - $betaFileModifiedTime;
  if ($timeAgo < 60) $lastModifiedOutput = $timeAgo . ' seconds ago';
  elseif ($timeAgo < 3600) $lastModifiedOutput = floor($timeAgo / 60) . ' minutes ago';
  elseif ($timeAgo < 86400) $lastModifiedOutput = floor($timeAgo / 3600) . ' hours ago';
  else $lastModifiedOutput = floor($timeAgo / 86400) . ' days ago';
} else {
  $lastModifiedOutput = 'File not found';
}

// Last Restarted Time
$restartLog = '/var/www/logs/version/' . $username . '_beta_version_control.txt';
if (file_exists($restartLog)) {
  $restartFileTime = filemtime($restartLog);
  $restartTimeAgo = time() - $restartFileTime;
  if ($restartTimeAgo < 60) $lastRestartOutput = $restartTimeAgo . ' seconds ago';
  elseif ($restartTimeAgo < 3600) $lastRestartOutput = floor($restartTimeAgo / 60) . ' minutes ago';
  elseif ($restartTimeAgo < 86400) $lastRestartOutput = floor($restartTimeAgo / 3600) . ' hours ago';
  else $lastRestartOutput = floor($restartTimeAgo / 86400) . ' days ago';
} else {
  $lastRestartOutput = 'Never'; // Message if restart log file does not exist
}

if ($backup_system == true) {
  $showButtons = true;
};
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
  <br>
  <?php echo $BotModMessage; ?>
  <?php echo $setupMessage; ?>
  <?php if ($betaAccess && $showButtons): ?><div class="notification is-danger has-text-black has-text-weight-bold">Before starting the Beta version, ensure the Stable version is stopped to avoid data conflicts.</div><?php endif; ?>
  <br>
  <!--<div class="notification is-warning has-text-black has-text-weight-bold">There is an issue with the bot account on twitch, system will use backup API and use your streamer account for chat messages.</div><br>-->
  <div class="columns is-desktop is-multiline is-centered box-container">
    <!-- Stable Bot Section -->
    <?php if ($showButtons): ?>
      <div class="column is-5 bot-box" id="stable-bot-status" style="position: relative;">
        <i class="fas fa-question-circle" id="stable-bot-modal-open" style="position: absolute; top: 10px; right: 10px; cursor: pointer;"></i>
        <h4 class="title is-4 bot-box-title">Stable Bot: (<?php echo "V" . $newVersion; ?>)</h4>
        <div class="status-message" style="text-align: center;">We recommend using this for every stream.<br>Well-tested and reliable for everyday use.</div>
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
        <h4 class="title is-4 bot-box-title">Beta Bot: (<?php echo "V" . $betaNewVersion . "B"; ?>)</h4>
        <div class="status-message" style="text-align: center;">Recommended for testing new things in your stream.</div>
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
    <!-- Alpha Bot Section -->
    <?php if ($showButtons): ?>
      <div class="column is-5 bot-box" id="alpha-bot-status" style="position: relative;">
        <i class="fas fa-question-circle" id="alpha-bot-modal-open" style="position: absolute; top: 10px; right: 10px; cursor: pointer;"></i>
        <h4 class="title is-4 bot-box-title">Alpha Bot: (<?php echo "V" . $alphaNewVersion . "A"; ?>)</h4>
        <div class="status-message" style="text-align: center;">Not recommended for running during streams as this version is for experimental features.</div>
        <div id="alphaStatus"><?php echo $alphaStatusOutput; ?></div>
        <div id="alphaVersion"><?php echo $alphaVersionRunning; ?></div>
        <br>
        <div class="buttons">
          <form action="" method="post">
            <button class="button is-danger bot-button button-size" type="submit" name="killAlphaBot">Stop Alpha Bot</button>
          </form>
          <form action="" method="post">
            <button class="button is-success bot-button button-size" type="submit" name="runAlphaBot">Run Alpha Bot</button>
          </form>
          <form action="" method="post">
            <button class="button is-warning bot-button button-size" type="submit" name="restartAlphaBot">Restart Alpha Bot</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
    <!-- Discord Bot Section -->
    <?php if ($guild_id && $live_channel_id && $showButtons): ?>
      <div class="column is-5 bot-box" id="discord-bot-status" style="position: relative;">
        <i class="fas fa-question-circle" id="discord-bot-modal-open" style="position: absolute; top: 10px; right: 10px; cursor: pointer;"></i>
        <h4 class="title is-4 bot-box-title">Discord Bot: (<?php echo "V" . htmlspecialchars($discordNewVersion); ?>)</h4>
        <div id="discordStatus"><?php echo $discordStatusOutput; ?></div>
        <div id="discordVersion"><?php echo $discordVersionRunning; ?></div>
        <br>
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
    <!-- System Status Section -->
    <div class="column is-5 bot-box" style="position: relative;">
      <i class="fas fa-question-circle" id="websocket-service-modal-open" style="position: absolute; top: 10px; right: 10px; cursor: pointer;"></i>
      <h4 class="title is-4 bot-box-title" style="text-align: center;">System Status</h4>
      <div class="columns is-mobile is-multiline">
        <div class="column is-6">
          <div style="display: flex; align-items: center; justify-content: center; flex-direction: column; text-align: center;">
            <span class="subtitle">
              <span id="apiServiceIcon">
                <i id="apiService" class="fas fa-heartbeat" style="color: green;"></i>
              </span>
              <span style="margin-left: 3px">API Service</span>
            </span>
          </div>
        </div>
        <div class="column is-6">
          <div style="display: flex; align-items: center; justify-content: center; flex-direction: column; text-align: center;">
            <span class="subtitle">
              <span id="databaseServiceIcon">
                <i id="databaseService" class="fas fa-heartbeat" style="color: green;"></i>
              </span>
              <span style="margin-left: 3px">Database Service</span>
            </span>
          </div>
        </div>
        <div class="column is-6">
          <div style="display: flex; align-items: center; justify-content: center; flex-direction: column; text-align: center;">
            <span class="subtitle">
              <span id="heartbeatIcon">
                <i id="heartbeat" class="fas fa-heartbeat" style="color: green;"></i>
              </span>
              <span style="margin-left: 3px">Notification Service</span>
            </span>
          </div>
        </div>
        <div class="column is-6">
          <div style="display: flex; align-items: center; justify-content: center; flex-direction: column; text-align: center;">
            <span class="subtitle">
              <span id="streamingServiceIcon">
                <i id="streamingService" class="fas fa-heartbeat" style="color: <?php echo $streamingServiceStatus['status'] === 'OK' ? 'green' : 'red'; ?>;"></i>
              </span>
              <span style="margin-left: 3px">Streaming Service</span>
            </span>
          </div>
        </div>
      </div>
      <div class="column is-12">
        <div style="display: flex; align-items: center; justify-content: center; flex-direction: column; text-align: center;">
          <button class="button is-link bot-button is-fullwidth no-working-spinner" onclick="window.open('https://uptime.botofthespecter.com/', '_blank')">Uptime Monitors</button>
        </div>
      </div>
      <div class="columns is-mobile">
        <div class="column">
          <div style="position: relative; text-align: center;">
            <button class="button is-primary bot-button is-fullwidth" onclick="sendStreamEvent('STREAM_ONLINE')" title="Clicking this button will force the entire system to show you as online.">Force Online Status</button>
            <span id="onlineTooltip" style="visibility: hidden; width: 120px; background-color: #555; color: #fff; text-align: center; border-radius: 6px; padding: 5px 0; position: absolute; z-index: 1; bottom: 125%; left: 50%; margin-left: -60px; opacity: 0; transition: opacity 0.3s;">Online Event Sent!</span>
          </div>
        </div>
        <div class="column">
          <div style="position: relative; text-align: center;">
            <button class="button is-danger bot-button is-fullwidth" onclick="sendStreamEvent('STREAM_OFFLINE')" title="Clicking this button will force the entire system to show you as offline.">Force Offline Status</button>
            <span id="offlineTooltip" style="visibility: hidden; width: 120px; background-color: #555; color: #fff; text-align: center; border-radius: 6px; padding: 5px 0; position: absolute; z-index: 1; bottom: 125%; left: 50%; margin-left: -60px; opacity: 0; transition: opacity 0.3s;">Offline Event Sent!</span>
          </div>
        </div>
      </div>
    </div>
    <!-- API System -->
    <div class="column is-5 bot-box" style="position: relative;">
      <i class="fas fa-question-circle" id="api-limits-modal-open" style="position: absolute; top: 10px; right: 10px; cursor: pointer;"></i>
      <h4 class="title is-4  bot-box-title">API Limits</h4>
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
    <!-- Invisible Column to balance the grid -->
    <div class="column is-5 bot-box is-invisible" style="position: relative;"></div>
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
        This button runs the stable version of the bot, which is well-tested and reliable for everyday use.
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
        This button runs the Beta version of the bot, which is in development and testing new features.
      </p>
      <p>
        <span class="has-text-weight-bold variable-title">Stop Beta Bot</span>:
        This button stops the Beta version of the bot from running.
      </p>
      <p>
        <span class="has-text-weight-bold variable-title">Restart Beta Bot</span>:
        This button restarts the Beta version of the bot, refreshing its connection and settings.
      </p>
      <br>
      <p>
        <span class="has-text-weight-bold variable-title">Beta Bot Version Control</span>: Below you'll find information about when the beta file was last updated and how long it has been since you last started or restarted the beta bot. 
        Keeping these two times as close as possible will ensure you're up-to-date with the latest changes, as we continuously test and improve the bot's functionality.
        <br>
        <span class="has-text-weight-light">Last Changed: <span id="last-modified-time"><?php echo $lastModifiedOutput; ?></span></span><br>
        <span class="has-text-weight-light">Last Ran: <span id="last-restart-time"><?php echo $lastRestartOutput; ?></span></span>
      </p>
    </section>
  </div>
</div>

<div class="modal" id="alpha-bot-modal">
  <div class="modal-background"></div>
  <div class="modal-card">
    <header class="modal-card-head has-background-dark">
      <p class="modal-card-title has-text-white">Alpha Bot Information</p>
      <button class="delete" aria-label="close" id="alpha-bot-modal-close"></button>
    </header>
    <section class="modal-card-body has-background-dark has-text-white">
      <p>
        <span class="has-text-weight-bold variable-title">Run Alpha Bot</span>:
        This button runs the Alpha version of the bot, which is in the early testing phase for experimental features.
      </p>
      <p>
        <span class="has-text-weight-bold variable-title">Stop Alpha Bot</span>:
        This button stops the Alpha version of the bot from running.
      </p>
      <p>
        <span class="has-text-weight-bold variable-title">Restart Alpha Bot</span>:
        This button restarts the Alpha version of the bot, refreshing its connection and settings.
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
      <p class="modal-card-title has-text-white">System Status Information</p>
      <button class="delete" aria-label="close" id="websocket-service-modal-close"></button>
    </header>
    <section class="modal-card-body has-background-dark has-text-white">
      <p>
        <span class="has-text-weight-bold variable-title">API Service</span>:<br>
        The API service is responsible for providing system data and managing events. It is currently active as shown by the green heartbeat icon. If the service status is offline, the icon will turn red.
      </p>
      <br>
      <p>
        <span class="has-text-weight-bold variable-title">Database Service</span>:<br>
        The database service handles all data storage and retrieval tasks. The service is currently running as indicated by the green heartbeat icon. If the service is down, the icon will display red.
      </p>
      <br>
      <p>
        <span class="has-text-weight-bold variable-title">Notification Service</span>:<br>
        This service ensures that notifications are sent out properly. The service is operational if the green heartbeat icon is visible. A red icon means the notification service is currently unavailable.
      </p>
      <br>
      <p>
        <span class="has-text-weight-bold variable-title">Streaming Service</span>:<br>
        The streaming service is responsible for our streaming server. The green heartbeat icon indicates that the service is active. If the icon turns red, it means the streaming service is currently offline.
      </p>
      <p>
        <span class="has-text-weight-bold variable-title">Force Online Status</span>:<br>
        Clicking this button will force the system to display you as online, even if your stream is not active. This will notify both the Twitch Chat Bot and the Discord Bot that you wish to appear online.
      </p>
      <br>
      <p>
        <span class="has-text-weight-bold variable-title">Force Offline Status</span>:<br>
        This button will set your status to offline across the system, even if you are currently online. Once clicked, both the Twitch Chat Bot and Discord Bot will be notified of your offline status.<br>
        Additionally, after 5 minutes of being offline, this action will clear the "Credits" overlay data and the "Seen Users" list for welcome messages.
      </p>
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
  { open: "api-limits-modal-open", close: "api-limits-modal-close" },
  { open: "alpha-bot-modal-open", close: "alpha-bot-modal-close" }
];

modalIds.forEach(modal => {
  const openButton = document.getElementById(modal.open);
  const closeButton = document.getElementById(modal.close);
  const modalElement = document.getElementById(modal.close.replace('-close', ''));

  if (openButton) {
    openButton.addEventListener("click", function() {
      modalElement.classList.add("is-active");
    });
  }
  if (closeButton) {
    closeButton.addEventListener("click", function() {
      modalElement.classList.remove("is-active");
    });
  }
  if (modalElement) {
    modalElement.addEventListener("click", function(event) {
      if (event.target === modalElement) {
        modalElement.classList.remove("is-active");
      }
    });
  }
});

window.addEventListener('error', function(event) {
  console.error('Error message:', event.message);
  console.error('Script error:', event.filename, 'line:', event.lineno, 'column:', event.colno);
});

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

function checkServiceStatus(service, elementId, url) {
  fetch(url)
    .then(response => response.json())
    .then(data => {
      const serviceIcon = document.getElementById(elementId);
      if (data.status === 'OK') {
        serviceIcon.className = 'fas fa-heartbeat beating';
        serviceIcon.style.color = 'green';
      } else {
        serviceIcon.className = 'fas fa-heart-broken';
        serviceIcon.style.color = 'red';
      }
    })
    .catch(error => {
      const serviceIcon = document.getElementById(elementId);
      serviceIcon.className = 'fas fa-heart-broken';
      serviceIcon.style.color = 'red';
    });
}

function checkAllServices() {
  // Fetch the latest status for API service
  fetch('/api_status.php?service=api')
    .then(response => response.json())
    .then(data => {
      const serviceIcon = document.getElementById('apiService');
      if (data.status === 'OK') {
        serviceIcon.className = 'fas fa-heartbeat beating';
        serviceIcon.style.color = 'green';
      } else {
        serviceIcon.className = 'fas fa-heart-broken';
        serviceIcon.style.color = 'red';
      }
    })
    .catch(error => {
      const serviceIcon = document.getElementById('apiService');
      serviceIcon.className = 'fas fa-heart-broken';
      serviceIcon.style.color = 'red';
    });

  // Fetch the latest status for WebSocket service
  fetch('/api_status.php?service=websocket')
    .then(response => response.json())
    .then(data => {
      const websocketServiceIcon = document.getElementById('heartbeat');
      if (data.status === 'OK') {
        websocketServiceIcon.className = 'fas fa-heartbeat beating';
        websocketServiceIcon.style.color = 'green';
      } else {
        websocketServiceIcon.className = 'fas fa-heart-broken';
        websocketServiceIcon.style.color = 'red';
      }
    })
    .catch(error => {
      const websocketServiceIcon = document.getElementById('heartbeat');
      websocketServiceIcon.className = 'fas fa-heart-broken';
      websocketServiceIcon.style.color = 'red';
    });

  // Fetch the latest status for Database service
  fetch('/api_status.php?service=database')
    .then(response => response.json())
    .then(data => {
      const databaseServiceIcon = document.getElementById('databaseService');
      if (data.status === 'OK') {
        databaseServiceIcon.className = 'fas fa-heartbeat beating';
        databaseServiceIcon.style.color = 'green';
      } else {
        databaseServiceIcon.className = 'fas fa-heart-broken';
        databaseServiceIcon.style.color = 'red';
      }
    })
    .catch(error => {
      const databaseServiceIcon = document.getElementById('databaseService');
      databaseServiceIcon.className = 'fas fa-heart-broken';
      databaseServiceIcon.style.color = 'red';
    });

  // Fetch the latest status for Streaming service
  fetch('/api_status.php?service=streaming')
    .then(response => response.json())
    .then(data => {
      const streamingServiceIcon = document.getElementById('streamingService');
      if (data.status === 'OK') {
        streamingServiceIcon.className = 'fas fa-heartbeat beating';
        streamingServiceIcon.style.color = 'green';
      } else {
        streamingServiceIcon.className = 'fas fa-heart-broken';
        streamingServiceIcon.style.color = 'red';
      }
    })
    .catch(error => {
      const streamingServiceIcon = document.getElementById('streamingService');
      streamingServiceIcon.className = 'fas fa-heart-broken';
      streamingServiceIcon.style.color = 'red';
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
      (<span title='Resets at midnight'>${data.weather.hours_until_midnight} hours, ${data.weather.minutes_until_midnight} minutes until reset</span>)</p>
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

setInterval(checkAllServices, 5000);
setInterval(updateApiLimits, 5000);
setInterval(checkLastModified, 5000);
checkAllServices();
updateApiLimits();
checkLastModified();
</script>
<?php if ($showButtons): ?>
<script>
function checkServiceStatus(service, elementId, url) {
  fetch(url)
    .then(response => response.json())
    .then(data => {
      const serviceIcon = document.getElementById(elementId);
      if (data.status === 'OK') {
        serviceIcon.className = 'fas fa-heartbeat beating';
        serviceIcon.style.color = 'green';
      } else {
        serviceIcon.className = 'fas fa-heart-broken';
        serviceIcon.style.color = 'red';
      }
    })
    .catch(error => {
      const serviceIcon = document.getElementById(elementId);
      serviceIcon.className = 'fas fa-heart-broken';
      serviceIcon.style.color = 'red';
    });
}

function checkAllServices() {
  // Fetch the latest status for API service
  fetch('/api_status.php?service=api')
    .then(response => response.json())
    .then(data => {
      const serviceIcon = document.getElementById('apiService');
      if (data.status === 'OK') {
        serviceIcon.className = 'fas fa-heartbeat beating';
        serviceIcon.style.color = 'green';
      } else {
        serviceIcon.className = 'fas fa-heart-broken';
        serviceIcon.style.color = 'red';
      }
    })
    .catch(error => {
      const serviceIcon = document.getElementById('apiService');
      serviceIcon.className = 'fas fa-heart-broken';
      serviceIcon.style.color = 'red';
    });

  // Fetch the latest status for WebSocket service
  fetch('/api_status.php?service=websocket')
    .then(response => response.json())
    .then(data => {
      const websocketServiceIcon = document.getElementById('heartbeat');
      if (data.status === 'OK') {
        websocketServiceIcon.className = 'fas fa-heartbeat beating';
        websocketServiceIcon.style.color = 'green';
      } else {
        websocketServiceIcon.className = 'fas fa-heart-broken';
        websocketServiceIcon.style.color = 'red';
      }
    })
    .catch(error => {
      const websocketServiceIcon = document.getElementById('heartbeat');
      websocketServiceIcon.className = 'fas fa-heart-broken';
      websocketServiceIcon.style.color = 'red';
    });

  // Fetch the latest status for Database service
  fetch('/api_status.php?service=database')
    .then(response => response.json())
    .then(data => {
      const databaseServiceIcon = document.getElementById('databaseService');
      if (data.status === 'OK') {
        databaseServiceIcon.className = 'fas fa-heartbeat beating';
        databaseServiceIcon.style.color = 'green';
      } else {
        databaseServiceIcon.className = 'fas fa-heart-broken';
        databaseServiceIcon.style.color = 'red';
      }
    })
    .catch(error => {
      const databaseServiceIcon = document.getElementById('databaseService');
      databaseServiceIcon.className = 'fas fa-heart-broken';
      databaseServiceIcon.style.color = 'red';
    });

  // Fetch the latest status for Streaming service
  fetch('/api_status.php?service=streaming')
    .then(response => response.json())
    .then(data => {
      const streamingServiceIcon = document.getElementById('streamingService');
      if (data.status === 'OK') {
        streamingServiceIcon.className = 'fas fa-heartbeat beating';
        streamingServiceIcon.style.color = 'green';
      } else {
        streamingServiceIcon.className = 'fas fa-heart-broken';
        streamingServiceIcon.style.color = 'red';
      }
    })
    .catch(error => {
      const streamingServiceIcon = document.getElementById('streamingService');
      streamingServiceIcon.className = 'fas fa-heart-broken';
      streamingServiceIcon.style.color = 'red';
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
      (<span title='Resets at midnight'>${data.weather.hours_until_midnight} hours, ${data.weather.minutes_until_midnight} minutes until reset</span>)</p>
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

setInterval(checkAllServices, 5000);
setInterval(updateApiLimits, 5000);
setInterval(checkLastModified, 5000);
checkAllServices();
updateApiLimits();
checkLastModified();
</script>
<?php endif; ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<?php include 'usr_database.php'; ?>
<script>
// Attach a submit handler on each form so that after submission all bot buttons are disabled
document.addEventListener('DOMContentLoaded', function() {
  // Reset all bot buttons on page load
  const buttons = document.querySelectorAll('.bot-button:not(.no-working-spinner)');
  buttons.forEach(btn => {
    // Store original text if not already stored
    if (!btn.dataset.originalText) {
      btn.dataset.originalText = btn.innerHTML;
    }
    // Ensure button is enabled and its text is reset
    btn.disabled = false;
    btn.innerHTML = btn.dataset.originalText;
    // On clicking update text to "Working" with spinner icon
    btn.addEventListener('click', function() {
      btn.innerHTML = 'Working <i class="fas fa-spinner fa-spin"></i>';
    });
  });

  // Attach submit handler to disable buttons on form submit
  const forms = document.querySelectorAll('form');
  forms.forEach(form => {
    form.addEventListener('submit', function(event) {
      // Slight delay to ensure submit signal is sent
      setTimeout(() => {
        document.querySelectorAll('.bot-button').forEach(btn => btn.disabled = true);
      }, 10);
    });
  });
});
</script>
</body>
</html>