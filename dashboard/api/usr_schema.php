<?php
// Returns the per-user schema check result once layout.php has run it after sending the page.
// ?since=<unix time> - only a check finished at or after this time counts (the page's render time).
require_once '/var/www/lib/session_bootstrap.php';
require_once '/var/www/lib/require_auth_ajax.php';
require_once __DIR__ . '/../includes/usr_schema_marker.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$username = (string) ($_SESSION['username'] ?? '');
session_write_close();

$since = isset($_GET['since']) ? (int) $_GET['since'] : 0;
$marker = $username !== '' ? usr_schema_marker_read($username) : null;
// A successful check counts whenever it ran (another tab may have finished it first); a failed one only if it is from this page.
$finished = $marker !== null
    && ($marker['fingerprint'] ?? '') === usr_schema_marker_fingerprint()
    && (!empty($marker['ok']) || (int) ($marker['checked_at'] ?? 0) >= $since);

echo json_encode([
    'ok' => true,
    'pending' => !$finished,
    'skipped' => false,
    'logs' => $finished && is_array($marker['logs'] ?? null) ? $marker['logs'] : [],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
