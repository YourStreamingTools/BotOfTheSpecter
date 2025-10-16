<?php
// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    session_start();
    // Check admin access
    if (!isset($_SESSION['admin']) || !$_SESSION['admin']) {
        http_response_code(403);
        throw new Exception('Admin access required');
    }
    // Load database config
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
        // Get all lists that have a board_id (these are the items to migrate)
        $sql = "SELECT * FROM lists WHERE board_id IS NOT NULL ORDER BY board_id";
        $result = $conn->query($sql);
        if (!$result) {
            throw new Exception('Query failed: ' . $conn->error);
        }
        $migrated = 0;
        $errors = [];
        while ($list = $result->fetch_assoc()) {
            $list_id = $list['id'];
            $board_id = $list['board_id'];
            $title = $list['name'];
            $description = $list['description'] ?? null;
            $position = $list['position'] ?? 0;
            // Determine section based on list name or default to Pending
            $section = 'Pending';
            $name_lower = strtolower($title);
            if (strpos($name_lower, 'progress') !== false) {
                $section = 'In Progress';
            } elseif (strpos($name_lower, 'beta') !== false) {
                $section = 'Beta';
            } elseif (strpos($name_lower, 'complete') !== false) {
                $section = 'Completed';
            }
            // Insert as card
            $insert_sql = "INSERT INTO cards (board_id, title, description, section, position) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($insert_sql);
            if (!$stmt) {
                $errors[] = "Failed to prepare insert for list '$title': " . $conn->error;
                continue;
            }
            $stmt->bind_param("isssi", $board_id, $title, $description, $section, $position);
            if ($stmt->execute()) {
                $migrated++;
            } else {
                $errors[] = "Failed to migrate list '$title': " . $stmt->error;
            }
            $stmt->close();
        }
        echo json_encode([
            'success' => true,
            'migrated' => $migrated,
            'errors' => $errors,
            'message' => "Successfully migrated $migrated list(s) to cards"
        ]);
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
