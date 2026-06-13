<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

require_once '/var/www/lib/require_auth.php';

// Page Title
$pageTitle = t('builtin_commands_page_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'includes/userdata.php';
include 'includes/bot_control.php';
include "includes/mod_access.php";
include 'includes/user_db.php';
include 'includes/storage_used.php';
session_write_close();
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) {
    die('Connection failed: ' . $db->connect_error);
}

// Check for cookie consent
$cookieConsent = isset($_COOKIE['cookie_consent']) && $_COOKIE['cookie_consent'] === 'accepted';

// Get filter preferences from cookies if they exist and consent was given
$showEnabled = true; // Default value
$showDisabled = true; // Default value

if ($cookieConsent) {
    // Read cookie values if they exist, otherwise use defaults
    if (isset($_COOKIE['show_enabled_commands'])) {
        $showEnabled = $_COOKIE['show_enabled_commands'] === 'true';
    }
    if (isset($_COOKIE['show_disabled_commands'])) {
        $showDisabled = $_COOKIE['show_disabled_commands'] === 'true';
    }
}

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

// Load command descriptions from API
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.botofthespecter.com/commands/info");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$jsonText = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($httpCode !== 200 || !$jsonText) {
    die(t('builtin_commands_error_api_load', [$httpCode]));
}

$jsonData = json_decode($jsonText, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die(t('builtin_commands_error_json_parse', [json_last_error_msg()]));
}

if (!isset($jsonData['commands']) || !is_array($jsonData['commands'])) {
    die(t('builtin_commands_error_invalid_data'));
}

$cmdData = $jsonData['commands'];

// Parse command data for descriptions, force levels, and aliases
$cmdDescriptions = [];
$cmdForceLevels = [];
$cmdAliases = [];
foreach ($cmdData as $cmdKey => $cmdInfo) {
    if (is_array($cmdInfo)) {
        $cmdDescriptions[$cmdKey] = $cmdInfo['description'] ?? t('builtin_commands_no_description');
        if (isset($cmdInfo['force_level'])) {
            $cmdForceLevels[$cmdKey] = $cmdInfo['force_level'];
        }
        if (isset($cmdInfo['aliases'])) {
            $cmdAliases[$cmdKey] = $cmdInfo['aliases'];
        }
    } else {
        // Backwards compatibility for old string format
        $cmdDescriptions[$cmdKey] = $cmdInfo;
    }
}

// Update command status or permission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Handle AJAX requests for command options
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        if ($_POST['action'] === 'get_command_options') {
            $command_name = $_POST['command_name'];
            $options = [];
            // Get cooldown options from builtin_commands table
            $cooldown_stmt = $db->prepare("SELECT cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command = ?");
            if ($cooldown_stmt) {
                $cooldown_stmt->bind_param('s', $command_name);
                $cooldown_stmt->execute();
                $cooldown_result = $cooldown_stmt->get_result();
                $cooldown_row = $cooldown_result->fetch_assoc();
                $cooldown_stmt->close();
                if ($cooldown_row) {
                    $options['cooldown_rate'] = (int)$cooldown_row['cooldown_rate'];
                    $options['cooldown_time'] = (int)$cooldown_row['cooldown_time'];
                    $options['cooldown_bucket'] = $cooldown_row['cooldown_bucket'];
                }
            }
            // Get command-specific options from command_options table
            $stmt = $db->prepare("SELECT options FROM command_options WHERE command = ?");
            if ($stmt) {
                $stmt->bind_param('s', $command_name);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $stmt->close();
                if ($row && $row['options']) {
                    $command_options = json_decode($row['options'], true);
                    if (is_array($command_options)) {
                        $options = array_merge($options, $command_options);
                    }
                }
            }
            while (ob_get_level()) ob_end_clean();
            echo json_encode(['success' => true, 'options' => $options]);
            exit();
        }
        if ($_POST['action'] === 'save_command_options') {
            $command_name = $_POST['command_name'];
            $options = $_POST['options'];
            // Validate JSON
            $decoded_options = json_decode($options, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                while (ob_get_level()) ob_end_clean();
                echo json_encode(['success' => false, 'message' => t('builtin_commands_error_invalid_json_options', [json_last_error_msg()])]);
                exit();
            }
            // Save cooldown options to builtin_commands table
            if (isset($decoded_options['cooldown_rate']) || isset($decoded_options['cooldown_time']) || isset($decoded_options['cooldown_bucket'])) {
                $cooldown_rate = $decoded_options['cooldown_rate'] ?? 1;
                $cooldown_time = $decoded_options['cooldown_time'] ?? 15;
                $cooldown_bucket = $decoded_options['cooldown_bucket'] ?? 'default';
                $cooldown_stmt = $db->prepare("UPDATE builtin_commands SET cooldown_rate = ?, cooldown_time = ?, cooldown_bucket = ? WHERE command = ?");
                if ($cooldown_stmt) {
                    $cooldown_stmt->bind_param('iiss', $cooldown_rate, $cooldown_time, $cooldown_bucket, $command_name);
                    if (!$cooldown_stmt->execute()) {
                        while (ob_get_level()) ob_end_clean();
                        echo json_encode(['success' => false, 'message' => t('builtin_commands_error_db_cooldown', [$cooldown_stmt->error])]);
                        $cooldown_stmt->close();
                        exit();
                    }
                    $cooldown_stmt->close();
                }
            }
            // Save command-specific options to command_options table (excluding cooldown options)
            $command_specific_options = array_diff_key($decoded_options, array_flip(['cooldown_rate', 'cooldown_time', 'cooldown_bucket']));
            if (!empty($command_specific_options)) {
                $command_options_json = json_encode($command_specific_options);
                // Insert or update command options
                $stmt = $db->prepare("INSERT INTO command_options (command, options) VALUES (?, ?) ON DUPLICATE KEY UPDATE options = ?");
                if ($stmt) {
                    $stmt->bind_param('sss', $command_name, $command_options_json, $command_options_json);
                    if (!$stmt->execute()) {
                        while (ob_get_level()) ob_end_clean();
                        echo json_encode(['success' => false, 'message' => t('builtin_commands_error_db_save_options', [$stmt->error])]);
                        $stmt->close();
                        exit();
                    }
                    $stmt->close();
                }
            } else {
                // If no command-specific options, remove from command_options table
                $stmt = $db->prepare("DELETE FROM command_options WHERE command = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $command_name);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            while (ob_get_level()) ob_end_clean();
            echo json_encode(['success' => true, 'debug' => 'Options saved successfully']);
            exit();
        }
    }
    // Process permission update
    if (isset($_POST['command_name']) && isset($_POST['usage_level'])) {
        $command_name = $_POST['command_name'];
        $usage_level = $_POST['usage_level'];
        
        // Check if this command has a forced level
        if (!isset($cmdForceLevels[$command_name])) {
            $dbPermission = $permissionsMap[$usage_level];
            $updateQuery = $db->prepare("UPDATE builtin_commands SET permission = ? WHERE command = ?");
            $updateQuery->bind_param('ss', $dbPermission, $command_name);
            $updateQuery->execute();
            $updateQuery->close();
        }
        
        header("Location: builtin.php");
        exit();
    }
    // Process status update
    if (isset($_POST['command_name']) && isset($_POST['status'])) {
        $dbcommand = $_POST['command_name'];
        $dbstatus = $_POST['status'];
        $updateQuery = $db->prepare("UPDATE builtin_commands SET status = ? WHERE command = ?");
        $updateQuery->bind_param('ss', $dbstatus, $dbcommand);
        $updateQuery->execute();
        $updateQuery->close();
        header("Location: builtin.php");
        exit();
    }
}

// Start output buffering for layout
ob_start();
?>
<div class="sp-card">
    <div class="sp-card-header">
        <i class="fas fa-terminal" style="color:var(--blue); margin-right:0.5rem;"></i>
        <div class="sp-card-title"><?php echo t('builtin_commands_header'); ?></div>
        <input class="sp-input" type="text" id="searchInput" onkeyup="searchFunction()" placeholder="<?php echo t('builtin_commands_search_placeholder'); ?>" style="max-width:300px; margin-left:auto;">
    </div>
    <div class="sp-card-body">
        <div style="display:flex; align-items:center; gap:1.5rem; margin-bottom:1rem;">
            <label style="display:flex; align-items:center; gap:0.4rem; color:var(--text-primary); cursor:pointer;">
                <input type="checkbox" id="showEnabled" <?php echo $showEnabled ? 'checked' : ''; ?>>
                <?php echo t('builtin_commands_show_enabled'); ?>
            </label>
            <label style="display:flex; align-items:center; gap:0.4rem; color:var(--text-primary); cursor:pointer;">
                <input type="checkbox" id="showDisabled" <?php echo $showDisabled ? 'checked' : ''; ?>>
                <?php echo t('builtin_commands_show_disabled'); ?>
            </label>
        </div>
        <div class="sp-table-wrap">
            <table class="sp-table" id="commandsTable">
                <thead>
                    <tr>
                        <th style="text-align:center; min-width:155px;"><?php echo t('builtin_commands_table_command'); ?></th>
                        <th style="text-align:center;"><?php echo t('builtin_commands_table_description'); ?></th>
                        <th style="text-align:center;"><?php echo t('builtin_commands_table_usage_level'); ?></th>
                        <th style="text-align:center;"><?php echo t('builtin_commands_table_status'); ?></th>
                        <th style="text-align:center;"><?php echo t('builtin_commands_table_action'); ?></th>
                        <th style="text-align:center;"><?php echo t('builtin_commands_table_options'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($builtinCommands)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center; color:var(--red);"><?php echo t('builtin_commands_no_commands'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($builtinCommands as $command): 
                            $cmdKey = $command['command'];
                            $desc = isset($cmdDescriptions[$cmdKey]) ? $cmdDescriptions[$cmdKey] : t('builtin_commands_no_description');
                            $hasForceLevel = isset($cmdForceLevels[$cmdKey]);
                            $forceLevel = $hasForceLevel ? $cmdForceLevels[$cmdKey] : null;
                            $aliases = isset($cmdAliases[$cmdKey]) ? $cmdAliases[$cmdKey] : [];
                            $tooltipText = !empty($aliases) ? t('builtin_commands_aliases_tooltip', ['aliases' => '!' . implode(', !', $aliases)]) : '';
                        ?>
                        <tr class="commandRow" data-status="<?php echo htmlspecialchars($command['status']); ?>">
                            <td style="text-align:center; font-weight:600; color:var(--blue); vertical-align:middle;">
                                <span <?php echo !empty($tooltipText) ? 'title="' . htmlspecialchars($tooltipText) . '" style="cursor: help; position: relative;"' : ''; ?>>
                                    !<?php echo htmlspecialchars($command['command']); ?>
                                </span>
                            </td>
                            <td style="vertical-align: middle;"><?php echo htmlspecialchars($desc); ?></td>
                            <td>
                                <?php if ($hasForceLevel): ?>
                                    <span class="sp-badge" style="background:rgba(251,191,36,0.15); color:var(--amber); border:1px solid var(--amber);">
                                        <i class="fas fa-lock"></i>
                                        <?php echo t('builtin_commands_permission_' . $forceLevel); ?>
                                    </span>
                                    <small class="sp-help" style="display:block; margin-top:0.3rem;"><?php echo t('builtin_commands_locked_permission'); ?></small>
                                <?php else: ?>
                                    <form method="post">
                                        <input type="hidden" name="command_name" value="<?php echo htmlspecialchars($command['command']); ?>">
                                        <select class="sp-select" name="usage_level" onchange="this.form.submit()">
                                            <?php $currentPermission = htmlspecialchars($command['permission']); foreach ($permissionsMap as $displayValue => $dbValue): ?>
                                                <option value="<?php echo $displayValue; ?>" <?php echo ($currentPermission == $dbValue) ? 'selected' : ''; ?>>
                                                    <?php echo t('builtin_commands_permission_' . $dbValue); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center; vertical-align:middle;">
                                <span class="sp-badge <?php echo ($command['status'] == 'Enabled') ? 'sp-badge-green' : 'sp-badge-red'; ?>">
                                    <?php echo t('builtin_commands_status_' . strtolower($command['status'])); ?>
                                </span>
                            </td>
                            <td style="text-align:center; vertical-align:middle;">
                                <label style="cursor:pointer;">
                                    <input type="checkbox" class="toggle-checkbox" style="position:absolute; opacity:0; width:0; height:0;"
                                        <?php echo ($command['status'] == 'Enabled') ? 'checked' : ''; ?>
                                        onchange="toggleStatus('<?php echo htmlspecialchars($command['command']); ?>', this.checked, this)">
                                    <i class="fa-solid <?php echo $command['status'] == 'Enabled' ? 'fa-toggle-on' : 'fa-toggle-off'; ?>" style="font-size:1.5em; <?php echo $command['status'] == 'Enabled' ? 'color:var(--green);' : 'color:var(--text-muted);'; ?>"></i>
                                </label>
                            </td>
                            <td style="text-align:center; vertical-align:middle;">
                                <button class="sp-btn sp-btn-sm" style="background:transparent; border:1px solid var(--blue); color:var(--blue);" onclick="openCommandModal('<?php echo htmlspecialchars($command['command']); ?>')">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="cc-modal-backdrop" id="commandModal">
    <div class="cc-modal" style="max-width: 600px;">
        <div class="cc-modal-head">
            <span class="cc-modal-title"><?php echo t('builtin_commands_modal_title'); ?> <span id="modalCommandName"></span></span>
            <button class="sp-btn sp-btn-ghost sp-btn-sm" onclick="closeCommandModal()">×</button>
        </div>
        <div class="cc-modal-body" style="max-height: 70vh; overflow-y: auto;">
            <div class="sp-alert sp-alert-info" style="margin-bottom:1rem;">
                <i class="fas fa-info-circle"></i>
                <?php echo t('builtin_commands_cooldown_info'); ?>
            </div>
            <div id="modalContent">
                <!-- Content will be dynamically loaded here -->
            </div>
        </div>
        <div class="cc-modal-foot">
            <button class="sp-btn sp-btn-primary" id="saveOptionsBtn" onclick="saveCommandOptions()"><?php echo t('builtin_commands_save_changes'); ?></button>
            <button class="sp-btn sp-btn-secondary" onclick="closeCommandModal()"><?php echo t('builtin_commands_cancel'); ?></button>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();

ob_start();
?>
<script>
const cookieConsent = <?php echo $cookieConsent ? 'true' : 'false'; ?>;
const BC_I18N = {
    loading: <?php echo json_encode(t('builtin_commands_js_loading')); ?>,
    errorParsing: <?php echo json_encode(t('builtin_commands_js_error_parsing')); ?>,
    errorLoadingOptions: <?php echo json_encode(t('builtin_commands_js_error_loading_options')); ?>,
    errorSavingOptions: <?php echo json_encode(t('builtin_commands_js_error_saving_options')); ?>,
    errorSavingPrefix: <?php echo json_encode(t('builtin_commands_js_error_saving_prefix')); ?>,
    saving: <?php echo json_encode(t('builtin_commands_js_saving')); ?>,
    saveSuccess: <?php echo json_encode(t('builtin_commands_js_save_success')); ?>,
    cooldownRateLabel: <?php echo json_encode(t('builtin_commands_js_cooldown_rate_label')); ?>,
    cooldownRateHelp: <?php echo json_encode(t('builtin_commands_js_cooldown_rate_help')); ?>,
    cooldownTimeLabel: <?php echo json_encode(t('builtin_commands_js_cooldown_time_label')); ?>,
    cooldownTimeHelp: <?php echo json_encode(t('builtin_commands_js_cooldown_time_help')); ?>,
    cooldownBucketLabel: <?php echo json_encode(t('builtin_commands_js_cooldown_bucket_label')); ?>,
    cooldownBucketDefault: <?php echo json_encode(t('builtin_commands_js_cooldown_bucket_default')); ?>,
    cooldownBucketUser: <?php echo json_encode(t('builtin_commands_js_cooldown_bucket_user')); ?>,
    cooldownBucketMod: <?php echo json_encode(t('builtin_commands_js_cooldown_bucket_mod')); ?>,
    cooldownBucketHelp: <?php echo json_encode(t('builtin_commands_js_cooldown_bucket_help')); ?>,
    lurkTimerLabel: <?php echo json_encode(t('builtin_commands_js_lurk_timer_label')); ?>,
    lurkTimerCheckbox: <?php echo json_encode(t('builtin_commands_js_lurk_timer_checkbox')); ?>,
    lurkTimerHelp: <?php echo json_encode(t('builtin_commands_js_lurk_timer_help')); ?>,
    unlurkTimerLabel: <?php echo json_encode(t('builtin_commands_js_unlurk_timer_label')); ?>,
    unlurkTimerCheckbox: <?php echo json_encode(t('builtin_commands_js_unlurk_timer_checkbox')); ?>,
    unlurkTimerHelp: <?php echo json_encode(t('builtin_commands_js_unlurk_timer_help')); ?>
};
// Remember search query using localStorage and attach filter listeners after DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        var query = localStorage.getItem('searchQuery') || '';
        searchInput.value = query;
        searchInput.addEventListener('input', function() {
            localStorage.setItem('searchQuery', this.value);
            applyTableFilters();
        });
    }
    // Attach event listeners for filter checkboxes
    var showEnabled = document.getElementById('showEnabled');
    var showDisabled = document.getElementById('showDisabled');
    if (showEnabled) {
        showEnabled.addEventListener('change', function() {
            applyTableFilters();
            setCookie('show_enabled_commands', this.checked ? 'true' : 'false', 30);
        });
    }
    if (showDisabled) {
        showDisabled.addEventListener('change', function() {
            applyTableFilters();
            setCookie('show_disabled_commands', this.checked ? 'true' : 'false', 30);
        });
    }
    // Initial filter pass (search + status)
    applyTableFilters();
    // Add keyboard support for modal
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeCommandModal();
        }
    });
});

function setCookie(name, value, days) {
    if (!cookieConsent) return;
    const d = new Date();
    d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
    const expires = "expires=" + d.toUTCString();
    document.cookie = name + "=" + value + ";" + expires + ";path=/";
}

function searchFunction() {
    applyTableFilters();
}

function applyTableFilters() {
    const input = document.getElementById('searchInput');
    const filter = input ? input.value.toLowerCase() : '';
    const showEnabledElem = document.getElementById('showEnabled');
    const showDisabledElem = document.getElementById('showDisabled');
    const showEnabled = showEnabledElem ? showEnabledElem.checked : true;
    const showDisabled = showDisabledElem ? showDisabledElem.checked : true;
    const table = document.getElementById('commandsTable');
    if (!table) return;
    const rows = table.getElementsByTagName('tr');
    for (let i = 1; i < rows.length; i++) {
        const row = rows[i];
        if (row.classList.contains('commandRow')) {
            const status = row.getAttribute('data-status');
            const statusAllowed = (showEnabled && status === 'Enabled') || (showDisabled && status === 'Disabled');
            const cells = row.getElementsByTagName('td');
            let matchesSearch = false;
            if (!filter) {
                matchesSearch = true;
            } else {
                for (let j = 0; j < cells.length; j++) {
                    const cellText = cells[j].textContent || cells[j].innerText;
                    if (cellText.toLowerCase().indexOf(filter) > -1) {
                        matchesSearch = true;
                        break;
                    }
                }
            }
            if (statusAllowed && matchesSearch) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        }
    }
}

function toggleFilter() {
    applyTableFilters();
}

function toggleStatus(commandName, isChecked, toggleElem) {
    var iconElem = toggleElem.parentElement.querySelector('i');
    if (iconElem) {
        iconElem.className = "fa-solid fa-spinner fa-spin";
    }
    var status = isChecked ? 'Enabled' : 'Disabled';
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === XMLHttpRequest.DONE) {
            if (xhr.status === 200) {
                location.reload();
            } else {
                console.error('Error updating status:', xhr.responseText);
            }
        }
    };
    xhr.send('command_name=' + encodeURIComponent(commandName) + '&status=' + encodeURIComponent(status));
}

function openCommandModal(commandName) {
    document.getElementById('modalCommandName').textContent = commandName;
    loadCommandOptions(commandName);
    document.getElementById('commandModal').classList.add('is-active');
}

function closeCommandModal() {
    document.getElementById('commandModal').classList.remove('is-active');
    // Clear modal content to prevent stale data
    document.getElementById('modalContent').innerHTML = '';
}

function loadCommandOptions(commandName) {
    const modalContent = document.getElementById('modalContent');
    // Show loading state
    modalContent.innerHTML = '<div style="text-align:center;"><i class="fas fa-spinner fa-spin"></i> ' + BC_I18N.loading + '</div>';
    // Fetch command options
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === XMLHttpRequest.DONE) {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        renderCommandOptions(commandName, response.options);
                    } else {
                        modalContent.innerHTML = '<div class="sp-alert sp-alert-danger">' + response.message + '</div>';
                    }
                } catch (e) {
                    modalContent.innerHTML = '<div class="sp-alert sp-alert-danger">' + BC_I18N.errorParsing + '</div>';
                }
            } else {
                modalContent.innerHTML = '<div class="sp-alert sp-alert-danger">' + BC_I18N.errorLoadingOptions + '</div>';
            }
        }
    };
    xhr.send('action=get_command_options&command_name=' + encodeURIComponent(commandName));
}

function renderCommandOptions(commandName, options) {
    const modalContent = document.getElementById('modalContent');
    // Get cooldown options with defaults
    const cooldownRate = options && options.cooldown_rate !== undefined ? options.cooldown_rate : 1;
    const cooldownTime = options && options.cooldown_time !== undefined ? options.cooldown_time : 15;
    const cooldownBucket = options && options.cooldown_bucket !== undefined ? options.cooldown_bucket : 'default';
    // Build cooldown options HTML
    let html = `
        <div class="sp-form-group">
            <label class="sp-label">${BC_I18N.cooldownRateLabel}</label>
            <input class="sp-input" type="number" id="cooldownRate" value="${cooldownRate}" min="1">
            <small class="sp-help">${BC_I18N.cooldownRateHelp}</small>
        </div>
        <div class="sp-form-group">
            <label class="sp-label">${BC_I18N.cooldownTimeLabel}</label>
            <input class="sp-input" type="number" id="cooldownTime" value="${cooldownTime}" min="0">
            <small class="sp-help">${BC_I18N.cooldownTimeHelp}</small>
        </div>
        <div class="sp-form-group">
            <label class="sp-label">${BC_I18N.cooldownBucketLabel}</label>
            <select class="sp-select" id="cooldownBucket">
                <option value="default" ${cooldownBucket === 'default' ? 'selected' : ''}>${BC_I18N.cooldownBucketDefault}</option>
                <option value="user" ${cooldownBucket === 'user' ? 'selected' : ''}>${BC_I18N.cooldownBucketUser}</option>
                <option value="mod" ${cooldownBucket === 'mod' ? 'selected' : ''}>${BC_I18N.cooldownBucketMod}</option>
            </select>
            <small class="sp-help">${BC_I18N.cooldownBucketHelp}</small>
        </div>
    `;
    // Add command-specific options
    if (commandName === 'lurk') {
        const timerEnabled = options && options.timer ? options.timer : false;
        html += `
            <hr style="border:none; border-top:1px solid var(--bg-surface); margin:1rem 0;">
            <div class="sp-form-group">
                <label class="sp-label">${BC_I18N.lurkTimerLabel}</label>
                <label style="display:flex; align-items:center; gap:0.5rem; color:var(--text-primary); cursor:pointer;">
                    <input type="checkbox" id="lurkTimer" ${timerEnabled ? 'checked' : ''}>
                    ${BC_I18N.lurkTimerCheckbox}
                </label>
                <small class="sp-help">${BC_I18N.lurkTimerHelp}</small>
            </div>
        `;
    } else if (commandName === 'unlurk') {
        const timerEnabled = options && options.timer ? options.timer : false;
        html += `
            <hr style="border:none; border-top:1px solid var(--bg-surface); margin:1rem 0;">
            <div class="sp-form-group">
                <label class="sp-label">${BC_I18N.unlurkTimerLabel}</label>
                <label style="display:flex; align-items:center; gap:0.5rem; color:var(--text-primary); cursor:pointer;">
                    <input type="checkbox" id="unlurkTimer" ${timerEnabled ? 'checked' : ''}>
                    ${BC_I18N.unlurkTimerCheckbox}
                </label>
                <small class="sp-help">${BC_I18N.unlurkTimerHelp}</small>
            </div>
        `;
    }
    modalContent.innerHTML = html;
}

function saveCommandOptions() {
    const commandName = document.getElementById('modalCommandName').textContent;
    let options = {};
    // Collect cooldown options
    const cooldownRate = document.getElementById('cooldownRate');
    const cooldownTime = document.getElementById('cooldownTime');
    const cooldownBucket = document.getElementById('cooldownBucket');
    if (cooldownRate) options.cooldown_rate = parseInt(cooldownRate.value) || 1;
    if (cooldownTime) options.cooldown_time = parseInt(cooldownTime.value) || 15;
    if (cooldownBucket) options.cooldown_bucket = cooldownBucket.value || 'default';
    // Collect command-specific options
    if (commandName === 'lurk') {
        const timerCheckbox = document.getElementById('lurkTimer');
        if (timerCheckbox) {
            options.timer = timerCheckbox.checked;
        }
    } else if (commandName === 'unlurk') {
        const timerCheckbox = document.getElementById('unlurkTimer');
        if (timerCheckbox) {
            options.timer = timerCheckbox.checked;
        }
    }
    // Show saving state
    const saveButton = document.getElementById('saveOptionsBtn');
    const originalText = saveButton.textContent;
    saveButton.textContent = BC_I18N.saving;
    saveButton.disabled = true;
    // Save options via AJAX
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === XMLHttpRequest.DONE) {
            // Reset button state
            saveButton.textContent = originalText;
            saveButton.disabled = false;
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        closeCommandModal();
                        // Show success message
                        showNotification(BC_I18N.saveSuccess, 'sp-alert-success');
                    } else {
                        showNotification(BC_I18N.errorSavingPrefix + response.message, 'sp-alert-danger');
                    }
                } catch (e) {
                    showNotification(BC_I18N.errorParsing, 'sp-alert-danger');
                }
            } else {
                showNotification(BC_I18N.errorSavingOptions, 'sp-alert-danger');
            }
        }
    };
    xhr.send('action=save_command_options&command_name=' + encodeURIComponent(commandName) + '&options=' + encodeURIComponent(JSON.stringify(options)));
}

function showNotification(message, type) {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `sp-alert ${type}`;
    notification.style.position = 'fixed';
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.zIndex = '9999';
    notification.style.maxWidth = '400px';
    notification.innerHTML = `
        <button class="sp-btn sp-btn-ghost sp-btn-sm" onclick="this.parentElement.remove()" style="float:right; padding:0 0.3rem;">×</button>
        ${message}
    `;
    document.body.appendChild(notification);
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>
