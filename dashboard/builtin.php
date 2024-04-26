<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

// check if user is logged in
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
$webhookPort = $user['webhook_port'];
$websocketPort = $user['websocket_port'];
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
    // Handle query execution error
    die("Error executing query: " . $conn->error);
}

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Merge status information from $builtinCommands array
        foreach ($builtinCommands as $builtinCommand) {
            if ($builtinCommand['command'] === $row['command_name']) {
                $row['status'] = $builtinCommand['status'];
                break;
            }
        }
        // Append the merged row to $commands array
        $commands[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <!-- Headder -->
    <?php include('header.php'); ?>
    <!-- /Headder -->
  </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="row column">
  <br>
  <h1><?php echo "$greeting, $twitchDisplayName <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
  <div class="row">
    <div class="columns">
      <h4>Bot Commands</h4>
      <p style='color: red;'>Soon you'll be able to enable and disable the built in commands. If there's a command you don't use you can disable it from working.</p>
        <input type="text" id="searchInput" onkeyup="searchFunction()" placeholder="Search for commands...">
        <table class="bot-table" id="commandsTable">
          <thead>
            <tr>
              <th>Command</th>
              <th>Functionality</th>
              <th>Example Response</th>
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
                <td><?php echo htmlspecialchars($command['response']); ?></td>
                <td><?php echo htmlspecialchars($command['level']); ?></td>
                <td><?php echo isset($command['status']) ? htmlspecialchars($command['status']) : 'Enabled'; ?></td>
                <td></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
    </div>
  </div>
<br><br><br>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
<script src="/js/search.js"></script>
</body>
</html>