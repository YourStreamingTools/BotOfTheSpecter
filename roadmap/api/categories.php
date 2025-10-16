<?php
// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    // Load database config
    $config_path = "/var/www/config/database.php";
    if (!file_exists($config_path)) {
        throw new Exception("Config file not found at: " . $config_path);
    }
    require_once $config_path;
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
        // Database doesn't exist, try to create it
        $create_sql = "CREATE DATABASE IF NOT EXISTS $dbname";
        if (!$conn->query($create_sql)) {
            throw new Exception('Failed to create database: ' . $conn->error);
        }
        if (!$conn->select_db($dbname)) {
            throw new Exception('Failed to select database: ' . $conn->error);
        }
        // Create tables
        $sql_categories = "CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT
        )";
        if (!$conn->query($sql_categories)) {
            throw new Exception('Failed to create categories table: ' . $conn->error);
        }
        // Insert sample categories
        $conn->query("INSERT IGNORE INTO categories (id, name, description) VALUES (1, 'Bot Features', 'Features for the Twitch bot')");
        $conn->query("INSERT IGNORE INTO categories (id, name, description) VALUES (2, 'Dashboard', 'Web dashboard improvements')");
        $conn->query("INSERT IGNORE INTO categories (id, name, description) VALUES (3, 'API', 'API enhancements')");
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get all categories
        $sql = "SELECT * FROM categories ORDER BY name";
        $result = $conn->query($sql);
        if (!$result) {
            throw new Exception('Query failed: ' . $conn->error);
        }
        $categories = [];
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $categories[] = $row;
            }
        }
        echo json_encode($categories);
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