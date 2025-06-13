<?php
// Fetch the current blacklist settings
$sql = "SELECT blacklist FROM joke_settings WHERE id = 1";
$stmt = $db->prepare($sql);
$stmt->execute();
$stmt->bind_result($blacklist_json);
if ($stmt->fetch()) {
    $current_blacklist = json_decode($blacklist_json, true);
}
$stmt->close();

// Fetch the current settings from the database each time the page loads
$fetch_sql = "SELECT send_welcome_messages, default_welcome_message, default_vip_welcome_message, default_mod_welcome_message, new_default_welcome_message, new_default_vip_welcome_message, new_default_mod_welcome_message FROM streamer_preferences WHERE id = 1";
$fetch_stmt = $db->prepare($fetch_sql);
$fetch_stmt->execute();
$fetch_stmt->bind_result($send_welcome_messages_db, $default_welcome_message_db, $default_vip_welcome_message_db, $default_mod_welcome_message_db, $new_default_welcome_message_db, $new_default_vip_welcome_message_db, $new_default_mod_welcome_message_db);
$preferences = [];
if ($fetch_stmt->fetch()) {
    $preferences = [
        'send_welcome_messages' => $send_welcome_messages_db,
        'default_welcome_message' => $default_welcome_message_db,
        'default_vip_welcome_message' => $default_vip_welcome_message_db,
        'default_mod_welcome_message' => $default_mod_welcome_message_db,
        'new_default_welcome_message' => $new_default_welcome_message_db,
        'new_default_vip_welcome_message' => $new_default_vip_welcome_message_db,
        'new_default_mod_welcome_message' => $new_default_mod_welcome_message_db
    ];
}
$fetch_stmt->close();

// Set default values if no settings exist in the database
$send_welcome_messages = isset($preferences['send_welcome_messages']) ? $preferences['send_welcome_messages'] : 1;
$default_welcome_message = isset($preferences['default_welcome_message']) ? $preferences['default_welcome_message'] : "Welcome back (user), glad to see you again!";
$new_default_welcome_message = isset($preferences['new_default_welcome_message']) ? $preferences['new_default_welcome_message'] : "(user) is new to the community, let's give them a warm welcome!";
$default_vip_welcome_message = isset($preferences['default_vip_welcome_message']) ? $preferences['default_vip_welcome_message'] : "ATTENTION! A very important person has entered the chat, welcome (user)";
$new_default_vip_welcome_message = isset($preferences['new_default_vip_welcome_message']) ? $preferences['new_default_vip_welcome_message'] : "ATTENTION! A very important person has entered the chat, welcome (user)";
$default_mod_welcome_message = isset($preferences['default_mod_welcome_message']) ? $preferences['default_mod_welcome_message'] : "MOD ON DUTY! Welcome in (user), the power of the sword has increased!";
$new_default_mod_welcome_message = isset($preferences['new_default_mod_welcome_message']) ? $preferences['new_default_mod_welcome_message'] : "MOD ON DUTY! Welcome in (user), the power of the sword has increased!";

// Fetch ad notice settings from the database
$stmt = $db->prepare("SELECT ad_start_message, ad_end_message, enable_ad_notice FROM ad_notice_settings WHERE id = 1");
$stmt->execute();
$stmt->bind_result($ad_start_message_db, $ad_end_message_db, $enable_ad_notice_db);
if ($stmt->fetch()) {
    $ad_start_message = $ad_start_message_db;
    $ad_end_message = $ad_end_message_db;
    $enable_ad_notice = (int)$enable_ad_notice_db;
} else {
    $ad_start_message = "Ads are running for (duration). We'll be right back after these ads.";
    $ad_end_message = "Thanks for sticking with us through the ads! Welcome back, everyone!";
    $enable_ad_notice = 1;
}
$stmt->close();

// If form is submitted, update the blacklist
$update_success = false;
$update_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Handle Joke Blacklist Update
    if (isset($_POST['blacklist'])) {
        $new_blacklist = isset($_POST['blacklist']) ? $_POST['blacklist'] : [];
        $new_blacklist_json = json_encode($new_blacklist);
        // Update the blacklist in the database
        $update_sql = "UPDATE joke_settings SET blacklist = ? WHERE id = 1";
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bind_param('s', $new_blacklist_json);
        $update_stmt->execute();
        $update_stmt->close();
        // Set success message for blacklist update in session
        $_SESSION['update_message'] = "Blacklist settings updated successfully.";
    }
    // Handle Welcome Message Settings Update
    elseif (isset($_POST['send_welcome_messages'])) {
        // Gather and save the updated welcome message data
        $send_welcome_messages = isset($_POST['send_welcome_messages']) ? 1 : 0;
        // Existing welcome messages
        $default_welcome_message = isset($_POST['default_welcome_message']) ? $_POST['default_welcome_message'] : '';
        $default_vip_welcome_message = isset($_POST['default_vip_welcome_message']) ? $_POST['default_vip_welcome_message'] : '';
        $default_mod_welcome_message = isset($_POST['default_mod_welcome_message']) ? $_POST['default_mod_welcome_message'] : '';
        // New welcome messages
        $new_default_welcome_message = isset($_POST['new_default_welcome_message']) ? $_POST['new_default_welcome_message'] : '';
        $new_default_vip_welcome_message = isset($_POST['new_default_vip_welcome_message']) ? $_POST['new_default_vip_welcome_message'] : '';
        $new_default_mod_welcome_message = isset($_POST['new_default_mod_welcome_message']) ? $_POST['new_default_mod_welcome_message'] : '';
        // Update the streamer_preferences in the database
        $update_sql = "
            INSERT INTO streamer_preferences 
            (id, send_welcome_messages, default_welcome_message, default_vip_welcome_message, default_mod_welcome_message, 
                new_default_welcome_message, new_default_vip_welcome_message, new_default_mod_welcome_message)
            VALUES 
            (1, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                send_welcome_messages = VALUES(send_welcome_messages), 
                default_welcome_message = VALUES(default_welcome_message),
                default_vip_welcome_message = VALUES(default_vip_welcome_message),
                default_mod_welcome_message = VALUES(default_mod_welcome_message),
                new_default_welcome_message = VALUES(new_default_welcome_message),
                new_default_vip_welcome_message = VALUES(new_default_vip_welcome_message),
                new_default_mod_welcome_message = VALUES(new_default_mod_welcome_message)";
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bind_param(
            'issssss',
            $send_welcome_messages,
            $default_welcome_message,
            $default_vip_welcome_message,
            $default_mod_welcome_message,
            $new_default_welcome_message,
            $new_default_vip_welcome_message,
            $new_default_mod_welcome_message
        );
        $update_stmt->execute();
        $update_stmt->close();
        // Set success message for welcome messages update in session
        $_SESSION['update_message'] = "Welcome message settings updated successfully.";
    }    
    // NEW: Move channel point reward mapping here
    elseif (isset($_POST['sound_file'], $_POST['twitch_alert_id'])) {
        $status = "";
        $soundFile = htmlspecialchars($_POST['sound_file']);
        $rewardId = htmlspecialchars($_POST['twitch_alert_id']);
        $db->begin_transaction();
        // Check if a mapping already exists for this sound file
        $checkExisting = $db->prepare("SELECT 1 FROM twitch_sound_alerts WHERE sound_mapping = ?");
        $checkExisting->bind_param('s', $soundFile);
        $checkExisting->execute();
        $checkExisting->store_result();
        if ($checkExisting->num_rows > 0) {
            // Update existing mapping
            if ($rewardId) {
                $updateMapping = $db->prepare("UPDATE twitch_sound_alerts SET twitch_alert_id = ? WHERE sound_mapping = ?");
                $updateMapping->bind_param('ss', $rewardId, $soundFile);
                if (!$updateMapping->execute()) {
                    $status .= "Failed to update mapping for file '" . $soundFile . "'. Database error: " . print_r($updateMapping->error_list, true) . "<br>"; 
                } else {
                    $status .= "Mapping for file '" . $soundFile . "' has been updated successfully.<br>";
                }
                $updateMapping->close();
            } else {
                // Clear the mapping if no reward is selected
                $clearMapping = $db->prepare("UPDATE twitch_sound_alerts SET twitch_alert_id = NULL WHERE sound_mapping = ?");
                $clearMapping->bind_param('s', $soundFile);
                if (!$clearMapping->execute()) {
                    $status .= "Failed to clear mapping for file '" . $soundFile . "'. Database error: " . print_r($clearMapping->error_list, true) . "<br>"; 
                } else {
                    $status .= "Mapping for file '" . $soundFile . "' has been cleared.<br>";
                }
                $clearMapping->close();
            }
        } else {
            // Create a new mapping if it doesn't exist
            if ($rewardId) {
                $insertMapping = $db->prepare("INSERT INTO twitch_sound_alerts (sound_mapping, twitch_alert_id) VALUES (?, ?)");
                $insertMapping->bind_param('ss', $soundFile, $rewardId);
                if (!$insertMapping->execute()) {
                    $status .= "Failed to create mapping for file '" . $soundFile . "'. Database error: " . print_r($insertMapping->error_list, true) . "<br>"; 
                } else {
                    $status .= "Mapping for file '" . $soundFile . "' has been created successfully.<br>";
                }
                $insertMapping->close();
            } 
        }
        $checkExisting->close();
        // Commit transaction
        $db->commit();
        $_SESSION['update_message'] = $status;
    }
    // Handle Ad Notices Update
    elseif (isset($_POST['ad_start_message'])) {
        $ad_start_message = $_POST['ad_start_message'];
        $ad_end_message = $_POST['ad_end_message'];
        $enable_ad_notice = isset($_POST['enable_ad_notice']) ? 1 : 0;
        $update_sql = "
            INSERT INTO ad_notice_settings 
            (id, ad_start_message, ad_end_message, enable_ad_notice)
            VALUES 
            (1, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                ad_start_message = VALUES(ad_start_message),
                ad_end_message = VALUES(ad_end_message),
                enable_ad_notice = VALUES(enable_ad_notice)";
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bind_param('ssi', $ad_start_message, $ad_end_message, $enable_ad_notice);
        $update_stmt->execute();
        $update_stmt->close();
        $_SESSION['update_message'] = "Ad notice settings updated successfully.";
    }
    // Refresh the page to show updated settings
    header("Location: " . $_SERVER['PHP_SELF']);
}

// Define empty variables
$status = '';

// Fetch sound alert mappings for the current user
$getTwitchAlerts = $db->prepare("SELECT sound_mapping, twitch_alert_id FROM twitch_sound_alerts");
$getTwitchAlerts->execute();
$getTwitchAlerts->bind_result($sound_mapping, $twitch_alert_id);
$soundAlerts = [];
while ($getTwitchAlerts->fetch()) {
    $soundAlerts[] = [
        'sound_mapping' => $sound_mapping,
        'twitch_alert_id' => $twitch_alert_id
    ];
}
$getTwitchAlerts->close();

// Create an associative array for easy lookup: sound_mapping => twitch_alert_id
$twitchSoundAlertMappings = [];
foreach ($soundAlerts as $alert) {
    $twitchSoundAlertMappings[$alert['sound_mapping']] = $alert['twitch_alert_id'];
}

$remaining_storage = $max_storage_size - $current_storage_used;
$max_upload_size = $remaining_storage;
// ini_set('upload_max_filesize', $max_upload_size);
// ini_set('post_max_size', $max_upload_size);

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES["filesToUpload"])) {
    foreach ($_FILES["filesToUpload"]["tmp_name"] as $key => $tmp_name) {
        $fileSize = $_FILES["filesToUpload"]["size"][$key];
        if ($current_storage_used + $fileSize > $max_storage_size) {
            $status .= "Failed to upload " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ". Storage limit exceeded.<br>";
            continue;
        }
        $cleanPath = rtrim($twitch_sound_alert_path, '/\\');
        $targetFile = $cleanPath . DIRECTORY_SEPARATOR . basename($_FILES["filesToUpload"]["name"][$key]);
        $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
        if ($fileType != "mp3") {
            $status .= "Failed to upload " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ". Only MP3 files are allowed.<br>";
            continue;
        }
        if (move_uploaded_file($tmp_name, $targetFile)) {
            $current_storage_used += $fileSize;
            $status .= "The file " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . " has been uploaded.<br>";
        } else {
            $error = error_get_last();
            $status .= "Sorry, there was an error uploading " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ".<br>Error details: " . print_r($error, true) . "<br>";
        }
    }
}

// Handle file deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_files'])) {
    foreach ($_POST['delete_files'] as $file_to_delete) {
        $file_to_delete = $twitch_sound_alert_path . '/' . basename($file_to_delete);
        if (is_file($file_to_delete) && unlink($file_to_delete)) {
            $status .= "The file " . htmlspecialchars(basename($file_to_delete)) . " has been deleted.<br>";
        } else {
            $status .= "Failed to delete " . htmlspecialchars(basename($file_to_delete)) . ".<br>";
        }
    }
}

$soundalert_files = array_diff(scandir($twitch_sound_alert_path), array('.', '..'));
function formatFileName($fileName) { return basename($fileName, '.mp3'); }
?>