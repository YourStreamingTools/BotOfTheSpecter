<?php
require_once '/var/www/lib/session_bootstrap.php';

require_once __DIR__ . '/admin_access.php';

$targetUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
if ($targetUserId <= 0) {
    header('Location: users.php?act_as=invalid');
    exit;
}

$stmt = $conn->prepare("SELECT id, username, twitch_display_name, profile_image, twitch_user_id, access_token, refresh_token, api_key, is_admin, beta_access, use_custom, use_self, email FROM users WHERE id = ? LIMIT 1");
if (!$stmt) {
    admin_audit_log('act_as_user', 'error', ['reason' => 'prepare_failed', 'target_user_id' => $targetUserId], 'user_id', (string) $targetUserId);
    header('Location: users.php?act_as=error');
    exit;
}

$stmt->bind_param('i', $targetUserId);
$stmt->execute();
$result = $stmt->get_result();
$targetUser = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$targetUser) {
    admin_audit_log('act_as_user', 'warning', ['reason' => 'target_not_found', 'target_user_id' => $targetUserId], 'user_id', (string) $targetUserId);
    header('Location: users.php?act_as=not_found');
    exit;
}

// If already acting as a user, restore the original admin session first
// before switching to the new target (supports opening act-as in new tabs).
if (!empty($_SESSION['admin_act_as_active']) && isset($_SESSION['admin_act_as_original']) && is_array($_SESSION['admin_act_as_original'])) {
    $original = $_SESSION['admin_act_as_original'];
    if (!empty($original['access_token'])) {
        $_SESSION['user_id']       = $original['user_id']       ?? null;
        $_SESSION['username']      = $original['username']      ?? '';
        $_SESSION['twitchUserId']  = $original['twitchUserId']  ?? '';
        $_SESSION['access_token']  = $original['access_token']  ?? '';
        $_SESSION['refresh_token'] = $original['refresh_token'] ?? '';
        $_SESSION['api_key']       = $original['api_key']       ?? '';
        $_SESSION['is_admin']      = $original['is_admin']      ?? true;
        $_SESSION['beta_access']   = $original['beta_access']   ?? false;
        $_SESSION['use_custom']    = $original['use_custom']    ?? 0;
        $_SESSION['use_self']      = $original['use_self']      ?? 0;
        $_SESSION['user_data']     = $original['user_data']     ?? null;
    }
    unset(
        $_SESSION['admin_act_as_active'],
        $_SESSION['admin_act_as_started_at'],
        $_SESSION['admin_act_as_actor_user_id'],
        $_SESSION['admin_act_as_actor_username'],
        $_SESSION['admin_act_as_actor_role'],
        $_SESSION['admin_act_as_target_user_id'],
        $_SESSION['admin_act_as_target_username'],
        $_SESSION['admin_act_as_target_display_name'],
        $_SESSION['admin_act_as_original']
    );
}

if (!isset($_SESSION['admin_act_as_original']) || !is_array($_SESSION['admin_act_as_original'])) {
    $_SESSION['admin_act_as_original'] = [
        'user_id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'twitchUserId' => $_SESSION['twitchUserId'] ?? null,
        'access_token' => $_SESSION['access_token'] ?? null,
        'refresh_token' => $_SESSION['refresh_token'] ?? null,
        'api_key' => $_SESSION['api_key'] ?? null,
        'is_admin' => $_SESSION['is_admin'] ?? true,
        'beta_access' => $_SESSION['beta_access'] ?? false,
        'use_custom' => $_SESSION['use_custom'] ?? 0,
        'use_self' => $_SESSION['use_self'] ?? 0,
        'user_data' => $_SESSION['user_data'] ?? null,
    ];
}

$actorUserId = $_SESSION['admin_act_as_original']['user_id'] ?? ($_SESSION['user_id'] ?? null);
$actorUsername = $_SESSION['admin_act_as_original']['username'] ?? ($_SESSION['username'] ?? '');

unset(
    $_SESSION['editing_user'],
    $_SESSION['editing_username'],
    $_SESSION['editing_display_name'],
    $_SESSION['editing_profile_image'],
    $_SESSION['editing_access_token'],
    $_SESSION['editing_refresh_token'],
    $_SESSION['editing_api_key']
);

$_SESSION['user_data'] = null;
$_SESSION['admin_act_as_active'] = true;
$_SESSION['admin_act_as_started_at'] = time();
$_SESSION['admin_act_as_actor_user_id'] = $actorUserId;
$_SESSION['admin_act_as_actor_username'] = $actorUsername;
$_SESSION['admin_act_as_actor_role'] = 'admin';
$_SESSION['admin_act_as_target_user_id'] = (int) ($targetUser['id'] ?? 0);
$_SESSION['admin_act_as_target_username'] = $targetUser['username'] ?? '';
$_SESSION['admin_act_as_target_display_name'] = $targetUser['twitch_display_name'] ?? '';

unset(
    $_SESSION['mod_act_as_active'],
    $_SESSION['mod_act_as_started_at'],
    $_SESSION['mod_act_as_actor_username'],
    $_SESSION['mod_act_as_target_user_id'],
    $_SESSION['mod_act_as_target_username'],
    $_SESSION['mod_act_as_target_display_name']
);

admin_audit_log(
    'act_as_user',
    'success',
    [
        'actor_user_id' => $actorUserId,
        'actor_username' => $actorUsername,
        'target_user_id' => (int) ($targetUser['id'] ?? 0),
        'target_username' => $targetUser['username'] ?? '',
    ],
    'user',
    (string) ($targetUser['username'] ?? $targetUserId)
);

session_write_close();
header('Location: ../dashboard.php');
exit;
