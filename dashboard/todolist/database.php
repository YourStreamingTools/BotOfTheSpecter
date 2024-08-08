<?php
// Connect to the MySQL database using mysqli
$db = new mysqli("sql.botofthespecter.com", "USERNAME", "PASSWORD", $username);

// Check the connection
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

// Set the charset to utf8mb4 for better Unicode support
$db->set_charset("utf8mb4");
?>