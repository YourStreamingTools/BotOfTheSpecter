<?php
header('Content-Type: application/json');

// Only allow this from admin or POST requests
session_start();

require_once "/var/www/config/database.php";

// Check if user is admin
$is_admin = false;
if (isset($_SESSION['username'])) {
    $db = new mysqli($db_servername, $db_username, $db_password, "roadmap");
    $username = $db->real_escape_string($_SESSION['username']);
    $result = $db->query("SELECT is_admin FROM users WHERE username = '$username'");
    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $is_admin = (bool)$user['is_admin'];
    }
    $db->close();
}

if (!$is_admin) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required']);
    exit;
}

$conn = new mysqli($db_servername, $db_username, $db_password, "roadmap");

if ($conn->connect_error) {
    echo json_encode(['error' => 'Connection failed: ' . $conn->connect_error]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Clear all cards from "Completed" list
    $delete_sql = "
        DELETE FROM cards 
        WHERE list_id IN (
            SELECT id FROM lists WHERE LOWER(name) = 'completed'
        )
    ";
    if ($conn->query($delete_sql)) {
        echo json_encode([
            'success' => true,
            'message' => 'All completed items have been cleared'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to clear completed items: ' . $conn->error
        ]);
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Just show what would be deleted
    $check_sql = "
        SELECT COUNT(*) as cnt FROM cards 
        WHERE list_id IN (
            SELECT id FROM lists WHERE LOWER(name) = 'completed'
        )
    ";
    $result = $conn->query($check_sql);
    $row = $result->fetch_assoc();
    echo json_encode([
        'completed_items_count' => intval($row['cnt']),
        'message' => 'POST to this endpoint to clear all completed items'
    ]);
}

$conn->close();
?>
