<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

// Set strict timeout limits to prevent hanging
set_time_limit(15); // Maximum 15 seconds for the entire request
ini_set('max_execution_time', 15);

// Clean output buffer
while (ob_get_level()) { ob_end_clean(); }
ob_start();

// Check if the user is logged in
if (!isset($_SESSION['access_token'])) {
  ob_clean();
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => 'Authentication required']);
  exit();
}

// Check for required parameters
if (!isset($_POST['action']) || !isset($_POST['bot'])) {
  ob_clean();
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
  exit();
}

$action = $_POST['action'];
$bot = $_POST['bot'];

// Validate action and bot type
if (!in_array($action, ['run', 'stop', 'status'])) {
  ob_clean();
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => 'Invalid action']);
  exit();
}

if (!in_array($bot, ['stable', 'beta', 'custom', 'v6'])) {
  ob_clean();
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => 'Invalid bot type']);
  exit();
}

// Include necessary files
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";
require_once 'bot_control_functions.php';
include 'userdata.php';
include '/var/www/config/twitch.php';

// Map action to function action (stop -> kill)
$actionMap = [ 'run' => 'run', 'stop' => 'stop' ];

// Get user information - ensure we have all required data
$username = $_SESSION['username'] ?? '';
$twitchUserId = $_SESSION['twitchUserId'] ?? '';
$authToken = $_SESSION['access_token'] ?? '';
$refreshToken = $_SESSION['refresh_token'] ?? '';
$apiKey = $_SESSION['api_key'] ?? '';

// Validate required session data
if (empty($username)) {
  ob_clean();
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => 'Username not found in session']);
  exit();
}
// If attempting to start a bot (not custom), check if bot is banned
if (($actionMap[$action] ?? '') === 'run' && $bot !== 'custom' && $username !== 'botofthespecter') {
  // Check if bot is a moderator first
  $modCheckUrl = "https://api.twitch.tv/helix/moderation/moderators?broadcaster_id=" . urlencode($twitchUserId);
  $modHeaders = ['Authorization: Bearer ' . $authToken, 'Client-ID: ' . $clientID];
  $modCh = curl_init($modCheckUrl);
  curl_setopt($modCh, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($modCh, CURLOPT_HTTPHEADER, $modHeaders);
  curl_setopt($modCh, CURLOPT_TIMEOUT, 5);
  $modResponse = curl_exec($modCh);
  $modHttpCode = curl_getinfo($modCh, CURLINFO_HTTP_CODE);
  curl_close($modCh);
  $isMod = false;
  if ($modResponse !== false && $modHttpCode === 200) {
    $modData = json_decode($modResponse, true);
    if (isset($modData['data'])) {
      $botUserId = '971436498';
      foreach ($modData['data'] as $mod) {
        if ($mod['user_id'] === $botUserId) {
          $isMod = true;
          break;
        }
      }
    }
  }
  // If bot is NOT a moderator, check if it's banned
  if (!$isMod) {
    $botUserId = '971436498';
    $banCheckUrl = "https://api.twitch.tv/helix/moderation/banned?broadcaster_id=" . urlencode($twitchUserId) . "&user_id=" . urlencode($botUserId);
    $banHeaders = ['Authorization: Bearer ' . $authToken, 'Client-ID: ' . $clientID];
    $banCh = curl_init($banCheckUrl);
    curl_setopt($banCh, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($banCh, CURLOPT_HTTPHEADER, $banHeaders);
    curl_setopt($banCh, CURLOPT_TIMEOUT, 5);
    $banResponse = curl_exec($banCh);
    $banHttpCode = curl_getinfo($banCh, CURLINFO_HTTP_CODE);
    curl_close($banCh);
    if ($banResponse !== false && $banHttpCode === 200) {
      $banData = json_decode($banResponse, true);
      if (isset($banData['data']) && !empty($banData['data'])) {
        $banReason = $banData['data'][0]['reason'] ?? 'No reason provided';
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Bot is BANNED from your channel. Reason: ' . $banReason . '. Please unban the bot and make it a moderator before starting.']);
        exit();
      }
    }
    // Bot is not mod and not banned - still can't start without mod
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Bot is not a moderator on your channel. Please make the bot a moderator before starting.']);
    exit();
  }
}
// Prepare parameters
$params = [
  'username' => $username,
  'twitch_user_id' => $twitchUserId,
  'auth_token' => $authToken,
  'refresh_token' => $refreshToken,
  'api_key' => $apiKey
];

// Perform the bot action with timeout monitoring
$startTime = time();
$maxExecutionTime = 12; // Leave buffer for cleanup
try {
  // If attempting to start the custom bot, require that a verified custom bot is configured
  if ($bot === 'custom' && ($actionMap[$action] ?? '') === 'run') {
    $channelOwnerId = $_SESSION['user_id'] ?? 0;
    if (empty($channelOwnerId)) {
      ob_clean(); header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'User not identified.']); exit();
    }
    // Check custom_bots table for this channel
    $cbStmt = $conn->prepare("SELECT bot_username, bot_channel_id, is_verified, access_token, token_expires, refresh_token FROM custom_bots WHERE channel_id = ? LIMIT 1");
    if ($cbStmt) {
      $cbStmt->bind_param('i', $channelOwnerId);
      $cbStmt->execute();
      $cbRes = $cbStmt->get_result();
      $cbRow = $cbRes->fetch_assoc();
      if (!$cbRow) {
        ob_clean(); header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Custom bot is not configured. Please add and verify a custom bot on the Profile page.']); exit();
      }
      if (intval($cbRow['is_verified']) !== 1) {
        ob_clean(); header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Custom bot is not verified. Please verify the custom bot on the Profile page before starting it.']); exit();
      }
      if (empty($cbRow['bot_channel_id']) || empty($cbRow['bot_username'])) {
        ob_clean(); header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Custom bot credentials are incomplete. Please check the Profile page.']); exit();
      }
      // Optionally attach custom bot info to params for performBotAction if needed in future
      $params['custom_bot_username'] = $cbRow['bot_username'];
      $params['custom_bot_channel_id'] = $cbRow['bot_channel_id'];
      // If we have tokens stored, ensure the access token is still usable; refresh if expired/short
      $storedAccess = $cbRow['access_token'] ?? null;
      $storedExpires = $cbRow['token_expires'] ?? null; // DATETIME string
      $storedRefresh = $cbRow['refresh_token'] ?? null;
      $needRefresh = false;
      if (empty($storedAccess)) {
        // No access token stored - require re-verify
        ob_clean(); header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Custom bot token missing. Please re-verify the custom bot on the Profile page.']); exit();
      }
      // If token_expires is not set or is in the past (or within 60s), refresh
      if (empty($storedExpires) || strtotime($storedExpires) <= (time() + 60)) {
        $needRefresh = true;
      }
      if ($needRefresh) {
        // Attempt token refresh if we have a refresh token
        if (empty($storedRefresh)) {
          ob_clean(); header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Custom bot token expired and no refresh token available. Please re-verify the custom bot on the Profile page.']); exit();
        }
        // Load Twitch client credentials
        @include_once '/var/www/config/twitch.php';
        $clientId = $clientID ?? $client_id ?? null;
        $clientSecret = $clientSecret ?? $client_secret ?? null;
        if (empty($clientId) || empty($clientSecret)) {
          ob_clean(); header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Server misconfiguration: missing Twitch client credentials.']); exit();
        }
        $refreshPost = http_build_query([
          'grant_type' => 'refresh_token',
          'refresh_token' => $storedRefresh,
          'client_id' => $clientId,
          'client_secret' => $clientSecret,
        ]);
        $rch = curl_init('https://id.twitch.tv/oauth2/token');
        curl_setopt($rch, CURLOPT_POST, true);
        curl_setopt($rch, CURLOPT_POSTFIELDS, $refreshPost);
        curl_setopt($rch, CURLOPT_RETURNTRANSFER, true);
        $refreshResp = curl_exec($rch);
        $refreshCode = curl_getinfo($rch, CURLINFO_HTTP_CODE);
        $refreshErr = curl_error($rch);
        curl_close($rch);
        if ($refreshResp === false || $refreshCode !== 200) {
          error_log('Custom bot token refresh failed: ' . ($refreshErr ?: "HTTP {$refreshCode}"));
          ob_clean(); header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Failed to refresh custom bot token. Please re-verify the custom bot on the Profile page.']); exit();
        }
        $refreshData = json_decode($refreshResp, true);
        $newAccess = $refreshData['access_token'] ?? null;
        $newRefresh = $refreshData['refresh_token'] ?? $storedRefresh;
        $newExpiresIn = $refreshData['expires_in'] ?? null;
        $newExpiresAt = $newExpiresIn ? date('Y-m-d H:i:s', time() + intval($newExpiresIn)) : $storedExpires;
        if (empty($newAccess)) {
          error_log('Custom bot refresh response missing access_token');
          ob_clean(); header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Failed to obtain refreshed token for custom bot. Please re-verify.']); exit();
        }
        // Persist rotated tokens back to DB if possible
        try {
          // Prefer updating refresh_token if column exists
          $updStmt = $conn->prepare("UPDATE custom_bots SET access_token = ?, token_expires = ?, refresh_token = ? WHERE bot_channel_id = ? LIMIT 1");
          if ($updStmt) {
            $updStmt->bind_param('ssss', $newAccess, $newExpiresAt, $newRefresh, $cbRow['bot_channel_id']);
            $updStmt->execute();
            $updStmt->close();
          } else {
            // Fallback: update only access_token and token_expires
            $updStmt2 = $conn->prepare("UPDATE custom_bots SET access_token = ?, token_expires = ? WHERE bot_channel_id = ? LIMIT 1");
            if ($updStmt2) { $updStmt2->bind_param('sss', $newAccess, $newExpiresAt, $cbRow['bot_channel_id']); $updStmt2->execute(); $updStmt2->close(); }
          }
        } catch (Exception $e) {
          error_log('Failed to persist refreshed custom bot token: ' . $e->getMessage());
        }
        // Update params to use the refreshed access token
        $params['auth_token'] = $newAccess;
      } else {
        // Token is fresh enough - use stored access token
        $params['auth_token'] = $storedAccess;
      }
    } else {
      // DB prepare failed - fallback to denying start to be safe
      ob_clean(); header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Unable to validate custom bot configuration.']); exit();
    }
  }
  $result = performBotAction($actionMap[$action], $bot, $params);
  // Check if we're approaching timeout
  if ((time() - $startTime) >= $maxExecutionTime) {
    $result = [
      'success' => false, 
      'message' => 'Operation timed out. Bot may still be processing in background.',
      'timeout' => true
    ];
  }
} catch (Exception $e) {
  $result = [
    'success' => false,
    'message' => 'Error: ' . $e->getMessage(),
    'error' => true
  ];
}

// Add some debugging information
error_log("Bot action performed - Bot: $bot, Action: $action, Username: $username, Duration: " . (time() - $startTime) . "s, Result: " . json_encode($result));

// Return response
ob_clean(); // Clear any accidental output
header('Content-Type: application/json');
echo json_encode($result);
exit();
?>
