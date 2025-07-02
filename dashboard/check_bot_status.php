<?php
// Set strict timeout limits to prevent hanging
set_time_limit(10); // Maximum 10 seconds for status check
ini_set('max_execution_time', 10);

while (ob_get_level()) { ob_end_clean(); }
ob_start();
session_start();

if (!isset($_SESSION['access_token'])) {
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => 'Authentication required']);
  exit();
}

if (!isset($_GET['bot'])) {
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => 'Missing bot parameter']);
  exit();
}

$bot = $_GET['bot'];
if (!in_array($bot, ['stable', 'beta'])) {
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => 'Invalid bot type']);
  exit();
}

require_once 'bot_control_functions.php';
$username = $_SESSION['username'] ?? '';

// Require username for bot status checks
if (empty($username)) {
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => 'Username not found in session']);
  exit();
}

$botStatus = checkBotRunning($username, $bot);

// Only fetch version info from API
$versionApiUrl = 'https://api.botofthespecter.com/versions';
$versionApiData = @file_get_contents($versionApiUrl);
$latestVersion = '';
if ($versionApiData !== false) {
  $versionInfo = json_decode($versionApiData, true);
  if ($bot === 'stable') {
    $latestVersion = $versionInfo['stable_version'] ?? '';
  } elseif ($bot === 'beta') {
    $latestVersion = $versionInfo['beta_version'] ?? '';
  }
} else {
  if ($bot === 'stable') {
    $latestVersion = '5.2';
  } elseif ($bot === 'beta') {
    $latestVersion = '5.4';
  }
}

// Determine the version control file path for last run (on the bot server)
$remoteVersionFile = "/home/botofthespecter/logs/version/";
if ($bot === 'stable') {
  $remoteVersionFile .= "{$username}_version_control.txt";
} elseif ($bot === 'beta') {
  $remoteVersionFile .= "{$username}_beta_version_control.txt";
}

// Function to get remote file mtime via SSH
function getRemoteFileMTime($remoteFile) {
  global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
  try {
    $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
    $cmd = "stat -c %Y " . escapeshellarg($remoteFile);
    $output = SSHConnectionManager::executeCommand($connection, $cmd);
    if ($output !== false) {
      $output = trim($output);
      if (is_numeric($output)) return (int)$output;
    }
  } catch (Exception $e) {
    error_log("Failed to get remote file mtime: " . $e->getMessage());
  }
  return null;
}

$lastRunTimestamp = getRemoteFileMTime($remoteVersionFile);

$response = [
  'success' => $botStatus['success'],
  'bot' => $bot,
  'running' => isset($botStatus['running']) ? $botStatus['running'] : false,
  'pid' => isset($botStatus['pid']) ? $botStatus['pid'] : 0,
  'version' => isset($botStatus['version']) ? $botStatus['version'] : '',
  'latestVersion' => $latestVersion,
  'updateAvailable' => !empty($botStatus['version']) && !empty($latestVersion) && version_compare($botStatus['version'], $latestVersion, '<'),
  'lastModified' => isset($botStatus['lastModified']) && $botStatus['lastModified'] ? formatTimeAgo($botStatus['lastModified']) : 'Unknown',
  'lastRun' => $lastRunTimestamp ? formatTimeAgo($lastRunTimestamp) : 'Never'
];

// Add status message - show helpful messages even when SSH succeeds
if (isset($botStatus['message']) && !empty($botStatus['message'])) {
  $response['message'] = $botStatus['message'];
}
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json');
echo json_encode($response);
exit();

function formatTimeAgo($timestamp) {
  if (!$timestamp) return 'Never';
  $current = time();
  $diff = $current - $timestamp;
  if ($diff < 60) {
    return "{$diff} seconds ago";
  } elseif ($diff < 3600) {
    $minutes = floor($diff / 60);
    return "{$minutes} minute" . ($minutes != 1 ? 's' : '') . " ago";
  } elseif ($diff < 86400) {
    $hours = floor($diff / 3600);
    return "{$hours} hour" . ($hours != 1 ? 's' : '') . " ago";
  } else {
    $days = floor($diff / 86400);
    return "{$days} day" . ($days != 1 ? 's' : '') . " ago";
  }
}
?>
