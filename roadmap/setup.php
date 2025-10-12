<?php
require_once "/var/www/config/database.php";
$dbname = "roadmap";

// Create connection without db first
$conn = new mysqli($db_servername, $db_username, $db_password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists
$sql = "CREATE DATABASE IF NOT EXISTS $dbname";
if ($conn->query($sql) === TRUE) {
    echo "Database created successfully<br>";
} else {
    die("Error creating database: " . $conn->error);
}

// Select the database
$conn->select_db($dbname);

// SQL to create tables
$sql_categories = "CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT
)";

$sql_boards = "CREATE TABLE IF NOT EXISTS boards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT,
    name VARCHAR(255) NOT NULL,
    created_by VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
)";

$sql_lists = "CREATE TABLE IF NOT EXISTS lists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    position INT DEFAULT 0,
    FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
)";

$sql_cards = "CREATE TABLE IF NOT EXISTS cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    list_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    position INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    due_date DATE,
    labels VARCHAR(255),
    FOREIGN KEY (list_id) REFERENCES lists(id) ON DELETE CASCADE
)";

// Execute queries
if ($conn->query($sql_categories) === TRUE) {
    echo "Categories table created successfully<br>";
} else {
    echo "Error creating categories table: " . $conn->error . "<br>";
}

if ($conn->query($sql_boards) === TRUE) {
    echo "Boards table created successfully<br>";
} else {
    echo "Error creating boards table: " . $conn->error . "<br>";
}

if ($conn->query($sql_lists) === TRUE) {
    echo "Lists table created successfully<br>";
} else {
    echo "Error creating lists table: " . $conn->error . "<br>";
}

if ($conn->query($sql_cards) === TRUE) {
    echo "Cards table created successfully<br>";
} else {
    echo "Error creating cards table: " . $conn->error . "<br>";
}

// Insert sample categories
$conn->query("INSERT IGNORE INTO categories (id, name, description) VALUES (1, 'Bot Features', 'Features for the Twitch bot')");
$conn->query("INSERT IGNORE INTO categories (id, name, description) VALUES (2, 'Dashboard', 'Web dashboard improvements')");
$conn->query("INSERT IGNORE INTO categories (id, name, description) VALUES (3, 'API', 'API enhancements')");

$conn->close();
?>