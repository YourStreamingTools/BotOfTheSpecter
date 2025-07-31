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
  $needs_reauth = false;
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
  $discord_userSTMT = $conn->prepare("SELECT * FROM discord_users WHERE user_id = ?");
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
  $needs_reauth = false;
  if ($has_discord_record) {
    $discordData = $discord_userResult->fetch_assoc();
    // Check for reauth requirement from database
    $needs_reauth = (isset($discordData['reauth']) && $discordData['reauth'] == 1);
    // If reauth is required, force user to relink for new scopes
    if ($needs_reauth) {
      $needs_relink = true;
    } else {
      // Check if we have ALL required token data: access_token, refresh_token
      if (!empty($discordData['access_token']) && 
          !empty($discordData['refresh_token'])) {
        // Validate token and get current authorization info using /oauth2/@me
        $auth_url = 'https://discord.com/api/v10/oauth2/@me';
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
    }
  } else {
    // No Discord record exists, user has never linked
    $needs_relink = false; // This is a new user, not a relink case
    $discordData = null; // Ensure discordData is null for users without records
  }
  $discord_userResult->close();
  $discord_userSTMT->close();
}

// Ensure discordData is properly initialized for all users
if ($discordData === null) {
  $discordData = []; // Initialize as empty array to prevent null pointer issues
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
        // Store Discord user information with tokens if available and reset reauth flag
        if ($refresh_token) {
          $sql = "INSERT INTO discord_users (user_id, discord_id, access_token, refresh_token, reauth) VALUES (?, ?, ?, ?, 0) ON DUPLICATE KEY UPDATE discord_id = VALUES(discord_id), access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), reauth = 0";
          $insertStmt = $conn->prepare($sql);
          $insertStmt->bind_param("isss", $user_id, $discord_id, $access_token, $refresh_token);
        } else {
          $sql = "INSERT INTO discord_users (user_id, discord_id, access_token, reauth) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE discord_id = VALUES(discord_id), access_token = VALUES(access_token), reauth = 0";
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

// Set default values if no Discord data exists
$existingLiveChannelId = $discordData['live_channel_id'] ?? "";
$existingGuildId = $discordData['guild_id'] ?? "";
$existingOnlineText = $discordData['online_text'] ?? "";
$existingOfflineText = $discordData['offline_text'] ?? "";
$existingStreamAlertChannelID = $discordData['stream_alert_channel_id'] ?? "";
$existingModerationChannelID = $discordData['moderation_channel_id'] ?? "";
$existingAlertChannelID = $discordData['alert_channel_id'] ?? "";
$existingTwitchStreamMonitoringID = $discordData['member_streams_id'] ?? "";
$hasGuildId = !empty($existingGuildId) && trim($existingGuildId) !== "";
// Check if manual IDs mode is explicitly enabled (only true if database value is 1)
$useManualIds = (isset($discordData['manual_ids']) && $discordData['manual_ids'] == 1);
// Debug logging to help track down the issue (can be removed once issue is resolved)
error_log("Discord Data Debug for user_id $user_id: " . json_encode([
  'has_discord_record' => $has_discord_record,
  'is_linked' => $is_linked,
  'needs_relink' => $needs_relink,
  'needs_reauth' => $needs_reauth,
  'reauth_flag' => (is_array($discordData) && isset($discordData['reauth'])) ? $discordData['reauth'] : 'not_set',
  'use_manual_ids' => $useManualIds,
  'manual_ids_flag' => (is_array($discordData) && isset($discordData['manual_ids'])) ? $discordData['manual_ids'] : 'not_set',
  'discordData_is_null' => ($discordData === null),
  'discordData_is_array' => is_array($discordData),
  'discordData_count' => is_array($discordData) ? count($discordData) : 'N/A',
  'existingGuildId' => $existingGuildId,
  'existingLiveChannelId' => $existingLiveChannelId,
  'existingOnlineText' => $existingOnlineText,
  'existingOfflineText' => $existingOfflineText
]));

// Fetch server management settings from Discord bot database
$serverManagementSettings = [
  'welcomeMessage' => false,
  'autoRole' => false,
  'roleHistory' => false,
  'messageTracking' => false,
  'roleTracking' => false,
  'serverRoleManagement' => false,
  'userTracking' => false
];

if ($is_linked && $hasGuildId) {
  $discord_conn = new mysqli($db_servername, $db_username, $db_password, "specterdiscordbot");
  if (!$discord_conn->connect_error) {
    $serverMgmtStmt = $discord_conn->prepare("SELECT welcomeMessage, autoRole, roleHistory, messageTracking, roleTracking, serverRoleManagement, userTracking FROM server_management WHERE server_id = ?");
    $serverMgmtStmt->bind_param("s", $existingGuildId);
    $serverMgmtStmt->execute();
    $serverMgmtResult = $serverMgmtStmt->get_result();
    if ($serverMgmtData = $serverMgmtResult->fetch_assoc()) {
      $serverManagementSettings = [
        'welcomeMessage' => (bool)$serverMgmtData['welcomeMessage'],
        'autoRole' => (bool)$serverMgmtData['autoRole'],
        'roleHistory' => (bool)$serverMgmtData['roleHistory'],
        'messageTracking' => (bool)$serverMgmtData['messageTracking'],
        'roleTracking' => (bool)$serverMgmtData['roleTracking'],
        'serverRoleManagement' => (bool)$serverMgmtData['serverRoleManagement'],
        'userTracking' => (bool)$serverMgmtData['userTracking']
      ];
    }
    $serverMgmtStmt->close();
    $discord_conn->close();
  }
}

// Check if any management features are enabled
$hasEnabledFeatures = array_reduce($serverManagementSettings, function($carry, $item) {
  return $carry || $item;
}, false);

// Fetch user's administrative guilds if linked
$userAdminGuilds = array();
if ($is_linked && !$needs_relink && !empty($discordData['access_token'])) {
  $userAdminGuilds = getUserAdminGuilds($discordData['access_token']);
  // Debug logging for guild fetching
  error_log("Guild Fetch Debug for user_id $user_id: " . json_encode([
    'is_linked' => $is_linked,
    'needs_relink' => $needs_relink,
    'has_access_token' => !empty($discordData['access_token']),
    'guild_count' => count($userAdminGuilds),
    'guilds' => array_map(function($guild) {
      return [
        'id' => $guild['id'] ?? 'no_id',
        'name' => $guild['name'] ?? 'no_name',
        'owner' => $guild['owner'] ?? false,
        'permissions' => $guild['permissions'] ?? 0
      ];
    }, $userAdminGuilds)
  ]));
}

// Fetch guild channels if user has a guild selected and is not using manual IDs
$guildChannels = array();
if ($is_linked && !$needs_relink && !empty($discordData['access_token']) && !$useManualIds && !empty($existingGuildId)) {
  $guildChannels = fetchGuildChannels($discordData['access_token'], $existingGuildId);
  // Debug logging for channel fetching
  error_log("Text Channel Fetch Debug for user_id $user_id, guild_id $existingGuildId: " . json_encode([
    'text_channel_count' => is_array($guildChannels) ? count($guildChannels) : 0,
    'text_channels_available' => !empty($guildChannels),
    'use_manual_ids' => $useManualIds
  ]));
}

// Fetch guild roles if user has a guild selected and is not using manual IDs
$guildRoles = array();
if ($is_linked && !$needs_relink && !empty($discordData['access_token']) && !$useManualIds && !empty($existingGuildId)) {
  $guildRoles = fetchGuildRoles($existingGuildId, $discordData['access_token']);
  // Debug logging for role fetching
  error_log("Role Fetch Debug for user_id $user_id, guild_id $existingGuildId: " . json_encode([
    'role_count' => is_array($guildRoles) ? count($guildRoles) : 0,
    'roles_available' => !empty($guildRoles),
    'use_manual_ids' => $useManualIds
  ]));
}

// Fetch guild voice channels if user has a guild selected and is not using manual IDs
$guildVoiceChannels = array();
if ($is_linked && !$needs_relink && !empty($discordData['access_token']) && !$useManualIds && !empty($existingGuildId)) {
  $guildVoiceChannels = fetchGuildVoiceChannels($discordData['access_token'], $existingGuildId);
  // Debug logging for voice channel fetching
  error_log("Voice Channel Fetch Debug for user_id $user_id, guild_id $existingGuildId: " . json_encode([
    'voice_channel_count' => is_array($guildVoiceChannels) ? count($guildVoiceChannels) : 0,
    'voice_channels_available' => !empty($guildVoiceChannels),
    'use_manual_ids' => $useManualIds
  ]));
}

function updateExistingDiscordValues() {
  global $conn, $user_id, $db_servername, $db_username, $db_password, $serverManagementSettings, $discordData;
  global $existingLiveChannelId, $existingGuildId, $existingOnlineText, $existingOfflineText;
  global $existingStreamAlertChannelID, $existingModerationChannelID, $existingAlertChannelID, $existingTwitchStreamMonitoringID, $hasGuildId;
  global $userAdminGuilds, $is_linked, $needs_relink, $useManualIds, $guildChannels, $guildRoles, $guildVoiceChannels;
  // Update discord_users table values from website database
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
  $hasGuildId = !empty($existingGuildId) && trim($existingGuildId) !== "";
  // Check if manual IDs mode is explicitly enabled (only true if database value is 1)
  $useManualIds = (isset($discordData['manual_ids']) && $discordData['manual_ids'] == 1);
  $discord_userResult->close();
  // Refresh user's administrative guilds
  $userAdminGuilds = array();
  if ($is_linked && !$needs_relink && !empty($discordData['access_token'])) {
    $userAdminGuilds = getUserAdminGuilds($discordData['access_token']);
  }
  // Refresh server management settings from specterdiscordbot database
  if ($hasGuildId) {
    $discord_conn = new mysqli($db_servername, $db_username, $db_password, "specterdiscordbot");
    if (!$discord_conn->connect_error) {
      $serverMgmtStmt = $discord_conn->prepare("SELECT welcomeMessage, autoRole, roleHistory, messageTracking, roleTracking, serverRoleManagement, userTracking FROM server_management WHERE server_id = ?");
      $serverMgmtStmt->bind_param("s", $existingGuildId);
      $serverMgmtStmt->execute();
      $serverMgmtResult = $serverMgmtStmt->get_result();
      if ($serverMgmtData = $serverMgmtResult->fetch_assoc()) {
        $serverManagementSettings = [
          'welcomeMessage' => (bool)$serverMgmtData['welcomeMessage'],
          'autoRole' => (bool)$serverMgmtData['autoRole'],
          'roleHistory' => (bool)$serverMgmtData['roleHistory'],
          'messageTracking' => (bool)$serverMgmtData['messageTracking'],
          'roleTracking' => (bool)$serverMgmtData['roleTracking'],
          'serverRoleManagement' => (bool)$serverMgmtData['serverRoleManagement'],
          'userTracking' => (bool)$serverMgmtData['userTracking']
        ];
      }
      $serverMgmtStmt->close();
      $discord_conn->close();
    }
  }
  // Refresh guild channels if user has a guild selected and is not using manual IDs
  $guildChannels = array();
  if ($is_linked && !$needs_relink && !empty($discordData['access_token']) && !$useManualIds && !empty($existingGuildId)) {
    $guildChannels = fetchGuildChannels($discordData['access_token'], $existingGuildId);
  }
  // Refresh guild roles if user has a guild selected and is not using manual IDs
  $guildRoles = array();
  if ($is_linked && !$needs_relink && !empty($discordData['access_token']) && !$useManualIds && !empty($existingGuildId)) {
    $guildRoles = fetchGuildRoles($existingGuildId, $discordData['access_token']);
  }
  // Refresh guild voice channels if user has a guild selected and is not using manual IDs
  $guildVoiceChannels = array();
  if ($is_linked && !$needs_relink && !empty($discordData['access_token']) && !$useManualIds && !empty($existingGuildId)) {
    $guildVoiceChannels = fetchGuildVoiceChannels($discordData['access_token'], $existingGuildId);
  }
}

// Generate auth URL with state parameter for security
$authURL = '';
if (!$is_linked || $needs_relink) {
  $state = bin2hex(random_bytes(16));
  $_SESSION['discord_oauth_state'] = $state;
  // Determine OAuth scopes based on user status
  if (!$is_linked) {
    // New user: Include 'bot' scope to add bot to their server
    $oauth_scopes = 'identify guilds guilds.members.read connections bot';
  } else {
    // Existing user relinking: Exclude 'bot' scope since bot should already be in server
    $oauth_scopes = 'identify guilds guilds.members.read connections';
  }
  $authURL = "https://discord.com/oauth2/authorize"
    . "?client_id=1170683250797187132"
    . "&response_type=code"
    . "&scope=" . urlencode($oauth_scopes)
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
// Helper function to fetch user's Discord guilds
function fetchUserGuilds($access_token) {
  $guilds_url = 'https://discord.com/api/v10/users/@me/guilds';
  $options = array(
    'http' => array(
      'header' => "Authorization: Bearer $access_token\r\n",
      'method' => 'GET'
    )
  );
  $context = stream_context_create($options);
  $response = @file_get_contents($guilds_url, false, $context);
  if ($response !== false) {
    $guilds = json_decode($response, true);
    if (is_array($guilds)) {
      return $guilds;
    } else {
      error_log("Discord API Error - fetchUserGuilds: Invalid JSON response");
    }
  } else {
    error_log("Discord API Error - fetchUserGuilds: Failed to fetch guilds");
  }
  return false;
}
// Helper function to check if user is admin/owner of a specific guild
function checkGuildPermissions($access_token, $guild_id) {
  $member_url = "https://discord.com/api/v10/users/@me/guilds/$guild_id/member";
  $options = array(
    'http' => array(
      'header' => "Authorization: Bearer $access_token\r\n",
      'method' => 'GET'
    )
  );
  $context = stream_context_create($options);
  $response = @file_get_contents($member_url, false, $context);
  if ($response !== false) {
    $member_data = json_decode($response, true);
    if (is_array($member_data)) {
      return $member_data;
    } else {
      error_log("Discord API Error - checkGuildPermissions: Invalid JSON response for guild $guild_id");
    }
  } else {
    error_log("Discord API Error - checkGuildPermissions: Failed to fetch member data for guild $guild_id");
  }
  return false;
}
// Helper function to get user's administrative guilds
function getUserAdminGuilds($access_token) {
  $guilds = fetchUserGuilds($access_token);
  $admin_guilds = array();
  if ($guilds && is_array($guilds)) {
    foreach ($guilds as $guild) {
      // Check if user is owner or has admin permissions
      // Permissions field contains bitwise permissions
      $permissions = intval($guild['permissions'] ?? 0);
      $is_owner = isset($guild['owner']) && $guild['owner'] === true;
      $has_admin = ($permissions & 0x8) === 0x8; // ADMINISTRATOR permission bit
      
      if ($is_owner || $has_admin) {
        $admin_guilds[] = $guild;
      }
    }
  } else {
    error_log("Discord API Error - getUserAdminGuilds: No guilds returned or invalid format");
  }
  return $admin_guilds;
}

// Helper function to fetch channels from a Discord guild
function fetchGuildChannels($access_token, $guild_id) {
  // Load Discord bot token for guild API calls
  require_once '../config/discord.php';
  global $bot_token;
  if (empty($guild_id)) {
    return false;
  }
  // Use bot token for guild-specific API calls instead of user access token
  $auth_token = !empty($bot_token) ? $bot_token : $access_token;
  $auth_header = !empty($bot_token) ? "Authorization: Bot $bot_token\r\n" : "Authorization: Bearer $access_token\r\n";
  $channels_url = "https://discord.com/api/v10/guilds/$guild_id/channels";
  $options = array(
    'http' => array(
      'header' => $auth_header,
      'method' => 'GET'
    )
  );
  $context = stream_context_create($options);
  $response = @file_get_contents($channels_url, false, $context);
  if ($response !== false) {
    $channels = json_decode($response, true);
    if (is_array($channels)) {
      // Filter for text channels (type 0) and sort by position
      $text_channels = array_filter($channels, function($channel) {
        return ($channel['type'] ?? -1) === 0; // 0 = GUILD_TEXT
      });
      // Sort channels by position
      usort($text_channels, function($a, $b) {
        return ($a['position'] ?? 0) - ($b['position'] ?? 0);
      });
      // Log successful channel fetch
      error_log("Discord API Success - fetchGuildChannels: Fetched " . count($text_channels) . " text channels for guild $guild_id using " . (!empty($bot_token) ? "bot token" : "user token"));
      return $text_channels;
    } else {
      error_log("Discord API Error - fetchGuildChannels: Invalid JSON response for guild $guild_id");
    }
  } else {
    // Get more detailed error information
    $error_info = error_get_last();
    error_log("Discord API Error - fetchGuildChannels: Failed to fetch channels for guild $guild_id. Error: " . ($error_info['message'] ?? 'Unknown error'));
  }
  return false;
}

// Helper function to fetch voice channels from a Discord guild
function fetchGuildVoiceChannels($access_token, $guild_id) {
  // Load Discord bot token for guild API calls
  require_once '../config/discord.php';
  global $bot_token;
  if (empty($guild_id)) {
    return false;
  }
  // Use bot token for guild-specific API calls instead of user access token
  $auth_token = !empty($bot_token) ? $bot_token : $access_token;
  $auth_header = !empty($bot_token) ? "Authorization: Bot $bot_token\r\n" : "Authorization: Bearer $access_token\r\n";
  $channels_url = "https://discord.com/api/v10/guilds/$guild_id/channels";
  $options = array(
    'http' => array(
      'header' => $auth_header,
      'method' => 'GET'
    )
  );
  $context = stream_context_create($options);
  $response = @file_get_contents($channels_url, false, $context);
  if ($response !== false) {
    $channels = json_decode($response, true);
    if (is_array($channels)) {
      // Filter for voice channels (type 2) and sort by position
      $voice_channels = array_filter($channels, function($channel) {
        return ($channel['type'] ?? -1) === 2; // 2 = GUILD_VOICE
      });
      // Sort channels by position
      usort($voice_channels, function($a, $b) {
        return ($a['position'] ?? 0) - ($b['position'] ?? 0);
      });
      // Log successful voice channel fetch
      error_log("Discord API Success - fetchGuildVoiceChannels: Fetched " . count($voice_channels) . " voice channels for guild $guild_id using " . (!empty($bot_token) ? "bot token" : "user token"));
      return $voice_channels;
    } else {
      error_log("Discord API Error - fetchGuildVoiceChannels: Invalid JSON response for guild $guild_id");
    }
  } else {
    // Get more detailed error information
    $error_info = error_get_last();
    error_log("Discord API Error - fetchGuildVoiceChannels: Failed to fetch voice channels for guild $guild_id. Error: " . ($error_info['message'] ?? 'Unknown error'));
  }
  return false;
}

// Helper function to fetch roles from a Discord guild
function fetchGuildRoles($guild_id, $access_token) {
  // Load Discord bot token for guild API calls
  require_once '../config/discord.php';
  global $bot_token;
  if (empty($guild_id)) {
    return false;
  }
  // Use bot token for guild-specific API calls instead of user access token
  $auth_token = !empty($bot_token) ? $bot_token : $access_token;
  $auth_header = !empty($bot_token) ? "Authorization: Bot $bot_token\r\n" : "Authorization: Bearer $access_token\r\n";
  $roles_url = "https://discord.com/api/v10/guilds/$guild_id/roles";
  $options = array(
    'http' => array(
      'header' => $auth_header,
      'method' => 'GET'
    )
  );
  $context = stream_context_create($options);
  $response = @file_get_contents($roles_url, false, $context);
  if ($response !== false) {
    $roles = json_decode($response, true);
    if (is_array($roles)) {
      // Filter out @everyone role and managed/bot roles, sort by position (highest first)
      $assignable_roles = array_filter($roles, function($role) {
        return ($role['name'] !== '@everyone') && 
               !($role['managed'] ?? false) && // Exclude bot/integration managed roles
               !($role['tags'] ?? false);      // Exclude roles with special tags
      });
      // Sort roles by position (highest position first, which is how Discord shows them)
      usort($assignable_roles, function($a, $b) {
        return ($b['position'] ?? 0) - ($a['position'] ?? 0);
      });
      // Log successful role fetch
      error_log("Discord API Success - fetchGuildRoles: Fetched " . count($assignable_roles) . " roles for guild $guild_id using " . (!empty($bot_token) ? "bot token" : "user token"));
      return $assignable_roles;
    } else {
      error_log("Discord API Error - fetchGuildRoles: Invalid JSON response for guild $guild_id");
    }
  } else {
    // Get more detailed error information
    $error_info = error_get_last();
    error_log("Discord API Error - fetchGuildRoles: Failed to fetch roles for guild $guild_id. Error: " . ($error_info['message'] ?? 'Unknown error'));
  }
  return false;
}

// Helper function to generate channel input field or dropdown
function generateChannelInput($fieldId, $fieldName, $currentValue, $placeholder, $useManualIds, $guildChannels, $icon = 'fas fa-hashtag', $required = false) {
  $requiredAttr = $required ? ' required' : '';
  if ($useManualIds || empty($guildChannels)) {
    // Show manual input field with enhanced placeholder for manual mode
    $manualPlaceholder = $useManualIds ? "Text Channel ID (Right-click channel → Copy Channel ID)" : $placeholder;
    $emptyPlaceholder = empty($currentValue) ? " placeholder=\"$manualPlaceholder\"" : '';
    return "
      <input class=\"input\" type=\"text\" id=\"$fieldId\" name=\"$fieldName\" value=\"" . htmlspecialchars($currentValue) . "\"$emptyPlaceholder$requiredAttr style=\"background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;\">
      <span class=\"icon is-small is-left has-text-grey-light\"><i class=\"$icon\"></i></span>";
  } else {
    // Show dropdown with channels
    $options = "<option value=\"\"" . (empty($currentValue) ? ' selected' : '') . ">Select a channel...</option>\n";
    foreach ($guildChannels as $channel) {
      $channelId = htmlspecialchars($channel['id']);
      $channelName = htmlspecialchars($channel['name']);
      $selected = ($currentValue === $channel['id']) ? ' selected' : '';
      $options .= "<option value=\"$channelId\"$selected>#$channelName</option>\n";
    }
    return "
      <div class=\"select is-fullwidth\" style=\"width: 100%;\">
        <select id=\"$fieldId\" name=\"$fieldName\"$requiredAttr style=\"background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px; width: 100%;\">$options</select>
      </div>
      <span class=\"icon is-small is-left has-text-grey-light\"><i class=\"$icon\"></i></span>";
  }
}

// Helper function to generate role input field or dropdown
function generateRoleInput($fieldId, $fieldName, $currentValue, $placeholder, $useManualIds, $guildRoles, $icon = 'fas fa-user-tag', $required = false) {
  $requiredAttr = $required ? ' required' : '';
  if ($useManualIds || empty($guildRoles)) {
    // Show manual input field with enhanced placeholder for manual mode
    $manualPlaceholder = $useManualIds ? "Role ID (Right-click role → Copy Role ID)" : $placeholder;
    $emptyPlaceholder = empty($currentValue) ? " placeholder=\"$manualPlaceholder\"" : '';
    return "
      <input class=\"input\" type=\"text\" id=\"$fieldId\" name=\"$fieldName\" value=\"" . htmlspecialchars($currentValue) . "\"$emptyPlaceholder$requiredAttr style=\"background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;\">
      <span class=\"icon is-small is-left has-text-grey-light\"><i class=\"$icon\"></i></span>";
  } else {
    // Show dropdown with roles
    $options = "<option value=\"\"" . (empty($currentValue) ? ' selected' : '') . ">Select a role...</option>\n";
    foreach ($guildRoles as $role) {
      $roleId = htmlspecialchars($role['id']);
      $roleName = htmlspecialchars($role['name']);
      $selected = ($currentValue === $role['id']) ? ' selected' : '';
      // Add color indicator if role has a color
      $colorIndicator = '';
      if (!empty($role['color']) && $role['color'] != 0) {
        $color = '#' . str_pad(dechex($role['color']), 6, '0', STR_PAD_LEFT);
        $colorIndicator = " style=\"color: $color;\"";
      }
      $options .= "<option value=\"$roleId\"$selected$colorIndicator>@$roleName</option>\n";
    }
    return "
      <div class=\"select is-fullwidth\" style=\"width: 100%;\">
        <select id=\"$fieldId\" name=\"$fieldName\"$requiredAttr style=\"background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px; width: 100%;\">$options</select>
      </div>
      <span class=\"icon is-small is-left has-text-grey-light\"><i class=\"$icon\"></i></span>";
  }
}

// Helper function to generate voice channel input field or dropdown
function generateVoiceChannelInput($fieldId, $fieldName, $currentValue, $placeholder, $useManualIds, $guildVoiceChannels, $icon = 'fas fa-volume-up', $required = false) {
  $requiredAttr = $required ? ' required' : '';
  if ($useManualIds || empty($guildVoiceChannels)) {
    // Show manual input field with enhanced placeholder for manual mode
    $manualPlaceholder = $useManualIds ? "Voice Channel ID (Right-click voice channel → Copy Channel ID)" : $placeholder;
    $emptyPlaceholder = empty($currentValue) ? " placeholder=\"$manualPlaceholder\"" : '';
    return "
      <input class=\"input\" type=\"text\" id=\"$fieldId\" name=\"$fieldName\" value=\"" . htmlspecialchars($currentValue) . "\"$emptyPlaceholder$requiredAttr style=\"background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;\">
      <span class=\"icon is-small is-left has-text-grey-light\"><i class=\"$icon\"></i></span>";
  } else {
    // Show dropdown with voice channels
    $options = "<option value=\"\"" . (empty($currentValue) ? ' selected' : '') . ">Select a voice channel...</option>\n";
    foreach ($guildVoiceChannels as $channel) {
      $channelId = htmlspecialchars($channel['id']);
      $channelName = htmlspecialchars($channel['name']);
      $selected = ($currentValue === $channel['id']) ? ' selected' : '';
      $options .= "<option value=\"$channelId\"$selected>🔊 $channelName</option>\n";
    }
    return "
      <div class=\"select is-fullwidth\" style=\"width: 100%;\">
        <select id=\"$fieldId\" name=\"$fieldName\"$requiredAttr style=\"background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px; width: 100%;\">$options</select>
      </div>
      <span class=\"icon is-small is-left has-text-grey-light\"><i class=\"$icon\"></i></span>";
  }
}

// Start output buffering for layout
ob_start();
?>
<div class="columns is-centered">
  <div class="column is-fullwidth">
    <!-- Modern Discord Integration Hero Section -->
    <div class="hero is-primary" style="background: linear-gradient(135deg, #5865f2 0%, #7289da 100%); border-radius: 16px; overflow: hidden; margin-bottom: 2rem;">
      <div class="hero-body" style="padding: 2rem;">
        <div class="container">
          <!-- Desktop layout: single row with status on right -->
          <div class="is-hidden-mobile">
            <div class="columns is-vcentered">
              <div class="column">
                <div class="media">
                  <div class="media-left">
                    <figure class="image is-64x64" style="background: rgba(255,255,255,0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                      <i class="fab fa-discord" style="font-size: 2rem; color: white;"></i>
                    </figure>
                  </div>
                  <div class="media-content">
                    <p class="title is-3-desktop is-4-tablet has-text-white" style="margin-bottom: 0.5rem; font-weight: 700; word-wrap: break-word; line-height: 1.2;">
                      Discord Integration
                    </p>
                    <p class="subtitle is-5-desktop is-6-tablet has-text-white" style="opacity: 0.9; word-wrap: break-word; line-height: 1.3; margin-bottom: 0;">
                      Connect your Discord server with BotOfTheSpecter
                    </p>
                  </div>
                </div>
              </div>
              <div class="column is-narrow">
                <?php if ($is_linked): ?>
                  <div class="has-text-right">
                    <div class="tags has-addons is-right mb-2">
                      <span class="tag is-success is-medium" style="border-radius: 50px; font-weight: 600;">
                        <span class="icon"><i class="fas fa-check-circle"></i></span>
                        <span>Connected</span>
                      </span>
                    </div>
                    <?php if ($expires_str): ?>
                      <p class="is-size-7 has-text-white" style="opacity: 0.8; word-wrap: break-word; line-height: 1.3;">
                        Active for <?php echo htmlspecialchars($expires_str); ?>
                      </p>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <div class="has-text-right">
                    <span class="tag is-warning is-medium" style="border-radius: 50px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                      <span>Not Connected</span>
                    </span>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <!-- Mobile layout: stacked rows -->
          <div class="is-hidden-tablet">
            <!-- Header with icon and title -->
            <div class="columns is-mobile is-vcentered mb-4">
              <div class="column">
                <div class="media">
                  <div class="media-left">
                    <figure class="image is-48x48-mobile" style="background: rgba(255,255,255,0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                      <i class="fab fa-discord" style="font-size: 1.5rem; color: white;"></i>
                    </figure>
                  </div>
                  <div class="media-content">
                    <p class="title is-5-mobile has-text-white" style="margin-bottom: 0.5rem; font-weight: 700; word-wrap: break-word; line-height: 1.2;">
                      Discord Integration
                    </p>
                    <p class="subtitle is-7-mobile has-text-white" style="opacity: 0.9; word-wrap: break-word; line-height: 1.3; margin-bottom: 0;">
                      Connect your Discord server with BotOfTheSpecter
                    </p>
                  </div>
                </div>
              </div>
            </div>
            <!-- Status section - separate row for mobile -->
            <div class="columns is-mobile">
              <div class="column">
                <?php if ($is_linked): ?>
                  <div class="has-text-centered">
                    <div class="tags has-addons is-centered mb-2">
                      <span class="tag is-success is-medium" style="border-radius: 50px; font-weight: 600;">
                        <span class="icon"><i class="fas fa-check-circle"></i></span>
                        <span>Connected</span>
                      </span>
                    </div>
                    <?php if ($expires_str): ?>
                      <p class="is-size-7-mobile has-text-white" style="opacity: 0.8; word-wrap: break-word; line-height: 1.3;">
                        Active for <?php echo htmlspecialchars($expires_str); ?>
                      </p>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <div class="has-text-centered">
                    <span class="tag is-warning is-medium" style="border-radius: 50px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                      <span>Not Connected</span>
                    </span>
                  </div>
                <?php endif; ?>
              </div>
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
                <h3 class="title is-3 has-text-white mb-3">
                  <?php echo (isset($discordData['reauth']) && $discordData['reauth'] == 1) ? 'New Permissions Required' : 'Reconnection Required'; ?>
                </h3>
                <p class="subtitle is-5 has-text-grey-light mb-5" style="max-width: 600px; margin: 0 auto; line-height: 1.6;">
                  <?php if (isset($discordData['reauth']) && $discordData['reauth'] == 1): ?>
                    We've added new features that require additional Discord permissions. Please re-authorize your account to access guild management features and server selection.
                  <?php else: ?>
                    Your Discord account was linked using our previous system. To access all the latest features and improved security, please reconnect your account with our updated integration.
                  <?php endif; ?>
                </p>
                <button class="button is-warning is-large" onclick="linkDiscord()" style="border-radius: 50px; font-weight: 600; padding: 1rem 2rem; box-shadow: 0 4px 16px rgba(255,152,0,0.3);">
                  <span class="icon"><i class="fas fa-sync-alt"></i></span>
                  <span>
                    <?php echo (isset($discordData['reauth']) && $discordData['reauth'] == 1) ? 'Grant New Permissions' : 'Reconnect Discord Account'; ?>
                  </span>
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
            <div class="card-content discord-card-content" style="padding: 2rem;">
              <!-- Desktop layout: single row with buttons on right -->
              <div class="is-hidden-mobile">
                <div class="level" style="flex-wrap: wrap !important; overflow: visible !important;">
                  <div class="level-left" style="flex: 1 !important; min-width: 0 !important; max-width: calc(100% - 200px) !important; overflow: visible !important;">
                    <div class="level-item" style="flex: 1 !important; min-width: 0 !important; overflow: visible !important;">
                      <div class="media" style="overflow: visible !important; min-width: 0 !important; flex: 1 !important;">
                        <div class="media-left">
                          <span class="icon is-large has-text-success" style="font-size: 3rem;">
                            <i class="fas fa-check-circle"></i>
                          </span>
                        </div>
                        <div class="media-content" style="overflow: visible !important; word-wrap: break-word !important; overflow-wrap: break-word !important; word-break: break-word !important; max-width: 100% !important; min-width: 0 !important; flex: 1 !important;">
                          <h4 class="title is-4 has-text-white mb-2" style="word-wrap: break-word !important; overflow-wrap: break-word !important; word-break: break-word !important; white-space: normal !important; max-width: 100% !important;"><?php echo t('discordbot_linked_title'); ?></h4>
                          <p class="subtitle is-6 has-text-grey-light mb-0" style="word-wrap: break-word !important; overflow-wrap: break-word !important; word-break: break-word !important; white-space: normal !important; max-width: 100% !important;">
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
              </div>
              <!-- Mobile layout: stacked rows -->
              <div class="is-hidden-tablet">
                <!-- Header with icon and title -->
                <div class="block mb-4">
                  <div class="media">
                    <div class="media-left">
                      <span class="icon is-large has-text-success" style="font-size: 2.5rem;">
                        <i class="fas fa-check-circle"></i>
                      </span>
                    </div>
                    <div class="media-content" style="overflow: visible !important; word-wrap: break-word !important; overflow-wrap: break-word !important; word-break: break-word !important; max-width: 100% !important; min-width: 0 !important; flex: 1 !important;">
                      <h4 class="title is-5-mobile has-text-white mb-2" style="word-wrap: break-word !important; overflow-wrap: break-word !important; word-break: break-word !important; white-space: normal !important; max-width: 100% !important;"><?php echo t('discordbot_linked_title'); ?></h4>
                      <p class="subtitle is-6-mobile has-text-grey-light mb-0" style="word-wrap: break-word !important; overflow-wrap: break-word !important; word-break: break-word !important; white-space: normal !important; max-width: 100% !important;">
                        <?php echo t('discordbot_linked_desc'); ?>
                      </p>
                    </div>
                  </div>
                </div>
                <!-- Buttons section - stacked on mobile -->
                <div class="block">
                  <div class="buttons is-centered">
                    <button class="button is-primary is-fullwidth-mobile" onclick="inviteBot()" style="border-radius: 25px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-plus-circle"></i></span>
                      <span>Invite Bot</span>
                    </button>
                    <button class="button is-danger is-fullwidth-mobile" onclick="disconnectDiscord()" style="border-radius: 25px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-unlink"></i></span>
                      <span>Disconnect</span>
                    </button>
                  </div>
                </div>
              </div>
              <?php if ($expires_str): ?>
                <div class="notification has-background-grey-darker" style="border-radius: 12px; margin-top: 1rem; border: 1px solid #3273dc;">
                  <div class="columns is-mobile is-vcentered">
                    <div class="column is-narrow">
                      <span class="icon has-text-info"><i class="fas fa-clock"></i></span>
                      <strong class="has-text-white" style="margin-left: 0.5rem;">Token Status:</strong>
                    </div>
                    <div class="column">
                      <span class="has-text-grey-light" style="word-wrap: break-word;">Valid for <?php echo htmlspecialchars($expires_str); ?></span>
                    </div>
                  </div>
                </div>
              <?php endif; ?>
              <!-- Guild ID Configuration - Independent Form -->
              <div class="card has-background-grey-darker mb-4" style="border-radius: 12px; border: 1px solid #363636; margin-top: 1rem;">
                <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                  <p class="card-header-title has-text-white" style="font-weight: 600;">
                    <span class="icon mr-2 has-text-primary"><i class="fas fa-server"></i></span>
                    Discord Server Configuration
                  </p>
                  <div class="card-header-icon" style="cursor: default;">
                    <span class="tag is-info is-light">
                      <span class="icon"><i class="fas fa-cog"></i></span>
                      <span>Required</span>
                    </span>
                  </div>
                </header>
                <div class="card-content">
                  <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                    <span class="icon"><i class="fas fa-info-circle"></i></span>
                    <strong>Required for All Discord Bot Features:</strong> Please select your Discord Server to enable all Discord Bot features including Server Management and Event Channels.
                  </div>
                  <form action="" method="post">
                    <div class="field">
                      <label class="label has-text-white" for="guild_id_config" style="font-weight: 500;">Discord Server</label>
                      <div class="control has-icons-left">
                        <?php if ($useManualIds): ?>
                          <!-- Manual ID Input Mode -->
                          <input class="input" type="text" id="guild_id_config" name="guild_id" value="<?php echo htmlspecialchars($existingGuildId); ?>"<?php if (empty($existingGuildId)) { echo ' placeholder="e.g. 123456789123456789"'; } ?> style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                          <span class="icon is-small is-left has-text-grey-light"><i class="fab fa-discord"></i></span>
                          <p class="help has-text-grey-light">Manual ID mode enabled. Right-click your Discord server name → Copy Server ID (Developer Mode required)</p>
                        <?php elseif (!empty($userAdminGuilds) && is_array($userAdminGuilds)): ?>
                          <!-- Dropdown Mode -->
                          <div class="select is-fullwidth" style="width: 100%;">
                            <select id="guild_id_config" name="guild_id" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px; width: 100%;">
                              <option value="" <?php echo empty($existingGuildId) ? 'selected' : ''; ?>>Select a Discord Server...</option>
                              <?php foreach ($userAdminGuilds as $guild): ?>
                                <?php 
                                  $isSelected = ($existingGuildId === $guild['id']) ? 'selected' : '';
                                  $guildName = htmlspecialchars($guild['name']);
                                  $ownerBadge = (isset($guild['owner']) && $guild['owner']) ? ' 👑' : ' 🛡️';
                                ?>
                                <option value="<?php echo htmlspecialchars($guild['id']); ?>" <?php echo $isSelected; ?>>
                                  <?php echo $guildName . $ownerBadge; ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <span class="icon is-small is-left has-text-grey-light"><i class="fab fa-discord"></i></span>
                          <p class="help has-text-grey-light">
                            Only servers where you have Administrator permissions are shown. 
                            👑 = Owner, 🛡️ = Administrator
                          </p>
                        <?php else: ?>
                          <!-- Fallback/Loading Mode -->
                          <input class="input" type="text" id="guild_id_config" name="guild_id" value="<?php echo htmlspecialchars($existingGuildId); ?>" placeholder="Loading servers..." disabled style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                          <span class="icon is-small is-left has-text-grey-light"><i class="fab fa-discord"></i></span>
                          <p class="help has-text-warning">
                            <?php if (!$is_linked || $needs_relink): ?>
                              Please link your Discord account to view available servers.
                            <?php else: ?>
                              No servers found where you have Administrator permissions, or servers are still loading.
                            <?php endif; ?>
                          </p>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="field">
                      <div class="control">
                        <button class="button is-primary is-fullwidth" type="submit" style="border-radius: 6px; font-weight: 600;"<?php echo (!$is_linked || $needs_relink || (!$useManualIds && empty($userAdminGuilds))) ? ' disabled' : ''; ?>>
                          <span class="icon"><i class="fas fa-save"></i></span>
                          <span>Save Server Configuration</span>
                        </button>
                      </div>
                      <?php if (!$is_linked || $needs_relink): ?>
                      <p class="help has-text-warning has-text-centered mt-2">Account not linked or needs relinking</p>
                      <?php elseif (!$useManualIds && empty($userAdminGuilds)): ?>
                      <p class="help has-text-warning has-text-centered mt-2">No servers available with admin permissions</p>
                      <?php endif; ?>
                    </div>
                  </form>
                </div>
              </div>
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
                <!-- Channel Input Mode Notification -->
                <?php if ($useManualIds): ?>
                  <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                    <span class="icon"><i class="fas fa-keyboard"></i></span>
                    <strong>Manual Input Mode:</strong> You are in manual ID mode. Enter channel IDs directly.
                  </div>
                <?php elseif (!empty($guildChannels)): ?>
                  <div class="notification is-success is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                    <span class="icon"><i class="fas fa-list"></i></span>
                    <strong>Channel Selector Mode:</strong> Select channels from your Discord server dropdowns.
                  </div>
                <?php elseif (!empty($existingGuildId)): ?>
                  <div class="notification is-warning is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                    <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                    <strong>Loading Channels:</strong> Unable to load channels. You may need to re-authorize your Discord account or check server permissions.
                  </div>
                <?php else: ?>
                  <div class="notification is-warning is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                    <span class="icon"><i class="fas fa-server"></i></span>
                    <strong>Server Required:</strong> Please configure your Discord Server above to enable channel selection.
                  </div>
                <?php endif; ?>
                <form action="" method="post" style="flex-grow: 1; display: flex; flex-direction: column;">
                  <div class="field">
                    <label class="label has-text-white" for="stream_channel_id" style="font-weight: 500;">
                      <span class="icon mr-1 has-text-success"><i class="fas fa-broadcast-tower"></i></span>
                      Stream Alerts Channel ID
                    </label>
                    <p class="help has-text-grey-light mb-2">Channel ID for stream online/offline notifications</p>
                    <div class="control has-icons-left">
                      <?php echo generateChannelInput('stream_channel_id', 'stream_channel_id', $existingStreamAlertChannelID, 'e.g. 123456789123456789', $useManualIds, $guildChannels); ?>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label has-text-white" for="mod_channel_id" style="font-weight: 500;">
                      <span class="icon mr-1 has-text-danger"><i class="fas fa-shield-alt"></i></span>
                      Moderation Channel ID
                    </label>
                    <p class="help has-text-grey-light mb-2">Channel ID for moderation actions and logs</p>
                    <div class="control has-icons-left">
                      <?php echo generateChannelInput('mod_channel_id', 'mod_channel_id', $existingModerationChannelID, 'e.g. 123456789123456789', $useManualIds, $guildChannels); ?>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label has-text-white" for="alert_channel_id" style="font-weight: 500;">
                      <span class="icon mr-1 has-text-warning"><i class="fas fa-exclamation-triangle"></i></span>
                      Event Alert Channel ID
                    </label>
                    <p class="help has-text-grey-light mb-2">Channel ID for general bot alerts and notifications</p>
                    <div class="control has-icons-left">
                      <?php echo generateChannelInput('alert_channel_id', 'alert_channel_id', $existingAlertChannelID, 'e.g. 123456789123456789', $useManualIds, $guildChannels); ?>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label has-text-white" for="twitch_stream_monitor_id" style="font-weight: 500;">
                      <span class="icon mr-1 has-text-info"><i class="fab fa-twitch"></i></span>
                      Twitch Stream Monitoring ID
                    </label>
                    <p class="help has-text-grey-light mb-2">Channel ID for Twitch Stream Monitoring</p>
                    <div class="control has-icons-left">
                      <?php echo generateChannelInput('twitch_stream_monitor_id', 'twitch_stream_monitor_id', $existingTwitchStreamMonitoringID, 'e.g. 123456789123456789', $useManualIds, $guildChannels); ?>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label has-text-white" for="live_channel_id" style="font-weight: 500;">
                      <span class="icon mr-1 has-text-info"><i class="fa-solid fa-volume-high"></i></span>
                      <?php echo t('discordbot_live_channel_id_label'); ?>
                    </label>
                    <p class="help has-text-grey-light mb-2"><?php echo t('discordbot_live_channel_id_help'); ?></p>
                    <div class="control has-icons-left">
                      <?php echo generateVoiceChannelInput('live_channel_id', 'live_channel_id', $existingLiveChannelId, 'e.g. 123456789123456789', $useManualIds, $guildVoiceChannels, 'fas fa-volume-up', true); ?>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label has-text-white" for="online_text" style="font-weight: 500;">
                      <span class="icon is-small is-left has-text-success"><i class="fas fa-circle"></i></span>
                      <?php echo t('discordbot_online_text_label'); ?>
                    </label>
                    <p class="help has-text-grey-light mb-2">Text to display when your channel is online</p>
                    <div class="control has-icons-left">
                      <input class="input" type="text" id="online_text" name="online_text" value="<?php echo htmlspecialchars($existingOnlineText); ?>"<?php if (empty($existingOnlineText)) { echo ' placeholder="e.g. Stream Online"'; } ?> maxlength="20" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
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
                      <input class="input" type="text" id="offline_text" name="offline_text" value="<?php echo htmlspecialchars($existingOfflineText); ?>"<?php if (empty($existingOfflineText)) { echo ' placeholder="e.g. Stream Offline"'; } ?> maxlength="20" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                      <span class="icon is-small is-left has-text-danger"><i class="fa-solid fa-comment"></i></span>
                    </div>
                    <p class="help has-text-grey-light">
                      <span id="offline_text_counter"><?php echo strlen($existingOfflineText); ?></span>/20 characters
                    </p>
                  </div>
                  <div style="flex-grow: 1;"></div>
                  <?php if ($useManualIds): ?>
                  <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                    <div class="content">
                      <p><strong>How to get Channel IDs:</strong></p>
                      <ol class="mb-2">
                        <li>Enable Developer Mode in Discord (User Settings → Advanced → Developer Mode)</li>
                        <li>Right-click on the desired channel</li>
                        <li>Select "Copy Channel ID"</li>
                        <li>Paste the ID into the appropriate field above</li>
                      </ol>
                    </div>
                  </div>
                  <?php endif; ?>
                  <div class="field">
                    <div class="control">
                      <button class="button is-primary is-fullwidth" type="submit" style="border-radius: 6px; font-weight: 600;"<?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                        <span class="icon"><i class="fas fa-cog"></i></span>
                        <span>Save Channel Configuration</span>
                      </button>
                    </div>
                    <?php if (!$is_linked || $needs_relink): ?>
                    <p class="help has-text-warning has-text-centered mt-2">Account not linked or needs relinking</p>
                    <?php elseif (!$hasGuildId): ?>
                    <p class="help has-text-warning has-text-centered mt-2">Guild ID not setup</p>
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
                <?php if (!$is_linked || $needs_relink): ?>
                <div class="notification is-warning is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                  <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                  <strong>Account Not Linked:</strong> Please link your Discord account to access server management features.
                </div>
                <?php elseif (!$hasGuildId): ?>
                <div class="notification is-warning is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                  <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                  <strong>Guild ID Required:</strong> Please configure your Discord Server ID above to enable server management features.
                </div>
                <?php else: ?>
                <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                  <span class="icon"><i class="fas fa-info-circle"></i></span>
                  <strong>Comprehensive Discord server management and moderation tools. Features include welcome messages, auto-role assignment, role history tracking, message monitoring (edited/deleted messages), and role change tracking for complete server oversight.</strong>
                </div>
                <?php endif; ?>
                <form action="" method="post">
                  <div class="field">
                    <label class="label has-text-white" style="font-weight: 500;">Server Management Features</label>
                    <div class="field" style="margin-bottom: 0.75rem;">
                      <div class="control">
                        <input id="welcomeMessage" type="checkbox" name="welcomeMessage" class="switch is-rounded"<?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                        <label for="welcomeMessage" class="has-text-white">Welcome Message</label>
                      </div>
                    </div>
                    <div class="field" style="margin-bottom: 0.75rem;">
                      <div class="control">
                        <input id="autoRole" type="checkbox" name="autoRole" class="switch is-rounded"<?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                        <label for="autoRole" class="has-text-white">Auto Role on Join</label>
                      </div>
                    </div>
                    <div class="field" style="margin-bottom: 0.75rem;">
                      <div class="control">
                        <input id="roleHistory" type="checkbox" name="roleHistory" class="switch is-rounded"<?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                        <label for="roleHistory" class="has-text-white">Role History (Restore roles on rejoin)</label>
                      </div>
                    </div>
                    <div class="field" style="margin-bottom: 0.75rem;">
                      <div class="control">
                        <input id="messageTracking" type="checkbox" name="messageTracking" class="switch is-rounded"<?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                        <label for="messageTracking" class="has-text-white">Message Tracking (Edited/Deleted messages)</label>
                      </div>
                    </div>
                    <div class="field" style="margin-bottom: 0.75rem;">
                      <div class="control">
                        <input id="roleTracking" type="checkbox" name="roleTracking" class="switch is-rounded"<?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                        <label for="roleTracking" class="has-text-white">Role Tracking (User added/removed from roles)</label>
                      </div>
                    </div>
                    <div class="field" style="margin-bottom: 0.75rem;">
                      <div class="control">
                        <input id="serverRoleManagement" type="checkbox" name="serverRoleManagement" class="switch is-rounded"<?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                        <label for="serverRoleManagement" class="has-text-white">Server Role Management (Track role creation/deletion)</label>
                      </div>
                    </div>
                    <div class="field">
                      <div class="control">
                        <input id="userTracking" type="checkbox" name="userTracking" class="switch is-rounded"<?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                        <label for="userTracking" class="has-text-white">User Tracking (Nickname, profile picture, status changes)</label>
                      </div>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
        <!-- Individual Management Feature Cards -->
        <?php if ($hasEnabledFeatures && $is_linked && !$needs_relink && $hasGuildId): ?>
        <div class="columns is-multiline is-variable is-3">
          <?php if ($serverManagementSettings['welcomeMessage']): ?>
          <div class="column is-half mb-1">
            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636;">
              <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                <p class="card-header-title has-text-white" style="font-weight: 600;">
                  <span class="icon mr-2 has-text-success"><i class="fas fa-door-open"></i></span>
                  Welcome Message Configuration
                </p>
                <div class="card-header-icon">
                  <span class="tag is-warning is-light">
                    <span class="icon"><i class="fas fa-clock"></i></span>
                    <span>Coming Soon</span>
                  </span>
                </div>
              </header>
              <div class="card-content">
                <div class="notification is-warning is-light mb-1">
                  <p class="has-text-dark"><strong>Coming Soon:</strong> This feature is currently in development and will be available in a future update.</p>
                </div>
                <p class="has-text-white-ter mb-1">Configure automated welcome messages for new members joining your Discord server.</p>
                <form action="" method="post">
                  <div class="field">
                    <label class="label has-text-white" style="font-weight: 500;">Welcome Channel ID</label>
                    <div class="control has-icons-left">
                      <input class="input" type="text" name="welcome_channel_id" placeholder="e.g. 123456789123456789" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;" disabled>
                      <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-hashtag"></i></span>
                    </div>
                    <p class="help has-text-grey-light">Channel where welcome messages will be sent</p>
                  </div>
                  <div class="field">
                    <label class="label has-text-white" style="font-weight: 500;">Welcome Message</label>
                    <div class="control">
                      <textarea class="textarea" name="welcome_message" rows="3" placeholder="Welcome {user} to our Discord server!" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;" disabled></textarea>
                    </div>
                    <p class="help has-text-grey-light">Use {user} to mention the new member</p>
                  </div>
                  <div class="field">
                    <div class="control">
                      <button class="button is-primary is-fullwidth" type="submit" name="save_welcome_message" style="border-radius: 6px; font-weight: 600;" disabled>
                        <span class="icon"><i class="fas fa-save"></i></span>
                        <span>Save Welcome Message Settings</span>
                      </button>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <?php if ($serverManagementSettings['autoRole']): ?>
          <div class="column is-half mb-1">
            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636;">
              <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                <p class="card-header-title has-text-white" style="font-weight: 600;">
                  <span class="icon mr-2 has-text-info"><i class="fas fa-user-plus"></i></span>
                  Auto Role Assignment Configuration
                </p>
                <div class="card-header-icon">
                  <span class="tag is-warning is-light">
                    <span class="icon"><i class="fas fa-clock"></i></span>
                    <span>Coming Soon</span>
                  </span>
                </div>
              </header>
              <div class="card-content">
                <div class="notification is-info is-light mb-1">
                  <p class="has-text-dark"><strong>Auto Role Assignment:</strong> Automatically assign a role to new members when they join your Discord server.</p>
                </div>
                <p class="has-text-white-ter mb-1">Configure automatic role assignment for new members joining your Discord server.</p>
                <form action="" method="post">
                  <div class="field">
                    <label class="label has-text-white" style="font-weight: 500;">Auto Role</label>
                    <div class="control has-icons-left">
                      <?php echo generateRoleInput(
                        'auto_role_id', 
                        'auto_role_id', 
                        '', // Current value would come from database 
                        'e.g. 123456789123456789', 
                        $useManualIds, 
                        $guildRoles, 
                        'fas fa-user-tag', 
                        false
                      ); ?>
                    </div>
                    <p class="help has-text-grey-light">Role to automatically assign to new members</p>
                  </div>
                  <div class="field">
                    <div class="control">
                      <button class="button is-primary is-fullwidth" type="submit" name="save_auto_role" style="border-radius: 6px; font-weight: 600;">
                        <span class="icon"><i class="fas fa-save"></i></span>
                        <span>Save Auto Role Settings</span>
                      </button>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <?php if ($serverManagementSettings['roleHistory']): ?>
          <div class="column is-half mb-1">
            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636;">
              <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                <p class="card-header-title has-text-white" style="font-weight: 600;">
                  <span class="icon mr-2 has-text-warning"><i class="fas fa-history"></i></span>
                  Role History Configuration
                </p>
                <div class="card-header-icon">
                  <span class="tag is-warning is-light">
                    <span class="icon"><i class="fas fa-clock"></i></span>
                    <span>Coming Soon</span>
                  </span>
                </div>
              </header>
              <div class="card-content">
                <div class="notification is-warning is-light mb-1">
                  <p class="has-text-dark"><strong>Coming Soon:</strong> This feature is currently in development and will be available in a future update.</p>
                </div>
                <p class="has-text-white-ter mb-1">Configure role restoration settings for members who rejoin your Discord server.</p>
                <form action="" method="post">
                  <div class="field">
                    <label class="label has-text-white" style="font-weight: 500;">Role History Settings</label>
                    <div class="control">
                      <label class="checkbox has-text-white">
                        <input type="checkbox" name="restore_all_roles" style="margin-right: 8px;" disabled>
                        Restore all previous roles when member rejoins
                      </label>
                    </div>
                  </div>
                  <div class="field">
                    <label class="label has-text-white" style="font-weight: 500;">History Retention (Days)</label>
                    <div class="control has-icons-left">
                      <input class="input" type="number" name="history_retention_days" value="30" min="1" max="365" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;" disabled>
                      <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-calendar"></i></span>
                    </div>
                    <p class="help has-text-grey-light">How long to keep role history data</p>
                  </div>
                  <div class="field">
                    <div class="control">
                      <button class="button is-primary is-fullwidth" type="submit" name="save_role_history" style="border-radius: 6px; font-weight: 600;" disabled>
                        <span class="icon"><i class="fas fa-save"></i></span>
                        <span>Save Role History Settings</span>
                      </button>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <?php if ($serverManagementSettings['messageTracking']): ?>
          <div class="column is-half mb-1">
            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636;">
              <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                <p class="card-header-title has-text-white" style="font-weight: 600;">
                  <span class="icon mr-2 has-text-danger"><i class="fas fa-eye"></i></span>
                  Message Tracking Configuration
                </p>
                <div class="card-header-icon">
                  <span class="tag is-warning is-light">
                    <span class="icon"><i class="fas fa-clock"></i></span>
                    <span>Coming Soon</span>
                  </span>
                </div>
              </header>
              <div class="card-content">
                <div class="notification is-warning is-light mb-1">
                  <p class="has-text-dark"><strong>Coming Soon:</strong> This feature is currently in development and will be available in a future update.</p>
                </div>
                <p class="has-text-white-ter mb-1">Configure message tracking for edited and deleted messages in your Discord server.</p>
                <form action="" method="post">
                  <div class="field">
                    <label class="label has-text-white" style="font-weight: 500;">Message Log Channel ID</label>
                    <div class="control has-icons-left">
                      <input class="input" type="text" name="message_log_channel_id" placeholder="e.g. 123456789123456789" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;" disabled>
                      <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-hashtag"></i></span>
                    </div>
                    <p class="help has-text-grey-light">Channel where message edit/delete logs will be sent</p>
                  </div>
                  <div class="field">
                    <label class="label has-text-white" style="font-weight: 500;">Tracking Options</label>
                    <div class="control">
                      <label class="checkbox has-text-white mb-2" style="display: block;">
                        <input type="checkbox" name="track_edits" style="margin-right: 8px;" disabled>
                        Track message edits
                      </label>
                      <label class="checkbox has-text-white">
                        <input type="checkbox" name="track_deletes" style="margin-right: 8px;" disabled>
                        Track message deletions
                      </label>
                    </div>
                  </div>
                  <div class="field">
                    <div class="control">
                      <button class="button is-primary is-fullwidth" type="submit" name="save_message_tracking" style="border-radius: 6px; font-weight: 600;" disabled>
                        <span class="icon"><i class="fas fa-save"></i></span>
                        <span>Save Message Tracking Settings</span>
                      </button>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <?php if ($serverManagementSettings['roleTracking']): ?>
          <div class="column is-half mb-1">
            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636;">
              <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                <p class="card-header-title has-text-white" style="font-weight: 600;">
                  <span class="icon mr-2 has-text-primary"><i class="fas fa-users-cog"></i></span>
                  Role Tracking Configuration
                </p>
                <div class="card-header-icon">
                  <span class="tag is-warning is-light">
                    <span class="icon"><i class="fas fa-clock"></i></span>
                    <span>Coming Soon</span>
                  </span>
                </div>
              </header>
              <div class="card-content">
                <div class="notification is-warning is-light mb-1">
                  <p class="has-text-dark"><strong>Coming Soon:</strong> This feature is currently in development and will be available in a future update.</p>
                </div>
                <p class="has-text-white-ter mb-1">Configure role change tracking for audit purposes in your Discord server.</p>
                <form action="" method="post">
                  <div class="field">
                    <label class="label has-text-white" style="font-weight: 500;">Role Log Channel ID</label>
                    <div class="control has-icons-left">
                      <input class="input" type="text" name="role_log_channel_id" placeholder="e.g. 123456789123456789" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;" disabled>
                      <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-hashtag"></i></span>
                    </div>
                    <p class="help has-text-grey-light">Channel where role change logs will be sent</p>
                  </div>
                  <div class="field">
                    <label class="label has-text-white" style="font-weight: 500;">Tracking Options</label>
                    <div class="control">
                      <label class="checkbox has-text-white mb-2" style="display: block;">
                        <input type="checkbox" name="track_role_additions" style="margin-right: 8px;" disabled>
                        Track role additions
                      </label>
                      <label class="checkbox has-text-white">
                        <input type="checkbox" name="track_role_removals" style="margin-right: 8px;" disabled>
                        Track role removals
                      </label>
                    </div>
                  </div>
                  <div class="field">
                    <div class="control">
                      <button class="button is-primary is-fullwidth" type="submit" name="save_role_tracking" style="border-radius: 6px; font-weight: 600;" disabled>
                        <span class="icon"><i class="fas fa-save"></i></span>
                        <span>Save Role Tracking Settings</span>
                      </button>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <?php if ($serverManagementSettings['serverRoleManagement']): ?>
          <div class="column is-half mb-1">
            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636;">
              <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                <p class="card-header-title has-text-white" style="font-weight: 600;">
                  <span class="icon mr-2 has-text-link"><i class="fas fa-cogs"></i></span>
                  Server Role Management Configuration
                </p>
                <div class="card-header-icon">
                  <span class="tag is-warning is-light">
                    <span class="icon"><i class="fas fa-clock"></i></span>
                    <span>Coming Soon</span>
                  </span>
                </div>
              </header>
              <div class="card-content">
                <div class="notification is-warning is-light mb-1">
                  <p class="has-text-dark"><strong>Coming Soon:</strong> This feature is currently in development and will be available in a future update.</p>
                </div>
                <p class="has-text-white-ter mb-1">Configure tracking for role creation and deletion within your Discord server.</p>
                <form action="" method="post">
                  <div class="field">
                    <label class="label has-text-white" style="font-weight: 500;">Server Management Log Channel ID</label>
                    <div class="control has-icons-left">
                      <input class="input" type="text" name="server_mgmt_log_channel_id" placeholder="e.g. 123456789123456789" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;" disabled>
                      <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-hashtag"></i></span>
                    </div>
                    <p class="help has-text-grey-light">Channel where server role management logs will be sent</p>
                  </div>
                  <div class="field">
                    <label class="label has-text-white" style="font-weight: 500;">Management Options</label>
                    <div class="control">
                      <label class="checkbox has-text-white mb-2" style="display: block;">
                        <input type="checkbox" name="track_role_creation" style="margin-right: 8px;" disabled>
                        Track role creation
                      </label>
                      <label class="checkbox has-text-white">
                        <input type="checkbox" name="track_role_deletion" style="margin-right: 8px;" disabled>
                        Track role deletion
                      </label>
                    </div>
                  </div>
                  <div class="field">
                    <div class="control">
                      <button class="button is-primary is-fullwidth" type="submit" name="save_server_role_management" style="border-radius: 6px; font-weight: 600;" disabled>
                        <span class="icon"><i class="fas fa-save"></i></span>
                        <span>Save Server Role Management Settings</span>
                      </button>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <?php if ($serverManagementSettings['userTracking']): ?>
          <div class="column is-half mb-1">
            <div class="card has-background-grey-darker" style="border-radius: 12px; border: 1px solid #363636;">
              <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
                <p class="card-header-title has-text-white" style="font-weight: 600;">
                  <span class="icon mr-2 has-text-info"><i class="fas fa-user-edit"></i></span>
                  User Tracking Configuration
                </p>
                <div class="card-header-icon">
                  <span class="tag is-warning is-light">
                    <span class="icon"><i class="fas fa-clock"></i></span>
                    <span>Coming Soon</span>
                  </span>
                </div>
              </header>
              <div class="card-content">
                <div class="notification is-warning is-light mb-1">
                  <p class="has-text-dark"><strong>Coming Soon:</strong> This feature is currently in development and will be available in a future update.</p>
                </div>
                <p class="has-text-white-ter mb-1">Configure user profile change tracking for your Discord server.</p>
                <form action="" method="post">
                  <div class="field">
                    <label class="label has-text-white" style="font-weight: 500;">User Log Channel ID</label>
                    <div class="control has-icons-left">
                      <input class="input" type="text" name="user_log_channel_id" placeholder="e.g. 123456789123456789" style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;" disabled>
                      <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-hashtag"></i></span>
                    </div>
                    <p class="help has-text-grey-light">Channel where user change logs will be sent</p>
                  </div>
                  <div class="field">
                    <label class="label has-text-white" style="font-weight: 500;">Tracking Options</label>
                    <div class="control">
                      <label class="checkbox has-text-white mb-2" style="display: block;">
                        <input type="checkbox" name="track_nickname_changes" style="margin-right: 8px;" disabled>
                        Track nickname changes
                      </label>
                      <label class="checkbox has-text-white mb-2" style="display: block;">
                        <input type="checkbox" name="track_avatar_changes" style="margin-right: 8px;" disabled>
                        Track avatar changes
                      </label>
                      <label class="checkbox has-text-white">
                        <input type="checkbox" name="track_status_changes" style="margin-right: 8px;" disabled>
                        Track status changes
                      </label>
                    </div>
                  </div>
                  <div class="field">
                    <div class="control">
                      <button class="button is-primary is-fullwidth" type="submit" name="save_user_tracking" style="border-radius: 6px; font-weight: 600;" disabled>
                        <span class="icon"><i class="fas fa-save"></i></span>
                        <span>Save User Tracking Settings</span>
                      </button>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
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
    const guildIdElement = document.getElementById('guild_id_config');
    const guildId = guildIdElement ? guildIdElement.value.trim() : '';
    // Debug logging
    console.log('UpdateDiscordSetting called:', { settingName, value, guildId });
    // Validate that we have a guild ID
    if (!guildId) {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'error',
        title: 'Guild ID is required. Please configure your Discord Server ID first.',
        showConfirmButton: false,
        timer: 4000,
        timerProgressBar: true
      });
      // Revert the toggle state
      const toggle = document.getElementById(settingName);
      if (toggle) {
        toggle.checked = !value;
      }
      return;
    }
    
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
    .then(response => {
      console.log('Response status:', response.status);
      console.log('Response ok:', response.ok);
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      return response.json();
    })
    .then(data => {
      console.log('Response data:', data);
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
        // Refresh the page after a short delay to show/hide management feature cards
        setTimeout(() => {
          window.location.reload();
        }, 2000);
      } else {
        // Show error and revert toggle
        Swal.fire({
          toast: true,
          position: 'top-end',
          icon: 'error',
          title: 'Failed to update setting: ' + (data.message || 'Unknown error'),
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
      console.error('Error details:', error);
      console.error('Error message:', error.message);
      // Show more specific error notification
      let errorMessage = 'Network error occurred';
      if (error.message.includes('HTTP error')) {
        errorMessage = `Server error: ${error.message}`;
      } else if (error.name === 'TypeError') {
        errorMessage = 'Network connection failed. Please check your internet connection.';
      } else if (error.message.includes('JSON')) {
        errorMessage = 'Server response format error. Please try again.';
      }
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'error',
        title: errorMessage,
        showConfirmButton: false,
        timer: 4000,
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
    // Initialize server management settings from PHP
    const serverManagementSettings = <?php echo json_encode($serverManagementSettings); ?>;
    const isLinked = <?php echo json_encode($is_linked); ?>;
    const needsRelink = <?php echo json_encode($needs_relink); ?>;
    const hasGuildId = <?php echo json_encode($hasGuildId); ?>;
    const settingToggles = [
      'welcomeMessage',
      'autoRole',
      'roleHistory', 
      'messageTracking',
      'roleTracking',
      'serverRoleManagement',
      'userTracking'
    ];
    // Set initial toggle states based on saved settings
    settingToggles.forEach(settingName => {
      const toggle = document.getElementById(settingName);
      if (toggle) {
        toggle.checked = serverManagementSettings[settingName] || false;
        // Only add event listeners to enabled toggles
        if (!toggle.disabled) {
          toggle.addEventListener('change', function() {
            updateDiscordSetting(settingName, this.checked);
          });
        } else {
          // Add click handler for disabled toggles to show helpful message
          toggle.addEventListener('click', function(e) {
            e.preventDefault();
            let message = '';
            if (!isLinked || needsRelink) {
              message = 'Please link your Discord account first to enable management features.';
            } else if (!hasGuildId) {
              message = 'Please configure your Discord Server ID above to enable management features.';
            } else {
              message = 'Management features are currently disabled.';
            }
            Swal.fire({
              toast: true,
              position: 'top-end',
              icon: 'warning',
              title: message,
              showConfirmButton: false,
              timer: 3000,
              timerProgressBar: true
            });
          });
        }
      }
    });
  });
</script>
<?php } ?>
<?php
$scripts = ob_get_clean();
include "layout.php";
?>