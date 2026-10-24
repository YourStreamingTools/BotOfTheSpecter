<?php
// Beta caption relay helpers (copied to public/api/captions/_relay.php by
// scripts/sync-live-api.sh). The live page holds a socket.io connection in the
// browser with the channel key; here the server holds it instead, as an
// Engine.IO v4 polling session, so the key never reaches the browser.
const BETA_CC_WS = 'https://websocket.botofthespecter.com/socket.io/?EIO=4&transport=polling';

function beta_cc_req(string $url, ?string $body = null, int $timeout = 8): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain;charset=UTF-8']); }
    $out = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $errno = curl_errno($ch); curl_close($ch);
    return [$code, $out === false ? null : (string) $out, $errno];
}
function beta_cc_url(string $sid): string { return BETA_CC_WS . '&sid=' . rawurlencode($sid) . '&t=' . bin2hex(random_bytes(4)); }
// Wait for a packet: '40' (connected) or an event name. Answers pings on the way.
function beta_cc_wait(string $sid, string $want): bool {
    for ($i = 0; $i < 4; $i++) {
        [$c, $got] = beta_cc_req(beta_cc_url($sid));
        if ($c !== 200 || $got === null) return false;
        foreach (explode("\x1e", $got) as $p) {
            if ($p === '2') beta_cc_req(beta_cc_url($sid), '3');
            if ($want === '' ? strpos($p, '40') === 0 : strpos($p, '42["' . $want . '"') === 0) return true;
        }
    }
    return false;
}
// Same connect and REGISTER as the live page's socket.
function beta_cc_open(string $key, string $name = 'Closed Captions Dashboard'): ?string {
    [$c, $open] = beta_cc_req(BETA_CC_WS . '&t=' . bin2hex(random_bytes(4)));
    if ($c !== 200 || $open === null || ($open[0] ?? '') !== '0') return null;
    $sid = (string) (json_decode(substr($open, 1), true)['sid'] ?? '');
    if ($sid === '') return null;
    $reg = '42' . json_encode(['REGISTER', ['code' => $key, 'channel' => 'Dashboard', 'name' => $name]]);
    if (beta_cc_req(beta_cc_url($sid), '40')[0] === 200 && beta_cc_wait($sid, '')
        && beta_cc_req(beta_cc_url($sid), $reg)[0] === 200 && beta_cc_wait($sid, 'SUCCESS')) return $sid;
    beta_cc_req(beta_cc_url($sid), '41');
    return null;
}
function beta_cc_packet(string $key, string $event, array $data): string {
    return '42' . json_encode([$event, ['code' => $key] + $data], JSON_UNESCAPED_UNICODE);
}
// One short session for a single event (used after a settings save).
function beta_cc_once(string $key, string $event, array $data, string $name = 'Closed Captions Dashboard'): bool {
    if ($key === '') return false;
    $sid = beta_cc_open($key, $name);
    if ($sid === null) return false;
    $ok = beta_cc_req(beta_cc_url($sid), beta_cc_packet($key, $event, $data))[0] === 200;
    beta_cc_req(beta_cc_url($sid), '41');
    return $ok;
}

// Server-side flood guard, whatever client code is running:
// - a tab can hold at most one poll at a time (flock per tab); a second poll
//   gets 410 at once, and the client's reopen is rate limited, so stacked
//   loops in old clients end (they stop when a reopen fails);
// - op=open is limited per session (BETA_RELAY_OPENS per minute), else 429;
// - blocked tabs get instant failures.
const BETA_RELAY_OPENS = 4;
const BETA_RELAY_POLL_S = 27; // under the server's 25 s ping interval plus margin
const BETA_RELAY_BLOCKED = ['d1fd157bf3e5a452'];
function beta_relay_dir(): string {
    $d = sys_get_temp_dir() . '/beta-relay';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}
function beta_relay_guard(string $ns, string $tab, string $op): void {
    if (in_array($tab, BETA_RELAY_BLOCKED, true)) {
        if ($op === 'close') beta_json(200, ['success' => true]);
        beta_json($op === 'open' ? 429 : 410, ['success' => false, 'gone' => true, 'message' => 'Reload the page.']);
    }
    if ($op !== 'open') return;
    $f = @fopen(beta_relay_dir() . '/open-' . sha1($ns . '|' . session_id()), 'c+');
    if (!$f) return;
    flock($f, LOCK_EX);
    $now = time();
    $ts = array_values(array_filter((array) json_decode((string) stream_get_contents($f), true), fn($t) => is_int($t) && $t > $now - 60));
    $over = count($ts) >= BETA_RELAY_OPENS;
    if (!$over) { $ts[] = $now; ftruncate($f, 0); rewind($f); fwrite($f, json_encode($ts)); }
    flock($f, LOCK_UN); fclose($f);
    if ($over) beta_json(429, ['success' => false, 'message' => 'Too many reconnects. Wait a minute, then reload the page.']);
}
// Returns the held lock; a second concurrent poll for the same tab gets 410 now.
function beta_relay_poll_lock(string $ns, string $tab) {
    $f = @fopen(beta_relay_dir() . '/poll-' . $ns . '-' . $tab, 'c');
    if ($f && !flock($f, LOCK_EX | LOCK_NB)) { fclose($f); beta_json(410, ['success' => false, 'gone' => true]); }
    return $f;
}
