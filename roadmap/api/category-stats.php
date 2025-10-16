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
        if (isset($_GET['id'])) {
            // Get stats for a specific category
            $category_id = $_GET['id'];
            $sql = "
                SELECT 
                    l.name as list_name,
                    COUNT(ca.id) as card_count
                FROM lists l
                LEFT JOIN cards ca ON l.id = ca.list_id
                WHERE l.board_id IN (
                    SELECT id FROM boards WHERE category_id = ?
                )
                GROUP BY l.id, l.name
                ORDER BY 
                    CASE 
                        WHEN LOWER(l.name) = 'completed' THEN 3
                        WHEN LOWER(l.name) = 'in progress' THEN 2
                        WHEN LOWER(l.name) = 'upcoming' THEN 1
                        ELSE 0
                    END DESC,
                    l.position ASC
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            $stmt->bind_param("i", $category_id);
            if (!$stmt->execute()) {
                throw new Exception('Execute failed: ' . $stmt->error);
            }
            $result = $stmt->get_result();
            $stats = [
                'total_cards' => 0,
                'completed_cards' => 0,
                'lists' => []
            ];
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $count = intval($row['card_count']);
                    $stats['total_cards'] += $count;
                    if (strtolower($row['list_name']) === 'completed') {
                        $stats['completed_cards'] += $count;
                    }
                    $stats['lists'][] = [
                        'name' => $row['list_name'],
                        'count' => $count
                    ];
                }
            }
            $stats['percentage'] = $stats['total_cards'] > 0 ? round(($stats['completed_cards'] / $stats['total_cards']) * 100) : 0;
            $stmt->close();
            echo json_encode($stats);
        } else {
            // Get stats for all categories
            $sql = "
                SELECT 
                    c.id,
                    c.name,
                    COUNT(DISTINCT ca.id) as total_cards,
                    COUNT(DISTINCT CASE WHEN LOWER(l.name) = 'completed' THEN ca.id ELSE NULL END) as completed_cards
                FROM categories c
                LEFT JOIN boards b ON c.id = b.category_id
                LEFT JOIN lists l ON b.id = l.board_id
                LEFT JOIN cards ca ON l.id = ca.list_id
                GROUP BY c.id, c.name
                ORDER BY c.name
            ";
            $result = $conn->query($sql);
            if (!$result) {
                throw new Exception('Query failed: ' . $conn->error);
            }
            $stats = [];
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $total = intval($row['total_cards']);
                    $completed = intval($row['completed_cards']);
                    $percentage = $total > 0 ? round(($completed / $total) * 100) : 0;
                    
                    $stats[] = [
                        'id' => intval($row['id']),
                        'name' => $row['name'],
                        'total_cards' => $total,
                        'completed_cards' => $completed,
                        'percentage' => $percentage
                    ];
                }
            }
            echo json_encode($stats);
        }
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
