<?php
/**
    * Roadmap Database Setup
    * Creates the necessary tables for the roadmap system
*/

require_once "/var/www/config/database.php";

function initializeRoadmapDatabase() {
    global $db_servername, $db_username, $db_password;
    // Create connection
    $conn = new mysqli($db_servername, $db_username, $db_password);
    if ($conn->connect_error) {
        return array('success' => false, 'message' => 'Connection failed: ' . $conn->connect_error);
    }
    // Create database if it doesn't exist
    $sql = "CREATE DATABASE IF NOT EXISTS roadmap";
    if (!$conn->query($sql)) {
        return array('success' => false, 'message' => 'Error creating database: ' . $conn->error);
    }
    // Select the database
    $conn->select_db('roadmap');
    // Create roadmap_items table
    $sql = "CREATE TABLE IF NOT EXISTS roadmap_items (
        id INT PRIMARY KEY AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        description LONGTEXT,
        category ENUM('REQUESTS', 'IN PROGRESS', 'BETA TESTING', 'COMPLETED', 'REJECTED') NOT NULL DEFAULT 'REQUESTS',
        subcategory ENUM('TWITCH BOT', 'DISCORD BOT', 'WEBSOCKET SERVER', 'API SERVER', 'WEBSITE', 'OTHER') NOT NULL,
        priority ENUM('LOW', 'MEDIUM', 'HIGH', 'CRITICAL') NOT NULL DEFAULT 'MEDIUM',
        website_type ENUM('DASHBOARD', 'OVERLAYS') DEFAULT NULL,
        completed_date DATE,
        created_by VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_category (category),
        INDEX idx_subcategory (subcategory),
        INDEX idx_priority (priority),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        return array('success' => false, 'message' => 'Error creating roadmap_items table: ' . $conn->error);
    }
    // Create roadmap_comments table
    $sql = "CREATE TABLE IF NOT EXISTS roadmap_comments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        item_id INT NOT NULL,
        username VARCHAR(255) NOT NULL,
        comment TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (item_id) REFERENCES roadmap_items(id) ON DELETE CASCADE,
        INDEX idx_item_id (item_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        return array('success' => false, 'message' => 'Error creating roadmap_comments table: ' . $conn->error);
    }
    $conn->close();
    return array('success' => true, 'message' => 'Database initialized successfully');
}

// Helper function to get database connection
function getRoadmapConnection() {
    global $db_servername, $db_username, $db_password;
    $conn = new mysqli($db_servername, $db_username, $db_password, 'roadmap');
    if ($conn->connect_error) {
        die('Connection failed: ' . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}
?>