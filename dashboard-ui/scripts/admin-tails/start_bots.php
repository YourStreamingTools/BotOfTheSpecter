<?php
// GET: the users table the old page rendered, plus the page strings. The handlers above
// answer ?get_running_bots, ?check_one_bot and the POSTs (validate/renew token, mod
// check, make mod, start, stop, restart).
ob_end_clean();
header('Content-Type: application/json');
$out = [];
foreach ($users as $u) {
    $out[] = [
        'id' => (int)$u['id'],
        'username' => (string)$u['username'],
        'twitch_user_id' => (string)$u['twitch_user_id'],
        'display_name' => (string)($u['twitch_display_name'] ?: $u['username']),
        'profile_image' => (string)$u['profile_image'],
        'custom_enabled' => ((int)($u['custom_bot_enabled'] ?? 0)) === 1,
    ];
}
echo json_encode(['success' => true, 'users' => $out, 'L' => badm_lang('admin_start_bots_')], JSON_UNESCAPED_UNICODE);
exit;
