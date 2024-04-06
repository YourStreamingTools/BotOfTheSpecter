<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize the session
session_start();

// check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Edit Custom Counts";

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
$statusOutput = 'Bot Status: Unknown';
$pid = '';
$status = "";
include 'bot_control.php';
include 'sqlite.php';

// Fetch commands from the custom_counts table
try {
  $stmt = $db->prepare("SELECT command FROM custom_counts");
  $stmt->execute();
  $commands = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
  $status = "Error fetching commands: " . $e->getMessage();
  $commands = [];
}

// Handling form submission for updating custom count
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update') {
  $formCommand = $_POST['command'] ?? '';
  $commandCount = $_POST['command_count'] ?? '';

  if ($formCommand && is_numeric($commandCount)) {
    try {
      $stmt = $db->prepare("UPDATE custom_counts SET count = :command_count WHERE command = :command");
      $stmt->bindParam(':command', $formCommand);
      $stmt->bindParam(':command_count', $commandCount, PDO::PARAM_INT);
      $stmt->execute();
      $status = "Count updated successfully for the command {$formCommand}.";
    } catch (PDOException $e) {
      $status = "Error: " . $e->getMessage();
    }
  } else {
    $status = "Invalid input.";
  }
}

// Fetch command counts
try {
  $stmt = $db->prepare("SELECT command, count FROM custom_counts");
  $stmt->execute();
  $commandData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $status = "Error fetching data: " . $e->getMessage();
  $commandData = [];
}

// Check for AJAX request to get the current typo count
if (isset($_GET['action']) && $_GET['action'] == 'get_command_count' && isset($_GET['command'])) {
  $requestedCommand = $_GET['command'];
  try {
    $stmt = $db->prepare("SELECT count FROM custom_counts WHERE command = :command");
    $stmt->bindParam(':command', $requestedCommand);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
      $status = $result['count'];
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
$commandCountsJs = json_encode(array_column($commandData, 'count', 'command'));
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
<div class="medium-4">
  <h2>Edit Custom Counter</h2>
  <form action="" method="post">
    <input type="hidden" name="action" value="update">
    <label for="command">Command:</label>
    <select id="command" name="command" required onchange="updateCurrentCount(this.value)">
      <option value="">Select a command</option>
      <?php foreach ($commands as $command): ?>
        <option value="<?php echo htmlspecialchars($command); ?>"><?php echo htmlspecialchars($command); ?></option>
      <?php endforeach; ?>
    </select>
    <div id="current-typo-count"></div>
    <label for="command_count">New Command Count:</label>
    <input type="number" id="command_count" name="command_count" required min="0">
    <input type="submit" class="defult-button" value="Update Command Count">
  </form>
  <?php echo "<p>$status</p>" ?>
</div>
</div>

<script>
function updateCurrentCount(command) {
  if (command) {
    fetch('?action=get_command_count&command=' + encodeURIComponent(command))
      .then(response => response.text())
      .then(data => {
        // Assuming that data is the typo count
        var commandCountInput = document.getElementById('command_count');
        commandCountInput.value = data;
      })
      .catch(error => console.error('Error:', error));
  } else {
    var commandCountInput = document.getElementById('command_count');
    commandCountInput.value = '';
  }
}
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
</body>
</html>