<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

$cacheUsername = $_SESSION['username'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $cacheDirectory = "cache/$cacheUsername";
    $cacheFile = "$cacheDirectory/bannedUsers.json";
    $tempCacheFile = "$cacheFile.tmp";

    $data = json_decode(file_get_contents('php://input'), true);

    if ($data === null) {
        error_log("Received invalid JSON data");
        echo json_encode(['status' => 'failed', 'error' => 'Invalid JSON']);
        exit();
    }

    if (!is_dir($cacheDirectory)) {
        mkdir($cacheDirectory, 0755, true);
    }

    // Log the cache data to be written
    error_log("Data to be written to cache: " . json_encode($data));

    // Write to a temporary file first
    if ($tempFileHandle = fopen($tempCacheFile, 'w')) {
        if (flock($tempFileHandle, LOCK_EX)) {
            fwrite($tempFileHandle, json_encode($data));
            fflush($tempFileHandle); // flush output before releasing the lock
            flock($tempFileHandle, LOCK_UN);
        }
        fclose($tempFileHandle);
        rename($tempCacheFile, $cacheFile);
        error_log("Updated cache for $cacheUsername: " . json_encode($data));
        echo json_encode(['status' => 'success']);
    } else {
        error_log("Failed to open temp cache file for writing");
        echo json_encode(['status' => 'failed', 'error' => 'Could not write to cache file']);
    }

    // Log the cache file content after writing
    $finalCacheContent = file_get_contents($cacheFile);
    error_log("Cache file content after updating: $finalCacheContent");

} else {
    error_log("Failed to update cache for $cacheUsername");
    echo json_encode(['status' => 'failed', 'error' => 'Invalid request']);
}
?>