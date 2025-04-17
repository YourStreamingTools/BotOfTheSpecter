<?php
// Include the database credentials
require_once "/var/www/config/database.php";

$db_name = $_SESSION['username'];

// Create database connection using mysqli with credentials from database.php
$db = new mysqli($db_servername, $db_username, $db_password, $db_name);

// Check connection
if ($db->connect_error) {
    error_log("Connection failed: " . $db->connect_error);
    die("Database connection failed. Please check the configuration.");
}

// Fetch the current blacklist settings
$current_blacklist = [];
$sql = "SELECT blacklist FROM joke_settings WHERE id = 1";
$result = $db->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    if ($row) {
        $current_blacklist = json_decode($row['blacklist'], true);
    }
    $result->free();
}

// Fetch the current settings from the database each time the page loads
$fetch_sql = "SELECT send_welcome_messages, default_welcome_message, default_vip_welcome_message, default_mod_welcome_message FROM streamer_preferences WHERE id = 1";
$fetch_result = $db->query($fetch_sql);
$preferences = $fetch_result ? $fetch_result->fetch_assoc() : null;
if ($fetch_result) {
    $fetch_result->free();
}

// Set default values if no settings exist in the database
$send_welcome_messages = isset($preferences['send_welcome_messages']) ? $preferences['send_welcome_messages'] : 1;
$default_welcome_message = isset($preferences['default_welcome_message']) ? $preferences['default_welcome_message'] : "Welcome back (user), glad to see you again!";
$new_default_welcome_message = isset($preferences['new_default_welcome_message']) ? $preferences['new_default_welcome_message'] : "(user) is new to the community, let's give them a warm welcome!";
$default_vip_welcome_message = isset($preferences['default_vip_welcome_message']) ? $preferences['default_vip_welcome_message'] : "ATTENTION! A very important person has entered the chat, welcome (user)";
$new_default_vip_welcome_message = isset($preferences['new_default_vip_welcome_message']) ? $preferences['new_default_vip_welcome_message'] : "ATTENTION! A very important person has entered the chat, welcome (user)";
$default_mod_welcome_message = isset($preferences['default_mod_welcome_message']) ? $preferences['default_mod_welcome_message'] : "MOD ON DUTY! Welcome in (user), the power of the sword has increased!";
$new_default_mod_welcome_message = isset($preferences['new_default_mod_welcome_message']) ? $preferences['new_default_mod_welcome_message'] : "MOD ON DUTY! Welcome in (user), the power of the sword has increased!";

// Fetch ad notice settings from the database
$stmt = $db->prepare("SELECT ad_upcoming_message, ad_start_message, ad_end_message, enable_ad_notice FROM ad_notice_settings WHERE id = 1");
$stmt->execute();
$stmt->bind_result($ad_upcoming_message_db, $ad_start_message_db, $ad_end_message_db, $enable_ad_notice);
$stmt->fetch();
$stmt->close();

// Default ad notice messages
$default_ad_upcoming_message = "Ads will be starting in (minutes).";
$default_ad_start_message = "Ads are running for (duration). We'll be right back after these ads.";
$default_ad_end_message = "Thanks for sticking with us through the ads! Welcome back, everyone!";

if ($ad_upcoming_message_db !== null) {
    $ad_upcoming_message = !empty($ad_upcoming_message_db) ? $ad_upcoming_message_db : $default_ad_upcoming_message;
    $ad_start_message = !empty($ad_start_message_db) ? $ad_start_message_db : $default_ad_start_message;
    $ad_end_message = !empty($ad_end_message_db) ? $ad_end_message_db : $default_ad_end_message;
} else {
    $ad_upcoming_message = $default_ad_upcoming_message;
    $ad_start_message = $default_ad_start_message;
    $ad_end_message = $default_ad_end_message;
    $enable_ad_notice = 1;
}

// Define empty variables
$status = '';

// Fetch sound alert mappings for the current user
$getTwitchAlerts = $db->prepare("SELECT sound_mapping, twitch_alert_id FROM twitch_sound_alerts");
$getTwitchAlerts->execute();
$result = $getTwitchAlerts->get_result();
$soundAlerts = [];
while ($row = $result->fetch_assoc()) {
    $soundAlerts[] = $row;
}
$getTwitchAlerts->close();

// Create an associative array for easy lookup: sound_mapping => twitch_alert_id
$twitchSoundAlertMappings = [];
foreach ($soundAlerts as $alert) {
    $twitchSoundAlertMappings[$alert['sound_mapping']] = $alert['twitch_alert_id'];
}

// Get the sound files
$soundalert_files = array_diff(scandir($twitch_sound_alert_path), array('.', '..'));
function formatFileName($fileName) { return basename($fileName, '.mp3'); }
?>