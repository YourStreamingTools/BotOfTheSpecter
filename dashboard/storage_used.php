<?php
include 'file_paths.php';

// Define the user's directories
$walkon_path = "/var/www/walkons/" . $username;
$soundalert_path = "/var/www/soundalerts/" . $username;
$videoalert_path = "/var/www/videoalerts/" . $username;
$twitch_sound_alert_path = $soundalert_path . "/twitch";
// Per-user uploaded music (private to uploader) - store outside public CDN to prevent direct access
$user_music_path = "/var/www/private/music_user/" . $username;
// Define user-specific storage limits
$base_storage_size = 20 * 1024 * 1024; // 20MB in bytes (FREE)

// Check if user has beta access from database
$betaAccess = false;
$userId = $_SESSION['user_id'] ?? 0;
if ($userId > 0) {
    require_once "/var/www/config/db_connect.php";
    $betaCheckStmt = $conn->prepare("SELECT beta_access FROM users WHERE id = ?");
    if ($betaCheckStmt) {
        $betaCheckStmt->bind_param("i", $userId);
        $betaCheckStmt->execute();
        $betaCheckResult = $betaCheckStmt->get_result();
        if ($betaRow = $betaCheckResult->fetch_assoc()) {
            $betaAccess = ($betaRow['beta_access'] == 1);
        }
        $betaCheckStmt->close();
    }
}

// Beta users get 500MB regardless of tier
if ($betaAccess) {
    $max_storage_size = 500 * 1024 * 1024; // 500MB for Beta users
} else {
    // Check tier for non-beta users
    $tier = $_SESSION['tier'] ?? "None";
    // If tier is not set or None, check subscription via API
    if ($tier === "None" || empty($tier)) {
        // Make internal request to check subscription
        $checkUrl = "https://" . $_SERVER['HTTP_HOST'] . "/check_subscription.php";
        $ch = curl_init($checkUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $subResponse = curl_exec($ch);
        curl_close($ch);
        if ($subResponse !== false) {
            $subData = json_decode($subResponse, true);
            if (isset($subData['tier'])) {
                $tier = $subData['tier'];
                $_SESSION['tier'] = $tier;
            }
        }
    }
    switch ($tier) {
        case "1000":
            $max_storage_size = 50 * 1024 * 1024; // 50MB
            break;
        case "2000":
            $max_storage_size = 100 * 1024 * 1024; // 100MB
            break;
        case "3000":
            $max_storage_size = 200 * 1024 * 1024; // 200MB
            break;
        case "4000":
            $max_storage_size = 500 * 1024 * 1024; // 500MB
            break;
        default:
            $max_storage_size = 20 * 1024 * 1024; // 20MB (FREE)
            break;
    }
}

// Helper function to ensure directory is writable
function ensureDirectoryWritable($path) {
    if (!is_dir($path)) {
        if (!mkdir($path, 0755, true)) {
            error_log("Failed to create directory: $path");
            return false;
        }
    }
    // Fix permissions if directory exists but isn't writable
    if (!is_writable($path)) {
        if (!chmod($path, 0755)) {
            error_log("Failed to chmod directory: $path");
            return false;
        }
    }
    return true;
}

// Create and fix permissions for user directories
ensureDirectoryWritable($walkon_path);
ensureDirectoryWritable($soundalert_path);
ensureDirectoryWritable($videoalert_path);
ensureDirectoryWritable($twitch_sound_alert_path);
// Ensure per-user music folder exists and is writable
ensureDirectoryWritable($user_music_path);

// Public user-music folder served by music.botspecter.com — mirror/symlink target for uploaded files
$public_user_music_path = "/var/www/usermusic/" . $username;
ensureDirectoryWritable($public_user_music_path);

// Function to calculate the total size of files in specified directories
function calculateStorageUsed($directories) {
    $totalSize = 0;
    foreach ($directories as $directory) {
        if (is_dir($directory)) {
            $files = scandir($directory);
            foreach ($files as $file) {
                if ($file != "." && $file != "..") {
                    $path = $directory . '/' . $file;
                    if (is_file($path)) {
                        $totalSize += filesize($path);
                    } elseif (is_dir($path) && $file != "twitch") {
                        $subDirFiles = scandir($path);
                        foreach ($subDirFiles as $subFile) {
                            if ($subFile != "." && $subFile != "..") {
                                $subPath = $path . '/' . $subFile;
                                if (is_file($subPath)) {
                                    $totalSize += filesize($subPath);
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    return $totalSize;
}

// Calculate the current storage used directly from directories (includes user uploads)
$current_storage_used = calculateStorageUsed([$walkon_path, $soundalert_path, $videoalert_path, $twitch_sound_alert_path, $user_music_path]);

// Calculate percentage for progress bar
$storage_percentage = ($current_storage_used / $max_storage_size) * 100;
?>