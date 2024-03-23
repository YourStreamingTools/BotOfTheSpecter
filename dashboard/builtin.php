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
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';

// Query to fetch commands from the database
$fetchCommandsSql = "SELECT command_name, usage_text FROM commands";
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
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BotOfTheSpecter - Built-in Bot Commands</title>
    <link rel="stylesheet" href="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.min.css">
    <link rel="stylesheet" href="https://cdn.yourstreaming.tools/css/custom.css">
    <link rel="stylesheet" href="pagination.css">
    <script src="about.js"></script>
  	<link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
  	<link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
  </head>
<body>
<!-- Navigation -->
<?php include('header.php'); ?>
<!-- /Navigation -->

<div class="row column">
  <br>
  <h1><?php echo "$greeting, $twitchDisplayName <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
  <div class="row">
    <div class="columns">
      <h4>Bot Commands</h4>
        <input type="text" id="searchInput" onkeyup="searchFunction()" placeholder="Search for commands...">
        <table class="bot-table" id="commandsTable">
          <thead>
            <tr>
              <th>Command</th>
              <th>Response</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($commands as $command): ?>
            <tr>
              <td>!<?php echo htmlspecialchars($command['command_name']); ?></td>
              <td><?php echo htmlspecialchars($command['usage_text']); ?></td>
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
<script src="search.js"></script>
</body>
</html>