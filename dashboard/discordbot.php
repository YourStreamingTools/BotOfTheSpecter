<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
    header('Location: login.php');
    exit();
}

// Page Title
$pageTitle = t('discordbot_page_title');

// Include files for database and user data
require_once "/var/www/config/db_connect.php";
include '/var/www/config/twitch.php';
include '/var/www/config/discord.php';
include 'userdata.php';
include 'bot_control.php';
include "mod_access.php";
include 'user_db.php';
include 'storage_used.php';
$stmt = $db->prepare("SELECT timezone FROM profile");
$stmt->execute();
$result = $stmt->get_result();
$channelData = $result->fetch_assoc();
$timezone = $channelData['timezone'] ?? 'UTC';
$stmt->close();
date_default_timezone_set($timezone);

// Check if the user is already linked with Discord
$discord_userSTMT = $conn->prepare("SELECT * FROM discord_users WHERE user_id = ?");
$discord_userSTMT->bind_param("i", $user_id);
$discord_userSTMT->execute();
$discord_userResult = $discord_userSTMT->get_result();
$is_linked = ($discord_userResult->num_rows > 0);
$discord_userResult->close();
$discord_userSTMT->close();

$buildStatus = "";
$errorMsg = "";
$linkingMessage = "";
$linkingMessageType = "";

// Handle user denial (error=access_denied in query string)
if (isset($_GET['error']) && $_GET['error'] === 'access_denied') {
    $linkingMessage = "Authorization was denied. Please allow access to link your Discord account.";
    $linkingMessageType = "is-danger";
}

// Handle Discord OAuth callback
if (isset($_GET['code']) && !$is_linked) {
  // Validate state parameter for security
  if (!isset($_GET['state']) || !isset($_SESSION['discord_oauth_state']) || $_GET['state'] !== $_SESSION['discord_oauth_state']) {
    $linkingMessage = "Invalid state parameter. Please try again.";
    $linkingMessageType = "is-danger";
  } else {
    unset($_SESSION['discord_oauth_state']);
    $code = $_GET['code'];    // Exchange the authorization code for an access token
    $token_url = 'https://discord.com/api/oauth2/token';
    $data = array(
      'grant_type' => 'authorization_code',
      'code' => $code,
      'redirect_uri' => 'https://dashboard.botofthespecter.com/discordbot.php'
    );
    // Use HTTP Basic authentication as recommended by Discord
    $auth = base64_encode($client_id . ':' . $client_secret);
    $options = array(
      'http' => array(
        'header' => "Content-type: application/x-www-form-urlencoded\r\n" .
          "Authorization: Basic $auth\r\n",
        'method' => 'POST',
        'content' => http_build_query($data)
      )
    );
    $context = stream_context_create($options);
    $response = file_get_contents($token_url, false, $context);
    $params = json_decode($response, true);
    // Check if access token was received successfully
    if (isset($params['access_token'])) {
      // Get user information using the access token
      $user_url = 'https://discord.com/api/users/@me';
      $token = $params['access_token'];
      $user_options = array(
        'http' => array(
          'header' => "Authorization: Bearer $token\r\n",
          'method' => 'GET'
        )
      );
      $user_context = stream_context_create($user_options);
      $user_response = file_get_contents($user_url, false, $user_context);
      $user_data = json_decode($user_response, true);
      // Save user information to the database
      if (isset($user_data['id'])) {
        $discord_id = $user_data['id'];
        $refresh_token = $params['refresh_token'] ?? null;
        $expires_in = $params['expires_in'] ?? null;
        // Store Discord user information with tokens if available
        if ($refresh_token) {
          $sql = "INSERT INTO discord_users (user_id, discord_id, refresh_token, expires_in) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE discord_id = VALUES(discord_id), refresh_token = VALUES(refresh_token), expires_in = VALUES(expires_in)";
          $insertStmt = $conn->prepare($sql);
          $insertStmt->bind_param("issi", $user_id, $discord_id, $refresh_token, $expires_in);
        } else {
          $sql = "INSERT INTO discord_users (user_id, discord_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE discord_id = VALUES(discord_id)";
          $insertStmt = $conn->prepare($sql);
          $insertStmt->bind_param("is", $user_id, $discord_id);
        }
        if ($insertStmt->execute()) {
          $linkingMessage = "Discord account successfully linked!";
          $linkingMessageType = "is-success";
          $is_linked = true;
          // Redirect to refresh page and show linked status
          header("Location: discordbot.php");
          exit();
        } else {
          $linkingMessage = "Linked, but failed to save Discord information.";
          $linkingMessageType = "is-warning";
        }
        $insertStmt->close();
      } else {
        $linkingMessage = "Error: Failed to retrieve user information from Discord API.";
        $linkingMessageType = "is-danger";
      }
    } else {
      $linkingMessage = "Error: Failed to retrieve access token from Discord API.";
      $linkingMessageType = "is-danger";
      if (isset($params['error'])) { $linkingMessage .= " Error: " . htmlspecialchars($params['error']); }
      if (isset($params['error_description'])) { $linkingMessage .= " Description: " . htmlspecialchars($params['error_description']); }
    }
  }
}

$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) { die('Connection failed: ' . $db->connect_error); }
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
      $stmt = $db->prepare("UPDATE profile SET $profile_key = ?");
      $stmt->bind_param('s', $webhook);
      if ($stmt->execute()) {
        $buildStatus = "Webhook URL updated successfully";
      } else {
        $errorMsg = "Error updating webhook URL: " . $stmt->error;
      }
      $stmt->close();
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
  $result = $stmt->get_result();
  $row = $result->fetch_assoc();
  $existingWebhooks[$key] = $row ? $row[$key] : "";
  $stmt->close();
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

// Generate auth URL with state parameter for security
$authURL = '';
if (!$is_linked) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['discord_oauth_state'] = $state;
    $authURL = "https://discord.com/oauth2/authorize"
        . "?client_id=1170683250797187132"
        . "&response_type=code"
        . "&scope=" . urlencode('identify guilds')
        . "&state={$state}"
        . "&redirect_uri=" . urlencode('https://dashboard.botofthespecter.com/discordbot.php');
}

// Helper function to refresh Discord access token
function refreshDiscordToken($refresh_token, $client_id, $client_secret) {
    $token_url = 'https://discord.com/api/oauth2/token';
    $data = array(
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh_token
    );
    // Use HTTP Basic authentication as recommended by Discord
    $auth = base64_encode($client_id . ':' . $client_secret);
    $options = array(
        'http' => array(
            'header' => "Content-type: application/x-www-form-urlencoded\r\n" .
                       "Authorization: Basic $auth\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        )
    );
    $context = stream_context_create($options);
    $response = file_get_contents($token_url, false, $context);
    $params = json_decode($response, true);
    if (isset($params['access_token'])) {
        return $params;
    }
    return false;
}
// Start output buffering for layout
ob_start();
?>
<div class="columns is-centered">
  <div class="column is-fullwidth">
    <div class="card has-background-dark has-text-white mb-5" style="border-radius: 14px; box-shadow: 0 4px 24px #000a;">
      <header class="card-header" style="border-bottom: 1px solid #23272f;">
        <span class="card-header-title is-size-4 has-text-white" style="font-weight:700;">
          <span class="icon mr-2"><i class="fab fa-discord"></i></span>
          <?php echo t('discordbot_page_title'); ?>
        </span>
        <?php if ($is_linked) { ?>
          <div class="card-header-icon">
            <button class="button is-info is-medium" onclick="discordBotDashboard()" style="border-radius: 6px; font-weight: 600;">
              <span class="icon"><i class="fab fa-discord"></i></span>
              <span>Discord Bot Dashboard</span>
            </button>
          </div>
        <?php } ?>
      </header>
      <div class="card-content">
        <?php if ($linkingMessage): ?>
          <div class="notification <?php echo $linkingMessageType === 'is-success' ? 'is-success' : ($linkingMessageType === 'is-danger' ? 'is-danger' : 'is-warning'); ?> is-light" style="border-radius: 8px; margin-bottom: 1.5rem;">
            <span class="icon">
              <?php if ($linkingMessageType === 'is-danger'): ?>
                <i class="fas fa-exclamation-triangle"></i>
              <?php elseif ($linkingMessageType === 'is-success'): ?>
                <i class="fas fa-check"></i>
              <?php else: ?>
                <i class="fas fa-info-circle"></i>
              <?php endif; ?>
            </span>
            <?php echo $linkingMessage; ?>
          </div>
        <?php endif; ?>
        
        <?php if (!$is_linked) { ?>
          <div class="has-text-centered" style="padding: 3rem 2rem;">
            <div class="mb-5">
              <span class="icon is-large has-text-primary mb-3" style="font-size: 4rem;">
                <i class="fab fa-discord"></i>
              </span>
            </div>
            <h3 class="title is-4 has-text-white mb-3"><?php echo t('discordbot_link_title'); ?></h3>
            <p class="subtitle is-6 has-text-grey-light mb-5" style="max-width: 500px; margin: 0 auto;">
              <?php echo t('discordbot_link_desc'); ?>
            </p>
            <button class="button is-primary is-large" onclick="linkDiscord()" style="border-radius: 8px; font-weight: 600;">
              <span class="icon"><i class="fab fa-discord"></i></span>
              <span><?php echo t('discordbot_link_btn'); ?></span>
            </button>
          </div>
        <?php } else { ?>
          <div class="has-text-centered mb-5" style="padding: 1rem 2rem;">
            <h4 class="title is-5 has-text-white mb-3">
              <span class="icon mr-2 has-text-success" style="font-size: 1.2rem;">
                <i class="fas fa-check-circle"></i>
              </span>
              <?php echo t('discordbot_linked_title'); ?>
            </h4>
            <p class="subtitle is-6 has-text-grey-light mb-4">
              <?php echo t('discordbot_linked_desc'); ?>
            </p>
          </div>
          
          <?php if ($_SERVER["REQUEST_METHOD"] == "POST") { ?>
            <?php if ($buildStatus) { ?>
              <div class="notification is-success is-light" style="border-radius: 8px; margin-bottom: 1.5rem;">
                <span class="icon"><i class="fas fa-check"></i></span>
                <?php echo $buildStatus; ?>
              </div>
            <?php } ?>
            <?php if ($errorMsg) { ?>
              <div class="notification is-danger is-light" style="border-radius: 8px; margin-bottom: 1.5rem;">
                <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                <?php echo $errorMsg; ?>
              </div>
            <?php } ?>
          <?php } ?>
          
          <div class="columns is-multiline is-variable is-6">
            <!-- Webhook URL Form -->
            <div class="column is-12-tablet is-4-desktop" style="display: flex;">
              <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
                <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                  <p class="card-header-title has-text-white" style="font-weight: 600;">
                    <span class="icon mr-2 has-text-primary"><i class="fas fa-link"></i></span>
                    <?php echo t('discordbot_webhook_card_title'); ?>
                  </p>
                </header>
                <div class="card-content" style="flex-grow: 1; display: flex; flex-direction: column;">
                  <form action="" method="post" style="flex-grow: 1; display: flex; flex-direction: column;">
                    <div class="field">
                      <label class="label has-text-white" for="option" style="font-weight: 500;"><?php echo t('discordbot_webhook_select_label'); ?></label>
                      <div class="control">
                        <div class="select is-fullwidth">
                          <select id="option" name="option" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                            <option value="discord_alert"><?php echo t('discordbot_webhook_option_alert'); ?></option>
                            <option value="discord_mod"><?php echo t('discordbot_webhook_option_mod'); ?></option>
                            <option value="discord_alert_online"><?php echo t('discordbot_webhook_option_online'); ?></option>
                          </select>
                        </div>
                      </div>
                    </div>
                    <div class="field">
                      <label class="label has-text-white" for="webhook" style="font-weight: 500;"><?php echo t('discordbot_webhook_url_label'); ?></label>
                      <div class="control has-icons-left">
                        <input class="input" type="text" id="webhook" name="webhook" required style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                        <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-link"></i></span>
                      </div>
                    </div>
                    <div style="flex-grow: 1;"></div>
                    <div class="field">
                      <div class="control">
                        <button class="button is-primary is-fullwidth" type="submit" style="border-radius: 6px; font-weight: 600;">
                          <span class="icon"><i class="fas fa-save"></i></span>
                          <span><?php echo t('discordbot_webhook_save_btn'); ?></span>
                        </button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <!-- Live Channel ID and Guild ID Form -->
            <div class="column is-12-tablet is-4-desktop" style="display: flex;">
              <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
                <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                  <p class="card-header-title has-text-white" style="font-weight: 600;">
                    <span class="icon mr-2 has-text-info"><i class="fas fa-volume-up"></i></span>
                    <?php echo t('discordbot_channel_card_title'); ?>
                  </p>
                </header>
                <div class="card-content" style="flex-grow: 1; display: flex; flex-direction: column;">
                  <form action="" method="post" style="flex-grow: 1; display: flex; flex-direction: column;">
                    <div class="field">
                      <label class="label has-text-white" for="live_channel_id" style="font-weight: 500;"><?php echo t('discordbot_live_channel_id_label'); ?></label>
                      <p class="help has-text-grey-light mb-2"><?php echo t('discordbot_live_channel_id_help'); ?></p>
                      <div class="control has-icons-left">
                        <input class="input" type="text" id="live_channel_id" name="live_channel_id" value="<?php echo htmlspecialchars($existingLiveChannelId); ?>" required style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                        <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-id-card"></i></span>
                      </div>
                    </div>
                    <div class="field">
                      <label class="label has-text-white" for="guild_id" style="font-weight: 500;"><?php echo t('discordbot_guild_id_label'); ?></label>
                      <p class="help has-text-grey-light mb-2"><?php echo t('discordbot_guild_id_help'); ?></p>
                      <div class="control has-icons-left">
                        <input class="input" type="text" id="guild_id" name="guild_id" value="<?php echo htmlspecialchars($existingGuildId); ?>" required style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                        <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-id-card"></i></span>
                      </div>
                    </div>
                    <div style="flex-grow: 1;"></div>
                    <div class="field">
                      <div class="control">
                        <button class="button is-info is-fullwidth" type="submit" style="border-radius: 6px; font-weight: 600;">
                          <span class="icon"><i class="fas fa-save"></i></span>
                          <span><?php echo t('discordbot_channel_save_btn'); ?></span>
                        </button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <!-- Online and Offline Text Updates -->
            <div class="column is-12-tablet is-4-desktop" style="display: flex;">
              <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
                <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                  <p class="card-header-title has-text-white" style="font-weight: 600;">
                    <span class="icon mr-2 has-text-success"><i class="fas fa-comment-dots"></i></span>
                    <?php echo t('discordbot_text_card_title'); ?>
                  </p>
                </header>
                <div class="card-content" style="flex-grow: 1; display: flex; flex-direction: column;">
                  <form action="" method="post" style="flex-grow: 1; display: flex; flex-direction: column;">
                    <div class="field">
                      <label class="label has-text-white" for="online_text" style="font-weight: 500;"><?php echo t('discordbot_online_text_label'); ?></label>
                      <div class="control has-icons-left">
                        <input class="input" type="text" id="online_text" name="online_text" value="<?php echo htmlspecialchars($existingOnlineText); ?>" required style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                        <span class="icon is-small is-left has-text-success"><i class="fas fa-circle"></i></span>
                      </div>
                    </div>
                    <div class="field">
                      <label class="label has-text-white" for="offline_text" style="font-weight: 500;"><?php echo t('discordbot_offline_text_label'); ?></label>
                      <div class="control has-icons-left">
                        <input class="input" type="text" id="offline_text" name="offline_text" value="<?php echo htmlspecialchars($existingOfflineText); ?>" required style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                        <span class="icon is-small is-left has-text-danger"><i class="fas fa-circle"></i></span>
                      </div>
                    </div>
                    <div style="flex-grow: 1;"></div>
                    <div class="field">
                      <div class="control">
                        <button class="button is-success is-fullwidth" type="submit" style="border-radius: 6px; font-weight: 600;">
                          <span class="icon"><i class="fas fa-save"></i></span>
                          <span><?php echo t('discordbot_text_save_btn'); ?></span>
                        </button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>
        <?php } ?>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
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
<?php if (!$is_linked) { ?>  <script>
    function linkDiscord() {
      window.location.href = "<?php echo addslashes($authURL); ?>";
    }
  </script>
<?php } else { ?>
  <script>
    function discordBotDashboard() {
      window.open("https://discord.botofthespecter.com/", "_blank");
    }
  </script>
<?php } ?>
<?php
$scripts = ob_get_clean();
include "layout.php";
?>