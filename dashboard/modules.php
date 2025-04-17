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
include 'module_data_post.php';
include "mod_access.php";
foreach ($profileData as $profile) {
    $timezone = $profile['timezone'];
    $weather = $profile['weather_location'];
}
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
    <div class="notification is-danger">This page is currently under maintenance. Features will remain unavailable until the update is complete. The absence of this notice will indicate that the work is finished.</div>
    <br>
    <?php if (isset($_SESSION['update_message'])): ?><div class="notification is-success"><?php echo $_SESSION['update_message']; unset($_SESSION['update_message']);?></div><?php endif; ?>
    <div class="columns is-desktop is-multiline is-centered box-container">
        <div class="column is-5 bot-box" id="chat-protection-settings" style="position: relative;">
            <h1 class="title is-3 has-text-centered">Chat Protection</h1>
            <h1 class="subtitle is-5 has-text-centered">Manage chat protection settings</h1>
            <button class="button is-primary load-content" data-content="chat-protection">Load Settings</button>
        </div>
        <div class="column is-5 bot-box" id="stable-bot-status" style="position: relative;">
            <h2 class="title is-3 has-text-centered">Manage Joke Blacklist</h2>
            <h2 class="subtitle is-5 has-text-centered" style="text-align: center;">Set which category is blocked</h2>
            <button class="button is-primary load-content" data-content="joke-blacklist">Load Settings</button>
        </div>
        <div class="column is-5 bot-box" id="welcome-message-settings" style="position: relative;">
            <h1 class="title is-3 has-text-centered">Custom Welcome Messages</h1>
            <h1 class="subtitle is-5 has-text-centered">Set default welcome messages</h1>
            <button class="button is-primary load-content" data-content="welcome-messages">Load Settings</button>
        </div>
        <div class="column is-5 bot-box" id="" style="position: relative;">
            <h1 class="title is-3 has-text-centered">Ad Notices (BETA v5.4)</h1>
            <h1 class="subtitle is-5 has-text-centered">Set what the bot does when an ad plays on your channel</h1>
            <button class="button is-primary load-content" data-content="ad-notices">Load Settings</button>
        </div>
        <div class="column is-5 bot-box" id="twitch-audio-alerts" style="position: relative;">
            <h1 class="title is-3 has-text-centered">Twitch Audio Alerts</h1>
            <h1 class="subtitle is-5 has-text-centered">Twitch Sound Alerts: Followers, Cheers, Subs, Raids, Gift Subs and Hype Trains</h1>
            <button class="button is-primary load-content" data-content="twitch-audio-alerts">Load Sound Alerts</button>
        </div>
        <div class="column is-5 bot-box" id="" style="position: relative;">
            <h1 class="title is-3 has-text-centered">Twitch Chat Alerts<br>(Under Development)</h1>
            <h1 class="subtitle is-5 has-text-centered">Twitch Chat alerts: Followers, Cheers, Subs and Raids</h1>
            <button class="button is-primary load-content" data-content="twitch-chat-alerts">Load Settings</button>
        </div>
    </div>
</div>

<!-- Content Containers for Dynamic Loading -->
<div class="container">
    <div id="dynamic-content" class="mt-5" style="display: none;">
        <div class="box">
            <div class="is-flex is-justify-content-space-between">
                <h2 class="title is-3" id="dynamic-content-title"></h2>
                <button class="button is-danger close-dynamic-content">Close</button>
            </div>
            <div id="dynamic-content-body"></div>
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
                    <form action="javascript:void(0);" method="POST" enctype="multipart/form-data" id="uploadForm">
                        <label for="filesToUpload" class="drag-area" id="drag-area">
                            <span>Drag & Drop files here or</span>
                            <span>Browse Files</span>
                            <input type="file" name="filesToUpload[]" id="filesToUpload" multiple>
                        </label>
                        <br>
                        <div id="file-list"></div>
                        <br>
                        <button type="submit" class="button is-primary" id="uploadButton">Upload MP3 Files</button>
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
                                        $allEvents = ['Follow', 'Raid', 'Cheer', 'Subscription', 'Gift Subscription', 'HypeTrain Start', 'HypeTrain End'];
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
                You can use the variable (duration) which will be replaced with the ads' duration.<br>
                You can use the variable (minutes) which will be replaced with upcoming ads' duration in minutes.<br>
            </div>
            <form method="POST" action="module_data_post.php">
                <div class="field">
                    <label class="label">Ad Upcoming Message</label>
                    <div class="control">
                        <input class="input" type="text" name="ad_upcoming_message" placeholder="Message when ads are upcoming" value="<?php echo htmlspecialchars($ad_upcoming_message); ?>">
                    </div>
                </div>
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

    // Handle all form submissions in the modals via AJAX
    $('form').on('submit', function(e) {
        // Special cases that shouldn't use AJAX
        if ($(this).attr('id') === 'uploadForm') {
            return true;
        }
        // Prevent default form submission for all other forms
        e.preventDefault();
        const form = $(this);
        const formData = form.serialize();
        $.ajax({
            type: 'POST',
            url: 'module_data_post.php',
            data: formData,
            success: function(response) {
                // Show success message without closing the modal
                const modal = form.closest('.modal');
                if (!modal.find('.ajax-notification').length) {
                    $('<div class="notification is-success ajax-notification">Settings saved successfully!</div>')
                        .insertBefore(form)
                        .delay(3000)
                        .fadeOut(500, function() {
                            $(this).remove();
                        });
                }
            },
            error: function() {
                // Show error message
                const modal = form.closest('.modal');
                if (!modal.find('.ajax-notification').length) {
                    $('<div class="notification is-danger ajax-notification">Error saving settings. Please try again.</div>')
                        .insertBefore(form)
                        .delay(3000)
                        .fadeOut(500, function() {
                            $(this).remove();
                        });
                }
            }
        });
    });
    // Special handling for the mapping-form which uses direct submission via select change
    $('.mapping-form').on('change', '.mapping-select', function(e) {
        e.preventDefault();
        const form = $(this).closest('form');
        const formData = form.serialize();
        $.ajax({
            type: 'POST',
            url: 'module_data_post.php',
            data: formData,
            success: function(response) {
                // Show temporary success notification near the select element
                const selectElement = form.find('.mapping-select');
                if (!selectElement.siblings('.ajax-notification').length) {
                    $('<div class="notification is-success ajax-notification is-small" style="padding: 0.5rem; margin: 0.5rem 0;">Mapping updated!</div>')
                        .insertAfter(selectElement)
                        .delay(2000)
                        .fadeOut(500, function() {
                            $(this).remove();
                        });
                }
                // Reload the page after a delay to update the mappings display
                setTimeout(function() {
                    location.reload();
                }, 2500);
            }
        });
    });
    // Handle the delete form submission via AJAX
    $('#deleteForm').on('submit', function(e) {
        e.preventDefault();
        const form = $(this);
        const formData = form.serialize();
        $.ajax({
            type: 'POST',
            url: 'module_data_post.php',
            data: formData,
            success: function(response) {
                // Show success message without closing the modal
                const modal = form.closest('.modal');
                if (!modal.find('.ajax-notification').length) {
                    $('<div class="notification is-success ajax-notification">Files deleted successfully!</div>')
                        .insertBefore(form)
                        .delay(2000)
                        .fadeOut(500, function() {
                            $(this).remove();
                        });
                }
                // Reload the page after a delay to update the file list
                setTimeout(function() {
                    location.reload();
                }, 2500);
            }
        });
    });
    // Handle the delete-single button clicks
    $('.delete-single').on('click', function() {
        let fileName = $(this).data('file');
        if (confirm('Are you sure you want to delete "' + fileName + '"?')) {
            // Create a temporary form with the file to delete
            const tempForm = $('<form></form>');
            tempForm.append($('<input>').attr({
                type: 'hidden',
                name: 'delete_files[]',
                value: fileName
            }));
            // Send AJAX request
            $.ajax({
                type: 'POST',
                url: 'module_data_post.php',
                data: tempForm.serialize(),
                success: function(response) {
                    // Show success message
                    const button = $('[data-file="' + fileName + '"]');
                    const row = button.closest('tr');
                    row.fadeOut(500, function() {
                        // Reload the page after row animation completes
                        location.reload();
                    });
                }
            });
        }
    });
    // Rest of the existing dropArea code
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
        // Add a notification to show upload is in progress
        if (!$('#upload-status').length) {
            $('#fileList').after('<div id="upload-status" class="notification is-info">Uploading files, please wait...</div>');
        }
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
                        $('#upload-status').html('Uploading: ' + Math.round(percentComplete) + '%');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                $('#upload-status').removeClass('is-info').addClass('is-success').html('Upload complete! Refreshing...');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            },
            error: function(jqXHR, textStatus, errorThrown) {
                $('#upload-status').removeClass('is-info').addClass('is-danger')
                    .html('Upload failed: ' + textStatus + '. Please try again.');
                console.error('Upload failed: ' + textStatus + ' - ' + errorThrown);
            }
        });
    }
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

// Dynamic content loading functionality
$(document).ready(function() {
    // Handle dynamic content loading buttons
    $('.load-content').on('click', function() {
        const contentType = $(this).data('content');
        $('#dynamic-content-title').text('');
        $('#dynamic-content-body').html('<div class="has-text-centered"><p>Loading content...</p><progress class="progress is-primary" max="100"></progress></div>');
        $('#dynamic-content').show();
        
        // Load different content based on the button clicked
        if (contentType === 'twitch-audio-alerts') {
            loadTwitchAudioAlerts();
        } else if (contentType === 'joke-blacklist') {
            loadJokeBlacklist();
        } else if (contentType === 'welcome-messages') {
            loadWelcomeMessages();
        } else if (contentType === 'ad-notices') {
            loadAdNotices();
        } else if (contentType === 'chat-protection') {
            loadChatProtection();
        } else if (contentType === 'twitch-chat-alerts') {
            loadTwitchChatAlerts();
        }
    });
    
    // Close dynamic content area when close button clicked
    $('.close-dynamic-content').on('click', function() {
        $('#dynamic-content').hide();
    });
    
    // Function to load Joke Blacklist content
    function loadJokeBlacklist() {
        $('#dynamic-content-title').text('Manage Joke Blacklist');
        
        let html = `
        <form method="POST" action="module_data_post.php" id="jokeBlacklistForm">
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
            <div id="blacklist-status-message"></div>
            <button class="button is-primary" type="submit">Save Settings</button>
        </form>`;
        
        $('#dynamic-content-body').html(html);
        
        // Handle form submission via AJAX
        $('#jokeBlacklistForm').on('submit', function(e) {
            e.preventDefault();
            const form = $(this);
            const formData = form.serialize();
            
            $.ajax({
                type: 'POST',
                url: 'module_data_post.php',
                data: formData,
                success: function() {
                    $('#blacklist-status-message').html('<div class="notification is-success">Settings saved successfully!</div>');
                    setTimeout(function() {
                        $('#blacklist-status-message .notification').fadeOut();
                    }, 3000);
                },
                error: function() {
                    $('#blacklist-status-message').html('<div class="notification is-danger">Error saving settings. Please try again.</div>');
                }
            });
        });
    }
    
    // Function to load Welcome Messages content
    function loadWelcomeMessages() {
        $('#dynamic-content-title').text('Custom Welcome Messages');
        
        let html = `
        <form method="POST" action="module_data_post.php" id="welcomeMessagesForm">
            <div class="notification is-info">
                <strong>Info:</strong> You can use the <code>(user)</code> variable in the welcome message. It will be replaced with the username of the user entering the chat.
            </div>
            <div class="field">
                <label class="label">Default New Member Welcome Message</label>
                <div class="control">
                    <input class="input" type="text" name="new_default_welcome_message" value="<?php echo htmlspecialchars($new_default_welcome_message); ?>">
                </div>
            </div>
            <div class="field">
                <label class="label">Default Returning Member Welcome Message</label>
                <div class="control">
                    <input class="input" type="text" name="default_welcome_message" value="<?php echo htmlspecialchars($default_welcome_message); ?>">
                </div>
            </div>
            <div class="field">
                <label class="label">Default New VIP Welcome Message</label>
                <div class="control">
                    <input class="input" type="text" name="new_default_vip_welcome_message" value="<?php echo htmlspecialchars($new_default_vip_welcome_message); ?>">
                </div>
            </div>
            <div class="field">
                <label class="label">Default Returning VIP Welcome Message</label>
                <div class="control">
                    <input class="input" type="text" name="default_vip_welcome_message" value="<?php echo htmlspecialchars($default_vip_welcome_message); ?>">
                </div>
            </div>
            <div class="field">
                <label class="label">Default New Mod Welcome Message</label>
                <div class="control">
                    <input class="input" type="text" name="new_default_mod_welcome_message" value="<?php echo htmlspecialchars($new_default_mod_welcome_message); ?>">
                </div>
            </div>
            <div class="field">
                <label class="label">Default Returning Mod Welcome Message</label>
                <div class="control">
                    <input class="input" type="text" name="default_mod_welcome_message" value="<?php echo htmlspecialchars($default_mod_welcome_message); ?>">
                </div>
            </div>
            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="send_welcome_messages" value="1" <?php echo $send_welcome_messages ? 'checked' : ''; ?>> Enable welcome messages
                </label>
            </div>
            <div id="welcome-status-message"></div>
            <button class="button is-primary" type="submit">Save Welcome Settings</button>
        </form>`;
        
        $('#dynamic-content-body').html(html);
        
        // Handle form submission via AJAX
        $('#welcomeMessagesForm').on('submit', function(e) {
            e.preventDefault();
            const form = $(this);
            const formData = form.serialize();
            
            $.ajax({
                type: 'POST',
                url: 'module_data_post.php',
                data: formData,
                success: function() {
                    $('#welcome-status-message').html('<div class="notification is-success">Settings saved successfully!</div>');
                    setTimeout(function() {
                        $('#welcome-status-message .notification').fadeOut();
                    }, 3000);
                },
                error: function() {
                    $('#welcome-status-message').html('<div class="notification is-danger">Error saving settings. Please try again.</div>');
                }
            });
        });
    }
    
    // Function to load Ad Notices content
    function loadAdNotices() {
        $('#dynamic-content-title').text('Ad Notices (BETA v5.4)');
        
        let html = `
        <form method="POST" action="module_data_post.php" id="adNoticesForm">
            <div class="notification is-info">
                You can use the variable (duration) which will be replaced with the ads' duration.<br>
                You can use the variable (minutes) which will be replaced with upcoming ads' duration in minutes.<br>
            </div>
            <div class="field">
                <label class="label">Ad Upcoming Message</label>
                <div class="control">
                    <input class="input" type="text" name="ad_upcoming_message" placeholder="Message when ads are upcoming" value="<?php echo htmlspecialchars($ad_upcoming_message); ?>">
                </div>
            </div>
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
            <div id="ad-notices-status-message"></div>
            <button class="button is-primary" type="submit">Save Ad Notice Settings</button>
        </form>`;
        
        $('#dynamic-content-body').html(html);
        
        // Handle form submission via AJAX
        $('#adNoticesForm').on('submit', function(e) {
            e.preventDefault();
            const form = $(this);
            const formData = form.serialize();
            
            $.ajax({
                type: 'POST',
                url: 'module_data_post.php',
                data: formData,
                success: function() {
                    $('#ad-notices-status-message').html('<div class="notification is-success">Settings saved successfully!</div>');
                    setTimeout(function() {
                        $('#ad-notices-status-message .notification').fadeOut();
                    }, 3000);
                },
                error: function() {
                    $('#ad-notices-status-message').html('<div class="notification is-danger">Error saving settings. Please try again.</div>');
                }
            });
        });
    }
    
    // Function to load Chat Protection content
    function loadChatProtection() {
        $('#dynamic-content-title').text('Chat Protection');
        
        // Load the protection.php content via AJAX
        $.ajax({
            url: 'protection.php',
            type: 'GET',
            success: function(response) {
                $('#dynamic-content-body').html(response);
            },
            error: function() {
                $('#dynamic-content-body').html('<div class="notification is-danger">Failed to load chat protection settings.</div>');
            }
        });
    }
    
    // Function to load Twitch Chat Alerts content
    function loadTwitchChatAlerts() {
        $('#dynamic-content-title').text('Twitch Chat Alerts');
        
        let html = `
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
                <label class="label">Follower Alert</label>
                <div class="control"><input class="input" type="text" name="message_alert" value="Thank you (user) for following! Welcome to the channel!"></div>
            </div>
            <div class="field">
                <label class="label">Cheer Alert</label>
                <div class="control"><input class="input" type="text" name="command_alert" value="Thank you (user) for (bits) bits!"></div>
            </div>
            <div class="field">
                <label class="label">Raid Alert</label>
                <div class="control"><input class="input" type="text" name="mention_alert" value="Incredible! (user) and (viewers) viewers have joined the party! Let's give them a warm welcome!"></div>
            </div>
            <div class="field">
                <label class="label">Subscription Alert</label>
                <div class="control"><input class="input" type="text" name="subscription_alert" value="Thank you (user) for subscribing! You are now a (tier) subscriber for (months) months!"></div>
            </div>
            <div class="field">
                <label class="label">Gift Subscription Alert</label>
                <div class="control"><input class="input" type="text" name="gift_subscription_alert" value="Thank you (user) for gifting a (tier) subscription to (count) members!"></div>
            </div>
            <div class="field">
                <label class="label">Hype Train Start</label>
                <div class="control"><input class="input" type="text" name="hype_train_start" value="The Hype Train has started! Starting at level: (level)"></div>
            </div>
            <div class="field">
                <label class="label">Hype Train End</label>
                <div class="control"><input class="input" type="text" name="hype_train_end" value="The Hype Train has ended at level (level)!"></div>
            </div>
            <div id="chat-alerts-status-message"></div>
            <button class="button is-primary" type="submit" disabled>Save Chat Alert Settings</button>
            <p><em>Coming soon: Chat alerts will be available in a future update.</em></p>
        </form>`;
        
        $('#dynamic-content-body').html(html);
    }

    // Function to load Twitch Audio Alerts content
    function loadTwitchAudioAlerts() {
        $('#dynamic-content-title').text('Twitch Audio Alerts');
        
        // Build the content HTML
        let html = `
        <div class="columns is-desktop is-multiline box-container is-centered">
            <div class="column is-4">
                <h1 class="title is-4">Upload MP3 Files:</h1>
                <form action="javascript:void(0);" method="POST" enctype="multipart/form-data" id="uploadForm">
                    <label for="filesToUpload" class="drag-area" id="drag-area">
                        <span>Drag & Drop files here or</span>
                        <span>Browse Files</span>
                        <input type="file" name="filesToUpload[]" id="filesToUpload" multiple accept=".mp3">
                    </label>
                    <br>
                    <div id="file-list"></div>
                    <br>
                    <button type="submit" class="button is-primary" id="uploadButton">Upload MP3 Files</button>
                </form>
                <br>
                <div class="progress-bar-container">
                    <div id="uploadProgressBar" class="progress-bar has-text-black-bis"></div>
                </div>
                <p id="storage-info"></p>
                <div id="upload-status-messages"></div>
            </div>
            <div class="column is-8">
                <div id="file-list-container">Loading file list...</div>
            </div>
        </div>`;
        
        $('#dynamic-content-body').html(html);
        
        // Get storage information
        $.ajax({
            url: 'get_storage_info.php',
            type: 'GET',
            success: function(response) {
                const data = JSON.parse(response);
                $('#uploadProgressBar').css('width', data.percentage + '%').text(data.percentage + '%');
                $('#storage-info').text(data.used + ' of ' + data.max + ' used');
            }
        });
        
        // Initialize the upload form controls
        initUploadFormHandlers();
        
        // Load the file list via AJAX
        loadFileList();
    }
});

// Initialize upload form handlers
function initUploadFormHandlers() {
    let dropArea = $('#drag-area');
    let fileInput = $('#filesToUpload');
    let fileList = $('#file-list');
    
    // Handle drag and drop
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
    
    // Handle form submission
    $('#uploadForm').on('submit', function(e) {
        e.preventDefault();
        let files = fileInput.prop('files');
        if (files.length === 0) {
            alert('No files selected!');
            return;
        }
        uploadFiles(files);
    });
}

// Function to load the file list via AJAX
function loadFileList() {
    $.ajax({
        url: 'get_file_list.php',
        type: 'GET',
        success: function(response) {
            $('#file-list-container').html(response);
            // Re-attach event handlers for delete and test buttons
            attachFileActionHandlers();
        },
        error: function() {
            $('#file-list-container').html('<div class="notification is-danger">Failed to load file list. Please try refreshing the page.</div>');
        }
    });
}

// Attach handlers for file actions (delete, test)
function attachFileActionHandlers() {
    // Delete single file
    $('.delete-single').on('click', function() {
        let fileName = $(this).data('file');
        if (confirm('Are you sure you want to delete "' + fileName + '"?')) {
            deleteFile(fileName);
        }
    });
    
    // Test sound button
    $('.test-sound').on('click', function() {
        let fileName = $(this).data('file');
        sendStreamEvent("SOUND_ALERT", fileName);
    });
    
    // Handle mapping select changes
    $('.mapping-select').on('change', function() {
        const form = $(this).closest('form');
        const formData = form.serialize();
        $.ajax({
            type: 'POST',
            url: 'module_data_post.php',
            data: formData,
            success: function() {
                // Show success notification
                const select = form.find('.mapping-select');
                $('<div class="notification is-success is-light" style="padding: 0.5rem; margin-top: 0.5rem;">Mapping updated!</div>')
                    .insertAfter(select)
                    .delay(2000)
                    .fadeOut(500, function() {
                        $(this).remove();
                    });
                
                // Reload file list to show updated mappings
                setTimeout(function() {
                    loadFileList();
                }, 2500);
            }
        });
    });
    
    // Handle delete selected button
    $('#delete-selected').on('click', function() {
        const checkedBoxes = $('input[name="delete_files[]"]:checked');
        if (checkedBoxes.length === 0) {
            alert('No files selected for deletion.');
            return;
        }
        
        if (confirm('Are you sure you want to delete ' + checkedBoxes.length + ' file(s)?')) {
            const formData = $('#deleteForm').serialize();
            $.ajax({
                type: 'POST',
                url: 'module_data_post.php',
                data: formData,
                success: function() {
                    // Reload file list
                    loadFileList();
                    $('#upload-status-messages').html('<div class="notification is-success">Selected files deleted successfully!</div>')
                        .delay(3000)
                        .fadeOut(500);
                }
            });
        }
    });
}

// Function to delete a file via AJAX
function deleteFile(fileName) {
    const tempForm = $('<form></form>');
    tempForm.append($('<input>').attr({
        type: 'hidden',
        name: 'delete_files[]',
        value: fileName
    }));
    
    $.ajax({
        type: 'POST',
        url: 'module_data_post.php',
        data: tempForm.serialize(),
        success: function() {
            // Show success message
            $('#upload-status-messages').html('<div class="notification is-success">File deleted successfully!</div>')
                .delay(3000)
                .fadeOut(500);
                
            // Reload file list
            loadFileList();
            
            // Update storage info
            updateStorageInfo();
        }
    });
}

// Function to upload files via AJAX
function uploadFiles(files) {
    let formData = new FormData();
    $.each(files, function(index, file) {
        formData.append('filesToUpload[]', file);
    });
    
    // Show upload status
    $('#upload-status-messages').html('<div class="notification is-info">Uploading files, please wait...</div>');
    
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
                    $('#upload-status-messages').html('<div class="notification is-info">Uploading: ' + Math.round(percentComplete) + '%</div>');
                }
            }, false);
            return xhr;
        },
        success: function() {
            $('#upload-status-messages').html('<div class="notification is-success">Upload complete!</div>');
            
            // Clear file input
            $('#filesToUpload').val('');
            $('#file-list').empty();
            
            // Reload file list
            loadFileList();
            
            // Update storage info
            updateStorageInfo();
        },
        error: function(xhr, status, error) {
            $('#upload-status-messages').html('<div class="notification is-danger">Upload failed: ' + error + '</div>');
        }
    });
}

// Function to update storage info
function updateStorageInfo() {
    $.ajax({
        url: 'get_storage_info.php',
        type: 'GET',
        success: function(response) {
            const data = JSON.parse(response);
            $('#uploadProgressBar').css('width', data.percentage + '%').text(data.percentage + '%');
            $('#storage-info').text(data.used + ' of ' + data.max + ' used');
        }
    });
}
</script>
</body>
</html>