<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Twitch Data - Channel Point Rewards";

// Include all the information
require_once "/var/www/config/db_connect.php";
include 'userdata.php';
include 'bot_control.php';
include 'user_db.php';
include "mod_access.php";
foreach ($profileData as $profile) {
  $timezone = $profile['timezone'];
  $weather = $profile['weather_location'];
}
date_default_timezone_set($timezone);
$syncMessage = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['rewardid']) && isset($_POST['newCustomMessage'])) {
  // Process the update here
  $rewardid = $_POST['rewardid'];
  $newCustomMessage = $_POST['newCustomMessage'];
  // Update the custom message in the database
  $messageQuery = $db->prepare("UPDATE channel_point_rewards SET custom_message = :custom_message WHERE reward_id = :rewardid");
  $messageQuery->bindParam(':custom_message', $newCustomMessage);
  $messageQuery->bindParam(':rewardid', $rewardid);
  $messageQuery->execute();
  header('Location: channel_rewards.php');
}

// Handle reward deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['deleteRewardId'])) {
    $deleteRewardId = $_POST['deleteRewardId'];
    $deleteQuery = $db->prepare("DELETE FROM channel_point_rewards WHERE reward_id = :rewardid");
    $deleteQuery->bindParam(':rewardid', $deleteRewardId);
    $deleteQuery->execute();
    header('Location: channel_rewards.php');
}

// Handle sync button click
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['syncRewards'])) {
    // Escape shell arguments to ensure safe execution
    $escapedUsername = escapeshellarg($username);
    $escapedTwitchUserId = escapeshellarg($twitchUserId);
    $escapedAuthToken = escapeshellarg($authToken);
    // Run the sync script
    shell_exec("python3 /var/www/bot/sync-channel-rewards.py -channel $escapedUsername -channelid $escapedTwitchUserId -token $escapedAuthToken 2>&1");
    // Add a message or feedback to the user while processing the request
    $syncMessage = "<p>Syncing rewards, please wait...</p>";
    sleep(3); // Optionally, add a delay before refreshing
    header('Location: channel_rewards.php');
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
  <br>
  <h1 class="title is-4">Channel Point Rewards:</h1>
  <div class="notification is-info">
    <form method="POST" class="is-flex is-align-items-center is-justify-content-space-between" id="sync-form">
      <div>
        Pressing the sync button will sync your Twitch channel point rewards to the Specter System.
        This process can take up to 30 seconds to complete.<br>
        If you have removed a reward from Twitch, it will not be removed from the Specter System, and you will need to manually delete it.
      </div>
      <button class="button is-primary" name="syncRewards" type="submit" id="sync-btn">
        <span id="sync-btn-spinner" class="icon is-small" style="display:none;">
          <i class="fas fa-spinner fa-spin"></i>
        </span>
        <span id="sync-btn-text">Sync Rewards</span>
      </button>
    </form>
  </div>
  <div class="notification is-info mt-4">
    <table>
      <td style="width: 50%; padding-right: 30px;">
        <p class="has-text-weight-bold">Built-in Tags Information:</p>
        <p>You can set up specific channel point rewards in Twitch to access Specter's built-in tags, any channel point name containing either of the tag will trigger the function.</p>
        <ul>
          <li><span class="has-text-weight-bold">Fortunes</span>: Channel point reward name "fortune"</li>
          <li><span class="has-text-weight-bold">Lotto Numbers</span>: Channel point reward name containing "lotto"</li>
          <li><span class="has-text-weight-bold">Text-to-Speech (TTS)</span>: Channel point reward name containing "tts".
          <br>TTS is then used via the sound overlays on the <a href="overlays.php">Overlays</a> page.</span></li>
        </ul>
      </td>
      <td style="width: 50%;">
        <p class="has-text-weight-bold">Custom Variables for Custom Messages | <span style="color: #000000;">BLACK</span> = Beta Bot Only</p>
        <p>You can use the following custom variables in the message to send to chat when a reward is triggered.</p>
        <ul>
          <li><span class="has-text-weight-bold">(user)</span>: This will tag the user that has used the channel point reward.</li>
          <li><span class="has-text-weight-bold">(usercount)</span>: This will count how many times a user has used the channel point reward. </li>
          <li><span class="has-text-weight-bold" style="color: #000000;">(userstreak)</span>: This will count how many times in a row the user has used this channel point reward.</li>
        </ul>
      </td>
    </table>
  </div>
  <table class="table is-striped is-fullwidth">
    <thead>
      <tr>
        <th>Reward Name</th>
        <th>Custom Message</th>
        <th style="width: 150px;">Reward Cost</th>
        <th style="width: 100px;">Editing</th>
        <th style="width: 100px;">Deleting</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($channelPointRewards as $reward): ?>
        <tr>
          <td><?php echo isset($reward['reward_title']) ? htmlspecialchars($reward['reward_title']) : ''; ?></td>
          <td>
            <div id="<?php echo $reward['reward_id']; ?>">
              <?php echo isset($reward['custom_message']) ? htmlspecialchars($reward['custom_message']) : ''; ?>
            </div>
            <div class="edit-box" id="edit-box-<?php echo $reward['reward_id']; ?>" style="display: none;">
              <textarea class="textarea custom-message" data-reward-id="<?php echo $reward['reward_id']; ?>"><?php echo isset($reward['custom_message']) ? htmlspecialchars($reward['custom_message']) : ''; ?></textarea>
            </div>
          </td>
          <td><?php echo isset($reward['reward_cost']) ? htmlspecialchars($reward['reward_cost']) : ''; ?></td>
          <td>
            <button class="button is-small is-info edit-btn" data-reward-id="<?php echo $reward['reward_id']; ?>"><i class="fas fa-pencil-alt"></i></button>
          </td>
          <td>
            <button class="button is-small is-danger delete-btn" data-reward-id="<?php echo $reward['reward_id']; ?>"><i class="fas fa-trash-alt"></i></button>
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

document.querySelectorAll('.delete-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const rewardid = this.getAttribute('data-reward-id');
    if (confirm('Are you sure you want to delete this reward?')) {
      deleteReward(rewardid);
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

function deleteReward(rewardid) {
  var xhr = new XMLHttpRequest();
  xhr.open("POST", "", true);
  xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  xhr.onreadystatechange = function() {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      location.reload();
    }
  };
  xhr.send("deleteRewardId=" + encodeURIComponent(rewardid));
}

// Sync Rewards button spinner and disable logic
document.getElementById('sync-form').addEventListener('submit', function(e) {
  var btn = document.getElementById('sync-btn');
  var spinner = document.getElementById('sync-btn-spinner');
  var text = document.getElementById('sync-btn-text');
  btn.disabled = true;
  spinner.style.display = '';
  text.textContent = 'Syncing Rewards...';
});
</script>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
</body>
</html>