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

// Initialize console logs array
$consoleLogs = array();

// Initialize Discord Bot Database Connection
$discord_conn = new mysqli($db_servername, $db_username, $db_password, "specterdiscordbot");
if ($discord_conn->connect_error) {
  die('Discord Database Connection failed: ' . $discord_conn->connect_error);
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
      if (
        !empty($discordData['access_token']) &&
        !empty($discordData['refresh_token'])
      ) {
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
                  if ($days > 0)
                    $parts[] = $days . ' day' . ($days > 1 ? 's' : '');
                  if ($hours > 0)
                    $parts[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
                  if ($minutes > 0 && count($parts) < 2)
                    $parts[] = $minutes . ' minute' . ($minutes > 1 ? 's' : '');
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

// Handle StreamersConnect auth_data callback
if (isset($_GET['auth_data'])) {
  $decoded = json_decode(base64_decode($_GET['auth_data']), true);
  if (!is_array($decoded) || empty($decoded['success'])) {
    $linkingMessage = "Authentication failed or was cancelled.";
    $linkingMessageType = "is-danger";
  } elseif (isset($decoded['service']) && $decoded['service'] === 'discord') {
    $access_token = $decoded['access_token'] ?? null;
    $refresh_token = $decoded['refresh_token'] ?? null;
    $expires_in = isset($decoded['expires_in']) ? intval($decoded['expires_in']) : null;
    $discord_user = $decoded['user'] ?? [];
    $discord_id = $discord_user['id'] ?? null;
    if ($discord_id && $access_token) {
      if ($refresh_token) {
        $sql = "INSERT INTO discord_users (user_id, discord_id, access_token, refresh_token, reauth) VALUES (?, ?, ?, ?, 0) ON DUPLICATE KEY UPDATE discord_id = VALUES(discord_id), access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), reauth = 0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isss", $user_id, $discord_id, $access_token, $refresh_token);
      } else {
        $sql = "INSERT INTO discord_users (user_id, discord_id, access_token, reauth) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE discord_id = VALUES(discord_id), access_token = VALUES(access_token), reauth = 0";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $user_id, $discord_id, $access_token);
      }
      if ($stmt->execute()) {
        $linkingMessage = "Discord account successfully linked via StreamersConnect!";
        $linkingMessageType = "is-success";
        header("Location: discordbot.php");
        exit();
      } else {
        $linkingMessage = "Linked, but failed to save Discord information.";
        $linkingMessageType = "is-warning";
      }
      $stmt->close();
    } else {
      $linkingMessage = "Error: Invalid auth data received from StreamersConnect.";
      $linkingMessageType = "is-danger";
    }
  }
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
      if (isset($params['error'])) {
        $linkingMessage .= " Error: " . htmlspecialchars($params['error']);
      }
      if (isset($params['error_description'])) {
        $linkingMessage .= " Description: " . htmlspecialchars($params['error_description']);
      }
    }
  }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  // Handle Message Tracking Configuration save
  if (isset($_POST['save_message_tracking'])) {
    // Fetch guild ID from discord_users table
    $message_tracking_guild_id = null;
    if ($is_linked) {
      $guildStmt = $conn->prepare("SELECT guild_id FROM discord_users WHERE user_id = ?");
      $guildStmt->bind_param("i", $user_id);
      $guildStmt->execute();
      $guildResult = $guildStmt->get_result();
      if ($guildResult->num_rows > 0) {
        $guildData = $guildResult->fetch_assoc();
        $message_tracking_guild_id = $guildData['guild_id'];
      }
      $guildStmt->close();
    }
    // Check if we have both guild ID and database connection
    if (!empty($message_tracking_guild_id) && $discord_conn && !$discord_conn->connect_error) {
      if (!empty($_POST['message_tracking_log_channel_id'])) {
        $message_tracking_log_channel_id = $_POST['message_tracking_log_channel_id'];
        $track_message_edits = isset($_POST['track_message_edits']) ? 1 : 0;
        $track_message_deletes = isset($_POST['track_message_deletes']) ? 1 : 0;
        if (!$track_message_edits && !$track_message_deletes) {
          $errorMsg .= "Please select at least one tracking option (message edits or deletions).<br>";
        } else {
          $messageTrackingConfig = [
            'enabled' => 1,
            'log_channel_id' => $message_tracking_log_channel_id,
            'track_edits' => $track_message_edits,
            'track_deletes' => $track_message_deletes
          ];
          $stmt = $discord_conn->prepare(
            "UPDATE server_management SET message_tracking_configuration = ? WHERE server_id = ?"
          );
          $stmt->bind_param("ss", json_encode($messageTrackingConfig), $message_tracking_guild_id);
          if ($stmt->execute()) {
            // Fetch channels to get the channel name for the success message
            $message_tracking_channels = array();
            if (!empty($discordData['access_token'])) {
              $message_tracking_channels = fetchGuildChannels($discordData['access_token'], $message_tracking_guild_id);
              $channel_name = getChannelNameFromId($message_tracking_log_channel_id, $message_tracking_channels);
            } else {
              $channel_name = $message_tracking_log_channel_id;
            }
            $buildStatus .= "Message Tracking configuration saved successfully (Log Channel: " . $channel_name . ")<br>";
            $existingMessageTrackingEnabled = 1;
            $existingMessageTrackingLogChannel = $message_tracking_log_channel_id;
            $existingMessageTrackingEdits = $track_message_edits;
            $existingMessageTrackingDeletes = $track_message_deletes;
          } else {
            $errorMsg .= "Failed to save message tracking configuration: " . $discord_conn->error . "<br>";
          }
          $stmt->close();
        }
      } else {
        $errorMsg .= "Message tracking log channel is required.<br>";
      }
    } else {
      $errorMsg .= "Guild not linked or no guild selected.<br>";
    }
  }
  // Handle FreeStuff (Free Games) settings save
  if (isset($_POST['save_freestuff_settings'])) {
    // Fetch guild ID from discord_users table
    $freestuff_guild_id = null;
    if ($is_linked) {
      $guildStmt = $conn->prepare("SELECT guild_id FROM discord_users WHERE user_id = ?");
      $guildStmt->bind_param("i", $user_id);
      $guildStmt->execute();
      $guildResult = $guildStmt->get_result();
      if ($guildResult->num_rows > 0) {
        $guildData = $guildResult->fetch_assoc();
        $freestuff_guild_id = $guildData['guild_id'];
      }
      $guildStmt->close();
    }
    // Check if we have both guild ID and database connection
    if (!empty($freestuff_guild_id) && $discord_conn && !$discord_conn->connect_error) {
      $fs_channel = trim($_POST['freestuff_channel_id'] ?? '');
      // Validate required fields
      if (empty($fs_channel)) {
        $errorMsg .= "Discord channel is required for Free Games module.<br>";
      } else {
        // Check if record exists
        $checkStmt = $discord_conn->prepare("SELECT id FROM freestuff_settings WHERE guild_id = ?");
        $checkStmt->bind_param("s", $freestuff_guild_id);
        $checkStmt->execute();
        $checkRes = $checkStmt->get_result();
        if ($checkRes && $checkRes->num_rows > 0) {
          // Update
          $updateStmt = $discord_conn->prepare("UPDATE freestuff_settings SET channel_id = ?, enabled = 1 WHERE guild_id = ?");
          $updateStmt->bind_param("ss", $fs_channel, $freestuff_guild_id);
          $ok = $updateStmt->execute();
          if ($ok) {
            $buildStatus .= "Free Games settings updated successfully.<br>";
            $existingFreestuffChannelID = $fs_channel;
            $existingFreestuffEnabled = 1;
          } else {
            $errorMsg .= "Failed to update Free Games settings: " . $discord_conn->error . "<br>";
          }
          $updateStmt->close();
        } else {
          // Insert
          $insertStmt = $discord_conn->prepare("INSERT INTO freestuff_settings (guild_id, channel_id, enabled) VALUES (?, ?, 1)");
          $insertStmt->bind_param("ss", $freestuff_guild_id, $fs_channel);
          if ($insertStmt->execute()) {
            $buildStatus .= "Free Games settings saved successfully.<br>";
            $existingFreestuffChannelID = $fs_channel;
            $existingFreestuffEnabled = 1;
          } else {
            $errorMsg .= "Failed to save Free Games settings: " . $discord_conn->error . "<br>";
          }
          $insertStmt->close();
        }
        $checkStmt->close();
      }
    } else {
      $errorMsg .= "Guild not linked or no guild selected for Free Games settings.<br>";
    }
  }
  try {
    // Handle JSON POST requests for server management settings
    $content = file_get_contents('php://input');
    $data = json_decode($content, true);
    if ($data && isset($data['action'])) {
      $action = $data['action'];
      $server_id = $data['server_id'] ?? null;
      if (!$server_id) {
        echo json_encode(['success' => false, 'message' => 'Server ID required']);
        exit;
      }
      if ($action === 'save_auto_role') {
        $auto_role_id = $data['auto_role_id'] ?? null;
        if (!$auto_role_id) {
          echo json_encode(['success' => false, 'message' => 'Auto role ID required']);
          exit;
        }
        // Update server_management table
        $stmt = $discord_conn->prepare("UPDATE server_management SET auto_role_assignment_configuration_role_id = ? WHERE server_id = ?");
        $stmt->bind_param("ss", $auto_role_id, $server_id);
        if ($stmt->execute()) {
          echo json_encode(['success' => true, 'message' => 'Auto role saved successfully']);
        } else {
          echo json_encode(['success' => false, 'message' => 'Failed to save auto role']);
        }
        $stmt->close();
      } elseif ($action === 'save_role_history') {
        $enabled = isset($data['enabled']) ? (int) $data['enabled'] : 0;
        $retention_days = isset($data['retention_days']) ? (int) $data['retention_days'] : 30;
        // Validate retention days
        if ($retention_days < 1 || $retention_days > 365) {
          $retention_days = 30;
        }
        // Create JSON config
        $role_history_config = json_encode([
          'enabled' => $enabled,
          'retention_days' => $retention_days
        ]);
        // Update server_management table
        $stmt = $discord_conn->prepare("UPDATE server_management SET role_history_configuration = ? WHERE server_id = ?");
        $stmt->bind_param("ss", $role_history_config, $server_id);
        if ($stmt->execute()) {
          echo json_encode(['success' => true, 'message' => 'Role History settings saved successfully']);
        } else {
          echo json_encode(['success' => false, 'message' => 'Failed to save Role History settings']);
        }
        $stmt->close();
      }
    } else {
      // Handle form POST requests
      if (isset($_POST['guild_id']) && !isset($_POST['live_channel_id']) && !isset($_POST['save_stream_online']) && !isset($_POST['save_alert_channels']) && !isset($_POST['save_stream_monitoring'])) {
        // Server Configuration: Save only guild_id to discord_users table
        $guild_id = $_POST['guild_id'];
        $stmt = $conn->prepare("UPDATE discord_users SET guild_id = ? WHERE user_id = ?");
        $stmt->bind_param("si", $guild_id, $user_id);
        if ($stmt->execute()) {
          $buildStatus = "Discord Server configuration saved successfully";
        } else {
          $errorMsg = "Error saving Discord Server configuration: " . $stmt->error;
        }
        $stmt->close();
        updateExistingDiscordValues(); // Refresh existing values after update
      } elseif (isset($_POST['save_stream_online'])) {
        // Save Stream Online / Live Status settings
        $guild_id = $_POST['guild_id'];
        $live_channel_id = $_POST['live_channel_id'] ?? null;
        $onlineText = isset($_POST['online_text']) ? $_POST['online_text'] : null;
        $offlineText = isset($_POST['offline_text']) ? $_POST['offline_text'] : null;
        $streamChannelID = !empty($_POST['stream_channel_id']) ? $_POST['stream_channel_id'] : null;
        $streamAlertEveryone = isset($_POST['stream_alert_everyone']) ? 1 : 0;
        $streamAlertCustomRole = !empty($_POST['stream_alert_custom_role']) ? $_POST['stream_alert_custom_role'] : null;
        $stmt = $conn->prepare("UPDATE discord_users SET live_channel_id = ?, guild_id = ? WHERE user_id = ?");
        $stmt->bind_param("ssi", $live_channel_id, $guild_id, $user_id);
        if ($stmt->execute()) {
          $buildStatus .= "Live Channel ID and Guild ID updated successfully<br>";
        } else {
          $errorMsg .= "Error updating Live Channel ID and Guild ID: " . $stmt->error . "<br>";
        }
        // Validate lengths for online/offline text
        if (!is_null($onlineText) && strlen($onlineText) > 20) {
          $errorMsg .= "Online text cannot exceed 20 characters. Current length: " . strlen($onlineText) . "<br>";
        } elseif (!is_null($offlineText) && strlen($offlineText) > 20) {
          $errorMsg .= "Offline text cannot exceed 20 characters. Current length: " . strlen($offlineText) . "<br>";
        } else {
          $stmt = $conn->prepare("UPDATE discord_users SET online_text = ?, offline_text = ? WHERE user_id = ?");
          $stmt->bind_param("ssi", $onlineText, $offlineText, $user_id);
          if ($stmt->execute()) {
            $buildStatus .= "Online and Offline Text has been updated successfully<br>";
          } else {
            $errorMsg .= "Error updating Online and Offline Text: " . $stmt->error . "<br>";
          }
        }
        $stmt = $conn->prepare("UPDATE discord_users SET stream_alert_channel_id = ?, stream_alert_everyone = ?, stream_alert_custom_role = ? WHERE user_id = ?");
        $stmt->bind_param("iisi", $streamChannelID, $streamAlertEveryone, $streamAlertCustomRole, $user_id);
        if ($stmt->execute()) {
          $buildStatus .= "Stream Alert Channel and mention settings updated successfully<br>";
        } else {
          $errorMsg .= "Error updating Stream Alert Channel or mention settings: " . $stmt->error . "<br>";
        }
        $stmt->close();
        updateExistingDiscordValues(); // Refresh existing values after update
      } elseif (isset($_POST['save_alert_channels'])) {
        // Save Alerts Channels (moderation, event alerts)
        $moderationChannelID = !empty($_POST['mod_channel_id']) ? $_POST['mod_channel_id'] : null;
        $alertChannelID = !empty($_POST['alert_channel_id']) ? $_POST['alert_channel_id'] : null;
        $stmt = $conn->prepare("UPDATE discord_users SET moderation_channel_id = ?, alert_channel_id = ? WHERE user_id = ?");
        $stmt->bind_param("iii", $moderationChannelID, $alertChannelID, $user_id);
        if ($stmt->execute()) {
          $buildStatus .= "Moderation and Alert channels updated successfully<br>";
        } else {
          $errorMsg .= "Error updating Moderation/Alert channels: " . $stmt->error . "<br>";
        }
        $stmt->close();
        updateExistingDiscordValues();
      } elseif (isset($_POST['save_stream_monitoring'])) {
        // Save the Twitch Stream Monitoring channel selection
        $memberStreamsID = !empty($_POST['twitch_stream_monitor_id']) ? $_POST['twitch_stream_monitor_id'] : null;
        $stmt = $conn->prepare("UPDATE discord_users SET member_streams_id = ? WHERE user_id = ?");
        $stmt->bind_param("ii", $memberStreamsID, $user_id);
        if ($stmt->execute()) {
          $buildStatus .= "Twitch Stream Monitoring channel updated successfully<br>";
        } else {
          $errorMsg .= "Error updating Twitch Stream Monitoring channel: " . $stmt->error . "<br>";
        }
        $stmt->close();
        updateExistingDiscordValues();
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
        $monitor_username = trim(str_replace(' ', '', $_POST['monitor_username']));
        if (empty($monitor_username)) {
          $errorMsg = "Twitch Username cannot be empty.";
        } elseif (strtolower($monitor_username) === strtolower($username)) {
          $errorMsg = "You cannot track your own channel.";
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
      } elseif (isset($_POST['save_role_history'])) {
        // Save Role History settings
        // Fetch guild ID from discord_users table if not already set
        $role_history_guild_id = null;
        if ($is_linked) {
          $guildStmt = $conn->prepare("SELECT guild_id FROM discord_users WHERE user_id = ?");
          $guildStmt->bind_param("i", $user_id);
          $guildStmt->execute();
          $guildResult = $guildStmt->get_result();
          if ($guildResult->num_rows > 0) {
            $guildData = $guildResult->fetch_assoc();
            $role_history_guild_id = $guildData['guild_id'];
          }
          $guildStmt->close();
        }
        // Check if we have both guild ID and database connection
        if (!empty($role_history_guild_id) && $discord_conn && !$discord_conn->connect_error) {
          $restore_roles = isset($_POST['restore_roles']) ? 1 : 0;
          $history_retention_days = isset($_POST['history_retention_days']) ? (int) $_POST['history_retention_days'] : 30;
          // Validate retention days
          if ($history_retention_days < 1 || $history_retention_days > 365) {
            $history_retention_days = 30;
          }
          // Create JSON config
          $role_history_config = json_encode([
            'enabled' => $restore_roles,
            'retention_days' => $history_retention_days
          ]);
          // Update server_management table in Discord bot database
          $stmt = $discord_conn->prepare("UPDATE server_management SET role_history_configuration = ? WHERE server_id = ?");
          if ($stmt) {
            $stmt->bind_param("ss", $role_history_config, $role_history_guild_id);
            if ($stmt->execute()) {
              $buildStatus .= "Role History settings saved successfully (Restore Roles: " . ($restore_roles ? 'Yes' : 'No') . ", Retention: " . $history_retention_days . " days)<br>";
            } else {
              $errorMsg .= "Error saving Role History settings: " . $stmt->error . "<br>";
            }
            $stmt->close();
          } else {
            $errorMsg .= "Error preparing Role History update statement: " . $discord_conn->error . "<br>";
          }
        } else {
          $errorMsg .= "Cannot save Role History settings: User not linked or no guild selected.<br>";
        }
      } elseif (isset($_POST['save_role_tracking'])) {
        // Save Role Tracking settings
        // Fetch guild ID from discord_users table if not already set
        $role_tracking_guild_id = null;
        if ($is_linked) {
          $guildStmt = $conn->prepare("SELECT guild_id FROM discord_users WHERE user_id = ?");
          $guildStmt->bind_param("i", $user_id);
          $guildStmt->execute();
          $guildResult = $guildStmt->get_result();
          if ($guildResult->num_rows > 0) {
            $guildData = $guildResult->fetch_assoc();
            $role_tracking_guild_id = $guildData['guild_id'];
          }
          $guildStmt->close();
        }
        // Check if we have both guild ID and database connection
        if (!empty($role_tracking_guild_id) && $discord_conn && !$discord_conn->connect_error) {
          $role_tracking_enabled = 1;  // Enabled when user saves
          $role_tracking_log_channel = !empty($_POST['role_tracking_log_channel_id']) ? $_POST['role_tracking_log_channel_id'] : null;
          $track_additions = isset($_POST['track_role_additions']) ? 1 : 0;
          $track_removals = isset($_POST['track_role_removals']) ? 1 : 0;

          if (!$role_tracking_log_channel) {
            $errorMsg .= "Log channel is required for Role Tracking.<br>";
          } else if (!$track_additions && !$track_removals) {
            $errorMsg .= "You must select at least one tracking option (additions or removals).<br>";
          } else {
            // Create JSON config
            $role_tracking_config = json_encode([
              'enabled' => $role_tracking_enabled,
              'log_channel_id' => $role_tracking_log_channel,
              'track_additions' => $track_additions,
              'track_removals' => $track_removals
            ]);
            // Update server_management table in Discord bot database
            $stmt = $discord_conn->prepare("UPDATE server_management SET role_tracking_configuration = ? WHERE server_id = ?");
            if ($stmt) {
              $stmt->bind_param("ss", $role_tracking_config, $role_tracking_guild_id);
              if ($stmt->execute()) {
                $channel_name = getChannelNameFromId($role_tracking_log_channel, $guildChannels);
                $buildStatus .= "Role Tracking settings saved successfully (Log Channel: " . $channel_name . ")<br>";
                $existingRoleTrackingEnabled = $role_tracking_enabled;
                $existingRoleTrackingLogChannel = $role_tracking_log_channel;
              } else {
                $errorMsg .= "Error saving Role Tracking settings: " . $stmt->error . "<br>";
              }
              $stmt->close();
            } else {
              $errorMsg .= "Error preparing Role Tracking update statement: " . $discord_conn->error . "<br>";
            }
          }
        } else {
          $errorMsg .= "Cannot save Role Tracking settings: User not linked or no guild selected.<br>";
        }
      } elseif (isset($_POST['save_server_role_management'])) {
        // Save Server Role Management settings
        $server_role_management_guild_id = null;
        if ($is_linked) {
          $guildStmt = $conn->prepare("SELECT guild_id FROM discord_users WHERE user_id = ?");
          $guildStmt->bind_param("i", $user_id);
          $guildStmt->execute();
          $guildResult = $guildStmt->get_result();
          if ($guildResult->num_rows > 0) {
            $guildData = $guildResult->fetch_assoc();
            $server_role_management_guild_id = $guildData['guild_id'];
          }
          $guildStmt->close();
        }

        if (!empty($server_role_management_guild_id) && $discord_conn && !$discord_conn->connect_error) {
          $server_role_mgmt_enabled = 1;
          $server_role_mgmt_log_channel = !empty($_POST['server_mgmt_log_channel_id']) ? $_POST['server_mgmt_log_channel_id'] : null;
          $track_role_creation = isset($_POST['track_role_creation']) ? 1 : 0;
          $track_role_deletion = isset($_POST['track_role_deletion']) ? 1 : 0;
          $track_role_edits = isset($_POST['track_role_edits']) ? 1 : 0;

          // Validate that at least one tracking option is selected
          if (empty($server_role_mgmt_log_channel)) {
            $errorMsg .= "Please select a log channel for Server Role Management.<br>";
          } elseif (!$track_role_creation && !$track_role_deletion && !$track_role_edits) {
            $errorMsg .= "Please select at least one tracking option (role creation, deletion, or edits).<br>";
          } else {
            // Prepare the configuration JSON
            $config = json_encode([
              'enabled' => $server_role_mgmt_enabled,
              'log_channel_id' => $server_role_mgmt_log_channel,
              'track_creation' => $track_role_creation,
              'track_deletion' => $track_role_deletion,
              'track_edits' => $track_role_edits
            ]);

            $stmt = $discord_conn->prepare("UPDATE server_management SET server_role_management_configuration = ? WHERE server_id = ?");
            if ($stmt) {
              $stmt->bind_param("ss", $config, $server_role_management_guild_id);
              if ($stmt->execute()) {
                // Fetch channels to get the channel name for the success message
                $server_role_mgmt_channels = array();
                if (!empty($discordData['access_token'])) {
                  $server_role_mgmt_channels = fetchGuildChannels($discordData['access_token'], $server_role_management_guild_id);
                  $channel_name = getChannelNameFromId($server_role_mgmt_log_channel, $server_role_mgmt_channels);
                } else {
                  $channel_name = $server_role_mgmt_log_channel;
                }
                $buildStatus .= "Server Role Management settings saved successfully (Log Channel: " . $channel_name . ")<br>";
                $existingServerRoleManagementEnabled = $server_role_mgmt_enabled;
                $existingServerRoleManagementLogChannel = $server_role_mgmt_log_channel;
                $existingRoleCreationTracking = $track_role_creation;
                $existingRoleDeletionTracking = $track_role_deletion;
                $existingRoleEditTracking = $track_role_edits;
              } else {
                $errorMsg .= "Error saving Server Role Management settings: " . $stmt->error . "<br>";
              }
              $stmt->close();
            } else {
              $errorMsg .= "Error preparing Server Role Management update statement: " . $discord_conn->error . "<br>";
            }
          }
        } else {
          $errorMsg .= "Cannot save Server Role Management settings: User not linked or no guild selected.<br>";
        }
      }
    }
    // Handle User Tracking Configuration save
    if (isset($_POST['save_user_tracking'])) {
      $user_tracking_guild_id = null;
      $guildStmt = $conn->prepare("SELECT guild_id FROM discord_users WHERE user_id = ?");
      if ($guildStmt) {
        $guildStmt->bind_param("i", $user_id);
        $guildStmt->execute();
        $guildResult = $guildStmt->get_result();
        if ($guildResult->num_rows > 0) {
          $guildData = $guildResult->fetch_assoc();
          $user_tracking_guild_id = $guildData['guild_id'];
        }
        $guildStmt->close();
      }
      if (!empty($user_tracking_guild_id) && $discord_conn && !$discord_conn->connect_error) {
        $user_tracking_enabled = 1;
        $user_tracking_log_channel = !empty($_POST['user_tracking_log_channel_id']) ? $_POST['user_tracking_log_channel_id'] : null;
        $track_user_joins = isset($_POST['track_user_joins']) ? 1 : 0;
        $track_user_leaves = isset($_POST['track_user_leaves']) ? 1 : 0;
        $track_user_nickname = isset($_POST['track_user_nickname']) ? 1 : 0;
        $track_user_username = isset($_POST['track_user_username']) ? 1 : 0;
        $track_user_avatar = isset($_POST['track_user_avatar']) ? 1 : 0;
        $track_user_status = isset($_POST['track_user_status']) ? 1 : 0;
        // Validate that at least one tracking option is selected
        if (empty($user_tracking_log_channel)) {
          $errorMsg .= "Please select a log channel for User Tracking.<br>";
        } elseif (!$track_user_joins && !$track_user_leaves && !$track_user_nickname && !$track_user_username && !$track_user_avatar && !$track_user_status) {
          $errorMsg .= "Please select at least one tracking option.<br>";
        } else {
          // Prepare the configuration JSON
          $config = json_encode([
            'enabled' => $user_tracking_enabled,
            'log_channel_id' => $user_tracking_log_channel,
            'track_joins' => $track_user_joins,
            'track_leaves' => $track_user_leaves,
            'track_nickname' => $track_user_nickname,
            'track_username' => $track_user_username,
            'track_avatar' => $track_user_avatar,
            'track_status' => $track_user_status
          ]);
          $stmt = $discord_conn->prepare("UPDATE server_management SET user_tracking_configuration = ? WHERE server_id = ?");
          if ($stmt) {
            $stmt->bind_param("ss", $config, $user_tracking_guild_id);
            if ($stmt->execute()) {
              // Fetch channels to get the channel name for the success message
              $user_tracking_channels = array();
              if (!empty($discordData['access_token'])) {
                $user_tracking_channels = fetchGuildChannels($discordData['access_token'], $user_tracking_guild_id);
                $channel_name = getChannelNameFromId($user_tracking_log_channel, $user_tracking_channels);
              } else {
                $channel_name = $user_tracking_log_channel;
              }
              $buildStatus .= "User Tracking settings saved successfully (Log Channel: " . $channel_name . ")<br>";
              $existingUserTrackingEnabled = $user_tracking_enabled;
              $existingUserTrackingLogChannel = $user_tracking_log_channel;
              $existingUserJoinTracking = $track_user_joins;
              $existingUserLeaveTracking = $track_user_leaves;
              $existingUserNicknameTracking = $track_user_nickname;
              $existingUserUsernameTracking = $track_user_username;
              $existingUserAvatarTracking = $track_user_avatar;
              $existingUserStatusTracking = $track_user_status;
            } else {
              $errorMsg .= "Error saving User Tracking settings: " . $stmt->error . "<br>";
            }
            $stmt->close();
          } else {
            $errorMsg .= "Error preparing User Tracking update statement: " . $discord_conn->error . "<br>";
          }
        }
      } else {
        $errorMsg .= "Cannot save User Tracking settings: User not linked or no guild selected.<br>";
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
$existingStreamAlertEveryone = $discordData['stream_alert_everyone'] ?? false;
$existingStreamAlertCustomRole = $discordData['stream_alert_custom_role'] ?? "";
// FreeStuff (Free Games) default settings
$existingFreestuffEnabled = 0;
$existingFreestuffChannelID = "";
$existingFreestuffTwitchUser = "";
// Initialize server management log channel IDs as empty (will be loaded from server_management table)
$existingWelcomeChannelID = "";
$existingWelcomeMessage = "";
$existingWelcomeUseDefault = false;
$existingWelcomeEmbed = false;
$existingAutoRoleID = "";
$existingMessageLogChannelID = "";
$existingMessageTrackingEnabled = 0;
$existingMessageTrackingLogChannel = "";
$existingMessageTrackingEdits = 1;
$existingMessageTrackingDeletes = 1;
$existingServerMgmtLogChannelID = "";
$existingUserLogChannelID = "";
$existingReactionRolesChannelID = "";
$existingReactionRolesMessage = "";
$existingReactionRolesMappings = "";
$existingAllowMultipleReactions = false;
$existingRulesChannelID = "";
$existingRulesTitle = "";
$existingRulesContent = "";
$existingRulesColor = "";
$existingRulesAcceptRoleID = "";
$existingWelcomeColour = "";
$existingStreamScheduleChannelID = "";
$existingStreamScheduleTitle = "";
$existingStreamScheduleContent = "";
$existingStreamScheduleColor = "";
$existingStreamScheduleTimezone = "";
$existingRoleHistoryEnabled = 0;
$existingRoleHistoryRetention = 30;
$existingRoleTrackingEnabled = 0;
$existingRoleTrackingLogChannel = "";
$existingRoleTrackingAdditions = 1;
$existingRoleTrackingRemovals = 1;
$existingServerRoleManagementEnabled = 0;
$existingServerRoleManagementLogChannel = "";
$existingRoleCreationTracking = 1;
$existingRoleDeletionTracking = 1;
$existingRoleEditTracking = 1;
$existingUserTrackingEnabled = 0;
$existingUserTrackingLogChannel = "";
$existingUserJoinTracking = 1;
$existingUserLeaveTracking = 1;
$existingUserNicknameTracking = 1;
$existingUserUsernameTracking = 1;
$existingUserAvatarTracking = 1;
$existingUserStatusTracking = 1;
$hasGuildId = !empty($existingGuildId) && trim($existingGuildId) !== "";
// Check if manual IDs mode is explicitly enabled (only true if database value is 1)
$useManualIds = (isset($discordData['manual_ids']) && $discordData['manual_ids'] == 1);
// Debug logging to help track down the issue (can be removed once issue is resolved)
$debugData = json_encode([
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
]);

// Fetch server management settings from Discord bot database
$serverManagementSettings = [
  'welcomeMessage' => false,
  'autoRole' => false,
  'roleHistory' => false,
  'messageTracking' => false,
  'roleTracking' => false,
  'serverRoleManagement' => false,
  'userTracking' => false,
  'reactionRoles' => false,
  'rulesConfiguration' => false,
  'streamSchedule' => false,
  'embedBuilder' => false,
  'freeGames' => false
];

if ($is_linked && $hasGuildId) {
  if (!$discord_conn->connect_error) {
    $serverMgmtStmt = $discord_conn->prepare("SELECT * FROM server_management WHERE server_id = ?");
    $serverMgmtStmt->bind_param("s", $existingGuildId);
    $serverMgmtStmt->execute();
    $serverMgmtResult = $serverMgmtStmt->get_result();
    if ($serverMgmtData = $serverMgmtResult->fetch_assoc()) {
      $serverManagementSettings = [
        'welcomeMessage' => (bool) $serverMgmtData['welcomeMessage'],
        'autoRole' => (bool) $serverMgmtData['autoRole'],
        'roleHistory' => (bool) $serverMgmtData['roleHistory'],
        'messageTracking' => (bool) $serverMgmtData['messageTracking'],
        'roleTracking' => (bool) $serverMgmtData['roleTracking'],
        'serverRoleManagement' => (bool) $serverMgmtData['serverRoleManagement'],
        'userTracking' => (bool) $serverMgmtData['userTracking'],
        'reactionRoles' => (bool) $serverMgmtData['reactionRoles'],
        'rulesConfiguration' => (bool) $serverMgmtData['rulesConfiguration'],
        'streamSchedule' => (bool) $serverMgmtData['streamSchedule'],
        'embedBuilder' => (bool) $serverMgmtData['embedBuilder'],
        'freeGames' => (bool) ($serverMgmtData['freeGames'] ?? 0)
      ];
      // Override channel IDs with values from server_management table if they exist
      if (!empty($serverMgmtData['welcome_message_configuration_channel'])) {
        $existingWelcomeChannelID = $serverMgmtData['welcome_message_configuration_channel'];
      }
      $existingWelcomeMessage = $serverMgmtData['welcome_message_configuration_message'] ?? "";
      $existingWelcomeUseDefault = (int) ($serverMgmtData['welcome_message_configuration_default'] ?? 1) === 1;
      $existingWelcomeEmbed = (int) ($serverMgmtData['welcome_message_configuration_embed'] ?? 0) === 1;
      $existingWelcomeColour = $serverMgmtData['welcome_message_configuration_colour'] ?? "#00d1b2";
      if (!empty($serverMgmtData['auto_role_assignment_configuration_role_id'])) {
        $existingAutoRoleID = $serverMgmtData['auto_role_assignment_configuration_role_id'];
      }
      // Parse role_history_configuration JSON
      if (!empty($serverMgmtData['role_history_configuration'])) {
        $roleHistoryConfig = json_decode($serverMgmtData['role_history_configuration'], true);
        if ($roleHistoryConfig && is_array($roleHistoryConfig)) {
          $existingRoleHistoryEnabled = isset($roleHistoryConfig['enabled']) ? (int) $roleHistoryConfig['enabled'] : 0;
          $existingRoleHistoryRetention = isset($roleHistoryConfig['retention_days']) ? (int) $roleHistoryConfig['retention_days'] : 30;
        }
      }
      // Parse role_tracking_configuration JSON
      if (!empty($serverMgmtData['role_tracking_configuration'])) {
        $roleTrackingConfig = json_decode($serverMgmtData['role_tracking_configuration'], true);
        if ($roleTrackingConfig && is_array($roleTrackingConfig)) {
          $existingRoleTrackingEnabled = isset($roleTrackingConfig['enabled']) ? (int) $roleTrackingConfig['enabled'] : 0;
          $existingRoleTrackingLogChannel = isset($roleTrackingConfig['log_channel_id']) ? $roleTrackingConfig['log_channel_id'] : "";
          $existingRoleTrackingAdditions = isset($roleTrackingConfig['track_additions']) ? (int) $roleTrackingConfig['track_additions'] : 1;
          $existingRoleTrackingRemovals = isset($roleTrackingConfig['track_removals']) ? (int) $roleTrackingConfig['track_removals'] : 1;
        }
      }
      // Parse server_role_management_configuration JSON
      if (!empty($serverMgmtData['server_role_management_configuration'])) {
        $serverRoleManagementConfig = json_decode($serverMgmtData['server_role_management_configuration'], true);
        if ($serverRoleManagementConfig && is_array($serverRoleManagementConfig)) {
          $existingServerRoleManagementEnabled = isset($serverRoleManagementConfig['enabled']) ? (int) $serverRoleManagementConfig['enabled'] : 0;
          $existingServerRoleManagementLogChannel = isset($serverRoleManagementConfig['log_channel_id']) ? $serverRoleManagementConfig['log_channel_id'] : "";
          $existingRoleCreationTracking = isset($serverRoleManagementConfig['track_creation']) ? (int) $serverRoleManagementConfig['track_creation'] : 1;
          $existingRoleDeletionTracking = isset($serverRoleManagementConfig['track_deletion']) ? (int) $serverRoleManagementConfig['track_deletion'] : 1;
          $existingRoleEditTracking = isset($serverRoleManagementConfig['track_edits']) ? (int) $serverRoleManagementConfig['track_edits'] : 1;
        }
      }
      // Parse message_tracking_configuration JSON
      if (!empty($serverMgmtData['message_tracking_configuration'])) {
        $messageTrackingConfig = json_decode($serverMgmtData['message_tracking_configuration'], true);
        if ($messageTrackingConfig && is_array($messageTrackingConfig)) {
          $existingMessageTrackingEnabled = isset($messageTrackingConfig['enabled']) ? (int) $messageTrackingConfig['enabled'] : 0;
          $existingMessageTrackingLogChannel = isset($messageTrackingConfig['log_channel_id']) ? $messageTrackingConfig['log_channel_id'] : "";
          $existingMessageTrackingEdits = isset($messageTrackingConfig['track_edits']) ? (int) $messageTrackingConfig['track_edits'] : 1;
          $existingMessageTrackingDeletes = isset($messageTrackingConfig['track_deletes']) ? (int) $messageTrackingConfig['track_deletes'] : 1;
        }
      }
      // Parse user_tracking_configuration JSON
      if (!empty($serverMgmtData['user_tracking_configuration'])) {
        $userTrackingConfig = json_decode($serverMgmtData['user_tracking_configuration'], true);
        if ($userTrackingConfig && is_array($userTrackingConfig)) {
          $existingUserTrackingEnabled = isset($userTrackingConfig['enabled']) ? (int) $userTrackingConfig['enabled'] : 0;
          $existingUserTrackingLogChannel = isset($userTrackingConfig['log_channel_id']) ? $userTrackingConfig['log_channel_id'] : "";
          $existingUserJoinTracking = isset($userTrackingConfig['track_joins']) ? (int) $userTrackingConfig['track_joins'] : 1;
          $existingUserLeaveTracking = isset($userTrackingConfig['track_leaves']) ? (int) $userTrackingConfig['track_leaves'] : 1;
          $existingUserNicknameTracking = isset($userTrackingConfig['track_nickname']) ? (int) $userTrackingConfig['track_nickname'] : 1;
          $existingUserUsernameTracking = isset($userTrackingConfig['track_username']) ? (int) $userTrackingConfig['track_username'] : 1;
          $existingUserAvatarTracking = isset($userTrackingConfig['track_avatar']) ? (int) $userTrackingConfig['track_avatar'] : 1;
          $existingUserStatusTracking = isset($userTrackingConfig['track_status']) ? (int) $userTrackingConfig['track_status'] : 1;
        }
      }
      if (!empty($serverMgmtData['role_tracking_configuration_channel'])) {
        $existingRoleLogChannelID = $serverMgmtData['role_tracking_configuration_channel'];
      }
      if (!empty($serverMgmtData['user_tracking_configuration_channel'])) {
        $existingUserLogChannelID = $serverMgmtData['user_tracking_configuration_channel'];
      }
      // Parse reaction_roles_configuration JSON
      if (!empty($serverMgmtData['reaction_roles_configuration'])) {
        $reactionRolesConfig = json_decode($serverMgmtData['reaction_roles_configuration'], true);
        if ($reactionRolesConfig && is_array($reactionRolesConfig)) {
          $existingReactionRolesChannelID = $reactionRolesConfig['channel_id'] ?? "";
          $existingReactionRolesMessage = $reactionRolesConfig['message'] ?? "";
          $existingReactionRolesMappings = $reactionRolesConfig['mappings'] ?? "";
          $existingAllowMultipleReactions = isset($reactionRolesConfig['allow_multiple']) ? (bool) $reactionRolesConfig['allow_multiple'] : false;

          // Debug log for reaction roles configuration
          $reactionRolesDebugData = json_encode([
            'raw_json' => $serverMgmtData['reaction_roles_configuration'],
            'parsed_data' => $reactionRolesConfig,
            'channel_id' => $existingReactionRolesChannelID,
            'has_message' => !empty($existingReactionRolesMessage),
            'has_mappings' => !empty($existingReactionRolesMappings),
            'allow_multiple' => $existingAllowMultipleReactions
          ]);
        } else {
          $consoleLogs[] = "console.error('Failed to parse reaction_roles_configuration JSON for guild $existingGuildId');";
        }
      }
      // Parse rules_configuration JSON
      if (!empty($serverMgmtData['rules_configuration'])) {
        $rulesConfig = json_decode($serverMgmtData['rules_configuration'], true);
        if ($rulesConfig && is_array($rulesConfig)) {
          $existingRulesChannelID = $rulesConfig['channel_id'] ?? "";
          $existingRulesTitle = $rulesConfig['title'] ?? "";
          $existingRulesContent = $rulesConfig['rules'] ?? "";
          $existingRulesColor = $rulesConfig['color'] ?? "#5865f2";
          $existingRulesAcceptRoleID = $rulesConfig['accept_role_id'] ?? "";
        }
      }
      // Parse stream_schedule_configuration JSON
      if (!empty($serverMgmtData['stream_schedule_configuration'])) {
        $streamScheduleConfig = json_decode($serverMgmtData['stream_schedule_configuration'], true);
        if ($streamScheduleConfig && is_array($streamScheduleConfig)) {
          $existingStreamScheduleChannelID = $streamScheduleConfig['channel_id'] ?? "";
          $existingStreamScheduleTitle = $streamScheduleConfig['title'] ?? "";
          $existingStreamScheduleContent = $streamScheduleConfig['schedule'] ?? "";
          $existingStreamScheduleColor = $streamScheduleConfig['color'] ?? "#9146ff";
          $existingStreamScheduleTimezone = $streamScheduleConfig['timezone'] ?? "";
        }
      }
    }
    $serverMgmtStmt->close();
  }
}

// Load FreeStuff (Free Games) settings from Discord bot DB if available
if ($is_linked && $hasGuildId && isset($discord_conn) && !$discord_conn->connect_error) {
  $fsStmt = $discord_conn->prepare("SELECT enabled, channel_id FROM freestuff_settings WHERE guild_id = ?");
  $fsStmt->bind_param("s", $existingGuildId);
  $fsStmt->execute();
  $fsRes = $fsStmt->get_result();
  if ($fsRow = $fsRes->fetch_assoc()) {
    $existingFreestuffEnabled = (int) ($fsRow['enabled'] ?? 0);
    $existingFreestuffChannelID = $fsRow['channel_id'] ?? "";
  }
  $fsStmt->close();
}

// Check if any management features are enabled
$hasEnabledFeatures = array_reduce($serverManagementSettings, function ($carry, $item) {
  return $carry || $item;
}, false);

// Fetch user's administrative guilds if linked
$userAdminGuilds = array();
if ($is_linked && !$needs_relink && !empty($discordData['access_token'])) {
  $userAdminGuilds = getUserAdminGuilds($discordData['access_token']);
  // Debug logging for guild fetching
  $guildDebugData = json_encode([
    'is_linked' => $is_linked,
    'needs_relink' => $needs_relink,
    'has_access_token' => !empty($discordData['access_token']),
    'guild_count' => count($userAdminGuilds),
    'guilds' => array_map(function ($guild) {
      return [
        'id' => $guild['id'] ?? 'no_id',
        'name' => $guild['name'] ?? 'no_name',
        'owner' => $guild['owner'] ?? false,
        'permissions' => $guild['permissions'] ?? 0
      ];
    }, $userAdminGuilds)
  ]);
}

// Fetch guild channels if user has a guild selected and is not using manual IDs
$guildChannels = array();
if ($is_linked && !$needs_relink && !empty($discordData['access_token']) && !$useManualIds && !empty($existingGuildId)) {
  $guildChannels = fetchGuildChannels($discordData['access_token'], $existingGuildId);
  // Debug logging for channel fetching
  $channelDebugData = json_encode([
    'text_channel_count' => is_array($guildChannels) ? count($guildChannels) : 0,
    'text_channels_available' => !empty($guildChannels),
    'use_manual_ids' => $useManualIds
  ]);
}

// Fetch guild roles if user has a guild selected and is not using manual IDs
$guildRoles = array();
if ($is_linked && !$needs_relink && !empty($discordData['access_token']) && !$useManualIds && !empty($existingGuildId)) {
  $guildRoles = fetchGuildRoles($existingGuildId, $discordData['access_token']);
  // Debug logging for role fetching
  $roleDebugData = json_encode([
    'role_count' => is_array($guildRoles) ? count($guildRoles) : 0,
    'roles_available' => !empty($guildRoles),
    'use_manual_ids' => $useManualIds
  ]);
}

// Fetch guild voice channels if user has a guild selected and is not using manual IDs
$guildVoiceChannels = array();
if ($is_linked && !$needs_relink && !empty($discordData['access_token']) && !$useManualIds && !empty($existingGuildId)) {
  $guildVoiceChannels = fetchGuildVoiceChannels($discordData['access_token'], $existingGuildId);
  // Debug logging for voice channel fetching
  $voiceChannelDebugData = json_encode([
    'voice_channel_count' => is_array($guildVoiceChannels) ? count($guildVoiceChannels) : 0,
    'voice_channels_available' => !empty($guildVoiceChannels),
    'use_manual_ids' => $useManualIds
  ]);
}

function updateExistingDiscordValues()
{
  global $conn, $user_id, $discord_conn, $serverManagementSettings, $discordData, $consoleLogs;
  global $existingLiveChannelId, $existingGuildId, $existingOnlineText, $existingOfflineText;
  global $existingStreamAlertChannelID, $existingModerationChannelID, $existingAlertChannelID, $existingTwitchStreamMonitoringID, $existingStreamAlertEveryone, $existingStreamAlertCustomRole, $hasGuildId;
  global $existingWelcomeChannelID, $existingWelcomeMessage, $existingWelcomeUseDefault, $existingWelcomeEmbed, $existingWelcomeColour, $existingAutoRoleID, $existingMessageLogChannelID, $existingRoleLogChannelID, $existingServerMgmtLogChannelID, $existingUserLogChannelID, $existingReactionRolesChannelID, $existingReactionRolesMessage, $existingReactionRolesMappings, $existingAllowMultipleReactions, $existingMessageTrackingEnabled, $existingMessageTrackingLogChannel, $existingMessageTrackingEdits, $existingMessageTrackingDeletes;
  global $existingRulesChannelID, $existingRulesTitle, $existingRulesContent, $existingRulesColor, $existingRulesAcceptRoleID;
  global $existingStreamScheduleChannelID, $existingStreamScheduleTitle, $existingStreamScheduleContent, $existingStreamScheduleColor, $existingStreamScheduleTimezone;
  global $existingRoleHistoryEnabled, $existingRoleHistoryRetention;
  global $existingRoleTrackingEnabled, $existingRoleTrackingLogChannel, $existingRoleTrackingAdditions, $existingRoleTrackingRemovals;
  global $existingServerRoleManagementEnabled, $existingServerRoleManagementLogChannel, $existingRoleCreationTracking, $existingRoleDeletionTracking;
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
  $existingStreamAlertEveryone = $discordData['stream_alert_everyone'] ?? false;
  $existingStreamAlertCustomRole = $discordData['stream_alert_custom_role'] ?? "";
  // Initialize server management log channel IDs as empty (will be loaded from server_management table)
  $existingWelcomeChannelID = "";
  $existingWelcomeMessage = "";
  $existingWelcomeUseDefault = false;
  $existingWelcomeEmbed = false;
  $existingWelcomeColour = "";
  $existingAutoRoleID = "";
  $existingMessageLogChannelID = "";
  $existingMessageTrackingEnabled = 0;
  $existingMessageTrackingLogChannel = "";
  $existingMessageTrackingEdits = 1;
  $existingMessageTrackingDeletes = 1;
  $existingServerMgmtLogChannelID = "";
  $existingUserLogChannelID = "";
  $existingReactionRolesChannelID = "";
  $existingReactionRolesMessage = "";
  $existingReactionRolesMappings = "";
  $existingAllowMultipleReactions = false;
  $existingRulesChannelID = "";
  $existingRulesTitle = "";
  $existingRulesContent = "";
  $existingRulesColor = "";
  $existingRulesAcceptRoleID = "";
  $existingStreamScheduleChannelID = "";
  $existingStreamScheduleTitle = "";
  $existingStreamScheduleContent = "";
  $existingStreamScheduleColor = "";
  $existingStreamScheduleTimezone = "";
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
    if (!$discord_conn->connect_error) {
      $serverMgmtStmt = $discord_conn->prepare("SELECT * FROM server_management WHERE server_id = ?");
      $serverMgmtStmt->bind_param("s", $existingGuildId);
      $serverMgmtStmt->execute();
      $serverMgmtResult = $serverMgmtStmt->get_result();
      if ($serverMgmtData = $serverMgmtResult->fetch_assoc()) {
        $serverManagementSettings = [
          'welcomeMessage' => (bool) $serverMgmtData['welcomeMessage'],
          'autoRole' => (bool) $serverMgmtData['autoRole'],
          'roleHistory' => (bool) $serverMgmtData['roleHistory'],
          'messageTracking' => (bool) $serverMgmtData['messageTracking'],
          'roleTracking' => (bool) $serverMgmtData['roleTracking'],
          'serverRoleManagement' => (bool) $serverMgmtData['serverRoleManagement'],
          'userTracking' => (bool) $serverMgmtData['userTracking'],
          'reactionRoles' => (bool) $serverMgmtData['reactionRoles'],
          'rulesConfiguration' => (bool) $serverMgmtData['rulesConfiguration'],
          'streamSchedule' => (bool) $serverMgmtData['streamSchedule'],
          'embedBuilder' => (bool) $serverMgmtData['embedBuilder'],
          'freeGames' => (bool) ($serverMgmtData['freeGames'] ?? 0)
        ];
        // Override channel IDs with values from server_management table if they exist
        if (!empty($serverMgmtData['welcome_message_configuration_channel'])) {
          $existingWelcomeChannelID = $serverMgmtData['welcome_message_configuration_channel'];
        }
        $existingWelcomeMessage = $serverMgmtData['welcome_message_configuration_message'] ?? "";
        $existingWelcomeUseDefault = (int) ($serverMgmtData['welcome_message_configuration_default'] ?? 1) === 1;
        $existingWelcomeEmbed = (int) ($serverMgmtData['welcome_message_configuration_embed'] ?? 0) === 1;
        $existingWelcomeColour = $serverMgmtData['welcome_message_configuration_colour'] ?? "#00d1b2";
        if (!empty($serverMgmtData['auto_role_assignment_configuration_role_id'])) {
          $existingAutoRoleID = $serverMgmtData['auto_role_assignment_configuration_role_id'];
        }
        // Parse role_history_configuration JSON
        if (!empty($serverMgmtData['role_history_configuration'])) {
          $roleHistoryConfig = json_decode($serverMgmtData['role_history_configuration'], true);
          if ($roleHistoryConfig && is_array($roleHistoryConfig)) {
            $existingRoleHistoryEnabled = isset($roleHistoryConfig['enabled']) ? (int) $roleHistoryConfig['enabled'] : 0;
            $existingRoleHistoryRetention = isset($roleHistoryConfig['retention_days']) ? (int) $roleHistoryConfig['retention_days'] : 30;
          }
        }
        // Parse role_tracking_configuration JSON
        if (!empty($serverMgmtData['role_tracking_configuration'])) {
          $roleTrackingConfig = json_decode($serverMgmtData['role_tracking_configuration'], true);
          if ($roleTrackingConfig && is_array($roleTrackingConfig)) {
            $existingRoleTrackingEnabled = isset($roleTrackingConfig['enabled']) ? (int) $roleTrackingConfig['enabled'] : 0;
            $existingRoleTrackingLogChannel = isset($roleTrackingConfig['log_channel_id']) ? $roleTrackingConfig['log_channel_id'] : "";
            $existingRoleTrackingAdditions = isset($roleTrackingConfig['track_additions']) ? (int) $roleTrackingConfig['track_additions'] : 1;
            $existingRoleTrackingRemovals = isset($roleTrackingConfig['track_removals']) ? (int) $roleTrackingConfig['track_removals'] : 1;
          }
        }
        // Parse server_role_management_configuration JSON
        if (!empty($serverMgmtData['server_role_management_configuration'])) {
          $serverRoleManagementConfig = json_decode($serverMgmtData['server_role_management_configuration'], true);
          if ($serverRoleManagementConfig && is_array($serverRoleManagementConfig)) {
            $existingServerRoleManagementEnabled = isset($serverRoleManagementConfig['enabled']) ? (int) $serverRoleManagementConfig['enabled'] : 0;
            $existingServerRoleManagementLogChannel = isset($serverRoleManagementConfig['log_channel_id']) ? $serverRoleManagementConfig['log_channel_id'] : "";
            $existingRoleCreationTracking = isset($serverRoleManagementConfig['track_creation']) ? (int) $serverRoleManagementConfig['track_creation'] : 1;
            $existingRoleDeletionTracking = isset($serverRoleManagementConfig['track_deletion']) ? (int) $serverRoleManagementConfig['track_deletion'] : 1;
          }
        }
        if (!empty($serverMgmtData['message_tracking_configuration'])) {
          $messageTrackingConfig = json_decode($serverMgmtData['message_tracking_configuration'], true);
          $existingMessageTrackingEnabled = isset($messageTrackingConfig['enabled']) ? (int) $messageTrackingConfig['enabled'] : 0;
          $existingMessageTrackingLogChannel = $messageTrackingConfig['log_channel_id'] ?? "";
          $existingMessageTrackingEdits = isset($messageTrackingConfig['track_edits']) ? (int) $messageTrackingConfig['track_edits'] : 1;
          $existingMessageTrackingDeletes = isset($messageTrackingConfig['track_deletes']) ? (int) $messageTrackingConfig['track_deletes'] : 1;
        }
        if (!empty($serverMgmtData['role_tracking_configuration_channel'])) {
          $existingRoleLogChannelID = $serverMgmtData['role_tracking_configuration_channel'];
        }
        if (!empty($serverMgmtData['server_role_management_configuration_channel'])) {
          $existingServerMgmtLogChannelID = $serverMgmtData['server_role_management_configuration_channel'];
        }
        if (!empty($serverMgmtData['user_tracking_configuration_channel'])) {
          $existingUserLogChannelID = $serverMgmtData['user_tracking_configuration_channel'];
        }
        // Parse reaction_roles_configuration JSON
        if (!empty($serverMgmtData['reaction_roles_configuration'])) {
          $reactionRolesConfig = json_decode($serverMgmtData['reaction_roles_configuration'], true);
          if ($reactionRolesConfig && is_array($reactionRolesConfig)) {
            $existingReactionRolesChannelID = $reactionRolesConfig['channel_id'] ?? "";
            $existingReactionRolesMessage = $reactionRolesConfig['message'] ?? "";
            $existingReactionRolesMappings = $reactionRolesConfig['mappings'] ?? "";
            $existingAllowMultipleReactions = isset($reactionRolesConfig['allow_multiple']) ? (bool) $reactionRolesConfig['allow_multiple'] : false;

            // Debug log for reaction roles configuration
            $reactionRolesDebugData = json_encode([
              'raw_json' => $serverMgmtData['reaction_roles_configuration'],
              'parsed_data' => $reactionRolesConfig,
              'channel_id' => $existingReactionRolesChannelID,
              'has_message' => !empty($existingReactionRolesMessage),
              'has_mappings' => !empty($existingReactionRolesMappings),
              'allow_multiple' => $existingAllowMultipleReactions
            ]);
          } else {
            $consoleLogs[] = "console.error('Failed to parse reaction_roles_configuration JSON (refresh) for guild $existingGuildId');";
          }
        }
        // Parse rules_configuration JSON
        if (!empty($serverMgmtData['rules_configuration'])) {
          $rulesConfig = json_decode($serverMgmtData['rules_configuration'], true);
          if ($rulesConfig && is_array($rulesConfig)) {
            $existingRulesChannelID = $rulesConfig['channel_id'] ?? "";
            $existingRulesTitle = $rulesConfig['title'] ?? "";
            $existingRulesContent = $rulesConfig['rules'] ?? "";
            $existingRulesColor = $rulesConfig['color'] ?? "#5865f2";
            $existingRulesAcceptRoleID = $rulesConfig['accept_role_id'] ?? "";
          }
        }
        // Parse stream_schedule_configuration JSON
        if (!empty($serverMgmtData['stream_schedule_configuration'])) {
          $streamScheduleConfig = json_decode($serverMgmtData['stream_schedule_configuration'], true);
          if ($streamScheduleConfig && is_array($streamScheduleConfig)) {
            $existingStreamScheduleChannelID = $streamScheduleConfig['channel_id'] ?? "";
            $existingStreamScheduleTitle = $streamScheduleConfig['title'] ?? "";
            $existingStreamScheduleContent = $streamScheduleConfig['schedule'] ?? "";
            $existingStreamScheduleColor = $streamScheduleConfig['color'] ?? "#9146ff";
            $existingStreamScheduleTimezone = $streamScheduleConfig['timezone'] ?? "";
          }
        }
      }
      $serverMgmtStmt->close();
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
  // Store a lightweight flag for StreamersConnect flow
  $_SESSION['discord_sc_auth_state'] = $state;
  // Determine OAuth scopes based on user status
  if (!$is_linked) {
    // New user: Include 'bot' scope to add bot to their server
    $oauth_scopes = 'identify guilds guilds.members.read connections bot';
  } else {
    // Existing user relinking: Exclude 'bot' scope since bot should already be in server
    $oauth_scopes = 'identify guilds guilds.members.read connections';
  }
  // Build StreamersConnect authorization URL
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
  $originDomain = $_SERVER['HTTP_HOST'];
  $returnUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
  $streamersconnectBase = 'https://streamersconnect.com/';
  $authURL = $streamersconnectBase . '?' . http_build_query([
    'service' => 'discord',
    'login' => $originDomain,
    'scopes' => $oauth_scopes,
    'return_url' => $returnUrl
  ]);
}

// Helper function to revoke Discord access or refresh token
function revokeDiscordToken($token, $client_id, $client_secret, $token_type_hint = 'access_token')
{
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
function fetchUserGuilds($access_token)
{
  global $consoleLogs;
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
      $consoleLogs[] = "console.error('Discord API Error - fetchUserGuilds: Invalid JSON response');";
    }
  } else {
    $consoleLogs[] = "console.error('Discord API Error - fetchUserGuilds: Failed to fetch guilds');";
  }
  return false;
}
// Helper function to check if user is admin/owner of a specific guild
function checkGuildPermissions($access_token, $guild_id)
{
  global $consoleLogs;
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
      $consoleLogs[] = "console.error('Discord API Error - checkGuildPermissions: Invalid JSON response for guild $guild_id');";
    }
  } else {
    $consoleLogs[] = "console.error('Discord API Error - checkGuildPermissions: Failed to fetch member data for guild $guild_id');";
  }
  return false;
}
// Helper function to get user's owned guilds
function getUserAdminGuilds($access_token)
{
  global $consoleLogs;
  $guilds = fetchUserGuilds($access_token);
  $admin_guilds = array();
  if ($guilds && is_array($guilds)) {
    foreach ($guilds as $guild) {
      // Check if user is owner only
      $is_owner = isset($guild['owner']) && $guild['owner'] === true;
      if ($is_owner) {
        $admin_guilds[] = $guild;
      }
    }
  } else {
    $consoleLogs[] = "console.error('Discord API Error - getUserAdminGuilds: No guilds returned or invalid format');";
  }
  return $admin_guilds;
}

// Helper function to fetch channels from a Discord guild
function fetchGuildChannels($access_token, $guild_id)
{
  // Load Discord bot token for guild API calls
  require_once '../config/discord.php';
  global $bot_token, $consoleLogs;
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
      // Filter for text channels (type 0) and announcement channels (type 5) and sort by position
      $text_channels = array_filter($channels, function ($channel) {
        $type = $channel['type'] ?? -1;
        return $type === 0 || $type === 5; // 0 = GUILD_TEXT, 5 = GUILD_NEWS (Announcement channels)
      });
      // Sort channels by position
      usort($text_channels, function ($a, $b) {
        return ($a['position'] ?? 0) - ($b['position'] ?? 0);
      });
      return $text_channels;
    } else {
      $consoleLogs[] = "console.error('Discord API Error - fetchGuildChannels: Invalid JSON response for guild $guild_id');";
    }
  } else {
    // Get more detailed error information
    $error_info = error_get_last();
    $consoleLogs[] = "console.error('Discord API Error - fetchGuildChannels: Failed to fetch channels for guild $guild_id. Error: " . addslashes($error_info['message'] ?? 'Unknown error') . "');";
  }
  return false;
}

// Helper function to fetch voice channels from a Discord guild
function fetchGuildVoiceChannels($access_token, $guild_id)
{
  // Load Discord bot token for guild API calls
  require_once '../config/discord.php';
  global $bot_token, $consoleLogs;
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
      $voice_channels = array_filter($channels, function ($channel) {
        return ($channel['type'] ?? -1) === 2; // 2 = GUILD_VOICE
      });
      // Sort channels by position
      usort($voice_channels, function ($a, $b) {
        return ($a['position'] ?? 0) - ($b['position'] ?? 0);
      });
      return $voice_channels;
    } else {
      $consoleLogs[] = "console.error('Discord API Error - fetchGuildVoiceChannels: Invalid JSON response for guild $guild_id');";
    }
  } else {
    // Get more detailed error information
    $error_info = error_get_last();
    $consoleLogs[] = "console.error('Discord API Error - fetchGuildVoiceChannels: Failed to fetch voice channels for guild $guild_id. Error: " . addslashes($error_info['message'] ?? 'Unknown error') . "');";
  }
  return false;
}

// Helper function to fetch roles from a Discord guild
function fetchGuildRoles($guild_id, $access_token)
{
  // Load Discord bot token for guild API calls
  require_once '../config/discord.php';
  global $bot_token, $consoleLogs;
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
      $assignable_roles = array_filter($roles, function ($role) {
        return ($role['name'] !== '@everyone') &&
          !($role['managed'] ?? false) && // Exclude bot/integration managed roles
          !($role['tags'] ?? false);      // Exclude roles with special tags
      });
      // Sort roles by position (highest position first, which is how Discord shows them)
      usort($assignable_roles, function ($a, $b) {
        return ($b['position'] ?? 0) - ($a['position'] ?? 0);
      });
      return $assignable_roles;
    } else {
      $consoleLogs[] = "console.error('Discord API Error - fetchGuildRoles: Invalid JSON response for guild $guild_id');";
    }
  } else {
    // Get more detailed error information
    $error_info = error_get_last();
    $consoleLogs[] = "console.error('Discord API Error - fetchGuildRoles: Failed to fetch roles for guild $guild_id. Error: " . addslashes($error_info['message'] ?? 'Unknown error') . "');";
  }
  return false;
}

// Helper function to generate channel input field or dropdown
function generateChannelInput($fieldId, $fieldName, $currentValue, $placeholder, $useManualIds, $guildChannels, $icon = 'fas fa-hashtag', $required = false)
{
  $requiredAttr = $required ? ' required' : '';
  if ($useManualIds || empty($guildChannels)) {
    // Show manual input field with enhanced placeholder for manual mode
    $manualPlaceholder = $useManualIds ? "Text Channel ID (Right-click channel → Copy Channel ID)" : $placeholder;
    $emptyPlaceholder = empty($currentValue) ? " placeholder=\"$manualPlaceholder\"" : '';
    return "
      <div class=\"control has-icons-left\">
        <input class=\"input\" type=\"text\" id=\"$fieldId\" name=\"$fieldName\" value=\"" . htmlspecialchars($currentValue) . "\"$emptyPlaceholder$requiredAttr style=\"background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;\">
        <span class=\"icon is-small is-left has-text-grey-light\"><i class=\"$icon\"></i></span>
      </div>";
  } else {
    // Show dropdown with channels
    $options = "<option value=\"\"" . (empty($currentValue) ? ' selected' : '') . ">Select a channel...</option>\n";
    foreach ($guildChannels as $channel) {
      $channelId = htmlspecialchars($channel['id']);
      $channelName = htmlspecialchars($channel['name']);
      $selected = ($currentValue === $channel['id']) ? ' selected' : '';
      $channelType = $channel['type'] ?? 0;
      $prefix = $channelType === 5 ? '📢 ' : ''; // Announcement channels get a megaphone emoji, regular channels have no prefix
      $options .= "<option value=\"$channelId\"$selected>$prefix$channelName</option>\n";
    }
    return "
      <div class=\"control has-icons-left\">
        <div class=\"select is-fullwidth\" style=\"width: 100%;\">
          <select id=\"$fieldId\" name=\"$fieldName\"$requiredAttr style=\"background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px; width: 100%;\">$options</select>
        </div>
        <span class=\"icon is-small is-left has-text-grey-light\"><i class=\"$icon\"></i></span>
      </div>";
  }
}

// Helper function to generate role input field or dropdown
function generateRoleInput($fieldId, $fieldName, $currentValue, $placeholder, $useManualIds, $guildRoles, $icon = 'fas fa-user-tag', $required = false)
{
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
function generateVoiceChannelInput($fieldId, $fieldName, $currentValue, $placeholder, $useManualIds, $guildVoiceChannels, $icon = 'fas fa-volume-up', $required = false)
{
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

// Helper function to get channel name from ID
function getChannelNameFromId($channel_id, $channels_array)
{
  if (empty($channel_id) || !is_array($channels_array)) {
    return $channel_id; // Return ID if not found
  }
  foreach ($channels_array as $channel) {
    if ($channel['id'] == $channel_id) {
      return '#' . $channel['name'];
    }
  }
  return $channel_id; // Return ID if channel not found in array
}

// Start output buffering for layout
ob_start();
?>
<div class="columns is-centered">
  <div class="column is-fullwidth">
    <!-- Modern Discord Integration Hero Section -->
    <div class="hero is-primary"
      style="background: linear-gradient(135deg, #5865f2 0%, #7289da 100%); border-radius: 16px; overflow: hidden; margin-bottom: 2rem;">
      <div class="hero-body" style="padding: 2rem;">
        <div class="container">
          <!-- Desktop layout: single row with status on right -->
          <div class="is-hidden-mobile">
            <div class="columns is-vcentered">
              <div class="column">
                <div class="media">
                  <div class="media-left">
                    <figure class="image is-64x64"
                      style="background: rgba(255,255,255,0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                      <i class="fab fa-discord" style="font-size: 2rem; color: white;"></i>
                    </figure>
                  </div>
                  <div class="media-content">
                    <p class="title is-3-desktop is-4-tablet has-text-white"
                      style="margin-bottom: 0.5rem; font-weight: 700; word-wrap: break-word; line-height: 1.2;">
                      Discord Integration
                    </p>
                    <p class="subtitle is-5-desktop is-6-tablet has-text-white"
                      style="opacity: 0.9; word-wrap: break-word; line-height: 1.3; margin-bottom: 0;">
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
                    <figure class="image is-48x48-mobile"
                      style="background: rgba(255,255,255,0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                      <i class="fab fa-discord" style="font-size: 1.5rem; color: white;"></i>
                    </figure>
                  </div>
                  <div class="media-content">
                    <p class="title is-5-mobile has-text-white"
                      style="margin-bottom: 0.5rem; font-weight: 700; word-wrap: break-word; line-height: 1.2;">
                      Discord Integration
                    </p>
                    <p class="subtitle is-7-mobile has-text-white"
                      style="opacity: 0.9; word-wrap: break-word; line-height: 1.3; margin-bottom: 0;">
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
                      <p class="is-size-7-mobile has-text-white"
                        style="opacity: 0.8; word-wrap: break-word; line-height: 1.3;">
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
      <div
        class="notification <?php echo $linkingMessageType === 'is-success' ? 'is-success' : ($linkingMessageType === 'is-danger' ? 'is-danger' : 'is-warning'); ?>"
        style="border-radius: 12px; margin-bottom: 2rem; border: none; box-shadow: 0 4px 16px rgba(0,0,0,0.1);">
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
            <div class="card has-background-dark"
              style="border-radius: 16px; border: 2px solid #ff9800; background: linear-gradient(135deg, #2a2a2a 0%, #3a3a3a 100%) !important;">
              <div class="card-content has-text-centered" style="padding: 3rem 2rem;">
                <div class="mb-4">
                  <span class="icon is-large has-text-warning" style="font-size: 4rem;">
                    <i class="fas fa-sync-alt"></i>
                  </span>
                </div>
                <h3 class="title is-3 has-text-white mb-3">
                  <?php echo (isset($discordData['reauth']) && $discordData['reauth'] == 1) ? 'New Permissions Required' : 'Reconnection Required'; ?>
                </h3>
                <p class="subtitle is-5 has-text-grey-light mb-5"
                  style="max-width: 600px; margin: 0 auto; line-height: 1.6;">
                  <?php if (isset($discordData['reauth']) && $discordData['reauth'] == 1): ?>
                    We've added new features that require additional Discord permissions. Please re-authorize your account to
                    access guild management features and server selection.
                  <?php else: ?>
                    Your Discord account was linked using our previous system. To access all the latest features and improved
                    security, please reconnect your account with our updated integration.
                  <?php endif; ?>
                </p>
                <button class="button is-warning is-large" onclick="linkDiscord()"
                  style="border-radius: 50px; font-weight: 600; padding: 1rem 2rem; box-shadow: 0 4px 16px rgba(255,152,0,0.3);">
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
            <div class="card has-background-dark"
              style="border-radius: 16px; border: 2px solid #5865f2; background: linear-gradient(135deg, #2a2a2a 0%, #363636 100%) !important;">
              <div class="card-content has-text-centered" style="padding: 3rem 2rem;">
                <div class="mb-4">
                  <span class="icon is-large has-text-primary" style="font-size: 4rem;">
                    <i class="fab fa-discord"></i>
                  </span>
                </div>
                <h3 class="title is-3 has-text-white mb-3"><?php echo t('discordbot_link_title'); ?></h3>
                <p class="subtitle is-5 has-text-grey-light mb-5"
                  style="max-width: 500px; margin: 0 auto; line-height: 1.6;">
                  <?php echo t('discordbot_link_desc'); ?>
                </p>
                <button class="button is-primary is-large" onclick="linkDiscord()"
                  style="border-radius: 50px; font-weight: 600; padding: 1rem 2rem; box-shadow: 0 4px 16px rgba(88,101,242,0.3);">
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
          <div class="card has-background-dark"
            style="border-radius: 16px; border: 2px solid #00d1b2; background: linear-gradient(135deg, #2a2a2a 0%, #363636 100%) !important;">
            <div class="card-content discord-card-content" style="padding: 2rem;">
              <!-- Desktop layout: single row with buttons on right -->
              <div class="is-hidden-mobile">
                <div class="level" style="flex-wrap: wrap !important; overflow: visible !important;">
                  <div class="level-left"
                    style="flex: 1 !important; min-width: 0 !important; max-width: calc(100% - 200px) !important; overflow: visible !important;">
                    <div class="level-item"
                      style="flex: 1 !important; min-width: 0 !important; overflow: visible !important;">
                      <div class="media"
                        style="overflow: visible !important; min-width: 0 !important; flex: 1 !important;">
                        <div class="media-left">
                          <span class="icon is-large has-text-success" style="font-size: 3rem;">
                            <i class="fas fa-check-circle"></i>
                          </span>
                        </div>
                        <div class="media-content"
                          style="overflow: visible !important; word-wrap: break-word !important; overflow-wrap: break-word !important; word-break: break-word !important; max-width: 100% !important; min-width: 0 !important; flex: 1 !important;">
                          <h4 class="title is-4 has-text-white mb-2"
                            style="word-wrap: break-word !important; overflow-wrap: break-word !important; word-break: break-word !important; white-space: normal !important; max-width: 100% !important;">
                            <?php echo t('discordbot_linked_title'); ?>
                          </h4>
                          <p class="subtitle is-6 has-text-grey-light mb-0"
                            style="word-wrap: break-word !important; overflow-wrap: break-word !important; word-break: break-word !important; white-space: normal !important; max-width: 100% !important;">
                            <?php echo t('discordbot_linked_desc'); ?>
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="level-right">
                    <div class="level-item">
                      <div class="buttons">
                        <button class="button is-primary" onclick="inviteBot()"
                          style="border-radius: 25px; font-weight: 600;">
                          <span class="icon"><i class="fas fa-plus-circle"></i></span>
                          <span>Invite Bot</span>
                        </button>
                        <button class="button is-danger" onclick="disconnectDiscord()"
                          style="border-radius: 25px; font-weight: 600;">
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
                    <div class="media-content"
                      style="overflow: visible !important; word-wrap: break-word !important; overflow-wrap: break-word !important; word-break: break-word !important; max-width: 100% !important; min-width: 0 !important; flex: 1 !important;">
                      <h4 class="title is-5-mobile has-text-white mb-2"
                        style="word-wrap: break-word !important; overflow-wrap: break-word !important; word-break: break-word !important; white-space: normal !important; max-width: 100% !important;">
                        <?php echo t('discordbot_linked_title'); ?>
                      </h4>
                      <p class="subtitle is-6-mobile has-text-grey-light mb-0"
                        style="word-wrap: break-word !important; overflow-wrap: break-word !important; word-break: break-word !important; white-space: normal !important; max-width: 100% !important;">
                        <?php echo t('discordbot_linked_desc'); ?>
                      </p>
                    </div>
                  </div>
                </div>
                <!-- Buttons section - stacked on mobile -->
                <div class="block">
                  <div class="buttons is-centered">
                    <button class="button is-primary is-fullwidth-mobile" onclick="inviteBot()"
                      style="border-radius: 25px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-plus-circle"></i></span>
                      <span>Invite Bot</span>
                    </button>
                    <button class="button is-danger is-fullwidth-mobile" onclick="disconnectDiscord()"
                      style="border-radius: 25px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-unlink"></i></span>
                      <span>Disconnect</span>
                    </button>
                  </div>
                </div>
              </div>
              <?php if ($expires_str): ?>
                <div class="notification has-background-grey-darker"
                  style="border-radius: 12px; margin-top: 1rem; border: 1px solid #3273dc;">
                  <div class="columns is-mobile is-vcentered">
                    <div class="column is-narrow">
                      <span class="icon has-text-info"><i class="fas fa-clock"></i></span>
                      <strong class="has-text-white" style="margin-left: 0.5rem;">Token Status:</strong>
                    </div>
                    <div class="column">
                      <span class="has-text-grey-light" style="word-wrap: break-word;">Valid for
                        <?php echo htmlspecialchars($expires_str); ?></span>
                    </div>
                  </div>
                </div>
              <?php endif; ?>
              <!-- Guild ID Configuration - Independent Form -->
              <div class="card has-background-grey-darker mb-4"
                style="border-radius: 12px; border: 1px solid #363636; margin-top: 1rem;">
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
                    <strong>Required for All Discord Bot Features:</strong> Please select your Discord Server to enable
                    all Discord Bot features including Server Management and Event Channels.
                  </div>
                  <form action="" method="post">
                    <div class="field">
                      <label class="label has-text-white" for="guild_id_config" style="font-weight: 500;">Discord
                        Server</label>
                      <div class="control has-icons-left">
                        <?php if ($useManualIds): ?>
                          <!-- Manual ID Input Mode -->
                          <input class="input" type="text" id="guild_id_config" name="guild_id"
                            value="<?php echo htmlspecialchars($existingGuildId); ?>" <?php if (empty($existingGuildId)) {
                                 echo ' placeholder="e.g. 123456789123456789"';
                               } ?>
                            style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                          <span class="icon is-small is-left has-text-grey-light"><i class="fab fa-discord"></i></span>
                          <p class="help has-text-grey-light">Manual ID mode enabled. Right-click your Discord server name →
                            Copy Server ID (Developer Mode required)</p>
                        <?php elseif (!empty($userAdminGuilds) && is_array($userAdminGuilds)): ?>
                          <!-- Dropdown Mode -->
                          <div class="select is-fullwidth" style="width: 100%;">
                            <select id="guild_id_config" name="guild_id"
                              style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px; width: 100%;">
                              <option value="" <?php echo empty($existingGuildId) ? 'selected' : ''; ?>>Select a Discord
                                Server...</option>
                              <?php foreach ($userAdminGuilds as $guild): ?>
                                <?php
                                $isSelected = ($existingGuildId === $guild['id']) ? 'selected' : '';
                                $guildName = htmlspecialchars($guild['name']);
                                $ownerBadge = (isset($guild['owner']) && $guild['owner']) ? '' : '';
                                ?>
                                <option value="<?php echo htmlspecialchars($guild['id']); ?>" <?php echo $isSelected; ?>>
                                  <?php echo $guildName . $ownerBadge; ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <span class="icon is-small is-left has-text-grey-light"><i class="fab fa-discord"></i></span>
                        <?php else: ?>
                          <!-- Fallback/Loading Mode -->
                          <input class="input" type="text" id="guild_id_config" name="guild_id"
                            value="<?php echo htmlspecialchars($existingGuildId); ?>" placeholder="Loading servers..."
                            disabled
                            style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                          <span class="icon is-small is-left has-text-grey-light"><i class="fab fa-discord"></i></span>
                          <p class="help has-text-warning">
                            <?php if (!$is_linked || $needs_relink): ?>
                              Please link your Discord account to view available servers.
                            <?php else: ?>
                              No servers found where you have Owner permissions, or servers are still loading.
                            <?php endif; ?>
                          </p>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="field">
                      <div class="control">
                        <button class="button is-primary is-fullwidth" type="submit"
                          style="border-radius: 6px; font-weight: 600;" <?php echo (!$is_linked || $needs_relink || (!$useManualIds && empty($userAdminGuilds))) ? ' disabled' : ''; ?>>
                          <span class="icon"><i class="fas fa-save"></i></span>
                          <span>Save Server Configuration</span>
                        </button>
                      </div>
                      <?php if (!$is_linked || $needs_relink): ?>
                        <p class="help has-text-warning has-text-centered mt-2">Account not linked or needs relinking</p>
                      <?php elseif (!$useManualIds && empty($userAdminGuilds)): ?>
                        <p class="help has-text-warning has-text-centered mt-2">No servers available with admin permissions
                        </p>
                      <?php endif; ?>
                    </div>
                  </form>
                </div>
              </div>
              <!-- Channel Input Mode Notification (moved here from Twitch Online Alert) -->
              <?php if ($useManualIds): ?>
                <div class="notification is-info is-light"
                  style="border-radius: 8px; margin-top: 0.75rem; margin-bottom: 1rem;">
                  <span class="icon"><i class="fas fa-keyboard"></i></span>
                  <strong>Manual Mode:</strong> Paste channel IDs here (one ID per field).
                </div>
              <?php elseif (!empty($guildChannels)): ?>
                <div class="notification is-success is-light"
                  style="border-radius: 8px; margin-top: 0.75rem; margin-bottom: 1rem;">
                  <span class="icon"><i class="fas fa-list"></i></span>
                  <strong>Pick From Server:</strong> Choose channels from the dropdowns below.
                </div>
              <?php elseif (!empty($existingGuildId)): ?>
                <div class="notification is-warning is-light"
                  style="border-radius: 8px; margin-top: 0.75rem; margin-bottom: 1rem;">
                  <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                  <strong>Can't load channels:</strong> Reconnect Discord or check the bot's server permissions.
                </div>
              <?php else: ?>
                <div class="notification is-warning is-light"
                  style="border-radius: 8px; margin-top: 0.75rem; margin-bottom: 1rem;">
                  <span class="icon"><i class="fas fa-server"></i></span>
                  <strong>No server selected:</strong> Pick a Discord server above to enable channel dropdowns.
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <!-- Success/Error Messages -->
        <?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
          <?php if ($buildStatus): ?>
            <div class="column is-12">
              <div class="notification has-background-dark"
                style="border-radius: 12px; border: 2px solid #48c774; box-shadow: 0 4px 16px rgba(72,199,116,0.2);">
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
              <div class="notification has-background-dark"
                style="border-radius: 12px; border: 2px solid #ff4e65; box-shadow: 0 4px 16px rgba(255,78,101,0.2);">
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
    <!-- Discord Server Management Card -->
    <div class="card has-background-grey-darker mb-5"
      style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
      <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
        <p class="card-header-title has-text-white" style="font-weight: 600;">
          <span class="icon mr-2 has-text-primary"><i class="fab fa-discord"></i></span>
          Discord Server Management
        </p>
        <div class="card-header-icon" style="cursor: default;">
          <span class="tag is-success is-light">
            <!--<span class="icon"><i class="fas fa-flask"></i></span>
            <span>PARTIAL COMPLETED</span>-->
            <span class="icon"><i class="fas fa-check-circle"></i></span>
            <span>COMPLETED</span>
          </span>
        </div>
      </header>
      <div class="card-content" style="flex-grow: 1; display: flex; flex-direction: column;">
        <?php if (!$is_linked || $needs_relink): ?>
          <div class="notification is-warning is-light" style="border-radius: 8px; margin-bottom: 1rem;">
            <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
            <strong>Account Not Linked:</strong> Please link your Discord account to access server management features.
          </div>
        <?php elseif (!$hasGuildId): ?>
          <div class="notification is-warning is-light" style="border-radius: 8px; margin-bottom: 1rem;">
            <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
            <strong>Guild ID Required:</strong> Please configure your Discord Server ID above to enable server management
            features.
          </div>
        <?php else: ?>
          <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
            <span class="icon"><i class="fas fa-info-circle"></i></span>
            <strong>Control your Discord server with welcome messages, automatic roles, message tracking, and
              more.</strong>
          </div>
        <?php endif; ?>
        <form action="" method="post" style="flex-grow: 1; display: flex; flex-direction: column;">
          <div class="field">
            <label class="label has-text-white" style="font-weight: 500;">Server Management Features</label>
            <div class="server-management-toggles" style="margin-bottom: 0.75rem;">
              <div class="toggle-item box">
                <label for="welcomeMessage" class="toggle-title has-text-white">Welcome Message</label>
                <div class="control" style="margin-top:0.5rem;">
                  <label class="switch is-rounded">
                    <input class="switch is-rounded" id="welcomeMessage" type="checkbox" name="welcomeMessage" <?php echo (!empty($serverManagementSettings['welcomeMessage']) ? ' checked' : ''); ?><?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                    <span class="check"></span>
                  </label>
                  <div class="toggle-status has-text-grey-light" data-for="welcomeMessage">Disabled</div>
                </div>
              </div>
              <div class="toggle-item box">
                <label for="autoRole" class="toggle-title has-text-white">Auto Role on Join</label>
                <div class="control" style="margin-top:0.5rem;">
                  <label class="switch is-rounded">
                    <input class="switch is-rounded" id="autoRole" type="checkbox" name="autoRole" <?php echo (!empty($serverManagementSettings['autoRole']) ? ' checked' : ''); ?><?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                    <span class="check"></span>
                  </label>
                  <div class="toggle-status has-text-grey-light" data-for="autoRole">Disabled</div>
                </div>
              </div>
              <div class="toggle-item box">
                <label for="roleHistory" class="toggle-title has-text-white">Role History</label>
                <div class="control" style="margin-top:0.5rem;">
                  <label class="switch is-rounded">
                    <input class="switch is-rounded" id="roleHistory" type="checkbox" name="roleHistory" <?php echo (!empty($serverManagementSettings['roleHistory']) ? ' checked' : ''); ?><?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                    <span class="check"></span>
                  </label>
                  <div class="toggle-status has-text-grey-light" data-for="roleHistory">Disabled</div>
                </div>
              </div>
              <div class="toggle-item box">
                <label for="messageTracking" class="toggle-title has-text-white">Message Tracking</label>
                <div class="control" style="margin-top:0.5rem;">
                  <label class="switch is-rounded">
                    <input class="switch is-rounded" id="messageTracking" type="checkbox" name="messageTracking" <?php echo (!empty($serverManagementSettings['messageTracking']) ? ' checked' : ''); ?><?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                    <span class="check"></span>
                  </label>
                  <div class="toggle-status has-text-grey-light" data-for="messageTracking">Disabled</div>
                </div>
              </div>
              <div class="toggle-item box">
                <label for="roleTracking" class="toggle-title has-text-white">Role Tracking</label>
                <div class="control" style="margin-top:0.5rem;">
                  <label class="switch is-rounded">
                    <input class="switch is-rounded" id="roleTracking" type="checkbox" name="roleTracking" <?php echo (!empty($serverManagementSettings['roleTracking']) ? ' checked' : ''); ?><?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                    <span class="check"></span>
                  </label>
                  <div class="toggle-status has-text-grey-light" data-for="roleTracking">Disabled</div>
                </div>
              </div>
              <div class="toggle-item box">
                <label for="serverRoleManagement" class="toggle-title has-text-white">Server Role Management</label>
                <div class="control" style="margin-top:0.5rem;">
                  <label class="switch is-rounded">
                    <input class="switch is-rounded" id="serverRoleManagement" type="checkbox"
                      name="serverRoleManagement" <?php echo (!empty($serverManagementSettings['serverRoleManagement']) ? ' checked' : ''); ?><?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                    <span class="check"></span>
                  </label>
                  <div class="toggle-status has-text-grey-light" data-for="serverRoleManagement">Disabled</div>
                </div>
              </div>
              <div class="toggle-item box">
                <label for="userTracking" class="toggle-title has-text-white">User Tracking</label>
                <div class="control" style="margin-top:0.5rem;">
                  <label class="switch is-rounded">
                    <input class="switch is-rounded" id="userTracking" type="checkbox" name="userTracking" <?php echo (!empty($serverManagementSettings['userTracking']) ? ' checked' : ''); ?><?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                    <span class="check"></span>
                  </label>
                  <div class="toggle-status has-text-grey-light" data-for="userTracking">Disabled</div>
                </div>
              </div>
              <div class="toggle-item box">
                <label for="reactionRoles" class="toggle-title has-text-white">Reaction Roles</label>
                <div class="control" style="margin-top:0.5rem;">
                  <label class="switch is-rounded">
                    <input class="switch is-rounded" id="reactionRoles" type="checkbox" name="reactionRoles" <?php echo (!empty($serverManagementSettings['reactionRoles']) ? ' checked' : ''); ?><?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                    <span class="check"></span>
                  </label>
                  <div class="toggle-status has-text-grey-light" data-for="reactionRoles">Disabled</div>
                </div>
              </div>
              <div class="toggle-item box">
                <label for="rulesConfiguration" class="toggle-title has-text-white">Rules Configuration</label>
                <div class="control" style="margin-top:0.5rem;">
                  <label class="switch is-rounded">
                    <input class="switch is-rounded" id="rulesConfiguration" type="checkbox" name="rulesConfiguration"
                      <?php echo (!empty($serverManagementSettings['rulesConfiguration']) ? ' checked' : ''); ?><?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                    <span class="check"></span>
                  </label>
                  <div class="toggle-status has-text-grey-light" data-for="rulesConfiguration">Disabled</div>
                </div>
              </div>
              <div class="toggle-item box">
                <label for="streamSchedule" class="toggle-title has-text-white">Stream Schedule</label>
                <div class="control" style="margin-top:0.5rem;">
                  <label class="switch is-rounded">
                    <input class="switch is-rounded" id="streamSchedule" type="checkbox" name="streamSchedule" <?php echo (!empty($serverManagementSettings['streamSchedule']) ? ' checked' : ''); ?><?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                    <span class="check"></span>
                  </label>
                  <div class="toggle-status has-text-grey-light" data-for="streamSchedule">Disabled</div>
                </div>
              </div>
              <div class="toggle-item box">
                <label for="embedBuilder" class="toggle-title has-text-white">Embed Builder</label>
                <div class="control" style="margin-top:0.5rem;">
                  <label class="switch is-rounded">
                    <input class="switch is-rounded" id="embedBuilder" type="checkbox" name="embedBuilder" <?php echo (!empty($serverManagementSettings['embedBuilder']) ? ' checked' : ''); ?><?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                    <span class="check"></span>
                  </label>
                  <div class="toggle-status has-text-grey-light" data-for="embedBuilder">Disabled</div>
                </div>
              </div>
              <div class="toggle-item box">
                <label for="freeGames" class="toggle-title has-text-white">Free Games</label>
                <div class="control" style="margin-top:0.5rem;">
                  <label class="switch is-rounded">
                    <input class="switch is-rounded" id="freeGames" type="checkbox" name="freeGames" <?php echo (!empty($serverManagementSettings['freeGames']) ? ' checked' : ''); ?><?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                    <span class="check"></span>
                  </label>
                  <div class="toggle-status has-text-grey-light" data-for="freeGames">Disabled</div>
                </div>
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>
    <!-- Discord Event Channels Configuration Cards -->
    <div class="columns is-variable is-6">
      <div class="column is-6">
        <!-- Twitch Online Alert Card -->
        <div class="card has-background-grey-darker"
          style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
          <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
            <p class="card-header-title has-text-white" style="font-weight: 600;">
              <span class="icon mr-2 has-text-primary"><i class="fab fa-discord"></i></span>
              Twitch Online Alert
            </p>
            <div class="card-header-icon" style="cursor: default;">
              <span class="tag is-success is-light">
                <span class="icon"><i class="fas fa-check-circle"></i></span>
                <span>COMPLETED</span>
              </span>
            </div>
          </header>
          <div class="card-content" style="flex-grow: 1; display: flex; flex-direction: column;">
            <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
              <p class="has-text-dark"><strong>Stream Online Alerts:</strong> Configure Discord channels for stream
                online alerts and voice status updates when you go live on Twitch.</p>
            </div>
            <!-- Stream Online / Live Status Form -->
            <form action="" method="post"
              style="flex-grow: 1; display: flex; flex-direction: column; margin-bottom: 1rem;">
              <input type="hidden" name="guild_id" value="<?php echo htmlspecialchars($existingGuildId); ?>">
              <div class="field">
                <label class="label has-text-white" for="stream_channel_id" style="font-weight: 500;">
                  <span class="icon mr-1 has-text-success"><i class="fas fa-broadcast-tower"></i></span>
                  Stream Online Alerts Channel <span class="has-text-danger">*</span>
                </label>
                <p class="help has-text-grey-light mb-2">For stream online notifications of your channel</p>
                <div class="control has-icons-left">
                  <?php echo generateChannelInput('stream_channel_id', 'stream_channel_id', $existingStreamAlertChannelID, 'e.g. 123456789123456789', $useManualIds, $guildChannels); ?>
                </div>
              </div>
              <div class="field" id="stream_everyone_field" style="display: none;">
                <label class="label has-text-white" style="font-weight: 500;">
                  <span class="icon mr-1 has-text-warning"><i class="fas fa-at"></i></span>
                  @everyone Mention for Stream Alerts
                </label>
                <p class="help has-text-grey-light mb-2">Mention @everyone when posting stream online alerts</p>
                <div class="control">
                  <input type="checkbox" id="stream_alert_everyone" name="stream_alert_everyone"
                    class="switch is-rounded" value="1" <?php echo $existingStreamAlertEveryone ? ' checked' : ''; ?>>
                  <label for="stream_alert_everyone" class="has-text-white">Enable @everyone mention</label>
                </div>
              </div>
              <div class="field" id="stream_custom_role_field" style="display: none;">
                <label class="label has-text-white" style="font-weight: 500;">
                  <span class="icon mr-1 has-text-info"><i class="fas fa-user-tag"></i></span>
                  Custom Role Mention for Stream Alerts
                </label>
                <p class="help has-text-grey-light mb-2">Select a custom role to mention instead of @everyone</p>
                <div class="control has-icons-left">
                  <?php echo generateRoleInput(
                    'stream_alert_custom_role',
                    'stream_alert_custom_role',
                    $existingStreamAlertCustomRole,
                    'e.g. 123456789123456789',
                    $useManualIds,
                    $guildRoles,
                    'fas fa-user-tag',
                    false
                  ); ?>
                </div>
              </div>
              <div class="field">
                <label class="label has-text-white" for="live_channel_id" style="font-weight: 500;">
                  <span class="icon mr-1 has-text-info"><i class="fa-solid fa-volume-high"></i></span>
                  Live Status Channel <span class="has-text-danger">*</span>
                </label>
                <p class="help has-text-grey-light mb-2">The voice channel to update with live status</p>
                <div class="control has-icons-left">
                  <?php echo generateVoiceChannelInput('live_channel_id', 'live_channel_id', $existingLiveChannelId, 'e.g. 123456789123456789', $useManualIds, $guildVoiceChannels, 'fas fa-volume-up', true); ?>
                </div>
              </div>
              <div class="field">
                <label class="label has-text-white" for="online_text" style="font-weight: 500;">
                  <span class="icon is-small is-left has-text-success"><i class="fas fa-circle"></i></span>
                  Online Text
                </label>
                <p class="help has-text-grey-light mb-2">Text to update the status voice channel when your channel is
                  online</p>
                <div class="control has-icons-left">
                  <input class="input" type="text" id="online_text" name="online_text"
                    value="<?php echo htmlspecialchars($existingOnlineText); ?>" <?php if (empty($existingOnlineText)) {
                         echo ' placeholder="e.g. Stream Online"';
                       } ?> maxlength="20"
                    style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                  <span class="icon is-small is-left has-text-success"><i class="fa-solid fa-comment"></i></span>
                </div>
                <p class="help has-text-grey-light">
                  <span id="online_text_counter"><?php echo strlen($existingOnlineText); ?></span>/20 characters
                </p>
              </div>
              <div class="field">
                <label class="label has-text-white" for="offline_text" style="font-weight: 500;">
                  <span class="icon is-small is-left has-text-danger"><i class="fas fa-circle"></i></span>
                  Offline Text
                </label>
                <p class="help has-text-grey-light mb-2">Text to update the status voice channel when your channel is
                  offline</p>
                <div class="control has-icons-left">
                  <input class="input" type="text" id="offline_text" name="offline_text"
                    value="<?php echo htmlspecialchars($existingOfflineText); ?>" <?php if (empty($existingOfflineText)) {
                         echo ' placeholder="e.g. Stream Offline"';
                       } ?> maxlength="20"
                    style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
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
                  <button class="button is-primary is-fullwidth" type="submit" name="save_stream_online"
                    style="border-radius: 6px; font-weight: 600;" <?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                    <span class="icon"><i class="fas fa-cog"></i></span>
                    <span>Save Stream & Live Status</span>
                  </button>
                </div>
                <?php if (!$is_linked || $needs_relink): ?>
                  <p class="help has-text-warning has-text-centered mt-2">Account not linked or needs relinking</p>
                <?php elseif (!$hasGuildId): ?>
                  <p class="help has-text-warning has-text-centered mt-2">Guild ID not setup</p>
                <?php else: ?>
                  <p class="help has-text-grey-light has-text-centered mt-2">
                    These settings control stream online alerts and the voice channel status text.
                  </p>
                <?php endif; ?>
              </div>
            </form>
          </div>
        </div>
      </div>
      <!-- Right Column -->
      <div class="column is-6">
        <!-- Twitch Stream Monitoring Card -->
        <div class="card has-background-grey-darker mb-5"
          style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
          <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
            <p class="card-header-title has-text-white" style="font-weight: 600;">
              <span class="icon mr-2 has-text-primary"><i class="fa-brands fa-twitch"></i></span>
              Twitch Stream Monitoring
            </p>
            <div class="card-header-icon" style="cursor: default;">
              <span class="tag is-success is-light">
                <span class="icon"><i class="fas fa-check-circle"></i></span>
                <span>COMPLETED</span>
              </span>
            </div>
          </header>
          <div class="card-content" style="flex-grow: 1; display: flex; flex-direction: column;">
            <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
              <p class="has-text-dark"><strong>Stream Monitoring:</strong> Add Twitch streamers to monitor and receive
                notifications in your Discord server when they go live.</p>
            </div>
            <form action="" method="post" style="flex-grow: 1; display: flex; flex-direction: column;">
              <div class="field">
                <label class="label has-text-white" for="option" style="font-weight: 500;">Twitch Username</label>
                <div class="control has-icons-left">
                  <input class="input" type="text" id="monitor_username" name="monitor_username"
                    placeholder="e.g. botofthespecter"
                    style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                  <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-person"></i></span>
                </div>
              </div>
              <div class="field">
                <div class="control">
                  <button class="button is-primary is-fullwidth" type="submit"
                    style="border-radius: 6px; font-weight: 600;" <?php echo (!$is_linked || $needs_relink) ? ' disabled' : ''; ?>>
                    <span class="icon"><i class="fas fa-save"></i></span>
                    <span>Add Streamer</span>
                  </button>
                </div>
                <?php if (!$is_linked || $needs_relink): ?>
                  <p class="help has-text-warning has-text-centered mt-2">Account not linked or needs relinking</p>
                <?php endif; ?>
              </div>
            </form>
            <div class="mt-3">
              <button class="button is-link is-fullwidth modal-button" style="border-radius: 6px; font-weight: 600;"
                data-target="savedStreamersModal">
                <span class="icon"><i class="fa-solid fa-people-group"></i></span>
                <span>View Tracked Streamers</span>
              </button>
            </div>
            <!-- Twitch Stream Monitoring Channel Selector -->
            <form action="" method="post" style="margin-top: 0.75rem;">
              <input type="hidden" name="guild_id" value="<?php echo htmlspecialchars($existingGuildId); ?>">
              <div class="field">
                <label class="label has-text-white" for="twitch_stream_monitor_id" style="font-weight: 500;">Twitch
                  Stream Monitoring Channel</label>
                <p class="help has-text-grey-light mb-2">Channel to post when tracked Twitch users go live</p>
                <div class="control has-icons-left">
                  <?php echo generateChannelInput('twitch_stream_monitor_id', 'twitch_stream_monitor_id', $existingTwitchStreamMonitoringID, 'e.g. 123456789123456789', $useManualIds, $guildChannels); ?>
                </div>
              </div>
              <div class="field">
                <div class="control">
                  <button class="button is-primary is-fullwidth" type="submit" name="save_stream_monitoring"
                    style="border-radius: 6px; font-weight: 600;" <?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                    <span class="icon"><i class="fas fa-wifi"></i></span>
                    <span>Save Monitoring Channel</span>
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
        <!-- Twitch Event/Action Audit Log Card (separate box below) -->
        <div class="card has-background-grey-darker"
          style="border-radius: 12px; border: 1px solid #363636; margin-top: 1rem; width: 100%;">
          <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
            <p class="card-header-title has-text-white" style="font-weight: 600;">
              <span class="icon mr-2 has-text-primary"><i class="fas fa-clipboard-list"></i></span>
              Twitch Event/Action Audit Log
            </p>
            <div class="card-header-icon" style="cursor: default;">
              <span class="tag is-info is-light">
                <span class="icon"><i class="fas fa-list"></i></span>
                <span>AUDIT</span>
              </span>
            </div>
          </header>
          <div class="card-content" style="display: flex; flex-direction: column;">
            <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
              <p class="has-text-dark"><strong>Audit Logging:</strong> Track all Twitch moderation actions and events
                with automatic Discord channel logging for full transparency and record-keeping.</p>
            </div>
            <form action="" method="post" style="display: flex; flex-direction: column; width: 100%;">
              <input type="hidden" name="guild_id" value="<?php echo htmlspecialchars($existingGuildId); ?>">
              <div class="field">
                <label class="label has-text-white" for="mod_channel_id" style="font-weight: 500;">
                  <span class="icon mr-1 has-text-danger"><i class="fas fa-shield-alt"></i></span>
                  Twitch Moderation Actions Channel <span class="has-text-danger">*</span>
                </label>
                <p class="help has-text-grey-light mb-2">Any moderation actions will be logged to this channel, e.g.
                  bans, timeouts, message deletions</p>
                <div class="control has-icons-left">
                  <?php echo generateChannelInput('mod_channel_id', 'mod_channel_id', $existingModerationChannelID, 'e.g. 123456789123456789', $useManualIds, $guildChannels); ?>
                </div>
              </div>
              <div class="field">
                <label class="label has-text-white" for="alert_channel_id" style="font-weight: 500;">
                  <span class="icon mr-1 has-text-warning"><i class="fas fa-exclamation-triangle"></i></span>
                  Twitch Event Alerts Channel <span class="has-text-danger">*</span>
                </label>
                <p class="help has-text-grey-light mb-2">Get a discord notification when a Twitch event occurs, e.g.
                  Followers, Subscriptions, Bits</p>
                <div class="control has-icons-left">
                  <?php echo generateChannelInput('alert_channel_id', 'alert_channel_id', $existingAlertChannelID, 'e.g. 123456789123456789', $useManualIds, $guildChannels); ?>
                </div>
              </div>
              <div class="field">
                <div class="control">
                  <button class="button is-primary is-fullwidth" type="submit" name="save_alert_channels"
                    style="border-radius: 6px; font-weight: 600;" <?php echo (!$is_linked || $needs_relink || !$hasGuildId) ? ' disabled' : ''; ?>>
                    <span class="icon"><i class="fas fa-bell"></i></span>
                    <span>Save Audit Log Channels</span>
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
    <!-- Individual Management Feature Cards -->
    <?php if ($hasEnabledFeatures && $is_linked && !$needs_relink && $hasGuildId): ?>
      <div class="columns is-multiline">
        <div class="column is-6 is-flex" id="feature-box-welcomeMessage"
          style="display: <?php echo $serverManagementSettings['welcomeMessage'] ? 'flex' : 'none'; ?>;">
          <div class="card has-background-grey-darker"
            style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
            <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
              <p class="card-header-title has-text-white" style="font-weight: 600;">
                <span class="icon mr-2 has-text-success"><i class="fas fa-door-open"></i></span>
                Welcome Message Configuration
              </p>
              <div class="card-header-icon">
                <button class="button is-ghost" onclick="clearFeature('welcomeMessage')" style="margin-right: 10px;"
                  title="Clear all welcome message data and disable this feature">
                  <span class="icon has-text-danger"><i class="fas fa-trash"></i></span>
                </button>
                <span class="tag is-success is-light">
                  <span class="icon"><i class="fas fa-check-circle"></i></span>
                  <span>COMPLETED</span>
                </span>
              </div>
            </header>
            <div class="card-content">
              <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                <p class="has-text-dark"><strong>Welcome Messages:</strong> Greet new members with personalized welcome
                  messages when they join your Discord server.</p>
              </div>
              <form action="" method="post">
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Welcome Channel <span
                      class="has-text-danger">*</span></label>
                  <div class="control has-icons-left">
                    <?php echo generateChannelInput('welcome_channel_id', 'welcome_channel_id', $existingWelcomeChannelID, 'e.g. 123456789123456789', $useManualIds, $guildChannels); ?>
                  </div>
                  <p class="help has-text-grey-light">Channel where welcome messages will be sent</p>
                </div>
                <div class="field">
                  <div class="control">
                    <label class="checkbox has-text-white">
                      <input type="checkbox" id="use_default_welcome_message" name="use_default_welcome_message"
                        style="margin-right: 8px;" <?php echo $existingWelcomeUseDefault ? ' checked' : ''; ?>>
                      Use default welcome message
                    </label>
                  </div>
                  <p class="help has-text-grey-light">Enable this to use the bot's default welcome message instead of a
                    custom one</p>
                </div>
                <div class="field">
                  <div class="control">
                    <label class="checkbox has-text-white">
                      <input type="checkbox" id="enable_embed_message" name="enable_embed_message"
                        style="margin-right: 8px;" <?php echo $existingWelcomeEmbed ? ' checked' : ''; ?>>
                      Enable Embed Message
                    </label>
                  </div>
                  <p class="help has-text-grey-light">Send the welcome message as a rich embed with formatting and colors
                  </p>
                </div>
                <div class="field" id="welcome_colour_field"
                  style="<?php echo $existingWelcomeEmbed ? '' : 'display: none;'; ?>">
                  <label class="label has-text-white" style="font-weight: 500;">
                    <span class="icon is-small mr-1"><i class="fas fa-palette"></i></span>
                    Embed Colour
                  </label>
                  <div class="control">
                    <input class="input" type="color" id="welcome_colour" name="welcome_colour"
                      value="<?php echo htmlspecialchars($existingWelcomeColour ?: '#00d1b2'); ?>"
                      style="height: 40px; cursor: pointer;">
                  </div>
                  <p class="help has-text-grey-light">Choose the colour for the embed border and accent</p>
                </div>
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Custom Welcome Message</label>
                  <div class="control">
                    <textarea class="textarea" id="welcome_message" name="welcome_message" rows="3"
                      placeholder="Welcome (user) to our server, we're so glad you joined us!"
                      style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;" <?php echo $existingWelcomeUseDefault ? ' disabled' : ''; ?>><?php echo htmlspecialchars($existingWelcomeMessage); ?></textarea>
                  </div>
                  <p class="help has-text-grey-light">Use (user) to insert the member's username</p>
                </div>
                <div class="field">
                  <div class="control">
                    <button class="button is-primary is-fullwidth" type="button" onclick="saveWelcomeMessage()"
                      name="save_welcome_message" style="border-radius: 6px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-save"></i></span>
                      <span>Save Welcome Message Configuration</span>
                    </button>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
        <div class="column is-6 is-flex" id="feature-box-autoRole"
          style="display: <?php echo $serverManagementSettings['autoRole'] ? 'flex' : 'none'; ?>;">
          <div class="card has-background-grey-darker"
            style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
            <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
              <p class="card-header-title has-text-white" style="font-weight: 600;">
                <span class="icon mr-2 has-text-info"><i class="fas fa-user-plus"></i></span>
                Auto Role Assignment Configuration
              </p>
              <div class="card-header-icon">
                <button class="button is-ghost" onclick="clearFeature('autoRole')" style="margin-right: 10px;"
                  title="Clear all auto role data and disable this feature">
                  <span class="icon has-text-danger"><i class="fas fa-trash"></i></span>
                </button>
                <span class="tag is-success is-light">
                  <span class="icon"><i class="fas fa-check-circle"></i></span>
                  <span>COMPLETED</span>
                </span>
              </div>
            </header>
            <div class="card-content">
              <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                <p class="has-text-dark"><strong>Auto Role Assignment:</strong> Automatically assign a role to new members
                  when they join your Discord server.</p>
              </div>
              <form action="" method="post">
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Auto Role</label>
                  <div class="control has-icons-left">
                    <?php echo generateRoleInput(
                      'auto_role_id',
                      'auto_role_id',
                      $existingAutoRoleID, // Current value from database 
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
                    <button class="button is-primary is-fullwidth" type="button" onclick="saveAutoRole()"
                      name="save_auto_role" style="border-radius: 6px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-save"></i></span>
                      <span>Save Auto Role Settings</span>
                    </button>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
        <div class="column is-6 is-flex" id="feature-box-roleHistory"
          style="display: <?php echo $serverManagementSettings['roleHistory'] ? 'flex' : 'none'; ?>;">
          <div class="card has-background-grey-darker"
            style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
            <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
              <p class="card-header-title has-text-white" style="font-weight: 600;">
                <span class="icon mr-2 has-text-warning"><i class="fas fa-history"></i></span>
                Role History Configuration
              </p>
              <div class="card-header-icon">
                <button class="button is-ghost" onclick="clearFeature('roleHistory')" style="margin-right: 10px;"
                  title="Clear all role history data and disable this feature">
                  <span class="icon has-text-danger"><i class="fas fa-trash"></i></span>
                </button>
                <span class="tag is-success is-light">
                  <span class="icon"><i class="fas fa-check-circle"></i></span>
                  <span>COMPLETED</span>
                </span>
              </div>
            </header>
            <div class="card-content">
              <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                <p class="has-text-dark"><strong>Role History:</strong> Automatically restore roles to members when they
                  rejoin your server, with configurable retention period for role records.</p>
              </div>
              <form id="roleHistoryForm" method="POST">
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Enable Role Restoration</label>
                  <div class="control">
                    <label class="checkbox has-text-white">
                      <input type="checkbox" id="restore_roles" name="restore_roles" <?php echo ($existingRoleHistoryEnabled == 1 ? 'checked' : ''); ?> style="margin-right: 8px;">
                      Restore all previous roles when member rejoins
                    </label>
                  </div>
                  <p class="help has-text-grey-light">When enabled, users will automatically receive their previous roles
                    when they rejoin</p>
                </div>
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">History Retention Period (Days)</label>
                  <div class="control has-icons-left">
                    <input class="input" type="number" id="history_retention_days" name="history_retention_days"
                      value="<?php echo $existingRoleHistoryRetention ?? 30; ?>" min="1" max="365"
                      style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;">
                    <span class="icon is-small is-left has-text-grey-light"><i class="fas fa-calendar"></i></span>
                  </div>
                  <p class="help has-text-grey-light">How long to keep role history data after a member leaves (1-365
                    days)</p>
                </div>
                <div class="field">
                  <div class="control">
                    <button class="button is-primary is-fullwidth" type="submit" name="save_role_history"
                      style="border-radius: 6px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-save"></i></span>
                      <span>Save Role History Settings</span>
                    </button>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
        <div class="column is-6 is-flex" id="feature-box-messageTracking"
          style="display: <?php echo $serverManagementSettings['messageTracking'] ? 'flex' : 'none'; ?>;">
          <div class="card has-background-grey-darker"
            style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
            <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
              <p class="card-header-title has-text-white" style="font-weight: 600;">
                <span class="icon mr-2 has-text-danger"><i class="fas fa-eye"></i></span>
                Message Tracking Configuration
              </p>
              <div class="card-header-icon">
                <button class="button is-ghost" onclick="clearFeature('messageTracking')" style="margin-right: 10px;"
                  title="Clear all message tracking data and disable this feature">
                  <span class="icon has-text-danger"><i class="fas fa-trash"></i></span>
                </button>
                <span class="tag is-success is-light">
                  <span class="icon"><i class="fas fa-check-circle"></i></span>
                  <span>COMPLETED</span>
                </span>
              </div>
            </header>
            <div class="card-content">
              <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                <p class="has-text-dark"><strong>Message Tracking:</strong> Track and log edited and deleted messages in
                  your Discord server for moderation and transparency purposes.</p>
              </div>
              <form action="" method="post">
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Message Log Channel ID</label>
                  <div class="control has-icons-left">
                    <?php echo generateChannelInput('message_tracking_log_channel_id', 'message_tracking_log_channel_id', $existingMessageTrackingLogChannel, 'e.g. 123456789123456789', $useManualIds, $guildChannels); ?>
                  </div>
                  <p class="help has-text-grey-light">Channel where message edit/delete logs will be sent</p>
                </div>
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Tracking Options</label>
                  <div class="control">
                    <label class="checkbox has-text-white mb-2" style="display: block;">
                      <input type="checkbox" name="track_message_edits" style="margin-right: 8px;" <?php echo $existingMessageTrackingEdits ? 'checked' : ''; ?>>
                      Track message edits
                    </label>
                    <label class="checkbox has-text-white">
                      <input type="checkbox" name="track_message_deletes" style="margin-right: 8px;" <?php echo $existingMessageTrackingDeletes ? 'checked' : ''; ?>>
                      Track message deletions
                    </label>
                  </div>
                </div>
                <div class="field">
                  <div class="control">
                    <button class="button is-primary is-fullwidth" type="submit" name="save_message_tracking"
                      style="border-radius: 6px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-save"></i></span>
                      <span>Save Message Tracking Settings</span>
                    </button>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
        <div class="column is-6 is-flex" id="feature-box-roleTracking"
          style="display: <?php echo $serverManagementSettings['roleTracking'] ? 'flex' : 'none'; ?>;">
          <div class="card has-background-grey-darker"
            style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
            <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
              <p class="card-header-title has-text-white" style="font-weight: 600;">
                <span class="icon mr-2 has-text-primary"><i class="fas fa-users-cog"></i></span>
                Role Tracking Configuration
              </p>
              <div class="card-header-icon">
                <button class="button is-ghost" onclick="clearFeature('roleTracking')" style="margin-right: 10px;"
                  title="Clear all role tracking data and disable this feature">
                  <span class="icon has-text-danger"><i class="fas fa-trash"></i></span>
                </button>
                <span class="tag is-success is-light">
                  <span class="icon"><i class="fas fa-check-circle"></i></span>
                  <span>COMPLETED</span>
                </span>
              </div>
            </header>
            <div class="card-content">
              <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                <p class="has-text-dark"><strong>Role Tracking:</strong> Monitor and log role assignments and removals for
                  audit and transparency purposes in your Discord server.</p>
              </div>
              <form action="" method="post">
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Role Log Channel</label>
                  <div class="control has-icons-left">
                    <?php echo generateChannelInput('role_tracking_log_channel_id', 'role_tracking_log_channel_id', $existingRoleTrackingLogChannel, 'Select log channel', $useManualIds, $guildChannels); ?>
                  </div>
                  <p class="help has-text-grey-light">Channel where role change logs will be sent</p>
                </div>
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Tracking Options</label>
                  <div class="control">
                    <label class="checkbox has-text-white mb-2" style="display: block;">
                      <input type="checkbox" name="track_role_additions" style="margin-right: 8px;" <?php echo $existingRoleTrackingAdditions ? 'checked' : ''; ?>>
                      Track role additions
                    </label>
                    <label class="checkbox has-text-white">
                      <input type="checkbox" name="track_role_removals" style="margin-right: 8px;" <?php echo $existingRoleTrackingRemovals ? 'checked' : ''; ?>>
                      Track role removals
                    </label>
                  </div>
                </div>
                <div class="field">
                  <div class="control">
                    <button class="button is-primary is-fullwidth" type="submit" name="save_role_tracking"
                      style="border-radius: 6px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-save"></i></span>
                      <span>Save Role Tracking Settings</span>
                    </button>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
        <div class="column is-6 is-flex" id="feature-box-serverRoleManagement"
          style="display: <?php echo $serverManagementSettings['serverRoleManagement'] ? 'flex' : 'none'; ?>;">
          <div class="card has-background-grey-darker"
            style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
            <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
              <p class="card-header-title has-text-white" style="font-weight: 600;">
                <span class="icon mr-2 has-text-link"><i class="fas fa-cogs"></i></span>
                Server Role Management Configuration
              </p>
              <div class="card-header-icon">
                <button class="button is-ghost" onclick="clearFeature('serverRoleManagement')" style="margin-right: 10px;"
                  title="Clear all server role management data and disable this feature">
                  <span class="icon has-text-danger"><i class="fas fa-trash"></i></span>
                </button>
                <span class="tag is-success is-light">
                  <span class="icon"><i class="fas fa-check-circle"></i></span>
                  <span>COMPLETED</span>
                </span>
              </div>
            </header>
            <div class="card-content">
              <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                <p class="has-text-dark"><strong>Server Role Management:</strong> Track role creation, deletion, and edits
                  within your Discord server for full server management audit logs.</p>
              </div>
              <form action="" method="post">
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Server Management Log Channel ID</label>
                  <div class="control has-icons-left">
                    <?php echo generateChannelInput('server_mgmt_log_channel_id', 'server_mgmt_log_channel_id', $existingServerRoleManagementLogChannel, 'e.g. 123456789123456789', $useManualIds, $guildChannels); ?>
                  </div>
                  <p class="help has-text-grey-light">Channel where server role management logs will be sent</p>
                </div>
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Management Options</label>
                  <div class="control">
                    <label class="checkbox has-text-white mb-2" style="display: block;">
                      <input type="checkbox" name="track_role_creation" style="margin-right: 8px;" <?php echo $existingRoleCreationTracking ? 'checked' : ''; ?>>
                      Track role creation
                    </label>
                    <label class="checkbox has-text-white mb-2" style="display: block;">
                      <input type="checkbox" name="track_role_deletion" style="margin-right: 8px;" <?php echo $existingRoleDeletionTracking ? 'checked' : ''; ?>>
                      Track role deletion
                    </label>
                    <label class="checkbox has-text-white">
                      <input type="checkbox" name="track_role_edits" style="margin-right: 8px;" <?php echo $existingRoleEditTracking ? 'checked' : ''; ?>>
                      Track role edits
                    </label>
                  </div>
                </div>
                <div class="field">
                  <div class="control">
                    <button class="button is-primary is-fullwidth" type="submit" name="save_server_role_management"
                      style="border-radius: 6px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-save"></i></span>
                      <span>Save Server Role Management Settings</span>
                    </button>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
        <div class="column is-6 is-flex" id="feature-box-userTracking"
          style="display: <?php echo $serverManagementSettings['userTracking'] ? 'flex' : 'none'; ?>;">
          <div class="card has-background-grey-darker"
            style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
            <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
              <p class="card-header-title has-text-white" style="font-weight: 600;">
                <span class="icon mr-2 has-text-info"><i class="fas fa-user-edit"></i></span>
                User Tracking Configuration
              </p>
              <div class="card-header-icon">
                <button class="button is-ghost" onclick="clearFeature('userTracking')" style="margin-right: 10px;"
                  title="Clear all user tracking data and disable this feature">
                  <span class="icon has-text-danger"><i class="fas fa-trash"></i></span>
                </button>
                <span class="tag is-success is-light">
                  <span class="icon"><i class="fas fa-check-circle"></i></span>
                  <span>COMPLETED</span>
                </span>
              </div>
            </header>
            <div class="card-content">
              <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                <p class="has-text-dark"><strong>User Tracking:</strong> Track and log user activity including joins,
                  leaves, nickname changes, avatar updates, and status changes in your Discord server.</p>
              </div>
              <form action="" method="post">
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">User Tracking Log Channel ID</label>
                  <div class="control has-icons-left">
                    <?php echo generateChannelInput('user_tracking_log_channel_id', 'user_tracking_log_channel_id', $existingUserTrackingLogChannel, 'e.g. 123456789123456789', $useManualIds, $guildChannels); ?>
                  </div>
                  <p class="help has-text-grey-light">Channel where user tracking logs will be sent</p>
                </div>
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Tracking Options</label>
                  <div class="control">
                    <label class="checkbox has-text-white mb-2" style="display: block;">
                      <input type="checkbox" name="track_user_joins" style="margin-right: 8px;" <?php echo $existingUserJoinTracking ? 'checked' : ''; ?>>
                      Track user joins
                    </label>
                    <label class="checkbox has-text-white mb-2" style="display: block;">
                      <input type="checkbox" name="track_user_leaves" style="margin-right: 8px;" <?php echo $existingUserLeaveTracking ? 'checked' : ''; ?>>
                      Track user leaves
                    </label>
                    <label class="checkbox has-text-white mb-2" style="display: block;">
                      <input type="checkbox" name="track_user_nickname" style="margin-right: 8px;" <?php echo $existingUserNicknameTracking ? 'checked' : ''; ?>>
                      Track nickname changes
                    </label>
                    <label class="checkbox has-text-white mb-2" style="display: block;">
                      <input type="checkbox" name="track_user_username" style="margin-right: 8px;" <?php echo $existingUserUsernameTracking ? 'checked' : ''; ?>>
                      Track username changes
                    </label>
                    <label class="checkbox has-text-white mb-2" style="display: block;">
                      <input type="checkbox" name="track_user_avatar" style="margin-right: 8px;" <?php echo $existingUserAvatarTracking ? 'checked' : ''; ?>>
                      Track avatar changes
                    </label>
                    <label class="checkbox has-text-white">
                      <input type="checkbox" name="track_user_status" style="margin-right: 8px;" <?php echo $existingUserStatusTracking ? 'checked' : ''; ?>>
                      Track status changes
                    </label>
                  </div>
                </div>
                <div class="field">
                  <div class="control">
                    <button class="button is-primary is-fullwidth" type="submit" name="save_user_tracking"
                      style="border-radius: 6px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-save"></i></span>
                      <span>Save User Tracking Settings</span>
                    </button>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
        <div class="column is-6 is-flex" id="feature-box-reactionRoles"
          style="display: <?php echo $serverManagementSettings['reactionRoles'] ? 'flex' : 'none'; ?>;">
          <div class="card has-background-grey-darker"
            style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
            <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
              <p class="card-header-title has-text-white" style="font-weight: 600;">
                <span class="icon mr-2 has-text-purple"><i class="fas fa-hand-paper"></i></span>
                Reaction Roles Configuration
              </p>
              <div class="card-header-icon">
                <button class="button is-ghost" onclick="clearReactionRoles()" style="margin-right: 10px;"
                  title="Clear all reaction roles data and disable this feature">
                  <span class="icon has-text-danger"><i class="fas fa-trash"></i></span>
                </button>
                <span class="tag is-success is-light">
                  <span class="icon"><i class="fas fa-check-circle"></i></span>
                  <span>COMPLETED</span>
                </span>
              </div>
            </header>
            <div class="card-content">
              <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                <p class="has-text-dark"><strong>Reaction Roles:</strong> Configure self-assignable roles via reactions in
                  your Discord server.</p>
              </div>
              <form action="" method="post">
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Reaction Roles Channel ID</label>
                  <div class="control has-icons-left">
                    <?php echo generateChannelInput('reaction_roles_channel_id', 'reaction_roles_channel_id', $existingReactionRolesChannelID, 'e.g. 123456789123456789', $useManualIds, $guildChannels); ?>
                  </div>
                  <p class="help has-text-grey-light">Channel where reaction roles messages will be posted</p>
                </div>
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Reaction Roles Message</label>
                  <div class="control">
                    <textarea class="textarea" id="reaction_roles_message" name="reaction_roles_message" rows="3"
                      placeholder="To join any of the following roles, use the icons below. Click on the boxes below to get the roles!"
                      style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;"><?php echo htmlspecialchars($existingReactionRolesMessage ?? ''); ?></textarea>
                  </div>
                  <p class="help has-text-grey-light">Message to display above the reaction roles. Leave empty for no
                    message.</p>
                </div>
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Reaction Role Mappings</label>
                  <div class="control">
                    <textarea class="textarea" id="reaction_roles_mappings" name="reaction_roles_mappings" rows="4"
                      placeholder=":thumbsup: Thumbs Up @Role1 [green]&#10;:heart: Love @Role2 [red]&#10;:star: VIP @Role3 [blue]&#10;Member Role @Role4 [gray]"
                      style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;"><?php echo htmlspecialchars($existingReactionRolesMappings ?? ''); ?></textarea>
                  </div>
                  <p class="help has-text-grey-light">Format: :emoji: Description @RoleName [color] (one per line)<br>
                    Colors: blue/primary, gray/secondary, green/success, red/danger (optional, defaults to blue)</p>
                </div>
                <div class="field">
                  <div class="control">
                    <label class="checkbox has-text-white">
                      <input type="checkbox" id="allow_multiple_reactions" name="allow_multiple_reactions"
                        style="margin-right: 8px;" <?php echo $existingAllowMultipleReactions ? ' checked' : ''; ?>>
                      Allow users to select multiple roles
                    </label>
                  </div>
                  <p class="help has-text-grey-light">If unchecked, users can only have one role from this reaction role
                    set</p>
                </div>
                <div class="field">
                  <div class="control">
                    <button class="button is-primary is-fullwidth" type="button" onclick="saveReactionRoles()"
                      name="save_reaction_roles" style="border-radius: 6px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-save"></i></span>
                      <span>Save Reaction Roles Settings</span>
                    </button>
                  </div>
                </div>
                <div class="field">
                  <div class="control">
                    <button class="button is-success is-fullwidth" type="button" onclick="sendReactionRolesMessage()"
                      id="send_reaction_roles_message" name="send_reaction_roles_message"
                      style="border-radius: 6px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-paper-plane"></i></span>
                      <span>Send Message to Channel</span>
                    </button>
                  </div>
                  <p class="help has-text-grey-light has-text-centered mt-2">Posts or updates the reaction roles message
                    in Discord and applies the emoji mappings</p>
                </div>
              </form>
            </div>
          </div>
        </div>
        <div class="column is-6 is-flex" id="feature-box-rulesConfiguration"
          style="display: <?php echo $serverManagementSettings['rulesConfiguration'] ? 'flex' : 'none'; ?>;">
          <div class="card has-background-grey-darker"
            style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
            <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
              <p class="card-header-title has-text-white" style="font-weight: 600;">
                <span class="icon mr-2 has-text-info"><i class="fas fa-gavel"></i></span>
                Rules Configuration
              </p>
              <div class="card-header-icon">
                <button class="button is-ghost" onclick="clearRules()" style="margin-right: 10px;"
                  title="Clear all rules data and disable this feature">
                  <span class="icon has-text-danger"><i class="fas fa-trash"></i></span>
                </button>
                <span class="tag is-success is-light">
                  <span class="icon"><i class="fas fa-check-circle"></i></span>
                  <span>COMPLETED</span>
                </span>
              </div>
            </header>
            <div class="card-content">
              <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                <p class="has-text-dark"><strong>Server Rules:</strong> Post an embed with your server rules to keep your
                  community informed and set clear expectations for all members.</p>
              </div>
              <form action="" method="post">
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Rules Channel <span
                      class="has-text-danger">*</span></label>
                  <div class="control has-icons-left">
                    <?php echo generateChannelInput('rules_channel_id', 'rules_channel_id', $existingRulesChannelID, 'e.g. 123456789123456789', $useManualIds, $guildChannels); ?>
                  </div>
                  <p class="help has-text-grey-light">Channel where the rules message will be posted</p>
                </div>
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Rules Title <span
                      class="has-text-danger">*</span></label>
                  <div class="control">
                    <input class="input" type="text" id="rules_title" name="rules_title"
                      value="<?php echo htmlspecialchars($existingRulesTitle ?? ''); ?>" placeholder="e.g. Server Rules"
                      style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;"
                      required>
                  </div>
                  <p class="help has-text-grey-light">Title for the rules embed (appears at the top)</p>
                </div>
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Rules Content <span
                      class="has-text-danger">*</span></label>
                  <div class="control">
                    <textarea class="textarea" id="rules_content" name="rules_content" rows="8"
                      placeholder="Enter your server rules (one per line or formatted as you prefer)&#10;&#10;Example:&#10;1. Be respectful to all members&#10;2. No spamming or advertising&#10;3. Keep content appropriate&#10;4. Follow Discord's Terms of Service"
                      style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;"
                      required><?php echo htmlspecialchars($existingRulesContent ?? ''); ?></textarea>
                  </div>
                  <p class="help has-text-grey-light">Enter your server rules. You can use numbered lists, bullet points,
                    or any format you prefer. Discord markdown is supported.</p>
                </div>
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Embed Color</label>
                  <div class="control">
                    <input class="input" type="color" id="rules_color" name="rules_color"
                      value="<?php echo htmlspecialchars($existingRulesColor ?: '#5865f2'); ?>"
                      style="background-color: #4a4a4a; border-color: #5a5a5a; height: 50px; border-radius: 6px;">
                  </div>
                  <p class="help has-text-grey-light">Choose a color for the rules embed border (default is Discord blue)
                  </p>
                </div>
                <div class="field">
                  <div class="control">
                    <label class="checkbox has-text-white">
                      <input type="checkbox" id="rules_assign_role_on_accept" name="rules_assign_role_on_accept"
                        style="margin-right: 8px;" <?php echo !empty($existingRulesAcceptRoleID) ? ' checked' : ''; ?>>
                      Assign role on rule acceptance
                    </label>
                  </div>
                  <p class="help has-text-grey-light">When enabled, users who react with ✅ to the rules will be assigned
                    the selected role</p>
                </div>
                <div class="field" id="rules_accept_role_field"
                  style="<?php echo empty($existingRulesAcceptRoleID) ? 'display: none;' : ''; ?>">
                  <label class="label has-text-white" style="font-weight: 500;">Rules Acceptance Role</label>
                  <div class="control has-icons-left">
                    <?php echo generateRoleInput('rules_accept_role_id', 'rules_accept_role_id', $existingRulesAcceptRoleID ?? '', 'e.g. 123456789123456789', $useManualIds, $guildRoles); ?>
                  </div>
                  <p class="help has-text-grey-light">Role to assign when users react with ✅ to accept the rules</p>
                </div>
                <div class="field">
                  <div class="control">
                    <button class="button is-primary is-fullwidth" type="button" onclick="saveRules()" name="save_rules"
                      style="border-radius: 6px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-save"></i></span>
                      <span>Save Rules Configuration</span>
                    </button>
                  </div>
                </div>
                <div class="field">
                  <div class="control">
                    <button class="button is-success is-fullwidth" type="button" onclick="sendRulesMessage()"
                      id="send_rules_message" name="send_rules_message" style="border-radius: 6px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-paper-plane"></i></span>
                      <span>Send Rules to Channel</span>
                    </button>
                  </div>
                  <p class="help has-text-grey-light has-text-centered mt-2">Posts or updates the rules embed in the
                    selected Discord channel with the latest configuration</p>
                </div>
              </form>
            </div>
          </div>
        </div>
        <div class="column is-6 is-flex" id="feature-box-streamSchedule"
          style="display: <?php echo $serverManagementSettings['streamSchedule'] ? 'flex' : 'none'; ?>;">
          <div class="card has-background-grey-darker"
            style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
            <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
              <p class="card-header-title has-text-white" style="font-weight: 600;">
                <span class="icon mr-2 has-text-primary"><i class="fas fa-calendar-alt"></i></span>
                Stream Schedule Configuration
              </p>
              <div class="card-header-icon">
                <button class="button is-ghost" onclick="clearStreamSchedule()" style="margin-right: 10px;"
                  title="Clear all schedule data and disable this feature">
                  <span class="icon has-text-danger"><i class="fas fa-trash"></i></span>
                </button>
                <span class="tag is-success is-light">
                  <span class="icon"><i class="fas fa-check-circle"></i></span>
                  <span>COMPLETED</span>
                </span>
              </div>
            </header>
            <div class="card-content">
              <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                <p class="has-text-dark"><strong>Stream Schedule:</strong> Post an embed with your streaming schedule to
                  keep your community informed about when you stream.</p>
              </div>
              <form action="" method="post">
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Schedule Channel <span
                      class="has-text-danger">*</span></label>
                  <div class="control has-icons-left">
                    <?php echo generateChannelInput('stream_schedule_channel_id', 'stream_schedule_channel_id', $existingStreamScheduleChannelID, 'e.g. 123456789123456789', $useManualIds, $guildChannels); ?>
                  </div>
                  <p class="help has-text-grey-light">Channel where the stream schedule message will be posted</p>
                </div>
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Schedule Title <span
                      class="has-text-danger">*</span></label>
                  <div class="control">
                    <input class="input" type="text" id="stream_schedule_title" name="stream_schedule_title"
                      value="<?php echo htmlspecialchars($existingStreamScheduleTitle ?? ''); ?>"
                      placeholder="e.g. Weekly Stream Schedule"
                      style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;"
                      required>
                  </div>
                  <p class="help has-text-grey-light">Title for the stream schedule embed (appears at the top)</p>
                </div>
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Stream Schedule Content <span
                      class="has-text-danger">*</span></label>
                  <div class="control">
                    <textarea class="textarea" id="stream_schedule_content" name="stream_schedule_content" rows="10"
                      placeholder="Enter your stream schedule (one per line or formatted as you prefer)&#10;&#10;Example:&#10;🎮 Monday: 7:00 PM - 10:00 PM EST - Variety Gaming&#10;🎮 Wednesday: 8:00 PM - 11:00 PM EST - Just Chatting&#10;🎮 Friday: 7:00 PM - 12:00 AM EST - Game Night&#10;🎮 Saturday: 3:00 PM - 7:00 PM EST - Community Games&#10;&#10;Or use Discord markdown:&#10;**Monday** - 7:00 PM EST&#10;**Wednesday** - 8:00 PM EST"
                      style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;"
                      required><?php echo htmlspecialchars($existingStreamScheduleContent ?? ''); ?></textarea>
                  </div>
                  <p class="help has-text-grey-light">Enter your stream schedule. You can use emojis, bullet points, or
                    any format you prefer. Discord markdown is supported. <a
                      href="https://help.botofthespecter.com/markdown.php" target="_blank" style="color: #3273dc;">View
                      markdown guide</a></p>
                </div>
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Timezone <span
                      class="has-text-danger">*</span></label>
                  <div class="control">
                    <input class="input" type="text" id="stream_schedule_timezone" name="stream_schedule_timezone"
                      value="<?php echo htmlspecialchars($existingStreamScheduleTimezone ?? ''); ?>"
                      placeholder="e.g. EST, PST, UTC, etc."
                      style="background-color: #4a4a4a; border-color: #5a5a5a; color: white; border-radius: 6px;"
                      required>
                  </div>
                  <p class="help has-text-grey-light">Specify your timezone for clarity (will be shown in the footer)</p>
                </div>
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Embed Color</label>
                  <div class="control">
                    <input class="input" type="color" id="stream_schedule_color" name="stream_schedule_color"
                      value="<?php echo htmlspecialchars($existingStreamScheduleColor ?: '#9146ff'); ?>"
                      style="background-color: #4a4a4a; border-color: #5a5a5a; height: 50px; border-radius: 6px;">
                  </div>
                  <p class="help has-text-grey-light">Choose a color for the schedule embed border (default is Twitch
                    purple)</p>
                </div>
                <div class="field">
                  <div class="control">
                    <button class="button is-primary is-fullwidth" type="button" onclick="saveStreamSchedule()"
                      name="save_stream_schedule" style="border-radius: 6px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-save"></i></span>
                      <span>Save Schedule Configuration</span>
                    </button>
                  </div>
                </div>
                <div class="field">
                  <div class="control">
                    <button class="button is-success is-fullwidth" type="button" onclick="sendStreamScheduleMessage()"
                      id="send_stream_schedule_message" name="send_stream_schedule_message"
                      style="border-radius: 6px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-paper-plane"></i></span>
                      <span>Send Schedule to Channel</span>
                    </button>
                  </div>
                  <p class="help has-text-grey-light has-text-centered mt-2">Posts or updates the stream schedule embed in
                    the selected Discord channel with the latest configuration</p>
                </div>
              </form>
            </div>
          </div>
        </div>
        <!-- Free Games Configuration Section -->
        <div class="column is-6 is-flex" id="feature-box-freeGames"
          style="<?php echo $serverManagementSettings['freeGames'] ? 'display: flex;' : 'display: none !important;'; ?>">
          <div class="card has-background-grey-darker"
            style="border-radius: 12px; border: 1px solid #363636; width: 100%; display: flex; flex-direction: column;">
            <header class="card-header" style="border-bottom: 1px solid #363636; border-radius: 12px 12px 0 0;">
              <p class="card-header-title has-text-white" style="font-weight: 600;">
                <span class="icon mr-2 has-text-success"><i class="fas fa-gift"></i></span>
                Free Games Configuration
              </p>
              <div class="card-header-icon">
                <button class="button is-ghost" onclick="clearFreeGames()" style="margin-right: 10px;"
                  title="Clear all free games data and disable this feature">
                  <span class="icon has-text-danger"><i class="fas fa-trash"></i></span>
                </button>
                <span class="tag is-success is-light">
                  <span class="icon"><i class="fas fa-check-circle"></i></span>
                  <span>COMPLETED</span>
                </span>
              </div>
            </header>
            <div class="card-content">
              <div class="notification is-info is-light" style="border-radius: 8px; margin-bottom: 1rem;">
                <p class="has-text-dark"><strong>Free Games:</strong> Get notified in your Discord server when free games
                  are available from various platforms.</p>
              </div>
              <form action="" method="post" onsubmit="handleFreestuffSubmit(event)">
                <div class="field">
                  <label class="label has-text-white" style="font-weight: 500;">Discord Channel <span
                      class="has-text-danger">*</span></label>
                  <div class="control has-icons-left">
                    <?php echo generateChannelInput('freestuff_channel_id', 'freestuff_channel_id', $existingFreestuffChannelID, 'e.g. 123456789123456789', $useManualIds, $guildChannels); ?>
                  </div>
                  <p class="help has-text-grey-light">Channel where free game announcements will be posted</p>
                </div>
                <div class="field">
                  <div class="control">
                    <button class="button is-primary is-fullwidth" type="submit" name="save_freestuff_settings" id="save_freestuff_btn"
                      style="border-radius: 6px; font-weight: 600;">
                      <span class="icon"><i class="fas fa-save"></i></span>
                      <span>Save Free Games Settings</span>
                    </button>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
        <!-- Embed Builder Section -->
        <div class="column is-12" id="feature-box-embedBuilder"
          style="display: <?php echo $serverManagementSettings['embedBuilder'] ? 'block' : 'none'; ?>;">
          <div class="box"
            style="background: linear-gradient(145deg, #2d2d2d 0%, #1a1a1a 100%); border-radius: 12px; border: 1px solid #3a3a3a; box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);">
            <div class="content">
              <h2 class="title is-4 has-text-white"
                style="border-bottom: 2px solid #9146ff; padding-bottom: 10px; margin-bottom: 20px;">
                <span class="icon-text">
                  <span class="icon has-text-info"><i class="fas fa-comment-dots"></i></span>
                  <span>Custom Embed Builder</span>
                </span>
              </h2>
              <p class="has-text-grey-light mb-4">Create, manage, and send custom Discord embeds to any channel in your
                server</p>
              <!-- Existing Embeds List -->
              <div class="box"
                style="background-color: #2a2a2a; border: 1px solid #3a3a3a; border-radius: 8px; margin-bottom: 20px;">
                <h3 class="subtitle is-5 has-text-white mb-3">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-list"></i></span>
                    <span>Your Custom Embeds</span>
                  </span>
                </h3>
                <div id="embedsList" style="max-height: 400px; overflow-y: auto;">
                  <!-- Embeds will be loaded here -->
                </div>
              </div>
              <!-- Create New Embed Button -->
              <div class="field">
                <div class="control">
                  <button class="button is-primary is-fullwidth" type="button" onclick="createEmbed()"
                    style="border-radius: 6px; font-weight: 600;">
                    <span class="icon"><i class="fas fa-plus"></i></span>
                    <span>Create New Embed</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Embed Builder Modal -->
<div id="embedBuilderModal" class="modal">
  <div class="modal-background"></div>
  <div class="modal-content" style="width: 90%; max-width: 1200px;">
    <div class="box" style="background-color: #2d2d2d; border-radius: 12px;">
      <h2 class="title is-4 has-text-white mb-4" id="embedModalTitle">Create Custom Embed</h2>
      <div class="columns">
        <!-- Left Column: Embed Configuration -->
        <div class="column is-7">
          <div class="field">
            <label class="label has-text-white">Embed Name</label>
            <div class="control">
              <input class="input" type="text" id="embed_name" placeholder="e.g., Welcome Message, Rules, Announcements"
                style="background-color: #3a3a3a; border-color: #5a5a5a; color: white;" oninput="updateEmbedPreview()">
            </div>
            <p class="help has-text-grey-light">Internal name to identify this embed (not shown in Discord)</p>
          </div>
          <div class="field">
            <label class="label has-text-white">Embed Title</label>
            <div class="control">
              <input class="input" type="text" id="embed_title" placeholder="e.g., Welcome to Our Server!"
                style="background-color: #3a3a3a; border-color: #5a5a5a; color: white;" oninput="updateEmbedPreview()">
            </div>
          </div>
          <div class="field">
            <label class="label has-text-white">Description</label>
            <div class="control">
              <textarea class="textarea" id="embed_description" rows="4" placeholder="Enter the main embed content..."
                style="background-color: #3a3a3a; border-color: #5a5a5a; color: white;"
                oninput="updateEmbedPreview()"></textarea>
            </div>
          </div>
          <div class="columns">
            <div class="column">
              <div class="field">
                <label class="label has-text-white">Embed Color</label>
                <div class="control">
                  <input class="input" type="color" id="embed_color" value="#5865f2"
                    style="background-color: #3a3a3a; border-color: #5a5a5a; height: 50px;"
                    oninput="updateEmbedPreview()">
                </div>
              </div>
            </div>
            <div class="column">
              <div class="field">
                <label class="label has-text-white">URL (optional)</label>
                <div class="control">
                  <input class="input" type="url" id="embed_url" placeholder="https://example.com"
                    style="background-color: #3a3a3a; border-color: #5a5a5a; color: white;"
                    oninput="updateEmbedPreview()">
                </div>
              </div>
            </div>
          </div>
          <div class="field">
            <label class="label has-text-white">Thumbnail URL (optional)</label>
            <div class="control">
              <input class="input" type="url" id="embed_thumbnail" placeholder="https://example.com/image.png"
                style="background-color: #3a3a3a; border-color: #5a5a5a; color: white;" oninput="updateEmbedPreview()">
            </div>
          </div>
          <div class="field">
            <label class="label has-text-white">Image URL (optional)</label>
            <div class="control">
              <input class="input" type="url" id="embed_image" placeholder="https://example.com/image.png"
                style="background-color: #3a3a3a; border-color: #5a5a5a; color: white;" oninput="updateEmbedPreview()">
            </div>
          </div>
          <div class="field">
            <label class="label has-text-white">Footer Text (optional)</label>
            <div class="control">
              <input class="input" type="text" id="embed_footer_text" placeholder="Footer text"
                style="background-color: #3a3a3a; border-color: #5a5a5a; color: white;" oninput="updateEmbedPreview()">
            </div>
          </div>
          <div class="field">
            <label class="label has-text-white">Footer Icon URL (optional)</label>
            <div class="control">
              <input class="input" type="url" id="embed_footer_icon" placeholder="https://example.com/icon.png"
                style="background-color: #3a3a3a; border-color: #5a5a5a; color: white;" oninput="updateEmbedPreview()">
            </div>
          </div>
          <div class="field">
            <label class="label has-text-white">Author Name (optional)</label>
            <div class="control">
              <input class="input" type="text" id="embed_author_name" placeholder="Author name"
                style="background-color: #3a3a3a; border-color: #5a5a5a; color: white;" oninput="updateEmbedPreview()">
            </div>
          </div>
          <div class="field">
            <label class="label has-text-white">Author URL (optional)</label>
            <div class="control">
              <input class="input" type="url" id="embed_author_url" placeholder="https://example.com"
                style="background-color: #3a3a3a; border-color: #5a5a5a; color: white;" oninput="updateEmbedPreview()">
            </div>
          </div>
          <div class="field">
            <label class="label has-text-white">Author Icon URL (optional)</label>
            <div class="control">
              <input class="input" type="url" id="embed_author_icon" placeholder="https://example.com/icon.png"
                style="background-color: #3a3a3a; border-color: #5a5a5a; color: white;" oninput="updateEmbedPreview()">
            </div>
          </div>
          <div class="field">
            <label class="checkbox has-text-white">
              <input type="checkbox" id="embed_timestamp" onchange="updateEmbedPreview()">
              Include Timestamp
            </label>
          </div>
          <!-- Fields Section -->
          <div class="box" style="background-color: #3a3a3a; border: 1px solid #5a5a5a;">
            <h4 class="subtitle is-6 has-text-white">Embed Fields</h4>
            <div id="embedFieldsList"></div>
            <button class="button is-small is-info" type="button" onclick="addEmbedField()">
              <span class="icon"><i class="fas fa-plus"></i></span>
              <span>Add Field</span>
            </button>
          </div>
        </div>
        <!-- Right Column: Preview -->
        <div class="column is-5">
          <div class="box" style="background-color: #36393f; border-radius: 8px; position: sticky; top: 20px;">
            <h4 class="subtitle is-6 has-text-white mb-3">Preview</h4>
            <div id="embedPreview" style="background-color: #2f3136; border-radius: 4px; padding: 16px;">
              <!-- Preview will be rendered here -->
            </div>
          </div>
        </div>
      </div>
      <div class="field is-grouped mt-4">
        <div class="control">
          <button class="button is-success" onclick="saveEmbed()">
            <span class="icon"><i class="fas fa-save"></i></span>
            <span>Save Embed</span>
          </button>
        </div>
        <div class="control">
          <button class="button is-light" onclick="closeEmbedModal()">
            <span class="icon"><i class="fas fa-times"></i></span>
            <span>Cancel</span>
          </button>
        </div>
      </div>
    </div>
  </div>
  <button class="modal-close is-large" aria-label="close" onclick="closeEmbedModal()"></button>
</div>
<!-- Send Embed Modal -->
<div id="sendEmbedModal" class="modal">
  <div class="modal-background"></div>
  <div class="modal-content" style="width: 500px;">
    <div class="box" style="background-color: #2d2d2d;">
      <h3 class="title is-5 has-text-white">Send Embed to Channel</h3>
      <div class="field">
        <label class="label has-text-white">Select Channel</label>
        <?php echo generateChannelInput('send_embed_channel', 'send_embed_channel', '', 'Select channel to send embed', $useManualIds, $guildChannels, 'fas fa-hashtag', true); ?>
      </div>
      <div class="field is-grouped mt-4">
        <div class="control">
          <button class="button is-success" onclick="confirmSendEmbed()">
            <span class="icon"><i class="fas fa-paper-plane"></i></span>
            <span>Send</span>
          </button>
        </div>
        <div class="control">
          <button class="button is-light" onclick="closeSendEmbedModal()">
            <span class="icon"><i class="fas fa-times"></i></span>
            <span>Cancel</span>
          </button>
        </div>
      </div>
    </div>
  </div>
  <button class="modal-close is-large" aria-label="close" onclick="closeSendEmbedModal()"></button>
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

  // Embed Builder Functions
  let currentEmbedId = 0;
  let embedFieldsCounter = 0;
  let currentSendEmbedId = 0;

  function loadEmbedsList() {
    fetch(`get_custom_embeds.php?server_id=${getCurrentServerId()}`)
      .then(response => response.json())
      .then(data => {
        const container = document.getElementById('embedsList');
        if (data.success && data.embeds && data.embeds.length > 0) {
          container.innerHTML = data.embeds.map(embed => `
          <div class="box" style="background-color: #3a3a3a; border: 1px solid #5a5a5a; margin-bottom: 10px;">
            <div class="level">
              <div class="level-left">
                <div class="level-item">
                  <div>
                    <p class="title is-6 has-text-white">${escapeHtml(embed.embed_name)}</p>
                    <p class="subtitle is-7 has-text-grey-light">${embed.title ? escapeHtml(embed.title) : 'No title'}</p>
                  </div>
                </div>
              </div>
              <div class="level-right">
                <div class="level-item">
                  <div class="buttons">
                    <button class="button is-small is-info" onclick="editEmbed(${embed.id})">
                      <span class="icon"><i class="fas fa-edit"></i></span>
                    </button>
                    <button class="button is-small is-success" onclick="sendEmbed(${embed.id})">
                      <span class="icon"><i class="fas fa-paper-plane"></i></span>
                    </button>
                    <button class="button is-small is-danger" onclick="deleteEmbed(${embed.id})">
                      <span class="icon"><i class="fas fa-trash"></i></span>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        `).join('');
        } else {
          container.innerHTML = '<p class="has-text-grey-light has-text-centered">No custom embeds yet. Create one to get started!</p>';
        }
      })
      .catch(error => {
        console.error('Error loading embeds:', error);
      });
  }

  function createEmbed() {
    currentEmbedId = 0;
    document.getElementById('embedModalTitle').textContent = 'Create Custom Embed';
    clearEmbedForm();
    document.getElementById('embedBuilderModal').classList.add('is-active');
    updateEmbedPreview();
  }

  function editEmbed(embedId) {
    currentEmbedId = embedId;
    document.getElementById('embedModalTitle').textContent = 'Edit Custom Embed';
    fetch(`get_custom_embed.php?id=${embedId}&server_id=${getCurrentServerId()}`)
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          const embed = data.embed;
          document.getElementById('embed_name').value = embed.embed_name || '';
          document.getElementById('embed_title').value = embed.title || '';
          document.getElementById('embed_description').value = embed.description || '';
          document.getElementById('embed_color').value = embed.color || '#5865f2';
          document.getElementById('embed_url').value = embed.url || '';
          document.getElementById('embed_thumbnail').value = embed.thumbnail_url || '';
          document.getElementById('embed_image').value = embed.image_url || '';
          document.getElementById('embed_footer_text').value = embed.footer_text || '';
          document.getElementById('embed_footer_icon').value = embed.footer_icon_url || '';
          document.getElementById('embed_author_name').value = embed.author_name || '';
          document.getElementById('embed_author_url').value = embed.author_url || '';
          document.getElementById('embed_author_icon').value = embed.author_icon_url || '';
          document.getElementById('embed_timestamp').checked = embed.timestamp_enabled == 1;
          // Load fields
          const fieldsContainer = document.getElementById('embedFieldsList');
          fieldsContainer.innerHTML = '';
          embedFieldsCounter = 0;
          if (embed.fields) {
            const fields = JSON.parse(embed.fields);
            fields.forEach(field => {
              addEmbedField(field.name, field.value, field.inline);
            });
          }
          document.getElementById('embedBuilderModal').classList.add('is-active');
          updateEmbedPreview();
        }
      });
  }

  function saveEmbed() {
    const embedName = document.getElementById('embed_name').value.trim();
    if (!embedName) {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Embed name is required',
        showConfirmButton: false,
        timer: 3000
      });
      return;
    }
    const fields = [];
    document.querySelectorAll('.embed-field-item').forEach(item => {
      const name = item.querySelector('.field-name').value.trim();
      const value = item.querySelector('.field-value').value.trim();
      const inline = item.querySelector('.field-inline').checked;
      if (name && value) {
        fields.push({ name, value, inline });
      }
    });
    const embedData = {
      action: 'save_custom_embed',
      server_id: getCurrentServerId(),
      embed_id: currentEmbedId,
      embed_name: embedName,
      title: document.getElementById('embed_title').value.trim() || null,
      description: document.getElementById('embed_description').value.trim() || null,
      color: document.getElementById('embed_color').value,
      url: document.getElementById('embed_url').value.trim() || null,
      thumbnail_url: document.getElementById('embed_thumbnail').value.trim() || null,
      image_url: document.getElementById('embed_image').value.trim() || null,
      footer_text: document.getElementById('embed_footer_text').value.trim() || null,
      footer_icon_url: document.getElementById('embed_footer_icon').value.trim() || null,
      author_name: document.getElementById('embed_author_name').value.trim() || null,
      author_url: document.getElementById('embed_author_url').value.trim() || null,
      author_icon_url: document.getElementById('embed_author_icon').value.trim() || null,
      timestamp_enabled: document.getElementById('embed_timestamp').checked,
      fields: JSON.stringify(fields)
    };
    fetch('save_discord_channel_config.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(embedData)
    })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: currentEmbedId ? 'Embed Updated' : 'Embed Created',
            showConfirmButton: false,
            timer: 3000
          });
          closeEmbedModal();
          loadEmbedsList();
        } else {
          Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'error',
            title: data.message || 'Failed to save embed',
            showConfirmButton: false,
            timer: 3000
          });
        }
      });
  }

  function sendEmbed(embedId) {
    currentSendEmbedId = embedId;
    // Fetch the embed to get the channel_id
    fetch(`get_custom_embed.php?id=${embedId}&server_id=${getCurrentServerId()}`)
      .then(response => response.json())
      .then(data => {
        if (data.success && data.embed.channel_id) {
          // Pre-populate the channel selector with the last used channel
          const channelSelect = document.getElementById('send_embed_channel');
          if (channelSelect) {
            channelSelect.value = data.embed.channel_id;
          }
        }
      })
      .catch(error => console.error('Error fetching embed for channel pre-population:', error));
    document.getElementById('sendEmbedModal').classList.add('is-active');
  }

  function confirmSendEmbed() {
    const channelId = document.getElementById('send_embed_channel').value.trim();
    if (!channelId) {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please select a channel',
        showConfirmButton: false,
        timer: 3000
      });
      return;
    }
    fetch('save_discord_channel_config.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'send_custom_embed',
        server_id: getCurrentServerId(),
        embed_id: currentSendEmbedId,
        channel_id: channelId
      })
    })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: 'Embed sent successfully!',
            showConfirmButton: false,
            timer: 3000
          });
          closeSendEmbedModal();
        } else {
          Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'error',
            title: data.message || 'Failed to send embed',
            showConfirmButton: false,
            timer: 3000
          });
        }
      });
  }

  function deleteEmbed(embedId) {
    Swal.fire({
      title: 'Delete Embed?',
      text: 'This action cannot be undone',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#d33',
      cancelButtonColor: '#3085d6',
      confirmButtonText: 'Yes, delete it'
    }).then((result) => {
      if (result.isConfirmed) {
        fetch('save_discord_channel_config.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'delete_custom_embed',
            server_id: getCurrentServerId(),
            embed_id: embedId
          })
        })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'Embed deleted',
                showConfirmButton: false,
                timer: 3000
              });
              loadEmbedsList();
            }
          });
      }
    });
  }

  function addEmbedField(name = '', value = '', inline = false) {
    embedFieldsCounter++;
    const fieldHtml = `
    <div class="box embed-field-item" style="background-color: #2a2a2a; border: 1px solid #5a5a5a; margin-bottom: 10px;" data-field-id="${embedFieldsCounter}">
      <div class="field">
        <label class="label has-text-white is-small">Field Name</label>
        <div class="control">
          <input class="input is-small field-name" type="text" value="${escapeHtml(name)}" placeholder="Field name" style="background-color: #3a3a3a; border-color: #5a5a5a; color: white;" onchange="updateEmbedPreview()">
        </div>
      </div>
      <div class="field">
        <label class="label has-text-white is-small">Field Value</label>
        <div class="control">
          <textarea class="textarea is-small field-value" placeholder="Field value" style="background-color: #3a3a3a; border-color: #5a5a5a; color: white;" onchange="updateEmbedPreview()">${escapeHtml(value)}</textarea>
        </div>
      </div>
      <div class="field">
        <label class="checkbox has-text-white is-small">
          <input type="checkbox" class="field-inline" ${inline ? 'checked' : ''} onchange="updateEmbedPreview()"> Inline
        </label>
        <div class="buttons is-pulled-right">
          <button class="button is-small is-info" type="button" onclick="moveFieldUp(${embedFieldsCounter})" title="Move Up">
            <span class="icon"><i class="fas fa-arrow-up"></i></span>
          </button>
          <button class="button is-small is-info" type="button" onclick="moveFieldDown(${embedFieldsCounter})" title="Move Down">
            <span class="icon"><i class="fas fa-arrow-down"></i></span>
          </button>
          <button class="button is-small is-danger" type="button" onclick="removeEmbedField(${embedFieldsCounter})">
            <span class="icon"><i class="fas fa-trash"></i></span>
          </button>
        </div>
      </div>
    </div>
  `;
    document.getElementById('embedFieldsList').insertAdjacentHTML('beforeend', fieldHtml);
    updateEmbedPreview();
  }

  function removeEmbedField(fieldId) {
    document.querySelector(`.embed-field-item[data-field-id="${fieldId}"]`).remove();
    updateEmbedPreview();
  }

  function moveFieldUp(fieldId) {
    const field = document.querySelector(`.embed-field-item[data-field-id="${fieldId}"]`);
    const previousField = field.previousElementSibling;
    if (previousField && previousField.classList.contains('embed-field-item')) {
      field.parentNode.insertBefore(field, previousField);
      updateEmbedPreview();
    }
  }

  function moveFieldDown(fieldId) {
    const field = document.querySelector(`.embed-field-item[data-field-id="${fieldId}"]`);
    const nextField = field.nextElementSibling;
    if (nextField && nextField.classList.contains('embed-field-item')) {
      field.parentNode.insertBefore(nextField, field);
      updateEmbedPreview();
    }
  }

  function updateEmbedPreview() {
    const title = document.getElementById('embed_title').value;
    const description = document.getElementById('embed_description').value;
    const color = document.getElementById('embed_color').value;
    const thumbnail = document.getElementById('embed_thumbnail').value;
    const image = document.getElementById('embed_image').value;
    const footerText = document.getElementById('embed_footer_text').value;
    const footerIcon = document.getElementById('embed_footer_icon').value;
    const authorName = document.getElementById('embed_author_name').value;
    const authorIcon = document.getElementById('embed_author_icon').value;
    const timestamp = document.getElementById('embed_timestamp').checked;
    let preview = `<div style="border-left: 4px solid ${color}; background-color: #2f3136; border-radius: 4px; padding: 16px;">`;
    if (authorName) {
      preview += `<div style="display: flex; align-items: center; margin-bottom: 8px;">`;
      if (authorIcon) preview += `<img src="${authorIcon}" style="width: 24px; height: 24px; border-radius: 50%; margin-right: 8px;" onerror="this.style.display='none'">`;
      preview += `<span style="color: #fff; font-weight: 600;">${parseDiscordMarkdown(authorName)}</span></div>`;
    }
    if (title) preview += `<div style="color: #fff; font-weight: 600; font-size: 16px; margin-bottom: 8px;">${parseDiscordMarkdown(title)}</div>`;
    if (description) preview += `<div style="color: #dcddde; font-size: 14px; margin-bottom: 8px; line-height: 1.4;">${parseDiscordMarkdown(description)}</div>`;
    // Fields
    const fields = [];
    document.querySelectorAll('.embed-field-item').forEach(item => {
      const name = item.querySelector('.field-name').value;
      const value = item.querySelector('.field-value').value;
      const inline = item.querySelector('.field-inline').checked;
      if (name && value) fields.push({ name, value, inline });
    });
    if (fields.length > 0) {
      preview += '<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 8px;">';
      fields.forEach(field => {
        const gridColumn = field.inline ? 'span 1' : 'span 3';
        preview += `<div style="grid-column: ${gridColumn};"><div style="color: #fff; font-weight: 600; font-size: 14px; margin-bottom: 4px;">${parseDiscordMarkdown(field.name)}</div><div style="color: #dcddde; font-size: 14px; line-height: 1.4;">${parseDiscordMarkdown(field.value)}</div></div>`;
      });
      preview += '</div>';
    }
    if (image) preview += `<img src="${image}" style="max-width: 100%; border-radius: 4px; margin-top: 16px;" onerror="this.style.display='none'">`;
    if (thumbnail) preview += `<img src="${thumbnail}" style="max-width: 80px; float: right; border-radius: 4px;" onerror="this.style.display='none'">`;
    if (footerText || timestamp) {
      preview += '<div style="display: flex; align-items: center; margin-top: 8px; padding-top: 8px; border-top: 1px solid #4a4a4a;">';
      if (footerIcon) preview += `<img src="${footerIcon}" style="width: 20px; height: 20px; border-radius: 50%; margin-right: 8px;" onerror="this.style.display='none'">`;
      preview += `<span style="color: #72767d; font-size: 12px;">${parseDiscordMarkdown(footerText)}`;
      if (timestamp) preview += ` • ${new Date().toLocaleString()}`;
      preview += '</span></div>';
    }
    preview += '</div>';
    document.getElementById('embedPreview').innerHTML = preview;
  }

  function clearEmbedForm() {
    document.getElementById('embed_name').value = '';
    document.getElementById('embed_title').value = '';
    document.getElementById('embed_description').value = '';
    document.getElementById('embed_color').value = '#5865f2';
    document.getElementById('embed_url').value = '';
    document.getElementById('embed_thumbnail').value = '';
    document.getElementById('embed_image').value = '';
    document.getElementById('embed_footer_text').value = '';
    document.getElementById('embed_footer_icon').value = '';
    document.getElementById('embed_author_name').value = '';
    document.getElementById('embed_author_url').value = '';
    document.getElementById('embed_author_icon').value = '';
    document.getElementById('embed_timestamp').checked = false;
    document.getElementById('embedFieldsList').innerHTML = '';
    embedFieldsCounter = 0;
  }

  function closeEmbedModal() {
    document.getElementById('embedBuilderModal').classList.remove('is-active');
  }

  function closeSendEmbedModal() {
    document.getElementById('sendEmbedModal').classList.remove('is-active');
  }

  function getCurrentServerId() {
    const guildIdElement = document.getElementById('guild_id_config');
    return guildIdElement ? guildIdElement.value : '';
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function parseDiscordMarkdown(text) {
    if (!text) return '';
    // Escape HTML first
    text = escapeHtml(text);
    // Headers (must come before other formatting)
    text = text.replace(/^### (.+)$/gm, '<h3 style="color: #fff; font-size: 14px; font-weight: 600; margin: 8px 0 4px 0;">$1</h3>');
    text = text.replace(/^## (.+)$/gm, '<h2 style="color: #fff; font-size: 16px; font-weight: 600; margin: 8px 0 4px 0;">$1</h2>');
    text = text.replace(/^# (.+)$/gm, '<h1 style="color: #fff; font-size: 18px; font-weight: 600; margin: 8px 0 4px 0;">$1</h1>');
    // Code blocks (triple backticks)
    text = text.replace(/```(\w+)?\n([\s\S]+?)```/g, '<pre style="background-color: #2f3136; border: 1px solid #202225; border-radius: 4px; padding: 8px; margin: 4px 0; overflow-x: auto;"><code style="color: #dcddde; font-family: monospace; font-size: 13px;">$2</code></pre>');
    // Inline code (single backticks)
    text = text.replace(/`([^`]+)`/g, '<code style="background-color: #2f3136; color: #dcddde; padding: 2px 4px; border-radius: 3px; font-family: monospace; font-size: 13px;">$1</code>');
    // Bold and italic combined (***text***)
    text = text.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
    text = text.replace(/___(.+?)___/g, '<strong><em>$1</em></strong>');
    // Bold (**text** or __text__)
    text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/__(.+?)__/g, '<strong>$1</strong>');
    // Italic (*text* or _text_)
    text = text.replace(/\*(.+?)\*/g, '<em>$1</em>');
    text = text.replace(/_([^_]+)_/g, '<em>$1</em>');
    // Strikethrough (~~text~~)
    text = text.replace(/~~(.+?)~~/g, '<s>$1</s>');
    // Underline (__text__ is already handled as bold)
    text = text.replace(/__([^_]+)__/g, '<u>$1</u>');
    // Links [text](url)
    text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" style="color: #00b0f4; text-decoration: none;" target="_blank">$1</a>');
    // Angle-bracketed URLs <url>
    text = text.replace(/&lt;(https?:\/\/[^\s&]+)&gt;/g, '<a href="$1" style="color: #00b0f4; text-decoration: none;" target="_blank">$1</a>');
    // Auto-link URLs
    text = text.replace(/(?<!href="|src=")(https?:\/\/[^\s<]+)/g, '<a href="$1" style="color: #00b0f4; text-decoration: none;" target="_blank">$1</a>');
    // Line breaks
    text = text.replace(/\n/g, '<br>');
    return text;
  }


  // Load embeds list on page load if embed builder is enabled
  if (document.getElementById('embedsList')) {
    loadEmbedsList();
  }

  // Add event listeners for embed form fields to update preview in real-time
  document.addEventListener('DOMContentLoaded', function () {
    const embedInputs = [
      'embed_name', 'embed_title', 'embed_description', 'embed_color', 'embed_url',
      'embed_thumbnail', 'embed_image', 'embed_footer_text', 'embed_footer_icon',
      'embed_author_name', 'embed_author_url', 'embed_author_icon', 'embed_timestamp'
    ];
    embedInputs.forEach(inputId => {
      const element = document.getElementById(inputId);
      if (element) {
        if (element.type === 'checkbox') {
          element.addEventListener('change', updateEmbedPreview);
        } else {
          element.addEventListener('input', updateEmbedPreview);
        }
      }
    });
  });

  $(document).ready(function () {
    // Character counters for online/offline text
    function updateCharCounter(inputId, counterId) {
      var input = $('#' + inputId);
      var counter = $('#' + counterId);
      var maxLength = 20;
      input.on('input', function () {
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
    // Toggle @everyone checkbox visibility based on stream channel selection
    function toggleStreamEveryoneField() {
      const streamChannelField = $('#stream_channel_id');
      const everyoneField = $('#stream_everyone_field');
      const selectedValue = streamChannelField.val();
      let hasValue = false;
      if (streamChannelField.is('select')) {
        hasValue = selectedValue && selectedValue !== '' && !selectedValue.includes('Select');
      } else if (streamChannelField.is('input[type="text"]')) {
        hasValue = selectedValue && selectedValue.trim() !== '';
      }
      if (hasValue) {
        everyoneField.show();
        const hasSavedValue = <?php echo isset($discordData['stream_alert_everyone']) ? 'true' : 'false'; ?>;
        if (!hasSavedValue) {
          $('#stream_alert_everyone').prop('checked', true);
        }
        // Update custom role field visibility based on current @everyone state
        toggleCustomRoleField();
      } else {
        everyoneField.hide();
        // Also uncheck the checkbox when hiding
        $('#stream_alert_everyone').prop('checked', false);
        // Hide custom role field when channel is deselected
        $('#stream_custom_role_field').hide();
      }
    }
    // Toggle custom role field based on @everyone checkbox
    function toggleCustomRoleField() {
      const everyoneChecked = $('#stream_alert_everyone').is(':checked');
      const customRoleField = $('#stream_custom_role_field');
      if (!everyoneChecked) {
        customRoleField.show();
      } else {
        customRoleField.hide();
      }
    }
    // Check on page load
    toggleStreamEveryoneField();
    toggleCustomRoleField();
    // Check when selection/input changes
    $('#stream_channel_id').on('change input', toggleStreamEveryoneField);
    // Check when @everyone checkbox changes
    $('#stream_alert_everyone').on('change', toggleCustomRoleField);
    // Dropdown validation for form buttons
    function validateDropdownSelection(fieldId, buttonName) {
      var fieldElement = $('#' + fieldId);
      var button = $('button[name="' + buttonName + '"]');
      function checkSelection() {
        var selectedValue = fieldElement.val();
        // Check if it's a select dropdown or text input
        if (fieldElement.is('select')) {
          // For dropdowns, ensure a valid option is selected (not empty and not the default "Select..." option)
          if (selectedValue && selectedValue !== '' && !selectedValue.includes('Select')) {
            button.prop('disabled', false);
          } else {
            button.prop('disabled', true);
          }
        } else if (fieldElement.is('input[type="text"]')) {
          // For text inputs (manual IDs), ensure there's text entered
          if (selectedValue && selectedValue.trim() !== '') {
            button.prop('disabled', false);
          } else {
            button.prop('disabled', true);
          }
        }
      }
      // Check on page load
      checkSelection();
      // Check when selection/input changes
      fieldElement.on('change input', checkSelection);
    }
    // Apply validation to all relevant dropdowns and inputs
    validateDropdownSelection('auto_role_id', 'save_auto_role');
    validateDropdownSelection('welcome_channel_id', 'save_welcome_message');
    validateDropdownSelection('message_log_channel_id', 'save_message_tracking');
    validateDropdownSelection('role_log_channel_id', 'save_role_tracking');
    validateDropdownSelection('server_mgmt_log_channel_id', 'save_server_role_management');
    validateDropdownSelection('user_log_channel_id', 'save_user_tracking');
    validateDropdownSelection('reaction_roles_channel_id', 'save_reaction_roles');
    validateDropdownSelection('rules_channel_id', 'save_rules');
    validateDropdownSelection('stream_schedule_channel_id', 'save_stream_schedule');
    // Validation for send reaction roles message button
    function validateSendReactionRolesButton() {
      const channelId = $('#reaction_roles_channel_id').val();
      const message = $('#reaction_roles_message').val().trim();
      const mappings = $('#reaction_roles_mappings').val().trim();
      let hasChannel = false;
      if ($('#reaction_roles_channel_id').is('select')) {
        hasChannel = channelId && channelId !== '' && !channelId.includes('Select');
      } else {
        hasChannel = channelId && channelId.trim() !== '';
      }
      const hasMessage = message !== '';
      const hasMappings = mappings !== '';
      const sendButton = $('#send_reaction_roles_message');
      if (hasChannel && hasMessage && hasMappings) {
        sendButton.prop('disabled', false);
      } else {
        sendButton.prop('disabled', true);
      }
    }
    // Check validation on page load and when inputs change
    validateSendReactionRolesButton();
    $('#reaction_roles_channel_id, #reaction_roles_message, #reaction_roles_mappings').on('change input', validateSendReactionRolesButton);
    // Validation for send rules message button
    function validateSendRulesButton() {
      const channelId = $('#rules_channel_id').val();
      const title = $('#rules_title').val().trim();
      const rules = $('#rules_content').val().trim();
      let hasChannel = false;
      if ($('#rules_channel_id').is('select')) {
        hasChannel = channelId && channelId !== '' && !channelId.includes('Select');
      } else {
        hasChannel = channelId && channelId.trim() !== '';
      }
      const hasTitle = title !== '';
      const hasRules = rules !== '';
      const sendButton = $('#send_rules_message');
      if (hasChannel && hasTitle && hasRules) {
        sendButton.prop('disabled', false);
      } else {
        sendButton.prop('disabled', true);
      }
    }
    // Check validation on page load and when inputs change
    validateSendRulesButton();
    $('#rules_channel_id, #rules_title, #rules_content').on('change input', validateSendRulesButton);
    // Toggle rules accept role field based on checkbox
    $('#rules_assign_role_on_accept').on('change', function () {
      if ($(this).is(':checked')) {
        $('#rules_accept_role_field').slideDown();
      } else {
        $('#rules_accept_role_field').slideUp();
        $('#rules_accept_role_id').val('');
      }
    });
    // Validation for send stream schedule message button
    function validateSendStreamScheduleButton() {
      try {
        const channelElement = $('#stream_schedule_channel_id');
        const titleElement = $('#stream_schedule_title');
        const contentElement = $('#stream_schedule_content');
        const timezoneElement = $('#stream_schedule_timezone');
        // Check if elements exist before trying to get values
        if (channelElement.length === 0 || titleElement.length === 0 || contentElement.length === 0 || timezoneElement.length === 0) {
          return;
        }
        const channelId = channelElement.val() || '';
        const title = (titleElement.val() || '').trim();
        const content = (contentElement.val() || '').trim();
        const timezone = (timezoneElement.val() || '').trim();
        let hasChannel = false;
        if (channelElement.is('select')) {
          hasChannel = channelId && channelId !== '' && !channelId.includes('Select');
        } else {
          hasChannel = channelId && channelId.trim() !== '';
        }
        const hasTitle = title !== '';
        const hasContent = content !== '';
        const hasTimezone = timezone !== '';
        const sendButton = $('#send_stream_schedule_message');
        const allValid = hasChannel && hasTitle && hasContent && hasTimezone;
        if (sendButton.length > 0) {
          if (allValid) {
            sendButton.prop('disabled', false);
          } else {
            sendButton.prop('disabled', true);
          }
        }
      } catch (error) {
        console.error('Error in validateSendStreamScheduleButton:', error);
      }
    }
    // Check validation on page load and when inputs change - use longer delay
    setTimeout(validateSendStreamScheduleButton, 500);
    $('#stream_schedule_channel_id, #stream_schedule_title, #stream_schedule_content, #stream_schedule_timezone').on('change input', validateSendStreamScheduleButton);
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
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
      })
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
          // Dynamically show/hide feature box based on toggle state
          const featureBoxId = 'feature-box-' + settingName;
          const featureBox = document.getElementById(featureBoxId);
          if (featureBox) {
            if (value) {
              // Feature enabled - show the box
              featureBox.style.setProperty('display', 'flex', 'important');
            } else {
              // Feature disabled - hide the box
              featureBox.style.setProperty('display', 'none', 'important');
            }
          }
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

  // Channel/Role Configuration AJAX Handler
  function saveChannelConfig(action, formData, button = null) {
    const guildIdElement = document.getElementById('guild_id_config');
    const guildId = guildIdElement ? guildIdElement.value.trim() : '';
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
      if (button) setButtonLoading(button, false);
      return;
    }
    // Add server_id and action to the form data
    formData.server_id = guildId;
    formData.action = action;
    fetch('save_discord_channel_config.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(formData)
    })
      .then(async response => {
        const contentType = response.headers.get('content-type');
        let responseData;
        try {
          const text = await response.text();
          responseData = JSON.parse(text);
        } catch (jsonError) {
          console.error('Failed to parse JSON. Error:', jsonError);
          responseData = { success: false, message: 'Server returned non-JSON response' };
        }
        if (!response.ok && (!responseData || !responseData.success)) {
          console.error('HTTP error response data:', responseData);
          // Only throw if we don't have a valid error message in the response
          if (!responseData || !responseData.message) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }
        }
        return responseData;
      })
      .then(data => {
        if (data.success) {
          // Show success notification
          Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: data.message || 'Configuration saved successfully',
            showConfirmButton: false,
            timer: 2000,
            timerProgressBar: true
          });

          // Handle clearing data - disable the appropriate toggle
          if (action === 'clear_reaction_roles') {
            const toggle = document.getElementById('reactionRoles');
            if (toggle) {
              toggle.checked = false;
              toggle.dispatchEvent(new Event('change'));
            }
          } else if (action === 'clear_rules') {
            const toggle = document.getElementById('rulesConfiguration');
            if (toggle) {
              toggle.checked = false;
              toggle.dispatchEvent(new Event('change'));
            }
          } else if (action === 'clear_stream_schedule') {
            const toggle = document.getElementById('streamSchedule');
            if (toggle) {
              toggle.checked = false;
              toggle.dispatchEvent(new Event('change'));
            }
          }

          // Update the form fields with the saved values (if returned in response)
          if (data.channel_id) {
            const channelInput = document.getElementById('reaction_roles_channel_id');
            if (channelInput && channelInput.value !== data.channel_id) {
              channelInput.value = data.channel_id;
            }
          }
          // Re-validate the stream schedule button after successful save
          if (typeof validateSendStreamScheduleButton === 'function') {
            setTimeout(validateSendStreamScheduleButton, 100);
          }
          // Note: No page reload - data is already saved and displayed in the form
        } else {
          // Show error
          console.error('Server returned error:', data.message);
          Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'error',
            title: 'Failed to save: ' + (data.message || 'Unknown error'),
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
          });
        }
        if (button) setButtonLoading(button, false);
      })
      .catch(error => {
        console.error('Error details:', error);
        console.error('Error message:', error.message);
        console.error('Error stack:', error.stack);
        Swal.fire({
          toast: true,
          position: 'top-end',
          icon: 'error',
          title: 'Network error: ' + error.message,
          showConfirmButton: false,
          timer: 4000,
          timerProgressBar: true
        });
        if (button) setButtonLoading(button, false);
      });
  }

  // Helper functions to show button loading state
  function setButtonLoading(button, isLoading = true) {
    if (!button) return;
    if (isLoading) {
      // Store original content
      button.dataset.originalHtml = button.innerHTML;
      button.disabled = true;
      button.innerHTML = '<span class="icon"><i class="fas fa-spinner fa-spin"></i></span><span>Processing...</span>';
    } else {
      // Restore original content
      button.disabled = false;
      if (button.dataset.originalHtml) {
        button.innerHTML = button.dataset.originalHtml;
      }
    }
  }

  // Handler functions for each save button
  function saveWelcomeMessage() {
    const button = event.target.closest('button');
    setButtonLoading(button, true);
    const welcomeChannelId = document.getElementById('welcome_channel_id').value;
    const welcomeMessage = document.getElementById('welcome_message').value;
    const useDefault = document.getElementById('use_default_welcome_message').checked;
    const enableEmbed = document.getElementById('enable_embed_message').checked;
    const welcomeColour = document.getElementById('welcome_colour').value;
    // Always require a channel
    if (!welcomeChannelId || welcomeChannelId === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please select a welcome channel',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }
    // If not using default message, require custom welcome message text
    if (!useDefault && (!welcomeMessage || welcomeMessage.trim() === '')) {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please enter a welcome message or enable "Use default welcome message"',
        showConfirmButton: false,
        timer: 4000,
        timerProgressBar: true
      });
      setButtonLoading(button, false);
      return;
    }

    saveChannelConfig('save_welcome_message', {
      welcome_channel_id: welcomeChannelId,
      welcome_message: useDefault ? '' : welcomeMessage,
      welcome_message_configuration_default: useDefault,
      welcome_message_configuration_embed: enableEmbed,
      welcome_message_configuration_colour: welcomeColour
    }, button);
  }

  function saveAutoRole() {
    const button = event.target.closest('button');
    setButtonLoading(button, true);
    
    const autoRoleId = document.getElementById('auto_role_id').value;
    if (!autoRoleId || autoRoleId === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please select an auto role',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      setButtonLoading(button, false);
      return;
    }
    saveChannelConfig('save_auto_role', {
      auto_role_id: autoRoleId
    }, button);
  }

  function saveMessageTracking() {
    const button = event.target.closest('button');
    setButtonLoading(button, true);
    
    const messageLogChannelId = document.getElementById('message_log_channel_id').value;
    if (!messageLogChannelId || messageLogChannelId === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please select a message log channel',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      setButtonLoading(button, false);
      return;
    }
    saveChannelConfig('save_message_tracking', {
      message_log_channel_id: messageLogChannelId
    }, button);
  }

  function saveRoleTracking() {
    const roleLogChannelId = document.getElementById('role_log_channel_id').value;
    if (!roleLogChannelId || roleLogChannelId === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please select a role log channel',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }
    saveChannelConfig('save_role_tracking', {
      role_log_channel_id: roleLogChannelId
    });
  }

  function saveServerRoleManagement() {
    const serverMgmtLogChannelId = document.getElementById('server_mgmt_log_channel_id').value;
    if (!serverMgmtLogChannelId || serverMgmtLogChannelId === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please select a server management log channel',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }
    saveChannelConfig('save_server_role_management', {
      server_mgmt_log_channel_id: serverMgmtLogChannelId
    });
  }

  function saveUserTracking() {
    const userLogChannelId = document.getElementById('user_log_channel_id').value;
    if (!userLogChannelId || userLogChannelId === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please select a user log channel',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }
    saveChannelConfig('save_user_tracking', {
      user_log_channel_id: userLogChannelId
    });
  }

  function saveReactionRoles() {
    const reactionRolesChannelId = document.getElementById('reaction_roles_channel_id').value.trim();
    const reactionRolesMessage = document.getElementById('reaction_roles_message').value;
    const reactionRolesMappings = document.getElementById('reaction_roles_mappings').value;
    const allowMultipleReactions = document.getElementById('allow_multiple_reactions').checked;
    // Always require a channel
    if (!reactionRolesChannelId || reactionRolesChannelId === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please select a reaction roles channel',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }
    // If mappings are provided, validate format
    if (reactionRolesMappings && reactionRolesMappings.trim() !== '') {
      const lines = reactionRolesMappings.trim().split('\n');
      for (let line of lines) {
        if (line.trim() && !line.includes('@')) {
          Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'warning',
            title: 'Invalid reaction role mapping format. Use: :emoji: Description @RoleName',
            showConfirmButton: false,
            timer: 4000,
            timerProgressBar: true
          });
          return;
        }
      }
    }

    saveChannelConfig('save_reaction_roles', {
      reaction_roles_channel_id: reactionRolesChannelId,
      reaction_roles_message: reactionRolesMessage,
      reaction_roles_mappings: reactionRolesMappings,
      allow_multiple_reactions: allowMultipleReactions
    });
  }

  function sendReactionRolesMessage() {
    const reactionRolesChannelId = document.getElementById('reaction_roles_channel_id').value.trim();
    const reactionRolesMessage = document.getElementById('reaction_roles_message').value;
    const reactionRolesMappings = document.getElementById('reaction_roles_mappings').value;
    const allowMultipleReactions = document.getElementById('allow_multiple_reactions').checked;

    // Validate required fields
    if (!reactionRolesChannelId || reactionRolesChannelId === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please select a reaction roles channel',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }

    if (!reactionRolesMessage || reactionRolesMessage.trim() === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please enter a reaction roles message',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }

    if (!reactionRolesMappings || reactionRolesMappings.trim() === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please enter reaction role mappings',
        showConfirmButton: false,
        timer: 4000,
        timerProgressBar: true
      });
      return;
    }

    // Validate mapping format
    const lines = reactionRolesMappings.trim().split('\n');
    for (let line of lines) {
      if (line.trim() && !line.includes('@')) {
        Swal.fire({
          toast: true,
          position: 'top-end',
          icon: 'warning',
          title: 'Invalid reaction role mapping format. Use: :emoji: Description @RoleName',
          showConfirmButton: false,
          timer: 4000,
          timerProgressBar: true
        });
        return;
      }
    }

    // Confirm before sending
    Swal.fire({
      title: 'Send Reaction Roles Message?',
      html: `Are you sure you want to send the reaction roles message to the selected Discord channel?<br><br><span class="has-text-weight-bold">This will post the message and add reaction emojis for users to interact with.</span>`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Send Message',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#28a745',
      cancelButtonColor: '#6c757d'
    }).then((result) => {
      if (result.isConfirmed) {
        saveChannelConfig('send_reaction_roles_message', {
          reaction_roles_channel_id: reactionRolesChannelId,
          reaction_roles_message: reactionRolesMessage,
          reaction_roles_mappings: reactionRolesMappings,
          allow_multiple_reactions: allowMultipleReactions
        });
      }
    }, button);
  }

  function saveRules() {
    const button = event.target.closest('button');
    setButtonLoading(button, true);
    const rulesChannelId = document.getElementById('rules_channel_id').value.trim();
    const rulesTitle = document.getElementById('rules_title').value;
    const rulesContent = document.getElementById('rules_content').value;
    const rulesColor = document.getElementById('rules_color').value;
    const assignRoleOnAccept = document.getElementById('rules_assign_role_on_accept').checked;
    const acceptRoleId = assignRoleOnAccept ? document.getElementById('rules_accept_role_id').value.trim() : '';
    // Always require a channel
    if (!rulesChannelId || rulesChannelId === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please select a rules channel',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }

    // Require title
    if (!rulesTitle || rulesTitle.trim() === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please enter a rules title',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }

    // Require rules content
    if (!rulesContent || rulesContent.trim() === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please enter at least one rule',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }

    // If assign role is checked, require a role ID
    if (assignRoleOnAccept && (!acceptRoleId || acceptRoleId === '')) {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please select a role for rule acceptance',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }

    saveChannelConfig('save_rules', {
      rules_channel_id: rulesChannelId,
      rules_title: rulesTitle,
      rules_content: rulesContent,
      rules_color: rulesColor,
      rules_accept_role_id: acceptRoleId
    });
  }

  function sendRulesMessage() {
    const rulesChannelId = document.getElementById('rules_channel_id').value.trim();
    const rulesTitle = document.getElementById('rules_title').value;
    const rulesContent = document.getElementById('rules_content').value;
    const rulesColor = document.getElementById('rules_color').value;
    const assignRoleOnAccept = document.getElementById('rules_assign_role_on_accept').checked;
    const acceptRoleId = assignRoleOnAccept ? document.getElementById('rules_accept_role_id').value.trim() : '';

    // Validate required fields
    if (!rulesChannelId || rulesChannelId === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please select a rules channel',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }

    if (!rulesTitle || rulesTitle.trim() === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please enter a rules title',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }

    if (!rulesContent || rulesContent.trim() === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please enter at least one rule',
        showConfirmButton: false,
        timer: 4000,
        timerProgressBar: true
      });
      return;
    }

    // If assign role is checked, require a role ID
    if (assignRoleOnAccept && (!acceptRoleId || acceptRoleId === '')) {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please select a role for rule acceptance',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }

    // Confirm before sending
    Swal.fire({
      title: 'Send Rules Message?',
      html: `Are you sure you want to send the rules message to the selected Discord channel?<br><br><span class="has-text-weight-bold">This will post an embed with your server rules.</span>`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Send Rules',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#28a745',
      cancelButtonColor: '#6c757d'
    }).then((result) => {
      if (result.isConfirmed) {
        saveChannelConfig('send_rules_message', {
          rules_channel_id: rulesChannelId,
          rules_title: rulesTitle,
          rules_content: rulesContent,
          rules_color: rulesColor,
          rules_accept_role_id: acceptRoleId
        });
      }
    }, button);
  }

  function saveStreamSchedule() {
    const button = event.target.closest('button');
    setButtonLoading(button, true);
    const scheduleChannelId = document.getElementById('stream_schedule_channel_id').value.trim();
    const scheduleTitle = document.getElementById('stream_schedule_title').value;
    const scheduleContent = document.getElementById('stream_schedule_content').value;
    const scheduleColor = document.getElementById('stream_schedule_color').value;
    const scheduleTimezone = document.getElementById('stream_schedule_timezone').value.trim();
    // Validate required fields
    if (!scheduleChannelId || scheduleChannelId === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please select a schedule channel',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }
    if (!scheduleTitle || scheduleTitle.trim() === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please enter a schedule title',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }
    if (!scheduleContent || scheduleContent.trim() === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please enter your stream schedule',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }
    if (!scheduleTimezone || scheduleTimezone === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please enter a timezone',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }
    saveChannelConfig('save_stream_schedule', {
      stream_schedule_channel_id: scheduleChannelId,
      stream_schedule_title: scheduleTitle,
      stream_schedule_content: scheduleContent,
      stream_schedule_color: scheduleColor,
      stream_schedule_timezone: scheduleTimezone
    }, function () {
      // Enable send button after successful save
      validateSendStreamScheduleButton();
    });
  }

  function sendStreamScheduleMessage() {
    const scheduleChannelId = document.getElementById('stream_schedule_channel_id').value.trim();
    const scheduleTitle = document.getElementById('stream_schedule_title').value;
    const scheduleContent = document.getElementById('stream_schedule_content').value;
    const scheduleColor = document.getElementById('stream_schedule_color').value;
    const scheduleTimezone = document.getElementById('stream_schedule_timezone').value.trim();
  }

  // Handler for Free Games form submission
  function handleFreestuffSubmit(event) {
    const button = document.getElementById('save_freestuff_btn');
    if (button) {
      setButtonLoading(button, true);
    }
    // Let the form submit normally
    return true;
  }
    // Validate required fields
    if (!scheduleChannelId || scheduleChannelId === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please select a schedule channel',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }
    if (!scheduleTitle || scheduleTitle.trim() === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please enter a schedule title',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
      });
      return;
    }
    if (!scheduleContent || scheduleContent.trim() === '') {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'warning',
        title: 'Please enter your stream schedule',
        showConfirmButton: false,
        timer: 4000,
        timerProgressBar: true
      });
      return;
    }
    // Confirm before sending
    Swal.fire({
      title: 'Send Stream Schedule?',
      html: `Are you sure you want to send the stream schedule to the selected Discord channel?<br><br><span class="has-text-weight-bold">This will post an embed with your streaming schedule.</span>`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Send Schedule',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#28a745',
      cancelButtonColor: '#6c757d'
    }).then((result) => {
      if (result.isConfirmed) {
        saveChannelConfig('send_stream_schedule_message', {
          stream_schedule_channel_id: scheduleChannelId,
          stream_schedule_title: scheduleTitle,
          stream_schedule_content: scheduleContent,
          stream_schedule_color: scheduleColor,
          stream_schedule_timezone: scheduleTimezone
        });
      }
    });
  }

  // Generic Clear Feature Function
  function clearFeature(featureName) {
    // Map feature names to display titles and action keys
    const featureMap = {
      'reactionRoles': {
        title: 'Clear Reaction Roles?',
        text: 'This will permanently delete all reaction roles configuration and settings. This action cannot be undone.',
        action: 'clear_reaction_roles'
      },
      'rulesConfiguration': {
        title: 'Clear Rules Configuration?',
        text: 'This will permanently delete all rules configuration and settings. This action cannot be undone.',
        action: 'clear_rules'
      },
      'streamSchedule': {
        title: 'Clear Stream Schedule?',
        text: 'This will permanently delete all stream schedule configuration and settings. This action cannot be undone.',
        action: 'clear_stream_schedule'
      },
      'welcomeMessage': {
        title: 'Clear Welcome Message?',
        text: 'This will permanently delete all welcome message configuration and settings. This action cannot be undone.',
        action: 'clear_welcome_message'
      },
      'autoRole': {
        title: 'Clear Auto Role Configuration?',
        text: 'This will permanently delete all auto role configuration and settings. This action cannot be undone.',
        action: 'clear_auto_role'
      },
      'roleHistory': {
        title: 'Clear Role History?',
        text: 'This will permanently delete all role history data and configuration. This action cannot be undone.',
        action: 'clear_role_history'
      },
      'messageTracking': {
        title: 'Clear Message Tracking?',
        text: 'This will permanently delete all message tracking configuration and settings. This action cannot be undone.',
        action: 'clear_message_tracking'
      },
      'roleTracking': {
        title: 'Clear Role Tracking?',
        text: 'This will permanently delete all role tracking configuration and settings. This action cannot be undone.',
        action: 'clear_role_tracking'
      },
      'serverRoleManagement': {
        title: 'Clear Server Role Management?',
        text: 'This will permanently delete all server role management configuration and settings. This action cannot be undone.',
        action: 'clear_server_role_management'
      },
      'userTracking': {
        title: 'Clear User Tracking?',
        text: 'This will permanently delete all user tracking configuration and settings. This action cannot be undone.',
        action: 'clear_user_tracking'
      },
      'freeGames': {
        title: 'Clear Free Games Configuration?',
        text: 'This will permanently delete all free games configuration and settings. This action cannot be undone.',
        action: 'clear_freestuff'
      }
    };

    const feature = featureMap[featureName];
    if (!feature) {
      console.error('Unknown feature:', featureName);
      return;
    }

    Swal.fire({
      title: feature.title,
      text: feature.text,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, Clear All Data',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#e74c3c',
      cancelButtonColor: '#6c757d'
    }).then((result) => {
      if (result.isConfirmed) {
        saveChannelConfig(feature.action, {});
      }
    });
  }

  // Deprecated individual clear functions (kept for backward compatibility)
  function clearReactionRoles() {
    clearFeature('reactionRoles');
  }

  function clearRules() {
    clearFeature('rulesConfiguration');
  }

  function clearStreamSchedule() {
    clearFeature('streamSchedule');
  }

  function clearFreeGames() {
    clearFeature('freeGames');
  }

  // Add event listeners to all Discord setting toggles
  document.addEventListener('DOMContentLoaded', function () {
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
      'userTracking',
      'reactionRoles',
      'rulesConfiguration',
      'streamSchedule',
      'embedBuilder',
      'freeGames'
    ];
    // Set initial toggle states based on saved settings
    settingToggles.forEach(settingName => {
      const toggle = document.getElementById(settingName);
      if (toggle) {
        toggle.checked = serverManagementSettings[settingName] || false;
        // Only add event listeners to enabled toggles
        if (!toggle.disabled) {
          toggle.addEventListener('change', function () {
            updateDiscordSetting(settingName, this.checked);
          });
        } else {
          // Add click handler for disabled toggles to show helpful message
          toggle.addEventListener('click', function (e) {
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

    // Handle welcome message default checkbox to enable/disable custom message textarea
    const useDefaultCheckbox = document.getElementById('use_default_welcome_message');
    const welcomeMessageTextarea = document.getElementById('welcome_message');
    if (useDefaultCheckbox && welcomeMessageTextarea) {
      function toggleWelcomeMessage() {
        if (useDefaultCheckbox.checked) {
          // Using default message - disable custom textarea
          welcomeMessageTextarea.disabled = true;
          welcomeMessageTextarea.style.opacity = '0.5';
          welcomeMessageTextarea.style.cursor = 'not-allowed';
        } else {
          // Using custom message - enable textarea
          welcomeMessageTextarea.disabled = false;
          welcomeMessageTextarea.style.opacity = '1';
          welcomeMessageTextarea.style.cursor = 'text';
        }
      }
      // Set initial state on page load
      toggleWelcomeMessage();
      // Add event listener for checkbox changes
      useDefaultCheckbox.addEventListener('change', toggleWelcomeMessage);
    }

    // Handle enable embed checkbox to show/hide colour field
    const enableEmbedCheckbox = document.getElementById('enable_embed_message');
    const welcomeColourField = document.getElementById('welcome_colour_field');
    if (enableEmbedCheckbox && welcomeColourField) {
      enableEmbedCheckbox.addEventListener('change', function () {
        if (this.checked) {
          welcomeColourField.style.display = '';
        } else {
          welcomeColourField.style.display = 'none';
        }
      });
    }

    // Validate send stream schedule button on page load
    if (typeof validateSendStreamScheduleButton === 'function') {
      validateSendStreamScheduleButton();
    }
  });
</script>
<?php } ?>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    function updateToggleStatus(input) {
      if (!input || !input.id) return;
      var status = document.querySelector('.toggle-status[data-for="' + input.id + '"]');
      if (!status) return;
      if (input.checked) {
        status.textContent = 'Enabled';
        status.classList.add('enabled');
        status.classList.remove('disabled');
      } else {
        status.textContent = 'Disabled';
        status.classList.add('disabled');
        status.classList.remove('enabled');
      }
    }

    var toggles = document.querySelectorAll('.server-management-toggles input[type="checkbox"]');
    toggles.forEach(function (t) {
      updateToggleStatus(t);
      t.addEventListener('change', function () { updateToggleStatus(t); });
    });
  });
</script>
<script>
  <?php
  // Output all console logs at once
  if (!empty($consoleLogs)) {
    foreach ($consoleLogs as $log) {
      echo $log . "\n";
    }
  }
  ?>
</script>
<?php
$scripts = ob_get_clean();

// Close Discord database connection
if (isset($discord_conn) && !$discord_conn->connect_error) {
  $discord_conn->close();
}

include "layout.php";
?>