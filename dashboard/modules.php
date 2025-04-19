<?php
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
include 'module_data.php';
include "mod_access.php";
include "file_paths.php";
foreach ($profileData as $profile) {
    $timezone = $profile['timezone'];
    $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);

// Get active tab from URL parameter or default to first tab
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'joke-blacklist';
?>
<!doctype html>
<html lang="en">
    <head>
        <!-- Header -->
        <?php include('header.php'); ?>
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
    <?php if (isset($_SESSION['update_message'])): ?>
        <div class="notification is-success"><?php echo $_SESSION['update_message']; unset($_SESSION['update_message']);?></div>
    <?php endif; ?>
    <!-- Tabs Navigation -->
    <div class="tabs">
        <ul>
            <li class="tab <?php echo $activeTab == 'joke-blacklist' ? 'is-active' : ''; ?>" data-tab="joke-blacklist">
                <a href="?tab=joke-blacklist">Joke Blacklist</a>
            </li>
            <li class="tab <?php echo $activeTab == 'welcome-messages' ? 'is-active' : ''; ?>" data-tab="welcome-messages">
                <a href="?tab=welcome-messages">Welcome Messages</a>
            </li>
            <li class="tab <?php echo $activeTab == 'chat-protection' ? 'is-active' : ''; ?>" data-tab="chat-protection">
                <a href="?tab=chat-protection">Chat Protection</a>
            </li>
            <li class="tab <?php echo $activeTab == 'ad-notices' ? 'is-active' : ''; ?>" data-tab="ad-notices">
                <a href="?tab=ad-notices">Ad Notices</a>
            </li>
            <li class="tab <?php echo $activeTab == 'twitch-audio-alerts' ? 'is-active' : ''; ?>" data-tab="twitch-audio-alerts">
                <a href="?tab=twitch-audio-alerts">Twitch Event Alerts</a>
            </li>
            <li class="tab <?php echo $activeTab == 'twitch-chat-alerts' ? 'is-active' : ''; ?>" data-tab="twitch-chat-alerts">
                <a href="?tab=twitch-chat-alerts">Twitch Chat Alerts</a>
            </li>
        </ul>
    </div>
    
    <!-- Tab Contents -->
    <div class="tab-content <?php echo $activeTab == 'joke-blacklist' ? 'is-active' : ''; ?>" id="joke-blacklist">
        <div class="module-container">
            <h2 class="title is-4">Manage Joke Blacklist</h2>
            <p class="subtitle is-6 has-text-danger">Any category selected here will not be allowed to be posted by the bot.</p>
            <form method="POST" action="module_data_post.php">
                <div class="columns is-multiline">
                    <div class="column is-3">
                        <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="Miscellaneous"<?php echo in_array("Miscellaneous", $current_blacklist) ? " checked" : ""; ?>> Miscellaneous</label></div>
                    </div>
                    <div class="column is-3">
                        <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="Coding"<?php echo in_array("Coding", $current_blacklist) ? " checked" : ""; ?>> Coding</label></div>
                    </div>
                    <div class="column is-3">
                        <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="Development"<?php echo in_array("Development", $current_blacklist) ? " checked" : ""; ?>> Development</label></div>
                    </div>
                    <div class="column is-3">
                        <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="Halloween"<?php echo in_array("Halloween", $current_blacklist) ? " checked" : ""; ?>> Halloween</label></div>
                    </div>
                    <div class="column is-3">
                        <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="Pun"<?php echo in_array("Pun", $current_blacklist) ? " checked" : ""; ?>> Pun</label></div>
                    </div>
                    <div class="column is-3">
                        <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="nsfw"<?php echo in_array("nsfw", $current_blacklist) ? " checked" : ""; ?>> NSFW</label></div>
                    </div>
                    <div class="column is-3">
                        <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="religious"<?php echo in_array("religious", $current_blacklist) ? " checked" : ""; ?>> Religious</label></div>
                    </div>
                    <div class="column is-3">
                        <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="political"<?php echo in_array("political", $current_blacklist) ? " checked" : ""; ?>> Political</label></div>
                    </div>
                    <div class="column is-3">
                        <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="racist"<?php echo in_array("racist", $current_blacklist) ? " checked" : ""; ?>> Racist</label></div>
                    </div>
                    <div class="column is-3">
                        <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="sexist"<?php echo in_array("sexist", $current_blacklist) ? " checked" : ""; ?>> Sexist</label></div>
                    </div>
                    <div class="column is-3">
                        <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="dark"<?php echo in_array("dark", $current_blacklist) ? " checked" : ""; ?>> Dark</label></div>
                    </div>
                    <div class="column is-3">
                        <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="explicit"<?php echo in_array("explicit", $current_blacklist) ? " checked" : ""; ?>> Explicit</label></div>
                    </div>
                </div>
                <div class="field">
                    <div class="control">
                        <button class="button is-primary" type="submit">Save Blacklist Settings</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <div class="tab-content <?php echo $activeTab == 'welcome-messages' ? 'is-active' : ''; ?>" id="welcome-messages">
        <div class="module-container">
            <h2 class="title is-4">Custom Welcome Messages</h2>
            <div class="notification is-info">
                <strong>Info:</strong> You can use the <code>(user)</code> variable in the welcome message. It will be replaced with the username of the user entering the chat.
            </div>
            <form method="POST" action="module_data_post.php">
                <div class="field">
                    <label class="has-text-white">Default New Member Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="new_default_welcome_message" value="<?php echo $new_default_welcome_message ? $new_default_welcome_message : '(user) is new to the community, let\'s give them a warm welcome!'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white">Default Returning Member Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="default_welcome_message" value="<?php echo $default_welcome_message ? $default_welcome_message : 'Welcome back (user), glad to see you again!'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white">Default New VIP Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="new_default_vip_welcome_message" value="<?php echo $new_default_vip_welcome_message ? $new_default_vip_welcome_message : 'ATTENTION! A very important person has entered the chat, welcome (user)'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white">Default Returning VIP Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="default_vip_welcome_message" value="<?php echo $default_vip_welcome_message ? $default_vip_welcome_message : 'ATTENTION! A very important person has entered the chat, welcome (user)'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white">Default New Mod Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="new_default_mod_welcome_message" value="<?php echo $new_default_mod_welcome_message ? $new_default_mod_welcome_message : 'MOD ON DUTY! Welcome in (user), the power of the sword has increased!'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white">Default Returning Mod Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="default_mod_welcome_message" value="<?php echo $default_mod_welcome_message ? $default_mod_welcome_message : 'MOD ON DUTY! Welcome in (user), the power of the sword has increased!'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="send_welcome_messages" value="1" <?php echo $send_welcome_messages ? 'checked' : ''; ?>> Enable welcome messages
                    </label>
                </div>
                <div class="field">
                    <div class="control">
                        <button class="button is-primary" type="submit">Save Welcome Settings</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="tab-content <?php echo $activeTab == 'chat-protection' ? 'is-active' : ''; ?>" id="chat-protection">
        <div class="module-container">
            <?php include('protection.php'); ?>
        </div>
    </div>

    <div class="tab-content <?php echo $activeTab == 'ad-notices' ? 'is-active' : ''; ?>" id="ad-notices">
        <div class="module-container">
            <h2 class="title is-4">Ad Notices</h2>
            <div class="notification is-warning">This feature is currently in development and is available to beta users running version 5.4.</div>
            <div class="notification is-info">
                <p>You can use the variable <code>(duration)</code> which will be replaced with the ads' duration.</p>
                <p>You can use the variable <code>(minutes)</code> which will be replaced with upcoming ads' duration in minutes.</p>
            </div>
            <form method="POST" action="module_data_post.php">
                <div class="field">
                    <label class="has-text-white">Ad Upcoming Message</label>
                    <div class="control">
                        <input class="input" type="text" name="ad_upcoming_message" placeholder="Message when ads are upcoming" value="<?php echo htmlspecialchars($ad_upcoming_message); ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white">Ad Starting Message</label>
                    <div class="control">
                        <input class="input" type="text" name="ad_start_message" placeholder="Message when ads start" value="<?php echo htmlspecialchars($ad_start_message); ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white">Ad Ended Message</label>
                    <div class="control">
                        <input class="input" type="text" name="ad_end_message" placeholder="Message when ads end" value="<?php echo htmlspecialchars($ad_end_message); ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="enable_ad_notice" value="1" <?php echo $enable_ad_notice ? 'checked' : ''; ?>> Enable Ad Notice
                    </label>
                </div>
                <div class="field">
                    <div class="control">
                        <button class="button is-primary" type="submit">Save Ad Notice Settings</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="tab-content <?php echo $activeTab == 'twitch-audio-alerts' ? 'is-active' : ''; ?>" id="twitch-audio-alerts">
        <div class="module-container">
        <h2 class="title is-3">Manage Twitch Event Sound Alerts:</h2>
            <div class="notification is-warning">This feature is currently in development and is available to beta users running version 5.4.</div>
            <div class="columns is-desktop is-multiline box-container is-centered" style="width: 100%;">
                <div class="column is-4" id="walkon-upload" style="position: relative;">
                    <h1 class="title is-4">Upload MP3 Files:</h1>
                    <form action="module_data_post.php" method="POST" enctype="multipart/form-data" id="uploadForm">
                        <label for="filesToUpload" class="drag-area" id="drag-area">
                            <span>Drag & Drop files here or</span>
                            <span>Browse Files</span>
                            <input type="file" name="filesToUpload[]" id="filesToUpload" multiple accept=".mp3">
                        </label>
                        <br>
                        <div id="file-list"></div>
                        <br>
                        <input type="submit" value="Upload MP3 Files" name="submit" class="button is-primary">
                    </form>
                    <br>
                    <div class="progress-bar-container">
                        <div id="uploadProgressBar" class="progress-bar has-text-black-bis" style="width: <?php echo $storage_percentage; ?>%;"><?php echo round($storage_percentage, 2); ?>%</div>
                    </div>
                    <p><?php echo round($current_storage_used / 1024 / 1024, 2); ?>MB of <?php echo round($max_storage_size / 1024 / 1024, 2); ?>MB used</p>
                    <?php if (!empty($status)) : ?>
                        <div class="message"><?php echo $status; ?></div>
                    <?php endif; ?>
                </div>
                <div class="column is-7 bot-box" id="walkon-upload" style="position: relative;">
                    <?php $walkon_files = array_diff(scandir($twitch_sound_alert_path), array('.', '..')); if (!empty($walkon_files)) : ?>
                    <h1 class="title is-4">Your Twitch Sound Alerts</h1>
                    <form action="module_data_post.php" method="POST" id="deleteForm">
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
                                        $current_mapped = isset($twitchSoundAlertMappings[$file]) ? $twitchSoundAlertMappings[$file] : null;
                                        $mappedEvents = [];
                                        foreach ($twitchSoundAlertMappings as $mappedFile => $mappedEvent) {
                                            if ($mappedFile !== $file && $mappedEvent) {
                                                $mappedEvents[] = $mappedEvent;
                                            }
                                        }
                                        $allEvents = ['Follow', 'Raid', 'Cheer', 'Subscription', 'Gift Subscription', 'Hype Train Start', 'Hype Train End'];
                                        $availableEvents = array_diff($allEvents, $mappedEvents);
                                        ?>
                                        <?php if ($current_mapped): ?>
                                            <em><?php echo htmlspecialchars($current_mapped); ?></em>
                                        <?php else: ?>
                                            <em>Not Mapped</em>
                                        <?php endif; ?>
                                        <br>
                                        <?php if (!empty($availableEvents) || !$current_mapped): ?>
                                            <form action="module_data_post.php" method="POST" class="mapping-form">
                                                <input type="hidden" name="sound_file" value="<?php echo htmlspecialchars($file); ?>">
                                                <select name="twitch_alert_id" class="mapping-select" onchange="this.form.submit()">
                                                    <option value="">-- Select Event --</option>
                                                    <?php
                                                    foreach ($availableEvents as $evt) {
                                                        if ($current_mapped !== $evt) {
                                                            echo '<option value="' . htmlspecialchars($evt) . '">' . htmlspecialchars($evt) . '</option>';
                                                        }
                                                    }
                                                    ?>
                                                </select>
                                            </form>
                                        <?php else: ?>
                                            <em>All events are mapped. Delete a file to add new mappings.</em>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="delete-single button is-danger" data-file="<?php echo htmlspecialchars($file); ?>">Delete</button>
                                    </td>
                                    <td>
                                        <button type="button" class="test-sound button is-primary" data-file="twitch/<?php echo htmlspecialchars($file); ?>">Test</button>
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
    
    <div class="tab-content <?php echo $activeTab == 'twitch-chat-alerts' ? 'is-active' : ''; ?>" id="twitch-chat-alerts">
        <div class="module-container">
            <h2 class="title is-4">Configure Chat Alerts</h2>
            <div class="notification is-warning">This feature is currently under development and will be available to beta users soon.</div>
            <div class="notification is-dark">
                <span class="has-text-weight-bold">Variables:</span><br>
                <ul>
                    <li><span class="has-text-weight-bold">(user)</span> for the username of the user.</li>
                    <li><span class="has-text-weight-bold">(bits)</span> for the number of bits for the cheer message.</li>
                    <li><span class="has-text-weight-bold">(total-bits)</span> for the total amount of bits the user has given.</li>
                    <li><span class="has-text-weight-bold">(viewers)</span> for the number of viewers in the raid message.</li>
                    <li><span class="has-text-weight-bold">(tier)</span> for the subscription tier.</li>
                    <li><span class="has-text-weight-bold">(months)</span> for the number of months subscribed.<span style="vertical-align: middle; line-height: 1; display: inline-block;" class="is-size-4 has-text-weight-bold has-text-danger">*</span></li>
                    <li><span class="has-text-weight-bold">(total-gifted)</span> for the total number of gifted subscriptions.<span style="vertical-align: middle; line-height: 1; display: inline-block;" class="is-size-4 has-text-weight-bold has-text-danger">*</span></li>
                    <li><span class="has-text-weight-bold">(count)</span> for the number of gifted subscriptions.</li>
                    <li><span class="has-text-weight-bold">(level)</span> for the hype train level.</li>
                </ul>
            </div>
            <form action="module_data_post.php" method="POST" id="chatAlertsForm">
                <div class="field">
                    <label class="has-text-white">Follower Alert</label>
                    <div class="control">
                        <input class="input" type="text" name="follower_alert" value="Thank you (user) for following! Welcome to the channel!">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white">Cheer Alert</label>
                    <div class="control">
                        <input class="input" type="text" name="cheer_alert" value="Thank you (user) for (bits) bits! You've given a total of (total-bits) bits.">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white">Raid Alert</label>
                    <div class="control">
                        <input class="input" type="text" name="raid_alert" value="Incredible! (user) and (viewers) viewers have joined the party! Let's give them a warm welcome!">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white">Subscription Alert <span class="is-size-4 has-text-weight-bold has-text-danger">*</span></label>
                    <div class="control">
                        <input class="input" type="text" name="subscription_alert" value="Thank you (user) for subscribing! You are now a (tier) subscriber for (months) months!">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white">Gift Subscription Alert <span class="is-size-4 has-text-weight-bold has-text-danger">*</span></label>
                    <div class="control">
                        <input class="input" type="text" name="gift_subscription_alert" value="Thank you (user) for gifting a (tier) subscription to (count) members! You have gifted a total of (total-gifted) to the community!">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white">Hype Train Start</label>
                    <div class="control">
                        <input class="input" type="text" name="hype_train_start" value="The Hype Train has started! Starting at level: (level)">
                    </div>
                </div>
                <di class="field">
                    <label class="has-text-white">Hype Train End</label>
                    <div class="control">
                        <input class="input" type="text" name="hype_train_end" value="The Hype Train has ended at level (level)!">
                    </div>
                </di>
                <br>
                <div class="field">
                    <div class="control">
                        <button class="button is-primary" type="submit">Save Settings</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<br><br>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle tab navigation
    document.querySelectorAll('.tab a').forEach(function(tabLink) {
        tabLink.addEventListener('click', function(e) {
            // Let the default link behavior handle navigation
        });
    });

    // File upload handling
    let dropArea = document.getElementById('drag-area');
    let fileInput = document.getElementById('filesToUpload');
    let fileList = document.getElementById('file-list');

    if (dropArea) {
        dropArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.add('dragging');
        });

        dropArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragging');
        });

        dropArea.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragging');
            let dt = e.dataTransfer;
            let files = dt.files;
            fileInput.files = files;
            updateFileList(files);
        });

        dropArea.addEventListener('click', function() {
            fileInput.click();
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', function() {
            updateFileList(this.files);
            if(this.files.length > 0) {
                uploadFiles(this.files);
            }
        });
    }

    function updateFileList(files) {
        if (!fileList) return;
        
        fileList.innerHTML = '';
        for (let i = 0; i < files.length; i++) {
            let fileItem = document.createElement('div');
            fileItem.textContent = files[i].name;
            fileList.appendChild(fileItem);
        }
    }
    
    function uploadFiles(files) {
        let formData = new FormData();
        for (let i = 0; i < files.length; i++) {
            formData.append('filesToUpload[]', files[i]);
        }
        // Show upload status indicator
        $('#file-list').append('<div class="notification is-info">Uploading files, please wait...</div>');
        $.ajax({
            url: 'module_data_post.php',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            xhr: function() {
                let xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        let percentComplete = (e.loaded / e.total) * 100;
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                // Check if response is JSON
                let result;
                try {
                    if (typeof response === 'string') {
                        result = JSON.parse(response);
                    } else {
                        result = response;
                    }
                    if (result.success) {
                        // Update the progress bar with new storage values
                        if (result.storage_percentage) {
                            $('#uploadProgressBar').css('width', result.storage_percentage + '%');
                            $('#uploadProgressBar').text(Math.round(result.storage_percentage * 100) / 100 + '%');
                        }
                        // Show success message
                        $('#file-list').html('<div class="notification is-success">Upload completed successfully!</div>');
                        // Reload the page after a short delay
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $('#file-list').html('<div class="notification is-danger">Upload failed: ' + (result.status || 'Unknown error') + '</div>');
                    }
                } catch (e) {
                    console.error("Error parsing response:", e);
                    $('#file-list').html('<div class="notification is-danger">Error processing server response</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('Upload failed: ' + textStatus + ' - ' + errorThrown);
                console.error('Response:', jqXHR.responseText);
                $('#file-list').html('<div class="notification is-danger">Upload failed: ' + textStatus + '<br>Please check file size limits and try again.</div>');
            }
        });
    }

    // Test sound buttons
    document.querySelectorAll('.test-sound').forEach(function(button) {
        button.addEventListener('click', function() {
            const fileName = this.getAttribute('data-file');
            sendStreamEvent('SOUND_ALERT', fileName);
        });
    });

    // Delete single file buttons
    document.querySelectorAll('.delete-single').forEach(function(button) {
        button.addEventListener('click', function() {
            const fileName = this.getAttribute('data-file');
            if (confirm('Are you sure you want to delete "' + fileName + '"?')) {
                let form = document.getElementById('deleteForm');
                let input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'delete_files[]';
                input.value = fileName;
                form.appendChild(input);
                form.submit();
            }
        });
    });
    // Add event listener for mapping select boxes
    $('.mapping-select').on('change', function() {
        $(this).closest('form').submit();
    });
});

// Function to send a stream event
function sendStreamEvent(eventType, fileName) {
    const xhr = new XMLHttpRequest();
    const url = "notify_event.php";
    const params = `event=${eventType}&sound=${encodeURIComponent(fileName)}&channel_name=<?php echo $username; ?>&api_key=<?php echo $api_key; ?>`;
    xhr.open("POST", url, true);
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
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
            } else {
                console.error(`Error sending ${eventType} event: ${xhr.responseText}`);
            }
        }
    };
    xhr.send(params);
}
</script>
</body>
</html>