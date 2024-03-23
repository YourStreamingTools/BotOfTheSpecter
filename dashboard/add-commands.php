<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL); ?>
<?php
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

$db = new PDO("sqlite:/var/www/bot/commands/{$username}.db");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BotOfTheSpecter - Add Bot Commands</title>
    <link rel="stylesheet" href="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.min.css">
    <link rel="stylesheet" href="https://cdn.yourstreaming.tools/css/custom.css">
    <link rel="stylesheet" href="pagination.css">
    <script src="about.js"></script>
  	<link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
  	<link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <!-- <?php echo "User: $username | $twitchUserId | $authToken"; ?> -->
  </head>
<body>
<!-- Navigation -->
<?php include('header.php'); ?>
<!-- /Navigation -->

<div class="row column">
    <br>
    <h1><?php echo "$greeting, $twitchDisplayName <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
    <br>
    <p style='color: red;'>When adding the commands into this site, please don't put the "exclamation mark" for your command, this does it automatically.</p>
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