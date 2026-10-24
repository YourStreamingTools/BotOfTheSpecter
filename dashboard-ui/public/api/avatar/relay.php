<?php
// Beta avatar relay. The page posts mouth states here; the server sends them
// on an Engine.IO session it holds for that tab, with the channel key from the
// session (same helpers as the caption relay). op=poll keeps the session alive
// and reports when the overlay asks for the current state.
//   POST op=open  tab=<16 hex>
//   POST op=state tab=<16 hex> state=idle|talking
//   GET  op=poll  tab=<16 hex>    (long poll, up to ~28s)
//   POST op=close tab=<16 hex>
require_once dirname(__DIR__) . '/_live.php';
require_once dirname(__DIR__) . '/captions/_relay.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$op = (string) ($_REQUEST['op'] ?? '');
$tab = (string) ($_REQUEST['tab'] ?? '');
if (!preg_match('/^[a-f0-9]{16}$/', $tab)) beta_json(400, ['success' => false, 'message' => 'Bad tab id.']);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if (($op === 'poll') === $isPost || !in_array($op, ['open', 'state', 'poll', 'close'], true)) beta_json(400, ['success' => false, 'message' => 'Unknown action.']);
beta_relay_guard('av', $tab, $op);
$key = (string) ($_SESSION['api_key'] ?? '');
if ($key === '') beta_json(409, ['success' => false, 'message' => 'Reload the page to start the avatar.']);
$tabs = is_array($_SESSION['beta_av'] ?? null) ? $_SESSION['beta_av'] : [];
$sid = (string) ($tabs[$tab] ?? '');

if ($op === 'open') {
    if ($sid !== '') beta_cc_req(beta_cc_url($sid), '41');
    session_write_close();
    $new = beta_cc_open($key, 'Avatar Dashboard');
    beta_session_start();
    $tabs = is_array($_SESSION['beta_av'] ?? null) ? $_SESSION['beta_av'] : [];
    unset($tabs[$tab]);
    if ($new !== null) { $tabs[$tab] = $new; $tabs = array_slice($tabs, -4, null, true); }
    $_SESSION['beta_av'] = $tabs;
    session_write_close();
    if ($new === null) beta_json(502, ['success' => false, 'message' => 'The overlay service did not answer.']);
    beta_json(200, ['success' => true]);
}
session_write_close();
if ($sid === '') beta_json(410, ['success' => false, 'gone' => true]);

if ($op === 'close') {
    beta_cc_req(beta_cc_url($sid), '41', 4);
    beta_session_start(); unset($_SESSION['beta_av'][$tab]); session_write_close();
    beta_json(200, ['success' => true]);
}
if ($op === 'poll') {
    $lock = beta_relay_poll_lock('av', $tab);
    [$c, $got, $errno] = beta_cc_req(beta_cc_url($sid), null, BETA_RELAY_POLL_S);
    if ($errno === 28) beta_json(200, ['success' => true]); // nothing arrived in time; session still fine
    if ($c !== 200 || $got === null) beta_json(410, ['success' => false, 'gone' => true]);
    $req = false;
    foreach (explode("\x1e", $got) as $p) {
        if ($p === '1' || strpos($p, '41') === 0) beta_json(410, ['success' => false, 'gone' => true]);
        if ($p === '2' && beta_cc_req(beta_cc_url($sid), '3')[0] !== 200) beta_json(410, ['success' => false, 'gone' => true]);
        if (strpos($p, '42["AVATAR_STATE_REQUEST"') === 0) $req = true;
    }
    beta_json(200, ['success' => true, 'request' => $req]);
}
// state
$state = (string) ($_POST['state'] ?? '');
if (!in_array($state, ['idle', 'talking'], true)) beta_json(400, ['success' => false, 'message' => 'Bad state.']);
[$c] = beta_cc_req(beta_cc_url($sid), beta_cc_packet($key, 'AVATAR_STATE', ['state' => $state, 'expression' => 'default']), 6);
if ($c !== 200) beta_json(410, ['success' => false, 'gone' => true]);
beta_json(200, ['success' => true]);
