<?php
// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    // Load database config
    require_once "/var/www/config/database.php";
    if (empty($db_servername) || empty($db_username)) {
        throw new Exception('Database configuration not properly set');
    }
    $dbname = "roadmap";
    // Create connection without db first
    $conn = new mysqli($db_servername, $db_username, $db_password);
    // Check connection
    if ($conn->connect_error) {
        throw new Exception('Connection failed: ' . $conn->connect_error);
    }
    // Select the database
    if (!$conn->select_db($dbname)) {
        $create_sql = "CREATE DATABASE IF NOT EXISTS $dbname";
        $conn->query($create_sql);
        $conn->select_db($dbname);
    }
    session_start();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get all boards
        $sql = "SELECT * FROM boards ORDER BY created_at DESC";
        $result = $conn->query($sql);
        $boards = [];
        if ($result && $result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $boards[] = $row;
            }
        }
        echo json_encode($boards);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_SESSION['admin']) || !$_SESSION['admin']) {
            throw new Exception('Admin access required');
        }
        // Create new board
        $data = json_decode(file_get_contents('php://input'), true);
        $name = $data['name'] ?? null;
        if (!$name) {
            throw new Exception('Board name is required');
        }
        $category_id = $data['category_id'] ?? null;
        $created_by = $data['created_by'] ?? $_SESSION['username'] ?? 'anonymous';
        $sql = "INSERT INTO boards (category_id, name, created_by) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param("iss", $category_id, $name, $created_by);
        if ($stmt->execute()) {
            echo json_encode(['id' => $conn->insert_id]);
        } else {
            throw new Exception('Failed to create board: ' . $stmt->error);
        }
        $stmt->close();
    } else {
        throw new Exception('Invalid request method');
    }
    $conn->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>