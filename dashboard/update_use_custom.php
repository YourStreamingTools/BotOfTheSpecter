<?php
session_start();
header('Content-Type: application/json');
// Require DB connection
require_once "/var/www/config/db_connect.php";

// Ensure user is authenticated
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$raw = file_get_contents('php://input');
parse_str($raw, $data);
$use_custom = isset($data['use_custom']) ? intval($data['use_custom']) : null;
if ($use_custom !== 0 && $use_custom !== 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid value']);
    exit();
}

try {
    $stmt = $conn->prepare('UPDATE users SET use_custom = ? WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new Exception('Prepare failed');
    }
    $stmt->bind_param('ii', $use_custom, $user_id);
    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    $stmt->close();
    // Update session copy
    $_SESSION['use_custom'] = $use_custom;
    echo json_encode(['success' => true, 'use_custom' => $use_custom]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>
