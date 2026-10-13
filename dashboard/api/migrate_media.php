<?php
require_once '/var/www/lib/session_bootstrap.php';
session_write_close();
header('Content-Type: application/json');

require_once '/var/www/lib/require_auth_ajax.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$username = $_SESSION['username'] ?? '';
if (empty($username)) {
    echo json_encode(['success' => false, 'message' => 'No username in session.']);
    exit();
}

require_once __DIR__ . '/../includes/media_migrate.php';
echo json_encode(migrate_user_media_library($username));
?>
