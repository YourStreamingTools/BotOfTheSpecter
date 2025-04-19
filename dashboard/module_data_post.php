<?php
// Initialize the session if not already
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include the database credentials
require_once "/var/www/config/database.php";
require_once "/var/www/config/db_connect.php";
include 'user_db.php';
include 'file_paths.php';

$db_name = isset($_SESSION['username']) ? $_SESSION['username'] : null;

if (!$db_name) {
    header('Location: login.php');
    exit();
}

// Create database connection using mysqli with credentials from database.php
$db = new mysqli($db_servername, $db_username, $db_password, $db_name);

// Check connection
if ($db->connect_error) {
    error_log("Connection failed: " . $db->connect_error);
    die("Database connection failed. Please check the configuration.");
}

// Initialize the active tab variable
$activeTab = "joke-blacklist"; // Default tab

// Process POST requests
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Determine which tab to return to based on POST data
    if (isset($_POST['blacklist'])) {
        $activeTab = "joke-blacklist";
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
        $activeTab = "welcome-messages";
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
    // Handle channel point reward mapping for twitch sound alerts
    elseif (isset($_POST['sound_file']) && isset($_POST['twitch_alert_id'])) {
        $activeTab = "twitch-audio-alerts";
        $status = "";
        $soundFile = htmlspecialchars($_POST['sound_file']);
        $rewardId = isset($_POST['twitch_alert_id']) ? htmlspecialchars($_POST['twitch_alert_id']) : '';
        
        $db->begin_transaction();
        // Check if a mapping already exists for this sound file
        $checkExisting = $db->prepare("SELECT 1 FROM twitch_sound_alerts WHERE sound_mapping = ?");
        $checkExisting->bind_param('s', $soundFile);
        $checkExisting->execute();
        $result = $checkExisting->get_result();
        
        if ($result->num_rows > 0) {
            // Update existing mapping
            if (!empty($rewardId)) {
                $updateMapping = $db->prepare("UPDATE twitch_sound_alerts SET twitch_alert_id = ? WHERE sound_mapping = ?");
                $updateMapping->bind_param('ss', $rewardId, $soundFile);
                if (!$updateMapping->execute()) {
                    $status .= "Failed to update mapping for file '" . $soundFile . "'. Database error: " . $db->error . "<br>"; 
                } else {
                    $status .= "Mapping for file '" . $soundFile . "' has been updated successfully.<br>";
                }
            } else {
                // Delete the mapping if no reward is selected
                $deleteMapping = $db->prepare("DELETE FROM twitch_sound_alerts WHERE sound_mapping = ?");
                $deleteMapping->bind_param('s', $soundFile);
                if (!$deleteMapping->execute()) {
                    $status .= "Failed to remove mapping for file '" . $soundFile . "'. Database error: " . $db->error . "<br>"; 
                } else {
                    $status .= "Mapping for file '" . $soundFile . "' has been removed.<br>";
                }
            }
        } else {
            // Create a new mapping if it doesn't exist
            if (!empty($rewardId)) {
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
        $activeTab = "ad-notices";
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
    // Handle chat alerts settings update
    elseif (isset($_POST['follower_alert'])) {
        $activeTab = "twitch-chat-alerts";
        // Handle twitch chat alerts settings
    }
    
    // Handle file upload for Twitch Sound Alerts
    if (isset($_FILES["filesToUpload"]) && is_array($_FILES["filesToUpload"]["tmp_name"])) {
        $activeTab = "twitch-audio-alerts";
        $status = "";
        
        // Ensure directory exists
        if (!is_dir($twitch_sound_alert_path)) {
            mkdir($twitch_sound_alert_path, 0755, true);
        }
        
        foreach ($_FILES["filesToUpload"]["tmp_name"] as $key => $tmp_name) {
            if (empty($tmp_name)) continue;
            
            $fileName = $_FILES["filesToUpload"]["name"][$key];
            $fileSize = $_FILES["filesToUpload"]["size"][$key];
            $fileError = $_FILES["filesToUpload"]["error"][$key];
            
            // Check if file size exceeds storage limit
            if ($current_storage_used + $fileSize > $max_storage_size) {
                $status .= "Failed to upload " . htmlspecialchars($fileName) . ". Storage limit exceeded.<br>";
                continue;
            }
            
            // Verify file is an MP3
            $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if ($fileType != "mp3") {
                $status .= "Failed to upload " . htmlspecialchars($fileName) . ". Only MP3 files are allowed.<br>";
                continue;
            }
            
            // Check for upload errors
            if ($fileError !== 0) {
                $status .= "Error uploading " . htmlspecialchars($fileName) . ". Error code: $fileError<br>";
                continue;
            }
            
            // Full path for the destination file
            $targetFile = $twitch_sound_alert_path . '/' . basename($fileName);
            
            // Move file to destination
            if (move_uploaded_file($tmp_name, $targetFile)) {
                $current_storage_used += $fileSize;
                // Update storage in database
                $stmt = $db->prepare("UPDATE storage_usage SET storage_used = ? WHERE user_id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $current_storage_used, $user_id);
                    $stmt->execute();
                    $stmt->close();
                }
                
                $status .= "The file " . htmlspecialchars($fileName) . " has been uploaded.<br>";
            } else {
                $error = error_get_last();
                $status .= "Sorry, there was an error uploading " . htmlspecialchars($fileName) . ".<br>";
                if ($error) {
                    $status .= "Error details: " . print_r($error, true) . "<br>";
                }
            }
        }
        
        // Calculate storage percentage
        if (isset($max_storage_size) && $max_storage_size > 0) {
            $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
        }
        
        $_SESSION['update_message'] = $status;
        
        // If this is an AJAX request, return JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['status' => $status, 'success' => true]);
            exit;
        }
    }

    // Handle file deletion
    if (isset($_POST['delete_files']) && is_array($_POST['delete_files'])) {
        $activeTab = "twitch-audio-alerts";
        $status = "";
        $totalFreed = 0;
        
        foreach ($_POST['delete_files'] as $file_to_delete) {
            $file_name = basename($file_to_delete);
            $full_path = $twitch_sound_alert_path . '/' . $file_name;
            
            // Get file size before deletion
            $fileSize = is_file($full_path) ? filesize($full_path) : 0;
            
            try {
                // First delete any database mappings
                $delete_mapping = $db->prepare("DELETE FROM twitch_sound_alerts WHERE sound_mapping = ?");
                if ($delete_mapping) {
                    $delete_mapping->bind_param('s', $file_name);
                    $delete_mapping->execute();
                    $delete_mapping->close();
                }
                
                // Now delete the actual file
                if (is_file($full_path) && unlink($full_path)) {
                    $status .= "The file " . htmlspecialchars($file_name) . " has been deleted.<br>";
                    
                    // Update storage used
                    $current_storage_used -= $fileSize;
                    if ($current_storage_used < 0) $current_storage_used = 0;
                    
                    $totalFreed += $fileSize;
                } else {
                    $status .= "Failed to delete " . htmlspecialchars($file_name) . ".<br>";
                }
            } catch (Exception $e) {
                $status .= "Error: " . $e->getMessage() . "<br>";
            }
        }
        
        // Update storage in database
        if ($totalFreed > 0) {
            $updateStorage = $db->prepare("UPDATE storage_usage SET storage_used = ? WHERE user_id = ?");
            if ($updateStorage) {
                $updateStorage->bind_param('ii', $current_storage_used, $user_id);
                $updateStorage->execute();
                $updateStorage->close();
            }
        }
        
        // Calculate storage percentage
        if (isset($max_storage_size) && $max_storage_size > 0) {
            $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
        }
        
        $_SESSION['update_message'] = $status;
    }

    // For non-AJAX requests, redirect back to the modules page with the active tab
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        header("Location: modules.php?tab=" . $activeTab);
        exit();
    }
}
?>