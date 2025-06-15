<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$today = new DateTime();
$backup_system = false;

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
  header('Location: login.php');
  exit();
}

// Page Title and Initial Variables
$pageTitle = t('bot_management_title');
$statusOutput = '';
$betaStatusOutput = '';
$discordStatusOutput = '';
$pid = '';
$versionRunning = '';
$betaVersionRunning = '';
$discordVersionRunning = '';
$BotIsMod = false;
$BotModMessage = "";
$setupMessage = "";
$showButtons = false;
$lastModifiedOutput = '';
$lastRestartOutput = '';
$stableLastModifiedOutput = '';
$stableLastRestartOutput = '';
$discordLastModifiedOutput = '';
$discordLastRestartOutput = '';

// Determine which bot to display based on selection or cookie
$selectedBot = $_GET['bot'] ?? null;

// If bot is specified in URL, update the cookie only for stable or beta
if (isset($_GET['bot'])) {
  if (in_array($_GET['bot'], ['stable', 'beta'])) {
    setcookie('selectedBot', $_GET['bot'], time() + (86400 * 30), "/"); // Cookie for 30 days
  }
} 
// If no bot specified in URL, try to get from cookie
else if (!isset($_GET['bot']) && isset($_COOKIE['selectedBot']) && in_array($_COOKIE['selectedBot'], ['stable', 'beta'])) {
  $selectedBot = $_COOKIE['selectedBot'];
} 
// Default to stable if no selection or cookie
else {
  $selectedBot = 'stable';
}

// Validate selected bot
if (!in_array($selectedBot, ['stable', 'beta', 'discord'])) {
  $selectedBot = 'stable';
}

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include '/var/www/config/discord.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include_once 'usr_database.php';
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Check if Discord is properly setup for this user with valid tokens
$hasDiscordSetup = false;
$discordNeedsRelink = false;
// Override for bot user "botofthespecter" - allow old system
if (isset($username) && $username === 'botofthespecter') {
  // For the bot user, use the old simple existence check
  $discordSetupStmt = $conn->prepare("SELECT 1 FROM discord_users WHERE user_id = ? LIMIT 1");
  $discordSetupStmt->bind_param("i", $user_id);
  $discordSetupStmt->execute();
  $discordSetupStmt->store_result();
  if ($discordSetupStmt->num_rows > 0) {
    $hasDiscordSetup = true;
  }
  $discordSetupStmt->close();
} else {
  // For all other users, use the new robust token validation
  $discordSetupStmt = $conn->prepare("SELECT access_token, refresh_token, expires_in FROM discord_users WHERE user_id = ? LIMIT 1");
  $discordSetupStmt->bind_param("i", $user_id);
  $discordSetupStmt->execute();
  $discordSetupResult = $discordSetupStmt->get_result();
  if ($discordSetupResult->num_rows > 0) {
    $discordData = $discordSetupResult->fetch_assoc();
    // Check if we have ALL required token data: access_token, refresh_token, and expires_in
    if (!empty($discordData['access_token']) && 
        !empty($discordData['refresh_token']) && 
        !empty($discordData['expires_in'])) {
      // Validate token using /oauth2/@me endpoint
      $auth_url = 'https://discord.com/api/oauth2/@me';
      $token = $discordData['access_token'];
      $auth_options = array(
        'http' => array(
          'header' => "Authorization: Bearer $token\r\n",
          'method' => 'GET'
        )
      );
      $auth_context = stream_context_create($auth_options);
      $auth_response = @file_get_contents($auth_url, false, $auth_context);
      if ($auth_response !== false) {
        $auth_data = json_decode($auth_response, true);
        if (isset($auth_data['user'])) {
          // Token is valid, Discord is properly set up
          $hasDiscordSetup = true;
        } else {
          // Token is invalid, needs relink
          $discordNeedsRelink = true;
        }
      } else {
        // API call failed, token might be invalid, needs relink
        $discordNeedsRelink = true;
      }
    } else {
      // Missing required token data, needs relink
      $discordNeedsRelink = true;
    }
  } else {
    // No Discord record exists, user has never linked
    $discordNeedsRelink = false; // This is a new user, not a relink case
  }

  $discordSetupResult->close();
  $discordSetupStmt->close();
}

// Check Beta Access
$betaAccess = false;
if ($user['beta_access'] == 1) {
  $betaAccess = true;
  $_SESSION['tier'] = "4000";
} else {
  $twitch_subscriptions_url = "https://api.twitch.tv/helix/subscriptions/user?broadcaster_id=140296994&user_id=$twitchUserId";
  $headers = [
    'Authorization: Bearer ' . $authToken,
    'Client-ID: ' . $clientID
  ];
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

// Display subscription warning for Beta if no access
$subscriptionWarning = '';
if ($selectedBot === 'beta' && !$betaAccess) {
  $subscriptionWarning = '<div class="notification is-warning has-text-black has-text-weight-bold">'
    . t('bot_beta_subscription_warning', ['premium_url' => 'premium.php'])
    . '</div>';
}

// Check running status for all bots to prevent conflicts
$stableRunning = checkBotsRunning($statusScriptPath, $username, 'stable');
$betaRunning = checkBotsRunning($statusScriptPath, $username, 'beta');

// Count how many bots are running
$runningBotCount = ($stableRunning ? 1 : 0) + ($betaRunning ? 1 : 0);
$multiBotWarning = '';
if (($stableRunning || $betaRunning) && $selectedBot !== 'discord') {
  $multiBotWarning = '<div class="notification is-danger has-text-black has-text-weight-bold">'
    . t('bot_multi_bot_warning')
    . '</div>';
}

// Check if the bot "knows" the user is online
$tagClass = 'tag is-large is-fullwidth is-medium mb-2 has-text-weight-bold has-text-centered';
$userOnlineStatus = null;
$status = null;
if (isset($username) && $username !== '') {
  $stmt = $db->prepare("SELECT status FROM stream_status");
  $stmt->execute();
  $stmt->bind_result($status);
  if ($stmt->fetch()) {
    if ($status === 'True') {
      $userOnlineStatus = '<span class="' . $tagClass . ' bot-status-tag is-success" style="width:100%;">' . t('bot_status_online') . '</span>';
    } elseif ($status === 'False') {
      $userOnlineStatus = '<span class="' . $tagClass . ' bot-status-tag is-warning" style="width:100%;">' . t('bot_status_offline') . '</span>';
    } else {
      $userOnlineStatus = '<span class="' . $tagClass . ' bot-status-tag is-warning" style="width:100%;">' . t('bot_status_unknown') . '</span>';
    }
  } else {
    $userOnlineStatus = '<span class="' . $tagClass . ' bot-status-tag is-warning" style="width:100%;">' . t('bot_status_na') . '</span>';
  }
  $stmt->close();
} else {
  $userOnlineStatus = '<span class="' . $tagClass . ' bot-status-tag is-warning" style="width:100%;">' . t('bot_status_na') . '</span>';
}

// Check only the selected bot's status
if ($selectedBot === 'stable') {
  $statusOutput = getBotsStatus($statusScriptPath, $username, 'stable');
  $botSystemStatus = strpos($statusOutput, 'PID') !== false;
  if ($botSystemStatus) {
    $versionRunning = getRunningVersion($versionFilePath, $newVersion);
  }
} elseif ($selectedBot === 'beta') {
  $betaStatusOutput = getBotsStatus($statusScriptPath, $username, 'beta');
  $betaBotSystemStatus = strpos($betaStatusOutput, 'PID') !== false;
  if ($betaBotSystemStatus) {
    $betaVersionRunning = getRunningVersion($betaVersionFilePath, $betaNewVersion, 'beta');
  }
} elseif ($selectedBot === 'discord') {
  $discordStatusOutput = getBotsStatus($statusScriptPath, $username, 'discord');
  $discordBotSystemStatus = strpos($discordStatusOutput, 'PID') !== false;
  if ($discordBotSystemStatus) {
    $discordVersionRunning = getRunningVersion($discordVersionFilePath, $discordNewVersion);
    $discordRunning = "<div class='status-message'>Discord bot is running.</div>";
  } else {
    $discordRunning = "<div class='status-message error'>Discord bot is NOT RUNNING.</div>";
    $discordVersionRunning = "";
  }
}

// Get last modified time of the bot script files using SSH
require_once 'bot_control_functions.php';
$stableBotScriptPath = "/home/botofthespecter/bot.py";
$betaBotScriptPath = "/home/botofthespecter/beta.py";
$discordBotScriptPath = "/home/botofthespecter/discordbot.py";

if ($backup_system == true) {
  $showButtons = true;
};

// Start output buffering for layout template
ob_start();
?>
<?php if($multiBotWarning): ?>
  <?php echo $multiBotWarning; ?>
<?php endif; ?>
<?php if($subscriptionWarning): ?>
  <?php echo $subscriptionWarning; ?>
<?php endif; ?>
<div class="columns is-variable is-6">
  <div class="column is-4">
    <div class="card has-background-dark has-text-white mb-4">
      <div class="card-header">
        <p class="card-header-title has-text-white is-centered">
          <?php echo t('bot_channel_status'); ?>
        </p>
      </div>
      <div class="card-content">
        <div class="content has-text-centered">
          <?php echo $userOnlineStatus; ?>
          <div class="mt-3">
            <?php
              if ($status === 'True') {
                echo '<button id="force-offline-btn" class="button is-warning is-medium is-fullwidth has-text-black has-text-weight-bold mt-2">'
                  . t('bot_force_offline') . '</button>';
              } elseif ($status === 'False' || $status === null || $status === 'N/A') {
                echo '<button id="force-online-btn" class="button is-success is-medium is-fullwidth has-text-black has-text-weight-bold mt-2">'
                  . t('bot_force_online') . '</button>';
              }
            ?>
          </div>
        </div>
      </div>
    </div>
    <!-- Version Info Card -->
    <div class="card has-background-dark has-text-white mb-4">
      <div class="card-content">
        <div class="content">
          <p class="card-title-mobile has-text-white is-centered" style="font-weight:700;">
            <?php if ($selectedBot === 'stable'): ?>
              <?php echo t('bot_stable_version_info'); ?>
            <?php elseif ($selectedBot === 'beta'): ?>
              <?php echo t('bot_beta_version_info'); ?>
            <?php elseif ($selectedBot === 'discord'): ?>
              <?php echo t('bot_discord_version_info'); ?>
            <?php endif; ?>
          </p>
          <div class="version-meta">
            <p>
              <span class="has-text-grey-light"><?php echo t('bot_last_updated'); ?></span>
              <span id="last-updated" class="has-text-info"></span>
            </p>
            <p>
              <span class="has-text-grey-light"><?php echo t('bot_last_run'); ?></span>
              <span id="last-run" class="has-text-info">
                <?php 
                  echo $selectedBot === 'stable' ? $stableLastRestartOutput : 
                       ($selectedBot === 'beta' ? $lastRestartOutput : 
                       ($selectedBot === 'discord' ? $discordLastRestartOutput : 'Unknown'));
                ?>
              </span>
            </p>
          </div>
          <p class="is-size-7 mt-3 has-text-grey-light">
            <?php echo t('bot_last_run_hint'); ?>
          </p>
        </div>
      </div>
    </div>
    <!-- API Limits Card -->
    <div class="card has-background-dark has-text-white">
      <div class="card-header">
        <p class="card-header-title has-text-white is-centered"><?php echo t('bot_api_limits'); ?></p>
      </div>
      <div class="card-content">
        <div class="api-limit-item mb-5">
          <div class="is-flex is-justify-content-space-between mb-2">
            <span class="has-text-grey-light"><?php echo t('bot_song_id_requests'); ?></span>
            <span id="shazam-count" class="has-text-success has-text-weight-bold">--</span>
          </div>
          <span class="api-helper-text"><?php echo t('bot_updated'); ?>: <span id="shazam-updated">--</span></span>
          <progress class="progress is-success" id="shazam-progress" value="0" max="500"></progress>
        </div>
        <div class="api-limit-item mb-5">
          <div class="is-flex is-justify-content-space-between mb-2">
            <span class="has-text-grey-light"><?php echo t('bot_exchange_rate_requests'); ?></span>
            <span id="exchange-count" class="has-text-info has-text-weight-bold">--</span>
          </div>
          <span class="api-helper-text"><?php echo t('bot_updated'); ?>: <span id="exchange-updated">--</span></span>
          <progress class="progress is-info" id="exchange-progress" value="0" max="1500"></progress>
        </div>
        <div class="api-limit-item">
          <div class="is-flex is-justify-content-space-between mb-2">
            <span class="has-text-grey-light"><?php echo t('bot_weather_requests'); ?></span>
            <span id="weather-count" class="has-text-warning has-text-weight-bold">--</span>
          </div>
          <span class="api-helper-text"><?php echo t('bot_updated'); ?>: <span id="weather-updated">--</span></span>
          <progress class="progress is-warning" id="weather-progress" value="0" max="1000"></progress>
        </div>
      </div>
    </div>
  </div>
  <!-- Main Bot Management Card -->
  <div class="column is-8">
    <div class="card has-background-dark has-text-white mb-4">
      <header class="card-header">
        <div class="is-flex is-justify-content-space-between is-align-items-center" style="width:100%;">
          <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
            <?php echo t('bot_management_title'); ?>
          </span>
          <div class="select is-medium" style="background: transparent; border: none;">
            <select id="bot-selector" onchange="changeBotSelection(this.value)" style="background: #23272f; color: #fff; border: none; font-weight: 600;">
              <option value="stable" <?php if($selectedBot === 'stable') echo 'selected'; ?>><?php echo t('bot_stable_bot'); ?></option>
              <option value="beta" <?php if($selectedBot === 'beta') echo 'selected'; ?>><?php echo t('bot_beta_bot'); ?></option>
              <option value="discord" <?php if($selectedBot === 'discord') echo 'selected'; ?>><?php echo t('bot_discord_bot'); ?></option>
            </select>
          </div>
        </div>
      </header>
      <div class="card-content">
        <?php if ($selectedBot === 'stable'): ?>
          <h3 class="title is-4 has-text-white has-text-centered mb-2">
            <?php echo t('bot_stable_controls') . " (v{$newVersion})"; ?>
          </h3>
          <p class="subtitle is-6 has-text-grey-lighter has-text-centered mb-4"><?php echo t('bot_stable_description'); ?></p>
        <?php elseif ($selectedBot === 'beta' && $betaAccess): ?>
          <h3 class="title is-4 has-text-white has-text-centered mb-2">
            <?php echo t('bot_beta_controls') . " (v{$betaNewVersion} B)"; ?>
          </h3>
          <p class="subtitle is-6 has-text-grey-lighter has-text-centered mb-4"><?php echo t('bot_beta_description'); ?></p>        <?php elseif ($selectedBot === 'discord' && $hasDiscordSetup): ?>
          <h3 class="title is-4 has-text-white has-text-centered mb-2">
            <?php echo t('bot_discord_controls') . " (v{$discordNewVersion})"; ?>
          </h3>
          <p class="subtitle is-6 has-text-grey-lighter has-text-centered mb-4"><?php echo t('bot_discord_description'); ?></p>
        <?php elseif ($selectedBot === 'discord' && $discordNeedsRelink): ?>
          <div class="notification is-warning has-text-black mb-4">
            <div class="has-text-centered">
              <h4 class="title is-5 has-text-black mb-3">
                <span class="icon is-medium">
                  <i class="fas fa-exclamation-triangle"></i>
                </span>
                Discord Account Reconnection Required
              </h4>
              <p class="mb-3">
                Your Discord account connection is missing required authentication tokens or has expired. 
                The Discord bot cannot operate without valid tokens.
              </p>
              <a href="discordbot.php" class="button is-primary is-medium has-text-weight-bold">
                <span class="icon">
                  <i class="fab fa-discord"></i>
                </span>
                <span>Reconnect Discord Account</span>
              </a>
            </div>
          </div>
        <?php elseif ($selectedBot === 'discord'): ?>
          <div class="notification is-info has-text-black mb-4">
            <div class="has-text-centered">
              <h4 class="title is-5 has-text-black mb-3">
                <span class="icon is-medium">
                  <i class="fab fa-discord"></i>
                </span>
                Discord Setup Required
              </h4>
              <p class="mb-3">
                To use the Discord bot, you need to connect your Discord account first. 
                This allows the bot to interact with your Discord server.
              </p>
              <a href="discordbot.php" class="button is-primary is-medium has-text-weight-bold">
                <span class="icon">
                  <i class="fab fa-discord"></i>
                </span>
                <span>Connect Discord Account</span>
              </a>
            </div>
          </div>        <?php endif; ?>
        
        <?php 
        // Only show bot status and controls if the bot is properly configured
        $showBotControls = false;
        if ($selectedBot === 'stable' || $selectedBot === 'beta') {
          $showBotControls = true;
        } elseif ($selectedBot === 'discord' && $hasDiscordSetup) {
          $showBotControls = true;
        }
        ?>
        <?php if ($showBotControls): ?>
        <div class="is-flex is-justify-content-center is-align-items-center mb-4" style="gap: 2rem;">
          <span class="icon is-large">
            <?php
              // Determine running status for the selected bot only
              $isRunning = false;
              if ($selectedBot === 'stable') {
                $isRunning = $stableRunning;
              } elseif ($selectedBot === 'beta') {
                $isRunning = $betaRunning;
              } elseif ($selectedBot === 'discord') {
                $isRunning = $discordBotSystemStatus;
              }
              // Use a green beating heart if running, else a red broken heart
              if ($isRunning) {
                $heartIcon = '<i class="fas fa-heartbeat fa-2x has-text-success beating"></i>';
              } else {
                $heartIcon = '<i class="fas fa-heart-broken fa-2x has-text-danger"></i>';
              }
              echo $heartIcon;
            ?>
          </span>
          <span class="is-size-5" style="font-weight:600;">
            <?php
              $statusText = $selectedBot === 'stable' ? ($stableRunning ? t('bot_online') : t('bot_offline')) :
                            ($selectedBot === 'beta' ? ($betaRunning ? t('bot_online') : t('bot_offline')) :
                            ($selectedBot === 'discord' ? ($discordBotSystemStatus ? t('bot_online') : t('bot_offline')) : t('bot_unknown')));
              echo t('bot_status_label') . " <span class='has-text-" . (($statusText === t('bot_online')) ? "success" : "danger") . "'>$statusText</span>";
            ?>
          </span>
        </div>
        <div class="buttons is-centered mb-2">
          <?php
            // Only show STOP if running, RUN if not running
            if ($isRunning) {
              ?>
              <button id="stop-bot-btn" class="button is-danger is-medium has-text-black has-text-weight-bold px-6 mr-3">
                <span class="icon"><i class="fas fa-stop"></i></span>
                <span><?php echo t('bot_stop'); ?></span>
              </button>
              <?php
            } else {
              ?>
              <button id="run-bot-btn" class="button is-success is-medium has-text-black has-text-weight-bold px-6 mr-3">
                <span class="icon"><i class="fas fa-play"></i></span>
                <span><?php echo t('bot_run'); ?></span>
              </button>
              <?php
            }
          ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <!-- System Status Card -->
    <div class="card has-background-dark has-text-white">
      <div class="card-header">
        <p class="card-header-title has-text-white is-centered"><?php echo t('bot_system_status'); ?></p>
      </div>
      <div class="card-content">
        <!-- Service Health Meters -->
        <div class="columns is-multiline">
          <div class="column is-4">
            <div class="box has-background-darker has-text-centered p-4">
              <div class="mb-3">
                <span class="icon is-large">
                  <i id="apiService" class="fas fa-heartbeat fa-2x beating has-text-success"></i>
                </span>
              </div>
              <h4 class="subtitle has-text-white mb-1"><?php echo t('bot_api_service'); ?></h4>
              <p id="api-service-status" class="is-size-7 has-text-grey-light"><?php echo t('bot_running_normally'); ?></p>
            </div>
          </div>
          <div class="column is-4">
            <div class="box has-background-darker has-text-centered p-4">
              <div class="mb-3">
                <span class="icon is-large">
                  <i id="databaseService" class="fas fa-heartbeat fa-2x beating has-text-success"></i>
                </span>
              </div>
              <h4 class="subtitle has-text-white mb-1"><?php echo t('bot_database_service'); ?></h4>
              <p id="db-service-status" class="is-size-7 has-text-grey-light"><?php echo t('bot_running_normally'); ?></p>
            </div>
          </div>
          <div class="column is-4">
            <div class="box has-background-darker has-text-centered p-4">
              <div class="mb-3">
                <span class="icon is-large">
                  <i id="notificationService" class="fas fa-heartbeat fa-2x beating has-text-success"></i>
                </span>
              </div>
              <h4 class="subtitle has-text-white mb-1"><?php echo t('bot_notification_service'); ?></h4>
              <p id="notif-service-status" class="is-size-7 has-text-grey-light"><?php echo t('bot_running_normally'); ?></p>
            </div>
          </div>
        </div>
        <h4 class="title is-5 has-text-white has-text-centered mt-5 mb-4"><?php echo t('bot_streaming_service_status'); ?></h4>
        <div class="columns is-multiline">
          <div class="column is-4">
            <div class="box has-background-darker has-text-centered p-4">
              <div class="mb-2">
                <span class="icon is-large">
                  <i id="auEast1Service" class="fas fa-heartbeat fa-2x beating has-text-success"></i>
                </span>
              </div>
              <h4 class="subtitle has-text-white mb-1">AU-EAST-1</h4>
              <p id="auEast1-service-status" class="is-size-7 has-text-grey-light"><?php echo t('bot_running_normally'); ?></p>
            </div>
          </div>
          <div class="column is-4">
            <div class="box has-background-darker has-text-centered p-4">
              <div class="mb-2">
                <span class="icon is-large">
                  <i id="usWest1Service" class="fas fa-heartbeat fa-2x beating has-text-success"></i>
                </span>
              </div>
              <h4 class="subtitle has-text-white mb-1">US-WEST-1</h4>
              <p id="usWest1-service-status" class="is-size-7 has-text-grey-light"><?php echo t('bot_running_normally'); ?></p>
            </div>
          </div>
          <div class="column is-4">
            <div class="box has-background-darker has-text-centered p-4">
              <div class="mb-2">
                <span class="icon is-large">
                  <i id="usEast1Service" class="fas fa-heartbeat fa-2x beating has-text-success"></i>
                </span>
              </div>
              <h4 class="subtitle has-text-white mb-1">US-EAST-1</h4>
              <p id="usEast1-service-status" class="is-size-7 has-text-grey-light"><?php echo t('bot_running_normally'); ?></p>
            </div>
          </div>
        </div>
        <div class="has-text-centered mt-5">
          <a href="https://uptime.botofthespecter.com/" target="_blank" class="button is-link is-fullwidth has-text-weight-bold">
            <span class="icon"><i class="fas fa-chart-line"></i></span>
            <span><?php echo t('bot_view_detailed_uptime'); ?></span>
          </a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Initialize the notification deletion functionality
  const deleteButtons = document.querySelectorAll('.notification .delete');
  deleteButtons.forEach(button => {
    const notification = button.parentNode;
    button.addEventListener('click', () => { notification.parentNode.removeChild(notification); });
  });
  // Global flag to track bot run operations in progress
  let botRunOperationInProgress = false;
  let currentBotBeingStarted = null;
  // Bot control buttons
  let stopBotBtn = document.getElementById('stop-bot-btn');
  let runBotBtn = document.getElementById('run-bot-btn');
  let botActionInProgress = false;
  const urlParams = new URLSearchParams(window.location.search);
  const selectedBot = urlParams.get('bot') || 'stable';
  function attachBotButtonListeners() {
    stopBotBtn = document.getElementById('stop-bot-btn');
    runBotBtn = document.getElementById('run-bot-btn');
    function getCurrentBotType() {
      const urlParams = new URLSearchParams(window.location.search);
      let bot = urlParams.get('bot');
      if (!bot) {
        bot = getCookie('selectedBot');
      }
      if (!bot) {
        bot = 'stable';
      }
      return bot;
    }
    if (stopBotBtn) {
      stopBotBtn.addEventListener('click', () => {
        const bot = getCurrentBotType();
        if (bot === 'stable') handleStableBotAction('stop');
        else if (bot === 'beta') handleBetaBotAction('stop');
        else handleDiscordBotAction('stop');
      });
    }
    if (runBotBtn) {
      runBotBtn.addEventListener('click', () => {
        const bot = getCurrentBotType();
        if (bot === 'stable') handleStableBotAction('run');
        else if (bot === 'beta') handleBetaBotAction('run');
        else handleDiscordBotAction('run');
      });
    }
  }
  attachBotButtonListeners();  // Function to handle bot actions
  function handleStableBotAction(action) {
    const btn = action === 'stop' ? stopBotBtn : runBotBtn;
    const originalContent = btn.innerHTML;
    // Show immediate feedback that action was initiated
    showNotification(`Stable bot ${action} command sent...`, 'info');
    btn.innerHTML = `<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span><?php echo t('bot_working'); ?></span>`;
    btn.disabled = true;
    // Set global flag for run operations
    if (action === 'run') {
      botRunOperationInProgress = true;
      currentBotBeingStarted = 'stable';
    }
    // Use setTimeout to avoid blocking the UI
    setTimeout(() => {
      fetch('bot_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=${encodeURIComponent(action)}&bot=stable`
      })
        .then(response => response.json())
        .then(data => {
          console.log('Stable bot action response:', data);
          if (data.success) {
            showNotification(`Stable bot ${action} command executed successfully`, 'success');
            // For run action, check if we already have a PID from the response
            if (action === 'run') {
              if (data.pid && parseInt(data.pid) > 0) {
                // Bot started successfully and we have a PID
                showNotification(`Stable bot is now running with PID ${data.pid}!`, 'success');
                updateBotStatus();
                botRunOperationInProgress = false;
                currentBotBeingStarted = null;
              } else {
                // Bot might be starting, poll for status
                startPollingBotStatus('stable', 20); // Increase attempts to 20
              }
            } else {
              // For stop action, check status after a brief delay
              setTimeout(() => {
                updateBotStatus();
              }, 1000);
            }
          } else { 
            // Reset flag on failure
            if (action === 'run') {
              botRunOperationInProgress = false;
              currentBotBeingStarted = null;
            }
            showNotification(`Failed to ${action} stable bot: ${data.message}`, 'danger'); 
          }
          // Always restore button state
          btn.innerHTML = originalContent;
          btn.disabled = false;
        })
        .catch(error => {
          console.error('Error:', error);
          // Reset flag on error
          if (action === 'run') {
            botRunOperationInProgress = false;
            currentBotBeingStarted = null;
          }
          showNotification(`Error processing request: ${error}`, 'danger');
          // Always restore button state
          btn.innerHTML = originalContent;
          btn.disabled = false;
        });
    }, 10); // Small delay to prevent UI blocking
  }

  function handleBetaBotAction(action) {
    const btn = action === 'stop' ? stopBotBtn : runBotBtn;
    const originalContent = btn.innerHTML;
    // Show immediate feedback that action was initiated
    showNotification(`Beta bot ${action} command sent...`, 'info');
    btn.innerHTML = `<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span><?php echo t('bot_working'); ?></span>`;
    btn.disabled = true;
    // Set global flag for run operations
    if (action === 'run') {
      botRunOperationInProgress = true;
      currentBotBeingStarted = 'beta';
    }
    // Use setTimeout to avoid blocking the UI
    setTimeout(() => {
      fetch('bot_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=${encodeURIComponent(action)}&bot=beta`
      })
        .then(response => response.json())
        .then(data => {
          console.log('Beta bot action response:', data);
          if (data.success) {
            showNotification(`Beta bot ${action} command executed successfully`, 'success');
            // For run action, check if we already have a PID from the response
            if (action === 'run') {
              if (data.pid && parseInt(data.pid) > 0) {
                // Bot started successfully and we have a PID
                showNotification(`Beta bot is now running with PID ${data.pid}!`, 'success');
                updateBotStatus();
                botRunOperationInProgress = false;
                currentBotBeingStarted = null;
              } else {
                // Bot might be starting, poll for status
                startPollingBotStatus('beta', 20); // Increase attempts to 20
              }
            } else {
              // For stop action, check status after a brief delay
              setTimeout(() => {
                updateBotStatus();
              }, 1000);
            }
          } else {
            // Reset flag on failure
            if (action === 'run') {
              botRunOperationInProgress = false;
              currentBotBeingStarted = null;
            }
            showNotification(`Failed to ${action} beta bot: ${data.message}`, 'danger');
          }
          // Always restore button state
          btn.innerHTML = originalContent;
          btn.disabled = false;
        })
        .catch(error => {
          console.error('Error:', error);
          // Reset flag on error
          if (action === 'run') {
            botRunOperationInProgress = false;
            currentBotBeingStarted = null;
          }
          showNotification(`Error processing request: ${error}`, 'danger');
          // Always restore button state
          btn.innerHTML = originalContent;
          btn.disabled = false;
        });
    }, 10); // Small delay to prevent UI blocking
  }

  function handleDiscordBotAction(action) {
    const btn = action === 'stop' ? stopBotBtn : runBotBtn;
    const originalContent = btn.innerHTML;
    // Show immediate feedback that action was initiated
    showNotification(`Discord bot ${action} command sent...`, 'info');
    btn.innerHTML = `<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span><?php echo t('bot_working'); ?></span>`;
    btn.disabled = true;
    // Set global flag for run operations
    if (action === 'run') {
      botRunOperationInProgress = true;
      currentBotBeingStarted = 'discord';
    }
    // Use setTimeout to avoid blocking the UI
    setTimeout(() => {
      fetch('bot_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=${encodeURIComponent(action)}&bot=discord`
      })
        .then(response => response.json())
        .then(data => {
          console.log('Discord bot action response:', data);
          if (data.success) {
            showNotification(`Discord bot ${action} command executed successfully`, 'success');
            // For run action, check if we already have a PID from the response
            if (action === 'run') {
              if (data.pid && parseInt(data.pid) > 0) {
                // Bot started successfully and we have a PID
                showNotification(`Discord bot is now running with PID ${data.pid}!`, 'success');
                updateBotStatus();
                botRunOperationInProgress = false;
                currentBotBeingStarted = null;
              } else {
                // Bot might be starting, poll for status
                startPollingBotStatus('discord', 20); // Increase attempts to 20
              }
            } else {
              // For stop action, check status after a brief delay
              setTimeout(() => {
                updateBotStatus();
              }, 1000);
            }
          } else {
            // Reset flag on failure
            if (action === 'run') {
              botRunOperationInProgress = false;
              currentBotBeingStarted = null;
            }
            showNotification(`Failed to ${action} discord bot: ${data.message}`, 'danger');
          }
          // Always restore button state
          btn.innerHTML = originalContent;
          btn.disabled = false;
        })
        .catch(error => {
          console.error('Error:', error);
          // Reset flag on error
          if (action === 'run') {
            botRunOperationInProgress = false;
            currentBotBeingStarted = null;
          }
          showNotification(`Error processing request: ${error}`, 'danger');
          // Always restore button state
          btn.innerHTML = originalContent;
          btn.disabled = false;
        });
    }, 10); // Small delay to prevent UI blocking
  }
  // Non-blocking polling function using setInterval instead of recursion
  function startPollingBotStatus(botType, maxAttempts) {
    let currentAttempt = 0;
    let pollInterval;
    // Show initial progress
    showNotification(`Checking ${botType} bot status...`, 'info');
    pollInterval = setInterval(() => {
      currentAttempt++;
      // Check if we've exceeded max attempts
      if (currentAttempt > maxAttempts) {
        clearInterval(pollInterval);
        showNotification(`${botType.charAt(0).toUpperCase() + botType.slice(1)} bot status check timed out. Please refresh the page to check current status.`, 'warning');
        // Reset the global flag even on timeout
        if (currentBotBeingStarted === botType) {
          botRunOperationInProgress = false;
          currentBotBeingStarted = null;
        }
        return;
      }
      // Show progress every few attempts to avoid spam
      if (currentAttempt % 4 === 0) {
        showNotification(`Checking ${botType} bot status... (${currentAttempt}/${maxAttempts})`, 'info');
      }
      // Make the status check request
      fetch(`check_bot_status.php?bot=${botType}&_t=${Date.now()}`)
        .then(async response => {
          const text = await response.text();
          console.log(`Bot status check attempt ${currentAttempt} for ${botType}:`, text);
          try {
            const data = JSON.parse(text);
            console.log('Parsed bot status data:', data);
            // Check if the bot is running - be more lenient with the checks
            if (data.success) {
              if (data.running && data.pid && parseInt(data.pid) > 0) {
                // Bot is now running with a valid PID - success!
                clearInterval(pollInterval);
                showNotification(`${botType.charAt(0).toUpperCase() + botType.slice(1)} bot is now running with PID ${data.pid}!`, 'success');
                updateBotStatus();
                // Reset the global flag and clean up persistent notifications
                if (currentBotBeingStarted === botType) {
                  botRunOperationInProgress = false;
                  currentBotBeingStarted = null;
                  // Remove any persistent bot operation notifications
                  document.querySelectorAll('.notification.bot-operation-persistent').forEach(n => {
                    if (n.parentNode) n.parentNode.removeChild(n);
                  });
                }
                return;
              }
              // If on attempt 5 or later and we have success but no PID, show alternative success
              if (currentAttempt >= 5 && data.message && (data.message.includes('running') || data.message.includes('started'))) {
                clearInterval(pollInterval);
                showNotification(`${botType.charAt(0).toUpperCase() + botType.slice(1)} bot appears to be running! Refreshing status...`, 'success');
                updateBotStatus();
                // Reset the global flag
                if (currentBotBeingStarted === botType) {
                  botRunOperationInProgress = false;
                  currentBotBeingStarted = null;
                }
                return;
              }
            }
            // If not running yet, continue polling (interval will handle the next attempt)
          } catch (e) {
            console.error('Error parsing bot status JSON:', e, 'Raw response:', text);
            // Continue polling on parse error
          }
        })
        .catch(error => {
          console.error('Error fetching bot status:', error);
          // Continue polling on network error
        });
    }, 1000); // Check every 1 second
  }

  // Function to show notifications
  function showNotification(message, type) {
    // Remove existing notifications with the same message and type
    document.querySelectorAll(`.notification.is-${type}`).forEach(n => {
      if (n.textContent.trim() === message.trim()) {
        n.parentNode.removeChild(n);
      }
    });
    // Remove previous status checking notifications when showing new ones
    if (message.includes('command sent') || message.includes('Checking') || message.includes('is now running')) {
      document.querySelectorAll('.notification.is-info').forEach(n => {
        if (n.textContent.includes('command sent') || n.textContent.includes('Checking') || n.textContent.includes('status check timed out')) {
          n.parentNode.removeChild(n);
        }
      });
    }
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification is-${type}`;
    // Add special handling for bot run operations in progress
    const isPersistentBotOperation = message.includes('currently starting up') || message.includes('Cannot switch bot types');
    if (isPersistentBotOperation) {
      notification.classList.add('bot-operation-persistent');
      notification.innerHTML = `
        <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
        ${message}
      `;
    } else {
      notification.innerHTML = `
        <button class="delete"></button>
        ${message}
      `;
      // Add delete button functionality
      const deleteBtn = notification.querySelector('.delete');
      deleteBtn.addEventListener('click', () => {
        notification.parentNode.removeChild(notification);
      });
    }
    // Add to the page
    const container = document.querySelector('.container');
    container.insertBefore(notification, container.firstChild);
    // Auto-remove after time (except for persistent bot operation messages)
    if (!isPersistentBotOperation) {
      const autoRemoveTime = type === 'info' ? 3000 : 5000;
      setTimeout(() => {
        if (notification.parentNode) {
          notification.parentNode.removeChild(notification);
        }
      }, autoRemoveTime);
    }
  }
  function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(';').shift();
    return null;
  }
  // Function to update bot status
  function updateBotStatus() {
    const urlParams = new URLSearchParams(window.location.search);
    let selectedBot = urlParams.get('bot');
    if (!selectedBot) { selectedBot = getCookie('selectedBot'); }
    if (!selectedBot) { selectedBot = 'stable'; }
    fetch(`check_bot_status.php?bot=${selectedBot}&_t=${Date.now()}`)
      .then(async response => {
        const text = await response.text();
        console.log('updateBotStatus response text:', text);
        try {
          const data = JSON.parse(text);
          console.log('updateBotStatus parsed data:', data);
          if (data.success) {
            // Update status icon and text
            const statusText = data.running ? 'ONLINE' : 'OFFLINE';
            const statusClass = data.running ? 'success' : 'danger';
            // Update the heartbeat icon for the selected bot only
            const heartIconContainer = document.querySelector('.icon.is-large');
            if (heartIconContainer) {
              if (data.running) { heartIconContainer.innerHTML = '<i class="fas fa-heartbeat fa-2x has-text-success beating"></i>'; }
              else { heartIconContainer.innerHTML = '<i class="fas fa-heart-broken fa-2x has-text-danger"></i>'; }
            }
              // Update status text
            const statusSpan = document.querySelector('.is-size-5 span[class*="has-text-"]');
            if (statusSpan) {
              statusSpan.textContent = statusText;
              statusSpan.className = `has-text-${statusClass}`;
            }
            // Update buttons by replacing the entire button container
            const buttonContainer = document.querySelector('.buttons.is-centered.mb-2');
            if (buttonContainer) {
              if (data.running) {
                // Show Stop button
                buttonContainer.innerHTML = `
                  <button id="stop-bot-btn" class="button is-danger is-medium has-text-black has-text-weight-bold px-6 mr-3">
                    <span class="icon"><i class="fas fa-stop"></i></span>
                    <span><?php echo addslashes(t('bot_stop')); ?></span>
                  </button>
                `;
              } else {
                // Show Run button
                buttonContainer.innerHTML = `
                  <button id="run-bot-btn" class="button is-success is-medium has-text-black has-text-weight-bold px-6 mr-3">
                    <span class="icon"><i class="fas fa-play"></i></span>
                    <span><?php echo addslashes(t('bot_run')); ?></span>
                  </button>
                `;
              }
              // Re-attach event listeners after updating the DOM
              attachBotButtonListeners();
            } else {}
            // Update last modified and last run if elements exist
            const lastUpdatedElement = document.getElementById('last-updated');
            if (lastUpdatedElement) {
              lastUpdatedElement.textContent = data.lastModified;
            }
            const lastRunElement = document.getElementById('last-run');
            if (lastRunElement) {
              lastRunElement.textContent = data.lastRun;
            }
            // Clear any existing error messages since this was successful
            let errorElement = document.querySelector('.bot-status-error');
            if (errorElement) {
              errorElement.style.display = 'none';
            }
            // Clear any existing info messages 
            let infoElement = document.querySelector('.bot-status-info');
            if (infoElement) {
              infoElement.style.display = 'none';
            }
          } else {
            console.error('Bot status API returned error:', data);
            // Clear any existing info messages since this is an error
            let infoElement = document.querySelector('.bot-status-info');
            if (infoElement) {
              infoElement.style.display = 'none';
            }
            // Display error message to user if available
            if (data.message) {
              // Try to find an error display element or create one
              let errorElement = document.querySelector('.bot-status-error');
              if (!errorElement) {
                errorElement = document.createElement('div');
                errorElement.className = 'notification is-danger bot-status-error';
                errorElement.style.margin = '10px 0';
                // Try to insert after the status heading
                const statusContainer = document.querySelector('.is-size-5');
                if (statusContainer && statusContainer.parentNode) {
                  statusContainer.parentNode.insertBefore(errorElement, statusContainer.nextSibling);
                }
              }
              errorElement.style.display = 'block';
              // Customize error message based on type
              if (data.message.includes('SSH connection failed')) {
                errorElement.innerHTML = `<strong>Connection Error:</strong> ${data.message}. Please check server connectivity or contact support.`;
              } else if (data.message.includes('SSH authentication failed')) {
                errorElement.innerHTML = `<strong>Authentication Error:</strong> ${data.message}. Please contact support.`;
              } else if (data.message.includes('SSH2 PHP extension')) {
                errorElement.innerHTML = `<strong>Server Configuration Error:</strong> ${data.message}. Please contact support.`;
              } else {
                errorElement.innerHTML = `<strong>Bot Status Error:</strong> ${data.message}`;
              }
            }
          }
        } catch (e) {
          console.error('Error parsing bot status JSON:', e, text);
        }
      }
    )
    .catch(error => console.error('Error fetching bot status:', error));
  }

  // Function to update API limits from api_limits.php
  function updateApiLimits() {
    fetch('api_limits.php')
      .then(response => response.json())
      .then(data => {
        // Shazam
        if (document.getElementById('shazam-count')) {
          document.getElementById('shazam-count').textContent = data.shazam.requests_remaining;
          document.getElementById('shazam-progress').value = data.shazam.requests_remaining;
          document.getElementById('shazam-progress').max = data.shazam.requests_limit;
          document.getElementById('shazam-updated').textContent = timeAgo(data.shazam.last_updated);
        }
        // Exchange Rate
        if (document.getElementById('exchange-count')) {
          document.getElementById('exchange-count').textContent = data.exchangerate.requests_remaining;
          document.getElementById('exchange-progress').value = data.exchangerate.requests_remaining;
          document.getElementById('exchange-progress').max = data.exchangerate.requests_limit;
          document.getElementById('exchange-updated').textContent = timeAgo(data.exchangerate.last_updated);
        }
        // Weather
        if (document.getElementById('weather-count')) {
          document.getElementById('weather-count').textContent = data.weather.requests_remaining;
          document.getElementById('weather-progress').value = data.weather.requests_remaining;
          document.getElementById('weather-progress').max = data.weather.requests_limit;
          document.getElementById('weather-updated').textContent = timeAgo(data.weather.last_updated);
        }
      })
      .catch(() => {
        // fallback: show dashes if error
        if (document.getElementById('shazam-count')) document.getElementById('shazam-count').textContent = '--';
        if (document.getElementById('exchange-count')) document.getElementById('exchange-count').textContent = '--';
        if (document.getElementById('weather-count')) document.getElementById('weather-count').textContent = '--';
      });
  }

  // Inject translations for "ago" time units
  const agoTranslations = {
    seconds: <?php echo json_encode(t('time_seconds_ago', [':count' => ':count'])); ?>,
    minutes: <?php echo json_encode(t('time_minutes_ago', [':count' => ':count'])); ?>,
    hours: <?php echo json_encode(t('time_hours_ago', [':count' => ':count'])); ?>,
    days: <?php echo json_encode(t('time_days_ago', [':count' => ':count'])); ?>
  };

  // Helper: convert ISO date to "time ago"
  function timeAgo(isoDate) {
    if (!isoDate) return '--';
    const now = new Date();
    const then = new Date(isoDate);
    const diff = Math.floor((now - then) / 1000);
    if (diff < 60) {
      return agoTranslations.seconds.replace(':count', diff);
    }
    if (diff < 3600) {
      return agoTranslations.minutes.replace(':count', Math.floor(diff/60));
    }
    if (diff < 86400) {
      return agoTranslations.hours.replace(':count', Math.floor(diff/3600));
    }
    return agoTranslations.days.replace(':count', Math.floor(diff/86400));
  }

  // Function to update service status from api_status.php
  function updateServiceStatus() {
    // Map service icon IDs to their api_status.php service param and status text element IDs
    const services = [
      { id: 'apiService', api: 'api', statusId: 'api-service-status' },
      { id: 'databaseService', api: 'database', statusId: 'db-service-status' },
      { id: 'notificationService', api: 'websocket', statusId: 'notif-service-status' },
      { id: 'auEast1Service', api: 'streamingService', statusId: 'auEast1-service-status' },
      { id: 'usWest1Service', api: 'streamingServiceWest', statusId: 'usWest1-service-status' },
      { id: 'usEast1Service', api: 'streamingServiceEast', statusId: 'usEast1-service-status' }
    ];
    // Inject translations from PHP
    const runningNormallyText = <?php echo json_encode(t('bot_running_normally')); ?>;
    const serviceDegradedText = <?php echo json_encode(t('bot_status_unknown')); ?>;
    const statusCheckFailedText = <?php echo json_encode(t('bot_refresh_channel_status_failed')); ?>;
    services.forEach(svc => {
      fetch('api_status.php?service=' + svc.api)
        .then(r => r.json())
        .then(data => {
          const icon = document.getElementById(svc.id);
          const statusElem = document.getElementById(svc.statusId);
          if (!icon || !statusElem) return;
          if (data.status === 'OK') {
            icon.className = 'fas fa-heartbeat fa-2x has-text-success beating';
            statusElem.textContent = runningNormallyText;
            statusElem.className = 'is-size-7 has-text-grey-light';
          } else {
            icon.className = 'fas fa-heart-broken fa-2x has-text-danger';
            statusElem.textContent = data.message || serviceDegradedText;
            statusElem.className = 'is-size-7 has-text-danger';
          }
        })
        .catch(error => {
          console.error(`Error checking status for ${svc.api}:`, error);
          // Handle error case for service status
          const icon = document.getElementById(svc.id);
          const statusElem = document.getElementById(svc.statusId);
          if (icon && statusElem) {
            icon.className = 'fas fa-heart-broken fa-2x has-text-danger';
            statusElem.textContent = statusCheckFailedText;
            statusElem.className = 'is-size-7 has-text-danger';
          }
        });
    });
  }

  // Set up polling for status updates
  setInterval(updateServiceStatus, 10000);
  setInterval(updateApiLimits, 30000);
  setInterval(updateBotStatus, 60000); // Check bot status every minute
  // Initial calls to populate data
  updateServiceStatus();
  updateApiLimits();
  updateBotStatus();
  attachBotButtonListeners();
  // Channel Status Force Buttons
  const forceOnlineBtn = document.getElementById('force-online-btn');
  const forceOfflineBtn = document.getElementById('force-offline-btn');
  // You may want to set apiKey from PHP session or config
  const apiKey = <?php echo json_encode($user['api_key'] ?? ''); ?>;
  function fetchAndUpdateChannelStatus() {
    // Only run if no bot action is in progress
    if (botActionInProgress) return;
    // AJAX call to get the latest status from the backend
    fetch('check_channel_status.php')
      .then(r => {
        if (!r.ok) throw new Error('Network response was not ok');
        return r.json();
      })
      .then(data => {
        if (typeof data.status !== 'undefined') {
          updateChannelStatusDisplay(data.status);
        } else {
          showNotification(<?php echo json_encode(t('bot_refresh_channel_status_failed')); ?>, 'danger');
        }
      })
      .catch(() => {
        showNotification(<?php echo json_encode(t('bot_refresh_channel_status_failed')); ?>, 'danger');
      });
  }

  function updateChannelStatusDisplay(newStatus) {
    // Update the status tag and buttons in the Channel Status card
    const contentDiv = document.querySelector('.card-content .content.has-text-centered');
    if (!contentDiv) return;
    let statusHtml = '';
    if (newStatus === 'True') {
      statusHtml = '<span class="<?php echo $tagClass; ?> bot-status-tag is-success" style="width:100%;">' + <?php echo json_encode(t('bot_status_online')); ?> + '</span>' +
                   '<div class="mt-3"><button id="force-offline-btn" class="button is-warning is-medium is-fullwidth has-text-black has-text-weight-bold mt-2"><?php echo t('bot_force_offline'); ?></button></div>';
    } else if (newStatus === 'False') {
      statusHtml = '<span class="<?php echo $tagClass; ?> bot-status-tag is-warning" style="width:100%;">' + <?php echo json_encode(t('bot_status_offline')); ?> + '</span>' +
                   '<div class="mt-3"><button id="force-online-btn" class="button is-success is-medium is-fullwidth has-text-black has-text-weight-bold mt-2"><?php echo t('bot_force_online'); ?></button></div>';
    } else if (newStatus === null || newStatus === 'N/A') {
      statusHtml = '<span class="<?php echo $tagClass; ?> bot-status-tag is-warning" style="width:100%;">' + <?php echo json_encode(t('bot_status_na')); ?> + '</span>';
    } else {
      statusHtml = '<span class="<?php echo $tagClass; ?> bot-status-tag is-warning" style="width:100%;">' + <?php echo json_encode(t('bot_status_unknown')); ?> + '</span>';
    }
    contentDiv.innerHTML = statusHtml;
    // Re-attach event listeners
    attachForceButtons();
  }

  function attachForceButtons() {
    const onlineBtn = document.getElementById('force-online-btn');
    const offlineBtn = document.getElementById('force-offline-btn');
    if (onlineBtn) {
      onlineBtn.addEventListener('click', function() {
        onlineBtn.disabled = true;
        fetch('https://api.botofthespecter.com/websocket/stream_online?api_key=' + encodeURIComponent(apiKey))
          .then(r => r.json())
          .then(data => {
            showNotification(<?php echo json_encode(t('bot_channel_forced_online')); ?>, 'success');
            setTimeout(fetchAndUpdateChannelStatus, 1200);
          })
          .catch(() => {
            showNotification(<?php echo json_encode(t('bot_channel_force_online_failed')); ?>, 'danger');
            onlineBtn.disabled = false;
          });
      });
    }
    if (offlineBtn) {
      offlineBtn.addEventListener('click', function() {
        offlineBtn.disabled = true;
        fetch('https://api.botofthespecter.com/websocket/stream_offline?api_key=' + encodeURIComponent(apiKey))
          .then(r => r.json())
          .then(data => {
            showNotification(<?php echo json_encode(t('bot_channel_forced_offline')); ?>, 'success');
            setTimeout(fetchAndUpdateChannelStatus, 1200);
          })
          .catch(() => {
            showNotification(<?php echo json_encode(t('bot_channel_force_offline_failed')); ?>, 'danger');
            offlineBtn.disabled = false;
          });
      });
    }
  }
  attachForceButtons();
  
  // Prevent page refresh/navigation when bot run operation is in progress
  window.addEventListener('beforeunload', function(e) {
    if (botRunOperationInProgress) {
      const message = `${currentBotBeingStarted ? currentBotBeingStarted.charAt(0).toUpperCase() + currentBotBeingStarted.slice(1) : 'Bot'} is currently starting up. Are you sure you want to leave?`;
      e.preventDefault();
      e.returnValue = message;
      return message;
    }
  });
  
  window.changeBotSelection = function(bot) {
    // Check if a bot run operation is in progress
    if (botRunOperationInProgress) {
      // Show warning notification
      showNotification(`Please wait! ${currentBotBeingStarted ? currentBotBeingStarted.charAt(0).toUpperCase() + currentBotBeingStarted.slice(1) : 'Bot'} is currently starting up. Cannot switch bot types until the bot is running with a valid PID.`, 'warning');
      // Reset the dropdown to the current selection to prevent visual confusion
      const botSelector = document.getElementById('bot-selector');
      if (botSelector) {
        const urlParams = new URLSearchParams(window.location.search);
        let currentBot = urlParams.get('bot');
        if (!currentBot) {
          currentBot = getCookie('selectedBot');
        }
        if (!currentBot) {
          currentBot = 'stable';
        }
        botSelector.value = currentBot;
      }
      return; // Don't proceed with the change
    }
    const url = new URL(window.location.href);
    url.searchParams.set('bot', bot);
    window.location.href = url.toString();
  };
});
// updateApiLimits();
</script>
<?php
// Get the buffered content
$scripts = ob_get_clean();
// Include the layout template
include 'layout.php';
?>