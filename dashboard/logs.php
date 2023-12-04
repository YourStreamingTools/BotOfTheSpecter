<?php
// Initialize the session
session_start();

// check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

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
$authToken = $access_token;
$user_timezone = $user['timezone'];

if (!$user_timezone || !in_array($user_timezone, timezone_identifiers_list())) {
    $user_timezone = 'Etc/UTC';
}

date_default_timezone_set($user_timezone);

// Determine the greeting based on the user's local time
$currentHour = date('G');
$greeting = '';

if ($currentHour < 12) {
    $greeting = "Good morning";
} else {
    $greeting = "Good afternoon";
}

$logContent = '';
$logTypeDisplay = '';
if(isset($_GET['logType'])) {
    $logType = $_GET['logType'];
    $logPath = "logs/$logType/$username.txt";
    if(file_exists($logPath)) {
      $logLines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      $logContent = implode("\n", array_reverse($logLines));  
        
        // Check if the log content is empty
        if (trim($logContent) === '') {
            $logContent = "Nothing has been logged yet.";
        }
    } else {
        $logContent = "Error getting that log file, it doesn't look like it exists.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BotOfTheSpecter - Bot Logs</title>
    <link rel="stylesheet" href="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.min.css">
    <link rel="stylesheet" href="https://cdn.yourstreaming.tools/css/custom.css">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.yourstreaming.tools/js/about.js"></script>
  	<link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
  	<link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <!-- <?php echo "User: $username | $twitchUserId | $authToken"; ?> -->
  </head>
<body>
<!-- Navigation -->
<div class="title-bar" data-responsive-toggle="mobile-menu" data-hide-for="medium">
  <button class="menu-icon" type="button" data-toggle="mobile-menu"></button>
  <div class="title-bar-title">Menu</div>
</div>
<nav class="top-bar stacked-for-medium" id="mobile-menu">
  <div class="top-bar-left">
    <ul class="dropdown vertical medium-horizontal menu" data-responsive-menu="drilldown medium-dropdown hinge-in-from-top hinge-out-from-top">
      <li class="menu-text">BotOfTheSpecter</li>
      <li><a href="bot.php">Dashboard</a></li>
      <li><a href="mods.php">View Mods</a></li>
      <li><a href="followers.php">View Followers</a></li>
      <li><a href="subscribers.php">View Subscribers</a></li>
      <li><a href="vips.php">View VIPs</a></li>
      <li class="is-active"><a href="logs.php">View Logs</a></li>
      <li><a href="commands.php">Bot Commands</a></li>
      <li><a href="add-commands.php">Add Bot Command</a></li>
      <li><a href="logout.php">Logout</a></li>
    </ul>
  </div>
  <div class="top-bar-right">
    <ul class="menu">
      <li><a class="popup-link" onclick="showPopup()">&copy; 2023 BotOfTheSpecter. All rights reserved.</a></li>
    </ul>
  </div>
</nav>
<!-- /Navigation -->

<div class="row column">
<br>
<h1><?php echo "$greeting, <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>$twitchDisplayName!"; ?></h1>
<br>
<h2>Your Logs:</h2>
<ul class="tabs" data-tabs id="logTabs">
    <li class="tabs-title <?php echo $logType === 'bot' ? 'is-active' : ''; ?>"><a href="#bot">Bot Logs</a></li>
    <li class="tabs-title <?php echo $logType === 'chat' ? 'is-active' : ''; ?>"><a href="#chat">Chat Logs</a></li>
    <li class="tabs-title <?php echo $logType === 'twitch' ? 'is-active' : ''; ?>"><a href="#twitch">Twitch Logs</a></li>
    <li class="tabs-title <?php echo $logType === 'script' ? 'is-active' : ''; ?>"><a href="#script">Script Logs</a></li>
</ul>

<div class="tabs-content" data-tabs-content="logTabs">
    <div class="tabs-panel <?php echo $logType === 'bot' ? 'is-active' : ''; ?>" id="bot">
        <h3>Bot Logs</h3>
        <pre><?php echo $logType === 'bot' ? htmlspecialchars($logContent) : 'Loading. Please wait.'; ?></pre>
    </div>
    <div class="tabs-panel <?php echo $logType === 'chat' ? 'is-active' : ''; ?>" id="chat">
        <h3>Chat Logs</h3>
        <pre><?php echo $logType === 'chat' ? htmlspecialchars($logContent) : 'Loading. Please wait.'; ?></pre>
    </div>
    <div class="tabs-panel <?php echo $logType === 'twitch' ? 'is-active' : ''; ?>" id="twitch">
        <h3>Twitch Logs</h3>
        <pre><?php echo $logType === 'twitch' ? htmlspecialchars($logContent) : 'Loading. Please wait.'; ?></pre>
    </div>
    <div class="tabs-panel <?php echo $logType === 'script' ? 'is-active' : ''; ?>" id="script">
        <h3>Script Logs</h3>
        <pre><?php echo $logType === 'script' ? htmlspecialchars($logContent) : 'Loading. Please wait.'; ?></pre>
    </div>
</div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
<script>
$(document).ready(function() {
    $("#logTabs a").click(function(e) {
        e.preventDefault();
        var logType = $(this).attr('href').replace('#', '');
        window.location.href = "logs.php?logType=" + logType;
    });
});
</script>
</body>
</html>