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
    $board_id = $data['board_id'];
    $name = $data['name'];
    $position = $data['position'] ?? 0;

    $sql = "INSERT INTO lists (board_id, name, position) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isi", $board_id, $name, $position);
    if ($stmt->execute()) {
        echo json_encode(['id' => $conn->insert_id]);
    } else {
        echo json_encode(['error' => 'Failed to create list']);
    }
    $stmt->close();
}

$conn->close();
?>