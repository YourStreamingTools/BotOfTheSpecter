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
$authToken = $access_token;
$user_timezone = $user['timezone'];

if (!$user_timezone || !in_array($user_timezone, timezone_identifiers_list())) {
    $user_timezone = 'Etc/UTC';
}

date_default_timezone_set($user_timezone);

// Determine the greeting based on the user's local time
$currentHour = date('G');
$greeting = '';

if ($currentHour < 12) {
    $greeting = "Good morning";
} else {
    $greeting = "Good afternoon";
}

try {
  $db = new PDO("sqlite:/var/www/bot/commands/{$username}_commands.db");
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Fetch all custom commands
  $getCommands = $db->query("SELECT * FROM custom_commands");
  $commands = $getCommands->fetchAll(PDO::FETCH_ASSOC);
  // Fetch typo counts
  $getTypos = $db->query("SELECT * FROM user_typos");
  $typos = $getTypos->fetchAll(PDO::FETCH_ASSOC);
  // Fetch user IDs from your database
  $getLurkers = $db->query("SELECT user_id FROM lurk_times");
  $lurkers = $getLurkers->fetchAll(PDO::FETCH_COLUMN, 0);

  // Prepare the Twitch API request
  $userIdParams = implode('&id=', $lurkers);
  $twitchApiUrl = "https://api.twitch.tv/helix/users?id=" . $userIdParams;
  $clientID = ''; // CHANGE TO MAKE THIS WORK
  $headers = [
      "Client-ID: $clientID",
      "Authorization: Bearer $authToken",
  ];

  // Execute the Twitch API request
  $ch = curl_init($twitchApiUrl);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $response = curl_exec($ch);
  curl_close($ch);

  // Decode the JSON response
  $userData = json_decode($response, true);

  // Map user IDs to usernames
  $usernames = [];
  foreach ($userData['data'] as $user) {
      $usernames[$user['id']] = $user['display_name'];
  }
} catch (PDOException $e) {
  echo 'Error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BotOfTheSpecter - Bot Commands</title>
    <link rel="stylesheet" href="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.min.css">
    <link rel="stylesheet" href="https://cdn.yourstreaming.tools/css/custom.css">
    <script src="https://cdn.yourstreaming.tools/js/about.js"></script>
  	<link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
  	<link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <!-- <?php echo "User: $username | $twitchUserId | $authToken"; ?> -->
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
      <li><a href="mods.php">View Mods</a></li>
      <li><a href="followers.php">View Followers</a></li>
      <li><a href="subscribers.php">View Subscribers</a></li>
      <li><a href="vips.php">View VIPs</a></li>
      <li><a href="logs.php">View Logs</a></li>
      <li class="is-active"><a href="commands.php">Bot Commands</a></li>
      <li><a href="add-commands.php">Add Bot Command</a></li>
      <li><a href="logout.php">Logout</a></li>
    </ul>
  </div>
  <div class="top-bar-right">
    <ul class="menu">
      <li><a class="popup-link" onclick="showPopup()">&copy; 2023 BotOfTheSpecter. All rights reserved.</a></li>
    </ul>
  </div>
</nav>
<!-- /Navigation -->

<div class="row column">
<br>
<h1><?php echo "$greeting, <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>$twitchDisplayName!"; ?></h1>
<div class="row">
  <div class="small-12 medium-6 columns">
    <h4>Bot Commands</h4>
    <table>
      <thead>
        <tr>
          <th>Command</th>
          <th>Response</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($commands as $command): ?>
          <tr>
            <td><?php echo $command['command']; ?></td>
            <td><?php echo $command['response']; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="small-12 medium-6 columns">
    <h4>Typo Counts</h4>
    <table>
      <thead>
        <tr>
          <th>Username</th>
          <th>Typo Count</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($typos as $typo): ?>
          <tr>
            <td><?php echo htmlspecialchars($typo['username']); ?></td>
            <td><?php echo htmlspecialchars($typo['typo_count']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="small-12 medium-6 columns">
    <h4>Currently Lurking Users</h4>
    <table>
      <thead>
        <tr>
          <th>User ID</th>
          <th>Start Time</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lurkers as $lurker): ?>
          <tr>
            <td><?php echo htmlspecialchars($lurker['user_id']); ?></td>
            <td><?php echo htmlspecialchars($lurker['start_time']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
</body>
</html>