<?php
header('Content-Type: application/json');
require_once "/var/www/config/database.php";
$dbname = "roadmap";

// Create connection without db first
$conn = new mysqli($db_servername, $db_username, $db_password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Select the database
$conn->select_db($dbname);

session_start();

$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['type'])) {
    if (!isset($_SESSION['admin']) || !$_SESSION['admin']) {
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
    if ($data['type'] === 'move_card') {
        $card_id = $data['card_id'];
        $new_list_id = $data['list_id'];
        $new_position = $data['position'];

        // Update the card's list and position
        $sql = "UPDATE cards SET list_id = ?, position = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $new_list_id, $new_position, $card_id);
        $success = $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => $success]);
    } elseif ($data['type'] === 'move_list') {
        $list_id = $data['list_id'];
        $new_position = $data['position'];

        $sql = "UPDATE lists SET position = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $new_position, $list_id);
        $success = $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => $success]);
    }
} else {
    echo json_encode(['error' => 'Invalid type']);
}

$conn->close();
?>