<?php
require_once '/var/www/lib/session_bootstrap.php';
$userLanguage = isset($_SESSION['language']) ? $_SESSION['language'] : (isset($user['language']) ? $user['language'] : 'EN');
include_once __DIR__ . '/../lang/i18n.php';

// Set strict timeout limits to prevent hanging
set_time_limit(15); // Maximum 15 seconds for the entire request
ini_set('max_execution_time', 15);

// Clean output buffer
while (ob_get_level()) { ob_end_clean(); }
ob_start();

require_once '/var/www/lib/require_auth_ajax.php';

if (isset($_SESSION['admin_act_as_active']) && $_SESSION['admin_act_as_active'] === true) {
  ob_clean();
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => t('bot_acting_as_disabled')]);
  exit();
}

// Check for required parameters
if (!isset($_POST['action']) || !isset($_POST['bot'])) {
  ob_clean();
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => t('bot_action_missing_parameters')]);
  exit();
}

$action = $_POST['action'];
$bot = $_POST['bot'];

// Validate action and bot type
if (!in_array($action, ['run', 'stop', 'status'])) {
  ob_clean();
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => t('bot_action_invalid_action')]);
  exit();
}

if (!in_array($bot, ['stable', 'beta', 'v6', 'kick'], true)) {
  ob_clean();
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'message' => t('bot_action_invalid_bot_type')]);
  exit();
}

// Include necessary files
require_once "/var/www/config/db_connect.php";
require_once "/var/www/config/ssh.php";
require_once '../includes/bot_control_functions.php';
include '../includes/userdata.php';
session_write_close();
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
  echo json_encode(['success' => false, 'message' => t('bot_action_username_not_found')]);
  exit();
}
// If attempting to start a Twitch bot, check if Specter is banned / not a mod
if (($actionMap[$action] ?? '') === 'run' && $bot !== 'kick' && $username !== 'botofthespecter') {
  // Check if bot is a moderator first
  $modCheckUrl = "https://api.twitch.tv/helix/moderation/moderators?broadcaster_id=" . urlencode($twitchUserId);
  $modHeaders = ['Authorization: Bearer ' . $authToken, 'Client-ID: ' . $clientID];
  $modCh = curl_init($modCheckUrl);
  curl_setopt($modCh, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($modCh, CURLOPT_HTTPHEADER, $modHeaders);
  curl_setopt($modCh, CURLOPT_TIMEOUT, 5);
  $modResponse = curl_exec($modCh);
  $modHttpCode = curl_getinfo($modCh, CURLINFO_HTTP_CODE);
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
if ($banResponse !== false && $banHttpCode === 200) {
      $banData = json_decode($banResponse, true);
      if (isset($banData['data']) && !empty($banData['data'])) {
        $banReason = $banData['data'][0]['reason'] ?? t('bot_action_ban_no_reason');
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => t('bot_action_bot_banned', ['reason' => $banReason])]);
        exit();
      }
    }
    // Bot is not mod and not banned - still can't start without mod
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => t('bot_action_not_moderator')]);
    exit();
  }
}
// Check if custom bot mode is enabled (only for beta and only when starting the bot)
$useCustomBot = false;
$customBotUsername = null;
if ($action === 'run' && isset($_POST['use_custom_bot']) && ($_POST['use_custom_bot'] === 'true' || $_POST['use_custom_bot'] === '1') && $bot === 'beta') {
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
        echo json_encode(['success' => false, 'message' => t('bot_action_custom_bot_unverified')]);
        exit();
      }
    } else {
      // No custom bot configured
      ob_clean();
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'message' => t('bot_action_no_custom_bot')]);
      exit();
    }
    $stmt->close();
  }
}

// Opt-in custom channel module: prefer session (userdata), allow POST override for immediate UI state
$loadCustomModule = ((int)($_SESSION['use_custom_module'] ?? 0)) === 1;
if (isset($_POST['load_custom_module'])) {
  $loadCustomModule = ($_POST['load_custom_module'] === 'true' || $_POST['load_custom_module'] === '1');
}

// Prepare parameters
$params = [
  'username' => $username,
  'twitch_user_id' => $twitchUserId,
  'auth_token' => $authToken,
  'refresh_token' => $refreshToken,
  'api_key' => $apiKey,
  'use_custom_bot' => $useCustomBot,
  'custom_bot_username' => $customBotUsername,
  'use_self' => (isset($_POST['use_self']) && ($_POST['use_self'] === 'true' || $_POST['use_self'] === '1')) ? true : false,
  'load_custom_module' => $loadCustomModule,
];

if ($bot === 'kick' && ($actionMap[$action] ?? '') === 'run') {
  require_once __DIR__ . '/../includes/kick_bot.php';
  $kickConfigPath = '/var/www/config/kick.php';
  if (!is_file($kickConfigPath)) {
    $kickConfigPath = __DIR__ . '/../../config/kick.php';
  }
  if (is_file($kickConfigPath)) {
    require_once $kickConfigPath;
  }
  $kickTokens = kick_bot_get_tokens($conn, $username);
  if (!$kickTokens) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => t('bot_kick_not_connected')]);
    exit();
  }
  $kickClientId = isset($kick_client_id) ? trim((string)$kick_client_id) : '';
  $kickClientSecret = isset($kick_client_secret) ? trim((string)$kick_client_secret) : '';
  if ($kickClientId === '' || $kickClientSecret === '') {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => t('bot_kick_app_not_configured')]);
    exit();
  }
  $params['twitch_user_id'] = $kickTokens['kick_user_id'];
  $params['auth_token'] = $kickTokens['access_token'];
  $params['refresh_token'] = $kickTokens['refresh_token'];
  $params['kick_username'] = $kickTokens['kick_username'];
  $params['kick_chatroom_id'] = $kickTokens['chatroom_id'];
  $params['kick_client_id'] = $kickClientId;
  $params['kick_client_secret'] = $kickClientSecret;
}

// Perform the bot action with timeout monitoring
$startTime = time();
$maxExecutionTime = 12; // Leave buffer for cleanup
try {
  $result = performBotAction($actionMap[$action], $bot, $params);
  // Check if we're approaching timeout
  if ((time() - $startTime) >= $maxExecutionTime) {
    $result = [
      'success' => false, 
      'message' => t('bot_action_timed_out'),
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

