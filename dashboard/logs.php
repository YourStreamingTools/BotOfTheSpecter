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
  <h1 class="title is-4"><?php echo "$greeting, $twitchDisplayName <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
  <br>
  <div class="tabs is-boxed">
    <ul>
      <li class="<?php echo $logType === 'bot' ? 'is-active' : ''; ?>"><a href="?logType=bot">Bot Logs</a></li>
      <li class="<?php echo $logType === 'script' ? 'is-active' : ''; ?>"><a href="?logType=script">Script Logs</a></li>
      <li class="<?php echo $logType === 'chat' ? 'is-active' : ''; ?>"><a href="?logType=chat">Chat Logs</a></li>
      <li class="<?php echo $logType === 'twitch' ? 'is-active' : ''; ?>"><a href="?logType=twitch">Twitch Logs</a></li>
      <li class="<?php echo $logType === 'api' ? 'is-active' : ''; ?>"><a href="?logType=api">API Logs</a></li>
    </ul>
  </div>
  
  <div>
    <div class="<?php echo $logType === 'bot' ? 'is-active' : ''; ?>">
        <h3 class="title is-5">Bot Logs</h3>
        <pre><?php echo $logType === 'bot' ? htmlspecialchars($logContent) : 'Loading. Please wait.'; ?></pre>
    </div>
    <div class="<?php echo $logType === 'script' ? 'is-active' : ''; ?>">
        <h3 class="title is-5">Script Logs</h3>
        <pre><?php echo $logType === 'script' ? htmlspecialchars($logContent) : 'Loading. Please wait.'; ?></pre>
    </div>
    <div class="<?php echo $logType === 'chat' ? 'is-active' : ''; ?>">
        <h3 class="title is-5">Chat Logs</h3>
        <pre><?php echo $logType === 'chat' ? htmlspecialchars($logContent) : 'Loading. Please wait.'; ?></pre>
    </div>
    <div class="<?php echo $logType === 'twitch' ? 'is-active' : ''; ?>">
        <h3 class="title is-5">Twitch Logs</h3>
        <pre><?php echo $logType === 'twitch' ? htmlspecialchars($logContent) : 'Loading. Please wait.'; ?></pre>
    </div>
    <div class="<?php echo $logType === 'api' ? 'is-active' : ''; ?>">
        <h3 class="title is-5">API Logs</h3>
        <pre><?php echo $logType === 'api' ? htmlspecialchars($logContent) : 'Loading. Please wait.'; ?></pre>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>