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
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    $category_id = $_GET['id'];
    // Get category
    $sql_category = "SELECT * FROM categories WHERE id = ?";
    $stmt_category = $conn->prepare($sql_category);
    $stmt_category->bind_param("i", $category_id);
    $stmt_category->execute();
    $category = $stmt_category->get_result()->fetch_assoc();
    $stmt_category->close();
    if (!$category) {
        echo json_encode(['error' => 'Category not found']);
        exit;
    }
    // Get boards for the category
    $sql_boards = "SELECT * FROM boards WHERE category_id = ? ORDER BY created_at DESC";
    $stmt_boards = $conn->prepare($sql_boards);
    $stmt_boards->bind_param("i", $category_id);
    $stmt_boards->execute();
    $result_boards = $stmt_boards->get_result();
    $boards = [];
    while ($board = $result_boards->fetch_assoc()) {
        $boards[] = $board;
    }
    $stmt_boards->close();
    $category['boards'] = $boards;
    $category['admin'] = isset($_SESSION['admin']) && $_SESSION['admin'];
    echo json_encode($category);
} else {
    throw new Exception('Invalid request');
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