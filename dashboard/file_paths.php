<?php
// Define base paths for uploads
$base_upload_path = "/var/www/soundalerts/";
$soundalert_path = "/var/www/soundalerts/" . $_SESSION['username'];

// Sound alerts path for this user
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? 'unknown';
$twitch_sound_alert_path = $base_upload_path . $username . "/twitch";

// Create base directory if it doesn't exist
if (!is_dir($base_upload_path)) {
    mkdir($base_upload_path, 0755, true);
}
// Create user's sound alerts directory if it doesn't exist
if (!is_dir($soundalert_path)) {
    mkdir($soundalert_path, 0755, true);
}
// Create twitch sound alert path
if (!is_dir($twitch_sound_alert_path)) {
    mkdir($twitch_sound_alert_path, 0755, true);
}
// Set proper permissions
chmod($base_upload_path, 0755);
chmod($soundalert_path, 0755);
chmod($twitch_sound_alert_path, 0755);
?>