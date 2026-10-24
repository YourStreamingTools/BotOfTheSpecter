<?php
// beta.admin sign-in. Operators only, no Twitch button on this host.
// Without a token: send the browser to home/sso.php?target=beta-admin, which
// requires a botofthespecter.com session with users.is_admin = 1 and mints a
// single-use handoff token bound to this target.
// With ?handoff=<token>: verify it (target, unused, unexpired), re-check
// users.is_admin, start the SPECTER_BETA_ADMIN session and register its
// 'beta-admin:' row in web_sessions so it shows in Active Sessions.
require __DIR__ . '/_session.php';

function badm_safe_return($raw): string
{
    $rt = (string) $raw;
    if ($rt !== '' && strncmp($rt, '/', 1) === 0 && strncmp($rt, '//', 2) !== 0 && strncmp($rt, '/api/', 5) !== 0) {
        return $rt;
    }
    return '/';
}

function badm_go(string $to): never
{
    header('Cache-Control: no-store');
    header('Location: ' . $to);
    exit;
}

$return = badm_safe_return($_GET['return'] ?? '/');
$token = preg_replace('/[^a-f0-9]/i', '', (string) ($_GET['handoff'] ?? ''));

if ($token === '') {
    badm_session_start();
    if (!empty($_SESSION['access_token']) && (int) ($_SESSION['is_admin'] ?? 0) === 1) {
        badm_go($return);
    }
    badm_go('https://botofthespecter.com/sso.php?target=beta-admin&return=' . rawurlencode($return));
}

$db = badm_ws_db();
if (strlen($token) !== 64 || !$db) {
    badm_go('/?signin=failed');
}
$st = $db->prepare(
    "SELECT twitch_user_id, username, display_name, access_token, refresh_token, profile_image, is_admin
     FROM handoff_tokens
     WHERE token = ? AND used = 0 AND expires_at > NOW() AND target = 'beta-admin'
     LIMIT 1"
);
if (!$st) {
    badm_go('/?signin=failed');
}
$st->bind_param('s', $token);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();
if (!$row) {
    badm_go('/?signin=failed');
}

// Single use, whatever happens next.
$mark = $db->prepare('UPDATE handoff_tokens SET used = 1 WHERE token = ?');
if ($mark) {
    $mark->bind_param('s', $token);
    $mark->execute();
    $mark->close();
}

$user = null;
$u = $db->prepare('SELECT id, username, is_admin FROM users WHERE twitch_user_id = ? LIMIT 1');
if ($u) {
    $tid = (string) $row['twitch_user_id'];
    $u->bind_param('s', $tid);
    $u->execute();
    $user = $u->get_result()->fetch_assoc() ?: null;
    $u->close();
}
if ((int) $row['is_admin'] !== 1 || !$user || (int) $user['is_admin'] !== 1) {
    badm_go('/?signin=denied');
}

badm_session_start();
$oldSid = session_id();
session_regenerate_id(true);
if ($oldSid !== '' && $oldSid !== session_id()) {
    badm_ws_delete($oldSid);
}
$_SESSION = [];
$_SESSION['access_token'] = (string) $row['access_token'];
$_SESSION['refresh_token'] = (string) $row['refresh_token'];
$_SESSION['twitchUserId'] = (string) $row['twitch_user_id'];
$_SESSION['twitch_user_id'] = (string) $row['twitch_user_id'];
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['username'] = (string) ($user['username'] ?: $row['username']);
$_SESSION['display_name'] = (string) $row['display_name'];
$_SESSION['profile_image'] = (string) $row['profile_image'];
$_SESSION['is_admin'] = 1;
$_SESSION['last_validated_at'] = time();
$_SESSION['twitch_expires_at'] = time() + 14400;
badm_ws_register();
badm_go($return);
