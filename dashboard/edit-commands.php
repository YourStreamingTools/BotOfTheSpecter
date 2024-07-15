<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Edit Custom Commands";

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
$status = "";
include 'bot_control.php';
include 'sqlite.php';

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
            $status = "<p class='has-text-success'>Command updated successfully!</p>";
        } else {
            // No rows updated, which means the command was not found
            $status = "<p class='has-text-danger'>Command not found or no changes made.</p>";
        }
    } catch (Exception $e) {
        // Catch any exceptions and display an error message
        $status = "<p class='has-text-danger'>Error updating command: " . $e->getMessage() . "</p>";
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
  <h1 class="title"><?php echo "$greeting, $twitchDisplayName <img id='profile-image' class='round-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
  <br>
  <?php if (!empty($commands)): ?>
    <p>Select the command you want to edit:</p>
    <form method="post" action="">
        <div class="field">
            <label class="label" for="command_to_edit">Command to Edit:</label>
            <div class="control">
                <div class="select">
                    <select name="command_to_edit" id="command_to_edit" onchange="showResponse()" required>
                        <option value="">Select a Command...</option>
                        <?php foreach ($commands as $command): ?>
                            <option value="<?php echo $command['command']; ?>">!<?php echo $command['command']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="field">
            <label class="label" for="command_response">Response:</label>
            <div class="control">
                <input class="input" type="text" name="command_response" id="command_response" value="" required>
            </div>
        </div>
        <div class="control"><button type="submit" class="button is-primary">Update Command</button></div>
    </form>
  <?php else: ?>
    <p>No commands available to edit.</p>
  <?php endif; ?>
  <?php echo $status; ?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
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