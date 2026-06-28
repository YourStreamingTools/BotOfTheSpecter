<?php
ob_start();
require_once '/var/www/lib/session_bootstrap.php';
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
        return ['success' => false, 'error' => t('admin_twitch_tokens_err_client_credentials_required')];
    }
    $url = 'https://id.twitch.tv/oauth2/token';
    $data = [
        'client_id' => $clientID,
        'client_secret' => $clientSecret,
        'grant_type' => 'client_credentials'
    ];
    $ch = curl_init($url);
    if (!$ch) {
        return ['success' => false, 'error' => t('admin_twitch_tokens_err_curl_init')];
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
if ($curlError) {
        return ['success' => false, 'error' => t('admin_twitch_tokens_err_curl', [$curlError])];
    }
    if ($httpCode !== 200) {
        $error = json_decode($response, true);
        $errorMsg = isset($error['message']) ? $error['message'] : t('admin_twitch_tokens_err_generate_http', [$httpCode]);
        return ['success' => false, 'error' => $errorMsg];
    }
    $result = json_decode($response, true);
    if ($result === null || !isset($result['access_token'])) {
        return ['success' => false, 'error' => t('admin_twitch_tokens_err_invalid_response')];
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
        return ['success' => false, 'error' => t('admin_twitch_tokens_err_db_connection')];
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
            return ['success' => false, 'error' => t('admin_twitch_tokens_err_prepare_insert', [$conn->error])];
        }
        $insert->bind_param('sss', $accessToken, $accessToken, $expiresAt);
        if (!$insert->execute()) {
            $error = $insert->error;
            $insert->close();
            return ['success' => false, 'error' => t('admin_twitch_tokens_err_insert_row', [$error])];
        }
        $insert->close();
        return verifyPersistedChatToken($conn, $accessToken);
    }
    if (!$tokenColumn || !isSafeColumnName($tokenColumn)) {
        return ['success' => false, 'error' => t('admin_twitch_tokens_err_no_token_column')];
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
    $rowId = isset($settings['row']['id']) ? (int) $settings['row']['id'] : 0;
    if ($rowId > 0) {
        // Anchor the write to the exact row every reader loads (ORDER BY id ASC LIMIT 1),
        // so a stray multi-row table can't leave the renew updating a different row than is read back.
        $sql = "UPDATE bot_chat_token SET " . implode(', ', $setSql) . " WHERE id = ? LIMIT 1";
        $types .= 'i';
        $values[] = $rowId;
    } else {
        // No usable id on the fetched row: at least match the ordering every reader uses.
        $sql = "UPDATE bot_chat_token SET " . implode(', ', $setSql) . " ORDER BY id ASC LIMIT 1";
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['success' => false, 'error' => t('admin_twitch_tokens_err_prepare_update', [$conn->error])];
    }
    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'error' => t('admin_twitch_tokens_err_update_token', [$error])];
    }
    $stmt->close();
    return verifyPersistedChatToken($conn, $accessToken);
}

// Read back the row every reader loads and confirm the chat token persisted intact. This
// catches silent VARCHAR truncation or a wrong-row write BEFORE we report success, so the
// admin page never shows a green "renewed" status for a token the bot can't actually use.
function verifyPersistedChatToken($conn, $accessToken) {
    $verify = fetchWebsiteTwitchSettings($conn);
    $storedToken = isset($verify['chat_token']) ? (string) $verify['chat_token'] : '';
    // fetchWebsiteTwitchSettings trims the stored value, so trim the expected side too — the
    // comparison stays symmetric and a legit token can never read as a false mismatch.
    $expectedToken = trim((string) $accessToken);
    if ($storedToken !== $expectedToken) {
        return ['success' => false, 'error' => t('admin_twitch_tokens_err_persist_mismatch', [strlen($storedToken), strlen($expectedToken)])];
    }
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
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_client_id_secret_required')]);
            exit;
        }
        $tokenResult = requestTwitchAppAccessToken($clientID, $clientSecret);
        if ($tokenResult['success']) {
            echo json_encode($tokenResult);
        } else {
            echo json_encode(['success' => false, 'error' => $tokenResult['error'] ?? t('admin_twitch_tokens_err_token_generation_failed')]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_server', [$e->getMessage()])]);
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
        // Only the chat-token path sets this: if the (app) token is invalid, mint+persist a fresh one.
        $renewIfInvalid = isset($_POST['renew_if_invalid']) && $_POST['renew_if_invalid'] === '1';
        if (empty($token)) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_access_token_required')]);
            exit;
        }
        $url = 'https://id.twitch.tv/oauth2/validate';
        $ch = curl_init($url);
        if (!$ch) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_curl_init')]);
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
if ($curlError) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_curl', [$curlError])]);
            exit;
        }
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if ($result === null) {
                echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_invalid_json')]);
                exit;
            }
            $expiresIn = intval($result['expires_in'] ?? 0);
            $syncWarning = null;
            if ($syncChatExpiry) {
                $syncPersistResult = persistWebsiteChatToken($conn, $token, $expiresIn);
                if (empty($syncPersistResult['success'])) {
                    $syncWarning = $syncPersistResult['error'] ?? t('admin_twitch_tokens_err_persist_expiry');
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
            $resp = ['success' => true, 'validation' => $result];
            if ($syncWarning !== null) {
                $resp['sync_warning'] = $syncWarning;
            }
            echo json_encode($resp);
        } else {
            // Chat token is invalid: mint a fresh app access token via client_credentials and
            // persist it to bot_chat_token so the bot (and every reader) picks up the new token.
            if ($renewIfInvalid) {
                $settings = fetchWebsiteTwitchSettings($conn);
                if (!empty($settings['client_id']) && !empty($settings['client_secret'])) {
                    $renewResult = requestTwitchAppAccessToken($settings['client_id'], $settings['client_secret']);
                    if (!empty($renewResult['success'])) {
                        $persistResult = persistWebsiteChatToken($conn, $renewResult['access_token'], $renewResult['expires_in'] ?? 0);
                        if (!empty($persistResult['success'])) {
                            echo json_encode([
                                'success' => true,
                                'auto_renewed' => true,
                                'renewed_token' => $renewResult['access_token'],
                                'renewed_expires_in' => $renewResult['expires_in'] ?? 0
                            ]);
                            exit;
                        }
                        // Minted a fresh token but could not save it (incl. truncation read-back
                        // mismatch) — surface the real error instead of the generic invalid one.
                        echo json_encode(['success' => false, 'error' => $persistResult['error'] ?? t('admin_twitch_tokens_err_persist_token')]);
                        exit;
                    }
                }
            }
            $error = json_decode($response, true);
            $errorMsg = isset($error['message']) ? $error['message'] : t('admin_twitch_tokens_err_validate_http', [$httpCode]);
            echo json_encode(['success' => false, 'error' => $errorMsg]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_server', [$e->getMessage()])]);
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
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_client_credentials_not_configured')]);
            exit;
        }
        $tokenResult = requestTwitchAppAccessToken($clientID, $clientSecret);
        if (empty($tokenResult['success'])) {
            echo json_encode(['success' => false, 'error' => $tokenResult['error'] ?? t('admin_twitch_tokens_err_renew_token')]);
            exit;
        }
        $persistResult = persistWebsiteChatToken($conn, $tokenResult['access_token'], $tokenResult['expires_in'] ?? 0);
        if (empty($persistResult['success'])) {
            echo json_encode(['success' => false, 'error' => $persistResult['error'] ?? t('admin_twitch_tokens_err_persist_token')]);
            exit;
        }
        echo json_encode([
            'success' => true,
            'new_token' => $tokenResult['access_token'],
            'expires_in' => $tokenResult['expires_in'] ?? 0,
            'token_type' => $tokenResult['token_type'] ?? 'bearer'
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_server', [$e->getMessage()])]);
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
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_user_id_required')]);
            exit;
        }
        $clientID = $GLOBALS['clientID'] ?? '';
        $clientSecret = $GLOBALS['clientSecret'] ?? '';
        if (empty($clientID) || empty($clientSecret)) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_client_credentials_not_configured')]);
            exit;
        }
        // Validate database connection
        if (!isset($conn) || !$conn) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_db_connection')]);
            exit;
        }
        // Get the user's refresh token from the users table
        $stmt = $conn->prepare("SELECT refresh_token FROM users WHERE twitch_user_id = ? LIMIT 1");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_db', [$conn->error])]);
            exit;
        }
        $stmt->bind_param('s', $userId);
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_db_query', [$stmt->error])]);
            $stmt->close();
            exit;
        }
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_user_not_found')]);
            exit;
        }
        $refreshToken = $row['refresh_token'] ?? '';
        if (empty($refreshToken)) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_no_refresh_token_user')]);
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
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_curl_init')]);
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
if ($curlError) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_curl', [$curlError])]);
            exit;
        }
        if ($httpCode !== 200) {
            $errMsg = $response ? (json_decode($response, true)['message'] ?? $response) : 'HTTP ' . $httpCode;
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_refresh_token', [$errMsg])]);
            exit;
        }
        $result = json_decode($response, true);
        if ($result === null) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_invalid_json')]);
            exit;
        }
        if (!isset($result['access_token'])) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_invalid_response_detail', [json_encode($result)])]);
            exit;
        }
        $newAccess = $result['access_token'];
        $newRefresh = $result['refresh_token'] ?? $refreshToken;
        $newExpiresIn = $result['expires_in'] ?? null;
        // Update the users table with new tokens
        $upd = $conn->prepare("UPDATE users SET access_token = ?, refresh_token = ? WHERE twitch_user_id = ? LIMIT 1");
        if (!$upd) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_db_update', [$conn->error])]);
            exit;
        }
        $upd->bind_param('sss', $newAccess, $newRefresh, $userId);
        if (!$upd->execute()) {
            $err = $upd->error;
            $upd->close();
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_db_update', [$err])]);
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
                echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_update_bot_access', [$conn->error])]);
                exit;
            }
            $upd2->close();
        } else {
            // non-fatal but inform caller
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_prepare_bot_access', [$conn->error])]);
            exit;
        }
        echo json_encode([
            'success' => true,
            'new_token' => $newAccess,
            'expires_in' => $newExpiresIn ?? 0
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_server', [$e->getMessage()])]);
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
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_token_id_type_required')]);
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
            echo json_encode(['success' => true, 'message' => t('admin_twitch_tokens_msg_validation_cached')]);
        } else {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_save_cache')]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_server', [$e->getMessage()])]);
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
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_user_id_required')]);
            exit;
        }
        // Validate database connection
        if (!isset($conn) || !$conn) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_db_connection')]);
            exit;
        }
        // Fetch current token from twitch_bot_access table
        $stmt = $conn->prepare("SELECT twitch_access_token FROM twitch_bot_access WHERE twitch_user_id = ? LIMIT 1");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_db_query_prepare')]);
            exit;
        }
        $stmt->bind_param('s', $userId);
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_db_query_execute')]);
            $stmt->close();
            exit;
        }
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_user_token_not_found')]);
            exit;
        }
        echo json_encode([
            'success' => true,
            'access_token' => $row['twitch_access_token'] ?? ''
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_server', [$e->getMessage()])]);
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
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_bot_id_or_username_required')]);
            exit;
        }
        // Validate database connection
        if (!isset($conn) || !$conn) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_db_connection')]);
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
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_db_query_prepare')]);
            exit;
        }
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_db_query_execute')]);
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
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_custom_bot_not_found')]);
            exit;
        }
        echo json_encode([
            'success' => true,
            'access_token' => $row['access_token'] ?? '',
            'token_expires' => $row['token_expires'] ?? '-'
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_server', [$e->getMessage()])]);
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
        echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_server', [$e->getMessage()])]);
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
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_bot_id_or_username_required')]);
            exit;
        }
        // Load client credentials from config
        $clientID = $GLOBALS['clientID'] ?? '';
        $clientSecret = $GLOBALS['clientSecret'] ?? '';
        if (empty($clientID) || empty($clientSecret)) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_client_credentials_not_configured')]);
            exit;
        }
        // Validate database connection
        if (!isset($conn) || !$conn) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_db_connection')]);
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
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_custom_bot_not_found')]);
            exit;
        }
        $refreshToken = $row['refresh_token'] ?? '';
        if (empty($refreshToken)) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_no_refresh_token_custom')]);
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
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_curl_init')]);
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
if ($curlError) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_curl', [$curlError])]);
            exit;
        }
        if ($httpCode !== 200) {
            $errMsg = $response ? (json_decode($response, true)['message'] ?? $response) : 'HTTP ' . $httpCode;
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_refresh_token', [$errMsg])]);
            exit;
        }
        $result = json_decode($response, true);
        if ($result === null) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_invalid_json')]);
            exit;
        }
        if (!isset($result['access_token'])) {
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_invalid_response_detail', [json_encode($result)])]);
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
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_db_update', [$conn->error])]);
            exit;
        }
        if (!$upd->execute()) {
            $err = $upd->error;
            $upd->close();
            echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_db_update', [$err])]);
            exit;
        }
        $upd->close();
        echo json_encode(['success' => true, 'new_token' => $newAccess, 'expires_at' => $newExpiresAt]);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => t('admin_twitch_tokens_err_server', [$e->getMessage()])]);
        exit;
    }
}

ob_start();
?>
<div class="sp-card">
    <div class="sp-card-body">
    <h1 style="font-size:1.25rem;font-weight:700;margin-bottom:0.75rem;"><span class="icon"><i class="fab fa-twitch"></i></span> <?php echo t('admin_twitch_tokens_page_title'); ?></h1>
    <p class="mb-4"><?php echo t('admin_twitch_tokens_intro'); ?></p>
    <div style="margin-bottom:1rem;">
        <button class="sp-btn sp-btn-info" id="learn-more-btn">
            <span class="icon"><i class="fas fa-info-circle"></i></span>
            <span><?php echo t('admin_twitch_tokens_learn_more'); ?></span>
        </button>
    </div>
    <div class="sp-card">
        <div class="sp-card-body">
        <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:0.75rem;"><?php echo t('admin_twitch_tokens_credentials_heading'); ?></h3>
        <p class="mb-4"><?php echo t('admin_twitch_tokens_credentials_intro'); ?></p>
        <div class="sp-form-group">
            <label class="sp-label"><?php echo t('admin_twitch_tokens_client_id_label'); ?></label>
            <input class="sp-input" type="text" id="client-id" placeholder="<?php echo htmlspecialchars(t('admin_twitch_tokens_client_id_placeholder')); ?>" value="<?php echo htmlspecialchars($clientID ?? ''); ?>" required>
            <small class="sp-text-muted"><?php echo t('admin_twitch_tokens_client_id_help'); ?></small>
        </div>
        <div class="sp-form-group">
            <label class="sp-label"><?php echo t('admin_twitch_tokens_client_secret_label'); ?></label>
            <input class="sp-input" type="password" id="client-secret" placeholder="<?php echo htmlspecialchars(t('admin_twitch_tokens_client_secret_placeholder')); ?>" value="<?php echo htmlspecialchars($clientSecret ?? ''); ?>" required>
            <small class="sp-text-muted"><?php echo t('admin_twitch_tokens_client_secret_help'); ?></small>
        </div>
        <div style="margin-bottom:1rem;">
            <button class="sp-btn sp-btn-primary" id="generate-token-btn">
                <span class="icon"><i class="fas fa-key"></i></span>
                <span><?php echo t('admin_twitch_tokens_generate_button'); ?></span>
            </button>
        </div>
        <div class="sp-alert sp-alert-info">
            <h4 style="font-size:0.9rem;font-weight:700;margin-bottom:0.5rem;">&#x2139;&#xFE0F; <?php echo t('admin_twitch_tokens_redirect_setup_heading'); ?></h4>
            <p><strong><?php echo t('admin_twitch_tokens_redirect_current_status_label'); ?></strong> <?php echo t('admin_twitch_tokens_redirect_current_status_text'); ?></p>
            <p><strong><?php echo t('admin_twitch_tokens_redirect_app_tokens_label'); ?></strong> <?php echo t('admin_twitch_tokens_redirect_app_tokens_text'); ?></p>
            <p><strong><?php echo t('admin_twitch_tokens_redirect_user_auth_label'); ?></strong> <?php echo t('admin_twitch_tokens_redirect_user_auth_text'); ?></p>
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
            <span class="sp-modal-title"><?php echo t('admin_twitch_tokens_learn_more'); ?></span>
            <button class="sp-modal-close" aria-label="<?php echo htmlspecialchars(t('admin_twitch_tokens_close')); ?>" id="close-modal">&#x2715;</button>
        </div>
        <div class="sp-modal-body">
                <h2><?php echo t('admin_twitch_tokens_modal_how_to_heading'); ?></h2>
                <p><?php echo t('admin_twitch_tokens_modal_how_to_intro'); ?></p>
                <ol>
                    <li><?php echo t('admin_twitch_tokens_modal_step_registered_app_prefix'); ?> <a href="https://dev.twitch.tv/console/apps" target="_blank"><?php echo t('admin_twitch_tokens_modal_dev_console'); ?></a></li>
                    <li><?php echo t('admin_twitch_tokens_modal_step_client_credentials'); ?></li>
                    <li><?php echo t('admin_twitch_tokens_modal_step_oauth_endpoint'); ?></li>
                </ol>
                <p><?php echo t('admin_twitch_tokens_modal_client_credentials_note'); ?></p>
                <h3><?php echo t('admin_twitch_tokens_modal_redirect_heading'); ?></h3>
                <p><strong><?php echo t('admin_twitch_tokens_modal_redirect_app_label'); ?></strong> <?php echo t('admin_twitch_tokens_modal_redirect_app_text'); ?></p>
                <p><strong><?php echo t('admin_twitch_tokens_modal_redirect_user_label'); ?></strong> <?php echo t('admin_twitch_tokens_modal_redirect_user_text'); ?></p>
                <p><strong><?php echo t('admin_twitch_tokens_modal_current_config_label'); ?></strong></p>
                <ul>
                    <li><?php echo t('admin_twitch_tokens_modal_production_label'); ?> <code><?php echo htmlspecialchars($redirectURI ?? t('admin_twitch_tokens_not_configured')); ?></code></li>
                    <li><?php echo t('admin_twitch_tokens_modal_beta_label'); ?> <code><?php echo htmlspecialchars($betaRedirectURI ?? t('admin_twitch_tokens_not_configured')); ?></code></li>
                </ul>
                <h3><?php echo t('admin_twitch_tokens_modal_badge_heading'); ?></h3>
                <p><?php echo t('admin_twitch_tokens_modal_badge_intro'); ?></p>
                <ul>
                    <li><?php echo t('admin_twitch_tokens_modal_badge_send_api'); ?></li>
                    <li><?php echo t('admin_twitch_tokens_modal_badge_use_app_token'); ?></li>
                    <li><?php echo t('admin_twitch_tokens_modal_badge_scope_prefix'); ?> <code>channel:bot</code> <?php echo t('admin_twitch_tokens_modal_badge_scope_suffix'); ?></li>
                    <li><?php echo t('admin_twitch_tokens_modal_badge_not_broadcaster'); ?></li>
                </ul>
        </div>
        <div style="padding:1rem;display:flex;justify-content:flex-end;border-top:1px solid var(--border);">
            <button class="sp-btn sp-btn-success" id="close-modal-footer"><?php echo t('admin_twitch_tokens_got_it'); ?></button>
        </div>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-body">
    <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:0.75rem;"><?php echo t('admin_twitch_tokens_validate_heading'); ?></h3>
    <p class="mb-4"><?php echo t('admin_twitch_tokens_validate_intro'); ?></p>
    <div class="sp-form-group">
        <label class="sp-label"><?php echo t('admin_twitch_tokens_access_token_label'); ?></label>
        <input class="sp-input" type="password" id="validate-token" placeholder="<?php echo htmlspecialchars(t('admin_twitch_tokens_access_token_placeholder')); ?>" required>
        <small class="sp-text-muted"><?php echo t('admin_twitch_tokens_access_token_help'); ?></small>
    </div>
    <div style="margin-bottom:1rem;">
        <button class="sp-btn sp-btn-info" id="validate-token-btn">
            <span class="icon"><i class="fas fa-check"></i></span>
            <span><?php echo t('admin_twitch_tokens_validate_button'); ?></span>
        </button>
    </div>
    </div>
</div>
<div id="validation-result" class="sp-alert" style="display:none;margin-top:0.5rem;">
    <div id="validation-content"></div>
</div>
<div class="sp-card">
    <div class="sp-card-body">
    <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:0.75rem;"><?php echo t('admin_twitch_tokens_chat_heading'); ?></h3>
    <p class="mb-4"><?php echo t('admin_twitch_tokens_chat_intro'); ?></p>
    <p><strong><?php echo t('admin_twitch_tokens_status_label'); ?></strong> <span id="chat-status"><?php echo t('admin_twitch_tokens_status_checking'); ?></span></p>
    <p><strong><?php echo t('admin_twitch_tokens_expires_in_label'); ?></strong> <span id="chat-expiry">-</span></p>
    <div style="margin-bottom:1rem;">
        <button class="sp-btn sp-btn-info" id="validate-chat-btn">
            <span class="icon"><i class="fas fa-check"></i></span>
            <span><?php echo t('admin_twitch_tokens_validate_chat_button'); ?></span>
        </button>
        <button class="sp-btn sp-btn-warning" id="renew-chat-btn" style="margin-left:10px;">
            <span class="icon"><i class="fas fa-sync-alt"></i></span>
            <span><?php echo t('admin_twitch_tokens_renew_chat_button'); ?></span>
        </button>
    </div>
    </div>
</div>
<div id="chat-token-result" class="sp-alert" style="display:none;margin-top:0.5rem;">
    <div id="chat-token-content"></div>
</div>
<div class="sp-card">
    <div class="sp-card-body">
    <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:0.75rem;"><?php echo t('admin_twitch_tokens_user_tokens_heading'); ?></h3>
    <p class="mb-4"><?php echo t('admin_twitch_tokens_user_tokens_intro'); ?></p>
    <div class="sp-alert sp-alert-info">
        <p><strong>&#x2139;&#xFE0F; <?php echo t('admin_twitch_tokens_user_info_heading'); ?></strong></p>
        <ul>
            <li><?php echo t('admin_twitch_tokens_user_info_authenticated'); ?></li>
            <li><?php echo t('admin_twitch_tokens_user_info_scopes'); ?></li>
            <li><?php echo t('admin_twitch_tokens_user_info_renewal'); ?></li>
            <li><?php echo t('admin_twitch_tokens_user_info_reauth'); ?></li>
        </ul>
    </div>
    <div style="margin-bottom:1rem;">
        <button class="sp-btn sp-btn-info" id="validate-all-btn">
            <span class="icon"><i class="fas fa-check-circle"></i></span>
            <span><?php echo t('admin_twitch_tokens_validate_all_button'); ?></span>
        </button>
        <button class="sp-btn sp-btn-danger" id="renew-invalid-btn" disabled style="margin-left: 10px;">
            <span class="icon"><i class="fas fa-refresh"></i></span>
            <span><?php echo t('admin_twitch_tokens_renew_invalid_button'); ?></span>
        </button>
    </div>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead>
                <tr>
                    <th><?php echo t('admin_twitch_tokens_th_username'); ?></th>
                    <th><?php echo t('admin_twitch_tokens_th_status'); ?></th>
                    <th><?php echo t('admin_twitch_tokens_th_expires_in'); ?></th>
                    <th><?php echo t('admin_twitch_tokens_th_actions'); ?></th>
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
                    echo "<td id='status-$tokenId'>" . htmlspecialchars(t('admin_twitch_tokens_status_not_validated')) . "</td>";
                    echo "<td id='expiry-$tokenId'>-</td>";
                    echo "<td><button class='sp-btn sp-btn-info sp-btn-sm' onclick='validateToken(null, \"$tokenId\")'>" . htmlspecialchars(t('admin_twitch_tokens_btn_validate')) . "</button> <button class='sp-btn sp-btn-warning sp-btn-sm' onclick='renewToken(\"$userId\", \"$tokenId\")'>" . htmlspecialchars(t('admin_twitch_tokens_btn_renew')) . "</button></td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='4'>" . htmlspecialchars(t('admin_twitch_tokens_no_tokens')) . "</td></tr>";
            }
            ?>
        </tbody>
    </table>
    </div>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-body">
    <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:0.75rem;"><?php echo t('admin_twitch_tokens_custom_heading'); ?></h3>
    <p class="mb-4"><?php echo t('admin_twitch_tokens_custom_intro'); ?></p>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead>
                <tr>
                    <th><?php echo t('admin_twitch_tokens_th_bot_username'); ?></th>
                    <th><?php echo t('admin_twitch_tokens_th_bot_channel_id'); ?></th>
                    <th><?php echo t('admin_twitch_tokens_th_status'); ?></th>
                    <th><?php echo t('admin_twitch_tokens_th_expires_at'); ?></th>
                    <th><?php echo t('admin_twitch_tokens_th_actions'); ?></th>
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
                    echo "<td id='status-custom-$tokenId'>" . htmlspecialchars(t('admin_twitch_tokens_status_not_validated')) . "</td>";
                    echo "<td id='expiry-custom-$tokenId'>" . htmlspecialchars($expiresAt) . "</td>";
                    echo "<td><button class='sp-btn sp-btn-info sp-btn-sm' onclick='validateCustomToken(null, \"$tokenId\")'>" . htmlspecialchars(t('admin_twitch_tokens_btn_validate')) . "</button> <button class='sp-btn sp-btn-warning sp-btn-sm' onclick='renewCustomToken(\"$botChannelId\", \"$tokenId\")'>" . htmlspecialchars(t('admin_twitch_tokens_btn_renew')) . "</button></td>";
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
                    echo "<td>$mbUsername <small style='color:#666'> " . htmlspecialchars(t('admin_twitch_tokens_module_tag')) . "</small></td>";
                    echo "<td>$mbChannelId</td>";
                    echo "<td id='status-custom-$tokenId'>" . htmlspecialchars(t('admin_twitch_tokens_status_not_validated')) . ($isVerified ? " (<span class='sp-text-success'>" . htmlspecialchars(t('admin_twitch_tokens_verified')) . "</span>)" : "") . "</td>";
                    echo "<td id='expiry-custom-$tokenId'>" . htmlspecialchars($expiresAt) . "</td>";
                    echo "<td><button class='sp-btn sp-btn-info sp-btn-sm' onclick='validateCustomToken(null, \"$tokenId\")'>" . htmlspecialchars(t('admin_twitch_tokens_btn_validate')) . "</button> <button class='sp-btn sp-btn-warning sp-btn-sm' onclick='renewCustomToken(\"$mbChannelId\", \"$tokenId\")'>" . htmlspecialchars(t('admin_twitch_tokens_btn_renew')) . "</button></td>";
                    echo "</tr>";
                }
            }

            if (!$foundAny) {
                echo "<tr><td colspan='5'>" . htmlspecialchars(t('admin_twitch_tokens_no_custom_bots')) . "</td></tr>";
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
const TT_I18N = {
    statusValid: <?php echo json_encode(t('admin_twitch_tokens_status_valid')); ?>,
    statusInvalid: <?php echo json_encode(t('admin_twitch_tokens_status_invalid')); ?>,
    statusExpired: <?php echo json_encode(t('admin_twitch_tokens_status_expired')); ?>,
    statusValidating: <?php echo json_encode(t('admin_twitch_tokens_status_validating')); ?>,
    statusFetching: <?php echo json_encode(t('admin_twitch_tokens_status_fetching')); ?>,
    statusRenewing: <?php echo json_encode(t('admin_twitch_tokens_status_renewing')); ?>,
    statusRenewed: <?php echo json_encode(t('admin_twitch_tokens_status_renewed')); ?>,
    statusRenewFailed: <?php echo json_encode(t('admin_twitch_tokens_status_renew_failed')); ?>,
    statusAutoRenewed: <?php echo json_encode(t('admin_twitch_tokens_status_auto_renewed')); ?>,
    statusRefreshing: <?php echo json_encode(t('admin_twitch_tokens_status_refreshing')); ?>,
    statusError: <?php echo json_encode(t('admin_twitch_tokens_status_error')); ?>,
    statusNoToken: <?php echo json_encode(t('admin_twitch_tokens_status_no_token')); ?>,
    errNoToken: <?php echo json_encode(t('admin_twitch_tokens_js_no_token')); ?>,
    errInvalidToken: <?php echo json_encode(t('admin_twitch_tokens_js_invalid_token')); ?>,
    errNetwork: <?php echo json_encode(t('admin_twitch_tokens_js_network_error')); ?>,
    tokenGenerated: <?php echo json_encode(t('admin_twitch_tokens_js_token_generated')); ?>,
    accessTokenLabel: <?php echo json_encode(t('admin_twitch_tokens_access_token_label')); ?>,
    expiresInSeconds: <?php echo json_encode(t('admin_twitch_tokens_js_expires_in_seconds')); ?>,
    copyToken: <?php echo json_encode(t('admin_twitch_tokens_js_copy_token')); ?>,
    generateAuthLink: <?php echo json_encode(t('admin_twitch_tokens_js_generate_auth_link')); ?>,
    errGenerating: <?php echo json_encode(t('admin_twitch_tokens_js_err_generating')); ?>,
    tokenValidated: <?php echo json_encode(t('admin_twitch_tokens_js_token_validated')); ?>,
    expiresInLabel: <?php echo json_encode(t('admin_twitch_tokens_expires_in_label')); ?>,
    expirationDateLabel: <?php echo json_encode(t('admin_twitch_tokens_js_expiration_date_label')); ?>,
    errValidating: <?php echo json_encode(t('admin_twitch_tokens_js_err_validating')); ?>,
    missingCredentialsTitle: <?php echo json_encode(t('admin_twitch_tokens_js_missing_credentials_title')); ?>,
    missingCredentialsRenew: <?php echo json_encode(t('admin_twitch_tokens_js_missing_credentials_renew')); ?>,
    missingCredentialsGenerate: <?php echo json_encode(t('admin_twitch_tokens_js_missing_credentials_generate')); ?>,
    missingCredentialsSimple: <?php echo json_encode(t('admin_twitch_tokens_js_missing_credentials_simple')); ?>,
    missingTokenTitle: <?php echo json_encode(t('admin_twitch_tokens_js_missing_token_title')); ?>,
    missingTokenText: <?php echo json_encode(t('admin_twitch_tokens_js_missing_token_text')); ?>,
    generatingChat: <?php echo json_encode(t('admin_twitch_tokens_js_generating_chat')); ?>,
    chatGeneratedTitle: <?php echo json_encode(t('admin_twitch_tokens_js_chat_generated_title')); ?>,
    chatTokenLabel: <?php echo json_encode(t('admin_twitch_tokens_js_chat_token_label')); ?>,
    showHideToken: <?php echo json_encode(t('admin_twitch_tokens_js_show_hide_token')); ?>,
    tokenExpiresAt: <?php echo json_encode(t('admin_twitch_tokens_js_token_expires_at')); ?>,
    savedNotice: <?php echo json_encode(t('admin_twitch_tokens_js_saved_notice')); ?>,
    errChatGenerate: <?php echo json_encode(t('admin_twitch_tokens_js_err_chat_generate')); ?>,
    errChatGenerating: <?php echo json_encode(t('admin_twitch_tokens_js_err_chat_generating')); ?>,
    renewalFailedTitle: <?php echo json_encode(t('admin_twitch_tokens_js_renewal_failed_title')); ?>,
    renewalFailedText: <?php echo json_encode(t('admin_twitch_tokens_js_renewal_failed_text')); ?>,
    errorTitle: <?php echo json_encode(t('admin_twitch_tokens_js_error_title')); ?>,
    copiedTitle: <?php echo json_encode(t('admin_twitch_tokens_js_copied_title')); ?>,
    chatTokenCopied: <?php echo json_encode(t('admin_twitch_tokens_js_chat_token_copied')); ?>,
    tokenCopied: <?php echo json_encode(t('admin_twitch_tokens_js_token_copied')); ?>,
    copyFailedTitle: <?php echo json_encode(t('admin_twitch_tokens_js_copy_failed_title')); ?>,
    copyFailedText: <?php echo json_encode(t('admin_twitch_tokens_js_copy_failed_text')); ?>,
    authLinkTitle: <?php echo json_encode(t('admin_twitch_tokens_js_auth_link_title')); ?>,
    authLinkHtml: <?php echo json_encode(t('admin_twitch_tokens_js_auth_link_html')); ?>,
    unitMonth: <?php echo json_encode(t('admin_twitch_tokens_unit_month')); ?>,
    unitMonths: <?php echo json_encode(t('admin_twitch_tokens_unit_months')); ?>,
    unitDay: <?php echo json_encode(t('admin_twitch_tokens_unit_day')); ?>,
    unitDays: <?php echo json_encode(t('admin_twitch_tokens_unit_days')); ?>,
    unitHour: <?php echo json_encode(t('admin_twitch_tokens_unit_hour')); ?>,
    unitHours: <?php echo json_encode(t('admin_twitch_tokens_unit_hours')); ?>,
    unitMinute: <?php echo json_encode(t('admin_twitch_tokens_unit_minute')); ?>,
    unitMinutes: <?php echo json_encode(t('admin_twitch_tokens_unit_minutes')); ?>,
    unitSecond: <?php echo json_encode(t('admin_twitch_tokens_unit_second')); ?>,
    unitSeconds: <?php echo json_encode(t('admin_twitch_tokens_unit_seconds')); ?>,
    zeroSeconds: <?php echo json_encode(t('admin_twitch_tokens_zero_seconds')); ?>
};
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
        let cacheLoadOk = false;
        fetch('?load_token_cache=1', {
            method: 'GET'
        })
        .then(response => response.json())
        .then(data => {
            if (data && data.success) {
                cacheLoadOk = true;
                if (data.cache) {
                    tokenCache = data.cache;
                    displayCachedValidation();
                }
            }
        })
        .catch(err => console.warn('Failed to load token cache:', err))
        .finally(() => {
            if (cacheLoadOk) {
                autoValidateUserTokensCacheFirst();
            }
            autoValidateCustomTokens();
        });
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
                    statusCell.textContent = TT_I18N.statusValid;
                    statusCell.className = 'sp-text-success';
                } else {
                    statusCell.textContent = TT_I18N.statusInvalid;
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
                        expiryCell.textContent = TT_I18N.statusExpired;
                        expiryCell.className = 'sp-text-danger';
                    }
                }
            }
        });
        // Display cached data for custom tokens
        const customRows = document.querySelectorAll('#custom-tokens-table-body tr[data-bot-channel-id]');
        customRows.forEach(row => {
            if ((row.getAttribute('data-token') || '').trim() !== '') return;
            const tokenId = row.id.replace('custom-row-', '');
            if (tokenCache[tokenId]) {
                const cached = tokenCache[tokenId];
                const statusCell = document.getElementById(`status-custom-${tokenId}`);
                const expiryCell = document.getElementById(`expiry-custom-${tokenId}`);
                if (cached.is_valid) {
                    statusCell.textContent = TT_I18N.statusValid;
                    statusCell.className = 'sp-text-success';
                } else {
                    statusCell.textContent = TT_I18N.statusInvalid;
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
                        expiryCell.textContent = TT_I18N.statusExpired;
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
        if (months > 0) timeParts.push(`${months} ${months > 1 ? TT_I18N.unitMonths : TT_I18N.unitMonth}`);
        if (days > 0) timeParts.push(`${days} ${days > 1 ? TT_I18N.unitDays : TT_I18N.unitDay}`);
        if (hours > 0) timeParts.push(`${hours} ${hours > 1 ? TT_I18N.unitHours : TT_I18N.unitHour}`);
        if (minutes > 0) timeParts.push(`${minutes} ${minutes > 1 ? TT_I18N.unitMinutes : TT_I18N.unitMinute}`);
        if (secs > 0) timeParts.push(`${secs} ${secs > 1 ? TT_I18N.unitSeconds : TT_I18N.unitSecond}`);
        return timeParts.join(', ') || TT_I18N.zeroSeconds;
    }
    // Run async thunks with a bounded concurrency so we never burst the validate endpoint.
    function runWithConcurrency(thunks, limit) {
        let idx = 0;
        const next = () => {
            if (idx >= thunks.length) return Promise.resolve();
            const thunk = thunks[idx++];
            return Promise.resolve().then(thunk).catch(() => {}).then(next);
        };
        const starters = [];
        const n = Math.min(limit, thunks.length);
        for (let i = 0; i < n; i++) starters.push(next());
        return Promise.all(starters);
    }
    // Cache-first user-token validation: only re-check tokens with no cache entry or a stale one.
    const TOKEN_CACHE_TTL_SECONDS = 600; // 10 minutes
    // After validation settles, mark rows showing Invalid and arm the "Renew Invalid" button.
    function refreshInvalidUserTokensButton() {
        const rows = Array.from(document.querySelectorAll('#tokens-table-body tr[data-user-id]'));
        invalidTokens = rows
            .filter(row => {
                const cell = document.getElementById(`status-${row.id.replace('row-', '')}`);
                return cell && cell.textContent === TT_I18N.statusInvalid;
            })
            .map(row => row.getAttribute('data-user-id'));
        if (invalidTokens.length > 0) {
            renewInvalidBtn.disabled = false;
            renewInvalidBtn.classList.remove('is-disabled-inactive');
        } else {
            renewInvalidBtn.disabled = true;
            renewInvalidBtn.classList.add('is-disabled-inactive');
        }
    }
    function autoValidateUserTokensCacheFirst() {
        const nowSec = Math.floor(new Date().getTime() / 1000);
        const rows = Array.from(document.querySelectorAll('#tokens-table-body tr[data-user-id]'));
        const toValidate = rows.filter(row => {
            const tokenId = row.id.replace('row-', '');
            const cached = tokenCache[tokenId];
            if (!cached || !cached.timestamp) return true;
            return (nowSec - cached.timestamp) > TOKEN_CACHE_TTL_SECONDS;
        });
        if (!toValidate.length) {
            // Nothing stale to re-check, but cached-invalid rows should still arm the button.
            refreshInvalidUserTokensButton();
            return;
        }
        runWithConcurrency(toValidate.map(row => () => validateToken(null, row.id.replace('row-', ''))), 6)
            .then(refreshInvalidUserTokensButton);
    }
    // Auto-validate every custom bot token that actually has a token stored (small table).
    function autoValidateCustomTokens() {
        const rows = Array.from(document.querySelectorAll('#custom-tokens-table-body tr[id^="custom-row-"]'));
        const thunks = rows
            .filter(row => (row.getAttribute('data-token') || '').trim() !== '')
            .map(row => () => validateCustomToken(null, row.id.replace('custom-row-', '')));
        if (thunks.length) runWithConcurrency(thunks, 6);
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
                if (status === TT_I18N.statusInvalid) {
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
                    title: TT_I18N.missingCredentialsTitle,
                    text: TT_I18N.missingCredentialsRenew,
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
                title: TT_I18N.missingCredentialsTitle,
                text: TT_I18N.missingCredentialsGenerate,
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
                    <h4 style="font-size:1rem;font-weight:700;margin-bottom:0.5rem;">${TT_I18N.tokenGenerated}</h4>
                    <div class="sp-form-group">
                        <label class="sp-label">${TT_I18N.accessTokenLabel}</label>
                        <input class="sp-input" type="text" value="${data.access_token}" readonly id="token-input">
                        <small class="sp-text-muted">${TT_I18N.expiresInSeconds.replace(':seconds', data.expires_in).replace(':hours', Math.floor(data.expires_in / 3600))}</small>
                    </div>
                    <div style="margin-bottom:0.5rem;">
                        <button class="sp-btn sp-btn-sm" onclick="copyToken()">
                            <span class="icon"><i class="fas fa-copy"></i></span>
                            <span>${TT_I18N.copyToken}</span>
                        </button>
                    </div>
                    <div style="margin-top:0.5rem;">
                        <button class="sp-btn sp-btn-info sp-btn-sm" onclick="generateAuthLink()">
                            <span class="icon"><i class="fas fa-link"></i></span>
                            <span>${TT_I18N.generateAuthLink}</span>
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
            tokenContent.innerHTML = `<p class="sp-text-danger">${TT_I18N.errGenerating}</p>`;
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
                title: TT_I18N.missingTokenTitle,
                text: TT_I18N.missingTokenText,
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
                if (months > 0) timeParts.push(`${months} ${months > 1 ? TT_I18N.unitMonths : TT_I18N.unitMonth}`);
                if (days > 0) timeParts.push(`${days} ${days > 1 ? TT_I18N.unitDays : TT_I18N.unitDay}`);
                if (hours > 0) timeParts.push(`${hours} ${hours > 1 ? TT_I18N.unitHours : TT_I18N.unitHour}`);
                if (minutes > 0) timeParts.push(`${minutes} ${minutes > 1 ? TT_I18N.unitMinutes : TT_I18N.unitMinute}`);
                if (seconds > 0) timeParts.push(`${seconds} ${seconds > 1 ? TT_I18N.unitSeconds : TT_I18N.unitSecond}`);
                const timeString = timeParts.join(', ') || TT_I18N.zeroSeconds;
                const dateOptions = { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
                validationContent.innerHTML = `
                    <h4 style="font-size:1rem;font-weight:700;margin-bottom:0.5rem;">${TT_I18N.tokenValidated}</h4>
                    <p><strong>${TT_I18N.expiresInLabel}</strong> ${timeString}</p>
                    <p><strong>${TT_I18N.expirationDateLabel}</strong> ${expiryDate.toLocaleString('en-AU', dateOptions)}</p>
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
            validationContent.innerHTML = `<p class="sp-text-danger">${TT_I18N.errValidating}</p>`;
            validationResult.style.display = '';
            ;
            validationResult.className = 'sp-alert sp-alert-danger';
        }
        validateBtn.classList.remove('sp-btn-loading');
        validateBtn.disabled = false;
    });
});

function validateChatToken(token, allowAutoRenew = true) {
    const statusCell = document.getElementById('chat-status');
    const expiryCell = document.getElementById('chat-expiry');
    const button = document.getElementById('validate-chat-btn');
    statusCell.textContent = TT_I18N.statusValidating;
    button.disabled = true;
    button.classList.add('sp-btn-loading');
    const formData = new FormData();
    formData.append('validate_token', '1');
    formData.append('access_token', token);
    formData.append('sync_chat_expiry', '1');
    if (allowAutoRenew) {
        // Primary validations (page load / Validate button) let the server auto-renew the app
        // token: refresh it within 24h of expiry, or mint + persist a fresh one if it's invalid,
        // so the bot always picks up a working token from bot_chat_token. Post-renew re-validations
        // pass false, so a renewal can never loop.
        formData.append('auto_renew_if_24h', '1');
        formData.append('renew_if_invalid', '1');
    }
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.auto_renewed && data.renewed_token) {
                chatToken = data.renewed_token;
                statusCell.textContent = TT_I18N.statusAutoRenewed;
                statusCell.className = 'sp-text-warning';
                expiryCell.textContent = TT_I18N.statusRefreshing;
                // Re-validate without the renew flag so a bad renewal can't loop.
                setTimeout(() => validateChatToken(chatToken, false), 500);
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
            if (months > 0) timeParts.push(`${months} ${months > 1 ? TT_I18N.unitMonths : TT_I18N.unitMonth}`);
            if (days > 0) timeParts.push(`${days} ${days > 1 ? TT_I18N.unitDays : TT_I18N.unitDay}`);
            if (hours > 0) timeParts.push(`${hours} ${hours > 1 ? TT_I18N.unitHours : TT_I18N.unitHour}`);
            if (minutes > 0) timeParts.push(`${minutes} ${minutes > 1 ? TT_I18N.unitMinutes : TT_I18N.unitMinute}`);
            if (seconds > 0) timeParts.push(`${seconds} ${seconds > 1 ? TT_I18N.unitSeconds : TT_I18N.unitSecond}`);
            const timeString = timeParts.join(', ') || TT_I18N.zeroSeconds;
            statusCell.textContent = TT_I18N.statusValid;
            statusCell.className = 'sp-text-success';
            expiryCell.textContent = timeString;
            expiryCell.className = '';
        } else {
            statusCell.textContent = TT_I18N.statusInvalid;
            statusCell.className = 'sp-text-danger';
            expiryCell.textContent = '-';
            expiryCell.className = 'sp-text-danger';
        }
    })
    .catch(error => {
        statusCell.textContent = TT_I18N.statusError;
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
    statusCell.textContent = TT_I18N.statusFetching;
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
                statusCell.textContent = TT_I18N.statusNoToken;
                statusCell.className = 'sp-text-warning';
                expiryCell.textContent = fetchData.error ? fetchData.error : '-';
                button.disabled = false;
                button.classList.remove('sp-btn-loading');
                return Promise.reject(new Error(fetchData.error || TT_I18N.errNoToken));
            }
            // Now validate the freshly fetched token
            const currentToken = fetchData.access_token;
            statusCell.textContent = TT_I18N.statusValidating;
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
            if (months > 0) timeParts.push(`${months} ${months > 1 ? TT_I18N.unitMonths : TT_I18N.unitMonth}`);
            if (days > 0) timeParts.push(`${days} ${days > 1 ? TT_I18N.unitDays : TT_I18N.unitDay}`);
            if (hours > 0) timeParts.push(`${hours} ${hours > 1 ? TT_I18N.unitHours : TT_I18N.unitHour}`);
            if (minutes > 0) timeParts.push(`${minutes} ${minutes > 1 ? TT_I18N.unitMinutes : TT_I18N.unitMinute}`);
            if (seconds > 0) timeParts.push(`${seconds} ${seconds > 1 ? TT_I18N.unitSeconds : TT_I18N.unitSecond}`);
            const timeString = timeParts.join(', ') || TT_I18N.zeroSeconds;
            statusCell.textContent = TT_I18N.statusValid;
            statusCell.className = 'sp-text-success';
            expiryCell.textContent = timeString;
            expiryCell.className = '';
            // Save to cache
            saveTokenToCache(tokenId, 'regular', expiresIn, true);
        } else {
            const errMsg = data.error || TT_I18N.errInvalidToken;
            statusCell.textContent = TT_I18N.statusInvalid;
            statusCell.className = 'sp-text-danger';
            expiryCell.textContent = errMsg;
            expiryCell.className = 'sp-text-danger';
            // Save to cache
            saveTokenToCache(tokenId, 'regular', 0, false);
        }
        return data;
    })
    .catch(error => {
        const msg = (error && error.message) ? error.message : TT_I18N.errNetwork;
        statusCell.textContent = TT_I18N.statusError;
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
    statusCell.textContent = TT_I18N.statusRenewing;
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
            statusCell.textContent = TT_I18N.statusRenewed;
            statusCell.className = 'sp-text-warning';
            expiryCell.textContent = '-';
            expiryCell.className = '';
            // Optionally, auto-validate
            setTimeout(() => validateToken(data.new_token, tokenId), 500);
        } else {
            statusCell.textContent = TT_I18N.statusRenewFailed;
            statusCell.className = 'sp-text-danger';
        }
    })
    .catch(error => {
        statusCell.textContent = TT_I18N.statusError;
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
    resultContent.innerHTML = `<p>${TT_I18N.generatingChat}</p>`;
    resultBox.style.display = '';
    const formData = new FormData();
    formData.append('renew_chat_token', '1');
    formData.append('client_id', clientId);
    formData.append('client_secret', clientSecret);
    fetch('', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.new_token) {
                const newToken = data.new_token;
                const expiresIn = data.expires_in || 0;
                chatToken = newToken; // update for future validation
                const dateOptions = { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
                const expiryDate = new Date(Date.now() + expiresIn * 1000).toLocaleString('en-AU', dateOptions);
                // Show masked input with eye toggle and copy
                resultContent.innerHTML = `
                    <div class="sp-alert sp-alert-success">
                        <p><strong>? ${TT_I18N.chatGeneratedTitle}</strong></p>
                        <p style="margin-top:0.75rem;"><strong>${TT_I18N.chatTokenLabel}</strong></p>
                        <div style="display:flex;gap:0.5rem;align-items:center;">
                            <input class="sp-input" type="password" id="chat-token-input" value="${newToken}" readonly style="flex:1;">
                            <button class="sp-btn sp-btn-sm" id="toggle-chat-eye" title="${TT_I18N.showHideToken}"><span class="icon"><i class="fas fa-eye"></i></span></button>
                            <button class="sp-btn sp-btn-sm" id="copy-chat-token"><span class="icon"><i class="fas fa-copy"></i></span></button>
                        </div>
                        <small class="sp-text-muted">${TT_I18N.tokenExpiresAt.replace(':date', expiryDate)}</small><br>
                        <small class="sp-text-muted"><strong>&#x2139;&#xFE0F; ${TT_I18N.savedNotice}</strong></small>
                    </div>
                `;
                // attach handlers
                document.getElementById('toggle-chat-eye').addEventListener('click', function() {
                    toggleChatTokenVisibility('chat-token-input', this.querySelector('i'));
                });
                document.getElementById('copy-chat-token').addEventListener('click', function() {
                    copyChatToken('chat-token-input');
                });
                setTimeout(() => validateChatToken(chatToken, false), 400);
            } else {
                const err = data.error || TT_I18N.errChatGenerate;
                resultContent.innerHTML = `<p class="sp-text-danger">${err}</p>`;
            }
        })
        .catch(() => {
            resultContent.innerHTML = `<p class="sp-text-danger">${TT_I18N.errChatGenerating}</p>`;
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
    statusCell.textContent = TT_I18N.statusFetching;
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
    return fetch('', { method: 'POST', body: fetchFormData })
        .then(response => response.json())
        .then(fetchData => {
            if (!fetchData.success) {
                statusCell.textContent = TT_I18N.statusNoToken;
                statusCell.className = 'sp-text-warning';
                expiryCell.textContent = fetchData.error ? fetchData.error : '-';
                if (btn) { btn.disabled = false; btn.classList.remove('sp-btn-loading'); }
                return Promise.reject(new Error(fetchData.error || TT_I18N.errNoToken));
            }
            // Now validate the freshly fetched token
            const currentToken = fetchData.access_token;
            statusCell.textContent = TT_I18N.statusValidating;
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
                if (months > 0) timeParts.push(`${months} ${months > 1 ? TT_I18N.unitMonths : TT_I18N.unitMonth}`);
                if (days > 0) timeParts.push(`${days} ${days > 1 ? TT_I18N.unitDays : TT_I18N.unitDay}`);
                if (hours > 0) timeParts.push(`${hours} ${hours > 1 ? TT_I18N.unitHours : TT_I18N.unitHour}`);
                if (minutes > 0) timeParts.push(`${minutes} ${minutes > 1 ? TT_I18N.unitMinutes : TT_I18N.unitMinute}`);
                if (seconds > 0) timeParts.push(`${seconds} ${seconds > 1 ? TT_I18N.unitSeconds : TT_I18N.unitSecond}`);
                const timeString = timeParts.join(', ') || TT_I18N.zeroSeconds;
                statusCell.textContent = TT_I18N.statusValid;
                statusCell.className = 'sp-text-success';
                expiryCell.textContent = timeString;
                expiryCell.className = '';
                // Save to cache
                saveTokenToCache(tokenId, 'custom', expiresIn, true);
            } else {
                const errMsg = data.error || TT_I18N.errInvalidToken;
                statusCell.textContent = TT_I18N.statusInvalid;
                statusCell.className = 'sp-text-danger';
                expiryCell.textContent = errMsg;
                expiryCell.className = 'sp-text-danger';
                // Save to cache
                saveTokenToCache(tokenId, 'custom', 0, false);
            }
        })
        .catch((err) => {
            const msg = (err && err.message) ? err.message : TT_I18N.errNetwork;
            statusCell.textContent = TT_I18N.statusError;
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
    statusCell.textContent = TT_I18N.statusRenewing;
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
                statusCell.textContent = TT_I18N.statusRenewed;
                statusCell.className = 'sp-text-warning';
                // Optionally auto-validate
                setTimeout(() => validateCustomToken(newToken, tokenId), 500);
            } else {
                statusCell.textContent = TT_I18N.statusRenewFailed;
                statusCell.className = 'sp-text-danger';
                console.error('Renew failed:', data.error);
                Swal.fire({
                    title: TT_I18N.renewalFailedTitle,
                    text: data.error || TT_I18N.renewalFailedText,
                    icon: 'error'
                });
            }
        })
        .catch(err => {
            statusCell.textContent = TT_I18N.statusError;
            statusCell.className = 'sp-text-danger';
            console.error('Fetch error:', err);
            Swal.fire({
                title: TT_I18N.errorTitle,
                text: TT_I18N.renewalFailedText,
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
                Swal.fire({ title: TT_I18N.copiedTitle, text: TT_I18N.chatTokenCopied, icon: 'success', timer: 1500, showConfirmButton: false });
            });
        } else {
            input.select();
            document.execCommand('copy');
            Swal.fire({ title: TT_I18N.copiedTitle, text: TT_I18N.chatTokenCopied, icon: 'success', timer: 1500, showConfirmButton: false });
        }
    } catch (e) {
        Swal.fire({ title: TT_I18N.copyFailedTitle, text: TT_I18N.copyFailedText, icon: 'error' });
    }
}

function copyToken() {
    const tokenInput = document.getElementById('token-input');
    tokenInput.select();
    document.execCommand('copy');
    // Optional: Show a brief success message
    Swal.fire({
        title: TT_I18N.copiedTitle,
        text: TT_I18N.tokenCopied,
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
            title: TT_I18N.missingCredentialsTitle,
            text: TT_I18N.missingCredentialsSimple,
            icon: 'warning'
        });
        return;
    }
    const authUrl = `https://id.twitch.tv/oauth2/token?client_id=${encodeURIComponent(clientId)}&client_secret=${encodeURIComponent(clientSecret)}&grant_type=client_credentials`;
    // Copy to clipboard
    navigator.clipboard.writeText(authUrl).then(() => {
        Swal.fire({
            title: TT_I18N.authLinkTitle,
            html: `${TT_I18N.authLinkHtml}<br><br><code>${authUrl}</code>`,
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
            title: TT_I18N.authLinkTitle,
            html: `${TT_I18N.authLinkHtml}<br><br><code>${authUrl}</code>`,
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
