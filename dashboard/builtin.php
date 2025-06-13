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
    "Mods" => "mod",
    "VIPs" => "vip",
    "All Subscribers" => "all-subs",
    "Tier 1 Subscriber" => "t1-sub",
    "Tier 2 Subscriber" => "t2-sub",
    "Tier 3 Subscriber" => "t3-sub"
];

// Load command descriptions from JSON file
$jsonText = file_get_contents(__DIR__ . '../../api/builtin_commands.json');
$cmdDescriptions = json_decode($jsonText, true)['commands'];

// Update command status or permission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Process permission update
    if (isset($_POST['command_name']) && isset($_POST['usage_level'])) {
        $command_name = $_POST['command_name'];
        $usage_level = $_POST['usage_level'];
        $dbPermission = $permissionsMap[$usage_level];
        $updateQuery = $db->prepare("UPDATE builtin_commands SET permission = ? WHERE command = ?");
        $updateQuery->bind_param('ss', $dbPermission, $command_name);
        $updateQuery->execute();
        $updateQuery->close();
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
                        <th class="has-text-centered has-text-middle"><?php echo t('builtin_commands_table_command'); ?></th>
                        <th class="has-text-centered has-text-middle"><?php echo t('builtin_commands_table_description'); ?></th>
                        <th class="has-text-centered"><?php echo t('builtin_commands_table_usage_level'); ?></th>
                        <th class="has-text-centered is-narrow has-text-middle"><?php echo t('builtin_commands_table_status'); ?></th>
                        <th class="has-text-centered is-narrow has-text-middle"><?php echo t('builtin_commands_table_action'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($builtinCommands)): ?>
                        <tr>
                            <td colspan="5" class="has-text-centered has-text-danger"><?php echo t('builtin_commands_no_commands'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($builtinCommands as $command): 
                            $cmdKey = $command['command'];
                            $desc = isset($cmdDescriptions[$cmdKey]) ? $cmdDescriptions[$cmdKey] : t('builtin_commands_no_description');
                        ?>
                        <tr class="commandRow" data-status="<?php echo htmlspecialchars($command['status']); ?>">
                            <td class="has-text-centered has-text-weight-semibold has-text-info" style="vertical-align: middle;">
                                !<?php echo htmlspecialchars($command['command']); ?>
                            </td>
                            <td style="vertical-align: middle;"><?php echo htmlspecialchars($desc); ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="command_name" value="<?php echo htmlspecialchars($command['command']); ?>">
                                    <div class="select is-fullwidth">
                                        <select name="usage_level" onchange="this.form.submit()">
                                            <?php $currentPermission = htmlspecialchars($command['permission']); foreach ($permissionsMap as $displayValue => $dbValue): ?>
                                                <option value="<?php echo $displayValue; ?>" <?php echo ($currentPermission == $dbValue) ? 'selected' : ''; ?>>
                                                    <?php echo t('builtin_commands_permission_' . $dbValue); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </form>
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
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
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
const cookieConsent = <?php echo $cookieConsent ? 'true' : 'false'; ?>;
// Remember search query using localStorage and attach filter listeners after DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        var query = localStorage.getItem('searchQuery') || '';
        searchInput.value = query;
        if (typeof searchFunction === "function") {
            searchFunction();
        }
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
});

function setCookie(name, value, days) {
    if (!cookieConsent) return;
    const d = new Date();
    d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
    const expires = "expires=" + d.toUTCString();
    document.cookie = name + "=" + value + ";" + expires + ";path=/";
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
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>
