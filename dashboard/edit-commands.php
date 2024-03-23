<?php 
// Initialize the session
session_start();

// Check if the user is logged in
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
$status = "";

// Connect to the SQLite database
$db = new PDO("sqlite:/var/www/bot/commands/{$username}.db");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Fetch list of commands from the database
$getCommands = $db->query("SELECT * FROM custom_commands");
$commands = $getCommands->fetchAll(PDO::FETCH_ASSOC);

// Check if form data has been submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['command_to_edit'], $_POST['command_response'])) {
    // Update the response for the selected command
    $command_to_edit = $_POST['command_to_edit'];
    $command_response = $_POST['command_response'];

    try {
        $updateSTMT = $db->prepare("UPDATE custom_commands SET response = ? WHERE command = ?");
        $updateSTMT->bindParam(1, $command_response);
        $updateSTMT->bindParam(2, $command_to_edit);
        $updateSTMT->execute();

        if ($updateSTMT->rowCount() > 0) {
            $status = "<p style='color: green;'>Command updated successfully!</p>";
        } else {
            // No rows updated, which means the command was not found
            $status = "<p style='color: red;'>Command not found or no changes made.</p>";
        }
    } catch (Exception $e) {
        // Catch any exceptions and display an error message
        $status = "<p style='color: red;'>Error updating command: " . $e->getMessage() . "</p>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BotOfTheSpecter - Edit Bot Commands</title>
    <link rel="stylesheet" href="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.min.css">
    <link rel="stylesheet" href="https://cdn.yourstreaming.tools/css/custom.css">
    <link rel="stylesheet" href="pagination.css">
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
<br>
<?php if (!empty($commands)): ?>
    <p style="color: white;">Select the command you want to edit:</p>
    <form method="post" action="">
        <div class="row small-3 columns">
            <label for="command_to_edit">Command to Edit:</label>
            <select name="command_to_edit" id="command_to_edit" onchange="showResponse()" required>
                <option value="">Select a Command...</option>
                <?php foreach ($commands as $command): ?>
                    <option value="<?php echo $command['command']; ?>">!<?php echo $command['command']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="row small-12 columns">
            <label for="command_response">Response:</label>
            <input type="text" name="command_response" id="command_response" value="" required>
        </div>
        <input type="submit" class="button" value="Update Command">
    </form>
<?php else: ?>
    <p>No commands available to edit.</p>
<?php endif; ?>
<?php echo $status; ?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
<script>
function showResponse() {
    var command = document.getElementById('command_to_edit').value;
    var commands = <?php echo json_encode($commands); ?>;
    var responseInput = document.getElementById('command_response');
    
    // Find the response for the selected command and display it in the text box
    var commandData = commands.find(c => c.command === command);
    responseInput.value = commandData ? commandData.response : '';
}
</script>
</body>
</html>