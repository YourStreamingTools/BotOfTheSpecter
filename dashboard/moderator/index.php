<?php
// Initialize the session
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$today = new DateTime();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: ../login.php');
    exit();
}

// Page Title and Initial Variables
$pageTitle = t('moderator_dashboard_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
// Include shared dashboard helpers from the parent dashboard folder
include_once __DIR__ . '/../userdata.php';
include_once __DIR__ . '/../bot_control.php';
include_once __DIR__ . '/../mod_access.php';
// Only include per-channel DB and storage logic if a channel/mod is selected
$no_mod_selected = false;
if (!isset($_SESSION['editing_username']) || empty($_SESSION['editing_username'])) {
  $no_mod_selected = true;
  // Provide safe defaults so the page can render without a selected channel
  $_SESSION['editing_display_name'] = $_SESSION['editing_display_name'] ?? 'No Channel Selected';
  $_SESSION['editing_user'] = $_SESSION['editing_user'] ?? '';
  $current_storage_used = 0;
  $max_storage_size = 0;
  $storage_percentage = 0;
} else {
  include 'user_db.php';
  include 'storage_used.php';
}
// Get timezone from per-channel DB if available
if (!empty($db) && empty($no_mod_selected)) {
  $stmt = $db->prepare("SELECT timezone FROM profile");
  if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $channelData = $result->fetch_assoc();
    $timezone = $channelData['timezone'] ?? 'UTC';
    $stmt->close();
  } else {
    $timezone = 'UTC';
  }
} else {
  $timezone = 'UTC';
}
date_default_timezone_set($timezone);
$currentDateTime = new DateTime('now');

$activeStatus = "Offline";
$stream_title = 'No Title';
$gameName = 'Not Playing';
$isLive = false;
$commandCount = 0;
$enabledCommandCount = 0;
$disabledCommandCount = 0;
$timerCount = 0;
$enabledTimerCount = 0;
$disabledTimerCount = 0;
$viewerCount = 0;
$streamUptime = '';

// Function to get channel status from Twitch API
function getChannelStatus($login) {
  global $clientID;
  $token = $_SESSION['access_token'];
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, "https://api.twitch.tv/helix/search/channels?first=1&query=" . urlencode($login));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Client-Id: ' . $clientID
  ]);
  $response = curl_exec($ch);
  $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $raw_response = $response;
  $curl_error = curl_error($ch);
  curl_close($ch);
  $result = [
    'success' => ($httpcode == 200),
    'http_code' => $httpcode,
    'raw_response' => $raw_response,
    'curl_error' => $curl_error,
    'data' => null
  ];
  if ($httpcode == 200) {
    $data = json_decode($response, true);
    if (!empty($data['data'][0])) {
      $result['data'] = $data['data'][0];
    }
  }
  return $result;
}

// Function to get stream information
function getStreamInfo($userId) {
  global $clientID;
  $token = $_SESSION['access_token'];
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, "https://api.twitch.tv/helix/streams?user_id=" . urlencode($userId));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Client-Id: ' . $clientID
  ]);
  $response = curl_exec($ch);
  $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $result = [
    'success' => ($httpcode == 200),
    'data' => null
  ];
  if ($httpcode == 200) {
    $data = json_decode($response, true);
    if (!empty($data['data'][0])) {
      $result['data'] = $data['data'][0];
    }
  }
  return $result;
}

// Get channel information (only if a channel is selected)
if (empty($no_mod_selected)) {
  $channelResponse = getChannelStatus($_SESSION['editing_display_name']);
  $channelInfo = $channelResponse['data'];
  if ($channelInfo) {
  $stream_title = $channelInfo['title'] ?? 'No Title';
  $gameName = $channelInfo['game_name'] ?? 'Not Playing';
  $isLive = $channelInfo['is_live'] ?? false;
  $activeStatus = $isLive ? "Live" : "Offline";
  // If channel is live, get additional stream information
  if ($isLive) {
    $streamInfo = getStreamInfo($channelInfo['id']);
    if ($streamInfo['success'] && $streamInfo['data']) {
      $viewerCount = $streamInfo['data']['viewer_count'] ?? 0;
      // Calculate uptime
      if (!empty($streamInfo['data']['started_at'])) {
        $startTime = new DateTime($streamInfo['data']['started_at']);
        $currentTime = new DateTime();
        $interval = $currentTime->diff($startTime);
        // Format the uptime
        $hours = $interval->h + ($interval->days * 24);
        $streamUptime = $hours . 'h ' . $interval->i . 'm';
      }
    } else {
      $channelInfo = null;
    }
  }
}
}

// If no moderator/channel selected, keep counts at zero and skip DB queries
if (empty($no_mod_selected) && !empty($db)) {
  // Count custom commands
  $commandCount = 0;
  $res = $db->query("SELECT COUNT(*) as cnt FROM custom_commands");
  if ($res) {
    $row = $res->fetch_assoc();
    $commandCount = $row['cnt'];
  }
  // Count enabled custom commands
  $enabledCommandCount = 0;
  $res = $db->query("SELECT COUNT(*) as cnt FROM custom_commands WHERE status = 'Enabled'");
  if ($res) {
    $row = $res->fetch_assoc();
    $enabledCommandCount = $row['cnt'];
  }
  // Count disabled custom commands
  $disabledCommandCount = 0;
  $res = $db->query("SELECT COUNT(*) as cnt FROM custom_commands WHERE status = 'Disabled'");
  if ($res) {
    $row = $res->fetch_assoc();
    $disabledCommandCount = $row['cnt'];
  }
  // Count timers
  $timerCount = 0;
  $res = $db->query("SELECT COUNT(*) as cnt FROM timed_messages");
  if ($res) {
    $row = $res->fetch_assoc();
    $timerCount = $row['cnt'];
  }
  // Count enabled timers
  $enabledTimerCount = 0;
  $res = $db->query("SELECT COUNT(*) as cnt FROM timed_messages WHERE status = 'True'");
  if ($res) {
    $row = $res->fetch_assoc();
    $enabledTimerCount = $row['cnt'];
  }
  // Count disabled timers
  $disabledTimerCount = 0;
  $res = $db->query("SELECT COUNT(*) as cnt FROM timed_messages WHERE status = 'False'");
  if ($res) {
    $row = $res->fetch_assoc();
    $disabledTimerCount = $row['cnt'];
  }
} else {
  $commandCount = $enabledCommandCount = $disabledCommandCount = 0;
  $timerCount = $enabledTimerCount = $disabledTimerCount = 0;
}

function formatBytes($bytes, $precision = 2) {
  $units = array('B', 'KB', 'MB', 'GB', 'TB');
  $bytes = max($bytes, 0);
  $pow = $bytes > 0 ? floor(log($bytes) / log(1024)) : 0;
  $pow = min($pow, count($units) - 1);
  $bytes /= pow(1024, $pow);
  return round($bytes, $precision) . ' ' . $units[$pow];
}
$storageUsedFormatted = formatBytes($current_storage_used ?? 0);
$storageMaxFormatted = formatBytes($max_storage_size ?? 0);
$storagePercent = isset($storage_percentage) ? max(0, min(100, round($storage_percentage, 2))) : 0;

ob_start();
?>
<div class="container">
  <br>
  <?php if (!empty($no_mod_selected)): ?>
    <div class="notification is-warning">
      <strong>No moderator/channel selected.</strong> Please select a channel to load moderator data.
    </div>
  <?php endif; ?>
  <div class="columns is-desktop">
    <div class="column is-3">
      <div class="box">
        <h3 class="title is-4">Moderator Info</h3>
        <p><span>Display Name:</span> <?php echo $_SESSION['editing_display_name']; ?></p>
        <p><span>Channel ID:</span> <?php echo $_SESSION['editing_user']; ?></p>
        <p><span>Current Date/Time:</span><br><?php echo $currentDateTime->format('F j, Y - g:i A'); ?></p>
      </div>
      <div class="box">
        <h3 class="title is-4">Quick Actions</h3>
        <div class="buttons">
          <a href="manage_custom_commands.php" class="button is-primary is-fullwidth mb-2">Manage Custom Commands</a>
          <a href="timed_messages.php" class="button is-info is-fullwidth mb-2">Manage Chat Timers</a>
          <!--<a href="" class="button is-warning is-fullwidth"></a>-->
        </div>
      </div>
      <div class="box" id="storage-usage" style="display: flex; flex-direction: column; justify-content: stretch;">
        <div class="is-flex is-align-items-center mb-2">
          <span class="icon is-large mr-2">
            <i class="fas fa-database fa-2x has-text-info"></i>
          </span>
          <div>
            <h2 class="title is-5 mb-0">Storage Usage</h2>
            <p class="help mb-0">Current storage usage for <?php echo isset($_SESSION['editing_display_name']) ? htmlspecialchars($_SESSION['editing_display_name']) : 'Channel'; ?></p>
          </div>
        </div>
        <div class="mb-2">
          <span class="has-text-weight-bold"><?php echo $storageUsedFormatted; ?></span>
          <span class="has-text-white">/ <?php echo $storageMaxFormatted; ?></span>
          <span class="has-text-white" style="float:right;"><?php echo number_format($storagePercent, 2); ?>% used</span>
        </div>
        <div style="margin-bottom:3px;">
          <progress class="progress is-info" value="<?php echo $storagePercent; ?>" max="100" style="height: 10px; margin-bottom:0;">
            <?php echo $storagePercent; ?>%
          </progress>
        </div>
        <div class="is-flex is-justify-content-space-between is-size-7" style="margin-top:0;">
          <span>0%</span>
          <span>25%</span>
          <span>50%</span>
          <span>75%</span>
          <span>100%</span>
        </div>
        <?php if ($storagePercent >= 100): ?>
          <div class="notification is-danger is-light mt-3 mb-0">
            <strong>Storage limit reached!</strong> Please delete some files or upgrade your plan.
          </div>
        <?php elseif ($storagePercent >= 90): ?>
          <div class="notification is-warning is-light mt-3 mb-0">
            <strong>Almost full!</strong> You are using over 90% of your storage.
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="column">
      <div class="box">
        <h3 class="title is-4">Channel Overview</h3>
        <div class="columns">
          <div class="column has-text-centered">
            <p class="heading">Custom Commands</p>
            <p class="title"><?php echo $commandCount; ?></p>
            <p class="subtitle is-size-6">
              <span class="has-text-success"><?php echo $enabledCommandCount; ?> Enabled</span>
              &nbsp;/&nbsp;
              <span class="has-text-danger"><?php echo $disabledCommandCount; ?> Disabled</span>
            </p>
          </div>
          <div class="column has-text-centered">
            <p class="heading">Timers</p>
            <p class="title"><?php echo $timerCount; ?></p>
            <p class="subtitle is-size-6">
              <span class="has-text-success"><?php echo $enabledTimerCount; ?> Enabled</span> 
              &nbsp;/&nbsp; 
              <span class="has-text-danger"><?php echo $disabledTimerCount; ?> Disabled</span>
            </p>
          </div>
          <div class="column has-text-centered">
            <p class="heading">Online Status</p>
            <p class="title"><?php echo $activeStatus; ?></p>
            <?php if ($isLive): ?>
            <p class="subtitle is-size-6">
              <span class="has-text-info"><?php echo number_format($viewerCount); ?> viewers</span>
              &nbsp;/&nbsp; 
              <span class="has-text-info"><?php echo $streamUptime; ?> uptime</span>
            </p>
            <?php endif; ?>
          </div>
        </div>
        <div class="mt-4">
          <p>Stream Title:<span class="has-text-success"> <?php echo htmlspecialchars($stream_title); ?></span></p>
          <p>Game/Category:<span class="has-text-info"> <?php echo htmlspecialchars($gameName); ?></span></p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<?php
$content = ob_get_clean();
include 'mod_layout.php';
exit;
?>