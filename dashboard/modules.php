<?php
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);

// Initialize the session
session_start();
$today = new DateTime();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title and Initial Variables
$title = "Modules";
$current_blacklist = [];

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'user_db.php';
include 'storage_used.php';
foreach ($profileData as $profile) {
    $timezone = $profile['timezone'];
    $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);

// Fetch the current blacklist settings
$sql = "SELECT blacklist FROM joke_settings WHERE id = 1";
$stmt = $db->prepare($sql);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
if ($result) {
    $current_blacklist = json_decode($result['blacklist'], true);
}

// Fetch the current settings from the database each time the page loads
$fetch_sql = "SELECT send_welcome_messages, default_welcome_message, default_vip_welcome_message, default_mod_welcome_message FROM streamer_preferences WHERE id = 1";
$fetch_stmt = $db->prepare($fetch_sql);
$fetch_stmt->execute();
$preferences = $fetch_stmt->fetch(PDO::FETCH_ASSOC);

// Set default values if no settings exist in the database
$send_welcome_messages = isset($preferences['send_welcome_messages']) ? $preferences['send_welcome_messages'] : 1;
$default_welcome_message = isset($preferences['default_welcome_message']) ? $preferences['default_welcome_message'] : "Welcome back (user), glad to see you again!";
$new_default_welcome_message = isset($preferences['new_default_welcome_message']) ? $preferences['new_default_welcome_message'] : "(user) is new to the community, let's give them a warm welcome!";
$default_vip_welcome_message = isset($preferences['default_vip_welcome_message']) ? $preferences['default_vip_welcome_message'] : "ATTENTION! A very important person has entered the chat, welcome (user)";
$new_default_vip_welcome_message = isset($preferences['new_default_vip_welcome_message']) ? $preferences['new_default_vip_welcome_message'] : "ATTENTION! A very important person has entered the chat, welcome (user)";
$default_mod_welcome_message = isset($preferences['default_mod_welcome_message']) ? $preferences['default_mod_welcome_message'] : "MOD ON DUTY! Welcome in (user), the power of the sword has increased!";
$new_default_mod_welcome_message = isset($preferences['new_default_mod_welcome_message']) ? $preferences['new_default_mod_welcome_message'] : "MOD ON DUTY! Welcome in (user), the power of the sword has increased!";

// If form is submitted, update the blacklist
$update_success = false;
$update_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Handle Joke Blacklist Update
    if (isset($_POST['blacklist'])) {
        $new_blacklist = isset($_POST['blacklist']) ? $_POST['blacklist'] : [];
        $new_blacklist_json = json_encode($new_blacklist);
        // Update the blacklist in the database
        $update_sql = "UPDATE joke_settings SET blacklist = :blacklist WHERE id = 1";
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bindParam(':blacklist', $new_blacklist_json);
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
            (1, :send_welcome_messages, :default_welcome_message, :default_vip_welcome_message, :default_mod_welcome_message,
                :new_default_welcome_message, :new_default_vip_welcome_message, :new_default_mod_welcome_message)
            ON DUPLICATE KEY UPDATE 
                send_welcome_messages = :send_welcome_messages, 
                default_welcome_message = :default_welcome_message,
                default_vip_welcome_message = :default_vip_welcome_message,
                default_mod_welcome_message = :default_mod_welcome_message,
                new_default_welcome_message = :new_default_welcome_message,
                new_default_vip_welcome_message = :new_default_vip_welcome_message,
                new_default_mod_welcome_message = :new_default_mod_welcome_message";
        $update_stmt = $db->prepare($update_sql);
        // Bind parameters
        $update_stmt->bindParam(':send_welcome_messages', $send_welcome_messages, PDO::PARAM_INT);
        $update_stmt->bindParam(':default_welcome_message', $default_welcome_message, PDO::PARAM_STR);
        $update_stmt->bindParam(':default_vip_welcome_message', $default_vip_welcome_message, PDO::PARAM_STR);
        $update_stmt->bindParam(':default_mod_welcome_message', $default_mod_welcome_message, PDO::PARAM_STR);
        $update_stmt->bindParam(':new_default_welcome_message', $new_default_welcome_message, PDO::PARAM_STR);
        $update_stmt->bindParam(':new_default_vip_welcome_message', $new_default_vip_welcome_message, PDO::PARAM_STR);
        $update_stmt->bindParam(':new_default_mod_welcome_message', $new_default_mod_welcome_message, PDO::PARAM_STR);
        // Execute the query
        $update_stmt->execute();
        // Set success message for welcome messages update in session
        $_SESSION['update_message'] = "Welcome message settings updated successfully.";
    }    
    // NEW: Move channel point reward mapping here
    elseif (isset($_POST['sound_file'], $_POST['twitch_alert_id'])) {
        $status = "";
        $soundFile = htmlspecialchars($_POST['sound_file']);
        $rewardId = htmlspecialchars($_POST['twitch_alert_id']);
        $db->beginTransaction();
        // Check if a mapping already exists for this sound file
        $checkExisting = $db->prepare("SELECT 1 FROM twitch_sound_alerts WHERE sound_mapping = :sound_mapping");
        $checkExisting->bindParam(':sound_mapping', $soundFile);
        $checkExisting->execute();
        if ($checkExisting->rowCount() > 0) {
            // Update existing mapping
            if ($rewardId) {
                $updateMapping = $db->prepare("UPDATE twitch_sound_alerts SET twitch_alert_id = :twitch_alert_id WHERE sound_mapping = :sound_mapping");
                $updateMapping->bindParam(':twitch_alert_id', $rewardId);
                $updateMapping->bindParam(':sound_mapping', $soundFile);
                if (!$updateMapping->execute()) {
                    $status .= "Failed to update mapping for file '" . $soundFile . "'. Database error: " . print_r($updateMapping->errorInfo(), true) . "<br>"; 
                } else {
                    $status .= "Mapping for file '" . $soundFile . "' has been updated successfully.<br>";
                }
            } else {
                // Clear the mapping if no reward is selected
                $clearMapping = $db->prepare("UPDATE twitch_sound_alerts SET twitch_alert_id = NULL WHERE sound_mapping = :sound_mapping");
                $clearMapping->bindParam(':sound_mapping', $soundFile);
                if (!$clearMapping->execute()) {
                    $status .= "Failed to clear mapping for file '" . $soundFile . "'. Database error: " . print_r($clearMapping->errorInfo(), true) . "<br>"; 
                } else {
                    $status .= "Mapping for file '" . $soundFile . "' has been cleared.<br>";
                }
            }
        } else {
            // Create a new mapping if it doesn't exist
            if ($rewardId) {
                $insertMapping = $db->prepare("INSERT INTO twitch_sound_alerts (sound_mapping, twitch_alert_id) VALUES (:sound_mapping, :twitch_alert_id)");
                $insertMapping->bindParam(':sound_mapping', $soundFile);
                $insertMapping->bindParam(':twitch_alert_id', $rewardId);
                if (!$insertMapping->execute()) {
                    $status .= "Failed to create mapping for file '" . $soundFile . "'. Database error: " . print_r($insertMapping->errorInfo(), true) . "<br>"; 
                } else {
                    $status .= "Mapping for file '" . $soundFile . "' has been created successfully.<br>";
                }
            } 
        }
        // Commit transaction
        $db->commit();
        $_SESSION['update_message'] = $status;
    }
    // Refresh the page to show updated settings
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Define empty variables
$status = '';

// Fetch sound alert mappings for the current user
$getTwitchAlerts = $db->prepare("SELECT sound_mapping, twitch_alert_id FROM twitch_sound_alerts");
$getTwitchAlerts->execute();
$soundAlerts = $getTwitchAlerts->fetchAll(PDO::FETCH_ASSOC);

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
        $targetFile = $twitch_sound_alert_path . '/' . basename($_FILES["filesToUpload"]["name"][$key]);
        $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
        if ($fileType != "mp3") {
            $status .= "Failed to upload " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ". Only MP3 files are allowed.<br>";
            continue;
        }
        if (move_uploaded_file($tmp_name, $targetFile)) {
            $current_storage_used += $fileSize;
            $status .= "The file " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . " has been uploaded.<br>";
        } else {
            $status .= "Sorry, there was an error uploading " . htmlspecialchars(basename($_FILES["filesToUpload"]["name"][$key])) . ".<br>";
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
<!doctype html>
<html lang="en">
    <head>
        <!-- Header -->
        <?php include('header.php'); ?>
        <style>.custom-width { width: 90vw; max-width: none; }</style>
        <!-- /Header -->
    </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
    <br>
    <h1 class="title is-3">Module Settings</h1>
    <br>
    <?php if (isset($_SESSION['update_message'])): ?><div class="notification is-success"><?php echo $_SESSION['update_message']; unset($_SESSION['update_message']);?></div><?php endif; ?>
    <div class="columns is-desktop is-multiline is-centered box-container">
        <!-- Joke Blacklist Section -->
        <div class="column is-4 bot-box" id="stable-bot-status" style="position: relative;">
            <h2 class="title is-3">Manage Joke Blacklist:</h2>
            <h2 class="subtitle is-5" style="text-align: center;">Set which category is blocked.</h2>
            <button class="button is-primary" onclick="openModal('jokeBlacklistModal')">Open Settings</button>
        </div>
        <!-- New Welcome Message Settings -->
        <div class="column is-6 bot-box" id="welcome-message-settings" style="position: relative;">
            <h1 class="title is-3">Custom Welcome Messages</h1>
            <h1 class="subtitle is-5">Set your default welcome messages for users, VIPs, and Mods.</h1>
            <button class="button is-primary" onclick="openModal('welcomeMessageModal')">Open Settings</button>
        </div>
        <div class="column is-5 bot-box" id="" style="position: relative;">
            <h1 class="title is-3">Twitch Alerts (COMMING SOON)</h1>
            <h1 class="subtitle is-5">Twitch Alert sounds: Followers, Cheers, Subs and Raids</h1>
            <button class="button is-primary" onclick="openModal('twitchAlertsModal')">Open Settings</button>
        </div>
    </div>
</div>

<!-- Joke Blacklist Modal -->
<div id="jokeBlacklistModal" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content">
        <div class="box">
            <form method="POST" action="">
                <h2 class="title is-3">Manage Joke Blacklist:</h2>
                <h2 class="subtitle is-4 has-text-danger" style="text-align: center;">Any category selected here will not be allowed to be posted by the bot.</h2>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="Miscellaneous"<?php echo in_array("Miscellaneous", $current_blacklist) ? " checked" : ""; ?>> Miscellaneous</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="Coding"<?php echo in_array("Coding", $current_blacklist) ? " checked" : ""; ?>> Coding</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="Development"<?php echo in_array("Development", $current_blacklist) ? " checked" : ""; ?>> Development</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="Halloween"<?php echo in_array("Halloween", $current_blacklist) ? " checked" : ""; ?>> Halloween</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="Pun"<?php echo in_array("Pun", $current_blacklist) ? " checked" : ""; ?>> Pun</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="nsfw"<?php echo in_array("nsfw", $current_blacklist) ? " checked" : ""; ?>> NSFW</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="religious"<?php echo in_array("religious", $current_blacklist) ? " checked" : ""; ?>> Religious</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="political"<?php echo in_array("political", $current_blacklist) ? " checked" : ""; ?>> Political</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="racist"<?php echo in_array("racist", $current_blacklist) ? " checked" : ""; ?>> Racist</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="sexist"<?php echo in_array("sexist", $current_blacklist) ? " checked" : ""; ?>> Sexist</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="dark"<?php echo in_array("dark", $current_blacklist) ? " checked" : ""; ?>> Dark</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="explicit"<?php echo in_array("explicit", $current_blacklist) ? " checked" : ""; ?>> Explicit</label></div>
                <button class="button is-primary" type="submit">Save Settings</button>
            </form>
        </div>
    </div>
    <button class="modal-close is-large" aria-label="close" onclick="closeModal('jokeBlacklistModal')"></button>
</div>

<!-- Welcome Message Modal -->
<div id="welcomeMessageModal" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content">
        <div class="box">
            <form method="POST" action="">
                <div class="notification is-info">
                    <strong>Info:</strong> You can use the <code>(user)</code> variable in the welcome message. It will be replaced with the username of the user entering the chat.
                </div>
                <div class="field">
                    <label class="label">Default New Member Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="new_default_welcome_message" value="<?php echo $new_default_welcome_message ? $new_default_welcome_message : '(user) is new to the community, let\'s give them a warm welcome!'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="label">Default Returning Member Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="default_welcome_message" value="<?php echo $default_welcome_message ? $default_welcome_message : 'Welcome back (user), glad to see you again!'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="label">Default New VIP Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="new_default_vip_welcome_message" value="<?php echo $new_default_vip_welcome_message ? $new_default_vip_welcome_message : 'ATTENTION! A very important person has entered the chat, welcome (user)'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="label">Default Returning VIP Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="default_vip_welcome_message" value="<?php echo $default_vip_welcome_message ? $default_vip_welcome_message : 'ATTENTION! A very important person has entered the chat, welcome (user)'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="label">Default New Mod Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="new_default_mod_welcome_message" value="<?php echo $new_default_mod_welcome_message ? $new_default_mod_welcome_message : 'MOD ON DUTY! Welcome in (user), the power of the sword has increased!'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="label">Default Returning Mod Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="default_mod_welcome_message" value="<?php echo $default_mod_welcome_message ? $default_mod_welcome_message : 'MOD ON DUTY! Welcome in (user), the power of the sword has increased!'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="send_welcome_messages" value="1" <?php echo $send_welcome_messages ? 'checked' : ''; ?>> Enable welcome messages
                    </label>
                </div>
                <button class="button is-primary" type="submit">Save Welcome Settings</button>
            </form>
        </div>
    </div>
    <button class="modal-close is-large" aria-label="close" onclick="closeModal('welcomeMessageModal')"></button>
</div>

<!-- Twitch Alerts Modal -->
<div id="twitchAlertsModal" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content custom-width">
        <div class="box">
            <h2 class="title is-3">Manage Twitch Event Sound Alerts:</h2>
            <div class="columns is-desktop is-multiline box-container is-centered" style="width: 100%;">
                <div class="column is-4" id="walkon-upload" style="position: relative;">
                    <h1 class="title is-4">Upload MP3 Files:</h1>
                    <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
                        <label for="filesToUpload" class="drag-area" id="drag-area">
                            <span>Drag & Drop files here or</span>
                            <span>Browse Files</span>
                            <input type="file" name="filesToUpload[]" id="filesToUpload" multiple>
                        </label>
                        <br>
                        <div id="file-list"></div>
                        <br>
                        <input type="submit" value="Upload MP3 Files" name="submit">
                    </form>
                    <br>
                    <div class="upload-progress-bar-container">
                        <div class="upload-progress-bar has-text-black-bis" style="width: 0%;"></div>
                    </div>
                    <br>
                    <div class="progress-bar-container">
                        <div class="progress-bar has-text-black-bis" style="width: <?php echo $storage_percentage; ?>%;"><?php echo round($storage_percentage, 2); ?>%</div>
                    </div>
                    <p><?php echo round($current_storage_used / 1024 / 1024, 2); ?>MB of <?php echo round($max_storage_size / 1024 / 1024, 2); ?>MB used</p>
                    <?php if (!empty($status)) : ?>
                        <div class="message"><?php echo $status; ?></div>
                    <?php endif; ?>
                </div>
                <div class="column is-7 bot-box" id="walkon-upload" style="position: relative;">
                    <?php $walkon_files = array_diff(scandir($twitch_sound_alert_path), array('.', '..')); if (!empty($walkon_files)) : ?>
                    <h1 class="title is-4">Your Twitch Sound Alerts</h1>
                    <form action="" method="POST" id="deleteForm">
                        <table class="table is-striped" style="width: 100%; text-align: center;">
                            <thead>
                                <tr>
                                    <th style="width: 70px;">Select</th>
                                    <th>File Name</th>
                                    <th>Twitch Event</th>
                                    <th style="width: 100px;">Action</th>
                                    <th style="width: 100px;">Test Audio</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($walkon_files as $file): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="delete_files[]" value="<?php echo htmlspecialchars($file); ?>">
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(pathinfo($file, PATHINFO_FILENAME)); ?>
                                    </td>
                                    <td>
                                        <?php
                                        // Determine the current mapped reward (if any)
                                        $current_reward_id = isset($twitchSoundAlertMappings[$file]) ? $twitchSoundAlertMappings[$file] : null;
                                        $current_reward_title = $current_reward_id ? htmlspecialchars($current_reward_id) : "Not Mapped";
                                        ?>
                                        <?php if ($current_reward_id): ?>
                                            <em><?php echo $current_reward_title; ?></em>
                                        <?php else: ?>
                                            <em>Not Mapped</em>
                                        <?php endif; ?>
                                        <br>
                                        <form action="" method="POST" class="mapping-form">
                                            <input type="hidden" name="sound_file" value="<?php echo htmlspecialchars($file); ?>">
                                            <select name="twitch_alert_id" class="mapping-select" onchange="this.form.submit()">
                                                <option value="">-- Select Event --</option>
                                                <?php if (!$current_reward_id): ?>
                                                    <option value="Follow" <?php echo $current_reward_id === 'Follow' ? 'selected' : ''; ?>>Follow</option>
                                                    <option value="Raid" <?php echo $current_reward_id === 'Raid' ? 'selected' : ''; ?>>Raid</option>
                                                    <option value="Cheer" <?php echo $current_reward_id === 'Cheer' ? 'selected' : ''; ?>>Cheer</option>
                                                    <option value="Subscription" <?php echo $current_reward_id === 'Subscription' ? 'selected' : ''; ?>>Subscription</option>
                                                <?php endif; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td>
                                        <button type="button" class="delete-single button is-danger" data-file="<?php echo htmlspecialchars($file); ?>">Delete</button>
                                    </td>
                                    <td>
                                        <button type="button" class="test-sound button is-primary" data-file="<?php echo htmlspecialchars($file); ?>">Test</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <input type="submit" value="Delete Selected" class="button is-danger" name="submit_delete" style="margin-top: 10px;">
                    </form>
                    <?php else: ?>
                        <h1 class="title is-4">No sound alert files uploaded.</h1>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <button class="modal-close is-large" aria-label="close" onclick="closeModal('twitchAlertsModal')"></button>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script>
function openModal(modalId) {
    document.getElementById(modalId).classList.add('is-active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('is-active');
}

$(document).ready(function() {
    let dropArea = $('#drag-area');
    let fileInput = $('#filesToUpload');
    let fileList = $('#file-list');
    let uploadProgressBar = $('.upload-progress-bar');

    dropArea.on('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropArea.addClass('dragging');
    });
    dropArea.on('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropArea.removeClass('dragging');
    });
    dropArea.on('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropArea.removeClass('dragging');
        let files = e.originalEvent.dataTransfer.files;
        fileInput.prop('files', files);
        fileList.empty();
        $.each(files, function(index, file) {
            fileList.append('<div>' + file.name + '</div>');
        });
        uploadFiles(files);
    });
    dropArea.on('click', function() {
        fileInput.click();
    });
    fileInput.on('change', function() {
        let files = fileInput.prop('files');
        fileList.empty();
        $.each(files, function(index, file) {
            fileList.append('<div>' + file.name + '</div>');
        });
        uploadFiles(files);
    });

    function uploadFiles(files) {
        let formData = new FormData();
        $.each(files, function(index, file) {
            formData.append('filesToUpload[]', file);
        });
        $.ajax({
            url: '',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            xhr: function() {
                let xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        let percentComplete = (e.loaded / e.total) * 100;
                        uploadProgressBar.css('width', percentComplete + '%');
                        uploadProgressBar.text(Math.round(percentComplete) + '%');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                location.reload(); // Reload the page to update the file list and storage usage
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('Upload failed: ' + textStatus + ' - ' + errorThrown);
            }
        });
    }

    $('.delete-single').on('click', function() {
        let fileName = $(this).data('file');
        if (confirm('Are you sure you want to delete "' + fileName + '"?')) {
            $('<input>').attr({
                type: 'hidden',
                name: 'delete_files[]',
                value: fileName
            }).appendTo('#deleteForm');
            $('#deleteForm').submit();
        }
    });
});

document.addEventListener("DOMContentLoaded", function () {
    // Attach click event listeners to all Test buttons
    document.querySelectorAll(".test-sound").forEach(function (button) {
        button.addEventListener("click", function () {
            const fileName = this.getAttribute("data-file");
            sendStreamEvent("SOUND_ALERT", fileName);
        });
    });
});

// Function to send a stream event
function sendStreamEvent(eventType, fileName) {
    const xhr = new XMLHttpRequest();
    const url = "notify_event.php";
    const params = `event=${eventType}&sound=${encodeURIComponent(fileName)}&channel_name=<?php echo $username; ?>&api_key=<?php echo $api_key; ?>`;
    xhr.open("POST", url, true);
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    console.log(`${eventType} event for ${fileName} sent successfully.`);
                } else {
                    console.error(`Error sending ${eventType} event: ${response.message}`);
                }
            } catch (e) {
                console.error("Error parsing JSON response:", e);
                console.error("Response:", xhr.responseText);
            }
        } else if (xhr.readyState === 4) {
            console.error(`Error sending ${eventType} event: ${xhr.responseText}`);
        }
    };
    xhr.send(params);
}
</script>
</body>
</html>