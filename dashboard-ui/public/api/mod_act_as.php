<?php
// Moderator Act As for the beta dashboard (started from the Mod Channels page).
// Port of dashboard/api/switch_channel.php on the beta session: admins (and the
// botofthespecter account) may act as anyone; everyone else must be a Twitch
// moderator of the target channel, checked live against Helix with the actor's
// own token. Sets the same session keys as the live switch, then opens the beta
// dashboard home. Stopping goes through stop_act_as.php, which returns here.
require __DIR__ . '/_session.php';
require_once '/var/www/config/db_connect.php';
include '/var/www/config/twitch.php';

function mca_go(string $q): never
{
    header('Cache-Control: no-store');
    header('Location: /mod-channels?act_as=' . $q);
    exit;
}

function mca_can_moderate(string $target, string $userId, string $token, string $clientID): bool
{
    if ($target === '' || $userId === '' || $token === '' || $clientID === '') return false;
    $cursor = null;
    do {
        $url = 'https://api.twitch.tv/helix/moderation/channels?user_id=' . urlencode($userId) . '&first=100' . ($cursor ? '&after=' . urlencode($cursor) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Client-ID: ' . $clientID], CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false || $code !== 200) { error_log("beta mod_act_as.php moderated-channels check failed. HTTP: {$code}, user: {$userId}, target: {$target}"); return false; }
        $data = json_decode($res, true);
        if (!isset($data['data']) || !is_array($data['data'])) return false;
        foreach ($data['data'] as $c) if ((string) ($c['broadcaster_id'] ?? '') === $target) return true;
        $cursor = $data['pagination']['cursor'] ?? null;
    } while (!empty($cursor));
    return false;
}

beta_session_start();
if (empty($_SESSION['access_token'])) { header('Location: /'); exit; }
$target = preg_replace('/\D/', '', (string) ($_GET['user_id'] ?? ''));
if ($target === '') mca_go('not_found');

// Use the original actor when already acting as someone else.
$actor = (isset($_SESSION['admin_act_as_original']) && is_array($_SESSION['admin_act_as_original'])) ? $_SESSION['admin_act_as_original'] : [
    'user_id' => $_SESSION['user_id'] ?? null,
    'username' => $_SESSION['username'] ?? '',
    'twitchUserId' => $_SESSION['twitchUserId'] ?? '',
    'access_token' => $_SESSION['access_token'] ?? null,
    'refresh_token' => $_SESSION['refresh_token'] ?? null,
    'api_key' => $_SESSION['api_key'] ?? null,
    'is_admin' => $_SESSION['is_admin'] ?? false,
    'beta_access' => $_SESSION['beta_access'] ?? false,
    'use_custom' => $_SESSION['use_custom'] ?? 0,
    'use_self' => $_SESSION['use_self'] ?? 0,
    'use_custom_module' => $_SESSION['use_custom_module'] ?? 0,
    'user_data' => $_SESSION['user_data'] ?? null,
];
$actorName = strtolower(trim((string) ($actor['username'] ?? '')));
$actorIsAdmin = !empty($actor['is_admin']);
$ok = $actorIsAdmin || $actorName === 'botofthespecter';
if (!$ok) {
    session_write_close(); // do not hold the session lock during the Twitch call
    $ok = mca_can_moderate($target, trim((string) ($actor['twitchUserId'] ?? '')), trim((string) ($actor['access_token'] ?? '')), (string) ($clientID ?? ''));
    beta_session_start();
}
if (!$ok) mca_go('denied');

$st = $conn->prepare('SELECT id, username, twitch_display_name FROM users WHERE twitch_user_id = ? LIMIT 1');
$st->bind_param('s', $target);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();
if (!$row) mca_go('not_found');

if (!isset($_SESSION['admin_act_as_original']) || !is_array($_SESSION['admin_act_as_original'])) $_SESSION['admin_act_as_original'] = $actor;
unset(
    $_SESSION['editing_user'], $_SESSION['editing_username'], $_SESSION['editing_display_name'], $_SESSION['editing_profile_image'],
    $_SESSION['editing_access_token'], $_SESSION['editing_refresh_token'], $_SESSION['editing_api_key'],
    $_SESSION['mod_act_as_active'], $_SESSION['mod_act_as_started_at'], $_SESSION['mod_act_as_actor_username'],
    $_SESSION['mod_act_as_target_user_id'], $_SESSION['mod_act_as_target_username'], $_SESSION['mod_act_as_target_display_name']
);
$_SESSION['user_data'] = null;
$_SESSION['admin_act_as_active'] = true;
$_SESSION['admin_act_as_started_at'] = time();
$_SESSION['admin_act_as_actor_user_id'] = $actor['user_id'] ?? null;
$_SESSION['admin_act_as_actor_username'] = $actor['username'] ?? '';
$_SESSION['admin_act_as_actor_role'] = $actorIsAdmin ? 'admin' : 'moderator';
$_SESSION['admin_act_as_target_user_id'] = (int) $row['id'];
$_SESSION['admin_act_as_target_username'] = (string) $row['username'];
$_SESSION['admin_act_as_target_display_name'] = (string) ($row['twitch_display_name'] ?? '');
session_write_close();
header('Cache-Control: no-store');
header('Location: /');
exit;
