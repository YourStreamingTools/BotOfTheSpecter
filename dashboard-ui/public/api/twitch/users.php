<?php
// Twitch user search for beta pickers (user commands, walk-ons).
// GET ?q=<text>       Helix Search Channels, enriched with Get Users, plus
//                     matching names from this channel's seen_users.
// GET ?login=<login>  exact Get Users lookup to confirm a login exists.
// Same bot app credentials as dashboard/media.php helix_lookup_user
// (config/twitch.php). Tokens never leave the server.
require_once dirname(__DIR__) . '/_live.php';

function tw_out(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body);
    exit();
}

// Per-session rate limit: 40 requests per rolling minute.
$now = time();
$hits = array_values(array_filter((array) ($_SESSION['beta_tw_search'] ?? []), fn($t) => $t > $now - 60));
if (count($hits) >= 40) {
    session_write_close();
    header('Retry-After: 30');
    tw_out(429, ['success' => false, 'error' => 'Too many searches. Wait a moment and try again.']);
}
$hits[] = $now;
$_SESSION['beta_tw_search'] = $hits;

$q = strtolower(preg_replace('/[^a-z0-9_]/i', '', trim((string) ($_GET['q'] ?? ''))));
$login = strtolower(preg_replace('/[^a-z0-9_]/i', '', trim((string) ($_GET['login'] ?? ''))));
if ($login === '' && strlen($q) < 2) {
    session_write_close();
    tw_out(400, ['success' => false, 'error' => 'Type at least 2 letters.']);
}
$q = substr($q, 0, 25);
$login = substr($login, 0, 25);

require_once '/var/www/config/db_connect.php';
include '/var/www/dashboard/includes/userdata.php';
include '/var/www/dashboard/includes/mod_access.php';
include '/var/www/dashboard/includes/user_db_connect.php';
include_once '/var/www/config/twitch.php';
session_write_close();

$cid = trim((string) ($clientID ?? ''));
$tok = trim((string) ($oauth ?? ''));
if ($cid === '' || $tok === '') tw_out(503, ['success' => false, 'error' => 'Twitch lookup is not available right now.']);

function tw_get(string $path, string $cid, string $tok): ?array {
    $key = 'beta_tw_' . md5($path);
    if (function_exists('apcu_fetch')) { $hit = apcu_fetch($key, $ok); if ($ok) return $hit; }
    $ch = curl_init('https://api.twitch.tv/helix/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Client-Id: ' . $cid]]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code !== 200) { error_log("beta twitch users: helix $code"); return null; }
    $data = json_decode((string) $raw, true);
    $rows = is_array($data['data'] ?? null) ? $data['data'] : [];
    if (function_exists('apcu_store')) apcu_store($key, $rows, 120);
    return $rows;
}
function tw_user(array $u): array {
    return [
        'id' => (string) ($u['id'] ?? ''), 'login' => strtolower((string) ($u['login'] ?? '')),
        'display_name' => (string) ($u['display_name'] ?? ''), 'avatar' => (string) ($u['profile_image_url'] ?? ''),
        'type' => in_array($u['broadcaster_type'] ?? '', ['partner', 'affiliate'], true) ? $u['broadcaster_type'] : '',
    ];
}

if ($login !== '') {
    $rows = tw_get('users?login=' . urlencode($login), $cid, $tok);
    if ($rows === null) tw_out(502, ['success' => false, 'error' => 'Twitch could not be reached.']);
    tw_out(200, ['success' => true, 'found' => !empty($rows[0]['id']), 'user' => !empty($rows[0]['id']) ? tw_user($rows[0]) : null]);
}

$chan = tw_get('search/channels?first=8&query=' . urlencode($q), $cid, $tok);
if ($chan === null) tw_out(502, ['success' => false, 'error' => 'Twitch could not be reached.']);
$logins = [];
foreach ($chan as $c) { $l = strtolower((string) ($c['broadcaster_login'] ?? '')); if ($l !== '') $logins[$l] = (bool) ($c['is_live'] ?? false); }
// An exact login always comes first, even when search ranks it lower.
$logins = [$q => $logins[$q] ?? false] + $logins;
$users = tw_get('users?' . implode('&', array_map(fn($l) => 'login=' . urlencode($l), array_slice(array_keys($logins), 0, 9))), $cid, $tok) ?? [];
$out = [];
foreach ($users as $u) { $x = tw_user($u); $x['live'] = $logins[$x['login']] ?? false; $out[$x['login']] = $x; }
$ordered = [];
foreach (array_keys($logins) as $l) if (isset($out[$l])) $ordered[] = $out[$l];

$seen = [];
if (isset($db) && $db instanceof mysqli) {
    $like = $q . '%';
    if ($st = $db->prepare('SELECT DISTINCT username FROM seen_users WHERE username LIKE ? ORDER BY username ASC LIMIT 6')) {
        $st->bind_param('s', $like);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) $seen[] = strtolower((string) $r['username']);
        $st->close();
    }
}
tw_out(200, ['success' => true, 'users' => $ordered, 'seen' => $seen]);
