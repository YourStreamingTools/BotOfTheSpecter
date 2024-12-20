<?php
$servername = "localhost";
$username = "username";
$password = "password";
$dbname = "dbname";

// Create a connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>