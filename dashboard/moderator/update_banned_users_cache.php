<?php
$cacheUsername = $_SESSION['editing_username'];
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $cacheDirectory = "cache/$cacheUsername";
    $cacheFile = "$cacheDirectory/bannedUsers.json";
    $tempCacheFile = "$cacheFile.tmp";
    $data = json_decode(file_get_contents('php://input'), true);
    if ($data === null) {
        echo json_encode(['status' => 'failed', 'error' => 'Invalid JSON']);
        exit();
    }
    if (!is_dir($cacheDirectory)) {
        mkdir($cacheDirectory, 0755, true);
    }
    // Write to a temporary file first
    if (!empty($data) && $tempFileHandle = fopen($tempCacheFile, 'w')) {
        if (flock($tempFileHandle, LOCK_EX)) {
            fwrite($tempFileHandle, json_encode($data));
            fflush($tempFileHandle); // flush output before releasing the lock
            flock($tempFileHandle, LOCK_UN);
        }
        fclose($tempFileHandle);
        rename($tempCacheFile, $cacheFile);
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'failed', 'error' => 'Could not write to cache file or data is empty']);
    }
} else {
    echo json_encode(['status' => 'failed', 'error' => 'Invalid request']);
}
?>