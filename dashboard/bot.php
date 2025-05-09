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
$statusOutput = '';
$betaStatusOutput = '';
$alphaStatusOutput = '';
$discordStatusOutput = '';
$pid = '';
$versionRunning = '';
$betaVersionRunning = '';
$alphaVersionRunning = '';
$discordVersionRunning = '';
$BotIsMod = false; // Default to false until we know for sure
$BotModMessage = "";
$setupMessage = "";
$showButtons = false;
$lastModifiedOutput = '';
$lastRestartOutput = '';
$stableLastModifiedOutput = '';
$stableLastRestartOutput = '';
$alphaLastModifiedOutput = '';
$alphaLastRestartOutput = '';
$discordLastModifiedOutput = '';
$discordLastRestartOutput = '';

// Determine which bot to display based on selection or cookie
$selectedBot = $_GET['bot'] ?? null;

// If bot is specified in URL, update the cookie only for stable, beta, or alpha
if (isset($_GET['bot'])) {
  if (in_array($_GET['bot'], ['stable', 'beta', 'alpha'])) {
    setcookie('selectedBot', $_GET['bot'], time() + (86400 * 30), "/"); // Cookie for 30 days
  }
} 
// If no bot specified in URL, try to get from cookie
else if (!isset($_GET['bot']) && isset($_COOKIE['selectedBot']) && in_array($_COOKIE['selectedBot'], ['stable', 'beta', 'alpha'])) {
  $selectedBot = $_COOKIE['selectedBot'];
} 
// Default to stable if no selection or cookie
else {
  $selectedBot = 'stable';
}

// Validate selected bot
if (!in_array($selectedBot, ['stable', 'beta', 'alpha', 'discord'])) {
  $selectedBot = 'stable';
}

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'user_db.php';
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

// Display subscription warning for Beta or Alpha if no access
$subscriptionWarning = '';
if (($selectedBot === 'beta' || $selectedBot === 'alpha') && !$user['beta_access'] && $_SESSION['tier'] !== "1000" && $_SESSION['tier'] !== "2000" && $_SESSION['tier'] !== "3000" && $_SESSION['tier'] !== "4000") {
  $subscriptionWarning = '<div class="notification is-warning has-text-black has-text-weight-bold">You need an active subscription to access this version of the bot. Please visit the <a href="premium.php">Premium page</a> for more information.</div>';
}

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

// Last Changed Time for Stable
$stableFile = '/var/www/bot/bot.py';
if (file_exists($stableFile)) {
  $stableFileModifiedTime = filemtime($stableFile);
  $stableTimeAgo = time() - $stableFileModifiedTime;
  if ($stableTimeAgo < 60) $stableLastModifiedOutput = $stableTimeAgo . ' seconds ago';
  elseif ($stableTimeAgo < 3600) $stableLastModifiedOutput = floor($stableTimeAgo / 60) . ' minutes ago';
  elseif ($stableTimeAgo < 86400) $stableLastModifiedOutput = floor($stableTimeAgo / 3600) . ' hours ago';
  else $stableLastModifiedOutput = floor($stableTimeAgo / 86400) . ' days ago';
} else {
  $stableLastModifiedOutput = 'Never';
}

// Last Restarted Time for Stable
$stableRestartLog = '/var/www/logs/version/' . $username . '_version_control.txt';
if (file_exists($stableRestartLog)) {
  $stableRestartFileTime = filemtime($stableRestartLog);
  $stableRestartTimeAgo = time() - $stableRestartFileTime;
  if ($stableRestartTimeAgo < 60) $stableLastRestartOutput = $stableRestartTimeAgo . ' seconds ago';
  elseif ($stableRestartTimeAgo < 3600) $stableLastRestartOutput = floor($stableRestartTimeAgo / 60) . ' minutes ago';
  elseif ($stableRestartTimeAgo < 86400) $stableLastRestartOutput = floor($stableRestartTimeAgo / 3600) . ' hours ago';
  else $stableLastRestartOutput = floor($stableRestartTimeAgo / 86400) . ' days ago';
} else {
  $stableLastRestartOutput = 'Never';
}

// Last Changed Time for Beta
$betaFile = '/var/www/bot/beta.py';
if (file_exists($betaFile)) {
  $betaFileModifiedTime = filemtime($betaFile);
  $timeAgo = time() - $betaFileModifiedTime;
  if ($timeAgo < 60) $lastModifiedOutput = $timeAgo . ' seconds ago';
  elseif ($timeAgo < 3600) $lastModifiedOutput = floor($timeAgo / 60) . ' minutes ago';
  elseif ($timeAgo < 86400) $lastModifiedOutput = floor($timeAgo / 3600) . ' hours ago';
  else $lastModifiedOutput = floor($timeAgo / 86400) . ' days ago';
} else {
  $lastModifiedOutput = 'Never';
}

// Last Restarted Time for Beta
$restartLog = '/var/www/logs/version/' . $username . '_beta_version_control.txt';
if (file_exists($restartLog)) {
  $restartFileTime = filemtime($restartLog);
  $restartTimeAgo = time() - $restartFileTime;
  if ($restartTimeAgo < 60) $lastRestartOutput = $restartTimeAgo . ' seconds ago';
  elseif ($restartTimeAgo < 3600) $lastRestartOutput = floor($restartTimeAgo / 60) . ' minutes ago';
  elseif ($restartTimeAgo < 86400) $lastRestartOutput = floor($restartTimeAgo / 3600) . ' hours ago';
  else $lastRestartOutput = floor($restartTimeAgo / 86400) . ' days ago';
} else {
  $lastRestartOutput = 'Never';
}

// Last Changed Time for Alpha
$alphaFile = '/var/www/bot/alpha.py';
if (file_exists($alphaFile)) {
  $alphaFileModifiedTime = filemtime($alphaFile);
  $alphaTimeAgo = time() - $alphaFileModifiedTime;
  if ($alphaTimeAgo < 60) $alphaLastModifiedOutput = $alphaTimeAgo . ' seconds ago';
  elseif ($alphaTimeAgo < 3600) $alphaLastModifiedOutput = floor($alphaTimeAgo / 60) . ' minutes ago';
  elseif ($alphaTimeAgo < 86400) $alphaLastModifiedOutput = floor($alphaTimeAgo / 3600) . ' hours ago';
  else $alphaLastModifiedOutput = floor($alphaTimeAgo / 86400) . ' days ago';
} else {
  $alphaLastModifiedOutput = 'Never';
}

// Last Restarted Time for Alpha
$alphaRestartLog = '/var/www/logs/version/' . $username . '_alpha_version_control.txt';
if (file_exists($alphaRestartLog)) {
  $alphaRestartFileTime = filemtime($alphaRestartLog);
  $alphaRestartTimeAgo = time() - $alphaRestartFileTime;
  if ($alphaRestartTimeAgo < 60) $alphaLastRestartOutput = $alphaRestartTimeAgo . ' seconds ago';
  elseif ($alphaRestartTimeAgo < 3600) $alphaLastRestartOutput = floor($alphaRestartTimeAgo / 60) . ' minutes ago';
  elseif ($alphaRestartTimeAgo < 86400) $alphaLastRestartOutput = floor($alphaRestartTimeAgo / 3600) . ' hours ago';
  else $alphaLastRestartOutput = floor($alphaRestartTimeAgo / 86400) . ' days ago';
} else {
  $alphaLastRestartOutput = 'Never';
}

// Last Changed Time for Discord
$discordFile = '/var/www/bot/discordbot.py';
if (file_exists($discordFile)) {
  $discordFileModifiedTime = filemtime($discordFile);
  $discordTimeAgo = time() - $discordFileModifiedTime;
  if ($discordTimeAgo < 60) $discordLastModifiedOutput = $discordTimeAgo . ' seconds ago';
  elseif ($discordTimeAgo < 3600) $discordLastModifiedOutput = floor($discordTimeAgo / 60) . ' minutes ago';
  elseif ($discordTimeAgo < 86400) $discordLastModifiedOutput = floor($discordTimeAgo / 3600) . ' hours ago';
  else $discordLastModifiedOutput = floor($discordTimeAgo / 86400) . ' days ago';
} else {
  $discordLastModifiedOutput = 'Never';
}

// Last Restarted Time for Discord
$discordRestartLog = '/var/www/logs/version/' . $username . '_discord_version_control.txt';
if (file_exists($discordRestartLog)) {
  $discordRestartFileTime = filemtime($discordRestartLog);
  $discordRestartTimeAgo = time() - $discordRestartFileTime;
  if ($discordRestartTimeAgo < 60) $discordLastRestartOutput = $discordRestartTimeAgo . ' seconds ago';
  elseif ($discordRestartTimeAgo < 3600) $discordLastRestartOutput = floor($discordRestartTimeAgo / 60) . ' minutes ago';
  elseif ($discordRestartTimeAgo < 86400) $discordLastRestartOutput = floor($discordRestartTimeAgo / 3600) . ' hours ago';
  else $discordLastRestartOutput = floor($discordRestartTimeAgo / 86400) . ' days ago';
} else {
  $discordLastRestartOutput = 'Never';
}

// Check running status for all three bots to prevent conflicts
$stableRunning = checkBotsRunning($statusScriptPath, $username, $logPath);
$betaRunning = checkBotsRunning($BetaStatusScriptPath, $username, $BetaLogPath);
$alphaRunning = checkBotsRunning($alphaStatusScriptPath, $username, $alphaLogPath);

$multiBotWarning = '';
if (
  ($selectedBot === 'stable' && ($betaRunning || $alphaRunning)) ||
  ($selectedBot === 'beta' && ($stableRunning || $alphaRunning)) ||
  ($selectedBot === 'alpha' && ($stableRunning || $betaRunning))
) {
  $multiBotWarning = '<div class="notification is-danger has-text-black has-text-weight-bold">
    <span class="has-text-weight-bold">Notice:</span> Running multiple versions of the bot (Stable, Beta, or Alpha) at the same time can cause data conflicts and unexpected behavior. Please ensure only one version is running at a time for best results.</span>
  </div>';
}

// Check if the bot "knows" the user is online
$onlineStatusFile = "/var/www/logs/online/{$username}.txt";
$userOnlineStatus = null;
$tagClass = 'tag is-large is-fullwidth mb-2 has-text-weight-bold has-text-centered';
if (file_exists($onlineStatusFile)) {
    $status = trim(file_get_contents($onlineStatusFile));
    if ($status === 'True') {
        $userOnlineStatus = '<span class="' . $tagClass . ' is-success is-light" style="width:100%;">STATUS: ONLINE</span>';
    } elseif ($status === 'False') {
        $userOnlineStatus = '<span class="' . $tagClass . ' is-warning is-light" style="width:100%;">STATUS: OFFLINE</span>';
    } else {
        $userOnlineStatus = '<span class="' . $tagClass . ' is-warning is-light" style="width:100%;">STATUS: UNKNOWN</span>';
    }
} else {
    $userOnlineStatus = '<span class="' . $tagClass . ' is-warning is-light" style="width:100%;">STATUS: N/A</span>';
}

// Check only the selected bot's status
if ($selectedBot === 'stable') {
  $statusOutput = getBotsStatus($statusScriptPath, $username, $logPath);
  $botSystemStatus = checkBotsRunning($statusScriptPath, $username, $logPath);
  if ($botSystemStatus) {
    $versionRunning = getRunningVersion($versionFilePath, $newVersion);
  }
} elseif ($selectedBot === 'beta') {
  $betaStatusOutput = getBotsStatus($BetaStatusScriptPath, $username, $BetaLogPath);
  $betaBotSystemStatus = checkBotsRunning($BetaStatusScriptPath, $username, $BetaLogPath);
  if ($betaBotSystemStatus) {
    $betaVersionRunning = getRunningVersion($betaVersionFilePath, $betaNewVersion, 'beta');
  }
} elseif ($selectedBot === 'alpha') {
  $alphaStatusOutput = getBotsStatus($alphaStatusScriptPath, $username, $alphaLogPath);
  $alphaBotSystemStatus = checkBotsRunning($alphaStatusScriptPath, $username, $alphaLogPath);
  if ($alphaBotSystemStatus) {
    $alphaVersionRunning = getRunningVersion($alphaVersionFilePath, $alphaNewVersion, 'alpha');
  }
} elseif ($selectedBot === 'discord') {
  $discordStatusOutput = getBotsStatus($discordStatusScriptPath, $username, $discordLogPath);
  $discordBotSystemStatus = checkBotsRunning($discordStatusScriptPath, $username, $discordLogPath);
  if ($discordBotSystemStatus) {
    $discordVersionRunning = getRunningVersion($discordVersionFilePath, $discordNewVersion);
  }
}

if ($backup_system == true) {
  $showButtons = true;
};
include "mod_access.php";
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
  <?php if ($betaAccess && $showButtons && $selectedBot === 'beta'): ?><div class="notification is-danger has-text-black has-text-weight-bold">Before starting the Beta version, ensure the Stable version is stopped to avoid data conflicts.</div><?php endif; ?>
  <?php if ($betaAccess && $showButtons && $selectedBot === 'alpha'): ?><div class="notification is-danger has-text-black has-text-weight-bold">Before using the Alpha version of the bot, please be aware that it is highly experimental and may contain features that are incomplete or unstable.</div><?php endif; ?>
  <div class="columns is-desktop">
    <!-- Left sidebar -->
    <div class="column is-3">
      <div class="box">
        <h3 class="title is-4">Bot Management</h3>
        <div class="field">
          <label class="label">Select Bot</label>
          <div class="control">
            <div class="select is-fullwidth">
              <select id="botSelector" onchange="changeBotSelection(this.value)">
                <option value="stable" <?php echo $selectedBot === 'stable' ? 'selected' : ''; ?>>Stable Bot</option>
                <option value="beta" <?php echo $selectedBot === 'beta' ? 'selected' : ''; ?>>Beta Bot</option>
                <option value="alpha" <?php echo $selectedBot === 'alpha' ? 'selected' : ''; ?>>Alpha Bot</option>
                <?php if ($guild_id && $live_channel_id): ?>
                <option value="discord" <?php echo $selectedBot === 'discord' ? 'selected' : ''; ?>>Discord Bot</option>
                <?php endif; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="buttons is-flex is-flex-direction-column mt-3">
          <button class="button is-primary has-text-black has-text-weight-bold is-fullwidth mb-2 is-rounded" onclick='window.location="manage_custom_commands.php";'>Manage Custom Commands</button>
          <button class="button is-info has-text-black has-text-weight-bold is-fullwidth mb-2 is-rounded" onclick='window.location="timed_messages.php";'>Manage Chat Timers</button>
          <!-- <button class="button is-info has-text-black has-text-weight-bold is-fullwidth mb-2 is-rounded" onclick='window.location=".php";'></button> -->
          <label class="label">Force Your Status</label>
          <?php echo $userOnlineStatus; ?>
          <button class="button is-success has-text-black has-text-weight-bold is-fullwidth mb-2 is-rounded" onclick="sendStreamEvent('STREAM_ONLINE')" title="Clicking this button will force the entire system to show you as online.">Force Online Status</button>
          <button class="button is-danger has-text-black has-text-weight-bold is-fullwidth mb-2 is-rounded" onclick="sendStreamEvent('STREAM_OFFLINE')" title="Clicking this button will force the entire system to show you as offline.">Force Offline Status</button>
        </div>
      </div>
      <!-- Bot version info box -->
      <?php if ($selectedBot === 'stable'): ?>
      <div class="box">
        <h3 class="title is-5">Stable Version Info</h3>
        <p><span class="has-text-weight-bold">Last Updated:</span> <span id="stable-last-modified-time"><?php echo $stableLastModifiedOutput; ?></span></p>
        <p><span class="has-text-weight-bold">Last Ran:</span> <span id="stable-last-restart-time"><?php echo $stableLastRestartOutput; ?></span></p>
        <p class="is-size-7 mt-2">Ensure the 'Last Run' time is either equal to or earlier than the 'Last Updated' time to apply the latest improvements.</p>
      </div>
      <?php elseif ($selectedBot === 'beta'): ?>
      <div class="box">
        <h3 class="title is-5">Beta Version Info</h3>
        <p><span class="has-text-weight-bold">Last Updated:</span> <span id="last-modified-time"><?php echo $lastModifiedOutput; ?></span></p>
        <p><span class="has-text-weight-bold">Last Ran:</span> <span id="last-restart-time"><?php echo $lastRestartOutput; ?></span></p>
        <p class="is-size-7 mt-2">Ensure the 'Last Run' time is either equal to or earlier than the 'Last Updated' time to apply the latest improvements.</p>
      </div>
      <?php elseif ($selectedBot === 'alpha'): ?>
      <div class="box">
        <h3 class="title is-5">Alpha Version Info</h3>
        <p><span class="has-text-weight-bold">Last Updated:</span> <span id="alpha-last-modified-time"><?php echo $alphaLastModifiedOutput; ?></span></p>
        <p><span class="has-text-weight-bold">Last Ran:</span> <span id="alpha-last-restart-time"><?php echo $alphaLastRestartOutput; ?></span></p>
        <p class="is-size-7 mt-2">Ensure the 'Last Run' time is either equal to or earlier than the 'Last Updated' time to apply the latest improvements.</p>
      </div>
      <?php elseif ($selectedBot === 'discord'): ?>
      <div class="box">
        <h3 class="title is-5">Discord Version Info</h3>
        <p><span class="has-text-weight-bold">Last Updated:</span> <span id="discord-last-modified-time"><?php echo $discordLastModifiedOutput; ?></span></p>
        <p><span class="has-text-weight-bold">Last Ran:</span> <span id="discord-last-restart-time"><?php echo $discordLastRestartOutput; ?></span></p>
        <p class="is-size-7 mt-2">Ensure the 'Last Run' time is either equal to or earlier than the 'Last Updated' time to apply the latest improvements.</p>
      </div>
      <?php endif; ?>
      <!-- API Limits Section -->
      <div class="box" style="position: relative;">
        <i class="fas fa-question-circle" id="api-limits-modal-open" style="position: absolute; top: 10px; right: 10px; cursor: pointer;"></i>
        <h4 class="title is-5">API Limits</h4>
        <div class="status-message" style="font-size: 14px; padding: 10px; background-color: #2c3e50; color: #ecf0f1; border-radius: 8px;">
          <!-- Song Identification Section -->
          <div class="api-section" id="shazam-section" style="padding-bottom: 10px; border-bottom: 1px solid #7f8c8d; margin-bottom: 10px;">
            <p style='color: #1abc9c;'>Song ID Left:
              <span style='color: #e74c3c; font-size: 18px; font-weight: bold; text-align: center;' id="shazam-count">Loading...</span>
              <br><small style='color: #95a5a6; font-size: 12px;'>Updated: <span id="shazam-updated">...</span></small>
            </p>
          </div>
          <!-- Exchange Rate Section -->
          <div class="api-section" id="exchangerate-section" style="padding-bottom: 10px; border-bottom: 1px solid #7f8c8d; margin-bottom: 10px;">
            <p style='color: #1abc9c;'>Exchange Rate Left:
              <span style='color: #e74c3c; font-size: 18px; font-weight: bold; text-align: center;' id="exchange-count">Loading...</span>
              <br><small style='color: #95a5a6; font-size: 12px;'>Updated: <span id="exchange-updated">...</span></small>
            </p>
          </div>
          <!-- Weather Usage Section -->
          <div class="api-section" id="weather-section">
            <p style='color: #1abc9c;'>Weather Left:
              <span style='color: #e74c3c; font-size: 18px; font-weight: bold; text-align: center;' id="weather-count">Loading...</span>
              <br><small style='color: #95a5a6; font-size: 12px;'>Updated: <span id="weather-updated">...</span></small>
            </p>
          </div>
        </div>
      </div>
    </div>
    <!-- Main content area -->
    <div class="column">
      <div class="columns">
        <!-- Bot Controls Section -->
        <div class="column is-12">
          <?php echo $BotModMessage; ?>
          <?php echo $setupMessage; ?>
          <?php if ($showButtons): ?>
          <div class="box">
            <?php echo $subscriptionWarning; ?>
            <?php if (in_array($selectedBot, ['stable', 'beta', 'alpha'])) { echo $multiBotWarning; }?>
            <?php if ($selectedBot === 'stable'): ?>
            <h3 class="title is-4">Stable Bot Controls (V<?php echo $newVersion; ?>)</h3>
            <p class="has-text-centered mb-3">The stable version is well-tested and reliable for everyday use. We recommend this version for every stream.</p>
            <div id="stableStatus"><?php echo $statusOutput; ?></div>
            <div id="stableVersion"><?php echo $versionRunning; ?></div>
            <div class="buttons is-centered mt-4">
              <form action="" method="post" class="mr-2">
                <button class="button is-danger bot-button button-size is-rounded" type="submit" name="killBot">Stop Bot</button>
              </form>
              <form action="" method="post" class="mr-2">
                <button class="button is-success bot-button button-size is-rounded" type="submit" name="runBot">Run Bot</button>
              </form>
              <form action="" method="post">
                <button class="button is-warning bot-button button-size is-rounded" type="submit" name="restartBot">Restart Bot</button>
              </form>
            </div>
            <?php elseif ($selectedBot === 'beta' && $betaAccess): ?>
            <h3 class="title is-4">Beta Bot Controls (V<?php echo $betaNewVersion; ?>B)</h3>
            <p class="has-text-centered mb-3">The beta version contains new features that are still being tested. Recommended for testing new functionality.</p>
            <div id="betaStatus"><?php echo $betaStatusOutput; ?></div>
            <div id="betaVersion"><?php echo $betaVersionRunning; ?></div>
            <div class="buttons is-centered mt-4">
              <form action="" method="post" class="mr-2">
                <button class="button is-danger bot-button button-size is-rounded" type="submit" name="killBetaBot">Stop Beta Bot</button>
              </form>
              <form action="" method="post" class="mr-2">
                <button class="button is-success bot-button button-size is-rounded" type="submit" name="runBetaBot">Run Beta Bot</button>
              </form>
              <form action="" method="post">
                <button class="button is-warning bot-button button-size is-rounded" type="submit" name="restartBetaBot">Restart Beta Bot</button>
              </form>
            </div>
            <?php elseif ($selectedBot === 'alpha' && $betaAccess): ?>
            <h3 class="title is-4">Alpha Bot Controls (V<?php echo $alphaNewVersion; ?>A)</h3>
            <p class="has-text-centered mb-3">The alpha version contains experimental features. Not recommended for use during live streams.</p>
            <div id="alphaStatus"><?php echo $alphaStatusOutput; ?></div>
            <div id="alphaVersion"><?php echo $alphaVersionRunning; ?></div>
            <div class="buttons is-centered mt-4">
              <form action="" method="post" class="mr-2">
                <button class="button is-danger bot-button button-size is-rounded" type="submit" name="killAlphaBot">Stop Alpha Bot</button>
              </form>
              <form action="" method="post" class="mr-2">
                <button class="button is-success bot-button button-size is-rounded" type="submit" name="runAlphaBot">Run Alpha Bot</button>
              </form>
              <form action="" method="post">
                <button class="button is-warning bot-button button-size is-rounded" type="submit" name="restartAlphaBot">Restart Alpha Bot</button>
              </form>
            </div>
            <?php elseif ($selectedBot === 'discord' && $guild_id && $live_channel_id): ?>
            <h3 class="title is-4">Discord Bot Controls (V<?php echo htmlspecialchars($discordNewVersion); ?>)</h3>
            <p class="has-text-centered mb-3">The Discord bot integrates with your Discord server to provide stream notifications and other features.</p>
            <div id="discordStatus"><?php echo $discordStatusOutput; ?></div>
            <div id="discordVersion"><?php echo $discordVersionRunning; ?></div>
            <div class="buttons is-centered mt-4">
              <form action="" method="post" class="mr-2">
                <button class="button is-danger bot-button button-size is-rounded" type="submit" name="killDiscordBot">Stop Discord Bot</button>
              </form>
              <form action="" method="post" class="mr-2">
                <button class="button is-success bot-button button-size is-rounded" type="submit" name="runDiscordBot">Run Discord Bot</button>
              </form>
              <form action="" method="post">
                <button class="button is-warning bot-button button-size is-rounded" type="submit" name="restartDiscordBot">Restart Discord Bot</button>
              </form>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <!-- System Status Section -->
      <div class="box" style="position: relative;">
        <i class="fas fa-question-circle" id="websocket-service-modal-open" style="position: absolute; top: 10px; right: 10px; cursor: pointer;"></i>
        <h4 class="title is-4 has-text-centered">System Status</h4>
        <div class="columns is-mobile is-multiline">
          <div class="column is-4">
            <div style="display: flex; align-items: center; justify-content: center; flex-direction: column; text-align: center;">
              <span class="subtitle">
                <span id="apiServiceIcon">
                  <i id="apiService" class="fas fa-heartbeat" style="color: green;"></i>
                </span>
                <span style="margin-left: 3px">API Service</span>
              </span>
            </div>
          </div>
          <div class="column is-4">
            <div style="display: flex; align-items: center; justify-content: center; flex-direction: column; text-align: center;">
              <span class="subtitle">
                <span id="databaseServiceIcon">
                  <i id="databaseService" class="fas fa-heartbeat" style="color: green;"></i>
                </span>
                <span style="margin-left: 3px">Database Service</span>
              </span>
            </div>
          </div>
          <div class="column is-4">
            <div style="display: flex; align-items: center; justify-content: center; flex-direction: column; text-align: center;">
              <span class="subtitle">
                <span id="heartbeatIcon">
                  <i id="heartbeat" class="fas fa-heartbeat" style="color: green;"></i>
                </span>
                <span style="margin-left: 3px">Notification Service</span>
              </span>
            </div>
          </div>
        </div>
        <h4 class="title is-4 has-text-centered">Streaming Service Status</h4>
        <div class="columns is-mobile is-multiline mt-3">
          <div class="column is-4">
            <div style="display: flex; align-items: center; justify-content: center; flex-direction: column; text-align: center;">
              <span class="subtitle">
                <span id="streamingServiceIcon">
                  <i id="streamingService" class="fas fa-heartbeat" style="color: <?php echo $streamingServiceStatus['status'] === 'OK' ? 'green' : 'red'; ?>;"></i>
                </span>
                <span style="margin-left: 3px">AU-EAST-1</span>
              </span>
            </div>
          </div>
          <div class="column is-4">
            <div style="display: flex; align-items: center; justify-content: center; flex-direction: column; text-align: center;">
              <span class="subtitle">
                <span id="streamingServiceWestIcon">
                  <i id="streamingServiceWest" class="fas fa-heartbeat" style="color: green;"></i>
                </span>
                <span style="margin-left: 3px">US-WEST-1</span>
              </span>
            </div>
          </div>
          <div class="column is-4">
            <div style="display: flex; align-items: center; justify-content: center; flex-direction: column; text-align: center;">
              <span class="subtitle">
                <span id="streamingServiceEastIcon">
                  <i id="streamingServiceEast" class="fas fa-heartbeat" style="color: green;"></i>
                </span>
                <span style="margin-left: 3px">US-EAST-1</span>
              </span>
            </div>
          </div>
        </div>
        <div class="column is-12">
          <div style="display: flex; align-items: center; justify-content: center; flex-direction: column; text-align: center; margin-top: 10px;">
            <button class="button is-link bot-button is-fullwidth no-working-spinner is-rounded" onclick="window.open('https://uptime.botofthespecter.com/', '_blank')">Uptime Monitors</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal components -->
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
        <span class="has-text-weight-bold variable-title">Streaming Services</span>:<br>
        The streaming service(s) is responsible for our streaming server. The green heartbeat icon indicates that the service is active. If the icon turns red, it means the streaming service is currently offline.
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
        Indicates the number of song identification requests we have remaining, this dose not count towards users with Spotify Connection.
      </p>
      <br>
      <p>
        <span class="has-text-weight-bold variable-title">Exchange Rate Checks Left:</span>
        Represents the number of exchange rate checks available to use to convert currency values.
      </p>
      <br>
      <p>
        <span class="has-text-weight-bold variable-title">Weather Requests Left:</span>
        Shows how many weather requests we can make to the weather API. This is used to provide weather information in the chat.
      </p>
    </section>
  </div>
</div>

<div id="cookie-consent-banner" class="notification is-dark has-text-centered" style="position: fixed; bottom: 1rem; right: 1rem; z-index: 100; display: none; padding: 1rem; box-shadow: 0 2px 10px rgba(0,0,0,0.3); max-width: 350px; border-radius: 6px;">
  <div class="content">
    <p class="has-text-white">
      We use cookies to enhance your experience on our site. By clicking "Accept", you consent to the use of cookies in accordance with our 
      <a href="https://botofthespecter.com/privacy-policy.php" target="_blank" class="has-text-link">Privacy Policy</a>. We use cookies to remember your bot version preference. This helps us provide a better experience for you. If you choose to decline cookies, we will not be able to remember your preference and you may need to select your bot version each time you visit our site.
    </p>
    <div class="buttons is-centered mt-2">
      <button id="accept-cookies" class="button is-success is-rounded is-hoverable">Accept</button>
      <button id="decline-cookies" class="button is-danger is-rounded is-hoverable">Decline</button>
    </div>
  </div>
</div>

<script>
// Function to handle bot selection changes
function changeBotSelection(bot) {
  window.location.href = 'bot.php?bot=' + bot;
}

const modalIds = [
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

  // Fetch the latest status for AU-EAST-1 Streaming service
  fetch('/api_status.php?service=streamingService')
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
    
  // Fetch the latest status for US-WEST-1 Streaming service
  fetch('/api_status.php?service=streamingServiceWest')
    .then(response => response.json())
    .then(data => {
      const streamingServiceWestIcon = document.getElementById('streamingServiceWest');
      if (data.status === 'OK') {
        streamingServiceWestIcon.className = 'fas fa-heartbeat beating';
        streamingServiceWestIcon.style.color = 'green';
      } else {
        streamingServiceWestIcon.className = 'fas fa-heart-broken';
        streamingServiceWestIcon.style.color = 'red';
      }
    })
    .catch(error => {
      const streamingServiceWestIcon = document.getElementById('streamingServiceWest');
      streamingServiceWestIcon.className = 'fas fa-heart-broken';
      streamingServiceWestIcon.style.color = 'red';
    });
    
  // Fetch the latest status for US-EAST-1 Streaming service
  fetch('/api_status.php?service=streamingServiceEast')
    .then(response => response.json())
    .then(data => {
      const streamingServiceEastIcon = document.getElementById('streamingServiceEast');
      if (data.status === 'OK') {
        streamingServiceEastIcon.className = 'fas fa-heartbeat beating';
        streamingServiceEastIcon.style.color = 'green';
      } else {
        streamingServiceEastIcon.className = 'fas fa-heart-broken';
        streamingServiceEastIcon.style.color = 'red';
      }
    })
    .catch(error => {
      const streamingServiceEastIcon = document.getElementById('streamingServiceEast');
      streamingServiceEastIcon.className = 'fas fa-heart-broken';
      streamingServiceEastIcon.style.color = 'red';
    });
}

function formatTimestamp(timestamp) {
  if (!timestamp) return "Never updated";
  
  const now = new Date();
  const updated = new Date(timestamp);
  const diffMs = now - updated;
  const diffSec = Math.floor(diffMs / 1000);
  const diffMin = Math.floor(diffSec / 60);
  const diffHour = Math.floor(diffMin / 60);
  const diffDay = Math.floor(diffHour / 24);

  if (diffSec < 60) {
    return `${diffSec} seconds ago`;
  } else if (diffMin < 60) {
    return `${diffMin} minute${diffMin !== 1 ? 's' : ''} ago`;
  } else if (diffHour < 24) {
    return `${diffHour} hour${diffHour !== 1 ? 's' : ''} ago`;
  } else {
    return `${diffDay} day${diffDay !== 1 ? 's' : ''} ago`;
  }
}

function updateApiLimits() {
  fetch('/api_limits.php')
  .then(response => response.json())
  .then(data => {
    // Display only the count numbers in the sidebar version
    document.getElementById('shazam-count').innerText = data.shazam.requests_remaining;
    document.getElementById('exchange-count').innerText = data.exchangerate.requests_remaining;
    document.getElementById('weather-count').innerText = data.weather.requests_remaining;
    
    // Update the tooltips with the last updated times in a friendly format
    document.getElementById('shazam-updated').innerText = formatTimestamp(data.shazam.last_updated);
    document.getElementById('exchange-updated').innerText = formatTimestamp(data.exchangerate.last_updated);
    document.getElementById('weather-updated').innerText = formatTimestamp(data.weather.last_updated);
  })
  .catch(error => {
    console.error('Error fetching API limits:', error);
  });
}

function checkBotStatus() {
  // Only check the currently selected bot
  const selectedBot = "<?php echo $selectedBot; ?>";
  
  // Define statusId and versionId based on the selected bot
  let statusId, versionId;
  switch(selectedBot) {
    case 'stable':
      statusId = 'stableStatus';
      versionId = 'stableVersion';
      break;
    case 'beta':
      statusId = 'betaStatus';
      versionId = 'betaVersion';
      break;
    case 'alpha':
      statusId = 'alphaStatus';
      versionId = 'alphaVersion';
      break;
    case 'discord':
      statusId = 'discordStatus';
      versionId = 'discordVersion';
      break;
  }
  
  // Make an AJAX request to fetch just this bot's status
  fetch(`check_bot_status.php?bot=${selectedBot}`)
    .then(response => response.json())
    .then(data => {
      if (statusId && document.getElementById(statusId)) {
        document.getElementById(statusId).innerHTML = data.status;
      }
      if (versionId && document.getElementById(versionId)) {
        document.getElementById(versionId).innerHTML = data.version;
      }
    })
    .catch(error => {
      console.error('Error fetching bot status:', error);
    });
}

function checkLastModified() {
  // Get the selected bot
  const selectedBot = "<?php echo $selectedBot; ?>";
  
  // Check the appropriate modified time and restart time based on the selected bot
  switch(selectedBot) {
    case 'stable':
      if (document.getElementById("stable-last-modified-time")) {
        // Implementation for checking stable bot's modified time
      }
      break;
    case 'beta':
      if (document.getElementById("beta-last-modified-time")) {
        // Implementation for checking beta bot's modified time
      }
      break;
    case 'alpha':
      if (document.getElementById("alpha-last-modified-time")) {
        // Implementation for checking alpha bot's modified time
      }
      break;
  }
}

// Refresh every 10 seconds, which is less frequent now that we're only checking one bot
setInterval(checkBotStatus, 10000);
setInterval(checkAllServices, 5000);
setInterval(updateApiLimits, 5000);
checkBotStatus();
checkAllServices();
updateApiLimits();

// Cookie consent management
document.addEventListener('DOMContentLoaded', function() {
  const cookieConsentBanner = document.getElementById('cookie-consent-banner');
  // Check if user has already made a cookie consent choice
  if (getCookie('cookie_consent') === '') {
    // No decision has been made, show the banner
    cookieConsentBanner.style.display = 'block';
  }
  // Accept cookies button
  document.getElementById('accept-cookies').addEventListener('click', function() {
    setCookie('cookie_consent', 'accepted', 365); // Remember for 1 year
    cookieConsentBanner.style.display = 'none';
  });
  // Decline cookies button
  document.getElementById('decline-cookies').addEventListener('click', function() {
    setCookie('cookie_consent', 'declined', 365); // Remember the decline for 1 year
    cookieConsentBanner.style.display = 'none';
    // Delete any existing bot selection cookie
    deleteCookie('selectedBot');
  });
  // Helper function to get cookie value
  function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(';').shift();
    return '';
  }
  // Helper function to set a cookie
  function setCookie(name, value, days) {
    const date = new Date();
    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    const expires = `expires=${date.toUTCString()}`;
    document.cookie = `${name}=${value}; ${expires}; path=/`;
  }
  // Helper function to delete a cookie
  function deleteCookie(name) {
    document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;`;
  }
});

// Tooltip functionality
document.addEventListener('DOMContentLoaded', function() {
  const tooltipTriggers = document.querySelectorAll('.tooltip-trigger');
  tooltipTriggers.forEach(trigger => {
    trigger.addEventListener('mouseenter', function() {
      const tooltipId = this.getAttribute('data-tooltip-id');
      const tooltip = document.getElementById(tooltipId);
      if (tooltip) {
        const rect = this.getBoundingClientRect();
        tooltip.style.display = 'block';
        tooltip.style.top = `${rect.bottom + window.scrollY + 5}px`;
        tooltip.style.left = `${rect.left + window.scrollX}px`;
      }
    });
    trigger.addEventListener('mouseleave', function() {
      const tooltipId = this.getAttribute('data-tooltip-id');
      const tooltip = document.getElementById(tooltipId);
      if (tooltip) {
        tooltip.style.display = 'none';
      }
    });
  });
});
</script>

<?php if ($showButtons): ?>
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
      setTimeout(() => { document.querySelectorAll('.bot-button').forEach(btn => btn.disabled = true); }, 10);
      const stopActions = [
        'killBot', 'restartBot',
        'killBetaBot', 'restartBetaBot',
        'killAlphaBot', 'restartAlphaBot',
        'killDiscordBot', 'restartDiscordBot'
      ];
      let shouldCheck = false;
      stopActions.forEach(action => { if (form.querySelector(`[name="${action}"]`)) shouldCheck = true; });
      if (shouldCheck) { setTimeout(() => { checkBotStatus(); }, 2000); }
    });
  });
});
</script>
<?php endif; ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<?php include 'usr_database.php'; ?>
</body>
</html>