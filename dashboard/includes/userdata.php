<?php
// Load translations so user-facing messages are localized.
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

$isActAs = !defined('ADMIN_PANEL_CONTEXT') && isset($_SESSION['admin_act_as_active']) && $_SESSION['admin_act_as_active'] === true;
$sessionAccessToken = $_SESSION['access_token'] ?? null;
$originalContext = (isset($_SESSION['admin_act_as_original']) && is_array($_SESSION['admin_act_as_original']))
    ? $_SESSION['admin_act_as_original']
    : [];
$actorAccessToken = $isActAs
    ? ($originalContext['access_token'] ?? $sessionAccessToken)
    : $sessionAccessToken;

if (!$actorAccessToken) {
    error_log("Access token not found in session.");
    header("Location: login.php");
    exit();
}

$userSTMT = null;
$targetUserId = $isActAs ? (int)($_SESSION['admin_act_as_target_user_id'] ?? 0) : 0;

if ($isActAs && $targetUserId > 0) {
    $userSTMT = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    if ($userSTMT) {
        $userSTMT->bind_param("i", $targetUserId);
    }
}

if (!$userSTMT) {
    $isActAs = false;
    // Resolve by stable identity, not the single global users.access_token.
    // That column is shared across devices and is rewritten on login/refresh;
    // looking up by it signed people out when another device or the API
    // rotated the token while this browser still held a valid session.
    $sessionTwitchUserId = (string)($_SESSION['twitchUserId'] ?? $_SESSION['twitch_user_id'] ?? '');
    $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($sessionTwitchUserId !== '') {
        $userSTMT = $conn->prepare("SELECT * FROM users WHERE twitch_user_id = ? LIMIT 1");
        if ($userSTMT) {
            $userSTMT->bind_param("s", $sessionTwitchUserId);
        }
    } elseif ($sessionUserId > 0) {
        $userSTMT = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        if ($userSTMT) {
            $userSTMT->bind_param("i", $sessionUserId);
        }
    }
    if (!$userSTMT) {
        // Last resort for legacy sessions that only stored the token.
        $userSTMT = $conn->prepare("SELECT * FROM users WHERE access_token = ? LIMIT 1");
        if ($userSTMT) {
            $userSTMT->bind_param("s", $actorAccessToken);
        }
    }
}

if (!$userSTMT || !$userSTMT->execute()) {
    error_log("Database query failed: " . ($userSTMT ? $userSTMT->error : 'prepare failed'));
    die(t('userdata_db_query_error'));
}
$userResult = $userSTMT->get_result();
if ($userResult->num_rows === 0) {
    if ($isActAs) {
        unset(
            $_SESSION['admin_act_as_active'],
            $_SESSION['admin_act_as_started_at'],
            $_SESSION['admin_act_as_actor_user_id'],
            $_SESSION['admin_act_as_actor_username'],
            $_SESSION['admin_act_as_actor_role'],
            $_SESSION['admin_act_as_target_user_id'],
            $_SESSION['admin_act_as_target_username'],
            $_SESSION['admin_act_as_target_display_name']
        );
        $fallbackTwitchId = (string)($_SESSION['twitchUserId'] ?? $_SESSION['twitch_user_id'] ?? '');
        $fallbackStmt = null;
        if ($fallbackTwitchId !== '') {
            $fallbackStmt = $conn->prepare("SELECT * FROM users WHERE twitch_user_id = ? LIMIT 1");
            if ($fallbackStmt) {
                $fallbackStmt->bind_param("s", $fallbackTwitchId);
            }
        }
        if (!$fallbackStmt) {
            $fallbackStmt = $conn->prepare("SELECT * FROM users WHERE access_token = ? LIMIT 1");
            if ($fallbackStmt) {
                $fallbackStmt->bind_param("s", $actorAccessToken);
            }
        }
        if ($fallbackStmt && $fallbackStmt->execute()) {
            $fallbackResult = $fallbackStmt->get_result();
            if ($fallbackResult->num_rows > 0) {
                $user = $fallbackResult->fetch_assoc();
                $fallbackStmt->close();
                goto user_loaded;
            }
        }
        if ($fallbackStmt) {
            $fallbackStmt->close();
        }
    }
    error_log("User not found for session (twitchUserId="
        . ($_SESSION['twitchUserId'] ?? $_SESSION['twitch_user_id'] ?? '')
        . ", user_id=" . ($_SESSION['user_id'] ?? '') . ")");
    header("Location: login.php");
    exit();
}

$user = $userResult->fetch_assoc();
user_loaded:
$user_id = $user['id'];
$username = $user['username'];
$twitchDisplayName = $user['twitch_display_name'];
$twitch_profile_image_url = $user['profile_image'];
$email = $user['email'];
$is_admin = ($user['is_admin'] == 1);
$betaAccess = ($user['beta_access'] == 1);
$betaPrograms = json_decode($user['beta_programs'] ?? '[]', true) ?? [];
$twitchUserId = $user['twitch_user_id'];
$refreshToken = $user['refresh_token'];
$api_key = $user['api_key'];
$timezone = 'Australia/Sydney';
$broadcasterID = $twitchUserId;
$use_custom = $user['use_custom'];
$use_self = $user['use_self'];

// Session tokens are authoritative for this browser (validated / refreshed
// by session_bootstrap). users.access_token is a single global column and
// must not overwrite a still-valid per-session token from another device
// or a just-refreshed session value.
if ($isActAs) {
    $authToken = $actorAccessToken;
    $refreshToken = $originalContext['refresh_token'] ?? ($_SESSION['refresh_token'] ?? $refreshToken);
    $_SESSION['access_token'] = $actorAccessToken;
    $_SESSION['refresh_token'] = $refreshToken;
} else {
    $authToken = $sessionAccessToken ?: ($user['access_token'] ?? '');
    if (!empty($_SESSION['refresh_token'])) {
        $refreshToken = $_SESSION['refresh_token'];
    }
    if (empty($_SESSION['access_token']) && $authToken !== '') {
        $_SESSION['access_token'] = $authToken;
    }
    if (empty($_SESSION['refresh_token']) && !empty($refreshToken)) {
        $_SESSION['refresh_token'] = $refreshToken;
    }
}

$_SESSION['user_id'] = $user_id;
$_SESSION['username'] = $username;
$_SESSION['twitchUserId'] = $twitchUserId;
$_SESSION['api_key'] = $api_key;
$_SESSION['user_data'] = $user;
$_SESSION['is_admin'] = $is_admin;
$_SESSION['beta_access'] = $betaAccess;
$_SESSION['beta_programs'] = $betaPrograms;
$_SESSION['use_custom'] = $use_custom;
$_SESSION['use_self'] = $use_self;
?>
