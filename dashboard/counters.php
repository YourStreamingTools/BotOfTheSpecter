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
$authToken = $access_token;
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include 'sqlite.php';
$countType= '';
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BotOfTheSpecter - Bot Commands</title>
    <link rel="stylesheet" href="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.min.css">
    <link rel="stylesheet" href="https://cdn.yourstreaming.tools/css/custom.css">
    <link rel="stylesheet" href="pagination.css">
    <script src="about.js"></script>
  	<link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
  	<link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
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
      <li class="is-active"><a href="counters.php">Counters</a></li>
      <li><a href="commands.php">Bot Commands</a></li>
      <li><a href="add-commands.php">Add Bot Command</a></li>
      <li><a href="edit_typos.php">Edit Typos</a></li>
      <li><a href="app.php">Download App</a></li>
      <li><a href="profile.php">Profile</a></li>
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
<ul class="tabs" data-tabs id="countTabs">
    <li class="tabs-title <?php echo $countType === 'lurking' ? 'is-active' : ''; ?>"><a href="#lurking">Currently Lurking Users</a></li>
    <li class="tabs-title <?php echo $countType === 'typo' ? 'is-active' : ''; ?>"><a href="#typo">Typo Counts</a></li>
    <li class="tabs-title <?php echo $countType === 'deaths' ? 'is-active' : ''; ?>"><a href="#deaths">Deaths Overview</a></li>
    <li class="tabs-title <?php echo $countType === 'hugs' ? 'is-active' : ''; ?>"><a href="#hugs">Hug Counts</a></li>
    <li class="tabs-title <?php echo $countType === 'kisses' ? 'is-active' : ''; ?>"><a href="#kisses">Kiss Counts</a></li>
</ul>
<div class="tabs-content" data-tabs-content="countTabs">
  <div class="tabs-panel <?php echo $countType === 'lurking' ? 'is-active' : ''; ?>" id="lurking">
    <h3>Currently Lurking Users</h3>
    <pre>
      <table class="counter-table">
        <thead>
          <tr>
            <th>Username</th>
            <th>Lurk Duration</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lurkers as $lurker): $displayName = $usernames[$lurker['user_id']] ?? $lurker['user_id'];?>
            <tr>
              <td id="<?php echo $lurker['user_id']; ?>"><?php echo htmlspecialchars($displayName); ?></td>
              <td id="lurk_duration"><?php echo htmlspecialchars($lurker['lurk_duration']); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </pre>
  </div>
  <div class="tabs-panel <?php echo $countType === 'typo' ? 'is-active' : ''; ?>" id="typo">
    <h3>Typo Counts</h3>
    <pre>
      <table class="counter-table">
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
    </pre>
  </div>
  <div class="tabs-panel <?php echo $countType === 'deaths' ? 'is-active' : ''; ?>" id="deaths">
    <h3>Deaths Overview</h3>
    <pre>
      <table class="counter-table">
        <thead>
          <tr>
            <th>Category</th>
            <th>Count</th>
          </tr>
        </thead>
        <tbody>
          <!-- Total Deaths -->
          <tr>
            <td>Total Deaths</td>
            <td><?php echo htmlspecialchars($totalDeaths['death_count'] ?? '0'); ?></td>
          </tr>
          <!-- Game-Specific Deaths -->
          <?php foreach ($gameDeaths as $gameDeath): ?>
          <tr>
            <td><?php echo htmlspecialchars($gameDeath['game_name']); ?></td>
            <td><?php echo htmlspecialchars($gameDeath['death_count']); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </pre>
  </div>
  <div class="tabs-panel <?php echo $countType === 'hugs' ? 'is-active' : ''; ?>" id="hugs">
    <h3>Hug Counts</h3>
    <pre>
      <table class="counter-table">
        <thead>
          <tr>
            <th>Username</th>
            <th>Hug Count</th>
          </tr>
        </thead>
        <tbody>
          <!-- Total Hugs -->
          <tr>
            <td>Total Hugs</td>
            <td><?php echo htmlspecialchars($totalHugs['total_hug_count'] ?? '0'); ?></td>
          </tr>
          <!-- Username-Specific Hugs -->
          <?php foreach ($hugCounts as $hugCount): ?>
          <tr>
            <td><?php echo htmlspecialchars($hugCount['username']); ?></td>
            <td><?php echo htmlspecialchars($hugCount['hug_count']); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </pre>
  </div>
  <div class="tabs-panel <?php echo $countType === 'kisses' ? 'is-active' : ''; ?>" id="kisses">
    <h3>Kiss Counts</h3>
    <pre>
      <table class="counter-table">
        <thead>
          <tr>
            <th>Username</th>
            <th>Kiss Count</th>
          </tr>
        </thead>
        <tbody>
          <!-- Total Kisses -->
          <tr>
            <td>Total Kisses</td>
            <td><?php echo htmlspecialchars($totalKisses['total_kiss_count'] ?? '0'); ?></td>
          </tr>
          <!-- Username-Specific Kisses -->
          <?php foreach ($kissCounts as $kissCount): ?>
          <tr>
            <td><?php echo htmlspecialchars($kissCount['username']); ?></td>
            <td><?php echo htmlspecialchars($kissCount['kiss_count']); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </pre>
  </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
<script>
    $(document).ready(function() {
        $("#countTabs a").click(function(e) {
            e.preventDefault();
            var countType = $(this).attr('href').replace('#', '');

            // Remove active class from all tabs and panels
            $(".tabs-title").removeClass("is-active");
            $(".tabs-panel").removeClass("is-active");

            // Add active class to clicked tab and corresponding panel
            $(this).parent().addClass("is-active");
            $("#" + countType).addClass("is-active");
        });
    });
</script>
</body>
</html>