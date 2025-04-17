<?php
// Initialize the session
session_start();

// Include the database credentials
require_once "/var/www/config/database.php";

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    error_log("User not logged in when accessing module_data_post.php");
    header("Location: login.php");
    exit();
}

$db_name = $_SESSION['username'];

// Create database connection using mysqli with credentials from database.php
$db = new mysqli($db_servername, $db_username, $db_password, $db_name);

// Check connection
if ($db->connect_error) {
    error_log("Connection failed: " . $db->connect_error);
    die("Database connection failed. Please check the configuration.");
}

// Process POST requests
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Handle Joke Blacklist Update
    if (isset($_POST['blacklist'])) {
        $new_blacklist = isset($_POST['blacklist']) ? $_POST['blacklist'] : [];
        $new_blacklist_json = json_encode($new_blacklist);
        // Update the blacklist in the database
        $update_sql = "UPDATE joke_settings SET blacklist = ? WHERE id = 1";
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bind_param("s", $new_blacklist_json);
        $update_stmt->execute();
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
                send_welcome_messages = ?, 
                default_welcome_message = ?,
                default_vip_welcome_message = ?,
                default_mod_welcome_message = ?,
                new_default_welcome_message = ?,
                new_default_vip_welcome_message = ?,
                new_default_mod_welcome_message = ?";
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bind_param('issssssissssss', 
            $send_welcome_messages, 
            $default_welcome_message, 
            $default_vip_welcome_message, 
            $default_mod_welcome_message, 
            $new_default_welcome_message, 
            $new_default_vip_welcome_message, 
            $new_default_mod_welcome_message,
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
    // Handle channel point reward mapping
    elseif (isset($_POST['sound_file'], $_POST['twitch_alert_id'])) {
        $status = "";
        $soundFile = htmlspecialchars($_POST['sound_file']);
        $rewardId = htmlspecialchars($_POST['twitch_alert_id']);
        
        // Validate that the twitch_alert_id is one of our allowed events
        $validEvents = ['Follow', 'Raid', 'Cheer', 'Subscription', 'Gift Subscription', 'HypeTrain Start', 'HypeTrain End', ''];
        
        if (!in_array($rewardId, $validEvents) && $rewardId !== '') {
            $status .= "Invalid event type selected.<br>";
            $_SESSION['update_message'] = $status;
            header("Location: modules.php");
            exit();
        }
        
        $db->begin_transaction();
        
        // Check if a mapping already exists for this sound file
        $checkExisting = $db->prepare("SELECT 1 FROM twitch_sound_alerts WHERE sound_mapping = ?");
        $checkExisting->bind_param('s', $soundFile);
        $checkExisting->execute();
        $result = $checkExisting->get_result();
        
        if ($result->num_rows > 0) {
            // Update existing mapping
            if ($rewardId) {
                $updateMapping = $db->prepare("UPDATE twitch_sound_alerts SET twitch_alert_id = ? WHERE sound_mapping = ?");
                $updateMapping->bind_param('ss', $rewardId, $soundFile);
                if (!$updateMapping->execute()) {
                    $status .= "Failed to update mapping for file '" . $soundFile . "'. Database error: " . $db->error . "<br>"; 
                } else {
                    $status .= "Mapping for file '" . $soundFile . "' has been updated successfully.<br>";
                }
            } else {
                // Clear the mapping if no reward is selected
                $clearMapping = $db->prepare("UPDATE twitch_sound_alerts SET twitch_alert_id = NULL WHERE sound_mapping = ?");
                $clearMapping->bind_param('s', $soundFile);
                if (!$clearMapping->execute()) {
                    $status .= "Failed to clear mapping for file '" . $soundFile . "'. Database error: " . $db->error . "<br>"; 
                } else {
                    $status .= "Mapping for file '" . $soundFile . "' has been cleared.<br>";
                }
            }
        } else {
            // Create a new mapping if it doesn't exist
            if ($rewardId) {
                $insertMapping = $db->prepare("INSERT INTO twitch_sound_alerts (sound_mapping, twitch_alert_id) VALUES (?, ?)");
                $insertMapping->bind_param('ss', $soundFile, $rewardId);
                if (!$insertMapping->execute()) {
                    $status .= "Failed to create mapping for file '" . $soundFile . "'. Database error: " . $db->error . "<br>"; 
                } else {
                    $status .= "Mapping for file '" . $soundFile . "' has been created successfully.<br>";
                }
            } 
        }
        
        // Commit transaction
        $db->commit();
        $_SESSION['update_message'] = $status;
    }
    // Handle Ad Notices Update
    elseif (isset($_POST['ad_start_message'])) {
        $ad_upcoming_message = $_POST['ad_upcoming_message'];
        $ad_start_message = $_POST['ad_start_message'];
        $ad_end_message = $_POST['ad_end_message'];
        $enable_ad_notice = isset($_POST['enable_ad_notice']) ? 1 : 0;
        $update_sql = "INSERT INTO ad_notice_settings 
            (id, ad_upcoming_message, ad_start_message, ad_end_message, enable_ad_notice)
            VALUES (1, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                ad_upcoming_message = ?,
                ad_start_message = ?,
                ad_end_message = ?,
                enable_ad_notice = ?";
                
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bind_param('sssisssi', 
            $ad_upcoming_message, 
            $ad_start_message, 
            $ad_end_message, 
            $enable_ad_notice,
            $ad_upcoming_message, 
            $ad_start_message, 
            $ad_end_message, 
            $enable_ad_notice
        );
        $update_stmt->execute();
        $update_stmt->close();
        $_SESSION['update_message'] = "Ad notice settings updated successfully.";
    }

    // Handle file upload
    if (isset($_FILES["filesToUpload"])) {
        $status = "";
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
        $_SESSION['update_message'] = $status;
    }

    // Handle file deletion
    if (isset($_POST['delete_files'])) {
        $status = "";
        $deletedFiles = [];
        foreach ($_POST['delete_files'] as $file_to_delete) {
            $file_basename = basename($file_to_delete);
            $file_path = $twitch_sound_alert_path . '/' . $file_basename;
            // Check if file exists
            if (!file_exists($file_path)) {
                $status .= "Failed to delete " . htmlspecialchars($file_basename) . " - File does not exist.<br>";
                continue;
            }
            // Check file permissions
            if (!is_writable($file_path)) {
                $status .= "Failed to delete " . htmlspecialchars($file_basename) . " - Permission denied.<br>";
                // Try to fix permissions
                chmod($file_path, 0644);
                continue;
            }
            // Attempt to delete the file with detailed error handling
            if (unlink($file_path)) {
                $deletedFiles[] = $file_basename;
                $status .= "The file " . htmlspecialchars($file_basename) . " has been deleted.<br>";
            } else {
                $error_message = error_get_last();
                $status .= "Failed to delete " . htmlspecialchars($file_basename) . " - ";
                $status .= $error_message ? htmlspecialchars($error_message['message']) : "Unknown error";
                $status .= "<br>";
                
                // Log the error
                error_log("Failed to delete file: $file_path - " . ($error_message ? $error_message['message'] : "Unknown error"));
            }
        }
        // Clean up database entries for deleted files
        if (!empty($deletedFiles)) {
            // Use prepared statement with multiple values
            $placeholders = str_repeat('?,', count($deletedFiles) - 1) . '?';
            $removeMapping = $db->prepare("DELETE FROM twitch_sound_alerts WHERE sound_mapping IN ($placeholders)");
            // Dynamically bind parameters
            $types = str_repeat('s', count($deletedFiles)); // 's' for each string
            $removeMapping->bind_param($types, ...$deletedFiles);
            if ($removeMapping->execute()) {
                $affected = $removeMapping->affected_rows;
                if ($affected > 0) {
                    $status .= "Removed $affected database mappings for deleted files.<br>";
                }
            } else {
                $status .= "Warning: Failed to clean up database mappings for deleted files. Error: " . $db->error . "<br>";
            }
        }
        $_SESSION['update_message'] = $status;
        // If this is an AJAX request, return JSON response
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => $status]);
            exit;
        }
    }
    // If this is an AJAX request, return JSON response
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $_SESSION['update_message'] ?? 'Operation completed successfully']);
        exit;
    }
    // Otherwise redirect back to the modules page
    header("Location: modules.php");
    exit();
}
?>