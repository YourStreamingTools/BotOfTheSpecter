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
include 'modding_access.php';
include 'user_db.php';
$getProfile = $db->query("SELECT timezone FROM profile");
$profile = $getProfile->fetchAll(PDO::FETCH_ASSOC);
$timezone = $profile['timezone'];
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
    // Gather selected options
    $new_blacklist = isset($_POST['blacklist']) ? $_POST['blacklist'] : [];
    $new_blacklist_json = json_encode($new_blacklist);
    // Update the blacklist in the database
    $update_sql = "UPDATE joke_settings SET blacklist = :blacklist WHERE id = 1";
    $update_stmt = $db->prepare($update_sql);
    $update_stmt->bindParam(':blacklist', $new_blacklist_json);
    $update_stmt->execute();
    // Refresh the page to show updated settings
    $update_success = true;
    $update_message = "Blacklist settings updated successfully.";
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
        <div class="column is-5 bot-box" id="stable-bot-status" style="position: relative;">
            <form method="POST" action="">
                <h1 class="title">Manage Joke Blacklist:</h1>
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
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>