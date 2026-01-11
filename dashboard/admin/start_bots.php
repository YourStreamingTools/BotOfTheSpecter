<?php
// Start output buffering immediately to prevent any accidental output
ob_start();

session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";
include '/var/www/config/twitch.php';
$pageTitle = 'Start User Bots';

// Token cache path
$tokenCacheFile = '/var/www/cache/tokens/start_bot_tokens.json';

function ensureTokenCacheDirExists($filePath) {
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

function readTokenCacheFile($filePath) {
    if (!file_exists($filePath)) return [];
    $contents = @file_get_contents($filePath);
    if ($contents === false) return [];
    $data = json_decode($contents, true);
    return is_array($data) ? $data : [];
}

function writeTokenCacheFile($filePath, $data) {
    ensureTokenCacheDirExists($filePath);
    $tmp = $filePath . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT);
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, $filePath);
}

function getTokenCacheEntry($filePath, $twitchId) {
    $cache = readTokenCacheFile($filePath);
    return $cache[$twitchId] ?? null;
}

function setTokenCacheEntry($filePath, $twitchId, $entry) {
    $cache = readTokenCacheFile($filePath);
    $cache[$twitchId] = $entry;
    return writeTokenCacheFile($filePath, $cache);
}

function removeTokenCacheEntry($filePath, $twitchId) {
    $cache = readTokenCacheFile($filePath);
    if (isset($cache[$twitchId])) {
        unset($cache[$twitchId]);
        return writeTokenCacheFile($filePath, $cache);
    }
    return true;
}

// Lightweight wrapper to provide a start_bot_for_user() function when missing.
// This uses the central performBotAction() implementation in dashboard/bot_control_functions.php.
if (!function_exists('start_bot_for_user')) {
    function start_bot_for_user($username, $botType = 'stable') {
        global $conn, $api_key;
        // Load tokens and api_key for the user
        $stmt = $conn->prepare("SELECT twitch_user_id, access_token, refresh_token, api_key FROM users WHERE username = ? LIMIT 1");
        if (!$stmt) return ['success' => false, 'message' => 'Database error preparing token lookup'];
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) return ['success' => false, 'message' => 'User not found'];
        // Extract all required fields
        $twitchUserId = trim($row['twitch_user_id'] ?? '');
        $accessToken = trim($row['access_token'] ?? '');
        $refreshToken = trim($row['refresh_token'] ?? '');
        // Get API key - try per-user key first, then global fallback
        $userApiKey = trim($row['api_key'] ?? '');
        $globalApiKey = trim($api_key ?? '');
        $finalApiKey = !empty($userApiKey) ? $userApiKey : $globalApiKey;
        // Validate all required parameters before proceeding
        $missingParams = [];
        if (empty($username)) $missingParams[] = 'username';
        if (empty($twitchUserId)) $missingParams[] = 'twitch_user_id';
        if (empty($accessToken)) $missingParams[] = 'access_token';
        if (empty($refreshToken)) $missingParams[] = 'refresh_token';
        if (empty($finalApiKey)) $missingParams[] = 'api_key';
        if (!empty($missingParams)) {
            return [
                'success' => false, 
                'message' => 'Missing required parameters: ' . implode(', ', $missingParams),
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
            $params = [
                'username' => $username,
                'twitch_user_id' => $twitchUserId,
                'auth_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'api_key' => $finalApiKey
            ];
            $res = performBotAction('run', $botType, $params);
            // performBotAction returns an array
            return $res;
        }
        return ['success' => false, 'message' => 'No bot start implementation available'];
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
        // Use the bot control helper to check each user's stable bot status (matches admin index behaviour)
        require_once __DIR__ . '/../bot_control_functions.php';
        $running_bots = [];
        // Fetch all users to check their bot status
        $stmtUsers = $conn->prepare("SELECT username FROM users");
        if ($stmtUsers && $stmtUsers->execute()) {
            $res = $stmtUsers->get_result();
            while ($u = $res->fetch_assoc()) {
                $uname = $u['username'];
                try {
                    // Check for stable bot first
                    $stableStatus = checkBotRunning($uname, 'stable');
                    if (isset($stableStatus['running']) && $stableStatus['running']) {
                        $running_bots[] = [
                            'username' => $uname,
                            'pid' => $stableStatus['pid'] ?? 'unknown',
                            'bot_type' => 'stable',
                            'version' => $stableStatus['version'] ?? ''
                        ];
                    } else {
                        // If stable is not running, check for beta bot
                        $betaStatus = checkBotRunning($uname, 'beta');
                        if (isset($betaStatus['running']) && $betaStatus['running']) {
                            $running_bots[] = [
                                'username' => $uname,
                                'pid' => $betaStatus['pid'] ?? 'unknown',
                                'bot_type' => 'beta',
                                'version' => $betaStatus['version'] ?? ''
                            ];
                        }
                    }
                } catch (Exception $e) {
                    // Ignore per-user errors but capture in debug
                    error_log('checkBotRunning error for ' . $uname . ': ' . $e->getMessage());
                }
            }
            $stmtUsers->close();
        } else {
            $debug = ob_get_clean();
            echo json_encode(['success' => false, 'message' => 'Failed to fetch users from database', 'bots' => [], 'debug' => $debug]);
            exit;
        }
        $debug = ob_get_clean();
        echo json_encode(['success' => true, 'bots' => $running_bots, 'debug' => $debug]);
    } catch (Exception $e) {
        $debug = ob_get_clean();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'bots' => [], 'debug' => $debug]);
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
        while (ob_get_level()) ob_end_clean();
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
        curl_close($ch);
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $expires = isset($data['expires_in']) ? (int)$data['expires_in'] : 0;
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
            curl_close($mod_ch);
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
                    curl_close($ban_ch);
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
        while (ob_get_level()) ob_end_clean();
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
        curl_close($ch);
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
                    $expires = isset($data['expires_in']) ? (int)$data['expires_in'] : 0;
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
                // Log DB update failure for debugging
                $dbErr = $stmt->error;
                error_log('Failed to update auth tokens for twitch_user_id=' . $twitch_user_id . ' stmt_error=' . $dbErr);
                $stmt->close();
                $debug = ob_get_clean();
                echo json_encode(['success' => false, 'message' => 'Failed to update database', 'debug' => $debug, 'error_details' => ['db_error' => $dbErr]]);
            }
        } else {
            // Log Twitch renewal failure for debugging
            $respSnippet = substr((string)$response, 0, 200);
            $debug = ob_get_clean();
            echo json_encode(['success' => false, 'message' => 'Failed to renew token with Twitch', 'debug' => $debug, 'error_details' => ['http_code' => $httpCode, 'curl_error' => $curlError, 'response' => $respSnippet]]);
        }
        } catch (Exception $e) {
            $debug = ob_get_clean();
            error_log('Exception in renew_user_token: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'debug' => $debug, 'error_details' => ['exception' => $e->getMessage()]]);
        }
    exit;
}

// Handle AJAX request to check if bot is a moderator
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_bot_mod_status'])) {
    // Clean any output that may have been generated
    while (ob_get_level()) ob_end_clean();
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
        curl_close($ch);
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
                curl_close($banCh);
                
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
    while (ob_get_level()) ob_end_clean();
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
        curl_close($ch);
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

// Handle AJAX request to restart bot
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['restart_bot'])) {
    require_once __DIR__ . '/../bot_control_functions.php';
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');
    $username = trim($_POST['username'] ?? '');
    $botType = trim($_POST['bot_type'] ?? 'stable');
    $pid = intval($_POST['pid'] ?? 0);
    // Log the restart attempt
    error_log("Bot restart request - Username: {$username}, Bot Type: {$botType}, PID: {$pid}");
    $success = false;
    $message = '';
    if (empty($username)) {
        $message = 'Username is required';
    } else {
        try {
            // Get user data including refresh_token and api_key from users table
            $stmt = $conn->prepare("SELECT twitch_user_id, refresh_token, api_key FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $userData = $result->fetch_assoc();
                $twitchUserId = $userData['twitch_user_id'];
                $refreshToken = $userData['refresh_token'];
                $apiKey = $userData['api_key'];
                // Get bot access token from twitch_bot_access table
                $stmt2 = $conn->prepare("SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = ?");
                $stmt2->bind_param("s", $twitchUserId);
                $stmt2->execute();
                $tokenResult = $stmt2->get_result();
                if ($tokenResult->num_rows > 0) {
                    $tokenData = $tokenResult->fetch_assoc();
                    $botAccessToken = $tokenData['twitch_access_token'];
                    error_log("RESTART DEBUG - About to restart: Username={$username}, BotType={$botType}, PID={$pid}");
                    // Step 1: Stop the bot if it's running
                    if ($pid > 0) {
                        error_log("RESTART DEBUG - Stopping PID {$pid} (should be {$botType} bot)");
                        try {
                            $connection = SSHConnectionManager::getConnection($bots_ssh_host, $bots_ssh_username, $bots_ssh_password);
                            if ($connection) {
                                SSHConnectionManager::executeCommand($connection, "kill -s kill $pid");
                                error_log("RESTART DEBUG - Kill command sent for PID {$pid}");
                                // Give it a moment to stop
                                sleep(1);
                            }
                        } catch (Exception $e) {
                            error_log("Error stopping bot during restart: " . $e->getMessage());
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
                    error_log("RESTART DEBUG - Calling performBotAction('run', '{$botType}', ...) for {$username}");
                    $result = performBotAction('run', $botType, $params);
                    error_log("RESTART DEBUG - performBotAction result: " . json_encode($result));
                    $success = $result['success'];
                    // Clarify which bot type was started
                    $message = $result['message'] . " (" . ucfirst($botType) . " version)";
                } else {
                    $message = 'Bot access token not found for user';
                }
                $stmt2->close();
            } else {
                $message = 'User not found';
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = 'Error restarting bot: ' . $e->getMessage();
            error_log("Bot restart error: " . $e->getMessage());
        }
    }
    $debug = ob_get_clean();
    echo json_encode(['success' => $success, 'message' => $message, 'debug' => $debug]);
    exit;
}

// Handle AJAX request to start bot for user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_user_bot'])) {
    // Clean any output that may have been generated and start a fresh buffer
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');
    require_once __DIR__ . '/../bot_control_functions.php';
    $username = trim($_POST['username'] ?? '');
    $botType = trim($_POST['bot_type'] ?? 'stable');
    if (empty($username)) {
        echo json_encode(['success' => false, 'message' => 'Username is required']);
        exit;
    }
    try {
        // Start the bot for the user while capturing incidental output
        $handledShutdown = false;
        // Ensure a fresh buffer to capture any HTML/error output produced during bot startup
        while (ob_get_level()) ob_end_clean();
        ob_start();
        register_shutdown_function(function() use (&$handledShutdown) {
            $err = error_get_last();
            if ($err && !$handledShutdown) {
                $debug = '';
                if (ob_get_level()) $debug = ob_get_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Fatal error during bot start', 'debug' => $debug, 'error_details' => ['shutdown_error' => $err]]);
                exit;
            }
        });
        try {
            $result = start_bot_for_user($username, $botType);
            $debug = '';
            if (ob_get_level()) $debug = ob_get_clean();
            $handledShutdown = true;
            if (is_array($result)) {
                $ok = $result['success'] ?? false;
                $msg = $result['message'] ?? '';
                $pid = $result['pid'] ?? null;
                echo json_encode(['success' => $ok, 'message' => $msg, 'pid' => $pid, 'debug' => $debug, 'details' => $result]);
            } elseif ($result === true) {
                echo json_encode(['success' => true, 'message' => 'Bot started successfully', 'debug' => $debug]);
            } else {
                echo json_encode(['success' => false, 'message' => $result, 'debug' => $debug]);
            }
        } catch (Throwable $e) {
            $debug = '';
            if (ob_get_level()) $debug = ob_get_clean();
            $handledShutdown = true;
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'debug' => $debug, 'error_details' => ['exception' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]]);
        }
    } catch (Exception $e) {
           $debug = ob_get_clean();
           echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'debug' => $debug]);
    }
    exit;
}

// Include userdata.php AFTER all AJAX handling to prevent output corruption
include '../userdata.php';

// Fetch all users from database
$users = [];
$stmt = $conn->prepare("SELECT id, username, twitch_user_id, twitch_display_name, profile_image FROM users ORDER BY id");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();

ob_start();
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<div class="box">
    <h1 class="title is-4"><span class="icon"><i class="fas fa-play-circle"></i></span> Start User Bots</h1>
    <p class="mb-4">Validate tokens and start stable bots for users. The system will automatically check if the token is valid and renew it if necessary before starting the bot.</p>
    <div class="is-flex is-justify-content-space-between is-align-items-center mb-4">
        <div class="buttons">
            <button class="button is-info" onclick="refreshBotStatus()">
                <span class="icon"><i class="fas fa-sync-alt"></i></span>
                <span>Refresh All</span>
            </button>
            <button class="button is-primary" onclick="refreshRunningStatus()">
                <span class="icon"><i class="fas fa-tasks"></i></span>
                <span>Refresh Status</span>
            </button>
            <button class="button is-info" onclick="refreshTokenStatus()">
                <span class="icon"><i class="fas fa-key"></i></span>
                <span>Refresh Token Status</span>
            </button>
            <button class="button is-light" onclick="refreshModStatus()">
                <span class="icon"><i class="fas fa-user-shield"></i></span>
                <span>Refresh Mod Status</span>
            </button>
            <button class="button is-warning" id="restart-all-btn" onclick="restartAllBots()" disabled>
                <span class="icon"><i class="fas fa-redo-alt"></i></span>
                <span>Restart All Bots</span>
            </button>
        </div>
        <div class="field">
            <p class="control has-icons-left">
                <input class="input" type="text" id="user-search" placeholder="Search users...">
                <span class="icon is-left">
                    <i class="fas fa-search"></i>
                </span>
            </p>
        </div>
    </div>
    <div class="table-container">
        <table class="table is-fullwidth is-striped">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Twitch ID</th>
                    <th>Bot Status</th>
                    <th>Bot Type</th>
                    <th>Token Status</th>
                    <th>Mod Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="users-table-body">
                <?php foreach ($users as $user): ?>
                <tr data-username="<?php echo htmlspecialchars($user['username']); ?>" 
                    data-twitch-id="<?php echo htmlspecialchars($user['twitch_user_id']); ?>">
                    <td>
                        <div class="is-flex is-align-items-center">
                            <figure class="image is-32x32 mr-2">
                                <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" 
                                     alt="<?php echo htmlspecialchars($user['username']); ?>" 
                                     class="is-rounded">
                            </figure>
                            <span><?php echo htmlspecialchars($user['twitch_display_name'] ?: $user['username']); ?></span>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($user['twitch_user_id']); ?></td>
                    <td>
                        <span class="tag bot-status-tag" data-username="<?php echo htmlspecialchars($user['username']); ?>">
                            <span class="icon"><i class="fas fa-spinner fa-pulse"></i></span>
                            <span>Checking...</span>
                        </span>
                    </td>
                    <td>
                        <span class="tag is-warning bot-type-tag">
                            <span>Unknown</span>
                        </span>
                    </td>
                    <td>
                        <span class="tag token-status-tag" data-twitch-id="<?php echo htmlspecialchars($user['twitch_user_id']); ?>">
                            <span class="icon"><i class="fas fa-question"></i></span>
                            <span>Unknown</span>
                        </span>
                    </td>
                    <td>
                        <span class="tag mod-status-tag">
                            <span class="icon"><i class="fas fa-question"></i></span>
                            <span>Unknown</span>
                        </span>
                    </td>
                    <td>
                        <div class="buttons are-small">
                            <button class="button is-warning make-mod-btn" 
                                    onclick="makeBotMod('<?php echo htmlspecialchars($user['twitch_user_id']); ?>')"
                                    style="display: none;"
                                    title="Grant moderator status to BotOfTheSpecter">
                                <span class="icon"><i class="fas fa-user-shield"></i></span>
                                <span>Make Bot Mod</span>
                            </button>
                            <button class="button is-success start-stable-btn" 
                                    onclick="startUserBot('<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['twitch_user_id']); ?>', 'stable')"
                                    disabled>
                                <span class="icon"><i class="fas fa-play"></i></span>
                                <span>Start Stable</span>
                            </button>
                            <button class="button is-info start-beta-btn" 
                                    onclick="startUserBot('<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['twitch_user_id']); ?>', 'beta')"
                                    disabled>
                                <span class="icon"><i class="fas fa-flask"></i></span>
                                <span>Start Beta</span>
                            </button>
                            <button class="button is-warning restart-bot-btn" 
                                    onclick="restartBot('<?php echo htmlspecialchars($user['username']); ?>', 'stable', 0, this)" 
                                    style="display: none;" 
                                    disabled>
                                <span class="icon"><i class="fas fa-sync-alt"></i></span>
                                <span>Restart Bot</span>
                            </button>
                            <button class="button is-info switch-bot-btn" 
                                    onclick="switchBotType('<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['twitch_user_id']); ?>', 'beta')" 
                                    style="display: none;" 
                                    disabled>
                                <span class="icon"><i class="fas fa-exchange-alt"></i></span>
                                <span>Switch to Beta</span>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
let runningBots = [];
let refreshTimer = null; // Debounce timer for refresh operations
// Helper to escape HTML for safe display in alerts
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
document.addEventListener('DOMContentLoaded', function() {
    // Load running bots status on page load
    refreshBotStatus();
    // Initialize search functionality
    const searchInput = document.getElementById('user-search');
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase().trim();
            const rows = document.querySelectorAll('#users-table-body tr');
            rows.forEach(row => {
                const username = row.querySelector('td:first-child')?.textContent.toLowerCase() || '';
                const twitchId = row.getAttribute('data-twitch-id')?.toLowerCase() || '';
                const displayName = row.querySelector('.has-text-grey')?.textContent.toLowerCase() || '';
                // Show row if search term matches username, display name, or Twitch ID
                if (username.includes(searchTerm) || displayName.includes(searchTerm) || twitchId.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }
});
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
function refreshBotStatus() {
    // Show toast notification
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'info',
        title: 'Refreshing all statuses...',
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
                    const restartBtn = row.querySelector('.restart-bot-btn');
                    const switchBtn = row.querySelector('.switch-bot-btn');
                    if (isRunning) {
                        // Determine bot type once for use in multiple places
                        const isBeta = isRunning.bot_type === 'beta';
                        // Show running status
                        if (botTag) {
                            botTag.className = 'tag is-success bot-status-tag';
                            botTag.innerHTML = '<span class="icon"><i class="fas fa-check-circle"></i></span><span>Running (PID: ' + isRunning.pid + ')</span>';
                        }
                        // Update bot type tag
                        if (botTypeTag) {
                            if (!isRunning.bot_type || (isRunning.bot_type !== 'beta' && isRunning.bot_type !== 'stable')) {
                                // Bot type is unknown or invalid
                                botTypeTag.className = 'tag is-warning bot-type-tag';
                                botTypeTag.innerHTML = '<span>Unknown</span>';
                            } else {
                                botTypeTag.className = isBeta ? 'tag is-info bot-type-tag' : 'tag is-success bot-type-tag';
                                botTypeTag.innerHTML = '<span>' + (isBeta ? 'Beta' : 'Stable') + '</span>';
                            }
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
                        // Show restart button with correct bot type and PID
                        if (restartBtn) {
                            restartBtn.style.display = 'inline-flex';
                            restartBtn.disabled = false;
                            restartBtn.setAttribute('onclick', `restartBot('${uname}', '${isRunning.bot_type}', ${isRunning.pid}, this)`);
                        }
                        // Show switch button with opposite bot type
                        if (switchBtn) {
                            const targetType = isBeta ? 'stable' : 'beta';
                            const btnText = isBeta ? 'Switch to Stable' : 'Switch to Beta';
                            switchBtn.style.display = 'inline-flex';
                            switchBtn.disabled = false;
                            switchBtn.setAttribute('onclick', `switchBotType('${uname}', '${twitchId}', '${targetType}')`);
                            switchBtn.querySelector('span:last-child').textContent = btnText;
                        }
                        // Validate token to check mod status even for running bots
                        if (twitchId) {
                            setTimeout(() => validateUserToken(twitchId), validateDelay);
                            validateDelay += 200;
                        }
                    } else {
                        // Update bot status tag for not running
                        if (botTag) {
                            botTag.className = 'tag is-danger bot-status-tag';
                            botTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Not Running</span>';
                        }
                        // Set bot type tag to "Bot Not Running" when not running
                        if (botTypeTag) {
                            botTypeTag.className = 'tag is-dark bot-type-tag';
                            botTypeTag.innerHTML = '<span>Bot Not Running</span>';
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
                        if (restartBtn) {
                            restartBtn.style.display = 'none';
                            restartBtn.disabled = true;
                        }
                        if (switchBtn) {
                            switchBtn.style.display = 'none';
                            switchBtn.disabled = true;
                        }
                        // Validate token to check mod status
                        if (twitchId) {
                            setTimeout(() => validateUserToken(twitchId), validateDelay);
                            validateDelay += 200;
                        }
                    }
                });
            }
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
        title: 'Refreshing bot status...',
        showConfirmButton: false,
        timer: 1500
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
                    const restartBtn = row.querySelector('.restart-bot-btn');
                    const switchBtn = row.querySelector('.switch-bot-btn');
                    if (isRunning) {
                        const isBeta = isRunning.bot_type === 'beta';
                        if (botTag) {
                            botTag.className = 'tag is-success bot-status-tag';
                            botTag.innerHTML = '<span class="icon"><i class="fas fa-check-circle"></i></span><span>Running (PID: ' + isRunning.pid + ')</span>';
                        }
                        if (botTypeTag) {
                            botTypeTag.className = isBeta ? 'tag is-info bot-type-tag' : 'tag is-success bot-type-tag';
                            botTypeTag.innerHTML = '<span>' + (isBeta ? 'Beta' : 'Stable') + '</span>';
                        }
                        if (startStableBtn) { startStableBtn.disabled = true; startStableBtn.style.display = 'none'; }
                        if (startBetaBtn) { startBetaBtn.disabled = true; startBetaBtn.style.display = 'none'; }
                        if (restartBtn) { restartBtn.style.display = 'inline-flex'; restartBtn.disabled = false; restartBtn.setAttribute('onclick', `restartBot('${uname}', '${isRunning.bot_type}', ${isRunning.pid}, this)`); }
                        if (switchBtn) { const targetType = isBeta ? 'stable' : 'beta'; const btnText = isBeta ? 'Switch to Stable' : 'Switch to Beta'; switchBtn.style.display = 'inline-flex'; switchBtn.disabled = false; switchBtn.setAttribute('onclick', `switchBotType('${uname}', '${twitchId}', '${targetType}')`); switchBtn.querySelector('span:last-child').textContent = btnText; }
                        // Keep row visible for running bots
                        row.style.display = '';
                    } else {
                        if (botTag) { botTag.className = 'tag is-danger bot-status-tag'; botTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Not Running</span>'; }
                        if (botTypeTag) { botTypeTag.className = 'tag is-dark bot-type-tag'; botTypeTag.innerHTML = '<span>Bot Not Running</span>'; }
                        if (startStableBtn) { startStableBtn.disabled = false; startStableBtn.style.display = 'inline-flex'; }
                        if (startBetaBtn) { startBetaBtn.disabled = false; startBetaBtn.style.display = 'inline-flex'; }
                        if (restartBtn) { restartBtn.style.display = 'none'; restartBtn.disabled = true; }
                        if (switchBtn) { switchBtn.style.display = 'none'; switchBtn.disabled = true; }
                        // Ensure row is visible for non-running bots
                        row.style.display = '';
                    }
                });
                // Show completion toast
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Bot status refreshed',
                    showConfirmButton: false,
                    timer: 2000
                });
            }
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
        title: `Refreshing token status for ${totalUsers} user(s)...`,
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
            title: 'Token status refresh complete',
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
        title: `Refreshing mod status for ${totalUsers} user(s)...`,
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
            title: 'Mod status refresh complete',
            showConfirmButton: false,
            timer: 2000
        });
    }, delay + 500);
}
function updateBotStatusDisplay() {
    // Only update visible rows (those not hidden because bot is running)
    document.querySelectorAll('#users-table-body tr').forEach(row => {
        if (row.style.display === 'none') return;
        const tag = row.querySelector('.bot-status-tag');
        const username = tag ? tag.getAttribute('data-username') : row.getAttribute('data-username');
        const isRunning = runningBots.find(bot => bot.username === username);
        if (tag) {
            if (isRunning) {
                tag.className = 'tag is-success bot-status-tag';
                tag.innerHTML = '<span class="icon"><i class="fas fa-check-circle"></i></span><span>Running (PID: ' + isRunning.pid + ')</span>';
            } else {
                tag.className = 'tag is-danger bot-status-tag';
                tag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Not Running</span>';
            }
        }
    });
}
async function validateUserToken(twitchUserId) {
    const row = document.querySelector(`tr[data-twitch-id="${twitchUserId}"]`);
    const tokenTag = row.querySelector('.token-status-tag');
    tokenTag.className = 'tag is-info token-status-tag';
    tokenTag.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-pulse"></i></span><span>Validating...</span>';
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
            tokenTag.className = 'tag is-success token-status-tag';
            const hours = Math.floor(data.expires_in / 3600);
            tokenTag.innerHTML = `<span class="icon"><i class="fas fa-check-circle"></i></span><span>Valid (${hours}h)</span>`;
            // Update mod status if returned from server
            if (typeof data.is_mod !== 'undefined') {
                const modTag = row.querySelector('.mod-status-tag');
                const startStableBtn = row.querySelector('.start-stable-btn');
                const startBetaBtn = row.querySelector('.start-beta-btn');
                const makeModBtn = row.querySelector('.make-mod-btn');
                const username = row.getAttribute('data-username');
                const isRunning = runningBots.find(bot => bot.username === username);
                // Skip mod check for BotOfTheSpecter's own channel
                if (twitchUserId === '971436498') {
                    modTag.className = 'tag is-info mod-status-tag';
                    modTag.innerHTML = '<span class="icon"><i class="fas fa-info-circle"></i></span><span>Skipped</span>';
                    if (makeModBtn) makeModBtn.style.display = 'none';
                    if (startStableBtn && !isRunning) startStableBtn.disabled = false;
                    if (startBetaBtn && !isRunning) startBetaBtn.disabled = false;
                } else if (data.is_mod) {
                    modTag.className = 'tag is-success mod-status-tag';
                    modTag.innerHTML = '<span class="icon"><i class="fas fa-check-circle"></i></span><span>Is Moderator</span>';
                    if (makeModBtn) makeModBtn.style.display = 'none';
                    if (startStableBtn && !isRunning) startStableBtn.disabled = false;
                    if (startBetaBtn && !isRunning) startBetaBtn.disabled = false;
                } else if (data.is_banned) {
                    // Bot is BANNED
                    modTag.className = 'tag is-danger mod-status-tag';
                    modTag.innerHTML = '<span class="icon"><i class="fas fa-ban"></i></span><span>Banned</span>';
                    modTag.title = 'Reason: ' + (data.ban_reason || 'No reason provided');
                    if (makeModBtn) makeModBtn.style.display = 'none';
                } else {
                    // Not a moderator: show a warning state but allow admins to start the bot
                    modTag.className = 'tag is-warning mod-status-tag';
                    modTag.innerHTML = '<span class="icon"><i class="fas fa-exclamation-triangle"></i></span><span>Not a Moderator</span>';
                    if (makeModBtn) makeModBtn.style.display = 'inline-flex';
                    // Allow start button even if the bot is not a moderator (admins can start regardless)
                    if (startStableBtn && !isRunning) startStableBtn.disabled = false;
                    if (startBetaBtn && !isRunning) startBetaBtn.disabled = false;
                    // If the bot is running but missing mod, indicate it visually
                    if (isRunning) {
                        modTag.className = 'tag is-warning mod-status-tag';
                        modTag.innerHTML = '<span class="icon"><i class="fas fa-exclamation-triangle"></i></span><span>Running Without Mod!</span>';
                    }
                }
            }
        } else {
            // Token invalid — attempt an automatic silent renewal
            tokenTag.className = 'tag is-warning token-status-tag';
            tokenTag.innerHTML = '<span class="icon"><i class="fas fa-exclamation-triangle"></i></span><span>Invalid — attempting renew...</span>';
            const renewed = await renewUserToken(twitchUserId, true);
            if (renewed) {
                tokenTag.className = 'tag is-success token-status-tag';
                tokenTag.innerHTML = '<span class="icon"><i class="fas fa-check-circle"></i></span><span>Renewed</span>';
                const startStableBtn = row.querySelector('.start-stable-btn');
                const startBetaBtn = row.querySelector('.start-beta-btn');
                if (startStableBtn) startStableBtn.disabled = false;
                if (startBetaBtn) startBetaBtn.disabled = false;
            } else {
                tokenTag.className = 'tag is-danger token-status-tag';
                tokenTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Renewal Failed</span>';
            }
        }
    } catch (error) {
        tokenTag.className = 'tag is-danger token-status-tag';
        tokenTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Error</span>';
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
    makeModBtn.classList.add('is-loading');
    modTag.className = 'tag is-info mod-status-tag';
    modTag.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-pulse"></i></span><span>Adding as Mod...</span>';
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
            modTag.className = 'tag is-success mod-status-tag';
            modTag.innerHTML = '<span class="icon"><i class="fas fa-check-circle"></i></span><span>Is Moderator</span>';
            makeModBtn.style.display = 'none';
            if (startStableBtn) startStableBtn.disabled = false;
            if (startBetaBtn) startBetaBtn.disabled = false;
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: 'BotOfTheSpecter is now a moderator!',
                timer: 2000,
                showConfirmButton: false
            });
        } else {
            modTag.className = 'tag is-danger mod-status-tag';
            modTag.innerHTML = '<span class="icon"><i class="fas fa-exclamation-circle"></i></span><span>Failed</span>';
            makeModBtn.disabled = false;
            makeModBtn.classList.remove('is-loading');
            Swal.fire({
                icon: 'error',
                title: 'Failed to Add Moderator',
                html: `Could not make BotOfTheSpecter a moderator.<br><br>${escapeHtml(data.message || 'Unknown error')}`,
                confirmButtonText: 'OK'
            });
        }
    } catch (error) {
        modTag.className = 'tag is-danger mod-status-tag';
        modTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Error</span>';
        makeModBtn.disabled = false;
        makeModBtn.classList.remove('is-loading');
        console.error('Error making bot mod:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'An error occurred while making bot a moderator'
        });
    }
}

async function checkBotModStatus(twitchUserId) {
    const row = document.querySelector(`tr[data-twitch-id="${twitchUserId}"]`);
    const modTag = row.querySelector('.mod-status-tag');
    try {
        // First validate the token to ensure we have a valid access token
        await validateUserToken(twitchUserId);
        // Now check mod status
        modTag.className = 'tag is-info mod-status-tag';
        modTag.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-pulse"></i></span><span>Checking...</span>';
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
                modTag.className = 'tag is-success mod-status-tag';
                modTag.innerHTML = '<span class="icon"><i class="fas fa-check"></i></span><span>Moderator</span>';
            } else if (result.is_banned) {
                modTag.className = 'tag is-danger mod-status-tag';
                modTag.innerHTML = '<span class="icon"><i class="fas fa-ban"></i></span><span>Banned</span>';
                modTag.title = 'Reason: ' + (result.ban_reason || 'No reason provided');
            } else {
                modTag.className = 'tag is-warning mod-status-tag';
                modTag.innerHTML = '<span class="icon"><i class="fas fa-times"></i></span><span>Not Mod</span>';
            }
        } else {
            modTag.className = 'tag is-danger mod-status-tag';
            modTag.innerHTML = '<span class="icon"><i class="fas fa-exclamation-triangle"></i></span><span>Check Failed</span>';
        }
    } catch (e) {
        console.error('Error checking mod/ban status:', e);
        modTag.className = 'tag is-danger mod-status-tag';
        modTag.innerHTML = '<span class="icon"><i class="fas fa-exclamation-triangle"></i></span><span>Error</span>';
    }
}

async function renewUserToken(twitchUserId, silent = false) {
    const row = document.querySelector(`tr[data-twitch-id="${twitchUserId}"]`);
    const tokenTag = row.querySelector('.token-status-tag');
    tokenTag.className = 'tag is-info token-status-tag';
    tokenTag.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-pulse"></i></span><span>Renewing...</span>';
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
            tokenTag.className = 'tag is-success token-status-tag';
            const hours = Math.floor(data.expires_in / 3600);
            tokenTag.innerHTML = `<span class="icon"><i class="fas fa-check-circle"></i></span><span>Renewed (${hours}h)</span>`;
            return true;
        } else {
            tokenTag.className = 'tag is-danger token-status-tag';
            tokenTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Renewal Failed</span>';
            if (!silent) {
                Swal.fire({
                    icon: 'error',
                    title: 'Token Renewal Failed',
                    text: data.message || 'Could not renew token'
                });
            }
            return false;
        }
    } catch (error) {
        tokenTag.className = 'tag is-danger token-status-tag';
        tokenTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Error</span>';
        console.error('Error renewing token:', error);
        if (!silent) {
            Swal.fire({
                icon: 'error',
                title: 'Token Renewal Error',
                text: 'An error occurred while renewing the token'
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
    const currentBtn = botType === 'beta' ? startBetaBtn : startStableBtn;
    const otherBtn = botType === 'beta' ? startStableBtn : startBetaBtn;
    // Check if bot is already running
    const isRunning = runningBots.find(bot => bot.username === username);
    if (isRunning) {
        // If trying to start same type, just show info
        if (isRunning.bot_type === botType) {
            Swal.fire({toast: true, position: 'top-end', icon: 'info', title: `${botType === 'beta' ? 'Beta' : 'Stable'} bot for ${username} is already running`, showConfirmButton: false, timer: 1500});
            return;
        }
        // If switching bot types, confirm with user
        const result = await Swal.fire({
            title: 'Switch Bot Type?',
            text: `This will stop the ${isRunning.bot_type} bot and start the ${botType} bot.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, switch it!',
            cancelButtonText: 'Cancel'
        });
        if (!result.isConfirmed) return;
    }
    // Start the bot immediately via AJAX without performing token/mod checks to avoid slowing bulk operations
    if (currentBtn) {
        currentBtn.disabled = true;
        currentBtn.classList.add('is-loading');
    }
    try {
        botTag.className = 'tag is-info bot-status-tag';
        botTag.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-pulse"></i></span><span>Starting ' + (botType === 'beta' ? 'Beta' : 'Stable') + '...</span>';
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
            botTag.className = 'tag is-success bot-status-tag';
            botTag.innerHTML = '<span class="icon"><i class="fas fa-check-circle"></i></span><span>Running</span>';
            const botTypeTag = row.querySelector('.bot-type-tag');
            if (botTypeTag) {
                botTypeTag.className = botType === 'beta' ? 'tag is-info bot-type-tag' : 'tag is-success bot-type-tag';
                botTypeTag.innerHTML = '<span>' + (botType === 'beta' ? 'Beta' : 'Stable') + '</span>';
            }
            if (startStableBtn) startStableBtn.style.display = 'none';
            if (startBetaBtn) startBetaBtn.style.display = 'none';
            // Remove old entry if switching types
            runningBots = runningBots.filter(bot => bot.username !== username);
            // Add to runningBots array optimistically
            runningBots.push({username: username, bot_type: botType, pid: 0});
            // Hide the row since bot is now running
            row.style.display = 'none';
            // Non-blocking toast notification only
            Swal.fire({toast: true, position: 'top-end', icon: 'success', title: `Started ${botType === 'beta' ? 'beta' : 'stable'} bot for ${username}`, showConfirmButton: false, timer: 1500});
            // Schedule a debounced refresh to verify actual status (non-blocking)
            scheduleRefresh(2000);
        } else {
            botTag.className = 'tag is-danger bot-status-tag';
            botTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Start Failed</span>';
            const msg = (startData && startData.message) ? startData.message : 'Could not start bot';
            Swal.fire({toast: true, position: 'top-end', icon: 'error', title: `Start failed: ${msg}`, showConfirmButton: false, timer: 3000});
        }
    } catch (error) {
        botTag.className = 'tag is-danger bot-status-tag';
        botTag.innerHTML = '<span class="icon"><i class="fas fa-times-circle"></i></span><span>Error</span>';
        console.error('Error starting bot:', error);
        Swal.fire({toast: true, position: 'top-end', icon: 'error', title: 'Error starting bot', showConfirmButton: false, timer: 3000});
    } finally {
        if (currentBtn) {
            currentBtn.disabled = false;
            currentBtn.classList.remove('is-loading');
        }
        if (otherBtn) {
            otherBtn.disabled = false;
        }
    }
}

// Function to switch bot type
window.switchBotType = async function(username, twitchUserId, targetType) {
    const result = await Swal.fire({
        title: 'Switch Bot Type?',
        text: `This will switch this bot to ${targetType === 'beta' ? 'Beta' : 'Stable'} version.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: `Yes, switch to ${targetType === 'beta' ? 'Beta' : 'Stable'}!`,
        cancelButtonText: 'Cancel'
    });
    if (result.isConfirmed) {
        // Use the existing startUserBot function which handles stopping and switching
        await startUserBot(username, twitchUserId, targetType);
    }
};

// Function to restart bot
window.restartBot = function(username, botType, pid, element) {
    Swal.fire({
        title: 'Are you sure?',
        text: 'Do you want to restart this bot? It will be stopped and started again.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, restart it!'
    }).then((result) => {
        if (result.isConfirmed) {
            // Log the restart details for debugging
            console.log('Restarting bot:', {username: username, botType: botType, pid: pid});
            
            // Show loading toast
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'info',
                title: 'Restarting ' + botType + ' bot...',
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
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: data.message || 'Bot restarted successfully',
                        showConfirmButton: false,
                        timer: 3000
                    });
                    // Refresh the bot status to update the display
                    setTimeout(() => refreshBotStatus(), 1500);
                } else {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'error',
                        title: data.message || 'Failed to restart bot',
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
                    title: 'Network error restarting bot',
                    showConfirmButton: false,
                    timer: 3000
                });
            });
        }
    });
};

// Function to restart all running bots
window.restartAllBots = async function() {
    if (runningBots.length === 0) {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'info',
            title: 'No running bots to restart',
            showConfirmButton: false,
            timer: 2000
        });
        return;
    }
    // Confirm the action
    const result = await Swal.fire({
        title: 'Restart All Bots?',
        text: `This will restart ${runningBots.length} running bot(s). Continue?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, restart all!'
    });
    if (!result.isConfirmed) return;
    // Disable the restart all button during the process
    const restartAllBtn = document.getElementById('restart-all-btn');
    if (restartAllBtn) {
        restartAllBtn.disabled = true;
        restartAllBtn.classList.add('is-loading');
    }
    // Store original PIDs for comparison
    const botRestartTracking = runningBots.map(bot => ({
        username: bot.username,
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
        title: `Starting restart process for ${runningBots.length} bot(s)...`
    });
    // Restart each bot sequentially
    for (let i = 0; i < botRestartTracking.length; i++) {
        const botInfo = botRestartTracking[i];
        // Update progress toast with current bot
        progressToast.fire({
            icon: 'info',
            title: `Restarting ${botInfo.username} (${i + 1}/${botRestartTracking.length})...`
        });
        try {
            // Send restart request
            const formData = new FormData();
            formData.append('restart_bot', '1');
            formData.append('username', botInfo.username);
            formData.append('bot_type', 'stable');
            formData.append('pid', botInfo.originalPid);
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                // Wait a moment for the bot to fully restart
                await new Promise(resolve => setTimeout(resolve, 2000));
                // Refresh bot status to get new PID
                const statusResponse = await fetch('?get_running_bots=1');
                const statusData = await statusResponse.json();
                if (statusData.success) {
                    // Find the bot in the new list
                    const updatedBot = statusData.bots.find(b => b.username === botInfo.username);
                    if (updatedBot && updatedBot.pid) {
                        botInfo.newPid = updatedBot.pid;
                        // Check if PID changed
                        if (botInfo.newPid !== botInfo.originalPid) {
                            botInfo.restarted = true;
                            successCount++;
                        } else {
                            // PID didn't change, consider it a failure
                            failCount++;
                            console.warn(`Bot ${botInfo.username} has same PID after restart: ${botInfo.originalPid}`);
                        }
                    } else {
                        // Bot not found in running list after restart
                        failCount++;
                        console.warn(`Bot ${botInfo.username} not found in running list after restart`);
                    }
                }
            } else {
                failCount++;
                console.error(`Failed to restart ${botInfo.username}:`, data.message);
            }
        } catch (error) {
            failCount++;
            console.error(`Error restarting ${botInfo.username}:`, error);
        }
        // Small delay between restarts
        if (i < botRestartTracking.length - 1) {
            await new Promise(resolve => setTimeout(resolve, 500));
        }
    }
    // Re-enable button
    if (restartAllBtn) {
        restartAllBtn.disabled = false;
        restartAllBtn.classList.remove('is-loading');
    }
    // Refresh the bot status one final time
    await refreshBotStatus();
    // Close the progress toast before showing final results
    Swal.close();
    // Show final results
    if (failCount === 0) {
        Swal.fire({
            icon: 'success',
            title: 'All bots restarted!',
            html: `Successfully restarted ${successCount} bot(s).<br><br>` +
                  botRestartTracking.map(b => 
                      `<span class="has-text-weight-bold">${b.username}</span>: PID ${b.originalPid} → ${b.newPid || 'N/A'}`
                  ).join('<br>'),
            confirmButtonText: 'OK'
        });
    } else {
        Swal.fire({
            icon: 'warning',
            title: 'Restart Complete with Issues',
            html: `Successfully restarted: ${successCount}<br>` +
                  `Failed: ${failCount}<br><br>` +
                  botRestartTracking.map(b => {
                      const status = b.restarted ? '✅' : '❌';
                      return `${status} <span class="has-text-weight-bold">${b.username}</span>: ${b.originalPid} → ${b.newPid || 'Failed'}`;
                  }).join('<br>'),
            confirmButtonText: 'OK'
        });
    }
};
</script>
<?php
$content = ob_get_clean();
include "admin_layout.php";
?>