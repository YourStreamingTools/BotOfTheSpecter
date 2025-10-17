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
            $category_id = $conn->insert_id;
            // Create board for this category
            $board_name = $name . " Board";
            $created_by = $_SESSION['username'] ?? 'admin';
            $sql_board = "INSERT INTO boards (category_id, name, created_by) VALUES (?, ?, ?)";
            $stmt_board = $conn->prepare($sql_board);
            if (!$stmt_board) {
                throw new Exception('Failed to prepare board insert: ' . $conn->error);
            }
            $stmt_board->bind_param("iss", $category_id, $board_name, $created_by);
            if (!$stmt_board->execute()) {
                throw new Exception('Failed to create board: ' . $stmt_board->error);
            }
            $board_id = $conn->insert_id;
            $stmt_board->close();
            // Create 4 default lists for the board
            $lists = [
                ['name' => 'Upcoming', 'position' => 0],
                ['name' => 'In Progress', 'position' => 1],
                ['name' => 'Beta', 'position' => 2],
                ['name' => 'Completed', 'position' => 3]
            ];
            $sql_list = "INSERT INTO lists (board_id, name, position) VALUES (?, ?, ?)";
            $stmt_list = $conn->prepare($sql_list);
            if (!$stmt_list) {
                throw new Exception('Failed to prepare list insert: ' . $conn->error);
            }
            foreach ($lists as $list) {
                $list_name = $list['name'];
                $list_position = $list['position'];
                $stmt_list->bind_param("isi", $board_id, $list_name, $list_position);
                if (!$stmt_list->execute()) {
                    throw new Exception('Failed to create list ' . $list_name . ': ' . $stmt_list->error);
                }
            }
            $stmt_list->close();
            echo json_encode([
                'success' => true,
                'id' => $category_id,
                'board_id' => $board_id,
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
