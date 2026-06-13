<?php
// Set strict timeout limits to prevent hanging
set_time_limit(8); // Maximum 8 seconds for status check (allows 2s buffer before PHP timeout)
ini_set('max_execution_time', 8);

while (ob_get_level()) { ob_end_clean(); }
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
session_write_close();

// Track operation start time for timeout management
$operationStart = microtime(true);

require_once '/var/www/lib/require_auth_ajax.php';

// Load translations so user-facing JSON messages are localized.
if (!function_exists('t')) {
  $userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : 'EN';
  $i18nPath = __DIR__ . '/../lang/i18n.php';
  if (file_exists($i18nPath)) {
    include_once $i18nPath;
  }
  if (!function_exists('t')) {
    function t($key, $replacements = [])
    {
      return $key;
    }
  }
}

if (!isset($_GET['bot'])) {
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => t('bot_action_missing_bot')]);
  exit();
}

$bot = $_GET['bot'];
if (!in_array($bot, ['stable', 'beta', 'v6'])) {
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => t('bot_action_invalid_bot_type')]);
  exit();
}

require_once '../includes/bot_control_functions.php';
$username = $_SESSION['username'] ?? '';

// Require username for bot status checks
if (empty($username)) {
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => t('bot_action_username_not_found')]);
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
  } elseif ($bot === 'v6') {
    $latestVersion = $versionInfo['v6_version'] ?? '6.0';
  }
} else {
  if ($bot === 'stable') {
    $latestVersion = '5.7.1';
  } elseif ($bot === 'beta') {
    $latestVersion = '5.8';
  } elseif ($bot === 'v6') {
    $latestVersion = '6.0';
  }
}

// Determine the version control file path for last run (on the bot server)
$remoteVersionFile = "/home/botofthespecter/logs/version/";
if ($bot === 'stable') {
  // Stable bot writes to the top-level version directory
  $remoteVersionFile .= "{$username}_version_control.txt";
} elseif ($bot === 'beta') {
  $remoteVersionFile .= "beta/{$username}_beta_version_control.txt";
}

// Function to get remote file mtime via SSH with timeout protection
function getRemoteFileMTime($remoteFile) {
  global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password, $operationStart;
  // Check if we're running out of time (1.5 second buffer)
  if ((microtime(true) - $operationStart) > 6.5) {
    error_log("Timeout protection: skipping getRemoteFileMTime - operation time exceeded");
    return null;
  }
  try {
    $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
    $cmd = "stat -c %Y " . escapeshellarg($remoteFile);
    $output = SSHConnectionManager::executeCommand($connection, $cmd);
    if ($output !== false && $output !== null) {
      if (function_exists('sanitizeSSHOutput')) { $output = sanitizeSSHOutput($output); }
      else { $output = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', (string)$output); }
      $output = trim($output);
      if (is_numeric($output)) return (int)$output;
    }
  } catch (Exception $e) {
    error_log("Failed to get remote file mtime: " . $e->getMessage());
  }
  return null;
}

// Function to get remote file contents via SSH with timeout protection
function getRemoteFileContents($remoteFile) {
  global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password, $operationStart;
  // Check if we're running out of time (1.5 second buffer)
  if ((microtime(true) - $operationStart) > 6.5) {
    error_log("Timeout protection: skipping getRemoteFileContents - operation time exceeded");
    return null;
  }
  try {
    $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
    $cmd = "cat " . escapeshellarg($remoteFile) . " 2>/dev/null";
    $output = SSHConnectionManager::executeCommand($connection, $cmd);
    if ($output !== false && $output !== null) {
      if (function_exists('sanitizeSSHOutput')) { $output = sanitizeSSHOutput($output); }
      else { $output = preg_replace('/\s*\[exit_code:\s*-?\d+\]\s*$/', '', (string)$output); }
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
  'lastModified' => isset($botStatus['lastModified']) && $botStatus['lastModified'] ? formatTimeAgo($botStatus['lastModified']) : t('bot_value_unknown'),
  'lastRun' => $lastRunTimestamp ? formatTimeAgo($lastRunTimestamp) : t('bot_value_never')
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
  if (!$timestamp) return t('bot_value_never');
  $current = time();
  $diff = $current - $timestamp;
  if ($diff < 60) {
    return t('time_seconds_ago', ['count' => $diff]);
  } elseif ($diff < 3600) {
    $minutes = floor($diff / 60);
    return t('time_minutes_ago', ['count' => $minutes]);
  } elseif ($diff < 86400) {
    $hours = floor($diff / 3600);
    return t('time_hours_ago', ['count' => $hours]);
  } else {
    $days = floor($diff / 86400);
    return t('time_days_ago', ['count' => $days]);
  }
}
?>
