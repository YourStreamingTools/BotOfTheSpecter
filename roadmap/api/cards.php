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
        $board_id = $data['board_id'] ?? null;
        $title = $data['title'] ?? null;
        $section = $data['section'] ?? 'Pending';
        $description = $data['description'] ?? '';
        $position = $data['position'] ?? 0;
        $due_date = $data['due_date'] ?? null;
        $labels = $data['labels'] ?? '';
        
        if (!$board_id || !$title) {
            throw new Exception('board_id and title are required');
        } 
        // Find the list_id for this board and section
        $list_sql = "SELECT id FROM lists WHERE board_id = ? AND LOWER(name) = LOWER(?)";
        $list_stmt = $conn->prepare($list_sql);
        if (!$list_stmt) {
            throw new Exception('Prepare failed for list lookup: ' . $conn->error);
        }
        $list_stmt->bind_param("is", $board_id, $section);
        $list_stmt->execute();
        $list_result = $list_stmt->get_result();
        if ($list_result->num_rows === 0) {
            $list_stmt->close();
            throw new Exception('No list found for section: ' . $section);
        }
        $list_row = $list_result->fetch_assoc();
        $list_id = $list_row['id'];
        $list_stmt->close();
        $sql = "INSERT INTO cards (board_id, list_id, title, section, description, position, due_date, labels) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param("iisssisss", $board_id, $list_id, $title, $section, $description, $position, $due_date, $labels);
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