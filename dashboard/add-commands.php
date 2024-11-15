<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Add Bot Commands";

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
$greeting = 'Hello';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['command']) && isset($_POST['response'])) {
        $newCommand = strtolower(str_replace(' ', '', $_POST['command']));
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
    <h1 class="title"><?php echo "$greeting, $twitchDisplayName <img id='profile-image' class='round-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
    <br>
    <div class="notification is-info">
        <p>When adding commands via this page, please note the following:<br>
        <ol style="padding-left: 30px;">
            <li>Avoid using the exclamation mark (!) in your command. This will be automatically added.</li>
            <li>Alternatively, you or your moderators can add commands by using the command <strong>!addcommand</strong> command in your Twitch Chat.<br>
                Example: <code>!addcommand mycommand This is my command</code></li>
        </ol></p><br>
        <button class="button is-primary" id="openModalButton">View Custom Variables</button>
    </div>
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
                        <span class="has-text-weight-bold variable-title">(count)</span><br>This counts how many times the command has been used and shows that number.
                        <br><span class="has-text-weight-bold">Example:</span><br><code>This command has been used (count) times.</code>
                        <br><span class="has-text-weight-bold">In Twitch Chat:</span><br><code>"This command has been used 5 times."</code>
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
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script>
document.getElementById("openModalButton").addEventListener("click", function() {
    document.getElementById("customVariablesModal").classList.add("is-active");
});
document.getElementById("closeModalButton").addEventListener("click", function() {
    document.getElementById("customVariablesModal").classList.remove("is-active");
});
</script>
</body>
</html>