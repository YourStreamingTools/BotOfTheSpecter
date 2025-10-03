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
$pageTitle = t('navbar_edit_custom_commands');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
$jsonText = file_get_contents(__DIR__ . '/../api/builtin_commands.json');
$builtinCommands = json_decode($jsonText, true);
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);
$status = "";
$notification_status = "";

// Permission mapping (display to db)
$permissionsMap = [
    "Everyone" => "everyone",
    "VIPs" => "vip",
    "All Subscribers" => "all-subs",
    "Tier 1 Subscriber" => "t1-sub",
    "Tier 2 Subscriber" => "t2-sub",
    "Tier 3 Subscriber" => "t3-sub",
    "Mods" => "mod",
    "Broadcaster" => "broadcaster"
];

// Reverse mapping (db to display)
$permissionsMapReverse = array_flip($permissionsMap);

// Check if form data has been submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Editing a Custom Command
    if (
        isset($_POST['command_to_edit']) && 
        isset($_POST['command_response']) && 
        isset($_POST['cooldown_response']) &&
        isset($_POST['new_command_name'])
    ) {
        $command_to_edit = $_POST['command_to_edit'];
        $command_response = $_POST['command_response'];
        $cooldown = $_POST['cooldown_response'];
        $permission = isset($_POST['permission_response']) ? $_POST['permission_response'] : 'Everyone';
        // Remove all non-alphanumeric characters
        $new_command_name = strtolower(str_replace(' ', '', $_POST['new_command_name']));
        $new_command_name = preg_replace('/[^a-z0-9]/', '', $new_command_name);
        // Check if new command name is built-in
        if (array_key_exists($new_command_name, $builtinCommands['commands'])) {
            $status = "Failed to update: The new command name matches a built-in command.";
            $notification_status = "is-danger";
        } else {
            try {
                // If the command name is changed, update it as well
                $dbPermission = $permissionsMap[$permission];
                $updateSTMT = $db->prepare("UPDATE custom_commands SET command = ?, response = ?, cooldown = ?, permission = ? WHERE command = ?");
                $updateSTMT->bind_param("ssiss", $new_command_name, $command_response, $cooldown, $dbPermission, $command_to_edit);
                $updateSTMT->execute();
                if ($updateSTMT->affected_rows > 0) {
                    $status = "Command ". $command_to_edit . " updated successfully!";
                    $notification_status = "is-success";
                } else {
                    $status = $command_to_edit . " not found or no changes made.";
                    $notification_status = "is-danger";
                }
                $updateSTMT->close();
                $commandsSTMT = $db->prepare("SELECT * FROM custom_commands");
                $commandsSTMT->execute();
                $result = $commandsSTMT->get_result();
                $commands = $result->fetch_all(MYSQLI_ASSOC);
                $commandsSTMT->close();
            } catch (Exception $e) {
                $status = "Error updating " .$command_to_edit . ": " . $e->getMessage();
                $notification_status = "is-danger";
            }
        }
    }
    // Adding a new custom command
    if (isset($_POST['command']) && isset($_POST['response']) && isset($_POST['cooldown'])) {
        $newCommand = strtolower(str_replace(' ', '', $_POST['command']));
        $newCommand = preg_replace('/[^a-z0-9]/', '', $newCommand);
        $newResponse = $_POST['response'];
        $cooldown = $_POST['cooldown'];
        $permission = isset($_POST['permission']) ? $_POST['permission'] : 'Everyone';
        // Check if command is built-in
        if (array_key_exists($newCommand, $builtinCommands['commands'])) {
            $status = "Failed to add: The custom command name matches a built-in command.";
            $notification_status = "is-danger";
        } else {
            // Insert new command into MySQL database
            try {
                $dbPermission = $permissionsMap[$permission];
                $insertSTMT = $db->prepare("INSERT INTO custom_commands (command, response, status, cooldown, permission) VALUES (?, ?, 'Enabled', ?, ?)");
                $insertSTMT->bind_param("ssiss", $newCommand, $newResponse, $cooldown, $dbPermission);
                $insertSTMT->execute();
                $insertSTMT->close();
                $commandsSTMT = $db->prepare("SELECT * FROM custom_commands");
                $commandsSTMT->execute();
                $result = $commandsSTMT->get_result();
                $commands = $result->fetch_all(MYSQLI_ASSOC);
                $commandsSTMT->close();
            } catch (Exception $e) {
                $status = t('custom_commands_error_generic');
                $notification_status = "is-danger";
            }
        }
    }
    // Handle status toggle and remove from commands.php
    $dataUpdated = false;
    if (isset($_POST['command']) && isset($_POST['status'])) {
        $dbcommand = $_POST['command'];
        $status_val = $_POST['status'];
        $updateQuery = $db->prepare("UPDATE custom_commands SET status = ? WHERE command = ?");
        if (!$updateQuery) { error_log("MySQL prepare failed: " . $db->error); }
        $updateQuery->bind_param('ss', $status_val, $dbcommand);
        $result = $updateQuery->execute();
        if (!$result) { error_log("MySQL execute failed: " . $updateQuery->error); }
        $affected_rows = $updateQuery->affected_rows;
        $updateQuery->close();
        $dataUpdated = $result;
        // For AJAX requests, return JSON response
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            if ($result && $affected_rows > 0) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Status updated successfully', 
                    'affected_rows' => $affected_rows,
                    'database' => $db->host_info,
                    'database_name' => $_SESSION['username'] ?? 'unknown',
                    'command' => $dbcommand,
                    'new_status' => $status_val
                ]);
            }else {
                echo json_encode(['success' => false, 'message' => 'No rows were updated', 'affected_rows' => $affected_rows]);
            }
            exit;
        }
    }
    if (isset($_POST['remove_command'])) {
        $commandToRemove = $_POST['remove_command'];
        $deleteStmt = $db->prepare("DELETE FROM custom_commands WHERE command = ?");
        $deleteStmt->bind_param('s', $commandToRemove);
        try {
            $deleteStmt->execute();
            $deleteStmt->close();
            $dataUpdated = true;
            $status = "Command removed successfully";
        } catch (mysqli_sql_exception $e) {
            $status = "Error removing command: " . $e->getMessage();
        }
    }
    // Refresh commands data after any database changes
    if ($dataUpdated) {
        $commands = $db->query("SELECT * FROM custom_commands")->fetch_all(MYSQLI_ASSOC);
    }
}

if (!isset($commands)) {
    $commandsSTMT = $db->prepare("SELECT * FROM custom_commands");
    $commandsSTMT->execute();
    $result = $commandsSTMT->get_result();
    $commands = $result->fetch_all(MYSQLI_ASSOC);
    $commandsSTMT->close();
}

// Start output buffering for layout
ob_start();
?>
<div class="notification is-info mb-5">
    <div class="columns is-vcentered">
        <div class="column is-narrow">
            <span class="icon is-large"><i class="fas fa-info-circle fa-2x"></i></span>
        </div>
        <div class="column">
            <p class="title is-6 mb-2"><?php echo t('navbar_edit_custom_commands'); ?></p>
            <ol class="ml-5 mb-3">
                <li><?php echo t('custom_commands_skip_exclamation'); ?></li>
                <li>
                    <?php echo t('custom_commands_add_in_chat'); ?> <code>!addcommand [command] [message]</code>
                    <div class="ml-4 mt-1"><code>!addcommand mycommand <?php echo t('custom_commands_example_message'); ?></code></div>
                </li>
            </ol>
            <p class="mb-1"><strong><?php echo t('custom_commands_level_up'); ?></strong></p>
            <p class="mb-1"><?php echo t('custom_commands_explore_variables'); ?></p>
            <p class="mb-2"><strong><?php echo t('custom_commands_note'); ?></strong> <?php echo t('custom_commands_note_detail'); ?></p>
            <button class="button is-primary is-small" id="openModalButton">
                <span class="icon"><i class="fas fa-code"></i></span>
                <span><?php echo t('custom_commands_view_variables'); ?></span>
            </button>
        </div>
    </div>
</div>
<div class="notification is-success mb-4">
    <div class="columns is-vcentered">
        <div class="column is-narrow">
            <span class="icon is-large"><i class="fas fa-star fa-2x"></i></span>
        </div>
        <div class="column">
            <p class="title is-6 mb-2"><strong>New in Version 5.5!</strong></p>
            <p>Permission levels are now available for custom commands! You can now control who can use each custom command with 8 different permission levels: Everyone, VIPs, All Subscribers, Tier 1/2/3 Subscribers, Mods, and Broadcaster Only.</p>
        </div>
    </div>
</div>
<?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
    <?php if (isset($_POST['command']) && isset($_POST['response']) && empty($status)): ?>
        <div class="notification is-success is-light mb-4">
            <span class="icon"><i class="fas fa-check-circle"></i></span>
            <span>
                <?php
                $commandAdded = strtolower(str_replace(' ', '', $_POST['command']));
                printf(
                    t('custom_commands_added_success'),
                    htmlspecialchars($commandAdded)
                );
                ?>
            </span>
        </div>
    <?php else: ?>
        <div class="notification <?php echo $notification_status; ?> is-light mb-4">
            <?php echo $status; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<h4 class="title is-4 has-text-centered mb-5"><?php echo t('navbar_edit_custom_commands'); ?></h4>
<div class="columns is-desktop is-multiline is-centered command-columns-equal" style="align-items: stretch;">
    <div class="column is-5-desktop is-12-mobile">
        <div class="box">
            <div class="mb-3" style="display: flex; align-items: center;">
                <span class="icon is-large has-text-primary" style="margin-right: 0.5rem;">
                    <i class="fas fa-plus-circle fa-2x"></i>
                </span>
                <h4 class="subtitle is-4 mb-0"><?php echo t('custom_commands_add_title'); ?></h4>
            </div>
            <form method="post" action="">
                <div class="field mb-4">
                    <label class="label" for="command"><?php echo t('custom_commands_command_label'); ?></label>
                    <div class="control has-icons-left">
                        <input class="input" type="text" name="command" id="command" required placeholder="<?php echo t('custom_commands_command_placeholder'); ?>">
                        <span class="icon is-small is-left"><i class="fas fa-terminal"></i></span>
                    </div>
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
                    <label class="label" for="cooldown"><?php echo t('custom_commands_cooldown_label'); ?></label>
                    <div class="control has-icons-left">
                        <input class="input" type="number" min="1" name="cooldown" id="cooldown" value="15" required>
                        <span class="icon is-small is-left"><i class="fas fa-clock"></i></span>
                    </div>
                </div>
                <div class="field mb-4">
                    <label class="label" for="permission">Permission Level</label>
                    <div class="control has-icons-left">
                        <div class="select is-fullwidth">
                            <select id="permission" name="permission" required style="padding-left: 35px;">
                                <?php foreach ($permissionsMap as $displayName => $dbValue): ?>
                                    <option value="<?php echo htmlspecialchars($displayName); ?>" <?php echo $displayName === 'Everyone' ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($displayName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <span class="icon is-small is-left"><i class="fas fa-users"></i></span>
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
    <div class="column is-5-desktop is-12-mobile">
        <div class="box">
            <div class="mb-3" style="display: flex; align-items: center;">
                <span class="icon is-large has-text-link" style="margin-right: 0.5rem;">
                    <i class="fas fa-edit fa-2x"></i>
                </span>
                <h4 class="subtitle is-4 mb-0"><?php echo t('custom_commands_edit_title'); ?></h4>
            </div>
            <?php if (!empty($commands)): ?>
                <form method="post" action="">
                    <div class="field mb-4">
                        <label class="label" for="command_to_edit"><?php echo t('custom_commands_edit_select_label'); ?></label>
                        <div class="control">
                            <div class="select is-fullwidth">
                                <select name="command_to_edit" id="command_to_edit" onchange="showResponse()" required>
                                    <option value=""><?php echo t('custom_commands_edit_select_placeholder'); ?></option>
                                    <?php foreach ($commands as $command): ?>
                                        <option value="<?php echo $command['command']; ?>">!<?php echo $command['command']; ?></option>
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
                    <div class="field mb-4">
                        <label class="label" for="permission_response">Permission Level</label>
                        <div class="control has-icons-left">
                            <div class="select is-fullwidth">
                                <select id="permission_response" name="permission_response" required style="padding-left: 35px;">
                                    <?php foreach ($permissionsMap as $displayName => $dbValue): ?>
                                        <option value="<?php echo htmlspecialchars($displayName); ?>">
                                            <?php echo htmlspecialchars($displayName); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <span class="icon is-small is-left"><i class="fas fa-users"></i></span>
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
                <h4 class="subtitle is-4 has-text-grey-light"><?php echo t('custom_commands_no_commands'); ?></h4>
            <?php endif; ?>
        </div>
    </div>
</div>
<div class="columns is-centered">
    <div class="column is-fullwidth">
        <div class="card has-background-dark has-text-white" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
            <header class="card-header" style="border-bottom: 1px solid #23272f; display: flex; justify-content: space-between; align-items: center;">
                <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
                    <span class="icon mr-2"><i class="fas fa-terminal"></i></span>
                    <?php echo t('custom_commands_header'); ?>
                </span>
                <?php if (!empty($commands)): ?>
                    <div class="field mb-0" style="min-width: 300px;">
                        <div class="control has-icons-left">
                            <input class="input" type="text" id="searchInput" placeholder="<?php echo t('builtin_commands_search_placeholder'); ?>" style="background-color: #2b2f3a; border-color: #4a4a4a; color: white; min-width: 300px;">
                            <span class="icon is-left"><i class="fas fa-search" style="color: #b5b5b5;"></i></span>
                        </div>
                    </div>
                <?php endif; ?>
            </header>
            <div class="card-content">
                <?php if (empty($commands)): ?>
                    <p><?php echo t('builtin_commands_no_commands'); ?></p>
                <?php else: ?>
                    <div class="table-container">
                        <table class="table is-fullwidth has-background-dark" id="commandsTable">
                            <thead>
                                <tr>
                                    <th class="has-text-centered is-narrow"><?php echo t('builtin_commands_table_command'); ?></th>
                                    <th class="has-text-centered"><?php echo t('builtin_commands_table_description'); ?></th>
                                    <th class="has-text-centered is-narrow"><?php echo t('builtin_commands_table_usage_level'); ?></th>
                                    <th class="has-text-centered is-narrow"><?php echo t('custom_commands_cooldown_label'); ?></th>
                                    <th class="has-text-centered is-narrow"><?php echo t('builtin_commands_table_status'); ?></th>
                                    <th class="has-text-centered is-narrow"><?php echo t('builtin_commands_table_action'); ?></th>
                                    <th class="has-text-centered is-narrow"><?php echo t('custom_commands_remove'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($commands as $command): ?>
                                    <tr>
                                        <td class="is-narrow" style="vertical-align: middle;">!<?php echo htmlspecialchars($command['command']); ?></td>
                                        <td style="vertical-align: middle;"><?php echo htmlspecialchars($command['response']); ?></td>
                                        <td class="has-text-centered" style="vertical-align: middle;"><?php echo $permissionsMapReverse[$command['permission']] ?? 'Everyone'; ?>
                                        </td>
                                        <td class="has-text-centered" style="vertical-align: middle;"><?php echo (int)$command['cooldown']; ?><?php echo t('custom_commands_cooldown_seconds'); ?></td>
                                        <td class="has-text-centered" style="vertical-align: middle;">
                                            <span class="tag is-medium <?php echo ($command['status'] == 'Enabled') ? 'is-success' : 'is-danger'; ?>">
                                                <?php echo t($command['status'] == 'Enabled' ? 'builtin_commands_status_enabled' : 'builtin_commands_status_disabled'); ?>
                                            </span>
                                        </td>                                        <td class="has-text-centered is-narrow" style="vertical-align: middle;">
                                            <label class="checkbox" style="cursor:pointer;">
                                                <input type="checkbox" class="toggle-checkbox" <?php echo $command['status'] == 'Enabled' ? 'checked' : ''; ?> onchange="toggleStatus('<?php echo $command['command']; ?>', this.checked, this)" style="display:none;">
                                                <span class="icon is-medium" onclick="event.preventDefault(); event.stopPropagation(); this.previousElementSibling.click();">
                                                    <i class="fa-solid <?php echo $command['status'] == 'Enabled' ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
                                                </span>
                                            </label>
                                        </td>
                                        <td class="has-text-centered is-narrow" style="vertical-align: middle;">
                                            <form method="POST" style="display:inline;" class="remove-command-form">
                                                <input type="hidden" name="remove_command" value="<?php echo htmlspecialchars($command['command']); ?>">
                                                <button type="button" class="button is-small is-danger remove-command-btn" title="<?php echo t('custom_commands_remove'); ?>"><i class="fas fa-trash-alt"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<div class="modal" id="customVariablesModal">
    <div class="modal-background"></div>
    <div class="modal-card custom-width">
        <header class="modal-card-head has-background-dark">
            <p class="modal-card-title has-text-white"><?php echo t('custom_commands_variables_modal_title'); ?><br>
                <span class="has-text-white is-size-6">
                    <?php echo t('custom_commands_variables_modal_hint'); ?>
                </span>
            </p>
            <button class="delete" aria-label="close" id="closeModalButton"></button>
        </header>
        <section class="modal-card-body has-background-dark has-text-white">
            <div class="mb-4">
                <span class="has-text-warning">
                    <?php echo t('custom_var_english_note'); ?>
                </span>
            </div>
            <div class="columns is-desktop is-multiline">
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(count)</span><br>
                    <?php echo t('custom_var_count_desc'); ?><br>
                    <span class="has-text-weight-bold"><?php echo t('custom_var_example'); ?></span><br>
                    <code><?php echo t('custom_var_count_example'); ?></code><br>
                    <span class="has-text-weight-bold"><?php echo t('custom_var_in_chat'); ?></span><br>
                    <code><?php echo t('custom_var_count_in_chat'); ?></code><br><br>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(usercount)</span><br>
                    <?php echo t('custom_var_usercount_desc'); ?><br>
                    <span class="has-text-weight-bold"><?php echo t('custom_var_example'); ?></span><br>
                    <code><?php echo t('custom_var_usercount_example'); ?></code><br>
                    <span class="has-text-weight-bold"><?php echo t('custom_var_in_chat'); ?></span><br>
                    <code><?php echo t('custom_var_usercount_in_chat'); ?></code><br><br>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(customapi.URL)</span><br>
                    <?php echo t('custom_var_customapi_desc'); ?><br>
                    <span class="has-text-weight-bold"><?php echo t('custom_var_example'); ?></span><br>
                    <code><?php echo t('custom_var_customapi_example'); ?></code>
                    <br><span class="has-text-weight-bold"><?php echo t('custom_var_in_chat'); ?></span><br>
                    <code><?php echo t('custom_var_customapi_in_chat'); ?></code>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(daysuntil.DATE)</span><br>
                    <?php echo t('custom_var_daysuntil_desc'); ?><br>
                    <span class="has-text-weight-bold"><?php echo t('custom_var_example'); ?></span><br>
                    <code><?php echo t('custom_var_daysuntil_example'); ?></code>
                    <br><span class="has-text-weight-bold"><?php echo t('custom_var_in_chat'); ?></span><br>
                    <code><?php echo t('custom_var_daysuntil_in_chat'); ?></code>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(timeuntil.DATE-TIME)</span><br>
                    <?php echo t('custom_var_timeuntil_desc'); ?><br>
                    <span class="has-text-weight-bold"><?php echo t('custom_var_example'); ?></span><br>
                    <code><?php echo t('custom_var_timeuntil_example'); ?></code>
                    <br><span class="has-text-weight-bold"><?php echo t('custom_var_in_chat'); ?></span><br>
                    <code><?php echo t('custom_var_timeuntil_in_chat'); ?></code>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(user)</span> | <span class="has-text-weight-bold variable-title">(author)</span><br>
                    <?php echo t('custom_var_user_author_desc'); ?><br>
                    <span class="has-text-weight-bold"><?php echo t('custom_var_example'); ?></span><br>
                    <code><?php echo t('custom_var_user_author_example'); ?></code>
                    <br><span class="has-text-weight-bold"><?php echo t('custom_var_in_chat'); ?></span><br>
                    <code><?php echo t('custom_var_user_author_in_chat'); ?></code>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(random.pick.*)</span><br>
                    <?php echo t('custom_var_random_pick_desc'); ?><br>
                    <span class="has-text-weight-bold"><?php echo t('custom_var_example'); ?></span><br>
                    <code><?php echo t('custom_var_random_pick_example'); ?></code>
                    <br><span class="has-text-weight-bold"><?php echo t('custom_var_in_chat'); ?></span><br>
                    <code><?php echo t('custom_var_random_pick_in_chat'); ?></code>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(math.*)</span><br>
                    <?php echo t('custom_var_math_desc'); ?><br>
                    <span class="has-text-weight-bold"><?php echo t('custom_var_example'); ?></span><br>
                    <code><?php echo t('custom_var_math_example'); ?></code>
                    <br><span class="has-text-weight-bold"><?php echo t('custom_var_in_chat'); ?></span><br>
                    <code><?php echo t('custom_var_math_in_chat'); ?></code>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(command.COMMAND)</span><br>
                    <?php echo t('custom_var_command_desc'); ?><br>
                    <span class="has-text-weight-bold"><?php echo t('custom_var_example'); ?></span><br>
                    <code><?php echo t('custom_var_command_example'); ?></code>
                    <br><span class="has-text-weight-bold"><?php echo t('custom_var_in_chat'); ?></span>
                    <br><?php echo t('custom_var_command_in_chat'); ?>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(random.number)</span><br>
                    <?php echo t('custom_var_random_number_desc'); ?><br>
                    <span class="has-text-weight-bold"><?php echo t('custom_var_example'); ?></span><br>
                    <code><?php echo t('custom_var_random_number_example'); ?></code>
                    <br><span class="has-text-weight-bold"><?php echo t('custom_var_in_chat'); ?></span><br>
                    <code><?php echo t('custom_var_random_number_in_chat'); ?></code>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(random.percent)</span><br>
                    <?php echo t('custom_var_random_percent_desc'); ?><br>
                    <span class="has-text-weight-bold"><?php echo t('custom_var_example'); ?></span><br>
                    <code><?php echo t('custom_var_random_percent_example'); ?></code>
                    <br><span class="has-text-weight-bold"><?php echo t('custom_var_in_chat'); ?></span><br>
                    <code><?php echo t('custom_var_random_percent_in_chat'); ?></code>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(game)</span><br>
                    <?php echo t('custom_var_game_desc'); ?><br>
                    <span class="has-text-weight-bold"><?php echo t('custom_var_example'); ?></span><br>
                    <code><?php echo t('custom_var_game_example'); ?></code>
                    <br><span class="has-text-weight-bold"><?php echo t('custom_var_in_chat'); ?></span><br>
                    <code><?php echo t('custom_var_game_in_chat'); ?></code>
                </div>
            </div>
        </section>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
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
    // SweetAlert2 for remove command
    setupRemoveButtons();
});

function setupRemoveButtons() {
    document.querySelectorAll('.remove-command-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var form = this.closest('form');
            Swal.fire({
                title: '<?php echo t('custom_commands_remove_confirm_title'); ?>',
                text: "<?php echo t('custom_commands_remove_confirm_text'); ?>",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: '<?php echo t('custom_commands_remove_confirm_btn'); ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
}

function toggleStatus(command, isChecked, elem) {
    // Prevent multiple rapid calls
    if (elem.dataset.processing === 'true') {
        return;
    }
    elem.dataset.processing = 'true';
    
    var icon = elem.parentElement.querySelector('i');
    var statusSpan = elem.closest('tr').querySelector('.tag');
    icon.className = "fa-solid fa-spinner fa-spin";
    var status = isChecked ? 'Enabled' : 'Disabled';
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");xhr.onreadystatechange = function() {
        if (xhr.readyState === XMLHttpRequest.DONE) {
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    console.log('Server response:', response);
                    if (response.success) {
                        // Update the toggle icon
                        icon.className = isChecked ? "fa-solid fa-toggle-on" : "fa-solid fa-toggle-off";
                        // Update the status tag
                        if (statusSpan) {
                            statusSpan.className = "tag is-medium " + (isChecked ? "is-success" : "is-danger");
                            statusSpan.textContent = isChecked ? "<?php echo t('builtin_commands_status_enabled'); ?>" : "<?php echo t('builtin_commands_status_disabled'); ?>";
                        }
                        if (response.affected_rows === 0) {
                            console.warn('No rows were affected by the update');
                            alert('Warning: Command may not exist in database');
                        }
                    } else {
                        // On error, revert the checkbox
                        elem.checked = !isChecked;
                        icon.className = !isChecked ? "fa-solid fa-toggle-on" : "fa-solid fa-toggle-off";
                        alert('Error: ' + response.message);
                    }
                } catch (e) {
                    console.error('Error parsing response:', e);
                    console.log('Raw response:', xhr.responseText);
                    // On error, revert the checkbox
                    elem.checked = !isChecked;
                    icon.className = !isChecked ? "fa-solid fa-toggle-on" : "fa-solid fa-toggle-off";
                    alert('Error parsing server response');
                }            } else {
                // On error, revert the checkbox
                elem.checked = !isChecked;
                icon.className = !isChecked ? "fa-solid fa-toggle-on" : "fa-solid fa-toggle-off";
                alert('HTTP Error: ' + xhr.status);
            }
            
            // Reset processing flag in all cases
            elem.dataset.processing = 'false';
        }
    };
    xhr.send("command=" + encodeURIComponent(command) + "&status=" + status);
}

function searchFunction() {
    var input = document.getElementById("searchInput");
    var filter = input.value.toLowerCase();
    var table = document.getElementById("commandsTable");
    var trs = table.getElementsByTagName("tr");
    for (var i = 1; i < trs.length; i++) {
        var tds = trs[i].getElementsByTagName("td");
        var found = false;
        for (var j = 0; j < tds.length; j++) {
            if (tds[j].textContent.toLowerCase().indexOf(filter) > -1) {
                found = true;
                break;
            }
        }
        trs[i].style.display = found ? "" : "none";
    }
}

document.getElementById("openModalButton").addEventListener("click", function() {
    document.getElementById("customVariablesModal").classList.add("is-active");
});
document.getElementById("closeModalButton").addEventListener("click", function() {
    document.getElementById("customVariablesModal").classList.remove("is-active");
});

function showResponse() {
    var command = document.getElementById('command_to_edit').value;
    var commands = <?php echo json_encode($commands); ?>;
    var permissionsMap = <?php echo json_encode(array_flip($permissionsMap)); ?>;
    var responseInput = document.getElementById('command_response');
    var cooldownInput = document.getElementById('cooldown_response');
    var newCommandInput = document.getElementById('new_command_name');
    var permissionInput = document.getElementById('permission_response');
    // Find the response for the selected command and display it in the text box
    var commandData = commands.find(c => c.command === command);
    responseInput.value = commandData ? commandData.response : '';
    cooldownInput.value = commandData ? commandData.cooldown : 15;
    newCommandInput.value = commandData ? commandData.command : '';
    
    // Set permission dropdown
    if (commandData && commandData.permission) {
        var displayPermission = permissionsMap[commandData.permission] || 'Everyone';
        permissionInput.value = displayPermission;
    } else {
        permissionInput.value = 'Everyone';
    }
    
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
    updateCharCount('response', 'responseCharCount');
    // Always initialize the edit response character counter, even when empty
    updateCharCount('command_response', 'editResponseCharCount');
    // Add event listener to command dropdown to update character count when a command is selected
    document.getElementById('command_to_edit').addEventListener('change', function() {
        updateCharCount('command_response', 'editResponseCharCount');
    });
    // Add form validation to both forms
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!validateForm(this)) {
                event.preventDefault();
                alert('<?php echo t('custom_commands_char_limit_alert'); ?>');
            }
        });
    });
}
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>