<?php
session_start();
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/lang/i18n.php';

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

if (!in_array($bot, ['stable', 'beta', 'discord'])) {
  ob_clean();
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => 'Invalid bot type']);
  exit();
}

// Include bot control functions
require_once 'bot_control_functions.php';

// Map action to function action (stop -> kill)
$actionMap = [ 'run' => 'run', 'stop' => 'stop' ];

// Get user information
$username = $_SESSION['username'] ?? '';
$twitchUserId = $_SESSION['twitchUserId'] ?? '';
$authToken = $_SESSION['access_token'] ?? '';
$refreshToken = $_SESSION['refresh_token'] ?? '';
$apiKey = $_SESSION['api_key'] ?? '';

// Prepare parameters
$params = [
  'username' => $bot === 'discord' ? null : $username,
  'twitch_user_id' => $twitchUserId,
  'auth_token' => $authToken,
  'refresh_token' => $refreshToken,
  'api_key' => $apiKey
];

// Perform the bot action
if ($bot === 'discord') {
    // Only allow status/version checks for Discord bot
    if ($action === 'status') {
        $result = checkBotRunning('discord');
        echo json_encode(['status' => $result]);
        exit;
    }
    // No start/stop actions for Discord bot
    echo json_encode(['error' => 'Action not allowed for Discord bot.']);
    exit;
} else {
    $result = performBotAction($actionMap[$action], $bot, $params);
}

// Add some debugging information
error_log("Bot action performed - Bot: $bot, Action: $action, Result: " . json_encode($result));

// Return response
ob_clean(); // Clear any accidental output
header('Content-Type: application/json');
echo json_encode($result);
exit();
?>
