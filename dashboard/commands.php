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
$pageTitle = t('custom_commands_page_title');

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

// Handle POST requests for status toggle and remove
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $dataUpdated = false;
    if (isset($_POST['command']) && isset($_POST['status'])) {
        $dbcommand = $_POST['command'];
        $status = $_POST['status'];
        $updateQuery = $db->prepare("UPDATE custom_commands SET status = ? WHERE command = ?");
        if (!$updateQuery) { error_log("MySQL prepare failed: " . $db->error); }
        $updateQuery->bind_param('ss', $status, $dbcommand);
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
                    'new_status' => $status
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

// Start output buffering for layout template
ob_start();
?>
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
                                        <td class="has-text-centered" style="vertical-align: middle;"><?php echo t('builtin_commands_permission_everyone'); ?></td>
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
        // Call searchFunction on page load to filter if there's a saved term
        searchFunction();
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
</script>
<?php
$scripts = ob_get_clean();
include 'layout.php';
?>