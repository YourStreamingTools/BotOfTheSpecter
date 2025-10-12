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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get all boards
    $sql = "SELECT * FROM boards ORDER BY created_at DESC";
    $result = $conn->query($sql);
    $boards = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $boards[] = $row;
        }
    }
    echo json_encode($boards);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['admin']) || !$_SESSION['admin']) {
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
    // Create new board
    $data = json_decode(file_get_contents('php://input'), true);
    $name = $data['name'];
    $category_id = $data['category_id'] ?? null;
    $created_by = $data['created_by'] ?? 'anonymous'; // Assume from session later
    $sql = "INSERT INTO boards (category_id, name, created_by) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $category_id, $name, $created_by);
    if ($stmt->execute()) {
        echo json_encode(['id' => $conn->insert_id]);
    } else {
        echo json_encode(['error' => 'Failed to create board']);
    }
    $stmt->close();
}

$conn->close();
?>