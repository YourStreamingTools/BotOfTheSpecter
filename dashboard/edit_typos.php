<?php ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL); ?>
<?php
// Initialize the session
session_start();

// Check if user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Connect to database
require_once "db_connect.php";

// Fetch the user's data from the database based on the access_token
$access_token = $_SESSION['access_token'];
$userSTMT = $conn->prepare("SELECT * FROM users WHERE access_token = ?");
$userSTMT->bind_param("s", $access_token);
$userSTMT->execute();
$userResult = $userSTMT->get_result();
$user = $userResult->fetch_assoc();
$loggedInUsername = $user['username'];
$twitchDisplayName = $user['twitch_display_name'];
$twitch_profile_image_url = $user['profile_image'];
$is_admin = ($user['is_admin'] == 1);
$twitchUserId = $user['twitch_user_id'];
$authToken = $access_token;
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';

// Database connection for bot commands
$db = new PDO("sqlite:/var/www/bot/commands/{$loggedInUsername}_commands.db");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Fetch usernames from the user_typos table
try {
  $usernames = $db->query("SELECT username FROM user_typos")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
  echo "Error fetching usernames: " . $e->getMessage();
  $usernames = [];
}

// Handling form submission for updating typo count
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update') {
    $formUsername = $_POST['username'] ?? '';
    $typoCount = $_POST['typo_count'] ?? '';

    if ($formUsername && is_numeric($typoCount)) {
        try {
            $stmt = $db->prepare("UPDATE user_typos SET typo_count = :typo_count WHERE username = :username");
            $stmt->bindParam(':username', $formUsername);
            $stmt->bindParam(':typo_count', $typoCount, PDO::PARAM_INT);
            $stmt->execute();
            echo "Typo count updated successfully for user {$formUsername}.";
        } catch (PDOException $e) {
            echo "Error: " . $e->getMessage();
        }
    } else {
        echo "Invalid input.";
    }
}

// Handling form submission for removing a user
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'remove') {
    $formUsername = $_POST['username'] ?? '';

    try {
        $stmt = $db->prepare("DELETE FROM user_typos WHERE username = :username");
        $stmt->bindParam(':username', $formUsername, PDO::PARAM_STR);
        $stmt->execute();
        echo "Typo record for user '$formUsername' has been removed.";
    } catch (PDOException $e) {
        echo 'Error: ' . $e->getMessage();
    }
}

// Fetch usernames and their current typo counts
try {
  $typoData = $db->query("SELECT username, typo_count FROM user_typos")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  echo "Error fetching typo data: " . $e->getMessage();
  $typoData = [];
}
$status = "";
// Check for AJAX request to get the current typo count
if (isset($_GET['action']) && $_GET['action'] == 'get_typo_count' && isset($_GET['username'])) {
  $requestedUsername = $_GET['username'];
  // Fetch the current typo count for the requested username
  $stmt = $db->prepare("SELECT typo_count FROM user_typos WHERE username = :username");
  $stmt->bindParam(':username', $requestedUsername);
  $stmt->execute();
  $result = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($result) {
    $status = $result['typo_count'];
  } else {
    $status = "0";
  }
  exit;
}

// Prepare a JavaScript object with typo counts for each user
$typoCountsJs = json_encode(array_column($typoData, 'typo_count', 'username'));
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BotOfTheSpecter - Edit Typos</title>
    <link rel="stylesheet" href="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.min.css">
    <link rel="stylesheet" href="https://cdn.yourstreaming.tools/css/custom.css">
    <link rel="stylesheet" href="pagination.css">
    <script src="about.js"></script>
  	<link rel="icon" href="https://cdn.botofthespecter.com/logo.png">
  	<link rel="apple-touch-icon" href="https://cdn.botofthespecter.com/logo.png">
    <script>var typoCounts = <?php echo $typoCountsJs ?></script>
    <!-- <?php echo "User: $username | $twitchUserId | $authToken"; ?> -->
  </head>
<body>
<!-- Navigation -->
<div class="title-bar" data-responsive-toggle="mobile-menu" data-hide-for="medium">
  <button class="menu-icon" type="button" data-toggle="mobile-menu"></button>
  <div class="title-bar-title">Menu</div>
</div>
<nav class="top-bar stacked-for-medium" id="mobile-menu">
  <div class="top-bar-left">
    <ul class="dropdown vertical medium-horizontal menu" data-responsive-menu="drilldown medium-dropdown hinge-in-from-top hinge-out-from-top">
      <li class="menu-text">BotOfTheSpecter</li>
      <li><a href="bot.php">Dashboard</a></li>
      <li>
        <a>Twitch Data</a>
        <ul class="vertical menu" data-dropdown-menu>
          <li><a href="mods.php">View Mods</a></li>
          <li><a href="followers.php">View Followers</a></li>
          <li><a href="subscribers.php">View Subscribers</a></li>
          <li><a href="vips.php">View VIPs</a></li>
        </ul>
      </li>
      <li><a href="logs.php">View Logs</a></li>
      <li><a href="counters.php">Counters</a></li>
      <li><a href="commands.php">Bot Commands</a></li>
      <li><a href="add-commands.php">Add Bot Command</a></li>
      <li class="is-active"><a href="edit_typos.php">Edit Typos</a></li>
      <li><a href="app.php">Download App</a></li>
      <li><a href="logout.php">Logout</a></li>
    </ul>
  </div>
  <div class="top-bar-right">
    <ul class="menu">
      <li><a class="popup-link" onclick="showPopup()">&copy; 2023-<?php echo date("Y"); ?> BotOfTheSpecter. All rights reserved.</a></li>
    </ul>
  </div>
</nav>
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
      <label for="username">Username:</label>
      <select id="username" name="username" required onchange="updateCurrentCount(this.value)">
        <option value="">Select a user</option>
        <?php foreach ($usernames as $name): ?>
          <option value="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></option>
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
      <label for="username">Username:</label>
      <select id="username" name="username" required onchange="updateCurrentCount(this.value)">
        <option value="">Select a user</option>
        <?php foreach ($usernames as $name): ?>
          <option value="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></option>
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