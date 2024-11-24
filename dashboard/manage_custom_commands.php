<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Manage Custom Commands";

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
$notification_status = "";

// Check if form data has been submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Editing a Custom Command
    if ((isset($_POST['command_to_edit'])) && ($_POST['command_response']) && (isset($_POST['cooldown']))) {
        // Update the response for the selected command
        $command_to_edit = $_POST['command_to_edit'];
        $command_response = $_POST['command_response'];
        $cooldown = $_POST['cooldown'];
        try {
            $updateSTMT = $db->prepare("UPDATE custom_commands SET response = ?, cooldown = ? WHERE command = ?");
            $updateSTMT->bindParam(1, $command_response);
            $updateSTMT->bindParam(2, $cooldown);
            $updateSTMT->bindParam(3, $command_to_edit);
            $updateSTMT->execute();
            if ($updateSTMT->rowCount() > 0) {
                $status = "Command ". $command_to_edit . " updated successfully!";
                $notification_status = "is-success";
            } else {
                // No rows updated, which means the command was not found
                $status = $command_to_edit . " not found or no changes made.";
                $notification_status = "is-danger";
            }
        } catch (Exception $e) {
            // Catch any exceptions and display an error message
            $status = "Error updating " .$command_to_edit . ": " . $e->getMessage();
            $notification_status = "is-danger";
        }
    }
    // Adding a new custom command
    if (isset($_POST['command']) && isset($_POST['response']) && isset($_POST['cooldown'])) {
        $newCommand = strtolower(str_replace(' ', '', $_POST['command']));
        $newResponse = $_POST['response'];
        $cooldown = $_POST['cooldown'];
        // Insert new command into MySQL database
        try {
            $insertSTMT = $db->prepare("INSERT INTO custom_commands (command, response, status, cooldown) VALUES (?, ?, 'Enabled', ?)");
            $insertSTMT->execute([$newCommand, $newResponse, $cooldown]);
        } catch (PDOException $e) {
            echo 'Error adding ' . $newCommand . ': ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <!-- Header -->
    <?php include('header.php'); ?>
    <style>
        .custom-width { width: 90vw; max-width: none; }
        .variable-item { margin-bottom: 1.5rem; }
        .variable-title { color: #ffdd57; }
    </style>
    <!-- /Header -->
  </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
    <br>
    <h4 class="title is-4">Manage Custom Commands</h4>
    <div class="notification is-info">
        <p>When adding commands via this page, please note the following:<br>
            <ol style="padding-left: 30px;">
                <li>Avoid using the exclamation mark (!) in your command name. The exclamation mark is automatically added to the beginning of your command.</li>
                <li>Alternatively, you or your moderators can add commands directly using the !addcommand command in your Twitch chat.<br>
                    Example: <code>!addcommand mycommand This is my custom command</code></li>
            </ol>
        </p>
        <p>If you want to add custom features or functionalities to your commands, check out the Custom Variables that can be used to make your command more dynamic. These variables allow you to personalize the behavior of your commands.</p>
        <p>Note: Custom Variables are only accepted in the response part of your command. Make sure to include them in the message that will be displayed to the user.</p>
        <button class="button is-primary" id="openModalButton">View Custom Variables</button>
    </div>
    <?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
        <?php if (isset($_POST['command']) && isset($_POST['response'])): ?>
            <div class="notification is-success">
                <p>Command "<?php $commandAdded = strtolower(str_replace(' ', '', $_POST['command'])); echo $commandAdded; ?>" has been successfully added to the database.</p>
            </div>
        <?php else: ?>
            <div class="notification <?php echo $notification_status; ?>"><?php echo $status; ?></div>
        <?php endif; ?>
    <?php endif; ?>
    <div class="columns is-desktop is-multiline box-container">
        <div class="column is-5 bot-box" style="position: relative;">
            <h4 class="subtitle is-4">Adding a custom command</h4>
            <form method="post" action="">
                <div class="field">
                    <label for="command">Command:</label>
                    <div class="control">
                        <input class="input" type="text" name="command" id="command" required>
                    </div>
                </div>
                <div class="field">
                    <label for="response">Response:</label>
                    <div class="control">
                        <input class="input" type="text" name="response" id="response" required>
                    </div>
                </div>
                <div class="field">
                    <label for="cooldown">Cooldown:</label>
                    <div class="control">
                        <input class="input" type="text" name="cooldown" id="cooldown" value="15" required>
                    </div>
                </div>
                <div class="control">
                    <button class="button is-primary" type="submit">Add Command</button>
                </div>
            </form>
        </div>
        <div class="column is-5 bot-box" style="position: relative;">
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
                    <div class="field">
                        <label for="cooldown">Cooldown:</label>
                        <div class="control">
                            <input class="input" type="text" name="cooldown" id="cooldown" value="" required>
                        </div>
                    </div>
                    <div class="control"><button type="submit" class="button is-primary">Update Command</button></div>
                </form>
            <?php else: ?>
                <h4 class="subtitle is-4">No commands available to edit.</h4>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal" id="customVariablesModal">
    <div class="modal-background"></div>
    <div class="modal-card custom-width">
        <header class="modal-card-head has-background-dark">
            <p class="modal-card-title has-text-white">Custom Variables to use while adding commands</p>
            <button class="delete" aria-label="close" id="closeModalButton"></button>
        </header>
        <section class="modal-card-body has-background-dark has-text-white">
            <div class="columns is-desktop is-multiline">
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(count)</span><br>
                    This variable counts how many times the specific command has been used and shows that number. The count tracks how many times this command has been used, not others.<br>
                    <span class="has-text-weight-bold">Example:</span><br>
                    <code>This command has been used (count) times.</code><br>
                    <span class="has-text-weight-bold">In Twitch Chat:</span><br>
                    <code>"This command has been used 5 times."</code><br><br>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(usercount)</span><br>
                    This variable counts how many times a specific user has used the command. The count is tracked for each user individually, so each user will have their own usage count stored in the database.<br>
                    <span class="has-text-weight-bold">Example:</span><br>
                    <code>This user has used this command (usercount) times.</code><br>
                    <span class="has-text-weight-bold">In Twitch Chat:</span><br>
                    <code>"This user has used this command 3 times."</code><br><br>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(customapi.URL)</span><br>This gets information from a URL and posts it in chat.<br>You can use this to get jokes, weather, or any other data from a website.
                    <br><span class="has-text-weight-bold">Example:</span><br><code>(customapi.https://api.botofthespecter.com/joke?api_key=APIKEY)</code>
                    <br><span class="has-text-weight-bold">In Twitch Chat:</span><br><code>"Why don’t skeletons fight each other? They don’t have the guts."</code>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(daysuntil.DATE)</span><br>This shows how many days until a specific date, like a holiday or event.
                    <br><span class="has-text-weight-bold">Example:</span><br><code>There are (daysuntil.2024-12-25) days until Christmas.</code>
                    <br><span class="has-text-weight-bold">In Twitch Chat:</span><br><code>"There are 75 days until Christmas."</code>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(user)</span> | <span class="has-text-weight-bold variable-title">(author)</span><br>This lets you tag someone by name when they use the command.<br>If no one is tagged, it will tag the person who used the command.<br>To always tag the user who issued the command use (author).
                    <br><span class="has-text-weight-bold">Example:</span><br><code>(author) is saying that (user) is awesome!</code>
                    <br><span class="has-text-weight-bold">In Twitch Chat:</span><br><code>"BotOfTheSpecter is saying that John is awesome!"</code>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(random.pick.*)</span><br>This randomly picks an item from a list you provide. It could be used to pick random items, people, or anything else.
                    <br><span class="has-text-weight-bold">Example:</span><br><code>Your spirit animal is: (random.pick.cat.dog.eagle.tiger)</code>
                    <br><span class="has-text-weight-bold">In Twitch Chat:</span><br><code>"Your spirit animal is: tiger"</code>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(math.*)</span><br>This solves simple math problems.
                    <br><span class="has-text-weight-bold">Example:</span><br><code>2+2 = (math.2+2)</code>
                    <br><span class="has-text-weight-bold">In Twitch Chat:</span><br><code>"2+2 = 4"</code>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(command.COMMAND)</span><br>This allows you to trigger other commands inside of one command.<br>You can combine multiple commands to post different messages.
                    <br><span class="has-text-weight-bold">Example:</span><br><code>Use these raid calls: (command.raid1) (command.raid2) (command.raid3)</code>
                    <br><span class="has-text-weight-bold">In Twitch Chat:</span>
                    <br> "Use these raid calls:"
                    <br> "Raid 1 message."
                    <br> "Raid 2 message."
                    <br> "Raid 3 message."
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(random.number)</span><br>This picks a random number between two numbers you specify, or by default between 0 and 100.<br>You can specify a range by using a dot followed by the numbers, like (random.number.1-1000), which will give you a random number within that range.
                    <br><span class="has-text-weight-bold">Example:</span><br><code>You've broken (random.number.1-1000) hearts!</code>
                    <br><span class="has-text-weight-bold">In Twitch Chat:</span><br><code>You've broken 583 hearts!"</code>
                </div>
                <div class="column is-4">
                    <span class="has-text-weight-bold variable-title">(random.percent)</span><br>This generates a random percentage between 0% and 100%, or any custom range you define.<br>You can specify a range by using a dot followed by the numbers, like (random.percent.0-200), which will give you a random percentage between those two numbers.
                    <br><span class="has-text-weight-bold">Example:</span><br><code>You have a (random.percent.0-200) chance of winning this game.</code>
                    <br><span class="has-text-weight-bold">In Twitch Chat:</span><br><code>"You have a 167% chance of winning this game."</code>
                </div>
            </div>
        </section>
    </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script>
document.getElementById("openModalButton").addEventListener("click", function() {
    document.getElementById("customVariablesModal").classList.add("is-active");
});
document.getElementById("closeModalButton").addEventListener("click", function() {
    document.getElementById("customVariablesModal").classList.remove("is-active");
});

function showResponse() {
    var command = document.getElementById('command_to_edit').value;
    var commands = <?php echo json_encode($commands); ?>;
    var responseInput = document.getElementById('command_response');
    var cooldownInput = document.getElementById('cooldown');
    // Find the response for the selected command and display it in the text box
    var commandData = commands.find(c => c.command === command);
    responseInput.value = commandData ? commandData.response : '';
    cooldownInput.value = commandData && commandData.cooldown != null ? commandData.cooldown : '15';
}
</script>
</body>
</html>