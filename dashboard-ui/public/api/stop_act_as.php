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
    // Older Act As sessions did not save these; leave the current value then.
    foreach (['user_id', 'username', 'display_name', 'profile_image', 'twitchUserId', 'access_token', 'refresh_token', 'api_key', 'is_admin', 'beta_access', 'beta_programs', 'use_custom', 'use_self', 'use_custom_module', 'user_data'] as $k) {
        if (array_key_exists($k, $original)) $_SESSION[$k] = $original[$k];
    }
}
beta_act_as_clear();
session_write_close();
header('Cache-Control: no-store');
header('Location: ' . $back);
exit;
