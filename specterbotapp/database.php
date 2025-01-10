<?php
$host = $_SERVER['HTTP_HOST'];
$host_parts = explode('.', $host);

// Check if the host is "specterbot.app" or "www.specterbot.app"
if (strpos($host, 'specterbot.app') !== false) {
    $username = 'website';
} else {
    $username = isset($host_parts[0]) ? $host_parts[0] : 'website';
}

$servername = "sql.botofthespecter.com";
$db_username = "";
$db_password = "";

$conn = new mysqli($servername, $db_username, $db_password, $username);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$connection = "Connected successfully to the database: " . $username . "";
?>