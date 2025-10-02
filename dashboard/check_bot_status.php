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
    $latestVersion = '5.4';
  } elseif ($bot === 'beta') {
    $latestVersion = '5.5';
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

// Function to get remote file contents via SSH
function getRemoteFileContents($remoteFile) {
  global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
  try {
    $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
    $cmd = "cat " . escapeshellarg($remoteFile) . " 2>/dev/null";
    $output = SSHConnectionManager::executeCommand($connection, $cmd);
    if ($output !== false) {
      return trim($output);
    }
  } catch (Exception $e) {
    error_log("Failed to get remote file contents: " . $e->getMessage());
  }
  return null;
}

// Helper: try to extract a semantic version number from arbitrary text
function extractSemver($text) {
  if (!$text) return '';
  // Match v?1.2.3 or 1.2.3 or 1.2
  if (preg_match('/v?(\d+\.\d+(?:\.\d+)?)/', $text, $m)) {
    return $m[1];
  }
  return trim($text);
}

$lastRunTimestamp = getRemoteFileMTime($remoteVersionFile);

// Try to read the remote version file contents and extract a version string
$remoteFileContents = getRemoteFileContents($remoteVersionFile);
$remoteFileVersion = extractSemver($remoteFileContents);

// If we were able to extract a version from the remote file, prefer that as the "version" value
$preferredVersion = '';
if (!empty($remoteFileVersion)) {
  $preferredVersion = $remoteFileVersion;
} elseif (!empty($botStatus['version'])) {
  $preferredVersion = $botStatus['version'];
}

$response = [
  'success' => $botStatus['success'],
  'bot' => $bot,
  'running' => isset($botStatus['running']) ? $botStatus['running'] : false,
  'pid' => isset($botStatus['pid']) ? $botStatus['pid'] : 0,
  // 'version' is the version the user last ran on the remote server (preferred), fallback to botStatus
  'version' => $preferredVersion,
  'lastRunVersion' => $remoteFileVersion ?: null,
  'latestVersion' => $latestVersion,
  'updateAvailable' => !empty($preferredVersion) && !empty($latestVersion) && version_compare($preferredVersion, $latestVersion, '<'),
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
