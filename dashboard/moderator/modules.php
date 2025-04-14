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
include '/var/www/config/twitch.php';
include 'modding_access.php';
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$channelData = $stmt->fetch(PDO::FETCH_ASSOC);
$timezone = $channelData['timezone'] ?? 'UTC';
date_default_timezone_set($timezone);
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
    <?php if (isset($_SESSION['update_message'])): ?><div class="notification is-success"><?php echo $_SESSION['update_message']; unset($_SESSION['update_message']);?></div><?php endif; ?>
    <div class="columns is-desktop is-multiline is-centered box-container">
        <div class="column is-5 bot-box" id="chat-protection-settings" style="position: relative;">
            <h1 class="title is-3 has-text-centered">Chat Protection</h1>
            <h1 class="subtitle is-5 has-text-centered">Manage chat protection settings</h1>
            <button class="button is-primary" onclick="openModal('chatProtectionModal')">Open Settings</button>
        </div>
        <div class="column is-5 bot-box" id="stable-bot-status" style="position: relative;">
            <h2 class="title is-3 has-text-centered">Manage Joke Blacklist</h2>
            <h2 class="subtitle is-5 has-text-centered" style="text-align: center;">Set which category is blocked</h2>
            <button class="button is-primary" onclick="openModal('jokeBlacklistModal')">Open Settings</button>
        </div>
        <div class="column is-5 bot-box" id="welcome-message-settings" style="position: relative;">
            <h1 class="title is-3 has-text-centered">Custom Welcome Messages</h1>
            <h1 class="subtitle is-5 has-text-centered">Set default welcome messages</h1>
            <button class="button is-primary" onclick="openModal('welcomeMessageModal')">Open Settings</button>
        </div>
        <div class="column is-5 bot-box" id="" style="position: relative;">
            <h1 class="title is-3 has-text-centered">Ad Notices (BETA v5.4)</h1>
            <h1 class="subtitle is-5 has-text-centered">Set what the bot does when an ad plays on your channel</h1>
            <button class="button is-primary" onclick="openModal('adNoticesModal')">Open Settings</button>
        </div>
        <div class="column is-5 bot-box" id="" style="position: relative;">
            <h1 class="title is-3 has-text-centered">Twitch Audio Alerts<br>(Under Development)</h1>
            <h1 class="subtitle is-5 has-text-centered">Twitch Sound Alerts: Followers, Cheers, Subs and Raids</h1>
            <button class="button is-primary" onclick="openModal('twitchAudioAlertsModal')">Open Settings</button>
        </div>
        <div class="column is-5 bot-box" id="" style="position: relative;">
            <h1 class="title is-3 has-text-centered">Twitch Chat Alerts<br>(Under Development)</h1>
            <h1 class="subtitle is-5 has-text-centered">Twitch Chat alerts: Followers, Cheers, Subs and Raids</h1>
            <button class="button is-primary" onclick="openModal('twitchChatAlertsModal')">Open Settings</button>
        </div>
    </div>
</div>

<!-- Joke Blacklist Modal -->
<div id="jokeBlacklistModal" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content" style="position: relative;">
        <button class="modal-close is-large" aria-label="close" onclick="closeModal('jokeBlacklistModal')" style="position: absolute; top: 10px; right: 10px;"></button>
        <div class="box">
            <form method="POST" action="module_data_post.php">
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
</div>

<!-- Welcome Message Modal -->
<div id="welcomeMessageModal" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content" style="position: relative;">
        <button class="modal-close is-large" aria-label="close" onclick="closeModal('welcomeMessageModal')" style="position: absolute; top: 10px; right: 10px;"></button>
        <div class="box">
            <h2 class="title is-3 has-text-white">Custom Welcome Messages</h2>
            <form method="POST" action="module_data_post.php">
                <div class="notification is-info">
                    <strong>Info:</strong> You can use the <code>(user)</code> variable in the welcome message. It will be replaced with the username of the user entering the chat.
                </div>
                <div class="field">
                    <label class="has-text-white has-text-left">
                        Default New Member Welcome Message
                    </label>
                    <div class="control">
                        <input class="input" type="text" name="new_default_welcome_message" value="<?php echo $new_default_welcome_message ? $new_default_welcome_message : '(user) is new to the community, let\'s give them a warm welcome!'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white has-text-left">
                        Default Returning Member Welcome Message
                    </label>
                    <div class="control">
                        <input class="input" type="text" name="default_welcome_message" value="<?php echo $default_welcome_message ? $default_welcome_message : 'Welcome back (user), glad to see you again!'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white has-text-left">
                        Default New VIP Welcome Message
                    </label>
                    <div class="control">
                        <input class="input" type="text" name="new_default_vip_welcome_message" value="<?php echo $new_default_vip_welcome_message ? $new_default_vip_welcome_message : 'ATTENTION! A very important person has entered the chat, welcome (user)'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white has-text-left">
                        Default Returning VIP Welcome Message
                    </label>
                    <div class="control">
                        <input class="input" type="text" name="default_vip_welcome_message" value="<?php echo $default_vip_welcome_message ? $default_vip_welcome_message : 'ATTENTION! A very important person has entered the chat, welcome (user)'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white has-text-left">
                        Default New Mod Welcome Message
                    </label>
                    <div class="control">
                        <input class="input" type="text" name="new_default_mod_welcome_message" value="<?php echo $new_default_mod_welcome_message ? $new_default_mod_welcome_message : 'MOD ON DUTY! Welcome in (user), the power of the sword has increased!'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="has-text-white has-text-left">
                        Default Returning Mod Welcome Message
                    </label>
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
</div>

<!-- Twitch Audio Alerts Modal -->
<div id="twitchAudioAlertsModal" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content custom-width" style="position: relative;">
        <button class="modal-close is-large" aria-label="close" onclick="closeModal('twitchAudioAlertsModal')" style="position: absolute; top: 10px; right: 10px;"></button>
        <div class="box">
            <h2 class="title is-3">Manage Twitch Event Sound Alerts:</h2>
            <div class="columns is-desktop is-multiline box-container is-centered" style="width: 100%;">
                <div class="column is-4" id="walkon-upload" style="position: relative;">
                    <h1 class="title is-4">Upload MP3 Files:</h1>
                    <form action="module_data_post.php" method="POST" enctype="multipart/form-data" id="uploadForm">
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
                                        $current_reward_id = isset($twitchSoundAlertMappings[$file]) ? $twitchSoundAlertMappings[$file] : null;
                                        $mappedEvents = [];
                                        foreach ($twitchSoundAlertMappings as $mappedFile => $mappedEvent) {
                                            if ($mappedFile !== $file && $mappedEvent) {
                                                $mappedEvents[] = $mappedEvent;
                                            }
                                        }
                                        $allEvents = ['Follow', 'Raid', 'Cheer', 'Subscription'];
                                        $availableEvents = array_diff($allEvents, $mappedEvents);
                                        ?>
                                        <?php if ($current_reward_id): ?>
                                            <em><?php echo htmlspecialchars($current_reward_id); ?></em>
                                        <?php else: ?>
                                            <em>Not Mapped</em>
                                        <?php endif; ?>
                                        <br>
                                        <?php if (!empty($availableEvents) || !$current_reward_id): ?>
                                            <form action="module_data_post.php" method="POST" class="mapping-form">
                                                <input type="hidden" name="sound_file" value="<?php echo htmlspecialchars($file); ?>">
                                                <select name="twitch_alert_id" class="mapping-select" onchange="this.form.submit()">
                                                    <option value="">-- Select Event --</option>
                                                    <?php
                                                    foreach ($availableEvents as $evt) {
                                                        if ($current_reward_id !== $evt) {
                                                            echo '<option value="' . $evt . '">' . $evt . '</option>';
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
</div>

<!-- Twitch Chat Alerts Modal -->
<div id="twitchChatAlertsModal" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content custom-width" style="position: relative;">
        <button class="modal-close is-large" aria-label="close" onclick="closeModal('twitchChatAlertsModal')" style="position: absolute; top: 10px; right: 10px;"></button>
        <div class="box">
            <h2 class="title is-3">Configure Chat Alerts:</h2>
            <div class="columns is-desktop is-multiline box-container is-centered" style="width: 100%;">
                <div class="column is-12" id="chat-alerts-settings" style="position: relative;">
                    <div class="notification is-info">
                        <span class="has-text-weight-bold">Variables:</span><br>
                        <ul>
                            <li><span class="has-text-weight-bold">(user)</span> for the username of the user.</li>
                            <li><span class="has-text-weight-bold">(bits)</span> for the number of bits for the cheer message.</li>
                            <li><span class="has-text-weight-bold">(viewers)</span> for the number of viewers in the raid message.</li>
                            <li><span class="has-text-weight-bold">(tier)</span> for the subscription tier.</li>
                            <li><span class="has-text-weight-bold">(months)</span> for the number of months subscribed.</li>
                            <li><span class="has-text-weight-bold">(count)</span> for the number of gifted subscriptions.</li>
                            <li><span class="has-text-weight-bold">(level)</span> for the hype train level.</li>
                        </ul>
                    </div>
                    <form action="module_data_post.php" method="POST" id="chatAlertsForm">
                        <div class="field">
                            <label class="has-text-white has-text-left">Follower Alert</label>
                            <div class="control"><input class="input" type="text" name="message_alert" value="Thank you (user) for following! Welcome to the channel!"></div>
                        </div>
                        <div class="field">
                            <label class="has-text-white has-text-left">Cheer Alert</label>
                            <div class="control"><input class="input" type="text" name="command_alert" value="Thank you (user) for (bits) bits!"></div>
                        </div>
                        <div class="field">
                            <label class="has-text-white has-text-left">Raid Alert</label>
                            <div class="control"><input class="input" type="text" name="mention_alert" value="Incredible! (user) and (viewers) viewers have joined the party! Let's give them a warm welcome!"></div>
                        </div>
                        <div class="field">
                            <label class="has-text-white has-text-left">Subscription Alert</label>
                            <div class="control"><input class="input" type="text" name="mention_alert" value="Thank you (user) for subscribing! You are now a (tier) subscriber for (months) months!"></div>
                        </div>
                        <div class="field">
                            <label class="has-text-white has-text-left">Gift Subscription Alert</label>
                            <div class="control"><input class="input" type="text" name="gift_subscription_alert" value="Thank you (user) for gifting a (tier) subscription to (count) members!"></div>
                        </div>
                        <div class="field">
                            <label class="has-text-white has-text-left">Hype Train Start</label>
                            <div class="control"><input class="input" type="text" name="hype_train_start" value="The Hype Train has started! Starting at level: (level)"></div>
                        </div>
                        <div class="field">
                            <label class="has-text-white has-text-left">Hype Train End</label>
                            <div class="control"><input class="input" type="text" name="hype_train_end" value="The Hype Train has ended at level (level)!"></div>
                        </div>
                        <button class="button is-primary" type="submit" disabled>Save Chat Alert Settings</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Ad Notices Modal -->
<div id="adNoticesModal" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content" style="position: relative;">
        <button class="modal-close is-large" aria-label="close" onclick="closeModal('adNoticesModal')" style="position: absolute; top: 10px; right: 10px;"></button>
        <div class="box">
            <h2 class="title is-3">Ad Notices (BETA v5.4)</h2>
            <div class="notification is-info">
                You can use the variable (duration) which will be replaced with the ads' duration.
            </div>
            <form method="POST" action="module_data_post.php">
                <div class="field">
                    <label class="label">Ad Starting Message</label>
                    <div class="control">
                        <input class="input" type="text" name="ad_start_message" placeholder="Message when ads start" value="<?php echo htmlspecialchars($ad_start_message); ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="label">Ad Ended Message</label>
                    <div class="control">
                        <input class="input" type="text" name="ad_end_message" placeholder="Message when ads end" value="<?php echo htmlspecialchars($ad_end_message); ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="enable_ad_notice" value="1" <?php echo $enable_ad_notice ? 'checked' : ''; ?>> Enable Ad Notice
                    </label>
                </div>
                <button class="button is-primary" type="submit">Save Ad Notice Settings</button>
            </form>
        </div>
    </div>
</div>

<!-- Chat Protection Modal -->
<div id="chatProtectionModal" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content custom-width" style="position: relative;">
        <button class="modal-close is-large" aria-label="close" onclick="closeModal('chatProtectionModal')" style="position: absolute; top: 10px; right: 10px;"></button>
        <div class="box">
            <?php include('protection.php'); ?>
        </div>
    </div>
</div>

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
    });

    $('#uploadForm').attr('action', ''); // Ensure action is empty

    $('#uploadForm').on('submit', function(e) {
        e.preventDefault(); 
        let files = fileInput.prop('files');
        console.log("Form submitted. Files selected:", files);
        if (files.length === 0) {
            alert('No files selected!');
            return;
        }
        uploadFiles(files);
    });

    function uploadFiles(files) {
        console.log("Starting upload:", files);
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
                        console.log("Upload progress: " + Math.round(percentComplete) + "%");
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                location.reload();
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

</script>
</body>
</html>