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
$title = "Bot Points Management";

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
$pointsName = htmlspecialchars($settings['point_name']);

// Fetch users and their points from bot_points table
$pointsStmt = $db->prepare("SELECT user_name, points FROM bot_points ORDER BY points DESC");
$pointsStmt->execute();
$pointsData = $pointsStmt->fetchAll(PDO::FETCH_ASSOC);
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

    <!-- Settings Button -->
    <button class="button is-primary" id="settingsButton">Settings</button>

    <!-- Points Table -->
    <h2 class="subtitle">User Points</h2>
    <table class="table is-fullwidth is-striped">
        <thead>
            <tr>
                <th>Username</th>
                <th>Points</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pointsData as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['user_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['points']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal -->
<div class="modal" id="settingsModal">
    <div class="modal-background"></div>
    <div class="modal-card">
        <header class="modal-card-head">
            <p class="modal-card-title">Points System Settings</p>
            <button class="delete" aria-label="close" id="closeModal"></button>
        </header>
        <section class="modal-card-body">
            <?php if ($status): ?>
                <div class="notification is-success"><?php echo $status; ?></div>
            <?php endif; ?>
            <form method="POST" action="">
                <div class="field">
                    <label class="label" for="point_name">Points Name</label>
                    <div class="control">
                        <input class="input" type="text" name="point_name" value="<?php echo $pointsName; ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="point_amount_chat"><?php echo $pointsName; ?> Earned per Chat Message</label>
                    <div class="control">
                        <input class="input" type="number" name="point_amount_chat" value="<?php echo htmlspecialchars($settings['point_amount_chat']); ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="point_amount_follower"><?php echo $pointsName; ?> Earned for Following</label>
                    <div class="control">
                        <input class="input" type="number" name="point_amount_follower" value="<?php echo htmlspecialchars($settings['point_ammount_follower']); ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="point_amount_subscriber"><?php echo $pointsName; ?> Earned for Subscribing</label>
                    <div class="control">
                        <input class="input" type="number" name="point_amount_subscriber" value="<?php echo htmlspecialchars($settings['point_amount_subscriber']); ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="point_amount_cheer"><?php echo $pointsName; ?> Earned Per Cheer</label>
                    <div class="control">
                        <input class="input" type="number" name="point_amount_cheer" value="<?php echo htmlspecialchars($settings['point_amount_cheer']); ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="point_amount_raid"><?php echo $pointsName; ?> Earned Per Raid</label>
                    <div class="control">
                        <input class="input" type="number" name="point_amount_raid" value="<?php echo htmlspecialchars($settings['point_amount_raid']); ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="subscriber_multiplier">Subscriber Multiplier</label>
                    <div class="control">
                        <div class="select is-fullwidth">
                            <select name="subscriber_multiplier">
                                <option value="0" <?php echo $settings['subscriber_multiplier'] == 0 ? 'selected' : ''; ?>>None</option>
                                <option value="2" <?php echo $settings['subscriber_multiplier'] == 2 ? 'selected' : ''; ?>>2x</option>
                                <option value="3" <?php echo $settings['subscriber_multiplier'] == 3 ? 'selected' : ''; ?>>3x</option>
                                <option value="4" <?php echo $settings['subscriber_multiplier'] == 4 ? 'selected' : ''; ?>>4x</option>
                                <option value="5" <?php echo $settings['subscriber_multiplier'] == 5 ? 'selected' : ''; ?>>5x</option>
                                <option value="6" <?php echo $settings['subscriber_multiplier'] == 6 ? 'selected' : ''; ?>>6x</option>
                                <option value="7" <?php echo $settings['subscriber_multiplier'] == 7 ? 'selected' : ''; ?>>7x</option>
                                <option value="8" <?php echo $settings['subscriber_multiplier'] == 8 ? 'selected' : ''; ?>>8x</option>
                                <option value="9" <?php echo $settings['subscriber_multiplier'] == 9 ? 'selected' : ''; ?>>9x</option>
                                <option value="10" <?php echo $settings['subscriber_multiplier'] == 10 ? 'selected' : ''; ?>>10x</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="field">
                    <div class="control">
                        <button class="button is-primary" type="submit">Update Settings</button>
                    </div>
                </div>
            </form>
        </section>
    </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script>
    // Modal Script
    document.getElementById('settingsButton').addEventListener('click', function() {
        document.getElementById('settingsModal').classList.add('is-active');
    });

    document.getElementById('closeModal').addEventListener('click', function() {
        document.getElementById('settingsModal').classList.remove('is-active');
    });

    document.querySelector('.modal-background').addEventListener('click', function() {
        document.getElementById('settingsModal').classList.remove('is-active');
    });
</script>
</body>
</html>