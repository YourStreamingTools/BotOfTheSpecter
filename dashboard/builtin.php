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

// Include all the information
require_once "db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'sqlite.php';
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);

// Query to fetch commands from the database
$fetchCommandsSql = "SELECT * FROM commands";
$result = $conn->query($fetchCommandsSql);
$commands = array();

if ($result === false) {
    // Handle query execution error
    die("Error executing query: " . $conn->error);
}

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $commands[] = $row;
    }
} else {
    // Handle no results found
    echo "No commands found in the database.";
}

// Check if the update request is sent via POST
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['command_name']) && isset($_POST['status'])) {
    // Process the update here
    $dbcommand = $_POST['command_name'];
    $dbstatus = $_POST['status'];

    // Update the status in the database
    $updateQuery = $db->prepare("UPDATE builtin_commands SET status = :status WHERE command = :command_name");
    $updateQuery->bindParam(':status', $dbstatus);
    $updateQuery->bindParam(':command_name', $dbcommand);
    $updateQuery->execute();
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
    <div class="notification is-info">Beta Users can change the permission level for the command, making commands any usage level.<br><a href="beta_builtin.php" class="button is-primary mt-4">Go to Beta Built-in Commands</a></div>
    <div class="field">
        <div class="control">
            <input class="input" type="text" id="searchInput" onkeyup="searchFunction()" placeholder="Search for commands...">
        </div>
    </div>
    <table class="table is-fullwidth" id="commandsTable">
        <thead>
            <tr>
                <th>Command</th>
                <th>Functionality</th>
                <th>Usage Level</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($commands as $command): ?>
            <tr>
                <td>!<?php echo htmlspecialchars($command['command_name']); ?></td>
                <td><?php echo htmlspecialchars($command['usage_text']); ?></td>
                <td><?php echo htmlspecialchars($command['level']); ?></td>
                <td><?php $statusQuery = $db->prepare("SELECT status FROM builtin_commands WHERE command = ?"); $statusQuery->execute([$command['command_name']]); $statusResult = $statusQuery->fetch(PDO::FETCH_ASSOC);if ($statusResult && isset($statusResult['status'])) { echo htmlspecialchars($statusResult['status']); } else { echo 'Unknown'; } ?></td>
                <td>
                <label class="switch">
                    <input type="checkbox" class="toggle-checkbox" <?php echo ($statusResult['status'] == 'Enabled') ? 'checked' : ''; ?> onchange="toggleStatus('<?php echo htmlspecialchars($command['command_name']); ?>', this.checked)">
                    <i class="fa-solid <?php echo $statusResult['status'] == 'Enabled' ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
                </label>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
function toggleStatus(commandName, isChecked) {
    var status = isChecked ? 'Enabled' : 'Disabled';
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === XMLHttpRequest.DONE) {
            if (xhr.status === 200) {
                // Reload the page after the AJAX request is completed
                location.reload();
            } else {
                // Error handling
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