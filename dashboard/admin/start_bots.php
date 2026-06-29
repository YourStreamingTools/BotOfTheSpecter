<?php
// Start output buffering immediately to prevent any accidental output
ob_start();

require_once '/var/www/lib/session_bootstrap.php';
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";
include '/var/www/config/twitch.php';
$pageTitle = t('admin_start_bots_page_title');

// Collect server-side logs for browser console output instead of server-side error_log
$client_console_logs = [];
function client_console_log($msg, $level = 'error')
{
    global $client_console_logs;
    if (!is_string($msg))
        $msg = print_r($msg, true);
    $msg = preg_replace('/(Authorization:\s*Bearer\s+)[^\s\\\]]+/i', '$1[REDACTED]', $msg);
    $msg = preg_replace('/(access_token|refresh_token|api_key|apiKey)["\']?\s*[:=]\s*[^\s\,\)\}]+/i', '$1: [REDACTED]', $msg);
    $msg = mb_substr($msg, 0, 2000);
    $client_console_logs[] = ['level' => $level, 'msg' => $msg];
}

// Token cache path
$tokenCacheFile = '/var/www/cache/tokens/start_bot_tokens.json';

function ensureTokenCacheDirExists($filePath)
{
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

function readTokenCacheFile($filePath)
{
    if (!file_exists($filePath))
        return [];
    $contents = @file_get_contents($filePath);
    if ($contents === false)
        return [];
    $data = json_decode($contents, true);
    return is_array($data) ? $data : [];
}

function writeTokenCacheFile($filePath, $data)
{
    ensureTokenCacheDirExists($filePath);
    $tmp = $filePath . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT);
    if (@file_put_contents($tmp, $json, LOCK_EX) === false)
        return false;
    return @rename($tmp, $filePath);
}

function getTokenCacheEntry($filePath, $twitchId)
{
    $cache = readTokenCacheFile($filePath);
    return $cache[$twitchId] ?? null;
}

function setTokenCacheEntry($filePath, $twitchId, $entry)
{
    $cache = readTokenCacheFile($filePath);
    $cache[$twitchId] = $entry;
    return writeTokenCacheFile($filePath, $cache);
}

function removeTokenCacheEntry($filePath, $twitchId)
{
    $cache = readTokenCacheFile($filePath);
    if (isset($cache[$twitchId])) {
        unset($cache[$twitchId]);
        return writeTokenCacheFile($filePath, $cache);
    }
    return true;
}

function get_admin_beta_mode_params($conn, $channelLookupId, $useCustom = false, $useSelf = false, $legacyTwitchUserId = null) {
    $params = [
        'use_custom_bot' => false,
        'custom_bot_username' => null,
        'use_self' => (bool) $useSelf
    ];
    if (!$useCustom || empty($channelLookupId)) {
        return $params;
    }
    $stmt = $conn->prepare("SELECT bot_username, is_verified FROM custom_bots WHERE channel_id = ? LIMIT 1");
    if (!$stmt) {
        client_console_log('get_admin_beta_mode_params: failed to prepare custom_bots lookup', 'warn');
        return $params;
    }
    $lookupId = (string) $channelLookupId;
    $stmt->bind_param('s', $lookupId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row && !empty($legacyTwitchUserId)) {
        $stmtLegacy = $conn->prepare("SELECT bot_username, is_verified FROM custom_bots WHERE channel_id = ? LIMIT 1");
        if ($stmtLegacy) {
            $legacyLookupId = (string) $legacyTwitchUserId;
            $stmtLegacy->bind_param('s', $legacyLookupId);
            $stmtLegacy->execute();
            $legacyRes = $stmtLegacy->get_result();
            $row = $legacyRes ? $legacyRes->fetch_assoc() : null;
            $stmtLegacy->close();
        }
    }
    if (!$row) {
        client_console_log('get_admin_beta_mode_params: no custom bot record for channel_id=' . $lookupId, 'warn');
        return $params;
    }
    if ((int) ($row['is_verified'] ?? 0) !== 1) {
        client_console_log('get_admin_beta_mode_params: custom bot exists but is not verified for channel_id=' . $lookupId, 'warn');
        return $params;
    }
    $customBotUsername = trim((string) ($row['bot_username'] ?? ''));
    if ($customBotUsername === '') {
        client_console_log('get_admin_beta_mode_params: custom bot username is empty for channel_id=' . $lookupId, 'warn');
        return $params;
    }
    $params['use_custom_bot'] = true;
    $params['custom_bot_username'] = $customBotUsername;
    $params['use_self'] = false;
    return $params;
}

function format_admin_elapsed_seconds($seconds) {
    if (!is_numeric($seconds) || $seconds < 0)
        return t('admin_start_bots_label_unknown');
    $seconds = (int) $seconds;
    if ($seconds < 60)
        return $seconds . 's';
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($days > 0)
        return $days . 'd ' . $hours . 'h ' . $minutes . 'm';
    if ($hours > 0)
        return $hours . 'h ' . $minutes . 'm';
    return $minutes . 'm';
}

function scan_running_bots_via_ps($filterUsername = null) {
    global $bots_ssh_host, $bots_ssh_username, $bots_ssh_password;
    $bots = [];
    if (empty($bots_ssh_host) || empty($bots_ssh_username) || empty($bots_ssh_password) || !class_exists('SSHConnectionManager')) {
        return $bots;
    }
    $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
    if (!$connection) {
        return $bots;
    }
    $output = SSHConnectionManager::executeCommand($connection, "ps -ww -eo pid=,etimes=,args=");
    if (function_exists('sanitizeSSHOutput')) {
        $output = sanitizeSSHOutput($output);
    }
    if ($output === false || $output === null) {
        return $bots;
    }
    foreach (preg_split('/\r?\n/', (string) $output) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        // Columns: <pid> <etimes> <args...>
        if (!preg_match('/^(\d+)\s+(\d+)\s+(.*)$/', $line, $m)) {
            continue;
        }
        $pid = (int) $m[1];
        $etimes = (int) $m[2];
        $args = $m[3];
        if (!preg_match('#(^|/)python[0-9.]*\s+(?:-u\s+)?(?:\S*/)?(bot|beta-v6|beta)\.py(\s|$)#', $args, $sm)) {
            continue;
        }
        $script = $sm[2]; // 'bot' | 'beta-v6' | 'beta'
        if (!preg_match('/-channel\s+(\S+)/', $args, $cm)) {
            continue;
        }
        $uname = $cm[1];
        if ($filterUsername !== null && strcasecmp($uname, $filterUsername) !== 0) {
            continue;
        }
        if ($script === 'bot') {
            $botType = 'stable';
        } elseif ($script === 'beta-v6') {
            $botType = 'v6';
        } else {
            $botType = preg_match('/(^|\s)-custom(\s|$)/', $args) ? 'custom' : 'beta';
        }
        // One bot per channel is expected; first match wins.
        if (!isset($bots[$uname])) {
            $bots[$uname] = [
                'pid' => $pid,
                'bot_type' => $botType,
                'uptime_seconds' => $etimes,
                'uptime_human' => format_admin_elapsed_seconds($etimes)
            ];
        }
    }
    return $bots;
}

// Lightweight wrapper to provide a start_bot_for_user() function when missing.
// This uses the central performBotAction() implementation in dashboard/bot_control_functions.php.
if (!function_exists('start_bot_for_user')) {
    function start_bot_for_user($username, $botType = 'stable')
    {
        global $conn, $api_key;
        // Load tokens and api_key for the user
        $stmt = $conn->prepare("SELECT id, twitch_user_id, access_token, refresh_token, api_key, use_custom, use_self FROM users WHERE username = ? LIMIT 1");
        if (!$stmt)
            return ['success' => false, 'message' => t('admin_start_bots_err_db_token_lookup')];
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row)
            return ['success' => false, 'message' => t('admin_start_bots_err_user_not_found')];
        // Extract all required fields
        $userId = trim((string) ($row['id'] ?? ''));
        $twitchUserId = trim($row['twitch_user_id'] ?? '');
        $accessToken = trim($row['access_token'] ?? '');
        $refreshToken = trim($row['refresh_token'] ?? '');
        $useCustom = ((int) ($row['use_custom'] ?? 0)) === 1;
        $useSelf = ((int) ($row['use_self'] ?? 0)) === 1;
        // Get API key - try per-user key first, then global fallback
        $userApiKey = trim($row['api_key'] ?? '');
        $globalApiKey = trim($api_key ?? '');
        $finalApiKey = !empty($userApiKey) ? $userApiKey : $globalApiKey;
        // Validate all required parameters before proceeding
        $missingParams = [];
        if (empty($username))
            $missingParams[] = 'username';
        if (empty($twitchUserId))
            $missingParams[] = 'twitch_user_id';
        if (empty($accessToken))
            $missingParams[] = 'access_token';
        if (empty($refreshToken))
            $missingParams[] = 'refresh_token';
        if (empty($finalApiKey))
            $missingParams[] = 'api_key';
        if (!empty($missingParams)) {
            return [
                'success' => false,
                'message' => t('admin_start_bots_err_missing_params', [implode(', ', $missingParams)]),
                'debug_info' => [
                    'username' => $username,
                    'twitch_user_id' => !empty($twitchUserId) ? 'present' : 'MISSING',
                    'access_token' => !empty($accessToken) ? 'present' : 'MISSING',
                    'refresh_token' => !empty($refreshToken) ? 'present' : 'MISSING',
                    'api_key' => !empty($finalApiKey) ? 'present' : 'MISSING'
                ]
            ];
        }
        // If performBotAction exists, delegate to it with all required params
        if (function_exists('performBotAction')) {
            $actionBotType = ($botType === 'custom') ? 'beta' : $botType;
            $params = [
                'username' => $username,
                'twitch_user_id' => $twitchUserId,
                'auth_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'api_key' => $finalApiKey
            ];
            if ($actionBotType === 'beta') {
                $effectiveUseCustom = ($botType === 'custom') ? true : $useCustom;
                $effectiveUseSelf = ($botType === 'custom') ? false : $useSelf;
                $betaModeParams = get_admin_beta_mode_params($conn, $userId, $effectiveUseCustom, $effectiveUseSelf, $twitchUserId);
                if ($botType === 'custom' && empty($betaModeParams['use_custom_bot'])) {
                    return ['success' => false, 'message' => t('admin_start_bots_err_custom_not_verified')];
                }
                $params = array_merge($params, $betaModeParams);
            }
            $res = performBotAction('run', $actionBotType, $params);
            // performBotAction returns an array
            return $res;
        }
        return ['success' => false, 'message' => t('admin_start_bots_err_no_impl')];
    }
}

// Check for AJAX requests IMMEDIATELY - before any other includes
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_running_bots'])) {
    // Clean ALL output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    try {
        // Clean ALL output buffers and start a fresh buffer to capture incidental output
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();
        // Check if SSH config is available
        if (!isset($bots_ssh_host) || !isset($bots_ssh_username) || !isset($bots_ssh_password)) {
            echo json_encode(['success' => false, 'message' => 'SSH configuration not available', 'bots' => []]);
            exit;
        }
        // Single SSH `ps` call returns every running bot at once. This used to be an
        // O(N users x several SSH calls) loop over status.py -- the main slowness.
        require_once __DIR__ . '/../includes/bot_control_functions.php';
        $running_bots = [];
        $scanned = scan_running_bots_via_ps();
        foreach ($scanned as $scanUname => $scanInfo) {
            $running_bots[] = [
                'username' => $scanUname,
                'pid' => $scanInfo['pid'],
                'bot_type' => $scanInfo['bot_type'],
                'version' => '',
                'uptime_seconds' => $scanInfo['uptime_seconds'],
                'uptime_human' => $scanInfo['uptime_human']
            ];
        }
        $debug = ob_get_clean();
        echo json_encode(['success' => true, 'bots' => $running_bots, 'debug' => $debug]);
    } catch (Exception $e) {
        $debug = ob_get_clean();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'bots' => [], 'debug' => $debug]);
    }
    exit;
}

// Lightweight single-bot status check (used by Restart All to verify ONE bot without
// re-scanning every user). One SSH `ps` call, filtered to this channel.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check_one_bot'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    try {
        require_once __DIR__ . '/../includes/bot_control_functions.php';
        $uname = trim($_GET['username'] ?? '');
        if ($uname === '') {
            echo json_encode(['success' => false, 'message' => 'Missing username', 'pid' => 0]);
            exit;
        }
        $scanned = scan_running_bots_via_ps($uname);
        $entry = null;
        foreach ($scanned as $info) {
            $entry = $info; // filtered set, at most one
            break;
        }
        if ($entry) {
            echo json_encode([
                'success' => true,
                'running' => true,
                'pid' => $entry['pid'],
                'bot_type' => $entry['bot_type']
            ]);
        } else {
            echo json_encode(['success' => true, 'running' => false, 'pid' => 0]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'pid' => 0]);
    }
    exit;
}

// Handle AJAX request to validate a user's token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validate_user_token'])) {
    // Clean any output that may have been generated
    ob_end_clean();
    header('Content-Type: application/json');
    try {
        // Clean any output that may have been generated and capture incidental output
        while (ob_get_level())
            ob_end_clean();
        ob_start();
        $twitch_user_id = $_POST['twitch_user_id'] ?? '';
        if (empty($twitch_user_id)) {
            echo json_encode(['success' => false, 'message' => 'Missing twitch_user_id']);
            exit;
        }
        // Fetch the user's access token from database
        $stmt = $conn->prepare("SELECT access_token FROM users WHERE twitch_user_id = ?");
        $stmt->bind_param("s", $twitch_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'No token found for user']);
            exit;
        }
        $row = $result->fetch_assoc();
        $access_token = $row['access_token'];
        $stmt->close();
        // Check cache first. If cache indicates token is valid AND shows is_mod=true, return immediately.
        // If cache indicates is_mod is false/unknown, continue and perform a fresh validation+mod check
        $cacheEntry = getTokenCacheEntry($tokenCacheFile, $twitch_user_id);
        if ($cacheEntry && isset($cacheEntry['expires_at']) && $cacheEntry['expires_at'] > time() && !empty($cacheEntry['is_mod'])) {
            $expires_in = $cacheEntry['expires_at'] - time();
            $is_mod = $cacheEntry['is_mod'] ?? false;
            $debug = ob_get_clean();
            echo json_encode([
                'success' => true,
                'valid' => true,
                'expires_in' => $expires_in,
                'is_mod' => $is_mod,
                'message' => 'Token is valid (cached)',
                'debug' => $debug
            ]);
            exit;
        }
        // Validate the token with Twitch
        $url = "https://id.twitch.tv/oauth2/validate";
        $headers = ["Authorization: OAuth $access_token"];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
if ($httpCode === 200) {
            $data = json_decode($response, true);
            $expires = isset($data['expires_in']) ? (int) $data['expires_in'] : 0;
            // Check mod status using the same token
            $bot_user_id = '971436498';
            $mod_url = "https://api.twitch.tv/helix/moderation/moderators?broadcaster_id={$twitch_user_id}&user_id={$bot_user_id}";
            $mod_headers = [
                "Authorization: Bearer {$access_token}",
                "Client-Id: {$clientID}"
            ];
            $mod_ch = curl_init();
            curl_setopt($mod_ch, CURLOPT_URL, $mod_url);
            curl_setopt($mod_ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($mod_ch, CURLOPT_HTTPHEADER, $mod_headers);
            $mod_response = curl_exec($mod_ch);
            $mod_httpCode = curl_getinfo($mod_ch, CURLINFO_HTTP_CODE);
$is_mod = false;
            $is_banned = false;
            $ban_reason = '';
            if ($mod_httpCode === 200) {
                $mod_data = json_decode($mod_response, true);
                $is_mod = !empty($mod_data['data']);
                // If NOT a mod, check if bot is banned
                if (!$is_mod) {
                    $ban_url = "https://api.twitch.tv/helix/moderation/banned?broadcaster_id={$twitch_user_id}&user_id={$bot_user_id}";
                    $ban_ch = curl_init();
                    curl_setopt($ban_ch, CURLOPT_URL, $ban_url);
                    curl_setopt($ban_ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ban_ch, CURLOPT_HTTPHEADER, $mod_headers);
                    $ban_response = curl_exec($ban_ch);
                    $ban_httpCode = curl_getinfo($ban_ch, CURLINFO_HTTP_CODE);
if ($ban_httpCode === 200) {
                        $ban_data = json_decode($ban_response, true);
                        if (!empty($ban_data['data'])) {
                            $is_banned = true;
                            $ban_reason = $ban_data['data'][0]['reason'] ?? 'No reason provided';
                        }
                    }
                }
            }
            // Update cache with expires_at and mod status
            if ($expires > 0) {
                $entry = [
                    'access_token' => $access_token,
                    'expires_at' => time() + $expires,
                    'is_mod' => $is_mod
                ];
                @setTokenCacheEntry($tokenCacheFile, $twitch_user_id, $entry);
            }
            $debug = ob_get_clean();
            echo json_encode([
                'success' => true,
                'valid' => true,
                'expires_in' => $data['expires_in'] ?? 0,
                'is_mod' => $is_mod,
                'is_banned' => $is_banned,
                'ban_reason' => $ban_reason,
                'message' => 'Token is valid',
                'debug' => $debug
            ]);
        } else {
            // Remove potentially stale cache entry
            @removeTokenCacheEntry($tokenCacheFile, $twitch_user_id);
            $debug = ob_get_clean();
            echo json_encode([
                'success' => true,
                'valid' => false,
                'message' => 'Token is invalid or expired',
                'debug' => $debug
            ]);
        }
    } catch (Exception $e) {
        $debug = ob_get_clean();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'debug' => $debug]);
    }
    exit;
}

// Handle AJAX request to renew a user's token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['renew_user_token'])) {
    // Clean any output that may have been generated
    ob_end_clean();
    header('Content-Type: application/json');
    try {
        // Clean any output that may have been generated and capture incidental output
        while (ob_get_level())
            ob_end_clean();
        ob_start();
        $twitch_user_id = $_POST['twitch_user_id'] ?? '';
        if (empty($twitch_user_id)) {
            echo json_encode(['success' => false, 'message' => 'Missing twitch_user_id']);
            exit;
        }
        // Fetch the user's refresh token from database
        $stmt = $conn->prepare("SELECT refresh_token FROM users WHERE twitch_user_id = ?");
        $stmt->bind_param("s", $twitch_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'No refresh token found for user']);
            exit;
        }
        $row = $result->fetch_assoc();
        $refresh_token = $row['refresh_token'];
        $stmt->close();
        // Renew the token with Twitch
        $url = "https://id.twitch.tv/oauth2/token";
        $postFields = http_build_query([
            'client_id' => $clientID,
            'client_secret' => $clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token
        ]);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($httpCode === 200) {
            $data = json_decode($response, true);
            $new_access_token = $data['access_token'];
            $new_refresh_token = $data['refresh_token'];
            // Update the database with new tokens
            $stmt = $conn->prepare("UPDATE users SET access_token = ?, refresh_token = ? WHERE twitch_user_id = ?");
            $stmt->bind_param("sss", $new_access_token, $new_refresh_token, $twitch_user_id);
            if ($stmt->execute()) {
                $stmt->close();
                // Update cache with new token expiry
                $expires = isset($data['expires_in']) ? (int) $data['expires_in'] : 0;
                if ($expires > 0) {
                    $entry = ['access_token' => $new_access_token, 'expires_at' => time() + $expires];
                    @setTokenCacheEntry($tokenCacheFile, $twitch_user_id, $entry);
                }
                $debug = ob_get_clean();
                echo json_encode([
                    'success' => true,
                    'message' => 'Token renewed successfully',
                    'expires_in' => $data['expires_in'] ?? 0,
                    'debug' => $debug
                ]);
            } else {
                // Log DB update failure for debugging (sent to client console)
                $dbErr = $stmt->error;
                client_console_log('Failed to update auth tokens for twitch_user_id=' . $twitch_user_id . ' stmt_error=' . $dbErr);
                $stmt->close();
                $debug = ob_get_clean();
                echo json_encode(['success' => false, 'message' => 'Failed to update database', 'debug' => $debug, 'error_details' => ['db_error' => $dbErr]]);
            }
        } else {
            // Log Twitch renewal failure for debugging
            $respSnippet = substr((string) $response, 0, 200);
            $debug = ob_get_clean();
            echo json_encode(['success' => false, 'message' => 'Failed to renew token with Twitch', 'debug' => $debug, 'error_details' => ['http_code' => $httpCode, 'curl_error' => $curlError, 'response' => $respSnippet]]);
        }
    } catch (Exception $e) {
        $debug = ob_get_clean();
        client_console_log('Exception in renew_user_token: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'debug' => $debug, 'error_details' => ['exception' => $e->getMessage()]]);
    }
    exit;
}

// Handle AJAX request to check if bot is a moderator
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_bot_mod_status'])) {
    // Clean any output that may have been generated
    while (ob_get_level())
        ob_end_clean();
    ob_start();
    header('Content-Type: application/json');
    try {
        $twitch_user_id = $_POST['twitch_user_id'] ?? '';
        if (empty($twitch_user_id)) {
            echo json_encode(['success' => false, 'message' => 'Missing twitch_user_id']);
            exit;
        }
        // Fetch the user's access token from database
        $stmt = $conn->prepare("SELECT access_token FROM users WHERE twitch_user_id = ?");
        $stmt->bind_param("s", $twitch_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'No token found for user']);
            exit;
        }
        $row = $result->fetch_assoc();
        $access_token = $row['access_token'];
        $stmt->close();
        // BotOfTheSpecter's Twitch user ID
        $bot_user_id = '971436498';
        // Check if bot is a moderator using Twitch API
        $url = "https://api.twitch.tv/helix/moderation/moderators?broadcaster_id={$twitch_user_id}&user_id={$bot_user_id}";
        $headers = [
            "Authorization: Bearer {$access_token}",
            "Client-Id: {$clientID}"
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
if ($httpCode === 200) {
            $data = json_decode($response, true);
            $isMod = !empty($data['data']);
            $isBanned = false;
            $banReason = '';
            // If bot is NOT a moderator, check if it's banned
            if (!$isMod) {
                $banCheckUrl = "https://api.twitch.tv/helix/moderation/banned?broadcaster_id={$twitch_user_id}&user_id={$bot_user_id}";
                $banCh = curl_init();
                curl_setopt($banCh, CURLOPT_URL, $banCheckUrl);
                curl_setopt($banCh, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($banCh, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer {$access_token}",
                    "Client-Id: {$clientID}"
                ]);
                $banResponse = curl_exec($banCh);
                $banHttpCode = curl_getinfo($banCh, CURLINFO_HTTP_CODE);
                $banCurlErr = curl_error($banCh);
if ($banHttpCode === 200 && !$banCurlErr) {
                    $banData = json_decode($banResponse, true);
                    if (!empty($banData['data'])) {
                        $isBanned = true;
                        $banReason = $banData['data'][0]['reason'] ?? 'No reason provided';
                    }
                }
            }
            $debug = ob_get_clean();
            echo json_encode([
                'success' => true,
                'is_mod' => $isMod,
                'is_banned' => $isBanned,
                'ban_reason' => $banReason,
                'message' => $isMod ? 'Bot is a moderator' : ($isBanned ? 'Bot is banned' : 'Bot is not a moderator')
            ]);
        } else {
            $debug = ob_get_clean();
            echo json_encode([
                'success' => false,
                'message' => "Failed to check mod status (HTTP {$httpCode})",
                'error' => $curlErr,
                'debug' => $debug
            ]);
        }
    } catch (Exception $e) {
        $debug = ob_get_clean();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'debug' => $debug]);
    }
    exit;
}

// Handle AJAX request to make bot a moderator
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['make_bot_mod'])) {
    // Clean any output that may have been generated
    while (ob_get_level())
        ob_end_clean();
    ob_start();
    header('Content-Type: application/json');
    try {
        $twitch_user_id = $_POST['twitch_user_id'] ?? '';
        if (empty($twitch_user_id)) {
            echo json_encode(['success' => false, 'message' => 'Missing twitch_user_id']);
            exit;
        }
        // Fetch the user's access token from database
        $stmt = $conn->prepare("SELECT access_token FROM users WHERE twitch_user_id = ?");
        $stmt->bind_param("s", $twitch_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'No token found for user']);
            exit;
        }
        $row = $result->fetch_assoc();
        $access_token = $row['access_token'];
        $stmt->close();
        // BotOfTheSpecter's Twitch user ID
        $bot_user_id = '971436498';
        // Make bot a moderator using Twitch API
        $url = "https://api.twitch.tv/helix/moderation/moderators?broadcaster_id={$twitch_user_id}&user_id={$bot_user_id}";
        $headers = [
            "Authorization: Bearer {$access_token}",
            "Client-Id: {$clientID}"
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
if ($httpCode === 204) {
            // Update cache to reflect new mod status
            $cacheEntry = getTokenCacheEntry($tokenCacheFile, $twitch_user_id);
            if ($cacheEntry) {
                $cacheEntry['is_mod'] = true;
                setTokenCacheEntry($tokenCacheFile, $twitch_user_id, $cacheEntry);
            }
            $debug = ob_get_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Bot successfully added as moderator',
                'debug' => $debug
            ]);
        } else {
            $debug = ob_get_clean();
            echo json_encode([
                'success' => false,
                'message' => "Failed to make bot a moderator (HTTP {$httpCode})",
                'error' => $curlErr,
                'response' => $response,
                'debug' => $debug
            ]);
        }
    } catch (Exception $e) {
        $debug = ob_get_clean();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'debug' => $debug]);
    }
    exit;
}

// Handle AJAX request to stop bot
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['stop_bot'])) {
    require_once __DIR__ . '/../includes/bot_control_functions.php';
    while (ob_get_level())
        ob_end_clean();
    ob_start();
    header('Content-Type: application/json');
    $username = trim($_POST['username'] ?? '');
    $pid = intval($_POST['pid'] ?? 0);
    $success = false;
    $message = '';
    if (empty($username)) {
        $message = t('admin_start_bots_err_username_required');
    } elseif ($pid <= 0) {
        $message = t('admin_start_bots_err_no_pid');
    } else {
        try {
            $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
            if ($connection) {
                SSHConnectionManager::executeCommand($connection, "kill -s kill $pid");
                $screenSession = 'specter_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $username);
                SSHConnectionManager::executeCommand($connection, 'screen -S ' . escapeshellarg($screenSession) . ' -X quit 2>/dev/null; true');
                SSHConnectionManager::executeCommand($connection, 'tmux kill-session -t ' . escapeshellarg($screenSession) . ' 2>/dev/null; true');
                $success = true;
                $message = t('admin_start_bots_msg_stop_success');
            } else {
                $message = t('admin_start_bots_err_connect');
            }
        } catch (Exception $e) {
            $message = t('admin_start_bots_err_stop_generic', [$e->getMessage()]);
        }
    }
    admin_audit_log(
        'start_bots_stop_bot',
        $success ? 'success' : 'failed',
        ['username' => $username, 'pid' => $pid, 'message' => $message],
        'username',
        $username
    );
    $debug = ob_get_clean();
    echo json_encode(['success' => $success, 'message' => $message, 'debug' => $debug]);
    exit;
}

// Handle AJAX request to restart bot
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['restart_bot'])) {
    require_once __DIR__ . '/../includes/bot_control_functions.php';
    while (ob_get_level())
        ob_end_clean();
    ob_start();
    header('Content-Type: application/json');
    $username = trim($_POST['username'] ?? '');
    $botType = trim($_POST['bot_type'] ?? 'stable');
    $pid = intval($_POST['pid'] ?? 0);
    // Log the restart attempt (send to client console)
    client_console_log("Bot restart request - Username: {$username}, Bot Type: {$botType}, PID: {$pid}");
    $success = false;
    $message = '';
    $restartedPid = 0;
    if (empty($username)) {
        $message = t('admin_start_bots_err_username_required');
    } else {
        try {
            // Get user data including refresh_token and api_key from users table
            $stmt = $conn->prepare("SELECT id, twitch_user_id, refresh_token, api_key, use_custom, use_self FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $userData = $result->fetch_assoc();
                $userId = trim((string) ($userData['id'] ?? ''));
                $twitchUserId = $userData['twitch_user_id'];
                $refreshToken = $userData['refresh_token'];
                $apiKey = $userData['api_key'];
                $useCustom = ((int) ($userData['use_custom'] ?? 0)) === 1;
                $useSelf = ((int) ($userData['use_self'] ?? 0)) === 1;
                // Get bot access token from twitch_bot_access table
                $stmt2 = $conn->prepare("SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = ?");
                $stmt2->bind_param("s", $twitchUserId);
                $stmt2->execute();
                $tokenResult = $stmt2->get_result();
                if ($tokenResult->num_rows > 0) {
                    $tokenData = $tokenResult->fetch_assoc();
                    $botAccessToken = $tokenData['twitch_access_token'];
                    client_console_log("RESTART DEBUG - About to restart: Username={$username}, BotType={$botType}, PID={$pid}");
                    // Step 1: Stop the bot if it's running
                    if ($pid > 0) {
                        client_console_log("RESTART DEBUG - Stopping PID {$pid} (should be {$botType} bot)");
                        try {
                            $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
                            if ($connection) {
                                SSHConnectionManager::executeCommand($connection, "kill -s kill $pid");
                                client_console_log("RESTART DEBUG - Kill command sent for PID {$pid}");
                                // Clean up screen session (and any leftover tmux session from before migration)
                                $restartScreenSession = 'specter_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $username);
                                SSHConnectionManager::executeCommand($connection, 'screen -S ' . escapeshellarg($restartScreenSession) . ' -X quit 2>/dev/null; true');
                                SSHConnectionManager::executeCommand($connection, 'tmux kill-session -t ' . escapeshellarg($restartScreenSession) . ' 2>/dev/null; true');
                                // Give it a moment to stop
                                sleep(1);
                            }
                        } catch (Exception $e) {
                            client_console_log("Error stopping bot during restart: " . $e->getMessage());
                        }
                    }
                    // Step 2: Start the bot with correct tokens
                    $params = [
                        'username' => $username,
                        'twitch_user_id' => $twitchUserId,
                        'auth_token' => $botAccessToken,  // Bot token from twitch_bot_access
                        'refresh_token' => $refreshToken,  // Refresh token from users table
                        'api_key' => $apiKey
                    ];
                    $actionBotType = ($botType === 'custom') ? 'beta' : $botType;
                    if ($actionBotType === 'beta') {
                        $effectiveUseCustom = ($botType === 'custom') ? true : $useCustom;
                        $effectiveUseSelf = ($botType === 'custom') ? false : $useSelf;
                        $channelLookupId = !empty($userId) ? $userId : $twitchUserId;
                        $betaModeParams = get_admin_beta_mode_params($conn, $channelLookupId, $effectiveUseCustom, $effectiveUseSelf, $twitchUserId);
                        if ($botType === 'custom' && empty($betaModeParams['use_custom_bot'])) {
                            $message = t('admin_start_bots_err_custom_not_verified');
                            $success = false;
                            $stmt2->close();
                            $stmt->close();
                            $debug = ob_get_clean();
                            echo json_encode(['success' => false, 'message' => $message, 'debug' => $debug]);
                            exit;
                        }
                        $params = array_merge($params, $betaModeParams);
                    }
                    client_console_log("RESTART DEBUG - Calling performBotAction('run', '{$actionBotType}', ...) for {$username}");
                    $result = performBotAction('run', $actionBotType, $params);
                    client_console_log("RESTART DEBUG - performBotAction result: " . json_encode($result));
                    $success = $result['success'];
                    $restartedPid = is_array($result) ? (int) ($result['pid'] ?? 0) : 0;
                    // Clarify which bot type was started
                    $message = $result['message'] . t('admin_start_bots_msg_version_suffix', [ucfirst($botType)]);
                } else {
                    $message = t('admin_start_bots_err_bot_token_not_found');
                }
                $stmt2->close();
            } else {
                $message = t('admin_start_bots_err_user_not_found');
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = t('admin_start_bots_err_restart_generic', [$e->getMessage()]);
            client_console_log("Bot restart error: " . $e->getMessage());
        }
    }
    admin_audit_log(
        'start_bots_restart_bot',
        $success ? 'success' : 'failed',
        [
            'username' => $username,
            'bot_type' => $botType,
            'pid' => $pid,
            'message' => $message
        ],
        'username',
        $username
    );
    $debug = ob_get_clean();
    echo json_encode(['success' => $success, 'message' => $message, 'pid' => $restartedPid, 'bot_type' => $botType, 'debug' => $debug]);
    exit;
}

// Handle AJAX request to start bot for user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_user_bot'])) {
    // Clean any output that may have been generated and start a fresh buffer
    while (ob_get_level())
        ob_end_clean();
    ob_start();
    header('Content-Type: application/json');
    require_once __DIR__ . '/../includes/bot_control_functions.php';
    $username = trim($_POST['username'] ?? '');
    $botType = trim($_POST['bot_type'] ?? 'stable');
    if (empty($username)) {
        echo json_encode(['success' => false, 'message' => t('admin_start_bots_err_username_required')]);
        exit;
    }
    try {
        // Start the bot for the user while capturing incidental output
        $handledShutdown = false;
        // Ensure a fresh buffer to capture any HTML/error output produced during bot startup
        while (ob_get_level())
            ob_end_clean();
        ob_start();
        register_shutdown_function(function () use (&$handledShutdown) {
            $err = error_get_last();
            if ($err && !$handledShutdown) {
                $debug = '';
                if (ob_get_level())
                    $debug = ob_get_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Fatal error during bot start', 'debug' => $debug, 'error_details' => ['shutdown_error' => $err]]);
                exit;
            }
        });
        try {
            $result = start_bot_for_user($username, $botType);
            $debug = '';
            if (ob_get_level())
                $debug = ob_get_clean();
            $handledShutdown = true;
            if (is_array($result)) {
                $ok = $result['success'] ?? false;
                $msg = $result['message'] ?? '';
                $pid = $result['pid'] ?? null;
                admin_audit_log(
                    'start_bots_start_user_bot',
                    $ok ? 'success' : 'failed',
                    ['username' => $username, 'bot_type' => $botType, 'pid' => $pid, 'message' => $msg],
                    'username',
                    $username
                );
                echo json_encode(['success' => $ok, 'message' => $msg, 'pid' => $pid, 'debug' => $debug, 'details' => $result]);
            } elseif ($result === true) {
                admin_audit_log(
                    'start_bots_start_user_bot',
                    'success',
                    ['username' => $username, 'bot_type' => $botType, 'message' => 'Bot started successfully'],
                    'username',
                    $username
                );
                echo json_encode(['success' => true, 'message' => t('admin_start_bots_msg_start_success'), 'debug' => $debug]);
            } else {
                admin_audit_log(
                    'start_bots_start_user_bot',
                    'failed',
                    ['username' => $username, 'bot_type' => $botType, 'message' => (string) $result],
                    'username',
                    $username
                );
                echo json_encode(['success' => false, 'message' => $result, 'debug' => $debug]);
            }
        } catch (Throwable $e) {
            $debug = '';
            if (ob_get_level())
                $debug = ob_get_clean();
            $handledShutdown = true;
            admin_audit_log(
                'start_bots_start_user_bot',
                'failed',
                ['username' => $username, 'bot_type' => $botType, 'error' => $e->getMessage()],
                'username',
                $username
            );
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'debug' => $debug, 'error_details' => ['exception' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]]);
        }
    } catch (Exception $e) {
        $debug = ob_get_clean();
        admin_audit_log(
            'start_bots_start_user_bot',
            'failed',
            ['username' => $username, 'bot_type' => $botType, 'error' => $e->getMessage()],
            'username',
            $username
        );
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'debug' => $debug]);
    }
    exit;
}

// Include userdata.php AFTER all AJAX handling to prevent output corruption
include '../includes/userdata.php';
session_write_close();

// Fetch all users from database
$users = [];
$stmt = $conn->prepare("SELECT u.id, u.username, u.twitch_user_id, u.twitch_display_name, u.profile_image, u.use_custom, CASE WHEN EXISTS (SELECT 1 FROM custom_bots cb WHERE cb.channel_id = CAST(u.id AS CHAR) AND cb.is_verified = 1 AND COALESCE(cb.bot_username, '') <> '') OR EXISTS (SELECT 1 FROM custom_bots cb WHERE cb.channel_id = u.twitch_user_id AND cb.is_verified = 1 AND COALESCE(cb.bot_username, '') <> '') THEN 1 ELSE 0 END AS custom_bot_enabled FROM users u ORDER BY u.id");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();

ob_start();
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<div class="sp-card">
    <div class="sp-card-body">
    <h1 style="font-size:1.25rem;font-weight:700;margin-bottom:0.75rem;"><span class="icon"><i class="fas fa-play-circle"></i></span> <?php echo t('admin_start_bots_page_title'); ?></h1>
    <p class="mb-4"><?php echo t('admin_start_bots_intro'); ?></p>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:0.5rem;">
        <div class="sp-btn-group">
            <button class="sp-btn sp-btn-info" onclick="refreshBotStatus()">
                <span class="icon"><i class="fas fa-sync-alt"></i></span>
                <span><?php echo t('admin_start_bots_btn_refresh_all'); ?></span>
            </button>
            <button class="sp-btn sp-btn-primary" onclick="refreshRunningStatus()">
                <span class="icon"><i class="fas fa-tasks"></i></span>
                <span><?php echo t('admin_start_bots_btn_refresh_status'); ?></span>
            </button>
            <button class="sp-btn sp-btn-info" onclick="refreshTokenStatus()">
                <span class="icon"><i class="fas fa-key"></i></span>
                <span><?php echo t('admin_start_bots_btn_refresh_token_status'); ?></span>
            </button>
            <button class="sp-btn" onclick="refreshModStatus()">
                <span class="icon"><i class="fas fa-user-shield"></i></span>
                <span><?php echo t('admin_start_bots_btn_refresh_mod_status'); ?></span>
            </button>
            <button class="sp-btn sp-btn-warning" id="restart-all-btn" onclick="restartAllBots()" disabled>
                <span class="icon"><i class="fas fa-redo-alt"></i></span>
                <span><?php echo t('admin_start_bots_btn_restart_all'); ?></span>
            </button>
        </div>
        <div class="sp-form-group" style="margin:0;">
            <div style="position:relative;">
                <span class="icon is-left" style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);pointer-events:none;"><i class="fas fa-search"></i></span>
                <input class="sp-input" style="padding-left:2.25rem;" type="text" id="user-search" placeholder="<?php echo htmlspecialchars(t('admin_start_bots_search_placeholder'), ENT_QUOTES); ?>">
            </div>
        </div>
    </div>
    <div class="sp-table-wrap">
        <table class="sp-table start-bots-table">
            <thead>
                <tr>
                    <th class="col-user"><?php echo t('admin_start_bots_th_user'); ?></th>
                    <th class="col-twitch-id"><?php echo t('admin_start_bots_th_twitch_id'); ?></th>
                    <th class="col-status"><?php echo t('admin_start_bots_th_bot_status'); ?></th>
                    <th class="col-type"><?php echo t('admin_start_bots_th_bot_type'); ?></th>
                    <th class="col-uptime"><?php echo t('admin_start_bots_th_running_for'); ?></th>
                    <th class="col-token"><?php echo t('admin_start_bots_th_token_status'); ?></th>
                    <th class="col-mod"><?php echo t('admin_start_bots_th_mod_status'); ?></th>
                    <th class="col-actions"><?php echo t('admin_start_bots_th_actions'); ?></th>
                </tr>
            </thead>
            <tbody id="users-table-body">
                <?php foreach ($users as $user): ?>
                    <?php $customBotEnabled = ((int) ($user['custom_bot_enabled'] ?? 0)) === 1; ?>
                    <tr data-username="<?php echo htmlspecialchars($user['username']); ?>"
                        data-twitch-id="<?php echo htmlspecialchars($user['twitch_user_id']); ?>"
                        data-custom-enabled="<?php echo $customBotEnabled ? '1' : '0'; ?>">
                        <td class="col-user">
                            <div style="display:flex;align-items:center;">
                                <img src="<?php echo htmlspecialchars($user['profile_image']); ?>"
                                    alt="<?php echo htmlspecialchars($user['username']); ?>"
                                    class="admin-bot-avatar" style="margin-right:0.5rem;">
                                <span><?php echo htmlspecialchars($user['twitch_display_name'] ?: $user['username']); ?></span>
                            </div>
                        </td>
                        <td class="col-twitch-id"><?php echo htmlspecialchars($user['twitch_user_id']); ?></td>
                        <td class="col-status">
                            <span class="sp-badge bot-status-tag"
                                data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                <span class="icon"><i class="fas fa-spinner fa-pulse"></i></span>
                                <span><?php echo t('admin_start_bots_status_checking'); ?></span>
                            </span>
                        </td>
                        <td class="col-type">
                            <span class="sp-badge sp-badge-amber bot-type-tag">
                                <span><?php echo t('admin_start_bots_label_unknown'); ?></span>
                            </span>
                        </td>
                        <td class="col-uptime">
                            <span class="sp-badge sp-badge-grey running-time-tag">
                                <span>-</span>
                            </span>
                        </td>
                        <td class="col-token">
                            <span class="sp-badge token-status-tag"
                                data-twitch-id="<?php echo htmlspecialchars($user['twitch_user_id']); ?>">
                                <span class="icon"><i class="fas fa-question"></i></span>
                                <span><?php echo t('admin_start_bots_label_unknown'); ?></span>
                            </span>
                        </td>
                        <td class="col-mod">
                            <span class="sp-badge mod-status-tag">
                                <span class="icon"><i class="fas fa-question"></i></span>
                                <span><?php echo t('admin_start_bots_label_unknown'); ?></span>
                            </span>
                        </td>
                        <td class="col-actions">
                            <div class="sp-btn-group">
                                <button class="sp-btn sp-btn-warning make-mod-btn"
                                    onclick="makeBotMod('<?php echo htmlspecialchars($user['twitch_user_id']); ?>')"
                                    style="display: none;" title="<?php echo htmlspecialchars(t('admin_start_bots_btn_make_mod_title'), ENT_QUOTES); ?>">
                                    <span class="icon"><i class="fas fa-user-shield"></i></span>
                                    <span class="btn-label"><?php echo t('admin_start_bots_btn_make_mod'); ?></span>
                                </button>
                                <button class="sp-btn sp-btn-success start-stable-btn"
                                    onclick="startUserBot('<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['twitch_user_id']); ?>', 'stable')"
                                    disabled>
                                    <span class="icon"><i class="fas fa-play"></i></span>
                                    <span class="btn-label"><?php echo t('admin_start_bots_btn_start_stable'); ?></span>
                                </button>
                                <button class="sp-btn sp-btn-info start-beta-btn"
                                    onclick="startUserBot('<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['twitch_user_id']); ?>', 'beta')"
                                    disabled>
                                    <span class="icon"><i class="fas fa-flask"></i></span>
                                    <span class="btn-label"><?php echo t('admin_start_bots_btn_start_beta'); ?></span>
                                </button>
                                <button class="sp-btn sp-btn-primary start-custom-btn"
                                    onclick="startUserBot('<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['twitch_user_id']); ?>', 'custom')"
                                    <?php echo $customBotEnabled ? '' : 'disabled'; ?> title="<?php echo htmlspecialchars($customBotEnabled ? t('admin_start_bots_btn_start_custom_title_enabled') : t('admin_start_bots_btn_start_custom_title_disabled'), ENT_QUOTES); ?>">
                                    <span class="icon"><i class="fas fa-user-astronaut"></i></span>
                                    <span class="btn-label"><?php echo t('admin_start_bots_btn_start_custom'); ?></span>
                                </button>
                                <button class="sp-btn sp-btn-warning restart-bot-btn"
                                    onclick="restartBot('<?php echo htmlspecialchars($user['username']); ?>', 'stable', 0, this)"
                                    style="display: none;" disabled>
                                    <span class="icon"><i class="fas fa-sync-alt"></i></span>
                                    <span class="btn-label"><?php echo t('admin_start_bots_btn_restart'); ?></span>
                                </button>
                                <button class="sp-btn sp-btn-info switch-bot-btn"
                                    onclick="switchBotType('<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['twitch_user_id']); ?>', 'beta')"
                                    style="display: none;" disabled>
                                    <span class="icon"><i class="fas fa-exchange-alt"></i></span>
                                    <span class="btn-label"><?php echo t('admin_start_bots_btn_switch_beta'); ?></span>
                                </button>
                                <button class="sp-btn sp-btn-primary switch-custom-btn"
                                    onclick="switchBotType('<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['twitch_user_id']); ?>', 'custom')"
                                    style="display: none;" disabled>
                                    <span class="icon"><i class="fas fa-user-astronaut"></i></span>
                                    <span class="btn-label"><?php echo t('admin_start_bots_btn_switch_custom'); ?></span>
                                </button>
                                <button class="sp-btn sp-btn-danger stop-bot-btn"
                                    onclick="stopBot('<?php echo htmlspecialchars($user['username']); ?>', 0, this)"
                                    style="display: none;" disabled>
                                    <span class="icon"><i class="fas fa-stop"></i></span>
                                    <span class="btn-label"><?php echo t('admin_start_bots_btn_stop'); ?></span>
                                </button>
                                <button class="sp-btn sp-btn-dark attach-console-btn"
                                    onclick="attachConsole('<?php echo htmlspecialchars($user['username']); ?>')"
                                    style="display: none;" disabled title="<?php echo htmlspecialchars(t('admin_start_bots_btn_attach_console_title'), ENT_QUOTES); ?>">
                                    <span class="icon"><i class="fas fa-terminal"></i></span>
                                    <span><?php echo t('admin_start_bots_btn_attach_console'); ?></span>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div>
</div>
<!-- Bot Console Viewer Modal -->
<div id="console-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.78);z-index:9999;justify-content:center;align-items:center;padding:1rem;" onclick="consoleModalBackdropClick(event)">
    <div style="background:var(--bg-card,#1a1a20);border-radius:var(--radius,8px);width:100%;max-width:960px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.6);" onclick="event.stopPropagation()">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:0.75rem 1rem;border-bottom:1px solid rgba(255,255,255,0.1);flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:0.75rem;">
                <span class="icon"><i class="fas fa-terminal"></i></span>
                <span id="console-modal-title" style="font-weight:700;"><?php echo t('admin_start_bots_console_title'); ?></span>
                <span id="console-modal-status" class="sp-badge sp-badge-blue"><?php echo t('admin_start_bots_console_connecting'); ?></span>
            </div>
            <button class="sp-btn sp-btn-danger" onclick="closeConsoleModal()" title="<?php echo htmlspecialchars(t('admin_start_bots_console_close_title'), ENT_QUOTES); ?>">
                <span class="icon"><i class="fas fa-times"></i></span>
                <span><?php echo t('admin_start_bots_console_close'); ?></span>
            </button>
        </div>
        <pre id="console-modal-output" style="background:var(--bg-input);color:var(--text-primary);font-family:'Courier New',monospace;flex:1;overflow-y:auto;white-space:pre-wrap;word-break:break-all;padding:1rem;margin:0;min-height:420px;max-height:calc(90vh - 60px);"></pre>
    </div>
</div>
<script>
    const COMPACT_BREAKPOINT = 1600;

    const SB_I18N = {
        unknown: <?php echo json_encode(t('admin_start_bots_label_unknown')); ?>,
        checking: <?php echo json_encode(t('admin_start_bots_status_checking')); ?>,
        notRunning: <?php echo json_encode(t('admin_start_bots_status_not_running')); ?>,
        botNotRunning: <?php echo json_encode(t('admin_start_bots_status_bot_not_running')); ?>,
        running: <?php echo json_encode(t('admin_start_bots_status_running')); ?>,
        runningPid: <?php echo json_encode(t('admin_start_bots_status_running_pid')); ?>,
        typeBeta: <?php echo json_encode(t('admin_start_bots_type_beta')); ?>,
        typeStable: <?php echo json_encode(t('admin_start_bots_type_stable')); ?>,
        typeCustom: <?php echo json_encode(t('admin_start_bots_type_custom')); ?>,
        typeV6: <?php echo json_encode(t('admin_start_bots_type_v6')); ?>,
        switchToStable: <?php echo json_encode(t('admin_start_bots_switch_to_stable')); ?>,
        switchToBeta: <?php echo json_encode(t('admin_start_bots_switch_to_beta')); ?>,
        switchToCustom: <?php echo json_encode(t('admin_start_bots_switch_to_custom')); ?>,
        refreshingAll: <?php echo json_encode(t('admin_start_bots_toast_refreshing_all')); ?>,
        refreshingStatus: <?php echo json_encode(t('admin_start_bots_toast_refreshing_status')); ?>,
        statusRefreshed: <?php echo json_encode(t('admin_start_bots_toast_status_refreshed')); ?>,
        refreshingTokens: <?php echo json_encode(t('admin_start_bots_toast_refreshing_tokens')); ?>,
        tokenRefreshComplete: <?php echo json_encode(t('admin_start_bots_toast_token_refresh_complete')); ?>,
        refreshingMods: <?php echo json_encode(t('admin_start_bots_toast_refreshing_mods')); ?>,
        modRefreshComplete: <?php echo json_encode(t('admin_start_bots_toast_mod_refresh_complete')); ?>,
        validating: <?php echo json_encode(t('admin_start_bots_token_validating')); ?>,
        valid: <?php echo json_encode(t('admin_start_bots_token_valid')); ?>,
        renewing: <?php echo json_encode(t('admin_start_bots_token_renewing')); ?>,
        renewed: <?php echo json_encode(t('admin_start_bots_token_renewed')); ?>,
        renewedHours: <?php echo json_encode(t('admin_start_bots_token_renewed_hours')); ?>,
        invalidAttemptRenew: <?php echo json_encode(t('admin_start_bots_token_invalid_renewing')); ?>,
        renewalFailed: <?php echo json_encode(t('admin_start_bots_token_renewal_failed')); ?>,
        error: <?php echo json_encode(t('admin_start_bots_error')); ?>,
        skipped: <?php echo json_encode(t('admin_start_bots_mod_skipped')); ?>,
        isModerator: <?php echo json_encode(t('admin_start_bots_mod_is_moderator')); ?>,
        moderator: <?php echo json_encode(t('admin_start_bots_mod_moderator')); ?>,
        banned: <?php echo json_encode(t('admin_start_bots_mod_banned')); ?>,
        notAModerator: <?php echo json_encode(t('admin_start_bots_mod_not_a_moderator')); ?>,
        notMod: <?php echo json_encode(t('admin_start_bots_mod_not_mod')); ?>,
        runningWithoutMod: <?php echo json_encode(t('admin_start_bots_mod_running_without_mod')); ?>,
        addingAsMod: <?php echo json_encode(t('admin_start_bots_mod_adding')); ?>,
        checkFailed: <?php echo json_encode(t('admin_start_bots_mod_check_failed')); ?>,
        reasonPrefix: <?php echo json_encode(t('admin_start_bots_mod_reason_prefix')); ?>,
        noReasonProvided: <?php echo json_encode(t('admin_start_bots_mod_no_reason')); ?>,
        makeModSuccessTitle: <?php echo json_encode(t('admin_start_bots_make_mod_success_title')); ?>,
        makeModSuccessText: <?php echo json_encode(t('admin_start_bots_make_mod_success_text')); ?>,
        failed: <?php echo json_encode(t('admin_start_bots_failed')); ?>,
        makeModFailedTitle: <?php echo json_encode(t('admin_start_bots_make_mod_failed_title')); ?>,
        makeModFailedHtml: <?php echo json_encode(t('admin_start_bots_make_mod_failed_html')); ?>,
        unknownError: <?php echo json_encode(t('admin_start_bots_unknown_error')); ?>,
        makeModErrorText: <?php echo json_encode(t('admin_start_bots_make_mod_error_text')); ?>,
        okBtn: <?php echo json_encode(t('admin_start_bots_btn_ok')); ?>,
        cancelBtn: <?php echo json_encode(t('admin_start_bots_btn_cancel')); ?>,
        tokenRenewalFailedTitle: <?php echo json_encode(t('admin_start_bots_token_renewal_failed_title')); ?>,
        tokenRenewalFailedText: <?php echo json_encode(t('admin_start_bots_token_renewal_failed_text')); ?>,
        tokenRenewalErrorTitle: <?php echo json_encode(t('admin_start_bots_token_renewal_error_title')); ?>,
        tokenRenewalErrorText: <?php echo json_encode(t('admin_start_bots_token_renewal_error_text')); ?>,
        botAlreadyRunning: <?php echo json_encode(t('admin_start_bots_toast_already_running')); ?>,
        switchTypeTitle: <?php echo json_encode(t('admin_start_bots_switch_title')); ?>,
        switchTypeTextStartStop: <?php echo json_encode(t('admin_start_bots_switch_text_stop_start')); ?>,
        switchTypeTextTarget: <?php echo json_encode(t('admin_start_bots_switch_text_target')); ?>,
        switchConfirmBtn: <?php echo json_encode(t('admin_start_bots_switch_confirm')); ?>,
        switchConfirmTargetBtn: <?php echo json_encode(t('admin_start_bots_switch_confirm_target')); ?>,
        customNotEnabledToast: <?php echo json_encode(t('admin_start_bots_toast_custom_not_enabled')); ?>,
        startingType: <?php echo json_encode(t('admin_start_bots_status_starting')); ?>,
        startedTypeToast: <?php echo json_encode(t('admin_start_bots_toast_started')); ?>,
        startFailed: <?php echo json_encode(t('admin_start_bots_status_start_failed')); ?>,
        couldNotStart: <?php echo json_encode(t('admin_start_bots_could_not_start')); ?>,
        startFailedToast: <?php echo json_encode(t('admin_start_bots_toast_start_failed')); ?>,
        errorStartingToast: <?php echo json_encode(t('admin_start_bots_toast_error_starting')); ?>,
        consoleTitlePrefix: <?php echo json_encode(t('admin_start_bots_console_title_prefix')); ?>,
        connecting: <?php echo json_encode(t('admin_start_bots_console_connecting')); ?>,
        live: <?php echo json_encode(t('admin_start_bots_console_live')); ?>,
        streamEnded: <?php echo json_encode(t('admin_start_bots_console_stream_ended')); ?>,
        streamDisconnected: <?php echo json_encode(t('admin_start_bots_console_stream_disconnected')); ?>,
        restartConfirmTitle: <?php echo json_encode(t('admin_start_bots_restart_confirm_title')); ?>,
        restartConfirmText: <?php echo json_encode(t('admin_start_bots_restart_confirm_text')); ?>,
        restartConfirmBtn: <?php echo json_encode(t('admin_start_bots_restart_confirm_btn')); ?>,
        restartingToast: <?php echo json_encode(t('admin_start_bots_toast_restarting')); ?>,
        restartSuccessDefault: <?php echo json_encode(t('admin_start_bots_restart_success_default')); ?>,
        restartRefreshHint: <?php echo json_encode(t('admin_start_bots_restart_refresh_hint')); ?>,
        restartFailedDefault: <?php echo json_encode(t('admin_start_bots_restart_failed_default')); ?>,
        networkErrorRestart: <?php echo json_encode(t('admin_start_bots_network_error_restart')); ?>,
        stopConfirmTitle: <?php echo json_encode(t('admin_start_bots_stop_confirm_title')); ?>,
        stopConfirmText: <?php echo json_encode(t('admin_start_bots_stop_confirm_text')); ?>,
        stopConfirmBtn: <?php echo json_encode(t('admin_start_bots_stop_confirm_btn')); ?>,
        stoppingToast: <?php echo json_encode(t('admin_start_bots_toast_stopping')); ?>,
        stopSuccess: <?php echo json_encode(t('admin_start_bots_stop_success')); ?>,
        stopRefreshHint: <?php echo json_encode(t('admin_start_bots_stop_refresh_hint')); ?>,
        stopFailedDefault: <?php echo json_encode(t('admin_start_bots_stop_failed_default')); ?>,
        networkErrorStop: <?php echo json_encode(t('admin_start_bots_network_error_stop')); ?>,
        noRunningBots: <?php echo json_encode(t('admin_start_bots_toast_no_running')); ?>,
        restartAllTitle: <?php echo json_encode(t('admin_start_bots_restart_all_title')); ?>,
        restartAllText: <?php echo json_encode(t('admin_start_bots_restart_all_text')); ?>,
        restartAllConfirmBtn: <?php echo json_encode(t('admin_start_bots_restart_all_confirm')); ?>,
        restartAllStarting: <?php echo json_encode(t('admin_start_bots_restart_all_starting')); ?>,
        restartingBotProgress: <?php echo json_encode(t('admin_start_bots_restart_all_progress')); ?>,
        allRestartedTitle: <?php echo json_encode(t('admin_start_bots_restart_all_done_title')); ?>,
        allRestartedHtml: <?php echo json_encode(t('admin_start_bots_restart_all_done_html')); ?>,
        restartIssuesTitle: <?php echo json_encode(t('admin_start_bots_restart_all_issues_title')); ?>,
        restartIssuesSuccess: <?php echo json_encode(t('admin_start_bots_restart_all_issues_success')); ?>,
        restartIssuesFailed: <?php echo json_encode(t('admin_start_bots_restart_all_issues_failed')); ?>
    };
    let runningBots = [];
    let refreshTimer = null; // Debounce timer for refresh operations
    function hasCustomBotEnabled(row) {
        return row && row.getAttribute('data-custom-enabled') === '1';
    }
    // Helper to get current search term and match rows against it
    function getSearchTerm() {
        const input = document.getElementById('user-search');
        return input ? input.value.toLowerCase().trim() : '';
    }
    function matchesSearch(row) {
        const term = getSearchTerm();
        if (!term) return true; // no filter -> match
        const username = row.querySelector('td:first-child')?.textContent.toLowerCase() || '';
        const twitchId = row.getAttribute('data-twitch-id')?.toLowerCase() || '';
        const displayName = row.querySelector('.has-text-grey')?.textContent.toLowerCase() || '';
        return username.includes(term) || displayName.includes(term) || twitchId.includes(term);
    }
    // Helper to escape HTML for safe display in alerts
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function formatUptime(seconds) {
        if (!Number.isFinite(seconds) || seconds < 0) return SB_I18N.unknown;
        const total = Math.floor(seconds);
        if (total < 60) return `${total}s`;
        const days = Math.floor(total / 86400);
        const hours = Math.floor((total % 86400) / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        if (days > 0) return `${days}d ${hours}h ${minutes}m`;
        if (hours > 0) return `${hours}h ${minutes}m`;
        return `${minutes}m`;
    }
    document.addEventListener('DOMContentLoaded', function () {
        // Load running bots status on page load
        refreshBotStatus();
        // Initialize search functionality
        const searchInput = document.getElementById('user-search');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                const rows = document.querySelectorAll('#users-table-body tr');
                rows.forEach(row => {
                    row.style.display = matchesSearch(row) ? '' : 'none';
                });
            });
        }
        // On smaller screens, simplify action button labels (keep full on desktop)
        // (see COMPACT_BREAKPOINT)
        applyCompactActionLabels();
        applyCompactTableColumns();

        // Re-apply on resize so columns and labels react live
        window.addEventListener('resize', function () {
            applyCompactActionLabels();
            applyCompactTableColumns();
        });
    });

    function applyCompactActionLabels() {
        if (window.innerWidth > COMPACT_BREAKPOINT) return;
        const rows = document.querySelectorAll('#users-table-body tr');
        rows.forEach(row => {
            row.querySelectorAll('.col-actions .sp-btn .btn-label').forEach(label => {
                let txt = label.textContent.trim();
                // Turn the buttons into the simple versions requested for small screens
                txt = txt.replace(/^Start\s+/i, '');           // "Stable", "Beta", "Custom"
                txt = txt.replace(/^Switch to\s+/i, '→ ');    // "→ Stable"
                if (txt.length > 12) {
                    const parts = txt.split(/\s+/);
                    if (parts.length > 1) txt = parts[parts.length - 1];
                }
                // Restart and Stop stay as-is (already short)
                // Make Mod is important - keep full text
                if (/^make mod$/i.test(txt)) {
                    txt = 'Make Mod';
                }
                label.textContent = txt;
            });
        });
    }

    function setCompactButtonLabel(btn, fullText) {
        const labelSpan = btn.querySelector('.btn-label') || btn.querySelector('span:last-child');
        if (!labelSpan) {
            btn.querySelector('span:last-child').textContent = fullText; // fallback
            return;
        }
        if (window.innerWidth > COMPACT_BREAKPOINT) {
            labelSpan.textContent = fullText;
            return;
        }
        let txt = String(fullText || '').trim();
        // Language-friendly shortening for small screens: keep the distinctive last part
        txt = txt.replace(/^Start\s+/i, '');
        txt = txt.replace(/^Switch to\s+/i, '→ ');
        // If still long (localized), keep only the last word for the type/target
        if (txt.length > 12) {
            const parts = txt.split(/\s+/);
            if (parts.length > 1) txt = parts[parts.length - 1];
        }
        // Make Mod is important - keep full text
        if (/^make mod$/i.test(txt)) {
            txt = 'Make Mod';
        }
        labelSpan.textContent = txt;
    }

    function applyCompactTableColumns() {
        // Force-hide unwanted columns on "smaller" screens via JS as reliable backup
        // (in case CSS media query doesn't trigger due to viewport, zoom, device pixel ratio, etc.)
        const table = document.querySelector('table.start-bots-table');
        if (!table) return;
        const colsToHide = ['col-twitch-id', 'col-type', 'col-uptime', 'col-token', 'col-mod'];
        const isCompact = window.innerWidth <= COMPACT_BREAKPOINT;
        colsToHide.forEach(function(cls) {
            table.querySelectorAll('th.' + cls + ', td.' + cls).forEach(function(el) {
                el.style.display = isCompact ? 'none' : '';
            });
        });
    }

    // Debounced refresh - delays the actual refresh to avoid multiple rapid calls
    function scheduleRefresh(delayMs = 2000) {
        // Clear any pending refresh
        if (refreshTimer) {
            clearTimeout(refreshTimer);
        }
        // Schedule a new refresh
        refreshTimer = setTimeout(() => {
            refreshBotStatus();
        }, delayMs);
    }
    // Simple %s placeholder substitution for injected translation strings
    function sbFormat(str) {
        const args = Array.prototype.slice.call(arguments, 1);
        let i = 0;
        return String(str).replace(/%s/g, () => (i < args.length ? args[i++] : '%s'));
    }
    function refreshBotStatus() {
        // Show toast notification
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'info',
            title: SB_I18N.refreshingAll,
            showConfirmButton: false,
            timer: 2000
        });
        fetch('?get_running_bots=1')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    runningBots = data.bots;
                    // Enable/disable Restart All button based on running bots count
                    const restartAllBtn = document.getElementById('restart-all-btn');
                    if (restartAllBtn) {
                        restartAllBtn.disabled = runningBots.length === 0;
                    }
                    // Process all users and validate tokens
                    const rows = Array.from(document.querySelectorAll('#users-table-body tr'));
                    let validateDelay = 0;
                    rows.forEach(row => {
                        const uname = row.getAttribute('data-username');
                        const twitchId = row.getAttribute('data-twitch-id');
                        const isRunning = runningBots.find(b => b.username === uname);
                        const botTag = row.querySelector('.bot-status-tag');
                        const botTypeTag = row.querySelector('.bot-type-tag');
                        const startStableBtn = row.querySelector('.start-stable-btn');
                        const startBetaBtn = row.querySelector('.start-beta-btn');
                        const startCustomBtn = row.querySelector('.start-custom-btn');
                        const restartBtn = row.querySelector('.restart-bot-btn');
                        const stopBotBtn = row.querySelector('.stop-bot-btn');
                        const switchBtn = row.querySelector('.switch-bot-btn');
                        const switchCustomBtn = row.querySelector('.switch-custom-btn');
                        const runningTimeTag = row.querySelector('.running-time-tag');
                        const canStartCustom = hasCustomBotEnabled(row);
                        if (isRunning) {
                            // Determine bot type once for use in multiple places
                            const runningType = (isRunning.bot_type || '').toLowerCase();
                            const isBetaFamily = runningType === 'beta' || runningType === 'custom';
                            // Show running status
                            if (botTag) {
                                botTag.className = 'sp-badge sp-badge-green bot-status-tag';
                                botTag.innerHTML = '<span class="icon"><i class="fas fa-check-circle"></i></span><span>' + escapeHtml(sbFormat(SB_I18N.runningPid, isRunning.pid)) + '</span>';
                            }
                            // Update bot type tag (handle stable, beta, custom, v6)
                            if (botTypeTag) {
                                const bt = (isRunning.bot_type || '').toLowerCase();
                                let className = 'sp-badge sp-badge-amber bot-type-tag';
                                let label = SB_I18N.unknown;
                                if (bt === 'beta') { className = 'sp-badge sp-badge-blue bot-type-tag'; label = SB_I18N.typeBeta; }
                                else if (bt === 'stable') { className = 'sp-badge sp-badge-green bot-type-tag'; label = SB_I18N.typeStable; }
                                else if (bt === 'custom') { className = 'sp-badge sp-badge-accent bot-type-tag'; label = SB_I18N.typeCustom; }
                                else if (bt === 'v6') { className = 'sp-badge sp-badge-grey bot-type-tag'; label = SB_I18N.typeV6; }
                                botTypeTag.className = className;
                                botTypeTag.innerHTML = '<span>' + escapeHtml(label) + '</span>';
                            }
                            if (runningTimeTag) {
                                const rawUptime = isRunning.uptime_seconds;
                                const hasNumericUptime = rawUptime !== null && rawUptime !== undefined && rawUptime !== '' && Number.isFinite(Number(rawUptime));
                                const uptimeLabel = hasNumericUptime
                                    ? formatUptime(Number(rawUptime))
                                    : (isRunning.uptime_human || SB_I18N.unknown);
                                runningTimeTag.className = 'sp-badge sp-badge-blue running-time-tag';
                                runningTimeTag.innerHTML = '<span>' + uptimeLabel + '</span>';
                            }
                            // Hide both start buttons for running bots
                            if (startStableBtn) {
                                startStableBtn.disabled = true;
                                startStableBtn.style.display = 'none';
                            }
                            if (startBetaBtn) {
                                startBetaBtn.disabled = true;
                                startBetaBtn.style.display = 'none';
                            }
                            if (startCustomBtn) {
                                startCustomBtn.disabled = true;
                                startCustomBtn.style.display = 'none';
                            }
                            // Show restart button with correct bot type and PID
                            if (restartBtn) {
                                restartBtn.style.display = 'inline-flex';
                                restartBtn.disabled = false;
                                restartBtn.setAttribute('onclick', `restartBot('${uname}', '${isRunning.bot_type}', ${isRunning.pid}, this)`);
                            }
                            // Show stop button with current PID
                            if (stopBotBtn) {
                                stopBotBtn.style.display = 'inline-flex';
                                stopBotBtn.disabled = false;
                                stopBotBtn.setAttribute('onclick', `stopBot('${uname}', ${isRunning.pid}, this)`);
                            }
                            // Show attach console button
                            const attachConsoleBtn = row.querySelector('.attach-console-btn');
                            if (attachConsoleBtn) {
                                attachConsoleBtn.style.display = 'inline-flex';
                                attachConsoleBtn.disabled = false;
                            }
                            // Show switch button with opposite bot type
                            if (switchBtn) {
                                const targetType = runningType === 'custom' ? 'stable' : (isBetaFamily ? 'stable' : 'beta');
                                const btnText = runningType === 'custom' ? SB_I18N.switchToStable : (isBetaFamily ? SB_I18N.switchToStable : SB_I18N.switchToBeta);
                                switchBtn.style.display = 'inline-flex';
                                switchBtn.disabled = false;
                                switchBtn.setAttribute('onclick', `switchBotType('${uname}', '${twitchId}', '${targetType}')`);
                                setCompactButtonLabel(switchBtn, btnText);
                            }
                            if (switchCustomBtn) {
                                if (runningType === 'custom') {
                                    switchCustomBtn.style.display = 'inline-flex';
                                    switchCustomBtn.disabled = false;
                                    switchCustomBtn.setAttribute('onclick', `switchBotType('${uname}', '${twitchId}', 'beta')`);
                                    setCompactButtonLabel(switchCustomBtn, SB_I18N.switchToBeta);
                                } else {
                                    const canSwitchToCustom = canStartCustom && runningType !== 'custom';
                                    if (canSwitchToCustom) {
                                        switchCustomBtn.style.display = 'inline-flex';
                                        switchCustomBtn.disabled = false;
                                        switchCustomBtn.setAttribute('onclick', `switchBotType('${uname}', '${twitchId}', 'custom')`);
                                        setCompactButtonLabel(switchCustomBtn, SB_I18N.switchToCustom);
                                    } else {
                                        switchCustomBtn.style.display = 'none';
                                        switchCustomBtn.disabled = true;
                                    }
                                }
                            }
                            // Validate token to check mod status even for running bots
                            if (twitchId) {
                                setTimeout(() => validateUserToken(twitchId), validateDelay);
                                validateDelay += 200;
                            }
                        } else {
                            // Hide attach console button when not running
                            const attachConsoleBtnOff = row.querySelector('.attach-console-btn');
                            if (attachConsoleBtnOff) { attachConsoleBtnOff.style.display = 'none'; attachConsoleBtnOff.disabled = true; }
                            // Update bot status tag for not running
                            if (botTag) {
                                botTag.className = 'sp-badge sp-badge-red bot-status-tag';
                                botTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>' + escapeHtml(SB_I18N.notRunning) + '</span>';
                            }
                            // Set bot type tag to "Bot Not Running" when not running
                            if (botTypeTag) {
                                botTypeTag.className = 'sp-badge sp-badge-grey bot-type-tag';
                                botTypeTag.innerHTML = '<span>' + escapeHtml(SB_I18N.botNotRunning) + '</span>';
                            }
                            if (runningTimeTag) {
                                runningTimeTag.className = 'sp-badge sp-badge-grey running-time-tag';
                                runningTimeTag.innerHTML = '<span>-</span>';
                            }
                            // Show both start buttons and hide restart button for non-running bots
                            if (startStableBtn) {
                                startStableBtn.disabled = false;
                                startStableBtn.style.display = 'inline-flex';
                            }
                            if (startBetaBtn) {
                                startBetaBtn.disabled = false;
                                startBetaBtn.style.display = 'inline-flex';
                            }
                            if (startCustomBtn) {
                                startCustomBtn.disabled = !canStartCustom;
                                startCustomBtn.style.display = 'inline-flex';
                            }
                            if (restartBtn) {
                                restartBtn.style.display = 'none';
                                restartBtn.disabled = true;
                            }
                            if (stopBotBtn) {
                                stopBotBtn.style.display = 'none';
                                stopBotBtn.disabled = true;
                            }
                            if (switchBtn) {
                                switchBtn.style.display = 'none';
                                switchBtn.disabled = true;
                            }
                            if (switchCustomBtn) {
                                switchCustomBtn.style.display = 'none';
                                switchCustomBtn.disabled = true;
                            }
                            // Validate token to check mod status
                            if (twitchId) {
                                setTimeout(() => validateUserToken(twitchId), validateDelay);
                                validateDelay += 200;
                            }
                        }
                    });
                }
                // Re-shorten any switch labels the refresh just set
                applyCompactActionLabels();
                applyCompactTableColumns();
            })
            .catch(error => {
                console.error('Error fetching running bots:', error);
            });
    }
    // Refresh only running bots status (PIDs, bot type, buttons) without touching tokens/mods
    function refreshRunningStatus() {
        // Show toast notification
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'info',
            title: SB_I18N.refreshingStatus,
            showConfirmButton: false,
            timer: 1500
        });
        // Update all bot status tags to show "Checking..." while we fetch
        document.querySelectorAll('.bot-status-tag').forEach(tag => {
            tag.className = 'sp-badge sp-badge-blue bot-status-tag';
            tag.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-pulse"></i></span><span>' + escapeHtml(SB_I18N.checking) + '</span>';
        });
        fetch('?get_running_bots=1')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    runningBots = data.bots;
                    const restartAllBtn = document.getElementById('restart-all-btn');
                    if (restartAllBtn) restartAllBtn.disabled = runningBots.length === 0;
                    const rows = Array.from(document.querySelectorAll('#users-table-body tr'));
                    rows.forEach(row => {
                        const uname = row.getAttribute('data-username');
                        const twitchId = row.getAttribute('data-twitch-id');
                        const isRunning = runningBots.find(b => b.username === uname);
                        const botTag = row.querySelector('.bot-status-tag');
                        const botTypeTag = row.querySelector('.bot-type-tag');
                        const startStableBtn = row.querySelector('.start-stable-btn');
                        const startBetaBtn = row.querySelector('.start-beta-btn');
                        const startCustomBtn = row.querySelector('.start-custom-btn');
                        const restartBtn = row.querySelector('.restart-bot-btn');
                        const stopBotBtn = row.querySelector('.stop-bot-btn');
                        const switchBtn = row.querySelector('.switch-bot-btn');
                        const switchCustomBtn = row.querySelector('.switch-custom-btn');
                        const runningTimeTag = row.querySelector('.running-time-tag');
                        const canStartCustom = hasCustomBotEnabled(row);
                        if (isRunning) {
                            const runningType = (isRunning.bot_type || '').toLowerCase();
                            const isBetaFamily = runningType === 'beta' || runningType === 'custom';
                            if (botTag) {
                                botTag.className = 'sp-badge sp-badge-green bot-status-tag';
                                botTag.innerHTML = '<span class="icon"><i class="fas fa-check-circle"></i></span><span>' + escapeHtml(sbFormat(SB_I18N.runningPid, isRunning.pid)) + '</span>';
                            }
                            if (botTypeTag) {
                                const bt = (isRunning.bot_type || '').toLowerCase();
                                let className = 'sp-badge sp-badge-amber bot-type-tag';
                                let label = SB_I18N.unknown;
                                if (bt === 'beta') { className = 'sp-badge sp-badge-blue bot-type-tag'; label = SB_I18N.typeBeta; }
                                else if (bt === 'stable') { className = 'sp-badge sp-badge-green bot-type-tag'; label = SB_I18N.typeStable; }
                                else if (bt === 'custom') { className = 'sp-badge sp-badge-accent bot-type-tag'; label = SB_I18N.typeCustom; }
                                else if (bt === 'v6') { className = 'sp-badge sp-badge-grey bot-type-tag'; label = SB_I18N.typeV6; }
                                botTypeTag.className = className;
                                botTypeTag.innerHTML = '<span>' + escapeHtml(label) + '</span>';
                            }
                            if (runningTimeTag) {
                                const rawUptime = isRunning.uptime_seconds;
                                const hasNumericUptime = rawUptime !== null && rawUptime !== undefined && rawUptime !== '' && Number.isFinite(Number(rawUptime));
                                const uptimeLabel = hasNumericUptime
                                    ? formatUptime(Number(rawUptime))
                                    : (isRunning.uptime_human || SB_I18N.unknown);
                                runningTimeTag.className = 'sp-badge sp-badge-blue running-time-tag';
                                runningTimeTag.innerHTML = '<span>' + uptimeLabel + '</span>';
                            }
                            if (startStableBtn) { startStableBtn.disabled = true; startStableBtn.style.display = 'none'; }
                            if (startBetaBtn) { startBetaBtn.disabled = true; startBetaBtn.style.display = 'none'; }
                            if (startCustomBtn) { startCustomBtn.disabled = true; startCustomBtn.style.display = 'none'; }
                            if (restartBtn) { restartBtn.style.display = 'inline-flex'; restartBtn.disabled = false; restartBtn.setAttribute('onclick', `restartBot('${uname}', '${isRunning.bot_type}', ${isRunning.pid}, this)`); }
                            if (stopBotBtn) { stopBotBtn.style.display = 'inline-flex'; stopBotBtn.disabled = false; stopBotBtn.setAttribute('onclick', `stopBot('${uname}', ${isRunning.pid}, this)`); }
                            const attachConsoleBtnR = row.querySelector('.attach-console-btn');
                            if (attachConsoleBtnR) { attachConsoleBtnR.style.display = 'inline-flex'; attachConsoleBtnR.disabled = false; }
                            if (switchBtn) { const targetType = runningType === 'custom' ? 'stable' : (isBetaFamily ? 'stable' : 'beta'); const btnText = runningType === 'custom' ? SB_I18N.switchToStable : (isBetaFamily ? SB_I18N.switchToStable : SB_I18N.switchToBeta); switchBtn.style.display = 'inline-flex'; switchBtn.disabled = false; switchBtn.setAttribute('onclick', `switchBotType('${uname}', '${twitchId}', '${targetType}')`); setCompactButtonLabel(switchBtn, btnText); }
                            if (switchCustomBtn) { if (runningType === 'custom') { switchCustomBtn.style.display = 'inline-flex'; switchCustomBtn.disabled = false; switchCustomBtn.setAttribute('onclick', `switchBotType('${uname}', '${twitchId}', 'beta')`); setCompactButtonLabel(switchCustomBtn, SB_I18N.switchToBeta); } else { const canSwitchToCustom = canStartCustom && runningType !== 'custom'; if (canSwitchToCustom) { switchCustomBtn.style.display = 'inline-flex'; switchCustomBtn.disabled = false; switchCustomBtn.setAttribute('onclick', `switchBotType('${uname}', '${twitchId}', 'custom')`); setCompactButtonLabel(switchCustomBtn, SB_I18N.switchToCustom); } else { switchCustomBtn.style.display = 'none'; switchCustomBtn.disabled = true; } } }
                        } else {
                            if (botTag) { botTag.className = 'sp-badge sp-badge-red bot-status-tag'; botTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>' + escapeHtml(SB_I18N.notRunning) + '</span>'; }
                            if (botTypeTag) { botTypeTag.className = 'sp-badge sp-badge-grey bot-type-tag'; botTypeTag.innerHTML = '<span>' + escapeHtml(SB_I18N.botNotRunning) + '</span>'; }
                            if (runningTimeTag) { runningTimeTag.className = 'sp-badge sp-badge-grey running-time-tag'; runningTimeTag.innerHTML = '<span>-</span>'; }
                            if (startStableBtn) { startStableBtn.disabled = false; startStableBtn.style.display = 'inline-flex'; }
                            if (startBetaBtn) { startBetaBtn.disabled = false; startBetaBtn.style.display = 'inline-flex'; }
                            if (startCustomBtn) { startCustomBtn.disabled = !canStartCustom; startCustomBtn.style.display = 'inline-flex'; }
                            if (restartBtn) { restartBtn.style.display = 'none'; restartBtn.disabled = true; }
                            if (stopBotBtn) { stopBotBtn.style.display = 'none'; stopBotBtn.disabled = true; }
                            const attachConsoleBtnOff2 = row.querySelector('.attach-console-btn');
                            if (attachConsoleBtnOff2) { attachConsoleBtnOff2.style.display = 'none'; attachConsoleBtnOff2.disabled = true; }
                            if (switchBtn) { switchBtn.style.display = 'none'; switchBtn.disabled = true; }
                            if (switchCustomBtn) { switchCustomBtn.style.display = 'none'; switchCustomBtn.disabled = true; }
                        }
                    });
                    // Show completion toast
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: SB_I18N.statusRefreshed,
                        showConfirmButton: false,
                        timer: 2000
                    });
                }
                applyCompactActionLabels();
                applyCompactTableColumns();
            })
            .catch(error => console.error('Error fetching running bots:', error));
    }
    // Refresh token status for all users: validate tokens and attempt renew when needed
    function refreshTokenStatus() {
        const rows = Array.from(document.querySelectorAll('#users-table-body tr'));
        const totalUsers = rows.length;
        // Show toast notification
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'info',
            title: sbFormat(SB_I18N.refreshingTokens, totalUsers),
            showConfirmButton: false,
            timer: 2000
        });
        let validateDelay = 0;
        rows.forEach(row => {
            const twitchId = row.getAttribute('data-twitch-id');
            if (twitchId) {
                setTimeout(() => validateUserToken(twitchId), validateDelay);
                validateDelay += 200;
            }
        });
        // Show completion toast after all validations are scheduled
        setTimeout(() => {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: SB_I18N.tokenRefreshComplete,
                showConfirmButton: false,
                timer: 2000
            });
        }, validateDelay + 500);
    }
    // Refresh moderator status for all users: checks if bot is a mod or banned
    function refreshModStatus() {
        const rows = Array.from(document.querySelectorAll('#users-table-body tr'));
        const totalUsers = rows.length;
        // Show toast notification
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'info',
            title: sbFormat(SB_I18N.refreshingMods, totalUsers),
            showConfirmButton: false,
            timer: 2000
        });
        let delay = 0;
        rows.forEach(row => {
            const twitchId = row.getAttribute('data-twitch-id');
            if (twitchId) {
                setTimeout(() => checkBotModStatus(twitchId), delay);
                delay += 200;
            }
        });
        // Show completion toast after all checks are scheduled
        setTimeout(() => {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: SB_I18N.modRefreshComplete,
                showConfirmButton: false,
                timer: 2000
            });
        }, delay + 500);
    }
    async function validateUserToken(twitchUserId) {
        const row = document.querySelector(`tr[data-twitch-id="${twitchUserId}"]`);
        const tokenTag = row.querySelector('.token-status-tag');
        tokenTag.className = 'sp-badge sp-badge-blue token-status-tag';
        tokenTag.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-pulse"></i></span><span>' + escapeHtml(SB_I18N.validating) + '</span>';
        try {
            const formData = new FormData();
            formData.append('validate_user_token', '1');
            formData.append('twitch_user_id', twitchUserId);
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success && data.valid) {
                tokenTag.className = 'sp-badge sp-badge-green token-status-tag';
                const hours = Math.floor(data.expires_in / 3600);
                tokenTag.innerHTML = `<span class="icon"><i class="fas fa-check-circle"></i></span><span>${escapeHtml(sbFormat(SB_I18N.valid, hours))}</span>`;
                // Update mod status if returned from server
                if (typeof data.is_mod !== 'undefined') {
                    const modTag = row.querySelector('.mod-status-tag');
                    const startStableBtn = row.querySelector('.start-stable-btn');
                    const startBetaBtn = row.querySelector('.start-beta-btn');
                    const startCustomBtn = row.querySelector('.start-custom-btn');
                    const makeModBtn = row.querySelector('.make-mod-btn');
                    const username = row.getAttribute('data-username');
                    const isRunning = runningBots.find(bot => bot.username === username);
                    const canStartCustom = hasCustomBotEnabled(row);
                    // Skip mod check for BotOfTheSpecter's own channel
                    if (twitchUserId === '971436498') {
                        modTag.className = 'sp-badge sp-badge-blue mod-status-tag';
                        modTag.innerHTML = '<span class="icon"><i class="fas fa-info-circle"></i></span><span>' + escapeHtml(SB_I18N.skipped) + '</span>';
                        if (makeModBtn) makeModBtn.style.display = 'none';
                        if (startStableBtn && !isRunning) startStableBtn.disabled = false;
                        if (startBetaBtn && !isRunning) startBetaBtn.disabled = false;
                        if (startCustomBtn && !isRunning) startCustomBtn.disabled = !canStartCustom;
                    } else if (data.is_mod) {
                        modTag.className = 'sp-badge sp-badge-green mod-status-tag';
                        modTag.innerHTML = '<span class="icon"><i class="fas fa-check-circle"></i></span><span>' + escapeHtml(SB_I18N.isModerator) + '</span>';
                        if (makeModBtn) makeModBtn.style.display = 'none';
                        if (startStableBtn && !isRunning) startStableBtn.disabled = false;
                        if (startBetaBtn && !isRunning) startBetaBtn.disabled = false;
                        if (startCustomBtn && !isRunning) startCustomBtn.disabled = !canStartCustom;
                    } else if (data.is_banned) {
                        // Bot is BANNED
                        modTag.className = 'sp-badge sp-badge-red mod-status-tag';
                        modTag.innerHTML = '<span class="icon"><i class="fas fa-ban"></i></span><span>' + escapeHtml(SB_I18N.banned) + '</span>';
                        modTag.title = SB_I18N.reasonPrefix + (data.ban_reason || SB_I18N.noReasonProvided);
                        if (makeModBtn) makeModBtn.style.display = 'none';
                    } else {
                        // Not a moderator: show a warning state but allow admins to start the bot
                        modTag.className = 'sp-badge sp-badge-amber mod-status-tag';
                        modTag.innerHTML = '<span class="icon"><i class="fas fa-exclamation-triangle"></i></span><span>' + escapeHtml(SB_I18N.notAModerator) + '</span>';
                        if (makeModBtn) makeModBtn.style.display = 'inline-flex';
                        // Allow start button even if the bot is not a moderator (admins can start regardless)
                        if (startStableBtn && !isRunning) startStableBtn.disabled = false;
                        if (startBetaBtn && !isRunning) startBetaBtn.disabled = false;
                        if (startCustomBtn && !isRunning) startCustomBtn.disabled = !canStartCustom;
                        // If the bot is running but missing mod, indicate it visually
                        if (isRunning) {
                            modTag.className = 'sp-badge sp-badge-amber mod-status-tag';
                            modTag.innerHTML = '<span class="icon"><i class="fas fa-exclamation-triangle"></i></span><span>' + escapeHtml(SB_I18N.runningWithoutMod) + '</span>';
                        }
                    }
                }
            } else {
                // Token invalid - attempt an automatic silent renewal
                tokenTag.className = 'sp-badge sp-badge-amber token-status-tag';
                tokenTag.innerHTML = '<span class="icon"><i class="fas fa-exclamation-triangle"></i></span><span>' + escapeHtml(SB_I18N.invalidAttemptRenew) + '</span>';
                const renewed = await renewUserToken(twitchUserId, true);
                if (renewed) {
                    tokenTag.className = 'sp-badge sp-badge-green token-status-tag';
                    tokenTag.innerHTML = '<span class="icon"><i class="fas fa-check-circle"></i></span><span>' + escapeHtml(SB_I18N.renewed) + '</span>';
                    const startStableBtn = row.querySelector('.start-stable-btn');
                    const startBetaBtn = row.querySelector('.start-beta-btn');
                    const startCustomBtn = row.querySelector('.start-custom-btn');
                    if (startStableBtn) startStableBtn.disabled = false;
                    if (startBetaBtn) startBetaBtn.disabled = false;
                    if (startCustomBtn) startCustomBtn.disabled = !hasCustomBotEnabled(row);
                } else {
                    tokenTag.className = 'sp-badge sp-badge-red token-status-tag';
                    tokenTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>' + escapeHtml(SB_I18N.renewalFailed) + '</span>';
                }
            }
        } catch (error) {
            tokenTag.className = 'sp-badge sp-badge-red token-status-tag';
            tokenTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>' + escapeHtml(SB_I18N.error) + '</span>';
            console.error('Error validating token:', error);
        }
    }

    async function makeBotMod(twitchUserId) {
        const row = document.querySelector(`tr[data-twitch-id="${twitchUserId}"]`);
        const modTag = row.querySelector('.mod-status-tag');
        const makeModBtn = row.querySelector('.make-mod-btn');
        const startStableBtn = row.querySelector('.start-stable-btn');
        const startBetaBtn = row.querySelector('.start-beta-btn');
        makeModBtn.disabled = true;
        makeModBtn.classList.add('sp-btn-loading');
        modTag.className = 'sp-badge sp-badge-blue mod-status-tag';
        modTag.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-pulse"></i></span><span>' + escapeHtml(SB_I18N.addingAsMod) + '</span>';
        try {
            const formData = new FormData();
            formData.append('make_bot_mod', '1');
            formData.append('twitch_user_id', twitchUserId);
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                modTag.className = 'sp-badge sp-badge-green mod-status-tag';
                modTag.innerHTML = '<span class="icon"><i class="fas fa-check-circle"></i></span><span>' + escapeHtml(SB_I18N.isModerator) + '</span>';
                makeModBtn.style.display = 'none';
                if (startStableBtn) startStableBtn.disabled = false;
                if (startBetaBtn) startBetaBtn.disabled = false;
                Swal.fire({
                    icon: 'success',
                    title: SB_I18N.makeModSuccessTitle,
                    text: SB_I18N.makeModSuccessText,
                    timer: 2000,
                    showConfirmButton: false
                });
            } else {
                modTag.className = 'sp-badge sp-badge-red mod-status-tag';
                modTag.innerHTML = '<span class="icon"><i class="fas fa-exclamation-circle"></i></span><span>' + escapeHtml(SB_I18N.failed) + '</span>';
                makeModBtn.disabled = false;
                makeModBtn.classList.remove('sp-btn-loading');
                Swal.fire({
                    icon: 'error',
                    title: SB_I18N.makeModFailedTitle,
                    html: `${escapeHtml(SB_I18N.makeModFailedHtml)}<br><br>${escapeHtml(data.message || SB_I18N.unknownError)}`,
                    confirmButtonText: SB_I18N.okBtn
                });
            }
        } catch (error) {
            modTag.className = 'sp-badge sp-badge-red mod-status-tag';
            modTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>' + escapeHtml(SB_I18N.error) + '</span>';
            makeModBtn.disabled = false;
            makeModBtn.classList.remove('sp-btn-loading');
            console.error('Error making bot mod:', error);
            Swal.fire({
                icon: 'error',
                title: SB_I18N.error,
                text: SB_I18N.makeModErrorText
            });
        }
    }

    async function checkBotModStatus(twitchUserId) {
        const row = document.querySelector(`tr[data-twitch-id="${twitchUserId}"]`);
        const modTag = row.querySelector('.mod-status-tag');
        const makeModBtn = row.querySelector('.make-mod-btn');
        const startStableBtn = row.querySelector('.start-stable-btn');
        const startBetaBtn = row.querySelector('.start-beta-btn');
        const username = row.getAttribute('data-username');
        const isRunning = runningBots.find(bot => bot.username === username);
        // Skip mod check for BotOfTheSpecter's own channel
        if (twitchUserId === '971436498') {
            modTag.className = 'sp-badge sp-badge-blue mod-status-tag';
            modTag.innerHTML = '<span class="icon"><i class="fas fa-info-circle"></i></span><span>' + escapeHtml(SB_I18N.skipped) + '</span>';
            if (makeModBtn) makeModBtn.style.display = 'none';
            if (startStableBtn && !isRunning) startStableBtn.disabled = false;
            if (startBetaBtn && !isRunning) startBetaBtn.disabled = false;
            return;
        }
        try {
            modTag.className = 'sp-badge sp-badge-blue mod-status-tag';
            modTag.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-pulse"></i></span><span>' + escapeHtml(SB_I18N.checking) + '</span>';
            const formData = new FormData();
            formData.append('check_bot_mod_status', '1');
            formData.append('twitch_user_id', twitchUserId);
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.success) {
                if (result.is_mod) {
                    modTag.className = 'sp-badge sp-badge-green mod-status-tag';
                    modTag.innerHTML = '<span class="icon"><i class="fas fa-check"></i></span><span>' + escapeHtml(SB_I18N.moderator) + '</span>';
                    if (makeModBtn) makeModBtn.style.display = 'none';
                } else if (result.is_banned) {
                    modTag.className = 'sp-badge sp-badge-red mod-status-tag';
                    modTag.innerHTML = '<span class="icon"><i class="fas fa-ban"></i></span><span>' + escapeHtml(SB_I18N.banned) + '</span>';
                    modTag.title = SB_I18N.reasonPrefix + (result.ban_reason || SB_I18N.noReasonProvided);
                    if (makeModBtn) makeModBtn.style.display = 'none';
                } else {
                    modTag.className = 'sp-badge sp-badge-amber mod-status-tag';
                    modTag.innerHTML = '<span class="icon"><i class="fas fa-times"></i></span><span>' + escapeHtml(SB_I18N.notMod) + '</span>';
                    if (makeModBtn) makeModBtn.style.display = 'inline-flex';
                }
            } else {
                modTag.className = 'sp-badge sp-badge-red mod-status-tag';
                modTag.innerHTML = '<span class="icon"><i class="fas fa-exclamation-triangle"></i></span><span>' + escapeHtml(SB_I18N.checkFailed) + '</span>';
            }
        } catch (e) {
            console.error('Error checking mod/ban status:', e);
            modTag.className = 'sp-badge sp-badge-red mod-status-tag';
            modTag.innerHTML = '<span class="icon"><i class="fas fa-exclamation-triangle"></i></span><span>' + escapeHtml(SB_I18N.error) + '</span>';
        }
    }
    async function renewUserToken(twitchUserId, silent = false) {
        const row = document.querySelector(`tr[data-twitch-id="${twitchUserId}"]`);
        const tokenTag = row.querySelector('.token-status-tag');
        tokenTag.className = 'sp-badge sp-badge-blue token-status-tag';
        tokenTag.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-pulse"></i></span><span>' + escapeHtml(SB_I18N.renewing) + '</span>';
        try {
            const formData = new FormData();
            formData.append('renew_user_token', '1');
            formData.append('twitch_user_id', twitchUserId);
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                tokenTag.className = 'sp-badge sp-badge-green token-status-tag';
                const hours = Math.floor(data.expires_in / 3600);
                tokenTag.innerHTML = `<span class="icon"><i class="fas fa-check-circle"></i></span><span>${escapeHtml(sbFormat(SB_I18N.renewedHours, hours))}</span>`;
                return true;
            } else {
                tokenTag.className = 'sp-badge sp-badge-red token-status-tag';
                tokenTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>' + escapeHtml(SB_I18N.renewalFailed) + '</span>';
                if (!silent) {
                    Swal.fire({
                        icon: 'error',
                        title: SB_I18N.tokenRenewalFailedTitle,
                        text: data.message || SB_I18N.tokenRenewalFailedText
                    });
                }
                return false;
            }
        } catch (error) {
            tokenTag.className = 'sp-badge sp-badge-red token-status-tag';
            tokenTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>' + escapeHtml(SB_I18N.error) + '</span>';
            console.error('Error renewing token:', error);
            if (!silent) {
                Swal.fire({
                    icon: 'error',
                    title: SB_I18N.tokenRenewalErrorTitle,
                    text: SB_I18N.tokenRenewalErrorText
                });
            }
            return false;
        }
    }
    async function startUserBot(username, twitchUserId, botType = 'stable') {
        const row = document.querySelector(`tr[data-twitch-id="${twitchUserId}"]`);
        const botTag = row.querySelector('.bot-status-tag');
        const tokenTag = row.querySelector('.token-status-tag');
        const startStableBtn = row.querySelector('.start-stable-btn');
        const startBetaBtn = row.querySelector('.start-beta-btn');
        const startCustomBtn = row.querySelector('.start-custom-btn');
        const currentBtn = botType === 'beta' ? startBetaBtn : (botType === 'custom' ? startCustomBtn : startStableBtn);
        const otherBtns = [startStableBtn, startBetaBtn, startCustomBtn].filter(btn => btn && btn !== currentBtn);
        // Check if bot is already running
        const isRunning = runningBots.find(bot => bot.username === username);
        if (isRunning) {
            // If trying to start same type, just show info
            if (isRunning.bot_type === botType) {
                const currentTypeLabel = botType === 'beta' ? SB_I18N.typeBeta : (botType === 'custom' ? SB_I18N.typeCustom : SB_I18N.typeStable);
                Swal.fire({ toast: true, position: 'top-end', icon: 'info', title: sbFormat(SB_I18N.botAlreadyRunning, currentTypeLabel, username), showConfirmButton: false, timer: 1500 });
                return;
            }
            // If switching bot types, confirm with user
            const result = await Swal.fire({
                title: SB_I18N.switchTypeTitle,
                text: sbFormat(SB_I18N.switchTypeTextStartStop, isRunning.bot_type, botType),
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: SB_I18N.switchConfirmBtn,
                cancelButtonText: SB_I18N.cancelBtn
            });
            if (!result.isConfirmed) return;
        }
        // Start the bot immediately via AJAX without performing token/mod checks to avoid slowing bulk operations
        if (currentBtn) {
            currentBtn.disabled = true;
            currentBtn.classList.add('sp-btn-loading');
        }
        try {
            if (botType === 'custom' && !hasCustomBotEnabled(row)) {
                Swal.fire({ toast: true, position: 'top-end', icon: 'warning', title: sbFormat(SB_I18N.customNotEnabledToast, username), showConfirmButton: false, timer: 2500 });
                return;
            }
            botTag.className = 'sp-badge sp-badge-blue bot-status-tag';
            const startTypeLabel = botType === 'beta' ? SB_I18N.typeBeta : (botType === 'custom' ? SB_I18N.typeCustom : SB_I18N.typeStable);
            botTag.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-pulse"></i></span><span>' + escapeHtml(sbFormat(SB_I18N.startingType, startTypeLabel)) + '</span>';
            const startFormData = new FormData();
            startFormData.append('start_user_bot', '1');
            startFormData.append('username', username);
            startFormData.append('bot_type', botType);
            const startResponse = await fetch('', { method: 'POST', body: startFormData });
            const startText = await startResponse.text();
            let startData = null;
            try { startData = JSON.parse(startText); } catch (e) { console.warn('Non-JSON start response, raw:', startText); }
            if (startData && startData.success) {
                // Optimistic UI update - immediately mark bot as running
                botTag.className = 'sp-badge sp-badge-green bot-status-tag';
                botTag.innerHTML = '<span class="icon"><i class="fas fa-check-circle"></i></span><span>' + escapeHtml(SB_I18N.running) + '</span>';
                const botTypeTag = row.querySelector('.bot-type-tag');
                if (botTypeTag) {
                    const botTypeClass = botType === 'beta' ? 'sp-badge sp-badge-blue bot-type-tag' : (botType === 'custom' ? 'sp-badge sp-badge-accent bot-type-tag' : 'sp-badge sp-badge-green bot-type-tag');
                    const botTypeLabel = botType === 'beta' ? SB_I18N.typeBeta : (botType === 'custom' ? SB_I18N.typeCustom : SB_I18N.typeStable);
                    botTypeTag.className = botTypeClass;
                    botTypeTag.innerHTML = '<span>' + escapeHtml(botTypeLabel) + '</span>';
                }
                if (startStableBtn) startStableBtn.style.display = 'none';
                if (startBetaBtn) startBetaBtn.style.display = 'none';
                if (startCustomBtn) startCustomBtn.style.display = 'none';
                // Remove old entry if switching types
                runningBots = runningBots.filter(bot => bot.username !== username);
                // Add to runningBots array optimistically
                runningBots.push({ username: username, bot_type: botType, pid: 0 });
                // Non-blocking toast notification only
                const startedTypeLabel = botType === 'beta' ? SB_I18N.typeBeta : (botType === 'custom' ? SB_I18N.typeCustom : SB_I18N.typeStable);
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: sbFormat(SB_I18N.startedTypeToast, startedTypeLabel, username), showConfirmButton: false, timer: 1500 });
                // Schedule a debounced refresh to verify actual status (non-blocking)
                scheduleRefresh(2000);
            } else {
                botTag.className = 'sp-badge sp-badge-red bot-status-tag';
                botTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>' + escapeHtml(SB_I18N.startFailed) + '</span>';
                const msg = (startData && startData.message) ? startData.message : SB_I18N.couldNotStart;
                Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: sbFormat(SB_I18N.startFailedToast, username, msg), showConfirmButton: false, timer: 3000 });
            }
        } catch (error) {
            botTag.className = 'sp-badge sp-badge-red bot-status-tag';
            botTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>' + escapeHtml(SB_I18N.error) + '</span>';
            console.error('Error starting bot:', error);
            Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: sbFormat(SB_I18N.errorStartingToast, username), showConfirmButton: false, timer: 3000 });
        } finally {
            if (currentBtn) {
                currentBtn.disabled = false;
                currentBtn.classList.remove('sp-btn-loading');
            }
            otherBtns.forEach(btn => {
                if (!btn) return;
                if (btn === startCustomBtn) {
                    btn.disabled = !hasCustomBotEnabled(row);
                } else {
                    btn.disabled = false;
                }
            });
        }
    }
    // Function to switch bot type
    window.switchBotType = async function (username, twitchUserId, targetType) {
        const targetTypeLabel = targetType === 'beta' ? SB_I18N.typeBeta : (targetType === 'custom' ? SB_I18N.typeCustom : SB_I18N.typeStable);
        const result = await Swal.fire({
            title: SB_I18N.switchTypeTitle,
            text: sbFormat(SB_I18N.switchTypeTextTarget, targetTypeLabel),
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: sbFormat(SB_I18N.switchConfirmTargetBtn, targetTypeLabel),
            cancelButtonText: SB_I18N.cancelBtn
        });
        if (result.isConfirmed) {
            // Use the existing startUserBot function which handles stopping and switching
            await startUserBot(username, twitchUserId, targetType);
        }
    };
    // Console viewer state
    let _consolePollTimer = null;
    let _consoleEventSource = null;
    let _activeConsoleLogFile = null;
    function _setConsoleStatus(msg, type) {
        const el = document.getElementById('console-modal-status');
        if (!el) return;
        const classes = { info: 'sp-badge-blue', success: 'sp-badge-green', warning: 'sp-badge-amber', danger: 'sp-badge-red' };
        el.textContent = msg;
        el.className = 'sp-badge ' + (classes[type] || 'sp-badge-blue');
    }
    // Strip ANSI escape codes so raw terminal sequences don't pollute the output
    function _stripAnsi(str) {
        return str.replace(/\x1b\[[0-9;]*[A-Za-z]/g, '').replace(/\x1b[()][AB012]/g, '');
    }
    // Open a persistent tail -f stream on the log file
    function _startConsoleTailStream(logFile) {
        if (_consoleEventSource) {
            _consoleEventSource.close();
            _consoleEventSource = null;
        }
        const out = document.getElementById('console-modal-output');
        // Show last 200 lines of existing output then follow live updates
        const tailCmd = 'tail -n 200 -f ' + logFile + ' 2>/dev/null';
        const tailUrl = 'terminal_stream.php?server=bots&command=' + encodeURIComponent(tailCmd) + '&safe=0';
        _consoleEventSource = new EventSource(tailUrl);
        _consoleEventSource.onmessage = function(e) {
            if (e.data && !e.data.startsWith('Executing on ')) {
                if (out) {
                    out.textContent += _stripAnsi(e.data) + '\n';
                    out.scrollTop = out.scrollHeight;
                }
            }
        };
        _consoleEventSource.addEventListener('done', function() {
            if (_consoleEventSource) { _consoleEventSource.close(); _consoleEventSource = null; }
            _setConsoleStatus(SB_I18N.streamEnded, 'warning');
        });
        _consoleEventSource.addEventListener('error', function() {
            if (_consoleEventSource) { _consoleEventSource.close(); _consoleEventSource = null; }
            _setConsoleStatus(SB_I18N.streamDisconnected, 'danger');
        });
        _consoleEventSource.onerror = function() {
            if (_consoleEventSource && _consoleEventSource.readyState === EventSource.CLOSED) {
                _consoleEventSource = null;
            }
        };
        _setConsoleStatus(SB_I18N.live, 'success');
    }
    window.attachConsole = function(username) {
        // The bot is launched with stdout/stderr redirected to the crash log:
        //   screen -dmS specter_USERNAME bash -c "python ... >> /logs/USERNAME_crash.log 2>&1"
        // Screen's own terminal stays empty; we must tail the crash log directly.
        const logFile = '/home/botofthespecter/logs/' + username + '_crash.log';
        const modal = document.getElementById('console-modal');
        document.getElementById('console-modal-title').textContent = sbFormat(SB_I18N.consoleTitlePrefix, username);
        document.getElementById('console-modal-output').textContent = '';
        _setConsoleStatus(SB_I18N.connecting, 'info');
        modal.style.display = 'flex';
        _activeConsoleLogFile = logFile;
        _startConsoleTailStream(logFile);
    };
    window.closeConsoleModal = function() {
        if (_consolePollTimer) { clearInterval(_consolePollTimer); _consolePollTimer = null; }
        if (_consoleEventSource) { _consoleEventSource.close(); _consoleEventSource = null; }
        _activeConsoleLogFile = null;
        document.getElementById('console-modal').style.display = 'none';
        document.getElementById('console-modal-output').textContent = '';
    };
    window.consoleModalBackdropClick = function(e) {
        if (e.target === document.getElementById('console-modal')) {
            closeConsoleModal();
        }
    };
    // Function to restart bot
    window.restartBot = function (username, botType, pid, element) {
        Swal.fire({
            title: SB_I18N.restartConfirmTitle,
            text: SB_I18N.restartConfirmText,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#aaa',
            confirmButtonText: SB_I18N.restartConfirmBtn
        }).then((result) => {
            if (result.isConfirmed) {
                // Log the restart details for debugging
                console.log('Restarting bot:', { username: username, botType: botType, pid: pid });
                // Show loading toast
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'info',
                    title: sbFormat(SB_I18N.restartingToast, botType, username),
                    showConfirmButton: false,
                    timer: 2000
                });
                const formData = new FormData();
                formData.append('restart_bot', '1');
                formData.append('username', username);
                formData.append('bot_type', botType);
                formData.append('pid', pid);
                // Log what we're sending
                console.log('FormData contents:', {
                    restart_bot: '1',
                    username: username,
                    bot_type: botType,
                    pid: pid
                });
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const successMessage = data.message || SB_I18N.restartSuccessDefault;
                            Swal.fire({
                                toast: true,
                                position: 'top-end',
                                icon: 'success',
                                title: username + ': ' + successMessage,
                                html: SB_I18N.restartRefreshHint,
                                showConfirmButton: false,
                                timer: 3000
                            });
                        } else {
                            const failureMessage = data.message || SB_I18N.restartFailedDefault;
                            Swal.fire({
                                toast: true,
                                position: 'top-end',
                                icon: 'error',
                                title: username + ': ' + failureMessage,
                                showConfirmButton: false,
                                timer: 3000
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error restarting bot:', error);
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'error',
                            title: sbFormat(SB_I18N.networkErrorRestart, username),
                            showConfirmButton: false,
                            timer: 3000
                        });
                    });
            }
        });
    };
    window.stopBot = function(username, pid, element) {
        Swal.fire({
            title: SB_I18N.stopConfirmTitle,
            text: sbFormat(SB_I18N.stopConfirmText, username),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e74c3c',
            cancelButtonColor: '#aaa',
            confirmButtonText: SB_I18N.stopConfirmBtn
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'info',
                    title: sbFormat(SB_I18N.stoppingToast, username),
                    showConfirmButton: false,
                    timer: 2000
                });
                const formData = new FormData();
                formData.append('stop_bot', '1');
                formData.append('username', username);
                formData.append('pid', pid);
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                toast: true,
                                position: 'top-end',
                                icon: 'success',
                                title: username + ': ' + SB_I18N.stopSuccess,
                                html: SB_I18N.stopRefreshHint,
                                showConfirmButton: false,
                                timer: 3000
                            });
                        } else {
                            Swal.fire({
                                toast: true,
                                position: 'top-end',
                                icon: 'error',
                                title: username + ': ' + (data.message || SB_I18N.stopFailedDefault),
                                showConfirmButton: false,
                                timer: 3000
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error stopping bot:', error);
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'error',
                            title: sbFormat(SB_I18N.networkErrorStop, username),
                            showConfirmButton: false,
                            timer: 3000
                        });
                    });
            }
        });
    };
    // Function to restart all running bots
    window.restartAllBots = async function () {
        if (runningBots.length === 0) {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'info',
                title: SB_I18N.noRunningBots,
                showConfirmButton: false,
                timer: 2000
            });
            return;
        }
        // Confirm the action
        const result = await Swal.fire({
            title: SB_I18N.restartAllTitle,
            text: sbFormat(SB_I18N.restartAllText, runningBots.length),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#aaa',
            confirmButtonText: SB_I18N.restartAllConfirmBtn
        });
        if (!result.isConfirmed) return;
        // Disable the restart all button during the process
        const restartAllBtn = document.getElementById('restart-all-btn');
        if (restartAllBtn) {
            restartAllBtn.disabled = true;
            restartAllBtn.classList.add('sp-btn-loading');
        }
        // Store original PIDs for comparison
        const botRestartTracking = runningBots.map(bot => ({
            username: bot.username,
            botType: bot.bot_type || 'stable',
            originalPid: bot.pid,
            newPid: null,
            restarted: false
        }));
        let successCount = 0;
        let failCount = 0;
        // Create a persistent progress toast that we'll update
        const progressToast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: null,
            timerProgressBar: false,
            didOpen: (toast) => {
                toast.style.cursor = 'default';
            }
        });
        // Show initial progress toast
        progressToast.fire({
            icon: 'info',
            title: sbFormat(SB_I18N.restartAllStarting, runningBots.length)
        });
        // Restart each bot sequentially
        for (let i = 0; i < botRestartTracking.length; i++) {
            const botInfo = botRestartTracking[i];
            // Update progress toast with current bot
            progressToast.fire({
                icon: 'info',
                title: sbFormat(SB_I18N.restartingBotProgress, botInfo.username, i + 1, botRestartTracking.length)
            });
            try {
                // Send restart request
                const formData = new FormData();
                formData.append('restart_bot', '1');
                formData.append('username', botInfo.username);
                formData.append('bot_type', botInfo.botType);
                formData.append('pid', botInfo.originalPid);
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (!data.success) {
                    failCount++;
                    console.error(`Failed to restart ${botInfo.username}:`, data.message);
                } else {
                    // Prefer the new PID reported directly by the restart handler --
                    // no full re-scan of every user (that was the slowness + flakiness).
                    let newPid = (data.pid && Number(data.pid) > 0) ? Number(data.pid) : 0;
                    // If the handler could not capture the fresh PID in its short window,
                    // verify with a lightweight single-bot check (one SSH call), polling briefly.
                    if (newPid === 0 || newPid === Number(botInfo.originalPid)) {
                        for (let attempt = 0; attempt < 3; attempt++) {
                            await new Promise(resolve => setTimeout(resolve, 1000));
                            try {
                                const chkResp = await fetch('?check_one_bot=1&username=' + encodeURIComponent(botInfo.username) + '&bot_type=' + encodeURIComponent(botInfo.botType));
                                const chk = await chkResp.json();
                                if (chk && chk.success && chk.pid && Number(chk.pid) > 0) {
                                    newPid = Number(chk.pid);
                                    if (newPid !== Number(botInfo.originalPid)) break;
                                }
                            } catch (e) { /* keep polling */ }
                        }
                    }
                    if (newPid > 0 && newPid !== Number(botInfo.originalPid)) {
                        botInfo.newPid = newPid;
                        botInfo.restarted = true;
                        successCount++;
                    } else {
                        failCount++;
                        console.warn(`Bot ${botInfo.username} did not come back with a new PID (original ${botInfo.originalPid}, saw ${newPid || 'none'})`);
                    }
                }
            } catch (error) {
                failCount++;
                console.error(`Error restarting ${botInfo.username}:`, error);
            }
            // Small delay between restarts
            if (i < botRestartTracking.length - 1) {
                await new Promise(resolve => setTimeout(resolve, 300));
            }
        }
        // Re-enable button
        if (restartAllBtn) {
            restartAllBtn.disabled = false;
            restartAllBtn.classList.remove('sp-btn-loading');
        }
        // Close the progress toast before showing final results
        Swal.close();
        // Sync the whole table from server truth once, at the end.
        try { refreshRunningStatus(); } catch (e) { /* non-fatal */ }
        // Show final results
        if (failCount === 0) {
            Swal.fire({
                icon: 'success',
                title: SB_I18N.allRestartedTitle,
                html: sbFormat(SB_I18N.allRestartedHtml, successCount) + `<br><br>` +
                    botRestartTracking.map(b =>
                        `<span class="has-text-weight-bold">${escapeHtml(b.username)}</span>: PID ${b.originalPid} &rarr; ${b.newPid || SB_I18N.unknown}`
                    ).join('<br>'),
                confirmButtonText: SB_I18N.okBtn
            });
        } else {
            Swal.fire({
                icon: 'warning',
                title: SB_I18N.restartIssuesTitle,
                html: sbFormat(SB_I18N.restartIssuesSuccess, successCount) + `<br>` +
                    sbFormat(SB_I18N.restartIssuesFailed, failCount) + `<br><br>` +
                    botRestartTracking.map(b => {
                        const status = b.restarted ? '&#10003;' : '&#10007;';
                        return `${status} <span class="has-text-weight-bold">${escapeHtml(b.username)}</span>: ${b.originalPid} &rarr; ${b.newPid || SB_I18N.failed}`;
                    }).join('<br>'),
                confirmButtonText: SB_I18N.okBtn
            });
        }
    };
</script>
<?php if (!empty($client_console_logs)): ?>
    <script>
        try {
            const __client_logs = <?php echo json_encode($client_console_logs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            if (Array.isArray(__client_logs) && __client_logs.length > 0) {
                console.groupCollapsed('Server Logs (' + __client_logs.length + ')');
                __client_logs.forEach((entry, idx) => {
                    try {
                        const title = '#' + idx + ' ' + (entry.level || 'error');
                        console.groupCollapsed(title);
                        console.error(entry.msg);
                        console.groupEnd();
                    } catch (e) {
                        console.error('Server log render error:', e);
                    }
                });
                console.groupEnd();
            }
        } catch (e) {
            console.error('Failed to parse server logs:', e);
        }
    </script>
<?php endif; ?>
<?php
$content = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>
