<?php
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Profile";
$status = "";
$timezone = "";
$weather = "";
$dbHyperateCode = "";

// Retrieve status from the session instead of GET parameters
if(isset($_SESSION['status'])) { 
    $status = $_SESSION['status'];
    unset($_SESSION['status']);
}

// Include all the information
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'user_db.php';
include 'storage_used.php';
include "mod_access.php";
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);
$dbHyperateCode = $profile['heartrate_code'];

// Convert the stored date and time to UTC using Sydney time zone (AEST/AEDT)
$signup_date = isset($user['signup_date']) ? $user['signup_date'] : null;
$last_login = isset($user['last_login']) ? $user['last_login'] : null;
if ($signup_date) {
    $signup_date_obj = date_create_from_format('Y-m-d H:i:s', $signup_date);
    $signup_date_utc = $signup_date_obj ? $signup_date_obj->setTimezone(new DateTimeZone('UTC'))->format('F j, Y g:i A') : 'Not Available';
} else {
    $signup_date_utc = 'Not Available';
}

if ($last_login) {
    $last_login_obj = date_create_from_format('Y-m-d H:i:s', $last_login);
    $last_login_utc = $last_login_obj ? $last_login_obj->setTimezone(new DateTimeZone('UTC'))->format('F j, Y g:i A') : 'Not Available';
} else {
    $last_login_utc = 'Not Available';
}

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  if (isset($_POST['form']) && $_POST['form'] == "profile") {
    // Update profile details (timezone and weather_location)
    if (isset($_POST["timezone"]) && isset($_POST["weather_location"])) {
      $timezone = $_POST["timezone"];
      // Remove spaces from weather location
      $weather_location = preg_replace('/\s+/', '', $_POST["weather_location"]);
      $updateQuery = $db->prepare("INSERT INTO profile (id, timezone, weather_location) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE timezone = VALUES(timezone), weather_location = VALUES(weather_location)");
      $updateQuery->execute([$timezone, $weather_location]);
      $_SESSION['status'] = "Profile updated successfully!";
      header("Location: profile.php");
      exit();
    } else {
      $status = "Error: Please provide both timezone and weather location.";
    }
  } elseif (isset($_POST['form']) && $_POST['form'] == "hyperate") {
    // Update hyperate code
    if (isset($_POST["hyperate_code"]) && $_POST["hyperate_code"] !== '') {
      $hyperateCode = $_POST["hyperate_code"];
      $updateQuery = $db->prepare("INSERT INTO profile (id, heartrate_code) VALUES (1, ?) ON DUPLICATE KEY UPDATE heartrate_code = VALUES(heartrate_code)");
      $updateQuery->execute([$hyperateCode]);
      $_SESSION['status'] = "Profile updated successfully!";
      header("Location: profile.php");
      exit();
    } else {
      $status = "Error: Please provide the connection code before submitting.";
    }
  }
}

// Function to get all PHP timezones
$timezones = get_timezones();
function get_timezones() {
  $timezones = DateTimeZone::listIdentifiers();
  $timezone_offsets = [];
  foreach($timezones as $timezone) {
    $datetime = new DateTime('now', new DateTimeZone($timezone));
    $offset = $datetime->getOffset();
    $timezone_offsets[$timezone] = $offset;
  }
  // Sort timezones by offset
  asort($timezone_offsets);
  return $timezone_offsets;
}

// AJAX handler for server storage info (admin only)
$server_storage_info = [];
$server_storage_percentage = 0;
if (isset($is_admin) && $is_admin && isset($_GET['get_storage_info'])) {
  require_once "/var/www/config/ssh.php";
  $server_storage_info = [];
  $cache_file = "/var/www/cache/server_storage_info.json";
  $cache_expiry = 300;
  $use_cache = false;
  if (file_exists($cache_file)) {
    $cache_time = filemtime($cache_file);
    if ((time() - $cache_time) < $cache_expiry) {
      $cache_content = file_get_contents($cache_file);
      if ($cache_content) {
        $server_storage_info = json_decode($cache_content, true);
        $use_cache = true;
      }
    }
  }
  if (!$use_cache) {
    $total_space = disk_total_space("/");
    $free_space = disk_free_space("/");
    $used_space = $total_space - $free_space;
    $server_storage_info['main'] = [
      'name' => 'Main Server',
      'mount' => '/',
      'total' => round($total_space / 1024 / 1024 / 1024, 2) . ' GB',
      'used' => round($used_space / 1024 / 1024 / 1024, 2) . ' GB',
      'free' => round($free_space / 1024 / 1024 / 1024, 2) . ' GB',
      'percentage' => ($used_space / $total_space) * 100
    ];
    function getRemoteDiskSpace($host, $username, $password, $mount) {
      if (empty($host) || empty($username) || empty($password)) { return null; }
      $connection = ssh2_connect($host, 22);
      if (!$connection) { return null; }
      if (!ssh2_auth_password($connection, $username, $password)) { return null;}
      $total_command = "df -k " . escapeshellarg($mount) . " | tail -1 | awk '{print \$2}'";
      $stream_total = ssh2_exec($connection, $total_command);
      stream_set_blocking($stream_total, true);
      $total_kb = (int)trim(stream_get_contents($stream_total));
      $total_gb = round($total_kb / 1024 / 1024, 2);
      $used_command = "df -k " . escapeshellarg($mount) . " | tail -1 | awk '{print \$3}'";
      $stream_used = ssh2_exec($connection, $used_command);
      stream_set_blocking($stream_used, true);
      $used_kb = (int)trim(stream_get_contents($stream_used));
      $used_gb = round($used_kb / 1024 / 1024, 2);
      $free_gb = $total_gb - $used_gb;
      $percentage = $total_gb > 0 ? ($used_gb / $total_gb) * 100 : 0;
      return [
        'total' => $total_gb . ' GB',
        'used' => $used_gb . ' GB',
        'free' => $free_gb . ' GB',
        'percentage' => $percentage
      ];
    }
    if (!empty($api_server_host) && !empty($api_server_username) && !empty($api_server_password)) {
      $api_storage = getRemoteDiskSpace($api_server_host, $api_server_username, $api_server_password, "/");
      if ($api_storage) {
        $server_storage_info['api'] = [
          'name' => 'API Server',
          'mount' => '/',
          'total' => $api_storage['total'],
          'used' => $api_storage['used'],
          'free' => $api_storage['free'],
          'percentage' => $api_storage['percentage']
        ];
      }
    }
    if (!empty($sql_server_host) && !empty($sql_server_username) && !empty($sql_server_password)) {
      $sql_storage = getRemoteDiskSpace($sql_server_host, $sql_server_username, $sql_server_password, "/");
      if ($sql_storage) {
        $server_storage_info['sql'] = [
          'name' => 'SQL Database Server',
          'mount' => '/',
          'total' => $sql_storage['total'],
          'used' => $sql_storage['used'],
          'free' => $sql_storage['free'],
          'percentage' => $sql_storage['percentage']
        ];
      }
    }
    if (!empty($storage_server_au_east_1_host) && !empty($storage_server_au_east_1_username) && !empty($storage_server_au_east_1_password)) {
      $au_east_storage = getRemoteDiskSpace($storage_server_au_east_1_host, $storage_server_au_east_1_username, $storage_server_au_east_1_password, "/");
      if ($au_east_storage) {
        $server_storage_info['au-east-1'] = [
          'name' => 'STREAM AU-EAST-1',
          'mount' => '/',
          'total' => $au_east_storage['total'],
          'used' => $au_east_storage['used'],
          'free' => $au_east_storage['free'],
          'percentage' => $au_east_storage['percentage']
        ];
      }
    }
    if (!empty($storage_server_us_west_1_host) && !empty($storage_server_us_west_1_username) && !empty($storage_server_us_west_1_password)) {
      $us_west_root_storage = getRemoteDiskSpace($storage_server_us_west_1_host, $storage_server_us_west_1_username, $storage_server_us_west_1_password, "/");
      if ($us_west_root_storage) {
        $server_storage_info['us-west-1-root'] = [
          'name' => 'STREAM US-WEST-1',
          'mount' => '/',
          'total' => $us_west_root_storage['total'],
          'used' => $us_west_root_storage['used'],
          'free' => $us_west_root_storage['free'],
          'percentage' => $us_west_root_storage['percentage']
        ];
      }
      $us_west_storage_mount = getRemoteDiskSpace($storage_server_us_west_1_host, $storage_server_us_west_1_username, $storage_server_us_west_1_password, "/mnt/stream-us-west-1");
      if ($us_west_storage_mount) {
        $server_storage_info['us-west-1-storage'] = [
          'name' => 'STREAM US-WEST-1',
          'mount' => '/mnt/stream-us-west-1',
          'total' => $us_west_storage_mount['total'],
          'used' => $us_west_storage_mount['used'],
          'free' => $us_west_storage_mount['free'],
          'percentage' => $us_west_storage_mount['percentage']
        ];
      }
    }
    if (!empty($storage_server_us_east_1_host) && !empty($storage_server_us_east_1_username) && !empty($storage_server_us_east_1_password)) {
      $us_east_root_storage = getRemoteDiskSpace($storage_server_us_east_1_host, $storage_server_us_east_1_username, $storage_server_us_east_1_password, "/");
      if ($us_east_root_storage) {
        $server_storage_info['us-east-1-root'] = [
          'name' => 'STREAM US-EAST-1',
          'mount' => '/',
          'total' => $us_east_root_storage['total'],
          'used' => $us_east_root_storage['used'],
          'free' => $us_east_root_storage['free'],
          'percentage' => $us_east_root_storage['percentage']
        ];
      }
      $us_east_storage_mount = getRemoteDiskSpace($storage_server_us_east_1_host, $storage_server_us_east_1_username, $storage_server_us_east_1_password, "/mnt/stream-us-east-1");
      if ($us_east_storage_mount) {
        $server_storage_info['us-east-1-storage'] = [
          'name' => 'STREAM US-EAST-1',
          'mount' => '/mnt/stream-us-east-1',
          'total' => $us_east_storage_mount['total'],
          'used' => $us_east_storage_mount['used'],
          'free' => $us_east_storage_mount['free'],
          'percentage' => $us_east_storage_mount['percentage']
        ];
      }
    }
    if (!is_dir(dirname($cache_file))) {
      mkdir(dirname($cache_file), 0755, true);
    }
    file_put_contents($cache_file, json_encode($server_storage_info));
  }
  // Group servers by name
  $grouped_servers = [];
  foreach ($server_storage_info as $key => $server) {
    if (!isset($grouped_servers[$server['name']])) {
      $grouped_servers[$server['name']] = [];
    }
    $grouped_servers[$server['name']][] = $server;
  }
  ob_start();
  ?>
  <div class="columns is-multiline">
    <?php foreach ($grouped_servers as $server_name => $mounts): ?>
      <div class="column is-4">
        <div class="has-background-grey-darker has-text-white p-4" style="border-radius: 6px;">
          <h5 class="label is-5 has-text-white"><?php echo $server_name; ?></h5>
          <?php foreach ($mounts as $mount): ?>
            <?php if ($mount['mount'] != '/'): ?>
              <p class="label is-6 has-text-white">Mount: <?php echo $mount['mount']; ?></p>
            <?php endif; ?>
            <div class="progress-bar-container">
              <div class="progress-bar has-text-black-bis" style="width: <?php echo $mount['percentage']; ?>%;"><?php echo round($mount['percentage'], 2); ?>%</div>
            </div>
            <p class="label is-6 has-text-white">Total: <?php echo $mount['total']; ?> | Used: <?php echo $mount['used']; ?> | Free: <?php echo $mount['free']; ?></p>
            <?php if (next($mounts)): ?>
              <div style="margin-bottom: 15px;"></div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php
  echo ob_get_clean();
  exit;
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
  <h4 class="title is-3">Your Specter Dashboard Profile</h4>
  <div style="text-align: center; display: <?php echo !empty($status) ? 'block' : 'none'; ?>;" id="global-status">
    <?php if (!empty($status)): ?>
      <div class="notification is-primary" style="display: inline-block; margin-bottom: 20px;">
        <?php echo htmlspecialchars($status); ?>
      </div>
    <?php endif; ?>
  </div>
  <br>
  <div class="columns is-desktop is-multiline is-centered box-container">
    <div class="column bot-box is-5">
      <ol>Your Username: <?php echo $username; ?></ol>
      <ol>Display Name: <?php echo $twitchDisplayName; ?></ol>
      <ol>You Joined: <span id="localSignupDate"></span></ol>
      <ol>Your Last Login: <span id="localLastLogin"></span></ol>
      <ol>Cookie Consent: <span id="cookie-consent-status"><?php echo isset($_COOKIE['cookie_consent']) ? ucfirst($_COOKIE['cookie_consent']) : 'Not set'; ?></span></ol>
      <ol>Time Zone: <?php echo $timezone; ?></ol>
      <ol>Weather Location: <?php echo $weather; ?></ol>
      <p>Your API Key: <span class="is-dark" id="api-key" data-key="<?php echo $api_key; ?>">••••••••••••••••••••••••••••••••••••••••••••••••</span></p>
      <br>
      <div class="buttons">
        <button type="button" id="toggle-api-key" class="button is-info is-outlined is-rounded">
          <span class="icon"><i class="fas fa-eye"></i></span><span>Show API Key</span>
        </button>
        <button type="button" id="regen-api-key-open" class="button is-primary is-rounded">
          <span class="icon is-small"><i class="fas fa-sync-alt"></i></span>
          <span>Regenerate API Key</span>
        </button>
        <button type="button" id="reset-cookie-consent" class="button is-primary is-rounded">
          <span class="icon is-small"><i class="fas fa-redo"></i></span>
          <span>Reset Cookies</span>
        </button>
      </div>
    </div>
    <div class="column bot-box is-5">
      <h4 class="label is-4">Update Profile</h4>
      <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
        <input type="hidden" name="form" value="profile">
        <div class="field">
          <label class="is-4" for="timezone">Timezone:</label>
          <div class="control has-icons-left">
            <div class="select">
              <select style="width: 350px;" id="timezone" name="timezone">
                <?php
                foreach ($timezones as $tz => $offset) {
                    $offset_prefix = $offset < 0 ? '-' : '+';
                    $offset_hours = gmdate('H:i', abs($offset));
                    $selected = ($tz == $timezone) ? ' selected' : '';
                    echo "<option value=\"$tz\"$selected>(UTC $offset_prefix$offset_hours) $tz</option>
                    ";
                }
                ?>
              </select>
              <div class="icon is-small is-left"><i class="fas fa-clock"></i></div>
            </div>
          </div>
        </div>
        <div class="field">
          <label class="is-4" for="weather_location">Weather Location:</label>
          <div class="control has-icons-left">
            <input style="width: 350px;" class="input" type="text" id="weather_location" name="weather_location" value="<?php echo $weather; ?>">
            <div class="icon is-small is-left"><i class="fas fa-globe"></i></div>
          </div>
          <div class="control">
            <button type="button" class="button is-info" id="check-weather" style="margin-top:5px;">CHECK LOCATION</button>
          </div>
        </div>
        <div class="control"><button type="submit" class="button is-primary">Submit</button></div>
      </form>
    </div>
    <div class="column bot-box is-5">
      <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
        <input type="hidden" name="form" value="hyperate">
        <div class="field">
          <label class="is-4">Heart Rate Code:</label><br>
          <div class="control has-icons-left">
            <input style="width: 130px;" class="input" type="text" id="hyperate_code" name="hyperate_code" value="<?php echo $dbHyperateCode; ?>">
            <span class="icon is-small is-left"><i class="fa-solid fa-heart-pulse"></i></span>
          </div>
        </div>
        <div class="control"><button type="submit" class="button is-primary">Submit</button></div>
        Heart Rate in chat via Specter is powered by: <a href="https://www.hyperate.io/" target="_blank">HypeRate.io</a>
      </form>
    </div>
    <div class="column bot-box is-5">
      <h4 class="label is-4">Storage Used</h4>
      <div class="progress-bar-container">
        <div class="progress-bar has-text-black-bis" style="width: <?php echo $storage_percentage; ?>%;"><?php echo round($storage_percentage, 2); ?>%</div>
      </div>
      <p><?php echo round($current_storage_used / 1024 / 1024, 2); ?>MB of <?php echo round($max_storage_size / 1024 / 1024, 2); ?>MB used</p>
    </div>
    <?php if ($is_admin): ?>
    <div class="column bot-box is-12">
      <h4 class="label is-4">Server Storage Information</h4>
      <button id="show-storage-info" class="button is-info is-rounded" type="button">
        <span class="icon"><i class="fas fa-hdd"></i></span>
        <span>Show Server Storage Info</span>
      </button>
      <div id="storage-info-container" style="margin-top: 20px;">
        <!-- Storage info will be loaded here -->
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Include the JavaScript files -->
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="/js/profile.js"></script>
<script src="/js/timezone.js"></script>
<script>
  // Reset cookie consent button
  document.getElementById('reset-cookie-consent').addEventListener('click', function() {
    Swal.fire({
      title: 'Reset Cookies',
      text: 'This will reset your cookie consent preferences and selected bot settings. You will see the cookie consent banner on your next page load.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#3085d6',
      cancelButtonColor: '#d33',
      confirmButtonText: 'Yes, reset cookies'
    }).then((result) => {
      if (result.isConfirmed) {
        // Delete the cookie consent cookie
        document.cookie = "cookie_consent=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
        document.cookie = "selectedBot=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
        // Update the display
        document.getElementById('cookie-consent-status').textContent = 'Not set';
        // Show confirmation
        Swal.fire(
          'Cookies Reset',
          'Cookie consent has been reset successfully.',
          'success'
        );
      }
    });
  });

  // Regenerate API Key button action
  document.getElementById("regen-api-key-open").addEventListener("click", function() {
    Swal.fire({
      title: 'Regenerate API Key',
      text: 'Regenerating your API Key will require a restart of the entire system, including the Twitch Chat Bot, Discord Bot, and Stream Overlays. Please ensure you go back to the dashboard to restart them.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#3085d6',
      cancelButtonColor: '#d33',
      confirmButtonText: 'Yes, regenerate key'
    }).then((result) => {
      if (result.isConfirmed) {
        var xhr = new XMLHttpRequest();
        xhr.open("POST", "regen_api_key.php", true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        // Handle the response when the API key is regenerated
        xhr.onload = function() {
          if (xhr.status == 200) {
            // Update the displayed API Key with the new one
            var apiKeyTag = document.getElementById("api-key");
            apiKeyTag.setAttribute("data-key", xhr.responseText);
            apiKeyTag.textContent = "••••••••••••••••••••••••••••••••••••••••••••••••";
            Swal.fire(
              'API Key Regenerated',
              'Your API key has been successfully regenerated.',
              'success'
            );
          } else {
            Swal.fire(
              'Error',
              'Error regenerating API key. Please try again.',
              'error'
            );
          }
        };
        // Send the AJAX request to regenerate the API key
        xhr.send("action=regen_api_key");
      }
    });
  });

  // Function to convert UTC date to local date in the desired format
  function convertUTCToLocalFormatted(utcDateStr) {
    const options = {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      hour: 'numeric',
      minute: 'numeric',
      hour12: true,
      timeZoneName: 'short'
    };
    const utcDate = new Date(utcDateStr + ' UTC');
    const localDate = new Date(utcDate.toLocaleString('en-US', { timeZone: '<?php echo $timezone; ?>' }));
    const dateTimeFormatter = new Intl.DateTimeFormat('en-US', options);
    return dateTimeFormatter.format(localDate);
  }
  // PHP variables holding the UTC date and time
  const signupDateUTC = "<?php echo $signup_date_utc; ?>";
  const lastLoginUTC = "<?php echo $last_login_utc; ?>";
  // Display the dates in the user's local time zone
  document.getElementById('localSignupDate').innerText = convertUTCToLocalFormatted(signupDateUTC);
  document.getElementById('localLastLogin').innerText = convertUTCToLocalFormatted(lastLoginUTC);

  document.getElementById('check-weather').addEventListener('click', function() {
    var locationInput = document.getElementById('weather_location').value;
    var location = locationInput.replace(/\s+/g, '');
    var apiKey = "<?php echo $api_key; ?>";
    var url = "https://api.botofthespecter.com/weather/location?api_key=" + apiKey + "&location=" + encodeURIComponent(location);
    var xhr = new XMLHttpRequest();
    xhr.open("GET", url, true);
    xhr.onload = function() {
      var globalStatus = document.getElementById('global-status');
      if (xhr.status == 200) {
        try {
          var response = JSON.parse(xhr.responseText);
          if(response.detail) {
            globalStatus.innerHTML = '<div class="notification is-primary" style="display: inline-block; margin-bottom: 20px;">' +
              'The location that you enter cannot be found.' +
              '</div>';
            globalStatus.style.display = 'block';
            return;
          }
          var message = response.message;
          var match = message.match(/Location:\s*([^()]+)\s*\(/);
          if (match && match[1]) {
            var validatedLocation = match[1].trim();
            globalStatus.innerHTML = '<div class="notification is-primary" style="display: inline-block; margin-bottom: 20px;">' +
              'Location checked and found: "' + validatedLocation + '"' +
              '</div>';
            globalStatus.style.display = 'block';
          } else {
            globalStatus.innerHTML = '<div class="notification is-primary" style="display: inline-block; margin-bottom: 20px;">' +
              'Location checked: ' + message + '</div>';
            globalStatus.style.display = 'block';
          }
        } catch(e) {
          globalStatus.innerHTML = '<div class="notification is-primary" style="display: inline-block; margin-bottom: 20px;">' +
            'Error parsing API response.</div>';
          globalStatus.style.display = 'block';
        }
      } else {
        globalStatus.innerHTML = '<div class="notification is-primary" style="display: inline-block; margin-bottom: 20px;">' +
          'Error checking location.</div>';
        globalStatus.style.display = 'block';
      }
    };
    xhr.send();
  });

document.addEventListener('DOMContentLoaded', function() {
    var toggleApiKeyBtn = document.getElementById('toggle-api-key');
    var apiKeyTag = document.getElementById('api-key');
    var masked = "••••••••••••••••••••••••••••••••••••••••••••••••"; // standard mask
    toggleApiKeyBtn.addEventListener('click', function() {
        if (apiKeyTag.textContent === masked) {
            Swal.fire({
                title: 'Are you sure?',
                text: 'Warning: Revealing your API Key on a shared screen (e.g., during a stream) can be a security risk. Ensure this screen is not visible to others before proceeding.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, show it',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if(result.isConfirmed){
                    apiKeyTag.textContent = apiKeyTag.getAttribute("data-key");
                    toggleApiKeyBtn.innerHTML = '<span class="icon"><i class="fas fa-eye-slash"></i></span><span>Hide API Key</span>';
                }
            });
        } else {
            apiKeyTag.textContent = masked;
            toggleApiKeyBtn.innerHTML = '<span class="icon"><i class="fas fa-eye"></i></span><span>Show API Key</span>';
        }
    });
});

<?php if ($is_admin): ?>
document.getElementById('show-storage-info').addEventListener('click', function() {
  var btn = this;
  var container = document.getElementById('storage-info-container');
  btn.disabled = true;
  btn.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span> <span>Loading...</span>';
  container.innerHTML = '<div class="notification is-info">Loading server storage info, please wait...</div>';
  fetch('profile.php?get_storage_info=1')
    .then(response => response.text())
    .then(html => {
      container.innerHTML = html;
      btn.style.display = 'none';
    })
    .catch(() => {
      container.innerHTML = '<div class="notification is-danger">Failed to load storage info.</div>';
      btn.disabled = false;
      btn.innerHTML = '<span class="icon"><i class="fas fa-hdd"></i></span> <span>Show Server Storage Info</span>';
    });
});
<?php endif; ?>
</script>
</body>
</html>