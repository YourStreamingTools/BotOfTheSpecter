<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Remove Custom Commands";

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
$status = "";
include 'bot_control.php';
include 'sqlite.php';

// Connect to the SQLite database
$db = new PDO("sqlite:/var/www/bot/commands/{$username}.db");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Fetch list of commands from the database
$commandQuery = $db->query("SELECT command FROM custom_commands");
$commands = $commandQuery->fetchAll(PDO::FETCH_COLUMN);

// Check if the form was submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['remove_command'])) {
    $commandToRemove = $_POST['remove_command'];

    // Prepare a delete statement
    $deleteStmt = $db->prepare("DELETE FROM custom_commands WHERE command = ?");
    $deleteStmt->bindParam(1, $commandToRemove, PDO::PARAM_STR);

    // Execute the delete statement
    try {
        $deleteStmt->execute();
        // Success message 
        $status = "Command removed successfully";
    } catch (PDOException $e) {
        // Handle potential errors here
        $status = "Error removing command: " . $e->getMessage();
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
<?php if (!empty($commands)): ?>
    <p style="color: white;">Select the command you want to remove:</p>
    <form method="post" action="">
        <div class="row small-3 columns">
            <label for="remove_command">Command to Remove:</label>
            <select name="remove_command" id="remove_command" required>
                <?php foreach ($commands as $command): ?>
                    <option value="<?php echo $command; ?>">!<?php echo $command; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="submit" class="defult-button" value="Remove Command">
    </form>
<?php else: ?>
    <p>No commands to remove.</p>
<?php endif; ?>
<?php echo $status; ?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
</body>
</html>