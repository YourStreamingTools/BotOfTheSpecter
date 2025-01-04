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
require_once "/var/www/config/db_connect.php";
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

// Fetch the current settings from the database each time the page loads
$fetch_sql = "SELECT send_welcome_messages, default_welcome_message, default_vip_welcome_message, default_mod_welcome_message FROM streamer_preferences WHERE id = 1";
$fetch_stmt = $db->prepare($fetch_sql);
$fetch_stmt->execute();
$preferences = $fetch_stmt->fetch(PDO::FETCH_ASSOC);

// Set default values if no settings exist in the database
$send_welcome_messages = isset($preferences['send_welcome_messages']) ? $preferences['send_welcome_messages'] : 1;
$default_welcome_message = isset($preferences['default_welcome_message']) ? $preferences['default_welcome_message'] : "Welcome back (user), glad to see you again!";
$new_default_welcome_message = isset($preferences['new_default_welcome_message']) ? $preferences['new_default_welcome_message'] : "(user) is new to the community, let's give them a warm welcome!";
$default_vip_welcome_message = isset($preferences['default_vip_welcome_message']) ? $preferences['default_vip_welcome_message'] : "ATTENTION! A very important person has entered the chat, welcome (user)";
$new_default_vip_welcome_message = isset($preferences['new_default_vip_welcome_message']) ? $preferences['new_default_vip_welcome_message'] : "ATTENTION! A very important person has entered the chat, welcome (user)";
$default_mod_welcome_message = isset($preferences['default_mod_welcome_message']) ? $preferences['default_mod_welcome_message'] : "MOD ON DUTY! Welcome in (user), the power of the sword has increased!";
$new_default_mod_welcome_message = isset($preferences['new_default_mod_welcome_message']) ? $preferences['new_default_mod_welcome_message'] : "MOD ON DUTY! Welcome in (user), the power of the sword has increased!";

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
        // Set success message for blacklist update in session
        $_SESSION['update_message'] = "Blacklist settings updated successfully.";
    }
    // Handle Welcome Message Settings Update
    elseif (isset($_POST['send_welcome_messages'])) {
        // Gather and save the updated welcome message data
        $send_welcome_messages = isset($_POST['send_welcome_messages']) ? 1 : 0;
        // Existing welcome messages
        $default_welcome_message = isset($_POST['default_welcome_message']) ? $_POST['default_welcome_message'] : '';
        $default_vip_welcome_message = isset($_POST['default_vip_welcome_message']) ? $_POST['default_vip_welcome_message'] : '';
        $default_mod_welcome_message = isset($_POST['default_mod_welcome_message']) ? $_POST['default_mod_welcome_message'] : '';
        // New welcome messages
        $new_default_welcome_message = isset($_POST['new_default_welcome_message']) ? $_POST['new_default_welcome_message'] : '';
        $new_default_vip_welcome_message = isset($_POST['new_default_vip_welcome_message']) ? $_POST['new_default_vip_welcome_message'] : '';
        $new_default_mod_welcome_message = isset($_POST['new_default_mod_welcome_message']) ? $_POST['new_default_mod_welcome_message'] : '';
        // Update the streamer_preferences in the database
        $update_sql = "
            INSERT INTO streamer_preferences 
            (id, send_welcome_messages, default_welcome_message, default_vip_welcome_message, default_mod_welcome_message, 
                new_default_welcome_message, new_default_vip_welcome_message, new_default_mod_welcome_message)
            VALUES 
            (1, :send_welcome_messages, :default_welcome_message, :default_vip_welcome_message, :default_mod_welcome_message,
                :new_default_welcome_message, :new_default_vip_welcome_message, :new_default_mod_welcome_message)
            ON DUPLICATE KEY UPDATE 
                send_welcome_messages = :send_welcome_messages, 
                default_welcome_message = :default_welcome_message,
                default_vip_welcome_message = :default_vip_welcome_message,
                default_mod_welcome_message = :default_mod_welcome_message,
                new_default_welcome_message = :new_default_welcome_message,
                new_default_vip_welcome_message = :new_default_vip_welcome_message,
                new_default_mod_welcome_message = :new_default_mod_welcome_message";
        $update_stmt = $db->prepare($update_sql);
        // Bind parameters
        $update_stmt->bindParam(':send_welcome_messages', $send_welcome_messages, PDO::PARAM_INT);
        $update_stmt->bindParam(':default_welcome_message', $default_welcome_message, PDO::PARAM_STR);
        $update_stmt->bindParam(':default_vip_welcome_message', $default_vip_welcome_message, PDO::PARAM_STR);
        $update_stmt->bindParam(':default_mod_welcome_message', $default_mod_welcome_message, PDO::PARAM_STR);
        $update_stmt->bindParam(':new_default_welcome_message', $new_default_welcome_message, PDO::PARAM_STR);
        $update_stmt->bindParam(':new_default_vip_welcome_message', $new_default_vip_welcome_message, PDO::PARAM_STR);
        $update_stmt->bindParam(':new_default_mod_welcome_message', $new_default_mod_welcome_message, PDO::PARAM_STR);
        // Execute the query
        $update_stmt->execute();
        // Set success message for welcome messages update in session
        $_SESSION['update_message'] = "Welcome message settings updated successfully.";
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
    <h1 class="title is-3">Module Settings</h1>
    <br>
    <?php if (isset($_SESSION['update_message'])): ?><div class="notification is-success"><?php echo $_SESSION['update_message']; unset($_SESSION['update_message']);?></div><?php endif; ?>
    <div class="columns is-desktop is-multiline is-centered box-container">
        <!-- Joke Blacklist Section -->
        <div class="column is-4 bot-box" id="stable-bot-status" style="position: relative;">
            <h2 class="title is-3">Manage Joke Blacklist:</h2>
            <h2 class="subtitle is-4 has-text-danger" style="text-align: center;">Any category selected here will not be allowed to be posted by the bot.</h2>
            <button class="button is-primary" onclick="openModal('jokeBlacklistModal')">Open Settings</button>
        </div>
        <!-- New Welcome Message Settings -->
        <div class="column is-6 bot-box" id="welcome-message-settings" style="position: relative;">
            <span style="position: absolute; top: 10px; right: 10px;" class="has-text-danger">v5.2 Feature</span>
            <h1 class="title is-3">Custom Welcome Messages</h1>
            <h1 class="subtitle is-5">Set your default welcome messages for users, VIPs, and Mods.</h1>
            <button class="button is-primary" onclick="openModal('welcomeMessageModal')">Open Settings</button>
        </div>
    </div>
</div>

<!-- Joke Blacklist Modal -->
<div id="jokeBlacklistModal" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content">
        <div class="box">
            <form method="POST" action="">
                <h2 class="title is-3">Manage Joke Blacklist:</h2>
                <h2 class="subtitle is-4 has-text-danger" style="text-align: center;">Any category selected here will not be allowed to be posted by the bot.</h2>
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
    </div>
    <button class="modal-close is-large" aria-label="close" onclick="closeModal('jokeBlacklistModal')"></button>
</div>

<!-- Welcome Message Modal -->
<div id="welcomeMessageModal" class="modal">
    <div class="modal-background"></div>
    <div class="modal-content">
        <div class="box">
            <form method="POST" action="">
                <div class="notification is-info">
                    <strong>Info:</strong> You can use the <code>(user)</code> variable in the welcome message. It will be replaced with the username of the user entering the chat.
                </div>
                <div class="field">
                    <label class="label">Default New Member Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="new_default_welcome_message" value="<?php echo $new_default_welcome_message ? $new_default_welcome_message : '(user) is new to the community, let\'s give them a warm welcome!'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="label">Default Returning Member Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="default_welcome_message" value="<?php echo $default_welcome_message ? $default_welcome_message : 'Welcome back (user), glad to see you again!'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="label">Default New VIP Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="new_default_vip_welcome_message" value="<?php echo $new_default_vip_welcome_message ? $new_default_vip_welcome_message : 'ATTENTION! A very important person has entered the chat, welcome (user)'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="label">Default Returning VIP Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="default_vip_welcome_message" value="<?php echo $default_vip_welcome_message ? $default_vip_welcome_message : 'ATTENTION! A very important person has entered the chat, welcome (user)'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="label">Default New Mod Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="new_default_mod_welcome_message" value="<?php echo $new_default_mod_welcome_message ? $new_default_mod_welcome_message : 'MOD ON DUTY! Welcome in (user), the power of the sword has increased!'; ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="label">Default Returning Mod Welcome Message</label>
                    <div class="control">
                        <input class="input" type="text" name="default_mod_welcome_message" value="<?php echo $default_mod_welcome_message ? $default_mod_welcome_message : 'MOD ON DUTY! Welcome in (user), the power of the sword has increased!'; ?>">
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
    <button class="modal-close is-large" aria-label="close" onclick="closeModal('welcomeMessageModal')"></button>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script>
function openModal(modalId) {
    document.getElementById(modalId).classList.add('is-active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('is-active');
}
</script>
</body>
</html>