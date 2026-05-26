<?php
include '/var/www/config/database.php';

$api_key      = $_GET['code']    ?? '';
$counter_name = $_GET['counter'] ?? '';
$type         = strtolower($_GET['type'] ?? 'text');

if (!in_array($type, ['json', 'text', 'number', 'name'], true)) {
    $type = 'text';
}

if ($type === 'json') {
    header('Content-Type: application/json; charset=utf-8');
} else {
    header('Content-Type: text/plain; charset=utf-8');
}
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

function respond_error($type, $message) {
    if ($type === 'json') {
        echo json_encode(['error' => $message]);
    } else {
        echo $message;
    }
    exit;
}

if ($api_key === '' || $counter_name === '') {
    respond_error($type, 'Missing code or counter parameter');
}

// Restrict counter name to command-like characters (matches how the bot stores them)
$counter_safe = preg_replace('/[^A-Za-z0-9_-]/', '', $counter_name);
if ($counter_safe === '') {
    respond_error($type, 'Invalid counter name');
}

// Resolve API key → username (per-user DB name)
$conn = new mysqli($db_servername, $db_username, $db_password, 'website');
if ($conn->connect_error) {
    respond_error($type, 'Database error');
}
$stmt = $conn->prepare("SELECT username FROM users WHERE api_key = ?");
$stmt->bind_param('s', $api_key);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();
$username = $user['username'] ?? '';
if ($username === '') {
    respond_error($type, 'Invalid code');
}

// Look up the count in the streamer's database
$user_db = new mysqli($db_servername, $db_username, $db_password, $username);
if ($user_db->connect_error) {
    respond_error($type, 'Database error');
}
$count = 0;
$stmt = $user_db->prepare("SELECT count FROM custom_counts WHERE command = ?");
if ($stmt) {
    $stmt->bind_param('s', $counter_safe);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) $count = (int)$row['count'];
    $stmt->close();
}
$user_db->close();

switch ($type) {
    case 'json':
        echo json_encode(['counter' => $counter_safe, 'count' => $count]);
        break;
    case 'number':
        echo $count;
        break;
    case 'name':
        echo $counter_safe;
        break;
    case 'text':
    default:
        echo $counter_safe . ': ' . $count;
        break;
}
