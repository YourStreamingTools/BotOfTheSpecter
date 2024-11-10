<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Edit Custom Counts";

// Include all the information
require_once "db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'sqlite.php';
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);
$greeting = 'Hello';
$status = "";

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
  <div class="column is-half">
    <h2 class="title is-5">Edit Custom Counter</h2>
    <form action="" method="post">
      <input type="hidden" name="action" value="update">
      <div class="field">
        <label class="label" for="command">Command:</label>
        <div class="control">
          <div class="select">
            <select id="command" name="command" required onchange="updateCurrentCount(this.value)">
              <option value="">Select a command</option>
              <?php foreach ($commands as $command): ?>
                <option value="<?php echo htmlspecialchars($command); ?>"><?php echo htmlspecialchars($command); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="field">
        <label class="label" for="command_count">New Command Count:</label>
        <div class="control">
          <input class="input" type="number" id="command_count" name="command_count" required min="0">
        </div>
      </div>
      <div class="control"><button type="submit" class="button is-primary">Update Command Count</button></div>
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
</body>
</html>