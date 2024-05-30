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
        
        // Insert new command into MySQL database
        try {
            $stmt = $db->prepare("INSERT INTO custom_commands (command, response, status) VALUES (?, ?, 'Enabled')");
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
<h1 class="title is-4"><?php echo "$greeting, $twitchDisplayName <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
<br>
<div class="columns">
    <div class="column">
        <form method="post" action="">
            <div class="field">
                <label class="label" for="command">Command:</label>
                <div class="control">
                    <input class="input" type="text" name="command" id="command" required>
                </div>
            </div>
            <div class="field">
                <label class="label" for="response">Response:</label>
                <div class="control">
                    <input class="input" type="text" name="response" id="response" required>
                </div>
            </div>
            <div class="control">
                <button class="button is-primary" type="submit">Add Command</button>
            </div>
        </form>
        <br>
        <?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
            <?php if (isset($_POST['command']) && isset($_POST['response'])): ?>
                <p class="has-text-success">Command "<?php echo $_POST['command']; ?>" has been successfully added to the database.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<div class="columns">
    <div class="column">
        <p class='has-text-info'>
        When adding commands via this site, please note the following:<br>
        <strong>1. Avoid using the exclamation mark (!) in your command.</strong> This will be automatically added.<br>
        <strong>2. Alternatively, you or your moderators can add commands during a stream using the command !addcommand.</strong> 
        <br>Example: !addcommand mycommand This is my command
    </p>
    </div>
    <div class="column">
        <p class='has-text-info'>
        Custom Variables to use while adding commands:<br>
        <strong>(count)</strong>: Using this option allows you to count how many times that command has been used and output that count in the command.<br>
        <strong>(customapi.URL)</strong>: Using this option allows you to get JSON API responses in chat. e.g. (customapi.https://api.botofthespecter.com/joke.php?api=APIKEY)<br>
        <strong>(daysuntil.DATE)</strong>: Using this option allows you to calculate the difference between two dates. e.g. (daysuntil.2024-12-25)<br>
        <strong>(user)</strong>: Using this option allows you to tag a user in any spot of the command. When triggering the command, you have to tag the user, e.g. !mycommand @BotOfTheSpecter<br>
        <strong>(command.COMMAND)</strong>: Using this option allows you to call other custom commands from one command, e.g. <strong>!raidtools (command.raid1) (command.raid2) (command.raid3)</strong>.
    </p>
    </div>
</div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>