<?php
// Beta caption relay. The browser posts caption events here; the server sends
// them on an Engine.IO session it holds for that tab, with the channel key from
// the session. The tab keeps the session alive with op=poll (answers pings).
//   POST op=open  tab=<16 hex>
//   POST op=send  tab=<16 hex> events=[{t:'c',text,f}|{t:'x'}|{t:'a',text}] (max 20)
//   GET  op=poll  tab=<16 hex>    (long poll, up to ~28s)
//   POST op=close tab=<16 hex>
require_once dirname(__DIR__) . '/_live.php';
require_once __DIR__ . '/_relay.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$op = (string) ($_REQUEST['op'] ?? '');
$tab = (string) ($_REQUEST['tab'] ?? '');
if (!preg_match('/^[a-f0-9]{16}$/', $tab)) beta_json(400, ['success' => false, 'message' => 'Bad tab id.']);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if (($op === 'poll') === $isPost || !in_array($op, ['open', 'send', 'poll', 'close'], true)) beta_json(400, ['success' => false, 'message' => 'Unknown action.']);
beta_relay_guard('cc', $tab, $op);
$key = (string) ($_SESSION['api_key'] ?? '');
if ($key === '') beta_json(409, ['success' => false, 'message' => 'Reload the page to start captions.']);
$tabs = is_array($_SESSION['beta_cc'] ?? null) ? $_SESSION['beta_cc'] : [];
$sid = (string) ($tabs[$tab] ?? '');

if ($op === 'open') {
    if ($sid !== '') beta_cc_req(beta_cc_url($sid), '41');
    session_write_close();
    $new = beta_cc_open($key);
    beta_session_start();
    $tabs = is_array($_SESSION['beta_cc'] ?? null) ? $_SESSION['beta_cc'] : [];
    unset($tabs[$tab]);
    if ($new !== null) { $tabs[$tab] = $new; $tabs = array_slice($tabs, -4, null, true); }
    $_SESSION['beta_cc'] = $tabs;
    session_write_close();
    if ($new === null) beta_json(502, ['success' => false, 'message' => 'The caption service did not answer.']);
    beta_json(200, ['success' => true]);
}
session_write_close();
if ($sid === '') beta_json(410, ['success' => false, 'gone' => true]);

if ($op === 'close') {
    beta_cc_req(beta_cc_url($sid), '41', 4);
    beta_session_start(); unset($_SESSION['beta_cc'][$tab]); session_write_close();
    beta_json(200, ['success' => true]);
}
if ($op === 'poll') {
    $lock = beta_relay_poll_lock('cc', $tab);
    [$c, $got, $errno] = beta_cc_req(beta_cc_url($sid), null, BETA_RELAY_POLL_S);
    if ($errno === 28) beta_json(200, ['success' => true]); // nothing arrived in time; session still fine
    if ($c !== 200 || $got === null) beta_json(410, ['success' => false, 'gone' => true]);
    foreach (explode("\x1e", $got) as $p) {
        if ($p === '1' || strpos($p, '41') === 0) beta_json(410, ['success' => false, 'gone' => true]);
        if ($p === '2' && beta_cc_req(beta_cc_url($sid), '3')[0] !== 200) beta_json(410, ['success' => false, 'gone' => true]);
    }
    beta_json(200, ['success' => true]);
}
// send
$events = json_decode((string) ($_POST['events'] ?? ''), true);
if (!is_array($events) || !array_is_list($events) || !$events || count($events) > 20) beta_json(400, ['success' => false, 'message' => 'Bad events.']);
$pk = [];
foreach ($events as $e) {
    $t = is_array($e) ? ($e['t'] ?? '') : '';
    if ($t === 'x') { $pk[] = beta_cc_packet($key, 'CLOSED_CAPTION_CLEAR', []); continue; }
    $text = $e['text'] ?? null;
    if (!is_string($text) || mb_strlen($text) > 2000 || !mb_check_encoding($text, 'UTF-8')) beta_json(400, ['success' => false, 'message' => 'Bad caption text.']);
    if ($t === 'c') $pk[] = beta_cc_packet($key, 'CLOSED_CAPTION', ['text' => $text, 'isFinal' => !empty($e['f']), 'translated' => !empty($e['tr'])]);
    elseif ($t === 'a' && preg_match('/^\[[A-Z ]{1,30}\]$/', $text)) $pk[] = beta_cc_packet($key, 'CLOSED_CAPTION', ['text' => $text, 'isFinal' => true, 'action' => true]);
    else beta_json(400, ['success' => false, 'message' => 'Bad event.']);
}
[$c] = beta_cc_req(beta_cc_url($sid), implode("\x1e", $pk), 6);
if ($c !== 200) beta_json(410, ['success' => false, 'gone' => true]);
beta_json(200, ['success' => true]);
