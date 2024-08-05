<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

$cacheUsername = $_SESSION['username'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $cacheDirectory = "cache/$cacheUsername";
    $cacheFile = "$cacheDirectory/bannedUsers.json";

    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_dir($cacheDirectory)) {
        mkdir($cacheDirectory, 0755, true);
    }

    file_put_contents($cacheFile, json_encode($data));
    error_log("Updated cache for $cacheUsername: " . json_encode($data));
    echo json_encode(['status' => 'success']);
} else {
    error_log("Failed to update cache for $cacheUsername");
    echo json_encode(['status' => 'failed', 'error' => 'Invalid request']);
}
?>