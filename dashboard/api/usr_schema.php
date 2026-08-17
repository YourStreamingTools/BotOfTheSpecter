<?php
// Returns schema console messages collected after layout finishes the HTML response.
require_once '/var/www/lib/session_bootstrap.php';
require_once '/var/www/lib/require_auth_ajax.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$username = (string) ($_SESSION['username'] ?? '');
$ok = (string) ($_SESSION['usr_schema_ok'] ?? '');
$logs = $_SESSION['usr_schema_console'] ?? null;
$peek = isset($_GET['peek']);

if (is_array($logs)) {
    if (!$peek) {
        unset($_SESSION['usr_schema_console']);
    }
    session_write_close();
    echo json_encode([
        'ok' => true,
        'pending' => false,
        'skipped' => false,
        'logs' => $logs,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

session_write_close();

if ($username !== '' && $ok === $username) {
    echo json_encode([
        'ok' => true,
        'pending' => false,
        'skipped' => true,
        'logs' => [],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'pending' => true,
    'skipped' => false,
    'logs' => [],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
