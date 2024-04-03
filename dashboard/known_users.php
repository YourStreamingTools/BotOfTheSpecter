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
$title = "Known Users";

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
$webhookPort = $user['webhook_port'];
$websocketPort = $user['websocket_port'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
$statusOutput = 'Bot Status: Unknown';
$pid = '';
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

<div class="row column">
<br>
<h1><?php echo "$greeting, $twitchDisplayName <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
<br>
<h2>Seen Users</h2>
<table class="bot-table">
  <thead>
    <tr>
      <th>Username</th>
      <th>Welcome Message</th>
      <th>Status</th>
      <th>Action</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($seenUsersData as $userData): ?>
      <tr>
        <td><?php echo isset($userData['username']) ? htmlspecialchars($userData['username']) : ''; ?></td>
        <td><?php echo isset($userData['welcome_message']) ? htmlspecialchars($userData['welcome_message']) : ''; ?></td>
        <td>
            <span style="color: <?php echo $userData['status'] == 'True' ? 'green' : 'red'; ?>">
                <?php echo isset($userData['status']) ? htmlspecialchars($userData['status']) : ''; ?>
            </span>
        </td>
        <td>
          <label class="switch">
            <i class="fa-solid <?php echo $userData['status'] == 'True' ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
          </label>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php echo $databaseStatus; ?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
</body>
</html>