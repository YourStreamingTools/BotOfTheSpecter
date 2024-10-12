<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL); ?>
<?php
// Initialize the session
session_start();

// check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Subathon Settings";

// Connect to database
require_once "db_connect.php";

// Fetch the user's data from the database based on the access_token
$access_token = $_SESSION['access_token'];
$stmt = $conn->prepare("SELECT * FROM users WHERE access_token = ?");
$stmt->bind_param("s", $access_token);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$user_id = $user['id'];
$username = $user['username'];
$broadcasterID = $user['twitch_user_id'];
$twitchDisplayName = $user['twitch_display_name'];
$twitch_profile_image_url = $user['profile_image'];
$is_admin = ($user['is_admin'] == 1);
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';
$message = '';

// Fetch the current subathon settings
$stmt = $db->prepare("SELECT * FROM subathon_settings LIMIT 1");
$stmt->execute();
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

// Default values if settings are not found
$starting_minutes = $settings['starting_minutes'] ?? 60;
$cheer_add = $settings['cheer_add'] ?? 5;
$sub_add_1 = $settings['sub_add_1'] ?? 10;
$sub_add_2 = $settings['sub_add_2'] ?? 20;
$sub_add_3 = $settings['sub_add_3'] ?? 30;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    // Get the submitted values
    $starting_minutes = $_POST['starting_minutes'];
    $cheer_add = $_POST['cheer_add'];
    $sub_add_1 = $_POST['sub_add_1'];
    $sub_add_2 = $_POST['sub_add_2'];
    $sub_add_3 = $_POST['sub_add_3'];
    // Update the settings in the database
    $stmt = $db->prepare("INSERT INTO subathon_settings (starting_minutes, cheer_add, sub_add_1, sub_add_2, sub_add_3) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE starting_minutes=?, cheer_add=?, sub_add_1=?, sub_add_2=?, sub_add_3=?");
    $stmt->execute([$starting_minutes, $cheer_add, $sub_add_1, $sub_add_2, $sub_add_3, $starting_minutes, $cheer_add, $sub_add_1, $sub_add_2, $sub_add_3]);
    // Set the success message
    $message = "Settings updated successfully!";
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
    <?php if ($message): ?>
        <div class="notification is-success">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    <form method="POST" action="">
        <div class="field">
            <label class="label" for="starting_minutes">Starting Minutes:</label>
            <div class="control">
                <input class="input" type="number" name="starting_minutes" id="starting_minutes" value="<?php echo htmlspecialchars($starting_minutes); ?>" required>
            </div>
        </div>
        <div class="field">
            <label class="label" for="cheer_add">Cheer Add:</label>
            <div class="control">
                <input class="input" type="number" name="cheer_add" id="cheer_add" value="<?php echo htmlspecialchars($cheer_add); ?>" required>
            </div>
        </div>
        <div class="field">
            <label class="label" for="sub_add_1">Sub Add 1:</label>
            <div class="control">
                <input class="input" type="number" name="sub_add_1" id="sub_add_1" value="<?php echo htmlspecialchars($sub_add_1); ?>" required>
            </div>
        </div>
        <div class="field">
            <label class="label" for="sub_add_2">Sub Add 2:</label>
            <div class="control">
                <input class="input" type="number" name="sub_add_2" id="sub_add_2" value="<?php echo htmlspecialchars($sub_add_2); ?>" required>
            </div>
        </div>
        <div class="field">
            <label class="label" for="sub_add_3">Sub Add 3:</label>
            <div class="control">
                <input class="input" type="number" name="sub_add_3" id="sub_add_3" value="<?php echo htmlspecialchars($sub_add_3); ?>" required>
            </div>
        </div>
        <div class="control">
            <button class="button is-primary" type="submit" name="update_settings">Update Settings</button>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>