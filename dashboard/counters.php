<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page title
$pageTitle = t('navbar_counters');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Check for cookie consent
$cookieConsent = isset($_COOKIE['cookie_consent']) && $_COOKIE['cookie_consent'] === 'accepted';

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
                    ($interval->i * 60) +
                    $interval->s;
    $lurkers[$key]['total_duration'] = $totalDuration; // Store for sorting
    $timeStringParts = [];
    if ($interval->y > 0) {
      $timeStringParts[] = "{$interval->y} " . t('time_years');
    }
    if ($interval->m > 0) {
      $timeStringParts[] = "{$interval->m} " . t('time_months');
    }
    if ($interval->d > 0) {
      $timeStringParts[] = "{$interval->d} " . t('time_days');
    }
    if ($interval->h > 0) {
      $timeStringParts[] = "{$interval->h} " . t('time_hours');
    }
    if ($interval->i > 0) {
      $timeStringParts[] = "{$interval->i} " . t('time_minutes');
    }
    if ($interval->s > 0 || empty($timeStringParts)) {
      $timeStringParts[] = "{$interval->s} " . t('time_seconds');
    }
    $lurkers[$key]['lurk_duration'] = implode(', ', $timeStringParts);
  }
  // Sort the lurkers array by total_duration (longest to shortest)
  usort($lurkers, function ($a, $b) {
    return $b['total_duration'] - $a['total_duration'];
  });
} catch (Exception $e) { // Changed PDOException to generic Exception
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

// Get the default data type to display - either from cookie or default to 'lurkers'
$defaultDataType = 'lurkers';
if ($cookieConsent && isset($_COOKIE['preferred_data_type'])) {
  $defaultDataType = $_COOKIE['preferred_data_type'];
}

// Start output buffering for main content
ob_start();
?>
<div class="columns is-centered">
  <div class="column is-fullwidth">
    <div class="card has-background-dark has-text-white mb-5" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
      <header class="card-header" style="border-bottom: 1px solid #23272f;">
        <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
          <span class="icon mr-2"><i class="fas fa-stopwatch"></i></span>
          <?php echo t('navbar_counters'); ?>
        </span>
      </header>
      <div class="card-content">
        <div class="buttons is-centered mb-4">
          <button class="button is-info" data-type="lurkers" onclick="loadData('lurkers')"><?php echo t('counters_lurkers'); ?></button>
          <button class="button is-info" data-type="typos" onclick="loadData('typos')"><?php echo t('edit_counters_edit_user_typos'); ?></button>
          <button class="button is-info" data-type="deaths" onclick="loadData('deaths')"><?php echo t('counters_deaths'); ?></button>
          <button class="button is-info" data-type="hugs" onclick="loadData('hugs')"><?php echo t('counters_hugs'); ?></button>
          <button class="button is-info" data-type="kisses" onclick="loadData('kisses')"><?php echo t('counters_kisses'); ?></button>
          <button class="button is-info" data-type="highfives" onclick="loadData('highfives')"><?php echo t('counters_highfives'); ?></button>
          <button class="button is-info" data-type="customCounts" onclick="loadData('customCounts')"><?php echo t('counters_custom_counts'); ?></button>
          <button class="button is-info" data-type="userCounts" onclick="loadData('userCounts')"><?php echo t('counters_user_counts'); ?></button>
          <button class="button is-info" data-type="rewardCounts" onclick="loadData('rewardCounts')"><?php echo t('counters_reward_counts'); ?></button>
          <button class="button is-info" data-type="watchTime" onclick="loadData('watchTime')"><?php echo t('counters_watch_time'); ?></button>
          <button class="button is-info" data-type="quotes" onclick="loadData('quotes')"><?php echo t('counters_quotes'); ?></button>
        </div>
        <div class="content">
          <div class="table-container">
            <h3 id="table-title" class="title is-4 has-text-white has-text-centered mb-3"></h3>
            <table class="table is-fullwidth" style="table-layout: fixed; width: 100%;">
              <thead>
                <tr>
                  <th id="info-column-data" class="has-text-white" style="width: 33%;"></th>
                  <th id="data-column-info" class="has-text-white" style="width: 33%;"></th>
                  <th id="count-column" class="has-text-white" style="width: 33%; display: none;"></th>
                </tr>
              </thead>
              <tbody id="table-body">
                <!-- Content will be dynamically injected here -->
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Set initial active button and load data
  const defaultType = '<?php echo $defaultDataType; ?>';
  // Highlight the default button using data-type attribute
  document.querySelectorAll('.buttons .button').forEach(button => {
    if (button.getAttribute('data-type') === defaultType) {
      button.classList.remove('is-info');
      button.classList.add('is-primary');
    } else {
      button.classList.remove('is-primary');
      button.classList.add('is-info');
    }
  });
  loadData(defaultType);
});

function formatWatchTime(seconds) {
  if (seconds == 0) {
    return "<span class='has-text-danger'><?php echo t('counters_watch_time_not_recorded'); ?></span>";
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

  // Store the user's preference in a cookie if consent is given
  if (<?php echo $cookieConsent ? 'true' : 'false'; ?>) {
    setCookie('preferred_data_type', type, 30); // Store for 30 days
  }

  switch(type) {
    case 'lurkers':
      data = <?php echo json_encode($lurkers); ?>;
      title = <?php echo json_encode(t('counters_lurkers')); ?>;
      dataColumn = <?php echo json_encode(t('counters_time_column')); ?>;
      infoColumn = <?php echo json_encode(t('counters_username_column')); ?>;
      break;
    case 'typos':
      data = <?php echo json_encode($typos); ?>;
      title = <?php echo json_encode(t('edit_counters_edit_user_typos')); ?>;
      dataColumn = <?php echo json_encode(t('edit_counters_new_typo_count')); ?>;
      infoColumn = <?php echo json_encode(t('edit_counters_username_label')); ?>;
      break;
    case 'deaths':
      data = <?php echo json_encode($gameDeaths); ?>;
      title = <?php echo json_encode(t('counters_deaths')); ?>;
      dataColumn = <?php echo json_encode(t('counters_count_column')); ?>;
      infoColumn = <?php echo json_encode(t('counters_game_column')); ?>;
      break;
    case 'hugs':
      data = <?php echo json_encode($hugCounts); ?>;
      title = <?php echo json_encode(t('counters_hugs')); ?>;
      dataColumn = <?php echo json_encode(t('counters_count_column')); ?>;
      infoColumn = <?php echo json_encode(t('counters_username_column')); ?>;
      break;
    case 'kisses':
      data = <?php echo json_encode($kissCounts); ?>;
      title = <?php echo json_encode(t('counters_kisses')); ?>;
      dataColumn = <?php echo json_encode(t('counters_count_column')); ?>;
      infoColumn = <?php echo json_encode(t('counters_username_column')); ?>;
      break;
    case 'highfives':
      data = <?php echo json_encode($highfiveCounts); ?>;
      title = <?php echo json_encode(t('counters_highfives')); ?>;
      dataColumn = <?php echo json_encode(t('counters_count_column')); ?>;
      infoColumn = <?php echo json_encode(t('counters_username_column')); ?>;
      break;
    case 'customCounts':
      data = <?php echo json_encode($customCounts); ?>;
      title = <?php echo json_encode(t('counters_custom_counts')); ?>;
      dataColumn = <?php echo json_encode(t('counters_used_column')); ?>;
      infoColumn = <?php echo json_encode(t('counters_command_column')); ?>;
      break;
    case 'userCounts':
      data = <?php echo json_encode($userCounts); ?>;
      countColumnVisible = true;
      title = <?php echo json_encode(t('counters_user_counts')); ?>;
      infoColumn = <?php echo json_encode(t('counters_username_column')); ?>;
      dataColumn = <?php echo json_encode(t('counters_command_column')); ?>;
      additionalColumnName = <?php echo json_encode(t('counters_count_column')); ?>;
      break;
    case 'rewardCounts':
      data = <?php echo json_encode($rewardCounts); ?>;
      countColumnVisible = true;
      title = <?php echo json_encode(t('counters_reward_counts')); ?>;
      infoColumn = <?php echo json_encode(t('counters_reward_name_column')); ?>;
      dataColumn = <?php echo json_encode(t('counters_username_column')); ?>;
      additionalColumnName = <?php echo json_encode(t('counters_count_column')); ?>;
      break;
    case 'watchTime':
      data = <?php echo json_encode($watchTimeData); ?>;
      title = <?php echo json_encode(t('counters_watch_time')); ?>;
      infoColumn = <?php echo json_encode(t('counters_username_column')); ?>;
      dataColumn = <?php echo json_encode(t('counters_online_watch_time_column')); ?>;
      additionalColumnName = <?php echo json_encode(t('counters_offline_watch_time_column')); ?>;
      countColumnVisible = true;
      data.sort((a, b) => b.total_watch_time_live - a.total_watch_time_live || b.total_watch_time_offline - a.total_watch_time_offline);
      break;
    case 'quotes':
      data = <?php echo json_encode($quotesData); ?>;
      title = <?php echo json_encode(t('counters_quotes')); ?>;
      infoColumn = <?php echo json_encode(t('counters_id_column')); ?>;
      dataColumn = <?php echo json_encode(t('counters_what_was_said_column')); ?>;
      break;
  }

  // Update active button state using data-type attribute
  document.querySelectorAll('.buttons .button').forEach(button => {
    if (button.getAttribute('data-type') === type) {
      button.classList.remove('is-info');
      button.classList.add('is-primary');
    } else {
      button.classList.remove('is-primary');
      button.classList.add('is-info');
    }
  });

  document.getElementById('data-column-info').innerText = dataColumn;
  document.getElementById('info-column-data').innerText = infoColumn;
  if (countColumnVisible) {
    document.getElementById('count-column').style.display = ''; // Ensure it's table-cell or empty for default
    document.getElementById('count-column').innerText = additionalColumnName;
  } else {
    document.getElementById('count-column').style.display = 'none';
  }
  data.forEach(item => {
    output += `<tr class="has-text-white">`; // Add has-text-white to table rows
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
    // Ensure three cells are added if countColumn is visible and the type doesn't already add three
    if (countColumnVisible) {
        if (type !== 'userCounts' && type !== 'rewardCounts' && type !== 'watchTime') {
             output += `<td></td>`; 
        }
    }
    output += `</tr>`;
  });
  document.getElementById('table-title').innerText = title;
  document.getElementById('table-body').innerHTML = output;
}

// Function to set a cookie
function setCookie(name, value, days) {
  const d = new Date();
  d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
  const expires = "expires=" + d.toUTCString();
  document.cookie = name + "=" + value + ";" + expires + ";path=/";
}
</script>
<?php
// Get the buffered content
$scripts = ob_get_clean();

// Use layout.php to render the page
include 'layout.php';
?>