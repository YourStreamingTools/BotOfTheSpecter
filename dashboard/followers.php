<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Twitch Followers";

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
$twitchDisplayName = $user['twitch_display_name'];
$twitch_profile_image_url = $user['profile_image'];
$is_admin = ($user['is_admin'] == 1);
$betaAccess = ($user['beta_access'] == 1);
$twitchUserId = $user['twitch_user_id'];
$broadcasterID = $twitchUserId;
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$api_key = $user['api_key'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';

// Handle AJAX request to load followers
if (isset($_GET['load']) && $_GET['load'] == 'followers') {
  header('Content-Type: application/json'); // Ensure the output is JSON
  // Fetch existing followers from the database, sorted by newest to oldest
  $stmt = $db->prepare("SELECT user_id, user_name, followed_at FROM followers_data ORDER BY followed_at DESC");
  $stmt->execute();
  $existingFollowers = $stmt->fetchAll(PDO::FETCH_ASSOC);
  // Check for updates from Twitch API and update the database accordingly
  $followersURL = "https://api.twitch.tv/helix/channels/followers?broadcaster_id=$broadcasterID&first=100";
  $clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';
  $apiFollowers = [];
  do {
      $response = fetchFollowers($followersURL, $authToken, $clientID);
      if ($response === false) {
        echo json_encode(["status" => "error", "message" => "Failed to fetch followers"]);
        exit();
      }
      $followerData = json_decode($response, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(["status" => "error", "message" => "Error decoding JSON response"]);
        exit();
      }
      foreach ($followerData["data"] as $follower) {
        $apiFollowers[] = $follower["user_id"];
        $followedAt = date('Y-m-d H:i:s', strtotime($follower["followed_at"]));
        // Check if the follower exists in the database
        $stmt = $db->prepare("SELECT COUNT(*) FROM followers_data WHERE user_id = :user_id");
        $stmt->execute([":user_id" => $follower["user_id"]]);
        $exists = $stmt->fetchColumn();
        if (!$exists) {
          // Insert new follower into the database
          $insertStmt = $db->prepare("INSERT INTO followers_data (user_id, user_name, followed_at) VALUES (:user_id, :user_name, :followed_at)");
          $insertStmt->execute([
            ":user_id" => $follower["user_id"],
            ":user_name" => $follower["user_name"],
            ":followed_at" => $followedAt
          ]);
        }
      }
      $cursor = $followerData["pagination"]["cursor"] ?? null;
      if ($cursor) {
        $followersURL = "https://api.twitch.tv/helix/channels/followers?broadcaster_id=$broadcasterID&first=100&after=$cursor";
      }
  } while ($cursor);
  // Delete followers from the database if they are no longer in the Twitch API response
  foreach ($existingFollowers as $existingFollower) {
    if (!in_array($existingFollower['user_id'], $apiFollowers)) {
      $deleteStmt = $db->prepare("DELETE FROM followers_data WHERE user_id = :user_id");
      $deleteStmt->execute([":user_id" => $existingFollower['user_id']]);
    }
  }
  // Fetch the updated list of followers from the database
  $stmt = $db->prepare("SELECT user_id, user_name, followed_at FROM followers_data ORDER BY followed_at DESC");
  $stmt->execute();
  $updatedFollowers = $stmt->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode(["status" => "success", "data" => $updatedFollowers]);
  exit();
}

// Function to fetch followers with error handling
function fetchFollowers($url, $authToken, $clientID) {
  $curl = curl_init($url);
  curl_setopt($curl, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $authToken,
    'Client-ID: ' . $clientID
  ]);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  $response = curl_exec($curl);
  if ($response === false) {
    // Handle cURL error
    $errorMessage = 'cURL error: ' . curl_error($curl);
    $errorDetails = 'URL: ' . curl_getinfo($curl, CURLINFO_EFFECTIVE_URL) . ' | HTTP Code: ' . curl_getinfo($curl, CURLINFO_HTTP_CODE);
    error_log($errorMessage . ' | ' . $errorDetails, 3, 'curl_errors.log');
    curl_close($curl);
    return false;
  }
  curl_close($curl);
  return $response;
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <!-- Header -->
    <?php include('header.php'); ?>
    <style>
      /* Fade-in animation */
      .follower-box {
        opacity: 0;
        transform: translateY(10px);
        transition: opacity 0.3s ease-in-out, transform 0.3s ease-in-out;
      }
      .follower-box.visible {
        opacity: 1;
        transform: translateY(0);
      }
    </style>
    <!-- /Header -->
  </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="container">
  <h1 class="title"><?php echo "$greeting, $twitchDisplayName <img id='profile-image' class='round-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
  <br>
  <!-- Followers Content Container -->
  <div id="followers-content">
    <h1 class="title is-4">Your Followers:</h1>
    <h3 id="live-data">Loading new followers...</h3><br>
    <div id="followers-list" class="columns is-multiline">
      <!-- AJAX appended followers -->
    </div>
  </div>
  <br>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script>
$(document).ready(function() {
  // Automatically fetch followers when the page loads
  function fetchNewFollowers() {
    $.ajax({
      url: window.location.href,
      method: 'GET',
      data: { load: 'followers' },
      dataType: 'json',
      success: function(response) {
        if (response.status === 'success') {
          // Prepend new followers to the top of the list
          response.data.forEach(function(follower, index) {
            setTimeout(function() {
              var followerHTML = `
                <div class="column is-one-third follower-box">
                  <div class="box">
                    <span>${follower.user_name}</span><br>
                    ${new Date(follower.followed_at).toLocaleDateString()}<br>
                    ${new Date(follower.followed_at).toLocaleTimeString()}
                  </div>
                </div>
              `;
              var $followerElement = $(followerHTML);
              $('#followers-list').append($followerElement);
              setTimeout(function() {
                $followerElement.addClass('visible');
              }, 10);
            }, index * 50);
          });
          $('#live-data').text("");
        } else {
          $('#live-data').text("Failed to load followers.");
        }
      },
      error: function(xhr, status, error) {
        console.error('AJAX Error: ' + error);
        $('#live-data').text("Failed to load followers.");
      }
    });
  }
  // Trigger the AJAX request on page load
  fetchNewFollowers();
  // Check for new followers every 5 minutes
  setInterval(fetchNewFollowers, 300000);
});
</script>
</body>
</html>