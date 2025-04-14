<?php 
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$title = "Discord Bot";

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

// Check if the user is already linked with Discord
$discord_userSTMT = $conn->prepare("SELECT * FROM discord_users WHERE user_id = ?");
$discord_userSTMT->bind_param("i", $user_id);
$discord_userSTMT->execute();
$discord_userResult = $discord_userSTMT->get_result();
$is_linked = ($discord_userResult->num_rows > 0);

$buildStatus = "";
$errorMsg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  try {
    if (isset($_POST['option']) && isset($_POST['webhook'])) {
      // Update webhook URL based on the selected option
      $option = $_POST['option'];
      $webhook = $_POST['webhook'];
      $profile_key = "";
      switch ($option) {
        case 'discord_alert':
          $profile_key = "discord_alert";
          break;
        case 'discord_mod':
          $profile_key = "discord_mod";
          break;
        case 'discord_alert_online':
          $profile_key = "discord_alert_online";
          break;
        default:
          $buildStatus = "Invalid option";
          exit;
      }
      $stmt = $db->prepare("UPDATE profile SET $profile_key = :webhook");
      $stmt->bindParam(':webhook', $webhook);
      if ($stmt->execute()) {
        $buildStatus = "Webhook URL updated successfully";
      } else {
        $errorMsg = "Error updating webhook URL: " . $stmt->errorInfo()[2];
      }
      $stmt->closeCursor();
    } elseif (isset($_POST['live_channel_id']) && isset($_POST['guild_id'])) {
      // Update live_channel_id and guild_id
      $live_channel_id = $_POST['live_channel_id'];
      $guild_id = $_POST['guild_id'];
      $stmt = $conn->prepare("UPDATE discord_users SET live_channel_id = ?, guild_id = ? WHERE user_id = ?");
      $stmt->bind_param("ssi", $live_channel_id, $guild_id, $user_id);
      if ($stmt->execute()) {
        $buildStatus = "Live Channel ID and Guild ID updated successfully";
      } else {
        $errorMsg = "Error updating Live Channel ID and Guild ID: " . $stmt->error;
      }
      $stmt->close();
    } elseif (isset($_POST['online_text']) && isset($_POST['offline_text'])) {
      $onlineText = $_POST['online_text'];
      $offlineText = $_POST['offline_text'];
      $stmt = $conn->prepare("UPDATE discord_users SET online_text = ?, offline_text = ? WHERE user_id = ?");
      $stmt->bind_param("ssi", $onlineText, $offlineText, $user_id);
      if ($stmt->execute()) {
        $buildStatus = "Online and Offline Text has been updated successfully";
      } else {
        $errorMsg = "Error updating Online and Offline Text: " . $stmt->error;
      }
      $stmt->close();
    }
  } catch (mysqli_sql_exception $e) {
    if (strpos($e->getMessage(), 'Data too long for column') !== false) {
      $errorMsg = "The text entered is too long. Please reduce the length and try again.";
    } else {
      $errorMsg = "An error occurred: " . $e->getMessage();
    }
  }
}

// Fetch existing webhook URLs
$webhookKeys = ['discord_alert', 'discord_mod', 'discord_alert_online'];
$existingWebhooks = [];

foreach ($webhookKeys as $key) {
  $stmt = $db->prepare("SELECT $key FROM profile");
  $stmt->execute();
  $result = $stmt->fetch(PDO::FETCH_ASSOC);
  $existingWebhooks[$key] = $result ? $result[$key] : "";
  $stmt->closeCursor();
}

// Fetch existing live_channel_id and guild_id
$discord_userSTMT = $conn->prepare("SELECT * FROM discord_users WHERE user_id = ?");
$discord_userSTMT->bind_param("i", $user_id);
$discord_userSTMT->execute();
$discord_userResult = $discord_userSTMT->get_result();
$discordData = $discord_userResult->fetch_assoc();
$existingLiveChannelId = $discordData['live_channel_id'] ?? "";
$existingGuildId = $discordData['guild_id'] ?? "";
$existingOnlineText = $discordData['online_text'] ?? "";
$existingOfflineText = $discordData['offline_text'] ?? "";
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
  <?php if (!$is_linked) { ?>
    <div class="is-centered has-text-centered">
      <h3 class="subtitle is-5">By linking your Discord account to Specter, you'll unlock some exciting features.<br>
        You'll receive alerts from Specter directly in your Discord server via a webhook, and our new feature will update a voice channel when you go live.
      </h3>
      <br>
      <button class="button is-link" onclick="linkDiscord()">Link Discord</button>
    </div>
  <?php } else { ?>
    <div class="is-centered has-text-centered">
      <h4 class="subtitle is-5">Thank you for linking your account.<br>
        We're constantly adding new Specter features to the Discord Bot, so keep an eye on the Discord server for updates.</h4>
      <button class="button is-link" onclick="discordBotDashboard()">BotOfTheSpecter Discord Bot Dashboard</button>
    </div>
    <?php if ($_SERVER["REQUEST_METHOD"] == "POST") { ?>
      <?php if ($buildStatus) { ?>
        <p class='has-text-success'><?php echo $buildStatus; ?></p>
      <?php } ?>
      <?php if ($errorMsg) { ?>
        <p class='has-text-danger'><?php echo $errorMsg; ?></p>
      <?php } ?>
    <?php } ?>
    <br>
    <div class="columns is-desktop is-multiline is-centered">
      <!-- Webhook URL Form -->
      <div class="column is-two-fifths bot-box">
        <h2 class="title is-4">Add Discord Webhook URLs</h2>
        <form action="" method="post">
          <div class="field">
            <label for="option">Select an option:</label>
            <div class="control">
              <div class="select">
                <select id="option" name="option">
                  <option value="discord_alert">Discord Alert (For Twitch Logs)</option>
                  <option value="discord_mod">Discord Mod Alert (For Mod logs from Twitch)</option>
                  <option value="discord_alert_online">Discord Alert Online (For posting in discord when the stream is online)</option>
                </select>
              </div>
            </div>
          </div>
          <div class="field">
            <label for="webhook">Discord Webhook URL:</label>
            <div class="control has-icons-left">
              <input class="input" type="text" id="webhook" name="webhook" required>
              <div class="icon is-small is-left"><i class="fas fa-link"></i></div>
            </div>
          </div>
          <div class="control">
            <button class="button is-primary" type="submit">Submit</button>
          </div>
        </form>
      </div>
      <!-- Live Channel ID and Guild ID Form -->
      <div class="column is-two-fifths bot-box">
        <h2 class="title is-4">Set Live Channel ID and Guild ID</h2>
        <form action="" method="post">
          <div class="field">
            <label for="live_channel_id">Live Channel ID:</label>
            <p class="help">This is the Channel ID of the voice channel you wish to update with the live status.</p>
            <div class="control has-icons-left">
              <input class="input" type="text" id="live_channel_id" name="live_channel_id" value="<?php echo htmlspecialchars($existingLiveChannelId); ?>" required>
              <div class="icon is-small is-left"><i class="fas fa-id-card"></i></div>
            </div>
          </div>
          <div class="field">
            <label for="guild_id">Guild ID:</label>
            <p class="help">This is your discord Server/Guild ID</p>
            <div class="control has-icons-left">
              <input class="input" type="text" id="guild_id" name="guild_id" value="<?php echo htmlspecialchars($existingGuildId); ?>" required>
              <div class="icon is-small is-left"><i class="fas fa-id-card"></i></div>
            </div>
          </div>
          <div class="control">
            <button class="button is-primary" type="submit">Submit</button>
          </div>
        </form>
      </div>
      <!-- Online and Offline Text Updates -->
      <div class="column is-two-fifths bot-box">
        <h2 class="title is-4">Set Discord Channel Text</h2>
        <form action="" method="post">
          <div class="field">
            <label for="online_text">Online Text:</label>
            <div class="control has-icons-left">
              <input class="input" type="text" id="online_text" name="online_text" value="<?php echo htmlspecialchars($existingOnlineText); ?>" required>
              <div class="icon is-small is-left"><i style="color: green;" class="fas fa-circle"></i></div>
            </div>
          </div>
          <div class="field">
            <label for="offline_text">Offline Text:</label>
            <div class="control has-icons-left">
              <input class="input" type="text" id="offline_text" name="offline_text" value="<?php echo htmlspecialchars($existingOfflineText); ?>" required>
              <div class="icon is-small is-left"><i style="color: red;" class="fas fa-circle"></i></div>
            </div>
          </div>
          <div class="control">
            <button class="button is-primary" type="submit">Submit</button>
          </div>
        </form>
      </div>
    </div>
  <?php } ?>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script>
  $(document).ready(function() {
    var webhooks = <?php echo json_encode($existingWebhooks); ?>;
    var initialWebhook = webhooks['discord_alert'] || '';
    $('#webhook').val(initialWebhook);
    $('#option').change(function() {
      var selectedOption = $(this).val();
      $('#webhook').val(webhooks[selectedOption] || '');
    });
  });
</script>
<?php if (!$is_linked) { ?>
  <script>
    function linkDiscord() {
      window.location.href = "https://discord.com/oauth2/authorize?client_id=1170683250797187132&response_type=code&redirect_uri=https%3A%2F%2Fdashboard.botofthespecter.com%2Fdiscord_auth.php&scope=identify+openid+guilds";
    }
  </script>
<?php } else { ?>
  <script>
    function discordBotDashboard() {
      window.open("https://discord.botofthespecter.com/", "_blank");
    }
  </script>
<?php } ?>
</body>
</html>