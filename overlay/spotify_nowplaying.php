<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (($_SERVER['HTTP_X_SPECTER_OVERLAY'] ?? '') !== '1') {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$code = $_GET['code'] ?? '';
if ($code === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
    echo json_encode(['active' => false]);
    exit;
}

include '/var/www/config/database.php';
$conn = @new mysqli($db_servername, $db_username, $db_password, 'website');
if ($conn->connect_error) {
    echo json_encode(['active' => false]);
    exit;
}

// Resolve the user by api_key and read their Spotify token + link state.
$stmt = $conn->prepare(
    "SELECT s.access_token, s.has_access, s.own_client
       FROM users u
       LEFT JOIN spotify_tokens s ON s.user_id = u.id
      WHERE u.api_key = ? LIMIT 1"
);
if (!$stmt) {
    echo json_encode(['active' => false]);
    exit;
}
$stmt->bind_param('s', $code);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$accessToken = $row['access_token'] ?? '';
$linked = $row
    && $accessToken !== ''
    && (((int)($row['has_access'] ?? 0) === 1) || ((int)($row['own_client'] ?? 0) === 1));
if (!$linked) {
    echo json_encode(['active' => false]);
    exit;
}

// Read-only call to Spotify. A 401 just means the token is mid-refresh.
$ch = curl_init('https://api.spotify.com/v1/me/player/currently-playing');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
curl_setopt($ch, CURLOPT_TIMEOUT, 6);
$resp = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || $resp === '' || $resp === false) {
    echo json_encode(['active' => false]); // 204 / 401 / error
    exit;
}

$d = json_decode($resp, true);
$item = $d['item'] ?? null;
if (!$item) {
    echo json_encode(['active' => false]);
    exit;
}

$artists = '';
if (!empty($item['artists'])) {
    $artists = implode(', ', array_map(static function ($a) { return $a['name'] ?? ''; }, $item['artists']));
}

echo json_encode([
    'active'      => true,
    'is_playing'  => (bool)($d['is_playing'] ?? false),
    'title'       => $item['name'] ?? '',
    'artist'      => $artists,
    'album_art'   => $item['album']['images'][0]['url'] ?? '',
    'progress_ms' => (int)($d['progress_ms'] ?? 0),
    'duration_ms' => (int)($item['duration_ms'] ?? 0),
]);