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
$title = "Download App";

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
require_once "Parsedown.php";
$markdownParser = new Parsedown();
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
<?php
  $directory = '/var/www/api/app';
  $files = scandir($directory);
  rsort($files);
  
  function compareVersions($a, $b) {
    // Extract version numbers without "BotOfTheSpecter-" and ".exe" and split them by '.'
    $versionA = explode('.', preg_replace('/^BotOfTheSpecter-|\.exe$/', '', $a));
    $versionB = explode('.', preg_replace('/^BotOfTheSpecter-|\.exe$/', '', $b));

    // Compare each segment of the version numbers
    for ($i = 0; $i < min(count($versionA), count($versionB)); $i++) {
      $numA = intval($versionA[$i]);
      $numB = intval($versionB[$i]);

      if ($numA !== $numB) {
        return ($numB - $numA);
      }
    }
    return count($versionB) - count($versionA);
  }

  // Sort the files using the custom sorting function
  usort($files, 'compareVersions');

  // Loop through sorted files
  foreach ($files as $file) {
    if (strpos($file, 'BotOfTheSpecter-') === 0 && pathinfo($file, PATHINFO_EXTENSION) === 'exe') {
      $version = preg_replace('/^BotOfTheSpecter-|\.exe$/', '', $file);
      $changelogFile = "$directory/changelog.$version.txt";
    
      // Read the changelog content
      $changelog = file_exists($changelogFile) ? file_get_contents($changelogFile) : 'No changelog available';
    
      // Convert the Markdown to HTML using Parsedown
      $changelogHTML = $markdownParser->text($changelog);
    
      echo "<div class='download-item'><h3>Version $version</h3><p>Changelog:</p><div class='changelog'>$changelogHTML</div><br><a href='https://api.botofthespecter.com/app/$file' download='BotOfTheSpecter.exe' class='download-button'>Download</a></div>";
    }
  }
?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
</body>
</html>