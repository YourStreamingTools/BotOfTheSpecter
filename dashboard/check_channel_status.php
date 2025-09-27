<?php
session_start();
include '/var/www/config/database.php';
$dbname = $_SESSION['username'];
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
header('Content-Type: application/json');

$status = null;
$stmt = $db->prepare("SELECT status FROM stream_status");
$stmt->execute();
$stmt->bind_result($status);
$stmt->fetch();
$stmt->close();
echo json_encode(['status' => $status]);