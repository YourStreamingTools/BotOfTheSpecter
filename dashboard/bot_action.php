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

if (!in_array($bot, ['stable', 'beta', 'v6'])) {
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
// If attempting to start a bot, check if bot is banned
if (($actionMap[$action] ?? '') === 'run' && $username !== 'botofthespecter') {
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
// Check if custom bot mode is enabled (only for beta and only when starting the bot)
$useCustomBot = false;
$customBotUsername = null;
if ($action === 'run' && isset($_POST['use_custom_bot']) && $_POST['use_custom_bot'] === 'true' && $bot === 'beta') {
  // Query custom_bots table for this channel
  $stmt = $conn->prepare("SELECT bot_username, is_verified FROM custom_bots WHERE channel_id = ? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param('s', $user_id);
    $stmt->execute();
    $result_cb = $stmt->get_result();
    if ($row = $result_cb->fetch_assoc()) {
      if ($row['is_verified'] == 1) {
        $useCustomBot = true;
        $customBotUsername = $row['bot_username'];
      } else {
        // Custom bot exists but not verified
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Custom bot is not verified. Please verify your custom bot in Profile settings.']);
        exit();
      }
    } else {
      // No custom bot configured
      ob_clean();
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'message' => 'No custom bot configured. Please configure a custom bot in Profile settings.']);
      exit();
    }
    $stmt->close();
  }
}

// Prepare parameters
$params = [
  'username' => $username,
  'twitch_user_id' => $twitchUserId,
  'auth_token' => $authToken,
  'refresh_token' => $refreshToken,
  'api_key' => $apiKey,
  'use_custom_bot' => $useCustomBot,
  'custom_bot_username' => $customBotUsername
];

// Perform the bot action with timeout monitoring
$startTime = time();
$maxExecutionTime = 12; // Leave buffer for cleanup
try {
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
