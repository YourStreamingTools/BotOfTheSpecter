<?php
// Define the user's directories
$walkon_path = "/var/www/walkons/" . $_SESSION['editing_username'];;
$soundalert_path = "/var/www/soundalerts/" . $_SESSION['editing_username'];;
$videoalert_path = "/var/www/videoalerts/" . $_SESSION['editing_username'];;
$twitch_sound_alert_path = $soundalert_path . "/twitch";

// Define user-specific storage limits
$base_storage_size = 20 * 1024 * 1024; // 20MB in bytes (FREE)
$tier = $_SESSION['tier'] ?? "None";

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

// Calculate total storage used by the user across both directories
function calculateStorageUsed($directories) {
    $size = 0;
    foreach ($directories as $directory) {
        foreach (glob(rtrim($directory, '/').'/*', GLOB_NOSORT) as $file) {
            $size += is_file($file) ? filesize($file) : calculateStorageUsed([$file]);
        }
    }
    return $size;
}

$current_storage_used = calculateStorageUsed([$walkon_path, $soundalert_path, $videoalert_path]);
$storage_percentage = ($current_storage_used / $max_storage_size) * 100;
// No database access here, so just keep as is for MySQLi context
?>