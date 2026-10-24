<?php
// Mirrors dashboard/api/stop_act_as.php for the beta session, then returns to beta.admin Users.
require __DIR__ . '/_session.php';
beta_session_start();
$back = 'https://beta.admin.botofthespecter.com/users?act_as=stopped';
if (empty($_SESSION['admin_act_as_active'])) { header('Location: /'); exit; }
// Act As started from Mod Channels goes back there; admin Act As goes back to beta.admin Users.
if (($_SESSION['admin_act_as_actor_role'] ?? '') === 'moderator') $back = '/mod-channels?act_as=stopped';
$original = $_SESSION['admin_act_as_original'] ?? null;
if (is_array($original) && !empty($original['access_token'])) {
    $_SESSION['user_id'] = $original['user_id'] ?? null;
    $_SESSION['username'] = $original['username'] ?? '';
    $_SESSION['twitchUserId'] = $original['twitchUserId'] ?? '';
    $_SESSION['access_token'] = $original['access_token'] ?? '';
    $_SESSION['refresh_token'] = $original['refresh_token'] ?? '';
    $_SESSION['api_key'] = $original['api_key'] ?? '';
    $_SESSION['is_admin'] = $original['is_admin'] ?? true;
    $_SESSION['beta_access'] = $original['beta_access'] ?? false;
    $_SESSION['use_custom'] = $original['use_custom'] ?? 0;
    $_SESSION['use_self'] = $original['use_self'] ?? 0;
    $_SESSION['user_data'] = $original['user_data'] ?? null;
}
unset(
    $_SESSION['admin_act_as_active'], $_SESSION['admin_act_as_started_at'], $_SESSION['admin_act_as_actor_user_id'],
    $_SESSION['admin_act_as_actor_username'], $_SESSION['admin_act_as_actor_role'], $_SESSION['admin_act_as_target_user_id'],
    $_SESSION['admin_act_as_target_username'], $_SESSION['admin_act_as_target_display_name'], $_SESSION['admin_act_as_original'],
    $_SESSION['editing_user'], $_SESSION['editing_username'], $_SESSION['editing_display_name'], $_SESSION['editing_profile_image'],
    $_SESSION['editing_access_token'], $_SESSION['editing_refresh_token'], $_SESSION['editing_api_key']
);
session_write_close();
header('Cache-Control: no-store');
header('Location: ' . $back);
exit;
