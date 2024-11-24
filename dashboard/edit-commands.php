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

// Include all the information
require_once "db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'sqlite.php';
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);
$status = "";

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
    <br>
    <h4 class="title is-4">Edit Custom Commands</h4>
    <div class="columns is-desktop is-multiline box-container">
        <div class="column is-4 bot-box" id="stable-bot-status" style="position: relative;">
            <?php if (!empty($commands)): ?>
                <h4 class="subtitle is-4">Select the command you want to edit</h4>
                <form method="post" action="">
                    <div class="field">
                        <label for="command_to_edit">Command to Edit:</label>
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
                        <label for="command_response">Response:</label>
                        <div class="control">
                            <input class="input" type="text" name="command_response" id="command_response" value="" required>
                        </div>
                    </div>
                    <div class="control"><button type="submit" class="button is-primary">Update Command</button></div>
                </form>
            <?php else: ?>
                <h4 class="subtitle is-4">No commands available to edit.</h4>
            <?php endif; ?>
            <?php echo $status; ?>
        </div>
    </div>
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