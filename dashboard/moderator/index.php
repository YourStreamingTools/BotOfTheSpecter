<?php
// Initialize the session
session_start();
$today = new DateTime();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title and Initial Variables
$title = "Moderator Dashboard";

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'modding_access.php';
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$channelData = $stmt->fetch(PDO::FETCH_ASSOC);
$timezone = $channelData['timezone'] ?? 'UTC';
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

// Get channel information
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
    }
  }
}

// Count custom commands
$commandCountStmt = $db->prepare("SELECT COUNT(*) FROM custom_commands");
$commandCountStmt->execute();
$commandCount = $commandCountStmt->fetchColumn();

// Count enabled custom commands
$enabledCommandStmt = $db->prepare("SELECT COUNT(*) FROM custom_commands WHERE status = 'Enabled'");
$enabledCommandStmt->execute();
$enabledCommandCount = $enabledCommandStmt->fetchColumn();

// Count disabled custom commands
$disabledCommandStmt = $db->prepare("SELECT COUNT(*) FROM custom_commands WHERE status = 'Disabled'");
$disabledCommandStmt->execute();
$disabledCommandCount = $disabledCommandStmt->fetchColumn();

// Count timers
$timerCountStmt = $db->prepare("SELECT COUNT(*) FROM timed_messages");
$timerCountStmt->execute();
$timerCount = $timerCountStmt->fetchColumn();

// Count enabled timers
$enabledTimerStmt = $db->prepare("SELECT COUNT(*) FROM timed_messages WHERE status = 'True'");
$enabledTimerStmt->execute();
$enabledTimerCount = $enabledTimerStmt->fetchColumn();

// Count disabled timers
$disabledTimerStmt = $db->prepare("SELECT COUNT(*) FROM timed_messages WHERE status = 'False'");
$disabledTimerStmt->execute();
$disabledTimerCount = $disabledTimerStmt->fetchColumn();
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
          <a href="manage_custom_commands.php?broadcaster_id=<?php echo $_SESSION['editing_user'];?>" class="button is-primary is-fullwidth mb-2">Manage Custom Commands</a>
          <a href="timed_messages.php?broadcaster_id=<?php echo $_SESSION['editing_user'];?>" class="button is-info is-fullwidth mb-2">Manage Chat Timers</a>
          <!--<a href="" class="button is-warning is-fullwidth"></a>-->
        </div>
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
</body>
</html>