<?php
header('Content-Type: application/json');

require_once "/var/www/config/database.php";

$conn = new mysqli($db_servername, $db_username, $db_password, "roadmap");

if ($conn->connect_error) {
    echo json_encode(['error' => 'Connection failed']);
    exit;
}

if (!isset($_GET['category_id'])) {
    echo json_encode(['error' => 'category_id required']);
    exit;
}

$category_id = intval($_GET['category_id']);

$result = $conn->query("SELECT id FROM boards WHERE category_id = $category_id LIMIT 1");

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode(['board' => ['id' => intval($row['id'])]]);
} else {
    echo json_encode(['board' => null]);
}

$conn->close();
?>
