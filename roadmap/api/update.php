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
    $data = json_decode(file_get_contents('php://input'), true);
    if (isset($data['type'])) {
        if (!isset($_SESSION['admin']) || !$_SESSION['admin']) {
            throw new Exception('Admin access required');
        }
        if ($data['type'] === 'move_card') {
            $card_id = $data['card_id'] ?? null;
            $new_list_id = $data['list_id'] ?? null;
            $new_position = $data['position'] ?? null;
            if (!$card_id || !$new_list_id || $new_position === null) {
                throw new Exception('card_id, list_id, and position are required');
            }
            // Update the card's list and position
            $sql = "UPDATE cards SET list_id = ?, position = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            $stmt->bind_param("iii", $new_list_id, $new_position, $card_id);
            $success = $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => $success]);
        } elseif ($data['type'] === 'move_list') {
            $list_id = $data['list_id'] ?? null;
            $new_position = $data['position'] ?? null;
            if (!$list_id || $new_position === null) {
                throw new Exception('list_id and position are required');
            }
            $sql = "UPDATE lists SET position = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            $stmt->bind_param("ii", $new_position, $list_id);
            $success = $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => $success]);
        } else {
            throw new Exception('Unknown type: ' . $data['type']);
        }
    } else {
        throw new Exception('Invalid request - type is required');
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