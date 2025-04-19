<?php
// Define base paths for uploads
$base_upload_path = "/var/www/soundalerts/";

// Sound alerts path for this user
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? 'unknown';
$twitch_sound_alert_path = $base_upload_path . "/" . $username . "/twitch";

// Ensure upload directories exist with proper permissions
function ensure_upload_directories_exist() {
    global $base_upload_path, $twitch_sound_alert_path;
    // Check base upload path
    if (!is_dir($base_upload_path)) {
        mkdir($base_upload_path, 0755, true);
    }
    // Check user sound alert path
    if (!is_dir($twitch_sound_alert_path)) {
        mkdir($twitch_sound_alert_path, 0755, true);
    }
    // Set proper permissions
    chmod($base_upload_path, 0755);
    chmod($twitch_sound_alert_path, 0755);
}
?>