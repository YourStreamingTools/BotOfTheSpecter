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
$timezone = 'Australia/Sydney';
date_default_timezone_set($timezone);
$greeting = 'Hello';
include 'bot_control.php';
include 'sqlite.php';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['rewardid']) && isset($_POST['newCustomMessage'])) {
  // Process the update here
  $rewardid = $_POST['rewardid'];
  $newCustomMessage = $_POST['newCustomMessage'];
  // Update the custom message in the database
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
  <h1 class="title is-4">Channel Point Rewards:</h1>
  <table class="table is-striped is-fullwidth">
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
              <textarea class="textarea custom-message" data-reward-id="<?php echo $reward['reward_id']; ?>"><?php echo isset($reward['custom_message']) ? htmlspecialchars($reward['custom_message']) : ''; ?></textarea>
            </div>
          </td>
          <td>
            <button class="button is-small is-info edit-btn" data-reward-id="<?php echo $reward['reward_id']; ?>"><i class="fas fa-pencil-alt"></i></button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
document.querySelectorAll('.edit-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const rewardid = this.getAttribute('data-reward-id');
    const editBox = document.getElementById('edit-box-' + rewardid);
    const customMessage = document.getElementById(rewardid);
    
    if (editBox.style.display === 'none') {
      // Show the edit box and hide the custom message
      editBox.style.display = 'block';
      customMessage.style.display = 'none';
      // Change the color of the edit button
      this.classList.add('editing');
    } else {
      // Save the updated custom message
      const newCustomMessage = editBox.querySelector('.custom-message').value;
      updateCustomMessage(rewardid, newCustomMessage);
      // Remove the editing class from the edit button
      this.classList.remove('editing');
    }
  });
});

function updateCustomMessage(rewardid, newCustomMessage) {
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
</body>
</html>