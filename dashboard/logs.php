<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL); ?>
<?php
// Initialize the session
session_start();

// Check if user is logged in
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
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';

$logContent = '';
$logType = '';
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
<body class="logs-body">
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->
<div class="logs-container">
  <div class="logs-sidebar">
    <h2 class="logs-title">Logs</h2>
    <div>
      <label for="logType">Select a log to view:</label>
      <select id="logs-logType" class="logs-select" onchange="changeLogType(this.value)">
        <option value="bot" <?php echo $logType === 'bot' ? 'selected' : ''; ?>>Bot Log</option>
        <option value="discord" <?php echo $logType === 'discord' ? 'selected' : ''; ?>>Discord Bot Log</option>
        <option value="chat" <?php echo $logType === 'chat' ? 'selected' : ''; ?>>Chat Log</option>
        <option value="twitch" <?php echo $logType === 'twitch' ? 'selected' : ''; ?>>Twitch Log</option>
        <option value="api" <?php echo $logType === 'api' ? 'selected' : ''; ?>>API Log</option>
      </select>
    </div>
    <div class="logs-options">
      <input type="checkbox" id="logs-autoUpdate" checked> Auto Update 10 secs<br>
      <input type="checkbox" id="logs-scrollBottom" checked> Scroll To Bottom<br>
      Times are in GMT+10
    </div>
  </div>
  <div class="logs-log-area">
    <div id="logs-logDisplay" class="logs-log-content">
      <h3 class="logs-title"><?php echo ucfirst($logType); ?> Logs</h3>
      <textarea readonly><?php echo htmlspecialchars($logContent); ?></textarea>
    </div>
  </div>
</div>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const logType = "<?php echo $logType; ?>";
    const logContentArea = document.querySelector('.logs-log-content textarea');
    function updateLogContent() {
      if (document.getElementById('logs-autoUpdate').checked) {
        fetch(`?logType=${logType}`)
          .then(response => response.text())
          .then(data => {
            const parser = new DOMParser();
            const htmlDoc = parser.parseFromString(data, 'text/html');
            const newLogContent = htmlDoc.querySelector('.logs-log-content textarea').value;
            logContentArea.value = newLogContent;
            if (document.getElementById('logs-scrollBottom').checked) {
              logContentArea.scrollTop = logContentArea.scrollHeight;
            }
          });
      }
    }
    setInterval(updateLogContent, 10000);
    document.getElementById('logs-logType').addEventListener('change', (event) => {
        changeLogType(event.target.value);
    });
  });
  function changeLogType(logType) {
    window.location.href = `?logType=${logType}`;
  }
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>