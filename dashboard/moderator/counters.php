<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page title
$title = "Counters and Information";

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include 'modding_access.php';
include 'user_db.php';
$getProfile = $db->query("SELECT timezone FROM profile");
$profile = $getProfile->fetchAll(PDO::FETCH_ASSOC);
$timezone = $profile['timezone'];
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
  <h1 class="title is-2">System Counters and Information</h1>
  <br>
  <div class="buttons is-centered">
    <button class="button is-info" onclick="loadData('lurkers')">Lurkers</button>
    <button class="button is-info" onclick="loadData('typos')">Typo Counts</button>
    <button class="button is-info" onclick="loadData('deaths')">Deaths Overview</button>
    <button class="button is-info" onclick="loadData('hugs')">Hug Counts</button>
    <button class="button is-info" onclick="loadData('kisses')">Kiss Counts</button>
    <button class="button is-info" onclick="loadData('highfives')">High-Five Counts</button>
    <button class="button is-info" onclick="loadData('customCounts')">Custom Counts</button>
    <button class="button is-info" onclick="loadData('userCounts')">User Counts</button>
    <button class="button is-info" onclick="loadData('rewardCounts')">Reward Counts</button>
    <button class="button is-info" onclick="loadData('watchTime')">Watch Time</button>
    <button class="button is-info" onclick="loadData('quotes')">Quotes</button>
  </div>
  <div class="content">
    <div class="box">
      <h3 id="table-title" class="title" style="color: white;"></h3>
      <table class="table is-striped is-fullwidth" style="table-layout: fixed; width: 100%;">
        <thead>
          <tr>
            <th id="info-column-data" style="color: white; width: 33%;"></th>
            <th id="data-column-info" style="color: white; width: 33%;"></th>
            <th id="count-column" style="color: white; width: 33%; display: none;"></th>
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
document.addEventListener('DOMContentLoaded', function() {
    loadData('lurkers');
});

function formatWatchTime(seconds) {
  if (seconds === 0) {
    return "<span class='has-text-danger'>Not Recorded</span>";
  }
  const units = {
      year: 31536000,
      month: 2592000,
      day: 86400,
      hour: 3600,
      minute: 60
  };
  const parts = [];
  for (const [name, divisor] of Object.entries(units)) {
      const quotient = Math.floor(seconds / divisor);
      if (quotient > 0) {
          parts.push(`${quotient} ${name}${quotient > 1 ? 's' : ''}`);
          seconds -= quotient * divisor;
      }
  }
  return `<span class='has-text-success'>${parts.join(', ')}</span>`;
}

function loadData(type) {
  let data;
  let title;
  let dataColumn;
  let infoColumn;
  let countColumnVisible = false;
  let additionalColumnName;
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
      dataColumn = 'Count';
      infoColumn = 'Game'; 
      break;
    case 'hugs':
      data = <?php echo json_encode($hugCounts); ?>;
      title = 'Hug Counts';
      dataColumn = 'Count';
      infoColumn = 'Username'; 
      break;
    case 'kisses':
      data = <?php echo json_encode($kissCounts); ?>;
      title = 'Kiss Counts';
      dataColumn = 'Count';
      infoColumn = 'Username'; 
      break;
    case 'highfives':
      data = <?php echo json_encode($highfiveCounts); ?>;
      title = 'High-Five Counts';
      dataColumn = 'Count';
      infoColumn = 'Username'; 
      break;
    case 'customCounts':
      data = <?php echo json_encode($customCounts); ?>;
      title = 'Custom Counts';
      dataColumn = 'Used';
      infoColumn = 'Command'; 
      break;
    case 'userCounts':
      data = <?php echo json_encode($userCounts); ?>;
      countColumnVisible = true;
      title = 'User Counts for Commands';
      infoColumn = 'Username';
      dataColumn = 'Command';
      additionalColumnName = 'Count';
      break;
    case 'rewardCounts':
      data = <?php echo json_encode($rewardCounts); ?>;
      countColumnVisible = true;
      title = 'Reward Counts';
      infoColumn = 'Reward Name';
      dataColumn = 'Username';
      additionalColumnName = 'Count';
      break;
    case 'watchTime': 
      data = <?php echo json_encode($watchTimeData); ?>;
      title = 'Watch Time';
      infoColumn = 'Username';
      dataColumn = 'Online Watch Time';
      additionalColumnName = 'Offline Watch Time';
      countColumnVisible = true;
      data.sort((a, b) => b.total_watch_time_live - a.total_watch_time_live || b.total_watch_time_offline - a.total_watch_time_offline);
      break;
    case 'quotes':
      data = <?php echo json_encode($quotesData); ?>;
      title = 'Quotes';
      infoColumn = 'ID';
      dataColumn = 'What was said';
      break;
  }
  document.getElementById('data-column-info').innerText = dataColumn;
  document.getElementById('info-column-data').innerText = infoColumn;
  if (countColumnVisible) {
    document.getElementById('count-column').style.display = '';
    document.getElementById('count-column').innerText = additionalColumnName;
  } else {
    document.getElementById('count-column').style.display = 'none';
  }
  data.forEach(item => {
    output += `<tr>`;
    if (type === 'lurkers') {
      output += `<td>${item.username}</td><td><span class='has-text-success'>${item.lurk_duration}</span></td>`;
    } else if (type === 'typos') {
      output += `<td>${item.username}</td><td><span class='has-text-success'>${item.typo_count}</span></td>`;
    } else if (type === 'deaths') {
      output += `<td>${item.game_name}</td><td><span class='has-text-success'>${item.death_count}</span></td>`;
    } else if (type === 'hugs') {
      output += `<td>${item.username}</td><td><span class='has-text-success'>${item.hug_count}</span></td>`;
    } else if (type === 'kisses') {
      output += `<td>${item.username}</td><td><span class='has-text-success'>${item.kiss_count}</span></td>`;
    } else if (type === 'highfives') {
      output += `<td>${item.username}</td><td><span class='has-text-success'>${item.highfive_count}</span></td>`;
    } else if (type === 'customCounts') {
      output += `<td>${item.command}</td><td><span class='has-text-success'>${item.count}</span></td>`;
    } else if (type === 'userCounts') {
      output += `<td>${item.user}</td><td><span class='has-text-success'>${item.command}</span></td><td><span class='has-text-success'>${item.count}</span></td>`;
    } else if (type === 'rewardCounts') {
      output += `<td>${item.reward_title}</td><td>${item.user}</td><td><span class='has-text-success'>${item.count}</span></td>`;
    } else if (type === 'watchTime') { 
      output += `<td>${item.username}</td><td>${formatWatchTime(item.total_watch_time_live)}</td><td>${formatWatchTime(item.total_watch_time_offline)}</td>`;
    } else if (type === 'quotes') {
      output += `<td>${item.id}</td><td><span class='has-text-success'>${item.quote}</span></td>`;
    }
    output += `</tr>`;
  });
  document.getElementById('table-title').innerText = title;
  document.getElementById('table-body').innerHTML = output;
}
</script>
</body>
</html>