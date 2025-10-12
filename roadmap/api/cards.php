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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['admin']) || !$_SESSION['admin']) {
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $list_id = $data['list_id'];
    $title = $data['title'];
    $description = $data['description'] ?? '';
    $position = $data['position'] ?? 0;
    $due_date = $data['due_date'] ?? null;
    $labels = $data['labels'] ?? '';

    $sql = "INSERT INTO cards (list_id, title, description, position, due_date, labels) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ississ", $list_id, $title, $description, $position, $due_date, $labels);
    if ($stmt->execute()) {
        echo json_encode(['id' => $conn->insert_id]);
    } else {
        echo json_encode(['error' => 'Failed to create card']);
    }
    $stmt->close();
}

$conn->close();
?>