<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Edit Bot Points Settings";

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
$broadcasterID = $twitchUserId;
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';
$status = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $point_name = $_POST['point_name'];
    $point_amount_chat = $_POST['point_amount_chat'];
    $point_amount_follower = $_POST['point_amount_follower'];
    $point_amount_subscriber = $_POST['point_amount_subscriber'];
    $point_amount_cheer = $_POST['point_amount_cheer'];
    $point_amount_raid = $_POST['point_amount_raid'];
    $subscriber_multiplier = $_POST['subscriber_multiplier'];

    $updateStmt = $db->prepare("UPDATE bot_settings SET 
        point_name = ?, 
        point_amount_chat = ?, 
        point_ammount_follower = ?, 
        point_amount_subscriber = ?, 
        point_amount_cheer = ?, 
        point_amount_raid = ?, 
        subscriber_multiplier = ?
    WHERE id = 1");

    $updateStmt->execute([
        $point_name, 
        $point_amount_chat, 
        $point_amount_follower, 
        $point_amount_subscriber, 
        $point_amount_cheer, 
        $point_amount_raid, 
        $subscriber_multiplier
    ]);
    $status = "Settings updated successfully!";
}

$settingsStmt = $db->prepare("SELECT * FROM bot_settings WHERE id = 1");
$settingsStmt->execute();
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

// Convert stored multiplier to slider value (0 to 9)
$sliderValue = $settings['subscriber_multiplier'] == 0 ? 0 : $settings['subscriber_multiplier'] - 1;
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
    <h2 class="subtitle">Points System Settings</h2>
    <?php if ($status): ?>
        <div class="notification is-success"><?php echo $status; ?></div>
    <?php endif; ?>
    <div class="columns is-desktop is-multiline">
        <form method="POST" action="" class="column is-8">
            <div class="columns is-multiline">
                <div class="column is-half">
                    <div class="field">
                        <label class="label" for="point_name">Point Name</label>
                        <div class="control">
                            <input class="input" type="text" name="point_name" value="<?php echo htmlspecialchars($settings['point_name']); ?>" required>
                        </div>
                    </div>
                </div>
                <div class="column is-half">
                    <div class="field">
                        <label class="label" for="point_amount_chat">Points for Chat</label>
                        <div class="control">
                            <input class="input" type="number" name="point_amount_chat" value="<?php echo htmlspecialchars($settings['point_amount_chat']); ?>" required>
                        </div>
                    </div>
                </div>
                <div class="column is-half">
                    <div class="field">
                        <label class="label" for="point_amount_follower">Points for Follower</label>
                        <div class="control">
                            <input class="input" type="number" name="point_amount_follower" value="<?php echo htmlspecialchars($settings['point_ammount_follower']); ?>" required>
                        </div>
                    </div>
                </div>
                <div class="column is-half">
                    <div class="field">
                        <label class="label" for="point_amount_subscriber">Points for Subscriber</label>
                        <div class="control">
                            <input class="input" type="number" name="point_amount_subscriber" value="<?php echo htmlspecialchars($settings['point_amount_subscriber']); ?>" required>
                        </div>
                    </div>
                </div>
                <div class="column is-half">
                    <div class="field">
                        <label class="label" for="point_amount_cheer">Points for Cheer</label>
                        <div class="control">
                            <input class="input" type="number" name="point_amount_cheer" value="<?php echo htmlspecialchars($settings['point_amount_cheer']); ?>" required>
                        </div>
                    </div>
                </div>
                <div class="column is-half">
                    <div class="field">
                        <label class="label" for="point_amount_raid">Points for Raid</label>
                        <div class="control">
                            <input class="input" type="number" name="point_amount_raid" value="<?php echo htmlspecialchars($settings['point_amount_raid']); ?>" required>
                        </div>
                    </div>
                </div>
                <div class="column is-full">
                    <div class="field">
                        <label class="label" for="subscriber_multiplier">Subscriber Multiplier</label>
                        <div class="control" style="display: flex; align-items: center; gap: 10px;">
                            <input class="slider is-fullwidth" type="range" name="subscriber_multiplier" min="0" max="9" value="<?php echo $sliderValue; ?>" step="1" style="height: 2.5em; width: 100%;" oninput="updateMultiplierLabel(this.value)">
                            <output id="multiplierLabel" style="font-size: 1.25em;"><?php echo ($settings['subscriber_multiplier'] == 0) ? '0' : $settings['subscriber_multiplier'] . 'x'; ?></output>
                        </div>
                    </div>
                </div>
            </div>
            <div class="field">
                <div class="control">
                    <button class="button is-primary" type="submit">Update Settings</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    function updateMultiplierLabel(value) {
        const label = document.getElementById('multiplierLabel');
        const multiplier = value == 0 ? 0 : parseInt(value) + 1;
        label.textContent = multiplier == 0 ? '0' : multiplier + 'x';
        document.querySelector("input[name='subscriber_multiplier']").value = multiplier;
    }
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>