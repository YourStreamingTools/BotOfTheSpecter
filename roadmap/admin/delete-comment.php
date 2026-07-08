<?php
/**
 * Roadmap comment delete handler
 */

require_once dirname(__DIR__) . '/includes/session.php';
roadmap_session_start();

if (!roadmap_is_logged_in() || !roadmap_is_admin()) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

require_once 'database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Invalid request']));
}

if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Invalid CSRF token']));
}

$commentId = (int)($_POST['comment_id'] ?? 0);
if ($commentId <= 0) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Invalid comment ID']));
}

$conn = getRoadmapConnection();
$stmt = $conn->prepare('DELETE FROM roadmap_comments WHERE id = ?');
if (!$stmt) {
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'Error preparing statement: ' . $conn->error]));
}

$stmt->bind_param('i', $commentId);
if ($stmt->execute() && $stmt->affected_rows > 0) {
    http_response_code(200);
    die(json_encode(['success' => true, 'message' => 'Comment deleted successfully']));
}

if ($stmt->affected_rows === 0) {
    http_response_code(404);
    die(json_encode(['success' => false, 'message' => 'Comment not found']));
}

http_response_code(500);
die(json_encode(['success' => false, 'message' => 'Error deleting comment: ' . $stmt->error]));