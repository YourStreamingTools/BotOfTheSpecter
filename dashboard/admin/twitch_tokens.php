<?php
ob_start();
session_start();
session_write_close();
require_once __DIR__ . '/admin_access.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';
$pageTitle = 'Twitch App Access Tokens';
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';

function pickFirstExistingKey(array $row, array $candidates) {
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row)) {
            return $candidate;
        }
    }
    return null;
}

function isSafeColumnName($column) {
    return is_string($column) && preg_match('/^[A-Za-z0-9_]+$/', $column);
}

function fetchWebsiteTwitchSettings($conn) {
    $settings = [
        'client_id' => isset($GLOBALS['clientID']) ? trim((string) $GLOBALS['clientID']) : '',
        'client_secret' => isset($GLOBALS['clientSecret']) ? trim((string) $GLOBALS['clientSecret']) : '',
        'chat_token' => isset($GLOBALS['oauth']) ? trim((string) $GLOBALS['oauth']) : '',
        'expires_at' => null,
        'row' => null,
        'columns' => [
            'client_id' => null,
            'client_secret' => null,
            'chat_token' => null,
            'expires_at' => null,
        ],
    ];
    if (!isset($conn) || !$conn) {
        return $settings;
    }
    $res = $conn->query("SELECT * FROM bot_chat_token ORDER BY id ASC LIMIT 1");
    if (!$res) {
        return $settings;
    }
    $row = $res->fetch_assoc();
    if (!$row || !is_array($row)) {
        return $settings;
    }
    $settings['row'] = $row;
    $clientIdKey = pickFirstExistingKey($row, ['twitch_client_id', 'client_id', 'clientID']);
    $clientSecretKey = pickFirstExistingKey($row, ['twitch_client_secret', 'client_secret', 'clientSecret']);
    $chatTokenKey = pickFirstExistingKey($row, ['twitch_oauth_api_token', 'oauth', 'chat_oauth_token', 'twitch_oauth_token', 'twitch_access_token', 'bot_oauth_token']);
    $expiresAtKey = pickFirstExistingKey($row, ['twitch_oauth_api_expires_at', 'oauth_expires_at', 'chat_token_expires_at', 'token_expires', 'token_expires_at']);
    $settings['columns']['client_id'] = $clientIdKey;
    $settings['columns']['client_secret'] = $clientSecretKey;
    $settings['columns']['chat_token'] = $chatTokenKey;
    $settings['columns']['expires_at'] = $expiresAtKey;
    if ($clientIdKey && !empty($row[$clientIdKey])) {
        $settings['client_id'] = trim((string) $row[$clientIdKey]);
    }
    if ($clientSecretKey && !empty($row[$clientSecretKey])) {
        $settings['client_secret'] = trim((string) $row[$clientSecretKey]);
    }
    if ($chatTokenKey && !empty($row[$chatTokenKey])) {
        $settings['chat_token'] = trim((string) $row[$chatTokenKey]);
    }
    if ($expiresAtKey && !empty($row[$expiresAtKey])) {
        $settings['expires_at'] = (string) $row[$expiresAtKey];
    }
    return $settings;
}

function requestTwitchAppAccessToken($clientID, $clientSecret) {
    if (empty($clientID) || empty($clientSecret)) {
        return ['success' => false, 'error' => 'Client credentials are required'];
    }
    $url = 'https://id.twitch.tv/oauth2/token';
    $data = [
        'client_id' => $clientID,
        'client_secret' => $clientSecret,
        'grant_type' => 'client_credentials'
    ];
    $ch = curl_init($url);
    if (!$ch) {
        return ['success' => false, 'error' => 'cURL initialization failed.'];
    }
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError) {
        return ['success' => false, 'error' => 'cURL error: ' . $curlError];
    }
    if ($httpCode !== 200) {
        $error = json_decode($response, true);
        $errorMsg = isset($error['message']) ? $error['message'] : 'Failed to generate token (HTTP ' . $httpCode . ').';
        return ['success' => false, 'error' => $errorMsg];
    }
    $result = json_decode($response, true);
    if ($result === null || !isset($result['access_token'])) {
        return ['success' => false, 'error' => 'Invalid response from Twitch API.'];
    }
    return [
        'success' => true,
        'access_token' => $result['access_token'],
        'expires_in' => intval($result['expires_in'] ?? 0),
        'token_type' => $result['token_type'] ?? 'bearer'
    ];
}

function persistWebsiteChatToken($conn, $accessToken, $expiresIn = 0) {
    if (!isset($conn) || !$conn) {
        return ['success' => false, 'error' => 'Database connection failed'];
    }
    $settings = fetchWebsiteTwitchSettings($conn);
    $tokenColumn = $settings['columns']['chat_token'];
    $expiresAtColumn = $settings['columns']['expires_at'];
    $hasRow = is_array($settings['row']);
    $expiresAt = null;
    if (intval($expiresIn) > 0) {
        $expiresAt = date('Y-m-d H:i:s', time() + intval($expiresIn));
    }
    if (!$hasRow) {
        $insert = $conn->prepare("INSERT INTO bot_chat_token (oauth, twitch_oauth_api_token, twitch_oauth_api_expires_at) VALUES (?, ?, ?)");
        if (!$insert) {
            return ['success' => false, 'error' => 'Failed to prepare bot_chat_token insert: ' . $conn->error];
        }
        $insert->bind_param('sss', $accessToken, $accessToken, $expiresAt);
        if (!$insert->execute()) {
            $error = $insert->error;
            $insert->close();
            return ['success' => false, 'error' => 'Failed to insert bot_chat_token row: ' . $error];
        }
        $insert->close();
        return ['success' => true];
    }
    if (!$tokenColumn || !isSafeColumnName($tokenColumn)) {
        return ['success' => false, 'error' => 'No suitable chat token column found in bot_chat_token table'];
    }
    $setSql = [];
    $types = '';
    $values = [];
    $setSql[] = "`{$tokenColumn}` = ?";
    $types .= 's';
    $values[] = $accessToken;
    if ($expiresAtColumn && isSafeColumnName($expiresAtColumn)) {
        $setSql[] = "`{$expiresAtColumn}` = ?";
        $types .= 's';
        $values[] = $expiresAt;
    }
    $sql = "UPDATE bot_chat_token SET " . implode(', ', $setSql) . " LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['success' => false, 'error' => 'Failed to prepare bot_chat_token update: ' . $conn->error];
    }
    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to update bot_chat_token token: ' . $error];
    }
    $stmt->close();
    return ['success' => true];
}

$dbTwitchSettings = fetchWebsiteTwitchSettings($conn);
$clientID = $dbTwitchSettings['client_id'];
$clientSecret = $dbTwitchSettings['client_secret'];
$oauth = $dbTwitchSettings['chat_token'];

// Handle AJAX request for token generation BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_token'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    try {
        $clientID = isset($_POST['client_id']) ? trim($_POST['client_id']) : '';
        $clientSecret = isset($_POST['client_secret']) ? trim($_POST['client_secret']) : '';
        // Fall back to config values if POST values are empty
        if (empty($clientID)) {
            $settings = fetchWebsiteTwitchSettings($conn);
            $clientID = $settings['client_id'] ?? '';
        }
        if (empty($clientSecret)) {
            $settings = isset($settings) ? $settings : fetchWebsiteTwitchSettings($conn);
            $clientSecret = $settings['client_secret'] ?? '';
        }
        if (empty($clientID) || empty($clientSecret)) {
            echo json_encode(['success' => false, 'error' => 'Client ID and Client Secret are required. Please configure them in your config file or enter them manually.']);
            exit;
        }
        $tokenResult = requestTwitchAppAccessToken($clientID, $clientSecret);
        if ($tokenResult['success']) {
            echo json_encode($tokenResult);
        } else {
            echo json_encode(['success' => false, 'error' => $tokenResult['error'] ?? 'Token generation failed']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request for token validation BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validate_token'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    try {
        $token = isset($_POST['access_token']) ? trim($_POST['access_token']) : '';
        $autoRenewIf24h = isset($_POST['auto_renew_if_24h']) && $_POST['auto_renew_if_24h'] === '1';
        $syncChatExpiry = isset($_POST['sync_chat_expiry']) && $_POST['sync_chat_expiry'] === '1';
        if (empty($token)) {
            echo json_encode(['success' => false, 'error' => 'Access token is required.']);
            exit;
        }
        $url = 'https://id.twitch.tv/oauth2/validate';
        $ch = curl_init($url);
        if (!$ch) {
            echo json_encode(['success' => false, 'error' => 'cURL initialization failed.']);
            exit;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: OAuth ' . $token
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($curlError) {
            echo json_encode(['success' => false, 'error' => 'cURL error: ' . $curlError]);
            exit;
        }
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if ($result === null) {
                echo json_encode(['success' => false, 'error' => 'Invalid JSON response from Twitch.']);
                exit;
            }
            $expiresIn = intval($result['expires_in'] ?? 0);
            if ($syncChatExpiry) {
                $syncPersistResult = persistWebsiteChatToken($conn, $token, $expiresIn);
                if (empty($syncPersistResult['success'])) {
                    echo json_encode(['success' => false, 'error' => $syncPersistResult['error'] ?? 'Failed to persist chat token expiry']);
                    exit;
                }
            }
            if ($autoRenewIf24h && $expiresIn > 0 && $expiresIn <= 86400) {
                $settings = fetchWebsiteTwitchSettings($conn);
                if (!empty($settings['client_id']) && !empty($settings['client_secret'])) {
                    $renewResult = requestTwitchAppAccessToken($settings['client_id'], $settings['client_secret']);
                    if (!empty($renewResult['success'])) {
                        $persistResult = persistWebsiteChatToken($conn, $renewResult['access_token'], $renewResult['expires_in'] ?? 0);
                        if (!empty($persistResult['success'])) {
                            echo json_encode([
                                'success' => true,
                                'validation' => $result,
                                'auto_renewed' => true,
                                'renewed_token' => $renewResult['access_token'],
                                'renewed_expires_in' => $renewResult['expires_in'] ?? 0
                            ]);
                            exit;
                        }
                    }
                }
            }
            echo json_encode([
                'success' => true,
                'validation' => $result
            ]);
        } else {
            $error = json_decode($response, true);
            $errorMsg = isset($error['message']) ? $error['message'] : 'Failed to validate token (HTTP ' . $httpCode . ').';
            echo json_encode(['success' => false, 'error' => $errorMsg]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request for chat token renewal and persist to bot_chat_token table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['renew_chat_token'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    try {
        $clientID = isset($_POST['client_id']) ? trim($_POST['client_id']) : '';
        $clientSecret = isset($_POST['client_secret']) ? trim($_POST['client_secret']) : '';
        if (empty($clientID) || empty($clientSecret)) {
            $settings = fetchWebsiteTwitchSettings($conn);
            if (empty($clientID)) {
                $clientID = $settings['client_id'] ?? '';
            }
            if (empty($clientSecret)) {
                $clientSecret = $settings['client_secret'] ?? '';
            }
        }
        if (empty($clientID) || empty($clientSecret)) {
            echo json_encode(['success' => false, 'error' => 'Client credentials not configured.']);
            exit;
        }
        $tokenResult = requestTwitchAppAccessToken($clientID, $clientSecret);
        if (empty($tokenResult['success'])) {
            echo json_encode(['success' => false, 'error' => $tokenResult['error'] ?? 'Failed to renew token']);
            exit;
        }
        $persistResult = persistWebsiteChatToken($conn, $tokenResult['access_token'], $tokenResult['expires_in'] ?? 0);
        if (empty($persistResult['success'])) {
            echo json_encode(['success' => false, 'error' => $persistResult['error'] ?? 'Failed to persist token']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'new_token' => $tokenResult['access_token'],
            'expires_in' => $tokenResult['expires_in'] ?? 0,
            'token_type' => $tokenResult['token_type'] ?? 'bearer'
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request for token renewal BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['renew_token'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    try {
        $userId = isset($_POST['twitch_user_id']) ? trim($_POST['twitch_user_id']) : '';
        if (empty($userId)) {
            echo json_encode(['success' => false, 'error' => 'User ID is required.']);
            exit;
        }
        $clientID = $GLOBALS['clientID'] ?? '';
        $clientSecret = $GLOBALS['clientSecret'] ?? '';
        if (empty($clientID) || empty($clientSecret)) {
            echo json_encode(['success' => false, 'error' => 'Client credentials not configured.']);
            exit;
        }
        // Validate database connection
        if (!isset($conn) || !$conn) {
            echo json_encode(['success' => false, 'error' => 'Database connection failed']);
            exit;
        }
        // Get the user's refresh token from the users table
        $stmt = $conn->prepare("SELECT refresh_token FROM users WHERE twitch_user_id = ? LIMIT 1");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'DB error: ' . $conn->error]);
            exit;
        }
        $stmt->bind_param('s', $userId);
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => 'DB query failed: ' . $stmt->error]);
            $stmt->close();
            exit;
        }
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit;
        }
        $refreshToken = $row['refresh_token'] ?? '';
        if (empty($refreshToken)) {
            echo json_encode(['success' => false, 'error' => 'No refresh token available for this user. User may need to re-authenticate.']);
            exit;
        }
        // Call Twitch token endpoint to refresh using refresh_token
        $url = 'https://id.twitch.tv/oauth2/token';
        $data = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientID,
            'client_secret' => $clientSecret
        ];
        $ch = curl_init($url);
        if (!$ch) {
            echo json_encode(['success' => false, 'error' => 'cURL initialization failed.']);
            exit;
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($curlError) {
            echo json_encode(['success' => false, 'error' => 'cURL error: ' . $curlError]);
            exit;
        }
        if ($httpCode !== 200) {
            $errMsg = $response ? (json_decode($response, true)['message'] ?? $response) : 'HTTP ' . $httpCode;
            echo json_encode(['success' => false, 'error' => 'Failed to refresh token: ' . $errMsg]);
            exit;
        }
        $result = json_decode($response, true);
        if ($result === null) {
            echo json_encode(['success' => false, 'error' => 'Invalid JSON response from Twitch.']);
            exit;
        }
        if (!isset($result['access_token'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid response from Twitch: ' . json_encode($result)]);
            exit;
        }
        $newAccess = $result['access_token'];
        $newRefresh = $result['refresh_token'] ?? $refreshToken;
        $newExpiresIn = $result['expires_in'] ?? null;
        // Update the users table with new tokens
        $upd = $conn->prepare("UPDATE users SET access_token = ?, refresh_token = ? WHERE twitch_user_id = ? LIMIT 1");
        if (!$upd) {
            echo json_encode(['success' => false, 'error' => 'DB update failed: ' . $conn->error]);
            exit;
        }
        $upd->bind_param('sss', $newAccess, $newRefresh, $userId);
        if (!$upd->execute()) {
            $err = $upd->error;
            $upd->close();
            echo json_encode(['success' => false, 'error' => 'DB update failed: ' . $err]);
            exit;
        }
        $upd->close();
        // Also update/replace the bot access token in twitch_bot_access so any services using
        // the bot access token will use the newly issued token (invalidating the old one)
        $upd2 = $conn->prepare("INSERT INTO twitch_bot_access (twitch_user_id, twitch_access_token) VALUES (?, ?) ON DUPLICATE KEY UPDATE twitch_access_token = VALUES(twitch_access_token)");
        if ($upd2) {
            $upd2->bind_param('ss', $userId, $newAccess);
            if (!$upd2->execute()) {
                // non-fatal: log or include in response
                $upd2->close();
                echo json_encode(['success' => false, 'error' => 'Failed to update twitch_bot_access: ' . $conn->error]);
                exit;
            }
            $upd2->close();
        } else {
            // non-fatal but inform caller
            echo json_encode(['success' => false, 'error' => 'Failed to prepare twitch_bot_access update: ' . $conn->error]);
            exit;
        }
        echo json_encode([
            'success' => true,
            'new_token' => $newAccess,
            'expires_in' => $newExpiresIn ?? 0
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request to save token validation results to cache
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_token_cache'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    try {
        $tokenId = isset($_POST['token_id']) ? trim($_POST['token_id']) : '';
        $tokenType = isset($_POST['token_type']) ? trim($_POST['token_type']) : ''; // 'regular' or 'custom'
        $expiresIn = isset($_POST['expires_in']) ? intval($_POST['expires_in']) : 0;
        $isValid = isset($_POST['is_valid']) ? filter_var($_POST['is_valid'], FILTER_VALIDATE_BOOLEAN) : false;
        if (empty($tokenId) || empty($tokenType)) {
            echo json_encode(['success' => false, 'error' => 'token_id and token_type are required']);
            exit;
        }
        // Define cache file location (outside web root or in a secure directory)
        $cacheDir = '/var/www/cache/tokens';
        $cacheFile = $cacheDir . '/token_validation_cache.json';
        // Create cache directory if it doesn't exist
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        // Load existing cache
        $cache = [];
        if (file_exists($cacheFile)) {
            $cacheContent = file_get_contents($cacheFile);
            $cache = json_decode($cacheContent, true) ?? [];
        }
        // Update or add token entry
        $cache[$tokenId] = [
            'token_type' => $tokenType,
            'is_valid' => $isValid,
            'expires_in' => $expiresIn,
            'expires_at' => $expiresIn > 0 ? date('Y-m-d H:i:s', time() + $expiresIn) : null,
            'last_validated' => date('Y-m-d H:i:s'),
            'timestamp' => time()
        ];
        // Save cache
        if (file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) {
            echo json_encode(['success' => true, 'message' => 'Token validation cached']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save cache file']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request to fetch current user token from database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_user_token'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    try {
        $userId = isset($_POST['twitch_user_id']) ? trim($_POST['twitch_user_id']) : '';
        if (empty($userId)) {
            echo json_encode(['success' => false, 'error' => 'User ID is required.']);
            exit;
        }
        // Validate database connection
        if (!isset($conn) || !$conn) {
            echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
            exit;
        }
        // Fetch current token from twitch_bot_access table
        $stmt = $conn->prepare("SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = ? LIMIT 1");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Database query preparation failed.']);
            exit;
        }
        $stmt->bind_param('s', $userId);
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => 'Database query execution failed.']);
            $stmt->close();
            exit;
        }
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'User token not found in twitch_bot_access table.']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'access_token' => $row['twitch_access_token'] ?? ''
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request to fetch current custom bot token from database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_custom_token'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    try {
        $botChannelId = isset($_POST['bot_channel_id']) ? trim($_POST['bot_channel_id']) : '';
        $botUsername = isset($_POST['bot_username']) ? trim($_POST['bot_username']) : '';
        if (empty($botChannelId) && empty($botUsername)) {
            echo json_encode(['success' => false, 'error' => 'Bot channel ID or bot username is required.']);
            exit;
        }
        // Validate database connection
        if (!isset($conn) || !$conn) {
            echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
            exit;
        }
        // Fetch current token from database using channel id if available, otherwise username
        if (!empty($botChannelId)) {
            $stmt = $conn->prepare("SELECT access_token, token_expires FROM custom_bots WHERE bot_channel_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $botChannelId);
            }
        } else {
            $stmt = $conn->prepare("SELECT access_token, token_expires FROM custom_bots WHERE bot_username = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $botUsername);
            }
        }
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Database query preparation failed.']);
            exit;
        }
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => 'Database query execution failed.']);
            $stmt->close();
            exit;
        }
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        // If not found in custom_bots, try custom_module_bots
        if (!$row) {
            if (!empty($botChannelId)) {
                $stmt2 = $conn->prepare("SELECT access_token, token_expires FROM custom_module_bots WHERE bot_channel_id = ? LIMIT 1");
                if ($stmt2) {
                    $stmt2->bind_param('s', $botChannelId);
                    $stmt2->execute();
                    $res2 = $stmt2->get_result();
                    $row = $res2 ? $res2->fetch_assoc() : null;
                    $stmt2->close();
                }
            }
            if (!$row && !empty($botUsername)) {
                $stmt3 = $conn->prepare("SELECT access_token, token_expires FROM custom_module_bots WHERE bot_username = ? LIMIT 1");
                if ($stmt3) {
                    $stmt3->bind_param('s', $botUsername);
                    $stmt3->execute();
                    $res3 = $stmt3->get_result();
                    $row = $res3 ? $res3->fetch_assoc() : null;
                    $stmt3->close();
                }
            }
        }
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Custom bot not found.']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'access_token' => $row['access_token'] ?? '',
            'token_expires' => $row['token_expires'] ?? '-'
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request to load token validation cache
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['load_token_cache'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    try {
        $cacheFile = '/var/www/cache/tokens/token_validation_cache.json';
        
        if (file_exists($cacheFile)) {
            $cacheContent = file_get_contents($cacheFile);
            $cache = json_decode($cacheContent, true) ?? [];
            echo json_encode(['success' => true, 'cache' => $cache]);
        } else {
            echo json_encode(['success' => true, 'cache' => []]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request to renew a custom bot's user token using its refresh_token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['renew_custom'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    try {
        $botChannelId = isset($_POST['bot_channel_id']) ? trim($_POST['bot_channel_id']) : '';
        $botUsername = isset($_POST['bot_username']) ? trim($_POST['bot_username']) : '';
        if (empty($botChannelId) && empty($botUsername)) {
            echo json_encode(['success' => false, 'error' => 'bot_channel_id or bot_username is required']);
            exit;
        }
        // Load client credentials from config
        $clientID = $GLOBALS['clientID'] ?? '';
        $clientSecret = $GLOBALS['clientSecret'] ?? '';
        if (empty($clientID) || empty($clientSecret)) {
            echo json_encode(['success' => false, 'error' => 'Client credentials not configured.']);
            exit;
        }
        // Validate database connection
        if (!isset($conn) || !$conn) {
            echo json_encode(['success' => false, 'error' => 'Database connection failed']);
            exit;
        }
        // Find the custom bot row (search custom_bots first, then custom_module_bots)
        $foundIn = null;
        $row = null;
        if (!empty($botChannelId)) {
            $stmt = $conn->prepare("SELECT refresh_token FROM custom_bots WHERE bot_channel_id = ? LIMIT 1");
            if ($stmt) { $stmt->bind_param('s', $botChannelId); $stmt->execute(); $res = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close(); }
        } elseif (!empty($botUsername)) {
            $stmt = $conn->prepare("SELECT refresh_token FROM custom_bots WHERE bot_username = ? LIMIT 1");
            if ($stmt) { $stmt->bind_param('s', $botUsername); $stmt->execute(); $res = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close(); }
        }
        if ($row) {
            $foundIn = 'custom_bots';
        } else {
            // try custom_module_bots
            if (!empty($botChannelId)) {
                $stmt2 = $conn->prepare("SELECT refresh_token FROM custom_module_bots WHERE bot_channel_id = ? LIMIT 1");
                if ($stmt2) { $stmt2->bind_param('s', $botChannelId); $stmt2->execute(); $res2 = $stmt2->get_result(); $row = $res2 ? $res2->fetch_assoc() : null; $stmt2->close(); }
            }
            if (!$row && !empty($botUsername)) {
                $stmt3 = $conn->prepare("SELECT refresh_token FROM custom_module_bots WHERE bot_username = ? LIMIT 1");
                if ($stmt3) { $stmt3->bind_param('s', $botUsername); $stmt3->execute(); $res3 = $stmt3->get_result(); $row = $res3 ? $res3->fetch_assoc() : null; $stmt3->close(); }
            }
            if ($row) {
                $foundIn = 'custom_module_bots';
            }
        }
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Custom bot not found']);
            exit;
        }
        $refreshToken = $row['refresh_token'] ?? '';
        if (empty($refreshToken)) {
            echo json_encode(['success' => false, 'error' => 'No refresh token available for this custom bot']);
            exit;
        }
        // Call Twitch token endpoint to refresh
        $url = 'https://id.twitch.tv/oauth2/token';
        $data = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientID,
            'client_secret' => $clientSecret
        ];
        $ch = curl_init($url);
        if (!$ch) {
            echo json_encode(['success' => false, 'error' => 'cURL initialization failed.']);
            exit;
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($curlError) {
            echo json_encode(['success' => false, 'error' => 'cURL error: ' . $curlError]);
            exit;
        }
        if ($httpCode !== 200) {
            $errMsg = $response ? (json_decode($response, true)['message'] ?? $response) : 'HTTP ' . $httpCode;
            echo json_encode(['success' => false, 'error' => 'Failed to refresh token: ' . $errMsg]);
            exit;
        }
        $result = json_decode($response, true);
        if ($result === null) {
            echo json_encode(['success' => false, 'error' => 'Invalid JSON response from Twitch.']);
            exit;
        }
        if (!isset($result['access_token'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid response from Twitch: ' . json_encode($result)]);
            exit;
        }
        $newAccess = $result['access_token'];
        $newRefresh = $result['refresh_token'] ?? $refreshToken;
        $newExpiresIn = $result['expires_in'] ?? null;
        $newExpiresAt = $newExpiresIn ? date('Y-m-d H:i:s', time() + intval($newExpiresIn)) : null;
        // Persist into the table where the row was found
        if ($foundIn === 'custom_module_bots') {
            // update by channel id if available, otherwise by username
            if (!empty($botChannelId)) {
                $upd = $conn->prepare("UPDATE custom_module_bots SET access_token = ?, token_expires = ?, refresh_token = ? WHERE bot_channel_id = ? LIMIT 1");
                if ($upd) {
                    $upd->bind_param('ssss', $newAccess, $newExpiresAt, $newRefresh, $botChannelId);
                }
            } else {
                $upd = $conn->prepare("UPDATE custom_module_bots SET access_token = ?, token_expires = ?, refresh_token = ? WHERE bot_username = ? LIMIT 1");
                if ($upd) {
                    $upd->bind_param('ssss', $newAccess, $newExpiresAt, $newRefresh, $botUsername);
                }
            }
        } else {
            $upd = $conn->prepare("UPDATE custom_bots SET access_token = ?, token_expires = ?, refresh_token = ? WHERE bot_channel_id = ? LIMIT 1");
            if ($upd) {
                $upd->bind_param('ssss', $newAccess, $newExpiresAt, $newRefresh, $botChannelId);
            }
        }
        if (!$upd) {
            echo json_encode(['success' => false, 'error' => 'DB update failed: ' . $conn->error]);
            exit;
        }
        if (!$upd->execute()) {
            $err = $upd->error;
            $upd->close();
            echo json_encode(['success' => false, 'error' => 'DB update failed: ' . $err]);
            exit;
        }
        $upd->close();
        echo json_encode(['success' => true, 'new_token' => $newAccess, 'expires_at' => $newExpiresAt]);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

ob_start();
?>
<div class="sp-card">
    <div class="sp-card-body">
    <h1 style="font-size:1.25rem;font-weight:700;margin-bottom:0.75rem;"><span class="icon"><i class="fab fa-twitch"></i></span> Twitch App Access Tokens</h1>
    <p class="mb-4">Generate App Access Tokens for Twitch API usage, such as for chatbot badge display.</p>
    <div style="margin-bottom:1rem;">
        <button class="sp-btn sp-btn-info" id="learn-more-btn">
            <span class="icon"><i class="fas fa-info-circle"></i></span>
            <span>What is an App Access Token?</span>
        </button>
    </div>
    <div class="sp-card">
        <div class="sp-card-body">
        <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:0.75rem;">Enter Twitch Application Credentials</h3>
        <p class="mb-4">Fields are pre-populated with your configured credentials. You can modify them if needed.</p>
        <div class="sp-form-group">
            <label class="sp-label">Client ID</label>
            <input class="sp-input" type="text" id="client-id" placeholder="Enter your Twitch Client ID" value="<?php echo htmlspecialchars($clientID ?? ''); ?>" required>
            <small class="sp-text-muted">Found in your Twitch Developer Console application settings</small>
        </div>
        <div class="sp-form-group">
            <label class="sp-label">Client Secret</label>
            <input class="sp-input" type="password" id="client-secret" placeholder="Enter your Twitch Client Secret" value="<?php echo htmlspecialchars($clientSecret ?? ''); ?>" required>
            <small class="sp-text-muted">Keep this secret! Found in your Twitch Developer Console application settings</small>
        </div>
        <div style="margin-bottom:1rem;">
            <button class="sp-btn sp-btn-primary" id="generate-token-btn">
                <span class="icon"><i class="fas fa-key"></i></span>
                <span>Generate App Access Token</span>
            </button>
        </div>
        <div class="sp-alert sp-alert-info">
            <h4 style="font-size:0.9rem;font-weight:700;margin-bottom:0.5rem;">&#x2139;&#xFE0F; Redirect URI Setup</h4>
            <p><strong>Current Status:</strong> Your system has redirect URIs configured for user authentication flows.</p>
            <p><strong>For App Access Tokens:</strong> No additional redirect setup needed - you're good to go!</p>
            <p><strong>For User Authentication:</strong> Make sure these URIs are added to your Twitch Developer Console application settings.</p>
        </div>
        </div>
    </div>
    <div id="token-result" class="sp-alert" style="display:none;margin-top:1rem;">
        <div id="token-content"></div>
    </div>
    </div>
</div>
<!-- Modal for App Access Token Information -->
<div class="sp-modal-backdrop" id="info-modal" style="display:none;">
    <div class="sp-modal">
        <div class="sp-modal-head">
            <span class="sp-modal-title">What is an App Access Token?</span>
            <button class="sp-modal-close" aria-label="close" id="close-modal">&#x2715;</button>
        </div>
        <div class="sp-modal-body">
                <h2>How to Get a Twitch App Access Token</h2>
                <p>To obtain an App Access Token, you need:</p>
                <ol>
                    <li>A registered Twitch application in the <a href="https://dev.twitch.tv/console/apps" target="_blank">Twitch Developer Console</a></li>
                    <li>Your application's Client ID and Client Secret</li>
                    <li>Use the OAuth endpoint to request the token</li>
                </ol>
                <p>The token is generated using the Client Credentials flow and is not tied to a specific user.</p>
                <h3>Redirect URI Configuration</h3>
                <p><strong>For App Access Tokens (Client Credentials):</strong> No redirect URI is required in the Twitch Developer Console.</p>
                <p><strong>For User Access Tokens (Authorization Code):</strong> You need to configure redirect URIs in your Twitch application settings.</p>
                <p><strong>Current Configuration:</strong></p>
                <ul>
                    <li>Production: <code><?php echo htmlspecialchars($redirectURI ?? 'Not configured'); ?></code></li>
                    <li>Beta: <code><?php echo htmlspecialchars($betaRedirectURI ?? 'Not configured'); ?></code></li>
                </ul>
                <h3>Requirements for Chat Bot Badge</h3>
                <p>For a chatbot to display the Chat Bot Badge:</p>
                <ul>
                    <li>Use the Send Chat Message API</li>
                    <li>Use an App Access Token</li>
                    <li>Have the <code>channel:bot</code> scope authorized by the broadcaster or moderator status</li>
                    <li>The chatbot's user account is not the channel's broadcaster</li>
                </ul>
        </div>
        <div style="padding:1rem;display:flex;justify-content:flex-end;border-top:1px solid var(--border);">
            <button class="sp-btn sp-btn-success" id="close-modal-footer">Got it!</button>
        </div>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-body">
    <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:0.75rem;">Validate Access Token</h3>
    <p class="mb-4">Enter an access token to validate its status and details.</p>
    <div class="sp-form-group">
        <label class="sp-label">Access Token</label>
        <input class="sp-input" type="password" id="validate-token" placeholder="Enter access token to validate" required>
        <small class="sp-text-muted">The token will be validated against Twitch's API</small>
    </div>
    <div style="margin-bottom:1rem;">
        <button class="sp-btn sp-btn-info" id="validate-token-btn">
            <span class="icon"><i class="fas fa-check"></i></span>
            <span>Validate Token</span>
        </button>
    </div>
    </div>
</div>
<div id="validation-result" class="sp-alert" style="display:none;margin-top:0.5rem;">
    <div id="validation-content"></div>
</div>
<div class="sp-card">
    <div class="sp-card-body">
    <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:0.75rem;">Twitch Chat Token</h3>
    <p class="mb-4">Status of the configured Twitch Chat OAuth token.</p>
    <p><strong>Status:</strong> <span id="chat-status">Checking...</span></p>
    <p><strong>Expires In:</strong> <span id="chat-expiry">-</span></p>
    <div style="margin-bottom:1rem;">
        <button class="sp-btn sp-btn-info" id="validate-chat-btn">
            <span class="icon"><i class="fas fa-check"></i></span>
            <span>Validate Chat Token</span>
        </button>
        <button class="sp-btn sp-btn-warning" id="renew-chat-btn" style="margin-left:10px;">
            <span class="icon"><i class="fas fa-sync-alt"></i></span>
            <span>Renew Chat Token</span>
        </button>
    </div>
    </div>
</div>
<div id="chat-token-result" class="sp-alert" style="display:none;margin-top:0.5rem;">
    <div id="chat-token-content"></div>
</div>
<div class="sp-card">
    <div class="sp-card-body">
    <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:0.75rem;">View Existing User Tokens</h3>
    <p class="mb-4">List of all stored Twitch User Tokens with their associated users. These are OAuth tokens with the scopes required for the system to operate (chat, moderation, channel management, analytics, etc.). When renewed, they use the refresh token to maintain authorization with the same scopes.</p>
    <div class="sp-alert sp-alert-info">
        <p><strong>&#x2139;&#xFE0F; User Token Information:</strong></p>
        <ul>
            <li>These are authenticated user tokens, not app-level tokens</li>
            <li>They maintain all required scopes for bot and dashboard functionality</li>
            <li>Renewal uses the refresh token to keep authorization valid</li>
            <li>If a token shows as "Invalid" repeatedly, the user may need to re-authenticate</li>
        </ul>
    </div>
    <div style="margin-bottom:1rem;">
        <button class="sp-btn sp-btn-info" id="validate-all-btn">
            <span class="icon"><i class="fas fa-check-circle"></i></span>
            <span>Validate All Tokens</span>
        </button>
        <button class="sp-btn sp-btn-danger" id="renew-invalid-btn" disabled style="margin-left: 10px;">
            <span class="icon"><i class="fas fa-refresh"></i></span>
            <span>Renew Invalid Tokens</span>
        </button>
    </div>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Status</th>
                    <th>Expires In</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="tokens-table-body">
            <?php
            $sql = "SELECT u.twitch_user_id, tba.twitch_access_token, u.username 
                    FROM users u 
                    LEFT JOIN twitch_bot_access tba ON u.twitch_user_id = tba.twitch_user_id 
                    WHERE tba.twitch_access_token IS NOT NULL AND tba.twitch_access_token != '' 
                    ORDER BY u.username ASC";
            $result = $conn->query($sql);
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $userId = $row['twitch_user_id'];
                    $token = $row['twitch_access_token'];
                    $username = htmlspecialchars($row['username']);
                    $tokenId = md5($userId . $token); // Use hash for unique ID
                    echo "<tr id='row-$tokenId' data-user-id='$userId'>";
                    echo "<td>$username</td>";
                    echo "<td id='status-$tokenId'>Not Validated</td>";
                    echo "<td id='expiry-$tokenId'>-</td>";
                    echo "<td><button class='sp-btn sp-btn-info sp-btn-sm' onclick='validateToken(null, \"$tokenId\")'>Validate</button> <button class='sp-btn sp-btn-warning sp-btn-sm' onclick='renewToken(\"$userId\", \"$tokenId\")'>Renew</button></td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='4'>No tokens found.</td></tr>";
            }
            ?>
        </tbody>
    </table>
    </div>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-body">
    <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:0.75rem;">Custom Bot Tokens</h3>
    <p class="mb-4">List of stored custom bot tokens with their associated bot accounts.</p>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead>
                <tr>
                    <th>Bot Username</th>
                    <th>Bot Channel ID</th>
                    <th>Status</th>
                    <th>Expires At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="custom-tokens-table-body">
            <?php
            $displayedBotIds = [];
            $displayedBotUsernames = [];

            // First, list entries from custom_bots (explicit custom bots with stored tokens)
            $sqlc = "SELECT channel_id, bot_username, bot_channel_id, access_token, token_expires FROM custom_bots";
            $resc = $conn->query($sqlc);
            $foundAny = false;
            if ($resc && $resc->num_rows > 0) {
                $foundAny = true;
                        while ($crow = $resc->fetch_assoc()) {
                            $botUsername = htmlspecialchars($crow['bot_username'] ?? '');
                            $botChannelId = htmlspecialchars($crow['bot_channel_id'] ?? '');
                    $accessToken = $crow['access_token'] ?? '';
                    $expiresAt = $crow['token_expires'] ?? '-';
                    $tokenId = md5(($botChannelId ?: $botUsername) . ($accessToken ?? ''));
                    // Track displayed identifiers to avoid duplicates when adding module bots below
                    if (!empty($botChannelId)) $displayedBotIds[] = $botChannelId;
                    if (!empty($botUsername)) $displayedBotUsernames[] = strtolower($botUsername);
                            echo "<tr id='custom-row-$tokenId' data-token='" . htmlspecialchars($accessToken) . "' data-bot-channel-id='" . htmlspecialchars($botChannelId) . "' data-bot-username='" . htmlspecialchars($botUsername) . "'>";
                    echo "<td>$botUsername</td>";
                    echo "<td>$botChannelId</td>";
                    echo "<td id='status-custom-$tokenId'>Not Validated</td>";
                    echo "<td id='expiry-custom-$tokenId'>" . htmlspecialchars($expiresAt) . "</td>";
                    echo "<td><button class='sp-btn sp-btn-info sp-btn-sm' onclick='validateCustomToken(null, \"$tokenId\")'>Validate</button> <button class='sp-btn sp-btn-warning sp-btn-sm' onclick='renewCustomToken(\"$botChannelId\", \"$tokenId\")'>Renew</button></td>";
                    echo "</tr>";
                }
            }

            // Next, include module bots from custom_module_bots (may not have tokens yet)
            $msql = "SELECT id, bot_username, bot_channel_id, is_verified FROM custom_module_bots ORDER BY bot_username ASC";
            $mres = $conn->query($msql);
            if ($mres && $mres->num_rows > 0) {
                while ($mrow = $mres->fetch_assoc()) {
                    $mbUsername = htmlspecialchars($mrow['bot_username'] ?? '');
                    $mbChannelId = htmlspecialchars($mrow['bot_channel_id'] ?? '');
                    $isVerified = intval($mrow['is_verified']) ? 'Yes' : 'No';
                    // Skip if already displayed from custom_bots (match by channel id or username)
                    $skip = false;
                    if (!empty($mbChannelId) && in_array($mbChannelId, $displayedBotIds, true)) $skip = true;
                    if (!$skip && !empty($mbUsername) && in_array(strtolower($mbUsername), $displayedBotUsernames, true)) $skip = true;
                    if ($skip) continue;
                    $foundAny = true;
                    // Try to find any stored token in custom_bots for this module bot
                    $accessToken = '';
                    $expiresAt = '-';
                    $checkStmt = $conn->prepare("SELECT access_token, token_expires FROM custom_bots WHERE bot_channel_id = ? OR bot_username = ? LIMIT 1");
                    if ($checkStmt) {
                        $checkStmt->bind_param('ss', $mbChannelId, $mbUsername);
                        $checkStmt->execute();
                        $cres = $checkStmt->get_result();
                        if ($cres && $cres->num_rows > 0) {
                            $crow = $cres->fetch_assoc();
                            $accessToken = $crow['access_token'] ?? '';
                            $expiresAt = $crow['token_expires'] ?? '-';
                        }
                        $checkStmt->close();
                    }
                    $tokenId = md5(($mbChannelId ?: $mbUsername) . ($accessToken ?? '') . 'module');
                    echo "<tr id='custom-row-$tokenId' data-token='" . htmlspecialchars($accessToken) . "' data-bot-channel-id='" . htmlspecialchars($mbChannelId) . "' data-bot-username='" . htmlspecialchars($mbUsername) . "'>";
                    echo "<td>$mbUsername <small style='color:#666'> (module)</small></td>";
                    echo "<td>$mbChannelId</td>";
                    echo "<td id='status-custom-$tokenId'>Not Validated" . ($isVerified ? " (<span class='sp-text-success'>Verified</span>)" : "") . "</td>";
                    echo "<td id='expiry-custom-$tokenId'>" . htmlspecialchars($expiresAt) . "</td>";
                    echo "<td><button class='sp-btn sp-btn-info sp-btn-sm' onclick='validateCustomToken(null, \"$tokenId\")'>Validate</button> <button class='sp-btn sp-btn-warning sp-btn-sm' onclick='renewCustomToken(\"$mbChannelId\", \"$tokenId\")'>Renew</button></td>";
                    echo "</tr>";
                }
            }

            if (!$foundAny) {
                echo "<tr><td colspan='5'>No custom bots found.</td></tr>";
            }
            ?>
        </tbody>
    </table>
    </div>
    </div>
</div>
<?php
$content = ob_get_clean();
ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const generateBtn = document.getElementById('generate-token-btn');
    const tokenResult = document.getElementById('token-result');
    const tokenContent = document.getElementById('token-content');
    const validateBtn = document.getElementById('validate-token-btn');
    const validationResult = document.getElementById('validation-result');
    const validationContent = document.getElementById('validation-content');
    const learnMoreBtn = document.getElementById('learn-more-btn');
    const infoModal = document.getElementById('info-modal');
    const closeModal = document.getElementById('close-modal');
    const closeModalFooter = document.getElementById('close-modal-footer');
    const validateAllBtn = document.getElementById('validate-all-btn');
    const renewInvalidBtn = document.getElementById('renew-invalid-btn');
    const validateChatBtn = document.getElementById('validate-chat-btn');
    const renewChatBtn = document.getElementById('renew-chat-btn');
    let invalidTokens = [];
    let tokenCache = {};
    let chatToken = <?php echo json_encode($oauth ?? ''); ?>;
    // Load token validation cache on page load
    function loadTokenCache() {
        fetch('?load_token_cache=1', {
            method: 'GET'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.cache) {
                tokenCache = data.cache;
                displayCachedValidation();
            }
        })
        .catch(err => console.warn('Failed to load token cache:', err));
    }
    // Display cached validation data on page load
    function displayCachedValidation() {
        // Display cached data for regular tokens
        const rows = document.querySelectorAll('#tokens-table-body tr[data-user-id]');
        rows.forEach(row => {
            const tokenId = row.id.replace('row-', '');
            if (tokenCache[tokenId]) {
                const cached = tokenCache[tokenId];
                const statusCell = document.getElementById(`status-${tokenId}`);
                const expiryCell = document.getElementById(`expiry-${tokenId}`);
                if (cached.is_valid) {
                    statusCell.textContent = 'Valid';
                    statusCell.className = 'sp-text-success';
                } else {
                    statusCell.textContent = 'Invalid';
                    statusCell.className = 'sp-text-danger';
                }
                if (cached.expires_in && cached.timestamp) {
                    // Recalculate remaining time based on cache timestamp + original expires_in
                    const cachedTime = cached.timestamp * 1000; // Convert to milliseconds
                    const expiryTime = cachedTime + (cached.expires_in * 1000); // Add expires_in seconds
                    const now = new Date().getTime();
                    const remaining = Math.floor((expiryTime - now) / 1000);
                    if (remaining > 0) {
                        expiryCell.textContent = formatTimeRemaining(remaining);
                    } else {
                        expiryCell.textContent = 'Expired';
                        expiryCell.className = 'sp-text-danger';
                    }
                }
            }
        });
        // Display cached data for custom tokens
        const customRows = document.querySelectorAll('#custom-tokens-table-body tr[data-bot-channel-id]');
        customRows.forEach(row => {
            const tokenId = row.id.replace('custom-row-', '');
            if (tokenCache[tokenId]) {
                const cached = tokenCache[tokenId];
                const statusCell = document.getElementById(`status-custom-${tokenId}`);
                const expiryCell = document.getElementById(`expiry-custom-${tokenId}`);
                if (cached.is_valid) {
                    statusCell.textContent = 'Valid';
                    statusCell.className = 'sp-text-success';
                } else {
                    statusCell.textContent = 'Invalid';
                    statusCell.className = 'sp-text-danger';
                }
                if (cached.expires_in && cached.timestamp) {
                    // Recalculate remaining time based on cache timestamp + original expires_in
                    const cachedTime = cached.timestamp * 1000; // Convert to milliseconds
                    const expiryTime = cachedTime + (cached.expires_in * 1000); // Add expires_in seconds
                    const now = new Date().getTime();
                    const remaining = Math.floor((expiryTime - now) / 1000);
                    if (remaining > 0) {
                        expiryCell.textContent = formatTimeRemaining(remaining);
                        expiryCell.className = '';
                    } else {
                        expiryCell.textContent = 'Expired';
                        expiryCell.className = 'sp-text-danger';
                    }
                }
            }
        });
    }
    // Helper function to format time remaining
    function formatTimeRemaining(seconds) {
        let remaining = seconds;
        const months = Math.floor(remaining / (30 * 24 * 3600));
        remaining %= (30 * 24 * 3600);
        const days = Math.floor(remaining / (24 * 3600));
        remaining %= (24 * 3600);
        const hours = Math.floor(remaining / 3600);
        remaining %= 3600;
        const minutes = Math.floor(remaining / 60);
        const secs = remaining % 60;
        let timeParts = [];
        if (months > 0) timeParts.push(`${months} month${months > 1 ? 's' : ''}`);
        if (days > 0) timeParts.push(`${days} day${days > 1 ? 's' : ''}`);
        if (hours > 0) timeParts.push(`${hours} hour${hours > 1 ? 's' : ''}`);
        if (minutes > 0) timeParts.push(`${minutes} minute${minutes > 1 ? 's' : ''}`);
        if (secs > 0) timeParts.push(`${secs} second${secs > 1 ? 's' : ''}`);
        return timeParts.join(', ') || '0 seconds';
    }
    // Load cache on page load
    loadTokenCache();
    // Modal functionality
    learnMoreBtn.addEventListener('click', function() {
        infoModal.style.display = 'flex';
    });
    closeModal.addEventListener('click', function() {
        infoModal.style.display = 'none';
    });
    closeModalFooter.addEventListener('click', function() {
        infoModal.style.display = 'none';
    });
    // Close modal when clicking background
    infoModal.addEventListener('click', function(event) {
        if (event.target === infoModal) {
            infoModal.style.display = 'none';
        }
    });
    // Validate all tokens
    validateAllBtn.addEventListener('click', function() {
        const rows = document.querySelectorAll('#tokens-table-body tr[data-user-id]');
        invalidTokens = [];
        renewInvalidBtn.disabled = true;
        renewInvalidBtn.classList.add('is-disabled-inactive');
        const promises = Array.from(rows).map(row => {
            const tokenId = row.id.replace('row-', '');
            return validateToken(null, tokenId);
        });
        Promise.all(promises).then(() => {
            rows.forEach(row => {
                const tokenId = row.id.replace('row-', '');
                const status = document.getElementById(`status-${tokenId}`).textContent;
                if (status === 'Invalid') {
                    invalidTokens.push(row.getAttribute('data-user-id'));
                }
            });
            if (invalidTokens.length > 0) {
                renewInvalidBtn.disabled = false;
                renewInvalidBtn.classList.remove('is-disabled-inactive');
            }
        });
    });
    // Renew invalid tokens
    renewInvalidBtn.addEventListener('click', function() {
        invalidTokens.forEach(userId => {
            const row = document.querySelector(`tr[data-user-id="${userId}"]`);
            const tokenId = row.id.replace('row-', '');
            renewToken(userId, tokenId);
        });
        invalidTokens = [];
        renewInvalidBtn.disabled = true;
        renewInvalidBtn.classList.add('is-disabled-inactive');
    });
    // Validate chat token
    validateChatBtn.addEventListener('click', function() {
        validateChatToken(chatToken);
    });
    // Auto-validate chat token on page load
    if (chatToken) {
        validateChatToken(chatToken);
    }
    // Renew chat token
    if (renewChatBtn) {
        renewChatBtn.addEventListener('click', function() {
            // Generate a new token using client credentials (same as "Generate App Access Token")
            const clientId = document.getElementById('client-id').value.trim();
            const clientSecret = document.getElementById('client-secret').value.trim();
            if (!clientId || !clientSecret) {
                Swal.fire({
                    title: 'Missing Credentials',
                    text: 'Please enter both Client ID and Client Secret above to renew the chat token.',
                    icon: 'warning'
                });
                return;
            }
            renewChatToken(clientId, clientSecret);
        });
    }
    generateBtn.addEventListener('click', async function() {
        const clientId = document.getElementById('client-id').value.trim();
        const clientSecret = document.getElementById('client-secret').value.trim();
        if (!clientId || !clientSecret) {
            Swal.fire({
                title: 'Missing Credentials',
                text: 'Please enter both Client ID and Client Secret, or ensure they are configured in your config file.',
                icon: 'warning'
            });
            return;
        }
        generateBtn.classList.add('sp-btn-loading');
        generateBtn.disabled = true;
        try {
            const formData = new FormData();
            formData.append('generate_token', '1');
            formData.append('client_id', clientId);
            formData.append('client_secret', clientSecret);
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                tokenContent.innerHTML = `
                    <h4 style="font-size:1rem;font-weight:700;margin-bottom:0.5rem;">Token Generated Successfully</h4>
                    <div class="sp-form-group">
                        <label class="sp-label">Access Token</label>
                        <input class="sp-input" type="text" value="${data.access_token}" readonly id="token-input">
                        <small class="sp-text-muted">Expires in: ${data.expires_in} seconds (${Math.floor(data.expires_in / 3600)} hours)</small>
                    </div>
                    <div style="margin-bottom:0.5rem;">
                        <button class="sp-btn sp-btn-sm" onclick="copyToken()">
                            <span class="icon"><i class="fas fa-copy"></i></span>
                            <span>Copy Token</span>
                        </button>
                    </div>
                    <div style="margin-top:0.5rem;">
                        <button class="sp-btn sp-btn-info sp-btn-sm" onclick="generateAuthLink()">
                            <span class="icon"><i class="fas fa-link"></i></span>
                            <span>Generate Auth Link</span>
                        </button>
                        </div>
                    </div>
                `;
                tokenResult.style.display = '';
                tokenResult.className = 'sp-alert sp-alert-success';
            } else {
                tokenContent.innerHTML = `<p class="sp-text-danger">${data.error}</p>`;
                tokenResult.style.display = '';
                tokenResult.className = 'sp-alert sp-alert-danger';
            }
        } catch (error) {
            tokenContent.innerHTML = '<p class="sp-text-danger">An error occurred while generating the token.</p>';
            tokenResult.style.display = '';
            tokenResult.className = 'sp-alert sp-alert-danger';
        }
        generateBtn.classList.remove('sp-btn-loading');
        generateBtn.disabled = false;
    });
    validateBtn.addEventListener('click', async function() {
        const token = document.getElementById('validate-token').value.trim();
        if (!token) {
            Swal.fire({
                title: 'Missing Token',
                text: 'Please enter an access token to validate.',
                icon: 'warning'
            });
            return;
        }
        validateBtn.classList.add('sp-btn-loading');
        validateBtn.disabled = true;
        try {
            const formData = new FormData();
            formData.append('validate_token', '1');
            formData.append('access_token', token);
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                const val = data.validation;
                const expiresIn = val.expires_in || 0;
                const now = new Date();
                const expiryDate = new Date(now.getTime() + expiresIn * 1000);
                // Calculate time components
                let remaining = expiresIn;
                const months = Math.floor(remaining / (30 * 24 * 3600));
                remaining %= (30 * 24 * 3600);
                const days = Math.floor(remaining / (24 * 3600));
                remaining %= (24 * 3600);
                const hours = Math.floor(remaining / 3600);
                remaining %= 3600;
                const minutes = Math.floor(remaining / 60);
                const seconds = remaining % 60;
                // Build time string, only including non-zero units
                let timeParts = [];
                if (months > 0) timeParts.push(`${months} month${months > 1 ? 's' : ''}`);
                if (days > 0) timeParts.push(`${days} day${days > 1 ? 's' : ''}`);
                if (hours > 0) timeParts.push(`${hours} hour${hours > 1 ? 's' : ''}`);
                if (minutes > 0) timeParts.push(`${minutes} minute${minutes > 1 ? 's' : ''}`);
                if (seconds > 0) timeParts.push(`${seconds} second${seconds > 1 ? 's' : ''}`);
                const timeString = timeParts.join(', ') || '0 seconds';
                const dateOptions = { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
                validationContent.innerHTML = `
                    <h4 style="font-size:1rem;font-weight:700;margin-bottom:0.5rem;">Token Validated Successfully</h4>
                    <p><strong>Expires In:</strong> ${timeString}</p>
                    <p><strong>Expiration Date:</strong> ${expiryDate.toLocaleString('en-AU', dateOptions)}</p>
                `;
                validationResult.style.display = '';
                validationResult.className = 'sp-alert sp-alert-success';
                ;
            } else {
                validationContent.innerHTML = `<p class="sp-text-danger">${data.error}</p>`;
                validationResult.style.display = '';
                ;
                validationResult.className = 'sp-alert sp-alert-danger';
            }
        } catch (error) {
            validationContent.innerHTML = '<p class="sp-text-danger">An error occurred while validating the token.</p>';
            validationResult.style.display = '';
            ;
            validationResult.className = 'sp-alert sp-alert-danger';
        }
        validateBtn.classList.remove('sp-btn-loading');
        validateBtn.disabled = false;
    });
});

function validateChatToken(token) {
    const statusCell = document.getElementById('chat-status');
    const expiryCell = document.getElementById('chat-expiry');
    const button = document.getElementById('validate-chat-btn');
    statusCell.textContent = 'Validating...';
    button.disabled = true;
    button.classList.add('sp-btn-loading');
    const formData = new FormData();
    formData.append('validate_token', '1');
    formData.append('access_token', token);
    formData.append('auto_renew_if_24h', '1');
    formData.append('sync_chat_expiry', '1');
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.auto_renewed && data.renewed_token) {
                chatToken = data.renewed_token;
                statusCell.textContent = 'Auto-Renewed';
                statusCell.className = 'sp-text-warning';
                expiryCell.textContent = 'Refreshing...';
                setTimeout(() => validateChatToken(chatToken), 500);
                return;
            }
            const val = data.validation;
            const expiresIn = val.expires_in || 0;
            const now = new Date();
            const expiryDate = new Date(now.getTime() + expiresIn * 1000);
            // Calculate time components
            let remaining = expiresIn;
            const months = Math.floor(remaining / (30 * 24 * 3600));
            remaining %= (30 * 24 * 3600);
            const days = Math.floor(remaining / (24 * 3600));
            remaining %= (24 * 3600);
            const hours = Math.floor(remaining / 3600);
            remaining %= 3600;
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            // Build time string
            let timeParts = [];
            if (months > 0) timeParts.push(`${months} month${months > 1 ? 's' : ''}`);
            if (days > 0) timeParts.push(`${days} day${days > 1 ? 's' : ''}`);
            if (hours > 0) timeParts.push(`${hours} hour${hours > 1 ? 's' : ''}`);
            if (minutes > 0) timeParts.push(`${minutes} minute${minutes > 1 ? 's' : ''}`);
            if (seconds > 0) timeParts.push(`${seconds} second${seconds > 1 ? 's' : ''}`);
            const timeString = timeParts.join(', ') || '0 seconds';
            statusCell.textContent = 'Valid';
            statusCell.className = 'sp-text-success';
            expiryCell.textContent = timeString;
            expiryCell.className = '';
        } else {
            statusCell.textContent = 'Invalid';
            statusCell.className = 'sp-text-danger';
            expiryCell.textContent = '-';
            expiryCell.className = 'sp-text-danger';
        }
    })
    .catch(error => {
        statusCell.textContent = 'Error';
        statusCell.className = 'sp-text-danger';
        expiryCell.textContent = '-';
        expiryCell.className = 'sp-text-danger';
    })
    .finally(() => {
        button.disabled = false;
        button.classList.remove('sp-btn-loading');
    });
}

function validateToken(token, tokenId) {
    const statusCell = document.getElementById(`status-${tokenId}`);
    const expiryCell = document.getElementById(`expiry-${tokenId}`);
    const row = document.getElementById(`row-${tokenId}`);
    const userId = row.getAttribute('data-user-id');
    const button = document.querySelector(`#row-${tokenId} button:first-child`);
    statusCell.textContent = 'Fetching current token...';
    button.disabled = true;
    button.classList.add('sp-btn-loading');
    // First fetch the current token from database
    const fetchFormData = new FormData();
    fetchFormData.append('fetch_user_token', '1');
    fetchFormData.append('twitch_user_id', userId);
    return fetch('', { method: 'POST', body: fetchFormData })
        .then(response => response.json())
        .then(fetchData => {
            if (!fetchData.success) {
                statusCell.textContent = 'No Token';
                statusCell.className = 'sp-text-warning';
                expiryCell.textContent = fetchData.error ? fetchData.error : '-';
                button.disabled = false;
                button.classList.remove('sp-btn-loading');
                return Promise.reject(new Error(fetchData.error || 'No token'));
            }
            // Now validate the freshly fetched token
            const currentToken = fetchData.access_token;
            statusCell.textContent = 'Validating...';
            const formData = new FormData();
            formData.append('validate_token', '1');
            formData.append('access_token', currentToken);
            return fetch('', { method: 'POST', body: formData });
        })
    .then(response => response.json())
    .then(data => {
    if (data.success) {
            const val = data.validation;
            const expiresIn = val.expires_in || 0;
            const now = new Date();
            const expiryDate = new Date(now.getTime() + expiresIn * 1000);
            // Calculate time components
            let remaining = expiresIn;
            const months = Math.floor(remaining / (30 * 24 * 3600));
            remaining %= (30 * 24 * 3600);
            const days = Math.floor(remaining / (24 * 3600));
            remaining %= (24 * 3600);
            const hours = Math.floor(remaining / 3600);
            remaining %= 3600;
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            // Build time string
            let timeParts = [];
            if (months > 0) timeParts.push(`${months} month${months > 1 ? 's' : ''}`);
            if (days > 0) timeParts.push(`${days} day${days > 1 ? 's' : ''}`);
            if (hours > 0) timeParts.push(`${hours} hour${hours > 1 ? 's' : ''}`);
            if (minutes > 0) timeParts.push(`${minutes} minute${minutes > 1 ? 's' : ''}`);
            if (seconds > 0) timeParts.push(`${seconds} second${seconds > 1 ? 's' : ''}`);
            const timeString = timeParts.join(', ') || '0 seconds';
            statusCell.textContent = 'Valid';
            statusCell.className = 'sp-text-success';
            expiryCell.textContent = timeString;
            expiryCell.className = '';
            // Save to cache
            saveTokenToCache(tokenId, 'regular', expiresIn, true);
        } else {
            const errMsg = data.error || 'Invalid token';
            statusCell.textContent = 'Invalid';
            statusCell.className = 'sp-text-danger';
            expiryCell.textContent = errMsg;
            expiryCell.className = 'sp-text-danger';
            // Save to cache
            saveTokenToCache(tokenId, 'regular', 0, false);
        }
        return data;
    })
    .catch(error => {
        const msg = (error && error.message) ? error.message : 'Network error';
        statusCell.textContent = 'Error';
        statusCell.className = 'sp-text-danger';
        expiryCell.textContent = msg;
        expiryCell.className = 'sp-text-danger';
        return { success: false };
    })
    .finally(() => {
        button.disabled = false;
        button.classList.remove('sp-btn-loading');
    });
}

function renewToken(userId, tokenId) {
    const statusCell = document.getElementById(`status-${tokenId}`);
    const expiryCell = document.getElementById(`expiry-${tokenId}`);
    const row = document.getElementById(`row-${tokenId}`);
    const buttons = row.querySelectorAll('button');
    buttons.forEach(btn => {
        btn.disabled = true;
        btn.classList.add('sp-btn-loading');
    });
    statusCell.textContent = 'Renewing...';
    const formData = new FormData();
    formData.append('renew_token', '1');
    formData.append('twitch_user_id', userId);
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update the row's data-token
            row.setAttribute('data-token', data.new_token);
            statusCell.textContent = 'Renewed';
            statusCell.className = 'sp-text-warning';
            expiryCell.textContent = '-';
            expiryCell.className = '';
            // Optionally, auto-validate
            setTimeout(() => validateToken(data.new_token, tokenId), 500);
        } else {
            statusCell.textContent = 'Renew Failed';
            statusCell.className = 'sp-text-danger';
        }
    })
    .catch(error => {
        statusCell.textContent = 'Error';
        statusCell.className = 'sp-text-danger';
    })
    .finally(() => {
        buttons.forEach(btn => {
            btn.disabled = false;
            btn.classList.remove('sp-btn-loading');
        });
    });
}

function renewChatToken(clientId, clientSecret) {
    const resultBox = document.getElementById('chat-token-result');
    const resultContent = document.getElementById('chat-token-content');
    const validateBtn = document.getElementById('validate-chat-btn');
    const renewBtn = document.getElementById('renew-chat-btn');
    if (renewBtn) { renewBtn.disabled = true; renewBtn.classList.add('sp-btn-loading'); }
    if (validateBtn) { validateBtn.disabled = true; }
    resultContent.innerHTML = '<p>Generating new chat token...</p>';
    resultBox.style.display = '';
    const formData = new FormData();
    formData.append('renew_chat_token', '1');
    formData.append('client_id', clientId);
    formData.append('client_secret', clientSecret);
    fetch('', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.access_token) {
                const newToken = data.access_token;
                const expiresIn = data.expires_in || 0;
                chatToken = newToken; // update for future validation
                const dateOptions = { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
                const expiryDate = new Date(Date.now() + expiresIn * 1000).toLocaleString('en-AU', dateOptions);
                // Show masked input with eye toggle and copy
                resultContent.innerHTML = `
                    <div class="sp-alert sp-alert-success">
                        <p><strong>✓ New Chat Token Generated Successfully</strong></p>
                        <p style="margin-top:0.75rem;"><strong>Token:</strong></p>
                        <div style="display:flex;gap:0.5rem;align-items:center;">
                            <input class="sp-input" type="password" id="chat-token-input" value="${newToken}" readonly style="flex:1;">
                            <button class="sp-btn sp-btn-sm" id="toggle-chat-eye" title="Show/Hide Token"><span class="icon"><i class="fas fa-eye"></i></span></button>
                            <button class="sp-btn sp-btn-sm" id="copy-chat-token"><span class="icon"><i class="fas fa-copy"></i></span></button>
                        </div>
                        <small class="sp-text-muted">Token expires at: ${expiryDate}</small><br>
                        <small class="sp-text-muted"><strong>&#x2139;&#xFE0F; Saved:</strong> This token was stored in the website database.</small>
                    </div>
                `;
                // attach handlers
                document.getElementById('toggle-chat-eye').addEventListener('click', function() {
                    toggleChatTokenVisibility('chat-token-input', this.querySelector('i'));
                });
                document.getElementById('copy-chat-token').addEventListener('click', function() {
                    copyChatToken('chat-token-input');
                });
                setTimeout(() => validateChatToken(chatToken), 400);
            } else {
                const err = data.error || 'Failed to generate new chat token.';
                resultContent.innerHTML = `<p class="sp-text-danger">${err}</p>`;
            }
        })
        .catch(() => {
            resultContent.innerHTML = '<p class="sp-text-danger">An error occurred while generating the chat token.</p>';
        })
        .finally(() => {
            if (renewBtn) { renewBtn.disabled = false; renewBtn.classList.remove('sp-btn-loading'); }
            if (validateBtn) { validateBtn.disabled = false; }
        });
}

function validateCustomToken(token, tokenId) {
    const statusCell = document.getElementById(`status-custom-${tokenId}`);
    const expiryCell = document.getElementById(`expiry-custom-${tokenId}`);
    const row = document.getElementById(`custom-row-${tokenId}`);
    const botChannelId = row.getAttribute('data-bot-channel-id');
    statusCell.textContent = 'Fetching current token...';
    // Disable the validate button in this row
    const btn = row.querySelector('button');
    if (btn) { btn.disabled = true; btn.classList.add('sp-btn-loading'); }
    // First fetch the current token from database
    const fetchFormData = new FormData();
    fetchFormData.append('fetch_custom_token', '1');
    fetchFormData.append('bot_channel_id', botChannelId);
    const botUsernameAttr = row.getAttribute('data-bot-username');
    if (!botChannelId && botUsernameAttr) {
        fetchFormData.set('bot_username', botUsernameAttr);
    }
    fetch('', { method: 'POST', body: fetchFormData })
        .then(response => response.json())
        .then(fetchData => {
            if (!fetchData.success) {
                statusCell.textContent = 'No Token';
                statusCell.className = 'sp-text-warning';
                expiryCell.textContent = fetchData.error ? fetchData.error : '-';
                if (btn) { btn.disabled = false; btn.classList.remove('sp-btn-loading'); }
                return Promise.reject(new Error(fetchData.error || 'No token'));
            }
            // Now validate the freshly fetched token
            const currentToken = fetchData.access_token;
            statusCell.textContent = 'Validating...';
            const formData = new FormData();
            formData.append('validate_token', '1');
            formData.append('access_token', currentToken);
            return fetch('', { method: 'POST', body: formData });
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const val = data.validation;
                const expiresIn = val.expires_in || 0;
                let remaining = expiresIn;
                const months = Math.floor(remaining / (30 * 24 * 3600));
                remaining %= (30 * 24 * 3600);
                const days = Math.floor(remaining / (24 * 3600));
                remaining %= (24 * 3600);
                const hours = Math.floor(remaining / 3600);
                remaining %= 3600;
                const minutes = Math.floor(remaining / 60);
                const seconds = remaining % 60;
                let timeParts = [];
                if (months > 0) timeParts.push(`${months} month${months > 1 ? 's' : ''}`);
                if (days > 0) timeParts.push(`${days} day${days > 1 ? 's' : ''}`);
                if (hours > 0) timeParts.push(`${hours} hour${hours > 1 ? 's' : ''}`);
                if (minutes > 0) timeParts.push(`${minutes} minute${minutes > 1 ? 's' : ''}`);
                if (seconds > 0) timeParts.push(`${seconds} second${seconds > 1 ? 's' : ''}`);
                const timeString = timeParts.join(', ') || '0 seconds';
                statusCell.textContent = 'Valid';
                statusCell.className = 'sp-text-success';
                expiryCell.textContent = timeString;
                expiryCell.className = '';
                // Save to cache
                saveTokenToCache(tokenId, 'custom', expiresIn, true);
            } else {
                const errMsg = data.error || 'Invalid token';
                statusCell.textContent = 'Invalid';
                statusCell.className = 'sp-text-danger';
                expiryCell.textContent = errMsg;
                expiryCell.className = 'sp-text-danger';
                // Save to cache
                saveTokenToCache(tokenId, 'custom', 0, false);
            }
        })
        .catch((err) => {
            const msg = (err && err.message) ? err.message : 'Network error';
            statusCell.textContent = 'Error';
            statusCell.className = 'sp-text-danger';
            expiryCell.textContent = msg;
            expiryCell.className = 'sp-text-danger';
        })
        .finally(() => {
            if (btn) { btn.disabled = false; btn.classList.remove('sp-btn-loading'); }
        });
}

function renewCustomToken(botChannelId, tokenId) {
    const row = document.getElementById(`custom-row-${tokenId}`);
    const buttons = row.querySelectorAll('button');
    buttons.forEach(btn => { btn.disabled = true; btn.classList.add('sp-btn-loading'); });
    const statusCell = document.getElementById(`status-custom-${tokenId}`);
    const expiryCell = document.getElementById(`expiry-custom-${tokenId}`);
    statusCell.textContent = 'Renewing...';
    const formData = new FormData();
    formData.append('renew_custom', '1');
    formData.append('bot_channel_id', botChannelId);
    const botUsernameAttr2 = row.getAttribute('data-bot-username');
    if (!botChannelId && botUsernameAttr2) {
        formData.set('bot_username', botUsernameAttr2);
    }
    fetch('', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            console.log('Renew custom response:', data);
            if (data.success) {
                const newToken = data.new_token || '';
                const expiresAt = data.expires_at || '';
                // Update row data-token and expiry display
                row.setAttribute('data-token', newToken);
                expiryCell.textContent = expiresAt || '-';
                expiryCell.className = '';
                statusCell.textContent = 'Renewed';
                statusCell.className = 'sp-text-warning';
                // Optionally auto-validate
                setTimeout(() => validateCustomToken(newToken, tokenId), 500);
            } else {
                statusCell.textContent = 'Renew Failed';
                statusCell.className = 'sp-text-danger';
                console.error('Renew failed:', data.error);
                Swal.fire({
                    title: 'Renewal Failed',
                    text: data.error || 'An error occurred while renewing the custom bot token.',
                    icon: 'error'
                });
            }
        })
        .catch(err => {
            statusCell.textContent = 'Error';
            statusCell.className = 'sp-text-danger';
            console.error('Fetch error:', err);
            Swal.fire({
                title: 'Error',
                text: 'An error occurred while renewing the custom bot token.',
                icon: 'error'
            });
        })
        .finally(() => {
            buttons.forEach(btn => { btn.disabled = false; btn.classList.remove('sp-btn-loading'); });
        });
}

function toggleChatTokenVisibility(inputId, iconEl) {
    const input = document.getElementById(inputId);
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        if (iconEl) { iconEl.classList.remove('fa-eye'); iconEl.classList.add('fa-eye-slash'); }
    } else {
        input.type = 'password';
        if (iconEl) { iconEl.classList.remove('fa-eye-slash'); iconEl.classList.add('fa-eye'); }
    }
}

function copyChatToken(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    try {
        // Use clipboard api when available
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(() => {
                Swal.fire({ title: 'Copied!', text: 'Chat token copied to clipboard', icon: 'success', timer: 1500, showConfirmButton: false });
            });
        } else {
            input.select();
            document.execCommand('copy');
            Swal.fire({ title: 'Copied!', text: 'Chat token copied to clipboard', icon: 'success', timer: 1500, showConfirmButton: false });
        }
    } catch (e) {
        Swal.fire({ title: 'Copy failed', text: 'Unable to copy token', icon: 'error' });
    }
}

function copyToken() {
    const tokenInput = document.getElementById('token-input');
    tokenInput.select();
    document.execCommand('copy');
    // Optional: Show a brief success message
    Swal.fire({
        title: 'Copied!',
        text: 'Token copied to clipboard',
        icon: 'success',
        timer: 1500,
        showConfirmButton: false
    });
}

function generateAuthLink() {
    const clientId = document.getElementById('client-id').value.trim();
    const clientSecret = document.getElementById('client-secret').value.trim();
    if (!clientId || !clientSecret) {
        Swal.fire({
            title: 'Missing Credentials',
            text: 'Please enter both Client ID and Client Secret.',
            icon: 'warning'
        });
        return;
    }
    const authUrl = `https://id.twitch.tv/oauth2/token?client_id=${encodeURIComponent(clientId)}&client_secret=${encodeURIComponent(clientSecret)}&grant_type=client_credentials`;
    // Copy to clipboard
    navigator.clipboard.writeText(authUrl).then(() => {
        Swal.fire({
            title: 'Auth Link Generated!',
            html: `The authorization URL has been copied to your clipboard:<br><br><code>${authUrl}</code>`,
            icon: 'success'
        });
    }).catch(() => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = authUrl;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        Swal.fire({
            title: 'Auth Link Generated!',
            html: `The authorization URL has been copied to your clipboard:<br><br><code>${authUrl}</code>`,
            icon: 'success'
        });
    });
}

// Save token validation result to cache
function saveTokenToCache(tokenId, tokenType, expiresIn, isValid) {
    const formData = new FormData();
    formData.append('save_token_cache', '1');
    formData.append('token_id', tokenId);
    formData.append('token_type', tokenType);
    formData.append('expires_in', expiresIn);
    formData.append('is_valid', isValid ? '1' : '0');
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Token validation cached:', tokenId);
        }
    })
    .catch(err => console.warn('Failed to save token cache:', err));
}
</script>
<?php
$scripts = ob_get_clean();
// layout mode inferred by dashboard/layout.php
include_once __DIR__ . '/../layout.php';
?>