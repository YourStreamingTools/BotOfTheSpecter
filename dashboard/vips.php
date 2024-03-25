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
$title = "Twitch VIPs";

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
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$webhookPort = $user['webhook_port'];
$websocketPort = $user['websocket_port'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';

// API endpoint to fetch VIPs of the channel
$vipsURL = "https://api.twitch.tv/helix/channels/vips?broadcaster_id=$broadcasterID";
$clientID = ''; // CHANGE TO MAKE THIS WORK

$allVIPs = [];
$VIPUserStatus="";
do {
    // Set up cURL request with headers
    $curl = curl_init($vipsURL);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $authToken,
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

    // Process and append VIP information to the array
    $vipsData = json_decode($response, true);
    $allVIPs = array_merge($allVIPs, $vipsData['data']);

    // Check if there are more pages of VIPs
    $cursor = $vipsData['pagination']['cursor'] ?? null;
    $vipsURL = "https://api.twitch.tv/helix/channels/vips?broadcaster_id=$broadcasterID&after=$cursor";

} while ($cursor);

// Number of VIPs per page
$vipsPerPage = 50;

// Calculate the total number of pages
$totalPages = ceil(count($allVIPs) / $vipsPerPage);

// Current page (default to 1 if not specified)
$currentPage = isset($_GET['page']) ? max(1, min($totalPages, intval($_GET['page']))) : 1;

// Calculate the start and end index for the current page
$startIndex = ($currentPage - 1) * $vipsPerPage;
$endIndex = $startIndex + $vipsPerPage;

// Get VIPs for the current page
$VIPsForCurrentPage = array_slice($allVIPs, $startIndex, $vipsPerPage);
$displaySearchBar = count($allVIPs) > $vipsPerPage;

// Check if the form has been submitted for adding or removing VIPs from the list
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Extract the username and action from the form submission
  $VIPusername = trim($_POST['vip-username']);
  $action = $_POST['action'];

  // Fetch the user ID using the external API
  $userID = file_get_contents("https://decapi.me/twitch/id/$VIPusername");

  if ($userID) {
      // Set up the Twitch API endpoint and headers
      $url = "https://api.twitch.tv/helix/channels/vips?broadcaster_id=$broadcasterID&user_id=$userID";
      $headers = [
          "Client-ID: $clientID",
          "Authorization: Bearer $accessToken",
          "Content-Type: application/json"
      ];

      // Initialize cURL session
      $ch = curl_init();

      // Set cURL options for adding or removing VIP
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

      if ($action === 'add') {
          curl_setopt($ch, CURLOPT_URL, $url);
          curl_setopt($ch, CURLOPT_POST, true);
      } elseif ($action === 'remove') {
          curl_setopt($ch, CURLOPT_URL, $url);
          curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
      }

      // Execute the API request
      $response = curl_exec($ch);
      $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      // Handle API response
      if ($httpcode == 204) {
        $VIPUserStatus = "Operation successful: User '$VIPusername' has been $action" . "ed as a VIP.";
      } else {
        $VIPUserStatus = "Operation failed: Unable to $action user '$VIPusername' as a VIP. Response code: $httpcode";
      }
  } else {
    $VIPUserStatus = "Could not retrieve user ID for username: $VIPusername";
  }
}
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
  <?php if ($displaySearchBar) : ?>
    <div class="row column">
        <div class="search-container">
            <input type="text" id="vip-search" placeholder="Search for VIPs...">
        </div>
    </div>
  <?php endif; ?>
  <div class="vip-form-group"><form method="POST"><h4>Add or Remove a user from your VIP list:<br>
    <div class="vip-form-group"><label for="username">Username:</label><input type="text" id="vip-username" name="vip-username" required></div><div class="vip-form-group"><button type="submit" name="action" value="add">Add VIP</button>&nbsp;&nbsp;&nbsp;<button type="submit" name="action" value="remove">Remove VIP</button></div></h4></form>
    <?php echo $VIPUserStatus; ?>
  </div>
  <h1>Your VIPs:</h1>
  <div class="vip-grid">
        <?php foreach ($VIPsForCurrentPage as $vip) : 
            $vipDisplayName = $vip['user_name'];
        ?>
        <div class="vip">
            <span><?php echo $vipDisplayName; ?></span>
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
    $('#vip-search').on('input', function() {
        var searchTerm = $(this).val().toLowerCase();
        $('.vip').each(function() {
            var vipName = $(this).find('span').text().toLowerCase();
            if (vipName.includes(searchTerm)) {
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