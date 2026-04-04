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
    // Quick toggle enable/disable (supports both AJAX and normal POST)
    if (isset($_POST['toggle_status']) && isset($_POST['toggle_id'])) {
        $toggle_id = (int)$_POST['toggle_id'];
        $new_status = ((int)$_POST['toggle_status'] === 1) ? 0 : 1;
        $is_ajax = !empty($_POST['ajax_action']) && $_POST['ajax_action'] === 'toggle_status';
        try {
            $stmt = $db->prepare("UPDATE timed_messages SET status = ? WHERE id = ?");
            $stmt->bind_param("ii", $new_status, $toggle_id);
            $stmt->execute();
            $stmt->close();
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'id' => $toggle_id, 'new_status' => $new_status]);
                exit();
            }
            $successMessage = 'Message ID ' . $toggle_id . ' has been ' . ($new_status ? 'enabled' : 'disabled') . '.';
        } catch (mysqli_sql_exception $e) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit();
            }
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
                // Find the lowest unused ID to fill gaps left by previous deletions
                $gapResult = $db->query(
                    'SELECT MIN(seq.id) AS next_id FROM ' .
                    '(SELECT 1 AS id UNION ALL SELECT id + 1 FROM timed_messages) seq ' .
                    'LEFT JOIN timed_messages t ON seq.id = t.id WHERE t.id IS NULL'
                );
                $nextId = ($gapResult && ($gapRow = $gapResult->fetch_assoc())) ? (int)$gapRow['next_id'] : null;
                if ($trigger_type === 'timer') {
                    if ($nextId) {
                        $stmt = $db->prepare('INSERT INTO timed_messages (`id`, `interval_count`, `chat_line_trigger`, `message`, `status`, `trigger_type`) VALUES (?, ?, NULL, ?, ?, ?)');
                        $stmt->bind_param("iisis", $nextId, $interval, $message, $status, $trigger_type);
                    } else {
                        $stmt = $db->prepare('INSERT INTO timed_messages (`interval_count`, `chat_line_trigger`, `message`, `status`, `trigger_type`) VALUES (?, NULL, ?, ?, ?)');
                        $stmt->bind_param("isis", $interval, $message, $status, $trigger_type);
                    }
                } elseif ($trigger_type === 'chat_lines') {
                    if ($nextId) {
                        $stmt = $db->prepare('INSERT INTO timed_messages (`id`, `interval_count`, `chat_line_trigger`, `message`, `status`, `trigger_type`) VALUES (?, NULL, ?, ?, ?, ?)');
                        $stmt->bind_param("iisis", $nextId, $chat_line_trigger, $message, $status, $trigger_type);
                    } else {
                        $stmt = $db->prepare('INSERT INTO timed_messages (`interval_count`, `chat_line_trigger`, `message`, `status`, `trigger_type`) VALUES (NULL, ?, ?, ?, ?)');
                        $stmt->bind_param("isis", $chat_line_trigger, $message, $status, $trigger_type);
                    }
                } else {
                    if ($nextId) {
                        $stmt = $db->prepare('INSERT INTO timed_messages (`id`, `interval_count`, `chat_line_trigger`, `message`, `status`, `trigger_type`) VALUES (?, ?, ?, ?, ?, ?)');
                        $stmt->bind_param("iiisis", $nextId, $interval, $chat_line_trigger, $message, $status, $trigger_type);
                    } else {
                        $stmt = $db->prepare('INSERT INTO timed_messages (`interval_count`, `chat_line_trigger`, `message`, `status`, `trigger_type`) VALUES (?, ?, ?, ?, ?)');
                        $stmt->bind_param("iisis", $interval, $chat_line_trigger, $message, $status, $trigger_type);
                    }
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
        $displayMessages = "<p>" . htmlspecialchars($_GET['successMessage']) . "</p>";
    } elseif (!empty($_GET['errorMessage'])) {
        $errorMessage = isset($_GET['errorMessage']) ? $_GET['errorMessage'] : '';
        $displayMessages = "<p>". htmlspecialchars($errorMessage) . "</p>";
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
<div class="sp-card">
    <div class="sp-card-header">
        <span class="sp-card-title">
            <i class="fas fa-clock" style="margin-right:0.5rem;"></i>
            <?php echo t('timed_messages_title'); ?>
        </span>
    </div>
    <div class="sp-card-body">
        <!-- Variables Information Card -->
        <div style="margin-bottom:1.25rem;">
            <div class="sp-card">
                <div class="sp-card-body">
                    <h5 style="font-size:0.9rem; font-weight:600; margin-bottom:0.5rem;"><i class="fas fa-info-circle" style="margin-right:0.4rem;"></i><?php echo t('timed_messages_variables_title') ?: 'Available Variables'; ?></h5>
                    <ul style="list-style:disc inside; margin-bottom:0;">
                        <li><code>(game)</code> - <?php echo t('timed_messages_var_game') ?: 'Displays the current game being played (NEW).'; ?></li>
                        <li><code>(command.yourcommand)</code> - <?php echo t('timed_messages_var_command') ?: 'Runs a custom command and sends its processed response as an additional chat message.'; ?></li>
                        <!-- Add more variables here as needed -->
                    </ul>
                    <div style="margin-top:0.75rem;">
                        <a href="https://help.botofthespecter.com/custom_variables.php" target="_blank" class="sp-btn sp-btn-primary sp-btn-sm">
                            <span class="icon"><i class="fas fa-code"></i></span>
                            <span><?php echo t('custom_commands_view_variables') ?: 'View Custom Variables'; ?></span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <!-- End Variables Information Card -->
        <div class="sp-alert sp-alert-info" style="margin-bottom:1.25rem;">
            <span class="icon"><i class="fas fa-info-circle"></i></span>
            <?php echo t('timed_messages_info'); ?>
        </div>
        <?php if ($displayMessages): ?>
            <div class="sp-alert sp-alert-info">
                <?php echo $displayMessages; ?>
            </div>
        <?php endif; ?>
        <div class="cc-form-grid" style="grid-template-columns:1fr 1fr 1fr;">
            <!-- Add Timed Message -->
            <div class="sp-card" style="display:flex; flex-direction:column;">
                <div class="sp-card-body" style="display:flex; flex-direction:column; flex:1;">
                    <h4 style="font-size:1.05rem; font-weight:600; margin-bottom:1rem;"><?php echo t('timed_messages_add_title'); ?></h4>
                    <form id="addMessageForm" method="post" action="" onsubmit="return validateForm()">
                        <div class="sp-form-group">
                            <label class="sp-label" for="message"><?php echo t('timed_messages_message_label'); ?></label>
                            <input class="sp-input" type="text" name="message" id="message" required maxlength="255" oninput="updateCharCount('message', 'charCount'); toggleAddButton();">
                            <small id="charCount" class="sp-help">0/255 <?php echo t('timed_messages_characters'); ?></small>
                            <small id="messageError" class="sp-help sp-help-danger" style="display: none;"><?php echo t('timed_messages_message_required'); ?></small>
                        </div>
                        <div class="sp-form-group">
                            <label class="sp-label">Trigger Type <span style="font-size:0.7rem; background:rgba(251,191,36,0.15); color:var(--amber); border-radius:3px; padding:1px 5px; margin-left:0.4rem; vertical-align:middle;">5.8 Beta</span></label>
                            <select class="sp-select" name="trigger_type" id="trigger_type" onchange="toggleAddTriggerType(); toggleAddButton();">
                                <option value="timer">Timer (minutes)</option>
                                <option value="chat_lines">Chat Lines</option>
                                <option value="both">Both (Timer &amp; Chat Lines)</option>
                            </select>
                            <small class="sp-help">Fire on a fixed time interval, or after a set number of chat messages.</small>
                        </div>
                        <div class="sp-form-group" id="add_interval_field">
                            <label class="sp-label" for="interval"><?php echo t('timed_messages_interval_label'); ?></label>
                            <input class="sp-input" type="number" name="interval" id="interval" min="5" max="60" value="5" oninput="toggleAddButton();">
                            <small id="intervalError" class="sp-help sp-help-danger" style="display: none;"><?php echo t('timed_messages_interval_error'); ?></small>
                        </div>
                        <div class="sp-form-group" id="add_chat_line_field" style="display:none;">
                            <label class="sp-label" for="chat_line_trigger"><?php echo t('timed_messages_chat_line_trigger_label'); ?></label>
                            <input class="sp-input" type="number" name="chat_line_trigger" id="chat_line_trigger" min="5" value="5" oninput="toggleAddButton();">
                            <small id="chatLineTriggerError" class="sp-help sp-help-danger" style="display: none;"><?php echo t('timed_messages_chat_line_trigger_error'); ?></small>
                        </div>
                        <div style="flex-grow:1"></div>
                        <button type="submit" id="addMessageButton" class="sp-btn sp-btn-primary" style="width:100%; margin-top:auto;" disabled><?php echo t('timed_messages_add_btn'); ?></button>
                    </form>
                </div>
            </div>
            <!-- Edit Timed Message -->
            <div class="sp-card" style="display:flex; flex-direction:column;">
                <div class="sp-card-body" style="display:flex; flex-direction:column; flex:1;">
                    <h4 style="font-size:1.05rem; font-weight:600; margin-bottom:1rem;"><?php echo t('timed_messages_edit_title'); ?></h4>
                    <?php if (!empty($timedMessagesData)): ?>
                    <form id="editMessageForm" method="post" action="" onsubmit="return validateEditForm()">
                        <div class="sp-form-group">
                            <label class="sp-label" for="edit_message"><?php echo t('timed_messages_select_edit_label'); ?></label>
                            <select class="sp-select" name="edit_message" id="edit_message" onchange="showResponse(); toggleEditButton();">
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
                        <div class="sp-form-group">
                            <label class="sp-label">Trigger Type <span style="font-size:0.7rem; background:rgba(251,191,36,0.15); color:var(--amber); border-radius:3px; padding:1px 5px; margin-left:0.4rem; vertical-align:middle;">5.8 Beta</span></label>
                            <select class="sp-select" name="edit_trigger_type" id="edit_trigger_type" onchange="toggleEditTriggerType();">
                                <option value="timer">Timer (minutes)</option>
                                <option value="chat_lines">Chat Lines</option>
                                <option value="both">Both (Timer &amp; Chat Lines)</option>
                            </select>
                        </div>
                        <div class="sp-form-group" id="edit_interval_field">
                            <label class="sp-label" for="edit_interval"><?php echo t('timed_messages_interval_label'); ?></label>
                            <input class="sp-input" type="number" name="edit_interval" id="edit_interval" min="5" max="60" oninput="toggleEditButton();">
                        </div>
                        <div class="sp-form-group" id="edit_chat_line_field" style="display:none;">
                            <label class="sp-label" for="edit_chat_line_trigger"><?php echo t('timed_messages_chat_line_trigger_label'); ?></label>
                            <input class="sp-input" type="number" name="edit_chat_line_trigger" id="edit_chat_line_trigger" min="5" oninput="toggleEditButton();">
                        </div>
                        <div class="sp-form-group">
                            <label class="sp-label" for="edit_message_content"><?php echo t('timed_messages_message_label'); ?></label>
                            <input class="sp-input" type="text" name="edit_message_content" id="edit_message_content" required maxlength="255" oninput="updateCharCount('edit_message_content', 'editCharCount'); toggleEditButton();">
                            <small id="editCharCount" class="sp-help">0/255 <?php echo t('timed_messages_characters'); ?></small>
                        </div>
                        <div class="sp-form-group">
                            <label class="sp-label" for="edit_status"><?php echo t('timed_messages_status_label'); ?></label>
                            <select class="sp-select" name="edit_status" id="edit_status" onchange="toggleEditButton();">
                                <option value="True"><?php echo t('timed_messages_status_enabled'); ?></option>
                                <option value="False"><?php echo t('timed_messages_status_disabled'); ?></option>
                            </select>
                        </div>
                        <button type="submit" id="editMessageButton" class="sp-btn sp-btn-primary" style="width:100%;" disabled><?php echo t('timed_messages_save_btn'); ?></button>
                    </form>
                    <?php else: ?>
                        <p style="color:var(--text-muted);"><?php echo t('timed_messages_no_edit'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Remove Timed Message -->
            <div class="sp-card" style="display:flex; flex-direction:column;">
                <div class="sp-card-body" style="display:flex; flex-direction:column; flex:1;">
                    <h4 style="font-size:1.05rem; font-weight:600; margin-bottom:1rem;"><?php echo t('timed_messages_remove_title'); ?></h4>
                    <?php if (!empty($timedMessagesData)): ?>
                    <form id="removeMessageForm" method="post" action="" style="display:flex; flex-direction:column; flex:1;">
                        <div class="sp-form-group">
                            <label class="sp-label" for="remove_message"><?php echo t('timed_messages_select_remove_label'); ?></label>
                            <select class="sp-select" name="remove_message" id="remove_message" onchange="showMessage(); toggleRemoveButton();">
                                <option value=""><?php echo t('timed_messages_select_remove_placeholder'); ?></option>
                                <?php foreach ($timedMessagesData as $message): ?>
                                    <option value="<?php echo $message['id']; ?>">
                                        <?php echo t('timed_messages_message_id'); ?> <?php echo $message['id']; ?> - <?php echo htmlspecialchars(mb_strimwidth($message['message'], 0, 40, "")); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sp-form-group">
                            <label class="sp-label" for="remove_message_content"><?php echo t('timed_messages_message_label'); ?></label>
                            <textarea class="sp-input" id="remove_message_content" disabled rows="7" style="height:auto; min-height:7rem;"></textarea>
                        </div>
                        <div style="flex-grow:1"></div>
                        <button type="submit" id="removeMessageButton" class="sp-btn sp-btn-danger" style="width:100%;" disabled><?php echo t('timed_messages_remove_btn'); ?></button>
                    </form>
                    <?php else: ?>
                        <p style="color:var(--text-muted);"><?php echo t('timed_messages_no_remove'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Current Timed Messages Table -->
<div class="sp-card" style="margin-top:1.5rem;">
    <div class="sp-card-header">
        <span class="sp-card-title">
            <i class="fas fa-list" style="margin-right:0.5rem;"></i>
            Current Timed Messages
        </span>
    </div>
    <div class="sp-card-body">
        <div class="sp-table-wrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th style="width: 42px; text-align: center; vertical-align: middle;">ID</th>
                        <th style="vertical-align: middle;">Message</th>
                        <th style="width: 150px; text-align: center; vertical-align: middle;">Trigger Type <span style="font-size:0.65rem; background:rgba(251,191,36,0.15); color:var(--amber); border-radius:3px; padding:1px 4px; margin-left:0.3rem;">5.8</span></th>
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
                                        echo '<span class="sp-badge sp-badge-blue">Chat Lines: ' . htmlspecialchars($msg['chat_line_trigger']) . '</span>';
                                    } elseif ($triggerType === 'both') {
                                        echo '<span class="sp-badge sp-badge-amber">Timer: ' . htmlspecialchars($msg['interval_count']) . ' min &amp; Chat Lines: ' . htmlspecialchars($msg['chat_line_trigger']) . '</span>';
                                    } else {
                                        echo '<span class="sp-badge sp-badge-accent">Timer: ' . htmlspecialchars($msg['interval_count']) . ' min</span>';
                                    }
                                    ?>
                                </td>
                                <td style="text-align: center; vertical-align: middle;">
                                    <button type="button"
                                        class="sp-btn sp-btn-sm toggle-status-btn <?php echo $msg['status'] == 1 ? 'sp-btn-success' : 'sp-btn-danger'; ?>"
                                        data-id="<?php echo $msg['id']; ?>"
                                        data-status="<?php echo $msg['status']; ?>"
                                        title="<?php echo $msg['status'] == 1 ? 'Click to disable' : 'Click to enable'; ?>">
                                        <span class="icon"><i class="fas <?php echo $msg['status'] == 1 ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i></span>
                                        <span><?php echo $msg['status'] == 1 ? 'Enabled' : 'Disabled'; ?></span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align:center;">No timed messages found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- Hidden fields for YourLinks API -->
<input type="hidden" id="yourlinks_api_key" value="<?php echo htmlspecialchars($userApiKey); ?>">
<input type="hidden" id="yourlinks_username" value="<?php echo htmlspecialchars($twitchUsername); ?>">
<?php
$content = ob_get_clean();

// Scripts section
ob_start();
?>
<script src="js/yourlinks-shortener.js?v=<?php echo filemtime(__DIR__ . '/js/yourlinks-shortener.js'); ?>"></script>
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
        document.getElementById('editCharCount').className = 'sp-help';
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
        counter.className = 'sp-help sp-help-danger';
    } else if (currentLength > maxLength * 0.8) {
        counter.className = 'sp-help sp-help-warning';
    } else {
        counter.className = 'sp-help';
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

// AJAX toggle enable/disable
document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.toggle-status-btn');
        if (!btn) return;
        var id = btn.dataset.id;
        var currentStatus = btn.dataset.status;
        btn.disabled = true;
        // Show spinner while processing
        btn.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>Updating...</span>';
        var body = new URLSearchParams();
        body.append('ajax_action', 'toggle_status');
        body.append('toggle_id', id);
        body.append('toggle_status', currentStatus);
        fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var newStatus = data.new_status;
                btn.dataset.status = newStatus;
                btn.className = 'sp-btn sp-btn-sm toggle-status-btn ' + (newStatus == 1 ? 'sp-btn-success' : 'sp-btn-danger');
                btn.title = newStatus == 1 ? 'Click to disable' : 'Click to enable';
                btn.innerHTML = '<span class="icon"><i class="fas ' + (newStatus == 1 ? 'fa-toggle-on' : 'fa-toggle-off') + '"></i></span>'
                              + '<span>' + (newStatus == 1 ? 'Enabled' : 'Disabled') + '</span>';
            } else {
                // Restore original state on failure
                btn.className = 'sp-btn sp-btn-sm toggle-status-btn ' + (currentStatus == 1 ? 'sp-btn-success' : 'sp-btn-danger');
                btn.innerHTML = '<span class="icon"><i class="fas ' + (currentStatus == 1 ? 'fa-toggle-on' : 'fa-toggle-off') + '"></i></span>'
                              + '<span>' + (currentStatus == 1 ? 'Enabled' : 'Disabled') + '</span>';
            }
            btn.disabled = false;
        })
        .catch(function() {
            // Restore original state on network error
            btn.className = 'sp-btn sp-btn-sm toggle-status-btn ' + (currentStatus == 1 ? 'sp-btn-success' : 'sp-btn-danger');
            btn.innerHTML = '<span class="icon"><i class="fas ' + (currentStatus == 1 ? 'fa-toggle-on' : 'fa-toggle-off') + '"></i></span>'
                          + '<span>' + (currentStatus == 1 ? 'Enabled' : 'Disabled') + '</span>';
            btn.disabled = false;
        });
    });
});
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>