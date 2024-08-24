<?php
// Display all PHP errors (useful during development)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
} else {
    $usernames = [];
}

// Determine the selected count type from the URL
$countType = isset($_GET['countType']) ? $_GET['countType'] : '';
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
  <div class="tabs is-boxed is-centered" id="countTabs">
    <ul>
      <li class="<?php echo $countType === 'lurking' ? 'is-active' : ''; ?>"><a href="?countType=lurking">Currently Lurking Users</a></li>
      <li class="<?php echo $countType === 'typo' ? 'is-active' : ''; ?>"><a href="?countType=typo">Typo Counts</a></li>
      <li class="<?php echo $countType === 'deaths' ? 'is-active' : ''; ?>"><a href="?countType=deaths">Deaths Overview</a></li>
      <li class="<?php echo $countType === 'hugs' ? 'is-active' : ''; ?>"><a href="?countType=hugs">Hug Counts</a></li>
      <li class="<?php echo $countType === 'kisses' ? 'is-active' : ''; ?>"><a href="?countType=kisses">Kiss Counts</a></li>
      <li class="<?php echo $countType === 'custom' ? 'is-active' : ''; ?>"><a href="?countType=custom">Custom Counts</a></li>
    </ul>
  </div>
  <div class="content">
    <div class="tabs-content">
      <!-- Lurking Users -->
      <div class="tab-content <?php echo $countType === 'lurking' ? 'is-active' : ''; ?>" id="lurking">
        <div class="box">
          <h3 class="title" style="color: white;">Currently Lurking Users</h3>
          <table class="table is-striped is-fullwidth">
            <thead>
              <tr>
                <th style="color: white;">Username</th>
                <th style="color: white;">Lurk Duration</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lurkers as $lurker): 
                $displayName = $usernames[$lurker['user_id']] ?? $lurker['user_id']; ?>
              <tr>
                <td id="<?php echo htmlspecialchars($lurker['user_id']); ?>"><?php echo htmlspecialchars($displayName); ?></td>
                <td id="lurk_duration"><?php echo htmlspecialchars($lurker['lurk_duration']); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      
      <!-- Typo Counts -->
      <div class="tab-content <?php echo $countType === 'typo' ? 'is-active' : ''; ?>" id="typo">
        <div class="box">
          <h3 class="title">Typo Counts</h3>
          <table class="table is-striped is-fullwidth">
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
      </div>

      <!-- Deaths Overview -->
      <div class="tab-content <?php echo $countType === 'deaths' ? 'is-active' : ''; ?>" id="deaths">
        <div class="box">
          <h3 class="title">Deaths Overview</h3>
          <table class="table is-striped is-fullwidth">
            <thead>
              <tr>
                <th>Category</th>
                <th>Count</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Total Deaths</td>
                <td><?php echo htmlspecialchars($totalDeaths['death_count'] ?? '0'); ?></td>
              </tr>
              <?php foreach ($gameDeaths as $gameDeath): ?>
              <tr>
                <td><?php echo htmlspecialchars($gameDeath['game_name']); ?></td>
                <td><?php echo htmlspecialchars($gameDeath['death_count']); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Hug Counts -->
      <div class="tab-content <?php echo $countType === 'hugs' ? 'is-active' : ''; ?>" id="hugs">
        <div class="box">
          <h3 class="title">Hug Counts</h3>
          <table class="table is-striped is-fullwidth">
            <thead>
              <tr>
                <th>Username</th>
                <th>Hug Count</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Total Hugs</td>
                <td><?php echo htmlspecialchars($totalHugs['total_hug_count'] ?? '0'); ?></td>
              </tr>
              <?php foreach ($hugCounts as $hugCount): ?>
              <tr>
                <td><?php echo htmlspecialchars($hugCount['username']); ?></td>
                <td><?php echo htmlspecialchars($hugCount['hug_count']); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Kiss Counts -->
      <div class="tab-content <?php echo $countType === 'kisses' ? 'is-active' : ''; ?>" id="kisses">
        <div class="box">
          <h3 class="title">Kiss Counts</h3>
          <table class="table is-striped is-fullwidth">
            <thead>
              <tr>
                <th>Username</th>
                <th>Kiss Count</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Total Kisses</td>
                <td><?php echo htmlspecialchars($totalKisses['total_kiss_count'] ?? '0'); ?></td>
              </tr>
              <?php foreach ($kissCounts as $kissCount): ?>
              <tr>
                <td><?php echo htmlspecialchars($kissCount['username']); ?></td>
                <td><?php echo htmlspecialchars($kissCount['kiss_count']); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Custom Counts -->
      <div class="tab-content <?php echo $countType === 'custom' ? 'is-active' : ''; ?>" id="custom">
        <div class="box">
          <h3 class="title">Custom Counts</h3>
          <table class="table is-striped is-fullwidth">
            <thead>
              <tr>
                <th>Command</th>
                <th>Count</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($customCounts as $customCount): ?>
              <tr>
                <td><?php echo htmlspecialchars($customCount['command']); ?></td>
                <td><?php echo htmlspecialchars($customCount['count']); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelectorAll('.tabs li');
    const tabContents = document.querySelectorAll('.tab-content');
    
    function activateTab(tab) {
      tabs.forEach(item => item.classList.remove('is-active'));
      tab.classList.add('is-active');
      const target = tab.querySelector('a').getAttribute('href').substring(1);
      tabContents.forEach(content => {
        content.classList.remove('is-active');
        if (content.id === target) {
          content.classList.add('is-active');
        }
      });
    }

    tabs.forEach(tab => {
      tab.addEventListener('click', (event) => {
        event.preventDefault();
        history.pushState(null, '', tab.querySelector('a').getAttribute('href'));
        activateTab(tab);
      });
    });

    // Activate the tab based on the URL hash
    if (window.location.hash) {
      const hash = window.location.hash.substring(1);
      const initialTab = document.querySelector(`.tabs li a[href="#${hash}"]`).parentElement;
      activateTab(initialTab);
    } else {
      // Default to the first tab
      activateTab(tabs[0]);
    }
  });
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>