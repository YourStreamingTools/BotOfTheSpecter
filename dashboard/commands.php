<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL); ?>
<?php
// Initialize the session
session_start();

// check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Custom Commands";

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
$webhookPort = $user['webhook_port'];
$websocketPort = $user['websocket_port'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';

// Check if the update request is sent via POST
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['command']) && isset($_POST['status'])) {
  // Process the update here
  $dbcommand = $_POST['command'];
  $status = $_POST['status'];

  // Update the status in the database
  $updateQuery = $db->prepare("UPDATE custom_commands SET status = :status WHERE command = :command");
  $updateQuery->bindParam(':status', $status);
  $updateQuery->bindParam(':command', $dbcommand);
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
  <h1><?php echo "$greeting, $twitchDisplayName <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
  <div class="columns">
    <div class="column">
      <h4 class="title is-4">Bot Commands</h4>
      <?php if (empty($commands)): ?>
        <p>No commands found.</p>
      <?php else: ?>
        <div class="field">
          <div class="control">
            <input class="input" type="text" id="searchInput" onkeyup="searchFunction()" placeholder="Search for commands...">
          </div>
        </div>
        <table class="table is-striped is-fullwidth" id="commandsTable">
          <thead>
            <tr>
              <th>Command</th>
              <th>Response</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($commands as $command): ?>
              <tr>
                <td>!<?php echo $command['command']; ?></td>
                <td><?php echo $command['response']; ?></td>
                <td><?php echo $command['status']; ?></td>
                <td>
                  <label class="checkbox">
                    <input type="checkbox" class="toggle-checkbox" <?php echo $command['status'] == 'Enabled' ? 'checked' : ''; ?> onchange="toggleStatus('<?php echo $command['command']; ?>', this.checked)">
                    <span class="icon is-small">
                      <i class="fa-solid <?php echo $command['status'] == 'Enabled' ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
                    </span>
                  </label>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
  <br><br><br>
</div>

<script>
function toggleStatus(command, isChecked) {
    var status = isChecked ? 'Enabled' : 'Disabled';
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function() {
        if (xhr.readyState === XMLHttpRequest.DONE) {
            console.log(xhr.responseText);
            // Reload the page after the AJAX request is completed
            location.reload();
        }
    };
    xhr.send("command=" + encodeURIComponent(command) + "&status=" + status);
}
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="/js/search.js"></script>
</body>
</html>