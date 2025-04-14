<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Twitch Data - VIPs";

// Include all the information
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'user_db.php';
include "mod_access.php";
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);

// API endpoint to fetch VIPs of the channel
$vipsURL = "https://api.twitch.tv/helix/channels/vips?broadcaster_id=$broadcasterID";
$clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';
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
      $addVIP = "https://api.twitch.tv/helix/channels/vips?broadcaster_id=$broadcasterID&user_id=$userID";
      $headers = [
          "Client-ID: $clientID",
          'Authorization: Bearer ' . $authToken,
          "Content-Type: application/json"
      ];
      // Initialize cURL session
      $ch = curl_init();
      // Set cURL options for adding or removing VIP
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      if ($action === 'add') {
          curl_setopt($ch, CURLOPT_URL, $addVIP);
          curl_setopt($ch, CURLOPT_POST, true);
      } elseif ($action === 'remove') {
          curl_setopt($ch, CURLOPT_URL, $addVIP);
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
    <!-- Header -->
    <?php include('header.php'); ?>
    <!-- /Header -->
  </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
  <br>
  <?php if ($displaySearchBar) : ?>
    <div class="field">
        <div class="control">
            <input class="input" type="text" id="vip-search" placeholder="Search for VIPs...">
        </div>
    </div>
  <?php endif; ?>
  <div class="box">
    <form method="POST">
      <h4 class="title is-4">Add or Remove a user from your VIP list:</h4>
      <div class="field">
        <label class="label" for="vip-username">Username:</label>
        <div class="control">
          <input class="input" type="text" id="vip-username" name="vip-username" required>
        </div>
      </div>
      <div class="field">
        <div class="control">
          <button class="button is-success" type="submit" name="action" value="add">Add VIP</button>
          <button class="button is-danger" type="submit" name="action" value="remove">Remove VIP</button>
        </div>
      </div>
    </form>
    <?php echo $VIPUserStatus; ?>
  </div>
  <h1 class="title is-4">Your VIPs:</h1>
  <div class="columns is-multiline is-centered">
        <?php foreach ($VIPsForCurrentPage as $vip) : 
            $vipDisplayName = $vip['user_name'];
        ?>
        <div class="column is-one-quarter">
            <div class="box centered-box"><?php echo $vipDisplayName; ?></div>
        </div>
        <?php endforeach; ?>
  </div>

  <!-- Pagination -->
  <nav class="pagination is-centered" role="navigation" aria-label="pagination">
      <?php if ($totalPages > 1) : ?>
          <?php for ($page = 1; $page <= $totalPages; $page++) : ?>
              <?php if ($page === $currentPage) : ?>
                  <span class="pagination-link is-current"><?php echo $page; ?></span>
              <?php else : ?>
                  <a class="pagination-link" href="?page=<?php echo $page; ?>"><?php echo $page; ?></a>
              <?php endif; ?>
          <?php endfor; ?>
      <?php endif; ?>
  </nav>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script>
$(document).ready(function() {
    <?php if ($displaySearchBar) : ?>
    $('#vip-search').on('input', function() {
        var searchTerm = $(this).val().toLowerCase();
        $('.column .box span').each(function() {
            var vipName = $(this).text().toLowerCase();
            if (vipName.includes(searchTerm)) {
                $(this).closest('.column').show();
            } else {
                $(this).closest('.column').hide();
            }
        });
    });
    <?php endif; ?>
});
</script>
</body>
</html>