<?php 
// Initialize the session
session_start();
$today = new DateTime();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title and Initial Variables
$title = "Modules";
$current_blacklist = [];

// Include files for database and user data
require_once "db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'user_db.php';
foreach ($profileData as $profile) {
    $timezone = $profile['timezone'];
    $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);

// Fetch the current blacklist settings
$sql = "SELECT blacklist FROM joke_settings WHERE id = 1";
$stmt = $db->prepare($sql);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
if ($result) {
    $current_blacklist = json_decode($result['blacklist'], true);
}

// If form is submitted, update the blacklist
$update_success = false;
$update_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Handle Joke Blacklist Update
    if (isset($_POST['blacklist'])) {
        $new_blacklist = isset($_POST['blacklist']) ? $_POST['blacklist'] : [];
        $new_blacklist_json = json_encode($new_blacklist);
        // Update the blacklist in the database
        $update_sql = "UPDATE joke_settings SET blacklist = :blacklist WHERE id = 1";
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bindParam(':blacklist', $new_blacklist_json);
        $update_stmt->execute();
        // Set success message for blacklist update
        $update_success = true;
        $update_message = "Blacklist settings updated successfully.";
    }
    // Handle Welcome Message Settings Update
    elseif (isset($_POST['send_welcome_messages'])) {
        // Gather welcome message data
        $send_welcome_messages = isset($_POST['send_welcome_messages']) ? 1 : 0;
        $default_welcome_message = isset($_POST['default_welcome_message']) ? $_POST['default_welcome_message'] : '';
        $default_vip_welcome_message = isset($_POST['default_vip_welcome_message']) ? $_POST['default_vip_welcome_message'] : '';
        $default_mod_welcome_message = isset($_POST['default_mod_welcome_message']) ? $_POST['default_mod_welcome_message'] : '';
        // Update the streamer_preferences in the database
        $update_sql = "UPDATE streamer_preferences SET 
                        send_welcome_messages = :send_welcome_messages, 
                        default_welcome_message = :default_welcome_message,
                        default_vip_welcome_message = :default_vip_welcome_message,
                        default_mod_welcome_message = :default_mod_welcome_message
                        WHERE id = 1";
        $update_stmt = $db->prepare($update_sql);
        // Bind parameters
        $update_stmt->bindParam(':send_welcome_messages', $send_welcome_messages, PDO::PARAM_INT);
        $update_stmt->bindParam(':default_welcome_message', $default_welcome_message, PDO::PARAM_STR);
        $update_stmt->bindParam(':default_vip_welcome_message', $default_vip_welcome_message, PDO::PARAM_STR);
        $update_stmt->bindParam(':default_mod_welcome_message', $default_mod_welcome_message, PDO::PARAM_STR);
        // Execute the query
        $update_stmt->execute();
        // Set success message for welcome messages update
        $update_success = true;
        $update_message = "Welcome message settings updated successfully.";
    }
    // Refresh the page to show updated settings
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
?>
<!doctype html>
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
    <?php if ($update_success): ?><div class="notification is-success"><?php echo $update_message; ?></div><?php endif; ?>
    <div class="columns is-desktop is-multiline box-container">
        <!-- Joke Blacklist Section -->
        <div class="column is-5 bot-box" id="stable-bot-status" style="position: relative;">
            <form method="POST" action="">
                <h1 class="title">Manage Joke Blacklist:</h1>
                <h1 class="subtitle has-text-danger" style="center">Any category selected here will not be allowed to be posted by the bot.</h1>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="Miscellaneous"<?php echo in_array("Miscellaneous", $current_blacklist) ? " checked" : ""; ?>> Miscellaneous</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="Coding"<?php echo in_array("Coding", $current_blacklist) ? " checked" : ""; ?>> Coding</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="Development"<?php echo in_array("Development", $current_blacklist) ? " checked" : ""; ?>> Development</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="Halloween"<?php echo in_array("Halloween", $current_blacklist) ? " checked" : ""; ?>> Halloween</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="Pun"<?php echo in_array("Pun", $current_blacklist) ? " checked" : ""; ?>> Pun</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="nsfw"<?php echo in_array("nsfw", $current_blacklist) ? " checked" : ""; ?>> NSFW</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="religious"<?php echo in_array("religious", $current_blacklist) ? " checked" : ""; ?>> Religious</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="political"<?php echo in_array("political", $current_blacklist) ? " checked" : ""; ?>> Political</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="racist"<?php echo in_array("racist", $current_blacklist) ? " checked" : ""; ?>> Racist</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="sexist"<?php echo in_array("sexist", $current_blacklist) ? " checked" : ""; ?>> Sexist</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="dark"<?php echo in_array("dark", $current_blacklist) ? " checked" : ""; ?>> Dark</label></div>
                <div class="field"><label class="checkbox"><input type="checkbox" name="blacklist[]" value="explicit"<?php echo in_array("explicit", $current_blacklist) ? " checked" : ""; ?>> Explicit</label></div>
                <button class="button is-primary" type="submit">Save Settings</button>
            </form>
        </div>
        <!-- New Welcome Message Settings -->
        <div class="column is-5 bot-box" id="welcome-message-settings">
        <form method="POST" action="">
            <h1 class="title">Custom Welcome Messages</h1>
            <h1 class="subtitle">Set your default welcome messages for users, VIPs, and Mods.</h1>
            <!-- Info Box about (user) variable -->
            <div class="notification is-info">
                <strong>Info:</strong> You can use the <code>(user)</code> variable in the welcome message. It will be replaced with the username of the user entering the chat.
            </div>
            <div class="field">
                <label class="label">Default Welcome Message</label>
                <div class="control">
                    <textarea class="textarea" name="default_welcome_message"><?php echo htmlspecialchars($default_welcome_message ? $default_welcome_message : "(user) is new to the community, let's give them a warm welcome!"); ?></textarea>
                </div>
            </div>
            <div class="field">
                <label class="label">Default VIP Welcome Message</label>
                <div class="control">
                    <textarea class="textarea" name="default_vip_welcome_message"><?php echo htmlspecialchars($default_vip_welcome_message ? $default_vip_welcome_message : "ATTENTION! A very important person has entered the chat, welcome (user)"); ?></textarea>
                </div>
            </div>
            <div class="field">
                <label class="label">Default Mod Welcome Message</label>
                <div class="control">
                    <textarea class="textarea" name="default_mod_welcome_message"><?php echo htmlspecialchars($default_mod_welcome_message ? $default_mod_welcome_message : "MOD ON DUTY! Welcome in (user), the power of the sword has increased!"); ?></textarea>
                </div>
            </div>
            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="send_welcome_messages" value="1" <?php echo $send_welcome_messages ? 'checked' : ''; ?>> Enable welcome messages
                </label>
            </div>
            <button class="button is-primary" type="submit">Save Welcome Settings</button>
        </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>