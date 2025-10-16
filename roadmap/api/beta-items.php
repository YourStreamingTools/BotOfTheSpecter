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
        echo json_encode(['error' => 'Connection failed: ' . $conn->connect_error]);
        exit;
    }
    // Select the database
    if (!$conn->select_db($dbname)) {
        $create_sql = "CREATE DATABASE IF NOT EXISTS $dbname";
        $conn->query($create_sql);
        $conn->select_db($dbname);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get 6 most recent beta items across all categories
        $sql = "
            SELECT 
                ca.id,
                ca.title as card_title,
                b.name as board_name,
                c.name as category_name,
                ca.id as sort_id
            FROM cards ca
            LEFT JOIN lists l ON ca.list_id = l.id
            LEFT JOIN boards b ON l.board_id = b.id
            LEFT JOIN categories c ON b.category_id = c.id
            WHERE LOWER(l.name) = 'beta'
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
