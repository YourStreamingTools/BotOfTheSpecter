<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Subathon Settings";

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'modding_access.php';
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$channelData = $stmt->fetch(PDO::FETCH_ASSOC);
$timezone = $channelData['timezone'] ?? 'UTC';
date_default_timezone_set($timezone);
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
    <br>
    <h1 class="title">Subathon Settings</h1>
    <?php if ($message): ?>
        <div class="notification is-success">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    <div class="notification is-warning">
        <div class="columns is-vcentered">
            <div class="column is-narrow">
                <span class="icon is-large">
                    <i class="fas fa-hourglass-half fa-2x"></i> 
                </span>
            </div>
            <div class="column">
                <p><span class="has-text-weight-bold">Subathon Time: Work in Progress!</span></p>
                <p>We're still fine-tuning the donation feature for adding time to your subathon. In the meantime, you can easily add time with a quick command. </p>
                <p><span class="has-text-weight-bold">Here's how:</span></p>
                <ul>
                    <li><span class="icon"><i class="fas fa-comment-dots"></i></span> Type <code>!subathon addtime [minutes]</code> in chat.</li>
                    <li> For example: <code>!subathon addtime 10</code> (adds 10 minutes).</li>
                </ul>
                <p>We'll keep you updated on the donation feature. Thanks for your patience!</p> 
            </div>
        </div>
    </div>
    <div class="columns is-desktop is-multiline box-container">
        <div class="column is-5" id="stable-bot-status" style="position: relative;">
            <form method="POST" action="">
                <div class="field">
                    <label for="starting_minutes">Starting Minutes:</label>
                    <div class="control">
                        <input class="input" type="number" name="starting_minutes" id="starting_minutes" value="<?php echo htmlspecialchars($starting_minutes); ?>" required>
                    </div>
                    <p class="help">This is the default starting time (in minutes) for the subathon timer when it begins. It indicates how long the subathon will run before any additional time is added. The default value is 60 minutes.</p>
                </div>
                <div class="field">
                    <label for="cheer_add">Cheer Add:</label>
                    <div class="control">
                        <input class="input" type="number" name="cheer_add" id="cheer_add" value="<?php echo htmlspecialchars($cheer_add); ?>" required>
                    </div>
                    <p class="help">The number of minutes added to the subathon for each cheer received (per 100 bits). Default is 5 minutes.</p>
                </div>
                <div class="field">
                    <label for="sub_add_1">Tier 1 Subscription:</label>
                    <div class="control">
                        <input class="input" type="number" name="sub_add_1" id="sub_add_1" value="<?php echo htmlspecialchars($sub_add_1); ?>" required>
                    </div>
                    <p class="help">The number of minutes added for each Tier 1 subscription received.</p>
                </div>
                <div class="field">
                    <label for="sub_add_2">Tier 2 Subscription:</label>
                    <div class="control">
                        <input class="input" type="number" name="sub_add_2" id="sub_add_2" value="<?php echo htmlspecialchars($sub_add_2); ?>" required>
                    </div>
                    <p class="help">The number of minutes added for each Tier 2 subscription received.</p>
                </div>
                <div class="field">
                    <label for="sub_add_3">Tier 3 Subscription:</label>
                    <div class="control">
                        <input class="input" type="number" name="sub_add_3" id="sub_add_3" value="<?php echo htmlspecialchars($sub_add_3); ?>" required>
                    </div>
                    <p class="help">The number of minutes added for each Tier 3 subscription received.</p>
                </div>
                <div class="control">
                    <button class="button is-primary" type="submit" name="update_settings">Update Settings</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>