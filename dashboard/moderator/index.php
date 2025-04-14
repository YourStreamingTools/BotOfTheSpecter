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
$title = "Dashboard";

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'modding_access.php';
include 'user_db.php';
$getProfile = $db->query("SELECT timezone FROM profile");
$profile = $getProfile->fetchAll(PDO::FETCH_ASSOC);
$timezone = $profile['timezone'];
date_default_timezone_set($timezone);

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

// Get channel information
$channelResponse = getChannelStatus($_SESSION['editing_display_name']);
$channelInfo = $channelResponse['data'];
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
        <p><span>Current Date:</span> <?php echo $today->format('F j, Y'); ?></p>
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
          <?php
          $commandCount = 0;
          $timerCount = 0;
          $activeStatus = "Offline";
          $title = 'No Title';
          $gameName = 'Not Playing';
          $tags = [];
          $isLive = false;
          if ($channelInfo) {
            $title = $channelInfo['title'] ?? 'No Title';
            $gameName = $channelInfo['game_name'] ?? 'Not Playing';
            $tags = $channelInfo['tags'] ?? [];
            $isLive = $channelInfo['is_live'] ?? false;
            $activeStatus = $isLive ? "Live" : "Offline";
          }
          ?>
          <div class="column has-text-centered">
            <p class="heading">Commands</p>
            <p class="title"><?php echo $commandCount; ?></p>
          </div>
          <div class="column has-text-centered">
            <p class="heading">Timers</p>
            <p class="title"><?php echo $timerCount; ?></p>
          </div>
          <div class="column has-text-centered">
            <p class="heading">Bot Status</p>
            <p class="title"><?php echo $activeStatus; ?></p>
          </div>
        </div>
        <div class="mt-4">
          <p><span>Stream Title:</span> <?php echo htmlspecialchars($title); ?></p>
          <p><span>Game/Category:</span> <?php echo htmlspecialchars($gameName); ?></p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>