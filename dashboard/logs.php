<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL); ?>
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
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';

$logContent = '';
$logTypeDisplay = '';
if(isset($_GET['logType'])) {
    $logType = $_GET['logType'];
    $logPath = "/var/www/logs/$logType/$username.txt";
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
    <link rel="stylesheet" href="pagination.css">
    <script src="about.js"></script>
  	<link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
  	<link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <!-- <?php echo "User: $username | $twitchUserId | $authToken"; ?> -->
  </head>
<body>
<!-- Navigation -->
<?php include('header.php'); ?>
<!-- /Navigation -->

<div class="row column">
<br>
<h1><?php echo "$greeting, $twitchDisplayName <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
<br>
<h2>Logs:</h2>
<ul class="tabs" data-tabs id="logTabs">
    <li class="tabs-title <?php echo $logType === 'bot' ? 'is-active' : ''; ?>"><a href="#bot">Bot Logs</a></li>
    <li class="tabs-title <?php echo $logType === 'chat' ? 'is-active' : ''; ?>"><a href="#chat">Chat Logs</a></li>
    <li class="tabs-title <?php echo $logType === 'twitch' ? 'is-active' : ''; ?>"><a href="#twitch">Twitch Logs</a></li>
    <li class="tabs-title <?php echo $logType === 'api' ? 'is-active' : ''; ?>"><a href="#api">API Logs</a></li>
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
    <div class="tabs-panel <?php echo $logType === 'api' ? 'is-active' : ''; ?>" id="api">
        <h3>API Logs</h3>
        <pre><?php echo $logType === 'api' ? htmlspecialchars($logContent) : 'Loading. Please wait.'; ?></pre>
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