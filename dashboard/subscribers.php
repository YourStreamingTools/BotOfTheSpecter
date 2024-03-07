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
$twitchDisplayName = $user['twitch_display_name'];
$twitch_profile_image_url = $user['profile_image'];
$is_admin = ($user['is_admin'] == 1);
$accessToken = $access_token;
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';

// API endpoint to fetch subscribers
$subscribersURL = "https://api.twitch.tv/helix/subscriptions?broadcaster_id=$broadcasterID";
$clientID = ''; // CHANGE TO MAKE THIS WORK

$allSubscribers = [];
do {
    // Set up cURL request with headers
    $curl = curl_init($subscribersURL);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Client-ID: ' . $clientID
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

    // Execute cURL request
    $response = curl_exec($curl);

    if ($response === false) {
        // Handle cURL error
        echo 'cURL error: ' . curl_error($curl);
        exit;
    }

    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200) {
        // Handle non-successful HTTP response
        $HTTPError = 'HTTP error: ' . $httpCode;
        exit;
    }

    curl_close($curl);

    // Process and append subscriber information to the array
    $subscribersData = json_decode($response, true);
    $allSubscribers = array_merge($allSubscribers, $subscribersData['data']);

    // Check if there are more pages of subscribers
    $cursor = $subscribersData['pagination']['cursor'] ?? null;
    $subscribersURL = "https://api.twitch.tv/helix/subscriptions?broadcaster_id=$broadcasterID&after=$cursor";

} while ($cursor);

// Number of subscribers per page
$subscribersPerPage = 50;

// Calculate the total number of pages
$totalPages = ceil(count($allSubscribers) / $subscribersPerPage);

// Current page (default to 1 if not specified)
$currentPage = isset($_GET['page']) ? max(1, min($totalPages, intval($_GET['page']))) : 1;

// Calculate the start and end index for the current page
$startIndex = ($currentPage - 1) * $subscribersPerPage;
$endIndex = $startIndex + $subscribersPerPage;

// Get subscribers for the current page
$subscribersForCurrentPage = array_slice($allSubscribers, $startIndex, $subscribersPerPage);
$displaySearchBar = count($allSubscribers) > $subscribersPerPage;
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BotOfTheSpecter - Twitch Subscribers</title>
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
          <li class="is-active"><a href="subscribers.php">View Subscribers</a></li>
          <li><a href="vips.php">View VIPs</a></li>
        </ul>
      </li>
      <li><a href="logs.php">View Logs</a></li>
      <li><a href="counters.php">Counters</a></li>
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
  <?php if ($displaySearchBar) : ?>
    <div class="row column">
        <div class="search-container">
            <input type="text" id="subscriber-search" placeholder="Search for Subscribers...">
        </div>
    </div>
  <?php endif; ?>
  <h1>Your Subscribers:</h1>
  <div class="subscribers-grid">
    <?php
    // Define a custom sorting function to sort by subscription tier in descending order
    usort($subscribersForCurrentPage, function ($a, $b) {
        // Subscription tiers in descending order (Tier 3, Tier 2, Tier 1)
        $tierOrder = ['3000', '2000', '1000'];
        
        // Get the tier values for $a and $b
        $tierA = $a['tier'];
        $tierB = $b['tier'];

        // Compare the positions of the tiers in the order defined
        $indexA = array_search($tierA, $tierOrder);
        $indexB = array_search($tierB, $tierOrder);

        // Compare the positions and return the comparison result
        return $indexA - $indexB;
    });

    // Loop through the sorted array
    foreach ($subscribersForCurrentPage as $subscriber) :
        $subscriberDisplayName = $subscriber['user_name'];
        $isGift = $subscriber['is_gift'] ?? false;
        $gifterName = $subscriber['gifter_name'] ?? '';
        $subscriptionTier = '';

        // Determine the subscription tier based on the subscription plan ID
        $subscriptionPlanId = $subscriber['tier'];
        if ($subscriptionPlanId == '1000') {
            $subscriptionTier = '1';
        } elseif ($subscriptionPlanId == '2000') {
            $subscriptionTier = '2';
        } elseif ($subscriptionPlanId == '3000') {
            $subscriptionTier = '3';
        } else {
            $subscriptionTier = '<font color="red">Unknown</font>';
        }

        // Check if $username is the same as $subscriberDisplayName
        if ($twitchDisplayName == $subscriberDisplayName) {
            echo "<div class='subscriber-broadcaster'><span>$subscriberDisplayName</span><span>Subscription Tier: $subscriptionTier</span></div>
            ";
        } else {
            // Check if it's a gift subscription
            if ($isGift) {
                echo "<div class='subscriber'><span>$subscriberDisplayName</span><span>Subscription Tier: $subscriptionTier</span><span>Gift Sub from $gifterName</span></div>
                ";
            // else show everything else as not gift subscription
            } else {
                echo "<div class='subscriber'><span>$subscriberDisplayName</span><span>Subscription Tier: $subscriptionTier</span></div>
                ";
            }
        }
    endforeach;
    ?>
  </div>

  <!-- Pagination -->
  <div class="pagination">
      <?php if ($totalPages > 1) : ?>
          <?php for ($page = 1; $page <= $totalPages; $page++) : ?>
              <?php if ($page === $currentPage) : ?>
                  <span class="current-page"><?php echo $page; ?></span>
              <?php else : ?>
                  <a href="?page=<?php echo $page; ?>"><?php echo $page; ?></a>
              <?php endif; ?>
          <?php endfor; ?>
      <?php endif; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
<script>
$(document).ready(function() {
    <?php if ($displaySearchBar) : ?>
    $('#subscriber-search').on('input', function() {
        var searchTerm = $(this).val().toLowerCase();
        $('.subscriber').each(function() {
            var subscriberName = $(this).find('span').text().toLowerCase();
            if (subscriberName.includes(searchTerm)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });
    <?php endif; ?>
});
</script>
</body>
</html>