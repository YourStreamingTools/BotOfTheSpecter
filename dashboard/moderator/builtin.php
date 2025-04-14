<?php
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Built-in Bot Commands";

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'modding_access.php';
include 'user_db.php';
$getProfile = $db->query("SELECT timezone FROM profile");
$profile = $getProfile->fetchAll(PDO::FETCH_ASSOC);
$timezone = $profile['timezone'];
date_default_timezone_set($timezone);

$permissionsMap = [
    "Everyone" => "everyone",
    "Mods" => "mod",
    "VIPs" => "vip",
    "All Subscribers" => "all-subs",
    "Tier 1 Subscriber" => "t1-sub",
    "Tier 2 Subscriber" => "t2-sub",
    "Tier 3 Subscriber" => "t3-sub"
];

// Update command status or permission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Process permission update
    if (isset($_POST['command_name']) && isset($_POST['usage_level'])) {
        $command_name = $_POST['command_name'];
        $usage_level = $_POST['usage_level'];
        $dbPermission = $permissionsMap[$usage_level];
        // Update permission in the database
        $updateQuery = $db->prepare("UPDATE builtin_commands SET permission = ? WHERE command = ?");
        $updateQuery->bind_param("ss", $dbPermission, $command_name);
        $updateQuery->execute();
        header("Location: builtin.php");
    }
    // Process status update
    if (isset($_POST['command_name']) && isset($_POST['status'])) {
        $dbcommand = $_POST['command_name'];
        $dbstatus = $_POST['status'];
        // Update the status in the database
        $updateQuery = $db->prepare("UPDATE builtin_commands SET status = :status WHERE command = :command_name");
        $updateQuery->bindParam(':status', $dbstatus);
        $updateQuery->bindParam(':command_name', $dbcommand);
        $updateQuery->execute();
        header("Location: builtin.php");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Header -->
    <?php include('header.php'); ?>
    <!-- /Header -->
</head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
    <br>
    <h4 class="title is-4">Bot Commands</h4>
    <!-- Toggle Filters -->
    <div class="field">
        <div class="control">
            <label class="checkbox">
                <input type="checkbox" id="showEnabled" checked> Show Enabled Commands
            </label>
            <label class="checkbox">
                <input type="checkbox" id="showDisabled" checked> Show Disabled Commands
            </label>
        </div>
    </div>
    <div class="field">
        <div class="control">
            <input class="input" type="text" id="searchInput" onkeyup="searchFunction()" placeholder="Search for commands...">
        </div>
    </div>
    <table class="table is-fullwidth" id="commandsTable">
        <thead>
            <tr>
                <th style="width: 200px;">Command</th>
                <th>Usage Level</th>
                <th style="width: 100px;">Status</th>
                <th style="width: 100px;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($builtinCommands)): ?>
                <tr>
                    <td colspan="4" style="text-align: center; color: red;">No commands found. Please run the Twitch Chat Bot to sync the commands.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($builtinCommands as $command): ?>
                <tr class="commandRow" data-status="<?php echo htmlspecialchars($command['status']); ?>">
                    <td>!<?php echo htmlspecialchars($command['command']); ?></td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="command_name" value="<?php echo htmlspecialchars($command['command']); ?>">
                            <div class="select is-fullwidth">
                                <select name="usage_level" onchange="this.form.submit()">
                                    <?php $currentPermission = htmlspecialchars($command['permission']); foreach ($permissionsMap as $displayValue => $dbValue): ?>
                                        <option value="<?php echo $displayValue; ?>" <?php echo ($currentPermission == $dbValue) ? 'selected' : ''; ?>>
                                            <?php echo $displayValue; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                    </td>
                    <td style="color: <?php echo ($command['status'] == 'Enabled') ? 'green' : 'red'; ?>;">
                        <?php echo htmlspecialchars($command['status']); ?>
                    </td>
                    <td>
                        <label class="switch">
                            <input type="checkbox" class="toggle-checkbox" <?php echo ($command['status'] == 'Enabled') ? 'checked' : ''; ?> onchange="toggleStatus('<?php echo htmlspecialchars($command['command']); ?>', this.checked)">
                            <i class="fa-solid <?php echo $command['status'] == 'Enabled' ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
                        </label>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
    // Toggle visibility of commands based on status
    document.getElementById('showEnabled').addEventListener('change', toggleFilter);
    document.getElementById('showDisabled').addEventListener('change', toggleFilter);
    function toggleFilter() {
        const showEnabled = document.getElementById('showEnabled').checked;
        const showDisabled = document.getElementById('showDisabled').checked;
        const rows = document.querySelectorAll('.commandRow');
        rows.forEach(row => {
            const status = row.getAttribute('data-status');
            if ((showEnabled && status === 'Enabled') || (showDisabled && status === 'Disabled')) {
                row.style.display = '';  // Show the row
            } else {
                row.style.display = 'none';  // Hide the row
            }
        });
    }
    // Initial call to set the correct visibility
    toggleFilter();
    function toggleStatus(commandName, isChecked) {
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
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="/js/search.js"></script>
</body>
</html>