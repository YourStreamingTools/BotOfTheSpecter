<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "/var/www/config/db_connect.php";

function admin_access_is_json_request() {
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower((string) $_SERVER['HTTP_ACCEPT']) : '';
    $contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string) $_SERVER['CONTENT_TYPE']) : '';
    $requestedWith = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) : '';
    return strpos($accept, 'application/json') !== false
        || strpos($contentType, 'application/json') !== false
        || $requestedWith === 'xmlhttprequest';
}

function admin_access_is_sse_request() {
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower((string) $_SERVER['HTTP_ACCEPT']) : '';
    return strpos($accept, 'text/event-stream') !== false;
}

function admin_access_deny($message = 'Access denied. Administrator access required.') {
    http_response_code(403);
    if (admin_access_is_sse_request()) {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        echo "event: error\n";
        echo 'data: ' . $message . "\n\n";
        echo "event: done\n";
        echo "data: {\"success\":false}\n\n";
        exit;
    }
    if (admin_access_is_json_request()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }
    $accessMode = 'denied';
    $info = $message;
    include __DIR__ . '/../restricted.php';
    exit;
}

if (!isset($_SESSION['access_token']) || empty($_SESSION['access_token'])) {
    header('Location: ../login.php');
    exit;
}

$access_token = $_SESSION['access_token'];
$adminStmt = $conn->prepare("SELECT id, username, is_admin FROM users WHERE access_token = ? LIMIT 1");

if (!$adminStmt) {
    admin_access_deny('Access denied. Unable to validate account permissions.');
}

$adminStmt->bind_param('s', $access_token);
$adminStmt->execute();
$adminResult = $adminStmt->get_result();
$adminRow = $adminResult ? $adminResult->fetch_assoc() : null;
$adminStmt->close();

if (!$adminRow) {
    header('Location: ../login.php');
    exit;
}

$is_admin = ((int) ($adminRow['is_admin'] ?? 0) === 1);
$_SESSION['user_id'] = (int) ($adminRow['id'] ?? 0);
$_SESSION['username'] = $adminRow['username'] ?? ($_SESSION['username'] ?? '');
$_SESSION['is_admin'] = $is_admin;

if (!$is_admin) {
    admin_access_deny('Access denied. This section is restricted to administrators only.');
}
?>