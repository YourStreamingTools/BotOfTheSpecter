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

// Include all the information
require_once "db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'user_db.php';
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);

try {
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
  <br>
  <div class="buttons">
    <button class="button is-info" onclick="loadData('lurkers')">Lurkers</button>
    <button class="button is-info" onclick="loadData('typos')">Typo Counts</button>
    <button class="button is-info" onclick="loadData('deaths')">Deaths Overview</button>
    <button class="button is-info" onclick="loadData('hugs')">Hug Counts</button>
    <button class="button is-info" onclick="loadData('kisses')">Kiss Counts</button>
    <button class="button is-info" onclick="loadData('custom')">Custom Counts</button>
    <button class="button is-info" onclick="loadData('userCounts')">User Counts</button>
    <button class="button is-info" onclick="loadData('watchTime')">Watch Time</button> 
  </div>
  <div class="content">
    <div class="box">
      <h3 id="table-title" class="title" style="color: white;">User Counts for Commands</h3>
      <table class="table is-striped is-fullwidth" style="table-layout: fixed; width: 100%;">
        <thead>
          <tr>
            <th id="info-column-data" style="color: white; width: 33%;">User</th>
            <th id="data-column-info" style="color: white; width: 33%;">Command</th>
            <th id="count-column" style="color: white; width: 33%; display: none;">Count</th>
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
  let countColumnVisible = false;
  let output = '';
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
    case 'userCounts':
      data = <?php echo json_encode($userCounts); ?>;
      title = 'User Counts for Commands';
      dataColumn = 'Count';
      infoColumn = 'Command'; 
      countColumnVisible = true;
      break;
    case 'watchTime': 
      data = <?php echo json_encode($watchTimeData); ?>;
      title = 'Watch Time';
      dataColumn = 'Total Watch Time';
      infoColumn = 'Username';
      break;
  }
  document.getElementById('data-column-info').innerText = dataColumn;
  document.getElementById('info-column-data').innerText = infoColumn;
  if (countColumnVisible) {
    document.getElementById('count-column').style.display = '';
  } else {
    document.getElementById('count-column').style.display = 'none';
  }
  data.forEach(item => {
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
    } else if (type === 'userCounts') {
      output += `<td>${item.user}</td><td>${item.command}</td><td>${item.count}</td>`; 
    } else if (type === 'watchTime') { 
      output += `<td>${item.username}</td><td>${item.watch_time}</td>`; 
    }
    output += `</tr>`;
  });
  document.getElementById('table-title').innerText = title;
  document.getElementById('table-body').innerHTML = output;
}
</script>
</body>
</html>