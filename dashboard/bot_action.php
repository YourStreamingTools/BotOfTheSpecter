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

if (!in_array($bot, ['stable', 'beta', 'custom'])) {
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
    $cbStmt = $conn->prepare("SELECT bot_username, bot_channel_id, is_verified FROM custom_bots WHERE channel_id = ? LIMIT 1");
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
