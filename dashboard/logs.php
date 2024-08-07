<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
$api_key = $user['api_key'];
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';

$logContent = 'Loading Please Wait';
$logType = 'bot';  // Default log type
if (isset($_GET['logType'])) {
  $logType = $_GET['logType'];
  $logPath = "/var/www/logs/$logType/$username.txt";
  if (file_exists($logPath)) {
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

// Check if it's an AJAX request
if (isset($_GET['log'])) {
  header('Content-Type: application/json');
  $logType = $_GET['log'];
  $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
  $logPath = "/var/www/logs/$logType/$username.txt";
  if (!file_exists($logPath)) {
      echo json_encode(['error' => 'Log file does not exist']);
      exit();
  }
  $file = new SplFileObject($logPath);
  $file->seek(PHP_INT_MAX);
  $linesTotal = $file->key();
  $startLine = max(0, $linesTotal - 200);
  if ($since > 0) {
      $startLine = $since;
  }
  $logLines = [];
  $file->seek($startLine);
  while (!$file->eof()) {
      $logLines[] = $file->fgets();
  }
  $logContent = implode("", array_reverse($logLines));
  echo json_encode(['last_line' => $linesTotal, 'data' => htmlspecialchars($logContent)]);
  exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <!-- Header -->
  <?php include('header.php'); ?>
  <style>
    .logs-container {
      display: flex;
      flex-direction: row;
      padding: 20px;
      height: 800px;
    }
    .logs-sidebar {
      width: 300px;
      padding-right: 20px;
    }
    .logs-log-area {
      flex-grow: 1;
      background-color: #333;
      padding: 20px;
      border-radius: 8px;
      display: flex;
      flex-direction: column;
    }
    .logs-log-content {
      flex-grow: 1;
      display: flex;
      flex-direction: column;
    }
    .logs-log-content textarea {
      width: 100%;
      flex-grow: 1;
      resize: none;
      background-color: #1e1e1e;
      color: #c0c0c0;
      border: none;
      padding: 10px;
      font-family: monospace;
    }
    .logs-title {
      color: #485fc7;
      text-align: center;
      font-size: 34px;
    }
    .logs-options {
      margin-bottom: 20px;
    }
    .logs-select {
      width: 100%;
      padding: 10px;
      margin-bottom: 10px;
      background-color: #333;
      color: #c0c0c0;
      border: none;
    }
  </style>
  <!-- /Header -->
</head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
  <div class="logs-container">
    <div class="logs-sidebar">
      <h2 class="logs-title">Logs</h2>
      <div>
        <label for="logs-select">Select a log to view:</label>
        <select id="logs-select" class="logs-select">
          <option value="bot" <?php echo $logType === 'bot' ? 'selected' : ''; ?>>Bot Log</option>
          <option value="discord" <?php echo $logType === 'discord' ? 'selected' : ''; ?>>Discord Bot Log</option>
          <option value="chat" <?php echo $logType === 'chat' ? 'selected' : ''; ?>>Chat Log</option>
          <option value="chat_history" <?php echo $logType === 'chat_history' ? 'selected' : ''; ?>>Chat History</option>
          <option value="twitch" <?php echo $logType === 'twitch' ? 'selected' : ''; ?>>Twitch Log</option>
          <option value="api" <?php echo $logType === 'api' ? 'selected' : ''; ?>>API Log</option>
        </select>
      </div>
      <div class="logs-options">
        Times are in GMT+10
      </div>
    </div>
    <div class="logs-log-area">
      <div id="logs-logDisplay" class="logs-log-content">
        <h3 class="logs-title" id="logs-log-name"><?php echo ucfirst($logType); ?> Logs</h3>
        <textarea id="logs-log-textarea" readonly><?php echo htmlspecialchars($logContent); ?></textarea>
      </div>
    </div>
  </div>
</div>

<script>
var last_line = 0;
async function autoupdateLog() {
    let logtext = document.getElementById("logs-log-textarea");
    const logselect = document.getElementById("logs-select");
    if (logselect.selectedIndex >= 0) {
        const logname = logselect.value;
        // Fetch Log Data
        let response = await fetch(`logs.php?log=${logname}&since=${last_line}`);
        let json = await response.json();
        last_line = json["last_line"];
        if (last_line === 0) {
            logtext.innerHTML = json["data"];
        } else {
            logtext.innerHTML += json["data"];
        }
        logtext.scrollTop = logtext.scrollHeight;
    }
}
async function updateLog(logname) {
  console.log('Changing log type to:', logname);
  last_line = 0;
  var logtext = document.getElementById("logs-log-textarea");
  var logtitle = document.getElementById("logs-log-name");
  logtitle.innerHTML = logname.charAt(0).toUpperCase() + logname.slice(1) + ' Logs';
  // Fetch Log Data
  let response = await fetch(`logs.php?log=${logname}`);
  let json = await response.json();
  if (json["data"].length == 0) {
    logtext.innerHTML = "(log is empty)";
  } else {
    last_line = json["last_line"];
    logtext.innerHTML = json["data"];
  }
}
document.getElementById("logs-select").addEventListener('change', (event) => {
  updateLog(event.target.value);
});
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>