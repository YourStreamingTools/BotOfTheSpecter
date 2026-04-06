<?php
$host = $_SERVER['HTTP_HOST'];
$host_parts = explode('.', $host);

// Check if the host is a subdomain of "specterbot.app"
if (count($host_parts) > 2 && $host_parts[1] . '.' . $host_parts[2] === 'specterbot.app') {
    $username = $host_parts[0];
} else {
    $username = 'website';
}

require_once '/var/www/config/database.php';
$servername = $db_servername;

$conn = new mysqli($servername, $db_username, $db_password, $username);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($username === 'website') {
    $connection = "Connected successfully to the database";
} else {
    $connection = "Connected successfully to the database: " . $username;
}

// Connect to custom module database if it exists for this user
$conn_module = null;
if ($username !== 'website') {
    $module_db = $username . '_custom_modules';
    $result = $conn->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . $conn->real_escape_string($module_db) . "'");
    if ($result && $result->num_rows > 0) {
        $conn_module = new mysqli($servername, $db_username, $db_password, $module_db);
        if ($conn_module->connect_error) {
            $conn_module = null;
        }
    }
}
?>