<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title and Header
$pageTitle = t('timed_messages_title');
$pageHeader = t('timed_messages_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Initialize variables for messages or errors
$successMessage = "";
$errorMessage = "";
$displayMessages = "";

// Handle POST requests for adding, editing, or removing timed messages
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Quick toggle enable/disable
    if (isset($_POST['toggle_status']) && isset($_POST['toggle_id'])) {
        $toggle_id = (int)$_POST['toggle_id'];
        $new_status = ((int)$_POST['toggle_status'] === 1) ? 0 : 1;
        try {
            $stmt = $db->prepare("UPDATE timed_messages SET status = ? WHERE id = ?");
            $stmt->bind_param("ii", $new_status, $toggle_id);
            $stmt->execute();
            $successMessage = 'Message ID ' . $toggle_id . ' has been ' . ($new_status ? 'enabled' : 'disabled') . '.';
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $errorMessage = "Error updating status: " . $e->getMessage();
        }
    }
    // Check if the form was submitted for adding a new message
    if (isset($_POST['message']) && isset($_POST['trigger_type'])) {
        $message = $_POST['message'];
        $trigger_type = in_array($_POST['trigger_type'], ['timer', 'chat_lines', 'both']) ? $_POST['trigger_type'] : 'timer';
        $interval = null;
        $chat_line_trigger = null;
        if ($trigger_type === 'timer' || $trigger_type === 'both') {
            $interval = filter_input(INPUT_POST, 'interval', FILTER_VALIDATE_INT, array("options" => array("min_range" => 5, "max_range" => 60)));
            if ($interval === false || $interval === null) {
                $errorMessage = "Interval must be a valid integer between 5 and 60.";
            }
        }
        if (empty($errorMessage) && ($trigger_type === 'chat_lines' || $trigger_type === 'both')) {
            $chat_line_trigger = filter_input(INPUT_POST, 'chat_line_trigger', FILTER_VALIDATE_INT, array("options" => array("min_range" => 5)));
            if ($chat_line_trigger === false || $chat_line_trigger === null) {
                $errorMessage = "Chat Line Trigger must be a valid integer greater than or equal to 5.";
            }
        }
        if (empty($errorMessage)) {
            try {
                $status = 1;
                if ($trigger_type === 'timer') {
                    $stmt = $db->prepare('INSERT INTO timed_messages (`interval_count`, `chat_line_trigger`, `message`, `status`, `trigger_type`) VALUES (?, NULL, ?, ?, ?)');
                    $stmt->bind_param("isis", $interval, $message, $status, $trigger_type);
                } elseif ($trigger_type === 'chat_lines') {
                    $stmt = $db->prepare('INSERT INTO timed_messages (`interval_count`, `chat_line_trigger`, `message`, `status`, `trigger_type`) VALUES (NULL, ?, ?, ?, ?)');
                    $stmt->bind_param("isis", $chat_line_trigger, $message, $status, $trigger_type);
                } else {
                    $stmt = $db->prepare('INSERT INTO timed_messages (`interval_count`, `chat_line_trigger`, `message`, `status`, `trigger_type`) VALUES (?, ?, ?, ?, ?)');
                    $stmt->bind_param("iisis", $interval, $chat_line_trigger, $message, $status, $trigger_type);
                }
                $stmt->execute();
                if ($trigger_type === 'both') {
                    $modeLabel = 'timer: ' . $interval . ' min & chat lines: ' . $chat_line_trigger;
                } elseif ($trigger_type === 'timer') {
                    $modeLabel = 'interval: ' . $interval . ' minute(s)';
                } else {
                    $modeLabel = 'chat lines: ' . $chat_line_trigger;
                }
                $successMessage = 'Timed Message: "' . $_POST['message'] . '" with ' . $modeLabel . ' has been successfully added to the database.';
                $stmt->close();
            } catch (mysqli_sql_exception $e) {
                $errorMessage = "Error adding message: " . $e->getMessage();
            }
        }
    }
    // Check if the form was submitted for removing a message
    elseif (isset($_POST['remove_message'])) {
        $message_id = $_POST['remove_message'];
        // Remove the selected message from the database
        try {
            $stmt = $db->prepare("DELETE FROM timed_messages WHERE id = ?");
            $stmt->bind_param("i", $message_id);
            $stmt->execute();
            // Check if the deletion was successful and provide feedback to the user
            $deleted = $stmt->affected_rows > 0; // Check if any rows were affected
            if ($deleted) {
                $successMessage = "Message removed successfully.";
            } else {
                $errorMessage = "Failed to remove message.";
            }
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $errorMessage = "Error removing message: " . $e->getMessage();
        }
    }
    // Check if the form was submitted for editing the message, interval, or status
    elseif (isset($_POST['edit_message']) && isset($_POST['edit_status']) && isset($_POST['edit_trigger_type'])) {
        $edit_message_id = $_POST['edit_message'];
        $edit_message_content = $_POST['edit_message_content'];
        $edit_status = $_POST['edit_status'];
        $edit_trigger_type = in_array($_POST['edit_trigger_type'], ['timer', 'chat_lines', 'both']) ? $_POST['edit_trigger_type'] : 'timer';
        $edit_interval = null;
        $edit_chat_line_trigger = null;
        if ($edit_trigger_type === 'timer' || $edit_trigger_type === 'both') {
            $edit_interval = filter_input(INPUT_POST, 'edit_interval', FILTER_VALIDATE_INT, array("options" => array("min_range" => 5, "max_range" => 60)));
            if ($edit_interval === false || $edit_interval === null) {
                $errorMessage = "Interval must be a valid integer between 5 and 60.";
            }
        }
        if (empty($errorMessage) && ($edit_trigger_type === 'chat_lines' || $edit_trigger_type === 'both')) {
            $edit_chat_line_trigger = filter_input(INPUT_POST, 'edit_chat_line_trigger', FILTER_VALIDATE_INT, array("options" => array("min_range" => 5)));
            if ($edit_chat_line_trigger === false || $edit_chat_line_trigger === null) {
                $errorMessage = "Chat Line Trigger must be a valid integer greater than or equal to 5.";
            }
        }
        if (empty($errorMessage)) {
            // Check if the edit_message_id exists in the timed_messages table
            $stmt = $db->prepare("SELECT COUNT(*) FROM timed_messages WHERE id = ?");
            $stmt->bind_param("i", $edit_message_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $message_exists = $result->fetch_row()[0];
            $stmt->close();
            if ($message_exists) {
                try {
                    $status_int = ($edit_status === 'True') ? 1 : 0;
                    if ($edit_trigger_type === 'timer') {
                        $stmt = $db->prepare('UPDATE timed_messages SET `interval_count` = ?, `chat_line_trigger` = NULL, `message` = ?, `status` = ?, `trigger_type` = ? WHERE id = ?');
                        $stmt->bind_param("isisi", $edit_interval, $edit_message_content, $status_int, $edit_trigger_type, $edit_message_id);
                    } elseif ($edit_trigger_type === 'chat_lines') {
                        $stmt = $db->prepare('UPDATE timed_messages SET `interval_count` = NULL, `chat_line_trigger` = ?, `message` = ?, `status` = ?, `trigger_type` = ? WHERE id = ?');
                        $stmt->bind_param("isisi", $edit_chat_line_trigger, $edit_message_content, $status_int, $edit_trigger_type, $edit_message_id);
                    } else {
                        $stmt = $db->prepare('UPDATE timed_messages SET `interval_count` = ?, `chat_line_trigger` = ?, `message` = ?, `status` = ?, `trigger_type` = ? WHERE id = ?');
                        $stmt->bind_param("iisisi", $edit_interval, $edit_chat_line_trigger, $edit_message_content, $status_int, $edit_trigger_type, $edit_message_id);
                    }
                    $stmt->execute();
                    $updated = $stmt->affected_rows > 0;
                    if ($updated) {
                        $successMessage = 'Message with ID ' . $edit_message_id . ' updated successfully.';
                    } else {
                        $errorMessage = "Failed to update message.";
                    }
                    $stmt->close();
                } catch (mysqli_sql_exception $e) {
                    $errorMessage = "Error updating message: " . $e->getMessage();
                }
            } else {
                $errorMessage = "Invalid input data.";
            }
        }
    }
    // Redirect with message
    if (!empty($successMessage)) {
        header("Location: {$_SERVER['PHP_SELF']}?successMessage=" . urlencode($successMessage));
        exit();
    } elseif (!empty($errorMessage)) {
        header("Location: {$_SERVER['PHP_SELF']}?errorMessage=" . urlencode($errorMessage));
        exit();
    }
}
$displayMessageData = !empty($_GET['successMessage']) || !empty($_GET['errorMessage']);
if ($displayMessageData) {
    if (!empty($_GET['successMessage'])) {
        $errorMessage = isset($_GET['successMessage']) ? $_GET['successMessage'] : '';
        $displayMessages = "<p class='has-text-black'>" . htmlspecialchars($_GET['successMessage']) . "</p>";
    } elseif (!empty($_GET['errorMessage'])) {
        $errorMessage = isset($_GET['errorMessage']) ? $_GET['errorMessage'] : '';
        $displayMessages = "<p class='has-text-black'>". htmlspecialchars($errorMessage) . "</p>";
    }
}

// Fetch all timed messages for dropdowns
$stmt = $db->prepare("SELECT * FROM timed_messages");
$stmt->execute();
$timedMessagesData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
// Get user's Twitch username & API key from session/database
$twitchUsername = $username;
$userApiKey = isset($_SESSION['api_key']) ? $_SESSION['api_key'] : '';
// Start output buffering for layout template
ob_start();
?>
<div class="section p-0">
    <div class="container">
        <div class="columns is-centered">
            <div class="column is-fullwidth">
                <div class="card has-background-dark has-text-white" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
                    <header class="card-header" style="border-bottom: 1px solid #23272f;">
                        <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                            <span class="icon mr-2"><i class="fas fa-clock"></i></span>
                            <?php echo t('timed_messages_title'); ?>
                        </span>
                    </header>
                    <div class="card-content">
                        <!-- Variables Information Card -->
                        <div class="columns is-desktop is-multiline is-centered mb-5">
                            <div class="column is-fullwidth" style="max-width: 1200px;">
                                <div class="card has-background-dark has-text-white" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
                                    <div class="card-content">
                                        <h5 class="title is-6 mb-2"><span class="icon"><i class="fas fa-info-circle"></i></span> <?php echo t('timed_messages_variables_title') ?: 'Available Variables'; ?></h5>
                                        <ul class="mb-0" style="list-style: disc inside;">
                                            <li><code>(game)</code> – <?php echo t('timed_messages_var_game') ?: 'Displays the current game being played (NEW).'; ?></li>
                                            <li><code>(command.yourcommand)</code> – <?php echo t('timed_messages_var_command') ?: 'Runs a custom command and sends its processed response as an additional chat message.'; ?></li>
                                            <!-- Add more variables here as needed -->
                                        </ul>
                                        <div class="mt-3">
                                            <a href="https://help.botofthespecter.com/custom_variables.php" target="_blank" class="button is-primary is-small">
                                                <span class="icon"><i class="fas fa-code"></i></span>
                                                <span><?php echo t('custom_commands_view_variables') ?: 'View Custom Variables'; ?></span>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- End Variables Information Card -->
                        <div class="notification is-info mb-5">
                            <span class="icon"><i class="fas fa-info-circle"></i></span>
                            <?php echo t('timed_messages_info'); ?>
                        </div>
                        <?php if ($displayMessages): ?>
                            <div class="notification is-primary">
                                <?php echo $displayMessages; ?>
                            </div>
                        <?php endif; ?>
                        <div class="columns is-desktop is-multiline">
                            <!-- Add Timed Message -->
                            <div class="column is-4 is-flex is-flex-direction-column is-fullheight">
                                <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                    <h4 class="title is-5"><?php echo t('timed_messages_add_title'); ?></h4>
                                    <form id="addMessageForm" method="post" action="" onsubmit="return validateForm()">
                                        <div class="field">
                                            <label class="label" for="message"><?php echo t('timed_messages_message_label'); ?></label>
                                            <div class="control">
                                                <input class="input" type="text" name="message" id="message" required maxlength="255" oninput="updateCharCount('message', 'charCount'); toggleAddButton();">
                                                <p id="charCount" class="help">0/255 <?php echo t('timed_messages_characters'); ?></p>
                                                <span id="messageError" class="help is-danger" style="display: none;"><?php echo t('timed_messages_message_required'); ?></span>
                                            </div>
                                        </div>
                                        <div class="field">
                                            <label class="label">Trigger Type <span class="tag is-warning is-light ml-2" style="font-size:0.7rem; vertical-align:middle;">5.8 Beta</span></label>
                                            <div class="control">
                                                <div class="select is-fullwidth">
                                                    <select name="trigger_type" id="trigger_type" onchange="toggleAddTriggerType(); toggleAddButton();">
                                                        <option value="timer">Timer (minutes)</option>
                                                        <option value="chat_lines">Chat Lines</option>
                                                        <option value="both">Both (Timer &amp; Chat Lines)</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <p class="help">Fire on a fixed time interval, or after a set number of chat messages.</p>
                                        </div>
                                        <div class="field" id="add_interval_field">
                                            <label class="label" for="interval"><?php echo t('timed_messages_interval_label'); ?></label>
                                            <div class="control">
                                                <input class="input" type="number" name="interval" id="interval" min="5" max="60" value="5" oninput="toggleAddButton();">
                                                <span id="intervalError" class="help is-danger" style="display: none;"><?php echo t('timed_messages_interval_error'); ?></span>
                                            </div>
                                        </div>
                                        <div class="field" id="add_chat_line_field" style="display:none;">
                                            <label class="label" for="chat_line_trigger"><?php echo t('timed_messages_chat_line_trigger_label'); ?></label>
                                            <div class="control">
                                                <input class="input" type="number" name="chat_line_trigger" id="chat_line_trigger" min="5" value="5" oninput="toggleAddButton();">
                                                <span id="chatLineTriggerError" class="help is-danger" style="display: none;"><?php echo t('timed_messages_chat_line_trigger_error'); ?></span>
                                            </div>
                                        </div>
                                        <div style="flex-grow:1"></div>
                                        <div class="control">
                                            <button type="submit" id="addMessageButton" class="button is-primary is-fullwidth" disabled><?php echo t('timed_messages_add_btn'); ?></button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <!-- Edit Timed Message -->
                            <div class="column is-4 is-flex is-flex-direction-column is-fullheight">
                                <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                    <h4 class="title is-5"><?php echo t('timed_messages_edit_title'); ?></h4>
                                    <?php if (!empty($timedMessagesData)): ?>
                                    <form id="editMessageForm" method="post" action="" onsubmit="return validateEditForm()">
                                        <div class="field">
                                            <label class="label" for="edit_message"><?php echo t('timed_messages_select_edit_label'); ?></label>
                                            <div class="control">
                                                <div class="select is-fullwidth">
                                                    <select name="edit_message" id="edit_message" onchange="showResponse(); toggleEditButton();">
                                                        <option value="" selected><?php echo t('timed_messages_select_edit_placeholder'); ?></option>
                                                        <?php
                                                        usort($timedMessagesData, function($a, $b) {
                                                            return $a['id'] - $b['id'];
                                                        });
                                                        foreach ($timedMessagesData as $message): ?>
                                                            <option value="<?php echo $message['id']; ?>">
                                                                (<?php echo "ID: " . $message['id']; ?>) <?php echo htmlspecialchars($message['message']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="field">
                                            <label class="label">Trigger Type <span class="tag is-warning is-light ml-2" style="font-size:0.7rem; vertical-align:middle;">5.8 Beta</span></label>
                                            <div class="control">
                                                <div class="select is-fullwidth">
                                                    <select name="edit_trigger_type" id="edit_trigger_type" onchange="toggleEditTriggerType();">
                                                        <option value="timer">Timer (minutes)</option>
                                                        <option value="chat_lines">Chat Lines</option>
                                                        <option value="both">Both (Timer &amp; Chat Lines)</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="field" id="edit_interval_field">
                                            <label class="label" for="edit_interval"><?php echo t('timed_messages_interval_label'); ?></label>
                                            <div class="control">
                                                <input class="input" type="number" name="edit_interval" id="edit_interval" min="5" max="60" oninput="toggleEditButton();">
                                            </div>
                                        </div>
                                        <div class="field" id="edit_chat_line_field" style="display:none;">
                                            <label class="label" for="edit_chat_line_trigger"><?php echo t('timed_messages_chat_line_trigger_label'); ?></label>
                                            <div class="control">
                                                <input class="input" type="number" name="edit_chat_line_trigger" id="edit_chat_line_trigger" min="5" oninput="toggleEditButton();">
                                            </div>
                                        </div>
                                        <div class="field">
                                            <label class="label" for="edit_message_content"><?php echo t('timed_messages_message_label'); ?></label>
                                            <div class="control">
                                                <input class="input" type="text" name="edit_message_content" id="edit_message_content" required maxlength="255" oninput="updateCharCount('edit_message_content', 'editCharCount'); toggleEditButton();">
                                                <p id="editCharCount" class="help">0/255 <?php echo t('timed_messages_characters'); ?></p>
                                            </div>
                                        </div>
                                        <div class="field">
                                            <label class="label" for="edit_status"><?php echo t('timed_messages_status_label'); ?></label>
                                            <div class="control">
                                                <div class="select is-fullwidth">
                                                    <select name="edit_status" id="edit_status" onchange="toggleEditButton();">
                                                        <option value="True"><?php echo t('timed_messages_status_enabled'); ?></option>
                                                        <option value="False"><?php echo t('timed_messages_status_disabled'); ?></option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="control">
                                            <button type="submit" id="editMessageButton" class="button is-primary is-fullwidth" disabled><?php echo t('timed_messages_save_btn'); ?></button>
                                        </div>
                                    </form>
                                    <?php else: ?>
                                        <p class="has-text-grey-light"><?php echo t('timed_messages_no_edit'); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- Remove Timed Message -->
                            <div class="column is-4 is-flex is-flex-direction-column is-fullheight">
                                <div class="box has-background-dark is-flex is-flex-direction-column is-fullheight">
                                    <h4 class="title is-5"><?php echo t('timed_messages_remove_title'); ?></h4>
                                    <?php if (!empty($timedMessagesData)): ?>
                                    <form id="removeMessageForm" method="post" action="" class="is-flex is-flex-direction-column is-flex-grow-1">
                                        <div class="field">
                                            <label class="label" for="remove_message"><?php echo t('timed_messages_select_remove_label'); ?></label>
                                            <div class="control">
                                                <div class="select is-fullwidth">
                                                    <select name="remove_message" id="remove_message" onchange="showMessage(); toggleRemoveButton();">
                                                        <option value=""><?php echo t('timed_messages_select_remove_placeholder'); ?></option>
                                                        <?php foreach ($timedMessagesData as $message): ?>
                                                            <option value="<?php echo $message['id']; ?>">
                                                                <?php echo t('timed_messages_message_id'); ?> <?php echo $message['id']; ?> - <?php echo htmlspecialchars(mb_strimwidth($message['message'], 0, 40, "")); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="field">
                                            <label class="label" for="remove_message_content"><?php echo t('timed_messages_message_label'); ?></label>
                                            <div class="control">
                                                <textarea class="textarea" id="remove_message_content" disabled rows="7"></textarea>
                                            </div>
                                        </div>
                                        <div style="flex-grow:1"></div>
                                        <div class="control">
                                            <button type="submit" id="removeMessageButton" class="button is-danger is-fullwidth" disabled><?php echo t('timed_messages_remove_btn'); ?></button>
                                        </div>
                                    </form>
                                    <?php else: ?>
                                        <p class="has-text-grey-light"><?php echo t('timed_messages_no_remove'); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Current Timed Messages Table -->
                <div class="columns is-centered mt-5">
                    <div class="column is-fullwidth">
                        <div class="card has-background-dark has-text-white" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
                            <header class="card-header">
                                <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                                    <span class="icon mr-2"><i class="fas fa-list"></i></span>
                                    Current Timed Messages
                                </span>
                            </header>
                            <div class="card-content">
                                <table class="table is-fullwidth has-background-dark has-text-white">
                                    <thead>
                                        <tr>
                                            <th style="width: 42px; text-align: center; vertical-align: middle;">ID</th>
                                            <th style="vertical-align: middle;">Message</th>
                                            <th style="width: 150px; text-align: center; vertical-align: middle;">Trigger Type <span class="tag is-warning is-light ml-1" style="font-size:0.65rem;">5.8</span></th>
                                            <th style="width: 130px; text-align: center; vertical-align: middle;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($timedMessagesData)): ?>
                                            <?php foreach ($timedMessagesData as $msg): ?>
                                                <tr>
                                                    <td style="text-align: center; vertical-align: middle;"><?php echo $msg['id']; ?></td>
                                                    <td><?php echo htmlspecialchars($msg['message']); ?></td>
                                                    <td style="text-align: center; vertical-align: middle;">
                                                        <?php
                                                        $triggerType = $msg['trigger_type'] ?? 'timer';
                                                        if ($triggerType === 'chat_lines') {
                                                            echo '<span class="tag is-info">Chat Lines: ' . htmlspecialchars($msg['chat_line_trigger']) . '</span>';
                                                        } elseif ($triggerType === 'both') {
                                                            echo '<span class="tag is-warning">Timer: ' . htmlspecialchars($msg['interval_count']) . ' min &amp; Chat Lines: ' . htmlspecialchars($msg['chat_line_trigger']) . '</span>';
                                                        } else {
                                                            echo '<span class="tag is-primary">Timer: ' . htmlspecialchars($msg['interval_count']) . ' min</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td style="text-align: center; vertical-align: middle;">
                                                        <form method="post" action="" style="display:inline;">
                                                            <input type="hidden" name="toggle_id" value="<?php echo $msg['id']; ?>">
                                                            <input type="hidden" name="toggle_status" value="<?php echo $msg['status']; ?>">
                                                            <button type="submit" class="button is-small <?php echo $msg['status'] == 1 ? 'is-success' : 'is-light'; ?>" title="<?php echo $msg['status'] == 1 ? 'Click to disable' : 'Click to enable'; ?>">
                                                                <span class="icon"><i class="fas <?php echo $msg['status'] == 1 ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i></span>
                                                                <span><?php echo $msg['status'] == 1 ? 'Enabled' : 'Disabled'; ?></span>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="4" class="has-text-centered">No timed messages found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
<!-- Hidden fields for YourLinks API -->
<input type="hidden" id="yourlinks_api_key" value="<?php echo htmlspecialchars($userApiKey); ?>">
<input type="hidden" id="yourlinks_username" value="<?php echo htmlspecialchars($twitchUsername); ?>">
<!-- YourLinks URL Shortener Modal -->
<div id="yourlinksModal" class="modal">
    <div class="modal-background"></div>
    <div class="modal-card">
        <header class="modal-card-head" style="background-color: #2c3e50; border-bottom: 3px solid #3498db;">
            <p class="modal-card-title has-text-white">
                <span class="icon"><i class="fas fa-link"></i></span>
                <span>Create Short Link with YourLinks.click</span>
            </p>
            <button id="yourlinks_close_btn" class="delete" aria-label="close"></button>
        </header>
        <section class="modal-card-body" style="background-color: #1e2936;">
            <div id="yourlinks_status" class="mb-4"></div>
            
            <div class="field">
                <label class="label has-text-white">Destination URL</label>
                <div class="control has-icons-left">
                    <input class="input" type="url" id="yourlinks_destination" placeholder="https://example.com" readonly>
                    <span class="icon is-small is-left"><i class="fas fa-globe"></i></span>
                </div>
                <p class="help has-text-grey-light">The URL you entered in the message</p>
            </div>
            <div class="field">
                <label class="label has-text-white">Link Name <span style="color: #f14668;">*</span></label>
                <div class="control has-icons-left">
                    <input class="input" type="text" id="yourlinks_link_name" placeholder="e.g., discord, youtube, twitch" maxlength="50">
                    <span class="icon is-small is-left"><i class="fas fa-link"></i></span>
                </div>
                <p class="help has-text-grey-light">Alphanumeric characters, hyphens, and underscores only. Will be: <code><?php echo htmlspecialchars($twitchUsername); ?>.yourlinks.click/<strong>linkname</strong></code></p>
            </div>
            <div class="field">
                <label class="label has-text-white">Title (Optional)</label>
                <div class="control has-icons-left">
                    <input class="input" type="text" id="yourlinks_title" placeholder="e.g., Join My Discord Server" maxlength="100">
                    <span class="icon is-small is-left"><i class="fas fa-heading"></i></span>
                </div>
                <p class="help has-text-grey-light">Display name for the link (for your reference)</p>
            </div>
        </section>
        <footer class="modal-card-foot" style="justify-content: flex-end; gap: 10px;">
            <button id="yourlinks_cancel_btn" class="button is-light">
                <span class="icon"><i class="fas fa-times"></i></span>
                <span>Cancel</span>
            </button>
            <button id="yourlinks_submit_btn" class="button is-primary">
                <span class="icon"><i class="fas fa-link"></i></span>
                <span>Create Link</span>
            </button>
        </footer>
    </div>
</div>
<?php
$content = ob_get_clean();

// Scripts section
ob_start();
?>
<script src="js/yourlinks-shortener.js?v=<?php echo time(); ?>"></script>
<script>
// Function to show response for editing
function showResponse() {
    var editMessage = document.getElementById('edit_message').value;
    var timedMessagesData = <?php echo json_encode($timedMessagesData); ?>;
    var editMessageContent = document.getElementById('edit_message_content');
    var editIntervalInput = document.getElementById('edit_interval');
    var editChatLineTriggerInput = document.getElementById('edit_chat_line_trigger');
    var editStatus = document.getElementById('edit_status');
    var editTriggerType = document.getElementById('edit_trigger_type');
    var messageData = timedMessagesData.find(m => m.id == editMessage);
    if (messageData) {
        editMessageContent.value = messageData.message;
        editIntervalInput.value = messageData.interval_count || 5;
        editChatLineTriggerInput.value = messageData.chat_line_trigger || 5;
        if (editStatus) editStatus.value = (messageData.status == 1) ? 'True' : 'False';
        if (editTriggerType) editTriggerType.value = messageData.trigger_type || 'timer';
        updateCharCount('edit_message_content', 'editCharCount');
        toggleEditTriggerType();
    } else {
        editMessageContent.value = '';
        editIntervalInput.value = '';
        editChatLineTriggerInput.value = '';
        if (editStatus) editStatus.value = '';
        if (editTriggerType) editTriggerType.value = 'timer';
        document.getElementById('editCharCount').textContent = '0/255 characters';
        document.getElementById('editCharCount').className = 'help';
        toggleEditTriggerType();
    }
    toggleEditButton();
}

// Function to show message content in remove textarea and enable button
function showMessage() {
    var removeMessage = document.getElementById('remove_message').value;
    var timedMessagesData = <?php echo json_encode($timedMessagesData); ?>;
    var removeMessageContent = document.getElementById('remove_message_content');
    var messageData = timedMessagesData.find(m => m.id == removeMessage);
    if (messageData) {
        removeMessageContent.value = messageData.message;
    } else {
        removeMessageContent.value = '';
    }
    toggleRemoveButton();
}

// Function to update character counts
function updateCharCount(inputId, counterId) {
    const input = document.getElementById(inputId);
    const counter = document.getElementById(counterId);
    const maxLength = 255;
    const currentLength = input.value.length;
    // Update the counter text
    counter.textContent = currentLength + '/' + maxLength + ' characters';
    // Update styling based on character count
    if (currentLength > maxLength) {
        counter.className = 'help is-danger';
    } else if (currentLength > maxLength * 0.8) {
        counter.className = 'help is-warning';
    } else {
        counter.className = 'help is-info';
    }
}

// Show/hide add form fields based on trigger type
function toggleAddTriggerType() {
    var triggerType = document.getElementById('trigger_type').value;
    document.getElementById('add_interval_field').style.display = (triggerType === 'timer' || triggerType === 'both') ? '' : 'none';
    document.getElementById('add_chat_line_field').style.display = (triggerType === 'chat_lines' || triggerType === 'both') ? '' : 'none';
}

// Show/hide edit form fields based on trigger type
function toggleEditTriggerType() {
    var triggerType = document.getElementById('edit_trigger_type') ? document.getElementById('edit_trigger_type').value : 'timer';
    var intervalField = document.getElementById('edit_interval_field');
    var chatLineField = document.getElementById('edit_chat_line_field');
    if (intervalField) intervalField.style.display = (triggerType === 'timer' || triggerType === 'both') ? '' : 'none';
    if (chatLineField) chatLineField.style.display = (triggerType === 'chat_lines' || triggerType === 'both') ? '' : 'none';
    toggleEditButton();
}

// Enable/disable add button based on input
function toggleAddButton() {
    var message = document.getElementById('message').value.trim();
    var triggerType = document.getElementById('trigger_type').value;
    var addBtn = document.getElementById('addMessageButton');
    var valid = message.length > 0;
    if (triggerType === 'timer' || triggerType === 'both') {
        var interval = document.getElementById('interval').value;
        valid = valid && interval !== "" && !isNaN(interval) && Number(interval) >= 5 && Number(interval) <= 60;
    }
    if (triggerType === 'chat_lines' || triggerType === 'both') {
        var chatLine = document.getElementById('chat_line_trigger').value;
        valid = valid && chatLine !== "" && !isNaN(chatLine) && Number(chatLine) >= 5;
    }
    addBtn.disabled = !valid;
}

// Enable/disable edit button based on input
function toggleEditButton() {
    var editMessage = document.getElementById('edit_message') ? document.getElementById('edit_message').value : '';
    var editMessageContent = document.getElementById('edit_message_content').value.trim();
    var editStatus = document.getElementById('edit_status') ? document.getElementById('edit_status').value : '';
    var editTriggerType = document.getElementById('edit_trigger_type') ? document.getElementById('edit_trigger_type').value : 'timer';
    var editBtn = document.getElementById('editMessageButton');
    if (!editBtn) return;
    var valid = editMessage !== "" && editMessageContent.length > 0 && editMessageContent.length <= 255 && editStatus !== "";
    if (editTriggerType === 'timer' || editTriggerType === 'both') {
        var editInterval = document.getElementById('edit_interval').value;
        valid = valid && editInterval !== "" && !isNaN(editInterval) && Number(editInterval) >= 5 && Number(editInterval) <= 60;
    }
    if (editTriggerType === 'chat_lines' || editTriggerType === 'both') {
        var editChatLineTrigger = document.getElementById('edit_chat_line_trigger').value;
        valid = valid && editChatLineTrigger !== "" && !isNaN(editChatLineTrigger) && Number(editChatLineTrigger) >= 5;
    }
    editBtn.disabled = !valid;
}

// Enable/disable remove button based on selection
function toggleRemoveButton() {
    var removeMessage = document.getElementById('remove_message').value;
    var removeBtn = document.getElementById('removeMessageButton');
    removeBtn.disabled = (removeMessage === "");
}

// Function to validate the form before submission
function validateForm() {
    // Message length validation
    const messageInput = document.getElementById('message');
    if (messageInput.value.length > 255) {
        document.getElementById('messageError').textContent = '<?php echo t('timed_messages_message_length_error'); ?>';
        document.getElementById('messageError').style.display = 'block';
        return false;
    }
    // Validate trigger-type-specific field
    const triggerType = document.getElementById('trigger_type').value;
    if (triggerType === 'timer' || triggerType === 'both') {
        const intervalInput = document.getElementById('interval');
        if (intervalInput.value < 5 || intervalInput.value > 60) {
            document.getElementById('intervalError').style.display = 'block';
            return false;
        }
    }
    return true;
}

// Function to validate the edit form before submission
function validateEditForm() {
    const editMessageContent = document.getElementById('edit_message_content');
    if (editMessageContent.value.length > 255) {
        alert('<?php echo t('timed_messages_char_limit_alert'); ?>');
        return false;
    }
    return true;
}

// Update the edit form to use validation
document.addEventListener('DOMContentLoaded', function() {
    const editForm = document.querySelector('form:nth-of-type(2)');
    if (editForm) {
        editForm.onsubmit = validateEditForm;
    }
});

// SweetAlert2 for remove confirmation
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('edit_trigger_type')) toggleEditTriggerType();
    toggleEditButton();
    toggleRemoveButton();
    var removeForm = document.getElementById('removeMessageForm');
    if (removeForm) {
        removeForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var select = document.getElementById('remove_message');
            if (!select.value) return;
            Swal.fire({
                title: 'Are you sure?',
                text: "This will permanently remove the selected message.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, remove it!',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    removeForm.submit();
                }
            });
        });
    }
});

// Call the function initially to pre-fill the fields if a default message is selected
window.onload = function() {
    toggleAddTriggerType();
    showResponse();
    updateCharCount('message', 'charCount');
    showMessage();
    toggleEditButton();
    toggleRemoveButton();
    toggleAddButton();
    // Initialize URL shortener for input fields
    yourLinksShortener.initializeField('message');
    yourLinksShortener.initializeField('edit_message_content');
}

// In case user types or changes values, keep button states updated
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('message').addEventListener('input', toggleAddButton);
    document.getElementById('interval').addEventListener('input', toggleAddButton);
    document.getElementById('chat_line_trigger').addEventListener('input', toggleAddButton);
    document.getElementById('trigger_type').addEventListener('change', function() {
        toggleAddTriggerType();
        toggleAddButton();
    });
    var editTriggerTypeEl = document.getElementById('edit_trigger_type');
    if (editTriggerTypeEl) {
        editTriggerTypeEl.addEventListener('change', function() {
            toggleEditTriggerType();
        });
    }
    var editIntervalEl = document.getElementById('edit_interval');
    if (editIntervalEl) editIntervalEl.addEventListener('input', toggleEditButton);
    var editChatLineEl = document.getElementById('edit_chat_line_trigger');
    if (editChatLineEl) editChatLineEl.addEventListener('input', toggleEditButton);
    var editMsgContentEl = document.getElementById('edit_message_content');
    if (editMsgContentEl) editMsgContentEl.addEventListener('input', function() {
        updateCharCount('edit_message_content', 'editCharCount');
        toggleEditButton();
    });
    var editStatusEl = document.getElementById('edit_status');
    if (editStatusEl) editStatusEl.addEventListener('change', toggleEditButton);
    var editMessageSelectEl = document.getElementById('edit_message');
    if (editMessageSelectEl) editMessageSelectEl.addEventListener('change', function() {
        showResponse();
        toggleEditButton();
    });
    document.getElementById('remove_message').addEventListener('change', function() {
        showMessage();
        toggleRemoveButton();
    });
});
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>