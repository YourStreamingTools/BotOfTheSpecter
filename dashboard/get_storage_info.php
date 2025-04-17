<?php
// Initialize the session
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    error_log("User not logged in when accessing get_storage_info.php");
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];

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

// Calculate total storage used by the user across directories
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

// Format values for display
$used_mb = round($current_storage_used / 1024 / 1024, 2);
$max_mb = round($max_storage_size / 1024 / 1024, 2);
$percentage = round($storage_percentage, 2);

// Return JSON response
echo json_encode([
    'used' => $used_mb . 'MB',
    'max' => $max_mb . 'MB',
    'percentage' => $percentage
]);
?>