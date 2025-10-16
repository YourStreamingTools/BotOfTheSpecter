<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    session_start();
    if (!isset($_SESSION['admin']) || !$_SESSION['admin']) {
        http_response_code(403);
        throw new Exception('Admin access required');
    }
    require_once "/var/www/config/database.php";
    if (empty($db_servername) || empty($db_username)) {
        throw new Exception('Database configuration not properly set');
    }
    $dbname = "roadmap";
    $conn = new mysqli($db_servername, $db_username, $db_password);
    if ($conn->connect_error) {
        throw new Exception('Connection failed: ' . $conn->connect_error);
    }
    if (!$conn->select_db($dbname)) {
        throw new Exception('Database not found');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Check if cards table exists
        $check_table = $conn->query("SHOW TABLES LIKE 'cards'");
        if (!$check_table || $check_table->num_rows === 0) {
            throw new Exception('Cards table does not exist. Please run "Auto-Fix Database" first.');
        }
        // Get all lists that have a board_id
        $sql = "SELECT * FROM lists WHERE board_id IS NOT NULL ORDER BY board_id";
        $result = $conn->query($sql);
        if (!$result) {
            throw new Exception('Query failed: ' . $conn->error);
        }
        $migrated = 0;
        $errors = [];
        while ($list = $result->fetch_assoc()) {
            try {
                $board_id = (int)$list['board_id'];
                $title = $list['name'];
                $position = (int)($list['position'] ?? 0);
                // Determine section based on list name
                $section = 'Pending';
                $name_lower = strtolower($title);
                if (strpos($name_lower, 'progress') !== false) {
                    $section = 'In Progress';
                } elseif (strpos($name_lower, 'beta') !== false) {
                    $section = 'Beta';
                } elseif (strpos($name_lower, 'complete') !== false) {
                    $section = 'Completed';
                }
                // Insert as card using prepared statement
                $insert_sql = "INSERT INTO cards (board_id, title, section, position) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($insert_sql);
                if (!$stmt) {
                    throw new Exception('Failed to prepare insert: ' . $conn->error);
                }
                $stmt->bind_param("issi", $board_id, $title, $section, $position);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to execute: ' . $stmt->error);
                }
                $stmt->close();
                $migrated++;
            } catch (Exception $e) {
                $errors[] = "List '" . $list['name'] . "': " . $e->getMessage();
            }
        }
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'migrated' => $migrated,
            'errors' => $errors
        ]);
    } else {
        throw new Exception('Invalid request method');
    }
    $conn->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
