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
$title = "Integrations";

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
  <!-- Fourthwall Integration -->
  <div class="content content-card">
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
  <!-- Ko-Fi Integration -->
  <div class="content content-card">
    <h2 class="subtitle">Ko-Fi Integration</h2>
    <p>Follow the steps below to integrate Specter with your Ko-Fi account:</p>
    <ol>
      <li>Log into your Ko-Fi account.</li>
      <li>When the manage page loads, on the left-hand side, under Stream Alerts, click the three dots where it says More.</li>
      <li>In the "More" section, click the API option.</li>
      <li>In the webhook URL field, enter: <br>
        <code>https://api.botofthespecter.com/kofi?api_key=</code> <br>
        Make sure to append your API key, which can be found on the Profile page of the Specter dashboard.
      </li>
      <li>Once you've entered the URL, click the Update button.</li>
    </ol>
    <p>That's it! Your Ko-Fi account is now integrated with Specter.</p>
  </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
</body>
</html>