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
        if (!isset($_SESSION['admin']) || !$_SESSION['admin']) {
            throw new Exception('Admin access required');
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $list_id = $data['list_id'] ?? null;
        $title = $data['title'] ?? null;
        $description = $data['description'] ?? '';
        $position = $data['position'] ?? 0;
        $due_date = $data['due_date'] ?? null;
        $labels = $data['labels'] ?? '';
        if (!$list_id || !$title) {
            throw new Exception('list_id and title are required');
        }
        $sql = "INSERT INTO cards (list_id, title, description, position, due_date, labels) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param("ississ", $list_id, $title, $description, $position, $due_date, $labels);
        if ($stmt->execute()) {
            echo json_encode(['id' => $conn->insert_id]);
        } else {
            throw new Exception('Failed to create card: ' . $stmt->error);
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