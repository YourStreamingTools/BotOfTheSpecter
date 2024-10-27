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

// Connect to database
require_once "db_connect.php";

// Fetch the user's data from the database based on the access_token
$access_token = $_SESSION['access_token'];
$userSTMT = $conn->prepare("SELECT * FROM users WHERE access_token = ?");
$userSTMT->bind_param("s", $access_token);
$userSTMT->execute();
$userResult = $userSTMT->get_result();
$user = $userResult->fetch_assoc();
$user_id = $user['id'];
$username = $user['username'];
$twitchDisplayName = $user['twitch_display_name'];
$twitch_profile_image_url = $user['profile_image'];
$is_admin = ($user['is_admin'] == 1);
$twitchUserId = $user['twitch_user_id'];
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';

// Query to fetch commands from the database
$fetchCommandsSql = "SELECT * FROM commands";
$result = $conn->query($fetchCommandsSql);
$commands = array();

if ($result === false) {
    die("Error executing query: " . $conn->error);
}

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $commands[] = $row;
    }
}

// Update command status or permission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Process permission update
    if (isset($_POST['command_name']) && isset($_POST['usage_level'])) {
        $command_name = $_POST['command_name'];
        $usage_level = $_POST['usage_level'];
        // Map display values to database values
        $permissionsMap = [
            "Everyone" => "everyone",
            "Mods" => "mod",
            "VIPs" => "vip",
            "Tier 1 Subscriber" => "t1-sub",
            "Tier 2 Subscriber" => "t2-sub",
            "Tier 3 Subscriber" => "t3-sub",
        ];
        $dbPermission = $permissionsMap[$usage_level];
        // Update permission in the database
        $updateQuery = $conn->prepare("UPDATE builtin_commands SET permission = ? WHERE command = ?");
        $updateQuery->bind_param("ss", $dbPermission, $command_name);
        $updateQuery->execute();
        header("Location: beta_builtin.php");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('header.php'); ?>
</head>
<body>
<?php include('navigation.php'); ?>

<div class="container">
<h1 class="title"><?php echo "$greeting, $twitchDisplayName <img id='profile-image' class='round-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
<br>
<h4 class="title is-4">Bot Commands</h4>
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
            <td>
                <form method="post">
                    <input type="hidden" name="command_name" value="<?php echo htmlspecialchars($command['command_name']); ?>">
                    <select name="usage_level" onchange="this.form.submit()">
                        <?php 
                            // Map database values to display values for selection
                            $currentPermission = htmlspecialchars($command['level']);
                            foreach ($permissionsMap as $displayValue => $dbValue): 
                        ?>
                            <option value="<?php echo $displayValue; ?>" <?php echo ($currentPermission == $dbValue) ? 'selected' : ''; ?>>
                                <?php echo $displayValue; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </td>
            <td>
                <?php 
                    // Fetch status and display appropriately
                    $statusQuery = $conn->prepare("SELECT status FROM builtin_commands WHERE command = ?");
                    $statusQuery->bind_param("s", $command['command_name']);
                    $statusQuery->execute();
                    $statusResult = $statusQuery->get_result()->fetch_assoc();
                    echo isset($statusResult['status']) ? htmlspecialchars($statusResult['status']) : 'Unknown'; 
                ?>
            </td>
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