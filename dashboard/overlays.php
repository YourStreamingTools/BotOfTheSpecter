<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Overlays";

// Include all the information
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'user_db.php';
include "mod_access.php";
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);
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
    <div class="notification is-info">
        <div class="columns is-vcentered is-centered">
            <div class="column is-narrow">
                <span class="icon is-large">
                    <i class="fas fa-broadcast-tower fa-2x"></i> 
                </span>
            </div>
            <div class="column">
                <p><span class="has-text-weight-bold">Seamless Streaming Integration</span></p>
                <p>Specter works with all your favorite streaming software: OBS Studio, Streamlabs OBS, XSplit Broadcaster, and more!</p>
                <p><span class="has-text-weight-bold">To connect Specter to your stream:</span></p>
                <ul>
                    <li><span class="icon"><i class="fas fa-link"></i></span> Add these links as browser sources in your streaming software.</li>
                    <li><span class="icon"><i class="fas fa-key"></i></span>  Replace <code>API_KEY_HERE</code> with your unique API key (found on your profile page).</li> 
                </ul>
                <p><span class="icon"><i class="fas fa-user-secret"></i></span> Keep your API key safe—it's like a password for your overlays! </p>
            </div>
        </div>
    </div>
    <br>
    <div class="columns is-desktop is-multiline">
        <div class="column is-full">
            <!-- All the Overlays -->
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">All Overlays:</h4>
                <p><em>This URL includes all overlays we offer, automatically added and updated.
                    <br>The exceptions are: Stream Ending Credits,  To Do List & Video Alerts Only.
                    <br>These exceptions require a separate URL, please see below.
                    <br>Add this link once, and any new overlays will be included automatically:</em></p>
                <code>https://overlay.botofthespecter.com/?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- Stream Ending Credits Overlay -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">Stream Ending Credits:</h4>
                <p><em>The Stream Ending Credits display a scrolling list of all viewers who attended and supported the stream.
                    <br>This includes followers, subscribers, donors, and cheerers to thank those who contributed.
                    <br>(Coming Soon)</em></p>
                <code>https://overlay.botofthespecter.com/credits.php?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- To Do List Overlay -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">To Do List:</h4>
                <p><em>Display a list of tasks to complete during the stream.
                    <br>This overlay helps you keep track of your goals and share them with your audience.
                    <br>You can specify a category by adding it to the URL like this:
                    <br>todolist.php?code=API_KEY&category=1</em></p>
                <code>https://overlay.botofthespecter.com/todolist.php?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- Death Overlay -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">Death Overlay Only:</h4>
                <p><em>Show only the death overlay for the death commands triggered in chat:
                    <br>"!deaths", "!deathadd", and "!deathremove".
                    <br>For best results, set Width to 450 and Height to 350:</em></p>
                <code>https://overlay.botofthespecter.com/deaths.php?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- Weather Overlay -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">Weather Overlay:</h4>
                <p><em>Show current weather information for your specified location in your stream.
                    <br>To use this overlay, add the following URL as a browser source and append your API key.</em></p>
                <code>https://overlay.botofthespecter.com/weather.php?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- Discord Join Notifications Overlay -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">Discord Join Notifications:</h4>
                <p><em>Display notifications when a user joins your Discord server.
                    <br>Add this URL as a browser source and append your API key.</em></p>
                <code>https://overlay.botofthespecter.com/discord.php?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- Subathon Overlay -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">Subathon:</h4>
                <p><em>Show a countdown timer for a subathon.
                    <br>Add this URL as a browser source and append your API key.</em></p>
                <code>https://overlay.botofthespecter.com/subathon.php?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- All Audio Overlays -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">All Audio:</h4>
                <p><em>This URL includes all audio alerts we offer, automatically updated.
                    <br>Add this link once to include any new audio alerts automatically.</em></p>
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
                <p><em>Only hear Walkon audio set for each user.</em></p>
                <code>https://overlay.botofthespecter.com/walkons.php?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- Sound Alerts Only Overlay -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">Sound Alerts Only:</h4>
                <p><em>Only hear the sound alerts for each channel point reward.</em></p>
                <code>https://overlay.botofthespecter.com/sound-alert.php?code=API_KEY_HERE</code>
            </div>
        </div>
        <!-- Video Alerts Only Overlay -->
        <div class="column is-half">
            <div class="box" style="height: 100%; display: flex; flex-direction: column; justify-content: space-between;">
                <h4 class="title is-4">Video Alerts:</h4>
                <p><em>To see the video alerts for each channel point reward use the following link</em></p>
                <code>https://overlay.botofthespecter.com/video-alert.php?code=API_KEY_HERE</code>
            </div>
        </div>
    </div>
    <br>
</div>

<!-- Include the JavaScript files -->
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>