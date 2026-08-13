<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title and Header
$pageTitle = t('timed_messages_title');
$pageHeader = t('timed_messages_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'includes/userdata.php';
include "includes/mod_access.php";
include 'includes/user_db_connect.php'; // FAST SHELL: connection only, no bulk table load
session_write_close();

// List endpoint first so the browser can paint skeletons, then fetch rows.
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'list') {
    header('Content-Type: application/json');
    try {
        $stmt = $db->prepare("SELECT * FROM timed_messages ORDER BY id ASC");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['success' => true, 'messages' => $rows]);
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

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
            $successMessage = $new_status
                ? t('timed_messages_msg_enabled', [$toggle_id])
                : t('timed_messages_msg_disabled', [$toggle_id]);
        } catch (mysqli_sql_exception $e) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit();
            }
            $errorMessage = t('timed_messages_err_updating_status') . $e->getMessage();
        }
    }
    // Check if the form was submitted for adding a new message
    if (isset($_POST['message']) && isset($_POST['trigger_type'])) {
        $message = $_POST['message'];
        $trigger_type = in_array($_POST['trigger_type'], ['timer', 'chat_lines', 'both', 'scheduled']) ? $_POST['trigger_type'] : 'timer';
        $has_shoutout_var = (bool)preg_match('/\(shoutout\.\w+\)/', $message);
        $interval = null;
        $chat_line_trigger = null;
        $scheduled_time = null;
        if ($trigger_type === 'timer' || $trigger_type === 'both') {
            $int_min = $has_shoutout_var ? 60 : 5;
            $interval = filter_input(INPUT_POST, 'interval', FILTER_VALIDATE_INT, array("options" => array("min_range" => $int_min, "max_range" => 480)));
            if ($interval === false || $interval === null) {
                $errorMessage = $has_shoutout_var
                    ? t('timed_messages_err_interval_shoutout')
                    : t('timed_messages_err_interval_range');
            }
        }
        if (empty($errorMessage) && ($trigger_type === 'chat_lines' || $trigger_type === 'both')) {
            $chat_line_trigger = filter_input(INPUT_POST, 'chat_line_trigger', FILTER_VALIDATE_INT, array("options" => array("min_range" => 5)));
            if ($chat_line_trigger === false || $chat_line_trigger === null) {
                $errorMessage = t('timed_messages_err_chat_line_min');
            }
        }
        if (empty($errorMessage) && $trigger_type === 'scheduled') {
            $raw_time = isset($_POST['scheduled_time']) ? trim($_POST['scheduled_time']) : '';
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw_time)) {
                $errorMessage = t('timed_messages_err_scheduled_time') ?: 'Please enter a valid time (HH:MM).';
            } else {
                $scheduled_time = $raw_time . ':00'; // store as HH:MM:SS for MySQL TIME
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
                        $stmt = $db->prepare('INSERT INTO timed_messages (`id`, `interval_count`, `chat_line_trigger`, `scheduled_time`, `message`, `status`, `trigger_type`) VALUES (?, ?, NULL, NULL, ?, ?, ?)');
                        $stmt->bind_param("iisis", $nextId, $interval, $message, $status, $trigger_type);
                    } else {
                        $stmt = $db->prepare('INSERT INTO timed_messages (`interval_count`, `chat_line_trigger`, `scheduled_time`, `message`, `status`, `trigger_type`) VALUES (?, NULL, NULL, ?, ?, ?)');
                        $stmt->bind_param("isis", $interval, $message, $status, $trigger_type);
                    }
                } elseif ($trigger_type === 'chat_lines') {
                    if ($nextId) {
                        $stmt = $db->prepare('INSERT INTO timed_messages (`id`, `interval_count`, `chat_line_trigger`, `scheduled_time`, `message`, `status`, `trigger_type`) VALUES (?, NULL, ?, NULL, ?, ?, ?)');
                        $stmt->bind_param("iisis", $nextId, $chat_line_trigger, $message, $status, $trigger_type);
                    } else {
                        $stmt = $db->prepare('INSERT INTO timed_messages (`interval_count`, `chat_line_trigger`, `scheduled_time`, `message`, `status`, `trigger_type`) VALUES (NULL, ?, NULL, ?, ?, ?)');
                        $stmt->bind_param("isis", $chat_line_trigger, $message, $status, $trigger_type);
                    }
                } elseif ($trigger_type === 'scheduled') {
                    if ($nextId) {
                        $stmt = $db->prepare('INSERT INTO timed_messages (`id`, `interval_count`, `chat_line_trigger`, `scheduled_time`, `message`, `status`, `trigger_type`) VALUES (?, NULL, NULL, ?, ?, ?, ?)');
                        $stmt->bind_param("issis", $nextId, $scheduled_time, $message, $status, $trigger_type);
                    } else {
                        $stmt = $db->prepare('INSERT INTO timed_messages (`interval_count`, `chat_line_trigger`, `scheduled_time`, `message`, `status`, `trigger_type`) VALUES (NULL, NULL, ?, ?, ?, ?)');
                        $stmt->bind_param("ssis", $scheduled_time, $message, $status, $trigger_type);
                    }
                } else {
                    if ($nextId) {
                        $stmt = $db->prepare('INSERT INTO timed_messages (`id`, `interval_count`, `chat_line_trigger`, `scheduled_time`, `message`, `status`, `trigger_type`) VALUES (?, ?, ?, NULL, ?, ?, ?)');
                        $stmt->bind_param("iiisis", $nextId, $interval, $chat_line_trigger, $message, $status, $trigger_type);
                    } else {
                        $stmt = $db->prepare('INSERT INTO timed_messages (`interval_count`, `chat_line_trigger`, `scheduled_time`, `message`, `status`, `trigger_type`) VALUES (?, ?, NULL, ?, ?, ?)');
                        $stmt->bind_param("iisis", $interval, $chat_line_trigger, $message, $status, $trigger_type);
                    }
                }
                $stmt->execute();
                if ($trigger_type === 'both') {
                    $modeLabel = t('timed_messages_mode_both', ['interval' => $interval, 'chat' => $chat_line_trigger]);
                } elseif ($trigger_type === 'timer') {
                    $modeLabel = t('timed_messages_mode_timer', [$interval]);
                } elseif ($trigger_type === 'scheduled') {
                    // Strip seconds from stored HH:MM:SS for display
                    $dispTime = substr($scheduled_time, 0, 5);
                    $modeLabel = t('timed_messages_mode_scheduled') ? t('timed_messages_mode_scheduled', [$dispTime]) : "Scheduled: {$dispTime}";
                } else {
                    $modeLabel = t('timed_messages_mode_chat_lines', [$chat_line_trigger]);
                }
                $successMessage = t('timed_messages_msg_added', ['message' => $_POST['message'], 'mode' => $modeLabel]);
                $stmt->close();
            } catch (mysqli_sql_exception $e) {
                $errorMessage = t('timed_messages_err_adding') . $e->getMessage();
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
                $successMessage = t('timed_messages_msg_removed');
            } else {
                $errorMessage = t('timed_messages_err_remove_failed');
            }
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $errorMessage = t('timed_messages_err_removing') . $e->getMessage();
        }
    }
    // Check if the form was submitted for editing the message, interval, or status
    elseif (isset($_POST['edit_message']) && isset($_POST['edit_status']) && isset($_POST['edit_trigger_type'])) {
        $edit_message_id = $_POST['edit_message'];
        $edit_message_content = $_POST['edit_message_content'];
        $edit_status = $_POST['edit_status'];
        $edit_trigger_type = in_array($_POST['edit_trigger_type'], ['timer', 'chat_lines', 'both', 'scheduled']) ? $_POST['edit_trigger_type'] : 'timer';
        $edit_has_shoutout_var = (bool)preg_match('/\(shoutout\.\w+\)/', $edit_message_content);
        $edit_interval = null;
        $edit_chat_line_trigger = null;
        $edit_scheduled_time = null;
        if ($edit_trigger_type === 'timer' || $edit_trigger_type === 'both') {
            $edit_int_min = $edit_has_shoutout_var ? 60 : 5;
            $edit_interval = filter_input(INPUT_POST, 'edit_interval', FILTER_VALIDATE_INT, array("options" => array("min_range" => $edit_int_min, "max_range" => 480)));
            if ($edit_interval === false || $edit_interval === null) {
                $errorMessage = $edit_has_shoutout_var
                    ? t('timed_messages_err_interval_shoutout')
                    : t('timed_messages_err_interval_range');
            }
        }
        if (empty($errorMessage) && ($edit_trigger_type === 'chat_lines' || $edit_trigger_type === 'both')) {
            $edit_chat_line_trigger = filter_input(INPUT_POST, 'edit_chat_line_trigger', FILTER_VALIDATE_INT, array("options" => array("min_range" => 5)));
            if ($edit_chat_line_trigger === false || $edit_chat_line_trigger === null) {
                $errorMessage = t('timed_messages_err_chat_line_min');
            }
        }
        if (empty($errorMessage) && $edit_trigger_type === 'scheduled') {
            $raw_edit_time = isset($_POST['edit_scheduled_time']) ? trim($_POST['edit_scheduled_time']) : '';
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw_edit_time)) {
                $errorMessage = t('timed_messages_err_scheduled_time') ?: 'Please enter a valid time (HH:MM).';
            } else {
                $edit_scheduled_time = $raw_edit_time . ':00';
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
                        $stmt = $db->prepare('UPDATE timed_messages SET `interval_count` = ?, `chat_line_trigger` = NULL, `scheduled_time` = NULL, `message` = ?, `status` = ?, `trigger_type` = ? WHERE id = ?');
                        $stmt->bind_param("isisi", $edit_interval, $edit_message_content, $status_int, $edit_trigger_type, $edit_message_id);
                    } elseif ($edit_trigger_type === 'chat_lines') {
                        $stmt = $db->prepare('UPDATE timed_messages SET `interval_count` = NULL, `chat_line_trigger` = ?, `scheduled_time` = NULL, `message` = ?, `status` = ?, `trigger_type` = ? WHERE id = ?');
                        $stmt->bind_param("isisi", $edit_chat_line_trigger, $edit_message_content, $status_int, $edit_trigger_type, $edit_message_id);
                    } elseif ($edit_trigger_type === 'scheduled') {
                        $stmt = $db->prepare('UPDATE timed_messages SET `interval_count` = NULL, `chat_line_trigger` = NULL, `scheduled_time` = ?, `message` = ?, `status` = ?, `trigger_type` = ? WHERE id = ?');
                        $stmt->bind_param("ssisi", $edit_scheduled_time, $edit_message_content, $status_int, $edit_trigger_type, $edit_message_id);
                    } else {
                        $stmt = $db->prepare('UPDATE timed_messages SET `interval_count` = ?, `chat_line_trigger` = ?, `scheduled_time` = NULL, `message` = ?, `status` = ?, `trigger_type` = ? WHERE id = ?');
                        $stmt->bind_param("iisisi", $edit_interval, $edit_chat_line_trigger, $edit_message_content, $status_int, $edit_trigger_type, $edit_message_id);
                    }
                    $stmt->execute();
                    $updated = $stmt->affected_rows > 0;
                    if ($updated) {
                        $successMessage = t('timed_messages_msg_updated', [$edit_message_id]);
                    } else {
                        $errorMessage = t('timed_messages_err_update_failed');
                    }
                    $stmt->close();
                } catch (mysqli_sql_exception $e) {
                    $errorMessage = t('timed_messages_err_updating') . $e->getMessage();
                }
            } else {
                $errorMessage = t('timed_messages_err_invalid_input');
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

$twitchUsername = $username;
// Start output buffering for layout template (skeletons; JS fills the list)
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
                        <a href="https://support.botofthespecter.com/index.php#variables" target="_blank" rel="noopener" class="sp-btn sp-btn-primary sp-btn-sm">
                            <span class="icon"><i class="fas fa-code"></i></span>
                            <span><?php echo t('custom_commands_view_variables') ?: 'View Custom Variables'; ?></span>
                        </a>
                    </div>
                    <div class="sp-betabot-toggle" style="margin-top:0.75rem;">
                        <input type="checkbox" class="switch" id="betaBotToggle" onchange="applyBetaBotCharLimit(this.checked)">
                        <label for="betaBotToggle"><?php echo t('timed_messages_beta_bot_toggle'); ?></label>
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
                            <input class="sp-input" type="text" name="message" id="message" required maxlength="255" oninput="updateCharCount('message', 'charCount'); updateShoutoutHint('message', 'interval', 'shoutoutHint'); toggleAddButton();">
                            <small id="charCount" class="sp-help">0/255 <?php echo t('timed_messages_characters'); ?></small>
                            <small id="shoutoutHint" class="sp-help" style="display:none; color:var(--amber);"><i class="fas fa-exclamation-triangle" style="margin-right:0.3rem;"></i><?php echo t('timed_messages_shoutout_hint'); ?></small>
                            <small id="messageError" class="sp-help sp-help-danger" style="display: none;"><?php echo t('timed_messages_message_required'); ?></small>
                        </div>
                        <div class="sp-form-group">
                            <label class="sp-label"><?php echo t('timed_messages_trigger_type_label'); ?> <span style="font-size:0.7rem; background:rgba(251,191,36,0.15); color:var(--amber); border-radius:3px; padding:1px 5px; margin-left:0.4rem; vertical-align:middle;">5.8 Beta</span></label>
                            <select class="sp-select" name="trigger_type" id="trigger_type" onchange="toggleAddTriggerType(); toggleAddButton();">
                                <option value="timer"><?php echo t('timed_messages_trigger_timer'); ?></option>
                                <option value="chat_lines"><?php echo t('timed_messages_trigger_chat_lines'); ?></option>
                                <option value="both"><?php echo t('timed_messages_trigger_both'); ?></option>
                                <option value="scheduled"><?php echo t('timed_messages_trigger_scheduled') ?: 'Scheduled (Time of Day)'; ?></option>
                            </select>
                            <small class="sp-help"><?php echo t('timed_messages_trigger_help'); ?></small>
                        </div>
                        <div class="sp-form-group" id="add_interval_field">
                            <label class="sp-label" for="interval"><?php echo t('timed_messages_interval_label'); ?></label>
                            <input class="sp-input" type="number" name="interval" id="interval" min="5" max="480" value="5" oninput="toggleAddButton();">
                            <small id="intervalError" class="sp-help sp-help-danger" style="display: none;"><?php echo t('timed_messages_interval_error'); ?></small>
                        </div>
                        <div class="sp-form-group" id="add_chat_line_field" style="display:none;">
                            <label class="sp-label" for="chat_line_trigger"><?php echo t('timed_messages_chat_line_trigger_label'); ?></label>
                            <input class="sp-input" type="number" name="chat_line_trigger" id="chat_line_trigger" min="5" value="5" oninput="toggleAddButton();">
                            <small id="chatLineTriggerError" class="sp-help sp-help-danger" style="display: none;"><?php echo t('timed_messages_chat_line_trigger_error'); ?></small>
                        </div>
                        <div class="sp-form-group" id="add_scheduled_field" style="display:none;">
                            <label class="sp-label" for="scheduled_time"><?php echo t('timed_messages_scheduled_time_label') ?: 'Send At (streamer time)'; ?></label>
                            <input class="sp-input" type="time" name="scheduled_time" id="scheduled_time" oninput="toggleAddButton();">
                            <small class="sp-help"><?php echo t('timed_messages_scheduled_time_help') ?: 'Message sends once daily at this time while the stream is live.'; ?></small>
                        </div>
                        <div style="flex-grow:1"></div>
                        <button type="submit" id="addMessageButton" class="sp-btn sp-btn-primary" style="width:100%; margin-top:auto;" disabled><?php echo t('timed_messages_add_btn'); ?></button>
                    </form>
                </div>
            </div>
            <!-- Edit Timed Message -->
            <div class="sp-card" style="display:flex; flex-direction:column;">
                <div class="sp-card-body" style="display:flex; flex-direction:column; flex:1;" id="editFormHost" aria-busy="true">
                    <h4 style="font-size:1.05rem; font-weight:600; margin-bottom:1rem;"><?php echo t('timed_messages_edit_title'); ?></h4>
                    <div id="editFormSkeleton" class="sp-skeleton-stack" aria-hidden="true">
                        <span class="sp-skeleton-line w-40"></span>
                        <span class="sp-skeleton-line w-90"></span>
                        <span class="sp-skeleton-line w-50"></span>
                        <span class="sp-skeleton-line w-70"></span>
                        <span class="sp-skeleton-line w-80"></span>
                        <span class="sp-skeleton-line w-45"></span>
                    </div>
                    <form id="editMessageForm" method="post" action="" onsubmit="return validateEditForm()" style="display:none;">
                        <div class="sp-form-group">
                            <label class="sp-label" for="edit_message"><?php echo t('timed_messages_select_edit_label'); ?></label>
                            <select class="sp-select" name="edit_message" id="edit_message" onchange="showResponse(); toggleEditButton();">
                                <option value="" selected><?php echo t('timed_messages_select_edit_placeholder'); ?></option>
                            </select>
                        </div>
                        <div class="sp-form-group">
                            <label class="sp-label"><?php echo t('timed_messages_trigger_type_label'); ?> <span style="font-size:0.7rem; background:rgba(251,191,36,0.15); color:var(--amber); border-radius:3px; padding:1px 5px; margin-left:0.4rem; vertical-align:middle;">5.8 Beta</span></label>
                            <select class="sp-select" name="edit_trigger_type" id="edit_trigger_type" onchange="toggleEditTriggerType();">
                                <option value="timer"><?php echo t('timed_messages_trigger_timer'); ?></option>
                                <option value="chat_lines"><?php echo t('timed_messages_trigger_chat_lines'); ?></option>
                                <option value="both"><?php echo t('timed_messages_trigger_both'); ?></option>
                                <option value="scheduled"><?php echo t('timed_messages_trigger_scheduled') ?: 'Scheduled (Time of Day)'; ?></option>
                            </select>
                        </div>
                        <div class="sp-form-group" id="edit_interval_field">
                            <label class="sp-label" for="edit_interval"><?php echo t('timed_messages_interval_label'); ?></label>
                            <input class="sp-input" type="number" name="edit_interval" id="edit_interval" min="5" max="480" oninput="toggleEditButton();">
                        </div>
                        <div class="sp-form-group" id="edit_chat_line_field" style="display:none;">
                            <label class="sp-label" for="edit_chat_line_trigger"><?php echo t('timed_messages_chat_line_trigger_label'); ?></label>
                            <input class="sp-input" type="number" name="edit_chat_line_trigger" id="edit_chat_line_trigger" min="5" oninput="toggleEditButton();">
                        </div>
                        <div class="sp-form-group" id="edit_scheduled_field" style="display:none;">
                            <label class="sp-label" for="edit_scheduled_time"><?php echo t('timed_messages_scheduled_time_label') ?: 'Send At (streamer time)'; ?></label>
                            <input class="sp-input" type="time" name="edit_scheduled_time" id="edit_scheduled_time" oninput="toggleEditButton();">
                            <small class="sp-help"><?php echo t('timed_messages_scheduled_time_help') ?: 'Message sends once daily at this time while the stream is live.'; ?></small>
                        </div>
                        <div class="sp-form-group">
                            <label class="sp-label" for="edit_message_content"><?php echo t('timed_messages_message_label'); ?></label>
                            <input class="sp-input" type="text" name="edit_message_content" id="edit_message_content" required maxlength="255" oninput="updateCharCount('edit_message_content', 'editCharCount'); updateShoutoutHint('edit_message_content', 'edit_interval', 'editShoutoutHint'); toggleEditButton();">
                            <small id="editCharCount" class="sp-help">0/255 <?php echo t('timed_messages_characters'); ?></small>
                            <small id="editShoutoutHint" class="sp-help" style="display:none; color:var(--amber);"><i class="fas fa-exclamation-triangle" style="margin-right:0.3rem;"></i><?php echo t('timed_messages_shoutout_hint'); ?></small>
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
                    <p id="editEmptyState" style="color:var(--text-muted); display:none;"><?php echo t('timed_messages_no_edit'); ?></p>
                </div>
            </div>
            <!-- Remove Timed Message -->
            <div class="sp-card" style="display:flex; flex-direction:column;">
                <div class="sp-card-body" style="display:flex; flex-direction:column; flex:1;" id="removeFormHost" aria-busy="true">
                    <h4 style="font-size:1.05rem; font-weight:600; margin-bottom:1rem;"><?php echo t('timed_messages_remove_title'); ?></h4>
                    <div id="removeFormSkeleton" class="sp-skeleton-stack" aria-hidden="true">
                        <span class="sp-skeleton-line w-40"></span>
                        <span class="sp-skeleton-line w-90"></span>
                        <span class="sp-skeleton-line w-80"></span>
                        <span class="sp-skeleton-line w-70"></span>
                    </div>
                    <form id="removeMessageForm" method="post" action="" style="display:none; flex-direction:column; flex:1;">
                        <div class="sp-form-group">
                            <label class="sp-label" for="remove_message"><?php echo t('timed_messages_select_remove_label'); ?></label>
                            <select class="sp-select" name="remove_message" id="remove_message" onchange="showMessage(); toggleRemoveButton();">
                                <option value=""><?php echo t('timed_messages_select_remove_placeholder'); ?></option>
                            </select>
                        </div>
                        <div class="sp-form-group">
                            <label class="sp-label" for="remove_message_content"><?php echo t('timed_messages_message_label'); ?></label>
                            <textarea class="sp-input" id="remove_message_content" disabled rows="7" style="height:auto; min-height:7rem;"></textarea>
                        </div>
                        <div style="flex-grow:1"></div>
                        <button type="submit" id="removeMessageButton" class="sp-btn sp-btn-danger" style="width:100%;" disabled><?php echo t('timed_messages_remove_btn'); ?></button>
                    </form>
                    <p id="removeEmptyState" style="color:var(--text-muted); display:none;"><?php echo t('timed_messages_no_remove'); ?></p>
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
            <?php echo t('timed_messages_current_title'); ?>
        </span>
    </div>
    <div class="sp-card-body">
        <div class="sp-table-wrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th style="width: 42px; text-align: center; vertical-align: middle;"><?php echo t('timed_messages_th_id'); ?></th>
                        <th style="vertical-align: middle;"><?php echo t('timed_messages_th_message'); ?></th>
                        <th style="width: 150px; text-align: center; vertical-align: middle;"><?php echo t('timed_messages_trigger_type_label'); ?> <span style="font-size:0.65rem; background:rgba(251,191,36,0.15); color:var(--amber); border-radius:3px; padding:1px 4px; margin-left:0.3rem;">5.8</span></th>
                        <th style="width: 130px; text-align: center; vertical-align: middle;"><?php echo t('timed_messages_status_label'); ?></th>
                    </tr>
                </thead>
                <tbody id="timedMessagesTableBody" aria-busy="true">
                    <?php for ($sk = 0; $sk < 5; $sk++): ?>
                    <tr aria-hidden="true">
                        <td style="text-align:center; vertical-align:middle;"><span class="sp-skeleton-line w-40"></span></td>
                        <td><span class="sp-skeleton-line w-80"></span></td>
                        <td style="text-align:center; vertical-align:middle;"><span class="sp-skeleton-badge"></span></td>
                        <td style="text-align:center; vertical-align:middle;"><span class="sp-skeleton-line w-60"></span></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<input type="hidden" id="yourlinks_username" value="<?php echo htmlspecialchars($twitchUsername); ?>">
<?php
$content = ob_get_clean();

// Scripts section
ob_start();
?>
<script src="js/yourlinks-shortener.js?v=<?php echo filemtime(__DIR__ . '/js/yourlinks-shortener.js'); ?>"></script>
<script>
var charLimit = 255;
var timedMessagesData = [];
const TM_I18N = {
    charactersSuffix: <?php echo json_encode(t('timed_messages_characters')); ?>,
    intervalShoutout: <?php echo json_encode(t('timed_messages_err_interval_shoutout')); ?>,
    intervalRange: <?php echo json_encode(t('timed_messages_alert_interval_range')); ?>,
    swalTitle: <?php echo json_encode(t('timed_messages_swal_title')); ?>,
    swalText: <?php echo json_encode(t('timed_messages_swal_text')); ?>,
    swalConfirm: <?php echo json_encode(t('timed_messages_swal_confirm')); ?>,
    swalCancel: <?php echo json_encode(t('timed_messages_swal_cancel')); ?>,
    updating: <?php echo json_encode(t('timed_messages_updating')); ?>,
    clickToDisable: <?php echo json_encode(t('timed_messages_click_to_disable')); ?>,
    clickToEnable: <?php echo json_encode(t('timed_messages_click_to_enable')); ?>,
    statusEnabled: <?php echo json_encode(t('timed_messages_status_enabled')); ?>,
    statusDisabled: <?php echo json_encode(t('timed_messages_status_disabled')); ?>,
    noneFound: <?php echo json_encode(t('timed_messages_none_found')); ?>,
    loadError: <?php echo json_encode(t('timed_messages_err_updating')); ?>,
    badgeChatLines: <?php echo json_encode(t('timed_messages_badge_chat_lines')); ?>,
    badgeTimer: <?php echo json_encode(t('timed_messages_badge_timer')); ?>,
    badgeScheduled: <?php echo json_encode(t('timed_messages_badge_scheduled') ?: 'Scheduled'); ?>,
    badgeMin: <?php echo json_encode(t('timed_messages_badge_min')); ?>,
    messageId: <?php echo json_encode(t('timed_messages_message_id')); ?>,
    selectEditPlaceholder: <?php echo json_encode(t('timed_messages_select_edit_placeholder')); ?>,
    selectRemovePlaceholder: <?php echo json_encode(t('timed_messages_select_remove_placeholder')); ?>
};

function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

function truncateMessage(str, maxLen) {
    var value = String(str == null ? '' : str);
    if (value.length <= maxLen) return value;
    return value.slice(0, maxLen);
}

function triggerBadgeHtml(msg) {
    var triggerType = msg.trigger_type || 'timer';
    if (triggerType === 'chat_lines') {
        return '<span class="sp-badge sp-badge-blue">' + escapeHtml(TM_I18N.badgeChatLines) + ': ' + escapeHtml(msg.chat_line_trigger) + '</span>';
    }
    if (triggerType === 'both') {
        return '<span class="sp-badge sp-badge-amber">' + escapeHtml(TM_I18N.badgeTimer) + ': ' + escapeHtml(msg.interval_count) + ' ' + escapeHtml(TM_I18N.badgeMin) + ' &amp; ' + escapeHtml(TM_I18N.badgeChatLines) + ': ' + escapeHtml(msg.chat_line_trigger) + '</span>';
    }
    if (triggerType === 'scheduled') {
        var dispTime = msg.scheduled_time ? String(msg.scheduled_time).substring(0, 5) : '--:--';
        return '<span class="sp-badge sp-badge-purple">' + escapeHtml(TM_I18N.badgeScheduled) + ': ' + escapeHtml(dispTime) + '</span>';
    }
    return '<span class="sp-badge sp-badge-accent">' + escapeHtml(TM_I18N.badgeTimer) + ': ' + escapeHtml(msg.interval_count) + ' ' + escapeHtml(TM_I18N.badgeMin) + '</span>';
}

function fillMessageSelect(selectEl, placeholder, optionBuilder) {
    if (!selectEl) return;
    var previous = selectEl.value;
    selectEl.innerHTML = '';
    var placeholderOpt = document.createElement('option');
    placeholderOpt.value = '';
    placeholderOpt.textContent = placeholder;
    selectEl.appendChild(placeholderOpt);
    timedMessagesData.forEach(function(message) {
        var opt = document.createElement('option');
        opt.value = message.id;
        opt.textContent = optionBuilder(message);
        selectEl.appendChild(opt);
    });
    if (previous && timedMessagesData.some(function(m) { return String(m.id) === String(previous); })) {
        selectEl.value = previous;
    }
}

function populateTimedMessageSelects() {
    var hasMessages = timedMessagesData.length > 0;
    var editSkeleton = document.getElementById('editFormSkeleton');
    var removeSkeleton = document.getElementById('removeFormSkeleton');
    var editForm = document.getElementById('editMessageForm');
    var removeForm = document.getElementById('removeMessageForm');
    var editEmpty = document.getElementById('editEmptyState');
    var removeEmpty = document.getElementById('removeEmptyState');
    var editHost = document.getElementById('editFormHost');
    var removeHost = document.getElementById('removeFormHost');
    if (editSkeleton) editSkeleton.style.display = 'none';
    if (removeSkeleton) removeSkeleton.style.display = 'none';
    if (editHost) editHost.setAttribute('aria-busy', 'false');
    if (removeHost) removeHost.setAttribute('aria-busy', 'false');
    if (editForm) {
        editForm.style.display = hasMessages ? '' : 'none';
        fillMessageSelect(document.getElementById('edit_message'), TM_I18N.selectEditPlaceholder, function(message) {
            return '(ID: ' + message.id + ') ' + message.message;
        });
    }
    if (removeForm) {
        removeForm.style.display = hasMessages ? 'flex' : 'none';
        fillMessageSelect(document.getElementById('remove_message'), TM_I18N.selectRemovePlaceholder, function(message) {
            return TM_I18N.messageId + ' ' + message.id + ' - ' + truncateMessage(message.message, 40);
        });
    }
    if (editEmpty) editEmpty.style.display = hasMessages ? 'none' : '';
    if (removeEmpty) removeEmpty.style.display = hasMessages ? 'none' : '';
}

function renderTimedMessagesTable() {
    var tbody = document.getElementById('timedMessagesTableBody');
    if (!tbody) return;
    tbody.setAttribute('aria-busy', 'false');
    if (!timedMessagesData.length) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">' + escapeHtml(TM_I18N.noneFound) + '</td></tr>';
        return;
    }
    tbody.innerHTML = timedMessagesData.map(function(msg) {
        var enabled = Number(msg.status) === 1;
        return '<tr>' +
            '<td style="text-align:center; vertical-align:middle;">' + escapeHtml(msg.id) + '</td>' +
            '<td>' + escapeHtml(msg.message) + '</td>' +
            '<td style="text-align:center; vertical-align:middle;">' + triggerBadgeHtml(msg) + '</td>' +
            '<td style="text-align:center; vertical-align:middle;">' +
                '<button type="button" class="sp-btn sp-btn-sm toggle-status-btn ' + (enabled ? 'sp-btn-success' : 'sp-btn-danger') + '" data-id="' + escapeHtml(msg.id) + '" data-status="' + escapeHtml(msg.status) + '" title="' + escapeHtml(enabled ? TM_I18N.clickToDisable : TM_I18N.clickToEnable) + '">' +
                    '<span class="icon"><i class="fas ' + (enabled ? 'fa-toggle-on' : 'fa-toggle-off') + '"></i></span>' +
                    '<span>' + escapeHtml(enabled ? TM_I18N.statusEnabled : TM_I18N.statusDisabled) + '</span>' +
                '</button>' +
            '</td></tr>';
    }).join('');
}

function renderTimedMessagesError() {
    timedMessagesData = [];
    populateTimedMessageSelects();
    var tbody = document.getElementById('timedMessagesTableBody');
    if (tbody) {
        tbody.setAttribute('aria-busy', 'false');
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">' + escapeHtml(TM_I18N.loadError) + '</td></tr>';
    }
}

function loadTimedMessages() {
    var url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.success) {
                renderTimedMessagesError();
                return;
            }
            timedMessagesData = Array.isArray(data.messages) ? data.messages : [];
            timedMessagesData.sort(function(a, b) { return Number(a.id) - Number(b.id); });
            populateTimedMessageSelects();
            renderTimedMessagesTable();
            showResponse();
            showMessage();
            toggleEditButton();
            toggleRemoveButton();
        })
        .catch(function() {
            renderTimedMessagesError();
        });
}
function applyBetaBotCharLimit(enabled) {
    charLimit = enabled ? 500 : 255;
    localStorage.setItem('betaBotMode', enabled ? '1' : '0');
    ['message', 'edit_message_content'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.setAttribute('maxlength', charLimit);
    });
    updateCharCount('message', 'charCount');
    updateCharCount('edit_message_content', 'editCharCount');
}

// Returns true if the given input's value contains a (shoutout.username) variable
function hasShoutoutVar(inputId) {
    var val = document.getElementById(inputId) ? document.getElementById(inputId).value : '';
    return /\(shoutout\.\w+\)/.test(val);
}

// Show/update the shoutout hint and enforce the interval minimum
function updateShoutoutHint(msgInputId, intervalInputId, hintId) {
    var hint = document.getElementById(hintId);
    var intervalInput = document.getElementById(intervalInputId);
    if (!hint || !intervalInput) return;
    var needsMin60 = hasShoutoutVar(msgInputId);
    hint.style.display = needsMin60 ? '' : 'none';
    intervalInput.min = needsMin60 ? '60' : '5';
    if (needsMin60 && Number(intervalInput.value) < 60) {
        intervalInput.value = '60';
    }
}

// Function to show response for editing
function showResponse() {
    var editSelect = document.getElementById('edit_message');
    if (!editSelect) return;
    var editMessage = editSelect.value;
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
        var editScheduledTimeInput = document.getElementById('edit_scheduled_time');
        if (editScheduledTimeInput) {
            // MySQL TIME stored as HH:MM:SS — strip seconds for <input type="time">
            var rawTime = messageData.scheduled_time || '';
            editScheduledTimeInput.value = rawTime ? rawTime.substring(0, 5) : '';
        }
        updateCharCount('edit_message_content', 'editCharCount');
        updateShoutoutHint('edit_message_content', 'edit_interval', 'editShoutoutHint');
        toggleEditTriggerType();
    } else {
        editMessageContent.value = '';
        editIntervalInput.value = '';
        editChatLineTriggerInput.value = '';
        if (editStatus) editStatus.value = '';
        if (editTriggerType) editTriggerType.value = 'timer';
        var editScheduledTimeInput = document.getElementById('edit_scheduled_time');
        if (editScheduledTimeInput) editScheduledTimeInput.value = '';
        document.getElementById('editCharCount').textContent = '0/255 ' + TM_I18N.charactersSuffix;
        document.getElementById('editCharCount').className = 'sp-help';
        updateShoutoutHint('edit_message_content', 'edit_interval', 'editShoutoutHint');
        toggleEditTriggerType();
    }
    toggleEditButton();
}

// Function to show message content in remove textarea and enable button
function showMessage() {
    var removeSelect = document.getElementById('remove_message');
    if (!removeSelect) return;
    var removeMessage = removeSelect.value;
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
    if (!input || !counter) return;
    const maxLength = charLimit;
    const currentLength = input.value.length;
    // Update the counter text
    counter.textContent = currentLength + '/' + maxLength + ' ' + TM_I18N.charactersSuffix;
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
    document.getElementById('add_scheduled_field').style.display = (triggerType === 'scheduled') ? '' : 'none';
}

// Show/hide edit form fields based on trigger type
function toggleEditTriggerType() {
    var triggerType = document.getElementById('edit_trigger_type') ? document.getElementById('edit_trigger_type').value : 'timer';
    var intervalField = document.getElementById('edit_interval_field');
    var chatLineField = document.getElementById('edit_chat_line_field');
    var scheduledField = document.getElementById('edit_scheduled_field');
    if (intervalField) intervalField.style.display = (triggerType === 'timer' || triggerType === 'both') ? '' : 'none';
    if (chatLineField) chatLineField.style.display = (triggerType === 'chat_lines' || triggerType === 'both') ? '' : 'none';
    if (scheduledField) scheduledField.style.display = (triggerType === 'scheduled') ? '' : 'none';
    toggleEditButton();
}

// Enable/disable add button based on input
function toggleAddButton() {
    var message = document.getElementById('message').value.trim();
    var triggerType = document.getElementById('trigger_type').value;
    var addBtn = document.getElementById('addMessageButton');
    var valid = message.length > 0;
    var intMin = hasShoutoutVar('message') ? 60 : 5;
    if (triggerType === 'timer' || triggerType === 'both') {
        var interval = document.getElementById('interval').value;
        valid = valid && interval !== "" && !isNaN(interval) && Number(interval) >= intMin && Number(interval) <= 480;
    }
    if (triggerType === 'chat_lines' || triggerType === 'both') {
        var chatLine = document.getElementById('chat_line_trigger').value;
        valid = valid && chatLine !== "" && !isNaN(chatLine) && Number(chatLine) >= 5;
    }
    if (triggerType === 'scheduled') {
        var scheduledTime = document.getElementById('scheduled_time').value;
        valid = valid && scheduledTime !== "";
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
    var editIntMin = hasShoutoutVar('edit_message_content') ? 60 : 5;
    if (editTriggerType === 'timer' || editTriggerType === 'both') {
        var editInterval = document.getElementById('edit_interval').value;
        valid = valid && editInterval !== "" && !isNaN(editInterval) && Number(editInterval) >= editIntMin && Number(editInterval) <= 480;
    }
    if (editTriggerType === 'chat_lines' || editTriggerType === 'both') {
        var editChatLineTrigger = document.getElementById('edit_chat_line_trigger').value;
        valid = valid && editChatLineTrigger !== "" && !isNaN(editChatLineTrigger) && Number(editChatLineTrigger) >= 5;
    }
    if (editTriggerType === 'scheduled') {
        var editScheduledTime = document.getElementById('edit_scheduled_time') ? document.getElementById('edit_scheduled_time').value : '';
        valid = valid && editScheduledTime !== "";
    }
    editBtn.disabled = !valid;
}

// Enable/disable remove button based on selection
function toggleRemoveButton() {
    var removeSelect = document.getElementById('remove_message');
    var removeBtn = document.getElementById('removeMessageButton');
    if (!removeSelect || !removeBtn) return;
    removeBtn.disabled = (removeSelect.value === "");
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
        const intMin = hasShoutoutVar('message') ? 60 : 5;
        const intervalInput = document.getElementById('interval');
        if (Number(intervalInput.value) < intMin || Number(intervalInput.value) > 480) {
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
    const editTriggerType = document.getElementById('edit_trigger_type') ? document.getElementById('edit_trigger_type').value : 'timer';
    if (editTriggerType === 'timer' || editTriggerType === 'both') {
        const editIntMin = hasShoutoutVar('edit_message_content') ? 60 : 5;
        const editIntervalInput = document.getElementById('edit_interval');
        if (editIntervalInput && (Number(editIntervalInput.value) < editIntMin || Number(editIntervalInput.value) > 480)) {
            alert(editIntMin === 60 ? TM_I18N.intervalShoutout : TM_I18N.intervalRange);
            return false;
        }
    }
    return true;
}

// Update the edit form to use validation
document.addEventListener('DOMContentLoaded', function() {
    const editForm = document.getElementById('editMessageForm');
    if (editForm) {
        editForm.onsubmit = validateEditForm;
    }
    loadTimedMessages();
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
                title: TM_I18N.swalTitle,
                text: TM_I18N.swalText,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: TM_I18N.swalConfirm,
                cancelButtonText: TM_I18N.swalCancel,
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
    var betaEnabled = localStorage.getItem('betaBotMode') === '1';
    var toggle = document.getElementById('betaBotToggle');
    if (toggle) toggle.checked = betaEnabled;
    applyBetaBotCharLimit(betaEnabled);
    toggleAddTriggerType();
    showResponse();
    updateCharCount('message', 'charCount');
    updateShoutoutHint('message', 'interval', 'shoutoutHint');
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
    document.getElementById('message').addEventListener('input', function() {
        updateShoutoutHint('message', 'interval', 'shoutoutHint');
        toggleAddButton();
    });
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
        btn.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>' + TM_I18N.updating + '</span>';
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
                btn.title = newStatus == 1 ? TM_I18N.clickToDisable : TM_I18N.clickToEnable;
                btn.innerHTML = '<span class="icon"><i class="fas ' + (newStatus == 1 ? 'fa-toggle-on' : 'fa-toggle-off') + '"></i></span>'
                              + '<span>' + (newStatus == 1 ? TM_I18N.statusEnabled : TM_I18N.statusDisabled) + '</span>';
                timedMessagesData.forEach(function(msg) {
                    if (String(msg.id) === String(id)) msg.status = newStatus;
                });
            } else {
                // Restore original state on failure
                btn.className = 'sp-btn sp-btn-sm toggle-status-btn ' + (currentStatus == 1 ? 'sp-btn-success' : 'sp-btn-danger');
                btn.innerHTML = '<span class="icon"><i class="fas ' + (currentStatus == 1 ? 'fa-toggle-on' : 'fa-toggle-off') + '"></i></span>'
                              + '<span>' + (currentStatus == 1 ? TM_I18N.statusEnabled : TM_I18N.statusDisabled) + '</span>';
            }
            btn.disabled = false;
        })
        .catch(function() {
            // Restore original state on network error
            btn.className = 'sp-btn sp-btn-sm toggle-status-btn ' + (currentStatus == 1 ? 'sp-btn-success' : 'sp-btn-danger');
            btn.innerHTML = '<span class="icon"><i class="fas ' + (currentStatus == 1 ? 'fa-toggle-on' : 'fa-toggle-off') + '"></i></span>'
                          + '<span>' + (currentStatus == 1 ? TM_I18N.statusEnabled : TM_I18N.statusDisabled) + '</span>';
            btn.disabled = false;
        });
    });
});
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>