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
$stmt = $conn->prepare("SELECT * FROM users WHERE access_token = ?");
$stmt->bind_param("s", $access_token);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$user_id = $user['id'];
$username = $user['username'];
$broadcasterID = $user['twitch_user_id'];
$accessToken = $access_token;
$twitchDisplayName = $user['twitch_display_name'];
$twitch_profile_image_url = $user['profile_image'];
$is_admin = ($user['is_admin'] == 1);
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';

// API endpoint to fetch followers
$allFollowers = [];
$showDisclaimer = true;
if (isset($_GET['load']) && $_GET['load'] == 'followers') {
  $showDisclaimer = false;
  // API endpoint to fetch followers
  $followersURL = "https://api.twitch.tv/helix/channels/followers?broadcaster_id=$broadcasterID";
  $clientID = ''; // CHANGE TO MAKE THIS WORK

  $allFollowers = [];
  $liveData = "";
  $cacheExpiration = 3600; // Cache expires after 1 hour
  $cacheDirectory = "cache/$username";
  $cacheFile = "$cacheDirectory/allFollowers.json";
  if (!is_dir($cacheDirectory)) {
    mkdir($cacheDirectory, 0755, true);
  }
  if (file_exists($cacheFile) && time() - filemtime($cacheFile) < $cacheExpiration) {
    $allFollowers = json_decode(file_get_contents($cacheFile), true);
    $cacheTime = filemtime($cacheFile);
    $currentTime = time();
    $timeDifference = round(($currentTime - $cacheTime) / 60);
    $liveData = "Follower results are cached up to 1 hour. (Cache updated: $timeDifference minutes ago)";
  } else {
    do {
        // Set up cURL request with headers
        $curl = curl_init($followersURL);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Client-ID: ' . $clientID
        ]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        // Execute cURL request
        $response = curl_exec($curl);

        if ($response === false) {
            // Handle cURL error
            $errorInfo = curl_getinfo($curl);
            $errorMessage = 'cURL error: ' . curl_error($curl);
            $errorDetails = 'URL: ' . $errorInfo['url'] . ' | HTTP Code: ' . $errorInfo['http_code'];
        
            // Log the error to a file for debugging
            error_log($errorMessage . ' | ' . $errorDetails, 3, 'curl_errors.log');
        
            echo 'An error occurred while fetching data. Please try again later.';
            exit;
        }

        if ($response === false) {
            // Handle cURL error
            echo 'cURL error: ' . curl_error($curl);
            exit;
        }

        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($httpCode !== 200) {
            // Handle non-successful HTTP response
            $HTTPError = 'HTTP error: ' . $httpCode;
            echo "$HTTPError";
            exit;
        }

        curl_close($curl);

        // Process and append follower information to the array
        $followersData = json_decode($response, true);
        $allFollowers = array_merge($allFollowers, $followersData['data']);

        // Save the data to the cache file
        file_put_contents($cacheFile, json_encode($allFollowers));
        $liveData = "Follower results have been cached, you're viewing live data.";

        // Check if there are more pages of followers
        $cursor = $followersData['pagination']['cursor'] ?? null;
        $followersURL = "https://api.twitch.tv/helix/channels/followers?broadcaster_id=$broadcasterID&after=$cursor";

    } while ($cursor);
  }
}

// Number of followers per page
$followersPerPage = 50;

// Calculate the total number of pages
$totalPages = ceil(count($allFollowers) / $followersPerPage);

// Current page (default to 1 if not specified)
$currentPage = isset($_GET['page']) ? max(1, min($totalPages, intval($_GET['page']))) : 1;

// Calculate the start and end index for the current page
$startIndex = ($currentPage - 1) * $followersPerPage;
$endIndex = $startIndex + $followersPerPage;

// Get followers for the current page
$followersForCurrentPage = array_slice($allFollowers, $startIndex, $followersPerPage);
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BotOfTheSpecter - Twitch Followers</title>
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
          <li class="is-active"><a href="followers.php">View Followers</a></li>
          <li><a href="subscribers.php">View Subscribers</a></li>
          <li><a href="vips.php">View VIPs</a></li>
        </ul>
      </li>
      <li><a href="logs.php">View Logs</a></li>
      <li><a href="counters.php">Counters</a></li>
      <li><a href="commands.php">Bot Commands</a></li>
      <li><a href="add-commands.php">Add Bot Command</a></li>
      <li><a href="edit_typos.php">Edit Typos</a></li>
      <li><a href="app.php">Download App</a></li>
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
<?php if ($showDisclaimer): ?>
<!-- Disclaimer and Button -->
<div class="row column text-center">
    <p>Disclaimer: Due to the time it takes to pull followers from Twitch, if you'd like to view all your followers, please click the button below.</p>
    <a href="?load=followers" class="button large">View Followers</a>
</div>

<?php endif; ?>
<!-- Followers Content Container (initially hidden) -->
<div id="followers-content" <?php if (!isset($_GET['load'])) echo 'style="display: none;"'; ?>>
    <?php if (isset($_GET['load']) && $_GET['load'] == 'followers'): ?>
    <h1>Your Followers:</h1>
    <h3><?php echo $liveData ?></h3>
    <div class="followers-grid">
        <?php foreach ($followersForCurrentPage as $follower) : 
            $followerDisplayName = $follower['user_name'];
        ?>
        <div class="follower">
            <span><?php echo $followerDisplayName; ?></span>
            <span class="follow-time">
              <?php echo date('d F Y', strtotime($follower['followed_at'])); ?><br>
              <?php echo date('H:i', strtotime($follower['followed_at'])); ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Pagination -->
    <div class="pagination">
      <?php if ($totalPages > 1) : ?>
          <?php for ($page = 1; $page <= $totalPages; $page++) : ?>
              <?php if ($page === $currentPage) : ?>
                  <span class="current-page"><?php echo $page; ?></span>
              <?php else : ?>
                  <a href="followers.php?load=followers&page=<?php echo $page; ?>"><?php echo $page; ?></a>
              <?php endif; ?>
          <?php endfor; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<br>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('view-followers-btn');
    btn.addEventListener('click', function() {
        // Hide the disclaimer div
        var disclaimerDiv = document.querySelector('.disclaimer'); // Select the disclaimer div
        disclaimerDiv.style.display = 'none'; // Hide it

        // Redirect to the same page with a 'load' parameter to trigger PHP loading
        window.location.href = '?load=followers';
    });
});
</script>
</body>
</html>