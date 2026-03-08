<?php
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
    $userSTMT = $conn->prepare("SELECT * FROM users WHERE access_token = ?");
    $userSTMT->bind_param("s", $actorAccessToken);
}

if (!$userSTMT->execute()) {
    error_log("Database query failed: " . $userSTMT->error);
    die("An error occurred.");
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
        $fallbackStmt = $conn->prepare("SELECT * FROM users WHERE access_token = ?");
        $fallbackStmt->bind_param("s", $actorAccessToken);
        if ($fallbackStmt->execute()) {
            $fallbackResult = $fallbackStmt->get_result();
            if ($fallbackResult->num_rows > 0) {
                $user = $fallbackResult->fetch_assoc();
                $fallbackStmt->close();
                goto user_loaded;
            }
        }
        $fallbackStmt->close();
    }
    error_log("User not found with access token: " . $actorAccessToken);
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
$twitchUserId = $user['twitch_user_id'];
$refreshToken = $user['refresh_token'];
$api_key = $user['api_key'];
$timezone = 'Australia/Sydney';
$broadcasterID = $twitchUserId;
$authToken = $user['access_token'] ?? '';
$use_custom = $user['use_custom'];
$use_self = $user['use_self'];

$_SESSION['user_id'] = $user_id;
$_SESSION['username'] = $username;
$_SESSION['twitchUserId'] = $twitchUserId;
$_SESSION['api_key'] = $api_key;
$_SESSION['user_data'] = $user;
$_SESSION['is_admin'] = $is_admin;
$_SESSION['beta_access'] = $betaAccess;
$_SESSION['use_custom'] = $use_custom;
$_SESSION['use_self'] = $use_self;

if ($isActAs) {
    $_SESSION['access_token'] = $actorAccessToken;
    $_SESSION['refresh_token'] = $originalContext['refresh_token'] ?? ($_SESSION['refresh_token'] ?? null);
} else {
    $_SESSION['access_token'] = $authToken;
    $_SESSION['refresh_token'] = $refreshToken;
}
?>
