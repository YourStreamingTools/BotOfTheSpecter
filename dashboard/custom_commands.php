<?php
ob_start();
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

function sanitize_command_name($command)
{
    $command = strtolower(str_replace(' ', '', (string)$command));
    return preg_replace('/[^a-z0-9]/', '', $command);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');
    if ($_POST['action'] === 'get_random_pick_options') {
        $commandName = sanitize_command_name($_POST['command_name'] ?? '');
        if ($commandName === '') {
            echo json_encode(['success' => false, 'message' => 'Command name is required.']);
            exit;
        }
        $stmt = $db->prepare("SELECT many_options_enabled, options FROM custom_command_random_pick_options WHERE command = ?");
        $stmt->bind_param('s', $commandName);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        $manyOptionsEnabled = false;
        $options = [];
        if ($row) {
            $manyOptionsEnabled = ((int)($row['many_options_enabled'] ?? 0) === 1);
            $decoded = json_decode($row['options'] ?? '[]', true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (!is_scalar($item)) {
                        continue;
                    }
                    $value = trim((string)$item);
                    if ($value !== '') {
                        $options[] = $value;
                    }
                }
            }
        }
        echo json_encode([
            'success' => true,
            'many_options_enabled' => $manyOptionsEnabled,
            'options' => $options,
        ]);
        exit;
    }
    if ($_POST['action'] === 'save_random_pick_options') {
        $commandName = sanitize_command_name($_POST['command_name'] ?? '');
        if ($commandName === '') {
            echo json_encode(['success' => false, 'message' => 'Command name is required.']);
            exit;
        }
        $decoded = json_decode($_POST['options'] ?? '[]', true);
        if (!is_array($decoded)) {
            echo json_encode(['success' => false, 'message' => 'Options payload is invalid.']);
            exit;
        }
        $cleanOptions = [];
        foreach ($decoded as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $value = trim((string)$item);
            if ($value !== '') {
                $cleanOptions[] = $value;
            }
        }
        $manyOptionsEnabled = 0;
        if (isset($_POST['many_options_enabled'])) {
            $rawEnabled = strtolower((string)$_POST['many_options_enabled']);
            $manyOptionsEnabled = ($rawEnabled === '1' || $rawEnabled === 'true') ? 1 : 0;
        }
        $optionsJson = json_encode(array_values($cleanOptions), JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare("INSERT INTO custom_command_random_pick_options (command, many_options_enabled, options) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE many_options_enabled = VALUES(many_options_enabled), options = VALUES(options)");
        $stmt->bind_param('sis', $commandName, $manyOptionsEnabled, $optionsJson);
        $success = $stmt->execute();
        $stmt->close();
        if (!$success) {
            echo json_encode(['success' => false, 'message' => 'Failed to save options.']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'saved_count' => count($cleanOptions),
            'many_options_enabled' => ($manyOptionsEnabled === 1),
        ]);
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'Unsupported action.']);
    exit;
}

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
        $new_command_name = sanitize_command_name($_POST['new_command_name']);
        // Check if new command name is built-in
        if (array_key_exists($new_command_name, $builtinCommands['commands'])) {
            $status = "Failed to update: The new command name matches a built-in command.";
            $notification_status = "sp-alert-danger";
        } else {
            try {
                // If the command name is changed, update it as well
                $dbPermission = $permissionsMap[$permission];
                $updateSTMT = $db->prepare("UPDATE custom_commands SET command = ?, response = ?, cooldown = ?, permission = ? WHERE command = ?");
                $updateSTMT->bind_param("ssiss", $new_command_name, $command_response, $cooldown, $dbPermission, $command_to_edit);
                $updateSTMT->execute();
                if ($new_command_name !== $command_to_edit) {
                    $renameOptionsSTMT = $db->prepare("UPDATE custom_command_random_pick_options SET command = ? WHERE command = ?");
                    $renameOptionsSTMT->bind_param("ss", $new_command_name, $command_to_edit);
                    $renameOptionsSTMT->execute();
                    $renameOptionsSTMT->close();
                }
                if ($updateSTMT->affected_rows > 0) {
                    $status = "Command ". $command_to_edit . " updated successfully!";
                    $notification_status = "sp-alert-success";
                } else {
                    $status = $command_to_edit . " not found or no changes made.";
                    $notification_status = "sp-alert-danger";
                }
                $updateSTMT->close();
                $commandsSTMT = $db->prepare("SELECT * FROM custom_commands");
                $commandsSTMT->execute();
                $result = $commandsSTMT->get_result();
                $commands = $result->fetch_all(MYSQLI_ASSOC);
                $commandsSTMT->close();
            } catch (Exception $e) {
                $status = "Error updating " .$command_to_edit . ": " . $e->getMessage();
                $notification_status = "sp-alert-danger";
            }
        }
    }
    // Adding a new custom command
    if (isset($_POST['command']) && isset($_POST['response']) && isset($_POST['cooldown'])) {
        $newCommand = sanitize_command_name($_POST['command']);
        $newResponse = $_POST['response'];
        $cooldown = $_POST['cooldown'];
        $permission = isset($_POST['permission']) ? $_POST['permission'] : 'Everyone';
        // Check if command is built-in
        if (array_key_exists($newCommand, $builtinCommands['commands'])) {
            $status = "Failed to add: The custom command name matches a built-in command.";
            $notification_status = "sp-alert-danger";
        } else {
            // Check if command already exists
            $checkSTMT = $db->prepare("SELECT command FROM custom_commands WHERE command = ?");
            $checkSTMT->bind_param("s", $newCommand);
            $checkSTMT->execute();
            $checkSTMT->store_result();
            $alreadyExists = $checkSTMT->num_rows > 0;
            $checkSTMT->close();
            if ($alreadyExists) {
                $status = "Failed to add: The command '!" . $newCommand . "' already exists in the list.";
                $notification_status = "sp-alert-danger";
            } else {
                // Insert new command into MySQL database
                try {
                    $dbPermission = $permissionsMap[$permission];
                    $insertSTMT = $db->prepare("INSERT INTO custom_commands (command, response, status, cooldown, permission) VALUES (?, ?, 'Enabled', ?, ?)");
                    $insertSTMT->bind_param("ssis", $newCommand, $newResponse, $cooldown, $dbPermission);
                    $insertSTMT->execute();
                    $insertSTMT->close();
                    $commandsSTMT = $db->prepare("SELECT * FROM custom_commands");
                    $commandsSTMT->execute();
                    $result = $commandsSTMT->get_result();
                    $commands = $result->fetch_all(MYSQLI_ASSOC);
                    $commandsSTMT->close();
                } catch (Exception $e) {
                    $status = t('custom_commands_error_generic');
                    $notification_status = "sp-alert-danger";
                }
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
            ob_clean();
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
            $deleteOptionsStmt = $db->prepare("DELETE FROM custom_command_random_pick_options WHERE command = ?");
            $deleteOptionsStmt->bind_param('s', $commandToRemove);
            $deleteOptionsStmt->execute();
            $deleteOptionsStmt->close();
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

// Get user's Twitch username & API key from session/database
$twitchUsername = $username;
$userApiKey = isset($_SESSION['api_key']) ? $_SESSION['api_key'] : '';

// Start output buffering for layout
ob_start();
?>
<div class="sp-alert sp-alert-info" style="display:flex; gap:1rem; align-items:flex-start; margin-bottom:1.5rem;">
    <span style="font-size:1.5rem; color:var(--blue); flex-shrink:0;"><i class="fas fa-info-circle"></i></span>
    <div>
        <p style="font-weight:700; margin-bottom:0.4rem;"><?php echo t('navbar_edit_custom_commands'); ?></p>
        <ol style="margin-left:1.25rem; margin-bottom:0.75rem;">
            <li><?php echo t('custom_commands_skip_exclamation'); ?></li>
            <li>
                <?php echo t('custom_commands_add_in_chat'); ?> <code>!addcommand [command] [message]</code>
                <div style="margin-left:1rem; margin-top:0.25rem;"><code>!addcommand mycommand <?php echo t('custom_commands_example_message'); ?></code></div>
            </li>
        </ol>
        <p style="margin-bottom:0.25rem;"><strong><?php echo t('custom_commands_level_up'); ?></strong></p>
        <p style="margin-bottom:0.25rem;"><?php echo t('custom_commands_explore_variables'); ?></p>
        <p style="margin-bottom:0.5rem;"><strong><?php echo t('custom_commands_note'); ?></strong> <?php echo t('custom_commands_note_detail'); ?></p>
        <a href="https://help.botofthespecter.com/custom_variables.php" target="_blank" class="sp-btn sp-btn-primary sp-btn-sm">
            <i class="fas fa-code"></i>
            <span><?php echo t('custom_commands_view_variables'); ?></span>
        </a>
    </div>
</div>
<?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
    <?php if (isset($_POST['command']) && isset($_POST['response']) && empty($status)): ?>
        <div class="sp-alert sp-alert-success" style="margin-bottom:1rem;">
            <i class="fas fa-check-circle"></i>
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
        <div class="sp-alert <?php echo $notification_status; ?>" style="margin-bottom:1rem;">
            <?php echo $status; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<h4 style="font-size:1.15rem; font-weight:700; text-align:center; color:var(--text-primary); margin-bottom:1.5rem;"><?php echo t('navbar_edit_custom_commands'); ?></h4>
<div class="cc-form-grid">
    <div class="sp-card">
        <form method="post" action="" style="display:flex; flex-direction:column; height:100%;">
            <div class="sp-card-header">
                <div class="sp-card-title">
                    <i class="fas fa-plus-circle" style="color:var(--accent);"></i>
                    <?php echo t('custom_commands_add_title'); ?>
                </div>
                <button class="sp-btn sp-btn-primary" type="submit">
                    <i class="fas fa-plus"></i>
                    <span><?php echo t('custom_commands_add_btn'); ?></span>
                </button>
            </div>
            <div class="sp-card-body" style="flex:1; display:flex; flex-direction:column;">
                <div class="sp-form-group">
                    <label class="sp-label" for="command"><?php echo t('custom_commands_command_label'); ?></label>
                    <div class="sp-input-wrap">
                        <i class="fas fa-terminal sp-input-icon"></i>
                        <input class="sp-input" type="text" name="command" id="command" required placeholder="<?php echo t('custom_commands_command_placeholder'); ?>">
                    </div>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="response"><?php echo t('custom_commands_response_label'); ?></label>
                    <div class="sp-input-wrap">
                        <i class="fas fa-message sp-input-icon"></i>
                        <input class="sp-input" type="text" name="response" id="response" required oninput="updateCharCount('response', 'responseCharCount')" maxlength="255" placeholder="<?php echo t('custom_commands_response_placeholder'); ?>">
                    </div>
                    <small id="responseCharCount" class="sp-help">0/255 <?php echo t('custom_commands_characters'); ?></small>
                    <button id="addManyOptionsBtn" class="sp-btn sp-btn-secondary sp-btn-sm" style="display:none; margin-top:0.5rem;" type="button" onclick="handleManyOptionsPrompt('response', 'command', true)">
                        <i class="fas fa-list"></i>
                        <span>Manage options for your command (Beta 5.8)</span>
                    </button>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="cooldown"><?php echo t('custom_commands_cooldown_label'); ?></label>
                    <div class="sp-input-wrap">
                        <i class="fas fa-clock sp-input-icon"></i>
                        <input class="sp-input" type="number" min="1" name="cooldown" id="cooldown" value="15" required>
                    </div>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="permission">Permission Level</label>
                    <div class="sp-input-wrap">
                        <i class="fas fa-users sp-input-icon"></i>
                        <select id="permission" name="permission" class="sp-select" required>
                            <?php foreach ($permissionsMap as $displayName => $dbValue): ?>
                                <option value="<?php echo htmlspecialchars($displayName); ?>" <?php echo $displayName === 'Everyone' ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($displayName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <div class="sp-card">
        <?php if (!empty($commands)): ?>
            <form method="post" action="" style="display:flex; flex-direction:column; height:100%;">
                <div class="sp-card-header">
                    <div class="sp-card-title">
                        <i class="fas fa-edit" style="color:var(--blue);"></i>
                        <?php echo t('custom_commands_edit_title'); ?>
                    </div>
                    <button type="submit" class="sp-btn sp-btn-primary">
                        <i class="fas fa-save"></i>
                        <span><?php echo t('custom_commands_update_btn'); ?></span>
                    </button>
                </div>
                <div class="sp-card-body" style="flex:1; display:flex; flex-direction:column;">
                    <div class="sp-form-group">
                        <label class="sp-label" for="command_to_edit"><?php echo t('custom_commands_edit_select_label'); ?></label>
                        <select name="command_to_edit" id="command_to_edit" class="sp-select" onchange="showResponse()" required>
                            <option value=""><?php echo t('custom_commands_edit_select_placeholder'); ?></option>
                            <?php foreach ($commands as $command): ?>
                                <option value="<?php echo $command['command']; ?>">!<?php echo $command['command']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="new_command_name"><?php echo t('custom_commands_edit_new_name_label'); ?></label>
                        <div class="sp-input-wrap">
                            <i class="fas fa-terminal sp-input-icon"></i>
                            <input class="sp-input" type="text" name="new_command_name" id="new_command_name" value="" required placeholder="<?php echo t('custom_commands_command_placeholder'); ?>">
                        </div>
                        <small class="sp-help"><?php echo t('custom_commands_skip_exclamation'); ?></small>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="command_response"><?php echo t('custom_commands_response_label'); ?></label>
                        <div class="sp-input-wrap">
                            <i class="fas fa-message sp-input-icon"></i>
                            <input class="sp-input" type="text" name="command_response" id="command_response" value="" required oninput="updateCharCount('command_response', 'editResponseCharCount')" maxlength="255" placeholder="<?php echo t('custom_commands_response_placeholder'); ?>">
                        </div>
                        <small id="editResponseCharCount" class="sp-help">0/255 <?php echo t('custom_commands_characters'); ?></small>
                        <button id="editManyOptionsBtn" class="sp-btn sp-btn-secondary sp-btn-sm" style="display:none; margin-top:0.5rem;" type="button" onclick="handleManyOptionsPrompt('command_response', 'new_command_name', true)">
                            <i class="fas fa-list"></i>
                            <span>Manage options for your command (Beta 5.8)</span>
                        </button>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="cooldown_response"><?php echo t('custom_commands_cooldown_label'); ?></label>
                        <div class="sp-input-wrap">
                            <i class="fas fa-clock sp-input-icon"></i>
                            <input class="sp-input" type="number" min="1" name="cooldown_response" id="cooldown_response" value="" required>
                        </div>
                    </div>
                    <div class="sp-form-group">
                        <label class="sp-label" for="permission_response">Permission Level</label>
                        <div class="sp-input-wrap">
                            <i class="fas fa-users sp-input-icon"></i>
                            <select id="permission_response" name="permission_response" class="sp-select" required>
                                <?php foreach ($permissionsMap as $displayName => $dbValue): ?>
                                    <option value="<?php echo htmlspecialchars($displayName); ?>">
                                        <?php echo htmlspecialchars($displayName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </form>
        <?php else: ?>
            <div class="sp-card-header">
                <div class="sp-card-title">
                    <i class="fas fa-edit" style="color:var(--blue);"></i>
                    <?php echo t('custom_commands_edit_title'); ?>
                </div>
            </div>
            <div class="sp-card-body">
                <p style="color:var(--text-muted);"><?php echo t('custom_commands_no_commands'); ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>
<div class="sp-card">
    <div class="sp-card-header">
        <div class="sp-card-title">
            <i class="fas fa-terminal"></i>
            <?php echo t('custom_commands_header'); ?>
        </div>
        <?php if (!empty($commands)): ?>
            <input class="sp-input" type="text" id="searchInput" placeholder="<?php echo t('builtin_commands_search_placeholder'); ?>" style="max-width:300px;">
        <?php endif; ?>
    </div>
    <div class="sp-card-body">
        <?php if (empty($commands)): ?>
            <p><?php echo t('builtin_commands_no_commands'); ?></p>
        <?php else: ?>
            <div class="sp-table-wrap">
                <table class="sp-table" id="commandsTable">
                    <thead>
                        <tr>
                            <th><?php echo t('builtin_commands_table_command'); ?></th>
                            <th><?php echo t('builtin_commands_table_description'); ?></th>
                            <th style="text-align:center;"><?php echo t('builtin_commands_table_usage_level'); ?></th>
                            <th style="text-align:center;"><?php echo t('custom_commands_cooldown_label'); ?></th>
                            <th style="text-align:center;"><?php echo t('builtin_commands_table_status'); ?></th>
                            <th style="text-align:center;"><?php echo t('builtin_commands_table_action'); ?></th>
                            <th style="text-align:center;"><?php echo t('custom_commands_remove'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($commands as $command): ?>
                            <tr>
                                <td>!<?php echo htmlspecialchars($command['command']); ?></td>
                                <td><?php echo htmlspecialchars($command['response']); ?></td>
                                <td style="text-align:center;"><?php echo $permissionsMapReverse[$command['permission']] ?? 'Everyone'; ?></td>
                                <td style="text-align:center;"><?php echo (int)$command['cooldown']; ?><?php echo t('custom_commands_cooldown_seconds'); ?></td>
                                <td style="text-align:center;">
                                    <span class="sp-badge <?php echo ($command['status'] == 'Enabled') ? 'sp-badge-green' : 'sp-badge-red'; ?>">
                                        <?php echo t($command['status'] == 'Enabled' ? 'builtin_commands_status_enabled' : 'builtin_commands_status_disabled'); ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <label style="cursor:pointer;">
                                        <input type="checkbox" class="toggle-checkbox" <?php echo $command['status'] == 'Enabled' ? 'checked' : ''; ?> onchange="toggleStatus('<?php echo $command['command']; ?>', this.checked, this)" style="display:none;">
                                        <span onclick="event.preventDefault(); event.stopPropagation(); this.previousElementSibling.click();" style="font-size:1.3rem; color:<?php echo $command['status'] == 'Enabled' ? 'var(--green)' : 'var(--text-muted)'; ?>;">
                                            <i class="fa-solid <?php echo $command['status'] == 'Enabled' ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
                                        </span>
                                    </label>
                                </td>
                                <td style="text-align:center;">
                                    <form method="POST" style="display:inline;" class="remove-command-form">
                                        <input type="hidden" name="remove_command" value="<?php echo htmlspecialchars($command['command']); ?>">
                                        <button type="button" class="sp-btn sp-btn-danger sp-btn-sm remove-command-btn" title="<?php echo t('custom_commands_remove'); ?>"><i class="fas fa-trash-alt"></i></button>
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
<!-- Hidden fields for YourLinks API -->
<input type="hidden" id="yourlinks_api_key" value="<?php echo htmlspecialchars($userApiKey); ?>">
<input type="hidden" id="yourlinks_username" value="<?php echo htmlspecialchars($twitchUsername); ?>">
<!-- YourLinks URL Shortener Modal -->
<div id="yourlinksModal" class="cc-modal-backdrop">
    <div class="cc-modal">
        <div class="cc-modal-head">
            <div class="cc-modal-title">
                <i class="fas fa-link"></i>
                Create Short Link with YourLinks.click
            </div>
            <button id="yourlinks_close_btn" class="sp-btn sp-btn-ghost sp-btn-sm" aria-label="close" style="font-size:1.2rem; line-height:1;">×</button>
        </div>
        <div class="cc-modal-body">
            <div id="yourlinks_status" style="margin-bottom:1rem;"></div>
            <div class="sp-form-group">
                <label class="sp-label" for="yourlinks_destination">Destination URL</label>
                <div class="sp-input-wrap">
                    <i class="fas fa-globe sp-input-icon"></i>
                    <input class="sp-input" type="url" id="yourlinks_destination" placeholder="https://example.com" readonly>
                </div>
                <small class="sp-help">The URL you entered in the message</small>
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="yourlinks_link_name">Link Name <span style="color:var(--red);">*</span></label>
                <div class="sp-input-wrap">
                    <i class="fas fa-link sp-input-icon"></i>
                    <input class="sp-input" type="text" id="yourlinks_link_name" placeholder="e.g., discord, youtube, twitch" maxlength="50">
                </div>
                <small class="sp-help">Alphanumeric characters, hyphens, and underscores only. Will be: <code><?php echo htmlspecialchars($twitchUsername); ?>.yourlinks.click/<strong>linkname</strong></code></small>
            </div>
            <div class="sp-form-group">
                <label class="sp-label" for="yourlinks_title">Title (Optional)</label>
                <div class="sp-input-wrap">
                    <i class="fas fa-heading sp-input-icon"></i>
                    <input class="sp-input" type="text" id="yourlinks_title" placeholder="e.g., Join My Discord Server" maxlength="100">
                </div>
                <small class="sp-help">Display name for the link (for your reference)</small>
            </div>
        </div>
        <div class="cc-modal-foot">
            <button id="yourlinks_cancel_btn" class="sp-btn sp-btn-secondary">
                <i class="fas fa-times"></i>
                <span>Cancel</span>
            </button>
            <button id="yourlinks_submit_btn" class="sp-btn sp-btn-primary">
                <i class="fas fa-link"></i>
                <span>Create Link</span>
            </button>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/yourlinks-shortener.js?v=<?php echo time(); ?>"></script>
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
    // Initialize URL shortener for input fields
    yourLinksShortener.setSuppressPromptsAfterDecline(true);
    yourLinksShortener.initializeField('response');
    yourLinksShortener.initializeField('command_response');
    initializeRandomPickWatcher('response', 'command');
    initializeRandomPickWatcher('command_response', 'new_command_name');
});

function sanitizeCommandName(commandName) {
    return String(commandName || '')
        .toLowerCase()
        .replace(/\s+/g, '')
        .replace(/[^a-z0-9]/g, '');
}

var randomPickOptionsCache = {};

function sendActionRequest(params, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== XMLHttpRequest.DONE) {
            return;
        }
        if (xhr.status !== 200) {
            callback(new Error('HTTP Error: ' + xhr.status));
            return;
        }
        try {
            callback(null, JSON.parse(xhr.responseText));
        } catch (error) {
            callback(error);
        }
    };
    var encoded = Object.keys(params)
        .map(function(key) {
            return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
        })
        .join('&');
    xhr.send(encoded);
}

function fetchRandomPickOptions(commandName, callback, forceRefresh) {
    if (!forceRefresh && randomPickOptionsCache[commandName]) {
        callback(null, randomPickOptionsCache[commandName]);
        return;
    }
    sendActionRequest(
        {
            action: 'get_random_pick_options',
            command_name: commandName
        },
        function(err, data) {
            if (!err && data && data.success) {
                randomPickOptionsCache[commandName] = data;
            }
            callback(err, data);
        }
    );
}

function prefetchRandomPickOptions(commandName) {
    var normalizedCommand = sanitizeCommandName(commandName);
    if (!normalizedCommand) {
        return;
    }
    fetchRandomPickOptions(normalizedCommand, function() {}, false);
}

function initializeRandomPickWatcher(responseInputId, commandInputId) {
    var responseInput = document.getElementById(responseInputId);
    if (!responseInput) {
        return;
    }
    var buttonId = responseInputId === 'response' ? 'addManyOptionsBtn' : 'editManyOptionsBtn';
    updateManyOptionsButtonVisibility(responseInputId, buttonId);
    responseInput.addEventListener('input', function() {
        updateManyOptionsButtonVisibility(responseInputId, buttonId);
    });
    responseInput.addEventListener('blur', function() {
        handleManyOptionsPrompt(responseInputId, commandInputId, false);
    });
}

function hasManyOptionsToken(responseValue) {
    var value = String(responseValue || '');
    var randomPickPattern = /\(random\.(?:pick|piack)\)/i;
    return randomPickPattern.test(value);
}

function updateManyOptionsButtonVisibility(responseInputId, buttonId) {
    var responseInput = document.getElementById(responseInputId);
    var button = document.getElementById(buttonId);
    if (!responseInput || !button) {
        return;
    }
    button.style.display = hasManyOptionsToken(responseInput.value) ? 'inline-flex' : 'none';
}

function handleManyOptionsPrompt(responseInputId, commandInputId, forceOpen) {
    var responseInput = document.getElementById(responseInputId);
    var commandInput = document.getElementById(commandInputId);
    if (!responseInput || !commandInput) {
        return;
    }
    var responseValue = responseInput.value || '';
    var hasNewSyntax = hasManyOptionsToken(responseValue);
    if (!hasNewSyntax && !forceOpen) {
        return;
    }
    var normalizedCommand = sanitizeCommandName(commandInput.value);
    if (!normalizedCommand) {
        Swal.fire({
            icon: 'info',
            title: 'Command name required',
            text: 'Set your command name first, then manage many options for (random.pick).'
        });
        return;
    }
    var cachedConfig = randomPickOptionsCache[normalizedCommand];
    var alreadyConfigured = cachedConfig && cachedConfig.many_options_enabled;
    var configuredForCommand = responseInput.dataset.randomPickConfiguredCommand === normalizedCommand;
    if (!forceOpen && (alreadyConfigured || configuredForCommand)) {
        return;
    }
    if (forceOpen) {
        openManyOptionsModal(normalizedCommand, responseInputId, false);
        return;
    }
    var promptSignature = normalizedCommand + '|' + responseValue;
    if (responseInput.dataset.randomPickPrompted === promptSignature) {
        return;
    }
    responseInput.dataset.randomPickPrompted = promptSignature;
    Swal.fire({
        icon: 'question',
        title: 'Use the new many options mode?',
        text: 'You used (random.pick). Do you want to manage a large option list in a modal instead of inline text?',
        showCancelButton: true,
        confirmButtonText: 'Yes, use many options',
        cancelButtonText: 'No, keep legacy syntax'
    }).then(function(result) {
        if (result.isConfirmed) {
            openManyOptionsModal(normalizedCommand, responseInputId, true);
        }
    });
}

function extractLegacyInlineRandomPickOptions(responseValue) {
    var value = String(responseValue || '');
    var match = value.match(/\(random\.(?:pick|piack)\.([^\)]+)\)/i);
    if (!match || !match[1]) {
        return [];
    }
    return match[1]
        .split('.')
        .map(function(item) { return item.trim(); })
        .filter(function(item) { return item.length > 0; });
}

function normalizeResponseToManyOptionsToken(responseValue) {
    return String(responseValue || '')
        .replace(/\(random\.(?:pick|piack)\.[^\)]*\)/gi, '(random.pick)')
        .replace(/\(random\.piack\)/gi, '(random.pick)');
}

function openManyOptionsModal(commandName, responseInputId, autoEnable) {
    var responseInput = document.getElementById(responseInputId);
    var inlineOptions = extractLegacyInlineRandomPickOptions(responseInput ? responseInput.value : '');
    if (!randomPickOptionsCache[commandName]) {
        Swal.fire({
            title: 'Loading options...',
            allowOutsideClick: false,
            didOpen: function() {
                Swal.showLoading();
            }
        });
    }
    fetchRandomPickOptions(commandName, function(err, data) {
            if (Swal.isVisible()) {
                Swal.close();
            }
            if (err || !data || !data.success) {
                Swal.fire({
                    icon: 'error',
                    title: 'Unable to load options',
                    text: (data && data.message) ? data.message : 'Please try again.'
                });
                return;
            }
            var dbOptions = Array.isArray(data.options) ? data.options : [];
            var effectiveOptions = dbOptions.length > 0 ? dbOptions : inlineOptions;
            var initialOptions = effectiveOptions.join('\n');
            var isEnabled = !!autoEnable || data.many_options_enabled || inlineOptions.length > 0;
            var checked = isEnabled ? 'checked' : '';
            Swal.fire({
                title: 'Many options for !' + commandName,
                html:
                    '<div class="field has-text-left">' +
                        '<label class="checkbox">' +
                            '<input type="checkbox" id="manyOptionsEnabled" ' + checked + '> Enable many options for (random.pick)' +
                        '</label>' +
                    '</div>' +
                    '<div class="field has-text-left mt-3">' +
                        '<label class="label has-text-black">Options (one per line)</label>' +
                        '<textarea id="manyOptionsList" class="textarea" rows="10" placeholder="Item 1\nItem 2\nItem 3">' + initialOptions + '</textarea>' +
                        '<p class="help has-text-black">No limit on item count. Empty lines are ignored.</p>' +
                    '</div>',
                width: 700,
                showCancelButton: true,
                confirmButtonText: 'Save options',
                preConfirm: function() {
                    var enabled = document.getElementById('manyOptionsEnabled').checked;
                    var raw = document.getElementById('manyOptionsList').value || '';
                    var options = raw
                        .split(/\r?\n/)
                        .map(function(item) { return item.trim(); })
                        .filter(function(item) { return item.length > 0; });
                    return {
                        enabled: enabled,
                        options: options
                    };
                }
            }).then(function(result) {
                if (!result.isConfirmed) {
                    return;
                }
                sendActionRequest(
                    {
                        action: 'save_random_pick_options',
                        command_name: commandName,
                        many_options_enabled: result.value.enabled ? '1' : '0',
                        options: JSON.stringify(result.value.options)
                    },
                    function(saveErr, saveData) {
                        if (saveErr || !saveData || !saveData.success) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Unable to save options',
                                text: (saveData && saveData.message) ? saveData.message : 'Please try again.'
                            });
                            return;
                        }
                        randomPickOptionsCache[commandName] = {
                            success: true,
                            many_options_enabled: !!result.value.enabled,
                            options: result.value.options
                        };
                        if (responseInput) {
                            responseInput.dataset.randomPickConfiguredCommand = commandName;
                        }
                        if (result.value.enabled && responseInput) {
                            responseInput.value = normalizeResponseToManyOptionsToken(responseInput.value);
                            if (responseInput.id === 'response') {
                                updateCharCount('response', 'responseCharCount');
                            }
                            if (responseInput.id === 'command_response') {
                                updateCharCount('command_response', 'editResponseCharCount');
                            }
                            var buttonId = responseInput.id === 'response' ? 'addManyOptionsBtn' : 'editManyOptionsBtn';
                            updateManyOptionsButtonVisibility(responseInput.id, buttonId);
                        }
                        if (responseInput && responseInput.id === 'command_response') {
                            clearEditCustomCommandForm();
                        }
                        Swal.fire({
                            icon: 'success',
                            title: 'Saved',
                            text: 'Many options were saved for !' + commandName + '.'
                        });
                    }
                );
            });
        }, false);
}

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
                            statusSpan.className = "sp-badge " + (isChecked ? "sp-badge-green" : "sp-badge-red");
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
    updateManyOptionsButtonVisibility('command_response', 'editManyOptionsBtn');
    if (commandData && commandData.command) {
        prefetchRandomPickOptions(commandData.command);
    }
}

function clearEditCustomCommandForm() {
    var commandSelect = document.getElementById('command_to_edit');
    var newCommandInput = document.getElementById('new_command_name');
    var responseInput = document.getElementById('command_response');
    var cooldownInput = document.getElementById('cooldown_response');
    var permissionInput = document.getElementById('permission_response');

    if (commandSelect) {
        commandSelect.value = '';
    }
    if (newCommandInput) {
        newCommandInput.value = '';
    }
    if (responseInput) {
        responseInput.value = '';
        responseInput.dataset.randomPickPrompted = '';
        responseInput.dataset.randomPickConfiguredCommand = '';
    }
    if (cooldownInput) {
        cooldownInput.value = '';
    }
    if (permissionInput) {
        permissionInput.value = 'Everyone';
    }

    updateCharCount('command_response', 'editResponseCharCount');
    updateManyOptionsButtonVisibility('command_response', 'editManyOptionsBtn');
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
        input.classList.add('sp-input-error');
        // Trim the input to maxLength characters
        input.value = input.value.substring(0, maxLength);
    } else if (currentLength > maxLength * 0.8) {
        counter.className = 'sp-help sp-help-warning';
        input.classList.remove('sp-input-error');
    } else {
        counter.className = 'sp-help';
        input.classList.remove('sp-input-error');
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
            input.classList.add('sp-input-error');
            valid = false;
            // Find associated help text and update
            const helpId = input.id + 'CharCount';
            const helpText = document.getElementById(helpId);
            if (helpText) {
                helpText.textContent = input.value.length + '/' + maxLength + ' characters - Exceeds limit!';
                helpText.className = 'sp-help sp-help-danger';
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