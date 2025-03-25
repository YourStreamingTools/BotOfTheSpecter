<?php 
// Display PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();
$today = new DateTime();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
  header('Location: login.php');
  exit();
}

// Page Title and Initial Variables
$title = "Streaming Settings";

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

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $twitch_key = $_POST['twitch_key'];
    $forward_to_twitch = isset($_POST['forward_to_twitch']) ? 1 : 0;

    // Update the database with the new settings
    $stmt = $db->prepare("INSERT INTO streaming_settings (id, twitch_key, forward_to_twitch) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE twitch_key = VALUES(twitch_key), forward_to_twitch = VALUES(forward_to_twitch)");
    if ($stmt === false) {
        die('Prepare failed: ' . htmlspecialchars($db->error));
    }
    $stmt->bindValue(1, $twitch_key, PDO::PARAM_STR);
    $stmt->bindValue(2, $forward_to_twitch, PDO::PARAM_INT);
    if ($stmt->execute() === false) {
        die('Execute failed: ' . htmlspecialchars($stmt->errorInfo()[2]));
    }
    $stmt->closeCursor();
    // Set session variable to indicate success
    $_SESSION['settings_saved'] = true;
    header('Location: streaming.php');
    exit();
}

// Fetch current settings
$result = $db->query("SELECT twitch_key, forward_to_twitch FROM streaming_settings WHERE id = 1");
if ($result === false) {
    die('Query failed: ' . htmlspecialchars($db->error));
}
$current_settings = $result->fetch(PDO::FETCH_ASSOC);
$twitch_key = $current_settings['twitch_key'] ?? '';
$forward_to_twitch = $current_settings['forward_to_twitch'] ?? 1;
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
    <div class="notification is-primary">
        <p class="has-text-weight-bold has-text-black">Complementary Streaming Service</p>
        <p class="has-text-black">This streaming feature is provided as a complementary service for all Specter users. You have multiple options:</p>
        <ul>
            <li class="has-text-black">Record your streams and simultaneously forward them to Twitch</li>
            <li class="has-text-black">Use our service as a secondary output for your streams via multi-streaming</li>
        </ul>
        <p class="has-text-black">The choice is yours on how to utilize this feature to enhance your streaming experience.</p>
    </div>
    <h1 class="title">Streaming Settings</h1>
    <?php if (isset($_SESSION['settings_saved'])): ?>
        <?php unset($_SESSION['settings_saved']); ?>
        <div class="notification is-success">
            Settings have been successfully saved.
        </div>
    <?php endif; ?>
    <div class="columns is-desktop is-multiline is-centered box-container">
        <div class="column is-5" style="position: relative;">
            <div class="notification is-info">
                <span class="has-text-weight-bold">Streaming Instructions:</span>
                <ul>
                    <li>Retrieve your Twitch Stream Key from your account settings.</li>
                    <li>Enter the key below and choose whether to forward it to Twitch.</li>
                    <li>Click "Save Settings".</li>
                </ul>
                <br>
                <span class="has-text-weight-bold">Server Information:</span>
                <p>Stream securely via RTMPS at <code>rtmps://stream.botofthespecter.com:1935</code>.</p>
                <p>Your API key (found on your profile) serves as the stream key.</p>
            </div>
        </div>
        <div class="column is-5 bot-box" style="position: relative;">
            <form method="post" action="">
                <div class="field">
                    <label class="has-text-white has-text-left" for="twitch_key">Twitch Stream Key</label>
                    <div class="control">
                        <input type="text" class="input" id="twitch_key" name="twitch_key" value="<?php echo htmlspecialchars($twitch_key); ?>" required>
                    </div>
                </div>
                <div class="field">
                    <div class="control">
                        <label class="checkbox" for="forward_to_twitch">
                            <input type="checkbox" id="forward_to_twitch" name="forward_to_twitch" <?php echo $forward_to_twitch ? 'checked' : ''; ?>>
                            Forward to Twitch
                        </label>
                    </div>
                </div>
                <div class="field">
                    <div class="control">
                        <button type="submit" class="button is-primary">Save Settings</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
