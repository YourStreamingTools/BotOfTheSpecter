<?php
$servername = "sql.botofthespecter.com";
$username = "";  // CHANGE TO MAKE THIS WORK
$password = ""; // CHANGE TO MAKE THIS WORK
$dbname = "website";

// Create a connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>