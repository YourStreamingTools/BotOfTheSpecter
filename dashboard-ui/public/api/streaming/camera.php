<?php
// Hand-written (not generated). Phone Camera card for the beta Streaming page:
// relay URLs plus the camera viewer password, same logic as
// dashboard/includes/stream_hub_data.php.
require_once dirname(__DIR__) . '/_live.php';
require_once '/var/www/config/db_connect.php';
require_once '/var/www/config/stream.php';
include '/var/www/dashboard/includes/userdata.php';

function beta_cam_out(int $code, array $body): never {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($code); header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode($body); exit();
}

$isActAsUser = isset($isActAs) && $isActAs === true;
$userId = (int) ($user_id ?? ($_SESSION['user_id'] ?? 0));
$login = strtolower(isset($username) && $username !== '' ? (string) $username : (string) ($_SESSION['username'] ?? ''));
session_write_close();
if ($userId <= 0 || $login === '') beta_cam_out(401, ['success' => false, 'error' => 'Not signed in.']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isActAsUser) beta_cam_out(403, ['success' => false, 'error' => 'Changes are disabled while acting as another user.']);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save') {
        $pw = $_POST['camera_password'] ?? '';
        // Goes inside SRT stream IDs and URLs, so keep it to URL-safe characters.
        if (!is_string($pw) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $pw)) beta_cam_out(400, ['success' => false, 'error' => 'Use 8 to 64 letters, numbers, - or _.']);
        $hash = password_hash($pw, PASSWORD_BCRYPT);
    } elseif ($action === 'clear') {
        $hash = null;
    } else beta_cam_out(400, ['success' => false, 'error' => 'Unknown action.']);
    $ok = false;
    try {
        $st = $conn->prepare('UPDATE users SET camera_password_hash = ? WHERE id = ?');
        if ($st) { $st->bind_param('si', $hash, $userId); $ok = $st->execute(); $st->close(); }
    } catch (mysqli_sql_exception $e) { $ok = false; }
    if (!$ok) beta_cam_out(500, ['success' => false, 'error' => 'The camera password could not be saved.']);
    beta_cam_out(200, ['success' => true, 'password_set' => $hash !== null, 'message' => $hash === null ? 'Camera password removed.' : 'Camera password saved.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') beta_cam_out(405, ['success' => false, 'error' => 'Unknown action.']);

$rtmpsUrl = rtrim((string) ($stream_rtmps_url ?? 'rtmps://syd1.stream.botofthespecter.com:1935/app'), '/');
$host = (string) (parse_url($rtmpsUrl, PHP_URL_HOST) ?: 'syd1.stream.botofthespecter.com');
$set = false;
try {
    $st = $conn->prepare('SELECT camera_password_hash FROM users WHERE id = ? LIMIT 1');
    if ($st) { $st->bind_param('i', $userId); $st->execute(); $row = $st->get_result()->fetch_assoc(); $set = !empty($row['camera_password_hash']); $st->close(); }
} catch (mysqli_sql_exception $e) { /* column not migrated yet */ }
beta_cam_out(200, [
    'success' => true,
    'act_as' => $isActAsUser,
    'login' => $login,
    'ingest_url' => "rtmps://{$host}:1935/camera",
    'srt_url' => "srt://{$host}:8890?streamid=read:camera/{$login}:{$login}:PASSWORD",
    'web_url' => "https://{$host}:8889/camera/{$login}/",
    'password_set' => $set,
]);
