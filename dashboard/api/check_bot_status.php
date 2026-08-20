<?php
// Set strict timeout limits to prevent hanging
set_time_limit(8); // Maximum 8 seconds for status check (allows 2s buffer before PHP timeout)
ini_set('max_execution_time', 8);

while (ob_get_level()) { ob_end_clean(); }
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
session_write_close();

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
if (!in_array($bot, ['stable', 'beta', 'v6', 'kick'], true)) {
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

// Process status + file mtimes come from the bots control API (local on bot host).
// No SSH — see bots-api.md.
$botStatus = checkBotRunning($username, $bot);

// Latest published version from public API (not bot-host filesystem)
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
    $latestVersion = $versionInfo['v6_version'] ?? '6.0.0';
  } elseif ($bot === 'kick') {
    $latestVersion = $versionInfo['kick_bot'] ?? '';
  }
} else {
  if ($bot === 'stable') {
    $latestVersion = '5.7.16';
  } elseif ($bot === 'beta') {
    $latestVersion = '5.8';
  } elseif ($bot === 'v6') {
    $latestVersion = '6.0.0';
  } elseif ($bot === 'kick') {
    $latestVersion = '1.0.2';
  }
}

// Helper: try to extract a semantic version number from arbitrary text
function extractSemver($text) {
  if (!$text) return '';
  // Match v?1.2.3 or 1.2.3 or 1.2
  if (preg_match('/v?(\d+\.\d+(?:\.\d+)?)/', $text, $m)) {
    return $m[1];
  }
  return trim((string)$text);
}

$scriptMTime = isset($botStatus['script_mtime']) && $botStatus['script_mtime'] !== null
  ? (int)$botStatus['script_mtime']
  : null;
$lastRunTimestamp = isset($botStatus['last_run_mtime']) && $botStatus['last_run_mtime'] !== null
  ? (int)$botStatus['last_run_mtime']
  : null;

$remoteFileVersion = extractSemver($botStatus['version'] ?? '');
$preferredVersion = '';
if (!empty($remoteFileVersion)) {
  $preferredVersion = $remoteFileVersion;
} elseif (!empty($botStatus['version'])) {
  $preferredVersion = (string)$botStatus['version'];
}

// Version string outdated (published latest > last-run version)
$versionOutdated = !empty($preferredVersion) && !empty($latestVersion)
  && version_compare($preferredVersion, $latestVersion, '<');

// Code on disk newer than last start (mtime of .py vs version-control file)
$codeUpdateAvailable = !empty($botStatus['code_update_available']);
if (!$codeUpdateAvailable && $scriptMTime !== null && $lastRunTimestamp !== null) {
  $codeUpdateAvailable = $scriptMTime > $lastRunTimestamp;
}

$response = [
  'success' => !empty($botStatus['success']),
  'bot' => $bot,
  'running' => !empty($botStatus['running']),
  'pid' => isset($botStatus['pid']) ? (int)$botStatus['pid'] : 0,
  // Version the user last ran (from version-control file on bot host)
  'version' => $preferredVersion,
  'lastRunVersion' => $remoteFileVersion ?: null,
  'latestVersion' => $latestVersion,
  'updateAvailable' => $versionOutdated || $codeUpdateAvailable,
  'codeUpdateAvailable' => $codeUpdateAvailable,
  'versionOutdated' => $versionOutdated,
  // Formatted for the version card
  'lastModified' => $scriptMTime ? formatTimeAgo($scriptMTime) : t('bot_value_unknown'),
  'lastRun' => $lastRunTimestamp ? formatTimeAgo($lastRunTimestamp) : t('bot_value_never'),
  // Raw unix timestamps for client-side compare (locale-safe)
  'scriptMtime' => $scriptMTime,
  'lastRunMtime' => $lastRunTimestamp,
  // Operator-deployed custom_channel_modules/{channel}.py on bot host
  'customModuleAvailable' => !empty($botStatus['custom_module_available']),
];

if (isset($botStatus['message']) && $botStatus['message'] !== '') {
  $response['message'] = $botStatus['message'];
}

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json');
echo json_encode($response);
exit();

function formatTimeAgo($timestamp) {
  if (!$timestamp) return t('bot_value_never');
  $current = time();
  $diff = $current - (int)$timestamp;
  if ($diff < 0) {
    $diff = 0;
  }
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
