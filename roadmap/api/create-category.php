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
        if (!$conn->query($create_sql)) {
            throw new Exception('Failed to create database: ' . $conn->error);
        }
        if (!$conn->select_db($dbname)) {
            throw new Exception('Failed to select database: ' . $conn->error);
        }
    }
    session_start();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Check admin access
        if (!isset($_SESSION['admin']) || !$_SESSION['admin']) {
            http_response_code(403);
            throw new Exception('Admin access required');
        }
        // Get POST data
        $data = json_decode(file_get_contents('php://input'), true);
        $name = $data['name'] ?? null;
        $description = $data['description'] ?? '';
        if (!$name) {
            throw new Exception('Category name is required');
        }
        // Insert new category
        $sql = "INSERT INTO categories (name, description) VALUES (?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param("ss", $name, $description);
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'id' => $conn->insert_id,
                'name' => $name,
                'description' => $description
            ]);
        } else {
            throw new Exception('Failed to create category: ' . $stmt->error);
        }
        $stmt->close();
    } else {
        throw new Exception('Invalid request method. POST required.');
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
