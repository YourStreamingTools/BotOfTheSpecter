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
    // Adding a new User Custom Command
    if (isset($_POST['command']) && isset($_POST['response']) && isset($_POST['cooldown']) && isset($_POST['user_id'])) {
        $newCommand = strtolower(str_replace(' ', '', $_POST['command']));
        $newCommand = preg_replace('/[^a-z0-9]/', '', $newCommand);
        $newResponse = $_POST['response'];
        $cooldown = $_POST['cooldown'];
        $user_id = $_POST['user_id'];
        
        // Check if command already exists
        $checkSTMT = $db->prepare("SELECT command FROM custom_user_commands WHERE command = ?");
        $checkSTMT->bind_param("s", $newCommand);
        $checkSTMT->execute();
        $result = $checkSTMT->get_result();
        
        if ($result->num_rows > 0) {
            $status = "Command '" . $newCommand . "' already exists. Please choose a different name.";
            $notification_status = "is-danger";
        } else {
            // Insert new command into MySQL database
            try {
                $insertSTMT = $db->prepare("INSERT INTO custom_user_commands (command, response, status, cooldown, user_id) VALUES (?, ?, 'Enabled', ?, ?)");
                $insertSTMT->bind_param("ssis", $newCommand, $newResponse, $cooldown, $user_id);
                $insertSTMT->execute();
                if ($insertSTMT->affected_rows > 0) {
                    $status = "User command '" . $newCommand . "' for user '" . $user_id . "' added successfully!";
                    $notification_status = "is-success";
                } else {
                    $status = "Error: Command was not added to the database.";
                    $notification_status = "is-danger";
                }
                $insertSTMT->close();
                $commandsSTMT = $db->prepare("SELECT * FROM custom_user_commands ORDER BY command ASC");
                $commandsSTMT->execute();
                $result = $commandsSTMT->get_result();
                $userCommands = $result->fetch_all(MYSQLI_ASSOC);
                $commandsSTMT->close();
            } catch (Exception $e) {
                $status = "Error adding command: " . $e->getMessage();
                $notification_status = "is-danger";
            }
        }
        $checkSTMT->close();
    }
    
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
    
    // Approving User Commands
    if (isset($_POST['approve_command'])) {
        $command = $_POST['approve_command'];
        $status_value = 'Enabled';
        try {
            $statusSTMT = $db->prepare("UPDATE custom_user_commands SET status = ? WHERE command = ?");
            $statusSTMT->bind_param("ss", $status_value, $command);
            $statusSTMT->execute();
            if ($statusSTMT->affected_rows > 0) {
                $status = "User command ". $command . " approved successfully!";
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
            $status = "Error approving command: " . $e->getMessage();
            $notification_status = "is-danger";
        }
    }
    
    // Deleting User Commands
    if (isset($_POST['delete_command'])) {
        $command = $_POST['delete_command'];
        try {
            $deleteSTMT = $db->prepare("DELETE FROM custom_user_commands WHERE command = ?");
            $deleteSTMT->bind_param("s", $command);
            $deleteSTMT->execute();
            if ($deleteSTMT->affected_rows > 0) {
                $status = "User command ". $command . " deleted successfully!";
                $notification_status = "is-success";
            } else {
                $status = $command . " not found.";
                $notification_status = "is-danger";
            }
            $deleteSTMT->close();
            $commandsSTMT = $db->prepare("SELECT * FROM custom_user_commands ORDER BY command ASC");
            $commandsSTMT->execute();
            $result = $commandsSTMT->get_result();
            $userCommands = $result->fetch_all(MYSQLI_ASSOC);
            $commandsSTMT->close();
        } catch (Exception $e) {
            $status = "Error deleting command: " . $e->getMessage();
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
            <p class="mb-2"><strong>Access:</strong> User commands can be used by the specified user and all channel moderators.</p>
        </div>
    </div>
</div>
<?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
    <div class="notification <?php echo $notification_status; ?> is-light mb-4">
        <?php echo $status; ?>
    </div>
<?php endif; ?>
<h4 class="title is-4 has-text-centered mb-5"><?php echo t('navbar_manage_user_commands'); ?></h4>
<div class="columns is-desktop is-centered" style="align-items: stretch; min-height: 100%;">
    <div class="column is-half">
        <div class="box" style="height: 100%; display: flex; flex-direction: column;">
            <div class="mb-3" style="display: flex; align-items: center;">
                <span class="icon is-large has-text-primary" style="margin-right: 0.5rem;">
                    <i class="fas fa-plus-circle fa-2x"></i>
                </span>
                <h4 class="subtitle is-4 mb-0"><?php echo t('user_commands_add_title'); ?></h4>
            </div>
            <form method="post" action="" style="flex-grow: 1;">
                <div class="field mb-4">
                    <label class="label" for="command"><?php echo t('custom_commands_command_label'); ?></label>
                    <div class="control has-icons-left">
                        <input class="input" type="text" name="command" id="command" required placeholder="<?php echo t('custom_commands_command_placeholder'); ?>">
                        <span class="icon is-small is-left"><i class="fas fa-terminal"></i></span>
                    </div>
                    <p class="help"><?php echo t('custom_commands_skip_exclamation'); ?></p>
                </div>
                <div class="field mb-4">
                    <label class="label" for="response"><?php echo t('custom_commands_response_label'); ?></label>
                    <div class="control has-icons-left">
                        <input class="input" type="text" name="response" id="response" required oninput="updateCharCount('response', 'responseCharCount')" maxlength="255" placeholder="<?php echo t('custom_commands_response_placeholder'); ?>">
                        <span class="icon is-small is-left"><i class="fas fa-message"></i></span>
                    </div>
                    <p id="responseCharCount" class="help mt-1">0/255 <?php echo t('custom_commands_characters'); ?></p>
                </div>
                <div class="field mb-4">
                    <label class="label" for="user_id"><?php echo t('user_commands_user_id_label'); ?></label>
                    <div class="control has-icons-left">
                        <input class="input" type="text" name="user_id" id="user_id" required placeholder="<?php echo t('user_commands_user_id_placeholder'); ?>">
                        <span class="icon is-small is-left"><i class="fas fa-user"></i></span>
                    </div>
                    <p class="help"><?php echo t('user_commands_user_id_help'); ?></p>
                    <p class="help has-text-info mt-1"><i class="fas fa-info-circle"></i> This command will also be available to all channel moderators.</p>
                </div>
                <div class="field mb-4">
                    <label class="label" for="cooldown"><?php echo t('custom_commands_cooldown_label'); ?></label>
                    <div class="control has-icons-left">
                        <input class="input" type="number" min="1" name="cooldown" id="cooldown" value="15" required>
                        <span class="icon is-small is-left"><i class="fas fa-clock"></i></span>
                    </div>
                </div>
                <div class="field is-grouped is-grouped-right">
                    <div class="control">
                        <button class="button is-primary" type="submit">
                            <span class="icon"><i class="fas fa-plus"></i></span>
                            <span><?php echo t('custom_commands_add_btn'); ?></span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div class="column is-half">
        <div class="box" style="height: 100%; display: flex; flex-direction: column;">
            <div class="mb-3" style="display: flex; align-items: center;">
                <span class="icon is-large has-text-link" style="margin-right: 0.5rem;">
                    <i class="fas fa-edit fa-2x"></i>
                </span>
                <h4 class="subtitle is-4 mb-0"><?php echo t('user_commands_edit_title'); ?></h4>
            </div>
            <?php if (!empty($userCommands)): ?>
                <form method="post" action="" style="flex-grow: 1;">
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
</div>

<?php if (!empty($userCommands)): ?>
<div class="box mt-5">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h4 class="title is-4 mb-0"><?php echo t('user_commands_list_title'); ?></h4>
        <div class="field mb-0" style="max-width: 340px;">
            <div class="control has-icons-left">
                <input class="input is-rounded" type="text" id="searchInput" placeholder="Search commands or users..." style="box-shadow: 0 1px 6px #0001;">
                <span class="icon is-left has-text-grey-light">
                    <i class="fas fa-search"></i>
                </span>
            </div>
        </div>
    </div>
    <div class="table-container">
        <table class="table is-fullwidth is-striped is-hoverable" id="commandsTable">
            <thead>
                <tr>
                    <th><?php echo t('user_commands_table_command'); ?></th>
                    <th><?php echo t('user_commands_table_response'); ?></th>
                    <th><?php echo t('user_commands_table_user'); ?></th>
                    <th class="has-text-centered"><?php echo t('user_commands_table_cooldown'); ?></th>
                    <th class="has-text-centered"><?php echo t('user_commands_table_status'); ?></th>
                    <th class="has-text-centered"><?php echo t('user_commands_table_actions'); ?></th>
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
                    <td class="has-text-centered"><?php echo $command['cooldown']; ?>s</td>
                    <td class="has-text-centered">
                        <?php if ($command['status'] === 'Enabled'): ?>
                            <span class="tag is-success"><?php echo t('user_commands_status_enabled'); ?></span>
                        <?php else: ?>
                            <span class="tag is-danger"><?php echo t('user_commands_status_disabled'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="has-text-centered">
                        <div class="field is-grouped is-grouped-centered">
                            <?php if ($command['status'] !== 'Enabled'): ?>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="approve_command" value="<?php echo $command['command']; ?>">
                                <button type="submit" class="button is-small is-success" title="<?php echo t('user_commands_approve_tooltip'); ?>">
                                    <span class="icon is-small"><i class="fas fa-check"></i></span>
                                </button>
                            </form>
                            <?php endif; ?>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="delete_command" value="<?php echo $command['command']; ?>">
                                <button type="submit" class="button is-small is-danger" title="Delete Command" onclick="return confirm('Are you sure you want to delete this command?')">
                                    <span class="icon is-small"><i class="fas fa-trash-alt"></i></span>
                                </button>
                            </form>
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
document.addEventListener("DOMContentLoaded", function() {
    var searchInput = document.getElementById("searchInput");
    if (searchInput) {
        searchInput.value = localStorage.getItem("searchTerm") || "";
        searchInput.addEventListener("input", function() {
            localStorage.setItem("searchTerm", this.value);
            searchFunction();
        });
        // Use setTimeout to ensure table is fully rendered before searching
        setTimeout(function() {
            if (typeof searchFunction === "function") {
                searchFunction();
            }
        }, 100);
    }
});

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
    // Initialize character counters for both add and edit forms
    updateCharCount('response', 'responseCharCount');
    updateCharCount('command_response', 'editResponseCharCount');
    
    // Add event listener to command dropdown to update character count when a command is selected
    const editDropdown = document.getElementById('command_to_edit');
    if (editDropdown) {
        editDropdown.addEventListener('change', function() {
            updateCharCount('command_response', 'editResponseCharCount');
        });
    }
    
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

function searchFunction() {
    var input = document.getElementById("searchInput");
    var filter = input.value.toLowerCase();
    var table = document.getElementById("commandsTable");
    var trs = table.getElementsByTagName("tr");
    for (var i = 1; i < trs.length; i++) {
        var tr = trs[i];
        var tds = tr.getElementsByTagName("td");
        var found = false;
        for (var j = 0; j < tds.length; j++) {
            if (tds[j].textContent.toLowerCase().indexOf(filter) > -1) {
                found = true;
                break;
            }
        }
        if (found) {
            tr.classList.remove("fade-out");
            tr.style.display = "";
        } else {
            tr.classList.add("fade-out");
            setTimeout(function(tr) {
                if (tr.classList.contains("fade-out")) {
                    tr.style.display = "none";
                }
            }, 300, tr);
        }
    }
}
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>
