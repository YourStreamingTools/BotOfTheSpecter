<?php
$servername = "";
$username = "";
$password = "";
$dbport = '18256';
$dbname = "website";

// Create a connection
$conn = new mysqli($servername, $username, $password, $dbname, $dbport);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>