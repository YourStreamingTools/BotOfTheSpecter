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
$title = "Known Users";

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
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
$statusOutput = 'Bot Status: Unknown';
$pid = '';
include 'bot_control.php';
include 'sqlite.php';

// Check if the update request is sent via POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  if (isset($_POST['username']) && isset($_POST['status'])) {
    // Process the update here
    $dbusername = $_POST['username'];
    $status = $_POST['status'];

    // Update the status in the database
    $updateQuery = $db->prepare("UPDATE seen_users SET status = :status WHERE username = :username");
    $updateQuery->bindParam(':status', $status);
    $updateQuery->bindParam(':username', $dbusername);
    $updateQuery->execute();
  }

  if (isset($_POST['userId']) && isset($_POST['newWelcomeMessage'])) {
    // Process the update here
    $userId = $_POST['userId'];
    $newWelcomeMessage = $_POST['newWelcomeMessage'];

    // Update the welcome message in the database
    $messageQuery = $db->prepare("UPDATE seen_users SET welcome_message = :welcome_message WHERE id = :user_id");
    $messageQuery->bindParam(':welcome_message', $newWelcomeMessage);
    $messageQuery->bindParam(':user_id', $userId);
    $messageQuery->execute();
    echo "<script>window.location.reload();</script>";
  }

  if (isset($_POST['deleteUserId'])) {
    // Processing the user
    $userId = $_POST['deleteUserId'];

    // Updating the database
    $deleteQuery = $db->prepare("DELETE FROM seen_users WHERE id = :user_id");
    $deleteQuery->bindParam(':user_id', $userId);
    $deleteQuery->execute();
    echo "<script>window.location.reload();</script>";
  }
}

// Check if the users in the table are banned from the channel
function getTwitchUserId($userToCheck, $accesstoken) {
  $users_url = "https://api.twitch.tv/helix/users/?login=$userToCheck";
  $clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';
  $headers = [
    "Client-ID: $clientID",
    "Authorization: Bearer $accesstoken",
  ];
  $ch = curl_init($users_url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  $response = curl_exec($ch);
  curl_close($ch);
  $data = json_decode($response, true);
  return $data['data'][0]['id'];
}
function isUserBanned($userToCheck, $accesstoken, $broadcaster) {
  $banned_url = "https://api.twitch.tv/helix/moderation/banned?broadcaster_id=$broadcaster&user_id=$userToCheck";
  $clientID = 'mrjucsmsnri89ifucl66jj1n35jkj8';
  $headers = [
    "Client-ID: $clientID",
    "Authorization: Bearer $accesstoken",
  ];
  $ch = curl_init($banned_url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  $response = curl_exec($ch);
  curl_close($ch);
  $data = json_decode($response, true);
  return !empty($data['data']);
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
  <h2 class="title is-4">Known Users & Welcome Messages</h2>
  <p class="has-text-danger">Click the Edit Button within the users table, edit the welcome message in the text box, when done, click the edit button again to save.</p>
  
  <!-- Search Bar -->
  <input type="text" id="searchInput" class="input" placeholder="Search users..." onkeyup="searchFunction()">
  <br><br>
  
  <table class="table is-fullwidth" id="commandsTable">
    <thead>
      <tr>
        <th>Username</th>
        <th>Welcome Message</th>
        <th>Status</th>
        <th>Action</th>
        <th>Edit</th>
        <th>Remove</th>
      </tr>
    </thead>
    <tbody id="user-table">
      <?php foreach ($seenUsersData as $userData): ?>
        <?php 
          $userToCheckID = getTwitchUserId($userData['username'], $access_token);
          $banned = isUserBanned($userToCheckID, $access_token, $broadcasterID) ? " (banned)" : "";
          ?>
        <tr>
          <td><?php echo isset($userData['username']) ? htmlspecialchars($userData['username']) : ''; echo $banned; ?></td>
          <td>
            <div id="welcome-message-<?php echo $userData['id']; ?>">
              <?php echo isset($userData['welcome_message']) ? htmlspecialchars($userData['welcome_message']) : ''; ?>
            </div>
            <div class="edit-box" id="edit-box-<?php echo $userData['id']; ?>" style="display: none;">
              <textarea class="textarea welcome-message" data-user-id="<?php echo $userData['id']; ?>"><?php echo isset($userData['welcome_message']) ? htmlspecialchars($userData['welcome_message']) : ''; ?></textarea>
            </div>
          </td>
          <td>
            <span style="color: <?php echo $userData['status'] == 'True' ? 'green' : 'red'; ?>">
              <?php echo isset($userData['status']) ? htmlspecialchars($userData['status']) : ''; ?>
            </span>
          </td>
          <td>
            <label class="checkbox">
              <input type="checkbox" class="toggle-checkbox" <?php echo $userData['status'] == 'True' ? 'checked' : ''; ?> onchange="toggleStatus('<?php echo $userData['username']; ?>', this.checked)">
              <i class="fa-solid <?php echo $userData['status'] == 'True' ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
            </label>
          </td>
          <td>
            <button class="button is-small is-primary edit-btn" data-user-id="<?php echo $userData['id']; ?>"><i class="fas fa-pencil-alt"></i></button>
          </td>
          <td></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <br><br><br><br>
</div>

<script>
function toggleStatus(username, isChecked) {
    var status = isChecked ? 'True' : 'False';
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function() {
        if (xhr.readyState === XMLHttpRequest.DONE) {
            console.log(xhr.responseText);
            // Reload the page after the AJAX request is completed
            location.reload();
        }
    };
    xhr.send("username=" + encodeURIComponent(username) + "&status=" + status);
}

document.querySelectorAll('.edit-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const userId = this.getAttribute('data-user-id');
    const editBox = document.getElementById('edit-box-' + userId);
    const welcomeMessage = document.getElementById('welcome-message-' + userId);
    
    if (editBox.style.display === 'none') {
      // Show the edit box and hide the welcome message
      editBox.style.display = 'block';
      welcomeMessage.style.display = 'none';
      // Change the color of the edit button
      this.classList.add('is-warning');
    } else {
      // Save the updated welcome message
      const newWelcomeMessage = editBox.querySelector('.welcome-message').value;
      updateWelcomeMessage(userId, newWelcomeMessage);
      // Remove the warning class from the edit button
      this.classList.remove('is-warning');
    }
  });
});

function updateWelcomeMessage(userId, newWelcomeMessage) {
  var xhr = new XMLHttpRequest();
  xhr.open("POST", "", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      location.reload();
    }
  };
  xhr.send("userId=" + encodeURIComponent(userId) + "&newWelcomeMessage=" + encodeURIComponent(newWelcomeMessage));
}
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="/js/search.js"></script>
</body>
</html>