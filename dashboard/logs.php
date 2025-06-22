<?php 
// Initialize the session
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

$pageTitle = t('bot_logs_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM `profile`");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

$logContent = t('logs_please_select_type');
$logType = '';  // Default log type

$logPath = "/home/botofthespecter/logs/logs";

// Include SSH configuration
include_once "/var/www/config/ssh.php";

// Helper function to read log file via SSH
function read_log_file($file_path) {
  global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
  try {
    $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
    $checkCommand = "test -f " . escapeshellarg($file_path) . " && echo '1' || echo '0'";
    $checkResult = SSHConnectionManager::executeCommand($connection, $checkCommand);
    if (trim($checkResult) !== '1') {
      $dirname = dirname($file_path);
      $dirCheckCommand = "test -d " . escapeshellarg($dirname) . " && echo '1' || echo '0'";
      $dirCheckResult = SSHConnectionManager::executeCommand($connection, $dirCheckCommand);
      if (trim($dirCheckResult) !== '1') {
        return ['error' => "Log directory not found: $dirname"];
      }
      return ['error' => "Log file not found: $file_path"];
    }
    // Read the entire file
    $readCommand = "cat " . escapeshellarg($file_path);
    $logContent = SSHConnectionManager::executeCommand($connection, $readCommand);
    if ($logContent === false) {
      return ['error' => 'Failed to read log file'];
    }
    $lines = explode("\n", $logContent);
    $linesTotal = count($lines);
    return [
      'linesTotal' => $linesTotal,
      'logContent' => $logContent
    ];
  } catch (Exception $e) { return ['error' => 'SSH connection failed: ' . $e->getMessage()]; }
}

// Helper function to highlight log dates in a string and add <br> at end of each line, with reverse order
function highlight_log_dates($text) {
  $style = 'style="color: #e67e22; font-weight: bold;"';
  $escaped = htmlspecialchars($text);
  $lines = explode("\n", $escaped);
  $lines = array_reverse($lines);
  foreach ($lines as &$line) {
    $line = preg_replace(
      '/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/',
      '<span ' . $style . '>$1</span>',
      $line
    );
  }
  return implode("<br>", $lines);
}

// AJAX handler for log fetching - must be before any output!
if (isset($_GET['log'])) {
  // Suppress warnings/notices for clean JSON output
  error_reporting(E_ERROR | E_PARSE);
  header('Content-Type: application/json');
  // Prevent browser/proxy caching
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Expires: 0');
  header('Pragma: no-cache');
  $logType = $_GET['log'];
  $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
  $currentUser = $_SESSION['username'];
  $log = "$logPath/$logType/$currentUser.txt";
  // Read the log file via SSH
  $result = read_log_file($log);
  if (isset($result['error'])) {
    echo json_encode(['error' => $result['error']]);
    exit();
  }
  $logContent = $result['logContent'];
  $linesTotal = $result['linesTotal'];
  // Only send the last 200 lines, reversed, to the frontend
  $lines = explode("\n", $logContent);
  $lines = array_reverse($lines);
  $last200 = array_slice($lines, 0, 200);
  $last200 = array_reverse($last200); // Put back in correct order
  $logContent = implode("\n", $last200);
  $logContent = highlight_log_dates($logContent);
  echo json_encode(['last_line' => $linesTotal, 'data' => $logContent]);
  exit();
}

if (isset($_GET['logType'])) {
  $logType = $_GET['logType'];
  $currentUser = $_SESSION['username'];
  $log = "$logPath/$logType/$currentUser.txt";
  // Read the log file via SSH
  $result = read_log_file($log, 200);  if (isset($result['error'])) {
    $logContent = "Error: " . $result['error'];
  } else {
    $logContent = $result['logContent'];
    if (trim($logContent) === '' || isset($result['empty'])) {
      $logContent = "Nothing has been logged yet.";
    }
  }
  // Highlight dates for initial page load
  $logContent = highlight_log_dates($logContent);
}


// Include access control
include "mod_access.php";

// Start output buffering for content
ob_start();
?>
<div class="notification is-warning is-light mb-3">
  <span class="icon"><i class="fas fa-info-circle"></i></span>
  <span>
    <?php echo t('logs_language_notice'); ?>
  </span>
</div>
<div class="columns">
  <div class="column is-one-quarter">
    <div class="box" style="height: 403px;">
      <p class="title is-5"><?php echo t('logs_select_log'); ?></p>
      <div class="field">
        <div class="control">
          <div class="select is-fullwidth" style="margin-bottom: 1em;">
            <select id="logs-select">
              <option><?php echo t('logs_select_type'); ?></option>
              <option value="bot" <?php echo $logType === 'bot' ? 'selected' : ''; ?>><?php echo t('logs_type_bot'); ?></option>
              <option value="chat" <?php echo $logType === 'chat' ? 'selected' : ''; ?>><?php echo t('logs_type_chat'); ?></option>
              <option value="twitch" <?php echo $logType === 'twitch' ? 'selected' : ''; ?>><?php echo t('logs_type_twitch'); ?></option>
              <option value="api" <?php echo $logType === 'api' ? 'selected' : ''; ?>><?php echo t('logs_type_api'); ?></option>
              <option value="chat_history" <?php echo $logType === 'chat_history' ? 'selected' : ''; ?>><?php echo t('logs_type_chat_history'); ?></option>
              <option value="event_log" <?php echo $logType === 'event_log' ? 'selected' : ''; ?>><?php echo t('logs_type_event_log'); ?></option>
              <option value="websocket" <?php echo $logType === 'websocket' ? 'selected' : ''; ?>><?php echo t('logs_type_websocket'); ?></option>
              <option value="discord" <?php echo $logType === 'discord' ? 'selected' : ''; ?>><?php echo t('logs_type_discord'); ?></option>
            </select>
          </div>
        </div>
      </div>
      <div class="content" id="logs-options">
        <?php echo t('logs_time_is'); ?> GMT+<span id="timezone-offset"></span>
        <div id="current-time-display" class="mt-2">
          <strong><?php echo t('logs_current_time'); ?></strong><br><span id="current-log-time"></span>
        </div>
      </div>
      <!-- Buttons Container - Hidden initially -->
      <div class="buttons buttons-container mt-4" style="display: none;">
        <button class="button is-link mr-2 mb-2" id="reload-log"><?php echo t('logs_reload_btn'); ?></button>
        <button class="button is-info toggle-button mr-2 mb-2" id="toggle-auto-refresh"><?php echo t('logs_auto_refresh'); ?>: OFF</button>
        <button class="button is-primary mb-2" id="load-more"><?php echo t('logs_load_more'); ?></button>
      </div>
    </div>
  </div>
  <div class="column">
    <div class="box">
      <div id="logs-logDisplay" class="logs-log-content">
        <h3 class="title is-5" id="log-title"></h3>
        <div class="field">
          <div class="control">
            <div
              id="logs-log-html"
              class="admin-log-content"
              style="max-height: 600px; min-height: 600px; font-family: monospace; white-space: pre-wrap; background: #23272f; color: #f5f5f5; border: 1px solid #444; border-radius: 4px; padding: 1em; width: 100%; overflow-x: auto; overflow-y: auto;"
              contenteditable="false"
            ><?php echo $logContent; ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();

// Start output buffering for scripts
ob_start();
?>
<script>
var last_line = 0;
var autoRefresh = false;
var currentLogName = ''; // Track the currently selected log
var logtext = document.getElementById("logs-log-textarea");
var logHtml = document.getElementById("logs-log-html");
const reloadButton = document.getElementById("reload-log");
const autoRefreshButton = document.getElementById("toggle-auto-refresh");
const loadMoreButton = document.getElementById("load-more");
const logSelect = document.getElementById("logs-select");
const buttonsContainer = document.querySelector(".buttons-container"); // Target the buttons container
const autoRefreshInterval = 5000; // Auto-refresh interval in milliseconds (5 seconds by default)

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
    console.log(<?php echo json_encode(t('logs_no_more_lines')); ?>);
    return;
  }
  // Load more lines or reset
  if (loadMore) {
    last_line -= 200;
  } else {
    last_line = 0;
  }
  try {
    // Add cache-busting timestamp to URL
    const response = await fetch(`logs.php?log=${logname}&since=${last_line}&_=${Date.now()}`);
    const json = await response.json();
      // Check for errors first
    if (json.error) {
      logHtml.innerHTML = `<span style="color: #ff6b6b;">Error: ${json.error}</span>`;
      toggleButtonsContainer(true);
      return;
    }
    // Check if file is empty
    if (json.empty || json["data"].length === 0 || json["data"].trim() === '') {
      logHtml.innerHTML = "(log is empty)";
    } else {
      last_line = json["last_line"];
      if (loadMore) {
        logHtml.innerHTML = json["data"] + logHtml.innerHTML;
      } else {
        logHtml.innerHTML = json["data"];
      }
    }
    toggleButtonsContainer(true);
  } catch (error) {
    console.error(<?php echo json_encode(t('logs_error_fetching')); ?>, error);
    logHtml.innerHTML = `<span style="color: #ff6b6b;">Network error: Failed to fetch log data</span>`;
  }
}

async function autoUpdateLog() {
  if (autoRefresh && currentLogName !== '') {
    try {
      // Add cache-busting timestamp to URL
      const response = await fetch(`logs.php?log=${currentLogName}&_=${Date.now()}`);
      const json = await response.json();
      // Check for errors
      if (json.error) {
        logHtml.innerHTML = `<span style="color: #ff6b6b;">Error: ${json.error}</span>`;
        return;
      }
      last_line = json["last_line"];
      logHtml.innerHTML = json["data"];
    } catch (error) {
      console.error(<?php echo json_encode(t('logs_error_fetching_auto_refresh')); ?>, error);
      logHtml.innerHTML = `<span style="color: #ff6b6b;">Auto-refresh error: Failed to fetch log data</span>`;
    }
  }
}

// Event listener for log type change
logSelect.addEventListener('change', (event) => {
  const selectedLog = event.target.value;
  if (selectedLog !== 'SELECT A LOG TYPE') {
    fetchLogData(selectedLog);
  } else {
    toggleButtonsContainer(false);
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
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  const hours = String(now.getHours()).padStart(2, '0');
  const minutes = String(now.getMinutes()).padStart(2, '0');
  const seconds = String(now.getSeconds()).padStart(2, '0');
  const formattedTime = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
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
<?php
$scripts = ob_get_clean();
include "layout.php";
?>