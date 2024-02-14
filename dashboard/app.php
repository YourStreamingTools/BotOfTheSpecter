<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL); ?>
<?php
// Initialize the session
session_start();

// check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

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
$webhookPort = $user['webhook_port'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
require_once "Parsedown.php";
$markdownParser = new Parsedown();
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BotOfTheSpecter - Download App</title>
    <link rel="stylesheet" href="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.min.css">
    <link rel="stylesheet" href="https://cdn.yourstreaming.tools/css/custom.css">
    <link rel="stylesheet" href="pagination.css">
    <script src="about.js"></script>
  	<link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
  	<link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/markdown-it/11.0.0/markdown-it.min.js"></script>
    <!-- <?php echo "User: $username | $twitchUserId | $authToken | Port: $webhookPort"; ?> -->
  </head>
<body>
<!-- Navigation -->
<div class="title-bar" data-responsive-toggle="mobile-menu" data-hide-for="medium">
  <button class="menu-icon" type="button" data-toggle="mobile-menu"></button>
  <div class="title-bar-title">Menu</div>
</div>
<nav class="top-bar stacked-for-medium" id="mobile-menu">
  <div class="top-bar-left">
    <ul class="dropdown vertical medium-horizontal menu" data-responsive-menu="drilldown medium-dropdown hinge-in-from-top hinge-out-from-top">
      <li class="menu-text">BotOfTheSpecter</li>
      <li><a href="bot.php">Dashboard</a></li>
      <li>
        <a>Twitch Data</a>
        <ul class="vertical menu" data-dropdown-menu>
          <li><a href="mods.php">View Mods</a></li>
          <li><a href="followers.php">View Followers</a></li>
          <li><a href="subscribers.php">View Subscribers</a></li>
          <li><a href="vips.php">View VIPs</a></li>
        </ul>
      </li>
      <li><a href="logs.php">View Logs</a></li>
      <li><a href="counters.php">Counters</a></li>
      <li><a href="commands.php">Bot Commands</a></li>
      <li><a href="add-commands.php">Add Bot Command</a></li>
      <li><a href="edit_typos.php">Edit Typos</a></li>
      <li class="is-active"><a href="app.php">Download App</a></li>
      <li><a href="logout.php">Logout</a></li>
    </ul>
  </div>
  <div class="top-bar-right">
    <ul class="menu">
      <li><a class="popup-link" onclick="showPopup()">&copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter. All rights reserved.</a></li>
    </ul>
  </div>
</nav>
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