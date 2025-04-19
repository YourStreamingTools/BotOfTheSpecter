<?php
include 'file_paths.php';

// Define the user's directories
$walkon_path = "/var/www/walkons/" . $username;
$soundalert_path = "/var/www/soundalerts/" . $username;
$videoalert_path = "/var/www/videoalerts/" . $username;
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

// Create the user's walkon directory if it doesn't exist
if (!is_dir($walkon_path)) {
    if (!mkdir($walkon_path, 0755, true)) {
        exit("Failed to create directory.");
    }
}

// Create the user's sound alerts directory if it doesn't exist
if (!is_dir($soundalert_path)) {
    if (!mkdir($soundalert_path, 0755, true)) {
        exit("Failed to create directory.");
    }
}

// Create the user's video alerts directory if it doesn't exist
if (!is_dir($videoalert_path)) {
    if (!mkdir($videoalert_path, 0755, true)) {
        exit("Failed to create directory.");
    }
}

// Create the user's Twitch sound alerts directory if it doesn't exist
if (!is_dir($twitch_sound_alert_path)) {
    if (!mkdir($twitch_sound_alert_path, 0755, true)) {
        exit("Failed to create directory.");
    }
}

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

// Calculate the current storage used directly from directories
$current_storage_used = calculateStorageUsed([$walkon_path, $soundalert_path, $videoalert_path, $twitch_sound_alert_path]);

// Calculate percentage for progress bar
$storage_percentage = ($current_storage_used / $max_storage_size) * 100;
?>