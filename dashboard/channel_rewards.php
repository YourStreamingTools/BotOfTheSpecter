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
$title = "Dashboard";

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
$betaAccess = ($user['beta_access'] == 1);
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
include 'beta_bot_control.php';
include 'sqlite.php';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['rewardid']) && isset($_POST['newCustomMessage'])) {
  // Process the update here
  $rewardid = $_POST['rewardid'];
  $newCustomMessage = $_POST['newCustomMessage'];

  // Update the welcome message in the database
  $messageQuery = $db->prepare("UPDATE channel_point_rewards SET custom_message = :custom_message WHERE reward_id = :rewardid");
  $messageQuery->bindParam(':custom_message', $newCustomMessage);
  $messageQuery->bindParam(':rewardid', $rewardid);
  $messageQuery->execute();
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
<table class="bot-table">
  <thead>
    <tr>
      <th>ID</th>
      <th>Name</th>
      <th>Cost</th>
      <th>Bot Message</th>
      <th>Edit</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($channelPointRewards as $reward): ?>
      <tr>
        <td><?php echo isset($reward['reward_id']) ? htmlspecialchars($reward['reward_id']) : ''; ?></td>
        <td><?php echo isset($reward['reward_title']) ? htmlspecialchars($reward['reward_title']) : ''; ?></td>
        <td><?php echo isset($reward['reward_cost']) ? htmlspecialchars($reward['reward_cost']) : ''; ?></td>
        <td>
          <div id="<?php echo $reward['reward_id']; ?>">
            <?php echo isset($reward['custom_message']) ? htmlspecialchars($reward['custom_message']) : ''; ?>
          </div>
          <div class="edit-box" id="edit-box-<?php echo $reward['reward_id']; ?>" style="display: none;">
            <textarea class="custom-message" data-reward-id="<?php echo $reward['reward_id']; ?>"><?php echo isset($reward['custom_message']) ? htmlspecialchars($reward['custom_message']) : ''; ?></textarea>
          </div>
        </td>
        <td>
          <button class="edit-btn" data-reward-id="<?php echo $reward['reward_id']; ?>"><i class="fa-solid fa-pencil-alt"></i></button>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<br><br>
</div>

<script>
document.querySelectorAll('.edit-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const rewardid = this.getAttribute('data-reward-id');
    const editBox = document.getElementById('edit-box-' + rewardid);
    const customMessage = document.getElementById(rewardid);
    
    if (editBox.style.display === 'none') {
      // Show the edit box and hide the welcome message
      editBox.style.display = 'block';
      customMessage.style.display = 'none';
      // Change the color of the edit button
      this.classList.add('editing');
    } else {
      // Save the updated welcome message
      const newCustomMessage = editBox.querySelector('.custom-message').value;
      updateWelcomeMessage(rewardid, newCustomMessage);
      // Remove the editing class from the edit button
      this.classList.remove('editing');
    }
  });
});

function updateWelcomeMessage(rewardid, newCustomMessage) {
  var xhr = new XMLHttpRequest();
  xhr.open("POST", "", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      location.reload();
    }
  };
  xhr.send("rewardid=" + encodeURIComponent(rewardid) + "&newCustomMessage=" + encodeURIComponent(newCustomMessage));
}
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://dhbhdrzi4tiry.cloudfront.net/cdn/sites/foundation.js"></script>
<script>$(document).foundation();</script>
</body>
</html>