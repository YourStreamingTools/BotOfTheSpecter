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

// Include all the information
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'user_db.php';
include 'storage_used.php';
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);
$dbHyperateCode = $profile['heartrate_code'];

// Convert the stored date and time to UTC using Sydney time zone (AEST/AEDT)
$signup_date = isset($user['signup_date']) ? $user['signup_date'] : null;
$last_login = isset($user['last_login']) ? $user['last_login'] : null;
$signup_date_utc = isset($signup_date) && $signup_date ? date_create_from_format('Y-m-d H:i:s', $signup_date)->setTimezone(new DateTimeZone('UTC'))->format('F j, Y g:i A') : 'Not Available';
$last_login_utc = isset($last_login) && $last_login ? date_create_from_format('Y-m-d H:i:s', $last_login)->setTimezone(new DateTimeZone('UTC'))->format('F j, Y g:i A') : 'Not Available';

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  // Check if timezone and weather_location are set
  if (isset($_POST["timezone"]) && isset($_POST["weather_location"])) {
    // Update the database with the new values, using id = 1
    $timezone = $_POST["timezone"];
    $weather_location = $_POST["weather_location"];
    // Insert or update the row where id is 1
    $updateQuery = $db->prepare("INSERT INTO profile (id, timezone, weather_location) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE timezone = VALUES(timezone), weather_location = VALUES(weather_location)");
    $updateQuery->execute([$timezone, $weather_location]);
    $status = "Profile updated successfully!";
  } else {
    $status = "Error: Please provide both timezone and weather location.";
  }
  // Check if HypeRate Code is here.
  if (isset($_POST["hyperate_code"])) {
    $hyperateCode = $_POST["hyperate_code"];
    $updateQuery = $db->prepare("INSERT INTO profile (id, heartrate_code) VALUES (1, ?) ON DUPLICATE KEY UPDATE heartrate_code = VALUES(heartrate_code)");
    $updateQuery->execute([$hyperateCode]);
    $status = "Profile updated successfully!";
  } else {
    $status = "Error: Please provide the connection code before submitting.";
  }
}

// Function to get all PHP timezones
$timezones = get_timezones();
function get_timezones() {
    $timezones = DateTimeZone::listIdentifiers();
    $timezone_offsets = [];
    foreach($timezones as $timezone) {
        $datetime = new DateTime(null, new DateTimeZone($timezone));
        $offset = $datetime->getOffset();
        $timezone_offsets[$timezone] = $offset;
    }
    // Sort timezones by offset
    asort($timezone_offsets);
    return $timezone_offsets;
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
  <div class="columns is-desktop is-multiline is-centered box-container">
    <div class="column bot-box is-5">
      <ol>Your Username: <?php echo $username; ?></ol>
      <ol>Display Name: <?php echo $twitchDisplayName; ?></ol>
      <ol>You Joined: <span id="localSignupDate"></span></ol>
      <ol>Your Last Login: <span id="localLastLogin"></span></ol>
      <ol>Time Zone: <?php echo $timezone; ?></ol>
      <ol>Weather Location: <?php echo $weather; ?></ol>
      <p>Your API Key: <span class="api-key-wrapper api-text-black" style="display: none;" id="api-key"><?php echo $api_key; ?></span></p>
      <button type="button" class="button is-primary" id="show-api-key" style="width: 130px;">Show API Key</button>
      <button type="button" class="button is-primary" id="hide-api-key" style="display:none; width: 130px;">Hide API Key</button>
      <button type="button" class="button is-primary" id="regen-api-key-open" style="width: 180px;">Regenerate API Key</button>
    </div>
    <div class="column bot-box is-4">
      <?php if (!empty($status)): ?>
        <div class="notification is-primary">
          <?php echo htmlspecialchars($status); ?>
        </div>
      <?php endif; ?>
      <h4 class="label is-4">Update Profile</h4>
      <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
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
        </div>
        <div class="control"><button type="submit" class="button is-primary">Submit</button></div>
      </form>
    </div>
    <div class="column bot-box is-5">
      <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
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
    <div class="column bot-box is-4">
      <h4 class="label is-4">Storage Used</h4>
      <div class="progress-bar-container">
        <div class="progress-bar has-text-black-bis" style="width: <?php echo $storage_percentage; ?>%;"><?php echo round($storage_percentage, 2); ?>%</div>
      </div>
      <p><?php echo round($current_storage_used / 1024 / 1024, 2); ?>MB of <?php echo round($max_storage_size / 1024 / 1024, 2); ?>MB used</p>
      <?php if ($is_admin): ?>
        <br>
        <h4 class="label is-4">Server Storage Information</h4>
        <?php
        $server_storage_info = '';
        if ($is_admin) {
          // Get server storage information
          $total_space = disk_total_space("/");
          $free_space = disk_free_space("/");
          $used_space = $total_space - $free_space;
          $server_storage_info = [
            'total' => round($total_space / 1024 / 1024 / 1024, 2) . ' GB',
            'used' => round($used_space / 1024 / 1024 / 1024, 2) . ' GB',
            'free' => round($free_space / 1024 / 1024 / 1024, 2) . ' GB'
          ];
          $server_storage_percentage = ($used_space / $total_space) * 100;
        }
        ?>
        <div class="progress-bar-container">
          <div class="progress-bar has-text-black-bis" style="width: <?php echo $server_storage_percentage; ?>%;"><?php echo round($server_storage_percentage, 2); ?>%</div>
        </div>
        <p class="label is-6">Total: <?php echo $server_storage_info['total']; ?> | Used: <?php echo $server_storage_info['used']; ?> | Free: <?php echo $server_storage_info['free']; ?></p>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="modal" id="regen-api-key">
  <div class="modal-background"></div>
  <div class="modal-card">
    <header class="modal-card-head has-background-dark">
      <p class="modal-card-title has-text-white">Regenerating API Key Warning</p>
      <button class="delete" aria-label="close" id="regen-api-key-close"></button>
    </header>
    <section class="modal-card-body has-background-dark has-text-white">
        <p>Regenerating your API Key will require a restart of the entire system, including the Twitch Chat Bot, Discord Bot, and Stream Overlays.</p>
        <p>Please ensure you go back to the dashboard to restart them.</p>
        <br>
        <button id="confirm-regen" class="button is-danger">Confirm</button>
      </section>
  </div>
</div>

<!-- Include the JavaScript files -->
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="/js/profile.js"></script>
<script src="/js/timezone.js"></script>

<!-- Warning Modal JavaScript -->
<script>
  // Modal handling: Open and close modals
  const modalIds = [
    { open: "regen-api-key-open", close: "regen-api-key-close" }
  ];

  modalIds.forEach(modal => {
    const openButton = document.getElementById(modal.open);
    const closeButton = document.getElementById(modal.close);

    if (openButton) {
      openButton.addEventListener("click", function() {
        document.getElementById(modal.close.replace('-close', '')).classList.add("is-active");
      });
    }
    if (closeButton) {
      closeButton.addEventListener("click", function() {
        document.getElementById(modal.close.replace('-close', '')).classList.remove("is-active");
      });
    }
  });

  // Confirm Regen button action
  document.getElementById("confirm-regen").addEventListener("click", function() {
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "regen_api_key.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    // Handle the response when the API key is regenerated
    xhr.onload = function() {
        if (xhr.status == 200) {
            // Update the displayed API Key with the new one
            document.getElementById("api-key").textContent = xhr.responseText;
            alert("Your API key has been successfully regenerated.");
        } else {
            alert("Error regenerating API key. Please try again.");
        }
    };
    // Send the AJAX request to regenerate the API key
    xhr.send("action=regen_api_key");
  });
</script>

<!-- JavaScript code to convert and display the dates -->
<script>
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
</script>
</body>
</html>