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

// Initialize Discord Bot Database Tables
include '/var/www/config/database.php';
$discord_conn = new mysqli($db_servername, $db_username, $db_password, "specterdiscordbot");
if (!$discord_conn->connect_error) {
    // Create server_management table if it doesn't exist
    $createTableSQL = "CREATE TABLE IF NOT EXISTS server_management (
        id INT AUTO_INCREMENT PRIMARY KEY,
        server_id VARCHAR(255) NOT NULL,
        welcomeMessage TINYINT(1) DEFAULT 0,
        autoRole TINYINT(1) DEFAULT 0,
        roleHistory TINYINT(1) DEFAULT 0,
        messageTracking TINYINT(1) DEFAULT 0,
        roleTracking TINYINT(1) DEFAULT 0,
        serverRoleManagement TINYINT(1) DEFAULT 0,
        userTracking TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_server (server_id)
    )";
    $discord_conn->query($createTableSQL);
    $discord_conn->close();
}

// Check if the user is already linked with Discord and validate tokens
if (isset($username) && $username === 'botofthespecter') {
  // For the bot user, use the old simple existence check
  $discord_userSTMT = $conn->prepare("SELECT * FROM discord_users WHERE user_id = ?");
  $discord_userSTMT->bind_param("i", $user_id);
  $discord_userSTMT->execute();
  $discord_userResult = $discord_userSTMT->get_result();
  $has_discord_record = ($discord_userResult->num_rows > 0);
  $is_linked = $has_discord_record;
  $discordData = null;
  $expires_str = '';
  $discord_username = '';
  $discord_discriminator = '';
  $discord_avatar = '';
  $needs_relink = false;
  if ($has_discord_record) {
    $discordData = $discord_userResult->fetch_assoc();
    $discord_username = $discordData['discord_username'] ?? '';
    $discord_discriminator = $discordData['discord_discriminator'] ?? '';
    $discord_avatar = $discordData['discord_avatar'] ?? '';
  }
  $discord_userResult->close();
  $discord_userSTMT->close();
} else {
  // For all other users, use the new robust token validation
  $discord_userSTMT = $conn->prepare("SELECT access_token, refresh_token FROM discord_users WHERE user_id = ?");
  $discord_userSTMT->bind_param("i", $user_id);
  $discord_userSTMT->execute();
  $discord_userResult = $discord_userSTMT->get_result();
  $has_discord_record = ($discord_userResult->num_rows > 0);
  $is_linked = false; // Will only be true if all required tokens are present and valid
  $discordData = null;
  $expires_str = '';
  $discord_username = '';
  $discord_discriminator = '';
  $discord_avatar = '';
  $needs_relink = false;
  if ($has_discord_record) {
    $discordData = $discord_userResult->fetch_assoc();
    // Check if we have ALL required token data: access_token, refresh_token
    if (!empty($discordData['access_token']) && 
        !empty($discordData['refresh_token'])) {
      // Validate token and get current authorization info using /oauth2/@me
      $auth_url = 'https://discord.com/api/oauth2/@me';
      $token = $discordData['access_token'];
      $auth_options = array(
        'http' => array(
          'header' => "Authorization: Bearer $token\r\n",
          'method' => 'GET'
        )
      );
      $auth_context = stream_context_create($auth_options);
      $auth_response = @file_get_contents($auth_url, false, $auth_context);
      if ($auth_response !== false) {
        $auth_data = json_decode($auth_response, true);
        if (isset($auth_data['user'])) {
          // Token is valid, set as properly linked
          $is_linked = true;
          $discord_username = $auth_data['user']['username'] ?? '';
          $discord_discriminator = $auth_data['user']['discriminator'] ?? '';
          $discord_avatar = $auth_data['user']['avatar'] ?? '';
          // Get actual expiration from Discord API
          if (isset($auth_data['expires'])) {
            try {
              $expires_datetime = new DateTime($auth_data['expires']);
              $now = new DateTime();
              $diff = $now->diff($expires_datetime);
              // Calculate time remaining
              $total_seconds = ($diff->days * 86400) + ($diff->h * 3600) + ($diff->i * 60);
              if ($total_seconds > 0) {
                $days = $diff->days;
                $hours = $diff->h;
                $minutes = $diff->i;
                $parts = [];
                if ($days > 0) $parts[] = $days . ' day' . ($days > 1 ? 's' : '');
                if ($hours > 0) $parts[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
                if ($minutes > 0 && count($parts) < 2) $parts[] = $minutes . ' minute' . ($minutes > 1 ? 's' : '');
                $expires_str = implode(', ', $parts);
              }
            } catch (Exception $e) {
              // If there's an error parsing the date, just set expires_str to empty
              $expires_str = '';
            }
          }
        } else {
          // Token is invalid, needs relink
          $needs_relink = true;
        }
      } else {
        // API call failed, token might be invalid, needs relink
        $needs_relink = true;
      }
    } else {
      // Missing required token data (access_token, refresh_token), needs relink
      $needs_relink = true;
    }
  } else {
    // No Discord record exists, user has never linked
    $needs_relink = false; // This is a new user, not a relink case
  }
  $discord_userResult->close();
  $discord_userSTMT->close();
}

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
        $access_token = $params['access_token'];
        $refresh_token = $params['refresh_token'] ?? null;
        // Store Discord user information with tokens if available
        if ($refresh_token) {
          $sql = "INSERT INTO discord_users (user_id, discord_id, access_token, refresh_token) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE discord_id = VALUES(discord_id), access_token = VALUES(access_token), refresh_token = VALUES(refresh_token)";
          $insertStmt = $conn->prepare($sql);
          $insertStmt->bind_param("isss", $user_id, $discord_id, $access_token, $refresh_token);
        } else {
          $sql = "INSERT INTO discord_users (user_id, discord_id, access_token) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE discord_id = VALUES(discord_id), access_token = VALUES(access_token)";
          $insertStmt = $conn->prepare($sql);
          $insertStmt->bind_param("iss", $user_id, $discord_id, $access_token);
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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  try {
    if (isset($_POST['live_channel_id']) && isset($_POST['guild_id']) && isset($_POST['online_text']) && isset($_POST['offline_text'])) {
      // Update live_channel_id and guild_id
      $guild_id = $_POST['guild_id'];
      $live_channel_id = $_POST['live_channel_id'] ?? null;
      $onlineText = !empty($live_channel_id) ? $_POST['online_text'] : null;
      $offlineText = !empty($live_channel_id) ? $_POST['offline_text'] : null;
      if (!empty($_POST['stream_channel_id'])) { $streamChannelID = $_POST['stream_channel_id']; } 
      else { $streamChannelID = null; }
      if (!empty($_POST['mod_channel_id'])) { $moderationChannelID = $_POST['mod_channel_id']; }
      else { $moderationChannelID = null; }
      if (!empty($_POST['alert_channel_id'])) { $alertChannelID = $_POST['alert_channel_id']; }
      else { $alertChannelID = null; }
      if (!empty($_POST['twitch_stream_monitor_id'])) { $memberStreamsID = $_POST['twitch_stream_monitor_id']; }
      else { $memberStreamsID = null; }
      $stmt = $conn->prepare("UPDATE discord_users SET live_channel_id = ?, guild_id = ? WHERE user_id = ?");
      $stmt->bind_param("ssi", $live_channel_id, $guild_id, $user_id);
      if ($stmt->execute()) { $buildStatus .= "Live Channel ID and Guild ID updated successfully<br>"; }
      else { $errorMsg .= "Error updating Live Channel ID and Guild ID: " . $stmt->error . "<br>"; }
      if (strlen($onlineText) > 20) { // Validate character limits (max 20 characters each)
        $errorMsg .= "Online text cannot exceed 20 characters. Current length: " . strlen($onlineText) ."<br>";
      } elseif (strlen($offlineText) > 20) {
        $errorMsg .= "Offline text cannot exceed 20 characters. Current length: " . strlen($offlineText) ."<br>";
      } else {
        $stmt = $conn->prepare("UPDATE discord_users SET online_text = ?, offline_text = ? WHERE user_id = ?");
        $stmt->bind_param("ssi", $onlineText, $offlineText, $user_id);
        if ($stmt->execute()) {
          $buildStatus .= "Online and Offline Text has been updated successfully<br>";
        } else {
          $errorMsg .= "Error updating Online and Offline Text: " . $stmt->error . "<br>";
        }
      }
      $stmt = $conn->prepare("UPDATE discord_users SET stream_alert_channel_id = ?, moderation_channel_id = ?, alert_channel_id = ?, member_streams_id = ? WHERE user_id = ?");
      $stmt->bind_param("iiiii", $streamChannelID, $moderationChannelID, $alertChannelID, $memberStreamsID, $user_id);
      if ($stmt->execute()) {
        $buildStatus .= "Stream Alert Channel ID, Moderation Channel ID, and Alert Channel ID updated successfully<br>";
      } else {
        $errorMsg .= "Error updating Stream Alert Channel ID, Moderation Channel ID, and Alert Channel ID: " . $stmt->error . "<br>";
      }
      $stmt->close();
      updateExistingDiscordValues(); // Refresh existing values after update
    } elseif (isset($_POST['disconnect_discord'])) {
      $discord_userSTMT = $conn->prepare("SELECT access_token, refresh_token FROM discord_users WHERE user_id = ?");
      $discord_userSTMT->bind_param("i", $user_id);
      $discord_userSTMT->execute();
      $discord_userResult = $discord_userSTMT->get_result();
      $discord_user_data = $discord_userResult->fetch_assoc();
      $discord_userSTMT->close();
      if ($discord_user_data) {
        if (!empty($discord_user_data['refresh_token'])) {
          $revoke_success = revokeDiscordToken($discord_user_data['refresh_token'], $client_id, $client_secret, 'refresh_token');
        } elseif (!empty($discord_user_data['access_token'])) {
          $revoke_success = revokeDiscordToken($discord_user_data['access_token'], $client_id, $client_secret, 'access_token');
        }
      }
      $deleteStmt = $conn->prepare("DELETE FROM discord_users WHERE user_id = ?");
      $deleteStmt->bind_param("i", $user_id);
      if ($deleteStmt->execute()) {
        $buildStatus = "Discord account successfully disconnected and tokens revoked";
        $is_linked = false; // Update the linked status
      } else {
        $errorMsg = "Error disconnecting Discord account: " . $deleteStmt->error;
      }
      $deleteStmt->close();
    } elseif (isset($_POST['monitor_username'])) {
      // Add Twitch streamer monitoring (URL is auto-generated)
      $monitor_username = trim($_POST['monitor_username']);
      if (empty($monitor_username)) {
        $errorMsg = "Twitch Username cannot be empty.";
      } else {
        $monitor_url = "https://www.twitch.tv/" . $monitor_username;
        // Check if the streamer already exists
        $checkStmt = $db->prepare("SELECT * FROM member_streams WHERE username = ?");
        $checkStmt->bind_param("s", $monitor_username);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult->num_rows > 0) {
          $buildStatus .= "Streamer already exists in the database.<br>";
        } else {
          // Insert new streamer
          $insertStmt = $db->prepare("INSERT INTO member_streams (username, stream_url) VALUES (?, ?)");
          $insertStmt->bind_param("ss", $monitor_username, $monitor_url);
          if ($insertStmt->execute()) {
            $buildStatus .= "Streamer added successfully.<br>";
          } else {
            $errorMsg .= "Error adding streamer: " . $insertStmt->error . "<br>";
          }
          $insertStmt->close();
        }
        $checkStmt->close();
      }
    } elseif (isset($_POST['remove_streamer'])) {
      // Remove Twitch streamer monitoring
      $remove_username = trim($_POST['remove_streamer']);
      if (empty($remove_username)) {
        $errorMsg = "Twitch Username cannot be empty.";
      } else {
        // Check if the streamer exists
        $checkStmt = $db->prepare("SELECT * FROM member_streams WHERE username = ?");
        $checkStmt->bind_param("s", $remove_username);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult->num_rows === 0) {
          $errorMsg .= "Streamer not found in the database.<br>";
        } else {
          // Delete the streamer
          $deleteStmt = $db->prepare("DELETE FROM member_streams WHERE username = ?");
          $deleteStmt->bind_param("s", $remove_username);
          if ($deleteStmt->execute()) {
            $buildStatus .= "Streamer removed successfully.<br>";
          } else {
            $errorMsg .= "Error removing streamer: " . $deleteStmt->error . "<br>";
          }
          $deleteStmt->close();
        }
        $checkStmt->close();
      }
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
$db = new mysqli($db_servername, $db_username, $db_password, $dbname);
if ($db->connect_error) { die('Connection failed: ' . $db->connect_error); }
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

$savedStreamers = [];
$savedStreamersSTMT = $db->prepare("SELECT username, stream_url FROM member_streams");
$savedStreamersSTMT->execute();
$savedStreamersResult = $savedStreamersSTMT->get_result();
while ($row = $savedStreamersResult->fetch_assoc()) {
  $savedStreamers[] = $row;
}
$savedStreamersSTMT->close();

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
$existingStreamAlertChannelID = $discordData['stream_alert_channel_id'] ?? "";
$existingModerationChannelID = $discordData['moderation_channel_id'] ?? "";
$existingAlertChannelID = $discordData['alert_channel_id'] ?? "";
$existingTwitchStreamMonitoringID = $discordData['member_streams_id'] ?? "";
$discord_userResult->close();

function updateExistingDiscordValues() {
  global $conn, $user_id;
  $discord_userSTMT = $conn->prepare("SELECT * FROM discord_users WHERE user_id = ?");
  $discord_userSTMT->bind_param("i", $user_id);
  $discord_userSTMT->execute();
  $discord_userResult = $discord_userSTMT->get_result();
  $discordData = $discord_userResult->fetch_assoc();
  $existingLiveChannelId = $discordData['live_channel_id'] ?? "";
  $existingGuildId = $discordData['guild_id'] ?? "";
  $existingOnlineText = $discordData['online_text'] ?? "";
  $existingOfflineText = $discordData['offline_text'] ?? "";
  $existingStreamAlertChannelID = $discordData['stream_alert_channel_id'] ?? "";
  $existingModerationChannelID = $discordData['moderation_channel_id'] ?? "";
  $existingAlertChannelID = $discordData['alert_channel_id'] ?? "";
  $existingTwitchStreamMonitoringID = $discordData['member_streams_id'] ?? "";
  $discord_userResult->close();
}

// Generate auth URL with state parameter for security
$authURL = '';
if (!$is_linked) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['discord_oauth_state'] = $state;
    $authURL = "https://discord.com/oauth2/authorize"
        . "?client_id=1170683250797187132"
        . "&response_type=code"
        . "&scope=" . urlencode('identify guilds connections role_connections.write')
        . "&state={$state}"
        . "&redirect_uri=" . urlencode('https://dashboard.botofthespecter.com/discordbot.php');
}

// Helper function to revoke Discord access or refresh token
function revokeDiscordToken($token, $client_id, $client_secret, $token_type_hint = 'access_token') {
  $revoke_url = 'https://discord.com/api/oauth2/token/revoke';
  $data = array(
    'token' => $token,
    'token_type_hint' => $token_type_hint
  );
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
  $response = file_get_contents($revoke_url, false, $context);
  return $response !== false;
}
// Start output buffering for layout
ob_start();
?>
<div class="columns is-centered">
  <div class="column is-fullwidth">
    <!-- Modern Discord Integration Hero Section -->
    <div class="hero is-primary" style="background: linear-gradient(135deg, #5865f2 0%, #7289da 100%); border-radius: 16px; overflow: hidden; margin-bottom: 2rem;">
      <div class="hero-body" style="padding: 2rem;">
        <div class="level is-mobile">
          <div class="level-left">
            <div class="level-item">
              <div class="media">
                <div class="media-left">
                  <figure class="image is-64x64" style="background: rgba(255,255,255,0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                    <i class="fab fa-discord" style="font-size: 2rem; color: white;"></i>
                  </figure>
                </div>
                <div class="media-content">
                  <p class="title is-3 has-text-white" style="margin-bottom: 0.5rem; font-weight: 700;">
                    Discord Integration
                  </p>
                  <p class="subtitle is-5 has-text-white" style="opacity: 0.9;">
                    Connect your Discord server with BotOfTheSpecter
                  </p>
                </div>
              </div>
            </div>
          </div>
          <div class="level-right">
            <div class="level-item">
              <?php if ($is_linked): ?>
                <div class="has-text-right">
                  <div class="tags">
                    <span class="tag is-success is-medium" style="border-radius: 50px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-check-circle"></i></span>
                      <span>Connected</span>
                    </span>
                  </div>
                  <?php if ($expires_str): ?>
                    <p class="is-size-7 has-text-white" style="opacity: 0.8; margin-top: 0.25rem;">
                      Active for <?php echo htmlspecialchars($expires_str); ?>
                    </p>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <span class="tag is-warning is-medium" style="border-radius: 50px; font-weight: 600;">
                  <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                  <span>Not Connected</span>
                </span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Status Cards Section -->
    <?php if ($linkingMessage): ?>
      <div class="notification <?php echo $linkingMessageType === 'is-success' ? 'is-success' : ($linkingMessageType === 'is-danger' ? 'is-danger' : 'is-warning'); ?>" style="border-radius: 12px; margin-bottom: 2rem; border: none; box-shadow: 0 4px 16px rgba(0,0,0,0.1);">
        <div class="level is-mobile">
          <div class="level-left">
            <div class="level-item">
              <span class="icon is-medium">
                <?php if ($linkingMessageType === 'is-danger'): ?>
                  <i class="fas fa-exclamation-triangle"></i>
                <?php elseif ($linkingMessageType === 'is-success'): ?>
                  <i class="fas fa-check-circle"></i>
                <?php else: ?>
                  <i class="fas fa-info-circle"></i>
                <?php endif; ?>
              </span>
              <div class="content" style="margin-left: 0.5rem;">
                <strong><?php echo $linkingMessage; ?></strong>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
    <!-- Main Content Cards -->
    <div class="columns is-multiline">
      <?php if (!$is_linked): ?>
        <?php if ($needs_relink): ?>
          <!-- Reconnection Required Card -->
          <div class="column is-12">
            <div class="card has-background-dark" style="border-radius: 16px; border: 2px solid #ff9800; background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%) !important;">
              <div class="card-content has-text-centered" style="padding: 3rem 2rem;">
                <div class="mb-4">
                  <span class="icon is-large has-text-warning" style="font-size: 4rem;">
                    <i class="fas fa-sync-alt"></i>
                  </span>
                </div>
                <h3 class="title is-3 has-text-white mb-3">Reconnection Required</h3>
                <p class="subtitle is-5 has-text-grey-light mb-5" style="max-width: 600px; margin: 0 auto; line-height: 1.6;">
                  Your Discord account was linked using our previous system. To access all the latest features and improved security, please reconnect your account with our updated integration.
                </p>
                <button class="button is-warning is-large" onclick="linkDiscord()" style="border-radius: 50px; font-weight: 600; padding: 1rem 2rem; box-shadow: 0 4px 16px rgba(255,152,0,0.3);">
                  <span class="icon"><i class="fas fa-sync-alt"></i></span>
                  <span>Reconnect Discord Account</span>
                </button>
              </div>
            </div>
          </div>
        <?php else: ?>
          <!-- Connect Discord Card -->
          <div class="column is-12">
            <div class="card has-background-dark" style="border-radius: 16px; border: 2px solid #5865f2; background: linear-gradient(135deg, #2a2a2a 0%, #363636 100%) !important;">
              <div class="card-content has-text-centered" style="padding: 3rem 2rem;">
                <div class="mb-4">
                  <span class="icon is-large has-text-primary" style="font-size: 4rem;">
                    <i class="fab fa-discord"></i>
                  </span>
                </div>
                <h3 class="title is-3 has-text-white mb-3"><?php echo t('discordbot_link_title'); ?></h3>
                <p class="subtitle is-5 has-text-grey-light mb-5" style="max-width: 500px; margin: 0 auto; line-height: 1.6;">
                  <?php echo t('discordbot_link_desc'); ?>
                </p>
                <button class="button is-primary is-large" onclick="linkDiscord()" style="border-radius: 50px; font-weight: 600; padding: 1rem 2rem; box-shadow: 0 4px 16px rgba(88,101,242,0.3);">
                  <span class="icon"><i class="fab fa-discord"></i></span>
                  <span><?php echo t('discordbot_link_btn'); ?></span>
                </button>
              </div>
            </div>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <!-- Connected Status Card -->
        <div class="column is-12">
          <div class="card has-background-dark" style="border-radius: 16px; border: 2px solid #00d1b2; background: linear-gradient(135deg, #2a2a2a 0%, #363636 100%) !important;">
            <div class="card-content" style="padding: 2rem;">
              <div class="level">
                <div class="level-left">
                  <div class="level-item">
                    <div class="media">
                      <div class="media-left">
                        <span class="icon is-large has-text-success" style="font-size: 3rem;">
                          <i class="fas fa-check-circle"></i>
                        </span>
                      </div>
                      <div class="media-content">
                        <h4 class="title is-4 has-text-white mb-2"><?php echo t('discordbot_linked_title'); ?></h4>
                        <p class="subtitle is-6 has-text-grey-light mb-3">
                          <?php echo t('discordbot_linked_desc'); ?>
                        </p>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="level-right">
                  <div class="level-item">
                    <div class="buttons">
                      <button class="button is-primary" onclick="inviteBot()" style="border-radius: 25px; font-weight: 600;">
                        <span class="icon"><i class="fas fa-plus-circle"></i></span>
                        <span>Invite Bot</span>
                      </button>
                      <button class="button is-danger" onclick="disconnectDiscord()" style="border-radius: 25px; font-weight: 600;">
                        <span class="icon"><i class="fas fa-unlink"></i></span>
                        <span>Disconnect</span>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
              <?php if ($expires_str): ?>
                <div class="notification has-background-grey-darker" style="border-radius: 12px; margin-top: 1rem; border: 1px solid #3273dc;">
                  <div class="level is-mobile">
                    <div class="level-left">
                      <div class="level-item">
                        <span class="icon has-text-info"><i class="fas fa-clock"></i></span>
                        <strong class="has-text-white" style="margin-left: 0.5rem;">Token Status:</strong>
                      </div>
                    </div>
                    <div class="level-right">
                      <div class="level-item">
                        <span class="has-text-grey-light">Valid for <?php echo htmlspecialchars($expires_str); ?></span>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
          <?php if ($buildStatus): ?>
            <div class="column is-12">
              <div class="notification has-background-dark" style="border-radius: 12px; border: 2px solid #48c774; box-shadow: 0 4px 16px rgba(72,199,116,0.2);">
                <div class="level is-mobile">
                  <div class="level-left">
                    <div class="level-item">
                      <span class="icon has-text-success"><i class="fas fa-check-circle"></i></span>
                      <div class="has-text-white" style="margin-left: 0.5rem;"><?php echo $buildStatus; ?></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php endif; ?>
          <?php if ($errorMsg): ?>
            <div class="column is-12">
              <div class="notification has-background-dark" style="border-radius: 12px; border: 2px solid #ff4e65; box-shadow: 0 4px 16px rgba(255,78,101,0.2);">
                <div class="level is-mobile">
                  <div class="level-left">
                    <div class="level-item">
                      <span class="icon has-text-danger"><i class="fas fa-exclamation-triangle"></i></span>
                      <div class="has-text-white" style="margin-left: 0.5rem;"><?php echo $errorMsg; ?></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
    </div>
          <div class="columns is-variable is-6">
            <!-- Left Column: New Discord Channel IDs Form -->
            <div class="column is-6">
              <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
                <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                  <p class="card-header-title has-text-white" style="font-weight: 600;">
                    <span class="icon mr-2 has-text-primary"><i class="fab fa-discord"></i></span>
                    Discord Event Channels
                  </p>
                  <div class="card-header-icon" style="cursor: default;">
                    <span class="tag is-warning is-light">
                      <span class="icon"><i class="fas fa-wrench"></i></span>
                      <span>IN TESTING!</span>
                    </span>
                  </div>
                </header>
                <div class="card-content" style="flex-grow: 1; display: flex; flex-direction: column;">
                  <p class="has-text-grey-light mb-4">
                    Configure Discord channels for different bot events. This new system will replace webhook URLs with direct channel integration.
                  </p>
                  <form action="" method="post" style="flex-grow: 1; display: flex; flex-direction: column;">
                    <div class="field">
                      <label class="label has-text-white" for="guild_id" style="font-weight: 500;">
                        <span class="icon mr-1 has-text-info"><i class="fa-solid fa-users"></i></span>
                        <?php echo t('discordbot_guild_id_label'); ?>
                      </label>
                      <p class="help has-text-grey-light mb-2"><?php echo t('discordbot_guild_id_help'); ?></p>
                      <div class="control has-icons-left">
                        <input class="input" type="text" id="guild_id" name="guild_id" value="<?php echo htmlspecialchars($existingGuildId) . "\""; if (empty($existingGuildId)) { echo " placeholder=\"e.g. 123456789123456789\""; } ?>" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                        <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-hashtag"></i></span>
                      </div>
                    </div>
                    <div class="field">
                      <label class="label has-text-white" for="stream_channel_id" style="font-weight: 500;">
                        <span class="icon mr-1 has-text-success"><i class="fas fa-broadcast-tower"></i></span>
                        Stream Alerts Channel ID
                      </label>
                      <p class="help has-text-grey-light mb-2">Channel ID for stream online/offline notifications</p>
                      <div class="control has-icons-left">
                        <input class="input" type="text" id="stream_channel_id" name="stream_channel_id" value="<?php echo htmlspecialchars($existingStreamAlertChannelID) . "\""; if (empty($existingStreamAlertChannelID)) { echo " placeholder=\"e.g. 123456789123456789\""; } ?>" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                        <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-hashtag"></i></span>
                      </div>
                    </div>
                    <div class="field">
                      <label class="label has-text-white" for="mod_channel_id" style="font-weight: 500;">
                        <span class="icon mr-1 has-text-danger"><i class="fas fa-shield-alt"></i></span>
                        Moderation Channel ID
                      </label>
                      <p class="help has-text-grey-light mb-2">Channel ID for moderation actions and logs</p>
                      <div class="control has-icons-left">
                        <input class="input" type="text" id="mod_channel_id" name="mod_channel_id" value="<?php echo htmlspecialchars($existingModerationChannelID) . "\""; if (empty($existingModerationChannelID)) { echo " placeholder=\"e.g. 123456789123456789\""; } ?>" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                        <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-hashtag"></i></span>
                      </div>
                    </div>
                    <div class="field">
                      <label class="label has-text-white" for="alert_channel_id" style="font-weight: 500;">
                        <span class="icon mr-1 has-text-warning"><i class="fas fa-exclamation-triangle"></i></span>
                        Event Alert Channel ID
                      </label>
                      <p class="help has-text-grey-light mb-2">Channel ID for general bot alerts and notifications</p>
                      <div class="control has-icons-left">
                        <input class="input" type="text" id="alert_channel_id" name="alert_channel_id" value="<?php echo htmlspecialchars($existingAlertChannelID) . "\""; if (empty($existingAlertChannelID)) { echo " placeholder=\"e.g. 123456789123456789\""; } ?>" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                        <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-hashtag"></i></span>
                      </div>
                    </div>
                    <div class="field">
                      <label class="label has-text-white" for="twitch_stream_monitor_id" style="font-weight: 500;">
                        <span class="icon mr-1 has-text-info"><i class="fab fa-twitch"></i></span>
                        Twitch Stream Monitoring ID
                      </label>
                      <p class="help has-text-grey-light mb-2">Channel ID for Twitch Stream Monitoring</p>
                      <div class="control has-icons-left">
                        <input class="input" type="text" id="twitch_stream_monitor_id" name="twitch_stream_monitor_id" value="<?php echo htmlspecialchars($existingTwitchStreamMonitoringID) . "\""; if (empty($existingTwitchStreamMonitoringID)) { echo " placeholder=\"e.g. 123456789123456789\""; } ?>" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                        <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-hashtag"></i></span>
                      </div>
                    </div>
                    <div class="field">
                      <label class="label has-text-white" for="live_channel_id" style="font-weight: 500;">
                        <span class="icon mr-1 has-text-info"><i class="fa-solid fa-volume-high"></i></span>
                        <?php echo t('discordbot_live_channel_id_label'); ?>
                      </label>
                      <p class="help has-text-grey-light mb-2"><?php echo t('discordbot_live_channel_id_help'); ?></p>
                      <div class="control has-icons-left">
                        <input class="input" type="text" id="live_channel_id" name="live_channel_id" value="<?php echo htmlspecialchars($existingLiveChannelId) . "\""; if (empty($existingLiveChannelId)) { echo " placeholder=\"e.g. 123456789123456789\""; } ?>" required style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                        <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-hashtag"></i></span>
                      </div>
                    </div>
                    <div class="field">
                      <label class="label has-text-white" for="online_text" style="font-weight: 500;">
                        <span class="icon is-small is-left has-text-success"><i class="fas fa-circle"></i></span>
                        <?php echo t('discordbot_online_text_label'); ?>
                      </label>
                      <p class="help has-text-grey-light mb-2">Text to display when your channel is online</p>
                      <div class="control has-icons-left">
                        <input class="input" type="text" id="online_text" name="online_text" value="<?php echo htmlspecialchars($existingOnlineText) . "\""; if (empty($existingOnlineText)) { echo " placeholder=\"e.g. Stream Online\""; } ?>" maxlength="20" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                        <span class="icon is-small is-left has-text-success"><i class="fa-solid fa-comment"></i></span>
                      </div>
                      <p class="help has-text-grey-light">
                        <span id="online_text_counter"><?php echo strlen($existingOnlineText); ?></span>/20 characters
                      </p>
                    </div>
                    <div class="field">
                      <label class="label has-text-white" for="offline_text" style="font-weight: 500;">
                        <span class="icon is-small is-left has-text-danger"><i class="fas fa-circle"></i></span>
                        <?php echo t('discordbot_offline_text_label'); ?>
                      </label>
                      <p class="help has-text-grey-light mb-2">Text to display when your channel is offline</p>
                      <div class="control has-icons-left">
                        <input class="input" type="text" id="offline_text" name="offline_text" value="<?php echo htmlspecialchars($existingOfflineText) . "\""; if (empty($existingOfflineText)) { echo " placeholder=\"e.g. Stream Offline\""; } ?>" maxlength="20" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                        <span class="icon is-small is-left has-text-danger"><i class="fa-solid fa-comment"></i></span>
                      </div>
                      <p class="help has-text-grey-light">
                        <span id="offline_text_counter"><?php echo strlen($existingOfflineText); ?></span>/20 characters
                      </p>
                    </div>
                    <div style="flex-grow: 1;"></div>
                    <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                      <div class="content">
                        <p><strong>How to get Channel IDs:</strong></p>
                        <ol class="mb-0">
                          <li>Enable Developer Mode in Discord (User Settings → Advanced → Developer Mode)</li>
                          <li>Right-click on the desired channel</li>
                          <li>Select "Copy Channel ID"</li>
                          <li>Paste the ID into the appropriate field above</li>
                        </ol>
                      </div>
                    </div>
                    <div class="field">
                      <div class="control">
                        <button class="button is-primary is-fullwidth" type="submit" style="border-radius: 6px; font-weight: 600;"<?php echo (!$is_linked || $needs_relink) ? ' disabled' : ''; ?>>
                          <span class="icon"><i class="fas fa-cog"></i></span>
                          <span>Save Channel Configuration</span>
                        </button>
                      </div>
                      <?php if (!$is_linked || $needs_relink): ?>
                      <p class="help has-text-warning has-text-centered mt-2">Account not linked or needs relinking</p>
                      <?php else: ?>
                      <p class="help has-text-grey-light has-text-centered mt-2">
                        Most of these features are currently under development. You can still set the channel IDs and text for future use.
                      </p>
                      <?php endif; ?>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <!-- Webhook URL Form - Legacy - Deprecated -->
            <div class="column is-6">
              <div class="card has-background-grey-darker mb-5" style="border-radius: 12px; border: 1px solid #363636;">
                <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0; cursor: pointer;" onclick="toggleDeprecatedCard()">
                  <p class="card-header-title has-text-white" style="font-weight: 600;">
                    <span class="icon mr-2 has-text-primary"><i class="fas fa-link"></i></span>
                    <?php echo t('discordbot_webhook_card_title'); ?> (Legacy)
                  </p>
                  <div class="card-header-icon">
                    <span class="tag is-warning is-light">
                      <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                      <span>Deprecated</span>
                    </span>
                    <span class="icon has-text-grey-light ml-2" id="deprecatedCardToggle">
                      <i class="fas fa-chevron-down"></i>
                    </span>
                  </div>
                </header>
                <div class="card-content" id="deprecatedCardContent" style="display: none;">
                  <div class="notification is-warning is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                    <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                    <strong>Note:</strong> This feature is being phased out.
                  </div>
                  <form action="" method="post">
                    <div class="field">
                      <label class="label has-text-white" for="option" style="font-weight: 500;">Select Event Type</label>
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
                        <input class="input" type="text" id="webhook" name="webhook" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                        <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-link"></i></span>
                      </div>
                    </div>
                    <div class="field">
                      <div class="control">
                      <button class="button is-primary is-fullwidth" type="submit" style="border-radius: 6px; font-weight: 600;" disabled>
                        <span class="icon"><i class="fas fa-save"></i></span>
                        <span><?php echo t('discordbot_webhook_save_btn'); ?></span>
                      </button>
                      <p class="help has-text-warning mt-2 has-text-centered">This feature is deprecated and cannot be used.</p>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
              <div class="card has-background-grey-darker mb-5" style="border-radius: 12px; border: 1px solid #363636;">
                <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                  <p class="card-header-title has-text-white" style="font-weight: 600;">
                    <span class="icon mr-2 has-text-primary"><i class="fa-brands fa-twitch"></i></span>
                    Twitch Stream Monitoring
                  </p>
                  <div class="card-header-icon" style="cursor: default;">
                    <span class="tag is-success is-light">
                      <span class="icon"><i class="fas fa-check-circle"></i></span>
                      <span>Fully integrated & live</span>
                    </span>
                  </div>
                </header>
                <div class="card-content">
                  <form action="" method="post">
                    <div class="field">
                      <label class="label has-text-white" for="option" style="font-weight: 500;">Twitch Username</label>
                      <div class="control has-icons-left">
                        <input class="input" type="text" id="monitor_username" name="monitor_username" placeholder="e.g. botofthespecter" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                        <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-person"></i></span>
                      </div>
                    </div>
                    <div class="field">
                      <div class="control">
                        <button class="button is-primary is-fullwidth" type="submit" style="border-radius: 6px; font-weight: 600;"<?php echo (!$is_linked || $needs_relink) ? ' disabled' : ''; ?>>
                          <span class="icon"><i class="fas fa-save"></i></span>
                          <span>Add Streamer</span>
                        </button>
                      </div>
                      <?php if (!$is_linked || $needs_relink): ?>
                      <p class="help has-text-warning has-text-centered mt-2">Account not linked or needs relinking</p>
                      <?php endif; ?>
                    </div>
                  </form>
                  <br>
                  <div>
                    <button class="button is-link is-fullwidth modal-button" style="border-radius: 6px; font-weight: 600;" data-target="savedStreamersModal">
                      <span class="icon"><i class="fa-solid fa-people-group"></i></span>
                      <span>View Tracked Streamers</span>
                    </button>
                  </div>
                </div>
              </div>
              <!-- Discord Server Management Box -->
              <div class="card has-background-grey-darker mb-5" style="border-radius: 12px; border: 1px solid #363636;">
                <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                  <p class="card-header-title has-text-white" style="font-weight: 600;">
                    <span class="icon mr-2 has-text-primary"><i class="fab fa-discord"></i></span>
                    Discord Server Management
                  </p>
                  <div class="card-header-icon" style="cursor: default;">
                    <span class="tag is-warning is-light">
                      <span class="icon"><i class="fas fa-hourglass-half"></i></span>
                      <span>COMING SOON</span>
                    </span>
                  </div>
                </header>
                <div class="card-content">
                  <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                    <span class="icon"><i class="fas fa-info-circle"></i></span>
                    <strong>Comprehensive Discord server management and moderation tools. Features include welcome messages, auto-role assignment, role history tracking, message monitoring (edited/deleted messages), and role change tracking for complete server oversight.</strong>
                  </div>
                  <form action="" method="post">
                    <div class="field">
                      <label class="label has-text-white" style="font-weight: 500;">Server Management Features</label>
                      <div class="field" style="margin-bottom: 0.75rem;">
                        <div class="control">
                          <input id="welcomeMessage" type="checkbox" name="welcomeMessage" class="switch is-rounded" disabled>
                          <label for="welcomeMessage" class="has-text-white">Welcome Message</label>
                        </div>
                      </div>
                      <div class="field" style="margin-bottom: 0.75rem;">
                        <div class="control">
                          <input id="autoRole" type="checkbox" name="autoRole" class="switch is-rounded" disabled>
                          <label for="autoRole" class="has-text-white">Auto Role on Join</label>
                        </div>
                      </div>
                      <div class="field" style="margin-bottom: 0.75rem;">
                        <div class="control">
                          <input id="roleHistory" type="checkbox" name="roleHistory" class="switch is-rounded" disabled>
                          <label for="roleHistory" class="has-text-white">Role History (Restore roles on rejoin)</label>
                        </div>
                      </div>
                      <div class="field" style="margin-bottom: 0.75rem;">
                        <div class="control">
                          <input id="messageTracking" type="checkbox" name="messageTracking" class="switch is-rounded" disabled>
                          <label for="messageTracking" class="has-text-white">Message Tracking (Edited/Deleted messages)</label>
                        </div>
                      </div>
                      <div class="field" style="margin-bottom: 0.75rem;">
                        <div class="control">
                          <input id="roleTracking" type="checkbox" name="roleTracking" class="switch is-rounded" disabled>
                          <label for="roleTracking" class="has-text-white">Role Tracking (User added/removed from roles)</label>
                        </div>
                      </div>
                      <div class="field" style="margin-bottom: 0.75rem;">
                        <div class="control">
                          <input id="serverRoleManagement" type="checkbox" name="serverRoleManagement" class="switch is-rounded" disabled>
                          <label for="serverRoleManagement" class="has-text-white">Server Role Management (Track role creation/deletion)</label>
                        </div>
                      </div>
                      <div class="field">
                        <div class="control">
                          <input id="userTracking" type="checkbox" name="userTracking" class="switch is-rounded" disabled>
                          <label for="userTracking" class="has-text-white">User Tracking (Nickname, profile picture, status changes)</label>
                        </div>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>
      </div>
    </div>
  </div>
</div>

<div id="savedStreamersModal" class="modal">
  <div class="modal-background"></div>
  <div class="modal-content" style="width: 50%;">
    <div class="box">
      <h1 class="title is-4 has-text-centered">Saved Streamers List</h1>
      <table class="table is-fullwidth has-text-centered">
        <thead>
          <tr>
            <th class="has-text-centered" style="text-align: center;">Twitch Username</th>
            <th class="has-text-centered" style="text-align: center;">Twitch URL</th>
            <th class="has-text-centered" style="text-align: center;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <!-- Dynamic content will be injected here -->
        </tbody>
      </table>
    </div>
  </div>
  <button class="modal-close is-large" aria-label="close"></button>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<script>
const tableBody = document.querySelector('#savedStreamersModal tbody');
const initialSavedStreamers = <?php echo json_encode($savedStreamers); ?>;
let streamersToDisplay = initialSavedStreamers;
function populateStreamersTable() {
  tableBody.innerHTML = '';
  if (streamersToDisplay.length === 0) {
    tableBody.innerHTML = '<tr><td colspan="3" class="has-text-centered">No streamers saved yet.</td></tr>';
    return;
  }
  streamersToDisplay.forEach(streamer => {
    const row = document.createElement('tr');
    row.innerHTML = `
      <td>${streamer.username}</td>
      <td><a href="${streamer.stream_url}" target="_blank">${streamer.stream_url}</a></td>
      <td>
        <button class="button is-danger is-small" onclick="removeStreamer('${streamer.username}')">
          <span class="icon"><i class="fas fa-trash"></i></span>
          <span>Remove</span>
        </button>
      </td>
    `;
    tableBody.appendChild(row);
  });
}
populateStreamersTable(streamersToDisplay);

function removeStreamer(username) {
  Swal.fire({
    title: 'Remove Tracked Streamer?',
    html: `Are you sure you want to remove <b>${username}</b> from your tracked streamer list?<br><br>This will stop the Discord bot from posting when this streamer goes live in your monitoring channel on your Discord server.`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Yes, Remove',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#e74c3c',
    cancelButtonColor: '#6c757d'
  }).then((result) => {
    if (result.isConfirmed) {
      // Create a form and submit it
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = '';
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'remove_streamer';
      input.value = username;
      form.appendChild(input);
      document.body.appendChild(form);
      form.submit();
    }
  });
}
</script>
<script>
  $(document).ready(function() {
    var webhooks = <?php echo json_encode($existingWebhooks); ?>;
    var initialWebhook = webhooks['discord_alert'] || '';
    $('#webhook').val(initialWebhook);
    $('#option').change(function() {
      var selectedOption = $(this).val();
      $('#webhook').val(webhooks[selectedOption] || '');
  });
  // Character counters for online/offline text
  function updateCharCounter(inputId, counterId) {
    var input = $('#' + inputId);
    var counter = $('#' + counterId);
    var maxLength = 20;
    input.on('input', function() {
      var currentLength = $(this).val().length;
      counter.text(currentLength);
      // Change color based on character count
      if (currentLength >= maxLength) {
        counter.css('color', '#ff3860'); // Red when at limit
      } else if (currentLength >= maxLength * 0.8) {
        counter.css('color', '#ffdd57'); // Yellow when approaching limit
      } else {
        counter.css('color', '#b5b5b5'); // Default grey
      }
    });
  }
  updateCharCounter('online_text', 'online_text_counter');
  updateCharCounter('offline_text', 'offline_text_counter');
});
</script>
<?php if (!$is_linked) { ?>
<script>function linkDiscord() { window.location.href = "<?php echo addslashes($authURL); ?>"; }</script>
<?php } else { ?>
<script>
  function disconnectDiscord() {
    Swal.fire({
      title: 'Disconnect Discord Account?',
      text: 'Are you sure you want to disconnect your Discord account? This will revoke all tokens and remove your Discord integration.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, Disconnect',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#e74c3c',
      cancelButtonColor: '#6c757d'
    }).then((result) => {
      if (result.isConfirmed) {
        // Create a form and submit it
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'disconnect_discord';
        input.value = '1';
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
      }
    });
  }
  function inviteBot() {
    // Discord bot invite URL with necessary permissions
    const botInviteURL = 'https://discord.com/oauth2/authorize' +
      '?client_id=1170683250797187132' +
      '&permissions=581651049737302' +
      '&integration_type=0' +
      '&scope=bot';
    
    window.open(botInviteURL, '_blank');
  }
  function toggleDeprecatedCard() {
    const content = document.getElementById('deprecatedCardContent');
    const toggle = document.getElementById('deprecatedCardToggle');
    
    if (content.style.display === 'none') {
      content.style.display = 'block';
      toggle.innerHTML = '<i class="fas fa-chevron-up"></i>';
    } else {
      content.style.display = 'none';
      toggle.innerHTML = '<i class="fas fa-chevron-down"></i>';
    }
  }
  // Discord Settings AJAX Handler
  function updateDiscordSetting(settingName, value) {
    const guildId = document.getElementById('guild_id') ? document.getElementById('guild_id').value : '';
    fetch('save_discord_server_management_settings.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        setting: settingName,
        value: value,
        server_id: guildId
      })
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        // Show success notification
        Swal.fire({
          toast: true,
          position: 'top-end',
          icon: 'success',
          title: 'Setting updated successfully',
          showConfirmButton: false,
          timer: 2000,
          timerProgressBar: true
        });
      } else {
        // Show error and revert toggle
        Swal.fire({
          toast: true,
          position: 'top-end',
          icon: 'error',
          title: 'Failed to update setting: ' + data.message,
          showConfirmButton: false,
          timer: 3000,
          timerProgressBar: true
        });
        // Revert the toggle state
        const toggle = document.getElementById(settingName);
        if (toggle) {
          toggle.checked = !value;
        }
      }
    })
    .catch(error => {
      console.error('Error:', error);
      // Show error notification and revert toggle
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'error',
        title: 'Network error occurred',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      // Revert the toggle state
      const toggle = document.getElementById(settingName);
      if (toggle) {
        toggle.checked = !value;
      }
    });
  }
  // Add event listeners to all Discord setting toggles
  document.addEventListener('DOMContentLoaded', function() {
    const settingToggles = [
      'welcomeMessage',
      'autoRole',
      'roleHistory', 
      'messageTracking',
      'roleTracking',
      'serverRoleManagement',
      'userTracking'
    ];
    settingToggles.forEach(settingName => {
      const toggle = document.getElementById(settingName);
      if (toggle) {
        toggle.addEventListener('change', function() {
          updateDiscordSetting(settingName, this.checked);
        });
      }
    });
  });
</script>
<?php } ?>
<?php
$scripts = ob_get_clean();
include "layout.php";
?>