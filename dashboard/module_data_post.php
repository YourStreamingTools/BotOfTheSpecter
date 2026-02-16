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
include 'storage_used.php';

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
    // Handle welcome messages toggle
    elseif (isset($_POST['toggle_welcome_messages'])) {
        $activeTab = "welcome-messages";
        $new_status = isset($_POST['welcome_messages_status']) ? intval($_POST['welcome_messages_status']) : 0;
        $update_sql = "UPDATE streamer_preferences SET send_welcome_messages = ? WHERE id = 1";
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bind_param('i', $new_status);
        $update_stmt->execute();
        $update_stmt->close();
        $_SESSION['update_message'] = "Welcome messages " . ($new_status ? "enabled" : "disabled") . " successfully.";
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
        $ad_snoozed_message = $_POST['ad_snoozed_message'];
        $enable_ad_notice = isset($_POST['enable_ad_notice']) ? 1 : 0;
        $enable_upcoming_ad_message = isset($_POST['enable_upcoming_ad_message']) ? 1 : 0;
        $enable_start_ad_message = isset($_POST['enable_start_ad_message']) ? 1 : 0;
        $enable_end_ad_message = isset($_POST['enable_end_ad_message']) ? 1 : 0;
        $enable_snoozed_ad_message = isset($_POST['enable_snoozed_ad_message']) ? 1 : 0;
        $enable_ai_ad_breaks = isset($_POST['enable_ai_ad_breaks']) ? 1 : 0;
        $update_sql = "INSERT INTO ad_notice_settings 
            (id, ad_upcoming_message, ad_start_message, ad_end_message, ad_snoozed_message, enable_ad_notice, enable_upcoming_ad_message, enable_start_ad_message, enable_end_ad_message, enable_snoozed_ad_message, enable_ai_ad_breaks)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                ad_upcoming_message = ?,
                ad_start_message = ?,
                ad_end_message = ?,
                ad_snoozed_message = ?,
                enable_ad_notice = ?,
                enable_upcoming_ad_message = ?,
                enable_start_ad_message = ?,
                enable_end_ad_message = ?,
                enable_snoozed_ad_message = ?,
                enable_ai_ad_breaks = ?";
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bind_param(
            'ssssiiiiiissssiiiiii',
            $ad_upcoming_message,
            $ad_start_message,
            $ad_end_message,
            $ad_snoozed_message,
            $enable_ad_notice,
            $enable_upcoming_ad_message,
            $enable_start_ad_message,
            $enable_end_ad_message,
            $enable_snoozed_ad_message,
            $enable_ai_ad_breaks,
            $ad_upcoming_message,
            $ad_start_message,
            $ad_end_message,
            $ad_snoozed_message,
            $enable_ad_notice,
            $enable_upcoming_ad_message,
            $enable_start_ad_message,
            $enable_end_ad_message,
            $enable_snoozed_ad_message,
            $enable_ai_ad_breaks
        );
        $update_stmt->execute();
        $update_stmt->close();
        $_SESSION['update_message'] = "Ad notice settings updated successfully.";
    }
    // Handle Game Deaths Settings
    elseif (isset($_POST['add_ignored_game'])) {
        $activeTab = "game-deaths";
        $game_name = trim($_POST['ignore_game_name']);
        if (!empty($game_name)) {
            $insert_stmt = $db->prepare("INSERT INTO game_deaths_settings (game_name) VALUES (?)");
            $insert_stmt->bind_param("s", $game_name);
            $insert_stmt->execute();
            $insert_stmt->close();
            $_SESSION['update_message'] = "Game '" . htmlspecialchars($game_name) . "' added to ignored list.";
        }
    } elseif (isset($_POST['remove_ignored_game'])) {
        $activeTab = "game-deaths";
        $game_name = $_POST['remove_ignored_game'];
        $delete_stmt = $db->prepare("DELETE FROM game_deaths_settings WHERE game_name = ?");
        $delete_stmt->bind_param("s", $game_name);
        $delete_stmt->execute();
        $delete_stmt->close();
        $_SESSION['update_message'] = "Game '" . htmlspecialchars($game_name) . "' removed from ignored list.";
    }
    // Handle file upload for Twitch Sound Alerts
    if (isset($_FILES["filesToUpload"]) && is_array($_FILES["filesToUpload"]["tmp_name"])) {
        $activeTab = "twitch-audio-alerts";
        $status = "";
        // Ensure directory exists
        if (!is_dir($twitch_sound_alert_path)) {
            mkdir($twitch_sound_alert_path, 0755, true);
        }
        // Define user-specific storage limits (backup in case storage_used.php wasn't included properly)
        if (!isset($max_storage_size)) {
            $tier = $_SESSION['tier'] ?? "None";
            switch ($tier) {
                case "1000":
                    $max_storage_size = 50 * 1024 * 1024;
                    break;
                case "2000":
                    $max_storage_size = 100 * 1024 * 1024;
                    break;
                case "3000":
                    $max_storage_size = 200 * 1024 * 1024;
                    break;
                case "4000":
                    $max_storage_size = 500 * 1024 * 1024;
                    break;
                default:
                    $max_storage_size = 20 * 1024 * 1024;
                    break;
            }
        }
        // Recalculate current storage used to ensure accuracy
        $walkon_path = "/var/www/walkons/" . $username;
        $videoalert_path = "/var/www/videoalerts/" . $username;
        $current_storage_used = calculateStorageUsed([$walkon_path, $soundalert_path, $videoalert_path, $twitch_sound_alert_path]);
        foreach ($_FILES["filesToUpload"]["tmp_name"] as $key => $tmp_name) {
            if (empty($tmp_name))
                continue;
            $fileName = $_FILES["filesToUpload"]["name"][$key];
            $fileSize = $_FILES["filesToUpload"]["size"][$key];
            $fileError = $_FILES["filesToUpload"]["error"][$key];
            // Check file size with accurate storage used calculation
            if ($current_storage_used + $fileSize > $max_storage_size) {
                $status .= "Failed to upload " . htmlspecialchars($fileName) . ". Storage limit exceeded. Using " .
                    round($current_storage_used / 1024 / 1024, 2) . "MB of " .
                    round($max_storage_size / 1024 / 1024, 2) . "MB.<br>";
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
                // Remove the database update since the table doesn't exist
                $status .= "The file " . htmlspecialchars($fileName) . " has been uploaded.<br>";
            } else {
                $error = error_get_last();
                $status .= "Sorry, there was an error uploading " . htmlspecialchars($fileName) . ".<br>";
                if ($error) {
                    $status .= "Error details: " . htmlspecialchars(print_r($error, true)) . "<br>";
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
            $responseData = [
                'status' => $status,
                'success' => strpos($status, 'Failed to upload') === false,
                'storage_used' => $current_storage_used,
                'max_storage' => $max_storage_size,
                'storage_percentage' => $storage_percentage,
                'tier' => $_SESSION['tier'] ?? 'None'
            ];
            // Debug values to help determine what's going wrong
            if (strpos($status, 'Failed to upload') !== false) {
                error_log("Upload Failed - Storage Debug: current=$current_storage_used, max=$max_storage_size, tier={$_SESSION['tier']}");
            }
            echo json_encode($responseData);
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
                    if ($current_storage_used < 0)
                        $current_storage_used = 0;
                    $totalFreed += $fileSize;
                } else {
                    $status .= "Failed to delete " . htmlspecialchars($file_name) . ".<br>";
                }
            } catch (Exception $e) {
                $status .= "Error: " . $e->getMessage() . "<br>";
            }
        }
        // Calculate storage percentage
        if (isset($max_storage_size) && $max_storage_size > 0) {
            $storage_percentage = ($current_storage_used / $max_storage_size) * 100;
        }
        $_SESSION['update_message'] = $status;
    }

    // Handle section-specific chat alert saves
    if (isset($_POST['section_save'])) {
        $section = $_POST['section_save'];
        $db = new mysqli($db_servername, $db_username, $db_password, $dbname);
        if ($db->connect_error) {
            die('Connection failed: ' . $db->connect_error);
        }
        $fieldsToUpdate = [];
        if ($section === 'general') {
            if (isset($_POST['follower_alert']))
                $fieldsToUpdate['follower_alert'] = $_POST['follower_alert'];
            if (isset($_POST['cheer_alert']))
                $fieldsToUpdate['cheer_alert'] = $_POST['cheer_alert'];
            if (isset($_POST['raid_alert']))
                $fieldsToUpdate['raid_alert'] = $_POST['raid_alert'];
        } elseif ($section === 'subscription') {
            if (isset($_POST['subscription_alert']))
                $fieldsToUpdate['subscription_alert'] = $_POST['subscription_alert'];
            if (isset($_POST['gift_subscription_alert']))
                $fieldsToUpdate['gift_subscription_alert'] = $_POST['gift_subscription_alert'];
        } elseif ($section === 'hype-train') {
            if (isset($_POST['hype_train_start']))
                $fieldsToUpdate['hype_train_start'] = $_POST['hype_train_start'];
            if (isset($_POST['hype_train_end']))
                $fieldsToUpdate['hype_train_end'] = $_POST['hype_train_end'];
        } elseif ($section === 'beta') {
            if (isset($_POST['gift_paid_upgrade']))
                $fieldsToUpdate['gift_paid_upgrade'] = $_POST['gift_paid_upgrade'];
            if (isset($_POST['prime_paid_upgrade']))
                $fieldsToUpdate['prime_paid_upgrade'] = $_POST['prime_paid_upgrade'];
            if (isset($_POST['pay_it_forward']))
                $fieldsToUpdate['pay_it_forward'] = $_POST['pay_it_forward'];
        } elseif ($section === 'regular-members') {
            // Handle regular members welcome messages
            $db_name_local = isset($_SESSION['username']) ? $_SESSION['username'] : null;
            $db_local = new mysqli($db_servername, $db_username, $db_password, $db_name_local);
            if ($db_local->connect_error) {
                echo json_encode(['success' => false, 'error' => 'Database connection failed']);
                exit();
            }
            $fieldsToUpdate = [];
            if (isset($_POST['new_default_welcome_message']))
                $fieldsToUpdate['new_default_welcome_message'] = $_POST['new_default_welcome_message'];
            if (isset($_POST['default_welcome_message']))
                $fieldsToUpdate['default_welcome_message'] = $_POST['default_welcome_message'];
            if (!empty($fieldsToUpdate)) {
                $updateParts = [];
                $params = [];
                $types = '';
                foreach ($fieldsToUpdate as $field => $value) {
                    $updateParts[] = "$field = ?";
                    $params[] = $value;
                    $types .= 's';
                }
                $update_sql = "UPDATE streamer_preferences SET " . implode(', ', $updateParts) . " WHERE id = 1";
                $update_stmt = $db_local->prepare($update_sql);
                $update_stmt->bind_param($types, ...$params);
                $update_stmt->execute();
                $update_stmt->close();
            }
            $db_local->close();
            echo json_encode(['success' => true, 'section' => $section]);
            exit();
        } elseif ($section === 'vip-members') {
            // Handle VIP members welcome messages
            $db_name_local = isset($_SESSION['username']) ? $_SESSION['username'] : null;
            $db_local = new mysqli($db_servername, $db_username, $db_password, $db_name_local);
            if ($db_local->connect_error) {
                echo json_encode(['success' => false, 'error' => 'Database connection failed']);
                exit();
            }
            $fieldsToUpdate = [];
            if (isset($_POST['new_default_vip_welcome_message']))
                $fieldsToUpdate['new_default_vip_welcome_message'] = $_POST['new_default_vip_welcome_message'];
            if (isset($_POST['default_vip_welcome_message']))
                $fieldsToUpdate['default_vip_welcome_message'] = $_POST['default_vip_welcome_message'];
            if (!empty($fieldsToUpdate)) {
                $updateParts = [];
                $params = [];
                $types = '';
                foreach ($fieldsToUpdate as $field => $value) {
                    $updateParts[] = "$field = ?";
                    $params[] = $value;
                    $types .= 's';
                }
                $update_sql = "UPDATE streamer_preferences SET " . implode(', ', $updateParts) . " WHERE id = 1";
                $update_stmt = $db_local->prepare($update_sql);
                $update_stmt->bind_param($types, ...$params);
                $update_stmt->execute();
                $update_stmt->close();
            }
            $db_local->close();
            echo json_encode(['success' => true, 'section' => $section]);
            exit();
        } elseif ($section === 'moderators') {
            // Handle moderators welcome messages
            $db_name_local = isset($_SESSION['username']) ? $_SESSION['username'] : null;
            $db_local = new mysqli($db_servername, $db_username, $db_password, $db_name_local);
            if ($db_local->connect_error) {
                echo json_encode(['success' => false, 'error' => 'Database connection failed']);
                exit();
            }
            $fieldsToUpdate = [];
            if (isset($_POST['new_default_mod_welcome_message']))
                $fieldsToUpdate['new_default_mod_welcome_message'] = $_POST['new_default_mod_welcome_message'];
            if (isset($_POST['default_mod_welcome_message']))
                $fieldsToUpdate['default_mod_welcome_message'] = $_POST['default_mod_welcome_message'];
            if (isset($_POST['send_welcome_messages']))
                $fieldsToUpdate['send_welcome_messages'] = $_POST['send_welcome_messages'];
            if (!empty($fieldsToUpdate)) {
                $updateParts = [];
                $params = [];
                $types = '';
                foreach ($fieldsToUpdate as $field => $value) {
                    $updateParts[] = "$field = ?";
                    $params[] = $value;
                    $types .= ($field === 'send_welcome_messages') ? 'i' : 's';
                }
                $update_sql = "UPDATE streamer_preferences SET " . implode(', ', $updateParts) . " WHERE id = 1";
                $update_stmt = $db_local->prepare($update_sql);
                $update_stmt->bind_param($types, ...$params);
                $update_stmt->execute();
                $update_stmt->close();
            }
            $db_local->close();
            echo json_encode(['success' => true, 'section' => $section]);
            exit();
        } elseif ($section === 'music') {
            // Handle music-related streamer preferences (persisted music source)
            $db_name_local = isset($_SESSION['username']) ? $_SESSION['username'] : null;
            $db_local = new mysqli($db_servername, $db_username, $db_password, $db_name_local);
            if ($db_local->connect_error) {
                echo json_encode(['success' => false, 'error' => 'Database connection failed']);
                exit();
            }
            if (isset($_POST['music_source'])) {
                $music_source = in_array($_POST['music_source'], ['system','user']) ? $_POST['music_source'] : 'system';
                $update_stmt = $db_local->prepare("UPDATE streamer_preferences SET music_source = ? WHERE id = 1");
                $update_stmt->bind_param('s', $music_source);
                $update_stmt->execute();
                $update_stmt->close();
            }
            $db_local->close();
            echo json_encode(['success' => true, 'section' => $section, 'music_source' => $music_source ?? 'system']);
            exit();
        }
        // Update or insert each field
        foreach ($fieldsToUpdate as $alertType => $alertMessage) {
            // Check if the alert type already exists
            $checkStmt = $db->prepare("SELECT 1 FROM twitch_chat_alerts WHERE alert_type = ?");
            $checkStmt->bind_param('s', $alertType);
            $checkStmt->execute();
            $checkStmt->store_result();
            if ($checkStmt->num_rows > 0) {
                // Update existing record
                $updateStmt = $db->prepare("UPDATE twitch_chat_alerts SET alert_message = ? WHERE alert_type = ?");
                $updateStmt->bind_param('ss', $alertMessage, $alertType);
                $updateStmt->execute();
                $updateStmt->close();
            } else {
                // Insert new record
                $insertStmt = $db->prepare("INSERT INTO twitch_chat_alerts (alert_type, alert_message) VALUES (?, ?)");
                $insertStmt->bind_param('ss', $alertType, $alertMessage);
                $insertStmt->execute();
                $insertStmt->close();
            }
            $checkStmt->close();
        }
        $db->close();
        echo json_encode(['success' => true, 'section' => $section]);
        exit();
    }
    // Handle Automated Shoutout Settings Update
    elseif (isset($_POST['cooldown_minutes'])) {
        $activeTab = "automated-shoutouts";
        $cooldown_minutes = max(60, intval($_POST['cooldown_minutes'])); // Enforce minimum of 60
        $stmt = $db->prepare("INSERT INTO automated_shoutout_settings (id, cooldown_minutes) VALUES (1, ?) ON DUPLICATE KEY UPDATE cooldown_minutes = ?");
        $stmt->bind_param('ii', $cooldown_minutes, $cooldown_minutes);
        if ($stmt->execute()) {
            $_SESSION['update_message'] = "Automated shoutout cooldown updated to $cooldown_minutes minutes.";
        } else {
            $_SESSION['update_message'] = "Error updating automated shoutout cooldown: " . $stmt->error;
        }
        $stmt->close();
    }
    // Handle Remove Automated Shoutout Cooldown
    elseif (isset($_POST['remove_shoutout_cooldown'])) {
        $activeTab = "automated-shoutouts";
        $user_id = $_POST['remove_shoutout_cooldown'];
        $stmt = $db->prepare("DELETE FROM automated_shoutout_tracking WHERE user_id = ?");
        $stmt->bind_param('s', $user_id);
        if ($stmt->execute()) {
            $_SESSION['update_message'] = "Automated shoutout cooldown removed for user.";
        } else {
            $_SESSION['update_message'] = "Error removing cooldown: " . $stmt->error;
        }
        $stmt->close();
    }
    // Handle Clear All Automated Shoutout Cooldowns
    elseif (isset($_POST['clear_all_shoutout_cooldowns'])) {
        $activeTab = "automated-shoutouts";
        $stmt = $db->prepare("DELETE FROM automated_shoutout_tracking");
        if ($stmt->execute()) {
            $_SESSION['update_message'] = "All automated shoutout cooldowns have been cleared.";
        } else {
            $_SESSION['update_message'] = "Error clearing cooldowns: " . $stmt->error;
        }
        $stmt->close();
    }
    // Handle TTS Settings Update
    elseif (isset($_POST['tts_voice']) && isset($_POST['tts_language'])) {
        $activeTab = "tts-settings";
        $tts_voice = $_POST['tts_voice'];
        $tts_language = $_POST['tts_language'];
        $stmt = $db->prepare("INSERT INTO tts_settings (id, voice, language) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE voice = ?, language = ?");
        $stmt->bind_param('ssss', $tts_voice, $tts_language, $tts_voice, $tts_language);
        if ($stmt->execute()) {
            $_SESSION['update_message'] = "TTS settings updated successfully. Voice: " . htmlspecialchars($tts_voice) . ", Language: " . htmlspecialchars($tts_language);
        } else {
            $_SESSION['update_message'] = "Error updating TTS settings: " . $stmt->error;
        }
        $stmt->close();
    }
    // Handle Chat Protection Settings
    elseif (isset($_POST['url_blocking'])) {
        $activeTab = "chat-protection";
        $url_blocking = $_POST['url_blocking'] == 'True' ? 'True' : 'False';
        $stmt = $db->prepare("UPDATE protection SET url_blocking = ?");
        $stmt->bind_param("s", $url_blocking);
        if ($stmt->execute()) {
            $_SESSION['update_message'] = "URL Blocking setting updated successfully.";
        } else {
            $_SESSION['update_message'] = "Failed to update your URL Blocking settings.";
            error_log("Error updating URL blocking: " . $db->error);
        }
        $stmt->close();
    }
    elseif (isset($_POST['block_first_message_commands'])) {
        $activeTab = "chat-protection";
        $val = $_POST['block_first_message_commands'] == 'True' ? 'True' : 'False';
        $stmt = $db->prepare("UPDATE protection SET block_first_message_commands = ?");
        $stmt->bind_param("s", $val);
        if ($stmt->execute()) {
            $_SESSION['update_message'] = "First-message command blocking setting updated successfully.";
        } else {
            $_SESSION['update_message'] = "Failed to update the setting.";
            error_log("Error updating block_first_message_commands: " . $db->error);
        }
        $stmt->close();
    }
    elseif (isset($_POST['whitelist_link'])) {
        $activeTab = "chat-protection";
        $whitelist_link = $_POST['whitelist_link'];
        $stmt = $db->prepare("INSERT INTO link_whitelist (link) VALUES (?)");
        $stmt->bind_param("s", $whitelist_link);
        if ($stmt->execute()) {
            $_SESSION['update_message'] = "Link added to the whitelist.";
        } else {
            $_SESSION['update_message'] = "Failed to add the link to the whitelist.";
            error_log("Error inserting whitelist link: " . $db->error);
        }
        $stmt->close();
    }
    elseif (isset($_POST['blacklist_link'])) {
        $activeTab = "chat-protection";
        $blacklist_link = $_POST['blacklist_link'];
        $stmt = $db->prepare("INSERT INTO link_blacklisting (link) VALUES (?)");
        $stmt->bind_param("s", $blacklist_link);
        if ($stmt->execute()) {
            $_SESSION['update_message'] = "Link added to the blacklist.";
        } else {
            $_SESSION['update_message'] = "Failed to add the link to the blacklist.";
            error_log("Error inserting blacklist link: " . $db->error);
        }
        $stmt->close();
    }
    elseif (isset($_POST['remove_whitelist_link'])) {
        $activeTab = "chat-protection";
        $remove_whitelist_link = $_POST['remove_whitelist_link'];
        $stmt = $db->prepare("DELETE FROM link_whitelist WHERE link = ?");
        $stmt->bind_param("s", $remove_whitelist_link);
        if ($stmt->execute()) {
            $_SESSION['update_message'] = "Link removed from the whitelist.";
        } else {
            $_SESSION['update_message'] = "Failed to remove the link from the whitelist.";
            error_log("Error deleting whitelist link: " . $db->error);
        }
        $stmt->close();
    }
    elseif (isset($_POST['remove_blacklist_link'])) {
        $activeTab = "chat-protection";
        $remove_blacklist_link = $_POST['remove_blacklist_link'];
        $stmt = $db->prepare("DELETE FROM link_blacklisting WHERE link = ?");
        $stmt->bind_param("s", $remove_blacklist_link);
        if ($stmt->execute()) {
            $_SESSION['update_message'] = "Link removed from the blacklist.";
        } else {
            $_SESSION['update_message'] = "Failed to remove the link from the blacklist.";
            error_log("Error deleting blacklist link: " . $db->error);
        }
        $stmt->close();
    }
    elseif (isset($_POST['term_blocking'])) {
        $activeTab = "chat-protection";
        $term_blocking = $_POST['term_blocking'] == 'True' ? 'True' : 'False';
        $stmt = $db->prepare("UPDATE protection SET term_blocking = ?");
        $stmt->bind_param("s", $term_blocking);
        if ($stmt->execute()) {
            $_SESSION['update_message'] = "Term Blocking setting updated successfully.";
        } else {
            $_SESSION['update_message'] = "Failed to update your Term Blocking settings.";
            error_log("Error updating term blocking: " . $db->error);
        }
        $stmt->close();
    }
    elseif (isset($_POST['blocked_term'])) {
        $activeTab = "chat-protection";
        $blocked_term = trim($_POST['blocked_term']);
        $stmt = $db->prepare("INSERT INTO blocked_terms (term) VALUES (?)");
        $stmt->bind_param("s", $blocked_term);
        if ($stmt->execute()) {
            $_SESSION['update_message'] = "Term added to blocked list.";
        } else {
            $_SESSION['update_message'] = "Failed to add term to blocked list.";
            error_log("Error inserting blocked term: " . $db->error);
        }
        $stmt->close();
    }
    elseif (isset($_POST['remove_blocked_term'])) {
        $activeTab = "chat-protection";
        $remove_blocked_term = $_POST['remove_blocked_term'];
        $stmt = $db->prepare("DELETE FROM blocked_terms WHERE term = ?");
        $stmt->bind_param("s", $remove_blocked_term);
        if ($stmt->execute()) {
            $_SESSION['update_message'] = "Term removed from blocked list.";
        } else {
            $_SESSION['update_message'] = "Failed to remove term from blocked list.";
            error_log("Error deleting blocked term: " . $db->error);
        }
        $stmt->close();
    }
    // For non-AJAX requests, redirect back to the modules page with the active tab
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        header("Location: modules.php?tab=" . $activeTab);
        exit();
    }
}
?>