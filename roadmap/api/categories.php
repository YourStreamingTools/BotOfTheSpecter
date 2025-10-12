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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get all categories
    $sql = "SELECT * FROM categories ORDER BY name";
    $result = $conn->query($sql);
    $categories = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $categories[] = $row;
        }
    }
    echo json_encode($categories);
}

$conn->close();
?>