<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL); ?>
<?php
// Initialize the session
session_start();

// check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Bot Logs";

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
$refreshToken = $user['refresh_token'];
$webhookPort = $user['webhook_port'];
$websocketPort = $user['websocket_port'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';

$logContent = '';
$logTypeDisplay = '';
if(isset($_GET['logType'])) {
    $logType = $_GET['logType'];
    $logPath = "/var/www/logs/$logType/$username.txt";
    if(file_exists($logPath)) {
        // Read the file in reverse
        $file = new SplFileObject($logPath);
        $file->seek(PHP_INT_MAX); // Move to the end of the file
        $linesTotal = $file->key(); // Get the total number of lines
        $startLine = max(0, $linesTotal - 200); // Calculate the starting line number

        $logLines = [];
        $file->seek($startLine);
        while (!$file->eof()) {
            $logLines[] = $file->fgets();
        }
        $logContent = implode("", array_reverse($logLines));

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
    <!-- Headder -->
    <?php include('header.php'); ?>
    <!-- /Headder -->
  </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="row column">
<br>
<h1><?php echo "$greeting, $twitchDisplayName <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
<br>
<ul class="tabs" data-tabs id="logTabs">
    <li class="tabs-title <?php echo $logType === 'bot' ? 'is-active' : ''; ?>"><a href="#bot">Bot Logs</a></li>
    <li class="tabs-title <?php echo $logType === 'script' ? 'is-active' : ''; ?>"><a href="#script">Script Logs</a></li>
    <li class="tabs-title <?php echo $logType === 'chat' ? 'is-active' : ''; ?>"><a href="#chat">Chat Logs</a></li>
    <li class="tabs-title <?php echo $logType === 'twitch' ? 'is-active' : ''; ?>"><a href="#twitch">Twitch Logs</a></li>
    <li class="tabs-title <?php echo $logType === 'api' ? 'is-active' : ''; ?>"><a href="#api">API Logs</a></li>
</ul>

<div class="tabs-content" data-tabs-content="logTabs">
    <div class="tabs-panel <?php echo $logType === 'bot' ? 'is-active' : ''; ?>" id="bot">
        <h3>Bot Logs</h3>
        <pre><?php echo $logType === 'bot' ? htmlspecialchars($logContent) : 'Loading. Please wait.'; ?></pre>
    </div>
    <div class="tabs-panel <?php echo $logType === 'script' ? 'is-active' : ''; ?>" id="script">
        <h3>Script Logs</h3>
        <pre><?php echo $logType === 'script' ? htmlspecialchars($logContent) : 'Loading. Please wait.'; ?></pre>
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