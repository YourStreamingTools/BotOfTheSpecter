<?php
$host = $_SERVER['HTTP_HOST'];
$host_parts = explode('.', $host);
$username = isset($host_parts[0]) ? $host_parts[0] : 'website';
$database = $username;

$servername = "sql.botofthespecter.com";
$db_username = "";
$db_password = "";

$conn = new mysqli($servername, $db_username, $db_password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Connected successfully to the database: " . $database;
?>