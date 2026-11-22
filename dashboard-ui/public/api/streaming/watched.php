<?php
// Hand-written (not generated). "Record other channels" card on the beta Streaming
// page: the Twitch channels this user records into their own stream storage.
// Tables: website.watched_record_channels / watched_record_limits
// (migrations/website/20261122_0001). stream/twitch-recorder.py does the recording.
require_once dirname(__DIR__) . '/_live.php';
require_once '/var/www/config/db_connect.php';
include '/var/www/config/twitch.php';
include '/var/www/dashboard/includes/userdata.php';

const BETA_WATCHED_DEFAULT_LIMIT = 3;

function beta_watched_out(int $code, array $body): never {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($code); header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE); exit();
}

$isActAsUser = isset($isActAs) && $isActAs === true;
$userId = (int) ($user_id ?? ($_SESSION['user_id'] ?? 0));
$login = strtolower(isset($username) && $username !== '' ? (string) $username : (string) ($_SESSION['username'] ?? ''));
$userToken = (string) ($_SESSION['access_token'] ?? '');
session_write_close();
if ($userId <= 0 || $login === '') beta_watched_out(401, ['success' => false, 'error' => 'Not signed in.']);

function beta_watched_limit(mysqli $conn, int $userId): int {
    $st = $conn->prepare('SELECT max_channels FROM watched_record_limits WHERE user_id = ? LIMIT 1');
    $st->bind_param('i', $userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ? (int) $row['max_channels'] : BETA_WATCHED_DEFAULT_LIMIT;
}

function beta_watched_list(mysqli $conn, int $userId): array {
    $st = $conn->prepare('SELECT channel_login, display_name, enabled FROM watched_record_channels WHERE user_id = ? ORDER BY id');
    $st->bind_param('i', $userId);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[] = ['login' => (string) $r['channel_login'], 'display_name' => (string) ($r['display_name'] ?: $r['channel_login']), 'enabled' => (int) $r['enabled'] === 1];
    }
    $st->close();
    return $out;
}

function beta_watched_twitch_user(string $name, string $clientId, string $token): ?array {
    if ($clientId === '' || $token === '') return null;
    $ch = curl_init('https://api.twitch.tv/helix/users?login=' . rawurlencode($name));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Client-ID: ' . $clientId, 'Authorization: Bearer ' . $token],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 401) beta_twitch_401();
    if ($code !== 200 || !is_string($body)) return null;
    $data = json_decode($body, true);
    $u = $data['data'][0] ?? null;
    return is_array($u) ? ['id' => (string) ($u['id'] ?? ''), 'login' => strtolower((string) ($u['login'] ?? '')), 'display_name' => (string) ($u['display_name'] ?? '')] : ['missing' => true];
}

function beta_watched_page(mysqli $conn, int $userId, bool $actAs, string $message = ''): never {
    $list = beta_watched_list($conn, $userId);
    $limit = beta_watched_limit($conn, $userId);
    beta_watched_out(200, ['success' => true, 'act_as' => $actAs, 'channels' => $list, 'limit' => $limit, 'message' => $message]);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (array_diff(array_keys($_GET), ['_ts'])) beta_watched_out(400, ['success' => false, 'error' => 'Unknown action.']);
        beta_watched_page($conn, $userId, $isActAsUser);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $_GET) beta_watched_out(405, ['success' => false, 'error' => 'Unknown action.']);
    if ($isActAsUser) beta_watched_out(403, ['success' => false, 'error' => 'Changes are disabled while acting as another user.']);
    $action = (string) ($_POST['action'] ?? '');
    $channel = strtolower(trim((string) ($_POST['channel'] ?? '')));
    if (!is_string($_POST['channel'] ?? '') || !preg_match('/^[a-z0-9_]{1,25}$/', $channel)) beta_watched_out(400, ['success' => false, 'error' => 'Enter a Twitch channel name (letters, numbers and _ only).']);

    if ($action === 'add') {
        if ($channel === $login) beta_watched_out(400, ['success' => false, 'error' => 'Your own channel is recorded with "Enable channel recording" above.']);
        $limit = beta_watched_limit($conn, $userId);
        $list = beta_watched_list($conn, $userId);
        foreach ($list as $c) if ($c['login'] === $channel) beta_watched_out(400, ['success' => false, 'error' => 'That channel is already on your list.']);
        if (count($list) >= $limit) beta_watched_out(400, ['success' => false, 'error' => $limit === 0 ? 'Recording other channels is not available on your account.' : "You can record up to $limit channel" . ($limit === 1 ? '' : 's') . '. Remove one to add another.']);
        $tw = beta_watched_twitch_user($channel, (string) ($clientID ?? ''), $userToken);
        if ($tw === null) beta_watched_out(502, ['success' => false, 'error' => 'Twitch could not be reached to check that channel. Please try again.']);
        if (!empty($tw['missing']) || $tw['login'] === '') beta_watched_out(404, ['success' => false, 'error' => 'No Twitch channel has that name.']);
        if ($tw['login'] === $login) beta_watched_out(400, ['success' => false, 'error' => 'Your own channel is recorded with "Enable channel recording" above.']);
        $st = $conn->prepare('INSERT INTO watched_record_channels (user_id, channel_login, channel_id, display_name, enabled) VALUES (?, ?, ?, ?, 1)');
        $st->bind_param('isss', $userId, $tw['login'], $tw['id'], $tw['display_name']);
        $st->execute();
        $st->close();
        beta_watched_page($conn, $userId, false, ($tw['display_name'] ?: $tw['login']) . ' will be recorded the next time they go live.');
    }
    if ($action === 'toggle') {
        $on = ($_POST['enabled'] ?? '') === '1' ? 1 : 0;
        $st = $conn->prepare('UPDATE watched_record_channels SET enabled = ? WHERE user_id = ? AND channel_login = ?');
        $st->bind_param('iis', $on, $userId, $channel);
        $st->execute();
        $st->close();
        beta_watched_page($conn, $userId, false, $on ? 'Recording switched on.' : 'Recording switched off.');
    }
    if ($action === 'remove') {
        $st = $conn->prepare('DELETE FROM watched_record_channels WHERE user_id = ? AND channel_login = ?');
        $st->bind_param('is', $userId, $channel);
        $st->execute();
        $st->close();
        beta_watched_page($conn, $userId, false, 'Channel removed. Recordings you already have are kept.');
    }
    beta_watched_out(400, ['success' => false, 'error' => 'Unknown action.']);
} catch (mysqli_sql_exception $e) {
    beta_watched_out(503, ['success' => false, 'not_ready' => true, 'error' => 'Recording other channels is not set up yet (the website migration has not been run).']);
}
