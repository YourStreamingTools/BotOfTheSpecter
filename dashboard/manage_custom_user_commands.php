<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$pageTitle = t('navbar_manage_user_commands');

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

$status = "";
$notification_status = "";

// Check if form data has been submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Editing a User Custom Command
    if (
        isset($_POST['command_to_edit']) && 
        isset($_POST['command_response']) && 
        isset($_POST['cooldown_response']) &&
        isset($_POST['new_command_name'])
    ) {
        $command_to_edit = $_POST['command_to_edit'];
        $command_response = $_POST['command_response'];
        $cooldown = $_POST['cooldown_response'];
        // Remove all non-alphanumeric characters
        $new_command_name = strtolower(str_replace(' ', '', $_POST['new_command_name']));
        $new_command_name = preg_replace('/[^a-z0-9]/', '', $new_command_name);
        try {
            // If the command name is changed, update it as well
            $updateSTMT = $db->prepare("UPDATE custom_user_commands SET command = ?, response = ?, cooldown = ? WHERE command = ?");
            $updateSTMT->bind_param("ssis", $new_command_name, $command_response, $cooldown, $command_to_edit);
            $updateSTMT->execute();
            if ($updateSTMT->affected_rows > 0) {
                $status = "User command ". $command_to_edit . " updated successfully!";
                $notification_status = "is-success";
            } else {
                $status = $command_to_edit . " not found or no changes made.";
                $notification_status = "is-danger";
            }
            $updateSTMT->close();
            $commandsSTMT = $db->prepare("SELECT * FROM custom_user_commands ORDER BY command ASC");
            $commandsSTMT->execute();
            $result = $commandsSTMT->get_result();
            $userCommands = $result->fetch_all(MYSQLI_ASSOC);
            $commandsSTMT->close();
        } catch (Exception $e) {
            $status = "Error updating " .$command_to_edit . ": " . $e->getMessage();
            $notification_status = "is-danger";
        }
    }
    
    // Deleting a User Custom Command
    if (isset($_POST['delete_command'])) {
        $command_to_delete = $_POST['delete_command'];
        try {
            $deleteSTMT = $db->prepare("DELETE FROM custom_user_commands WHERE command = ?");
            $deleteSTMT->bind_param("s", $command_to_delete);
            $deleteSTMT->execute();
            if ($deleteSTMT->affected_rows > 0) {
                $status = "User command ". $command_to_delete . " deleted successfully!";
                $notification_status = "is-success";
            } else {
                $status = $command_to_delete . " not found.";
                $notification_status = "is-danger";
            }
            $deleteSTMT->close();
            $commandsSTMT = $db->prepare("SELECT * FROM custom_user_commands ORDER BY command ASC");
            $commandsSTMT->execute();
            $result = $commandsSTMT->get_result();
            $userCommands = $result->fetch_all(MYSQLI_ASSOC);
            $commandsSTMT->close();
        } catch (Exception $e) {
            $status = "Error deleting " .$command_to_delete . ": " . $e->getMessage();
            $notification_status = "is-danger";
        }
    }
    
    // Approving/Rejecting User Commands
    if (isset($_POST['approve_command']) || isset($_POST['reject_command'])) {
        $command = isset($_POST['approve_command']) ? $_POST['approve_command'] : $_POST['reject_command'];
        $status_value = isset($_POST['approve_command']) ? 'Enabled' : 'Disabled';
        try {
            $statusSTMT = $db->prepare("UPDATE custom_user_commands SET status = ? WHERE command = ?");
            $statusSTMT->bind_param("ss", $status_value, $command);
            $statusSTMT->execute();
            if ($statusSTMT->affected_rows > 0) {
                $action = isset($_POST['approve_command']) ? 'approved' : 'rejected';
                $status = "User command ". $command . " " . $action . " successfully!";
                $notification_status = "is-success";
            } else {
                $status = $command . " not found or no changes made.";
                $notification_status = "is-danger";
            }
            $statusSTMT->close();
            $commandsSTMT = $db->prepare("SELECT * FROM custom_user_commands ORDER BY command ASC");
            $commandsSTMT->execute();
            $result = $commandsSTMT->get_result();
            $userCommands = $result->fetch_all(MYSQLI_ASSOC);
            $commandsSTMT->close();
        } catch (Exception $e) {
            $status = "Error updating command status: " . $e->getMessage();
            $notification_status = "is-danger";
        }
    }
}

if (!isset($userCommands)) {
    $commandsSTMT = $db->prepare("SELECT * FROM custom_user_commands ORDER BY command ASC");
    $commandsSTMT->execute();
    $result = $commandsSTMT->get_result();
    $userCommands = $result->fetch_all(MYSQLI_ASSOC);
    $commandsSTMT->close();
}

ob_start();
?>
<div class="notification is-info mb-5">
    <div class="columns is-vcentered">
        <div class="column is-narrow">
            <span class="icon is-large"><i class="fas fa-info-circle fa-2x"></i></span>
        </div>
        <div class="column">
            <p class="title is-6 mb-2"><?php echo t('navbar_manage_user_commands'); ?></p>
            <p class="mb-1"><?php echo t('user_commands_info_desc'); ?></p>
            <ul class="ml-5 mb-3">
                <li><?php echo t('user_commands_view_all'); ?></li>
                <li><?php echo t('user_commands_approve_reject'); ?></li>
                <li><?php echo t('user_commands_edit_responses'); ?></li>
                <li><?php echo t('user_commands_delete_commands'); ?></li>
            </ul>
            <p class="mb-2"><strong><?php echo t('custom_commands_note'); ?></strong> <?php echo t('user_commands_note_detail'); ?></p>
        </div>
    </div>
</div>
<?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
    <div class="notification <?php echo $notification_status; ?> is-light mb-4">
        <?php echo $status; ?>
    </div>
<?php endif; ?>
<h4 class="title is-4 has-text-centered mb-5"><?php echo t('navbar_manage_user_commands'); ?></h4>
<div class="columns is-desktop is-multiline is-centered command-columns-equal" style="align-items: stretch;">
    <div class="column is-6-desktop is-12-mobile">
        <div class="box">
            <div class="mb-3" style="display: flex; align-items: center;">
                <span class="icon is-large has-text-link" style="margin-right: 0.5rem;">
                    <i class="fas fa-edit fa-2x"></i>
                </span>
                <h4 class="subtitle is-4 mb-0"><?php echo t('user_commands_edit_title'); ?></h4>
            </div>
            <?php if (!empty($userCommands)): ?>
                <form method="post" action="">
                    <div class="field mb-4">
                        <label class="label" for="command_to_edit"><?php echo t('user_commands_edit_select_label'); ?></label>
                        <div class="control">
                            <div class="select is-fullwidth">
                                <select name="command_to_edit" id="command_to_edit" onchange="showResponse()" required>
                                    <option value=""><?php echo t('user_commands_edit_select_placeholder'); ?></option>
                                    <?php foreach ($userCommands as $command): ?>
                                        <option value="<?php echo $command['command']; ?>">!<?php echo $command['command']; ?> (for <?php echo $command['user_id']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field mb-4">
                        <label class="label" for="new_command_name"><?php echo t('custom_commands_edit_new_name_label'); ?></label>
                        <div class="control has-icons-left">
                            <input class="input" type="text" name="new_command_name" id="new_command_name" value="" required placeholder="<?php echo t('custom_commands_command_placeholder'); ?>">
                            <span class="icon is-small is-left"><i class="fas fa-terminal"></i></span>
                        </div>
                        <p class="help"><?php echo t('custom_commands_skip_exclamation'); ?></p>
                    </div>
                    <div class="field mb-4">
                        <label class="label" for="command_response"><?php echo t('custom_commands_response_label'); ?></label>
                        <div class="control has-icons-left">
                            <input class="input" type="text" name="command_response" id="command_response" value="" required oninput="updateCharCount('command_response', 'editResponseCharCount')" maxlength="255" placeholder="<?php echo t('custom_commands_response_placeholder'); ?>">
                            <span class="icon is-small is-left"><i class="fas fa-message"></i></span>
                        </div>
                        <p id="editResponseCharCount" class="help mt-1">0/255 <?php echo t('custom_commands_characters'); ?></p>
                    </div>
                    <div class="field mb-4">
                        <label class="label" for="cooldown_response"><?php echo t('custom_commands_cooldown_label'); ?></label>
                        <div class="control has-icons-left">
                            <input class="input" type="number" min="1" name="cooldown_response" id="cooldown_response" value="" required>
                            <span class="icon is-small is-left"><i class="fas fa-clock"></i></span>
                        </div>
                    </div>
                    <div class="field is-grouped is-grouped-right">
                        <div class="control">
                            <button type="submit" class="button is-link">
                                <span class="icon"><i class="fas fa-save"></i></span>
                                <span><?php echo t('custom_commands_update_btn'); ?></span>
                            </button>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <h4 class="subtitle is-4 has-text-grey-light"><?php echo t('user_commands_no_commands'); ?></h4>
            <?php endif; ?>
        </div>
    </div>
    <div class="column is-6-desktop is-12-mobile">
        <div class="box">
            <div class="mb-3" style="display: flex; align-items: center;">
                <span class="icon is-large has-text-danger" style="margin-right: 0.5rem;">
                    <i class="fas fa-trash-alt fa-2x"></i>
                </span>
                <h4 class="subtitle is-4 mb-0"><?php echo t('user_commands_delete_title'); ?></h4>
            </div>
            <?php if (!empty($userCommands)): ?>
                <form method="post" action="" onsubmit="return confirmDelete()">
                    <div class="field mb-4">
                        <label class="label" for="delete_command"><?php echo t('user_commands_delete_select_label'); ?></label>
                        <div class="control">
                            <div class="select is-fullwidth">
                                <select name="delete_command" id="delete_command" required>
                                    <option value=""><?php echo t('user_commands_delete_select_placeholder'); ?></option>
                                    <?php foreach ($userCommands as $command): ?>
                                        <option value="<?php echo $command['command']; ?>">!<?php echo $command['command']; ?> (for <?php echo $command['user_id']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="field is-grouped is-grouped-right">
                        <div class="control">
                            <button type="submit" class="button is-danger">
                                <span class="icon"><i class="fas fa-trash"></i></span>
                                <span><?php echo t('user_commands_delete_btn'); ?></span>
                            </button>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <h4 class="subtitle is-4 has-text-grey-light"><?php echo t('user_commands_no_commands'); ?></h4>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($userCommands)): ?>
<div class="box mt-5">
    <h4 class="title is-4 mb-4"><?php echo t('user_commands_list_title'); ?></h4>
    <div class="table-container">
        <table class="table is-fullwidth is-striped is-hoverable">
            <thead>
                <tr>
                    <th><?php echo t('user_commands_table_command'); ?></th>
                    <th><?php echo t('user_commands_table_response'); ?></th>
                    <th><?php echo t('user_commands_table_user'); ?></th>
                    <th><?php echo t('user_commands_table_cooldown'); ?></th>
                    <th><?php echo t('user_commands_table_status'); ?></th>
                    <th><?php echo t('user_commands_table_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($userCommands as $command): ?>
                <tr>
                    <td><code>!<?php echo htmlspecialchars($command['command']); ?></code></td>
                    <td class="is-family-monospace" style="max-width: 300px; word-wrap: break-word;">
                        <?php echo htmlspecialchars($command['response']); ?>
                    </td>
                    <td><?php echo htmlspecialchars($command['user_id']); ?></td>
                    <td><?php echo $command['cooldown']; ?>s</td>
                    <td>
                        <?php if ($command['status'] === 'Enabled'): ?>
                            <span class="tag is-success"><?php echo t('user_commands_status_enabled'); ?></span>
                        <?php else: ?>
                            <span class="tag is-danger"><?php echo t('user_commands_status_disabled'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="field is-grouped">
                            <?php if ($command['status'] !== 'Enabled'): ?>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="approve_command" value="<?php echo $command['command']; ?>">
                                <button type="submit" class="button is-small is-success" title="<?php echo t('user_commands_approve_tooltip'); ?>">
                                    <span class="icon is-small"><i class="fas fa-check"></i></span>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if ($command['status'] !== 'Disabled'): ?>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="reject_command" value="<?php echo $command['command']; ?>">
                                <button type="submit" class="button is-small is-warning" title="<?php echo t('user_commands_reject_tooltip'); ?>">
                                    <span class="icon is-small"><i class="fas fa-times"></i></span>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();

ob_start();
?>
<script>
function showResponse() {
    var command = document.getElementById('command_to_edit').value;
    var commands = <?php echo json_encode($userCommands); ?>;
    var responseInput = document.getElementById('command_response');
    var cooldownInput = document.getElementById('cooldown_response');
    var newCommandInput = document.getElementById('new_command_name');
    // Find the response for the selected command and display it in the text box
    var commandData = commands.find(c => c.command === command);
    responseInput.value = commandData ? commandData.response : '';
    cooldownInput.value = commandData ? commandData.cooldown : 15;
    newCommandInput.value = commandData ? commandData.command : '';
    // Update character count for the edit response field
    updateCharCount('command_response', 'editResponseCharCount');
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
        input.classList.add('is-danger');
        // Trim the input to maxLength characters
        input.value = input.value.substring(0, maxLength);
    } else if (currentLength > maxLength * 0.8) {
        counter.className = 'help is-warning';
        input.classList.remove('is-danger');
    } else {
        counter.className = 'help is-info';
        input.classList.remove('is-danger');
    }
}

// Confirm delete function
function confirmDelete() {
    var select = document.getElementById('delete_command');
    var commandName = select.options[select.selectedIndex].text;
    return confirm('Are you sure you want to delete the command: ' + commandName + '? This action cannot be undone.');
}

// Validate form before submission
function validateForm(form) {
    const maxLength = 255;
    let valid = true;
    // Check all text inputs with maxlength attribute
    const textInputs = form.querySelectorAll('input[type="text"][maxlength]');
    textInputs.forEach(input => {
        if (input.value.length > maxLength) {
            input.classList.add('is-danger');
            valid = false;
            // Find associated help text and update
            const helpId = input.id + 'CharCount';
            const helpText = document.getElementById(helpId);
            if (helpText) {
                helpText.textContent = input.value.length + '/' + maxLength + ' characters - Exceeds limit!';
                helpText.className = 'help is-danger';
            }
        }
    });
    return valid;
}

// Initialize character counters when page loads
window.onload = function() {
    // Always initialize the edit response character counter, even when empty
    updateCharCount('command_response', 'editResponseCharCount');
    // Add event listener to command dropdown to update character count when a command is selected
    document.getElementById('command_to_edit').addEventListener('change', function() {
        updateCharCount('command_response', 'editResponseCharCount');
    });
    // Add form validation to forms
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!validateForm(this)) {
                event.preventDefault();
                alert('Please ensure all fields meet the character limits before submitting.');
            }
        });
    });
}
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>
