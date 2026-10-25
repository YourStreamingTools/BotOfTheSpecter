<?php
// Twitch user token upkeep for pages that must keep running (Closed Captions).
//   GET ?op=ping      keeps the beta session alive (beta_session_start slides it)
//   GET ?op=check     validates the session's Twitch token with
//                     id.twitch.tv/oauth2/validate, and refreshes it when Twitch
//                     answers 401 or it has under 15 minutes left.
// Reuses the live helpers in /var/www/lib/web_session.php (the same validate,
// refresh and client credential lookup the old dashboard's session_bootstrap
// uses). New tokens go where the old dashboard keeps them: the session, the
// users row and this session's beta web_sessions row. Tokens never leave the server.
require_once dirname(__DIR__) . '/_live.php';
require_once '/var/www/lib/web_session.php';

const BETA_TW_LOW = 900;    // refresh when under 15 minutes are left
const BETA_TW_HOUR = 3600;  // Twitch requires a validate every hour

$op = (string) ($_GET['op'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !in_array($op, ['ping', 'check'], true)) beta_json(400, ['success' => false, 'message' => 'Unknown action.']);
if ($op === 'ping') { session_write_close(); beta_json(200, ['success' => true]); }

// The token is always the signed-in person's, also while acting as another channel.
$uid = (string) (beta_actor('twitchUserId') ?? '');
$at = (string) ($_SESSION['access_token'] ?? '');
$rt = (string) ($_SESSION['refresh_token'] ?? '');
// Don't hold the session lock over the Twitch calls; the relay shares it.
session_write_close();
if ($uid === '') beta_json(200, ['success' => false, 'signin' => true, 'message' => 'Twitch sign-in needs renewing.']);

// When to check next: hourly, or 15 minutes before expiry, whichever is first.
function beta_tw_next(int $expiresIn): int {
    return max(60, min(BETA_TW_HOUR, $expiresIn - BETA_TW_LOW));
}
// Writes the tokens into the session and this session's web_sessions row.
function beta_tw_store(string $at, string $rt, int $expiresIn): void {
    beta_session_start();
    if ($at !== '') $_SESSION['access_token'] = $at;
    if ($rt !== '') $_SESSION['refresh_token'] = $rt;
    // Keep the copy Stop Acting As restores in step.
    if (isset($_SESSION['admin_act_as_original']) && is_array($_SESSION['admin_act_as_original'])) {
        if ($at !== '') $_SESSION['admin_act_as_original']['access_token'] = $at;
        if ($rt !== '') $_SESSION['admin_act_as_original']['refresh_token'] = $rt;
    }
    $_SESSION['twitch_expires_at'] = time() + $expiresIn;
    $_SESSION['last_validated_at'] = time();
    beta_ws_register();
    session_write_close();
}
function beta_tw_ok(int $expiresIn, bool $refreshed): never {
    beta_json(200, ['success' => true, 'expires_in' => $expiresIn, 'next_check' => beta_tw_next($expiresIn), 'refreshed' => $refreshed]);
}

$v = bots_twitch_validate($at);
if (!empty($v['ok'])) {
    $exp = (int) ($v['payload']['expires_in'] ?? 0);
    if ($exp >= BETA_TW_LOW) { beta_tw_store('', '', $exp); beta_tw_ok($exp, false); }
} elseif (($v['reason'] ?? '') !== 'invalid') {
    beta_json(200, ['success' => false, 'retry' => 60, 'message' => 'Twitch did not answer the token check.']);
}

// 401 or nearly expired: refresh, one at a time per Twitch user (other tabs,
// sessions and the old dashboard all share the same refresh token).
$db = beta_ws_db();
if (!$db) beta_json(200, ['success' => false, 'retry' => 60, 'message' => 'Could not reach the database.']);
$lock = 'beta-tw-refresh:' . $uid;
$st = $db->prepare('SELECT GET_LOCK(?, 15)');
$st->bind_param('s', $lock); $st->execute(); $st->bind_result($got); $st->fetch(); $st->close();
if ((int) $got !== 1) beta_json(200, ['success' => false, 'retry' => 30, 'message' => 'A token refresh is already running.']);
// beta_json exits, which skips finally blocks, so release on shutdown.
register_shutdown_function(function () use ($db, $lock) {
    $st = $db->prepare('SELECT RELEASE_LOCK(?)');
    if ($st) { $st->bind_param('s', $lock); $st->execute(); $st->close(); }
});
{
    // Someone may have refreshed while we waited: adopt the users row's token
    // if it differs from ours and is still good, instead of refreshing again.
    $st = $db->prepare('SELECT access_token, refresh_token FROM users WHERE twitch_user_id = ? LIMIT 1');
    $st->bind_param('s', $uid); $st->execute(); $st->bind_result($dbAt, $dbRt); $st->fetch(); $st->close();
    $dbAt = (string) $dbAt; $dbRt = (string) $dbRt;
    if ($dbAt !== '' && $dbAt !== $at) {
        $v2 = bots_twitch_validate($dbAt);
        $exp = (int) ($v2['payload']['expires_in'] ?? 0);
        if (!empty($v2['ok']) && $exp >= BETA_TW_LOW) { beta_tw_store($dbAt, $dbRt ?: $rt, $exp); beta_tw_ok($exp, true); }
    }
    $r = bots_twitch_refresh_token($rt !== '' ? $rt : $dbRt, $db);
    if (empty($r['ok']) && $dbRt !== '' && $dbRt !== $rt && ($r['reason'] ?? '') === 'invalid') $r = bots_twitch_refresh_token($dbRt, $db);
    if (empty($r['ok'])) {
        if (($r['reason'] ?? '') === 'invalid') {
            error_log('[beta token] refresh rejected for twitch_user_id=' . $uid . ' http=' . ($r['http'] ?? '?'));
            beta_json(200, ['success' => false, 'signin' => true, 'message' => 'Twitch sign-in needs renewing.']);
        }
        beta_json(200, ['success' => false, 'retry' => 60, 'message' => 'Twitch did not answer the token refresh.']);
    }
    $u = $db->prepare('UPDATE users SET access_token = ?, refresh_token = ? WHERE twitch_user_id = ?');
    if ($u) { $u->bind_param('sss', $r['access_token'], $r['refresh_token'], $uid); if (!$u->execute()) error_log('[beta token] users update failed: ' . $u->error); $u->close(); }
    beta_tw_store($r['access_token'], $r['refresh_token'], (int) $r['expires_in']);
    error_log('[beta token] refreshed access token for twitch_user_id=' . $uid);
    beta_tw_ok((int) $r['expires_in'], true);
}
