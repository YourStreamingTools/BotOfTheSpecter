<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
    <h1 class="title">
        <?php echo "$greeting, $twitchDisplayName"; ?>
        <img id='profile-image' class='round-image' src='<?php echo $twitch_profile_image_url; ?>' width='50px' height='50px' alt='<?php echo $twitchDisplayName; ?> Profile Image'>
    </h1>
    <br>
    <h3 class="title is-3">This system is fully compatible with all popular streaming software, including:</h3>
    <h4 class="title is-4">OBS Studio, Streamlabs OBS, XSplit Broadcaster, Wirecast, vMix, Lightstream, and many more</h4>
    <h4 class="title is-4">To integrate with your streaming setup, simply add one or more of the following links to a browser source in your streaming software, appending your API key from the profile page:</h4>
    <br>
    <div class="columns is-desktop is-multiline">
        <div class="column is-half">
            <!-- All the Overlays -->
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">All Overlays:</h4>
                <p><em>This URL includes any and all overlays we offer, automatically added and updated.
                    <br>The only exception is the Stream Ending Credits & To Do List, which must be added separately.
                    <br>Simply add this link once, and any new overlays will be included automatically:</em></p>
                <code>https://overlay.botofthespecter.com/?code=API_KEY_HERE</code>
            </div>
        </div>
    </div>
    <div class="columns is-desktop is-multiline">
        <!-- Death Overlay -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">Death Overlay Only:</h4>
                <p><em>Display only the death overlay when the death commands are triggered in chat:
                    <br>"!deaths", "!deathadd", and "!deathremove".
                    <br>For best results, set Width to 450 and Height to 350:</em></p>
                <code>https://overlay.botofthespecter.com/deaths.php?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- Stream Ending Credits Overlay -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">Stream Ending Credits:</h4>
                <p><em>The Stream Ending Credits display a scrolling list of all the viewers who attended and supported the stream.
                    <br>This includes followers, subscribers, donors, and cheerers, providing a special thank you to everyone who contributed.
                    <br>(Coming Soon)</em></p>
                <code>https://overlay.botofthespecter.com/credits.php?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- To Do List Overlay -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">To Do List:</h4>
                <p><em>Display a list of tasks to be completed during the stream.
                    <br>This overlay helps you keep track of your goals and share them with your audience.
                    <br>You can define a working category by adding it to the URL like this:
                    <br>todolist.php?code=API_KEY&category=1</></em></p>
                <code>https://overlay.botofthespecter.com/todolist.php?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- Weather Overlay -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">Weather Overlay:</h4>
                <p><em>Display the current weather information for your specified location in your stream.
                    <br>To use this overlay, add the following URL as a browser source and append your API key.</em></p>
                <code>https://overlay.botofthespecter.com/weather.php?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- Discord Join Notifications Overlay -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">Discord Join Notifications:</h4>
                <p><em>Display notifications when a user joins your Discord server.
                    <br>To use this overlay, add the following URL as a browser source and append your API key.</em></p>
                <code>https://overlay.botofthespecter.com/discord.php?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- All Audio Overlays -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">All Audio:</h4>
                <p><em>This URL includes any and all audio alerts we offer, automatically added and updated.
                    <br>You only need to add this link once, and any new audio alerts will be included automatically:</em></p>
                <code>https://overlay.botofthespecter.com/alert.php?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- TTS Only Overlay -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">Text To Speech (TTS) Only:</h4>
                <p><em>Only hear the Text To Speech audio.</em></p>
                <code>https://overlay.botofthespecter.com/tts.php?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- Walkons Only Overlay -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">Walkons Only:</h4>
                <p><em>Only hear the Walkon audio that you've set for each user.</em></p>
                <code>https://overlay.botofthespecter.com/walkons.php?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- Sound Alerts Only Overlay -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">Sound Alerts Only:</h4>
                <p><em>Only hear the sound alerts audio that you've set for each channel point reward.</em></p>
                <code>https://overlay.botofthespecter.com/sound-alert.php?code=API_KEY_HERE</code>
            </div>
        </div>
    </div>
    <br>
</div>

<!-- Include the JavaScript files -->
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>