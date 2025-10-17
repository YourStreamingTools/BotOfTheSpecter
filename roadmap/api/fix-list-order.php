<?php
// Admin-only script to fix list ordering - put Completed last
header('Content-Type: application/json');

try {
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['board_id'])) {
        $board_id = intval($_POST['board_id']);
        // Define the correct order - Completed should be last (4)
        $order = [
            'Upcoming' => 1,
            'Upcoming/Pending' => 1,
            'In Progress' => 2,
            'Beta' => 3,
            'Completed' => 4
        ];
        // Get all lists for this board
        $sql = "SELECT id, name FROM lists WHERE board_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $board_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $updated = 0;
        while ($row = $result->fetch_assoc()) {
            $list_id = $row['id'];
            $list_name = $row['name'];
            $new_position = $order[$list_name] ?? 5;
            $update_sql = "UPDATE lists SET position = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ii", $new_position, $list_id);
            if ($update_stmt->execute()) {
                $updated++;
            }
            $update_stmt->close();
        }
        $stmt->close();
        echo json_encode(['success' => true, 'updated' => $updated]);
    } else {
        throw new Exception('Invalid request');
    }
    $conn->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
