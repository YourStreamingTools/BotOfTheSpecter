<?php
require_once '/var/www/lib/session_bootstrap.php';
header('Content-Type: application/json');
require_once '/var/www/lib/require_auth_ajax.php';        // 401 + redirect JSON if no session
require_once '/var/www/config/db_connect.php';            // $conn -> website DB

// Block control while acting as another user (parity with spotifylink.php).
if (!empty($_SESSION['admin_act_as_active'])) {
    echo json_encode(['success' => false, 'error' => 'act_as']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['success' => false, 'error' => 'no_user']);
    exit;
}

// Read the (system-refreshed) access token + connection gate.
$stmt = $conn->prepare("SELECT access_token, has_access, own_client FROM spotify_tokens WHERE user_id = ? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$accessToken = $row['access_token'] ?? '';
$connected = $row
    && $accessToken !== ''
    && (((int)($row['has_access'] ?? 0) === 1) || ((int)($row['own_client'] ?? 0) === 1));
if (!$connected) {
    echo json_encode(['success' => false, 'error' => 'not_connected']);
    exit;
}

// Resolve the requested action.
$action = $_POST['action'] ?? $_GET['action'] ?? 'state';
$base = 'https://api.spotify.com/v1/me/player';
$method = 'GET';
$url = $base;
switch ($action) {
    case 'state':    $method = 'GET';  $url = $base;             break;
    case 'play':     $method = 'PUT';  $url = $base . '/play';   break;
    case 'pause':    $method = 'PUT';  $url = $base . '/pause';  break;
    case 'next':     $method = 'POST'; $url = $base . '/next';   break;
    case 'previous': $method = 'POST'; $url = $base . '/previous'; break;
    case 'volume':
        $vol = (int)($_POST['value'] ?? $_GET['value'] ?? -1);
        if ($vol < 0 || $vol > 100) { echo json_encode(['success' => false, 'error' => 'bad_volume']); exit; }
        $method = 'PUT';
        $url = $base . '/volume?volume_percent=' . $vol;
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'bad_action']);
        exit;
}

// Call Spotify.
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken,
    'Content-Type: application/json',
    'Content-Length: 0',
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 8);
$resp = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($action === 'state') {
    if ($httpCode === 204 || $resp === '' || $resp === false) {
        // 204 = no active device / nothing playing.
        echo json_encode(['success' => true, 'active' => false]);
        exit;
    }
    if ($httpCode === 200) {
        $d = json_decode($resp, true);
        $item = $d['item'] ?? null;
        $artists = '';
        if ($item && !empty($item['artists'])) {
            $artists = implode(', ', array_map(static function ($a) { return $a['name'] ?? ''; }, $item['artists']));
        }
        echo json_encode([
            'success'       => true,
            'active'        => true,
            'is_playing'    => (bool)($d['is_playing'] ?? false),
            'progress_ms'   => (int)($d['progress_ms'] ?? 0),
            'shuffle_state' => (bool)($d['shuffle_state'] ?? false),
            'repeat_state'  => $d['repeat_state'] ?? 'off',
            'device'        => [
                'name'            => $d['device']['name'] ?? '',
                'type'            => $d['device']['type'] ?? '',
                'supports_volume' => (bool)($d['device']['supports_volume'] ?? false),
                'volume_percent'  => (int)($d['device']['volume_percent'] ?? 0),
            ],
            'disallows'     => $d['actions']['disallows'] ?? new stdClass(),
            'track'         => $item ? [
                'name'        => $item['name'] ?? '',
                'artists'     => $artists,
                'duration_ms' => (int)($item['duration_ms'] ?? 0),
                'album_art'   => $item['album']['images'][0]['url'] ?? '',
                'url'         => $item['external_urls']['spotify'] ?? '',
            ] : null,
        ]);
        exit;
    }
    // any other code falls through to the error map
}

if (in_array($httpCode, [200, 202, 204], true)) {
    echo json_encode(['success' => true]);
    exit;
}

$codeMap = [401 => 'expired', 403 => 'premium', 404 => 'no_device', 429 => 'rate_limited'];
echo json_encode([
    'success' => false,
    'error'   => $codeMap[$httpCode] ?? 'generic',
    'http'    => $httpCode,
]);

