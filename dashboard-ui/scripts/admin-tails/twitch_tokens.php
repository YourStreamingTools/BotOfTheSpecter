<?php
// beta additions. Validation runs here, server side, so the browser never holds a stored token.
// These only call Twitch's read-only /oauth2/validate; nothing is renewed or saved.
function badm_tt_validate($token) {
    $token = trim((string) $token);
    if ($token === '') { return ['success' => true, 'state' => 'none']; }
    $ch = curl_init('https://id.twitch.tv/oauth2/validate');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: OAuth ' . $token], CURLOPT_TIMEOUT => 10]);
    $resp = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($err) { return ['success' => false, 'error' => $err]; }
    if ($code !== 200) { return ['success' => true, 'state' => 'invalid', 'http' => $code]; }
    $v = json_decode((string) $resp, true) ?: [];
    $exp = (int) ($v['expires_in'] ?? 0);
    return ['success' => true, 'state' => ($exp === 0 && isset($v['expires_in'])) ? 'valid_no_expiry' : 'valid', 'expires_in' => $exp,
        'login' => $v['login'] ?? null, 'user_id' => $v['user_id'] ?? null, 'client_id' => $v['client_id'] ?? null, 'scopes' => $v['scopes'] ?? []];
}
function badm_tt_one($conn, $sql, $param) {
    $st = $conn->prepare($sql); if (!$st) { return null; }
    $st->bind_param('s', $param); $st->execute(); $r = $st->get_result(); $row = $r ? $r->fetch_assoc() : null; $st->close();
    return $row;
}
ob_end_clean();
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['badm_validate_user'])) {
    $row = badm_tt_one($conn, 'SELECT twitch_access_token AS t FROM twitch_bot_access WHERE twitch_user_id = ? LIMIT 1', trim((string) $_POST['twitch_user_id']));
    echo json_encode($row ? badm_tt_validate($row['t']) : ['success' => false, 'error' => t('admin_twitch_tokens_err_user_token_not_found')]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['badm_validate_custom'])) {
    $id = trim((string) ($_POST['bot_channel_id'] ?? '')); $name = trim((string) ($_POST['bot_username'] ?? ''));
    $table = ($_POST['module'] ?? '') === '1' ? 'custom_module_bots' : 'custom_bots';
    $row = $id !== '' ? badm_tt_one($conn, "SELECT access_token AS t FROM $table WHERE bot_channel_id = ? LIMIT 1", $id) : null;
    if (!$row && $name !== '') { $row = badm_tt_one($conn, "SELECT access_token AS t FROM $table WHERE bot_username = ? LIMIT 1", $name); }
    echo json_encode($row ? badm_tt_validate($row['t']) : ['success' => false, 'error' => t('admin_twitch_tokens_err_custom_bot_not_found')]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['badm_validate_chat'])) {
    $s = fetchWebsiteTwitchSettings($conn);
    echo json_encode(badm_tt_validate($s['chat_token'] ?? ''));
    exit;
}
// GET: what the old page rendered, without any token values.
$users = [];
$res = $conn->query("SELECT u.twitch_user_id, u.username FROM users u LEFT JOIN twitch_bot_access tba ON u.twitch_user_id = tba.twitch_user_id WHERE tba.twitch_access_token IS NOT NULL AND tba.twitch_access_token != '' ORDER BY u.username ASC");
while ($res && ($r = $res->fetch_assoc())) { $users[] = ['twitch_user_id' => (string) $r['twitch_user_id'], 'username' => (string) $r['username']]; }
$custom = []; $ids = []; $names = [];
$res = $conn->query('SELECT bot_username, bot_channel_id, access_token, token_expires FROM custom_bots');
while ($res && ($r = $res->fetch_assoc())) {
    $custom[] = ['bot_username' => (string) $r['bot_username'], 'bot_channel_id' => (string) $r['bot_channel_id'], 'module' => false, 'verified' => null, 'has_token' => trim((string) $r['access_token']) !== '', 'expires' => $r['token_expires'] ?? '-'];
    if ($r['bot_channel_id'] !== '' && $r['bot_channel_id'] !== null) { $ids[] = (string) $r['bot_channel_id']; }
    if ($r['bot_username']) { $names[] = strtolower($r['bot_username']); }
}
$res = $conn->query('SELECT bot_username, bot_channel_id, is_verified, access_token, token_expires FROM custom_module_bots ORDER BY bot_username ASC');
while ($res && ($r = $res->fetch_assoc())) {
    if (($r['bot_channel_id'] && in_array((string) $r['bot_channel_id'], $ids, true)) || ($r['bot_username'] && in_array(strtolower($r['bot_username']), $names, true))) { continue; }
    $custom[] = ['bot_username' => (string) $r['bot_username'], 'bot_channel_id' => (string) $r['bot_channel_id'], 'module' => true, 'verified' => (int) $r['is_verified'] === 1, 'has_token' => trim((string) $r['access_token']) !== '', 'expires' => $r['token_expires'] ?? '-'];
}
echo json_encode(['success' => true, 'client_id' => (string) ($clientID ?? ''), 'has_secret' => !empty($clientSecret), 'redirect_uri' => $redirectURI ?? null, 'beta_redirect_uri' => $betaRedirectURI ?? null,
    'users' => $users, 'custom' => $custom, 'L' => badm_lang('admin_twitch_tokens_')], JSON_UNESCAPED_UNICODE);
exit;
