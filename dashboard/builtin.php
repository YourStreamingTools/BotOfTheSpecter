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
$pageTitle = t('builtin_commands_page_title');

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
curl_close($ch);

if ($httpCode !== 200 || !$jsonText) {
    die('Failed to load builtin commands information from API (HTTP ' . $httpCode . ')');
}

$jsonData = json_decode($jsonText, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die('Failed to parse builtin commands JSON: ' . json_last_error_msg());
}

if (!isset($jsonData['commands']) || !is_array($jsonData['commands'])) {
    die('Invalid builtin commands data structure received from API');
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
            // Get cooldown options from builtin_commands table
            $cooldown_stmt = $db->prepare("SELECT cooldown_rate, cooldown_time, cooldown_bucket FROM builtin_commands WHERE command = ?");
            $cooldown_stmt->bind_param('s', $command_name);
            $cooldown_stmt->execute();
            $cooldown_result = $cooldown_stmt->get_result();
            $cooldown_row = $cooldown_result->fetch_assoc();
            $cooldown_stmt->close();
            $options = [];
            if ($cooldown_row) {
                $options['cooldown_rate'] = (int)$cooldown_row['cooldown_rate'];
                $options['cooldown_time'] = (int)$cooldown_row['cooldown_time'];
                $options['cooldown_bucket'] = $cooldown_row['cooldown_bucket'];
            }
            // Get command-specific options from command_options table
            $stmt = $db->prepare("SELECT options FROM command_options WHERE command = ?");
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
            echo json_encode(['success' => true, 'options' => $options]);
            exit();
        }
        if ($_POST['action'] === 'save_command_options') {
            $command_name = $_POST['command_name'];
            $options = $_POST['options'];
            // Validate JSON
            $decoded_options = json_decode($options, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode(['success' => false, 'message' => 'Invalid JSON options: ' . json_last_error_msg()]);
                exit();
            }
            // Save cooldown options to builtin_commands table
            if (isset($decoded_options['cooldown_rate']) || isset($decoded_options['cooldown_time']) || isset($decoded_options['cooldown_bucket'])) {
                $cooldown_rate = $decoded_options['cooldown_rate'] ?? 1;
                $cooldown_time = $decoded_options['cooldown_time'] ?? 15;
                $cooldown_bucket = $decoded_options['cooldown_bucket'] ?? 'default';
                $cooldown_stmt = $db->prepare("UPDATE builtin_commands SET cooldown_rate = ?, cooldown_time = ?, cooldown_bucket = ? WHERE command = ?");
                $cooldown_stmt->bind_param('iiss', $cooldown_rate, $cooldown_time, $cooldown_bucket, $command_name);
                if (!$cooldown_stmt->execute()) {
                    echo json_encode(['success' => false, 'message' => 'Database error updating cooldown: ' . $cooldown_stmt->error]);
                    $cooldown_stmt->close();
                    exit();
                }
                $cooldown_stmt->close();
            }
            // Save command-specific options to command_options table (excluding cooldown options)
            $command_specific_options = array_diff_key($decoded_options, array_flip(['cooldown_rate', 'cooldown_time', 'cooldown_bucket']));
            if (!empty($command_specific_options)) {
                $command_options_json = json_encode($command_specific_options);
                // Insert or update command options
                $stmt = $db->prepare("INSERT INTO command_options (command, options) VALUES (?, ?) ON DUPLICATE KEY UPDATE options = ?");
                $stmt->bind_param('sss', $command_name, $command_options_json, $command_options_json);
                if (!$stmt->execute()) {
                    echo json_encode(['success' => false, 'message' => 'Database error saving command options: ' . $stmt->error]);
                    $stmt->close();
                    exit();
                }
                $stmt->close();
            } else {
                // If no command-specific options, remove from command_options table
                $stmt = $db->prepare("DELETE FROM command_options WHERE command = ?");
                $stmt->bind_param('s', $command_name);
                $stmt->execute();
                $stmt->close();
            }
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
<div class="card" style="box-shadow: 0 2px 16px #0001;">
    <header class="card-header">
        <p class="card-header-title is-size-4">
            <span class="icon has-text-info mr-2"><i class="fas fa-terminal"></i></span>
            <?php echo t('builtin_commands_header'); ?>
        </p>
    </header>
    <div class="card-content">
        <!--<div class="notification is-info is-light mb-4">
            <span class="icon">
                <i class="fas fa-info-circle"></i>
            </span>
            <strong>New in Version 5.5:</strong> You can now use the "Broadcaster" permission level to restrict commands so that only you (the broadcaster) can use them. This is perfect for commands you want to keep exclusive to yourself.
        </div>-->
        <div class="columns is-vcentered is-mobile mb-3">
            <div class="column is-narrow">
                <label class="checkbox mr-3">
                    <input type="checkbox" id="showEnabled" <?php echo $showEnabled ? 'checked' : ''; ?>>
                    <span class="ml-1"><?php echo t('builtin_commands_show_enabled'); ?></span>
                </label>
                <label class="checkbox">
                    <input type="checkbox" id="showDisabled" <?php echo $showDisabled ? 'checked' : ''; ?>>
                    <span class="ml-1"><?php echo t('builtin_commands_show_disabled'); ?></span>
                </label>
            </div>
            <div class="column">
                <div class="field is-pulled-right" style="max-width: 340px;">
                    <div class="control has-icons-left">
                        <input class="input is-rounded" type="text" id="searchInput" onkeyup="searchFunction()" placeholder="<?php echo t('builtin_commands_search_placeholder'); ?>" style="box-shadow: 0 1px 6px #0001;">
                        <span class="icon is-left has-text-grey-light">
                            <i class="fas fa-search"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <div class="table-container">
            <table class="table is-fullwidth" id="commandsTable">
                <thead>
                    <tr>
                        <th class="has-text-centered has-text-middle" style="min-width: 155px;"><?php echo t('builtin_commands_table_command'); ?></th>
                        <th class="has-text-centered has-text-middle"><?php echo t('builtin_commands_table_description'); ?></th>
                        <th class="has-text-centered"><?php echo t('builtin_commands_table_usage_level'); ?></th>
                        <th class="has-text-centered is-narrow has-text-middle"><?php echo t('builtin_commands_table_status'); ?></th>
                        <th class="has-text-centered is-narrow has-text-middle"><?php echo t('builtin_commands_table_action'); ?></th>
                        <th class="has-text-centered is-narrow has-text-middle">Options</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($builtinCommands)): ?>
                        <tr>
                            <td colspan="6" class="has-text-centered has-text-danger"><?php echo t('builtin_commands_no_commands'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($builtinCommands as $command): 
                            $cmdKey = $command['command'];
                            $desc = isset($cmdDescriptions[$cmdKey]) ? $cmdDescriptions[$cmdKey] : t('builtin_commands_no_description');
                            $hasForceLevel = isset($cmdForceLevels[$cmdKey]);
                            $forceLevel = $hasForceLevel ? $cmdForceLevels[$cmdKey] : null;
                            $aliases = isset($cmdAliases[$cmdKey]) ? $cmdAliases[$cmdKey] : [];
                            $tooltipText = !empty($aliases) ? 'Aliases: !' . implode(', !', $aliases) : '';
                        ?>
                        <tr class="commandRow" data-status="<?php echo htmlspecialchars($command['status']); ?>">
                            <td class="has-text-centered has-text-weight-semibold has-text-info" style="vertical-align: middle;">
                                <span <?php echo !empty($tooltipText) ? 'title="' . htmlspecialchars($tooltipText) . '" style="cursor: help; position: relative;"' : ''; ?>>
                                    !<?php echo htmlspecialchars($command['command']); ?>
                                </span>
                            </td>
                            <td style="vertical-align: middle;"><?php echo htmlspecialchars($desc); ?></td>
                            <td>
                                <?php if ($hasForceLevel): ?>
                                    <div class="field">
                                        <div class="control">
                                            <span class="tag is-medium is-warning">
                                                <span class="icon is-small">
                                                    <i class="fas fa-lock"></i>
                                                </span>
                                                <span><?php echo t('builtin_commands_permission_' . $forceLevel); ?></span>
                                            </span>
                                        </div>
                                        <p class="help is-size-7 has-text-grey">
                                            <?php echo t('builtin_commands_locked_permission'); ?>
                                        </p>
                                    </div>
                                <?php else: ?>
                                    <form method="post">
                                        <input type="hidden" name="command_name" value="<?php echo htmlspecialchars($command['command']); ?>">
                                        <div class="select is-fullwidth">
                                            <select name="usage_level" class="permission-select" onchange="this.form.submit()">
                                                <?php $currentPermission = htmlspecialchars($command['permission']); foreach ($permissionsMap as $displayValue => $dbValue): ?>
                                                    <option value="<?php echo $displayValue; ?>" class="permission-<?php echo $dbValue; ?>" <?php echo ($currentPermission == $dbValue) ? 'selected' : ''; ?>>
                                                        <?php echo t('builtin_commands_permission_' . $dbValue); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td class="has-text-centered" style="vertical-align: middle;">
                                <span class="tag is-medium <?php echo ($command['status'] == 'Enabled') ? 'is-success' : 'is-danger'; ?>">
                                    <?php echo t('builtin_commands_status_' . strtolower($command['status'])); ?>
                                </span>
                            </td>
                            <td class="has-text-centered" style="vertical-align: middle;">
                                <label class="switch" style="cursor:pointer;">
                                    <input type="checkbox" class="toggle-checkbox" style="position:absolute; opacity:0; width:0; height:0;"
                                        <?php echo ($command['status'] == 'Enabled') ? 'checked' : ''; ?>
                                        onchange="toggleStatus('<?php echo htmlspecialchars($command['command']); ?>', this.checked, this)">
                                    <i class="fa-solid <?php echo $command['status'] == 'Enabled' ? 'fa-toggle-on' : 'fa-toggle-off'; ?> ml-2" style="font-size:1.5em;"></i>
                                </label>
                            </td>
                            <td class="has-text-centered" style="vertical-align: middle;">
                                <button class="button is-small is-info is-outlined" onclick="openCommandModal('<?php echo htmlspecialchars($command['command']); ?>')">
                                    <span class="icon is-small">
                                        <i class="fas fa-edit"></i>
                                    </span>
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

<!-- Command Options Modal -->
<div class="modal" id="commandModal">
    <div class="modal-background" onclick="closeCommandModal()"></div>
    <div class="modal-card" style="max-width: 600px;">
        <header class="modal-card-head">
            <p class="modal-card-title">Command Options: <span id="modalCommandName"></span></p>
            <button class="delete" aria-label="close" onclick="closeCommandModal()"></button>
        </header>
        <section class="modal-card-body" style="max-height: 70vh; overflow-y: auto;">
            <div class="notification is-info is-light mb-4">
                <span class="icon">
                    <i class="fas fa-info-circle"></i>
                </span>
                <strong>Cooldown Options:</strong> These settings are available in version 5.5 and above. Configure how often commands can be used.
            </div>
            <div id="modalContent">
                <!-- Content will be dynamically loaded here -->
            </div>
        </section>
        <footer class="modal-card-foot">
            <button class="button is-success" onclick="saveCommandOptions()">Save Changes</button>
            <button class="button" onclick="closeCommandModal()">Cancel</button>
        </footer>
    </div>
</div>

<?php
$content = ob_get_clean();

ob_start();
?>
<script>
const cookieConsent = <?php echo $cookieConsent ? 'true' : 'false'; ?>;
// Remember search query using localStorage and attach filter listeners after DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        var query = localStorage.getItem('searchQuery') || '';
        searchInput.value = query;
        // Use setTimeout to ensure table is fully rendered before searching
        setTimeout(function() {
            if (typeof searchFunction === "function") {
                searchFunction();
            }
        }, 100);
        searchInput.addEventListener('input', function() {
            localStorage.setItem('searchQuery', this.value);
        });
    }
    // Attach event listeners for filter checkboxes
    var showEnabled = document.getElementById('showEnabled');
    var showDisabled = document.getElementById('showDisabled');
    if (showEnabled) {
        showEnabled.addEventListener('change', function() {
            toggleFilter();
            setCookie('show_enabled_commands', this.checked ? 'true' : 'false', 30);
        });
    }
    if (showDisabled) {
        showDisabled.addEventListener('change', function() {
            toggleFilter();
            setCookie('show_disabled_commands', this.checked ? 'true' : 'false', 30);
        });
    }
    // Initial filter
    toggleFilter();
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
    const input = document.getElementById('searchInput');
    const filter = input.value.toLowerCase();
    const table = document.getElementById('commandsTable');
    const rows = table.getElementsByTagName('tr');
    for (let i = 1; i < rows.length; i++) {
        const row = rows[i];
        if (row.classList.contains('commandRow')) {
            const cells = row.getElementsByTagName('td');
            let found = false;
            for (let j = 0; j < cells.length; j++) {
                const cellText = cells[j].textContent || cells[j].innerText;
                if (cellText.toLowerCase().indexOf(filter) > -1) {
                    found = true;
                    break;
                }
            }
            if (found) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        }
    }
}

function toggleFilter() {
    const showEnabled = document.getElementById('showEnabled').checked;
    const showDisabled = document.getElementById('showDisabled').checked;
    const rows = document.querySelectorAll('.commandRow');
    rows.forEach(row => {
        const status = row.getAttribute('data-status');
        if ((showEnabled && status === 'Enabled') || (showDisabled && status === 'Disabled')) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
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
    modalContent.innerHTML = '<div class="has-text-centered"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
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
                        modalContent.innerHTML = '<div class="notification is-danger">' + response.message + '</div>';
                    }
                } catch (e) {
                    modalContent.innerHTML = '<div class="notification is-danger">Error parsing response</div>';
                }
            } else {
                modalContent.innerHTML = '<div class="notification is-danger">Error loading command options</div>';
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
        <div class="field">
            <label class="label">Cooldown Rate</label>
            <div class="control">
                <input class="input" type="number" id="cooldownRate" value="${cooldownRate}" min="1">
            </div>
            <p class="help">Number of times the command can be used before triggering cooldown. 1 means once used, you must wait the cooldown time before using it again.</p>
        </div>
        <div class="field">
            <label class="label">Cooldown Time (seconds)</label>
            <div class="control">
                <input class="input" type="number" id="cooldownTime" value="${cooldownTime}" min="0">
            </div>
            <p class="help">Time in seconds before the command can be used again after reaching the rate limit.</p>
        </div>
        <div class="field">
            <label class="label">Cooldown Bucket</label>
            <div class="control">
                <div class="select is-fullwidth">
                    <select id="cooldownBucket">
                        <option value="default" ${cooldownBucket === 'default' ? 'selected' : ''}>Default (all users)</option>
                        <option value="user" ${cooldownBucket === 'user' ? 'selected' : ''}>User (per-user cooldown)</option>
                        <option value="mod" ${cooldownBucket === 'mod' ? 'selected' : ''}>Mod (only cooldown for mods)</option>
                    </select>
                </div>
            </div>
            <p class="help">Bucket name for grouping cooldowns.</p>
        </div>
    `;
    // Add command-specific options
    if (commandName === 'lurk') {
        const timerEnabled = options && options.timer ? options.timer : false;
        html += `
            <hr class="mt-4 mb-4">
            <div class="field">
                <label class="label">Lurk Timer</label>
                <div class="control">
                    <label class="checkbox">
                        <input type="checkbox" id="lurkTimer" ${timerEnabled ? 'checked' : ''}>
                        Enable lurk timer (shows how long user has been lurking when they use !lurk again)
                    </label>
                </div>
                <p class="help">When enabled, the bot will track how long users have been lurking and display the duration when they use the !lurk command again.</p>
            </div>
        `;
    } else if (commandName === 'unlurk') {
        const timerEnabled = options && options.timer ? options.timer : false;
        html += `
            <hr class="mt-4 mb-4">
            <div class="field">
                <label class="label">Unlurk Timer</label>
                <div class="control">
                    <label class="checkbox">
                        <input type="checkbox" id="unlurkTimer" ${timerEnabled ? 'checked' : ''}>
                        Enable unlurk timer (resets lurk timer when user returns from lurking)
                    </label>
                </div>
                <p class="help">When enabled, the bot will reset the user's lurk timer instead of removing them from lurk tracking when they use !unlurk. This allows continuous lurk time tracking.</p>
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
    const saveButton = document.querySelector('.modal-card-foot .is-success');
    const originalText = saveButton.textContent;
    saveButton.textContent = 'Saving...';
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
                        showNotification('Command options saved successfully!', 'is-success');
                    } else {
                        showNotification('Error saving options: ' + response.message, 'is-danger');
                    }
                } catch (e) {
                    showNotification('Error parsing response', 'is-danger');
                }
            } else {
                showNotification('Error saving command options', 'is-danger');
            }
        }
    };
    xhr.send('action=save_command_options&command_name=' + encodeURIComponent(commandName) + '&options=' + encodeURIComponent(JSON.stringify(options)));
}

function showNotification(message, type) {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification ${type} is-light`;
    notification.style.position = 'fixed';
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.zIndex = '9999';
    notification.style.maxWidth = '400px';
    notification.innerHTML = `
        <button class="delete" onclick="this.parentElement.remove()"></button>
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
