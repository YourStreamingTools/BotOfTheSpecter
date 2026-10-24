<?php
// Mirrors dashboard/login.php's StreamersConnect branch: verify auth_data_sig,
// else exchange server_token (both with the API key), then the same
// restricted/memorial checks and users insert/update. Unsigned auth_data is
// not accepted here.
require __DIR__ . '/_session.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') beta_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];

$cfg = require '/var/www/config/main.php';
$apiKey = $cfg['streamersconnect_api_key'] ?? '';
if (!$apiKey) beta_json(500, ['ok' => false, 'error' => 'server_not_configured']);

function sc_post(string $url, array $body, string $apiKey): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . $apiKey],
    ]);
    $response = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response && $http === 200) {
        $res = json_decode($response, true);
        if (!empty($res['success']) && !empty($res['payload']) && is_array($res['payload'])) return $res['payload'];
    }
    return null;
}

$decoded = null;
if (!empty($in['auth_data_sig']) && is_string($in['auth_data_sig'])) {
    $decoded = sc_post('https://streamersconnect.com/verify_auth_sig.php', ['auth_data_sig' => $in['auth_data_sig']], $apiKey);
}
if (!$decoded && !empty($in['server_token']) && is_string($in['server_token'])) {
    $decoded = sc_post('https://streamersconnect.com/token_exchange.php', ['server_token' => $in['server_token']], $apiKey);
}
if (!is_array($decoded) || empty($decoded['success'])) beta_json(401, ['ok' => false, 'error' => 'auth_failed']);
if (($decoded['service'] ?? '') !== 'twitch') beta_json(400, ['ok' => false, 'error' => 'unexpected_service']);

$accessToken = $decoded['access_token'] ?? null;
$refreshToken = $decoded['refresh_token'] ?? null;
$user = $decoded['user'] ?? [];
$twitchDisplayName = $user['display_name'] ?? ($user['global_name'] ?? null);
$twitchUsername = strtolower((string) ($user['login'] ?? $user['username'] ?? ''));
$profileImageUrl = $user['profile_image_url'] ?? null;
$twitchUserId = $user['id'] ?? null;
$email = $user['email'] ?? '';
if (!$accessToken || !$twitchUserId) beta_json(400, ['ok' => false, 'error' => 'parse_failed']);

require_once '/var/www/config/db_connect.php';
require_once '/var/www/dashboard/includes/username_rename.php';

$stmt = mysqli_prepare($conn, 'SELECT 1 FROM restricted_users WHERE twitch_user_id = ? OR username = ?');
mysqli_stmt_bind_param($stmt, 'ss', $twitchUserId, $twitchUsername);
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);
if (mysqli_stmt_num_rows($stmt) > 0) beta_json(403, ['ok' => false, 'error' => 'restricted']);
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, 'SELECT is_deceased FROM users WHERE twitch_user_id = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 's', $twitchUserId);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $isDeceased);
$found = mysqli_stmt_fetch($stmt);
mysqli_stmt_close($stmt);
if ($found && (int) $isDeceased === 1) beta_json(403, ['ok' => false, 'error' => 'memorial']);

beta_session_start();
// A revoked old session was just destroyed; start a fresh one for this login.
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$oldSid = session_id();
session_regenerate_id(true);
unset($_SESSION['ws_row']);
$_SESSION['access_token'] = $accessToken;
$_SESSION['refresh_token'] = $refreshToken;
$_SESSION['username'] = $twitchUsername;
$_SESSION['twitchUserId'] = $twitchUserId;
$_SESSION['profile_image'] = $profileImageUrl;
$_SESSION['display_name'] = $twitchDisplayName;
$_SESSION['twitch_expires_at'] = time() + (isset($decoded['expires_in']) ? (int) $decoded['expires_in'] : 14400);
$_SESSION['last_validated_at'] = time();

$stmt = mysqli_prepare($conn, 'SELECT id, api_key FROM users WHERE twitch_user_id = ?');
mysqli_stmt_bind_param($stmt, 's', $twitchUserId);
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);
if (mysqli_stmt_num_rows($stmt) > 0) {
    mysqli_stmt_bind_result($stmt, $userId, $userApiKey);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    $_SESSION['user_id'] = $userId;
    $_SESSION['api_key'] = $userApiKey;
    $renameResult = bots_handle_username_change_on_login($conn, $twitchUserId, $twitchUsername);
    $usernameForUpdate = $twitchUsername;
    if (!empty($renameResult['changed']) && empty($renameResult['apply_username_update'])) {
        $usernameForUpdate = $renameResult['old_username'] ?: $twitchUsername;
        error_log("beta-dashboard callback: username change blocked for twitch_user_id=$twitchUserId; kept username=$usernameForUpdate (db: " . ($renameResult['db']['message'] ?? 'n/a') . ')');
    }
    $_SESSION['username'] = $usernameForUpdate;
    $stmt = mysqli_prepare($conn, 'UPDATE users SET access_token = ?, refresh_token = ?, profile_image = ?, username = ?, twitch_display_name = ?, last_login = ?, email = ? WHERE twitch_user_id = ?');
    $lastLogin = date('Y-m-d H:i:s');
    mysqli_stmt_bind_param($stmt, 'ssssssss', $accessToken, $refreshToken, $profileImageUrl, $usernameForUpdate, $twitchDisplayName, $lastLogin, $email, $twitchUserId);
    if (!mysqli_stmt_execute($stmt)) {
        error_log('beta-dashboard callback: update failed: ' . mysqli_stmt_error($stmt));
        beta_json(500, ['ok' => false, 'error' => 'db_error']);
    }
} else {
    mysqli_stmt_close($stmt);
    $newApiKey = bin2hex(random_bytes(16));
    if (!mysqli_begin_transaction($conn, MYSQLI_TRANS_START_READ_WRITE)) mysqli_autocommit($conn, false);
    try {
        $idResult = mysqli_query($conn, 'SELECT id FROM users ORDER BY id ASC FOR UPDATE');
        $expected = 1;
        if ($idResult) {
            while ($row = mysqli_fetch_assoc($idResult)) {
                if ((int) $row['id'] !== $expected) break;
                $expected++;
            }
        }
        $stmt = mysqli_prepare($conn, 'INSERT INTO users (id, username, access_token, refresh_token, api_key, profile_image, twitch_user_id, twitch_display_name, email, is_admin, new_media) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1)');
        if ($stmt === false) throw new Exception('Prepare failed: ' . mysqli_error($conn));
        mysqli_stmt_bind_param($stmt, 'issssssss', $expected, $twitchUsername, $accessToken, $refreshToken, $newApiKey, $profileImageUrl, $twitchUserId, $twitchDisplayName, $email);
        if (!mysqli_stmt_execute($stmt)) throw new Exception('Insert failed: ' . mysqli_stmt_error($stmt));
        $maxRes = mysqli_query($conn, 'SELECT MAX(id) AS maxid FROM users');
        if ($maxRes) {
            $maxRow = mysqli_fetch_assoc($maxRes);
            @mysqli_query($conn, 'ALTER TABLE users AUTO_INCREMENT = ' . ((int) $maxRow['maxid'] + 1));
        }
        mysqli_commit($conn);
        $_SESSION['user_id'] = $expected;
        $_SESSION['api_key'] = $newApiKey;
    } catch (Exception $e) {
        @mysqli_rollback($conn);
        mysqli_autocommit($conn, true);
        error_log('beta-dashboard callback: ' . $e->getMessage());
        $_SESSION = [];
        beta_json(500, ['ok' => false, 'error' => 'db_error']);
    }
}
// SSO Active Sessions row for this login (the old id's row goes).
if ($oldSid !== '' && $oldSid !== session_id()) beta_ws_delete($oldSid);
beta_ws_register();
beta_json(200, ['ok' => true]);
