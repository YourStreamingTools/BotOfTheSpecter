<?php
// Admin Act As for the beta dashboard (started from beta.admin Users).
// beta.admin mints a single-use handoff_tokens row (target 'beta-act-as',
// 5 minutes) holding the admin's Twitch id and the target users.id; this
// consumes it. The browser must already hold a beta dashboard session for that
// same admin, and users.is_admin is re-checked. Then the same session keys as
// dashboard/admin/act_as_user.php are set, and beta_act_as_apply() swaps the
// session identity to the target the way includes/userdata.php does.
require __DIR__ . '/_session.php';

function aa_go(string $q): never
{
    header('Cache-Control: no-store');
    header('Location: /?act_as=' . $q);
    exit;
}

$token = preg_replace('/[^a-f0-9]/i', '', (string) ($_GET['handoff'] ?? ''));
$db = beta_ws_db();
if (strlen($token) !== 64 || !$db) aa_go('invalid');

beta_session_start();
if (empty($_SESSION['access_token']) || empty($_SESSION['twitchUserId'])) aa_go('signin');

$st = $db->prepare("SELECT twitch_user_id, username FROM handoff_tokens WHERE token = ? AND used = 0 AND expires_at > NOW() AND target = 'beta-act-as' LIMIT 1");
if (!$st) aa_go('error');
$st->bind_param('s', $token);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();
if (!$row) aa_go('invalid');
$mark = $db->prepare('UPDATE handoff_tokens SET used = 1 WHERE token = ?');
if ($mark) { $mark->bind_param('s', $token); $mark->execute(); $mark->close(); }

$actorTid = (string) ($_SESSION['admin_act_as_original']['twitchUserId'] ?? $_SESSION['twitchUserId']);
if ((string) $row['twitch_user_id'] !== $actorTid) aa_go('denied');

$q = $db->prepare('SELECT id, username, is_admin FROM users WHERE twitch_user_id = ? LIMIT 1');
$q->bind_param('s', $actorTid);
$q->execute();
$actor = $q->get_result()->fetch_assoc();
$q->close();
if (!$actor || (int) $actor['is_admin'] !== 1) aa_go('denied');

$targetId = (int) $row['username'];
$q = $db->prepare('SELECT id, username, twitch_display_name FROM users WHERE id = ? LIMIT 1');
$q->bind_param('i', $targetId);
$q->execute();
$target = $q->get_result()->fetch_assoc();
$q->close();
if (!$target) aa_go('not_found');

if (!isset($_SESSION['admin_act_as_original']) || !is_array($_SESSION['admin_act_as_original'])) {
    $_SESSION['admin_act_as_original'] = beta_act_as_snapshot();
}
unset($_SESSION['mod_act_as_active'], $_SESSION['mod_act_as_started_at'], $_SESSION['mod_act_as_actor_username'], $_SESSION['mod_act_as_target_user_id'], $_SESSION['mod_act_as_target_username'], $_SESSION['mod_act_as_target_display_name'], $_SESSION['admin_act_as_applied']);
$_SESSION['admin_act_as_active'] = true;
$_SESSION['admin_act_as_started_at'] = time();
$_SESSION['admin_act_as_actor_user_id'] = (int) $actor['id'];
$_SESSION['admin_act_as_actor_username'] = (string) $actor['username'];
$_SESSION['admin_act_as_actor_role'] = 'admin';
$_SESSION['admin_act_as_target_user_id'] = (int) $target['id'];
$_SESSION['admin_act_as_target_username'] = (string) $target['username'];
$_SESSION['admin_act_as_target_display_name'] = (string) ($target['twitch_display_name'] ?? '');
// Load the target's identity into the session now (see beta_act_as_apply).
beta_act_as_apply();

// Same audit row as the live act_as_user.php.
$details = json_encode(['actor_user_id' => (int) $actor['id'], 'actor_username' => $actor['username'], 'target_user_id' => (int) $target['id'], 'target_username' => $target['username'], 'surface' => 'beta-dashboard'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$a = $db->prepare("INSERT INTO admin_audit_log (actor_user_id, actor_username, action, status, target_type, target_value, details_json, request_method, request_path, ip_address, user_agent) VALUES (?, ?, 'act_as_user', 'success', 'user', ?, ?, 'GET', '/api/act_as.php', ?, ?)");
if ($a) {
    $aid = (int) $actor['id']; $aun = (string) $actor['username']; $tv = (string) $target['username'];
    $ip = substr((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $a->bind_param('isssss', $aid, $aun, $tv, $details, $ip, $ua);
    $a->execute();
    $a->close();
}
session_write_close();
header('Cache-Control: no-store');
header('Location: /');
exit;
