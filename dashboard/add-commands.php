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
$title = "Add Bot Commands";

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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  if (isset($_POST['command']) && isset($_POST['response'])) {
      $newCommand = $_POST['command'];
      $newResponse = $_POST['response'];
      
      // Insert new command into SQLite database
      try {
          $stmt = $db->prepare("INSERT INTO custom_commands (command, response) VALUES (?, ?)");
          $stmt->execute([$newCommand, $newResponse]);
      } catch (PDOException $e) {
          echo 'Error adding command: ' . $e->getMessage();
      }
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
    <br>
    <div class="row">
    <div class="small-12 medium-6 column">
        <p style='color: red;'>
        When adding commands via this site, please note the following:<br>
        <strong>1. Avoid using the exclamation mark (!) in your command.</strong> This will be automatically added.<br>
        <strong>2. Alternatively, you or your moderators can add commands during a stream using the command !addcommand.</strong> 
        <br>Example: !addcommand mycommand This is my command
    </p>
    </div>
    <div class="small-12 medium-6 column">
        <p style='color: red;'>
        Custom Variables to use while adding commands:<br>
        <strong>(count)</strong>: Using this option allows you to count how many times that command has been used and output that count in the command.<br>
        <strong>(customapi.URL)</strong>: Using this option allows you to get JSON API responses in chat. e.g. (customapi.https://api.botofthespecter.com/joke.php?api=APIKEY)<br>
        <strong>(daysuntil.DATE)</strong>: Using this option allows you to calulate the difference between two dates. e.g. (daysuntil.2024-12-25)
        </p>
    </div>
    <form method="post" action="">
        <div class="row">
            <div class="small-12 medium-6 column">
                <label for="command">Command:</label>
                <input type="text" name="command" id="command" required>
            </div>
            <div class="small-12 medium-6 column">
                <label for="response">Response:</label>
                <input type="text" name="response" id="response" required>
            </div>
        </div>
        <input type="submit" class="defult-button" value="Add Command">
    </form>
    <br>
    <?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
        <?php if (isset($_POST['command']) && isset($_POST['response'])): ?>
            <p style="color: green;">Command "<?php echo $_POST['command']; ?>" has been successfully added to the database.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
</body>
</html>