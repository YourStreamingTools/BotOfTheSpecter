<?php
// Initialize the session
session_start();

if (!isset($_SESSION['username'])) {
    $errorMsg = 'User session not found.';
    error_log("update_banned_users_cache.php: " . $errorMsg);
    echo json_encode(['status' => 'failed', 'error' => $errorMsg]);
    exit();
}

$loggedInUsername = $_SESSION['username'];
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $cacheBaseDir = "/var/www/cache/known_users";
    $cacheFile = "$cacheBaseDir/$loggedInUsername.json";
    $tempCacheFile = "$cacheFile.tmp";
    $data = json_decode(file_get_contents('php://input'), true);
    if ($data === null) {
        $errorMsg = 'Invalid JSON received.';
        error_log("update_banned_users_cache.php: " . $errorMsg . " User: " . $loggedInUsername);
        echo json_encode(['status' => 'failed', 'error' => $errorMsg]);
        exit();
    }
    if (!is_dir($cacheBaseDir)) {
        if (!mkdir($cacheBaseDir, 0755, true) && !is_dir($cacheBaseDir)) {
            $errorMsg = "Could not create cache directory: $cacheBaseDir. Check permissions.";
            error_log("update_banned_users_cache.php: " . $errorMsg . " User: " . $loggedInUsername);
            echo json_encode(['status' => 'failed', 'error' => $errorMsg]);
            exit();
        }
    }
    if (is_array($data) && count($data) > 0) {
        $existingCache = [];
        if (file_exists($cacheFile)) {
            $existingContent = file_get_contents($cacheFile);
            if ($existingContent) {
                $existingCache = json_decode($existingContent, true) ?? [];
            }
        }
        $mergedCache = array_merge($existingCache, $data);
        $tempFileHandle = @fopen($tempCacheFile, 'w');
        if ($tempFileHandle) {
            if (flock($tempFileHandle, LOCK_EX)) {
                fwrite($tempFileHandle, json_encode($mergedCache));
                fflush($tempFileHandle);
                flock($tempFileHandle, LOCK_UN);
            } else {
                fclose($tempFileHandle);
                $errorMsg = "Could not acquire lock on temporary cache file: $tempCacheFile.";
                error_log("update_banned_users_cache.php: " . $errorMsg . " User: " . $loggedInUsername);
                if (file_exists($tempCacheFile)) @unlink($tempCacheFile);
                echo json_encode(['status' => 'failed', 'error' => $errorMsg]);
                exit();
            }
            fclose($tempFileHandle);
            if (rename($tempCacheFile, $cacheFile)) {
                error_log("update_banned_users_cache.php: Successfully updated cache for user: $loggedInUsername with " . count($data) . " new entries (total: " . count($mergedCache) . ").");
                echo json_encode(['status' => 'success', 'entries_added' => count($data), 'total_entries' => count($mergedCache)]);
            } else {
                $errorMsg = "Could not rename temporary cache file $tempCacheFile to $cacheFile. Check permissions.";
                error_log("update_banned_users_cache.php: " . $errorMsg . " User: " . $loggedInUsername);
                if (file_exists($tempCacheFile)) {
                    @unlink($tempCacheFile);
                }
                echo json_encode(['status' => 'failed', 'error' => 'Could not save cache file. Check server logs and permissions.']);
            }
        } else {
            $errorMsg = "Could not open temporary cache file for writing: $tempCacheFile. Check permissions.";
            error_log("update_banned_users_cache.php: " . $errorMsg . " User: " . $loggedInUsername);
            echo json_encode(['status' => 'failed', 'error' => $errorMsg]);
        }
    } else {
        error_log("update_banned_users_cache.php: Received empty or invalid data for user: $loggedInUsername. Data: " . json_encode($data));
        echo json_encode(['status' => 'failed', 'error' => 'No valid data received to cache.']);
    }
} else {
    $errorMsg = 'Invalid request method.';
    error_log("update_banned_users_cache.php: " . $errorMsg);
    echo json_encode(['status' => 'failed', 'error' => $errorMsg]);
}
?>