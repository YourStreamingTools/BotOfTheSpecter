<?php
$host = $_SERVER['HTTP_HOST'];
$host_parts = explode('.', $host);

// Check if the host is a subdomain of "specterbot.app"
if (count($host_parts) > 2 && $host_parts[1] . '.' . $host_parts[2] === 'specterbot.app') {
    $username = $host_parts[0];
} else {
    $username = 'website';
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