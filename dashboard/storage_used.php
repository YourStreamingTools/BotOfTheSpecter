<?php
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