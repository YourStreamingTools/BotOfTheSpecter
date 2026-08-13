<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('navbar_manage_user_commands');

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
        $stmt = $db->prepare("SELECT * FROM custom_user_commands ORDER BY command ASC");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['success' => true, 'commands' => $rows]);
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
            $status = t('user_commands_msg_already_exists', ['command' => $newCommand]);
            $notification_status = "sp-alert-danger";
        } else {
            // Insert new command into MySQL database
            try {
                $insertSTMT = $db->prepare("INSERT INTO custom_user_commands (command, response, status, cooldown, user_id) VALUES (?, ?, 'Enabled', ?, ?)");
                $insertSTMT->bind_param("ssis", $newCommand, $newResponse, $cooldown, $user_id);
                $insertSTMT->execute();
                if ($insertSTMT->affected_rows > 0) {
                    $status = t('user_commands_msg_add_success', ['command' => $newCommand, 'user' => $user_id]);
                    $notification_status = "sp-alert-success";
                } else {
                    $status = t('user_commands_msg_add_error');
                    $notification_status = "sp-alert-danger";
                }
                $insertSTMT->close();
            } catch (Exception $e) {
                $status = t('user_commands_msg_add_exception') . " " . $e->getMessage();
                $notification_status = "sp-alert-danger";
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
                $status = t('user_commands_msg_update_success', ['command' => $command_to_edit]);
                $notification_status = "sp-alert-success";
            } else {
                $status = t('user_commands_msg_update_not_found', ['command' => $command_to_edit]);
                $notification_status = "sp-alert-danger";
            }
            $updateSTMT->close();
        } catch (Exception $e) {
            $status = t('user_commands_msg_update_exception', ['command' => $command_to_edit]) . " " . $e->getMessage();
            $notification_status = "sp-alert-danger";
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
                $status = t('user_commands_msg_approve_success', ['command' => $command]);
                $notification_status = "sp-alert-success";
            } else {
                $status = t('user_commands_msg_update_not_found', ['command' => $command]);
                $notification_status = "sp-alert-danger";
            }
            $statusSTMT->close();
        } catch (Exception $e) {
            $status = t('user_commands_msg_approve_exception') . " " . $e->getMessage();
            $notification_status = "sp-alert-danger";
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
                $status = t('user_commands_msg_delete_success', ['command' => $command]);
                $notification_status = "sp-alert-success";
            } else {
                $status = t('user_commands_msg_delete_not_found', ['command' => $command]);
                $notification_status = "sp-alert-danger";
            }
            $deleteSTMT->close();
        } catch (Exception $e) {
            $status = t('user_commands_msg_delete_exception') . " " . $e->getMessage();
            $notification_status = "sp-alert-danger";
        }
    }
}

ob_start();
?>

<div class="sp-alert sp-alert-info" style="display:flex; gap:1rem; align-items:flex-start; margin-bottom:1.5rem;">
    <i class="fas fa-info-circle fa-2x" style="flex-shrink:0; margin-top:0.2rem;"></i>
    <div>
        <p style="font-weight:600; margin-bottom:0.4rem;"><?php echo t('navbar_manage_user_commands'); ?></p>
        <p class="mb-1"><?php echo t('user_commands_info_desc'); ?></p>
        <ul style="margin-left:1.2rem; margin-bottom:0.75rem;">
            <li><?php echo t('user_commands_view_all'); ?></li>
            <li><?php echo t('user_commands_approve_reject'); ?></li>
            <li><?php echo t('user_commands_edit_responses'); ?></li>
            <li><?php echo t('user_commands_delete_commands'); ?></li>
        </ul>
        <p style="margin-bottom:0.5rem;"><strong><?php echo t('custom_commands_note'); ?></strong> <?php echo t('user_commands_note_detail'); ?></p>
        <p><?php echo t('manage_custom_user_commands_access_note'); ?></p>
    </div>
</div>
<?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
    <div class="sp-alert <?php echo $notification_status; ?>">
        <?php echo $status; ?>
    </div>
<?php endif; ?>
<h4 style="font-size:1.15rem; font-weight:700; text-align:center; color:var(--text-primary); margin-bottom:1.5rem;"><?php echo t('navbar_manage_user_commands'); ?></h4>
<div class="cc-form-grid">
    <div class="sp-card" style="display:flex; flex-direction:column;">
        <div class="sp-card-header">
            <i class="fas fa-plus-circle" style="color:var(--accent); margin-right:0.5rem;"></i>
            <div class="sp-card-title"><?php echo t('user_commands_add_title'); ?></div>
        </div>
        <div class="sp-card-body" style="flex:1; display:flex; flex-direction:column;">
            <form method="post" action="" style="flex-grow: 1;">
                <div class="sp-form-group">
                    <label class="sp-label" for="command"><?php echo t('custom_commands_command_label'); ?></label>
                    <div class="sp-input-wrap">
                        <span class="sp-input-icon"><i class="fas fa-terminal"></i></span>
                        <input class="sp-input" type="text" name="command" id="command" required placeholder="<?php echo t('custom_commands_command_placeholder'); ?>">
                    </div>
                    <small class="sp-help"><?php echo t('custom_commands_skip_exclamation'); ?></small>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="response"><?php echo t('custom_commands_response_label'); ?></label>
                    <div class="sp-input-wrap">
                        <span class="sp-input-icon"><i class="fas fa-message"></i></span>
                        <input class="sp-input" type="text" name="response" id="response" required oninput="updateCharCount('response', 'responseCharCount')" maxlength="255" placeholder="<?php echo t('custom_commands_response_placeholder'); ?>">
                    </div>
                    <small id="responseCharCount" class="sp-help">0/255 <?php echo t('custom_commands_characters'); ?></small>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="user_id"><?php echo t('user_commands_user_id_label'); ?></label>
                    <div class="sp-input-wrap">
                        <span class="sp-input-icon"><i class="fas fa-user"></i></span>
                        <input class="sp-input" type="text" name="user_id" id="user_id" required placeholder="<?php echo t('user_commands_user_id_placeholder'); ?>">
                    </div>
                    <small class="sp-help"><?php echo t('user_commands_user_id_help'); ?></small>
                    <small class="sp-help" style="color:var(--blue);"><i class="fas fa-info-circle"></i> <?php echo t('manage_custom_user_commands_mod_available_note'); ?></small>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="cooldown"><?php echo t('custom_commands_cooldown_label'); ?></label>
                    <div class="sp-input-wrap">
                        <span class="sp-input-icon"><i class="fas fa-clock"></i></span>
                        <input class="sp-input" type="number" min="1" name="cooldown" id="cooldown" value="15" required>
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; margin-top:1rem;">
                    <button class="sp-btn sp-btn-primary" type="submit">
                        <i class="fas fa-plus"></i>
                        <span><?php echo t('custom_commands_add_btn'); ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <div class="sp-card" style="display:flex; flex-direction:column;">
        <div class="sp-card-header">
            <i class="fas fa-edit" style="color:var(--blue); margin-right:0.5rem;"></i>
            <div class="sp-card-title"><?php echo t('user_commands_edit_title'); ?></div>
        </div>
        <div class="sp-card-body" style="flex:1; display:flex; flex-direction:column;" id="editFormHost" aria-busy="true">
            <div id="editFormSkeleton" class="sp-skeleton-stack" aria-hidden="true">
                <span class="sp-skeleton-line w-40"></span>
                <span class="sp-skeleton-line w-90"></span>
                <span class="sp-skeleton-line w-50"></span>
                <span class="sp-skeleton-line w-70"></span>
                <span class="sp-skeleton-line w-80"></span>
                <span class="sp-skeleton-line w-45"></span>
            </div>
            <form id="editUserCommandForm" method="post" action="" style="flex-grow: 1; display:none;">
                <div class="sp-form-group">
                    <label class="sp-label" for="command_to_edit"><?php echo t('user_commands_edit_select_label'); ?></label>
                    <select class="sp-select" name="command_to_edit" id="command_to_edit" onchange="showResponse()" required>
                        <option value=""><?php echo t('user_commands_edit_select_placeholder'); ?></option>
                    </select>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="new_command_name"><?php echo t('custom_commands_edit_new_name_label'); ?></label>
                    <div class="sp-input-wrap">
                        <span class="sp-input-icon"><i class="fas fa-terminal"></i></span>
                        <input class="sp-input" type="text" name="new_command_name" id="new_command_name" value="" required placeholder="<?php echo t('custom_commands_command_placeholder'); ?>">
                    </div>
                    <small class="sp-help"><?php echo t('custom_commands_skip_exclamation'); ?></small>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="command_response"><?php echo t('custom_commands_response_label'); ?></label>
                    <div class="sp-input-wrap">
                        <span class="sp-input-icon"><i class="fas fa-message"></i></span>
                        <input class="sp-input" type="text" name="command_response" id="command_response" value="" required oninput="updateCharCount('command_response', 'editResponseCharCount')" maxlength="255" placeholder="<?php echo t('custom_commands_response_placeholder'); ?>">
                    </div>
                    <small id="editResponseCharCount" class="sp-help">0/255 <?php echo t('custom_commands_characters'); ?></small>
                </div>
                <div class="sp-form-group">
                    <label class="sp-label" for="cooldown_response"><?php echo t('custom_commands_cooldown_label'); ?></label>
                    <div class="sp-input-wrap">
                        <span class="sp-input-icon"><i class="fas fa-clock"></i></span>
                        <input class="sp-input" type="number" min="1" name="cooldown_response" id="cooldown_response" value="" required>
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; margin-top:1rem;">
                    <button type="submit" class="sp-btn sp-btn-primary">
                        <i class="fas fa-save"></i>
                        <span><?php echo t('custom_commands_update_btn'); ?></span>
                    </button>
                </div>
            </form>
            <p id="editEmptyState" style="color:var(--text-muted); display:none;"><?php echo t('user_commands_no_commands'); ?></p>
        </div>
    </div>
</div>

<div class="sp-card" style="margin-top:1.5rem;">
    <div class="sp-card-header">
        <div class="sp-card-title"><?php echo t('user_commands_list_title'); ?></div>
        <input class="sp-input" type="text" id="searchInput" placeholder="<?php echo htmlspecialchars(t('manage_custom_user_commands_search_placeholder')); ?>" style="max-width:300px;">
    </div>
    <div class="sp-card-body">
        <div class="sp-table-wrap">
            <table class="sp-table" id="commandsTable">
                <thead>
                    <tr>
                        <th><?php echo t('user_commands_table_command'); ?></th>
                        <th><?php echo t('user_commands_table_response'); ?></th>
                        <th><?php echo t('user_commands_table_user'); ?></th>
                        <th style="text-align:center;"><?php echo t('user_commands_table_cooldown'); ?></th>
                        <th style="text-align:center;"><?php echo t('user_commands_table_status'); ?></th>
                        <th style="text-align:center;"><?php echo t('user_commands_table_actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="commandsTableBody" aria-busy="true">
                    <?php for ($sk = 0; $sk < 5; $sk++): ?>
                    <tr aria-hidden="true">
                        <td><span class="sp-skeleton-line w-40"></span></td>
                        <td><span class="sp-skeleton-line w-80"></span></td>
                        <td><span class="sp-skeleton-line w-50"></span></td>
                        <td style="text-align:center;"><span class="sp-skeleton-line w-40"></span></td>
                        <td style="text-align:center;"><span class="sp-skeleton-badge"></span></td>
                        <td style="text-align:center;"><span class="sp-skeleton-line w-60"></span></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();

ob_start();
?>
<script>
var userCommands = [];
const i18nCharacters = <?php echo json_encode(t('custom_commands_characters')); ?>;
const i18nExceedsLimit = <?php echo json_encode(t('user_commands_js_exceeds_limit')); ?>;
const i18nCharLimitAlert = <?php echo json_encode(t('user_commands_js_char_limit_alert')); ?>;
const UC_I18N = {
    noCommands: <?php echo json_encode(t('user_commands_no_commands')); ?>,
    loadError: <?php echo json_encode(t('user_commands_msg_add_exception')); ?>,
    selectPlaceholder: <?php echo json_encode(t('user_commands_edit_select_placeholder')); ?>,
    forLabel: <?php echo json_encode(t('manage_custom_user_commands_for_label')); ?>,
    statusEnabled: <?php echo json_encode(t('user_commands_status_enabled')); ?>,
    statusDisabled: <?php echo json_encode(t('user_commands_status_disabled')); ?>,
    approveTooltip: <?php echo json_encode(t('user_commands_approve_tooltip')); ?>,
    deleteTooltip: <?php echo json_encode(t('manage_custom_user_commands_delete_tooltip')); ?>,
    deleteConfirm: <?php echo json_encode(t('user_commands_js_delete_confirm')); ?>
};

function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

function fillCommandSelect(selectEl) {
    if (!selectEl) return;
    var previous = selectEl.value;
    selectEl.innerHTML = '';
    var placeholderOpt = document.createElement('option');
    placeholderOpt.value = '';
    placeholderOpt.textContent = UC_I18N.selectPlaceholder;
    selectEl.appendChild(placeholderOpt);
    userCommands.forEach(function(command) {
        var opt = document.createElement('option');
        opt.value = command.command;
        opt.textContent = '!' + command.command + ' (' + UC_I18N.forLabel + ' ' + command.user_id + ')';
        selectEl.appendChild(opt);
    });
    if (previous && userCommands.some(function(c) { return c.command === previous; })) {
        selectEl.value = previous;
    }
}

function populateUserCommandSelects() {
    var hasCommands = userCommands.length > 0;
    var editSkeleton = document.getElementById('editFormSkeleton');
    var editForm = document.getElementById('editUserCommandForm');
    var editEmpty = document.getElementById('editEmptyState');
    var editHost = document.getElementById('editFormHost');
    if (editSkeleton) editSkeleton.style.display = 'none';
    if (editHost) editHost.setAttribute('aria-busy', 'false');
    if (editForm) {
        editForm.style.display = hasCommands ? '' : 'none';
        fillCommandSelect(document.getElementById('command_to_edit'));
    }
    if (editEmpty) editEmpty.style.display = hasCommands ? 'none' : '';
}

function renderUserCommandsTable() {
    var tbody = document.getElementById('commandsTableBody');
    if (!tbody) return;
    tbody.setAttribute('aria-busy', 'false');
    if (!userCommands.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">' + escapeHtml(UC_I18N.noCommands) + '</td></tr>';
        return;
    }
    tbody.innerHTML = userCommands.map(function(command) {
        var enabled = command.status === 'Enabled';
        var name = command.command;
        var approveBtn = '';
        if (!enabled) {
            approveBtn = '<form method="post" style="display: inline;">' +
                '<input type="hidden" name="approve_command" value="' + escapeHtml(name) + '">' +
                '<button type="submit" class="sp-btn sp-btn-sm" style="background:var(--green); color:#000;" title="' + escapeHtml(UC_I18N.approveTooltip) + '">' +
                '<i class="fas fa-check"></i></button></form>';
        }
        return '<tr>' +
            '<td><code>!' + escapeHtml(name) + '</code></td>' +
            '<td style="max-width: 300px; word-wrap: break-word;">' + escapeHtml(command.response) + '</td>' +
            '<td>' + escapeHtml(command.user_id) + '</td>' +
            '<td style="text-align:center;">' + escapeHtml(command.cooldown) + 's</td>' +
            '<td style="text-align:center;">' +
                (enabled
                    ? '<span class="sp-badge sp-badge-green">' + escapeHtml(UC_I18N.statusEnabled) + '</span>'
                    : '<span class="sp-badge sp-badge-red">' + escapeHtml(UC_I18N.statusDisabled) + '</span>') +
            '</td>' +
            '<td style="text-align:center;"><div style="display:flex; justify-content:center; gap:0.4rem;">' +
                approveBtn +
                '<form method="post" style="display: inline;">' +
                    '<input type="hidden" name="delete_command" value="' + escapeHtml(name) + '">' +
                    '<button type="submit" class="sp-btn sp-btn-danger sp-btn-sm" title="' + escapeHtml(UC_I18N.deleteTooltip) + '" onclick="return confirm(' + JSON.stringify(UC_I18N.deleteConfirm) + ')">' +
                    '<i class="fas fa-trash-alt"></i></button></form>' +
            '</div></td></tr>';
    }).join('');
}

function renderUserCommandsError() {
    userCommands = [];
    populateUserCommandSelects();
    var tbody = document.getElementById('commandsTableBody');
    if (tbody) {
        tbody.setAttribute('aria-busy', 'false');
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">' + escapeHtml(UC_I18N.loadError) + '</td></tr>';
    }
}

function applySavedSearch() {
    var searchInput = document.getElementById("searchInput");
    if (!searchInput) return;
    searchInput.value = localStorage.getItem("searchTerm") || "";
    if (typeof searchFunction === "function") {
        searchFunction();
    }
}

function loadUserCommands() {
    var url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('ajax_action', 'list');
    fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.success) {
                renderUserCommandsError();
                return;
            }
            userCommands = Array.isArray(data.commands) ? data.commands : [];
            populateUserCommandSelects();
            renderUserCommandsTable();
            showResponse();
            applySavedSearch();
        })
        .catch(function() {
            renderUserCommandsError();
        });
}

document.addEventListener("DOMContentLoaded", function() {
    var searchInput = document.getElementById("searchInput");
    if (searchInput) {
        searchInput.addEventListener("input", function() {
            localStorage.setItem("searchTerm", this.value);
            searchFunction();
        });
    }
    loadUserCommands();
});

function showResponse() {
    var selectEl = document.getElementById('command_to_edit');
    var responseInput = document.getElementById('command_response');
    var cooldownInput = document.getElementById('cooldown_response');
    var newCommandInput = document.getElementById('new_command_name');
    if (!selectEl || !responseInput || !cooldownInput || !newCommandInput) return;
    var command = selectEl.value;
    var commandData = userCommands.find(function(c) { return c.command === command; });
    responseInput.value = commandData ? commandData.response : '';
    cooldownInput.value = commandData ? commandData.cooldown : 15;
    newCommandInput.value = commandData ? commandData.command : '';
    updateCharCount('command_response', 'editResponseCharCount');
}

// Function to update character counts
function updateCharCount(inputId, counterId) {
    const input = document.getElementById(inputId);
    const counter = document.getElementById(counterId);
    if (!input || !counter) return;
    const maxLength = 255;
    const currentLength = input.value.length;
    // Update the counter text
    counter.textContent = currentLength + '/' + maxLength + ' ' + i18nCharacters;
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
                helpText.textContent = input.value.length + '/' + maxLength + ' ' + i18nCharacters + i18nExceedsLimit;
                helpText.className = 'sp-help sp-help-danger';
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
                alert(i18nCharLimitAlert);
            }
        });
    });
}

function searchFunction() {
    var input = document.getElementById("searchInput");
    var table = document.getElementById("commandsTable");
    if (!input || !table) return;
    var filter = input.value.toLowerCase();
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
