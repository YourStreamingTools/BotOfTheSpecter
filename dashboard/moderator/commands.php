<?php
// Initialize the session
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: ../login.php');
    exit();
}

// Page Title
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

// Fetch all custom commands
$commands = [];
$commandsStmt = $db->prepare("SELECT * FROM custom_commands");
$commandsStmt->execute();
$result = $commandsStmt->get_result();
while ($row = $result->fetch_assoc()) {
    $commands[] = $row;
}
$commandsStmt->close();

// Check if the update request is sent via POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['command']) && isset($_POST['status'])) {
        $dbcommand = $_POST['command'];
        $status = $_POST['status'];
        $updateQuery = $db->prepare("UPDATE custom_commands SET status = :status WHERE command = :command");
        $updateQuery->bindParam(':status', $status);
        $updateQuery->bindParam(':command', $dbcommand);
        $updateQuery->execute();
    }
    if (isset($_POST['remove_command'])) {
        $commandToRemove = $_POST['remove_command'];
        $deleteStmt = $db->prepare("DELETE FROM custom_commands WHERE command = ?");
        $deleteStmt->bindParam(1, $commandToRemove, PDO::PARAM_STR);
        try {
            $deleteStmt->execute();
            $status = "Command removed successfully";
            header("Refresh: 1; url={$_SERVER['PHP_SELF']}");
            exit();
        } catch (PDOException $e) {
            $status = "Error removing command: " . $e->getMessage();
        }
    }
}
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
                                        </td>
                                        <td class="has-text-centered is-narrow" style="vertical-align: middle;">
                                            <label class="checkbox" style="cursor:pointer;">
                                                <input type="checkbox" class="toggle-checkbox" <?php echo $command['status'] == 'Enabled' ? 'checked' : ''; ?> onchange="toggleStatus('<?php echo $command['command']; ?>', this.checked, this)" style="display:none;">
                                                <span class="icon is-medium" onclick="this.previousElementSibling.click();">
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
    var icon = elem.parentElement.querySelector('i');
    icon.className = "fa-solid fa-spinner fa-spin";
    var status = isChecked ? 'Enabled' : 'Disabled';
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function() {
        if (xhr.readyState === XMLHttpRequest.DONE) {
            location.reload();
        }
    };
    xhr.send("command=" + encodeURIComponent(command) + "&status=" + status);
}

// Simple search function for filtering table rows
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
include 'mod_layout.php';
exit;
?>