<?php
session_start();
require_once "/var/www/config/db_connect.php";
header('Content-Type: application/json');

$status = null;
if (isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    $stmt = $db->prepare("SELECT status FROM stream_status");
    $stmt->execute();
    $stmt->bind_result($status);
    $stmt->fetch();
    $stmt->close();
}
echo json_encode(['status' => $status]);