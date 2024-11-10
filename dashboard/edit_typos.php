<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Edit Typos";

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
$status = '';

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
    <div class="column is-half">
      <h2 class="title is-5">Edit User Typos</h2>
      <form action="" method="post">
        <input type="hidden" name="action" value="update">
        <div class="field">
          <label class="label" for="typo-username">Username:</label>
          <div class="control">
            <div class="select">
              <select id="typo-username" name="typo-username" required onchange="updateCurrentCount(this.value)">
                <option value="">Select a user</option>
                <?php foreach ($usernames as $typo_name): ?>
                  <option value="<?php echo htmlspecialchars($typo_name); ?>"><?php echo htmlspecialchars($typo_name); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="field">
          <label class="label" for="typo_count">New Typo Count:</label>
          <div class="control">
            <input class="input" type="number" id="typo_count" name="typo_count" required min="0">
          </div>
        </div>
        <div class="control"><button type="submit" class="button is-primary">Update Typo Count</button></div>
      </form>
      <?php echo "<p>$status</p>" ?>
    </div>
    <div class="column is-half">
      <h2 class="title is-5">Remove User Typo Record</h2>
      <form action="" method="post">
        <input type="hidden" name="action" value="remove">
        <div class="field">
          <label class="label" for="typo-username-remove">Username:</label>
          <div class="control">
            <div class="select">
              <select id="typo-username-remove" name="typo-username-remove" required onchange="updateCurrentCount(this.value)">
                <option value="">Select a user</option>
                <?php foreach ($usernames as $typo_name): ?>
                  <option value="<?php echo htmlspecialchars($typo_name); ?>"><?php echo htmlspecialchars($typo_name); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="control"><button type="submit" class="button is-danger">Remove Typo Record</button></div>
      </form>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script>
function updateCurrentCount(username) {
  if (username) {
    fetch('?action=get_typo_count&username=' + encodeURIComponent(username))
      .then(response => response.text())
      .then(data => {
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