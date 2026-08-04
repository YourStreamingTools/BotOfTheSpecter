<?php
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

// Define the versions for the bots
$remoteVersionFile = "/home/botofthespecter/logs/version";
$versionFilePath = "{$remoteVersionFile}/{$username}_version_control.txt";
$betaVersionFilePath = "{$remoteVersionFile}/beta/{$username}_beta_version_control.txt";
$v6VersionFilePath = "{$remoteVersionFile}/v6/{$username}_v6_version_control.txt";

// Define the status script path
$statusScriptPath = "/home/botofthespecter/status.py";

// Define script path for stable bot
$botScriptPath = "/home/botofthespecter/bot.py";

// Define script path for beta bot
$BetaBotScriptPath = "/home/botofthespecter/beta.py";

// Define script path for v6 bot
$V6BotScriptPath = "/home/botofthespecter/beta-v6.py";

// Fetch all versions from the API ONCE at the top
$versionApiUrl = 'https://api.botofthespecter.com/versions';
$versionApiData = @file_get_contents($versionApiUrl);
if ($versionApiData !== false) {
    $versionInfo = json_decode($versionApiData, true);
    $newVersion = $versionInfo['stable_version'] ?? 'N/A';
    $betaNewVersion = $versionInfo['beta_version'] ?? 'N/A';
    $v6NewVersion = $versionInfo['v6_version'] ?? '6.0.0';
} else {
    $newVersion = $betaNewVersion = $v6NewVersion = 'N/A';
}

// Get bot status and check if it's running - Used for initial page load only
if (!function_exists('bots_api_bot_status')) {
    require_once __DIR__ . '/bots_api_client.php';
}

// Fetch each bot type's status once and cache it, since getBotsStatus(),
// checkBotsRunning(), and getRunningVersion() below all need the same
// underlying bots-API response (running state, pid, and version together).
$__botStatusCache = [];
function fetchBotStatusData($username, $system) {
    global $__botStatusCache;
    $cacheKey = $system ?? '';
    if (array_key_exists($cacheKey, $__botStatusCache)) {
        return $__botStatusCache[$cacheKey];
    }
    $resp = bots_api_bot_status($username, $system);
    if (!$resp['ok'] && $system === 'beta') {
        $resp = bots_api_bot_status($username, null);
    }
    if (!$resp['ok']) {
        $result = ['ok' => false, 'error' => is_string($resp['error']) ? $resp['error'] : 'Bots API status request failed'];
        $__botStatusCache[$cacheKey] = $result;
        return $result;
    }
    $data = is_array($resp['data']) ? $resp['data'] : [];
    $running = !empty($data['running']);
    $foundType = $data['bot_type'] ?? null;
    if ($running && $system) {
        $match = ($foundType === $system) || ($system === 'beta' && in_array($foundType, ['beta', 'custom'], true));
        if (!$match && $foundType !== null) {
            $running = false;
        }
    }
    $result = [
        'ok' => true,
        'running' => $running,
        'pid' => $running ? intval($data['pid'] ?? 0) : 0,
        'version' => $running ? (string)($data['version'] ?? '') : '',
    ];
    $__botStatusCache[$cacheKey] = $result;
    return $result;
}

try {
    $statusOutput = getBotsStatus($statusScriptPath, $username, 'stable');
    $botSystemStatus = checkBotsRunning($statusScriptPath, $username, 'stable');
    $betaStatusOutput = getBotsStatus($statusScriptPath, $username, 'beta');
    $betaBotSystemStatus = checkBotsRunning($statusScriptPath, $username, 'beta');
    $v6StatusOutput = getBotsStatus($statusScriptPath, $username, 'v6');
    $v6BotSystemStatus = checkBotsRunning($statusScriptPath, $username, 'v6');
} catch (Exception $e) {
    $statusOutput = "<div class='status-message error'>Status: Bots API error</div>";
    $botSystemStatus = false;
    $betaStatusOutput = "<div class='status-message error'>Status: Bots API error</div>";
    $betaBotSystemStatus = false;
    $v6StatusOutput = "<div class='status-message error'>Status: Bots API error</div>";
    $v6BotSystemStatus = false;
}

// Utility function to get bot status message via the private bots control API.
function getBotsStatus($statusScriptPath, $username, $system = 'stable') {
    $status = fetchBotStatusData($username, $system);
    if (!$status['ok']) {
        return "<div class='status-message error'>Status: " . htmlspecialchars($status['error']) . "</div>";
    }
    if ($status['pid'] > 0) {
        return "<div class='status-message'>Status: PID {$status['pid']}.</div>";
    }
    return "<div class='status-message error'>Status: NOT RUNNING</div>";
}

// Check if a bot is running (returns boolean) via the private bots control API.
function checkBotsRunning($statusScriptPath, $username, $system = 'stable') {
    $status = fetchBotStatusData($username, $system);
    return $status['ok'] && $status['running'];
}

// Get the version currently running, via the same bots control API response
// (no separate round-trip needed - the status endpoint already returns it).
function getRunningVersion($versionFilePath, $newVersion, $type = '') {
    global $username;
    $status = fetchBotStatusData($username, $type ?: 'stable');
    if ($status['ok'] && $status['running'] && $status['version'] !== '') {
        return $status['version'];
    }
    return $newVersion;
}

// Set running versions if bots are running
if ($botSystemStatus) {
    $versionRunning = getRunningVersion($versionFilePath, $newVersion);
}

if ($betaBotSystemStatus) {
    $betaVersionRunning = getRunningVersion($betaVersionFilePath, $betaNewVersion, 'beta');
}

if ($v6BotSystemStatus) {
    $v6VersionRunning = getRunningVersion($v6VersionFilePath, $v6NewVersion, 'v6');
}
?>
