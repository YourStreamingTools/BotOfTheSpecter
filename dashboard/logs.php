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

$logContent = 'Please Select A Log Type';
$logType = '';  // Default log type
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
          <option>SELECT A LOG TYPE</option>
          <option value="bot" <?php echo $logType === 'bot' ? 'selected' : ''; ?>>Bot Log</option>
          <option value="chat" <?php echo $logType === 'chat' ? 'selected' : ''; ?>>Chat Log</option>
          <option value="twitch" <?php echo $logType === 'twitch' ? 'selected' : ''; ?>>Twitch Log</option>
          <option value="api" <?php echo $logType === 'api' ? 'selected' : ''; ?>>API Log</option>
          <option value="chat_history" <?php echo $logType === 'chat_history' ? 'selected' : ''; ?>>Chat History</option>
          <option value="event_log" <?php echo $logType === 'event_log' ? 'selected' : ''; ?>>Event Log</option>
          <option value="discord" <?php echo $logType === 'discord' ? 'selected' : ''; ?>>Discord Bot Log</option>
        </select>
      </div>
      <div class="logs-options" id="logs-options">
        Log Time is GMT+<span id="timezone-offset"></span>
        <div id="current-time-display" class="current-time-display">
          Current Log Time: <span id="current-log-time"></span>
        </div>
      </div>
      <!-- Buttons Container - Hidden initially -->
      <div class="buttons-container" style="display: none;">
        <button class="button" id="reload-log">Reload Log</button>
        <button class="button toggle-button" id="toggle-auto-refresh">Auto-refresh: OFF</button>
        <button class="button" id="load-more">Load More Lines</button>
      </div>
    </div>
    <div class="logs-log-area">
      <div id="logs-logDisplay" class="logs-log-content">
        <h3 class="logs-title" id="log-title">Logs</h3>
        <textarea id="logs-log-textarea" readonly><?php echo htmlspecialchars($logContent); ?></textarea>
      </div>
    </div>
  </div>
</div>

<script>
var last_line = 0;
var autoRefresh = false;
var currentLogName = ''; // Track the currently selected log
var logtext = document.getElementById("logs-log-textarea");
const reloadButton = document.getElementById("reload-log");
const autoRefreshButton = document.getElementById("toggle-auto-refresh");
const loadMoreButton = document.getElementById("load-more");
const logSelect = document.getElementById("logs-select");
const buttonsContainer = document.querySelector(".buttons-container"); // Target the buttons container
const autoRefreshInterval = 5000; // Auto-refresh interval in milliseconds (5 seconds by default)

// Get the h2 element where the log title will be displayed
const logTitle = document.getElementById("log-title");

// Function to update the log title with the selected log type
function updateLogTitle(logname) {
  if (logname && logname !== 'SELECT A LOG TYPE') {
    logTitle.textContent = `${capitalizeFirstLetter(logname)} Logs`;
  } else {
    logTitle.textContent = 'Logs';
  }
}

// Capitalize the first letter of the log type for a nice title format
function capitalizeFirstLetter(string) {
  return string.charAt(0).toUpperCase() + string.slice(1);
}

// Function to show buttons when a log file is selected
function toggleButtonsContainer(show) {
  if (show) {
    buttonsContainer.style.display = "block";
  } else {
    buttonsContainer.style.display = "none";
  }
}

async function fetchLogData(logname, loadMore = false) {
  // Set the current log name
  if (currentLogName !== logname) {
    currentLogName = logname;
  }

  // Prevent negative line counts
  if (loadMore && last_line <= 0) {
    console.log("No more lines to load.");
    return;
  }

  // Load more lines or reset
  if (loadMore) {
    last_line -= 200;
  } else {
    last_line = 0;
  }

  try {
    const response = await fetch(`logs.php?log=${logname}&since=${last_line}`);
    const json = await response.json();
    if (json["data"].length === 0) {
      logtext.innerHTML = "(log is empty)";
    } else {
      last_line = json["last_line"];
      if (loadMore) {
        logtext.innerHTML = json["data"] + logtext.innerHTML;
      } else {
        logtext.innerHTML = json["data"];
      }
    }

    // Show buttons after selecting a valid log file
    toggleButtonsContainer(true);

    // Update the log title based on selected log type
    updateLogTitle(logname);

  } catch (error) {
    console.error("Error fetching log data:", error);
  }
}

async function autoUpdateLog() {
  if (autoRefresh && currentLogName !== '') {
    try {
      const response = await fetch(`logs.php?log=${currentLogName}`);
      const json = await response.json();
      last_line = json["last_line"];
      logtext.innerHTML = json["data"];
    } catch (error) {
      console.error("Error fetching log data for auto-refresh:", error);
    }
  }
}

// Event listener for log type change
logSelect.addEventListener('change', (event) => {
  const selectedLog = event.target.value;
  if (selectedLog !== 'SELECT A LOG TYPE') {
    fetchLogData(selectedLog);
    updateLogTitle(selectedLog);  // Update the log title
  } else {
    toggleButtonsContainer(false);
    updateLogTitle('');
  }
});

// Event listener for reload button
reloadButton.addEventListener('click', () => {
  fetchLogData(currentLogName);
});

// Event listener for auto-refresh toggle
autoRefreshButton.addEventListener('click', () => {
  autoRefresh = !autoRefresh;
  autoRefreshButton.innerText = `Auto-refresh: ${autoRefresh ? 'ON' : 'OFF'}`;
  autoRefreshButton.classList.toggle('active', autoRefresh);
});

// Event listener for load more button
loadMoreButton.addEventListener('click', () => {
  fetchLogData(currentLogName, true);
});

// Auto-refresh interval (every 5 seconds)
setInterval(autoUpdateLog, autoRefreshInterval);

// Function to dynamically update current log time in 24-hour format with seconds
function updateCurrentLogTime() {
  const now = new Date();
  const hours = String(now.getHours()).padStart(2, '0');
  const minutes = String(now.getMinutes()).padStart(2, '0');
  const seconds = String(now.getSeconds()).padStart(2, '0');
  const formattedTime = `${hours}:${minutes}:${seconds}`;
  document.getElementById('current-log-time').innerText = formattedTime;
}

// Call the function to update the time every second
setInterval(updateCurrentLogTime, 1000);

// Function to check for daylight savings and adjust the GMT offset
function updateGMTOffset() {
  const now = new Date();
  const timezoneOffset = now.getTimezoneOffset();
  const isDaylightSavings = timezoneOffset === -660; // GMT+11 = -660 minutes offset
  const timezoneElement = document.getElementById('timezone-offset');
  timezoneElement.innerText = isDaylightSavings ? '11' : '10';
}

// Update the GMT offset on page load
updateGMTOffset();
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>