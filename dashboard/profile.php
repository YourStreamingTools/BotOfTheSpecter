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
$title = "Profile";
$status = "";
$timezone = "";
$weather = "";

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
$twitchUserId = $user['twitch_user_id'];
$signup_date = $user['signup_date'];
$last_login = $user['last_login'];
$api_key = $user['api_key'];
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}

// Convert the stored date and time to UTC using Sydney time zone (AEST/AEDT)
date_default_timezone_set($timezone);
$signup_date_utc = date_create_from_format('Y-m-d H:i:s', $signup_date)->setTimezone(new DateTimeZone('UTC'))->format('F j, Y g:i A');
$last_login_utc = date_create_from_format('Y-m-d H:i:s', $last_login)->setTimezone(new DateTimeZone('UTC'))->format('F j, Y g:i A');

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  // Check if timezone and weather_location are set
  if (isset($_POST["timezone"]) && isset($_POST["weather_location"])) {
      // Update the database with the new values
      $timezone = $_POST["timezone"];
      $weather_location = $_POST["weather_location"];
      $updateQuery = $db->prepare("UPDATE profile SET timezone = ?, weather_location = ?");
      $updateQuery->execute([$timezone, $weather_location]);
      $status = "Profile updated successfully!";
  } else {
    $status = "Error: Please provide both timezone and weather location.";
  }
}

// Function to get all PHP timezones
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
  <h1 class="title"><?php echo "$greeting, $twitchDisplayName <img id='profile-image' class='round-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
  <br>
  <div class="columns">
    <div class="column is-one-third">
      <table class="table is-fullwidth">
        <tr>
          <td>Your Username:</td>
          <td><?php echo $username; ?></td>
        </tr>
        <tr>
          <td>Display Name:</td>
          <td><?php echo $twitchDisplayName; ?></td>
        </tr>
        <tr>
          <td>You Joined:</td>
          <td><span id="localSignupDate"></span></td>
        </tr>
        <tr>
          <td>Your Last Login:</td>
          <td><span id="localLastLogin"></span></td>
        </tr>
        <tr>
          <td>Time Zone:</td>
          <td><?php echo $timezone; ?></td>
        </tr>
        <tr>
          <td>Weather Location:</td>
          <td><?php echo $weather; ?></td>
        </tr>
      </table>
      <p>Your API Key: <span class="api-key-wrapper api-text-black" style="display: none;"><?php echo $api_key; ?></span></p>
      <button type="button" class="button is-primary" id="show-api-key">Show API Key</button>
      <button type="button" class="button is-primary" id="hide-api-key" style="display:none;">Hide API Key</button>
    </div>
    <div class="column is-one-third">
      <h2 class="title is-4">Update Profile</h2>
      <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
        <div class="field">
          <label class="label" for="timezone">Timezone:</label>
          <div class="control">
            <div class="select">
              <select id="timezone" name="timezone">
                <?php
                $timezones = get_timezones();
                foreach ($timezones as $tz => $offset) {
                    $offset_prefix = $offset < 0 ? '-' : '+';
                    $offset_hours = gmdate('H:i', abs($offset));
                    $selected = ($tz == $timezone) ? 'selected' : '';
                    echo "<option value=\"$tz\" $selected>(UTC $offset_prefix$offset_hours) $tz</option>";
                }
                ?>
              </select>
            </div>
          </div>
        </div>
        <div class="field">
          <label class="label" for="weather_location">Weather Location:</label>
          <div class="control">
            <input class="input" type="text" id="weather_location" name="weather_location" value="<?php echo $weather; ?>">
          </div>
        </div>
        <div class="control"><button type="submit" class="button is-primary">Submit</button></div>
      </form>
      <br>
      <?php if (!empty($status)): ?>
        <div class="notification is-primary">
          <?php echo htmlspecialchars($status); ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Include the JavaScript files -->
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="/js/profile.js"></script>
<script src="/js/timezone.js"></script>

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