<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['access_token']) || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Include database connection
require_once "/var/www/config/db_connect.php";
include '/var/www/config/database.php';

$user_id = $_SESSION['user_id'];

// Get parameters
$server_id = isset($_GET['server_id']) ? trim($_GET['server_id']) : '';

if (!$server_id) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing server ID']);
    exit();
}

try {
    // Connect to Discord bot database
    $discord_conn = new mysqli($db_servername, $db_username, $db_password, "specterdiscordbot");
    if ($discord_conn->connect_error) {
        throw new Exception('Discord database connection failed');
    }
    
    // Fetch all embeds for this server
    $stmt = $discord_conn->prepare("SELECT id, embed_name, title, description, color, created_at, updated_at FROM custom_embeds WHERE server_id = ? ORDER BY updated_at DESC");
    $stmt->bind_param("s", $server_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $embeds = [];
    while ($row = $result->fetch_assoc()) {
        $embeds[] = $row;
    }
    
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'embeds' => $embeds
    ]);
    
    $stmt->close();
    $discord_conn->close();
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
