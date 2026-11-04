<?php
// Hidden key sign-in (/login/key): an admin API key with Dashboard access and
// its password, set in the beta admin Key Manager, signs in as the key's demo
// account (users.is_demo = 1). Nothing links here; it is for app review and
// demos. The demo account has no Twitch account, so pages that call Twitch
// have nothing to show.
require __DIR__ . '/_session.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') beta_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$key = trim((string) ($in['api_key'] ?? ''));
$password = (string) ($in['password'] ?? '');
// Each failure says which check failed (keys are 32 random hex characters, so
// this gives guessing nothing), after a pause, and is logged without secrets.
$fail = function (string $why, string $service = ''): never {
    error_log('beta key_login failed: ' . $why . ($service !== '' ? ' (service ' . $service . ')' : '') . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    usleep(750000);
    beta_json(401, ['ok' => false, 'error' => $why]);
};
if ($key === '' || $password === '' || strlen($key) > 128) $fail('missing');

require_once '/var/www/config/db_connect.php';
mysqli_report(MYSQLI_REPORT_OFF);
$st = $conn->prepare('SELECT service, dashboard_access, channel, password FROM admin_api_keys WHERE api_key = ? LIMIT 1');
if (!$st) { error_log('beta key_login db: ' . $conn->error); $fail('db'); }
$st->bind_param('s', $key);
$st->execute();
$k = $st->get_result()->fetch_assoc();
$st->close();
if (!$k) $fail('key');
if ((int) $k['dashboard_access'] !== 1) $fail('no_dashboard_access', (string) $k['service']);
if (empty($k['channel'])) $fail('no_demo_account', (string) $k['service']);
if (empty($k['password'])) $fail('no_password', (string) $k['service']);
if (!password_verify($password, $k['password'])) $fail('password', (string) $k['service']);

$st = $conn->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
if (!$st) { error_log('beta key_login db: ' . $conn->error); $fail('db'); }
$st->bind_param('s', $k['channel']);
$st->execute();
$u = $st->get_result()->fetch_assoc();
$st->close();
if (!$u) $fail('no_demo_account', (string) $k['service']);
if ((int) ($u['is_demo'] ?? 0) !== 1) $fail('not_demo', (string) $k['service']);

beta_session_start();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$oldSid = session_id();
session_regenerate_id(true);
unset($_SESSION['ws_row']);
beta_act_as_clear();
// No Twitch account: placeholders keep the session checks (me.php, Active
// Sessions) working, and Twitch calls fail as signed out.
$_SESSION['access_token'] = 'demo';
$_SESSION['refresh_token'] = '';
$_SESSION['twitchUserId'] = (string) ($u['twitch_user_id'] ?: 'demo:' . $u['id']);
$_SESSION['user_id'] = $u['id'];
$_SESSION['username'] = $u['username'];
$_SESSION['display_name'] = $u['twitch_display_name'] ?: $u['username'];
$_SESSION['profile_image'] = $u['profile_image'];
$_SESSION['api_key'] = $u['api_key'];
$_SESSION['is_admin'] = false;
$_SESSION['is_demo'] = true;
$_SESSION['demo_key_service'] = $k['service'];
$_SESSION['twitch_expires_at'] = time() + 4 * 60 * 60;
$_SESSION['last_validated_at'] = time();
$st = $conn->prepare('UPDATE users SET last_login = ? WHERE id = ?');
if ($st) {
    $now = date('Y-m-d H:i:s');
    $id = (int) $u['id'];
    $st->bind_param('si', $now, $id);
    $st->execute();
    $st->close();
}
if ($oldSid !== '' && $oldSid !== session_id()) beta_ws_delete($oldSid);
beta_ws_register();
session_write_close();

// Answer first, then make sure the demo channel's own database exists, as the
// old dashboard's layout does for every signed-in channel.
http_response_code(200);
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['ok' => true]);
if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
include '/var/www/dashboard/includes/usr_database.php';
