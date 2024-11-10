<?php
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Platform Integrations"; 

// Include all the information
require_once "db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'sqlite.php';
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);
$greeting = 'Hello';
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <!-- Headder -->
    <?php include('header.php'); ?>
    <!-- /Headder -->
  </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
  <h1 class="title"><?php echo "$greeting, $twitchDisplayName <img id='profile-image' class='round-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
  <br>
  <div class="columns is-desktop is-multiline box-container">
    <div class="column bot-box content content-card" id="fourthwall"> 
      <h2 class="subtitle">Fourthwall Integration</h2>
      <p>Follow the steps below to integrate Specter with your Fourthwall account:</p>
      <ol>
        <li>Login to your Fourthwall admin dashboard.</li>
        <li>On the left-hand menu, click Settings.</li>
        <li>In the Site Settings page, find and click the For developers link.</li>
        <li>Click Create webhook in the webhooks section.</li>
        <li>In the URL field, enter: <br>
          <code>https://api.botofthespecter.com/fourthwall?api_key=</code> <br>
          Make sure to append your API key, which can be found on the Profile page.
        </li>
        <li>From the "Add Event" list, choose any or all of the following events:
          <ul>
            <li>Order placed</li>
            <li>Gift purchase</li>
            <li>Donation</li>
            <li>Subscription purchased</li>
          </ul>
        </li>
      </ol>
      <p>That's it! Your Fourthwall account is now integrated with Specter.</p>
    </div>
    <div class="column bot-box content content-card" id="kofi"> 
      <h2 class="subtitle">Ko-Fi Integration</h2>
      <p>Follow the steps below to integrate Specter with your Ko-Fi account:</p>
      <ol>
        <li>Log into your Ko-Fi account.</li>
        <li>When the manage page loads, on the left-hand side, under Stream Alerts, click the three dots where it says More.</li>
        <li>In the "More" section, click the API option.</li>
        <li>In the webhook URL field, enter: <br>
          <code>https://api.botofthespecter.com/kofi?api_key=</code> <br>
          Make sure to append your API key, which can be found on the Profile page.
        </li>
        <li>Once you've entered the URL, click the Update button.</li>
      </ol>
      <p>That's it! Your Ko-Fi account is now integrated with Specter.</p>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>