<?php
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
if (!in_array($bot, ['stable', 'beta', 'discord'])) {
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => 'Invalid bot type']);
  exit();
}

require_once 'bot_control_functions.php';
$username = $_SESSION['username'] ?? '';

// Check if username is available
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
  } elseif ($bot === 'discord') {
    $latestVersion = $versionInfo['discord_bot'] ?? '';
  }
} else {
  if ($bot === 'stable') {
    $latestVersion = '5.2';
  } elseif ($bot === 'beta') {
    $latestVersion = '5.4';
  } elseif ($bot === 'discord') {
    $latestVersion = '2.1';
  }
}

// Determine the version control file path for last run (on the bot server)
$remoteVersionFile = "/home/botofthespecter/logs/version/";
if ($bot === 'stable') {
  $remoteVersionFile .= "{$username}_version_control.txt";
} elseif ($bot === 'beta') {
  $remoteVersionFile .= "{$username}_beta_version_control.txt";
} elseif ($bot === 'discord') {
  $remoteVersionFile .= "{$username}_discord_version_control.txt";
}

// Function to get remote file mtime via SSH
function getRemoteFileMTime($remoteFile) {
  include "/var/www/config/ssh.php";
  $connection = @ssh2_connect($bots_ssh_host, 22);
  if (!$connection) return null;
  if (!@ssh2_auth_password($connection, $bots_ssh_username, $bots_ssh_password)) {
    if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
    return null;
  }
  $cmd = "stat -c %Y " . escapeshellarg($remoteFile);
  $stream = @ssh2_exec($connection, $cmd);
  if (!$stream) {
    if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
    return null;
  }
  stream_set_blocking($stream, true);
  $output = trim(stream_get_contents($stream));
  fclose($stream);
  if (function_exists('ssh2_disconnect')) { ssh2_disconnect($connection); }
  if (is_numeric($output)) return (int)$output;
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
