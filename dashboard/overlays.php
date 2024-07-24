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
$title = "Overlays";
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
$twitchUserId = $user['twitch_user_id'];
$signup_date = $user['signup_date'];
$last_login = $user['last_login'];
$api_key = $user['api_key'];
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';
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
    <h3 class="title is-3">This website is fully compatible with popular streaming software, including:</h3>
    <h4 class="title is-4">OBS Studio, Streamlabs OBS, XSplit Broadcaster, Wirecast, vMix, Lightstream and many more</h4>
    <h4 class="title is-4">To integrate with your streaming setup, simply add one or more of the following links to a browser source in your streaming software, appending your API key from the profile page:</h4>
    <br>
    <ul>
        <li>All Overlays:<br>
            <em>This URL includes any and all overlays we offer, automatically added and updated. You only need to add this link once, and any new overlays will be included automatically:</em><br>
            <code>https://overlay.botofthespecter.com/?code=API_KEY_HERE</code>
        </li>
        <br>
        <li>Death Overlay:<br>
            <em>For best results, set Width to 450 and Height to 350:</em><br>
            <code>https://overlay.botofthespecter.com/deaths.php?code=API_KEY_HERE</code>
        </li>
        <br>
        <li>Stream End Credits:<br>
            <em>(Coming Soon)</em><br>
            <code>https://overlay.botofthespecter.com/credits.php?code=API_KEY_HERE</code>
        </li>
        <br>
        <li>All Audio:<br>
            <code>https://overlay.botofthespecter.com/alert.php?code=API_KEY_HERE</code>
        </li>
        <br>
        <li>TTS Only:<br>
            <code>https://overlay.botofthespecter.com/tts.php?code=API_KEY_HERE</code>
        </li>
        <br>
        <li>Walkons Only:<br>
            <code>https://overlay.botofthespecter.com/walkons.php?code=API_KEY_HERE</code>
        </li>
    </ul>
</div>

<!-- Include the JavaScript files -->
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="/js/obsbutton.js" defer></script>
</body>
</html>