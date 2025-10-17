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
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get recently completed items (limit to 6)
        $sql = "
            SELECT 
                ca.id,
                ca.title as card_title,
                b.name as board_name,
                cat.name as category_name,
                ca.created_at
            FROM cards ca
            JOIN lists l ON ca.list_id = l.id
            JOIN boards b ON l.board_id = b.id
            JOIN categories cat ON b.category_id = cat.id
            WHERE LOWER(l.name) = 'completed'
            ORDER BY ca.id DESC
            LIMIT 6
        ";
        $result = $conn->query($sql);
        if (!$result) {
            throw new Exception('Query failed: ' . $conn->error);
        }
        $items = [];
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $items[] = [
                    'id' => intval($row['id']),
                    'card_title' => $row['card_title'],
                    'board_name' => $row['board_name'],
                    'category_name' => $row['category_name']
                ];
            }
        }
        echo json_encode($items);
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
