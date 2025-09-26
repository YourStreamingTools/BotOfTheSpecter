<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';
$today = new DateTime();
$backup_system = false;

function validateTwitchToken($token) {
  $url = "https://id.twitch.tv/oauth2/validate";
  $headers = ['Authorization: OAuth ' . $token];
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout
  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($httpCode === 200) {
    $data = json_decode($response, true);
    return $data; // Return the validation data including expires_in
  } else {
    return false; // Token is invalid or expired
  }
}

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
  header('Location: login.php');
  exit();
}

// Validate the Twitch token
$tokenData = validateTwitchToken($_SESSION['access_token']);
$consoleLog = '';
if (!$tokenData) {
  // Token is invalid, clear session and redirect to login
  session_unset();
  session_destroy();
  header('Location: login.php');
  exit();
} else {
  // Prepare console log for validation info (without exposing sensitive data)
  $consoleLog = "<script>console.log('Token validated: " . addslashes($tokenData['login']) . " | " . addslashes($tokenData['user_id']) . " | " . addslashes($tokenData['expires_in']) . "');</script>";
}

// Page Title and Initial Variables
$pageTitle = t('bot_management_title');
$statusOutput = '';
$betaStatusOutput = '';
$pid = '';
$versionRunning = '';
$betaVersionRunning = '';
$BotIsMod = false;
$BotModMessage = "";
$setupMessage = "";
$showButtons = false;
$lastModifiedOutput = '';
$lastRestartOutput = '';
$stableLastModifiedOutput = '';
$stableLastRestartOutput = '';

$selectedBot = $_GET['bot'] ?? null;
if (isset($_GET['bot'])) {
  if (in_array($_GET['bot'], ['stable', 'beta'])) {
    setcookie('selectedBot', $_GET['bot'], time() + (86400 * 30), "/"); // Cookie for 30 days
  }
}
else if (!isset($_GET['bot']) && isset($_COOKIE['selectedBot']) && in_array($_COOKIE['selectedBot'], ['stable', 'beta'])) {
  $selectedBot = $_COOKIE['selectedBot'];
}
else { $selectedBot = 'stable'; }
if (!in_array($selectedBot, ['stable', 'beta'])) { $selectedBot = 'stable'; }

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include '/var/www/config/ssh.php';
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
$isTechnical = isset($user['is_technical']) ? (bool)$user['is_technical'] : false;

function checkSSHFileStatus($username) {
  global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
  // Check if SSH extension is available
  if (!extension_loaded('ssh2')) {
    error_log("SSH2 extension not loaded - cannot check SSH file status");
    return null;
  }
  // Check if SSH config variables are set
  if (empty($bots_ssh_host) || empty($bots_ssh_username) || empty($bots_ssh_password)) {
    error_log("SSH configuration incomplete - cannot check SSH file status");
    return null;
  }
  try {
    $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
    if (!$connection) {
      return null;
    }
    $filePath = "/home/botofthespecter/logs/online/" . escapeshellarg($username) . ".txt";
    $command = "cat " . $filePath . " 2>/dev/null";
    $output = SSHConnectionManager::executeCommand($connection, $command);
    if ($output !== false) {
      $status = trim($output);
      // Return the status if it's either 'True' or 'False'
      if ($status === 'True' || $status === 'False') {
        return $status;
      }
    }
    return null;
  } catch (Exception $e) {
    error_log("SSH status check failed for user {$username}: " . $e->getMessage());
    return null;
  }
}

function checkTwitchStreamStatus($twitchUserId, $authToken, $clientID) {
  // Check if we have the required parameters
  if (empty($twitchUserId) || empty($authToken) || empty($clientID)) {
    error_log("Twitch API check failed: Missing required parameters");
    return null;
  }
  try {
    $url = "https://api.twitch.tv/helix/streams?user_id=" . urlencode($twitchUserId);
    $headers = [
      'Authorization: Bearer ' . $authToken,
      'Client-ID: ' . $clientID
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3); // 3 second connection timeout
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $httpCode !== 200) {
      error_log("Twitch API request failed. HTTP Code: $httpCode");
      return null;
    }
    $data = json_decode($response, true);
    if (!isset($data['data'])) {
      error_log("Twitch API response missing data field");
      return null;
    }
    // If the data array has content, the stream is live
    // If the data array is empty, the stream is offline (but we won't use this for offline determination)
    if (!empty($data['data'])) {
      return 'True'; // Stream is definitively online according to Twitch
    }
    return null; // Don't return 'False' even if empty, as per requirement
  } catch (Exception $e) {
    error_log("Twitch API check exception: " . $e->getMessage());
    return null;
  }
}

function checkBotIsMod($broadcasterId, $authToken, $clientID) {
  try {
    $url = "https://api.twitch.tv/helix/moderation/moderators?broadcaster_id=" . urlencode($broadcasterId);
    $headers = ['Authorization: Bearer ' . $authToken,'Client-ID: ' . $clientID];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $httpCode !== 200) {
      error_log("Twitch API mod check failed. HTTP Code: $httpCode");
      return false;
    }
    $data = json_decode($response, true);
    if (!isset($data['data'])) {
      error_log("Twitch API mod check response missing data field");
      return false;
    }
    $botUserId = '971436498'; // The bot's user ID
    foreach ($data['data'] as $mod) {
      if ($mod['user_id'] === $botUserId) {
        return true;
      }
    }
    return false;
  } catch (Exception $e) {
    error_log("Twitch API mod check exception: " . $e->getMessage());
    return false;
  }
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

// Check if bot is mod
if ($username !== 'botofthespecter') {
  $BotIsMod = checkBotIsMod($twitchUserId, $authToken, $clientID);
} else {
  $BotIsMod = true; // Bot is the channel owner, no mod check needed
}
if (!$BotIsMod) {
  $BotModMessage = "The bot is not a moderator on your channel. Please make the bot a moderator to start it.";
}

// Display subscription warning for Beta if no access
$subscriptionWarning = '';
if ($selectedBot === 'beta' && !$betaAccess) {
  $subscriptionWarning = '<div class="notification is-warning has-text-black has-text-weight-bold">'
    . t('bot_beta_subscription_warning', ['premium_url' => 'premium.php'])
    . '</div>';
}

// Check running status for all bots to prevent conflicts
$stableRunning = false; // Will be determined by JavaScript
$betaRunning = false;   // Will be determined by JavaScript
$runningBotCount = 0;
$multiBotWarning = '';
if ($stableRunning || $betaRunning) {
  $multiBotWarning = '<div class="notification is-danger has-text-black has-text-weight-bold">'
    . t('bot_multi_bot_warning')
    . '</div>';
}

// Check if the bot "knows" the user is online
$tagClass = 'tag is-large is-fullwidth is-medium mb-2 has-text-weight-bold has-text-centered';
$userOnlineStatus = null;
$dbStatus = null;
$sshStatus = null;
$twitchStatus = null;
$finalStatus = null;

if (isset($username) && $username !== '') {
  // Check 1: Database status
  $stmt = $db->prepare("SELECT status FROM stream_status");
  $stmt->execute();
  $stmt->bind_result($dbStatus);
  if ($stmt->fetch()) {
    // Database status retrieved
  } else {
    $dbStatus = null;
  }
  $stmt->close();
  // Check 2: SSH file status
  $sshStatus = checkSSHFileStatus($username);
  // Check 3: Twitch API status (authoritative for online, never for offline)
  $twitchStatus = checkTwitchStreamStatus($twitchUserId, $authToken, $clientID);
  // Determine final status - Twitch API has highest priority for "online"
  if ($twitchStatus === 'True') {
    // If Twitch says online, then definitely online
    $finalStatus = 'True';
  } elseif ($dbStatus === 'True' || $sshStatus === 'True') {
    // If either other check says online (and Twitch doesn't contradict), then online
    $finalStatus = 'True';
  } elseif ($dbStatus === 'False' && $sshStatus === 'False') {
    $finalStatus = 'False';
  } elseif ($dbStatus === 'False' && $sshStatus === null) {
    $finalStatus = 'False';
  } elseif ($dbStatus === null && $sshStatus === 'False') {
    $finalStatus = 'False';
  } elseif ($dbStatus === null && $sshStatus === null) {
    $finalStatus = null;
  } else {
    // If one is False and the other is unknown, default to False
    $finalStatus = 'False';
  }
  // Determine internal status for force buttons
  $internalOnline = ($dbStatus === 'True' || $sshStatus === 'True');
  // Generate status display based on final status
  if ($finalStatus === 'True') {
    $userOnlineStatus = '<span class="' . $tagClass . ' bot-status-tag is-success" style="width:100%;">' . t('bot_status_online') . '</span>';
  } elseif ($finalStatus === 'False') {
    $userOnlineStatus = '<span class="' . $tagClass . ' bot-status-tag is-warning" style="width:100%;">' . t('bot_status_offline') . '</span>';
  } else {
    $userOnlineStatus = '<span class="' . $tagClass . ' bot-status-tag is-warning" style="width:100%;">' . t('bot_status_unknown') . '</span>';
  }
  if ($isTechnical) {
    $formatStatus = function($status) {
      if ($status === 'True') return 'Online';
      if ($status === 'False') return 'Offline';
      return $status ?? 'null';
    };
    $debugInfo = '<div class="has-text-grey is-size-7 mt-1">';
    $debugInfo .= 'DB: ' . $formatStatus($dbStatus) . ' | SSH: ' . $formatStatus($sshStatus) . ' | Twitch: ' . $formatStatus($twitchStatus) . ' | Final: ' . $formatStatus($finalStatus);
    $debugInfo .= '</div>';
    $userOnlineStatus .= $debugInfo;
  }
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
}

// Get last modified time of the bot script files using SSH
require_once 'bot_control_functions.php';
$stableBotScriptPath = "/home/botofthespecter/bot.py";
$betaBotScriptPath = "/home/botofthespecter/beta.py";

if ($backup_system == true) {
  $showButtons = true;
};

function dbTimeToUserTime($dbDatetime, $userTimezone = 'UTC') {
    if (!$dbDatetime) return '';
    try {
        $dt = new DateTime($dbDatetime, new DateTimeZone('Australia/Sydney'));
        $dt->setTimezone(new DateTimeZone($userTimezone));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return $dbDatetime; // fallback to raw if error
    }
}

// Start output buffering for layout template
ob_start();
?>
<?php if($multiBotWarning): ?>
  <?php echo $multiBotWarning; ?>
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
              if ($internalOnline) {
                echo '<button id="force-offline-btn" class="button is-warning is-medium is-fullwidth has-text-black has-text-weight-bold mt-2">'
                  . t('bot_force_offline') . '</button>';
              } else {
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
            <?php endif; ?>
          </p>
          <div class="version-meta">
            <p>
              <span class="has-text-grey-light"><?php echo t('bot_last_updated'); ?></span>
              <span id="last-updated" class="has-text-info">Loading...</span>
            </p>
            <p>
              <span class="has-text-grey-light"><?php echo t('bot_last_run'); ?></span>
              <span id="last-run" class="has-text-info">Loading...</span>
            </p>
            <p>
              <span class="has-text-grey-light"><?php echo t('bot_running_version'); ?></span>
              <span id="running-version" class="has-text-info"><?php echo $selectedBot === 'beta' ? ($betaVersionRunning ?: $betaNewVersion) : ($versionRunning ?: $newVersion); ?></span>
            </p>
            <div id="version-update-indicator" class="mt-2" style="display: none;">
              <span class="tag is-warning is-light is-small">Update Available</span>
            </div>
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
              <option value="stable" <?php if($selectedBot === 'stable') echo 'selected'; ?>>
                <?php echo t('bot_stable_bot'); ?>
              </option>
              <option value="beta" <?php if($selectedBot === 'beta') echo 'selected'; ?>>
                <?php echo t('bot_beta_bot'); ?>
              </option>
            </select>
          </div>
        </div>
      </header>
      <div class="card-content">
        <?php if ($selectedBot === 'stable'): ?>
          <h3 class="title is-4 has-text-white has-text-centered mb-2">
            <?php echo t('bot_stable_controls') . " (v{$newVersion})"; ?>
          </h3>
          <p class="subtitle is-6 has-text-grey-lighter has-text-centered mb-4">
            <?php echo t('bot_stable_description'); ?>
          </p>
        <?php elseif ($selectedBot === 'beta' && $betaAccess): ?>
          <h3 class="title is-4 has-text-white has-text-centered mb-2">
            <?php echo t('bot_beta_controls') . " (v{$betaNewVersion} B)"; ?>
          </h3>
          <p class="subtitle is-6 has-text-grey-lighter has-text-centered mb-4">
            <?php echo t('bot_beta_description'); ?>
          </p>
        <?php endif; ?>
        <?php 
        // Only show bot status and controls if the bot is properly configured
        $showBotControls = false;
        if ($selectedBot === 'stable' || $selectedBot === 'beta') {
          $showBotControls = true;
        } 
        ?>
        <?php if ($showBotControls): ?>
        <div class="is-flex is-justify-content-center is-align-items-center mb-4" style="gap: 2rem;">
          <span class="icon is-large" id="botStatusIcon">
            <?php
              // Determine running status for the selected bot only
              $isRunning = false;
              if ($selectedBot === 'stable') {
                $isRunning = $stableRunning;
              } elseif ($selectedBot === 'beta') {
                $isRunning = $betaRunning;
              } 
              // Show loading state initially - JavaScript will update with real status
              $heartIcon = '<i class="fas fa-spinner fa-spin fa-2x has-text-info"></i>';
              echo $heartIcon;
            ?>
          </span>
          <span class="is-size-5" style="font-weight:600;">
            <?php echo t('bot_status_label'); ?> <span id="bot-status-text" class="has-text-info">Fetching status...</span>
            <?php if ($isTechnical): ?>
            <div id="bot-pid-display" class="has-text-grey-light is-size-7 mt-1" style="display: none;">
              PID: <span id="bot-pid-value">--</span>
            </div>
            <?php endif; ?>
          </span>
        </div>
        <?php if ($BotModMessage): ?>
        <p class="has-text-danger has-text-centered mb-2"><?php echo $BotModMessage; ?></p>
        <?php endif; ?>
        <?php if ($selectedBot === 'beta' && !$betaAccess): ?>
        <div class="notification is-warning has-text-black has-text-weight-bold has-text-centered is-centered mb-2">
          <?php echo t('bot_beta_subscription_warning', ['premium_url' => 'premium.php']); ?>
        </div>
        <?php else: ?>
        <div class="buttons is-centered mb-2">
          <button class="button is-info is-medium has-text-black has-text-weight-bold px-6 mr-3" disabled>
            <span class="icon"><i class="fas fa-spinner fa-spin"></i></span>
            <span>Checking status...</span>
          </button>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <!-- System Status Card -->
    <div class="card has-background-dark has-text-white">
      <div class="card-header">
        <!-- Left side: Uptime Monitors button -->
        <div class="header-left">
          <a href="https://uptime.botofthespecter.com/" target="_blank" class="button is-link has-text-weight-bold is-small uptime-monitors-btn">
            <span class="icon"><i class="fas fa-chart-line"></i></span>
            <span><?php echo t('bot_view_detailed_uptime'); ?></span>
          </a>
        </div>
        <!-- Center: Title -->
        <p class="card-header-title title is-5 has-text-white is-centered">
          <?php echo t('bot_system_status'); ?>
        </p>
        <!-- Right side: Network Status -->
        <?php if ($isTechnical): ?>
        <div class="header-right">
          <div class="network-status-container">
            <div class="network-status-inner" style="font-family: monospace; font-size: 0.75rem;">
              <div class="has-text-white-ter" style="font-weight: 600; margin-bottom: 2px;">
                <span class="icon is-small"><i class="fas fa-network-wired"></i></span>
                Network Status
            </div>
            <div class="has-text-grey-light">
              <span class="has-text-grey">Avg Latency:</span> <span id="network-avg-latency">--ms</span>
            </div>
            <div class="has-text-grey-light">
              <span class="has-text-grey">Services Up:</span> <span id="services-up-count">--/--</span>
            </div>
          </div>
        </div>
        <?php else: ?>
        <div class="header-right header-placeholder"></div>
        <?php endif; ?>
      </div>
      <?php if ($isTechnical): ?>
        <div class="notification is-info has-text-centered" style="width: 100%; margin: 0 auto;">
          All latency and service status results below are measured from our Australian datacenter.
        </div>
      <?php endif; ?>
      <div class="card-content pt-0">
        <h4 class="title is-5 has-text-white has-text-centered mt-4 mb-4">
          <?php echo t('bot_generic_services'); ?>
        </h4>
        <!-- Service Health Meters -->
        <div class="columns is-multiline">
          <div class="column is-4">
            <div class="box has-background-darker has-text-centered p-4">
              <div class="mb-3">
                <span class="icon is-large" id="botStatusIcon">
                  <i id="apiService" class="fas fa-heartbeat fa-2x beating has-text-success"></i>
                </span>
              </div>
              <h4 class="subtitle has-text-white mb-1"><?php echo t('bot_api_service'); ?></h4>
              <p id="api-service-status" class="is-size-7 has-text-grey-light">
                <?php echo t('bot_running_normally'); ?>
              </p>
              <?php if ($isTechnical): ?>
                <div class="mt-2 has-text-left" style="font-family: monospace; font-size: 0.7rem;">
                  <div class="has-text-grey-light">
                    <span class="has-text-grey">Latency:</span> <span id="api-service-latency">--ms</span>
                  </div>
                  <div class="has-text-grey-light">
                    <span class="has-text-grey">Last Check:</span> <span id="api-service-lastcheck">--</span>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="column is-4">
            <div class="box has-background-darker has-text-centered p-4">
              <div class="mb-3">
                <span class="icon is-large" id="botStatusIcon">
                  <i id="databaseService" class="fas fa-heartbeat fa-2x beating has-text-success"></i>
                </span>
              </div>
              <h4 class="subtitle has-text-white mb-1"><?php echo t('bot_database_service'); ?></h4>
              <p id="db-service-status" class="is-size-7 has-text-grey-light">
                <?php echo t('bot_running_normally'); ?>
              </p>
              <?php if ($isTechnical): ?>
                <div class="mt-2 has-text-left" style="font-family: monospace; font-size: 0.7rem;">
                  <div class="has-text-grey-light">
                    <span class="has-text-grey">Latency:</span> <span id="db-service-latency">--ms</span>
                  </div>
                  <div class="has-text-grey-light">
                    <span class="has-text-grey">Last Check:</span> <span id="db-service-lastcheck">--</span>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="column is-4">
            <div class="box has-background-darker has-text-centered p-4">
              <div class="mb-3">
                <span class="icon is-large" id="botStatusIcon">
                  <i id="notificationService" class="fas fa-heartbeat fa-2x beating has-text-success"></i>
                </span>
              </div>
              <h4 class="subtitle has-text-white mb-1"><?php echo t('bot_notification_service'); ?></h4>
              <p id="notif-service-status" class="is-size-7 has-text-grey-light">
                <?php echo t('bot_running_normally'); ?>
              </p>
              <?php if ($isTechnical): ?>
                <div class="mt-2 has-text-left" style="font-family: monospace; font-size: 0.7rem;">
                  <div class="has-text-grey-light">
                    <span class="has-text-grey">Latency:</span> <span id="notif-service-latency">--ms</span>
                  </div>
                  <div class="has-text-grey-light">
                    <span class="has-text-grey">Last Check:</span> <span id="notif-service-lastcheck">--</span>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="column is-4">
            <div class="box has-background-darker has-text-centered p-4">
              <div class="mb-3">
                <span class="icon is-large" id="botStatusIcon">
                  <i id="botsService" class="fas fa-heartbeat fa-2x beating has-text-success"></i>
                </span>
              </div>
              <h4 class="subtitle has-text-white mb-1">BOT Server</h4>
              <p id="bots-service-status" class="is-size-7 has-text-grey-light"><?php echo t('bot_running_normally'); ?></p>
              <?php if ($isTechnical): ?>
                <div class="mt-2 has-text-left" style="font-family: monospace; font-size: 0.7rem;">
                  <div class="has-text-grey-light">
                    <span class="has-text-grey">Latency:</span> <span id="bots-service-latency">--ms</span>
                  </div>
                  <div class="has-text-grey-light">
                    <span class="has-text-grey">Last Check:</span> <span id="bots-service-lastcheck">--</span>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="column is-4">
            <div class="box has-background-darker has-text-centered p-4">
              <div class="mb-3">
                <span class="icon is-large" id="botStatusIcon">
                  <i id="discordService" class="fab fa-discord fa-2x beating has-text-success"></i>
                </span>
              </div>
              <h4 class="subtitle has-text-white mb-1">Discord Bot Service</h4>
              <p id="discord-service-status" class="is-size-7 has-text-grey-light"><?php echo t('bot_running_normally'); ?></p>
              <?php if ($isTechnical): ?>
                <div class="mt-2 has-text-left" style="font-family: monospace; font-size: 0.7rem;">
                  <div class="has-text-grey-light">
                    <span class="has-text-grey">Latency:</span> <span id="discord-service-latency">Not Recorded</span>
                  </div>
                  <div class="has-text-grey-light">
                    <span class="has-text-grey">Last Check:</span> <span id="discord-service-lastcheck">Not Recorded</span>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <h4 class="title is-5 has-text-white has-text-centered mt-5 mb-4">
          <?php echo t('bot_streaming_service_status'); ?>
        </h4>
        <div class="columns is-multiline">
          <div class="column is-4">
            <div class="box has-background-darker has-text-centered p-4">
              <div class="mb-2">
                <span class="icon is-large" id="botStatusIcon">
                  <i id="auEast1Service" class="fas fa-heartbeat fa-2x beating has-text-success"></i>
                </span>
              </div>
              <h4 class="subtitle has-text-white mb-1">AU-EAST-1</h4>
              <p id="auEast1-service-status" class="is-size-7 has-text-grey-light"><?php echo t('bot_running_normally'); ?></p>
              <?php if ($isTechnical): ?>
                <div class="mt-2 has-text-left" style="font-family: monospace; font-size: 0.7rem;">
                  <div class="has-text-grey-light">
                    <span class="has-text-grey">Latency:</span> <span id="auEast1-service-latency">--ms</span>
                  </div>
                  <div class="has-text-grey-light">
                    <span class="has-text-grey">Last Check:</span> <span id="auEast1-service-lastcheck">--</span>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="column is-4">
            <div class="box has-background-darker has-text-centered p-4">
              <div class="mb-2">
                <span class="icon is-large" id="botStatusIcon">
                  <i id="usWest1Service" class="fas fa-heartbeat fa-2x beating has-text-success"></i>
                </span>
              </div>
              <h4 class="subtitle has-text-white mb-1">US-WEST-1</h4>
              <p id="usWest1-service-status" class="is-size-7 has-text-grey-light"><?php echo t('bot_running_normally'); ?></p>
              <?php if ($isTechnical): ?>
                <div class="mt-2 has-text-left" style="font-family: monospace; font-size: 0.7rem;">
                  <div class="has-text-grey-light">
                    <span class="has-text-grey">Latency:</span> <span id="usWest1-service-latency">--ms</span>
                  </div>
                  <div class="has-text-grey-light">
                    <span class="has-text-grey">Last Check:</span> <span id="usWest1-service-lastcheck">--</span>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="column is-4">
            <div class="box has-background-darker has-text-centered p-4">
              <div class="mb-2">
                <span class="icon is-large" id="botStatusIcon">
                  <i id="usEast1Service" class="fas fa-heartbeat fa-2x beating has-text-success"></i>
                </span>
              </div>
              <h4 class="subtitle has-text-white mb-1">US-EAST-1</h4>
              <p id="usEast1-service-status" class="is-size-7 has-text-grey-light"><?php echo t('bot_running_normally'); ?></p>
              <?php if ($isTechnical): ?>
                <div class="mt-2 has-text-left" style="font-family: monospace; font-size: 0.7rem;">
                  <div class="has-text-grey-light">
                    <span class="has-text-grey">Latency:</span> <span id="usEast1-service-latency">--ms</span>
                  </div>
                  <div class="has-text-grey-light">
                    <span class="has-text-grey">Last Check:</span> <span id="usEast1-service-lastcheck">--</span>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
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
const latestStableVersion = <?php echo json_encode($newVersion); ?>;
const latestBetaVersion = <?php echo json_encode($betaNewVersion); ?>;
const serverStableVersion = <?php echo json_encode($versionRunning); ?>;
const serverBetaVersion = <?php echo json_encode($betaVersionRunning); ?>;
// Technical UI Enhancements
const technicalCSS = `
  .technical-info-grid {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.25rem;
    align-items: center;
  }
  .technical-metric {
    transition: all 0.3s ease;
  }
  .technical-metric:hover {
    transform: scale(1.05);
  }
  .heartbeat.beating {
    animation: heartbeat 2s ease-in-out infinite;
  }
  @keyframes heartbeat {
    0% { transform: scale(1); }
    14% { transform: scale(1.1); }
    28% { transform: scale(1); }
    42% { transform: scale(1.1); }
    70% { transform: scale(1); }
  }
`;
// Inject CSS if technical mode is enabled
if (<?php echo json_encode($isTechnical); ?>) {
  const style = document.createElement('style');
  style.textContent = technicalCSS;
  document.head.appendChild(style);
}
document.addEventListener('DOMContentLoaded', function() {
  const isTechnical = <?php echo json_encode($isTechnical); ?>;
  const isBotMod = <?php echo json_encode($BotIsMod); ?>;
  const hasBetaAccess = <?php echo json_encode($betaAccess); ?>;
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
      });
    }
    if (runBotBtn) {
      runBotBtn.addEventListener('click', () => {
        const bot = getCurrentBotType();
        if (bot === 'stable') handleStableBotAction('run');
        else if (bot === 'beta') handleBetaBotAction('run');
      });
    }
  }
  attachBotButtonListeners();  // Function to handle bot actions
  function handleStableBotAction(action) {
    if (action === 'run' && !isBotMod) {
      showNotification("The bot is not a moderator on your channel. Please make the bot a moderator to start it.", 'danger');
      return;
    }
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
      fetchWithTimeout('bot_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=${encodeURIComponent(action)}&bot=stable`
      }, 8000) // Reduced from 15000 to 8000
        .then(response => response.json())
        .then(data => {
          console.log('Stable bot action response:', data);
          if (data.success) {
            // Immediately update UI optimistically
            const expectedRunning = (action === 'run');
            updateUIOptimistically(expectedRunning, 'Stable');
            // Reset button state
            btn.innerHTML = originalContent;
            btn.disabled = false;
            // Start polling to verify the actual state change
            startStatusVerification('stable', expectedRunning, 0);
            // Reset flag on success
            if (action === 'run') {
              botRunOperationInProgress = false;
              currentBotBeingStarted = null;
            }
          } else { 
            // Reset flag on failure
            if (action === 'run') {
              botRunOperationInProgress = false;
              currentBotBeingStarted = null;
            }
            showNotification(`Failed to ${action} stable bot: ${data.message}`, 'danger'); 
            // Always restore button state
            btn.innerHTML = originalContent;
            btn.disabled = false;
          }
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
    if (action === 'run' && !isBotMod) {
      showNotification("The bot is not a moderator on your channel. Please make the bot a moderator to start it.", 'danger');
      return;
    }
    if (action === 'run' && !hasBetaAccess) {
      showNotification("You do not have access to the beta bot. Please subscribe to access beta features.", 'danger');
      return;
    }
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
      fetchWithTimeout('bot_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=${encodeURIComponent(action)}&bot=beta`
      }, 8000) // Reduced from 15000 to 8000
        .then(response => response.json())
        .then(data => {
          console.log('Beta bot action response:', data);
          if (data.success) {
            // Immediately update UI optimistically
            const expectedRunning = (action === 'run');
            updateUIOptimistically(expectedRunning, 'Beta');
            // Reset button state
            btn.innerHTML = originalContent;
            btn.disabled = false;
            // Start polling to verify the actual state change
            startStatusVerification('beta', expectedRunning, 0);
            // Reset flag on success
            if (action === 'run') {
              botRunOperationInProgress = false;
              currentBotBeingStarted = null;
            }
          } else {
            // Reset flag on failure
            if (action === 'run') {
              botRunOperationInProgress = false;
              currentBotBeingStarted = null;
            }
            showNotification(`Failed to ${action} beta bot: ${data.message}`, 'danger');
            // Always restore button state
            btn.innerHTML = originalContent;
            btn.disabled = false;
          }
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
      fetchWithTimeout(`check_bot_status.php?bot=${botType}&_t=${Date.now()}`, {}, 8000)
        .then(async response => {
          const text = await response.text();
          console.log(`Bot status check attempt ${currentAttempt} for ${botType}:`, text);
          try {
            const data = JSON.parse(text);
            console.log('Parsed bot status data:', data);
            // Check if the bot is running - be more lenient with the checks
            if (typeof data.running !== 'undefined') {
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
  // New status verification function that checks if the expected state has been achieved
  function startStatusVerification(botType, expectedRunning, attempt) {
    const maxAttempts = 15; // Check for up to 15 seconds
    const checkInterval = 1000; // Check every second
    if (attempt >= maxAttempts) {
      showNotification(`${botType} bot status verification timed out. The bot may take longer to ${expectedRunning ? 'start' : 'stop'}.`, 'warning');
      return;
    }
    // Wait before checking (except for the first attempt)
    const delay = attempt === 0 ? 500 : checkInterval;
    setTimeout(() => {
      updateBotStatus(true) // Silent update to avoid showing duplicate messages
        .then(data => {
          if (data && typeof data.running !== 'undefined') {
            if (data.running === expectedRunning) {
              // State has changed as expected!
              const actionText = expectedRunning ? 'started' : 'stopped';
              const statusText = expectedRunning ? 'online' : 'offline';
              showNotification(`${botType} bot ${actionText} successfully and is now ${statusText}!`, 'success');
              return; // Stop verification
            } else {
              // State hasn't changed yet, continue checking
              startStatusVerification(botType, expectedRunning, attempt + 1);
            }
          } else {
            // Error checking status, continue trying
            startStatusVerification(botType, expectedRunning, attempt + 1);
          }
        })
        .catch(error => {
          console.error('Error during status verification:', error);
          // Continue trying on error
          startStatusVerification(botType, expectedRunning, attempt + 1);
        });
    }, delay);
  }
  // Function to show notifications
  function showNotification(message, type) {
    // Remove existing notifications with the same message and type
    document.querySelectorAll(`.notification.is-${type}`).forEach(n => {
      // Don't remove update notifications
      if (type === 'update' || n.classList.contains('bot-operation-persistent')) {
        return;
      }
      if (n.textContent.trim() === message.trim()) {
        n.parentNode.removeChild(n);
      }
    });
    // Remove previous status checking notifications when showing new ones
    if (message.includes('command sent') || message.includes('Checking') || message.includes('is now running')) {
      document.querySelectorAll('.notification.is-info').forEach(n => {
        // Don't remove update notifications or persistent notifications
        if (n.classList.contains('bot-operation-persistent') || n.querySelector('.fas.fa-exclamation-triangle')) {
          return;
        }
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
    // Add special handling for update notifications
    const isUpdateNotification = type === 'update' || message.includes('version is available');
    if (isPersistentBotOperation || isUpdateNotification) {
      notification.classList.add('bot-operation-persistent');
      if (isUpdateNotification) {
        // Update notifications are completely persistent with no delete button
        notification.innerHTML = `
          <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
          ${message}
        `;
      } else {
        // Other persistent notifications get delete button
        notification.innerHTML = `
          <button class="delete"></button>
          <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
          ${message}
        `;
        // Add delete button functionality for non-update persistent notifications
        const deleteBtn = notification.querySelector('.delete');
        deleteBtn.addEventListener('click', () => {
          notification.parentNode.removeChild(notification);
        });
      }
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
    // Auto-remove after time (except for persistent bot operation messages and update notifications)
    if (!isPersistentBotOperation && !isUpdateNotification) {
      const autoRemoveTime = type === 'info' ? 3000 : 5000;
      setTimeout(() => {
        if (notification.parentNode) {
          notification.parentNode.removeChild(notification);
        }
      }, autoRemoveTime);
    }
  }
  setInterval(function() {
    var webIcon = document.getElementById('web1Service');
    var webStatusElem = document.getElementById('web1-service-status');
    if (webIcon) webIcon.className = 'fas fa-heartbeat fa-2x has-text-success beating';
    if (webStatusElem) {
      webStatusElem.textContent = '';
      webStatusElem.className = 'is-size-7 has-text-grey-light';
    }
  }, 10000); // Increased from 2000ms to 10000ms (10 seconds)
  function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(';').shift();
    return null;
  }
  // Function to update UI optimistically (immediately) when an action succeeds
  function updateUIOptimistically(expectedRunning, botType) {
    const statusText = expectedRunning ? 'ONLINE' : 'OFFLINE';
    const statusClass = expectedRunning ? 'success' : 'danger';
    // Update the heartbeat icon
    const heartIconContainer = document.getElementById('botStatusIcon');
    if (heartIconContainer) {
      if (expectedRunning) {
        heartIconContainer.innerHTML = '<i class="fas fa-heartbeat fa-2x has-text-success beating"></i>';
      } else {
        heartIconContainer.innerHTML = '<i class="fas fa-heart-broken fa-2x has-text-danger"></i>';
      }
    }
    // Update status text
    const statusSpan = document.querySelector('.is-size-5 span[class*="has-text-"]');
    if (statusSpan) {
      statusSpan.textContent = statusText;
      statusSpan.className = `has-text-${statusClass}`;
    }
    // Update PID display if technical mode is enabled
    if (isTechnical) {
      const pidDisplay = document.getElementById('bot-pid-display');
      const pidValue = document.getElementById('bot-pid-value');
      if (pidDisplay && pidValue) {
        if (expectedRunning) {
          pidDisplay.style.display = '';
          pidValue.textContent = '--'; // Will be updated by status verification
        } else {
          pidDisplay.style.display = 'none';
        }
      }
    }
    // Update buttons
    const buttonContainer = document.querySelector('.buttons.is-centered.mb-2');
    if (buttonContainer) {
      if (expectedRunning) {
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
          <button id="run-bot-btn" class="button is-success is-medium has-text-black has-text-weight-bold px-6 mr-3" ${(!isBotMod || (selectedBot === 'beta' && !hasBetaAccess)) ? 'disabled' : ''}>
            <span class="icon"><i class="fas fa-play"></i></span>
            <span><?php echo addslashes(t('bot_run')); ?></span>
          </button>
        `;
      }
      // Re-attach event listeners
      attachBotButtonListeners();
    }
    // Show a small indicator that we're verifying the status
    if (expectedRunning) {
      showNotification(`${botType} bot started - verifying status...`, 'info');
    } else {
      showNotification(`${botType} bot stopped - verifying status...`, 'info');
    }
  }
  // Function to update bot status
  function updateBotStatus(silentUpdate = false) {
    const urlParams = new URLSearchParams(window.location.search);
    let selectedBot = urlParams.get('bot');
    if (!selectedBot) { selectedBot = getCookie('selectedBot'); }
    if (!selectedBot) { selectedBot = 'stable'; }
    return fetchWithTimeout(`check_bot_status.php?bot=${selectedBot}&_t=${Date.now()}`, {}, 5000) // Reduced from 8000 to 5000
      .then(async response => {
        const text = await response.text();
        try {
          const data = JSON.parse(text);
          console.log('updateBotStatus parsed data:', data);
          if (data.running !== undefined) {
            console.log('Bot status data received:', {
              bot: data.bot,
              running: data.running,
              version: data.version,
              lastModified: data.lastModified,
              lastRun: data.lastRun
            });
            // Check for version updates
            const latestVersion = selectedBot === 'beta' ? latestBetaVersion : latestStableVersion;
            if (data.version && data.version !== latestVersion && latestVersion !== 'N/A') {
              // Always show update notification when there's an update available
              showNotification(`A new ${selectedBot} bot version is available! Current: ${data.version}, Latest: ${latestVersion}`, 'update');
              // Show update indicator in version card
              const updateIndicator = document.getElementById('version-update-indicator');
              if (updateIndicator) {
                updateIndicator.style.display = 'block';
              }
            } else {
              // Hide update indicator if versions match
              const updateIndicator = document.getElementById('version-update-indicator');
              if (updateIndicator) {
                updateIndicator.style.display = 'none';
              }
            }
            const statusText = data.running ? 'ONLINE' : 'OFFLINE';
            const statusClass = data.running ? 'success' : 'danger';
            // Update status text
            const statusTextElement = document.getElementById('bot-status-text');
            if (statusTextElement) {
              statusTextElement.innerHTML = `<span class="has-text-${statusClass}">${statusText}</span>`;
            }
            // Update heart icon
            const heartIconContainer = document.getElementById('botStatusIcon');
            if (heartIconContainer) {
              if (data.running) {
                heartIconContainer.innerHTML = '<i class="fas fa-heartbeat fa-2x has-text-success beating"></i>';
              } else {
                heartIconContainer.innerHTML = '<i class="fas fa-heart-broken fa-2x has-text-danger"></i>';
              }
            }
            // Update buttons based on status
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
                  <button id="run-bot-btn" class="button is-success is-medium has-text-black has-text-weight-bold px-6 mr-3" ${(!isBotMod || (selectedBot === 'beta' && !hasBetaAccess)) ? 'disabled' : ''}>
                    <span class="icon"><i class="fas fa-play"></i></span>
                    <span><?php echo addslashes(t('bot_run')); ?></span>
                  </button>
                `;
              }
              // Re-attach event listeners
              attachBotButtonListeners();
            }
            // Update PID display if technical mode is enabled
            if (isTechnical) {
              const pidDisplay = document.getElementById('bot-pid-display');
              const pidValue = document.getElementById('bot-pid-value');
              if (pidDisplay && pidValue) {
                if (data.running && data.pid) {
                  pidDisplay.style.display = '';
                  pidValue.textContent = data.pid;
                } else {
                  pidDisplay.style.display = 'none';
                }
              }
            }
            // Update version info card
            const lastUpdatedElement = document.getElementById('last-updated');
            const lastRunElement = document.getElementById('last-run');
            const runningVersionElement = document.getElementById('running-version');
            if (lastUpdatedElement) {
              lastUpdatedElement.textContent = data.lastModified || 'Unknown';
              console.log('Updated last-updated to:', data.lastModified || 'Unknown');
            }
            if (lastRunElement) {
              lastRunElement.textContent = data.lastRun || 'Never';
              console.log('Updated last-run to:', data.lastRun || 'Never');
            }
            if (runningVersionElement) {
              runningVersionElement.textContent = data.version || 'Unknown';
              console.log('Updated running-version to:', data.version || 'Unknown');
            }
            // Update technical info if available
            const latencyElement = document.getElementById(`${selectedBot}-service-latency`);
            const lastCheckElement = document.getElementById(`${selectedBot}-service-lastcheck`);
            if (latencyElement && lastCheckElement) {
              latencyElement.textContent = `${data.latency || '--'}ms`;
              lastCheckElement.textContent = data.lastRun || '--';
            }
            return data; // Return the data for status verification
          } else {
            console.error('Bot status API returned invalid data:', data);
            // Set fallback values if API fails
            const lastUpdatedElement = document.getElementById('last-updated');
            const lastRunElement = document.getElementById('last-run');
            const runningVersionElement = document.getElementById('running-version');
            if (lastUpdatedElement) {
              lastUpdatedElement.textContent = 'Error loading';
            }
            if (lastRunElement) {
              lastRunElement.textContent = 'Error loading';
            }
            if (runningVersionElement) {
              const fallbackVersion = selectedBot === 'beta' ? (serverBetaVersion || latestBetaVersion) : (serverStableVersion || latestStableVersion);
              runningVersionElement.textContent = fallbackVersion;
            }
            return null;
          }
        } catch (e) {
          console.error('Error parsing bot status JSON:', e);
          // Set fallback values if JSON parsing fails
          const lastUpdatedElement = document.getElementById('last-updated');
          const lastRunElement = document.getElementById('last-run');
          const runningVersionElement = document.getElementById('running-version');
          if (lastUpdatedElement) {
            lastUpdatedElement.textContent = 'Error loading';
          }
          if (lastRunElement) {
            lastRunElement.textContent = 'Error loading';
          }
          if (runningVersionElement) {
            const fallbackVersion = selectedBot === 'beta' ? (serverBetaVersion || latestBetaVersion) : (serverStableVersion || latestStableVersion);
            runningVersionElement.textContent = fallbackVersion;
          }
          return null;
        }
      })
      .catch(error => {
        console.error('Error fetching bot status:', error);
        // Set fallback values if fetch fails
        const lastUpdatedElement = document.getElementById('last-updated');
        const lastRunElement = document.getElementById('last-run');
        const runningVersionElement = document.getElementById('running-version');
        if (lastUpdatedElement) {
          lastUpdatedElement.textContent = 'Error loading';
        }
        if (lastRunElement) {
          lastRunElement.textContent = 'Error loading';
        }
        if (runningVersionElement) {
          const fallbackVersion = selectedBot === 'beta' ? (serverBetaVersion || latestBetaVersion) : (serverStableVersion || latestStableVersion);
          runningVersionElement.textContent = fallbackVersion;
        }
        return null;
      });
  }
  // Function to update API limits from api_limits.php
  function updateApiLimits() {
    fetchWithTimeout('api_limits.php', {}, 8000)
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
          document.getElementById('weather-count').textContent = data.weather.requests_remaining || '--';
          document.getElementById('weather-progress').value = data.weather.requests_remaining || 0;
          document.getElementById('weather-progress').max = data.weather.requests_limit;
          let weatherUpdated = data.weather.last_updated;
          if (weatherUpdated) {
            try {
              const parsedDate = new Date(weatherUpdated);
              if (!isNaN(parsedDate.getTime())) {
                const timeAgoText = timeAgo(weatherUpdated);
                document.getElementById('weather-updated').textContent = timeAgoText;
              } else {
                console.warn('Invalid weather timestamp:', weatherUpdated);
                document.getElementById('weather-updated').textContent = '--';
              }
            } catch (error) {
              console.error('Error parsing weather timestamp:', error, weatherUpdated);
              document.getElementById('weather-updated').textContent = '--';
            }
          } else {
            console.warn('No weather timestamp provided');
            document.getElementById('weather-updated').textContent = '--';
          }
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
    let then;
    if (typeof isoDate === 'number' || /^\d+$/.test(isoDate)) {
      // Handle Unix timestamp (seconds or ms)
      then = new Date((isoDate.toString().length > 10 ? Number(isoDate) : Number(isoDate) * 1000));
    } else {
      // Handle MySQL datetime string by replacing space with 'T'
      let safeDate = isoDate.replace(' ', 'T');
      then = new Date(safeDate);
    }
    // Check if the date is valid
    if (isNaN(then.getTime())) {
      return '--';
    }
    // Special handling for same-day weather timestamps with timezone issues
    const nowDate = now.toDateString();
    const thenDate = then.toDateString();
    let diff;
    // Check if we have a timezone offset issue (around 10 hours difference)
    const rawDiff = Math.floor((now - then) / 1000);
    const hoursDiff = Math.abs(rawDiff) / 3600;
    if (hoursDiff >= 9 && hoursDiff <= 11 && nowDate === thenDate) {
      // Likely a timezone issue - calculate based on just the time components
      const nowHours = now.getHours();
      const nowMinutes = now.getMinutes();
      const thenHours = then.getHours();
      const thenMinutes = then.getMinutes();
      // Convert both to minutes since midnight
      const nowTotalMinutes = nowHours * 60 + nowMinutes;
      const thenTotalMinutes = thenHours * 60 + thenMinutes;
      // Calculate difference in seconds
      diff = (nowTotalMinutes - thenTotalMinutes) * 60;
      // If negative, it means the timestamp is later in the day
      if (diff < 0) {
        diff = Math.abs(diff);
      }
    } else {
      // Normal calculation
      diff = Math.floor((now - then) / 1000);
    }
    // Check if diff is NaN
    if (isNaN(diff)) {
      return '--';
    }
    // If negative but small (< 5 minutes), treat as "just now"
    // If larger negative (timezone issues), try to handle gracefully
    if (diff < 0) {
      if (diff > -300) { // Less than 5 minutes in the future
        diff = 0;
      } else if (diff > -43200) { // Less than 12 hours in the future (timezone issue)
        diff = Math.abs(diff); // Convert to positive for display
      } else {
        return '--';
      }
    }
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
    const webIcon = document.getElementById('web1Service');
    const webStatusElem = document.getElementById('web1-service-status');
    const webLatencyElem = document.getElementById('web1-service-latency');
    const webLastCheckElem = document.getElementById('web1-service-lastcheck');
    if (webIcon) webIcon.className = 'fas fa-heartbeat fa-2x has-text-success beating';
    if (webStatusElem) {
      webStatusElem.textContent = '';
      webStatusElem.className = 'is-size-7 has-text-grey-light';
    }
    // All other services (excluding web1Service)
    const services = [
      { id: 'apiService',           api: 'api',                   statusId: 'api-service-status',     latencyId: 'api-service-latency',     lastCheckId: 'api-service-lastcheck' },
      { id: 'databaseService',      api: 'database',              statusId: 'db-service-status',      latencyId: 'db-service-latency',      lastCheckId: 'db-service-lastcheck' },
      { id: 'notificationService',  api: 'websocket',             statusId: 'notif-service-status',   latencyId: 'notif-service-latency',   lastCheckId: 'notif-service-lastcheck' },
      { id: 'botsService',          api: 'bots',                  statusId: 'bots-service-status',    latencyId: 'bots-service-latency',    lastCheckId: 'bots-service-lastcheck' },
      { id: 'auEast1Service',       api: 'streamingService',      statusId: 'auEast1-service-status', latencyId: 'auEast1-service-latency', lastCheckId: 'auEast1-service-lastcheck' },
      { id: 'usWest1Service',       api: 'streamingServiceWest',  statusId: 'usWest1-service-status', latencyId: 'usWest1-service-latency', lastCheckId: 'usWest1-service-lastcheck' },
      { id: 'usEast1Service',       api: 'streamingServiceEast',  statusId: 'usEast1-service-status', latencyId: 'usEast1-service-latency', lastCheckId: 'usEast1-service-lastcheck' }
    ];
    // Inject translations from PHP
    const runningNormallyText = <?php echo json_encode(t('bot_running_normally')); ?>;
    const serviceDegradedText = <?php echo json_encode(t('bot_status_unknown')); ?>;
    const statusCheckFailedText = <?php echo json_encode(t('bot_refresh_channel_status_failed')); ?>;
    services.forEach(svc => {
      fetchWithTimeout('api_status.php?service=' + svc.api, {}, 8000)
        .then(r => r.json())
        .then(data => {
          const icon = document.getElementById(svc.id);
          const statusElem = document.getElementById(svc.statusId);
          if (!icon || !statusElem) return;
          // Robust icon update logic
          if (data && data.status === 'OK') {
            icon.className = 'fas fa-heartbeat fa-2x has-text-success beating';
          } else if (data.status === 'DISABLED') {
            icon.className = 'fas fa-pause-circle fa-2x has-text-grey';
          } else {
            icon.className = 'fas fa-heart-broken fa-2x has-text-danger';
          }
          // Update status text
          if (statusElem) {
            if (data.status === 'OK') {
              statusElem.textContent = runningNormallyText;
              statusElem.className = 'is-size-7 has-text-success';
            } else if (data.status === 'ERROR') {
              statusElem.textContent = data.message || serviceDegradedText;
              statusElem.className = 'is-size-7 has-text-danger';
            } else if (data.status === 'DISABLED') {
              statusElem.textContent = 'Monitoring Disabled';
              statusElem.className = 'is-size-7 has-text-grey';
            } else {
              statusElem.textContent = serviceDegradedText;
              statusElem.className = 'is-size-7 has-text-warning';
            }
          }
          // Update technical information if in technical mode
          if (isTechnical) {
            const latencyElem = document.getElementById(svc.latencyId);
            const lastCheckElem = document.getElementById(svc.lastCheckId);
            if (latencyElem) {
              if (data.status === 'DISABLED') {
                latencyElem.innerHTML = '<span class="has-text-grey">--ms</span>';
              } else if (data.latency_ms !== null && data.latency_ms !== undefined) {
                const latencyColor = data.latency_ms < 100 ? 'has-text-success' : data.latency_ms < 300 ? 'has-text-warning' : 'has-text-danger';
                latencyElem.innerHTML = `<span class="${latencyColor}">${data.latency_ms}ms</span>`;
              } else {
                latencyElem.innerHTML = '<span class="has-text-danger">--ms</span>';
              }
            }
            if (lastCheckElem) {
              const now = new Date();
              const timeStr = now.toLocaleTimeString('en-US', { 
                hour12: false, 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit' 
              });
              lastCheckElem.innerHTML = `<span class="has-text-info">${timeStr}</span>`;
            }
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
          // Update technical information on error if in technical mode
          if (isTechnical) {
            const latencyElem = document.getElementById(svc.latencyId);
            const lastCheckElem = document.getElementById(svc.lastCheckId);
            if (latencyElem) {
              latencyElem.innerHTML = '<span class="has-text-danger">ERROR</span>';
            }
            if (lastCheckElem) {
              const now = new Date();
              const timeStr = now.toLocaleTimeString('en-US', { 
                hour12: false, 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit' 
              });
              lastCheckElem.innerHTML = `<span class="has-text-danger">${timeStr}</span>`;            }
          }
        });
    });
  }
  // Function to update technical system overview
  function updateTechnicalOverview() {
    if (!isTechnical) return;
    // Calculate average latency and services status
    const services = ['api', 'database', 'websocket', 'bots', 'streamingService', 'streamingServiceWest', 'streamingServiceEast'];
    let totalLatency = 0;
    let servicesUp = 0;
    let latencyCount = 0;
    let servicesChecked = 0;
    let totalEnabled = 0;
    services.forEach(service => {
      fetchWithTimeout(`api_status.php?service=${service}`, {}, 8000)
        .then(r => r.json())
        .then(data => {
          servicesChecked++;
          if (data.status !== 'DISABLED') {
            totalEnabled++;
            if (data.status === 'OK') {
              servicesUp++;
              if (data.latency_ms) {
                totalLatency += data.latency_ms;
                latencyCount++;
              }
            }
          }
          // Update overview after all services are checked
          if (servicesChecked === services.length) {
            const avgLatency = latencyCount > 0 ? Math.round(totalLatency / latencyCount) : 0;
            // Update network status
            const avgLatencyElem = document.getElementById('network-avg-latency');
            if (avgLatencyElem) {
              const latencyColor = avgLatency < 100 ? 'has-text-success' : avgLatency < 300 ? 'has-text-warning' : 'has-text-danger';
              avgLatencyElem.innerHTML = `<span class="${latencyColor}">${avgLatency}ms</span>`;
            }            
            const servicesUpElem = document.getElementById('services-up-count');
            if (servicesUpElem) {
              const servicesColor = servicesUp === totalEnabled ? 'has-text-success' : servicesUp >= Math.floor(totalEnabled * 0.8) ? 'has-text-warning' : 'has-text-danger';
              servicesUpElem.innerHTML = `<span class="${servicesColor}">${servicesUp}/${totalEnabled}</span>`;
            }
            const lastUpdateElem = document.getElementById('system-last-update');
            if (lastUpdateElem) {
              const now = new Date();
              const timeStr = now.toLocaleTimeString('en-US', { 
                hour12: false, 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit' 
              });
              lastUpdateElem.innerHTML = `<span class="has-text-info">${timeStr}</span>`;
            }
          }
        })
        .catch(() => {
          servicesChecked++;
          totalEnabled++; // Assume enabled if fetch fails
          // Service check failed, don't count as up
          if (servicesChecked === services.length) {
            // Still update the display even if some services failed
            const avgLatency = latencyCount > 0 ? Math.round(totalLatency / latencyCount) : 0;
            const avgLatencyElem = document.getElementById('network-avg-latency');
            if (avgLatencyElem && avgLatency > 0) {
              const latencyColor = avgLatency < 100 ? 'has-text-success' : avgLatency < 300 ? 'has-text-warning' : 'has-text-danger';
              avgLatencyElem.innerHTML = `<span class="${latencyColor}">${avgLatency}ms</span>`;            }
              const servicesUpElem = document.getElementById('services-up-count');
            if (servicesUpElem) {
              const servicesColor = servicesUp === totalEnabled ? 'has-text-success' : servicesUp >= Math.floor(totalEnabled * 0.8) ? 'has-text-warning' : 'has-text-danger';
              servicesUpElem.innerHTML = `<span class="${servicesColor}">${servicesUp}/${totalEnabled}</span>`;
            }
          }
        });
    });
    // Update server metrics
    updateServerMetrics();
  }
  // Function to update server metrics using real data
  function updateServerMetrics() {
    if (!isTechnical) return;
    fetchWithTimeout('server_metrics.php', {}, 8000)
      .then(r => r.json())
      .then(data => {
        if (data.error) {
          // Fallback to simulated data if real metrics not available
          updateSimulatedServerMetrics();
          return;
        }
        const cpuElem = document.getElementById('server-cpu-load');
        if (cpuElem && data.cpu_load !== null) {
          const cpuColor = data.cpu_load < 60 ? 'has-text-success' : data.cpu_load < 80 ? 'has-text-warning' : 'has-text-danger';
          cpuElem.innerHTML = `<span class="${cpuColor}">${data.cpu_load}%</span>`; 
        } else if (cpuElem) {
          cpuElem.innerHTML = '<span class="has-text-grey">N/A</span>';
        }
        const memoryElem = document.getElementById('server-memory-usage');
        if (memoryElem && data.memory_usage !== null) {
          const memoryColor = data.memory_usage < 70 ? 'has-text-success' : data.memory_usage < 85 ? 'has-text-warning' : 'has-text-danger';
          memoryElem.innerHTML = `<span class="${memoryColor}">${data.memory_usage}%</span>`;
        } else if (memoryElem) {
          memoryElem.innerHTML = '<span class="has-text-grey">N/A</span>';
        }
        const diskElem = document.getElementById('server-disk-usage');
        if (diskElem && data.disk_usage !== null) {
          const diskColor = data.disk_usage < 70 ? 'has-text-success' : data.disk_usage < 85 ? 'has-text-warning' : 'has-text-danger';
          diskElem.innerHTML = `<span class="${diskColor}">${data.disk_usage}%</span>`;
        } else if (diskElem) {
          diskElem.innerHTML = '<span class="has-text-grey">N/A</span>';
        }
      })
      .catch(error => {
        console.error('Error fetching server metrics:', error);
        // Fallback to simulated data
        updateSimulatedServerMetrics();
      });
  }
  // Fallback function for simulated server metrics
  function updateSimulatedServerMetrics() {
    if (!isTechnical) return;
    // Simulate some realistic server metrics
    const cpuLoad = Math.floor(Math.random() * 30) + 15; // 15-45%
    const memoryUsage = Math.floor(Math.random() * 40) + 35; // 35-75%
    const diskUsage = Math.floor(Math.random() * 20) + 25; // 25-45%
    const cpuElem = document.getElementById('server-cpu-load');
    if (cpuElem) {
      const cpuColor = cpuLoad < 60 ? 'has-text-success' : cpuLoad < 80 ? 'has-text-warning' : 'has-text-danger';
      cpuElem.innerHTML = `<span class="${cpuColor}">${cpuLoad}%</span>`;
    }
    const memoryElem = document.getElementById('server-memory-usage');
    if (memoryElem) {
      const memoryColor = memoryUsage < 70 ? 'has-text-success' : memoryUsage < 85 ? 'has-text-warning' : 'has-text-danger';
      memoryElem.innerHTML = `<span class="${memoryColor}">${memoryUsage}%</span>`;
    }
    const diskElem = document.getElementById('server-disk-usage');
    if (diskElem) {
      const diskColor = diskUsage < 70 ? 'has-text-success' : diskUsage < 85 ? 'has-text-warning' : 'has-text-danger';
      diskElem.innerHTML = `<span class="${diskColor}">${diskUsage}%</span>`;
    }
  }
  // Set up polling for status updates - REDUCED FREQUENCY TO PREVENT OVERLOAD
  setInterval(updateServiceStatus, 30000); // Increased from 10s to 30s
  setInterval(updateApiLimits, 60000);     // Increased from 30s to 60s
  setInterval(() => updateBotStatus(true), 120000); // Increased from 60s to 120s
  if (isTechnical) {
    setInterval(updateTechnicalOverview, 60000); // Increased from 15s to 60s
  }
  updateServiceStatus();
  updateApiLimits();
  updateBotStatus(false);
  if (isTechnical) {
    updateTechnicalOverview();
  }
  attachBotButtonListeners();
  // Channel Status Force Buttons
  const forceOnlineBtn = document.getElementById('force-online-btn');
  const forceOfflineBtn = document.getElementById('force-offline-btn');
  const apiKey = <?php echo json_encode($user['api_key'] ?? ''); ?>;
  function fetchAndUpdateChannelStatus() {
    // Only run if no bot action is in progress
    if (botActionInProgress) return;
    // AJAX call to get the latest status from the backend
    fetchWithTimeout('check_channel_status.php', {}, 8000)
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
        fetchWithTimeout('https://api.botofthespecter.com/websocket/stream_online?api_key=' + encodeURIComponent(apiKey), {}, 8000)
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
        fetchWithTimeout('https://api.botofthespecter.com/websocket/stream_offline?api_key=' + encodeURIComponent(apiKey), {}, 8000)
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
  // Fetch wrapper with timeout to prevent message port errors
  function fetchWithTimeout(url, options = {}, timeout = 10000) {
    return new Promise((resolve, reject) => {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => {
        controller.abort();
        reject(new Error('Request timeout'));
      }, timeout);
      fetch(url, {
        ...options,
        signal: controller.signal
      })
      .then(response => {
        clearTimeout(timeoutId);
        resolve(response);
      })
      .catch(error => {
        clearTimeout(timeoutId);
        if (error.name === 'AbortError') {
          reject(new Error('Request timeout'));
        } else {
          reject(error);
        }
      });
    });
  }
});
// updateApiLimits();
</script>
<!-- 
For Stable Bot:
python /home/botofthespecter/bot.py -channel <?php echo htmlspecialchars($username); ?> -channelid <?php echo htmlspecialchars($twitchUserId); ?> -token <?php echo htmlspecialchars($authToken); ?> -refresh <?php echo htmlspecialchars($refreshToken); ?> -apitoken <?php echo htmlspecialchars($api_key); ?>

For Beta Bot:
python /home/botofthespecter/beta.py -channel <?php echo htmlspecialchars($username); ?> -channelid <?php echo htmlspecialchars($twitchUserId); ?> -token <?php echo htmlspecialchars($authToken); ?> -refresh <?php echo htmlspecialchars($refreshToken); ?> -apitoken <?php echo htmlspecialchars($api_key); ?>

-->
<?php
echo $consoleLog;
// Get the buffered content
$scripts = ob_get_clean();
// Include the layout template
include 'layout.php';
?>