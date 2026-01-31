<?php
// Initialize session and check authentication
session_start();
if (!isset($_SESSION['access_token'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Set JSON header
header('Content-Type: application/json');

// Get the link from POST data
$link = isset($_POST['link']) ? trim($_POST['link']) : '';

if (empty($link)) {
    echo json_encode(['matches' => false]);
    exit();
}

// Database connection for spam_pattern database
require_once "/var/www/config/database.php";
$spam_db = new mysqli($db_servername, $db_username, $db_password, 'spam_pattern');
if ($spam_db->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

try {
    // Fetch all spam patterns
    $stmt = $spam_db->prepare("SELECT spam_pattern FROM spam_patterns");
    $stmt->execute();
    $result = $stmt->get_result();
    $matches = false;
    while ($row = $result->fetch_assoc()) {
        $pattern = $row['spam_pattern'];
        // Escape the pattern for use in regex
        $escaped_pattern = preg_quote($pattern, '/');
        // Check if the link matches the spam pattern (case-insensitive)
        if (preg_match('/' . $escaped_pattern . '/i', $link)) {
            $matches = true;
            break;
        }
    }
    $stmt->close();
    $spam_db->close();
    echo json_encode(['matches' => $matches]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>
