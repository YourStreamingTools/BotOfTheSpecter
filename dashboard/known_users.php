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
$_SESSION['twitch_user_id'] = $twitchUserId;
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

// Handle POST requests for updates
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  if (isset($_POST['username']) && isset($_POST['status'])) {
    $dbusername = $_POST['username'];
    $status = $_POST['status'];
    $updateQuery = $db->prepare("UPDATE seen_users SET status = :status WHERE username = :username");
    $updateQuery->bindParam(':status', $status);
    $updateQuery->bindParam(':username', $dbusername);
    $updateQuery->execute();
  }

  if (isset($_POST['userId']) && isset($_POST['newWelcomeMessage'])) {
    $userId = $_POST['userId'];
    $newWelcomeMessage = $_POST['newWelcomeMessage'];
    $messageQuery = $db->prepare("UPDATE seen_users SET welcome_message = :welcome_message WHERE id = :user_id");
    $messageQuery->bindParam(':welcome_message', $newWelcomeMessage);
    $messageQuery->bindParam(':user_id', $userId);
    $messageQuery->execute();
    echo "<script>window.location.reload();</script>";
  }

  if (isset($_POST['deleteUserId'])) {
    $deleteUserId = $_POST['deleteUserId'];
    $deleteQuery = $db->prepare("DELETE FROM seen_users WHERE id = :user_id");
    $deleteQuery->bindParam(':user_id', $deleteUserId);
    $deleteQuery->execute();
    echo "<script>window.location.reload();</script>";
  }
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
        <tr>
          <td>
            <span class="username" data-username="<?php echo htmlspecialchars($userData['username']); ?>">
              <?php echo isset($userData['username']) ? htmlspecialchars($userData['username']) : ''; ?>
            </span>
            <span class="banned-status"></span>
          </td>
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
          <td>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="deleteUserId" value="<?php echo $userData['id']; ?>">
              <button type="submit" class="button is-small is-danger"><i class="fas fa-trash-alt"></i></button>
            </form>
          </td>
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
      editBox.style.display = 'block';
      welcomeMessage.style.display = 'none';
      this.classList.add('is-warning');
    } else {
      const newWelcomeMessage = editBox.querySelector('.welcome-message').value;
      updateWelcomeMessage(userId, newWelcomeMessage);
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

document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.username').forEach(usernameElement => {
    const username = usernameElement.dataset.username;
    fetchBannedStatus(username, usernameElement);
  });
});

function fetchBannedStatus(username, usernameElement) {
  const xhr = new XMLHttpRequest();
  xhr.open("POST", "fetch_banned_status.php", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE && xhr.status === 200) {
      const response = JSON.parse(xhr.responseText);
      const bannedStatusElement = usernameElement.nextElementSibling;
      if (response.banned) {
        bannedStatusElement.innerHTML = " <em style='color:red'>(banned)</em>";
      }
    }
  };
  xhr.send("usernameToCheck=" + encodeURIComponent(username));
}
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="/js/search.js"></script>
</body>
</html>