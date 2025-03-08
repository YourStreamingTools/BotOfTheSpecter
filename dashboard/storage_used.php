<?php
// Define user-specific storage limits
$base_storage_size = 2 * 1024 * 1024; // 2MB in bytes
$tier = $_SESSION['tier'] ?? "None";

switch ($tier) {
    case "1000":
        $max_storage_size = 5 * 1024 * 1024; // 5MB
        break;
    case "2000":
        $max_storage_size = 10 * 1024 * 1024; // 10MB
        break;
    case "3000":
        $max_storage_size = 20 * 1024 * 1024; // 20MB
        break;
    case "4000":
        $max_storage_size = 50 * 1024 * 1024; // 50MB
        break;
    default:
        $max_storage_size = $base_storage_size; // Default 2MB
        break;
}

// Define the user's directories
$walkon_path = "/var/www/walkons/" . $username;
$soundalert_path = "/var/www/soundalerts/" . $username;
$videoalert_path = "/var/www/videoalerts/" . $username;

// Create the user's directory if it doesn't exist
if (!is_dir($walkon_path)) {
    if (!mkdir($walkon_path, 0755, true)) {
        exit("Failed to create directory.");
    }
}

if (!is_dir($soundalert_path)) {
    if (!mkdir($soundalert_path, 0755, true)) {
        exit("Failed to create directory.");
    }
}

if (!is_dir($videoalert_path)) {
    if (!mkdir($videoalert_path, 0755, true)) {
        exit("Failed to create directory.");
    }
}

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
?>