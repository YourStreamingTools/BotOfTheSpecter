<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Edit Typos";

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
$twitchUserId = $user['twitch_user_id'];
$broadcasterID = $twitchUserId;
$authToken = $access_token;
$refreshToken = $user['refresh_token'];
$webhookPort = $user['webhook_port'];
$websocketPort = $user['websocket_port'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';

// Database connection for bot commands
try {
  $db = new PDO("sqlite:/var/www/bot/commands/{$username}.db");
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  $status = "Error connecting to the database: " . $e->getMessage();
  exit();
}

// Fetch usernames from the user_typos table
try {
  $stmt = $db->prepare("SELECT username FROM user_typos");
  $stmt->execute();
  $usernames = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
  $status = "Error fetching usernames: " . $e->getMessage();
  $usernames = [];
}

// Handling form submission for updating typo count
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update') {
  $formUsername = $_POST['typo-username'] ?? '';
  $typoCount = $_POST['typo_count'] ?? '';

  if ($formUsername && is_numeric($typoCount)) {
    try {
      $stmt = $db->prepare("UPDATE user_typos SET typo_count = :typo_count WHERE username = :username");
      $stmt->bindParam(':username', $formUsername);
      $stmt->bindParam(':typo_count', $typoCount, PDO::PARAM_INT);
      $stmt->execute();
      $status = "Typo count updated successfully for user {$formUsername}.";
    } catch (PDOException $e) {
      $status = "Error: " . $e->getMessage();
    }
  } else {
    $status = "Invalid input.";
  }
}

// Handling form submission for removing a user
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'remove') {
  $formUsername = $_POST['typo-username-remove'] ?? '';
  try {
    $stmt = $db->prepare("DELETE FROM user_typos WHERE username = :username");
    $stmt->bindParam(':username', $formUsername, PDO::PARAM_STR);
    $stmt->execute();
    $status = "Typo record for user '$formUsername' has been removed.";
  } catch (PDOException $e) {
    echo 'Error: ' . $e->getMessage();
  }
}

// Fetch usernames and their current typo counts
try {
  $stmt = $db->prepare("SELECT username, typo_count FROM user_typos");
  $stmt->execute();
  $typoData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $status = "Error fetching typo data: " . $e->getMessage();
  $typoData = [];
}

$status = "";

// Check for AJAX request to get the current typo count
if (isset($_GET['action']) && $_GET['action'] == 'get_typo_count' && isset($_GET['username'])) {
  $requestedUsername = $_GET['username'];
  try {
    $stmt = $db->prepare("SELECT typo_count FROM user_typos WHERE username = :username");
    $stmt->bindParam(':username', $requestedUsername);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
      $status = $result['typo_count'];
    } else {
      $status = "0";
    }
    echo $status;
  } catch (PDOException $e) {
      $status = "Error: " . $e->getMessage();
  }
  exit;
}

// Prepare a JavaScript object with typo counts for each user
$typoCountsJs = json_encode(array_column($typoData, 'typo_count', 'username'));
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <!-- Headder -->
    <?php include('header.php'); ?>
    <!-- /Headder -->
  </head>
<body>
<!-- Navigation -->
<?php include('navigation.php'); ?>
<!-- /Navigation -->

<div class="row column">
  <br>
  <h1><?php echo "$greeting, $twitchDisplayName <img id='profile-image' src='$twitch_profile_image_url' width='50px' height='50px' alt='$twitchDisplayName Profile Image'>"; ?></h1>
  <br>
  <div class="row">
    <div class="small-12 medium-6 columns">
      <h2>Edit User Typos</h2>
      <form action="" method="post">
        <input type="hidden" name="action" value="update">
        <label for="typo-username">Username:</label>
        <select id="typo-username" name="typo-username" required onchange="updateCurrentCount(this.value)">
          <option value="">Select a user</option>
          <?php foreach ($usernames as $typo_name): ?>
            <option value="<?php echo htmlspecialchars($typo_name); ?>"><?php echo htmlspecialchars($typo_name); ?></option>
          <?php endforeach; ?>
        </select>
        <div id="current-typo-count"></div>
        <label for="typo_count">New Typo Count:</label>
        <input type="number" id="typo_count" name="typo_count" required min="0">
        <input type="submit" class="defult-button" value="Update Typo Count">
      </form>
      <?php echo "<p>$status</p>" ?>
    </div>
    <div class="small-12 medium-6 columns">
      <h2>Remove User Typo Record</h2>
      <form action="" method="post">
        <input type="hidden" name="action" value="remove">
        <label for="typo-username-remove">Username:</label>
        <select id="typo-username-remove" name="typo-username-remove" required onchange="updateCurrentCount(this.value)">
          <option value="">Select a user</option>
          <?php foreach ($usernames as $typo_name): ?>
            <option value="<?php echo htmlspecialchars($typo_name); ?>"><?php echo htmlspecialchars($typo_name); ?></option>
          <?php endforeach; ?>
        </select>
        <input type="submit" class="defult-button" value="Remove Typo Record">
      </form>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
<script>
function updateCurrentCount(username) {
  if (username) {
    fetch('?action=get_typo_count&username=' + encodeURIComponent(username))
      .then(response => response.text())
      .then(data => {
        // Assuming that data is the typo count
        var typoCountInput = document.getElementById('typo_count');
        typoCountInput.value = data;
      })
      .catch(error => console.error('Error:', error));
  } else {
    var typoCountInput = document.getElementById('typo_count');
    typoCountInput.value = '';
  }
}
</script>
</body>
</html>