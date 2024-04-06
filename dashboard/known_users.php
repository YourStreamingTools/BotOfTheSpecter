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
$webhookPort = $user['webhook_port'];
$websocketPort = $user['websocket_port'];
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
$statusOutput = 'Bot Status: Unknown';
$pid = '';
include 'bot_control.php';
include 'sqlite.php';

// Check if the update request is sent via POST
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['username']) && isset($_POST['status'])) {
  // Process the update here
  $dbusername = $_POST['username'];
  $status = $_POST['status'];

  // Update the status in the database
  $updateQuery = $db->prepare("UPDATE seen_users SET status = :status WHERE username = :username");
  $updateQuery->bindParam(':status', $status);
  $updateQuery->bindParam(':username', $dbusername);
  $updateQuery->execute();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['userId']) && isset($_POST['newWelcomeMessage'])) {
  // Process the update here
  $userId = $_POST['userId'];
  $newWelcomeMessage = $_POST['newWelcomeMessage'];

  // Update the welcome message in the database
  $messageQuery = $db->prepare("UPDATE seen_users SET welcome_message = :welcome_message WHERE id = :user_id");
  $messageQuery->bindParam(':welcome_message', $newWelcomeMessage);
  $messageQuery->bindParam(':user_id', $userId);
  $messageQuery->execute(); // Correct spelling here
  echo "<script>window.location.reload();</script>";
}
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
<h2>Known Users & Welcome Messages</h2>
<p style='color: red;'>Soon, you'll be able to edit welcome messages. With a toggle button, you can enable or disable the bot's response to a user's welcome message.</p>
<table class="bot-table">
  <thead>
    <tr>
      <th>Username</th>
      <th>Welcome Message</th>
      <th>Status</th>
      <th>Action</th>
      <th>Edit</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($seenUsersData as $userData): ?>
      <tr>
        <td><?php echo isset($userData['username']) ? htmlspecialchars($userData['username']) : ''; ?></td>
        <td>
          <div id="welcome-message-<?php echo $userData['id']; ?>">
            <?php echo isset($userData['welcome_message']) ? htmlspecialchars($userData['welcome_message']) : ''; ?>
          </div>
          <div class="edit-box" id="edit-box-<?php echo $userData['id']; ?>" style="display: none;">
            <textarea class="welcome-message" data-user-id="<?php echo $userData['id']; ?>"><?php echo isset($userData['welcome_message']) ? htmlspecialchars($userData['welcome_message']) : ''; ?></textarea>
          </div>
        </td>
        <td>
          <span style="color: <?php echo $userData['status'] == 'True' ? 'green' : 'red'; ?>">
            <?php echo isset($userData['status']) ? htmlspecialchars($userData['status']) : ''; ?>
          </span>
        </td>
        <td>
          <label class="switch">
            <input type="checkbox" class="toggle-checkbox" <?php echo $userData['status'] == 'True' ? 'checked' : ''; ?> onchange="toggleStatus('<?php echo $userData['username']; ?>', this.checked)">
            <i class="fa-solid <?php echo $userData['status'] == 'True' ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
          </label>
        </td>
        <td>
          <button class="edit-btn" data-user-id="<?php echo $userData['id']; ?>"><i class="fa-solid fa-pencil-alt"></i></button>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
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
</script>
<script>
document.querySelectorAll('.edit-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const userId = this.getAttribute('data-user-id');
    const editBox = document.getElementById('edit-box-' + userId);
    editBox.style.display = editBox.style.display === 'none' ? 'block' : 'none';
    const welcomeMessage = document.getElementById('welcome-message-' + userId);
    welcomeMessage.style.display = welcomeMessage.style.display === 'none' ? 'block' : 'none'; // Hide the welcome message when showing the edit box
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
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
</body>
</html>