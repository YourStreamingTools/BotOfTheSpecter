<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page title
$title = "Bot Counters";

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

try {
  // Fetch lurkers
  $getLurkers = $db->query("SELECT user_id, start_time FROM lurk_times");
  $lurkers = $getLurkers->fetchAll(PDO::FETCH_ASSOC);
  // Calculate lurk durations for each user
  foreach ($lurkers as $key => $lurker) {
    $startTime = new DateTime($lurker['start_time']);
    $currentTime = new DateTime();
    $interval = $currentTime->diff($startTime);
    // Calculate total duration in seconds for sorting
    $totalDuration = ($interval->y * 365 * 24 * 3600) + 
                     ($interval->m * 30 * 24 * 3600) + 
                     ($interval->d * 24 * 3600) + 
                     ($interval->h * 3600) + 
                     ($interval->i * 60);
    $lurkers[$key]['total_duration'] = $totalDuration; // Store for sorting
    $timeStringParts = [];
    if ($interval->y > 0) {
      $timeStringParts[] = "{$interval->y} year(s)";
    }
    if ($interval->m > 0) {
      $timeStringParts[] = "{$interval->m} month(s)";
    }
    if ($interval->d > 0) {
      $timeStringParts[] = "{$interval->d} day(s)";
    }
    if ($interval->h > 0) {
      $timeStringParts[] = "{$interval->h} hour(s)";
    }
    if ($interval->i > 0) {
      $timeStringParts[] = "{$interval->i} minute(s)";
    }
    $lurkers[$key]['lurk_duration'] = implode(', ', $timeStringParts);
  }
  // Sort the lurkers array by total_duration (longest to shortest)
  usort($lurkers, function ($a, $b) {
    return $b['total_duration'] - $a['total_duration'];
  });
} catch (PDOException $e) {
  echo 'Error: ' . $e->getMessage();
}

// Prepare the Twitch API request for user data
$userIds = array_column($lurkers, 'user_id');
$userIdParams = implode('&id=', $userIds);
$twitchApiUrl = "https://api.twitch.tv/helix/users?id=" . $userIdParams;
$clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';
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

// Check if data exists and is not null
if (isset($userData['data']) && is_array($userData['data'])) {
  // Map user IDs to usernames
  $usernames = [];
  foreach ($userData['data'] as $user) {
      $usernames[$user['id']] = $user['display_name'];
  }
  // Map the Twitch usernames to the lurkers based on their user_id
  foreach ($lurkers as $key => $lurker) {
    if (isset($usernames[$lurker['user_id']])) {
      $lurkers[$key]['username'] = $usernames[$lurker['user_id']];
    } else {
      $lurkers[$key]['username'] = 'Unknown'; // Fallback if username not found
    }
  }
} else {
  $usernames = [];
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
  <h1 class="title">
    <?php echo "$greeting, " . htmlspecialchars($twitchDisplayName) . " 
    <img id='profile-image' class='round-image is-rounded' style='width: 64px; height: 64px; margin-left: 10px; vertical-align: middle;' 
    src='" . htmlspecialchars($twitch_profile_image_url) . "' 
    alt='" . htmlspecialchars($twitchDisplayName) . " Profile Image'>"; ?>
  </h1>
  <br>
  <div class="buttons">
    <button class="button is-info" onclick="loadData('lurkers')">Lurkers</button>
    <button class="button is-info" onclick="loadData('typos')">Typo Counts</button>
    <button class="button is-info" onclick="loadData('deaths')">Deaths Overview</button>
    <button class="button is-info" onclick="loadData('hugs')">Hug Counts</button>
    <button class="button is-info" onclick="loadData('kisses')">Kiss Counts</button>
    <button class="button is-info" onclick="loadData('custom')">Custom Counts</button>
  </div>
  <div class="content">
    <div class="box">
      <h3 id="table-title" class="title" style="color: white;">Currently Lurking Users</h3>
      <table class="table is-striped is-fullwidth" style="table-layout: fixed; width: 100%;">
        <thead>
          <tr>
            <th id="info-column-data" style="color: white; width: 50%;"></th>
            <th id="data-column-info" style="color: white; width: 50%;"></th>
          </tr>
        </thead>
        <tbody id="table-body">
          <!-- Content will be dynamically injected here -->
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
  function loadData(type) {
    let data;
    let title;
    let dataColumn;
    let infoColumn;
    switch(type) {
      case 'lurkers':
        data = <?php echo json_encode($lurkers); ?>;
        title = 'Currently Lurking Users';
        dataColumn = 'Time';
        infoColumn = 'Username';
        break;
      case 'typos':
        data = <?php echo json_encode($typos); ?>;
        title = 'Typo Counts';
        dataColumn = 'Typo Count';
        infoColumn = 'Username';
        break;
      case 'deaths':
        data = <?php echo json_encode($gameDeaths); ?>;
        title = 'Deaths Overview';
        dataColumn = 'Death Count';
        infoColumn = 'Game';
        break;
      case 'hugs':
        data = <?php echo json_encode($hugCounts); ?>;
        title = 'Hug Counts';
        dataColumn = 'Hug Count';
        infoColumn = 'Username';
        break;
      case 'kisses':
        data = <?php echo json_encode($kissCounts); ?>;
        title = 'Kiss Counts';
        dataColumn = 'Kiss Count';
        infoColumn = 'Username';
        break;
      case 'custom':
        data = <?php echo json_encode($customCounts); ?>;
        title = 'Custom Counts';
        dataColumn = 'Used';
        infoColumn = 'Command';
        break;
    }
    document.getElementById('data-column-info').innerText = dataColumn;
    document.getElementById('info-column-data').innerText = infoColumn;
    let output = '';
    data.forEach(function(item) {
      output += `<tr>`;
      if (type === 'lurkers') {
        output += `<td>${item.username}</td><td>${item.lurk_duration}</td>`;
      } else if (type === 'typos') {
        output += `<td>${item.username}</td><td>${item.typo_count}</td>`;
      } else if (type === 'deaths') {
        output += `<td>${item.game_name}</td><td>${item.death_count}</td>`;
      } else if (type === 'hugs') {
        output += `<td>${item.username}</td><td>${item.hug_count}</td>`;
      } else if (type === 'kisses') {
        output += `<td>${item.username}</td><td>${item.kiss_count}</td>`;
      } else if (type === 'custom') {
        output += `<td>${item.command}</td><td>${item.count}</td>`;
      }
      output += `</tr>`;
    });
    document.getElementById('table-title').innerText = title;
    document.getElementById('table-body').innerHTML = output;
  }
  // Load Lurkers by default on page load
  document.addEventListener('DOMContentLoaded', function () {
    loadData('lurkers');
  });
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>